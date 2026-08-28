<?php
require 'inc/auth.php';
require 'inc/layout.php';
exige_admin();

$msg=''; $tipo='';

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='novo') {
    if (!csrf_valido()) { $msg='Sessão inválida.'; $tipo='erro'; }
    else {
        $nome=trim($_POST['nome']??''); $email=trim($_POST['email']??'');
        $senha=$_POST['senha']??''; $papel=($_POST['papel']??'revendedor')==='admin'?'admin':'revendedor';
        if ($nome===''||$email===''||strlen($senha)<6) {
            $msg='Preencha nome, e-mail e senha (mín. 6 caracteres).'; $tipo='erro';
        } else {
            try {
                db()->prepare('INSERT INTO usuarios (nome,email,senha_hash,papel) VALUES (?,?,?,?)')
                    ->execute([$nome,$email,password_hash($senha,PASSWORD_DEFAULT),$papel]);
                $msg='Usuário criado.'; $tipo='ok';
            } catch (Throwable $e) {
                $msg='E-mail já cadastrado.'; $tipo='erro';
            }
        }
    }
}

$usuarios = db()->query('SELECT id,nome,email,papel,ativo,criado_em FROM usuarios ORDER BY nome')->fetchAll();

abre_pagina('Usuários', 'usuarios');
?>
<h1 class="titulo">Usuários do painel</h1>
<p class="subtitulo">Adicione revendedores ou funcionários que emitem licenças</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
  <h3>Novo usuário</h3>
  <form method="post">
    <input type="hidden" name="acao" value="novo">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div><label>Nome</label><input name="nome" required></div>
      <div><label>E-mail</label><input name="email" type="email" required></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div><label>Senha</label><input name="senha" type="password" required></div>
      <div><label>Papel</label>
        <select name="papel">
          <option value="revendedor">Revendedor</option>
          <option value="admin">Administrador</option>
        </select>
      </div>
    </div>
    <button class="btn">Criar usuário</button>
  </form>
</div>

<div class="card">
  <h3>Usuários</h3>
  <table>
    <thead><tr><th>Nome</th><th>E-mail</th><th>Papel</th><th>Desde</th></tr></thead>
    <tbody>
    <?php foreach ($usuarios as $u): ?>
      <tr>
        <td><?= e($u['nome']) ?></td>
        <td class="mono"><?= e($u['email']) ?></td>
        <td><span class="badge <?= $u['papel']==='admin'?'ativa':'nova' ?>"><?= e($u['papel']) ?></span></td>
        <td class="mono"><?= date('d/m/Y', strtotime($u['criado_em'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php fecha_pagina();
