<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  REVALIDAÇÕES — saúde do parque
 * =====================================================================
 *  Responde uma pergunta que o resto do painel não responde: QUAL PARTE
 *  DO PARQUE ESTÁ REALMENTE REPORTANDO?
 *
 *  DOIS SINAIS, e a diferença entre eles é o que interessa:
 *
 *    último ping        a cada 15 min — diz se a máquina está viva
 *    última revalidação a cada 7 dias — diz se o licenciamento funciona
 *
 *  Ping em dia + revalidação parada = SOFTWARE DESATUALIZADO. O
 *  executável tem o ping mas não a revalidação. Foi o caso do TS6 até
 *  setembro de 2026, e é o número que mede a migração do parque.
 *
 *  Os dois parados = máquina desligada ou cliente que parou de usar.
 *
 *  Nenhum dos dois é bloqueio: a licença vale pelo prazo pago. Isto
 *  aqui é diagnóstico, não cobrança.
 * ===================================================================== */

$fFaixa = trim($_GET['faixa'] ?? '');
$q = trim($_GET['q'] ?? '');

/* ---------------------------------------------------------------------
 *  Última revalidação por licença.
 *
 *  Vem do log, não de uma coluna: a revalidação bem-sucedida registra
 *  'revalidar' com resultado ok. Uma coluna em `licencas` seria mais
 *  rápida, mas exigiria migração e perderia o histórico que já existe.
 * ------------------------------------------------------------------- */
$sql = "
  SELECT l.id, l.chave, l.expira_em, l.status,
         COALESCE(c.nome_fantasia, c.razao_social, '(sem cliente)') AS cliente,
         p.codigo AS produto, t.nome AS tier,
         COALESCE(u.nome_fantasia, u.empresa, u.nome) AS revendedor,
         m.fingerprint, m.ultimo_acesso,
         DATEDIFF(NOW(), m.ultimo_acesso) AS dias_ping,
         (SELECT MAX(a.criado_em) FROM ativacoes_log a
           WHERE a.licenca_id = l.id
             AND a.acao IN ('revalidar','ativar_online','ativar_offline')
             AND a.resultado = 'ok') AS ultima_reval
    FROM licencas l
    LEFT JOIN clientes c ON c.id = l.cliente_id
    LEFT JOIN produtos p ON p.id = l.produto_id
    LEFT JOIN tiers    t ON t.id = l.tier_id
    LEFT JOIN usuarios u ON u.id = l.revendedor_id
    LEFT JOIN maquinas m ON m.fingerprint = l.fingerprint
   WHERE l.status = 'ativa' AND l.fingerprint IS NOT NULL";

$args = [];
if ($q !== '') {
    $sql .= " AND (c.razao_social LIKE ? OR c.nome_fantasia LIKE ?
                   OR l.chave LIKE ? OR m.fingerprint LIKE ?)";
    $like = "%$q%";
    array_push($args, $like, $like, $like, $like);
}

$st = db()->prepare($sql);
$st->execute($args);
$linhas = $st->fetchAll();

/* ---- classifica cada máquina ---------------------------------------- */
$hoje = new DateTime('today');
foreach ($linhas as &$l) {
    $l['dias_reval'] = null;
    if ($l['ultima_reval']) {
        $d = new DateTime(substr($l['ultima_reval'], 0, 10));
        $l['dias_reval'] = (int)$hoje->diff($d)->days;
    }
    $dp = $l['dias_ping'] === null ? null : (int)$l['dias_ping'];
    $dr = $l['dias_reval'];

    /* A leitura combina os dois sinais. É o que transforma dois números
       soltos numa informação acionável. */
    if ($dr === null) {
        $l['faixa']   = 'nunca';
        $l['leitura'] = ($dp !== null && $dp <= 7)
                      ? 'versão sem revalidação'
                      : 'nunca reportou';
    } elseif ($dr > 30) {
        $l['faixa']   = 'sem_contato';
        $l['leitura'] = ($dp !== null && $dp <= 7)
                      ? 'versão sem revalidação'
                      : 'máquina parada';
    } elseif ($dr > 7) {
        $l['faixa']   = 'atrasada';
        $l['leitura'] = 'sem internet';
    } else {
        $l['faixa']   = 'em_dia';
        $l['leitura'] = 'em dia';
    }
}
unset($l);

$cont = ['em_dia'=>0, 'atrasada'=>0, 'sem_contato'=>0, 'nunca'=>0];
foreach ($linhas as $l) $cont[$l['faixa']]++;

if ($fFaixa !== '' && isset($cont[$fFaixa]))
    $linhas = array_values(array_filter($linhas,
        function ($x) use ($fFaixa) { return $x['faixa'] === $fFaixa; }));

// pior primeiro: quem está há mais tempo sem revalidar
usort($linhas, function ($a, $b) {
    $va = $a['dias_reval'] === null ? 99999 : $a['dias_reval'];
    $vb = $b['dias_reval'] === null ? 99999 : $b['dias_reval'];
    return $vb <=> $va;
});

