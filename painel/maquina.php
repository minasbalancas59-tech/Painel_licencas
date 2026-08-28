<?php
require 'inc/auth.php';
require 'inc/layout.php';
exige_login();

/* =====================================================================
 *  USO DE UMA MAQUINA
 * =====================================================================
 *  Responde tres perguntas que a lista de maquinas nao responde:
 *    - quantas vezes abriu por dia / por mes
 *    - em que horarios costuma ficar ligado
 *    - quanto tempo durou cada sessao
 *
 *  As sessoes sao RECONSTRUIDAS a partir dos sinais de presenca: uma
 *  sequencia de eventos com menos de LIMITE_SESSAO minutos entre si e
 *  a mesma sessao. Nao existe "hora de logout" confiavel - PC desligado
 *  na tomada nunca envia fechamento -, entao inferir pelo silencio e
 *  mais honesto que confiar num evento que pode nunca chegar.
 * ===================================================================== */

const LIMITE_SESSAO = 35;   // minutos de silencio que encerram a sessao
                            // (o cliente sinaliza presenca a cada 15)

$fp = trim($_GET['fp'] ?? '');
if ($fp === '') { header('Location: maquinas.php'); exit; }

$fPeriodo = (int)($_GET['dias'] ?? 30);
if (!in_array($fPeriodo, [7, 30, 90, 365], true)) $fPeriodo = 30;

