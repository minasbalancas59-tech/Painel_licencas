<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  VOLUME DE PESAGENS
 * =====================================================================
 *  Responde as tres perguntas da renovacao:
 *
 *    quem usa MUITO   -> justifica o preco, candidato a upgrade
 *    quem usa POUCO   -> risco de nao renovar
 *    quem esta CAINDO -> o alerta antecipado; um cliente que despencou
 *                        de 800 para 200 pesagens/mes ja decidiu sair,
 *                        so nao avisou
 *
 *  A comparacao e "mes atual x media dos 3 anteriores". Comparar com o
 *  mesmo mes do ano passado seria melhor estatisticamente, mas exigiria
 *  13 meses de historico - que ninguem tem ainda.
 *
 *  ATENCAO: os dados comecam quando o cliente recebe a versao nova do
 *  software. Cliente sem numero aqui nao e cliente parado - e cliente
 *  que ainda nao atualizou.
 * ===================================================================== */

$mesAtual = date('Y-m');
$mesRef   = trim($_GET['mes'] ?? $mesAtual);
if (!preg_match('/^\d{4}-\d{2}$/', $mesRef)) $mesRef = $mesAtual;

$fProd = trim($_GET['produto'] ?? '');
$produtos = db()->query(
  'SELECT codigo, nome FROM produtos WHERE ativo=1 ORDER BY codigo')->fetchAll();
if ($fProd !== '' && !in_array($fProd, array_column($produtos,'codigo'), true))
    $fProd = '';

$wProd = $fProd === '' ? '' : 'AND m.produto = ' . db()->quote($fProd);

// meses com dado, para o seletor
$meses = db()->query(
  'SELECT DISTINCT ano_mes FROM pesagens_mensal ORDER BY ano_mes DESC LIMIT 24')
  ->fetchAll(PDO::FETCH_COLUMN);
if (!$meses) $meses = [$mesAtual];

/* ---------------------------------------------------------------------
 *  quadro por cliente: mes de referencia x media dos 3 anteriores
 * ------------------------------------------------------------------- */
$tresAntes = date('Y-m', strtotime($mesRef . '-01 -3 months'));

