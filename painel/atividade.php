<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  ATIVIDADE DE ATIVAÇÃO
 * =====================================================================
 *  AGRUPADA POR LICENÇA, não por evento.
 *
 *  O Relatório já lista tudo em ordem cronológica — bom para auditoria,
 *  ruim para diagnóstico. Quando um cliente liga dizendo que não
 *  consegue ativar, você quer ver a sequência DELE, não rolar mil
 *  linhas procurando as tentativas dele no meio das dos outros.
 *
 *  Aqui cada linha é uma licença que teve movimento, com o resultado da
 *  última tentativa. Expandindo, aparece a sequência inteira.
 *
 *  REVALIDAÇÕES FICAM DE FORA. Cada máquina consulta a cada 7 dias;
 *  incluí-las afogaria as ativações, que são o que interessa aqui. Elas
 *  têm tela própria — exceto quando resultam em bloqueio, que é evento
 *  digno de atenção.
 * ===================================================================== */

$fDias = (int)($_GET['dias'] ?? 7);
if (!in_array($fDias, [1, 7, 30, 90], true)) $fDias = 7;

$fRes = trim($_GET['res'] ?? '');
if (!in_array($fRes, ['ok', 'negado', 'erro'], true)) $fRes = '';

$q = trim($_GET['q'] ?? '');

/* As ações que interessam: ativação, autocadastro e bloqueio de
   revalidação. Revalidação bem-sucedida fica na tela própria. */
$acoes = "('ativar_online','ativar_offline','gerar_offline','autocadastro')";

$where = ["a.acao IN $acoes",
          "a.criado_em >= DATE_SUB(NOW(), INTERVAL $fDias DAY)"];
$args = [];

if ($fRes !== '') { $where[] = 'a.resultado = ?'; $args[] = $fRes; }

if ($q !== '') {
    $where[] = '(a.chave LIKE ? OR a.fingerprint LIKE ? OR c.razao_social LIKE ?
                 OR c.nome_fantasia LIKE ?)';
    $like = "%$q%";
    array_push($args, $like, $like, $like, $like);
}
$wSql = 'WHERE ' . implode(' AND ', $where);

