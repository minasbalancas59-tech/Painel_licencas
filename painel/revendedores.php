<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  REVENDEDORES
 * =====================================================================
 *  Antes, revendedor era so um login em Usuarios. Aqui ele e tratado
 *  como o que e: uma empresa parceira, com CNPJ, contato e um estoque
 *  de licencas sob responsabilidade.
 *
 *  Usuarios continua existindo para logins administrativos internos.
 * ===================================================================== */

$msg=''; $tipo=''; $abrirForm=false; $old=[];

// ---- novo revendedor -------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='novo') {
    if (!csrf_valido()) { $msg='Sessão inválida.'; $tipo='erro'; }
    else {
        $nome  = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if ($nome==='' || $email==='' || strlen($senha) < 6) {
            $msg='Preencha nome, e-mail e senha (mínimo 6 caracteres).';
            $tipo='erro'; $abrirForm=true; $old=$_POST;
        } else {
            try {
                db()->prepare(
                  'INSERT INTO usuarios
                     (nome,empresa,cnpj,telefone,municipio,uf,observacao,
                      limite_estoque,email,senha_hash,papel)
                   VALUES (?,?,?,?,?,?,?,?,?,?,"revendedor")')
                  ->execute([
                    $nome,
                    (trim($_POST['empresa'] ?? '') ?: null),
                    (trim($_POST['cnpj'] ?? '') ?: null),
                    (trim($_POST['telefone'] ?? '') ?: null),
                    (trim($_POST['municipio'] ?? '') ?: null),
                    (strtoupper(substr(trim($_POST['uf'] ?? ''),0,2)) ?: null),
                    (trim($_POST['observacao'] ?? '') ?: null),
                    ((int)($_POST['limite_estoque'] ?? 0) ?: null),
                    $email,
                    password_hash($senha, PASSWORD_DEFAULT),
                  ]);
                $msg='Revendedor cadastrado.'; $tipo='ok';
            } catch (Throwable $e) {
                $msg='Não foi possível cadastrar (e-mail já existe?).';
                $tipo='erro'; $abrirForm=true; $old=$_POST;
            }
        }
    }
}

// ---- editar ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='editar') {
    if (csrf_valido()) {
        $rid = (int)$_POST['id'];
        db()->prepare(
          'UPDATE usuarios
              SET nome=?, empresa=?, cnpj=?, telefone=?, municipio=?, uf=?,
                  observacao=?, limite_estoque=?
            WHERE id=? AND papel="revendedor"')
          ->execute([
            trim($_POST['nome'] ?? ''),
            (trim($_POST['empresa'] ?? '') ?: null),
            (trim($_POST['cnpj'] ?? '') ?: null),
            (trim($_POST['telefone'] ?? '') ?: null),
            (trim($_POST['municipio'] ?? '') ?: null),
            (strtoupper(substr(trim($_POST['uf'] ?? ''),0,2)) ?: null),
            (trim($_POST['observacao'] ?? '') ?: null),
            ((int)($_POST['limite_estoque'] ?? 0) ?: null),
            $rid,
          ]);
        $msg='Revendedor atualizado.'; $tipo='ok';
    }
}

// ---- ativar / desativar ---------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='alternar_ativo') {
    if (csrf_valido()) {
        db()->prepare(
          'UPDATE usuarios SET ativo = 1 - ativo
            WHERE id=? AND papel="revendedor"')->execute([(int)$_POST['id']]);
        $msg='Situação do revendedor alterada.'; $tipo='ok';
    }
}

// ---- nova senha ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='senha') {
    if (csrf_valido()) {
        $nova = $_POST['nova_senha'] ?? '';
        if (strlen($nova) < 6) { $msg='Senha muito curta (mínimo 6).'; $tipo='erro'; }
        else {
            db()->prepare(
              'UPDATE usuarios SET senha_hash=? WHERE id=? AND papel="revendedor"')
              ->execute([password_hash($nova, PASSWORD_DEFAULT), (int)$_POST['id']]);
            $msg='Senha redefinida.'; $tipo='ok';
        }
    }
}

