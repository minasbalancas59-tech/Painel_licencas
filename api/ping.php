<?php
/**
 * =====================================================================
 *  API - PING de abertura  (registro de acesso por maquina)
 * =====================================================================
 *  O Total Scale chama isto silenciosamente ao abrir:
 *    POST { "chave":"TS6X-...", "fingerprint":"...",
 *           "maq_nome":"...", "maq_usuario":"...", "maq_so":"...",
 *           "origem":"licenca" | "dongle" | "tslpr",
 *           "tipo":"abertura" | "presenca" | "fechamento",
 *           "pesagens_mes": 1240 }
 *
 *  "tipo" alimenta a tabela `acessos` (historico evento a evento).
 *  Ausente = 'abertura', para o cliente antigo continuar funcionando.
 *  So 'abertura' incrementa o contador; 'presenca' e 'fechamento' nao,
 *  senao um PC ligado o dia todo apareceria com centenas de aberturas.
 *
 *  Nao valida licenca nem bloqueia nada: so registra que a maquina
 *  apareceu. Responde rapido. Se algo falhar, o cliente ignora.
 *
 *  "origem" identifica COMO a maquina esta licenciada. Serve para
 *  acompanhar a migracao do Rockey2: quem chega como 'dongle' ainda
 *  nao foi convertido. Campo opcional - cliente antigo que nao enviar
 *  continua funcionando, so grava NULL.
 *
 *  "pesagens_mes" e o total de pesagens do mes corrente na base do
 *  cliente (tabelamestre, contadas pelo campo SAIDA). Alimenta
 *  `pesagens_mensal`, que NAO e expurgada: e o historico comercial
 *  usado nas renovacoes. Campo opcional; cliente antigo nao envia.
 *
 *  Resposta: { "ok": true }  (sempre que gravar; erros sao silenciosos)
 * =====================================================================
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib/licenca.php';

function resp_ping($ok) {
    echo json_encode(['ok' => (bool)$ok], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$chave = trim($body['chave'] ?? '');
$fp    = trim($body['fingerprint'] ?? '');

if ($fp === '') {
    resp_ping(false);
}

$maqNome    = mb_substr(trim($body['maq_nome'] ?? ''), 0, 120);
$maqUsuario = mb_substr(trim($body['maq_usuario'] ?? ''), 0, 120);
$maqSO      = mb_substr(trim($body['maq_so'] ?? ''), 0, 120);
$ip         = $_SERVER['REMOTE_ADDR'] ?? null;

// origem: so aceita valores conhecidos, para o relatorio nao encher de
// variacao digitada errada. Desconhecido vira NULL.
$origem = strtolower(trim($body['origem'] ?? ''));
if (!in_array($origem, ['licenca', 'dongle', 'tslpr'], true)) {
    $origem = null;
}

$tipo = strtolower(trim($body['tipo'] ?? 'abertura'));
if (!in_array($tipo, ['abertura', 'presenca', 'fechamento'], true)) {
    $tipo = 'abertura';
}

// total de pesagens do mes corrente na base do cliente.
// -1 = nao informado (cliente antigo ou consulta local falhou).
$pesagensMes = isset($body['pesagens_mes'])
             ? (int)$body['pesagens_mes'] : -1;

try {
    // resolve licenca/cliente pela chave (se veio)
    $licId = null; $cliId = null;
    if ($chave !== '') {
        $st = db()->prepare(
            'SELECT id, cliente_id FROM licencas WHERE chave = ? LIMIT 1');
        $st->execute([$chave]);
        if ($row = $st->fetch()) {
            $licId = $row['id'];
            $cliId = $row['cliente_id'];
        }
    }

    // So a abertura conta como "uso": presenca e fechamento apenas
    // atualizam o ultimo acesso.
    $incremento = ($tipo === 'abertura') ? 1 : 0;

    // upsert por fingerprint: cria a maquina ou atualiza o acesso
    // (MySQL/MariaDB: INSERT ... ON DUPLICATE KEY UPDATE)
    $st = db()->prepare(
        'INSERT INTO maquinas
           (fingerprint, licenca_id, cliente_id, maq_nome, maq_usuario,
            maq_so, origem, primeiro_acesso, ultimo_acesso, aberturas, ip_ultimo)
         VALUES (?,?,?,?,?,?,?, NOW(), NOW(), ?, ?)
         ON DUPLICATE KEY UPDATE
           licenca_id  = COALESCE(VALUES(licenca_id), licenca_id),
           cliente_id  = COALESCE(VALUES(cliente_id), cliente_id),
           maq_nome    = VALUES(maq_nome),
           maq_usuario = VALUES(maq_usuario),
           maq_so      = VALUES(maq_so),
           origem      = COALESCE(VALUES(origem), origem),
           ultimo_acesso = NOW(),
           aberturas   = aberturas + ?,
           ip_ultimo   = VALUES(ip_ultimo)');
    $st->execute([$fp, $licId, $cliId, $maqNome, $maqUsuario, $maqSO,
                  $origem, $incremento, $ip, $incremento]);

    // historico evento a evento: e daqui que saem os relatorios de uso
    $st = db()->prepare(
        'INSERT INTO acessos (fingerprint, licenca_id, cliente_id, tipo, ts, ip)
         VALUES (?,?,?,?, NOW(), ?)');
    $st->execute([$fp, $licId, $cliId, $tipo, $ip]);

    /* -----------------------------------------------------------------
     *  volume de pesagens do mes
     * -----------------------------------------------------------------
     *  GREATEST em vez de soma: o cliente reporta o TOTAL ACUMULADO do
     *  mes a cada ping. Somar multiplicaria o numero por quantos pings
     *  chegaram. Guardamos o maior valor ja visto no mes.
     *
     *  O maior tambem protege contra um ping fora de ordem chegando com
     *  numero menor (rede lenta, relogio do cliente atrasado).
     * ----------------------------------------------------------------- */
    if ($pesagensMes >= 0 && $cliId !== null) {
        $prod = null;
        if ($licId !== null) {
            $stP = db()->prepare(
              'SELECT p.codigo FROM licencas l
                 JOIN produtos p ON p.id = l.produto_id
                WHERE l.id = ?');
            $stP->execute([$licId]);
            $prod = $stP->fetchColumn() ?: null;
        }

        $st = db()->prepare(
            'INSERT INTO pesagens_mensal
               (cliente_id, ano_mes, fingerprint, pesagens, licenca_id, produto)
             VALUES (?, DATE_FORMAT(NOW(), "%Y-%m"), ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               pesagens   = GREATEST(pesagens, VALUES(pesagens)),
               licenca_id = COALESCE(VALUES(licenca_id), licenca_id),
               produto    = COALESCE(VALUES(produto), produto)');
        $st->execute([$cliId, $fp, $pesagensMes, $licId, $prod]);
    }

    resp_ping(true);
} catch (Throwable $e) {
    // silencioso: nunca atrapalha o cliente
    resp_ping(false);
}
