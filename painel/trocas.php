<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  TROCAS DE CLIENTE - fila de aprovacao
 * =====================================================================
 *  Uma licenca vinculada NAO muda de cliente sozinha. Se pudesse, o
 *  revendedor reciclaria a mesma licenca entre clientes ao longo do
 *  tempo - na pratica, vendendo duas vezes a mesma coisa.
 *
 *  Fluxo: o revendedor solicita em Minhas licencas, escreve o motivo,
 *  e voce aprova ou nega aqui. Tudo fica em `trocas_cliente` mais o
 *  registro em `ativacoes_log`.
 *
 *  QUANDO APROVAR: cliente errado no cadastro, empresa que trocou de
 *  CNPJ, equipamento devolvido e revendido.
 *  QUANDO DESCONFIAR: o mesmo revendedor pedindo troca com frequencia,
 *  ou trocas logo apos a ativacao - pode ser licenca sendo usada como
 *  demonstracao rotativa. Para isso existe a licenca tipo demo.
 *
 *  IMPORTANTE: aprovar troca NAO libera a maquina. O cliente novo
 *  provavelmente esta em outro PC, entao normalmente e preciso liberar
 *  a maquina tambem - o botao aparece junto quando ha fingerprint.
 * ===================================================================== */

$msg=''; $tipo='';
$u = usuario_logado();

