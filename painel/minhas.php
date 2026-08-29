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
 *  Mostra tambem a validade: o software ja avisa o cliente quando esta
 *  perto de vencer, entao o revendedor precisa da mesma informacao para
 *  conseguir atender.
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

// --- solicitar troca de cliente -------------------------------------
// O revendedor NAO troca sozinho: uma licenca que muda de cliente a
// vontade vira a mesma licenca vendida duas vezes. Ele pede, o admin
// decide. Excecao: licenca demo, que existe justamente para circular.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='solicitar_troca') {
    if (!csrf_valido()) { $msg='Sessão inválida.'; $tipo='erro'; }
    else {
        $licId = (int)($_POST['licenca_id'] ?? 0);
        $novoId= (int)($_POST['cliente_novo'] ?? 0);
        $motivo= trim($_POST['motivo'] ?? '');
        $lic = exige_licenca_do_usuario($licId);
        $cli = exige_cliente_do_usuario($novoId);

        if (empty($lic['cliente_id'])) {
            $msg='Esta licença ainda não tem cliente. Use "Vincular" acima.';
            $tipo='erro';
        } elseif ((int)$lic['cliente_id'] === $novoId) {
            $msg='A licença já está neste cliente.'; $tipo='erro';
        } elseif ($motivo === '') {
            $msg='Descreva o motivo da troca.'; $tipo='erro';
        } else {
            $jaTem = db()->prepare(
              'SELECT 1 FROM trocas_cliente
                WHERE licenca_id=? AND status="pendente" LIMIT 1');
            $jaTem->execute([$licId]);
            if ($jaTem->fetchColumn()) {
                $msg='Já existe uma solicitação pendente para esta licença.';
                $tipo='erro';
            } else {
                db()->prepare(
                  'INSERT INTO trocas_cliente
                     (licenca_id,revendedor_id,cliente_atual,cliente_novo,motivo)
                   VALUES (?,?,?,?,?)')
                  ->execute([$licId, $rev, $lic['cliente_id'], $novoId, $motivo]);

                $u = usuario_logado();
                log_acao_painel($licId, $lic['chave'], null,
                    'solicitar_troca', 'ok', $u['id'], $u['nome'] ?? null,
                    $lic['produto_codigo'], $lic['tier_codigo'],
                    'para cliente: '.$cli['razao_social'].' - '.$motivo);

                $msg='Solicitação enviada. Você será avisado quando for '
                   . 'analisada pelo administrador.';
                $tipo='ok';
            }
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

// --- filtros ---------------------------------------------------------
$fSit   = trim($_GET['sit'] ?? '');     // livre | vinculada | vencendo | vencidas
$fBusca = trim($_GET['q'] ?? '');

[$wEsc, $aEsc] = escopo_where('l');
$where = []; $args = [];
if ($wEsc) { $where[] = $wEsc; $args = array_merge($args, $aEsc); }

switch ($fSit) {
    case 'livre':      $where[] = 'l.cliente_id IS NULL'; break;
    case 'vinculada':  $where[] = 'l.cliente_id IS NOT NULL'; break;
    case 'vencendo':
        $where[] = "l.status='ativa' AND l.expira_em BETWEEN CURDATE() "
                 . "AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; break;
    case 'vencidas':
        $where[] = "l.expira_em < CURDATE()"; break;
}
if ($fBusca !== '') {
    $where[] = '(l.chave LIKE ? OR c.razao_social LIKE ? '
             . 'OR c.nome_fantasia LIKE ? OR m.maq_nome LIKE ?)';
    for ($i=0;$i<4;$i++) $args[] = '%'.$fBusca.'%';
}
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$licencas = db()->prepare(
  "SELECT l.*, c.razao_social, c.nome_fantasia,
          p.codigo AS produto_codigo, t.nome AS tier_nome,
          m.maq_nome, m.ultimo_acesso,
          DATEDIFF(l.expira_em, CURDATE()) AS dias_restantes
     FROM licencas l
     LEFT JOIN clientes c ON c.id = l.cliente_id
     LEFT JOIN produtos p ON p.id = l.produto_id
     LEFT JOIN tiers    t ON t.id = l.tier_id
     LEFT JOIN maquinas m ON m.fingerprint = l.fingerprint
   $whereSql
   ORDER BY l.cliente_id IS NOT NULL, l.expira_em");
$licencas->execute($args);
$licencas = $licencas->fetchAll();

// clientes do revendedor, para os selects de vinculo
[$wCli, $aCli] = escopo_where('c');
$stc = db()->prepare(
  'SELECT c.id, c.razao_social FROM clientes c '
  . ($wCli ? "WHERE $wCli" : '') . ' ORDER BY c.razao_social');
$stc->execute($aCli);
$clientes = $stc->fetchAll();

// solicitacoes em aberto, para marcar as linhas correspondentes
$pendTroca = [];
$stPT = db()->prepare(
  'SELECT licenca_id, criado_em FROM trocas_cliente
    WHERE revendedor_id=? AND status="pendente"');
$stPT->execute([$rev]);
foreach ($stPT->fetchAll() as $r) $pendTroca[(int)$r['licenca_id']] = $r['criado_em'];

// ultimas decisoes, para o revendedor saber o que foi resolvido
$stDec = db()->prepare(
  "SELECT t.*, c.razao_social AS novo_nome, l.chave
     FROM trocas_cliente t
     LEFT JOIN clientes c ON c.id=t.cliente_novo
     LEFT JOIN licencas l ON l.id=t.licenca_id
    WHERE t.revendedor_id=? AND t.status<>'pendente'
    ORDER BY t.decidido_em DESC LIMIT 5");
$stDec->execute([$rev]);
$decisoes = $stDec->fetchAll();

$livres = array_filter($licencas, fn($l) => empty($l['cliente_id']));
$vencendo = array_filter($licencas,
    fn($l) => $l['status']==='ativa'
           && (int)$l['dias_restantes'] >= 0
           && (int)$l['dias_restantes'] <= 30);

abre_pagina('Minhas licenças', 'minhas');
?>
<h1 class="titulo">Minhas licenças</h1>
<p class="subtitulo">Vincule ao cliente e libere a máquina quando ele trocar de PC</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<?php foreach ($decisoes as $d):
    $ok = $d['status']==='aprovada'; ?>
  <div class="card" style="padding:10px 16px;margin-bottom:10px;
       border-left:3px solid var(--<?= $ok ? 'verde' : 'vermelho' ?>)">
    <span style="font-size:13px">
      Troca da licença <span class="mono"><?= e($d['chave']) ?></span> para
      <b><?= e($d['novo_nome']) ?></b>
      foi <b><?= $ok ? 'aprovada' : 'negada' ?></b>
      em <?= $d['decidido_em'] ? date('d/m/Y', strtotime($d['decidido_em'])) : '—' ?>.
      <?php if ($d['observacao_admin']): ?>
        <br><span style="color:var(--texto-2);font-style:italic">
          "<?= e($d['observacao_admin']) ?>"</span>
      <?php endif; ?>
    </span>
  </div>
<?php endforeach; ?>

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
    <div>
      <div style="font-size:26px;font-weight:700;color:var(--ambar)"><?= count($vencendo) ?></div>
      <div class="subtitulo" style="margin:0">Vencem em 30 dias</div>
    </div>
  </div>
</div>

<div class="card">
  <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div style="flex:1;min-width:220px">
      <label>Buscar por chave, cliente ou máquina</label>
      <input type="text" name="q" value="<?= e($fBusca) ?>">
    </div>
    <div>
      <label>Situação</label>
      <select name="sit">
        <option value="">— todas —</option>
        <option value="livre"     <?= $fSit==='livre'    ?'selected':'' ?>>Livres</option>
        <option value="vinculada" <?= $fSit==='vinculada'?'selected':'' ?>>Vinculadas</option>
        <option value="vencendo"  <?= $fSit==='vencendo' ?'selected':'' ?>>Vencendo em 30 dias</option>
        <option value="vencidas"  <?= $fSit==='vencidas' ?'selected':'' ?>>Vencidas</option>
      </select>
    </div>
    <button class="btn">Filtrar</button>
    <?php if ($fSit || $fBusca): ?>
      <a class="btn sec" href="minhas.php">Limpar</a>
    <?php endif; ?>
  </form>
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
      <th>Situação</th><th>Expira</th><th>Máquina</th>
      <th>Transferências</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$licencas): ?>
      <tr><td colspan="8" style="color:var(--texto-2)">
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
        <td style="font-size:12px">
          <?php if ($l['cliente_id']): ?>
            <a href="cliente.php?id=<?= (int)$l['cliente_id'] ?>">
              <?= e($l['nome_fantasia'] ?: $l['razao_social']) ?></a>
          <?php else: ?>
            <span style="color:var(--texto-2)">— livre —</span>
          <?php endif; ?>
        </td>
        <td><span class="badge <?= e($sitCls) ?>"><?= e($sitTxt) ?></span></td>
        <td class="mono" style="font-size:11px">
          <?= date('d/m/Y', strtotime($l['expira_em'])) ?>
          <?php $dr = (int)$l['dias_restantes'];
                $cor = $dr < 0 ? 'var(--vermelho)'
                     : ($dr <= 30 ? 'var(--ambar)' : 'var(--texto-2)'); ?>
          <br><span style="font-size:10px;color:<?= $cor ?>">
            <?= $dr < 0 ? abs($dr).' dias atrás' : 'em '.$dr.' dias' ?>
          </span>
        </td>
        <td class="mono" style="font-size:11px">
          <?php if ($l['fingerprint']): ?>
            <?= e($l['maq_nome'] ?: substr($l['fingerprint'],0,14).'…') ?>
            <?php if ($l['ultimo_acesso']): ?>
              <br><span style="font-size:10px;color:var(--texto-2)">
                visto <?= date('d/m/Y', strtotime($l['ultimo_acesso'])) ?></span>
            <?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="mono" style="font-size:12px">
          <?php if ($ehDemo): ?>
            <span style="color:var(--texto-2)">livre</span>
          <?php else: ?>
            <?= $restam ?> de <?= (int)$l['max_transferencias'] ?>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if (isset($pendTroca[(int)$l['id']])): ?>
            <span class="badge nova" style="font-size:10px">troca solicitada</span>
          <?php elseif ($l['cliente_id'] && !$ehDemo): ?>
            <button type="button" class="btn sec pequeno"
                    onclick="pedirTroca(<?= $l['id'] ?>, '<?= e($l['chave']) ?>')">
              Trocar cliente</button>
          <?php endif; ?>
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

<!-- solicitacao de troca de cliente -->
<div id="modalTroca" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,.6);z-index:50;align-items:center;justify-content:center">
  <div class="card" style="max-width:520px;width:92%;margin:0">
    <h3 style="margin-top:0">Solicitar troca de cliente</h3>
    <p class="subtitulo" style="margin-top:-6px">
      A troca depende de aprovação do administrador. Licença:
      <span class="mono" id="mtChave"></span>
    </p>
    <form method="post">
      <input type="hidden" name="acao" value="solicitar_troca">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="licenca_id" id="mtId">

      <label>Novo cliente *</label>
      <select name="cliente_novo" required>
        <option value="">— selecione —</option>
        <?php foreach ($clientes as $c): ?>
          <option value="<?= $c['id'] ?>"><?= e($c['razao_social']) ?></option>
        <?php endforeach; ?>
      </select>

      <label style="margin-top:12px">Motivo *</label>
      <textarea name="motivo" required style="min-height:80px"
                placeholder="Ex: cliente devolveu o equipamento; cadastro feito no cliente errado; empresa mudou de CNPJ"></textarea>

      <div style="margin-top:14px">
        <button class="btn">Enviar solicitação</button>
        <button type="button" class="btn sec" style="margin-left:8px"
                onclick="document.getElementById('modalTroca').style.display='none'">
          Cancelar</button>
      </div>
    </form>
  </div>
</div>

<script>
function pedirTroca(id, chave) {
  document.getElementById('mtId').value = id;
  document.getElementById('mtChave').textContent = chave;
  document.getElementById('modalTroca').style.display = 'flex';
}
</script>
<?php fecha_pagina();
