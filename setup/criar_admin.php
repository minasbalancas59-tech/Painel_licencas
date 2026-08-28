<?php
/**
 * Cria (ou atualiza) o usuario administrador do painel.
 * Uso:  php setup/criar_admin.php  email  senha  ["Nome"]
 */
require_once __DIR__ . '/../api/lib/licenca.php';

$email = $argv[1] ?? '';
$senha = $argv[2] ?? '';
$nome  = $argv[3] ?? 'Administrador';

if ($email === '' || strlen($senha) < 6) {
    fwrite(STDERR, "Uso: php criar_admin.php email senha [\"Nome\"]\n");
    fwrite(STDERR, "     (senha com no minimo 6 caracteres)\n");
    exit(1);
}

$hash = password_hash($senha, PASSWORD_DEFAULT);

$st = db()->prepare('SELECT id FROM usuarios WHERE email=?');
$st->execute([$email]);

if ($st->fetch()) {
    db()->prepare('UPDATE usuarios SET senha_hash=?, papel="admin", ativo=1, nome=? WHERE email=?')
        ->execute([$hash, $nome, $email]);
    echo "Admin atualizado: $email\n";
} else {
    db()->prepare('INSERT INTO usuarios (nome,email,senha_hash,papel) VALUES (?,?,?,"admin")')
        ->execute([$nome, $email, $hash]);
    echo "Admin criado: $email\n";
}
