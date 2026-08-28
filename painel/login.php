<?php
require_once __DIR__ . '/../api/lib/licenca.php';

// mantem a sessao viva pelo tempo definido em config.php (SESSAO_TIMEOUT)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', (string)SESSAO_TIMEOUT);
}
session_start();

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // normaliza o e-mail: remove espacos e ignora maiusculas/minusculas
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $senha = $_POST['senha'] ?? '';

    $st = db()->prepare('SELECT * FROM usuarios WHERE LOWER(email)=? AND ativo=1 LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();

    if ($u && password_verify($senha, $u['senha_hash'])) {
        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id' => $u['id'], 'nome' => $u['nome'],
            'email' => $u['email'], 'papel' => $u['papel'],
        ];
        $_SESSION['ultimo_acesso'] = time();
        header('Location: index.php');
        exit;
    }
    $erro = 'E-mail ou senha incorretos.';
}
function e2($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrar · Licenciamento Total Scale</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/estilo.css">
  <style>
    /* campo de senha com botao "olho" dentro */
    .campo-senha { position: relative; }
    .campo-senha input { width: 100%; padding-right: 44px; }
    .btn-olho {
      position: absolute; top: 50%; right: 8px; transform: translateY(-50%);
      width: 30px; height: 30px; border: none; background: transparent;
      cursor: pointer; padding: 0; display: flex; align-items: center;
      justify-content: center; color: #93a1ac;
    }
    .btn-olho:hover { color: #e6ebef; }
    .btn-olho svg { width: 20px; height: 20px; }
  </style>
</head>
<body>
  <div class="login-wrap">
    <div class="marca">TOTAL<b>SCALE</b></div>
    <div class="sub">PAINEL DE LICENCIAMENTO</div>
    <div class="card">
      <?php if ($erro): ?><div class="aviso erro"><?= e2($erro) ?></div><?php endif; ?>
      <?php if (isset($_GET['timeout'])): ?><div class="aviso erro">Sessão expirada. Entre novamente.</div><?php endif; ?>
      <form method="post">
        <label>E-mail</label>
        <input type="email" name="email" required autofocus>
        <label>Senha</label>
        <div class="campo-senha">
          <input type="password" name="senha" id="senha" required>
          <button type="button" class="btn-olho" id="verSenha"
                  aria-label="Mostrar senha" title="Mostrar senha"
                  onclick="alternarSenha()">
            <!-- icone olho (mostrar) -->
            <svg id="ico-olho" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
              <circle cx="12" cy="12" r="3"></circle>
            </svg>
            <!-- icone olho cortado (ocultar), escondido por padrao -->
            <svg id="ico-olho-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
              <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
              <line x1="1" y1="1" x2="23" y2="23"></line>
            </svg>
          </button>
        </div>
        <button class="btn" style="width:100%">Entrar</button>
      </form>
    </div>
  </div>

  <script>
    function alternarSenha() {
      var campo = document.getElementById('senha');
      var olho  = document.getElementById('ico-olho');
      var olhoOff = document.getElementById('ico-olho-off');
      var botao = document.getElementById('verSenha');
      if (campo.type === 'password') {
        campo.type = 'text';
        olho.style.display = 'none';
        olhoOff.style.display = 'block';
        botao.setAttribute('aria-label', 'Ocultar senha');
        botao.setAttribute('title', 'Ocultar senha');
      } else {
        campo.type = 'password';
        olho.style.display = 'block';
        olhoOff.style.display = 'none';
        botao.setAttribute('aria-label', 'Mostrar senha');
        botao.setAttribute('title', 'Mostrar senha');
      }
    }
  </script>
</body>
</html>
