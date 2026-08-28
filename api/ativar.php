<?php
/**
 * =====================================================================
 *  API - Ativacao ONLINE  (v2: multi-produto)
 * =====================================================================
 *  O Delphi faz POST aqui com:  { "chave": "...", "fingerprint": "..." }
 *  Retorna JSON:
 *     sucesso: { "ok": true, "licenca": "<base64 assinada>",
 *                "produto": "...", "tier": "...", "nivel": N,
 *                "expira": "...", "carencia": N }
 *     falha:   { "ok": false, "erro": "mensagem" }
 * =====================================================================
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib/licenca.php';

function responde(array $r, int $http = 200) {
    http_response_code($http);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- le a requisicao -------------------------------------------------
$body = json_decode(file_get_contents('php://input'), true);
$chave = trim($body['chave'] ?? '');
$fp    = trim($body['fingerprint'] ?? '');

if ($chave === '' || $fp === '') {
    log_acao(null, $chave, $fp, 'ativar_online', 'erro', 'campos vazios');
    responde(['ok' => false, 'erro' => 'Chave e identificacao da maquina sao obrigatorias.'], 400);
}

try {
    // --- busca a licenca (com produto/tier via JOIN) -----------------
    $st = db()->prepare(
        'SELECT l.*, c.razao_social, c.cnpj,
                p.codigo AS produto_codigo,
                t.codigo AS tier_codigo, t.nivel AS tier_nivel
           FROM licencas l
           JOIN clientes c ON c.id = l.cliente_id
           LEFT JOIN produtos p ON p.id = l.produto_id
           LEFT JOIN tiers    t ON t.id = l.tier_id
          WHERE l.chave = ? LIMIT 1');
    $st->execute([$chave]);
    $lic = $st->fetch();

    if (!$lic) {
        log_acao(null, $chave, $fp, 'ativar_online', 'negado', 'chave inexistente');
        responde(['ok' => false, 'erro' => 'Chave de licenca invalida.'], 404);
    }

    // --- checa status ------------------------------------------------
    if ($lic['status'] === 'revogada') {
        log_acao($lic['id'], $chave, $fp, 'ativar_online', 'negado', 'revogada');
        responde(['ok' => false, 'erro' => 'Esta licenca foi revogada.'], 403);
    }

    // --- checa validade ----------------------------------------------
    if (strtotime($lic['expira_em']) < strtotime(date('Y-m-d'))) {
        db()->prepare('UPDATE licencas SET status="expirada" WHERE id=?')
            ->execute([$lic['id']]);
        log_acao($lic['id'], $chave, $fp, 'ativar_online', 'negado', 'expirada');
        responde(['ok' => false, 'erro' => 'Esta licenca esta expirada.'], 403);
    }

    // --- checa vinculo de maquina ------------------------------------
    if (!empty($lic['fingerprint']) && $lic['fingerprint'] !== $fp) {
        log_acao($lic['id'], $chave, $fp, 'ativar_online', 'negado',
                 'ja ativada em outra maquina');
        responde(['ok' => false,
                  'erro' => 'Esta licenca ja esta em uso em outra maquina. Contate o suporte.'], 403);
    }

    // --- ativa (grava fingerprint na primeira vez) -------------------
    if (empty($lic['fingerprint'])) {
        db()->prepare(
            'UPDATE licencas
                SET fingerprint=?, status="ativa", tipo_ativacao="online",
                    ativada_em=NOW()
              WHERE id=?')->execute([$fp, $lic['id']]);
    }

    // --- emite a licenca assinada ------------------------------------
    // Se a licenca tem produto/tier (emitida no modo novo), assina v2.
    // Senao, mantem a assinatura v1 original (licencas antigas).
    $carencia = (int)($lic['carencia_dias'] ?? 15);
    if (!empty($lic['produto_codigo']) && !empty($lic['tier_codigo'])) {
        $assinada = emitir_licenca_assinada_v2([
            'cliente'     => $lic['razao_social'],
            'cnpj'        => $lic['cnpj'],
            'chave'       => $lic['chave'],
            'fingerprint' => $fp,
            'produto'     => $lic['produto_codigo'],
            'tier'        => $lic['tier_codigo'],
            'nivel'       => (int)$lic['tier_nivel'],
            'modulos'     => $lic['modulos'],
            'emitido'     => date('Y-m-d'),
            'expira'      => $lic['expira_em'],
            'carencia'    => $carencia,
        ]);
    } else {
        $assinada = emitir_licenca_assinada([
            'cliente'     => $lic['razao_social'],
            'cnpj'        => $lic['cnpj'],
            'chave'       => $lic['chave'],
            'fingerprint' => $fp,
            'modulos'     => $lic['modulos'],
            'emitido'     => date('Y-m-d'),
            'expira'      => $lic['expira_em'],
        ]);
    }

    registra_maquina_ativacao($fp, $lic['id'], $lic['cliente_id']);
    log_acao($lic['id'], $chave, $fp, 'ativar_online', 'ok',
             trim(($lic['produto_codigo'] ?? '').' '.($lic['tier_codigo'] ?? '')));
    responde([
        'ok'       => true,
        'licenca'  => $assinada,
        'cliente'  => $lic['razao_social'],
        'produto'  => $lic['produto_codigo'],
        'tier'     => $lic['tier_codigo'],
        'nivel'    => isset($lic['tier_nivel']) ? (int)$lic['tier_nivel'] : null,
        'expira'   => $lic['expira_em'],
        'carencia' => $carencia,
        'modulos'  => $lic['modulos'],
    ]);

} catch (Throwable $e) {
    log_acao(null, $chave, $fp, 'ativar_online', 'erro', $e->getMessage());
    responde(['ok' => false, 'erro' => 'Erro interno. Tente novamente mais tarde.'], 500);
}

/**
 * Cria/atualiza a maquina no momento da ativacao.
 * A ativacao so recebe { chave, fingerprint }, entao maq_nome/usuario/so
 * ficam NULL e sao preenchidos depois pelo ping.php. Os COALESCE abaixo
 * garantem que uma ativacao NUNCA apague o que os pings ja coletaram.
 */
