<?php
/**
 * =====================================================================
 *  API - Ativacao ONLINE  (v2: multi-produto)
 * =====================================================================
 *  O Delphi faz POST aqui com:  { "chave": "...", "fingerprint": "...",
 *                                 "cadastro": { ... } }
 *
 *  O bloco "cadastro" so vem quando a licenca ainda nao tem cliente -
 *  o caso do revendedor que repassa a chave sem usar o painel. O
 *  cliente final preenche os dados no proprio Total Scale e o servidor
 *  cria o cadastro ja vinculado a licenca e ao revendedor que vendeu.
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
           LEFT JOIN clientes c ON c.id = l.cliente_id
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


    /* =================================================================
     *  AUTOCADASTRO DO CLIENTE FINAL
     * =================================================================
     *  Licenca sem cliente = repassada pelo revendedor sem passar pelo
     *  painel. O Total Scale exige o registro da empresa antes de
     *  ativar, e e aqui que ele chega.
     * ================================================================= */
    if (empty($lic['cliente_id'])) {
        $cad = $body['cadastro'] ?? null;

        // sem os dados, devolve o pedido em vez de ativar. O Delphi
        // reconhece 'precisa_cadastro' e abre o formulario.
        if (!is_array($cad) || trim($cad['cnpj'] ?? '') === '') {
            log_acao($lic['id'], $chave, $fp, 'ativar_online', 'negado',
                     'aguardando cadastro do cliente final');
            responde([
                'ok' => false,
                'precisa_cadastro' => true,
                'erro' => 'Esta licenca ainda nao esta vinculada a uma empresa. '
                        . 'Informe os dados para concluir a ativacao.'
            ], 409);
        }

        $cnpj = preg_replace('/\D/', '', $cad['cnpj']);
        if (strlen($cnpj) !== 14 || !cnpj_dv_valido($cnpj)) {
            responde(['ok' => false, 'precisa_cadastro' => true,
                      'erro' => 'CNPJ invalido. Confira os numeros digitados.'], 400);
        }

        $razao    = mb_substr(trim($cad['razao_social'] ?? ''), 0, 160);
        $contato  = mb_substr(trim($cad['contato']  ?? ''), 0, 120);
        $telefone = mb_substr(trim($cad['telefone'] ?? ''), 0, 40);
        $email    = mb_strtolower(mb_substr(trim($cad['email'] ?? ''), 0, 160));
        $munic    = mb_substr(trim($cad['municipio'] ?? ''), 0, 120);
        $uf       = strtoupper(mb_substr(trim($cad['uf'] ?? ''), 0, 2));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

        /* --- confere na Receita ---------------------------------------
         * A razao social vem de la, nao do que o cliente digitou. Se o
         * servico estiver fora, ACEITA o que veio e marca para
         * conferir: o cliente nao pode ficar parado por um servico
         * que nao e nosso.
         * ------------------------------------------------------------- */
        $receitaOk = false;
        $dadosReceita = consultar_receita($cnpj);
        if ($dadosReceita) {
            $receitaOk = true;
            $razao = $dadosReceita['razao_social'] ?: $razao;
            if ($munic === '') $munic = $dadosReceita['municipio'] ?? '';
            if ($uf    === '') $uf    = $dadosReceita['uf'] ?? '';
        }
        if ($razao === '') $razao = 'CNPJ ' . $cnpj;

        /* --- ja existe cliente com este CNPJ? -------------------------
         * Reaproveita sempre, nunca duplica. Mas se o cadastro existente
         * pertence a OUTRO revendedor - ou era venda direta - marca
         * como conflito para voce decidir. Reatribuir sozinho seria
         * mexer na carteira de alguem sem avisar.
         * ------------------------------------------------------------- */
        $stC = db()->prepare(
          "SELECT id, razao_social, revendedor_id
             FROM clientes
            WHERE REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','') = ?
            LIMIT 1");
        $stC->execute([$cnpj]);
        $existente = $stC->fetch();

        $revLic = $lic['revendedor_id'] ?? null;
        $obs = '';

        if ($existente) {
            $cliId = (int)$existente['id'];
            $revAnterior = $existente['revendedor_id'] ?? null;

            if ((int)$revAnterior !== (int)$revLic) {
                $resultado = 'conflito';
                $obs = $revAnterior
                     ? 'cadastro pertencia a outro revendedor (id ' . $revAnterior . ')'
                     : 'cadastro era de venda direta';
            } else {
                $resultado = 'reaproveitado';
            }

            // completa o que estiver em branco, sem sobrescrever o que
            // ja foi conferido por voce
            db()->prepare(
              'UPDATE clientes
                  SET telefone = COALESCE(NULLIF(telefone,""), ?),
                      email    = COALESCE(NULLIF(email,""), ?),
                      municipio= COALESCE(NULLIF(municipio,""), ?),
                      uf       = COALESCE(NULLIF(uf,""), ?)
                WHERE id = ?')
              ->execute([$telefone ?: null, $email ?: null,
                         $munic ?: null, $uf ?: null, $cliId]);
        } else {
            $resultado = 'criado';
            db()->prepare(
              'INSERT INTO clientes
                 (razao_social, cnpj, contato, telefone, email, municipio, uf,
                  revendedor_id, origem_cadastro, conferido, dados_receita,
                  autocadastro_em)
               VALUES (?,?,?,?,?,?,?,?,"autocadastro",0,?,NOW())')
              ->execute([$razao, $cnpj, ($contato ?: null), ($telefone ?: null),
                         ($email ?: null), ($munic ?: null), ($uf ?: null),
                         $revLic, $receitaOk ? 1 : 0]);
            $cliId = (int)db()->lastInsertId();

            // contato principal, para a licenca chegar por e-mail
            if ($contato !== '' || $email !== '' || $telefone !== '') {
                try {
                    db()->prepare(
                      'INSERT INTO cliente_contatos
                         (cliente_id, nome, telefone, email, principal)
                       VALUES (?,?,?,?,1)')
                      ->execute([$cliId, ($contato ?: 'Contato'),
                                 ($telefone ?: null), ($email ?: null)]);
                } catch (Throwable $e) { /* tabela pode nao existir */ }
            }
        }

        // vincula a licenca ao cliente
        db()->prepare('UPDATE licencas SET cliente_id=? WHERE id=?')
            ->execute([$cliId, $lic['id']]);

        // registra na fila de conferencia SEMPRE - inclusive quando o
        // cadastro ja existia. Sem isto, um CNPJ conhecido entraria em
        // silencio e voce nunca saberia que ele passou a comprar de
        // revenda.
        db()->prepare(
          'INSERT INTO autocadastros
             (licenca_id, cliente_id, revendedor_id, cnpj_informado,
              razao_informada, contato_informado, telefone_informado,
              email_informado, municipio_informado, uf_informada,
              resultado, receita_ok, observacao, fingerprint, ip)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$lic['id'], $cliId, $revLic, $cnpj,
                     mb_substr(trim($cad['razao_social'] ?? ''), 0, 160),
                     $contato, $telefone, $email, $munic, $uf,
                     $resultado, $receitaOk ? 1 : 0, ($obs ?: null),
                     $fp, $_SERVER['REMOTE_ADDR'] ?? null]);

        log_acao($lic['id'], $chave, $fp, 'autocadastro', 'ok',
                 $resultado . ': ' . $razao . ' (' . $cnpj . ')');

        // recarrega para a assinatura sair com o nome certo
        $lic['cliente_id']   = $cliId;
        $lic['razao_social'] = $razao;
        $lic['cnpj']         = $cnpj;
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
