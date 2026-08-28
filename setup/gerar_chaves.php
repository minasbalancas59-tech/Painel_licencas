<?php
/**
 * =====================================================================
 *  GERADOR DE CHAVES Ed25519  -  execute UMA UNICA VEZ
 * =====================================================================
 *  Uso (na VPS):   php gerar_chaves.php
 *
 *  Gera:
 *    - chave_privada.bin  -> FICA NO SERVIDOR. NUNCA compartilhe. Faca backup.
 *    - chave_publica.bin  -> vai embutida no seu executavel Delphi.
 *    - chave_publica.pas  -> trecho pronto para colar no Delphi (hex).
 *
 *  Se a chave privada vazar, qualquer um consegue forjar licencas.
 *  Se voce perder a chave privada, nao consegue mais emitir licencas
 *  validas para os executaveis ja distribuidos -> GUARDE O BACKUP.
 * =====================================================================
 */

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "ERRO: extensao sodium nao disponivel. Instale php-sodium (PHP 7.2+).\n");
    exit(1);
}

$destino = __DIR__ . '/chaves';
if (!is_dir($destino)) mkdir($destino, 0700, true);

if (file_exists("$destino/chave_privada.bin")) {
    fwrite(STDERR, "As chaves JA existem em $destino. Abortando para nao sobrescrever.\n");
    fwrite(STDERR, "Se realmente quer novas chaves, apague a pasta manualmente primeiro.\n");
    exit(1);
}

$par        = sodium_crypto_sign_keypair();
$privada    = sodium_crypto_sign_secretkey($par);   // 64 bytes
$publica    = sodium_crypto_sign_publickey($par);   // 32 bytes

file_put_contents("$destino/chave_privada.bin", $privada);
chmod("$destino/chave_privada.bin", 0600);
file_put_contents("$destino/chave_publica.bin", $publica);

// gera o trecho Delphi com a chave publica em hexadecimal
$hex = strtoupper(bin2hex($publica));
$linhas = str_split($hex, 32);
$pas  = "// Chave publica Ed25519 - cole em uAtivacao.pas\n";
$pas .= "// (32 bytes = 64 chars hex)\n";
$pas .= "const\n  CHAVE_PUBLICA_HEX =\n";
foreach ($linhas as $i => $l) {
    $pas .= "    '$l'" . ($i === count($linhas)-1 ? ";\n" : " +\n");
}
file_put_contents("$destino/chave_publica.pas", $pas);

echo "OK! Chaves geradas em: $destino\n\n";
echo "PROXIMOS PASSOS:\n";
echo "  1. Faca BACKUP de chave_privada.bin em local seguro (offline).\n";
echo "  2. Restrinja o acesso: a pasta chaves/ deve ficar FORA do www publico.\n";
echo "  3. Copie o conteudo de chave_publica.pas para o seu projeto Delphi.\n";
echo "\nChave publica (hex):\n$hex\n";