function registra_maquina_ativacao($fp, $licId, $cliId, $origem = 'licenca')
{
    $fp = trim((string)$fp);
    if ($fp === '' || preg_match('/^TS[0-9A-Z]{2}-/i', $fp)) {
        return false;   // vazio, ou uma CHAVE colada no campo errado
    }
    try {
        $st = db()->prepare(
            'INSERT INTO maquinas
               (fingerprint, licenca_id, cliente_id, maq_nome, maq_usuario,
                maq_so, origem, primeiro_acesso, ultimo_acesso, aberturas,
                ip_ultimo)
             VALUES (?,?,?, NULL, NULL, NULL, ?, NOW(), NOW(), 0, ?)
             ON DUPLICATE KEY UPDATE
               licenca_id    = COALESCE(VALUES(licenca_id), licenca_id),
               cliente_id    = COALESCE(VALUES(cliente_id), cliente_id),
               maq_nome      = COALESCE(maq_nome,    VALUES(maq_nome)),
               maq_usuario   = COALESCE(maq_usuario, VALUES(maq_usuario)),
               maq_so        = COALESCE(maq_so,      VALUES(maq_so)),
               origem        = COALESCE(VALUES(origem), origem),
               ultimo_acesso = NOW(),
               ip_ultimo     = VALUES(ip_ultimo)');
        $st->execute([$fp, $licId, $cliId, $origem,
                      $_SERVER['REMOTE_ADDR'] ?? null]);
        return true;
    } catch (Throwable $e) {
        return false;   // nunca deixa o registro derrubar uma ativacao valida
    }
}