// ---- dados da maquina ----------------------------------------------
$st = db()->prepare(
  'SELECT m.*, c.razao_social, l.chave, l.expira_em, l.status AS lic_status,
          p.codigo AS produto_codigo, t.nome AS tier_nome
     FROM maquinas m
     LEFT JOIN licencas l ON l.id = m.licenca_id
     LEFT JOIN clientes c ON c.id = COALESCE(m.cliente_id, l.cliente_id)
     LEFT JOIN produtos p ON p.id = l.produto_id
     LEFT JOIN tiers    t ON t.id = l.tier_id
    WHERE m.fingerprint = ? LIMIT 1');
$st->execute([$fp]);
$maq = $st->fetch();
if (!$maq) { header('Location: maquinas.php'); exit; }

$desde = date('Y-m-d 00:00:00', strtotime("-$fPeriodo days"));

// ---- aberturas por dia ---------------------------------------------
$st = db()->prepare(
  "SELECT DATE(ts) AS dia, COUNT(*) AS n
     FROM acessos
    WHERE fingerprint = ? AND tipo = 'abertura' AND ts >= ?
    GROUP BY dia ORDER BY dia DESC");
$st->execute([$fp, $desde]);
$porDia = $st->fetchAll();

// ---- aberturas por mes ---------------------------------------------
$st = db()->prepare(
  "SELECT DATE_FORMAT(ts,'%Y-%m') AS mes, COUNT(*) AS n
     FROM acessos
    WHERE fingerprint = ? AND tipo = 'abertura'
    GROUP BY mes ORDER BY mes DESC LIMIT 12");
$st->execute([$fp]);
$porMes = $st->fetchAll();

// ---- presenca por hora do dia --------------------------------------
// conta QUALQUER evento: o que interessa aqui e "estava ligado nessa
// hora", nao "abriu nessa hora"
$st = db()->prepare(
  "SELECT HOUR(ts) AS h, COUNT(*) AS n
     FROM acessos
    WHERE fingerprint = ? AND ts >= ?
    GROUP BY h ORDER BY h");
$st->execute([$fp, $desde]);
$porHora = array_fill(0, 24, 0);
foreach ($st->fetchAll() as $r) { $porHora[(int)$r['h']] = (int)$r['n']; }

// ---- sessoes (reconstruidas) ---------------------------------------
$st = db()->prepare(
  "SELECT ts, tipo FROM acessos
    WHERE fingerprint = ? AND ts >= ?
    ORDER BY ts");
$st->execute([$fp, $desde]);
$eventos = $st->fetchAll();

$sessoes = [];
$ini = $fim = null;
foreach ($eventos as $ev) {
    $t = strtotime($ev['ts']);
    if ($ini === null) { $ini = $fim = $t; continue; }
    if (($t - $fim) > LIMITE_SESSAO * 60) {
        $sessoes[] = ['ini' => $ini, 'fim' => $fim];
        $ini = $t;
    }
    $fim = $t;
}
if ($ini !== null) $sessoes[] = ['ini' => $ini, 'fim' => $fim];
$sessoes = array_reverse($sessoes);

$totalSeg = 0;
foreach ($sessoes as $s) { $totalSeg += ($s['fim'] - $s['ini']); }
$horasTotal = $totalSeg / 3600;
$aberturasPeriodo = array_sum(array_column($porDia, 'n'));
$diasComUso = count($porDia);

function dur($seg) {
    if ($seg < 60) return 'menos de 1 min';
    $h = intdiv($seg, 3600); $m = intdiv($seg % 3600, 60);
    return $h > 0 ? "{$h}h {$m}min" : "{$m}min";
}

abre_pagina('Uso da máquina', 'maquinas');
?>
<h1 class="titulo"><?= e($maq['maq_nome'] ?: 'Máquina sem nome') ?></h1>
<p class="subtitulo">
  <?= e($maq['razao_social'] ?: 'cliente não vinculado') ?>
  · <?= e($maq['maq_usuario'] ?: '—') ?>
  · <?= e($maq['maq_so'] ?: '—') ?>
  · <span class="mono"><?= e($maq['fingerprint']) ?></span>
</p>

<div class="card">
  <form method="get" style="display:flex;gap:12px;align-items:flex-end">
    <input type="hidden" name="fp" value="<?= e($fp) ?>">
    <div>
      <label>Período</label>
      <select name="dias" onchange="this.form.submit()">
        <?php foreach ([7=>'7 dias',30=>'30 dias',90=>'90 dias',365=>'1 ano'] as $d=>$rot): ?>
          <option value="<?= $d ?>" <?= $fPeriodo===$d?'selected':'' ?>><?= $rot ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <a class="btn sec" href="maquinas.php">Voltar</a>
  </form>
</div>

<div class="card">
  <div style="display:flex;gap:34px;flex-wrap:wrap">
    <div>
      <div style="font-size:26px;font-weight:700"><?= number_format($aberturasPeriodo,0,',','.') ?></div>
      <div class="subtitulo" style="margin:0">Aberturas no período</div>
    </div>
    <div>
      <div style="font-size:26px;font-weight:700"><?= $diasComUso ?></div>
      <div class="subtitulo" style="margin:0">Dias com uso</div>
    </div>
    <div>
      <div style="font-size:26px;font-weight:700"><?= number_format($horasTotal,1,',','.') ?>h</div>
      <div class="subtitulo" style="margin:0">Tempo ligado (estimado)</div>
    </div>
    <div>
      <div style="font-size:26px;font-weight:700"><?= number_format((int)$maq['aberturas'],0,',','.') ?></div>
      <div class="subtitulo" style="margin:0">Aberturas desde sempre</div>
    </div>
  </div>
</div>

<div class="card">
  <h3>Horário de uso</h3>
  <p class="subtitulo">Em que horas do dia esta máquina costuma estar ligada</p>
  <?php $picoH = max(1, max($porHora)); ?>
  <div style="display:flex;align-items:flex-end;gap:3px;height:140px;margin-top:14px">
    <?php for ($h = 0; $h < 24; $h++):
        $alt = (int)round(120 * $porHora[$h] / $picoH); ?>
      <div style="flex:1;text-align:center" title="<?= $h ?>h — <?= $porHora[$h] ?> registros">
        <div style="height:<?= max(2,$alt) ?>px;background:<?= $porHora[$h] ? 'var(--verde,#38b26b)' : 'var(--borda,#2a3138)' ?>;border-radius:3px 3px 0 0"></div>
        <div class="mono" style="font-size:9px;color:var(--texto-2);margin-top:4px"><?= $h ?></div>
      </div>
    <?php endfor; ?>
  </div>
</div>

<div class="card">
  <h3>Sessões</h3>
  <p class="subtitulo">
    Reconstruídas pelos sinais de presença; um intervalo maior que
    <?= LIMITE_SESSAO ?> minutos encerra a sessão.
  </p>
  <table>
    <thead><tr><th>Início</th><th>Fim</th><th>Duração</th></tr></thead>
    <tbody>
    <?php if (!$sessoes): ?>
      <tr><td colspan="3" style="color:var(--texto-2)">
        Nenhum registro no período. Se a máquina está em uso, o cliente
        ainda pode estar com a versão anterior do sistema.
      </td></tr>
    <?php else: foreach (array_slice($sessoes, 0, 60) as $s): ?>
      <tr>
        <td class="mono" style="font-size:11px"><?= date('d/m/Y H:i', $s['ini']) ?></td>
        <td class="mono" style="font-size:11px"><?= date('d/m/Y H:i', $s['fim']) ?></td>
        <td class="mono" style="font-size:11px"><?= e(dur($s['fim'] - $s['ini'])) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
  <div class="card">
    <h3>Aberturas por dia</h3>
    <table>
      <thead><tr><th>Dia</th><th>Aberturas</th><th></th></tr></thead>
      <tbody>
      <?php if (!$porDia): ?>
        <tr><td colspan="3" style="color:var(--texto-2)">Sem registros.</td></tr>
      <?php else: $picoD = max(1, max(array_column($porDia,'n')));
        foreach (array_slice($porDia,0,31) as $d): ?>
        <tr>
          <td class="mono" style="font-size:11px"><?= date('d/m/Y', strtotime($d['dia'])) ?></td>
          <td class="mono"><?= (int)$d['n'] ?></td>
          <td style="width:50%">
            <div style="height:8px;border-radius:4px;background:var(--verde,#38b26b);width:<?= (int)round(100*$d['n']/$picoD) ?>%"></div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3>Aberturas por mês</h3>
    <table>
      <thead><tr><th>Mês</th><th>Aberturas</th><th></th></tr></thead>
      <tbody>
      <?php if (!$porMes): ?>
        <tr><td colspan="3" style="color:var(--texto-2)">Sem registros.</td></tr>
      <?php else: $picoM = max(1, max(array_column($porMes,'n')));
        foreach ($porMes as $m): ?>
        <tr>
          <td class="mono" style="font-size:11px"><?= e($m['mes']) ?></td>
          <td class="mono"><?= (int)$m['n'] ?></td>
          <td style="width:50%">
            <div style="height:8px;border-radius:4px;background:var(--verde,#38b26b);width:<?= (int)round(100*$m['n']/$picoM) ?>%"></div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php fecha_pagina();
