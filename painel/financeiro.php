<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  FINANCEIRO
 * =====================================================================
 *  Responde o que o painel de licenças sozinho não respondia:
 *  quanto entrou, de quem, e quanto está por vir.
 *
 *  RECEITA aqui é sempre o que VOCÊ recebeu — do cliente na venda
 *  direta, do revendedor na revenda. A margem que o revendedor põe em
 *  cima não aparece, porque não é seu dinheiro.
 *
 *  A PREVISÃO soma o último valor cobrado das licenças que vencem no
 *  período. É estimativa: assume que quem renovar pagará o mesmo. Serve
 *  para dimensionar o trimestre, não para fechar o caixa.
 * ===================================================================== */

$mesAtual = date('Y-m');
$fDe  = trim($_GET['de']  ?? date('Y-m', strtotime('-11 months')));
$fAte = trim($_GET['ate'] ?? $mesAtual);
if (!preg_match('/^\d{4}-\d{2}$/', $fDe))  $fDe  = date('Y-m', strtotime('-11 months'));
if (!preg_match('/^\d{4}-\d{2}$/', $fAte)) $fAte = $mesAtual;
if ($fDe > $fAte) [$fDe, $fAte] = [$fAte, $fDe];

function brl(?float $v): string {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}
function mesRot(string $am): string {
    $m = ['01'=>'jan','02'=>'fev','03'=>'mar','04'=>'abr','05'=>'mai','06'=>'jun',
          '07'=>'jul','08'=>'ago','09'=>'set','10'=>'out','11'=>'nov','12'=>'dez'];
    [$a, $mm] = explode('-', $am);
    return ($m[$mm] ?? $mm) . '/' . substr($a, 2);
}

