<?php
/**
 * =====================================================================
 *  Aviso de vencimento de licencas - rodar 1x por dia no cron
 * =====================================================================
 *      0 8 * * * /usr/bin/php /var/www/licenca/cron/avisar_vencimentos.php \
 *                  >> /var/log/avisos_licenca.log 2>&1
 *
 *  O QUE FAZ
 *    1) procura licencas ativas que cruzam um marco (30, 15, 7, 0 dias
 *       ou ja vencidas ha 1 dia)
 *    2) monta UM resumo para o admin com tudo
 *    3) monta um resumo por revendedor, so com as licencas dele
 *    4) registra o envio em `avisos_vencimento` para nao repetir
 *
 *  POR QUE RESUMO E NAO UM E-MAIL POR LICENCA
 *  Um cliente com 5 licencas vencendo no mesmo dia geraria 5 e-mails.
 *  Resumo diario e lido; enxurrada e ignorada - e cair no spam quebra
 *  justamente o aviso que importa.
 *
 *  IDEMPOTENTE: pode rodar duas vezes no mesmo dia sem enviar repetido.
 *  Se a licenca for RENOVADA, a data muda e os avisos do ciclo novo
 *  voltam a valer (a chave unica inclui expira_em).
 *
 *  MODO DE TESTE (nao envia, so mostra):
 *      php cron/avisar_vencimentos.php --simular
 * =====================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script roda apenas pela linha de comando.\n");
}

require_once __DIR__ . '/../api/lib/licenca.php';
require_once __DIR__ . '/../api/lib/config_db.php';
require_once __DIR__ . '/../api/lib/smtp.php';

$simular = in_array('--simular', $argv, true);

// marcos configuraveis na tela de Configuracoes do painel
$MARCOS = array_values(array_filter(
    array_map('intval', explode(',', cfg('aviso_marcos', '30,15,7,0'))),
    fn($n) => $n >= 0 && $n <= 365));
if (!$MARCOS) $MARCOS = [30, 15, 7, 0];
$MARCOS[] = -1;   // -1 = venceu ontem, sempre avisa

function log_linha(string $t): void {
    echo '[' . date('Y-m-d H:i:s') . "] $t\n";
}

if (cfg('aviso_ativo', '1') !== '1' && !$simular) {
    log_linha('Avisos desligados em Configuracoes. Nada a fazer.');
    exit(0);
}

/* ---------------------------------------------------------------------
 *  1) levanta as licencas que cruzam um marco hoje
 * ------------------------------------------------------------------- */
