<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  PAINEL - visao gerencial
 * =====================================================================
 *  Graficos alimentados por tres fontes:
 *    licencas      -> emissao, status, produto, revendedor
 *    acessos       -> uso real (sinais de abertura do software)
 *    maquinas      -> parque instalado e migracao do dongle
 *
 *  NOTA: o banco nao guarda valor monetario. "Vendas" aqui significa
 *  QUANTIDADE de licencas emitidas. Para faturamento real seria preciso
 *  uma coluna `valor` em licencas (ver rodape desta tela).
 * ===================================================================== */

/* ---------------------------------------------------------------------
 *  FILTRO POR PRODUTO
 * ---------------------------------------------------------------------
 *  Vale para a tela inteira - indicadores, graficos e tabelas. Um
 *  filtro que age so em parte da tela confunde mais do que ajuda.
 *
 *  O produto vive em `licencas`. As tabelas `maquinas` e `acessos` nao
 *  o tem, entao precisam de JOIN. Consequencia: ao filtrar, maquinas
 *  sem licenca vinculada somem - o que e correto, porque nao da para
 *  afirmar de qual produto elas sao.
 * ------------------------------------------------------------------- */
$produtos = db()->query(
  'SELECT id, codigo, nome FROM produtos WHERE ativo=1 ORDER BY codigo')
  ->fetchAll();

$fProd = trim($_GET['produto'] ?? '');
$prodValidos = array_column($produtos, 'codigo');
if ($fProd !== '' && !in_array($fProd, $prodValidos, true)) $fProd = '';

$prodNome = '';
foreach ($produtos as $pp)
    if ($pp['codigo'] === $fProd) $prodNome = $pp['nome'];

// fragmentos prontos, para nao repetir o mesmo JOIN em 13 consultas
if ($fProd !== '') {
    $joinProd = 'JOIN produtos pf ON pf.id = l.produto_id AND pf.codigo = '
              . db()->quote($fProd);
    $wLic  = 'AND l.produto_id = (SELECT id FROM produtos WHERE codigo = '
           . db()->quote($fProd) . ')';
    $wLicW = 'WHERE l.produto_id = (SELECT id FROM produtos WHERE codigo = '
           . db()->quote($fProd) . ')';
} else {
    $joinProd = ''; $wLic = ''; $wLicW = '';
}

$anoAtual = (int)date('Y');
$fAno = (int)($_GET['ano'] ?? $anoAtual);

// ---- KPIs -----------------------------------------------------------
$kpi = db()->query(
  "SELECT
     COUNT(*)                                                        AS total,
     SUM(status='ativa')                                             AS ativas,
     SUM(cliente_id IS NULL)                                         AS estoque,
     SUM(tipo_licenca='demo')                                        AS demos,
     SUM(status='ativa' AND expira_em BETWEEN CURDATE()
         AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))                   AS expirando,
     SUM(YEAR(emitido_em)=YEAR(CURDATE()))                           AS ano_corrente,
     SUM(YEAR(emitido_em)=YEAR(CURDATE())
         AND MONTH(emitido_em)=MONTH(CURDATE()))                     AS mes_corrente
   FROM licencas l $wLicW")->fetch();

$clientesTotal = $fProd === ''
  ? db()->query('SELECT COUNT(*) FROM clientes')->fetchColumn()
  : db()->query("SELECT COUNT(DISTINCT l.cliente_id) FROM licencas l $wLicW")
        ->fetchColumn();

