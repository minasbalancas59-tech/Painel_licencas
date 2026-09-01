<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  REVENDEDORES - lista
 * =====================================================================
 *  Mesmo padrao da tela de clientes: a busca e o que se usa no dia a
 *  dia, o cadastro e eventual e fica recolhido. Os detalhes de cada
 *  revendedor (licencas, contatos, edicao) ficam em revendedor.php.
 * ===================================================================== */

$msg=''; $tipo=''; $abrirForm=false; $old=[];

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='novo') {
    if (!csrf_valido()) { $msg='Sessão inválida.'; $tipo='erro'; }
    else {
        $nome  = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if ($nome==='' || $email==='' || strlen($senha) < 6) {
            $msg='Preencha responsável, e-mail e senha (mínimo 6 caracteres).';
            $tipo='erro'; $abrirForm=true; $old=$_POST;
        } else {
            try {
                db()->beginTransaction();
                db()->prepare(
                  'INSERT INTO usuarios
                     (nome,empresa,nome_fantasia,cnpj,telefone,municipio,uf,
                      observacao,limite_estoque,email,senha_hash,papel)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,"revendedor")')
                  ->execute([
                    $nome,
                    (trim($_POST['empresa'] ?? '') ?: null),
                    (trim($_POST['nome_fantasia'] ?? '') ?: null),
                    (trim($_POST['cnpj'] ?? '') ?: null),
                    (trim($_POST['telefone'] ?? '') ?: null),
                    (trim($_POST['municipio'] ?? '') ?: null),
                    (strtoupper(substr(trim($_POST['uf'] ?? ''),0,2)) ?: null),
                    (trim($_POST['observacao'] ?? '') ?: null),
                    ((int)($_POST['limite_estoque'] ?? 0) ?: null),
                    $email,
                    password_hash($senha, PASSWORD_DEFAULT),
                  ]);
                $novoId = (int)db()->lastInsertId();
                db()->prepare(
                  'INSERT INTO revendedor_contatos
                     (revendedor_id,nome,telefone,email,principal)
                   VALUES (?,?,?,?,1)')
                  ->execute([$novoId, $nome,
                             (trim($_POST['telefone'] ?? '') ?: null), $email]);
                db()->commit();
                $msg='Revendedor cadastrado.'; $tipo='ok';
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                $msg='Não foi possível cadastrar (e-mail já existe?).';
                $tipo='erro'; $abrirForm=true; $old=$_POST;
            }
        }
    }
}

// ---- busca ----------------------------------------------------------
$busca = trim($_GET['q'] ?? '');
$fSit  = trim($_GET['sit'] ?? '');

$where = ["u.papel='revendedor'"]; $args = [];
if ($fSit === 'ativo')   $where[] = 'u.ativo=1';
if ($fSit === 'inativo') $where[] = 'u.ativo=0';
if ($busca !== '') {
    $where[] = '(u.nome LIKE ? OR u.empresa LIKE ? OR u.nome_fantasia LIKE ? '
             . 'OR u.cnpj LIKE ? OR u.email LIKE ? OR u.municipio LIKE ? '
             . 'OR EXISTS (SELECT 1 FROM revendedor_contatos rc '
             . '            WHERE rc.revendedor_id=u.id AND ('
             . '              rc.nome LIKE ? OR rc.telefone LIKE ? '
             . '              OR rc.email LIKE ?)))';
    for ($i=0;$i<9;$i++) $args[] = '%'.$busca.'%';
}
$whereSql = 'WHERE '.implode(' AND ', $where);

$st = db()->prepare(
  "SELECT u.*,
          (SELECT COUNT(*) FROM licencas l WHERE l.revendedor_id=u.id) AS total,
          (SELECT COUNT(*) FROM licencas l
            WHERE l.revendedor_id=u.id AND l.cliente_id IS NULL) AS estoque,
          (SELECT COUNT(*) FROM licencas l
            WHERE l.revendedor_id=u.id AND l.status='ativa') AS ativas,
          (SELECT COUNT(*) FROM licencas l
            WHERE l.revendedor_id=u.id AND l.status='ativa'
              AND l.expira_em BETWEEN CURDATE()
                  AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS vencendo,
          (SELECT COUNT(*) FROM clientes c WHERE c.revendedor_id=u.id) AS clientes
     FROM usuarios u
   $whereSql
   ORDER BY u.ativo DESC, COALESCE(u.nome_fantasia,u.empresa,u.nome)");
$st->execute($args);
$revs = $st->fetchAll();

if ($busca !== '' && !$revs) $abrirForm = true;

function v($old,$c){ return e($old[$c] ?? ''); }

abre_pagina('Revendedores', 'revendedores');
?>
<h1 class="titulo">Revendedores</h1>
<p class="subtitulo">Parceiros que vendem e atendem clientes finais em seu nome</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <div style="flex:1;min-width:240px">
      <label>Buscar por empresa, fantasia, CNPJ, responsável, e-mail ou cidade</label>
      <input type="text" name="q" value="<?= e($busca) ?>" autofocus>
    </div>
    <div>
      <label>Situação</label>
      <select name="sit">
        <option value="">— todos —</option>
        <option value="ativo"   <?= $fSit==='ativo'  ?'selected':'' ?>>Ativos</option>
        <option value="inativo" <?= $fSit==='inativo'?'selected':'' ?>>Inativos</option>
      </select>
    </div>
    <button class="btn">Buscar</button>
    <?php if ($busca!=='' || $fSit!==''): ?>
      <a class="btn sec" href="revendedores.php">Limpar</a>
    <?php endif; ?>
    <button type="button" class="btn sec" onclick="abrirCadastro()">+ Novo revendedor</button>
  </form>
  <?php if ($busca !== ''): ?>
    <p class="subtitulo" style="margin:12px 0 0">
      <?= count($revs) ?> resultado(s) para "<?= e($busca) ?>"
    </p>
  <?php endif; ?>
</div>

<div class="card" id="boxCadastro" style="<?= $abrirForm ? '' : 'display:none' ?>">
  <h3>Novo revendedor</h3>
  <?php if ($busca !== '' && !$revs): ?>
    <p class="subtitulo" style="margin-top:-6px">
      Nenhum revendedor encontrado para "<?= e($busca) ?>". Cadastre abaixo.
    </p>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="acao" value="novo">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <h3 style="font-size:13px;margin:0 0 10px">Empresa</h3>

    <div style="display:grid;grid-template-columns:1fr auto 2fr;gap:12px;
         align-items:flex-end">
      <div>
        <label>CNPJ</label>
        <input name="cnpj" id="rCnpj" autocomplete="off"
               value="<?= v($old,'cnpj') ?>" placeholder="00.000.000/0000-00">
      </div>
      <div>
        <button type="button" class="btn sec" onclick="buscarCnpjRev()">
          Buscar na Receita</button>
      </div>
      <div>
        <span class="subtitulo" id="rStatusCnpj"
              style="margin:0;display:block;font-size:12px"></span>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px">
      <div><label>Razão social</label>
        <input name="empresa" id="rEmpresa" autocomplete="off"
               value="<?= $old ? v($old,'empresa') : e($busca) ?>"></div>
      <div><label>Nome fantasia</label>
        <input name="nome_fantasia" id="rFantasia" autocomplete="off"
               value="<?= v($old,'nome_fantasia') ?>"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:16px;margin-top:12px">
      <div><label>Telefone</label>
        <input name="telefone" id="rTelefone" autocomplete="off"
               value="<?= v($old,'telefone') ?>"></div>
      <div><label>Município</label>
        <input name="municipio" id="rMunicipio" autocomplete="off"
               value="<?= v($old,'municipio') ?>"></div>
      <div><label>UF</label>
        <input name="uf" id="rUf" maxlength="2" autocomplete="off"
               value="<?= v($old,'uf') ?>"></div>
    </div>

    <h3 style="font-size:13px;margin:18px 0 10px">Acesso ao painel</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
      <div><label>Responsável *</label>
        <input name="nome" id="rNome" autocomplete="off"
               required value="<?= v($old,'nome') ?>"></div>
      <div><label>E-mail (login) *</label>
        <input name="email" id="rEmail" type="email" required
               autocomplete="new-password"
               value="<?= v($old,'email') ?>"></div>
      <div><label>Senha inicial *</label>
        <input name="senha" type="password" required minlength="6"
               autocomplete="new-password"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 3fr;gap:16px;margin-top:12px">
      <div><label>Limite de estoque</label>
        <input name="limite_estoque" type="number" min="0"
               placeholder="vazio = sem limite" value="<?= v($old,'limite_estoque') ?>"></div>
      <div><label>Observação</label>
        <input name="observacao" value="<?= v($old,'observacao') ?>"></div>
    </div>

    <div style="margin-top:14px">
      <button class="btn">Cadastrar revendedor</button>
      <button type="button" class="btn sec" style="margin-left:8px"
              onclick="document.getElementById('boxCadastro').style.display='none'">
        Cancelar
      </button>
    </div>
  </form>
</div>

<div class="card">
  <h3><?= $busca !== '' ? 'Resultados' : 'Cadastrados' ?> (<?= count($revs) ?>)</h3>
  <table>
    <thead><tr>
      <th>Revendedor</th><th>Contato</th><th>Local</th>
      <th>Clientes</th><th>Estoque</th><th>Ativas</th>
      <th>Vencendo</th><th>Situação</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$revs): ?>
      <tr><td colspan="9" style="color:var(--texto-2)">
        <?= $busca!=='' ? 'Nenhum revendedor encontrado.' : 'Nenhum revendedor cadastrado.' ?>
      </td></tr>
    <?php else: foreach ($revs as $r):
        $estouro = $r['limite_estoque'] !== null
                && (int)$r['estoque'] > (int)$r['limite_estoque'];
    ?>
      <tr>
        <td>
          <a href="revendedor.php?id=<?= $r['id'] ?>">
            <b><?= e($r['nome_fantasia'] ?: ($r['empresa'] ?: $r['nome'])) ?></b></a>
          <?php if ($r['empresa'] && $r['nome_fantasia']): ?>
            <br><span style="font-size:10px;color:var(--texto-2)">
              <?= e($r['empresa']) ?></span>
          <?php endif; ?>
          <?php if ($r['cnpj']): ?>
            <br><span class="mono" style="font-size:10px;color:var(--texto-2)">
              <?= e($r['cnpj']) ?></span>
          <?php endif; ?>
        </td>
        <td style="font-size:11px">
          <?= e($r['nome']) ?><br>
          <span style="color:var(--texto-2)"><?= e($r['email']) ?></span>
        </td>
        <td style="font-size:11px;color:var(--texto-2)">
          <?= e($r['municipio'] ? $r['municipio'].($r['uf']?'/'.$r['uf']:'') : '—') ?>
        </td>
        <td class="mono"><?= (int)$r['clientes'] ?></td>
        <td class="mono" style="<?= $estouro ? 'color:var(--vermelho)' : '' ?>">
          <?= (int)$r['estoque'] ?>
          <?php if ($r['limite_estoque'] !== null): ?>
            <span style="font-size:10px;color:var(--texto-2)">/<?= (int)$r['limite_estoque'] ?></span>
          <?php endif; ?>
        </td>
        <td class="mono" style="color:var(--verde)"><?= (int)$r['ativas'] ?></td>
        <td class="mono" style="<?= (int)$r['vencendo']>0 ? 'color:var(--ambar)' : '' ?>">
          <?= (int)$r['vencendo'] ?>
        </td>
        <td>
          <span class="badge <?= $r['ativo'] ? 'ativa' : 'revogada' ?>">
            <?= $r['ativo'] ? 'ativo' : 'inativo' ?></span>
        </td>
        <td><a class="btn sec pequeno" href="revendedor.php?id=<?= $r['id'] ?>">Ver detalhes</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
/* Busca o CNPJ na Receita e preenche o cadastro.

   O mesmo endpoint usado no cadastro de clientes: a consulta sai do
   servidor, não do navegador — evita CORS e permite cache de 30 dias.

   Só preenche campo VAZIO. Se você já digitou o nome fantasia como
   conhece o parceiro, a Receita não sobrescreve. */
function buscarCnpjRev() {
  var campo  = document.getElementById('rCnpj');
  var status = document.getElementById('rStatusCnpj');
  var cnpj   = (campo.value || '').replace(/\D/g, '');

  if (cnpj.length !== 14) {
    status.textContent = 'Digite os 14 dígitos do CNPJ.';
    status.style.color = 'var(--vermelho)';
    campo.focus();
    return;
  }

  status.textContent = 'Consultando…';
  status.style.color = '';

  fetch('cnpj.php?cnpj=' + cnpj)
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (!j.ok) {
        status.textContent = j.erro || 'Não encontrado.';
        status.style.color = 'var(--vermelho)';
        return;
      }
      var d = j.dados;

      function por(id, valor) {
        var el = document.getElementById(id);
        if (el && valor && !el.value) el.value = valor;
      }
      por('rEmpresa',   d.razao_social);
      por('rFantasia',  d.nome_fantasia);
      por('rTelefone',  d.telefone);
      por('rMunicipio', d.municipio);
      por('rUf',        d.uf);
      por('rEmail',     (d.email || '').toLowerCase());

      // formata o CNPJ do jeito que se lê
      campo.value = cnpj.replace(
        /^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');

      status.textContent = d.situacao
        ? 'Encontrado · ' + d.situacao
        : 'Encontrado.';
      status.style.color = 'var(--verde)';
    })
    .catch(function () {
      status.textContent = 'Falha na consulta. Preencha à mão.';
      status.style.color = 'var(--vermelho)';
    });
}

/* O Chrome ignora autocomplete="off" em campos de e-mail dentro de
   formulário com senha: ele entende como tela de login e oferece a
   credencial salva — a SUA, no caso. Limpar no carregamento resolve o
   que o atributo não resolve. */
document.addEventListener('DOMContentLoaded', function () {
  setTimeout(function () {
    var e = document.getElementById('rEmail');
    var v = <?= json_encode(v($old, 'email')) ?>;
    if (e && !v && e.value) e.value = '';
  }, 120);
});
</script>
<script>
function abrirCadastro() {
  const box = document.getElementById('boxCadastro');
  box.style.display = '';
  box.scrollIntoView({ behavior:'smooth', block:'center' });
  const c = box.querySelector('input[name="empresa"]');
  if (c) c.focus();
}
</script>
<?php fecha_pagina();
