<?php
/**
 * =====================================================================
 *  Download do instalador
 * =====================================================================
 *  SEM LOGIN de propósito: o revendedor e o técnico de campo não têm
 *  conta no painel, e exigir uma transformaria "baixe aqui" em "peça
 *  acesso, espere, depois baixe".
 *
 *  A proteção é o token: 20 caracteres aleatórios, impossível de
 *  adivinhar, e revogável um a um se vazar.
 *
 *  DUAS FORMAS DE CHAMAR
 *    baixar.php?p=TOKEN   entrega a versão ATUAL daquele software
 *    baixar.php?t=TOKEN   entrega uma versão específica
 *
 *  O primeiro é o link que você divulga: publicou versão nova, quem
 *  tem o link antigo já baixa a nova.
 *
 *  O arquivo é lido de /var/licenca_arquivos — fora do webroot, então
 *  ninguém chega nele pela URL direta.
 * =====================================================================
 */

require_once __DIR__ . '/../api/lib/licenca.php';

const DIR_ARQ = '/var/licenca_arquivos/instaladores';

function recusa(string $motivo, int $http = 404): void {
    http_response_code($http);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Download indisponível</title></head>'
       . '<body style="font-family:system-ui,sans-serif;max-width:520px;'
       . 'margin:80px auto;padding:0 20px;color:#1C2126;line-height:1.7">'
       . '<h1 style="font-size:22px;margin:0 0 8px">Download indisponível</h1>'
       . '<p style="color:#5F5E5A">' . htmlspecialchars($motivo) . '</p>'
       . '<p style="color:#5F5E5A;font-size:14px;margin-top:24px">'
       . 'Peça um link atualizado ao suporte.<br>'
       . '<b>Total Scale</b> · (31) 3357-4000</p>'
       . '</body></html>';
    exit;
}

$tokenProduto = preg_replace('/[^a-f0-9]/i', '', $_GET['p'] ?? '');
$tokenVersao  = preg_replace('/[^a-f0-9]/i', '', $_GET['t'] ?? '');

if ($tokenProduto === '' && $tokenVersao === '')
    recusa('Link inválido.', 400);

try {
    if ($tokenVersao !== '') {
        // versão específica
        $st = db()->prepare(
          'SELECT v.*, p.nome AS produto_nome
             FROM versoes v JOIN produtos p ON p.id = v.produto_id
            WHERE v.token = ? AND v.publicada = 1 LIMIT 1');
        $st->execute([$tokenVersao]);
        $origem = 'versao';
    } else {
        // versão atual do produto
        $st = db()->prepare(
          'SELECT v.*, p.nome AS produto_nome
             FROM versoes v JOIN produtos p ON p.id = v.produto_id
            WHERE p.token_download = ? AND v.atual = 1 AND v.publicada = 1
            LIMIT 1');
        $st->execute([$tokenProduto]);
        $origem = 'produto';
    }
    $v = $st->fetch();
} catch (Throwable $e) {
    recusa('Serviço indisponível no momento.', 503);
}

if (!$v)
    recusa('Este link não corresponde a nenhuma versão publicada.');

$caminho = DIR_ARQ . '/' . basename($v['arquivo']);
if (!is_file($caminho))
    // banco e disco fora de sincronia: alguém apagou o arquivo à mão
    recusa('O arquivo desta versão não está mais disponível.', 410);

/* --- registra o download --------------------------------------------
 * Serve para saber se o parque atualizou depois de uma correção
 * importante. Falha aqui não impede o download.
 * ------------------------------------------------------------------- */
try {
    db()->prepare(
      'INSERT INTO downloads_log (versao_id, ip, user_agent, referencia)
       VALUES (?,?,?,?)')
      ->execute([$v['id'],
                 $_SERVER['REMOTE_ADDR'] ?? null,
                 mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                 $origem]);
    db()->prepare('UPDATE versoes SET downloads = downloads + 1 WHERE id=?')
        ->execute([$v['id']]);
} catch (Throwable $e) { /* silencioso */ }

/* --- entrega ---------------------------------------------------------
 * readfile() em vez de file_get_contents(): não carrega 150 MB na
 * memória do PHP só para repassar ao navegador.
 * ------------------------------------------------------------------- */
$nome = $v['nome_original'] ?: $v['arquivo'];
$nome = preg_replace('/[^\w. -]/', '', $nome);

while (ob_get_level()) ob_end_clean();

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $nome . '"');
header('Content-Length: ' . filesize($caminho));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// download grande não pode morrer no tempo limite do script
set_time_limit(0);
ignore_user_abort(true);

readfile($caminho);
exit;
