<?php
/**
 * =====================================================================
 *  Biblioteca central de licenciamento
 *  Compartilhada entre o painel web e a API de ativacao.
 * =====================================================================
 */

require_once __DIR__ . '/config.php';

/* -------------------------------------------------------------------
 * Conexao PDO
 * ----------------------------------------------------------------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/* -------------------------------------------------------------------
 * Carrega as chaves (privada so existe no servidor)
 * ----------------------------------------------------------------- */
function chave_privada(): string {
    $p = CHAVES_DIR . '/chave_privada.bin';
    if (!is_readable($p)) {
        throw new RuntimeException('Chave privada nao encontrada. Rode setup/gerar_chaves.php');
    }
    return file_get_contents($p);
}

function chave_publica(): string {
    return file_get_contents(CHAVES_DIR . '/chave_publica.bin');
}

/* -------------------------------------------------------------------
 * Gera uma chave de licenca legivel (o cliente digita esta chave)
 *   formato: TS6X-XXXX-XXXX-XXXX  (sem caracteres ambiguos)
 * ----------------------------------------------------------------- */
/**
 * Gera a chave de registro no formato PREFIXO-XXXX-XXXX-XXXX.
 *
 * O prefixo vem do codigo do produto: ts5 -> TS5X, tslpr -> TSLPRX.
 * Antes era fixo em 'TS6X' para todos, e um cliente do TS5 recebia
 * chave comecando com "TS6" - confusao garantida no atendimento.
 *
 * O prefixo e apenas um rotulo: a validacao continua sendo pelo
 * produto_id gravado no banco e pelo campo "produto" da licenca
 * assinada. Chaves antigas com TS6X seguem valendo.
 *
 * @param string $produtoCodigo codigo do produto (ex: 'ts5'). Vazio
 *                              mantem o prefixo historico.
 */
function gerar_chave_licenca(string $produtoCodigo = ''): string {
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem I,O,0,1
    $bloco = function() use ($alfabeto) {
        $s = '';
        for ($i = 0; $i < 4; $i++)
            $s .= $alfabeto[random_int(0, strlen($alfabeto)-1)];
        return $s;
    };

    $cod = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $produtoCodigo));
    // limita a 6 para nao estourar o VARCHAR(35) da coluna chave
    if ($cod === '') $cod = 'TS6';
    $prefixo = substr($cod, 0, 6) . 'X';

    return $prefixo . '-' . $bloco() . '-' . $bloco() . '-' . $bloco();
}

/* -------------------------------------------------------------------
 * Monta e ASSINA o arquivo de licenca.
 *   O resultado (base64) e o que o Delphi guarda e valida offline.
 *
 *   Estrutura:  base64( json_payload ) . "." . base64( assinatura )
 * ----------------------------------------------------------------- */