$marcosSql = implode(',', $MARCOS);
$st = db()->prepare(
  "SELECT l.id, l.chave, l.expira_em, l.revendedor_id,
          DATEDIFF(l.expira_em, CURDATE()) AS dias,
          c.razao_social, c.nome_fantasia, c.cnpj,
          p.codigo AS produto, t.nome AS tier,
          u.nome AS rev_nome, u.email AS rev_email,
          u.empresa AS rev_empresa, u.nome_fantasia AS rev_fantasia,
          m.maq_nome, m.ultimo_acesso
     FROM licencas l
     LEFT JOIN clientes c ON c.id = l.cliente_id
     LEFT JOIN produtos p ON p.id = l.produto_id
     LEFT JOIN tiers    t ON t.id = l.tier_id
     LEFT JOIN usuarios u ON u.id = l.revendedor_id
     LEFT JOIN maquinas m ON m.fingerprint = l.fingerprint
    WHERE l.status IN ('ativa','nova')
      AND l.tipo_licenca = 'venda'
      AND l.cliente_id IS NOT NULL
      AND DATEDIFF(l.expira_em, CURDATE()) IN ($marcosSql)
    ORDER BY l.expira_em, c.razao_social");
$st->execute();
$linhas = $st->fetchAll();

if (!$linhas) { log_linha('Nenhuma licenca em marco de aviso hoje.'); exit(0); }

/* ---------------------------------------------------------------------
 *  2) descarta o que ja foi avisado neste ciclo
 * ------------------------------------------------------------------- */
$stJa = db()->prepare(
  'SELECT 1 FROM avisos_vencimento
    WHERE licenca_id=? AND marco=? AND expira_em=? LIMIT 1');

$pendentes = [];
foreach ($linhas as $l) {
    $marco = (string)(int)$l['dias'];
    if ((int)$l['dias'] < 0) $marco = 'vencida';
    $stJa->execute([$l['id'], $marco, $l['expira_em']]);
    if ($stJa->fetchColumn()) continue;
    $l['_marco'] = $marco;
    $pendentes[] = $l;
}

if (!$pendentes) { log_linha('Tudo ja avisado neste ciclo.'); exit(0); }
log_linha(count($pendentes) . ' licenca(s) a avisar.');

/* ---------------------------------------------------------------------
 *  3) monta o HTML do resumo
 * ------------------------------------------------------------------- */
function tabela_html(array $itens, bool $paraRevendedor = false): string {
    $h = '<table style="border-collapse:collapse;font-family:Arial,sans-serif;'
       . 'font-size:13px;width:100%">'
       . '<tr style="background:#f0a92b;color:#14171a">'
       . '<th style="padding:8px;text-align:left">Cliente</th>'
       . '<th style="padding:8px;text-align:left">Software</th>'
       . '<th style="padding:8px;text-align:left">Chave</th>'
       . '<th style="padding:8px;text-align:left">Vence</th>'
       . '<th style="padding:8px;text-align:left">Situação</th>';
    if (!$paraRevendedor) $h .= '<th style="padding:8px;text-align:left">Origem</th>';
    $h .= '</tr>';

    foreach ($itens as $i => $l) {
        $dias = (int)$l['dias'];
        if ($dias < 0)      { $sit = 'VENCIDA';            $cor = '#e0574e'; }
        elseif ($dias === 0){ $sit = 'vence hoje';         $cor = '#e0574e'; }
        elseif ($dias <= 7) { $sit = "em $dias dias";      $cor = '#e0574e'; }
        elseif ($dias <= 15){ $sit = "em $dias dias";      $cor = '#f0a92b'; }
        else                { $sit = "em $dias dias";      $cor = '#93a1ac'; }

        $fundo = $i % 2 ? '#f7f9fa' : '#ffffff';
        $cli   = htmlspecialchars($l['nome_fantasia'] ?: ($l['razao_social'] ?: '—'));
        $soft  = htmlspecialchars(strtoupper($l['produto'] ?? '—')
                 . ($l['tier'] ? ' · ' . $l['tier'] : ''));
        $rev   = $l['revendedor_id']
               ? htmlspecialchars($l['rev_fantasia'] ?: ($l['rev_empresa'] ?: $l['rev_nome']))
               : 'venda direta';

        $h .= "<tr style=\"background:$fundo\">"
            . '<td style="padding:8px">' . $cli . '</td>'
            . '<td style="padding:8px">' . $soft . '</td>'
            . '<td style="padding:8px;font-family:monospace;font-size:11px">'
            . htmlspecialchars($l['chave']) . '</td>'
            . '<td style="padding:8px">' . date('d/m/Y', strtotime($l['expira_em'])) . '</td>'
            . '<td style="padding:8px;color:' . $cor . ';font-weight:bold">' . $sit . '</td>';
        if (!$paraRevendedor) $h .= '<td style="padding:8px">' . $rev . '</td>';
        $h .= '</tr>';
    }
    return $h . '</table>';
}

function corpo_html(string $titulo, string $intro, array $itens, bool $rev): string {
    return '<div style="font-family:Arial,sans-serif;color:#14171a;max-width:800px">'
         . '<h2 style="color:#14171a;margin:0 0 4px">' . $titulo . '</h2>'
         . '<p style="color:#666;font-size:13px;margin:0 0 18px">' . $intro . '</p>'
         . tabela_html($itens, $rev)
         . '<p style="color:#93a1ac;font-size:11px;margin-top:22px">'
         . 'Enviado automaticamente pelo painel de licenças em '
         . date('d/m/Y H:i') . '. Não responda este e-mail.</p></div>';
}

/* ---------------------------------------------------------------------
 *  4) e-mail do admin: tudo
 * ------------------------------------------------------------------- */
$destAdmin = cfg('email_admin') ?: null;
$enviados  = [];   // [licenca_id, marco, expira_em, destino]

if ($destAdmin) {
    $venc = array_filter($pendentes, fn($l) => (int)$l['dias'] < 0);
    $assunto = count($pendentes) . ' licença(s) a vencer'
             . ($venc ? ' — ' . count($venc) . ' já vencida(s)' : '');

    $html = corpo_html(
        'Licenças a vencer',
        'Resumo diário. Abra o painel em Licenças → filtro "Vencimento" '
        . 'para renovar.',
        $pendentes, false);

    if ($simular) {
        log_linha("[simular] admin <$destAdmin>: $assunto");
    } else {
        $erro = null;
        if (smtp_enviar($destAdmin, $assunto, $html, $erro)) {
            log_linha("Admin avisado ($destAdmin): " . count($pendentes) . ' licencas.');
            foreach ($pendentes as $l) {
                $enviados[] = [$l['id'], $l['_marco'], $l['expira_em'], $destAdmin];
            }
        } else {
            log_linha("ERRO ao enviar para o admin: $erro");
        }
    }
} else {
    log_linha('AVISO: e-mail do admin nao definido em Configuracoes - '
            . 'admin nao avisado.');
}

/* ---------------------------------------------------------------------
 *  5) e-mail por revendedor: so o que e dele
 * ------------------------------------------------------------------- */
$porRev = [];
if (cfg('aviso_revendedor', '1') !== '1') {
    log_linha('Aviso a revendedores desligado em Configuracoes.');
}
foreach ($pendentes as $l) {
    if (cfg('aviso_revendedor', '1') !== '1') break;
    if (!empty($l['revendedor_id']) && !empty($l['rev_email'])) {
        $porRev[$l['revendedor_id']][] = $l;
    }
}

foreach ($porRev as $revId => $itens) {
    $email = $itens[0]['rev_email'];
    $nome  = $itens[0]['rev_fantasia'] ?: ($itens[0]['rev_empresa'] ?: $itens[0]['rev_nome']);
    $assunto = count($itens) . ' licença(s) dos seus clientes a vencer';

    $html = corpo_html(
        'Licenças a vencer',
        'Olá, ' . htmlspecialchars($nome) . '. Estas licenças de clientes '
        . 'seus estão perto do vencimento.',
        $itens, true);

    if ($simular) {
        log_linha("[simular] revendedor <$email>: " . count($itens) . ' licencas');
        continue;
    }
    $erro = null;
    if (smtp_enviar($email, $assunto, $html, $erro)) {
        log_linha("Revendedor avisado ($email): " . count($itens) . ' licencas.');
        foreach ($itens as $l) {
            $enviados[] = [$l['id'], $l['_marco'], $l['expira_em'], $email];
        }
    } else {
        log_linha("ERRO ao enviar para $email: $erro");
    }
}

/* ---------------------------------------------------------------------
 *  6) registra o que saiu
 *     INSERT IGNORE porque a mesma licenca pode ter ido para o admin e
 *     para o revendedor - a chave unica cuida do resto
 * ------------------------------------------------------------------- */
if ($enviados && !$simular) {
    $ins = db()->prepare(
      'INSERT IGNORE INTO avisos_vencimento
         (licenca_id, marco, expira_em, destino) VALUES (?,?,?,?)');
    foreach ($enviados as $e) $ins->execute($e);
    log_linha(count($enviados) . ' registro(s) de aviso gravado(s).');
}

log_linha('Concluido.');
