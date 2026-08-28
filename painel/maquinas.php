<?php
require 'inc/auth.php';
require 'inc/layout.php';
exige_login();

/* =====================================================================
 *  MAQUINAS  -  onde o software esta efetivamente rodando
 * =====================================================================
 *  Alimentada por api/ping.php, chamado pelo cliente a cada abertura.
 *  Diferente do Relatorio (que mostra o log de acoes), aqui a pergunta
 *  e "quais PCs estao usando o sistema, e como estao licenciados".
 * ===================================================================== */

// ---- filtros (via GET) ---------------------------------------------
$fOrigem  = trim($_GET['origem'] ?? '');
$fProduto = trim($_GET['produto'] ?? '');
$fBusca   = trim($_GET['busca'] ?? '');
$fSumido  = trim($_GET['sumido'] ?? '');   // dias sem aparecer

$porPagina = 50;
$pagina    = max(1, (int)($_GET['pg'] ?? 1));
$offset    = ($pagina - 1) * $porPagina;

// ---- WHERE dinamico -------------------------------------------------
$where = [];
$args  = [];

if ($fOrigem === 'sem') {
    // maquinas que nunca informaram origem (cliente em versao antiga)
    $where[] = 'm.origem IS NULL';
} elseif ($fOrigem !== '') {
    $where[] = 'm.origem = ?';
    $args[] = $fOrigem;
}
if ($fProduto !== '') { $where[] = 'p.codigo = ?'; $args[] = $fProduto; }
if ($fSumido !== '' && (int)$fSumido > 0) {
    // Valor embutido, nao placeholder: INTERVAL ? DAY falha em PDO com
    // prepares nativos. Seguro porque passa por (int) - nao ha como
    // injetar SQL num inteiro.
    $dias = (int)$fSumido;
    $where[] = "m.ultimo_acesso < DATE_SUB(NOW(), INTERVAL $dias DAY)";
}
if ($fBusca !== '') {
    $where[] = '(m.maq_nome LIKE ? OR m.maq_usuario LIKE ? '
             . 'OR c.razao_social LIKE ? OR m.fingerprint LIKE ?)';
    for ($i = 0; $i < 4; $i++) { $args[] = '%'.$fBusca.'%'; }
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$juncoes =
  'FROM maquinas m
     LEFT JOIN licencas l ON l.id = m.licenca_id
     LEFT JOIN clientes c ON c.id = COALESCE(m.cliente_id, l.cliente_id)
     LEFT JOIN produtos p ON p.id = l.produto_id
     LEFT JOIN tiers    t ON t.id = l.tier_id';

// ---- total (paginacao) ----------------------------------------------
$stCount = db()->prepare("SELECT COUNT(*) $juncoes $whereSql");
$stCount->execute($args);
$total = (int)$stCount->fetchColumn();
$totalPaginas = max(1, (int)ceil($total / $porPagina));

// ---- consulta principal ---------------------------------------------
$st = db()->prepare(
  "SELECT m.*, c.razao_social,
          l.chave, l.status AS lic_status, l.expira_em,
          p.codigo AS produto_codigo, t.nome AS tier_nome,
          DATEDIFF(NOW(), m.ultimo_acesso) AS dias_sem_ver
     $juncoes
   $whereSql
   ORDER BY m.ultimo_acesso DESC
   LIMIT $porPagina OFFSET $offset");
$st->execute($args);
$linhas = $st->fetchAll();

// ---- resumo por origem (do filtro atual) ----------------------------
$stRes = db()->prepare(
  "SELECT COALESCE(m.origem,'sem informacao') AS org, COUNT(*) AS n
     $juncoes $whereSql
   GROUP BY org ORDER BY n DESC");
$stRes->execute($args);
$resumo = $stRes->fetchAll();

// contadores gerais (sempre do total, nao do filtro - servem de bussola)
$gerais = db()->query(
  "SELECT
     COUNT(*) AS total,
     SUM(CASE WHEN origem='dongle' THEN 1 ELSE 0 END) AS dongle,
     SUM(CASE WHEN ultimo_acesso >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              THEN 1 ELSE 0 END) AS ativas7,
     SUM(CASE WHEN licenca_id IS NULL THEN 1 ELSE 0 END) AS sem_licenca
   FROM maquinas")->fetch();

$produtosLista = db()->query(
  'SELECT codigo FROM produtos ORDER BY codigo')->fetchAll(PDO::FETCH_COLUMN);

function rotuloOrigem($o) {
    switch ($o) {
        case 'licenca': return ['Licença web', 'ativa'];
        case 'dongle':  return ['Rockey2 (dongle)', 'nova'];
        case 'tslpr':   return ['TS LPR', 'ativa'];
        default:        return ['—', 'nova'];
    }
}

function linkPaginaMaq($n) {
    $q = $_GET; $q['pg'] = $n;
    return 'maquinas.php?' . http_build_query($q);
}

abre_pagina('Máquinas', 'maquinas');
?>
<h1 class="titulo">Máquinas</h1>
<p class="subtitulo">Onde o software está rodando, e como está licenciado</p>

<div class="card">
  <div style="display:flex;gap:34px;flex-wrap:wrap">
    <div>
      <div style="font-size:26px;font-weight:700"><?= number_format($gerais['total'],0,',','.') ?></div>
      <div class="subtitulo" style="margin:0">Máquinas registradas</div>
    </div>
    <div>
      <div style="font-size:26px;font-weight:700;color:var(--verde,#38b26b)"><?= number_format($gerais['ativas7'],0,',','.') ?></div>
      <div class="subtitulo" style="margin:0">Ativas nos últimos 7 dias</div>
    </div>
    <div>
      <div style="font-size:26px;font-weight:700"><?= number_format($gerais['dongle'],0,',','.') ?></div>
      <div class="subtitulo" style="margin:0">Ainda no dongle</div>
    </div>
    <div>
      <div style="font-size:26px;font-weight:700"><?= number_format($gerais['sem_licenca'],0,',','.') ?></div>
      <div class="subtitulo" style="margin:0">Sem licença vinculada</div>
    </div>
  </div>
</div>

<div class="card">
  <h3>Filtros</h3>
  <form method="get">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
      <div>
        <label>Origem</label>
        <select name="origem">
          <option value="">— todas —</option>
          <option value="licenca" <?= $fOrigem==='licenca'?'selected':'' ?>>Licença web</option>
          <option value="dongle"  <?= $fOrigem==='dongle'?'selected':'' ?>>Rockey2 (dongle)</option>
          <option value="tslpr"   <?= $fOrigem==='tslpr'?'selected':'' ?>>TS LPR</option>
          <option value="sem"     <?= $fOrigem==='sem'?'selected':'' ?>>Sem informação</option>
        </select>
      </div>
      <div>
        <label>Software</label>
        <select name="produto">
          <option value="">— todos —</option>
          <?php foreach ($produtosLista as $p): ?>
            <option value="<?= e($p) ?>" <?= $fProduto===$p?'selected':'' ?>><?= e(strtoupper($p)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Sem aparecer há</label>
        <select name="sumido">
          <option value="">— qualquer —</option>
          <option value="7"  <?= $fSumido==='7'?'selected':'' ?>>mais de 7 dias</option>
          <option value="30" <?= $fSumido==='30'?'selected':'' ?>>mais de 30 dias</option>
          <option value="90" <?= $fSumido==='90'?'selected':'' ?>>mais de 90 dias</option>
        </select>
      </div>
    </div>
    <div style="margin-top:12px">
      <label>Buscar (PC, usuário, cliente ou código da máquina)</label>
      <input type="text" name="busca" value="<?= e($fBusca) ?>" placeholder="ex: PC-BALANCA ou nome do cliente">
    </div>
    <div style="margin-top:14px">
      <button class="btn">Filtrar</button>
      <a class="btn sec" href="maquinas.php" style="margin-left:8px">Limpar</a>
    </div>
  </form>
</div>

<?php if ($resumo): ?>
<div class="card">
  <h3>Resumo do filtro (<?= number_format($total,0,',','.') ?> máquina<?= $total==1?'':'s' ?>)</h3>
  <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:8px">
    <?php foreach ($resumo as $rr): $r = rotuloOrigem($rr['org']); ?>
      <div style="min-width:130px">
        <div style="font-size:22px;font-weight:700"><?= number_format($rr['n'],0,',','.') ?></div>
        <div class="subtitulo" style="margin:0"><?= e($r[0]) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h3>Máquinas</h3>
  <table>
    <thead><tr>
      <th>PC</th><th>Usuário</th><th>Sistema</th><th>Origem</th>
      <th>Cliente</th><th>Software/Tipo</th><th>Aberturas</th>
      <th>Último acesso</th><th>Código da máquina</th>
    </tr></thead>
    <tbody>
    <?php if (!$linhas): ?>
      <tr><td colspan="9" style="color:var(--texto-2)">
        Nenhuma máquina para os filtros escolhidos.
      </td></tr>
    <?php else: foreach ($linhas as $m):
        $ro   = rotuloOrigem($m['origem']);
        $dias = $m['dias_sem_ver'];
        // 30 dias sem aparecer costuma ser desinstalacao, troca de PC ou
        // cliente parado - vale destacar em vez de esconder no meio da lista
        $sumiu = ($dias !== null && $dias > 30);
        $prodTier = $m['produto_codigo']
            ? strtoupper($m['produto_codigo']).($m['tier_nome']?' · '.$m['tier_nome']:'')
            : '—';
    ?>
      <tr>
        <td style="font-size:12px">
          <a href="maquina.php?fp=<?= urlencode($m['fingerprint']) ?>">
            <?= e($m['maq_nome'] ?: '(sem nome)') ?></a>
        </td>
        <td style="font-size:12px"><?= e($m['maq_usuario'] ?: '—') ?></td>
        <td style="font-size:11px;color:var(--texto-2)"><?= e($m['maq_so'] ?: '—') ?></td>
        <td><span class="badge <?= e($ro[1]) ?>"><?= e($ro[0]) ?></span></td>
        <td style="font-size:12px"><?= e($m['razao_social'] ?: '—') ?></td>
        <td class="mono" style="font-size:11px"><?= e($prodTier) ?></td>
        <td class="mono"><?= (int)$m['aberturas'] ?></td>
        <td class="mono" style="font-size:11px;<?= $sumiu?'color:var(--vermelho)':'' ?>">
          <?= $m['ultimo_acesso'] ? date('d/m/Y H:i', strtotime($m['ultimo_acesso'])) : '—' ?>
          <?php if ($sumiu): ?><br><span style="font-size:10px">há <?= (int)$dias ?> dias</span><?php endif; ?>
        </td>
        <td class="mono" style="font-size:11px"><?= e(substr($m['fingerprint'],0,14)) ?>…</td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php if ($totalPaginas > 1): ?>
    <div style="display:flex;gap:8px;align-items:center;margin-top:16px;justify-content:center">
      <?php if ($pagina > 1): ?>
        <a class="btn sec pequeno" href="<?= e(linkPaginaMaq($pagina-1)) ?>">‹ Anterior</a>
      <?php endif; ?>
      <span class="subtitulo" style="margin:0">Página <?= $pagina ?> de <?= $totalPaginas ?></span>
      <?php if ($pagina < $totalPaginas): ?>
        <a class="btn sec pequeno" href="<?= e(linkPaginaMaq($pagina+1)) ?>">Próxima ›</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php fecha_pagina();
