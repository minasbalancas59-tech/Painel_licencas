<?php
require 'inc/auth.php';
require 'inc/layout.php';
exige_login();

$msg = ''; $tipo = '';

// --- cadastro -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='novo') {
    if (!csrf_valido()) { $msg='Sessão inválida, recarregue a página.'; $tipo='erro'; }
    else {
        $razao = trim($_POST['razao_social'] ?? '');
        if ($razao === '') { $msg='Informe a razão social.'; $tipo='erro'; }
        else {
            $st = db()->prepare(
              'INSERT INTO clientes (razao_social,cnpj,contato,telefone,email,observacao,criado_por)
               VALUES (?,?,?,?,?,?,?)');
            $st->execute([
                $razao,
                trim($_POST['cnpj']??''),
                trim($_POST['contato']??''),
                trim($_POST['telefone']??''),
                trim($_POST['email']??''),
                trim($_POST['observacao']??''),
                usuario_logado()['id'],
            ]);
            $msg='Cliente cadastrado.'; $tipo='ok';
        }
    }
}

$clientes = db()->query(
  'SELECT c.*, (SELECT COUNT(*) FROM licencas l WHERE l.cliente_id=c.id) AS n_lic
     FROM clientes c ORDER BY c.razao_social')->fetchAll();

abre_pagina('Clientes', 'clientes');
?>
<h1 class="titulo">Clientes</h1>
<p class="subtitulo">Cadastre as empresas que utilizam o Total Scale</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
  <h3>Novo cliente</h3>
  <form method="post">
    <input type="hidden" name="acao" value="novo">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
      <div><label>Razão social *</label><input name="razao_social" required></div>
      <div><label>CNPJ</label><input name="cnpj" placeholder="00.000.000/0001-00"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
      <div><label>Contato</label><input name="contato"></div>
      <div><label>Telefone</label><input name="telefone"></div>
      <div><label>E-mail</label><input name="email" type="email"></div>
    </div>
    <label>Observação</label>
    <textarea name="observacao" style="min-height:60px"></textarea>
    <button class="btn">Cadastrar cliente</button>
  </form>
</div>

<div class="card">
  <h3>Clientes cadastrados</h3>
  <table>
    <thead><tr><th>Razão social</th><th>CNPJ</th><th>Contato</th><th>Licenças</th><th></th></tr></thead>
    <tbody>
    <?php if (!$clientes): ?>
      <tr><td colspan="5" style="color:var(--texto-2)">Nenhum cliente cadastrado.</td></tr>
    <?php else: foreach ($clientes as $c): ?>
      <tr>
        <td><a href="cliente.php?id=<?= $c['id'] ?>"><?= e($c['razao_social']) ?></a></td>
        <td class="mono"><?= e($c['cnpj'] ?: '—') ?></td>
        <td><?= e($c['contato'] ?: '—') ?></td>
        <td><?= (int)$c['n_lic'] ?></td>
        <td><a class="btn sec pequeno" href="cliente.php?id=<?= $c['id'] ?>">Ver detalhes</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php fecha_pagina();
