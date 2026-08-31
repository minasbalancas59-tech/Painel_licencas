<?php
/**
 * =====================================================================
 *  Manutencao da base - rodar 1x por semana
 * =====================================================================
 *      0 4 * * 0 /usr/bin/php /var/www/licenca/cron/manutencao.php \
 *                  >> /var/log/manutencao_licenca.log 2>&1
 *
 *  O QUE FAZ
 *    1) consolida os acessos em resumo mensal (acessos_resumo)
 *    2) apaga os acessos brutos com mais de RETENCAO_DIAS
 *    3) apaga avisos de vencimento antigos
 *    4) roda OPTIMIZE quando apagou muita coisa
 *
 *  POR QUE 400 DIAS
 *  Duas telas consultam periodos longos:
 *    cliente.php  filtro de 365 dias
 *    maquina.php  grafico dos ultimos 12 meses
 *  Cortar em 180 dias - o palpite comum - quebraria as duas. 400 da
 *  folga de um mes sobre o maior filtro.
 *
 *  O resumo mensal roda ANTES do expurgo, entao o historico de anos
 *  anteriores continua consultavel: uma linha por maquina por mes, em
 *  vez de alguns milhares.
 *
 *  MODO DE TESTE (mostra o que faria, sem apagar):
 *      php cron/manutencao.php --simular
 * =====================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script roda apenas pela linha de comando.\n");
}

require_once __DIR__ . '/../api/lib/licenca.php';

const RETENCAO_DIAS       = 400;   // acessos brutos
const RETENCAO_AVISOS     = 730;   // historico de avisos enviados
const LOTE                = 20000; // linhas por rodada do DELETE

$simular = in_array('--simular', $argv, true);

function log_linha(string $t): void {
    echo '[' . date('Y-m-d H:i:s') . "] $t\n";
}

log_linha($simular ? 'MODO SIMULACAO - nada sera apagado' : 'Manutencao iniciada');

/* ---------------------------------------------------------------------
 *  1) consolida os meses que estao prestes a sair da janela
 * ------------------------------------------------------------------- */
$corte = date('Y-m-d', strtotime('-' . RETENCAO_DIAS . ' days'));

$meses = db()->prepare(
  "SELECT DISTINCT DATE_FORMAT(ts,'%Y-%m') AS m
     FROM acessos WHERE ts < ? ORDER BY m");
$meses->execute([$corte]);
$meses = $meses->fetchAll(PDO::FETCH_COLUMN);

if (!$meses) {
    log_linha('Nenhum mes a consolidar.');
} else {
    log_linha(count($meses) . ' mes(es) a consolidar: ' . implode(', ', $meses));

    if (!$simular) {
        // REPLACE em vez de INSERT: se o mes ja foi consolidado numa
        // execucao anterior interrompida, recalcula em vez de duplicar
        $st = db()->prepare(
          "REPLACE INTO acessos_resumo
             (fingerprint, ano_mes, aberturas, sinais, dias_ativos,
              primeiro_dia, ultimo_dia, cliente_id, licenca_id)
           SELECT fingerprint,
                  DATE_FORMAT(ts,'%Y-%m'),
                  SUM(tipo='abertura'),
                  COUNT(*),
                  COUNT(DISTINCT DATE(ts)),
                  MIN(DATE(ts)),
                  MAX(DATE(ts)),
                  MAX(cliente_id),
                  MAX(licenca_id)
             FROM acessos
            WHERE DATE_FORMAT(ts,'%Y-%m') = ?
            GROUP BY fingerprint");
        foreach ($meses as $m) {
            $st->execute([$m]);
            log_linha("  $m consolidado (" . $st->rowCount() . ' maquina(s))');
        }
    }
}

/* ---------------------------------------------------------------------
 *  2) expurga os acessos brutos
 *     em lotes, para nao travar a tabela numa transacao gigante
 * ------------------------------------------------------------------- */
$cont = db()->prepare('SELECT COUNT(*) FROM acessos WHERE ts < ?');
$cont->execute([$corte]);
$total = (int)$cont->fetchColumn();

if ($total === 0) {
    log_linha('Nenhum acesso alem de ' . RETENCAO_DIAS . ' dias.');
} else {
    log_linha("$total acesso(s) anteriores a $corte");

    if ($simular) {
        log_linha('[simular] seriam apagados em ' . ceil($total / LOTE) . ' lote(s)');
    } else {
        $del = db()->prepare('DELETE FROM acessos WHERE ts < ? LIMIT ' . LOTE);
        $apagados = 0;
        do {
            $del->execute([$corte]);
            $n = $del->rowCount();
            $apagados += $n;
            if ($n > 0) usleep(200000);   // alivia o disco entre lotes
        } while ($n > 0);
        log_linha("$apagados acesso(s) apagados.");
    }
}

/* ---------------------------------------------------------------------
 *  3) historico de avisos de vencimento
 * ------------------------------------------------------------------- */
try {
    $corteAv = date('Y-m-d', strtotime('-' . RETENCAO_AVISOS . ' days'));
    $c = db()->prepare('SELECT COUNT(*) FROM avisos_vencimento WHERE enviado_em < ?');
    $c->execute([$corteAv]);
    $nAv = (int)$c->fetchColumn();
    if ($nAv > 0) {
        if ($simular) {
            log_linha("[simular] $nAv aviso(s) antigos seriam apagados");
        } else {
            db()->prepare('DELETE FROM avisos_vencimento WHERE enviado_em < ?')
                ->execute([$corteAv]);
            log_linha("$nAv aviso(s) antigos apagados.");
        }
    }
} catch (Throwable $e) {
    log_linha('avisos_vencimento: ' . $e->getMessage());
}

/* ---------------------------------------------------------------------
 *  4) OPTIMIZE quando valeu a pena
 *     O InnoDB nao devolve espaco ao disco apos DELETE sem isto.
 *     Bloqueia a tabela, por isso so quando apagou bastante e em
 *     horario de baixo uso (o cron roda domingo as 4h).
 * ------------------------------------------------------------------- */
if (!$simular && $total > 50000) {
    log_linha('Reorganizando a tabela (pode demorar)...');
    db()->query('OPTIMIZE TABLE acessos');
    log_linha('Tabela reorganizada.');
}

/* ---------------------------------------------------------------------
 *  situacao final
 * ------------------------------------------------------------------- */
$r = db()->query(
  "SELECT COUNT(*) AS n,
          MIN(ts) AS mais_antigo,
          MAX(ts) AS mais_novo
     FROM acessos")->fetch();
log_linha(sprintf('acessos: %s linha(s), de %s ate %s',
    number_format((int)$r['n'], 0, ',', '.'),
    $r['mais_antigo'] ?: '-', $r['mais_novo'] ?: '-'));

$rr = db()->query('SELECT COUNT(*) FROM acessos_resumo')->fetchColumn();
log_linha("acessos_resumo: $rr linha(s)");

log_linha('Concluido.');
