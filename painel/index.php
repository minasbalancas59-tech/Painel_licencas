<?php
require 'inc/auth.php';
require 'inc/layout.php';
exige_login();

$total     = db()->query('SELECT COUNT(*) FROM licencas')->fetchColumn();
$ativas    = db()->query("SELECT COUNT(*) FROM licencas WHERE status='ativa'")->fetchColumn();
$clientes  = db()->query('SELECT COUNT(*) FROM clientes')->fetchColumn();
$expirando = db()->query(
    "SELECT COUNT(*) FROM licencas
      WHERE status='ativa' AND expira_em BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
)->fetchColumn();

$ultimos = db()->query(
    "SELECT a.criado_em, a.acao, a.resultado, a.chave, c.razao_social
       FROM ativacoes_log a
       LEFT JOIN licencas l ON l.id = a.licenca_id
       LEFT JOIN clientes c ON c.id = l.cliente_id
      ORDER BY a.id DESC LIMIT 12"
)->fetchAll();

abre_pagina('Painel', 'painel');
?>
<h1 class="titulo">Visão geral</h1>
<p class="subtitulo">Situação das licenças e atividade recente</p>

<div class="stats">
  <div class="stat"><div class="n"><?= $ativas ?></div><div class="l">Licenças ativas</div></div>
  <div class="stat"><div class="n"><?= $total ?></div><div class="l">Total emitidas</div></div>
  <div class="stat"><div class="n"><?= $clientes ?></div><div class="l">Clientes</div></div>
  <div class="stat"><div class="n"><?= $expirando ?></div><div class="l">Expiram em 30 dias</div></div>
</div>

<div class="card">
  <h3>Atividade recente</h3>
  <table>
    <thead><tr><th>Quando</th><th>Ação</th><th>Chave</th><th>Cliente</th><th>Resultado</th></tr></thead>
    <tbody>
    <?php if (!$ultimos): ?>
      <tr><td colspan="5" style="color:var(--texto-2)">Nenhuma atividade ainda.</td></tr>
    <?php else: foreach ($ultimos as $r): ?>
      <tr>
        <td class="mono"><?= date('d/m H:i', strtotime($r['criado_em'])) ?></td>
        <td><?= e($r['acao']) ?></td>
        <td class="mono"><?= e($r['chave'] ?? '—') ?></td>
        <td><?= e($r['razao_social'] ?? '—') ?></td>
        <td>
          <?php $cor = $r['resultado']==='ok'?'ativa':($r['resultado']==='negado'?'revogada':'expirada'); ?>
          <span class="badge <?= $cor ?>"><?= e($r['resultado']) ?></span>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php fecha_pagina();
