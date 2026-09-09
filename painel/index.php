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

/* ---------------------------------------------------------------------
 *  SÓ LICENÇAS ATIVAS
 * ---------------------------------------------------------------------
 *  A visão geral mostra a base VIVA: quantas licenças estão de pé
 *  agora, quanto se usa, quem está para vencer.
 *
 *  Revogadas e expiradas continuam no banco e aparecem em Licenças e no
 *  Relatório — mas somá-las aqui distorce tudo. Um painel que diz "40
 *  licenças" quando 25 foram revogadas não ajuda a decidir nada.
 *
 *  Inclui 'nova' junto com 'ativa': licença emitida e ainda não
 *  ativada é uma venda feita, esperando instalação. Deixá-la de fora
 *  esconderia o estoque do revendedor.
 * ------------------------------------------------------------------- */
$soAtivas = "l.status IN ('ativa','nova')";

// fragmentos prontos, para nao repetir o mesmo filtro em 13 consultas
if ($fProd !== '') {
    $joinProd = 'JOIN produtos pf ON pf.id = l.produto_id AND pf.codigo = '
              . db()->quote($fProd);
    $wLic  = "AND $soAtivas AND l.produto_id = (SELECT id FROM produtos "
           . 'WHERE codigo = ' . db()->quote($fProd) . ')';
    $wLicW = "WHERE $soAtivas AND l.produto_id = (SELECT id FROM produtos "
           . 'WHERE codigo = ' . db()->quote($fProd) . ')';
} else {
    $joinProd = '';
    $wLic  = "AND $soAtivas";
    $wLicW = "WHERE $soAtivas";
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
  "SELECT COALESCE(u.nome_fantasia, u.empresa, u.nome, 'Venda direta') AS nome,
          u.nome AS contato,
          COUNT(*) AS total,
          SUM(l.cliente_id IS NOT NULL) AS vinculadas,
          SUM(l.cliente_id IS NULL)     AS estoque,
          SUM(l.transferencias)         AS transf
     FROM licencas l LEFT JOIN usuarios u ON u.id=l.revendedor_id
   $wLicW
    GROUP BY nome ORDER BY total DESC")->fetchAll();

// ---- status ----------------------------------------------------------
/* Situação: agora conta TODAS, inclusive as que o resto da tela
   filtra. É o único lugar onde revogada e expirada aparecem — serve
   justamente para você saber quanto ficou de fora dos outros
   números. */
$wProdSo = $fProd === '' ? '' :
    'WHERE l.produto_id = (SELECT id FROM produtos WHERE codigo = '
    . db()->quote($fProd) . ')';

$porStatus = db()->query(
  "SELECT l.status, COUNT(*) AS n FROM licencas l
   $wProdSo GROUP BY l.status")->fetchAll();

$foraDaConta = 0;
foreach ($porStatus as $ps)
    if (!in_array($ps['status'], ['ativa','nova'], true))
        $foraDaConta += (int)$ps['n'];

// cor por status, na MESMA ordem dos labels (o Chart.js exige array,
// nao objeto: um objeto vira cores indefinidas e as fatias saem pretas)
$mapaCor = ['ativa'=>'#38b26b','nova'=>'#4a9fd4',
            'revogada'=>'#e0574e','expirada'=>'#93a1ac'];
$corStatus = [];
foreach ($porStatus as $ps) $corStatus[] = $mapaCor[$ps['status']] ?? '#93a1ac';

/* Emissões do mês, por software.

   O KPI "Emitidas no mês" dá o total; este quadro mostra de onde ele
   veio. Um mês bom no TS6 e fraco no TS5 é informação diferente de um
   mês médio nos dois — e o total sozinho esconde isso.

   Traz também o mês anterior, porque um número sem comparação não diz
   se foi bom ou ruim. */
$emissaoMes = db()->query(
  "SELECT p.codigo, p.nome,
          SUM(DATE_FORMAT(l.emitido_em,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')) AS mes,
          SUM(DATE_FORMAT(l.emitido_em,'%Y-%m') =
              DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH),'%Y-%m')) AS mes_ant
     FROM produtos p
     LEFT JOIN licencas l ON l.produto_id = p.id
    WHERE p.ativo = 1
    GROUP BY p.id ORDER BY p.codigo")->fetchAll();

/* ---------------------------------------------------------------------
 *  EMITIDAS E NÃO ATIVADAS
 * ---------------------------------------------------------------------
 *  Chave gerada que nunca foi instalada em máquina nenhuma
 *  (fingerprint nulo). São dois casos bem diferentes:
 *
 *    com cliente  — ele comprou e não instalou. Venda parada; alguém
 *                   precisa cobrar a instalação.
 *    sem cliente  — estoque do revendedor, esperando ele vender. É
 *                   situação normal.
 *
 *  O que importa nos dois é HÁ QUANTO TEMPO. Emitida ontem não é
 *  problema; de três meses atrás é alguém que talvez nem lembre que
 *  comprou.
 * ------------------------------------------------------------------- */
$naoAtiv = db()->query(
  "SELECT
     SUM(l.cliente_id IS NOT NULL AND DATEDIFF(NOW(),l.emitido_em) <= 15) AS cli_novo,
     SUM(l.cliente_id IS NOT NULL AND DATEDIFF(NOW(),l.emitido_em) BETWEEN 16 AND 60) AS cli_medio,
     SUM(l.cliente_id IS NOT NULL AND DATEDIFF(NOW(),l.emitido_em) > 60) AS cli_velho,
     SUM(l.cliente_id IS NULL)  AS estoque,
     COUNT(*)                   AS total
   FROM licencas l
  WHERE l.fingerprint IS NULL
    AND l.status IN ('nova','ativa')
    $wLic")->fetch();

// estoque por revendedor, para ver quem acumula sem vender
$estoqueRev = db()->query(
  "SELECT COALESCE(u.nome_fantasia, u.empresa, u.nome, '(sem revendedor)') AS nome,
          COUNT(*) AS n
     FROM licencas l
     LEFT JOIN usuarios u ON u.id = l.revendedor_id
    WHERE l.fingerprint IS NULL AND l.cliente_id IS NULL
      AND l.status IN ('nova','ativa')
      $wLic
    GROUP BY l.revendedor_id ORDER BY n DESC LIMIT 5")->fetchAll();

// as mais paradas: a lista de cobrança
$paradas = db()->query(
  "SELECT l.chave, l.emitido_em,
          COALESCE(c.nome_fantasia, c.razao_social) AS cliente,
          p.codigo AS produto, t.nome AS tier,
          DATEDIFF(NOW(), l.emitido_em) AS dias
     FROM licencas l
     JOIN clientes c ON c.id = l.cliente_id
     LEFT JOIN produtos p ON p.id = l.produto_id
     LEFT JOIN tiers    t ON t.id = l.tier_id
    WHERE l.fingerprint IS NULL AND l.status IN ('nova','ativa')
      $wLic
    ORDER BY l.emitido_em LIMIT 5")->fetchAll();

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

/** Nome do mês por extenso, para o título dos cards. */
function mesPtBr(int $n): string {
    return ['', 'janeiro','fevereiro','março','abril','maio','junho','julho',
            'agosto','setembro','outubro','novembro','dezembro'][$n] ?? '';
}

abre_pagina('Painel', 'painel');
?>
<h1 class="titulo">Visão geral</h1>
<p class="subtitulo">
  <?= $fProd === ''
      ? 'Licenças ativas, uso do software e desempenho por revendedor'
      : 'Licenças ativas de ' . e($prodNome) ?>
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
  <a class="stat" href="licencas.php?status=nova" style="text-decoration:none">
    <div class="n" style="color:<?= (int)$naoAtiv['cli_velho'] > 0
        ? 'var(--vermelho)' : 'var(--texto)' ?>"><?= (int)$naoAtiv['total'] ?></div>
    <div class="l">Não ativadas</div>
    <?php if ((int)$naoAtiv['cli_velho'] > 0): ?>
      <div class="l" style="font-size:10px;color:var(--vermelho)">
        <?= (int)$naoAtiv['cli_velho'] ?> há mais de 60 dias</div>
    <?php endif; ?>
  </a>
  <?php if ((int)$maq['dongle'] > 0): ?>
    <div class="stat"><div class="n"><?= (int)$maq['dongle'] ?></div>
      <div class="l">Ainda no dongle</div></div>
  <?php endif; ?>
</div>

<?php if ($fProd === '' && count($emissaoMes) > 1): ?>
  <div class="card" style="padding:14px 18px">
    <div style="display:flex;justify-content:space-between;align-items:baseline;
         margin-bottom:12px">
      <h3 style="margin:0">Emitidas em <?= mesPtBr(date('n')) ?></h3>
      <span class="subtitulo" style="margin:0">comparado ao mês anterior</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(<?=
         min(count($emissaoMes), 4) ?>,1fr);gap:12px">
      <?php foreach ($emissaoMes as $em):
          $m  = (int)$em['mes'];
          $ma = (int)$em['mes_ant'];
          $dif = $m - $ma;
      ?>
        <a href="licencas.php?produto=<?= e($em['codigo']) ?>"
           style="text-decoration:none;background:var(--bg-3);
           border-radius:var(--raio);padding:12px 14px;display:block">
          <div style="font-size:11px;color:var(--texto-2)">
            <?= e($em['nome']) ?></div>
          <div class="mono" style="font-size:22px;margin-top:4px"><?= $m ?></div>
          <div style="font-size:11px;margin-top:2px;color:<?=
              $dif > 0 ? 'var(--verde)' : ($dif < 0 ? 'var(--vermelho)' : 'var(--texto-2)') ?>">
            <?php if ($ma === 0 && $m === 0): ?>
              nenhuma no mês passado
            <?php elseif ($dif > 0): ?>
              +<?= $dif ?> · eram <?= $ma ?>
            <?php elseif ($dif < 0): ?>
              <?= $dif ?> · eram <?= $ma ?>
            <?php else: ?>
              igual ao mês passado
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ((int)$naoAtiv['total'] > 0): ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:baseline">
    <h3 style="margin:0">Emitidas e não ativadas</h3>
    <span class="subtitulo" style="margin:0"><?= (int)$naoAtiv['total'] ?> licenças</span>
  </div>
  <p class="subtitulo" style="margin:4px 0 16px">
    Chave gerada que nunca foi instalada em nenhuma máquina.
  </p>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
    <div>
      <h4 style="margin:0 0 4px;font-size:12px;color:var(--ambar)">
        AGUARDANDO INSTALAÇÃO</h4>
      <p class="subtitulo" style="margin:0 0 10px;font-size:11px">
        Cliente definido, software não instalado
      </p>
      <table style="font-size:13px">
        <tr><td>até 15 dias</td>
            <td class="mono" style="text-align:right"><?= (int)$naoAtiv['cli_novo'] ?></td></tr>
        <tr><td>16 a 60 dias</td>
            <td class="mono" style="text-align:right;color:var(--ambar)">
              <?= (int)$naoAtiv['cli_medio'] ?></td></tr>
        <tr><td>mais de 60 dias</td>
            <td class="mono" style="text-align:right;color:var(--vermelho)">
              <?= (int)$naoAtiv['cli_velho'] ?></td></tr>
      </table>
    </div>

    <div>
      <h4 style="margin:0 0 4px;font-size:12px;color:var(--ambar)">
        ESTOQUE DE REVENDEDOR</h4>
      <p class="subtitulo" style="margin:0 0 10px;font-size:11px">
        Ainda sem cliente final
      </p>
      <table style="font-size:13px">
        <?php if (!$estoqueRev): ?>
          <tr><td style="color:var(--texto-2)">Nenhuma em estoque.</td></tr>
        <?php else: foreach ($estoqueRev as $er): ?>
          <tr><td><?= e($er['nome']) ?></td>
              <td class="mono" style="text-align:right"><?= (int)$er['n'] ?></td></tr>
        <?php endforeach; endif; ?>
      </table>
    </div>
  </div>

  <?php if ($paradas): ?>
    <div style="border-top:1px solid var(--borda);margin-top:16px;padding-top:12px">
      <p class="subtitulo" style="margin:0 0 8px">As mais paradas</p>
      <table style="font-size:13px">
        <?php foreach ($paradas as $pa): ?>
          <tr>
            <td><?= e($pa['cliente']) ?></td>
            <td class="mono" style="width:110px;font-size:11px;color:var(--texto-2)">
              <?= e(strtoupper($pa['produto'] ?? '—')) ?>
              <?= $pa['tier'] ? '· ' . e($pa['tier']) : '' ?></td>
            <td style="width:80px;text-align:right;color:<?=
                (int)$pa['dias'] > 60 ? 'var(--vermelho)'
                : ((int)$pa['dias'] > 15 ? 'var(--ambar)' : 'var(--texto-2)') ?>">
              <?= (int)$pa['dias'] ?> dias</td>
          </tr>
        <?php endforeach; ?>
      </table>
      <a class="btn sec pequeno" style="margin-top:12px"
         href="licencas.php?status=nova">Ver todas</a>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

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
    <p class="subtitulo" style="margin-top:-6px">
      <?php if ($foraDaConta > 0): ?>
        Os demais números desta tela contam só as ativas.
        <b><?= $foraDaConta ?></b> revogada(s) ou expirada(s) ficam de fora.
      <?php else: ?>
        Todas as licenças estão ativas.
      <?php endif; ?>
    </p>
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
        <td>
          <?= e($r['nome']) ?>
          <?php if (!empty($r['contato']) && $r['contato'] !== $r['nome']): ?>
            <br><span style="font-size:11px;color:var(--texto-2)">
              <?= e($r['contato']) ?></span>
          <?php endif; ?>
        </td>
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
