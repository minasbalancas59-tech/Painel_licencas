<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();

/* =====================================================================
 *  MINHAS LICENÇAS  -  painel do revendedor
 * =====================================================================
 *  O revendedor NAO emite licenca: ele recebe um estoque do admin e
 *  aqui faz duas coisas:
 *
 *    vincular  - liga uma licenca livre a um cliente final dele
 *    liberar   - quando o PC do cliente queima, solta a maquina para
 *                que ele reative no PC novo (ate max_transferencias)
 *
 *  Trocar o cliente de uma licenca ja vinculada NAO e feito aqui:
 *  depende de aprovacao do admin (ver trocas.php). A excecao sao as
 *  licencas de demonstracao, que o revendedor movimenta a vontade.
 *
 *  A data de vencimento nao aparece nesta tela - so o estado.
 * ===================================================================== */

$msg=''; $tipo='';
$rev = revendedor_atual();

// --- vincular licenca a um cliente ----------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='vincular') {
    if (!csrf_valido()) { $msg='Sessão inválida.'; $tipo='erro'; }
    else {
        $licId = (int)($_POST['licenca_id'] ?? 0);
        $cliId = (int)($_POST['cliente_id'] ?? 0);
        $lic = exige_licenca_do_usuario($licId);
        $cli = exige_cliente_do_usuario($cliId);

        if (!empty($lic['cliente_id']) && $lic['tipo_licenca'] !== 'demo') {
            $msg = 'Esta licença já está vinculada a um cliente. '
                 . 'Para trocar, solicite aprovação do administrador.';
            $tipo='erro';
        } else {
            db()->prepare('UPDATE licencas SET cliente_id=? WHERE id=?')
                ->execute([$cliId, $licId]);
            $u = usuario_logado();
            log_acao_painel($licId, $lic['chave'], null, 'vincular_cliente', 'ok',
                $u['id'], $u['nome'] ?? null,
                $lic['produto_codigo'], $lic['tier_codigo'],
                'cliente: '.$cli['razao_social']);
            $msg='Licença vinculada a '.$cli['razao_social'].'.'; $tipo='ok';
        }
    }
}

// --- liberar maquina (PC queimou / trocou de equipamento) -----------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='liberar') {
    if (!csrf_valido()) { $msg='Sessão inválida.'; $tipo='erro'; }
    else {
        $licId = (int)($_POST['licenca_id'] ?? 0);
        $lic = exige_licenca_do_usuario($licId);

        if (empty($lic['fingerprint'])) {
            $msg='Esta licença não está presa a nenhuma máquina.'; $tipo='erro';
        } elseif ($lic['tipo_licenca'] !== 'demo'
                  && transferencias_restantes($lic) <= 0) {
            $msg='Limite de transferências atingido. '
               . 'Solicite uma nova licença ao administrador.';
            $tipo='erro';
        } else {
            $fpAntigo = $lic['fingerprint'];
            // demo nao consome o contador: e para instalar e testar
            $inc = $lic['tipo_licenca'] === 'demo' ? 0 : 1;
            db()->prepare(
              'UPDATE licencas
                  SET fingerprint=NULL, status="nova",
                      transferencias = transferencias + ?
                WHERE id=?')->execute([$inc, $licId]);

            $u = usuario_logado();
            log_acao_painel($licId, $lic['chave'], $fpAntigo,
                'liberar_maquina', 'ok', $u['id'], $u['nome'] ?? null,
                $lic['produto_codigo'], $lic['tier_codigo'],
                'liberada para nova maquina');
            $msg='Máquina liberada. O cliente já pode ativar no PC novo '
               . 'usando a mesma chave.';
            $tipo='ok';
        }
    }
}

// --- consultas -------------------------------------------------------
[$wEsc, $aEsc] = escopo_where('l');
$whereSql = $wEsc ? "WHERE $wEsc" : '';