/* ---- contadores do período ------------------------------------------ */
$stC = db()->prepare(
  "SELECT SUM(a.resultado='ok')      AS ok,
          SUM(a.resultado='negado')  AS negado,
          SUM(a.resultado='erro')    AS erro,
          SUM(a.detalhe LIKE '%inexistente%') AS inexistente
     FROM ativacoes_log a
     LEFT JOIN licencas l ON l.id = a.licenca_id
     LEFT JOIN clientes c ON c.id = l.cliente_id
    WHERE a.acao IN $acoes
      AND a.criado_em >= DATE_SUB(NOW(), INTERVAL $fDias DAY)");
$stC->execute();
$cont = $stC->fetch();

/* ---- eventos, agrupados depois em PHP -------------------------------
 * O agrupamento é por CHAVE e não por licenca_id: tentativa com chave
 * inexistente não tem licença, e é justamente a que interessa ver.
 * -------------------------------------------------------------------- */
$st = db()->prepare(
  "SELECT a.*, l.id AS lic_id, l.status AS lic_status,
          COALESCE(c.nome_fantasia, c.razao_social) AS cliente,
          p.codigo AS produto, t.nome AS tier,
          u.nome AS revendedor
     FROM ativacoes_log a
     LEFT JOIN licencas l  ON l.id = a.licenca_id
     LEFT JOIN clientes c  ON c.id = l.cliente_id
     LEFT JOIN produtos p  ON p.id = l.produto_id
     LEFT JOIN tiers    t  ON t.id = l.tier_id
     LEFT JOIN usuarios u  ON u.id = l.revendedor_id
   $wSql
   ORDER BY a.id DESC LIMIT 400");
$st->execute($args);
$eventos = $st->fetchAll();

$grupos = [];
foreach ($eventos as $e) {
    // chave vazia (erro de JSON, por exemplo) vira um grupo por
    // fingerprint, para não juntar máquinas diferentes
    $k = $e['chave'] !== '' && $e['chave'] !== null
       ? $e['chave']
       : ('fp:' . ($e['fingerprint'] ?: 'desconhecido'));

    if (!isset($grupos[$k])) {
        $grupos[$k] = [
            'chave'     => $e['chave'],
            'cliente'   => $e['cliente'],
            'lic_id'    => $e['lic_id'],
            'produto'   => $e['produto'],
            'tier'      => $e['tier'],
            'revendedor'=> $e['revendedor'],
            'fp'        => $e['fingerprint'],
            'ip'        => $e['ip'],
            'ultimo'    => $e['criado_em'],
            'resultado' => $e['resultado'],
            'eventos'   => [],
        ];
    }
    $grupos[$k]['eventos'][] = $e;
    if (!$grupos[$k]['fp'] && $e['fingerprint'])
        $grupos[$k]['fp'] = $e['fingerprint'];
}

function rotulo_acao(string $a): string {
    return [
        'ativar_online'  => 'ativação online',
        'ativar_offline' => 'ativação offline',
        'gerar_offline'  => 'código offline gerado',
        'autocadastro'   => 'cadastro do cliente',
    ][$a] ?? $a;
}

function cor_resultado(string $r): string {
    return $r === 'ok'     ? 'var(--verde)'
         : ($r === 'erro'  ? 'var(--vermelho)' : 'var(--ambar)');
}

function link_at(array $novo = []): string {
    $p = array_merge(['dias'=>$_GET['dias'] ?? '', 'res'=>$_GET['res'] ?? '',
                      'q'=>$_GET['q'] ?? ''], $novo);
    return 'atividade.php?' . http_build_query(array_filter($p, 'strlen'));
}

/** Texto pronto para colar numa conversa com o suporte. */
function diagnostico(array $g): string {
    $q = "\n";
    $t = "DIAGNOSTICO DE ATIVACAO" . $q . $q;
    $t .= 'Chave: ' . ($g['chave'] ?: '(nenhuma)') . $q;
    if ($g['cliente'])    $t .= 'Cliente: ' . $g['cliente'] . $q;
    if ($g['revendedor']) $t .= 'Revendedor: ' . $g['revendedor'] . $q;
    if ($g['produto'])    $t .= 'Software: ' . strtoupper($g['produto'])
                              . ($g['tier'] ? ' - ' . $g['tier'] : '') . $q;
    if ($g['fp'])         $t .= 'Maquina: ' . $g['fp'] . $q;
    if ($g['ip'])         $t .= 'IP: ' . $g['ip'] . $q;

    $t .= $q . 'TENTATIVAS' . $q;
    foreach (array_reverse($g['eventos']) as $ev)
        $t .= date('d/m H:i', strtotime($ev['criado_em'])) . '  '
            . rotulo_acao($ev['acao']) . ' - ' . $ev['resultado']
            . ($ev['detalhe'] ? ': ' . $ev['detalhe'] : '') . $q;

    return $t;
}

abre_pagina('Atividade', 'atividade');
?>
<h1 class="titulo">Atividade de ativação</h1>
<p class="subtitulo">
  Agrupada por licença: veja a sequência de tentativas de cada máquina
</p>

<div class="stats">
  <a class="stat" href="<?= e(link_at(['res'=>''])) ?>"
     style="text-decoration:none;<?= $fRes===''?'outline:2px solid var(--ambar)':'' ?>">
    <div class="n"><?= (int)$cont['ok'] ?></div>
    <div class="l">Ativadas</div></a>
  <a class="stat" href="<?= e(link_at(['res'=>'negado'])) ?>"
     style="text-decoration:none;<?= $fRes==='negado'?'outline:2px solid var(--ambar)':'' ?>">
    <div class="n" style="color:var(--ambar)"><?= (int)$cont['negado'] ?></div>
    <div class="l">Negadas</div></a>
  <a class="stat" href="<?= e(link_at(['res'=>'erro'])) ?>"
     style="text-decoration:none;<?= $fRes==='erro'?'outline:2px solid var(--ambar)':'' ?>">
    <div class="n" style="color:var(--vermelho)"><?= (int)$cont['erro'] ?></div>
    <div class="l">Erros</div></a>
  <div class="stat">
    <div class="n"><?= (int)$cont['inexistente'] ?></div>
    <div class="l">Chave inexistente</div></div>
</div>

<div class="card">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <div style="flex:1;min-width:220px">
      <label>Buscar</label>
      <input name="q" value="<?= e($q) ?>"
             placeholder="cliente, chave ou código da máquina">
    </div>
    <div>
      <label>Período</label>
      <select name="dias" onchange="this.form.submit()">
        <?php foreach ([1=>'Hoje', 7=>'7 dias', 30=>'30 dias', 90=>'90 dias'] as $d=>$r): ?>
          <option value="<?= $d ?>" <?= $fDias===$d?'selected':'' ?>><?= $r ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <input type="hidden" name="res" value="<?= e($fRes) ?>">
    <button class="btn">Filtrar</button>
    <a class="btn sec" href="atividade.php">Limpar</a>
  </form>
</div>

<?php if (!$grupos): ?>
  <div class="card"><p class="subtitulo" style="margin:0">
    Nenhuma tentativa de ativação no período.
  </p></div>
<?php else: ?>
  <div class="card" style="padding:0;overflow:hidden">
    <?php foreach ($grupos as $k => $g):
        $n = count($g['eventos']);
        $falhou = $g['resultado'] !== 'ok';
        $semLic = !$g['lic_id'];
        $borda = $g['resultado'] === 'ok'   ? 'var(--verde)'
               : ($g['resultado'] === 'erro' ? 'var(--vermelho)'
               : ($semLic ? 'var(--borda)' : 'var(--ambar)'));
        $idg = 'g' . md5($k);
    ?>
      <div style="border-bottom:1px solid var(--borda);
           border-left:3px solid <?= $borda ?>">

        <div onclick="alternar('<?= $idg ?>')"
             style="padding:12px 16px;cursor:pointer;display:flex;
             justify-content:space-between;align-items:flex-start;gap:12px;
             flex-wrap:wrap">
          <div style="min-width:0;flex:1">
            <div style="font-size:14px">
              <?php if ($g['cliente']): ?>
                <?= e($g['cliente']) ?>
              <?php elseif ($semLic): ?>
                <span style="color:var(--texto-2)">sem licença correspondente</span>
              <?php else: ?>
                <span style="color:var(--texto-2)">— estoque —</span>
                <?php if ($g['revendedor']): ?>
                  · <?= e($g['revendedor']) ?>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <div class="mono" style="font-size:11px;color:var(--texto-2);
                 margin-top:2px">
              <?= e($g['chave'] ?: '(sem chave)') ?>
              <?php if ($g['fp']): ?> · <?= e($g['fp']) ?><?php endif; ?>
              <?php if ($g['produto']): ?>
                · <?= e(strtoupper($g['produto'])) ?>
                <?= $g['tier'] ? '·' . e($g['tier']) : '' ?>
              <?php endif; ?>
            </div>
          </div>
          <div style="text-align:right;white-space:nowrap">
            <span style="font-size:12px;color:<?= cor_resultado($g['resultado']) ?>">
              <?= $n > 1 ? $n . ' tentativas · ' : '' ?>
              <?= $g['resultado'] === 'ok' ? 'ativada'
                  : ($g['resultado'] === 'erro' ? 'falhou' : 'negada') ?>
            </span>
            <div style="font-size:12px;color:var(--texto-2);margin-top:2px">
              <?= date('d/m H:i', strtotime($g['ultimo'])) ?>
            </div>
          </div>
        </div>

        <div id="<?= $idg ?>" style="display:none;padding:0 16px 14px;
             background:var(--bg-3)">
          <table style="font-size:12px">
            <?php foreach ($g['eventos'] as $ev): ?>
              <tr>
                <td class="mono" style="width:80px;color:var(--texto-2)">
                  <?= date('d/m H:i', strtotime($ev['criado_em'])) ?></td>
                <td style="width:130px;color:<?= cor_resultado($ev['resultado']) ?>">
                  <?= e(rotulo_acao($ev['acao'])) ?></td>
                <td style="color:var(--texto-2)">
                  <?= e($ev['detalhe'] ?: $ev['resultado']) ?></td>
              </tr>
            <?php endforeach; ?>
          </table>

          <div style="display:flex;gap:6px;margin-top:12px;flex-wrap:wrap">
            <?php if ($g['lic_id']): ?>
              <a class="btn sec pequeno"
                 href="licencas.php?q=<?= urlencode($g['chave']) ?>">Abrir licença</a>
            <?php endif; ?>
            <?php if ($g['fp']): ?>
              <a class="btn sec pequeno"
                 href="maquina.php?fp=<?= urlencode($g['fp']) ?>">Ver máquina</a>
            <?php endif; ?>
            <button type="button" class="btn sec pequeno"
                    data-diag="<?= e(json_encode(diagnostico($g),
                                     JSON_UNESCAPED_UNICODE)) ?>"
                    onclick="copiarDiag(this)">Copiar diagnóstico</button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>


<script>
function alternar(id) {
  var el = document.getElementById(id);
  el.style.display = el.style.display === 'none' ? '' : 'none';
}

function copiarDiag(btn) {
  var txt = JSON.parse(btn.getAttribute('data-diag'));
  var ok = function () {
    var antes = btn.textContent;
    btn.textContent = 'Copiado!';
    setTimeout(function () { btn.textContent = antes; }, 1800);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(txt).then(ok);
  } else {
    var ta = document.createElement('textarea');
    ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); ok(); } catch (e) {}
    document.body.removeChild(ta);
  }
}
</script>
<?php fecha_pagina();
