<?php
/**
 * =====================================================================
 *  Consulta de CNPJ - preenche o cadastro automaticamente
 * =====================================================================
 *  Usa a BrasilAPI (gratuita, sem cadastro, dados da Receita Federal).
 *  Chamada pelo servidor, nao pelo navegador: evita CORS, mantem a
 *  chamada sob nosso controle e permite cache.
 *
 *  GET cnpj.php?cnpj=12345678000199
 *  -> { ok:true, dados:{ razao_social, nome_fantasia, ... } }
 *
 *  Exige login: nao e um proxy aberto de consulta de CNPJ para a
 *  internet inteira.
 * =====================================================================
 */

require 'inc/auth.php';
exige_login();

header('Content-Type: application/json; charset=utf-8');

function resp(array $d, int $status = 200): void {
    http_response_code($status);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Valida o digito verificador do CNPJ.
 *
 * Sem isto, um numero digitado errado passa no teste de 14 digitos, vai
 * ate a Receita e volta como "nao encontrado" - o que faz o operador
 * procurar o cliente que nao existe, em vez de conferir o que digitou.
 */
function cnpj_valido(string $c): bool {
    if (strlen($c) !== 14) return false;
    // 00000000000000, 11111111111111 etc. passam na formula
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

$cnpj = preg_replace('/\D/', '', $_GET['cnpj'] ?? '');

if ($cnpj === '') {
    resp(['ok' => false, 'erro' => 'Informe o CNPJ.'], 400);
}
if (strlen($cnpj) !== 14) {
    resp(['ok' => false,
          'erro' => 'O CNPJ tem ' . strlen($cnpj) . ' dígito(s); precisa de 14.'
        ], 400);
}
if (!cnpj_valido($cnpj)) {
    resp(['ok' => false,
          'erro' => 'CNPJ inválido — confira os números digitados.'], 400);
}

// cache em disco: a Receita muda pouco, e evita bater na API a cada
// digitacao. 30 dias e um meio-termo razoavel.
$dirCache = sys_get_temp_dir() . '/cnpj_cache';
if (!is_dir($dirCache)) @mkdir($dirCache, 0700, true);
$arqCache = "$dirCache/$cnpj.json";

if (is_file($arqCache) && (time() - filemtime($arqCache)) < 30*24*3600) {
    $bruto = file_get_contents($arqCache);
} else {
    $ch = curl_init("https://brasilapi.com.br/api/cnpj/v1/$cnpj");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT      => 'PainelLicencas/1.0',
    ]);
    $bruto  = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroC  = curl_error($ch);
    curl_close($ch);

    if ($bruto === false) {
        resp(['ok' => false,
              'erro' => 'Não foi possível consultar agora: ' . $erroC], 502);
    }
    if ($codigo === 404) {
        resp(['ok' => false, 'erro' => 'CNPJ não encontrado na Receita.'], 404);
    }
    if ($codigo !== 200) {
        resp(['ok' => false,
              'erro' => "Serviço de consulta indisponível (HTTP $codigo)."], 502);
    }
    @file_put_contents($arqCache, $bruto);
}

$d = json_decode($bruto, true);
if (!is_array($d)) {
    resp(['ok' => false, 'erro' => 'Resposta inválida do serviço.'], 502);
}

// monta o telefone no formato (DD) NNNNN-NNNN quando possivel
$tel = trim((string)($d['ddd_telefone_1'] ?? ''));
if (preg_match('/^(\d{2})(\d{4,5})(\d{4})$/', preg_replace('/\D/','',$tel), $m)) {
    $tel = "({$m[1]}) {$m[2]}-{$m[3]}";
}

resp(['ok' => true, 'dados' => [
    'razao_social'   => $d['razao_social']   ?? '',
    'nome_fantasia'  => $d['nome_fantasia']  ?? '',
    'telefone'       => $tel,
    'email'          => $d['email']          ?? '',
    'municipio'      => $d['municipio']      ?? '',
    'uf'             => $d['uf']             ?? '',
    'situacao'       => $d['descricao_situacao_cadastral'] ?? '',
    'endereco'       => trim(
        ($d['logradouro'] ?? '') . ', ' . ($d['numero'] ?? '') .
        (($d['bairro'] ?? '') ? ' - ' . $d['bairro'] : '')
    ),
]]);
