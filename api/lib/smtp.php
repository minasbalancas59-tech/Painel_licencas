<?php
/**
 * =====================================================================
 *  Envio de e-mail por SMTP - implementacao minima, sem dependencias
 * =====================================================================
 *  Escrito a mao de proposito: instalar Composer/PHPMailer na VPS so
 *  para mandar um aviso por dia adiciona atualizacoes e superficie de
 *  ataque que este projeto nao precisa.
 *
 *  Suporta STARTTLS (porta 587) e SSL direto (porta 465), que cobrem
 *  Gmail, Zoho, Outlook e praticamente todo provedor transacional.
 *
 *  Configuracao: tela Configuracoes do painel (tabela `configuracoes`).
 *  Se estiver vazia la, cai nas constantes do config.php - assim quem
 *  ja tinha configurado no arquivo continua funcionando.
 * =====================================================================
 */

require_once __DIR__ . '/config_db.php';

function smtp_enviar(string $para, string $assunto, string $htmlCorpo,
                     string &$erro = null): bool
{
    $cHost  = cfg('smtp_host');
    $cPorta = (int)cfg('smtp_porta', 587);
    $cUser  = cfg('smtp_usuario');
    $cPass  = cfg('smtp_senha');
    $cDe    = cfg('smtp_de') ?: $cUser;
    $cNome  = cfg('smtp_de_nome', 'Painel de Licenças');

    if ($cHost === '' || $cUser === '' || $cPass === '') {
        $erro = 'Servidor de e-mail nao configurado. '
              . 'Preencha em Configuracoes no painel.';
        return false;
    }

    $host = $cHost;
    $port = $cPorta;
    $ssl  = ($port === 465);

    $destino = ($ssl ? 'ssl://' : '') . $host;
    $fp = @stream_socket_client("$destino:$port", $en, $es, 20);
    if (!$fp) { $erro = "Conexao SMTP falhou: $es ($en)"; return false; }
    stream_set_timeout($fp, 20);

    $lerResposta = function () use ($fp) {
        $r = '';
        while ($linha = fgets($fp, 515)) {
            $r .= $linha;
            if (isset($linha[3]) && $linha[3] === ' ') break;
        }
        return $r;
    };
    $cmd = function ($c, $esperado) use ($fp, $lerResposta, &$erro) {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $r = $lerResposta();
        if ((int)substr($r, 0, 3) !== $esperado) {
            $erro = 'SMTP: ' . trim($r) . ($c ? " (apos: " . substr($c,0,20) . ")" : '');
            return false;
        }
        return true;
    };

    $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');

    if (!$cmd(null, 220))  { fclose($fp); return false; }
    if (!$cmd($ehlo, 250)) { fclose($fp); return false; }

    if (!$ssl) {
        if (!$cmd('STARTTLS', 220)) { fclose($fp); return false; }
        if (!stream_socket_enable_crypto($fp, true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $erro = 'Falha ao ativar TLS'; fclose($fp); return false;
        }
        if (!$cmd($ehlo, 250)) { fclose($fp); return false; }
    }

    if (!$cmd('AUTH LOGIN', 334))                { fclose($fp); return false; }
    if (!$cmd(base64_encode($cUser), 334))       { fclose($fp); return false; }
    if (!$cmd(base64_encode($cPass), 235))       { fclose($fp); return false; }
    if (!$cmd('MAIL FROM:<' . $cDe . '>', 250))  { fclose($fp); return false; }
    if (!$cmd('RCPT TO:<' . $para . '>', 250))      { fclose($fp); return false; }
    if (!$cmd('DATA', 354))                         { fclose($fp); return false; }

    $cabecalho =
        'From: =?UTF-8?B?' . base64_encode($cNome) . '?= <' . $cDe . ">\r\n" .
        'To: <' . $para . ">\r\n" .
        'Subject: =?UTF-8?B?' . base64_encode($assunto) . "?=\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: text/html; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: base64\r\n" .
        'Date: ' . date('r') . "\r\n\r\n";

    $corpo = chunk_split(base64_encode($htmlCorpo));
    // linha que comeca com ponto encerraria o DATA antes da hora
    $corpo = str_replace("\n.", "\n..", $corpo);

    fwrite($fp, $cabecalho . $corpo . "\r\n.\r\n");
    if (!$cmd(null, 250)) { fclose($fp); return false; }

    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}