if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_valido()) {
    $ac  = $_POST['acao'] ?? '';
    $tid = (int)($_POST['id'] ?? 0);

    if (in_array($ac, ['aprovar','negar'], true) && $tid) {
        $st = db()->prepare(
          'SELECT t.*, l.chave, l.fingerprint, p.codigo AS pc, tr.codigo AS tc
             FROM trocas_cliente t
             JOIN licencas l   ON l.id = t.licenca_id
             LEFT JOIN produtos p ON p.id = l.produto_id
             LEFT JOIN tiers tr   ON tr.id = l.tier_id
            WHERE t.id=? AND t.status="pendente"');
        $st->execute([$tid]);
        $tr = $st->fetch();

        if (!$tr) { $msg='Solicitação não encontrada ou já decidida.'; $tipo='erro'; }
        else {
            $obs = trim($_POST['observacao_admin'] ?? '');

            if ($ac === 'aprovar') {
                $liberar = !empty($_POST['liberar_maquina']);
                db()->beginTransaction();
                try {
                    db()->prepare('UPDATE licencas SET cliente_id=? WHERE id=?')
                        ->execute([$tr['cliente_novo'], $tr['licenca_id']]);

                    if ($liberar && $tr['fingerprint']) {
                        // troca de cliente costuma significar outro PC;
                        // nao consome o contador de transferencias porque
                        // e uma decisao administrativa, nao um PC queimado
                        db()->prepare(
                          'UPDATE licencas SET fingerprint=NULL, status="nova"
                            WHERE id=?')->execute([$tr['licenca_id']]);
                    }

                    db()->prepare(
                      'UPDATE trocas_cliente
                          SET status="aprovada", decidido_por=?, decidido_em=NOW(),
                              observacao_admin=?
                        WHERE id=?')->execute([$u['id'], ($obs ?: null), $tid]);
                    db()->commit();
                } catch (Throwable $e) {
                    db()->rollBack();
                    $msg='Erro ao aprovar: '.$e->getMessage(); $tipo='erro';
                }

                if ($tipo !== 'erro') {
                    log_acao_painel($tr['licenca_id'], $tr['chave'],
                        $tr['fingerprint'], 'aprovar_troca', 'ok',
                        $u['id'], $u['nome'] ?? null, $tr['pc'], $tr['tc'],
                        'cliente '.$tr['cliente_atual'].' -> '.$tr['cliente_novo']
                        . ($liberar ? ' + maquina liberada' : ''));
                    $msg='Troca aprovada.'
                       . ($liberar ? ' Máquina liberada para nova ativação.' : '');
                    $tipo='ok';
                }
            } else {
                db()->prepare(
                  'UPDATE trocas_cliente
                      SET status="negada", decidido_por=?, decidido_em=NOW(),
                          observacao_admin=?
                    WHERE id=?')->execute([$u['id'], ($obs ?: null), $tid]);
                log_acao_painel($tr['licenca_id'], $tr['chave'], null,
                    'negar_troca', 'negado', $u['id'], $u['nome'] ?? null,
                    $tr['pc'], $tr['tc'], $obs ?: 'sem justificativa');
                $msg='Solicitação negada.'; $tipo='ok';
            }
        }
    }
}

// ---- filtro ----------------------------------------------------------
$fStatus = trim($_GET['status'] ?? 'pendente');

$where = []; $args = [];
if ($fStatus !== '') { $where[] = 't.status = ?'; $args[] = $fStatus; }
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$st = db()->prepare(
  "SELECT t.*, l.chave, l.fingerprint, l.expira_em, l.transferencias,
          p.codigo AS produto, ti.nome AS tier,
          ca.razao_social AS cli_atual_nome, ca.nome_fantasia AS cli_atual_fant,
          cn.razao_social AS cli_novo_nome,  cn.nome_fantasia AS cli_novo_fant,
          u.nome AS rev_nome, u.empresa AS rev_empresa,
          u.nome_fantasia AS rev_fantasia,
          ud.nome AS decidido_por_nome,
          m.maq_nome, m.ultimo_acesso
     FROM trocas_cliente t
     JOIN licencas l       ON l.id = t.licenca_id
     LEFT JOIN clientes ca ON ca.id = t.cliente_atual
     LEFT JOIN clientes cn ON cn.id = t.cliente_novo
     LEFT JOIN usuarios u  ON u.id = t.revendedor_id
     LEFT JOIN usuarios ud ON ud.id = t.decidido_por
     LEFT JOIN produtos p  ON p.id = l.produto_id
     LEFT JOIN tiers ti    ON ti.id = l.tier_id
     LEFT JOIN maquinas m  ON m.fingerprint = l.fingerprint
   $whereSql
   ORDER BY t.status='pendente' DESC, t.id DESC
   LIMIT 200");
$st->execute($args);
$trocas = $st->fetchAll();

$cont = db()->query(
  "SELECT SUM(status='pendente') AS pendentes,
          SUM(status='aprovada') AS aprovadas,
          SUM(status='negada')   AS negadas
     FROM trocas_cliente")->fetch();

abre_pagina('Trocas de cliente', 'trocas');
?>
<h1 class="titulo">Trocas de cliente</h1>
<p class="subtitulo">
  Solicitações dos revendedores para revincular uma licença a outro cliente
</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="stats">
  <div class="stat"><div class="n" style="color:var(--ambar)"><?= (int)$cont['pendentes'] ?></div>
    <div class="l">Aguardando você</div></div>
  <div class="stat"><div class="n" style="color:var(--verde)"><?= (int)$cont['aprovadas'] ?></div>
    <div class="l">Aprovadas</div></div>
  <div class="stat"><div class="n"><?= (int)$cont['negadas'] ?></div>
    <div class="l">Negadas</div></div>
</div>

<div class="card">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end">
    <div>
      <label>Mostrar</label>
      <select name="status" onchange="this.form.submit()">
        <option value="pendente" <?= $fStatus==='pendente'?'selected':'' ?>>Pendentes</option>
        <option value="aprovada" <?= $fStatus==='aprovada'?'selected':'' ?>>Aprovadas</option>
        <option value="negada"   <?= $fStatus==='negada'  ?'selected':'' ?>>Negadas</option>
        <option value=""         <?= $fStatus===''        ?'selected':'' ?>>Todas</option>
      </select>
    </div>
  </form>
</div>

<?php if (!$trocas): ?>
  <div class="card">
    <p class="subtitulo" style="margin:0">
      <?= $fStatus==='pendente'
          ? 'Nenhuma solicitação aguardando decisão.'
          : 'Nenhuma solicitação com este filtro.' ?>
    </p>
  </div>
<?php else: foreach ($trocas as $t):
    $pend  = $t['status']==='pendente';
    $borda = $pend ? 'var(--ambar)'
           : ($t['status']==='aprovada' ? 'var(--verde)' : 'var(--vermelho)');
    $revRot = $t['rev_fantasia'] ?: ($t['rev_empresa'] ?: $t['rev_nome']);
?>
  <div class="card" style="border-left:3px solid <?= $borda ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
      <div>
        <h3 style="margin:0 0 4px">
          <?= e($t['cli_atual_fant'] ?: ($t['cli_atual_nome'] ?: '— sem cliente —')) ?>
          <span style="color:var(--texto-2)">&rarr;</span>
          <?= e($t['cli_novo_fant'] ?: $t['cli_novo_nome']) ?>
        </h3>
        <p class="subtitulo" style="margin:0">
          Solicitado por <b><?= e($revRot) ?></b> em
          <?= date('d/m/Y H:i', strtotime($t['criado_em'])) ?>
        </p>
      </div>
      <span class="badge <?= $pend ? 'nova'
            : ($t['status']==='aprovada' ? 'ativa' : 'revogada') ?>">
        <?= e($t['status']) ?></span>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:16px">
      <div>
        <table style="font-size:12px">
          <tr><td style="color:var(--texto-2)">Licença</td>
              <td class="mono"><?= e($t['chave']) ?></td></tr>
          <tr><td style="color:var(--texto-2)">Software</td>
              <td><?= e(strtoupper($t['produto'] ?? '—')) ?>
                  <?= $t['tier'] ? '· '.e($t['tier']) : '' ?></td></tr>
          <tr><td style="color:var(--texto-2)">Expira</td>
              <td><?= date('d/m/Y', strtotime($t['expira_em'])) ?></td></tr>
          <tr><td style="color:var(--texto-2)">Máquina</td>
              <td><?= $t['fingerprint']
                      ? e($t['maq_nome'] ?: substr($t['fingerprint'],0,14).'…')
                      : '<span style="color:var(--azul)">não ativada</span>' ?></td></tr>
          <?php if ($t['ultimo_acesso']): ?>
            <tr><td style="color:var(--texto-2)">Último acesso</td>
                <td><?= date('d/m/Y', strtotime($t['ultimo_acesso'])) ?></td></tr>
          <?php endif; ?>
          <tr><td style="color:var(--texto-2)">Transferências</td>
              <td><?= (int)$t['transferencias'] ?> já usadas</td></tr>
        </table>
      </div>

      <div>
        <label style="font-size:11px">Motivo informado</label>
        <p style="font-size:12px;margin:4px 0 0;font-style:italic">
          <?= $t['motivo'] ? '"'.e($t['motivo']).'"' : 'não informado' ?>
        </p>
        <?php if (!$pend): ?>
          <p class="subtitulo" style="margin:12px 0 0">
            <?= e($t['status']==='aprovada' ? 'Aprovada' : 'Negada') ?> por
            <?= e($t['decidido_por_nome'] ?: '—') ?> em
            <?= $t['decidido_em'] ? date('d/m/Y H:i', strtotime($t['decidido_em'])) : '—' ?>
          </p>
          <?php if ($t['observacao_admin']): ?>
            <p style="font-size:12px;margin:6px 0 0;font-style:italic">
              "<?= e($t['observacao_admin']) ?>"</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($pend): ?>
      <div style="border-top:1px solid var(--borda);margin-top:16px;padding-top:14px">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $t['id'] ?>">
          <label>Observação (fica registrada)</label>
          <input name="observacao_admin" placeholder="opcional na aprovação, recomendada ao negar">

          <?php if ($t['fingerprint']): ?>
            <label style="display:flex;align-items:center;gap:8px;
                   text-transform:none;margin:12px 0 0">
              <input type="checkbox" name="liberar_maquina" value="1"
                     style="width:auto" checked>
              Liberar a máquina junto
              <span style="color:var(--texto-2);font-size:11px">
                — o cliente novo provavelmente usa outro PC; não consome
                o limite de transferências</span>
            </label>
          <?php endif; ?>

          <div style="margin-top:14px">
            <button class="btn" name="acao" value="aprovar">Aprovar troca</button>
            <button class="btn perigo" name="acao" value="negar"
                    style="margin-left:8px"
                    onclick="return confirm('Negar esta solicitação?')">Negar</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>
<?php fecha_pagina();