function dias_txt(?int $d): string {
    if ($d === null) return 'nunca';
    if ($d === 0)    return 'hoje';
    if ($d === 1)    return 'ontem';
    return $d . ' dias';
}
function cor_dias(?int $d): string {
    if ($d === null) return 'var(--vermelho)';
    if ($d <= 7)     return 'var(--verde)';
    if ($d <= 30)    return 'var(--ambar)';
    return 'var(--vermelho)';
}
function link_rv(array $novo = []): string {
    $p = array_merge(['faixa'=>$_GET['faixa'] ?? '', 'q'=>$_GET['q'] ?? ''], $novo);
    return 'revalidacoes.php?' . http_build_query(array_filter($p, 'strlen'));
}

abre_pagina('Revalidações', 'revalidacoes');
?>
<h1 class="titulo">Revalidações</h1>
<p class="subtitulo">
  Quais máquinas estão realmente reportando ao servidor
</p>

<div class="stats">
  <?php foreach ([
      'em_dia'      => ['Em dia',          'até 7 dias',       'var(--verde)'],
      'atrasada'    => ['Atrasadas',       '8 a 30 dias',      'var(--ambar)'],
      'sem_contato' => ['Sem contato',     'mais de 30 dias',  'var(--vermelho)'],
      'nunca'       => ['Nunca revalidou', 'versão antiga',    ''],
  ] as $k => $d): ?>
    <a class="stat" href="<?= e(link_rv(['faixa' => $fFaixa === $k ? '' : $k])) ?>"
       style="text-decoration:none;<?= $fFaixa===$k?'outline:2px solid var(--ambar)':'' ?>">
      <div class="n" style="<?= $d[2] ? 'color:'.$d[2] : '' ?>"><?= $cont[$k] ?></div>
      <div class="l"><?= $d[0] ?></div>
      <div class="l" style="font-size:10px;opacity:.7"><?= $d[1] ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($cont['nunca'] > 0): ?>
  <div class="card" style="border-left:3px solid var(--azul)">
    <p style="margin:0;font-size:13px">
      <b><?= $cont['nunca'] ?> licença(s) nunca revalidaram.</b>
      Quando o ping está em dia mas a revalidação não, o executável
      naquela máquina é anterior ao recurso — atualize o software e o
      número cai. Nenhuma delas está bloqueada: a licença vale pelo prazo
      pago.
    </p>
  </div>
<?php endif; ?>

<div class="card">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <div style="flex:1;min-width:220px">
      <label>Buscar</label>
      <input name="q" value="<?= e($q) ?>" placeholder="cliente, chave ou máquina">
    </div>
    <input type="hidden" name="faixa" value="<?= e($fFaixa) ?>">
    <button class="btn">Filtrar</button>
    <a class="btn sec" href="revalidacoes.php">Limpar</a>
  </form>
</div>

<div class="card">
  <h3>
    <?= count($linhas) ?> licença(s) ativa(s)
    <?= $fFaixa !== '' ? '· filtrado' : '' ?>
  </h3>
  <p class="subtitulo" style="margin-top:-6px">
    Ordenado pelo maior tempo sem revalidar. A coluna <b>leitura</b>
    combina os dois sinais.
  </p>

  <table>
    <thead><tr>
      <th>Cliente</th>
      <th style="width:90px">Último ping</th>
      <th style="width:100px">Revalidação</th>
      <th style="width:160px">Leitura</th>
      <th style="width:60px"></th>
    </tr></thead>
    <tbody>
    <?php if (!$linhas): ?>
      <tr><td colspan="5" style="color:var(--texto-2)">
        Nenhuma licença ativa com máquina registrada.</td></tr>
    <?php else: foreach ($linhas as $l): ?>
      <tr>
        <td>
          <?= e($l['cliente']) ?>
          <br><span class="mono" style="font-size:11px;color:var(--texto-2)">
            <?= e(strtoupper($l['produto'] ?? '—')) ?>
            <?= $l['tier'] ? '· ' . e($l['tier']) : '' ?>
            <?= $l['revendedor'] ? '· ' . e($l['revendedor']) : '' ?>
          </span>
        </td>
        <td style="color:<?= cor_dias($l['dias_ping'] === null ? null : (int)$l['dias_ping']) ?>">
          <?= dias_txt($l['dias_ping'] === null ? null : (int)$l['dias_ping']) ?></td>
        <td style="color:<?= cor_dias($l['dias_reval']) ?>">
          <?= dias_txt($l['dias_reval']) ?></td>
        <td style="font-size:12px;color:<?=
            $l['faixa'] === 'em_dia' ? 'var(--verde)'
            : ($l['faixa'] === 'atrasada' ? 'var(--texto-2)' : 'var(--ambar)') ?>">
          <?= e($l['leitura']) ?></td>
        <td>
          <?php if ($l['fingerprint']): ?>
            <a class="btn sec pequeno"
               href="maquina.php?fp=<?= urlencode($l['fingerprint']) ?>">Ver</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php fecha_pagina();
