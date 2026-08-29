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

// filtro vindo dos botoes de alerta (leva direto ao subconjunto que
// motivou o aviso, em vez de obrigar a garimpar a tabela inteira)
$fAlerta = trim($_GET['alerta'] ?? '');
$ROTULO_ALERTA = [
    'vencendo'     => 'vencendo nos próximos 30 dias',
    'vencidas'     => 'já vencidas',
    'nao_ativadas' => 'emitidas e nunca ativadas',
    'revogadas'    => 'revogadas',
];

// ---- licencas (com filtro) ------------------------------------------
$wLic = ['l.cliente_id = ?']; $aLic = [$id];
if ($fStatus  !== '') { $wLic[] = 'l.status = ?';  $aLic[] = $fStatus; }
if ($fProduto !== '') { $wLic[] = 'p.codigo = ?';  $aLic[] = $fProduto; }

switch ($fAlerta) {
    case 'vencendo':
        $wLic[] = "l.status='ativa' AND l.expira_em BETWEEN CURDATE() "
                . "AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
        break;
    case 'vencidas':
        $wLic[] = "l.status='ativa' AND l.expira_em < CURDATE()";
        break;
    case 'nao_ativadas':
        $wLic[] = "l.fingerprint IS NULL AND l.status <> 'revogada'";
        break;
    case 'revogadas':
        $wLic[] = "l.status='revogada'";
        break;
}