/* ---- totais do período --------------------------------------------- */
$st = db()->prepare(
  "SELECT COUNT(*) AS n,
          SUM(valor) AS total,
          SUM(CASE WHEN tipo='emissao'   THEN valor ELSE 0 END) AS emissoes,
          SUM(CASE WHEN tipo='renovacao' THEN valor ELSE 0 END) AS renovacoes,
          SUM(CASE WHEN revendedor_id IS NULL THEN valor ELSE 0 END) AS direta,
          SUM(CASE WHEN revendedor_id IS NOT NULL THEN valor ELSE 0 END) AS revenda
     FROM financeiro_mov
    WHERE competencia BETWEEN ? AND ?");
$st->execute([$fDe, $fAte]);
$tot = $st->fetch();

$ticket = ((int)$tot['n'] > 0) ? ((float)$tot['total'] / (int)$tot['n']) : 0;

/* ---- série mensal --------------------------------------------------- */
$st = db()->prepare(
  "SELECT competencia,
          SUM(CASE WHEN tipo='emissao'   THEN valor ELSE 0 END) AS emis,
          SUM(CASE WHEN tipo='renovacao' THEN valor ELSE 0 END) AS renov
     FROM financeiro_mov
    WHERE competencia BETWEEN ? AND ?
    GROUP BY competencia ORDER BY competencia");
$st->execute([$fDe, $fAte]);
$serie = $st->fetchAll();

/* ---- por revendedor -------------------------------------------------- */
$st = db()->prepare(
  "SELECT COALESCE(u.nome_fantasia, u.empresa, u.nome, '(venda direta)') AS nome,
          m.revendedor_id, u.desconto_revenda,
          COUNT(*) AS qtd, SUM(m.valor) AS total
     FROM financeiro_mov m
     LEFT JOIN usuarios u ON u.id = m.revendedor_id
    WHERE m.competencia BETWEEN ? AND ?
    GROUP BY m.revendedor_id ORDER BY total DESC");
$st->execute([$fDe, $fAte]);
$porRev = $st->fetchAll();

/* ---- por produto ---------------------------------------------------- */
$st = db()->prepare(
  "SELECT COALESCE(produto,'(sem produto)') AS produto,
          COUNT(*) AS qtd, SUM(valor) AS total
     FROM financeiro_mov
    WHERE competencia BETWEEN ? AND ?
    GROUP BY produto ORDER BY total DESC");
$st->execute([$fDe, $fAte]);
$porProd = $st->fetchAll();

/* ---- previsão de renovação ------------------------------------------
 *  Soma o último valor cobrado das licenças ativas que vencem nos
 *  próximos 12 meses. É estimativa, não compromisso.
 * -------------------------------------------------------------------- */
$prev = db()->query(
  "SELECT DATE_FORMAT(l.expira_em,'%Y-%m') AS mes,
          COUNT(*) AS qtd, SUM(COALESCE(l.valor,0)) AS total,
          SUM(l.valor IS NULL) AS sem_valor
     FROM licencas l
    WHERE l.status IN ('ativa','nova')
      AND l.tipo_licenca = 'venda'
      AND l.expira_em BETWEEN CURDATE()
                          AND DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY mes ORDER BY mes")->fetchAll();

$prevTotal    = array_sum(array_map(fn($p) => (float)$p['total'], $prev));
$prevSemValor = array_sum(array_map(fn($p) => (int)$p['sem_valor'], $prev));

/* ---- clientes por receita ------------------------------------------- */
$st = db()->prepare(
  "SELECT c.id, c.razao_social, c.nome_fantasia,
          COUNT(*) AS qtd, SUM(m.valor) AS total, MAX(m.criado_em) AS ultima
     FROM financeiro_mov m
     JOIN clientes c ON c.id = m.cliente_id
    WHERE m.competencia BETWEEN ? AND ?
    GROUP BY c.id ORDER BY total DESC LIMIT 20");
$st->execute([$fDe, $fAte]);
$porCli = $st->fetchAll();

abre_pagina('Financeiro', 'financeiro');
?>
<h1 class="titulo">Financeiro</h1>
<p class="subtitulo">Receita de licenças, por revendedor e por produto</p>

<?php if ((int)$tot['n'] === 0 && !$prev): ?>
  <div class="card" style="border-left:3px solid var(--azul)">
    <h3>Ainda sem movimentação</h3>
    <p style="margin:0;font-size:13px">
      Os valores começam a aparecer quando você preencher o campo
      <b>Valor cobrado</b> ao emitir ou renovar uma licença. Para que ele
      venha sugerido, cadastre o preço de cada tipo em
      <a href="catalogo.php">Catálogo</a>.
    </p>
  </div>
<?php endif; ?>

<div class="card">
  <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div><label>De</label>
      <input type="month" name="de" value="<?= e($fDe) ?>"></div>
    <div><label>Até</label>
      <input type="month" name="ate" value="<?= e($fAte) ?>"></div>
    <button class="btn">Filtrar</button>
    <a class="btn sec" href="financeiro.php">Últimos 12 meses</a>
  </form>
</div>

<div class="stats">
  <div class="stat"><div class="n"><?= brl((float)$tot['total']) ?></div>
    <div class="l">Receita no período</div></div>
  <div class="stat"><div class="n"><?= brl($ticket) ?></div>
    <div class="l">Ticket médio</div></div>
  <div class="stat"><div class="n" style="color:var(--verde)">
    <?= brl((float)$tot['renovacoes']) ?></div>
    <div class="l">De renovações</div></div>
  <div class="stat"><div class="n" style="color:var(--ambar)">
    <?= brl($prevTotal) ?></div>
    <div class="l">Previsto 12 meses</div></div>
</div>

<?php if ($serie): ?>
<div class="card">
  <h3>Receita por mês</h3>
  <canvas id="gSerie" height="95"></canvas>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
  <div class="card">
    <h3>Por origem</h3>
    <table style="font-size:13px">
      <tr><td>Venda direta</td>
          <td class="mono" style="text-align:right"><?= brl((float)$tot['direta']) ?></td></tr>
      <tr><td>Por revendedor</td>
          <td class="mono" style="text-align:right"><?= brl((float)$tot['revenda']) ?></td></tr>
      <tr style="border-top:1px solid var(--borda)">
          <td><b>Total</b></td>
          <td class="mono" style="text-align:right"><b><?= brl((float)$tot['total']) ?></b></td></tr>
    </table>
    <p class="subtitulo" style="margin:10px 0 0;font-size:11px">
      Na revenda, o valor é o que o revendedor pagou a você. O preço que
      ele cobra do cliente final não entra aqui.
    </p>
  </div>

  <div class="card">
    <h3>Por software</h3>
    <table style="font-size:13px">
      <?php foreach ($porProd as $p): ?>
        <tr><td><?= e(strtoupper($p['produto'])) ?>
            <span style="color:var(--texto-2)">· <?= (int)$p['qtd'] ?></span></td>
          <td class="mono" style="text-align:right"><?= brl((float)$p['total']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$porProd): ?>
        <tr><td colspan="2" style="color:var(--texto-2)">Sem dados.</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="card">
  <h3>Por revendedor</h3>
  <table>
    <thead><tr><th>Revendedor</th><th>Desconto</th>
      <th style="text-align:right">Licenças</th>
      <th style="text-align:right">Receita</th></tr></thead>
    <tbody>
    <?php if (!$porRev): ?>
      <tr><td colspan="4" style="color:var(--texto-2)">
        Nenhuma movimentação no período.</td></tr>
    <?php else: foreach ($porRev as $r): ?>
      <tr>
        <td><?php if ($r['revendedor_id']): ?>
              <a href="revendedor.php?id=<?= (int)$r['revendedor_id'] ?>">
                <?= e($r['nome']) ?></a>
            <?php else: ?>
              <span style="color:var(--texto-2)"><?= e($r['nome']) ?></span>
            <?php endif; ?></td>
        <td class="mono" style="font-size:11px">
          <?= $r['desconto_revenda'] > 0
              ? number_format((float)$r['desconto_revenda'],1,',','.').'%' : '—' ?></td>
        <td class="mono" style="text-align:right"><?= (int)$r['qtd'] ?></td>
        <td class="mono" style="text-align:right"><?= brl((float)$r['total']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Previsão de renovação — próximos 12 meses</h3>
  <p class="subtitulo" style="margin-top:-6px">
    Soma do último valor cobrado das licenças que vencem em cada mês.
    <b>É estimativa</b>: assume que quem renovar pagará o mesmo.
    <?php if ($prevSemValor > 0): ?>
      <br><span style="color:var(--ambar)"><?= $prevSemValor ?> licença(s)
      sem valor registrado não entram nesta conta.</span>
    <?php endif; ?>
  </p>
  <table>
    <thead><tr><th>Mês</th>
      <th style="text-align:right">Licenças</th>
      <th style="text-align:right">Valor estimado</th><th></th></tr></thead>
    <tbody>
    <?php if (!$prev): ?>
      <tr><td colspan="4" style="color:var(--texto-2)">
        Nenhuma licença vencendo nos próximos 12 meses.</td></tr>
    <?php else: foreach ($prev as $p): ?>
      <tr>
        <td><?= e(mesRot($p['mes'])) ?></td>
        <td class="mono" style="text-align:right"><?= (int)$p['qtd'] ?></td>
        <td class="mono" style="text-align:right"><?= brl((float)$p['total']) ?></td>
        <td><a class="btn sec pequeno"
               href="licencas.php?venc=90&ordem=vence">ver licenças</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Clientes por receita</h3>
  <table>
    <thead><tr><th>Cliente</th>
      <th style="text-align:right">Movimentos</th>
      <th style="text-align:right">Total</th>
      <th>Último</th></tr></thead>
    <tbody>
    <?php if (!$porCli): ?>
      <tr><td colspan="4" style="color:var(--texto-2)">Sem dados.</td></tr>
    <?php else: foreach ($porCli as $c): ?>
      <tr>
        <td><a href="cliente.php?id=<?= (int)$c['id'] ?>">
          <?= e($c['nome_fantasia'] ?: $c['razao_social']) ?></a></td>
        <td class="mono" style="text-align:right"><?= (int)$c['qtd'] ?></td>
        <td class="mono" style="text-align:right"><?= brl((float)$c['total']) ?></td>
        <td class="mono" style="font-size:11px">
          <?= date('d/m/Y', strtotime($c['ultima'])) ?></td>
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
    labels: <?= json_encode(array_map('mesRot', array_column($serie,'competencia'))) ?>,
    datasets: [
      { label: 'Emissões',   backgroundColor: '#f0a92b',
        data: <?= json_encode(array_map('floatval', array_column($serie,'emis'))) ?> },
      { label: 'Renovações', backgroundColor: '#38b26b',
        data: <?= json_encode(array_map('floatval', array_column($serie,'renov'))) ?> }
    ]
  },
  options: {
    plugins: { legend: { position: 'bottom' } },
    scales: {
      x: { stacked: true, grid: { display: false } },
      y: { stacked: true, beginAtZero: true, grid: { color: '#313a42' },
           ticks: { callback: function (v) { return 'R$ ' + v.toLocaleString('pt-BR'); } } }
    }
  }
});
</script>
<?php endif; ?>
<?php fecha_pagina();
