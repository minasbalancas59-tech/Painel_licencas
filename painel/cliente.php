<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();

/* =====================================================================
 *  CLIENTE - ficha completa
 * =====================================================================
 *  Tres blocos, cada um com filtro proprio:
 *    licencas  - historico completo, filtravel por status/software
 *    maquinas  - onde o software roda
 *    uso       - graficos de abertura no periodo escolhido
 *
 *  O uso vem da tabela `acessos` (sinais enviados pelo software), nao
 *  do contador da tabela `maquinas`: o contador e um total acumulado,
 *  enquanto `acessos` guarda quando cada abertura aconteceu - e e isso
 *  que permite montar a serie temporal.
 * ===================================================================== */

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: clientes.php'); exit; }

$stc = db()->prepare('SELECT * FROM clientes WHERE id=?');
$stc->execute([$id]);
$cli = $stc->fetch();
if (!$cli) { header('Location: clientes.php'); exit; }

// revendedor so ve os proprios clientes
$rev = revendedor_atual();
if ($rev !== null && (int)$cli['revendedor_id'] !== $rev) {
    http_response_code(403);
    exit('Este cliente não pertence a você.');
}

// ---- contatos: adicionar / remover / marcar principal ---------------
$msgC=''; $tipoC='';
if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_valido()) {
    $ac = $_POST['acao'] ?? '';

    if ($ac === 'contato_novo') {
        $nome = trim($_POST['c_nome'] ?? '');
        if ($nome === '') { $msgC='Informe o nome do contato.'; $tipoC='erro'; }
        else {
            db()->prepare(
              'INSERT INTO cliente_contatos
                 (cliente_id,nome,cargo,telefone,email,observacao)
               VALUES (?,?,?,?,?,?)')
              ->execute([$id, $nome,
                         (trim($_POST['c_cargo'] ?? '') ?: null),
                         (trim($_POST['c_telefone'] ?? '') ?: null),
                         (trim($_POST['c_email'] ?? '') ?: null),
                         (trim($_POST['c_obs'] ?? '') ?: null)]);
            $msgC='Contato adicionado.'; $tipoC='ok';
        }
    }
    elseif ($ac === 'contato_remove') {
        // o cliente_id no WHERE impede remover contato de outro cliente
        db()->prepare('DELETE FROM cliente_contatos WHERE id=? AND cliente_id=?')
            ->execute([(int)$_POST['c_id'], $id]);
        $msgC='Contato removido.'; $tipoC='ok';
    }
    elseif ($ac === 'cliente_editar') {
        $razao = trim($_POST['razao_social'] ?? '');
        if ($razao === '') { $msgC='A razao social nao pode ficar vazia.'; $tipoC='erro'; }
        else {
            db()->prepare(
              'UPDATE clientes
                  SET razao_social=?, nome_fantasia=?, cnpj=?,
                      municipio=?, uf=?, observacao=?
                WHERE id=?')
              ->execute([$razao,
                         (trim($_POST['nome_fantasia'] ?? '') ?: null),
                         trim($_POST['cnpj'] ?? ''),
                         (trim($_POST['municipio'] ?? '') ?: null),
                         (strtoupper(substr(trim($_POST['uf'] ?? ''),0,2)) ?: null),
                         trim($_POST['observacao'] ?? ''),
                         $id]);
            // recarrega para o cabecalho refletir a mudanca na hora
            $stc->execute([$id]);
            $cli = $stc->fetch();
            $msgC='Cadastro atualizado.'; $tipoC='ok';
        }
    }
    elseif ($ac === 'contato_editar') {
        $nome = trim($_POST['c_nome'] ?? '');
        if ($nome === '') { $msgC='Informe o nome do contato.'; $tipoC='erro'; }
        else {
            // cliente_id no WHERE impede editar contato de outro cliente
            db()->prepare(
              'UPDATE cliente_contatos
                  SET nome=?, cargo=?, telefone=?, email=?, observacao=?
                WHERE id=? AND cliente_id=?')
              ->execute([$nome,
                         (trim($_POST['c_cargo'] ?? '') ?: null),
                         (trim($_POST['c_telefone'] ?? '') ?: null),
                         (trim($_POST['c_email'] ?? '') ?: null),
                         (trim($_POST['c_obs'] ?? '') ?: null),
                         (int)$_POST['c_id'], $id]);
            $msgC='Contato atualizado.'; $tipoC='ok';
        }
    }
    elseif ($ac === 'contato_principal') {
        db()->prepare('UPDATE cliente_contatos SET principal=0 WHERE cliente_id=?')
            ->execute([$id]);
        db()->prepare('UPDATE cliente_contatos SET principal=1 WHERE id=? AND cliente_id=?')
            ->execute([(int)$_POST['c_id'], $id]);
        $msgC='Contato principal atualizado.'; $tipoC='ok';
    }
}

// ---- contatos do cliente --------------------------------------------
$stCt = db()->prepare(
  'SELECT * FROM cliente_contatos WHERE cliente_id=?
    ORDER BY principal DESC, nome');
$stCt->execute([$id]);
$contatos = $stCt->fetchAll();

// ---- filtros --------------------------------------------------------
$fStatus  = trim($_GET['status'] ?? '');
$fProduto = trim($_GET['produto'] ?? '');
$fDias    = (int)($_GET['dias'] ?? 30);
if (!in_array($fDias, [7,30,90,365], true)) $fDias = 30;

// ---- licencas (com filtro) ------------------------------------------
$wLic = ['l.cliente_id = ?']; $aLic = [$id];
if ($fStatus  !== '') { $wLic[] = 'l.status = ?';  $aLic[] = $fStatus; }
if ($fProduto !== '') { $wLic[] = 'p.codigo = ?';  $aLic[] = $fProduto; }

$stl = db()->prepare(
  'SELECT l.*, t.nome AS tier_nome, t.codigo AS tier_codigo,
          p.codigo AS produto_codigo, ur.nome AS revogada_por_nome,
          DATEDIFF(l.expira_em, CURDATE()) AS dias_restantes
     FROM licencas l
     LEFT JOIN tiers t    ON t.id = l.tier_id
     LEFT JOIN produtos p ON p.id = l.produto_id
     LEFT JOIN usuarios ur ON ur.id = l.revogada_por
    WHERE '.implode(' AND ', $wLic).'
    ORDER BY l.id DESC');
$stl->execute($aLic);
$licencas = $stl->fetchAll();

// produtos que este cliente tem (para o select do filtro)
$stp = db()->prepare(
  'SELECT DISTINCT p.codigo FROM licencas l
     JOIN produtos p ON p.id=l.produto_id
    WHERE l.cliente_id=? ORDER BY p.codigo');
$stp->execute([$id]);
$produtosCli = $stp->fetchAll(PDO::FETCH_COLUMN);

// ---- resumo ---------------------------------------------------------
$resumo = db()->prepare(
  "SELECT COUNT(*) AS total,
          SUM(status='ativa')    AS ativas,
          SUM(status='revogada') AS revogadas,
          SUM(expira_em < CURDATE()) AS vencidas
     FROM licencas WHERE cliente_id=?");
$resumo->execute([$id]);
$resumo = $resumo->fetch();

// ---- maquinas -------------------------------------------------------
$stm = db()->prepare(
  'SELECT m.*, l.chave, t.codigo AS tier_codigo, p.codigo AS produto_codigo
     FROM maquinas m
     LEFT JOIN licencas l ON l.id = m.licenca_id
     LEFT JOIN tiers t    ON t.id = l.tier_id
     LEFT JOIN produtos p ON p.id = l.produto_id
    WHERE m.cliente_id = ?
    ORDER BY m.ultimo_acesso DESC');
$stm->execute([$id]);
$maquinas = $stm->fetchAll();

// ---- uso: aberturas por dia no periodo ------------------------------
$janela = $fDias - 1;   // valor ja validado contra [7,30,90,365]
$stu = db()->prepare(
  "SELECT DATE(ts) AS dia, COUNT(*) AS n
     FROM acessos
    WHERE cliente_id = ? AND tipo='abertura'
      AND ts >= DATE_SUB(CURDATE(), INTERVAL $janela DAY)
    GROUP BY dia ORDER BY dia");
$stu->execute([$id]);
$usoRaw = [];
foreach ($stu->fetchAll() as $r) $usoRaw[$r['dia']] = (int)$r['n'];

// 365 rotulos diarios viram um borrao ilegivel: no periodo de 1 ano,
// agrupa por mes; nos demais, mantem o detalhe diario.
$labDia = []; $datDia = [];
if ($fDias === 365) {
    for ($i = 11; $i >= 0; $i--) {
        $mes = date('Y-m', strtotime("-$i month"));
        $soma = 0;
        foreach ($usoRaw as $d => $n) {
            if (strpos($d, $mes) === 0) $soma += $n;
        }
        $labDia[] = date('m/y', strtotime($mes.'-01'));
        $datDia[] = $soma;
    }
} else {
    for ($i = $fDias - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $labDia[] = date('d/m', strtotime($d));
        $datDia[] = $usoRaw[$d] ?? 0;
    }
}

// ---- uso por maquina no periodo -------------------------------------
$stmu = db()->prepare(
  "SELECT COALESCE(m.maq_nome, LEFT(a.fingerprint,12)) AS nome, COUNT(*) AS n
     FROM acessos a
     LEFT JOIN maquinas m ON m.fingerprint = a.fingerprint
    WHERE a.cliente_id = ? AND a.tipo='abertura'
      AND a.ts >= DATE_SUB(CURDATE(), INTERVAL $janela DAY)
    GROUP BY nome ORDER BY n DESC LIMIT 10");
$stmu->execute([$id]);
$usoMaquina = $stmu->fetchAll();

// ---- horarios de uso -------------------------------------------------
$sth = db()->prepare(
  "SELECT HOUR(ts) AS h, COUNT(*) AS n
     FROM acessos
    WHERE cliente_id = ? AND ts >= DATE_SUB(CURDATE(), INTERVAL $janela DAY)
    GROUP BY h");
$sth->execute([$id]);
$horaRaw = [];
foreach ($sth->fetchAll() as $r) $horaRaw[(int)$r['h']] = (int)$r['n'];
$datHora = [];
for ($h = 0; $h < 24; $h++) $datHora[] = $horaRaw[$h] ?? 0;

$totalAberturas = array_sum($datDia);

function tempoAtras($dt) {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 60)      return 'agora mesmo';
    if ($diff < 3600)    return floor($diff/60).' min atrás';
    if ($diff < 86400)   return floor($diff/3600).' h atrás';
    if ($diff < 2592000) return floor($diff/86400).' dia(s) atrás';
    return date('d/m/Y', strtotime($dt));
}

// URL atual (com filtros) para os forms de contato nao perderem o estado
function urlAtual() {
    $base = [];
    foreach (['id','status','produto','dias'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    return 'cliente.php?'.http_build_query($base);
}

function linkFiltro(array $novo) {
    // repassa apenas os filtros conhecidos - $_GET inteiro carregaria
    // qualquer parametro estranho colado na URL
    $base = [];
    foreach (['id','status','produto','dias'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    return 'cliente.php?'.http_build_query(array_merge($base, $novo));
}

$ROTULO_MOTIVO = [
    'inadimplencia' => 'Inadimplência',
    'cancelamento'  => 'Cancelamento pelo cliente',
    'troca_licenca' => 'Substituída por outra licença',
    'uso_indevido'  => 'Uso indevido',
    'erro_emissao'  => 'Erro na emissão',
    'outro'         => 'Outro',
];

abre_pagina('Cliente', 'clientes');
?>
<p class="subtitulo" style="margin-bottom:4px"><a href="clientes.php">‹ Clientes</a></p>
<h1 class="titulo"><?= e($cli['nome_fantasia'] ?: $cli['razao_social']) ?></h1>
<p class="subtitulo">
  <?php if ($cli['nome_fantasia']): ?><?= e($cli['razao_social']) ?> · <?php endif; ?>
  <?= e($cli['cnpj'] ?: 'sem CNPJ') ?>
  <?php if (!empty($cli['municipio'])): ?>
    · <?= e($cli['municipio']) ?><?= $cli['uf'] ? '/'.e($cli['uf']) : '' ?>
  <?php endif; ?>
</p>

<?php if ($msgC): ?><div class="aviso <?= $tipoC ?>"><?= e($msgC) ?></div><?php endif; ?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h3 style="margin:0">Cadastro</h3>
    <button type="button" class="btn sec pequeno"
            onclick="alternar('boxEditar')">Editar cadastro</button>
  </div>

  <div id="boxEditar" style="display:none;margin-top:16px">
    <form method="post" action="<?= e(urlAtual()) ?>">
      <input type="hidden" name="acao" value="cliente_editar">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

      <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <div style="flex:0 0 220px">
          <label>CNPJ</label>
          <input name="cnpj" id="fCnpj" value="<?= e($cli['cnpj']) ?>">
        </div>
        <button type="button" class="btn sec" onclick="buscarCnpj()">
          Atualizar pela Receita
        </button>
        <span id="cnpjStatus" class="subtitulo" style="margin:0 0 8px"></span>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px">
        <div>
          <label>Razão social *</label>
          <input name="razao_social" id="fRazao" required
                 value="<?= e($cli['razao_social']) ?>">
        </div>
        <div>
          <label>Nome fantasia</label>
          <input name="nome_fantasia" id="fFantasia"
                 value="<?= e($cli['nome_fantasia'] ?? '') ?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-top:12px">
        <div><label>Município</label>
          <input name="municipio" id="fMunicipio" value="<?= e($cli['municipio'] ?? '') ?>"></div>
        <div><label>UF</label>
          <input name="uf" id="fUf" maxlength="2" value="<?= e($cli['uf'] ?? '') ?>"></div>
      </div>

      <label style="margin-top:12px">Observação</label>
      <textarea name="observacao" style="min-height:60px"><?= e($cli['observacao'] ?? '') ?></textarea>

      <div style="margin-top:12px">
        <button class="btn">Salvar alterações</button>
        <button type="button" class="btn sec" style="margin-left:8px"
                onclick="alternar('boxEditar')">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <h3>Contatos (<?= count($contatos) ?>)</h3>
  <table>
    <thead><tr>
      <th>Nome</th><th>Cargo</th><th>Telefone</th><th>E-mail</th>
      <th>Observação</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$contatos): ?>
      <tr><td colspan="6" style="color:var(--texto-2)">Nenhum contato cadastrado.</td></tr>
    <?php else: foreach ($contatos as $ct): ?>
      <tr id="ver<?= $ct['id'] ?>">
        <td>
          <b><?= e($ct['nome']) ?></b>
          <?php if ($ct['principal']): ?>
            <span class="badge ativa" style="font-size:10px">principal</span>
          <?php endif; ?>
        </td>
        <td style="font-size:12px"><?= e($ct['cargo'] ?: '—') ?></td>
        <td class="mono" style="font-size:12px"><?= e($ct['telefone'] ?: '—') ?></td>
        <td style="font-size:12px">
          <?php if ($ct['email']): ?>
            <a href="mailto:<?= e($ct['email']) ?>"><?= e($ct['email']) ?></a>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td style="font-size:11px;color:var(--texto-2)"><?= e($ct['observacao'] ?: '') ?></td>
        <td style="white-space:nowrap">
          <button type="button" class="btn sec pequeno"
                  onclick="editarContato(<?= $ct['id'] ?>)">Editar</button>
          <?php if (!$ct['principal']): ?>
            <form method="post" action="<?= e(urlAtual()) ?>" style="display:inline">
              <input type="hidden" name="acao" value="contato_principal">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="c_id" value="<?= $ct['id'] ?>">
              <button class="btn sec pequeno">Tornar principal</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= e(urlAtual()) ?>" style="display:inline"
                onsubmit="return confirm('Remover este contato?')">
            <input type="hidden" name="acao" value="contato_remove">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="c_id" value="<?= $ct['id'] ?>">
            <button class="btn perigo pequeno">Remover</button>
          </form>
        </td>
      </tr>
      <tr id="edt<?= $ct['id'] ?>" style="display:none">
        <td colspan="6" style="background:var(--bg-3)">
          <form method="post" action="<?= e(urlAtual()) ?>">
            <input type="hidden" name="acao" value="contato_editar">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="c_id" value="<?= $ct['id'] ?>">
            <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
              <div><label>Nome *</label>
                <input name="c_nome" required value="<?= e($ct['nome']) ?>"></div>
              <div><label>Cargo / setor</label>
                <input name="c_cargo" value="<?= e($ct['cargo'] ?? '') ?>"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:10px">
              <div><label>Telefone</label>
                <input name="c_telefone" value="<?= e($ct['telefone'] ?? '') ?>"></div>
              <div><label>E-mail</label>
                <input name="c_email" type="email" value="<?= e($ct['email'] ?? '') ?>"></div>
              <div><label>Observação</label>
                <input name="c_obs" value="<?= e($ct['observacao'] ?? '') ?>"></div>
            </div>
            <div style="margin-top:10px">
              <button class="btn pequeno">Salvar</button>
              <button type="button" class="btn sec pequeno" style="margin-left:6px"
                      onclick="editarContato(<?= $ct['id'] ?>)">Cancelar</button>
            </div>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <button type="button" class="btn sec" style="margin-top:14px"
          onclick="alternar('boxContato')">
    + Adicionar contato
  </button>

  <div id="boxContato" style="display:none;margin-top:16px;
       border-top:1px solid var(--borda);padding-top:16px">
    <form method="post" action="<?= e(urlAtual()) ?>">
      <input type="hidden" name="acao" value="contato_novo">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
        <div><label>Nome *</label><input name="c_nome" required></div>
        <div><label>Cargo / setor</label>
          <input name="c_cargo" placeholder="ex: Operador, TI, Financeiro"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-top:12px">
        <div><label>Telefone</label><input name="c_telefone"></div>
        <div><label>E-mail</label><input name="c_email" type="email"></div>
        <div><label>Observação</label><input name="c_obs"></div>
      </div>
      <button class="btn" style="margin-top:12px">Adicionar contato</button>
    </form>
  </div>
</div>

<div class="stats">
  <div class="stat"><div class="n"><?= (int)$resumo['total'] ?></div><div class="l">Licenças</div></div>
  <div class="stat"><div class="n" style="color:var(--verde)"><?= (int)$resumo['ativas'] ?></div><div class="l">Ativas</div></div>
  <div class="stat"><div class="n"><?= count($maquinas) ?></div><div class="l">Máquinas</div></div>
  <div class="stat"><div class="n"><?= $totalAberturas ?></div><div class="l">Aberturas (<?= $fDias ?>d)</div></div>
</div>

<div class="card">
  <h3>Licenças</h3>
  <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="dias" value="<?= $fDias ?>">
    <div>
      <label>Status</label>
      <select name="status">
        <option value="">— todos —</option>
        <?php foreach (['ativa','nova','revogada','expirada'] as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Software</label>
      <select name="produto">
        <option value="">— todos —</option>
        <?php foreach ($produtosCli as $p): ?>
          <option value="<?= e($p) ?>" <?= $fProduto===$p?'selected':'' ?>><?= e(strtoupper($p)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn">Filtrar</button>
    <a class="btn sec" href="cliente.php?id=<?= $id ?>">Limpar</a>
  </form>

  <table style="margin-top:16px">
    <thead><tr>
      <th>Chave</th><th>Software/Tipo</th><th>Emitida</th>
      <th>Expira</th><th>Situação</th><th>Máquina</th>
    </tr></thead>
    <tbody>
    <?php if (!$licencas): ?>
      <tr><td colspan="6" style="color:var(--texto-2)">
        Nenhuma licença para os filtros escolhidos.
      </td></tr>
    <?php else: foreach ($licencas as $l):
        $prodTier = $l['produto_codigo']
            ? strtoupper($l['produto_codigo']).($l['tier_nome']?' · '.$l['tier_nome']:'')
            : '—';
        $dias = (int)$l['dias_restantes'];
    ?>
      <tr>
        <td class="mono" style="font-size:11px"><?= e($l['chave']) ?>
          <?php if (($l['tipo_licenca'] ?? '')==='demo'): ?>
            <br><span class="badge nova" style="font-size:10px">demonstração</span>
          <?php endif; ?>
        </td>
        <td class="mono" style="font-size:11px"><?= e($prodTier) ?></td>
        <td class="mono" style="font-size:11px"><?= date('d/m/Y', strtotime($l['emitido_em'])) ?></td>
        <td class="mono" style="font-size:11px">
          <?= date('d/m/Y', strtotime($l['expira_em'])) ?>
          <?php if ($dias >= 0 && $dias <= 30): ?>
            <br><span style="font-size:10px;color:var(--ambar)"><?= $dias ?> dias</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge <?= e($l['status']) ?>"><?= e($l['status']) ?></span>
          <?php if ($l['status']==='revogada'): ?>
            <br><span style="font-size:10px;color:var(--texto-2)">
              <?= e($ROTULO_MOTIVO[$l['motivo_revogacao']] ?? 'motivo não informado') ?>
              <?php if ($l['revogada_em']): ?>
                <br><?= date('d/m/Y', strtotime($l['revogada_em'])) ?>
                <?= $l['revogada_por_nome'] ? '· '.e($l['revogada_por_nome']) : '' ?>
              <?php endif; ?>
            </span>
            <?php if (!empty($l['obs_revogacao'])): ?>
              <br><span style="font-size:10px;color:var(--texto-2);font-style:italic">
                "<?= e($l['obs_revogacao']) ?>"</span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td class="mono" style="font-size:10px">
          <?= $l['fingerprint'] ? e(substr($l['fingerprint'],0,14)).'…' : '— não ativada —' ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?php if (eh_admin()): ?>
    <a class="btn" style="margin-top:14px" href="licencas.php?cliente=<?= $id ?>">Emitir nova licença</a>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Uso do software</h3>
  <div style="display:flex;gap:8px;margin-bottom:16px">
    <?php foreach ([7=>'7 dias', 30=>'30 dias', 90=>'90 dias', 365=>'1 ano'] as $d=>$rot): ?>
      <a class="btn <?= $fDias===$d ? '' : 'sec' ?> pequeno"
         href="<?= e(linkFiltro(['dias'=>$d])) ?>"><?= $rot ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($totalAberturas === 0): ?>
    <p class="subtitulo" style="margin:0">
      Nenhuma abertura registrada no período. Os dados aparecem aqui quando
      o software é aberto com acesso à internet.
    </p>
  <?php else: ?>
    <canvas id="gUso" height="80"></canvas>
  <?php endif; ?>
</div>

<?php if ($totalAberturas > 0): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
  <div class="card">
    <h3>Aberturas por máquina</h3>
    <canvas id="gMaquina" height="160"></canvas>
  </div>
  <div class="card">
    <h3>Horários de uso</h3>
    <canvas id="gHora" height="160"></canvas>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h3>Máquinas (<?= count($maquinas) ?>)</h3>
  <table>
    <thead><tr>
      <th>Máquina</th><th>Usuário</th><th>Sistema</th>
      <th>Software/Tipo</th><th>Aberturas</th><th>Último acesso</th>
    </tr></thead>
    <tbody>
    <?php if (!$maquinas): ?>
      <tr><td colspan="6" style="color:var(--texto-2)">
        Nenhuma máquina registrada ainda.
      </td></tr>
    <?php else: foreach ($maquinas as $m):
        $prodTier = $m['produto_codigo']
            ? strtoupper($m['produto_codigo']).($m['tier_codigo']?' · '.$m['tier_codigo']:'')
            : '—';
    ?>
      <tr>
        <td>
          <a href="maquina.php?fp=<?= urlencode($m['fingerprint']) ?>"
             title="<?= e($m['fingerprint']) ?>">
            <b><?= e($m['maq_nome'] ?: '(sem nome)') ?></b></a>
        </td>
        <td style="font-size:12px"><?= e($m['maq_usuario'] ?: '—') ?></td>
        <td style="font-size:11px;color:var(--texto-2)"><?= e($m['maq_so'] ?: '—') ?></td>
        <td class="mono" style="font-size:11px"><?= e($prodTier) ?></td>
        <td class="mono"><?= (int)$m['aberturas'] ?></td>
        <td style="font-size:12px"><?= e(tempoAtras($m['ultimo_acesso'])) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ($cli['observacao']): ?>
<div class="card">
  <h3>Observação</h3>
  <p style="margin:0;white-space:pre-wrap"><?= e($cli['observacao']) ?></p>
</div>
<?php endif; ?>

<?php if ($totalAberturas > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const AMBAR='#f0a92b', VERDE='#38b26b', AZUL='#4a9fd4', CINZA='#93a1ac', BORDA='#313a42';
Chart.defaults.color = CINZA;
Chart.defaults.font.family = 'Inter, sans-serif';
Chart.defaults.font.size = 11;
const grade = { grid:{ color:BORDA }, ticks:{ color:CINZA } };
const eixoY = { ...grade, beginAtZero:true, ticks:{ precision:0, color:CINZA } };
const semLegenda = { legend:{ display:false } };

new Chart(document.getElementById('gUso'), {
  type:'line',
  data:{ labels:<?= json_encode($labDia) ?>,
         datasets:[{ data:<?= json_encode($datDia) ?>,
                     borderColor:VERDE, backgroundColor:'rgba(56,178,107,.12)',
                     fill:true, tension:.3, pointRadius:2 }] },
  options:{ plugins:semLegenda, scales:{ x:grade, y:eixoY } }
});

new Chart(document.getElementById('gMaquina'), {
  type:'bar',
  data:{ labels:<?= json_encode(array_column($usoMaquina,'nome')) ?>,
         datasets:[{ data:<?= json_encode(array_map('intval', array_column($usoMaquina,'n'))) ?>,
                     backgroundColor:AZUL, borderRadius:3 }] },
  options:{ indexAxis:'y', plugins:semLegenda,
            scales:{ x:eixoY, y:grade } }
});

new Chart(document.getElementById('gHora'), {
  type:'bar',
  data:{ labels:<?= json_encode(array_map(fn($h)=>sprintf('%02dh',$h), range(0,23))) ?>,
         datasets:[{ data:<?= json_encode($datHora) ?>,
                     backgroundColor:AMBAR, borderRadius:2 }] },
  options:{ plugins:semLegenda, scales:{ x:grade, y:eixoY } }
});
</script>
<?php endif; ?>

<script>
function alternar(id) {
  const el = document.getElementById(id);
  el.style.display = (el.style.display === 'none') ? '' : 'none';
  if (el.style.display === '') el.scrollIntoView({behavior:'smooth', block:'center'});
}

function editarContato(id) {
  const ver = document.getElementById('ver' + id);
  const edt = document.getElementById('edt' + id);
  const abrindo = edt.style.display === 'none';
  edt.style.display = abrindo ? '' : 'none';
  ver.style.display = abrindo ? 'none' : '';
}

// Aqui a consulta SOBRESCREVE os campos, ao contrario do cadastro novo:
// na edicao o usuario clicou de proposito para atualizar os dados.
function buscarCnpj() {
  const status = document.getElementById('cnpjStatus');
  const cnpj = document.getElementById('fCnpj').value.replace(/\D/g, '');

  if (cnpj.length !== 14) {
    status.textContent = 'Digite os 14 dígitos do CNPJ.';
    status.style.color = 'var(--vermelho)';
    return;
  }
  status.textContent = 'Consultando...';
  status.style.color = 'var(--texto-2)';

  fetch('cnpj.php?cnpj=' + cnpj)
    .then(r => r.json())
    .then(j => {
      if (!j.ok) {
        status.textContent = j.erro || 'Não encontrado.';
        status.style.color = 'var(--vermelho)';
        return;
      }
      const d = j.dados;
      const por = (id, val) => {
        const el = document.getElementById(id);
        if (el && val) el.value = val;
      };
      por('fRazao', d.razao_social);
      por('fFantasia', d.nome_fantasia);
      por('fMunicipio', d.municipio);
      por('fUf', d.uf);
      status.textContent = d.situacao ? ('Receita: ' + d.situacao) : 'Dados atualizados.';
      status.style.color = (d.situacao && d.situacao.toUpperCase() !== 'ATIVA')
                           ? 'var(--ambar)' : 'var(--verde)';
    })
    .catch(() => {
      status.textContent = 'Falha na consulta.';
      status.style.color = 'var(--vermelho)';
    });
}
</script>
<?php fecha_pagina();