// maquinas sem licenca vinculada saem quando ha filtro: nao da para
// dizer de qual produto elas sao
$maq = db()->query(
  "SELECT COUNT(*) AS total,
          SUM(m.ultimo_acesso >= DATE_SUB(NOW(), INTERVAL 7 DAY))  AS ativas7,
          SUM(m.ultimo_acesso >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS ativas30,
          SUM(m.origem='dongle')                                   AS dongle
     FROM maquinas m " .
  ($fProd === '' ? '' :
     "JOIN licencas l ON l.id = m.licenca_id $wLic ")
  )->fetch();

// ---- rotulos dos ultimos 12 meses ------------------------------------
// preenchidos mesmo sem emissao, senao o grafico "pula" periodos vazios
$labMes = [];
for ($i = 11; $i >= 0; $i--) {
    $labMes[] = date('m/y', strtotime(date('Y-m', strtotime("-$i month")).'-01'));
}

// ---- emissao por ano -------------------------------------------------
$anos = db()->query(
  "SELECT YEAR(l.emitido_em) AS ano, COUNT(*) AS n
     FROM licencas l $wLicW GROUP BY ano ORDER BY ano")->fetchAll();
$labAno = array_column($anos, 'ano');
$datAno = array_map('intval', array_column($anos, 'n'));

// ---- uso diario (30 dias, sinais de abertura) ------------------------
$uso = db()->query(
  "SELECT DATE(a.ts) AS dia, COUNT(*) AS n
     FROM acessos a " .
  ($fProd === '' ? '' : "JOIN licencas l ON l.id = a.licenca_id $wLic ") .
  "WHERE a.tipo='abertura'
      AND a.ts >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY dia ORDER BY dia")->fetchAll();
$usoRaw = [];
foreach ($uso as $r) $usoRaw[$r['dia']] = (int)$r['n'];
$labDia = []; $datDia = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $labDia[] = date('d/m', strtotime($d));
    $datDia[] = $usoRaw[$d] ?? 0;
}

// ---- por produto -----------------------------------------------------
// so faz sentido sem filtro: com um produto escolhido seria uma
// fatia unica de 100%
$porProd = $fProd !== '' ? [] : db()->query(
  "SELECT COALESCE(p.nome,'(sem produto)') AS nome, COUNT(*) AS n
     FROM licencas l LEFT JOIN produtos p ON p.id=l.produto_id
    GROUP BY nome ORDER BY n DESC")->fetchAll();

// ---- por tier (o "tipo de licenca" da tela de emissao) ---------------
$porTier = db()->query(
  "SELECT CONCAT(UPPER(COALESCE(p.codigo,'?')),' - ',
                 COALESCE(t.nome,'(sem tipo)')) AS nome,
          COUNT(*) AS n
     FROM licencas l
     LEFT JOIN tiers t    ON t.id = l.tier_id
     LEFT JOIN produtos p ON p.id = l.produto_id
   $wLicW
    GROUP BY nome ORDER BY n DESC")->fetchAll();

// ---- emissao mensal separada por tier (barras empilhadas) ------------
$tiersLista = db()->query(
  "SELECT DISTINCT COALESCE(t.nome,'(sem tipo)') AS nome
     FROM licencas l LEFT JOIN tiers t ON t.id=l.tier_id
   $wLicW
    ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);

$stMT = db()->query(
  "SELECT DATE_FORMAT(l.emitido_em,'%Y-%m') AS mes,
          COALESCE(t.nome,'(sem tipo)') AS tier, COUNT(*) AS n
     FROM licencas l LEFT JOIN tiers t ON t.id=l.tier_id
    WHERE l.emitido_em >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH)
      $wLic
    GROUP BY mes, tier")->fetchAll();
$mtRaw = [];
foreach ($stMT as $r) $mtRaw[$r['mes']][$r['tier']] = (int)$r['n'];

// uma serie por tier, com zero nos meses sem emissao
$seriesTier = [];
foreach ($tiersLista as $tn) {
    $linha = [];
    for ($i = 11; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-$i month"));
        $linha[] = $mtRaw[$m][$tn] ?? 0;
    }
    $seriesTier[] = ['nome' => $tn, 'dados' => $linha];
}

// ---- venda x demonstracao -------------------------------------------
$porTipoLic = db()->query(
  "SELECT l.tipo_licenca, COUNT(*) AS n FROM licencas l
   $wLicW
    GROUP BY l.tipo_licenca ORDER BY n DESC")->fetchAll();

// ---- por revendedor --------------------------------------------------
$porRev = db()->query(
  "SELECT COALESCE(u.nome,'Venda direta') AS nome,
          COUNT(*) AS total,
          SUM(l.cliente_id IS NOT NULL) AS vinculadas,
          SUM(l.cliente_id IS NULL)     AS estoque,
          SUM(l.transferencias)         AS transf
     FROM licencas l LEFT JOIN usuarios u ON u.id=l.revendedor_id
   $wLicW
    GROUP BY nome ORDER BY total DESC")->fetchAll();

// ---- status ----------------------------------------------------------
$porStatus = db()->query(
  "SELECT l.status, COUNT(*) AS n FROM licencas l
   $wLicW GROUP BY l.status")->fetchAll();

// cor por status, na MESMA ordem dos labels (o Chart.js exige array,
// nao objeto: um objeto vira cores indefinidas e as fatias saem pretas)
$mapaCor = ['ativa'=>'#38b26b','nova'=>'#4a9fd4',
            'revogada'=>'#e0574e','expirada'=>'#93a1ac'];
$corStatus = [];
foreach ($porStatus as $ps) $corStatus[] = $mapaCor[$ps['status']] ?? '#93a1ac';

// ---- vencimentos proximos -------------------------------------------
$vencendo = db()->query(
  "SELECT l.chave, l.expira_em, c.razao_social, p.codigo AS produto,
          DATEDIFF(l.expira_em, CURDATE()) AS dias
     FROM licencas l
     LEFT JOIN clientes c ON c.id=l.cliente_id
     LEFT JOIN produtos p ON p.id=l.produto_id
    WHERE l.status='ativa'
      AND l.expira_em BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
      $wLic
    ORDER BY l.expira_em LIMIT 15")->fetchAll();

// ---- atividade recente ----------------------------------------------
$ultimos = db()->query(
  "SELECT a.criado_em, a.acao, a.resultado, a.chave, c.razao_social
     FROM ativacoes_log a
     LEFT JOIN licencas l ON l.id = a.licenca_id
     LEFT JOIN clientes c ON c.id = l.cliente_id
   $wLicW
    ORDER BY a.id DESC LIMIT 10")->fetchAll();

abre_pagina('Painel', 'painel');
?>
<h1 class="titulo">Visão geral</h1>
<p class="subtitulo">
  <?= $fProd === ''
      ? 'Licenças, uso do software e desempenho por revendedor'
      : 'Mostrando apenas ' . e($prodNome) ?>
</p>

<div style="display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap">
  <a class="btn <?= $fProd === '' ? '' : 'sec' ?> pequeno"
     href="index.php">Todos</a>
  <?php foreach ($produtos as $pp): ?>
    <a class="btn <?= $fProd === $pp['codigo'] ? '' : 'sec' ?> pequeno"
       href="index.php?produto=<?= e($pp['codigo']) ?>"><?= e($pp['nome']) ?></a>
  <?php endforeach; ?>
</div>

<div class="stats">
  <div class="stat"><div class="n"><?= (int)$kpi['ativas'] ?></div><div class="l">Licenças ativas</div></div>
  <div class="stat"><div class="n"><?= (int)$kpi['mes_corrente'] ?></div><div class="l">Emitidas no mês</div></div>
  <div class="stat"><div class="n"><?= (int)$kpi['ano_corrente'] ?></div><div class="l">Emitidas no ano</div></div>
  <div class="stat"><div class="n"><?= (int)$maq['ativas7'] ?></div><div class="l">Máquinas ativas (7d)</div></div>
</div>

<div class="stats">
  <div class="stat"><div class="n"><?= (int)$kpi['estoque'] ?></div><div class="l">Em estoque</div></div>
  <div class="stat"><div class="n"><?= (int)$clientesTotal ?></div><div class="l">Clientes</div></div>
  <div class="stat"><div class="n" style="color:var(--ambar)"><?= (int)$kpi['expirando'] ?></div><div class="l">Expiram em 30 dias</div></div>
  <div class="stat"><div class="n"><?= (int)$maq['dongle'] ?></div><div class="l">Ainda no dongle</div></div>
</div>

<div class="card">
  <h3>Licenças emitidas por mês, por tipo</h3>
  <canvas id="gEmissao" height="90"></canvas>
</div>

<div style="display:grid;grid-template-columns:<?= $porProd ? '1fr 1fr' : '1fr' ?>;gap:16px">
  <div class="card">
    <h3>Por ano</h3>
    <canvas id="gAno" height="<?= $porProd ? 150 : 90 ?>"></canvas>
  </div>
  <?php if ($porProd): ?>
    <div class="card">
      <h3>Por software</h3>
      <canvas id="gProduto" height="150"></canvas>
    </div>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
  <div class="card">
    <h3>Por tipo de licença</h3>
    <canvas id="gTier" height="120"></canvas>
  </div>
  <div class="card">
    <h3>Venda x demonstração</h3>
    <canvas id="gTipoLic" height="185"></canvas>
  </div>
</div>

<div class="card">
  <h3>Uso diário do software (aberturas, 30 dias)</h3>
  <canvas id="gUso" height="90"></canvas>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
  <div class="card">
    <h3>Situação das licenças</h3>
    <canvas id="gStatus" height="150"></canvas>
  </div>
  <div class="card">
    <h3>Parque instalado</h3>
    <table>
      <tbody>
        <tr><td>Máquinas registradas</td><td class="mono"><?= (int)$maq['total'] ?></td></tr>
        <tr><td>Ativas nos últimos 7 dias</td><td class="mono" style="color:var(--verde)"><?= (int)$maq['ativas7'] ?></td></tr>
        <tr><td>Ativas nos últimos 30 dias</td><td class="mono"><?= (int)$maq['ativas30'] ?></td></tr>
        <tr><td>Ainda no dongle Rockey2</td><td class="mono" style="color:var(--ambar)"><?= (int)$maq['dongle'] ?></td></tr>
        <tr><td>Licenças de demonstração</td><td class="mono"><?= (int)$kpi['demos'] ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h3>Desempenho por revendedor</h3>
  <table>
    <thead><tr>
      <th>Revendedor</th><th>Total</th><th>Vinculadas</th>
      <th>Em estoque</th><th>Transferências</th>
    </tr></thead>
    <tbody>
    <?php foreach ($porRev as $r): ?>
      <tr>
        <td><?= e($r['nome']) ?></td>
        <td class="mono"><?= (int)$r['total'] ?></td>
        <td class="mono"><?= (int)$r['vinculadas'] ?></td>
        <td class="mono"><?= (int)$r['estoque'] ?></td>
        <td class="mono"><?= (int)$r['transf'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Vencendo nos próximos 90 dias</h3>
  <table>
    <thead><tr><th>Chave</th><th>Cliente</th><th>Software</th><th>Expira</th><th>Faltam</th></tr></thead>
    <tbody>
    <?php if (!$vencendo): ?>
      <tr><td colspan="5" style="color:var(--texto-2)">Nenhuma licença vencendo no período.</td></tr>
    <?php else: foreach ($vencendo as $v): ?>
      <tr>
        <td class="mono" style="font-size:12px"><?= e($v['chave']) ?></td>
        <td><?= e($v['razao_social'] ?? '— estoque —') ?></td>
        <td class="mono"><?= e(strtoupper($v['produto'] ?? '—')) ?></td>
        <td class="mono"><?= date('d/m/Y', strtotime($v['expira_em'])) ?></td>
        <td class="mono" style="<?= $v['dias'] <= 30 ? 'color:var(--ambar)' : '' ?>">
          <?= (int)$v['dias'] ?> dias
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const AMBAR = '#f0a92b', VERDE = '#38b26b', AZUL = '#4a9fd4',
      VERM  = '#e0574e', CINZA = '#93a1ac', BORDA = '#313a42';

Chart.defaults.color = CINZA;
Chart.defaults.font.family = 'Inter, sans-serif';
Chart.defaults.font.size = 11;

const grade = { grid: { color: BORDA }, ticks: { color: CINZA } };
const semLegenda = { legend: { display: false } };

// paleta ciclica: cobre qualquer numero de tiers sem cor repetida perto
const PALETA = [AMBAR, AZUL, VERDE, VERM, '#9b7fd4', '#4ec9c0', '#d4884a'];

const seriesTier = <?= json_encode($seriesTier) ?>;
new Chart(document.getElementById('gEmissao'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($labMes) ?>,
    datasets: seriesTier.map((s, i) => ({
      label: s.nome, data: s.dados,
      backgroundColor: PALETA[i % PALETA.length], borderRadius: 3
    }))
  },
  options: {
    plugins: { legend: { position: 'bottom' } },
    scales: {
      x: { ...grade, stacked: true },
      y: { ...grade, stacked: true, beginAtZero: true,
           ticks: { precision: 0, color: CINZA } }
    }
  }
});

new Chart(document.getElementById('gTier'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($porTier,'nome')) ?>,
    datasets: [{ data: <?= json_encode(array_map('intval', array_column($porTier,'n'))) ?>,
                 backgroundColor: PALETA, borderRadius: 3 }]
  },
  options: {
    indexAxis: 'y',
    plugins: semLegenda,
    scales: { x: { ...grade, beginAtZero: true, ticks: { precision: 0, color: CINZA } },
              y: grade }
  }
});

new Chart(document.getElementById('gTipoLic'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_map(
        fn($r) => $r['tipo_licenca']==='demo' ? 'Demonstração' : 'Venda',
        $porTipoLic)) ?>,
    datasets: [{ data: <?= json_encode(array_map('intval', array_column($porTipoLic,'n'))) ?>,
                 backgroundColor: <?= json_encode(array_map(
                     fn($r) => $r['tipo_licenca']==='demo' ? '#4a9fd4' : '#38b26b',
                     $porTipoLic)) ?>,
                 borderColor: '#1c2126', borderWidth: 2 }]
  },
  options: { plugins: { legend: { position: 'bottom' } } }
});

new Chart(document.getElementById('gAno'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($labAno) ?>,
    datasets: [{ data: <?= json_encode($datAno) ?>,
                 backgroundColor: AZUL, borderRadius: 3 }]
  },
  options: { plugins: semLegenda, scales: { x: grade,
             y: { ...grade, beginAtZero: true, ticks: { precision: 0, color: CINZA } } } }
});

<?php if ($porProd): ?>
new Chart(document.getElementById('gProduto'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($porProd,'nome')) ?>,
    datasets: [{ data: <?= json_encode(array_map('intval', array_column($porProd,'n'))) ?>,
                 backgroundColor: [AMBAR, AZUL, VERDE, VERM, CINZA],
                 borderColor: '#1c2126', borderWidth: 2 }]
  },
  options: { plugins: { legend: { position: 'right' } } }
});
<?php endif; ?>

new Chart(document.getElementById('gUso'), {
  type: 'line',
  data: {
    labels: <?= json_encode($labDia) ?>,
    datasets: [{ label: 'Aberturas', data: <?= json_encode($datDia) ?>,
                 borderColor: VERDE, backgroundColor: 'rgba(56,178,107,.12)',
                 fill: true, tension: .3, pointRadius: 2 }]
  },
  options: { plugins: semLegenda, scales: { x: grade,
             y: { ...grade, beginAtZero: true, ticks: { precision: 0, color: CINZA } } } }
});

new Chart(document.getElementById('gStatus'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($porStatus,'status')) ?>,
    datasets: [{ data: <?= json_encode(array_map('intval', array_column($porStatus,'n'))) ?>,
                 backgroundColor: <?= json_encode($corStatus) ?>,
                 borderColor: '#1c2126', borderWidth: 2 }]
  },
  options: { plugins: { legend: { position: 'right' } } }
});
</script>
<?php fecha_pagina();
