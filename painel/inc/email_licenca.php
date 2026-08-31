<?php
/**
 * =====================================================================
 *  Envio da licença por e-mail
 * =====================================================================
 *  Manda a chave para o cliente no momento da emissão.
 *
 *  POR QUE ISTO EXISTE
 *  A chave chega por WhatsApp e some na conversa. Quando o PC do
 *  cliente morre, ele liga sem ter a chave em lugar nenhum — e alguém
 *  precisa garimpar no painel para descobrir qual licença é dele.
 *  Com o e-mail, a chave fica na caixa de entrada dele para sempre.
 *
 *  Falha em silêncio de propósito: se o SMTP não estiver configurado
 *  ou o cliente não tiver e-mail, a emissão não pode ser interrompida
 *  por causa disso. O retorno diz o que aconteceu, para a tela avisar.
 * =====================================================================
 */

require_once __DIR__ . '/../../api/lib/smtp.php';

/**
 * Destinatários de um cliente: o contato principal primeiro, depois os
 * demais que tenham e-mail. Devolve lista vazia se não houver nenhum.
 */
function emails_do_cliente(int $clienteId): array {
    $out = [];
    try {
        $st = db()->prepare(
          'SELECT email FROM cliente_contatos
            WHERE cliente_id = ? AND email IS NOT NULL AND email <> ""
            ORDER BY principal DESC, id');
        $st->execute([$clienteId]);
        $out = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { /* tabela ainda nao criada */ }

    if (!$out) {
        // cadastro antigo, antes da tabela de contatos
        $st = db()->prepare('SELECT email FROM clientes WHERE id = ?');
        $st->execute([$clienteId]);
        $e = trim((string)$st->fetchColumn());
        if ($e !== '') $out = [$e];
    }
    return array_values(array_unique(array_filter($out,
        function ($e) { return filter_var($e, FILTER_VALIDATE_EMAIL); })));
}

/**
 * Envia a licença. Retorna [enviados, mensagem].
 *
 * @param array $lic linha de licencas com os JOINs resolvidos
 */
function enviar_licenca_email(array $lic): array {
    if (empty($lic['cliente_id']))
        return [0, 'Licença sem cliente: nada a enviar.'];

    $dest = emails_do_cliente((int)$lic['cliente_id']);
    if (!$dest)
        return [0, 'Cliente sem e-mail cadastrado.'];

    $produto = strtoupper($lic['produto_codigo'] ?? '');
    $nomeProd = $produto === 'TS5'    ? 'Total Scale 5'
              : ($produto === 'TS6'   ? 'Total Scale 6'
              : ($produto === 'TSLPR' ? 'TS LPR' : 'Total Scale'));

    $cliente = $lic['nome_fantasia'] ?? ($lic['cli_fantasia'] ?? null);
    if (!$cliente) $cliente = $lic['razao_social'] ?? '';

    $assunto = 'Sua licenca do ' . $nomeProd . ' - ' . $lic['chave'];

    $html =
      '<div style="font-family:Arial,sans-serif;color:#14171a;max-width:620px">'
    . '<h2 style="margin:0 0 4px">Licença do ' . htmlspecialchars($nomeProd) . '</h2>'
    . '<p style="color:#666;font-size:13px;margin:0 0 20px">'
    . htmlspecialchars($cliente) . '</p>'

    . '<div style="background:#f7f9fa;border-left:3px solid #f0a92b;'
    . 'padding:14px 18px;margin-bottom:20px">'
    . '<div style="font-size:12px;color:#666;margin-bottom:4px">'
    . 'CHAVE DE REGISTRO</div>'
    . '<div style="font-family:monospace;font-size:20px;font-weight:bold;'
    . 'letter-spacing:1px">' . htmlspecialchars($lic['chave']) . '</div>'
    . '</div>'

    . '<p style="font-size:14px"><b>Guarde este e-mail.</b> Se precisar '
    . 'reinstalar o sistema ou trocar de computador, esta chave será '
    . 'solicitada.</p>'

    . '<h3 style="font-size:15px;margin:24px 0 8px">Como ativar</h3>'
    . '<ol style="font-size:14px;line-height:1.7;padding-left:20px;margin:0">'
    . '<li>Abra o ' . htmlspecialchars($nomeProd) . '</li>'
    . '<li>Na tela de registro, cole a chave acima em "Ativação online"</li>'
    . '<li>Clique em <b>Ativar online</b></li>'
    . '</ol>'

    . '<p style="font-size:13px;color:#666;margin-top:20px">'
    . 'A chave vale para <b>um computador</b>. Ao ativar, ela fica '
    . 'vinculada àquela máquina. Se precisar trocar de PC ou formatar, '
    . 'avise-nos <b>antes</b> — precisamos liberar a licença para que ela '
    . 'funcione na máquina nova.</p>'

    . '<p style="font-size:13px;color:#666">'
    . '<b>Sem internet na máquina?</b> Na mesma tela, clique em "Copiar '
    . 'código", envie o código para nós e devolvemos o código de ativação '
    . 'offline.</p>'

    . '<p style="color:#93a1ac;font-size:11px;margin-top:24px;'
    . 'border-top:1px solid #e0e0e0;padding-top:12px">'
    . 'Enviado automaticamente pelo sistema de licenciamento em '
    . date('d/m/Y H:i') . '. Em caso de dúvida, fale com o suporte.</p>'
    . '</div>';

    $ok = 0; $erros = [];
    foreach ($dest as $e) {
        $err = null;
        if (smtp_enviar($e, $assunto, $html, $err)) $ok++;
        else $erros[] = $e . ': ' . $err;
    }

    if ($ok === count($dest))
        return [$ok, 'Chave enviada para ' . implode(', ', $dest) . '.'];
    if ($ok > 0)
        return [$ok, "Enviado para $ok de " . count($dest) . ' destinatários.'];
    return [0, 'Falha no envio. ' . implode(' | ', $erros)];
}