function emitir_licenca_assinada(array $dados): string {
    // campos que o Delphi vai validar
    $payload = [
        'cliente'     => $dados['cliente'],
        'cnpj'        => $dados['cnpj'] ?? '',
        'chave'       => $dados['chave'],
        'fingerprint' => $dados['fingerprint'],
        'modulos'     => $dados['modulos'],       // ex: "TBE,RFID,LPR"
        'emitido'     => $dados['emitido'],       // YYYY-MM-DD
        'expira'      => $dados['expira'],        // YYYY-MM-DD
        'versao'      => 1,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assinatura = sodium_crypto_sign_detached($json, chave_privada());

    return b64u_encode($json) . '.' . b64u_encode($assinatura);
}

/* -------------------------------------------------------------------
 * Verifica uma licenca assinada (usado tambem em testes no servidor).
 *   Retorna o payload (array) se valida, ou null se invalida.
 * ----------------------------------------------------------------- */
function verificar_licenca(string $licenca): ?array {
    $partes = explode('.', $licenca);
    if (count($partes) !== 2) return null;

    $json = b64u_decode($partes[0]);
    $sig  = b64u_decode($partes[1]);
    if ($json === false || $sig === false) return null;

    if (!sodium_crypto_sign_verify_detached($sig, $json, chave_publica()))
        return null;

    return json_decode($json, true);
}

/* -------------------------------------------------------------------
 * base64 url-safe (sem +,/,= que atrapalham copiar/colar)
 * ----------------------------------------------------------------- */
function b64u_encode(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function b64u_decode(string $s) {
    return base64_decode(strtr($s, '-_', '+/'));
}

/* -------------------------------------------------------------------
 * Registra uma linha de auditoria
 * ----------------------------------------------------------------- */
function log_acao(?int $licencaId, ?string $chave, ?string $fp,
                  string $acao, string $resultado, string $detalhe = ''): void {
    $st = db()->prepare(
        'INSERT INTO ativacoes_log
           (licenca_id, chave, fingerprint, ip, acao, resultado, detalhe)
         VALUES (?,?,?,?,?,?,?)');
    $st->execute([
        $licencaId, $chave, $fp,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $acao, $resultado, mb_substr($detalhe, 0, 255)
    ]);
}

/* ===== EXTENSAO MULTI-PRODUTO (produto/tier/nivel + log estendido) ===== */

function resolver_tier(int $tierId): array {
    $st = db()->prepare(
        'SELECT p.id     AS produto_id,
                p.codigo AS produto_codigo, p.nome AS produto_nome,
                t.codigo AS tier_codigo,    t.nome AS tier_nome,
                t.nivel  AS nivel,          t.preco_base AS preco_base
           FROM tiers t
           JOIN produtos p ON p.id = t.produto_id
          WHERE t.id = ? AND t.ativo = 1 AND p.ativo = 1');
    $st->execute([$tierId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Tier invalido ou inativo (id=' . $tierId . ').');
    }
    return $row;
}

function emitir_licenca_assinada_v2(array $dados): string {
    $payload = [
        'cliente'     => $dados['cliente'],
        'cnpj'        => $dados['cnpj'] ?? '',
        'chave'       => $dados['chave'],
        'fingerprint' => $dados['fingerprint'],
        'produto'     => $dados['produto'],
        'tier'        => $dados['tier'],
        'nivel'       => (int)$dados['nivel'],
        'modulos'     => $dados['modulos'] ?? '',
        'emitido'     => $dados['emitido'],
        'expira'      => $dados['expira'],
        'carencia'    => (int)($dados['carencia'] ?? 15),
        'versao'      => 2,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assinatura = sodium_crypto_sign_detached($json, chave_privada());
    return b64u_encode($json) . '.' . b64u_encode($assinatura);
}

function log_acao_painel(?int $licencaId, ?string $chave, ?string $fp,
                         string $acao, string $resultado,
                         ?int $usuarioId, ?string $usuarioNome,
                         ?string $produtoCodigo, ?string $tierCodigo,
                         string $detalhe = ''): void {
    $st = db()->prepare(
        'INSERT INTO ativacoes_log
           (licenca_id, usuario_id, usuario_nome, chave, fingerprint,
            produto_codigo, tier_codigo, ip, acao, resultado, detalhe)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
        $licencaId, $usuarioId, $usuarioNome, $chave, $fp,
        $produtoCodigo, $tierCodigo,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $acao, $resultado, mb_substr($detalhe, 0, 255)
    ]);
}

/* =====================================================================
 *  Apoio ao autocadastro do cliente final
 * ===================================================================== */

/**
 * Valida o digito verificador do CNPJ.
 * Evita cadastro criado a partir de numero digitado errado - que
 * depois vira cliente fantasma na base.
 */
function cnpj_dv_valido(string $c): bool {
    if (strlen($c) !== 14) return false;
    if (preg_match('/^(\d)\1{13}$/', $c)) return false;
    foreach ([12, 13] as $pos) {
        $soma = 0;
        $peso = ($pos === 12) ? 5 : 6;
        for ($i = 0; $i < $pos; $i++) {
            $soma += (int)$c[$i] * $peso;
            $peso = ($peso === 2) ? 9 : $peso - 1;
        }
        $dv = $soma % 11;
        $dv = ($dv < 2) ? 0 : 11 - $dv;
        if ((int)$c[$pos] !== $dv) return false;
    }
    return true;
}

/**
 * Consulta o CNPJ na BrasilAPI. Devolve null se nao conseguir.
 *
 * TIMEOUT CURTO de proposito: isto roda no meio de uma ativacao, com o
 * cliente esperando na frente da balanca. Melhor cadastrar com o que
 * ele digitou e conferir depois do que deixa-lo parado esperando um
 * servico que nao e nosso.
 *
 * O cache evita bater na API a cada ativacao do mesmo CNPJ.
 */
function consultar_receita(string $cnpj): ?array {
    $dir = sys_get_temp_dir() . '/cnpj_cache';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $arq = "$dir/$cnpj.json";

    $bruto = null;
    if (is_file($arq) && (time() - filemtime($arq)) < 30 * 24 * 3600) {
        $bruto = file_get_contents($arq);
    } else {
        $ch = curl_init("https://brasilapi.com.br/api/cnpj/v1/$cnpj");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => 'PainelLicencas/1.0',
        ]);
        $r    = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r !== false && $http === 200) {
            $bruto = $r;
            @file_put_contents($arq, $r);
        }
    }

    if ($bruto === null) return null;
    $d = json_decode($bruto, true);
    if (!is_array($d) || empty($d['razao_social'])) return null;

    return [
        'razao_social'  => $d['razao_social'],
        'nome_fantasia' => $d['nome_fantasia'] ?? '',
        'municipio'     => $d['municipio'] ?? '',
        'uf'            => $d['uf'] ?? '',
    ];
}