$licencas = db()->prepare(
  "SELECT l.*, c.razao_social,
          p.codigo AS produto_codigo, t.nome AS tier_nome
     FROM licencas l
     LEFT JOIN clientes c ON c.id = l.cliente_id
     LEFT JOIN produtos p ON p.id = l.produto_id
     LEFT JOIN tiers    t ON t.id = l.tier_id
   $whereSql
   ORDER BY l.cliente_id IS NOT NULL, l.id DESC");
$licencas->execute($aEsc);
$licencas = $licencas->fetchAll();

// clientes do revendedor, para os selects de vinculo
[$wCli, $aCli] = escopo_where('c');
$stc = db()->prepare(
  'SELECT c.id, c.razao_social FROM clientes c '
  . ($wCli ? "WHERE $wCli" : '') . ' ORDER BY c.razao_social');
$stc->execute($aCli);
$clientes = $stc->fetchAll();

$livres = array_filter($licencas, fn($l) => empty($l['cliente_id']));

abre_pagina('Minhas licenças', 'minhas');
?>
<h1 class="titulo">Minhas licenças</h1>
<p class="subtitulo">Vincule ao cliente e libere a máquina quando ele trocar de PC</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
  <div style="display:flex;gap:34px;flex-wrap:wrap">
    <div>
      <div style="font-size:26px;font-weight:700"><?= count($licencas) ?></div>
      <div class="subtitulo" style="margin:0">Licenças no seu estoque</div>
    </div>
    <div>
      <div style="font-size:26px;font-weight:700;color:var(--verde,#38b26b)"><?= count($livres) ?></div>
      <div class="subtitulo" style="margin:0">Livres para vincular</div>
    </div>
  </div>
</div>

<?php if ($livres && $clientes): ?>
<div class="card">
  <h3>Vincular licença a um cliente</h3>
  <form method="post">
    <input type="hidden" name="acao" value="vincular">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div>
        <label>Licença livre *</label>
        <select name="licenca_id" required>
          <option value="">— selecione —</option>
          <?php foreach ($livres as $l): ?>
            <option value="<?= $l['id'] ?>">
              <?= e($l['chave']) ?> ·
              <?= e(strtoupper($l['produto_codigo'] ?? '?')) ?>
              <?= e($l['tier_nome'] ? '· '.$l['tier_nome'] : '') ?>
              <?= $l['tipo_licenca']==='demo' ? ' (demonstração)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Cliente *</label>
        <select name="cliente_id" required>
          <option value="">— selecione —</option>
          <?php foreach ($clientes as $c): ?>
            <option value="<?= $c['id'] ?>"><?= e($c['razao_social']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button class="btn" style="margin-top:14px">Vincular</button>
  </form>
</div>
<?php elseif (!$clientes): ?>
<div class="card">
  <p class="subtitulo" style="margin:0">
    Cadastre primeiro os seus clientes em <a href="clientes.php">Clientes</a>
    para poder vincular as licenças.
  </p>
</div>
<?php endif; ?>

<div class="card">
  <h3>Estoque</h3>
  <table>
    <thead><tr>
      <th>Chave</th><th>Software / Tipo</th><th>Cliente</th>
      <th>Situação</th><th>Máquina</th><th>Transferências</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$licencas): ?>
      <tr><td colspan="7" style="color:var(--texto-2)">
        Nenhuma licença atribuída a você ainda.
      </td></tr>
    <?php else: foreach ($licencas as $l):
        [$sitTxt, $sitCls] = situacao_licenca($l);
        $restam = transferencias_restantes($l);
        $ehDemo = $l['tipo_licenca'] === 'demo';
    ?>
      <tr>
        <td class="mono" style="font-size:12px">
          <?= e($l['chave']) ?>
          <?php if ($ehDemo): ?>
            <br><span class="badge nova" style="font-size:10px">demonstração</span>
          <?php endif; ?>
        </td>
        <td style="font-size:12px">
          <?= e(strtoupper($l['produto_codigo'] ?? '—')) ?>
          <?= $l['tier_nome'] ? ' · '.e($l['tier_nome']) : '' ?>
        </td>
        <td style="font-size:12px"><?= e($l['razao_social'] ?: '— livre —') ?></td>
        <td><span class="badge <?= e($sitCls) ?>"><?= e($sitTxt) ?></span></td>
        <td class="mono" style="font-size:11px">
          <?= $l['fingerprint'] ? e(substr($l['fingerprint'],0,14)).'…' : '—' ?>
        </td>
        <td class="mono" style="font-size:12px">
          <?php if ($ehDemo): ?>
            <span style="color:var(--texto-2)">livre</span>
          <?php else: ?>
            <?= $restam ?> de <?= (int)$l['max_transferencias'] ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($l['fingerprint'] && ($ehDemo || $restam > 0)): ?>
            <form method="post" onsubmit="return confirm('Liberar a máquina desta licença? O cliente precisará ativar novamente no PC novo.')">
              <input type="hidden" name="acao" value="liberar">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="licenca_id" value="<?= $l['id'] ?>">
              <button class="btn sec pequeno">Liberar máquina</button>
            </form>
          <?php elseif ($l['fingerprint']): ?>
            <span class="subtitulo" style="margin:0;font-size:11px">
              limite atingido
            </span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php fecha_pagina();