// ---- listagem com desempenho ----------------------------------------
$revs = db()->query(
  "SELECT u.*,
          (SELECT COUNT(*) FROM licencas l WHERE l.revendedor_id=u.id) AS total,
          (SELECT COUNT(*) FROM licencas l
            WHERE l.revendedor_id=u.id AND l.cliente_id IS NULL) AS estoque,
          (SELECT COUNT(*) FROM licencas l
            WHERE l.revendedor_id=u.id AND l.cliente_id IS NOT NULL) AS vinculadas,
          (SELECT COUNT(*) FROM licencas l
            WHERE l.revendedor_id=u.id AND l.status='ativa') AS ativas,
          (SELECT COUNT(*) FROM licencas l
            WHERE l.revendedor_id=u.id AND l.tipo_licenca='demo') AS demos,
          (SELECT COALESCE(SUM(l.transferencias),0) FROM licencas l
            WHERE l.revendedor_id=u.id) AS transf,
          (SELECT COUNT(*) FROM clientes c WHERE c.revendedor_id=u.id) AS clientes
     FROM usuarios u
    WHERE u.papel='revendedor'
    ORDER BY u.ativo DESC, COALESCE(u.empresa,u.nome)")->fetchAll();

function v($old,$c){ return e($old[$c] ?? ''); }

abre_pagina('Revendedores', 'revendedores');
?>
<h1 class="titulo">Revendedores</h1>
<p class="subtitulo">Parceiros que vendem e atendem clientes finais em seu nome</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
  <button type="button" class="btn" onclick="alternar('boxNovo')">
    + Novo revendedor
  </button>

  <div id="boxNovo" style="<?= $abrirForm ? '' : 'display:none' ?>;margin-top:16px">
    <form method="post">
      <input type="hidden" name="acao" value="novo">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

      <h3 style="font-size:13px;margin:0 0 10px">Empresa</h3>
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
        <div><label>Razão social / nome da empresa</label>
          <input name="empresa" value="<?= v($old,'empresa') ?>"></div>
        <div><label>CNPJ</label><input name="cnpj" value="<?= v($old,'cnpj') ?>"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:16px;margin-top:12px">
        <div><label>Telefone</label>
          <input name="telefone" value="<?= v($old,'telefone') ?>"></div>
        <div><label>Município</label>
          <input name="municipio" value="<?= v($old,'municipio') ?>"></div>
        <div><label>UF</label>
          <input name="uf" maxlength="2" value="<?= v($old,'uf') ?>"></div>
      </div>

      <h3 style="font-size:13px;margin:18px 0 10px">Acesso ao painel</h3>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
        <div><label>Nome do responsável *</label>
          <input name="nome" required value="<?= v($old,'nome') ?>"></div>
        <div><label>E-mail (login) *</label>
          <input name="email" type="email" required value="<?= v($old,'email') ?>"></div>
        <div><label>Senha inicial *</label>
          <input name="senha" type="password" required minlength="6"></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 3fr;gap:16px;margin-top:12px">
        <div><label>Limite de estoque</label>
          <input name="limite_estoque" type="number" min="0"
                 placeholder="vazio = sem limite"
                 value="<?= v($old,'limite_estoque') ?>"></div>
        <div><label>Observação</label>
          <input name="observacao" value="<?= v($old,'observacao') ?>"></div>
      </div>

      <div style="margin-top:14px">
        <button class="btn">Cadastrar revendedor</button>
        <button type="button" class="btn sec" style="margin-left:8px"
                onclick="alternar('boxNovo')">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <h3>Cadastrados (<?= count($revs) ?>)</h3>
  <table>
    <thead><tr>
      <th>Revendedor</th><th>Contato</th><th>Local</th>
      <th>Clientes</th><th>Estoque</th><th>Vinculadas</th>
      <th>Ativas</th><th>Transf.</th><th>Situação</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$revs): ?>
      <tr><td colspan="10" style="color:var(--texto-2)">
        Nenhum revendedor cadastrado.
      </td></tr>
    <?php else: foreach ($revs as $r):
        $estouro = $r['limite_estoque'] !== null
                && (int)$r['estoque'] > (int)$r['limite_estoque'];
    ?>
      <tr id="rv<?= $r['id'] ?>">
        <td>
          <b><?= e($r['empresa'] ?: $r['nome']) ?></b>
          <?php if ($r['empresa']): ?>
            <br><span style="font-size:10px;color:var(--texto-2)">
              <?= e($r['nome']) ?></span>
          <?php endif; ?>
          <?php if ($r['cnpj']): ?>
            <br><span class="mono" style="font-size:10px;color:var(--texto-2)">
              <?= e($r['cnpj']) ?></span>
          <?php endif; ?>
        </td>
        <td style="font-size:11px">
          <?= e($r['email']) ?>
          <?php if ($r['telefone']): ?><br><?= e($r['telefone']) ?><?php endif; ?>
        </td>
        <td style="font-size:11px;color:var(--texto-2)">
          <?= e($r['municipio'] ? $r['municipio'].($r['uf']?'/'.$r['uf']:'') : '—') ?>
        </td>
        <td class="mono"><?= (int)$r['clientes'] ?></td>
        <td class="mono" style="<?= $estouro ? 'color:var(--vermelho)' : '' ?>">
          <?= (int)$r['estoque'] ?>
          <?php if ($r['limite_estoque'] !== null): ?>
            <span style="font-size:10px;color:var(--texto-2)">
              /<?= (int)$r['limite_estoque'] ?></span>
          <?php endif; ?>
        </td>
        <td class="mono"><?= (int)$r['vinculadas'] ?></td>
        <td class="mono" style="color:var(--verde)"><?= (int)$r['ativas'] ?></td>
        <td class="mono"><?= (int)$r['transf'] ?></td>
        <td>
          <span class="badge <?= $r['ativo'] ? 'ativa' : 'revogada' ?>">
            <?= $r['ativo'] ? 'ativo' : 'inativo' ?></span>
        </td>
        <td style="white-space:nowrap">
          <button type="button" class="btn sec pequeno"
                  onclick="editarRev(<?= $r['id'] ?>)">Editar</button>
        </td>
      </tr>

      <tr id="ed<?= $r['id'] ?>" style="display:none">
        <td colspan="10" style="background:var(--bg-3);padding:16px">
          <form method="post">
            <input type="hidden" name="acao" value="editar">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px">
              <div><label>Empresa</label>
                <input name="empresa" value="<?= e($r['empresa'] ?? '') ?>"></div>
              <div><label>CNPJ</label>
                <input name="cnpj" value="<?= e($r['cnpj'] ?? '') ?>"></div>
              <div><label>Telefone</label>
                <input name="telefone" value="<?= e($r['telefone'] ?? '') ?>"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 2fr 1fr 1fr;gap:12px;margin-top:10px">
              <div><label>Responsável</label>
                <input name="nome" value="<?= e($r['nome']) ?>"></div>
              <div><label>Município</label>
                <input name="municipio" value="<?= e($r['municipio'] ?? '') ?>"></div>
              <div><label>UF</label>
                <input name="uf" maxlength="2" value="<?= e($r['uf'] ?? '') ?>"></div>
              <div><label>Limite estoque</label>
                <input name="limite_estoque" type="number" min="0"
                       value="<?= $r['limite_estoque'] !== null
                                  ? (int)$r['limite_estoque'] : '' ?>"></div>
            </div>
            <label style="margin-top:10px">Observação</label>
            <input name="observacao" value="<?= e($r['observacao'] ?? '') ?>">
            <div style="margin-top:12px">
              <button class="btn pequeno">Salvar</button>
              <button type="button" class="btn sec pequeno" style="margin-left:6px"
                      onclick="editarRev(<?= $r['id'] ?>)">Cancelar</button>
            </div>
          </form>

          <div style="display:flex;gap:20px;margin-top:16px;
               border-top:1px solid var(--borda);padding-top:14px;flex-wrap:wrap">
            <form method="post" style="display:flex;gap:8px;align-items:flex-end">
              <input type="hidden" name="acao" value="senha">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <div><label>Redefinir senha</label>
                <input name="nova_senha" type="password" minlength="6"
                       placeholder="mínimo 6 caracteres"></div>
              <button class="btn sec pequeno">Redefinir</button>
            </form>

            <form method="post" style="align-self:flex-end"
                  onsubmit="return confirm('<?= $r['ativo']
                      ? 'Desativar este revendedor? Ele perde o acesso ao painel.'
                      : 'Reativar este revendedor?' ?>')">
              <input type="hidden" name="acao" value="alternar_ativo">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn <?= $r['ativo'] ? 'perigo' : '' ?> pequeno">
                <?= $r['ativo'] ? 'Desativar acesso' : 'Reativar acesso' ?>
              </button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <p class="subtitulo" style="margin:14px 0 0">
    Desativar não apaga nada: as licenças e clientes continuam, apenas o
    acesso ao painel é bloqueado.
  </p>
</div>

<script>
function alternar(id) {
  const el = document.getElementById(id);
  el.style.display = (el.style.display === 'none') ? '' : 'none';
}
function editarRev(id) {
  const ver = document.getElementById('rv' + id);
  const edt = document.getElementById('ed' + id);
  const abrindo = edt.style.display === 'none';
  edt.style.display = abrindo ? '' : 'none';
  ver.style.display = abrindo ? 'none' : '';
}
</script>
<?php fecha_pagina();