// LEFT JOIN em maquinas pelo fingerprint: traz onde a licenca esta
// rodando de fato, sem precisar de uma segunda consulta por linha
$stl = db()->prepare(
  'SELECT l.*, t.nome AS tier_nome, t.codigo AS tier_codigo,
          p.codigo AS produto_codigo, ur.nome AS revogada_por_nome,
          ue.nome AS emitida_por_nome, urv.nome AS revendedor_nome,
          m.maq_nome, m.maq_usuario, m.maq_so, m.origem,
          m.primeiro_acesso, m.ultimo_acesso, m.aberturas, m.ip_ultimo,
          DATEDIFF(l.expira_em, CURDATE()) AS dias_restantes,
          DATEDIFF(NOW(), m.ultimo_acesso) AS dias_sem_ver
     FROM licencas l
     LEFT JOIN tiers t     ON t.id = l.tier_id
     LEFT JOIN produtos p  ON p.id = l.produto_id
     LEFT JOIN usuarios ur ON ur.id = l.revogada_por
     LEFT JOIN usuarios ue ON ue.id = l.criado_por
     LEFT JOIN usuarios urv ON urv.id = l.revendedor_id
     LEFT JOIN maquinas m  ON m.fingerprint = l.fingerprint
    WHERE '.implode(' AND ', $wLic).'
    ORDER BY l.expira_em');
$stl->execute($aLic);
$licencas = $stl->fetchAll();

// produtos que este cliente tem (para o select do filtro)
$stp = db()->prepare(
  'SELECT DISTINCT p.codigo FROM licencas l
     JOIN produtos p ON p.id=l.produto_id
    WHERE l.cliente_id=? ORDER BY p.codigo');
$stp->execute([$id]);
$produtosCli = $stp->fetchAll(PDO::FETCH_COLUMN);

// ---- o que o cliente possui (tiers distintos, so os validos) --------
// Agrupa por produto+tier: e a resposta para "o que esse cliente tem
// contratado", que a lista de chaves individuais nao da de imediato.
$stPos = db()->prepare(
  "SELECT p.codigo AS produto, t.nome AS tier, t.nivel,
          COUNT(*) AS qtd,
          SUM(l.status='ativa') AS ativas,
          MAX(l.expira_em) AS maior_validade
     FROM licencas l
     LEFT JOIN produtos p ON p.id = l.produto_id
     LEFT JOIN tiers    t ON t.id = l.tier_id
    WHERE l.cliente_id = ? AND l.status <> 'revogada'
    GROUP BY p.codigo, t.nome, t.nivel
    ORDER BY p.codigo, t.nivel DESC");
$stPos->execute([$id]);
$possui = $stPos->fetchAll();

// ---- relacionamento: desde quando, e quem atende ---------------------
$stRel = db()->prepare(
  "SELECT MIN(l.emitido_em) AS primeira_licenca,
          SUM(l.transferencias) AS transferencias,
          MAX(l.max_transferencias) AS max_transf
     FROM licencas l WHERE l.cliente_id = ?");
$stRel->execute([$id]);
$rel = $stRel->fetch();

$revNome = null;
if (!empty($cli['revendedor_id'])) {
    $stRv = db()->prepare('SELECT nome FROM usuarios WHERE id=?');
    $stRv->execute([$cli['revendedor_id']]);
    $revNome = $stRv->fetchColumn() ?: null;
}

// ---- historico de eventos deste cliente ------------------------------
$stEv = db()->prepare(
  "SELECT a.* FROM ativacoes_log a
     JOIN licencas l ON l.id = a.licenca_id
    WHERE l.cliente_id = ?
    ORDER BY a.id DESC LIMIT 25");
$stEv->execute([$id]);
$eventos = $stEv->fetchAll();

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

// ---- alertas: o que merece atencao neste cliente ---------------------
// A lista de clientes mostra quem existe; estes avisos mostram quem
// precisa de uma ligacao.
$alertas = [];

// 1) licenca vencendo
$stAl = db()->prepare(
  "SELECT COUNT(*) FROM licencas
    WHERE cliente_id=? AND status='ativa'
      AND expira_em BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$stAl->execute([$id]);
if ($n = (int)$stAl->fetchColumn()) {
    $alertas[] = ['ambar', "$n licença(s) vencem nos próximos 30 dias.", 'vencendo'];
}

// 2) licenca vencida ainda sem renovacao
$stAl = db()->prepare(
  "SELECT COUNT(*) FROM licencas
    WHERE cliente_id=? AND status='ativa' AND expira_em < CURDATE()");
$stAl->execute([$id]);
if ($n = (int)$stAl->fetchColumn()) {
    $alertas[] = ['vermelho', "$n licença(s) já venceram e não foram renovadas.", 'vencidas'];
}

// 3) sumico: parou de abrir o sistema
$stAl = db()->prepare(
  "SELECT DATEDIFF(NOW(), MAX(ultimo_acesso)) FROM maquinas WHERE cliente_id=?");
$stAl->execute([$id]);
$diasSumido = $stAl->fetchColumn();
if ($diasSumido !== null && $diasSumido !== false && (int)$diasSumido > 30) {
    $alertas[] = ['vermelho',
        'Sem abrir o sistema há '.(int)$diasSumido.' dias. '
       .'Pode ser desinstalação, troca de fornecedor ou máquina parada.', null];
}

// 4) licenca emitida que nunca foi ativada
$stAl = db()->prepare(
  "SELECT COUNT(*) FROM licencas
    WHERE cliente_id=? AND fingerprint IS NULL AND status <> 'revogada'");
$stAl->execute([$id]);
if ($n = (int)$stAl->fetchColumn()) {
    $alertas[] = ['azul', "$n licença(s) emitidas mas nunca ativadas.", 'nao_ativadas'];
}

// 5) transferencias perto do limite
if ((int)$rel['transferencias'] > 0) {
    $restam = (int)$rel['max_transf'] - (int)$rel['transferencias'];
    if ($restam <= 1) {
        $alertas[] = ['ambar',
            'Limite de transferências quase esgotado ('
           .(int)$rel['transferencias'].' de '.(int)$rel['max_transf'].' usadas).', null];
    }
}

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
    foreach (['id','status','produto','dias','alerta'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    return 'cliente.php?'.http_build_query($base);
}

function linkFiltro(array $novo) {
    // repassa apenas os filtros conhecidos - $_GET inteiro carregaria
    // qualquer parametro estranho colado na URL
    $base = [];
    foreach (['id','status','produto','dias','alerta'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    return 'cliente.php?'.http_build_query(array_merge($base, $novo));
}

$ROTULO_EVENTO = [
    'emitir'             => 'Licença emitida',
    'ativar'             => 'Ativada na máquina',
    'revalidar'          => 'Revalidação online',
    'revogar'            => 'Revogada',
    'vincular_cliente'   => 'Vinculada ao cliente',
    'liberar_maquina'    => 'Máquina liberada',
    'atribuir_revendedor'=> 'Atribuída a revendedor',
    'solicitar_troca'    => 'Troca solicitada',
    'aprovar_troca'      => 'Troca aprovada',
    'negar_troca'        => 'Troca negada',
];

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

<?php if ($possui): ?>
<div style="margin:-14px 0 20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
  <?php foreach ($possui as $pp):
      $rot = strtoupper($pp['produto'] ?? '?').' · '.($pp['tier'] ?: 'sem tipo');
      $cls = (int)$pp['ativas'] > 0 ? 'ativa' : 'expirada';
  ?>
    <span class="badge <?= $cls ?>" style="font-size:12px;padding:5px 10px">
      <?= e($rot) ?>
      <?php if ((int)$pp['qtd'] > 1): ?>
        <span style="opacity:.7">×<?= (int)$pp['qtd'] ?></span>
      <?php endif; ?>
    </span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" style="padding:12px 18px;margin-bottom:20px">
  <div style="display:flex;gap:28px;flex-wrap:wrap;font-size:12px">
    <div>
      <span style="color:var(--texto-2)">Cliente desde</span><br>
      <b><?= $rel['primeira_licenca']
              ? date('m/Y', strtotime($rel['primeira_licenca']))
              : '—' ?></b>
    </div>
    <div>
      <span style="color:var(--texto-2)">Atendido por</span><br>
      <b><?= e($revNome ?: 'Venda direta') ?></b>
    </div>
    <div>
      <span style="color:var(--texto-2)">Transferências</span><br>
      <b><?= (int)$rel['transferencias'] ?> de <?= (int)($rel['max_transf'] ?: 3) ?></b>
    </div>
    <div>
      <span style="color:var(--texto-2)">Último acesso</span><br>
      <b><?= $diasSumido === null || $diasSumido === false
              ? '—' : ((int)$diasSumido === 0 ? 'hoje' : (int)$diasSumido.' dias atrás') ?></b>
    </div>
  </div>
</div>

<?php foreach ($alertas as $al): ?>
  <div class="card" style="padding:10px 16px;margin-bottom:10px;
       border-left:3px solid var(--<?= $al[0] ?>);
       display:flex;justify-content:space-between;align-items:center;gap:16px">
    <span style="font-size:13px"><?= e($al[1]) ?></span>
    <?php if (!empty($al[2])): ?>
      <a class="btn sec pequeno" style="white-space:nowrap"
         href="<?= e(linkFiltro(['alerta'=>$al[2]])) ?>#licencas">
        Ver licenças
      </a>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

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
      <div style="margin-top:12px">
        <button class="btn">Adicionar contato</button>
        <button type="button" class="btn sec" style="margin-left:8px"
                onclick="alternar('boxContato')">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<div class="stats">
  <div class="stat"><div class="n"><?= (int)$resumo['total'] ?></div><div class="l">Licenças</div></div>
  <div class="stat"><div class="n" style="color:var(--verde)"><?= (int)$resumo['ativas'] ?></div><div class="l">Ativas</div></div>
  <div class="stat"><div class="n"><?= count($maquinas) ?></div><div class="l">Máquinas</div></div>
  <div class="stat"><div class="n"><?= $totalAberturas ?></div><div class="l">Aberturas (<?= $fDias ?>d)</div></div>
</div>

<div class="card" id="licencas">
  <h3>Licenças</h3>
  <?php if ($fAlerta && isset($ROTULO_ALERTA[$fAlerta])): ?>
    <p class="subtitulo" style="margin-top:-6px">
      Mostrando apenas as licenças <b><?= e($ROTULO_ALERTA[$fAlerta]) ?></b> ·
      <a href="<?= e(linkFiltro(['alerta'=>''])) ?>">ver todas</a>
    </p>
  <?php endif; ?>
  <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="dias" value="<?= $fDias ?>">
    <input type="hidden" name="alerta" value="<?= e($fAlerta) ?>">
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
      <th>Expira</th><th>Situação</th><th>Máquina</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$licencas): ?>
      <tr><td colspan="7" style="color:var(--texto-2)">
        Nenhuma licença para os filtros escolhidos.
      </td></tr>
    <?php else: foreach ($licencas as $l):
        $prodTier = $l['produto_codigo']
            ? strtoupper($l['produto_codigo']).($l['tier_nome']?' · '.$l['tier_nome']:'')
            : '—';
        $dias = (int)$l['dias_restantes'];
    ?>
      <tr>
        <td class="mono" style="font-size:11px">
          <a href="#" onclick="detalhe(<?= $l['id'] ?>);return false;"
             title="Ver todos os detalhes"><?= e($l['chave']) ?></a>
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
          <?php if ($l['fingerprint']): ?>
            <?= e($l['maq_nome'] ?: substr($l['fingerprint'],0,14).'…') ?>
          <?php else: ?>
            <span style="color:var(--azul)">não ativada</span>
          <?php endif; ?>
        </td>
        <td>
          <button type="button" class="btn sec pequeno"
                  onclick="detalhe(<?= $l['id'] ?>)">Detalhes</button>
        </td>
      </tr>
      <tr id="det<?= $l['id'] ?>" style="display:none">
        <td colspan="7" style="background:var(--bg-3);padding:16px">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px">

            <div>
              <h4 style="margin:0 0 8px;font-size:12px;color:var(--ambar)">
                LICENÇA</h4>
              <table style="font-size:11px">
                <tr><td style="color:var(--texto-2)">Chave</td>
                    <td class="mono"><?= e($l['chave']) ?></td></tr>
                <tr><td style="color:var(--texto-2)">Software</td>
                    <td><?= e(strtoupper($l['produto_codigo'] ?? '—')) ?>
                        <?= $l['tier_nome'] ? '· '.e($l['tier_nome']) : '' ?></td></tr>
                <tr><td style="color:var(--texto-2)">Tipo</td>
                    <td><?= ($l['tipo_licenca'] ?? '')==='demo'
                            ? 'Demonstração' : 'Venda' ?></td></tr>
                <tr><td style="color:var(--texto-2)">Módulos</td>
                    <td class="mono"><?= e($l['modulos'] ?: '—') ?></td></tr>
                <tr><td style="color:var(--texto-2)">Emitida em</td>
                    <td><?= date('d/m/Y', strtotime($l['emitido_em'])) ?></td></tr>
                <tr><td style="color:var(--texto-2)">Emitida por</td>
                    <td><?= e($l['emitida_por_nome'] ?: '—') ?></td></tr>
                <tr><td style="color:var(--texto-2)">Revendedor</td>
                    <td><?= e($l['revendedor_nome'] ?: 'venda direta') ?></td></tr>
                <tr><td style="color:var(--texto-2)">Expira em</td>
                    <td><?= date('d/m/Y', strtotime($l['expira_em'])) ?>
                        <?php $dr=(int)$l['dias_restantes']; ?>
                        <span style="color:<?= $dr<0?'var(--vermelho)':($dr<=30?'var(--ambar)':'var(--texto-2)') ?>">
                          (<?= $dr < 0 ? abs($dr).' dias atrás' : $dr.' dias' ?>)
                        </span></td></tr>
                <tr><td style="color:var(--texto-2)">Carência</td>
                    <td><?= (int)($l['carencia_dias'] ?? 0) ?> dias</td></tr>
                <tr><td style="color:var(--texto-2)">Transferências</td>
                    <td><?= (int)$l['transferencias'] ?> de
                        <?= (int)($l['max_transferencias'] ?? 3) ?></td></tr>
              </table>
            </div>

            <div>
              <h4 style="margin:0 0 8px;font-size:12px;color:var(--ambar)">
                MÁQUINA</h4>
              <?php if (!$l['fingerprint']): ?>
                <p style="font-size:11px;color:var(--texto-2)">
                  Ainda não ativada. A chave foi entregue mas o software
                  nunca foi aberto com ela.
                </p>
              <?php else: ?>
                <table style="font-size:11px">
                  <tr><td style="color:var(--texto-2)">Código</td>
                      <td class="mono"><?= e($l['fingerprint']) ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Nome do PC</td>
                      <td><?= e($l['maq_nome'] ?: '—') ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Usuário</td>
                      <td><?= e($l['maq_usuario'] ?: '—') ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Sistema</td>
                      <td><?= e($l['maq_so'] ?: '—') ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Origem</td>
                      <td><?= e($l['origem'] ?: '—') ?></td></tr>
                  <tr><td style="color:var(--texto-2)">IP</td>
                      <td class="mono"><?= e($l['ip_ultimo'] ?: '—') ?></td></tr>
                </table>
              <?php endif; ?>
            </div>

            <div>
              <h4 style="margin:0 0 8px;font-size:12px;color:var(--ambar)">
                USO</h4>
              <?php if (!$l['fingerprint']): ?>
                <p style="font-size:11px;color:var(--texto-2)">Sem uso registrado.</p>
              <?php else: ?>
                <table style="font-size:11px">
                  <tr><td style="color:var(--texto-2)">Primeiro acesso</td>
                      <td><?= $l['primeiro_acesso']
                              ? date('d/m/Y H:i', strtotime($l['primeiro_acesso'])) : '—' ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Último acesso</td>
                      <td><?= $l['ultimo_acesso']
                              ? date('d/m/Y H:i', strtotime($l['ultimo_acesso'])) : '—' ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Há quanto tempo</td>
                      <td><?php
                          $ds = $l['dias_sem_ver'];
                          if ($ds === null) {
                              echo '—';
                          } elseif ((int)$ds === 0) {
                              echo 'hoje';
                          } else {
                              $cor = (int)$ds > 30 ? 'var(--vermelho)' : 'inherit';
                              echo '<span style="color:'.$cor.'">'.(int)$ds.' dias</span>';
                          }
                      ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Aberturas</td>
                      <td class="mono"><?= (int)$l['aberturas'] ?></td></tr>
                </table>
                <a class="btn sec pequeno" style="margin-top:10px"
                   href="maquina.php?fp=<?= urlencode($l['fingerprint']) ?>">
                  Ver uso detalhado
                </a>
              <?php endif; ?>

              <?php if ($l['status']==='revogada'): ?>
                <h4 style="margin:16px 0 8px;font-size:12px;color:var(--vermelho)">
                  REVOGAÇÃO</h4>
                <table style="font-size:11px">
                  <tr><td style="color:var(--texto-2)">Motivo</td>
                      <td><?= e($ROTULO_MOTIVO[$l['motivo_revogacao']] ?? 'não informado') ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Quando</td>
                      <td><?= $l['revogada_em']
                              ? date('d/m/Y', strtotime($l['revogada_em'])) : '—' ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Por</td>
                      <td><?= e($l['revogada_por_nome'] ?: '—') ?></td></tr>
                </table>
                <?php if (!empty($l['obs_revogacao'])): ?>
                  <p style="font-size:11px;font-style:italic;margin-top:6px">
                    "<?= e($l['obs_revogacao']) ?>"</p>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
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

<div class="card">
  <h3>Histórico deste cliente</h3>
  <p class="subtitulo" style="margin-top:-6px">
    Últimos 25 eventos: emissões, ativações, revogações e transferências
  </p>
  <table>
    <thead><tr>
      <th>Quando</th><th>Evento</th><th>Chave</th>
      <th>Quem</th><th>Detalhe</th><th>Resultado</th>
    </tr></thead>
    <tbody>
    <?php if (!$eventos): ?>
      <tr><td colspan="6" style="color:var(--texto-2)">Nenhum evento registrado.</td></tr>
    <?php else: foreach ($eventos as $ev):
        $cor = $ev['resultado']==='ok' ? 'ativa'
             : ($ev['resultado']==='negado' ? 'revogada' : 'expirada');
    ?>
      <tr>
        <td class="mono" style="font-size:11px">
          <?= date('d/m/Y H:i', strtotime($ev['criado_em'])) ?></td>
        <td style="font-size:12px"><?= e($ROTULO_EVENTO[$ev['acao']] ?? $ev['acao']) ?></td>
        <td class="mono" style="font-size:10px"><?= e($ev['chave'] ?: '—') ?></td>
        <td style="font-size:11px"><?= e($ev['usuario_nome'] ?: 'sistema') ?></td>
        <td style="font-size:11px;color:var(--texto-2)"><?= e($ev['detalhe'] ?: '') ?></td>
        <td><span class="badge <?= $cor ?>" style="font-size:10px">
          <?= e($ev['resultado']) ?></span></td>
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
function detalhe(id) {
  const el = document.getElementById('det' + id);
  el.style.display = (el.style.display === 'none') ? '' : 'none';
}

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