$st = db()->prepare(
  "SELECT c.id, c.razao_social, c.nome_fantasia,
          SUM(CASE WHEN m.ano_mes = :mes THEN m.pesagens ELSE 0 END) AS atual,
          -- media dos 3 meses anteriores ao de referencia
          ROUND(SUM(CASE WHEN m.ano_mes < :mes2 AND m.ano_mes >= :tres
                         THEN m.pesagens ELSE 0 END) / 3) AS media_ant,
          COUNT(DISTINCT m.fingerprint) AS maquinas,
          MAX(m.produto) AS produto
     FROM pesagens_mensal m
     JOIN clientes c ON c.id = m.cliente_id
    WHERE m.ano_mes >= :tres2 AND m.ano_mes <= :mes3
      $wProd
    GROUP BY c.id
   HAVING atual > 0 OR media_ant > 0
    ORDER BY atual DESC");
$st->execute([
  ':mes'  => $mesRef, ':mes2' => $mesRef, ':mes3' => $mesRef,
  ':tres' => $tresAntes, ':tres2' => $tresAntes,
]);
$linhas = $st->fetchAll();

// classifica cada cliente pela variacao
foreach ($linhas as &$l) {
    $a = (int)$l['atual']; $m = (int)$l['media_ant'];
    $l['var'] = ($m > 0) ? round((($a - $m) / $m) * 100) : null;
    // ordem importa: 'parou' precisa vir antes de 'queda', senao um
    // cliente com zero cairia na regra dos 50% e apareceria so como
    // "em queda" - perdendo o alerta mais grave
    if ($a === 0 && $m > 0)             $l['sinal'] = 'parou';
    elseif ($m === 0 && $a > 0)         $l['sinal'] = 'novo';
    elseif ($m > 0 && $a <= $m * 0.5)   $l['sinal'] = 'queda';
    elseif ($m > 0 && $a >= $m * 1.3)   $l['sinal'] = 'alta';
    else                                $l['sinal'] = '';
}
unset($l);

$totalMes  = array_sum(array_column($linhas, 'atual'));
$emQueda   = count(array_filter($linhas, fn($x) => in_array($x['sinal'], ['queda','parou'])));
$emAlta    = count(array_filter($linhas, fn($x) => $x['sinal'] === 'alta'));

/* ---------------------------------------------------------------------
 *  serie dos ultimos 12 meses, para o grafico
 * ------------------------------------------------------------------- */
$serie = db()->query(
  "SELECT m.ano_mes, SUM(m.pesagens) AS n
     FROM pesagens_mensal m
    WHERE m.ano_mes >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m')
      " . ($fProd === '' ? '' : 'AND m.produto = ' . db()->quote($fProd)) . "
    GROUP BY m.ano_mes ORDER BY m.ano_mes")->fetchAll();

function fmtMes(string $am): string {
    $m = ['01'=>'jan','02'=>'fev','03'=>'mar','04'=>'abr','05'=>'mai','06'=>'jun',
          '07'=>'jul','08'=>'ago','09'=>'set','10'=>'out','11'=>'nov','12'=>'dez'];
    [$a, $mm] = explode('-', $am);
    return ($m[$mm] ?? $mm) . '/' . substr($a, 2);
}

abre_pagina('Volume de pesagens', 'pesagens');
?>
<h1 class="titulo">Volume de pesagens</h1>
<p class="subtitulo">
  Quanto cada cliente realmente usa o sistema — o argumento da renovação
</p>

<?php if (!$linhas && !$serie): ?>
  <div class="card" style="border-left:3px solid var(--azul)">
    <h3>Ainda sem dados</h3>
    <p style="margin:0;font-size:13px">
      O volume começa a ser registrado quando o cliente recebe a versão do
      software que reporta as pesagens. Clientes que ainda não atualizaram
      não aparecem aqui — <b>ausência de número não significa cliente
      parado</b>.
    </p>
  </div>
<?php endif; ?>

<div class="card">
  <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div>
      <label>Mês de referência</label>
      <select name="mes" onchange="this.form.submit()">
        <?php foreach ($meses as $m): ?>
          <option value="<?= e($m) ?>" <?= $m===$mesRef?'selected':'' ?>>
            <?= e(fmtMes($m)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Software</label>
      <select name="produto" onchange="this.form.submit()">
        <option value="">— todos —</option>
        <?php foreach ($produtos as $p): ?>
          <option value="<?= e($p['codigo']) ?>" <?= $fProd===$p['codigo']?'selected':'' ?>>
            <?= e($p['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<div class="stats">
  <div class="stat"><div class="n"><?= number_format($totalMes,0,',','.') ?></div>
    <div class="l">Pesagens em <?= e(fmtMes($mesRef)) ?></div></div>
  <div class="stat"><div class="n"><?= count($linhas) ?></div>
    <div class="l">Clientes reportando</div></div>
  <div class="stat"><div class="n" style="color:var(--vermelho)"><?= $emQueda ?></div>
    <div class="l">Em queda ou parados</div></div>
  <div class="stat"><div class="n" style="color:var(--verde)"><?= $emAlta ?></div>
    <div class="l">Em alta</div></div>
</div>

<?php if ($serie): ?>
<div class="card">
  <h3>Total por mês</h3>
  <canvas id="gSerie" height="90"></canvas>
</div>
<?php endif; ?>

<div class="card">
  <h3>Por cliente</h3>
  <p class="subtitulo" style="margin-top:-6px">
    Comparado com a média dos 3 meses anteriores. Ordenado do maior volume
    para o menor.
  </p>

  <table>
    <thead><tr>
      <th>Cliente</th><th>Software</th><th>Máquinas</th>
      <th style="text-align:right"><?= e(fmtMes($mesRef)) ?></th>
      <th style="text-align:right">Média 3 meses</th>
      <th>Variação</th>
    </tr></thead>
    <tbody>
    <?php if (!$linhas): ?>
      <tr><td colspan="6" style="color:var(--texto-2)">
        Nenhum cliente reportou pesagens neste período.</td></tr>
    <?php else: foreach ($linhas as $l):
        switch ($l['sinal']) {
          case 'queda': $cor='var(--vermelho)'; $rot='em queda'; break;
          case 'parou': $cor='var(--vermelho)'; $rot='parou de usar'; break;
          case 'alta':  $cor='var(--verde)';    $rot='em alta'; break;
          case 'novo':  $cor='var(--azul)';     $rot='começou agora'; break;
          default:      $cor='var(--texto-2)';  $rot=''; break;
        }
    ?>
      <tr>
        <td><a href="cliente.php?id=<?= (int)$l['id'] ?>">
          <?= e($l['nome_fantasia'] ?: $l['razao_social']) ?></a></td>
        <td class="mono" style="font-size:11px">
          <?= e(strtoupper($l['produto'] ?? '—')) ?></td>
        <td class="mono"><?= (int)$l['maquinas'] ?></td>
        <td class="mono" style="text-align:right;font-weight:bold">
          <?= number_format((int)$l['atual'],0,',','.') ?></td>
        <td class="mono" style="text-align:right;color:var(--texto-2)">
          <?= number_format((int)$l['media_ant'],0,',','.') ?></td>
        <td style="color:<?= $cor ?>;font-size:12px">
          <?php if ($l['var'] !== null): ?>
            <?= $l['var'] > 0 ? '+' : '' ?><?= (int)$l['var'] ?>%
          <?php endif; ?>
          <?php if ($rot): ?>
            <br><span style="font-size:11px"><?= $rot ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ($serie): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color = '#93a1ac';
new Chart(document.getElementById('gSerie'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map('fmtMes', array_column($serie,'ano_mes'))) ?>,
    datasets: [{ label: 'Pesagens',
      data: <?= json_encode(array_map('intval', array_column($serie,'n'))) ?>,
      backgroundColor: '#f0a92b' }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, grid: { color: '#313a42' } },
              x: { grid: { display: false } } }
  }
});
</script>
<?php endif; ?>
<?php fecha_pagina();
