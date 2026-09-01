<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  REVENDEDOR - ficha completa
 * =====================================================================
 *  Espelha a ficha do cliente: cadastro editavel, contatos multiplos,
 *  licencas com filtro e dossiê, e os clientes que ele atende.
 * ===================================================================== */

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: revendedores.php'); exit; }

$str = db()->prepare("SELECT * FROM usuarios WHERE id=? AND papel='revendedor'");
$str->execute([$id]);
$rev = $str->fetch();
if (!$rev) { header('Location: revendedores.php'); exit; }

$msg=''; $tipo='';

// ---- acoes -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_valido()) {
    $ac = $_POST['acao'] ?? '';

    if ($ac === 'editar') {
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') { $msg='O responsável não pode ficar vazio.'; $tipo='erro'; }
        else {
            db()->prepare(
              'UPDATE usuarios
                  SET nome=?, empresa=?, nome_fantasia=?, cnpj=?, telefone=?,
                      municipio=?, uf=?, observacao=?, limite_estoque=?,
                      desconto_revenda=?
                WHERE id=? AND papel="revendedor"')
              ->execute([$nome,
                (trim($_POST['empresa'] ?? '') ?: null),
                (trim($_POST['nome_fantasia'] ?? '') ?: null),
                (trim($_POST['cnpj'] ?? '') ?: null),
                (trim($_POST['telefone'] ?? '') ?: null),
                (trim($_POST['municipio'] ?? '') ?: null),
                (strtoupper(substr(trim($_POST['uf'] ?? ''),0,2)) ?: null),
                (trim($_POST['observacao'] ?? '') ?: null),
                ((int)($_POST['limite_estoque'] ?? 0) ?: null),
                // percentual: aceita "12,5" do formulario brasileiro
                max(0, min(99.99, (float)str_replace(',', '.',
                    trim($_POST['desconto_revenda'] ?? '0')))),
                $id]);
            $str->execute([$id]); $rev = $str->fetch();
            $msg='Cadastro atualizado.'; $tipo='ok';
        }
    }
    elseif ($ac === 'contato_novo') {
        $nome = trim($_POST['c_nome'] ?? '');
        if ($nome === '') { $msg='Informe o nome do contato.'; $tipo='erro'; }
        else {
            db()->prepare(
              'INSERT INTO revendedor_contatos
                 (revendedor_id,nome,cargo,telefone,email,observacao)
               VALUES (?,?,?,?,?,?)')
              ->execute([$id, $nome,
                (trim($_POST['c_cargo'] ?? '') ?: null),
                (trim($_POST['c_telefone'] ?? '') ?: null),
                (trim($_POST['c_email'] ?? '') ?: null),
                (trim($_POST['c_obs'] ?? '') ?: null)]);
            $msg='Contato adicionado.'; $tipo='ok';
        }
    }
    elseif ($ac === 'contato_editar') {
        $nome = trim($_POST['c_nome'] ?? '');
        if ($nome !== '') {
            db()->prepare(
              'UPDATE revendedor_contatos
                  SET nome=?, cargo=?, telefone=?, email=?, observacao=?
                WHERE id=? AND revendedor_id=?')
              ->execute([$nome,
                (trim($_POST['c_cargo'] ?? '') ?: null),
                (trim($_POST['c_telefone'] ?? '') ?: null),
                (trim($_POST['c_email'] ?? '') ?: null),
                (trim($_POST['c_obs'] ?? '') ?: null),
                (int)$_POST['c_id'], $id]);
            $msg='Contato atualizado.'; $tipo='ok';
        }
    }
    elseif ($ac === 'contato_remove') {
        db()->prepare('DELETE FROM revendedor_contatos WHERE id=? AND revendedor_id=?')
            ->execute([(int)$_POST['c_id'], $id]);
        $msg='Contato removido.'; $tipo='ok';
    }
    elseif ($ac === 'contato_principal') {
        db()->prepare('UPDATE revendedor_contatos SET principal=0 WHERE revendedor_id=?')
            ->execute([$id]);
        db()->prepare('UPDATE revendedor_contatos SET principal=1 WHERE id=? AND revendedor_id=?')
            ->execute([(int)$_POST['c_id'], $id]);
        $msg='Contato principal atualizado.'; $tipo='ok';
    }
    elseif ($ac === 'senha') {
        $nova = $_POST['nova_senha'] ?? '';
        if (strlen($nova) < 6) { $msg='Senha muito curta (mínimo 6).'; $tipo='erro'; }
        else {
            db()->prepare('UPDATE usuarios SET senha_hash=? WHERE id=? AND papel="revendedor"')
                ->execute([password_hash($nova, PASSWORD_DEFAULT), $id]);
            $msg='Senha redefinida.'; $tipo='ok';
        }
    }
    elseif ($ac === 'alternar_ativo') {
        db()->prepare('UPDATE usuarios SET ativo = 1 - ativo WHERE id=? AND papel="revendedor"')
            ->execute([$id]);
        $str->execute([$id]); $rev = $str->fetch();
        $msg='Situação alterada.'; $tipo='ok';
    }
}

// ---- contatos --------------------------------------------------------
$stc = db()->prepare(
  'SELECT * FROM revendedor_contatos WHERE revendedor_id=?
    ORDER BY principal DESC, nome');
$stc->execute([$id]);
$contatos = $stc->fetchAll();

// ---- filtros de licenca ---------------------------------------------
$fSit     = trim($_GET['sit'] ?? '');
$fProduto = trim($_GET['produto'] ?? '');
$fBusca   = trim($_GET['q'] ?? '');

$wLic = ['l.revendedor_id = ?']; $aLic = [$id];
switch ($fSit) {
    case 'livre':     $wLic[] = 'l.cliente_id IS NULL'; break;
    case 'vinculada': $wLic[] = 'l.cliente_id IS NOT NULL'; break;
    case 'vencendo':  $wLic[] = "l.status='ativa' AND l.expira_em BETWEEN CURDATE() "
                              . "AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; break;
    case 'vencidas':  $wLic[] = 'l.expira_em < CURDATE()'; break;
    case 'revogadas': $wLic[] = "l.status='revogada'"; break;
    case 'demo':      $wLic[] = "l.tipo_licenca='demo'"; break;
}
if ($fProduto !== '') { $wLic[] = 'p.codigo = ?'; $aLic[] = $fProduto; }
if ($fBusca !== '') {
    $wLic[] = '(l.chave LIKE ? OR c.razao_social LIKE ? '
            . 'OR c.nome_fantasia LIKE ? OR m.maq_nome LIKE ?)';
    for ($i=0;$i<4;$i++) $aLic[] = '%'.$fBusca.'%';
}

$stl = db()->prepare(
  'SELECT l.*, c.razao_social, c.nome_fantasia AS cli_fantasia,
          t.nome AS tier_nome, p.codigo AS produto_codigo,
          ue.nome AS emitida_por_nome, ur.nome AS revogada_por_nome,
          m.maq_nome, m.maq_usuario, m.maq_so, m.primeiro_acesso,
          m.ultimo_acesso, m.aberturas, m.ip_ultimo,
          DATEDIFF(l.expira_em, CURDATE()) AS dias_restantes,
          DATEDIFF(NOW(), m.ultimo_acesso) AS dias_sem_ver
     FROM licencas l
     LEFT JOIN clientes c  ON c.id = l.cliente_id
     LEFT JOIN tiers t     ON t.id = l.tier_id
     LEFT JOIN produtos p  ON p.id = l.produto_id
     LEFT JOIN usuarios ue ON ue.id = l.criado_por
     LEFT JOIN usuarios ur ON ur.id = l.revogada_por
     LEFT JOIN maquinas m  ON m.fingerprint = l.fingerprint
    WHERE '.implode(' AND ', $wLic).'
    ORDER BY l.cliente_id IS NOT NULL, l.expira_em');
$stl->execute($aLic);
$licencas = $stl->fetchAll();

$stp = db()->prepare(
  'SELECT DISTINCT p.codigo FROM licencas l
     JOIN produtos p ON p.id=l.produto_id
    WHERE l.revendedor_id=? ORDER BY p.codigo');
$stp->execute([$id]);
$produtosRev = $stp->fetchAll(PDO::FETCH_COLUMN);

// ---- resumo ----------------------------------------------------------
$res = db()->prepare(
  "SELECT COUNT(*) AS total,
          SUM(cliente_id IS NULL)  AS estoque,
          SUM(status='ativa')      AS ativas,
          SUM(tipo_licenca='demo') AS demos,
          SUM(status='ativa' AND expira_em BETWEEN CURDATE()
              AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS vencendo,
          COALESCE(SUM(transferencias),0) AS transf
     FROM licencas WHERE revendedor_id=?");
$res->execute([$id]);
$res = $res->fetch();

$stCli = db()->prepare(
  'SELECT c.id, c.razao_social, c.nome_fantasia, c.municipio, c.uf,
          (SELECT COUNT(*) FROM licencas l WHERE l.cliente_id=c.id) AS n_lic
     FROM clientes c WHERE c.revendedor_id=? ORDER BY c.razao_social');
$stCli->execute([$id]);
$clientesRev = $stCli->fetchAll();

$ROTULO_MOTIVO = [
    'inadimplencia'=>'Inadimplência', 'cancelamento'=>'Cancelamento pelo cliente',
    'troca_licenca'=>'Substituída por outra', 'uso_indevido'=>'Uso indevido',
    'erro_emissao'=>'Erro na emissão', 'outro'=>'Outro',
];

function urlAtual() {
    $b = [];
    foreach (['id','sit','produto','q'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $b[$k] = $_GET[$k];
    }
    return 'revendedor.php?'.http_build_query($b);
}

abre_pagina('Revendedor', 'revendedores');
?>
<p class="subtitulo" style="margin-bottom:4px">
  <a href="revendedores.php">‹ Revendedores</a></p>
<h1 class="titulo">
  <?= e($rev['nome_fantasia'] ?: ($rev['empresa'] ?: $rev['nome'])) ?>
  <?php if (!$rev['ativo']): ?>
    <span class="badge revogada" style="font-size:12px">inativo</span>
  <?php endif; ?>
</h1>
<p class="subtitulo">
  <?php if ($rev['empresa'] && $rev['nome_fantasia']): ?>
    <?= e($rev['empresa']) ?> · <?php endif; ?>
  <?= e($rev['cnpj'] ?: 'sem CNPJ') ?>
  <?php if ($rev['municipio']): ?>
    · <?= e($rev['municipio']) ?><?= $rev['uf'] ? '/'.e($rev['uf']) : '' ?>
  <?php endif; ?>
</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="stats">
  <div class="stat"><div class="n"><?= (int)$res['total'] ?></div><div class="l">Licenças</div></div>
  <div class="stat"><div class="n"><?= (int)$res['estoque'] ?></div><div class="l">Em estoque</div></div>
  <div class="stat"><div class="n" style="color:var(--verde)"><?= (int)$res['ativas'] ?></div><div class="l">Ativas</div></div>
  <div class="stat"><div class="n" style="color:var(--ambar)"><?= (int)$res['vencendo'] ?></div><div class="l">Vencem em 30d</div></div>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h3 style="margin:0">Cadastro</h3>
    <button type="button" class="btn sec pequeno" onclick="alternar('boxEditar')">
      Editar cadastro</button>
  </div>
  <div id="boxEditar" style="display:none;margin-top:16px">
    <form method="post" action="<?= e(urlAtual()) ?>">
      <input type="hidden" name="acao" value="editar">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div><label>Razão social</label>
          <input name="empresa" value="<?= e($rev['empresa'] ?? '') ?>"></div>
        <div><label>Nome fantasia</label>
          <input name="nome_fantasia" value="<?= e($rev['nome_fantasia'] ?? '') ?>"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 2fr 1fr;gap:16px;margin-top:12px">
        <div><label>CNPJ</label><input name="cnpj" value="<?= e($rev['cnpj'] ?? '') ?>"></div>
        <div><label>Telefone</label><input name="telefone" value="<?= e($rev['telefone'] ?? '') ?>"></div>
        <div><label>Município</label><input name="municipio" value="<?= e($rev['municipio'] ?? '') ?>"></div>
        <div><label>UF</label><input name="uf" maxlength="2" value="<?= e($rev['uf'] ?? '') ?>"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 2fr;gap:16px;margin-top:12px">
        <div><label>Responsável *</label>
          <input name="nome" required value="<?= e($rev['nome']) ?>"></div>
        <div><label>Desconto de revenda (%)</label>
        <input name="desconto_revenda" inputmode="decimal"
               value="<?= rtrim(rtrim(number_format(
                   (float)($rev['desconto_revenda'] ?? 0),2,',','.'),'0'),',') ?>">
        <span class="subtitulo" style="margin:4px 0 0;display:block;font-size:11px">
          abatido do preço de tabela ao emitir para ele</span></div>
      <div><label>Limite de estoque</label>
          <input name="limite_estoque" type="number" min="0"
                 value="<?= $rev['limite_estoque'] !== null ? (int)$rev['limite_estoque'] : '' ?>"></div>
        <div><label>Observação</label>
          <input name="observacao" value="<?= e($rev['observacao'] ?? '') ?>"></div>
      </div>
      <div style="margin-top:14px">
        <button class="btn">Salvar alterações</button>
        <button type="button" class="btn sec" style="margin-left:8px"
                onclick="alternar('boxEditar')">Cancelar</button>
      </div>
    </form>

    <div style="display:flex;gap:20px;margin-top:18px;
         border-top:1px solid var(--borda);padding-top:14px;flex-wrap:wrap">
      <form method="post" action="<?= e(urlAtual()) ?>"
            style="display:flex;gap:8px;align-items:flex-end">
        <input type="hidden" name="acao" value="senha">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <div><label>Redefinir senha</label>
          <input name="nova_senha" type="password" minlength="6"
                 placeholder="mínimo 6 caracteres"></div>
        <button class="btn sec pequeno">Redefinir</button>
      </form>
      <form method="post" action="<?= e(urlAtual()) ?>" style="align-self:flex-end"
            onsubmit="return confirm('<?= $rev['ativo']
                ? 'Desativar? Ele perde o acesso ao painel.' : 'Reativar o acesso?' ?>')">
        <input type="hidden" name="acao" value="alternar_ativo">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <button class="btn <?= $rev['ativo'] ? 'perigo' : '' ?> pequeno">
          <?= $rev['ativo'] ? 'Desativar acesso' : 'Reativar acesso' ?></button>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <h3>Contatos (<?= count($contatos) ?>)</h3>
  <table>
    <thead><tr><th>Nome</th><th>Cargo</th><th>Telefone</th>
      <th>E-mail</th><th>Observação</th><th></th></tr></thead>
    <tbody>
    <?php if (!$contatos): ?>
      <tr><td colspan="6" style="color:var(--texto-2)">Nenhum contato.</td></tr>
    <?php else: foreach ($contatos as $ct): ?>
      <tr id="cver<?= $ct['id'] ?>">
        <td><b><?= e($ct['nome']) ?></b>
          <?php if ($ct['principal']): ?>
            <span class="badge ativa" style="font-size:10px">principal</span>
          <?php endif; ?></td>
        <td style="font-size:12px"><?= e($ct['cargo'] ?: '—') ?></td>
        <td class="mono" style="font-size:12px"><?= e($ct['telefone'] ?: '—') ?></td>
        <td style="font-size:12px">
          <?php if ($ct['email']): ?>
            <a href="mailto:<?= e($ct['email']) ?>"><?= e($ct['email']) ?></a>
          <?php else: ?>—<?php endif; ?></td>
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
      <tr id="cedt<?= $ct['id'] ?>" style="display:none">
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
          onclick="alternar('boxContato')">+ Adicionar contato</button>

  <div id="boxContato" style="display:none;margin-top:16px;
       border-top:1px solid var(--borda);padding-top:16px">
    <form method="post" action="<?= e(urlAtual()) ?>">
      <input type="hidden" name="acao" value="contato_novo">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
        <div><label>Nome *</label><input name="c_nome" required></div>
        <div><label>Cargo / setor</label>
          <input name="c_cargo" placeholder="ex: Comercial, Suporte, Financeiro"></div>
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

<div class="card">
  <h3>Licenças (<?= count($licencas) ?>)</h3>
  <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div style="flex:1;min-width:200px">
      <label>Buscar por chave, cliente ou máquina</label>
      <input type="text" name="q" value="<?= e($fBusca) ?>">
    </div>
    <div>
      <label>Situação</label>
      <select name="sit">
        <option value="">— todas —</option>
        <option value="livre"     <?= $fSit==='livre'    ?'selected':'' ?>>Em estoque</option>
        <option value="vinculada" <?= $fSit==='vinculada'?'selected':'' ?>>Vinculadas</option>
        <option value="vencendo"  <?= $fSit==='vencendo' ?'selected':'' ?>>Vencendo em 30d</option>
        <option value="vencidas"  <?= $fSit==='vencidas' ?'selected':'' ?>>Vencidas</option>
        <option value="revogadas" <?= $fSit==='revogadas'?'selected':'' ?>>Revogadas</option>
        <option value="demo"      <?= $fSit==='demo'     ?'selected':'' ?>>Demonstração</option>
      </select>
    </div>
    <div>
      <label>Software</label>
      <select name="produto">
        <option value="">— todos —</option>
        <?php foreach ($produtosRev as $p): ?>
          <option value="<?= e($p) ?>" <?= $fProduto===$p?'selected':'' ?>>
            <?= e(strtoupper($p)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn">Filtrar</button>
    <a class="btn sec" href="revendedor.php?id=<?= $id ?>">Limpar</a>
  </form>

  <table style="margin-top:16px">
    <thead><tr>
      <th>Chave</th><th>Software/Tipo</th><th>Cliente</th>
      <th>Expira</th><th>Situação</th><th>Máquina</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$licencas): ?>
      <tr><td colspan="7" style="color:var(--texto-2)">
        Nenhuma licença para os filtros escolhidos.</td></tr>
    <?php else: foreach ($licencas as $l):
        $dr = (int)$l['dias_restantes'];
        $cor = $dr < 0 ? 'var(--vermelho)' : ($dr <= 30 ? 'var(--ambar)' : 'var(--texto-2)');
    ?>
      <tr>
        <td class="mono" style="font-size:11px">
          <a href="#" onclick="detalhe(<?= $l['id'] ?>);return false;"><?= e($l['chave']) ?></a>
          <?php if (($l['tipo_licenca'] ?? '')==='demo'): ?>
            <br><span class="badge nova" style="font-size:10px">demonstração</span>
          <?php endif; ?>
        </td>
        <td class="mono" style="font-size:11px">
          <?= e(strtoupper($l['produto_codigo'] ?? '—')) ?>
          <?= $l['tier_nome'] ? '· '.e($l['tier_nome']) : '' ?></td>
        <td style="font-size:12px">
          <?php if ($l['cliente_id']): ?>
            <a href="cliente.php?id=<?= (int)$l['cliente_id'] ?>">
              <?= e($l['cli_fantasia'] ?: $l['razao_social']) ?></a>
          <?php else: ?>
            <span style="color:var(--texto-2)">— estoque —</span>
          <?php endif; ?>
        </td>
        <td class="mono" style="font-size:11px">
          <?= date('d/m/Y', strtotime($l['expira_em'])) ?>
          <br><span style="font-size:10px;color:<?= $cor ?>">
            <?= $dr < 0 ? abs($dr).' dias atrás' : 'em '.$dr.' dias' ?></span>
        </td>
        <td><span class="badge <?= e($l['status']) ?>"><?= e($l['status']) ?></span></td>
        <td class="mono" style="font-size:11px">
          <?= $l['fingerprint']
              ? e($l['maq_nome'] ?: substr($l['fingerprint'],0,14).'…')
              : '<span style="color:var(--azul)">não ativada</span>' ?></td>
        <td><button type="button" class="btn sec pequeno"
                    onclick="detalhe(<?= $l['id'] ?>)">Detalhes</button></td>
      </tr>
      <tr id="det<?= $l['id'] ?>" style="display:none">
        <td colspan="7" style="background:var(--bg-3);padding:16px">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px">
            <div>
              <h4 style="margin:0 0 8px;font-size:12px;color:var(--ambar)">LICENÇA</h4>
              <table style="font-size:11px">
                <tr><td style="color:var(--texto-2)">Chave</td>
                    <td class="mono"><?= e($l['chave']) ?></td></tr>
                <tr><td style="color:var(--texto-2)">Tipo</td>
                    <td><?= ($l['tipo_licenca'] ?? '')==='demo'?'Demonstração':'Venda' ?></td></tr>
                <tr><td style="color:var(--texto-2)">Módulos</td>
                    <td class="mono"><?= e($l['modulos'] ?: '—') ?></td></tr>
                <tr><td style="color:var(--texto-2)">Emitida em</td>
                    <td><?= date('d/m/Y', strtotime($l['emitido_em'])) ?></td></tr>
                <tr><td style="color:var(--texto-2)">Emitida por</td>
                    <td><?= e($l['emitida_por_nome'] ?: '—') ?></td></tr>
                <tr><td style="color:var(--texto-2)">Carência</td>
                    <td><?= (int)($l['carencia_dias'] ?? 0) ?> dias</td></tr>
                <tr><td style="color:var(--texto-2)">Transferências</td>
                    <td><?= (int)$l['transferencias'] ?> de
                        <?= (int)($l['max_transferencias'] ?? 3) ?></td></tr>
              </table>
            </div>
            <div>
              <h4 style="margin:0 0 8px;font-size:12px;color:var(--ambar)">MÁQUINA</h4>
              <?php if (!$l['fingerprint']): ?>
                <p style="font-size:11px;color:var(--texto-2)">
                  Ainda não ativada.</p>
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
                  <tr><td style="color:var(--texto-2)">IP</td>
                      <td class="mono"><?= e($l['ip_ultimo'] ?: '—') ?></td></tr>
                </table>
              <?php endif; ?>
            </div>
            <div>
              <h4 style="margin:0 0 8px;font-size:12px;color:var(--ambar)">USO</h4>
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
                  <tr><td style="color:var(--texto-2)">Aberturas</td>
                      <td class="mono"><?= (int)$l['aberturas'] ?></td></tr>
                </table>
                <a class="btn sec pequeno" style="margin-top:10px"
                   href="maquina.php?fp=<?= urlencode($l['fingerprint']) ?>">
                  Ver uso detalhado</a>
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
                </table>
              <?php endif; ?>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Clientes atendidos (<?= count($clientesRev) ?>)</h3>
  <table>
    <thead><tr><th>Cliente</th><th>Local</th><th>Licenças</th></tr></thead>
    <tbody>
    <?php if (!$clientesRev): ?>
      <tr><td colspan="3" style="color:var(--texto-2)">
        Nenhum cliente cadastrado por este revendedor.</td></tr>
    <?php else: foreach ($clientesRev as $c): ?>
      <tr>
        <td><a href="cliente.php?id=<?= $c['id'] ?>">
          <b><?= e($c['nome_fantasia'] ?: $c['razao_social']) ?></b></a></td>
        <td style="font-size:11px;color:var(--texto-2)">
          <?= e($c['municipio'] ? $c['municipio'].($c['uf']?'/'.$c['uf']:'') : '—') ?></td>
        <td class="mono"><?= (int)$c['n_lic'] ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
function alternar(id) {
  const el = document.getElementById(id);
  el.style.display = (el.style.display === 'none') ? '' : 'none';
}
function detalhe(id) {
  const el = document.getElementById('det' + id);
  el.style.display = (el.style.display === 'none') ? '' : 'none';
}
function editarContato(id) {
  const ver = document.getElementById('cver' + id);
  const edt = document.getElementById('cedt' + id);
  const abrindo = edt.style.display === 'none';
  edt.style.display = abrindo ? '' : 'none';
  ver.style.display = abrindo ? 'none' : '';
}
</script>
<?php fecha_pagina();
