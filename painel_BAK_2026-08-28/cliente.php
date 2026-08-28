<?php
require 'inc/auth.php';
require 'inc/layout.php';
exige_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: clientes.php'); exit; }

// dados do cliente
$stc = db()->prepare('SELECT * FROM clientes WHERE id=?');
$stc->execute([$id]);
$cli = $stc->fetch();
if (!$cli) { header('Location: clientes.php'); exit; }

// maquinas do cliente (ultimo acesso + contador)
$stm = db()->prepare(
  'SELECT m.*,
          (SELECT chave FROM licencas l WHERE l.id = m.licenca_id) AS chave,
          (SELECT t.codigo FROM licencas l
             JOIN tiers t ON t.id = l.tier_id
            WHERE l.id = m.licenca_id) AS tier_codigo,
          (SELECT p.codigo FROM licencas l
             JOIN produtos p ON p.id = l.produto_id
            WHERE l.id = m.licenca_id) AS produto_codigo
     FROM maquinas m
    WHERE m.cliente_id = ?
    ORDER BY m.ultimo_acesso DESC');
$stm->execute([$id]);
$maquinas = $stm->fetchAll();

// licencas do cliente (resumo)
$stl = db()->prepare(
  'SELECT l.*, t.codigo AS tier_codigo, p.codigo AS produto_codigo
     FROM licencas l
     LEFT JOIN tiers t    ON t.id = l.tier_id
     LEFT JOIN produtos p ON p.id = l.produto_id
    WHERE l.cliente_id = ?
    ORDER BY l.id DESC');
$stl->execute([$id]);
$licencas = $stl->fetchAll();

// helper: "há quanto tempo"
function tempoAtras($dt) {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    $diff = time() - $ts;
    if ($diff < 60)      return 'agora mesmo';
    if ($diff < 3600)    return floor($diff/60).' min atrás';
    if ($diff < 86400)   return floor($diff/3600).' h atrás';
    if ($diff < 2592000) return floor($diff/86400).' dia(s) atrás';
    return date('d/m/Y', $ts);
}

abre_pagina('Cliente', 'clientes');
?>
<p class="subtitulo" style="margin-bottom:4px">
  <a href="clientes.php">‹ Clientes</a>
</p>
<h1 class="titulo"><?= e($cli['razao_social']) ?></h1>
<p class="subtitulo">
  <?= e($cli['cnpj'] ?: 'sem CNPJ') ?>
  <?= $cli['contato'] ? ' · '.e($cli['contato']) : '' ?>
  <?= $cli['telefone'] ? ' · '.e($cli['telefone']) : '' ?>
</p>

<div class="card">
  <h3>Máquinas deste cliente (<?= count($maquinas) ?>)</h3>
  <table>
    <thead><tr>
      <th>Máquina</th><th>Usuário</th><th>Sistema</th>
      <th>Software/Tipo</th><th>Aberturas</th><th>Último acesso</th>
    </tr></thead>
    <tbody>
    <?php if (!$maquinas): ?>
      <tr><td colspan="6" style="color:var(--texto-2)">
        Nenhuma máquina registrada ainda. As máquinas aparecem aqui quando o
        Total Scale é aberto com internet.
      </td></tr>
    <?php else: foreach ($maquinas as $m):
        $prodTier = $m['produto_codigo']
            ? strtoupper($m['produto_codigo']).($m['tier_codigo']?' · '.$m['tier_codigo']:'')
            : '—';
    ?>
      <tr>
        <td title="<?= e($m['fingerprint']) ?>">
          <b><?= e($m['maq_nome'] ?: '(sem nome)') ?></b>
        </td>
        <td style="font-size:12px"><?= e($m['maq_usuario'] ?: '—') ?></td>
        <td style="font-size:11px;color:var(--texto-2)"><?= e($m['maq_so'] ?: '—') ?></td>
        <td class="mono" style="font-size:11px"><?= e($prodTier) ?></td>
        <td class="mono"><?= (int)$m['aberturas'] ?></td>
        <td style="font-size:12px" title="<?= e($m['ultimo_acesso'] ?: '') ?>">
          <?= e(tempoAtras($m['ultimo_acesso'])) ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Licenças deste cliente</h3>
  <table>
    <thead><tr><th>Chave</th><th>Software/Tipo</th><th>Expira</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$licencas): ?>
      <tr><td colspan="4" style="color:var(--texto-2)">Nenhuma licença emitida.</td></tr>
    <?php else: foreach ($licencas as $l):
        $prodTier = $l['produto_codigo']
            ? strtoupper($l['produto_codigo']).($l['tier_codigo']?' · '.$l['tier_codigo']:'')
            : '—';
    ?>
      <tr>
        <td class="mono" style="font-size:11px"><?= e($l['chave']) ?></td>
        <td class="mono" style="font-size:11px"><?= e($prodTier) ?></td>
        <td class="mono"><?= date('d/m/Y', strtotime($l['expira_em'])) ?></td>
        <td><span class="badge <?= e($l['status']) ?>"><?= e($l['status']) ?></span></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <a class="btn" style="margin-top:14px" href="licencas.php?cliente=<?= $id ?>">Emitir nova licença</a>
</div>
<?php fecha_pagina();
