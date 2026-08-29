<?php
/**
 * =====================================================================
 *  Configuracoes guardadas no banco
 * =====================================================================
 *  O config.php continua com o que nao pode mudar em runtime (acesso ao
 *  banco, caminho das chaves). O que o operador ajusta - servidor de
 *  e-mail, destinatario dos avisos - vem daqui, editavel pelo painel.
 *
 *  SENHAS SAO CIFRADAS EM REPOUSO
 *  O backup diario do banco sobe para a nuvem. Senha SMTP em texto puro
 *  ali significa que quem tiver o dump manda e-mail em seu nome. Por
 *  isso os valores marcados como cifrados sao guardados com AES-256, e
 *  a chave de cifra vive FORA do banco - logo, nao vai no dump.
 *
 *  De onde vem a chave de cifra, em ordem:
 *    1) constante CONFIG_KEY no config.php (recomendado)
 *    2) hash do arquivo chave_privada.bin, que ja esta no disco e
 *       nunca entra no dump do banco
 *
 *  Consequencia da opcao 2: se voce trocar a chave privada Ed25519, as
 *  senhas guardadas param de decifrar e precisam ser digitadas de novo.
 *  Nada mais quebra - e a chave privada nao deve mudar nunca.
 * =====================================================================
 */

function cfg_chave_cifra(): string {
    static $k = null;
    if ($k !== null) return $k;

    if (defined('CONFIG_KEY') && CONFIG_KEY !== '') {
        return $k = hash('sha256', CONFIG_KEY, true);
    }
    $arq = (defined('CHAVES_DIR') ? CHAVES_DIR : '/var/licenca/chaves')
         . '/chave_privada.bin';
    if (is_readable($arq)) {
        return $k = hash('sha256', 'cfg|' . file_get_contents($arq), true);
    }
    // ultimo recurso: sem chave estavel nao ha como cifrar com seguranca
    throw new RuntimeException(
        'Sem chave de cifra: defina CONFIG_KEY em config.php.');
}

function cfg_cifrar(string $texto): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($texto, 'aes-256-cbc', cfg_chave_cifra(),
                           OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function cfg_decifrar(?string $guardado): string {
    if ($guardado === null || $guardado === '') return '';
    $bruto = base64_decode($guardado, true);
    if ($bruto === false || strlen($bruto) < 17) return '';
    $iv  = substr($bruto, 0, 16);
    $enc = substr($bruto, 16);
    $txt = openssl_decrypt($enc, 'aes-256-cbc', cfg_chave_cifra(),
                           OPENSSL_RAW_DATA, $iv);
    return $txt === false ? '' : $txt;
}

/** todas as configuracoes, ja decifradas */
function cfg_todas(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    try {
        $st = db()->query('SELECT chave, valor, cifrado FROM configuracoes');
        foreach ($st->fetchAll() as $r) {
            $cache[$r['chave']] = $r['cifrado']
                ? cfg_decifrar($r['valor'])
                : (string)($r['valor'] ?? '');
        }
    } catch (Throwable $e) {
        // tabela ainda nao criada: segue com os defaults
    }
    return $cache;
}

/**
 * Le uma configuracao. Se estiver vazia no banco, cai na constante
 * equivalente do config.php - assim a migracao do arquivo para a tela
 * nao quebra quem ainda nao preencheu.
 */
function cfg(string $chave, $padrao = '') {
    $t = cfg_todas();
    if (isset($t[$chave]) && $t[$chave] !== '') return $t[$chave];

    $constantes = [
        'email_admin'  => 'EMAIL_ADMIN',
        'smtp_host'    => 'SMTP_HOST',
        'smtp_porta'   => 'SMTP_PORT',
        'smtp_usuario' => 'SMTP_USER',
        'smtp_senha'   => 'SMTP_PASS',
        'smtp_de'      => 'SMTP_DE',
        'smtp_de_nome' => 'SMTP_DE_NOME',
    ];
    if (isset($constantes[$chave]) && defined($constantes[$chave])) {
        return constant($constantes[$chave]);
    }
    return $padrao;
}

function cfg_salvar(string $chave, ?string $valor, bool $cifrar = false,
                    ?int $usuarioId = null): void {
    $guardar = ($cifrar && $valor !== null && $valor !== '')
             ? cfg_cifrar($valor) : $valor;

    db()->prepare(
      'INSERT INTO configuracoes (chave, valor, cifrado, atualizado_por)
       VALUES (?,?,?,?)
       ON DUPLICATE KEY UPDATE
         valor = VALUES(valor), cifrado = VALUES(cifrado),
         atualizado_por = VALUES(atualizado_por)')
      ->execute([$chave, $guardar, $cifrar ? 1 : 0, $usuarioId]);
}
