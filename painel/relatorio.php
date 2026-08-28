<?php
require 'inc/auth.php';
require 'inc/layout.php';
exige_login();

// ---- filtros (via GET) ---------------------------------------------
$fAcao      = trim($_GET['acao'] ?? '');
$fResultado = trim($_GET['resultado'] ?? '');
$fProduto   = trim($_GET['produto'] ?? '');
$fBusca     = trim($_GET['busca'] ?? '');
$fDe        = trim($_GET['de'] ?? '');
$fAte       = trim($_GET['ate'] ?? '');

// paginacao
$porPagina = 50;
$pagina    = max(1, (int)($_GET['pg'] ?? 1));
$offset    = ($pagina - 1) * $porPagina;

// ---- monta o WHERE dinamicamente -----------------------------------
$where = [];
$args  = [];

if ($fAcao !== '')      { $where[] = 'a.acao = ?';           $args[] = $fAcao; }
if ($fResultado !== '') { $where[] = 'a.resultado = ?';      $args[] = $fResultado; }
if ($fProduto !== '')   { $where[] = 'a.produto_codigo = ?'; $args[] = $fProduto; }
if ($fDe !== '')        { $where[] = 'a.criado_em >= ?';     $args[] = $fDe . ' 00:00:00'; }
if ($fAte !== '')       { $where[] = 'a.criado_em <= ?';     $args[] = $fAte . ' 23:59:59'; }
if ($fBusca !== '') {
    $where[] = '(a.chave LIKE ? OR c.razao_social LIKE ? OR a.usuario_nome LIKE ?)';
    $args[] = '%'.$fBusca.'%'; $args[] = '%'.$fBusca.'%'; $args[] = '%'.$fBusca.'%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ---- total de linhas (para paginacao) ------------------------------
$stCount = db()->prepare(
  "SELECT COUNT(*)
     FROM ativacoes_log a
     LEFT JOIN licencas l ON l.id = a.licenca_id
     LEFT JOIN clientes c ON c.id = l.cliente_id
   $whereSql");
$stCount->execute($args);
$total = (int)$stCount->fetchColumn();
$totalPaginas = max(1, (int)ceil($total / $porPagina));

// ---- consulta principal --------------------------------------------
$sql =
  "SELECT a.*, c.razao_social
     FROM ativacoes_log a
     LEFT JOIN licencas l ON l.id = a.licenca_id
     LEFT JOIN clientes c ON c.id = l.cliente_id
   $whereSql
   ORDER BY a.id DESC
   LIMIT $porPagina OFFSET $offset";
$st = db()->prepare($sql);
$st->execute($args);
$linhas = $st->fetchAll();

// ---- resumo rapido (contadores do periodo filtrado) ----------------
$stResumo = db()->prepare(
  "SELECT a.acao, COUNT(*) AS n
     FROM ativacoes_log a
     LEFT JOIN licencas l ON l.id = a.licenca_id
     LEFT JOIN clientes c ON c.id = l.cliente_id
   $whereSql
   GROUP BY a.acao ORDER BY n DESC");
$stResumo->execute($args);
$resumo = $stResumo->fetchAll();

// listas para os selects de filtro
$acoes    = db()->query('SELECT DISTINCT acao FROM ativacoes_log ORDER BY acao')->fetchAll(PDO::FETCH_COLUMN);
$produtos = db()->query('SELECT codigo FROM produtos ORDER BY codigo')->fetchAll(PDO::FETCH_COLUMN);

// helper: rotulo amigavel e cor da acao
function rotuloAcao($a) {
    switch ($a) {
        case 'emitir':         return ['Emissão','info'];
        case 'ativar_online':  return ['Ativação online','ok'];
        case 'gerar_offline':  return ['Ativação offline','ok'];
        case 'revogar':        return ['Revogação','perigo'];
        case 'verificar':      return ['Verificação','neutro'];
        default:               return [$a,'neutro'];
    }
}

// preserva filtros nos links de paginacao
function linkPagina($n) {
    $q = $_GET; $q['pg'] = $n;
    return 'relatorio.php?' . http_build_query($q);
}

abre_pagina('Relatório', 'relatorio');
?>
<h1 class="titulo">Relatório de uso</h1>
<p class="subtitulo">Histórico de emissões, ativações e revogações de licenças</p>

<div class="card">
  <h3>Filtros</h3>
  <form method="get">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
      <div>
        <label>Ação</label>
        <select name="acao">
          <option value="">— todas —</option>
          <?php foreach ($acoes as $a): $r=rotuloAcao($a); ?>
            <option value="<?= e($a) ?>" <?= $fAcao===$a?'selected':'' ?>><?= e($r[0]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Resultado</label>
        <select name="resultado">
          <option value="">— todos —</option>
          <option value="ok"     <?= $fResultado==='ok'?'selected':'' ?>>OK</option>
          <option value="negado" <?= $fResultado==='negado'?'selected':'' ?>>Negado</option>
          <option value="erro"   <?= $fResultado==='erro'?'selected':'' ?>>Erro</option>
        </select>
      </div>
      <div>
        <label>Software</label>
        <select name="produto">
          <option value="">— todos —</option>
          <?php foreach ($produtos as $p): ?>
            <option value="<?= e($p) ?>" <?= $fProduto===$p?'selected':'' ?>><?= e(strtoupper($p)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 2fr;gap:14px;margin-top:12px">
      <div>
        <label>De</label>
        <input type="date" name="de" value="<?= e($fDe) ?>">
      </div>
      <div>
        <label>Até</label>
        <input type="date" name="ate" value="<?= e($fAte) ?>">
      </div>
      <div>
        <label>Buscar (chave, cliente ou usuário)</label>
        <input type="text" name="busca" value="<?= e($fBusca) ?>" placeholder="ex: TS6X-... ou nome do cliente">
      </div>
    </div>
    <div style="margin-top:14px">
      <button class="btn">Filtrar</button>
      <a class="btn sec" href="relatorio.php" style="margin-left:8px">Limpar</a>
    </div>
  </form>
</div>

<?php if ($resumo): ?>
<div class="card">
  <h3>Resumo do período (<?= number_format($total,0,',','.') ?> registro<?= $total==1?'':'s' ?>)</h3>
  <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:8px">
    <?php foreach ($resumo as $rr): $r=rotuloAcao($rr['acao']); ?>
      <div style="min-width:120px">
        <div style="font-size:26px;font-weight:700;color:var(--texto)"><?= number_format($rr['n'],0,',','.') ?></div>
        <div class="subtitulo" style="margin:0"><?= e($r[0]) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h3>Registros</h3>
  <table>
    <thead><tr>
      <th>Data/hora</th><th>Ação</th><th>Resultado</th><th>Software/Tipo</th>
      <th>Chave</th><th>Cliente</th><th>Usuário</th><th>Máquina</th><th>Detalhe</th>
    </tr></thead>
    <tbody>
    <?php if (!$linhas): ?>
      <tr><td colspan="9" style="color:var(--texto-2)">Nenhum registro para os filtros escolhidos.</td></tr>
    <?php else: foreach ($linhas as $l):
        $r = rotuloAcao($l['acao']);
        $prodTier = $l['produto_codigo']
            ? strtoupper($l['produto_codigo']).($l['tier_codigo']?' · '.$l['tier_codigo']:'')
            : '—';
        $badgeRes = $l['resultado']==='ok' ? 'ativa' : ($l['resultado']==='negado' ? 'revogada' : 'nova');
    ?>
      <tr>
        <td class="mono" style="font-size:11px"><?= date('d/m/Y H:i', strtotime($l['criado_em'])) ?></td>
        <td><?= e($r[0]) ?></td>
        <td><span class="badge <?= $badgeRes ?>"><?= e($l['resultado']) ?></span></td>
        <td class="mono" style="font-size:11px"><?= e($prodTier) ?></td>
        <td class="mono" style="font-size:11px"><?= e($l['chave'] ?: '—') ?></td>
        <td style="font-size:12px"><?= e($l['razao_social'] ?: '—') ?></td>
        <td style="font-size:12px"><?= e($l['usuario_nome'] ?: '—') ?></td>
        <td class="mono" style="font-size:11px"><?= e($l['fingerprint'] ? substr($l['fingerprint'],0,12).'…' : '—') ?></td>
        <td style="font-size:11px;color:var(--texto-2)"><?= e($l['detalhe'] ?: '') ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php if ($totalPaginas > 1): ?>
    <div style="display:flex;gap:8px;align-items:center;margin-top:16px;justify-content:center">
      <?php if ($pagina > 1): ?>
        <a class="btn sec pequeno" href="<?= e(linkPagina($pagina-1)) ?>">‹ Anterior</a>
      <?php endif; ?>
      <span class="subtitulo" style="margin:0">Página <?= $pagina ?> de <?= $totalPaginas ?></span>
      <?php if ($pagina < $totalPaginas): ?>
        <a class="btn sec pequeno" href="<?= e(linkPagina($pagina+1)) ?>">Próxima ›</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php fecha_pagina();
