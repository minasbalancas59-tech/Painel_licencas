<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
require_once __DIR__ . '/../api/lib/config_db.php';
require 'inc/mensagem.php';
require 'inc/email_licenca.php';
exige_login();

/* Usada no log da renovação. No escopo global: dentro de um if, o PHP
   só declara quando aquele bloco executa. */
function moeda(?float $v): string {
    return $v === null ? '—' : 'R$ ' . number_format($v, 2, ',', '.');
}
exige_admin_escopo();

/* =====================================================================
 *  LICENCAS - emissao e gestao
 * =====================================================================
 *  IMPORTANTE (tecnico): a licenca entregue ao cliente e um JSON
 *  ASSINADO. Mexer nas colunas aqui NAO altera o arquivo que ja esta
 *  na maquina dele - o api/ativar.php re-assina com os valores atuais
 *  a cada chamada, e o cliente so recebe a versao nova na proxima
 *  revalidacao (ciclo de 7 dias do uRevalidacao.pas).
 *
 *  Dai a separacao das acoes:
 *    EDITAR  - vinculo, limite de transferencias e anotacao interna.
 *              Nada disso entra no payload assinado.
 *    RENOVAR - estende a validade. Propaga na revalidacao, com log.
 *    REVOGAR - corta o acesso, com motivo obrigatorio.
 *
 *  Produto, tier e modulos nao sao editaveis de proposito: mudariam o
 *  que foi contratado sem deixar rastro. Nesse caso, revogue e emita
 *  outra licenca.
 * ===================================================================== */

$msg=''; $tipo=''; $chaveGerada=''; $abrirEmissao=false; $idsGerados=[];

/* ---------------------------------------------------------------------
 *  POST / Redirect / GET
 * ---------------------------------------------------------------------
 *  Sem isto, apertar F5 depois de emitir REENVIA o formulario e emite
 *  outra licenca - cada atualizacao da pagina queimava uma chave.
 *
 *  Toda acao que grava termina em redirecionamento. A mensagem (e a
 *  chave gerada) viaja na sessao, para sobreviver ao redirect sem
 *  aparecer na URL.
 * ------------------------------------------------------------------- */
function pos_acao(string $msg, string $tipo, string $chave = '',
                  array $ids = []): void {
    $_SESSION['flash'] = ['msg' => $msg, 'tipo' => $tipo,
                          'chave' => $chave, 'ids' => $ids];
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// recupera a mensagem deixada pela acao anterior
if (!empty($_SESSION['flash'])) {
    $msg         = $_SESSION['flash']['msg']   ?? '';
    $tipo        = $_SESSION['flash']['tipo']  ?? '';
    $chaveGerada = $_SESSION['flash']['chave'] ?? '';
    $idsGerados  = $_SESSION['flash']['ids']   ?? [];
    unset($_SESSION['flash']);
}

// --- emitir nova licenca (v2: produto + tier) ----------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='emitir') {
    if (!csrf_valido()) { $msg='Sessão inválida.'; $tipo='erro'; }
    else {
        $cliId    = (int)($_POST['cliente_id'] ?? 0);
        $tierId   = (int)($_POST['tier_id'] ?? 0);
        // teto de 240 meses (20 anos): sem limite, um valor absurdo
        // vindo do formulario geraria data invalida no strtotime
        $meses    = max(1, min(240, (int)($_POST['meses'] ?? 12)));
        $carencia = (int)($_POST['carencia'] ?? 15);
        $mods     = $_POST['modulos'] ?? [];
        // destino explicito: evita o engano de deixar o cliente vazio
        // sem querer e a licenca sumir para um estoque nao pretendido
        $destino  = ($_POST['destino'] ?? 'cliente') === 'revenda'
                    ? 'revenda' : 'cliente';
        $revId    = (int)($_POST['revendedor_id'] ?? 0) ?: null;

        // cada destino zera o campo do outro
        if ($destino === 'revenda') { $cliId = 0; }
        else                        { $revId = null; }
        $tipoLic  = ($_POST['tipo_licenca'] ?? 'venda') === 'demo' ? 'demo' : 'venda';
        $qtd      = max(1, min(50, (int)($_POST['quantidade'] ?? 1)));
        // so aceita codigos que existem e estao ativos no catalogo
        $validos = db()->query(
          'SELECT codigo FROM modulos WHERE ativo=1')->fetchAll(PDO::FETCH_COLUMN);
        $mods = array_intersect(
            array_map(fn($m)=>strtoupper(preg_replace('/[^A-Za-z0-9]/','',$m)), $mods),
            $validos);
        $modsCsv = implode(',', $mods);

        if ($tierId<=0) {
            $msg='Selecione o software e o tipo de licença.'; $tipo='erro';
        } elseif ($destino === 'cliente' && $cliId <= 0) {
            $msg='Para cliente final, selecione o cliente.'; $tipo='erro';
        } elseif ($destino === 'revenda' && !$revId) {
            $msg='Para revenda, selecione o revendedor.'; $tipo='erro';
        }
        elseif ($meses<=0)     { $msg='Validade inválida.'; $tipo='erro'; }
        else {
            try {
                // resolve produto/tier/nivel a partir do tier escolhido
                $t = resolver_tier($tierId);   // produto_codigo, tier_codigo, nivel...

                // busca dados do cliente para o payload assinado.
                // Licenca de estoque nasce sem cliente: quem preenche e o
                // revendedor, ao vincular. So valida se um cliente foi escolhido.
                $cliRow = null;
                if ($cliId > 0) {
                    $cli = db()->prepare('SELECT razao_social,cnpj FROM clientes WHERE id=?');
                    $cli->execute([$cliId]);
                    $cliRow = $cli->fetch();
                    if (!$cliRow) throw new RuntimeException('Cliente não encontrado.');
                }

                $emit  = date('Y-m-d');
                $exp   = date('Y-m-d', strtotime("+$meses months"));
                $u     = usuario_logado();
                $geradas = [];
                $idsEmitidos = [];

                // grava a(s) licenca(s) - fingerprint fica NULL ate a ativacao
                $st = db()->prepare(
                  'INSERT INTO licencas
                     (cliente_id,revendedor_id,produto_id,tier_id,chave,modulos,
                      emitido_em,expira_em,carencia_dias,status,tipo_licenca,criado_por)
                   VALUES (?,?,?,?,?,?,?,?,?,"nova",?,?)');

                for ($i = 0; $i < $qtd; $i++) {
                    // prefixo pelo produto: TS5X, TS6X, TSLPRX...
                    $chave = gerar_chave_licenca($t['produto_codigo']);
                    $st->execute([
                        ($cliId ?: null), $revId,
                        $t['produto_id'],   // vem do JOIN em resolver_tier()
                        $tierId, $chave, ($modsCsv ?: ''),
                        $emit, $exp, $carencia, $tipoLic, $u['id']
                    ]);
                    $licId = (int)db()->lastInsertId();
                    $geradas[] = $chave;
                    $idsEmitidos[] = $licId;

                    log_acao_painel(
                        $licId, $chave, null, 'emitir', 'ok',
                        $u['id'], $u['nome'] ?? null,
                        $t['produto_codigo'], $t['tier_codigo'],
                        "validade {$meses}m, carencia {$carencia}d, {$tipoLic}"
                        . ($revId ? ", revendedor {$revId}" : ''));
                }

                // envia a chave por e-mail: o WhatsApp some na conversa,
                // o e-mail fica. Falha aqui nao invalida a emissao.
                $avisoMail = '';
                if ($cliId > 0 && $idsEmitidos) {
                    $stM = db()->prepare(
                      'SELECT l.*, c.razao_social, c.nome_fantasia,
                              p.codigo AS produto_codigo
                         FROM licencas l
                         LEFT JOIN clientes c ON c.id=l.cliente_id
                         LEFT JOIN produtos p ON p.id=l.produto_id
                        WHERE l.id = ?');
                    $enviados = 0;
                    foreach ($idsEmitidos as $lid) {
                        $stM->execute([$lid]);
                        $rowM = $stM->fetch();
                        if ($rowM) {
                            list($n, $txt) = enviar_licenca_email($rowM);
                            $enviados += $n;
                            if ($n === 0) $avisoMail = ' ' . $txt;
                        }
                    }
                    if ($enviados > 0)
                        $avisoMail = ' Chave enviada por e-mail ao cliente.';
                }

                pos_acao(
                    count($geradas) . " licença(s) emitida(s) "
                    . "({$t['produto_codigo']} · {$t['tier_codigo']}"
                    . ($tipoLic === 'demo' ? ' · demonstração' : '') . ")."
                    . $avisoMail,
                    'ok', implode("\n", $geradas), $idsEmitidos);
            } catch (Throwable $ex) {
                $msg='Erro ao emitir: '.$ex->getMessage(); $tipo='erro';
            }
        }
    }
}

// --- reenviar a chave por e-mail ------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='reenviar') {
    if (csrf_valido()) {
        $stE = db()->prepare(
          'SELECT l.*, c.razao_social, c.nome_fantasia,
                  p.codigo AS produto_codigo
             FROM licencas l
             LEFT JOIN clientes c ON c.id=l.cliente_id
             LEFT JOIN produtos p ON p.id=l.produto_id
            WHERE l.id = ?');
        $stE->execute([(int)$_POST['id']]);
        $rowE = $stE->fetch();
        if (!$rowE) pos_acao('Licença não encontrada.', 'erro');
        list($n, $txt) = enviar_licenca_email($rowE);
        pos_acao($txt, $n > 0 ? 'ok' : 'erro');
    }
}

// --- revogar --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='revogar') {
    if (csrf_valido()) {
        $id = (int)$_POST['id'];

        // motivo e obrigatorio: revogar sem registrar o porque deixa o
        // suporte sem resposta quando o cliente liga perguntando
        $motivosOk = ['inadimplencia','cancelamento','troca_licenca',
                      'uso_indevido','erro_emissao','outro'];
        $motivo = $_POST['motivo_revogacao'] ?? '';
        $obs    = trim($_POST['obs_revogacao'] ?? '');

        if (!in_array($motivo, $motivosOk, true)) {
            $msg = 'Selecione o motivo da revogacao.'; $tipo = 'erro';
        } elseif ($motivo === 'outro' && $obs === '') {
            $msg = 'Para o motivo "Outro", descreva na observacao.';
            $tipo = 'erro';
        } else {
            $u = usuario_logado();
            db()->prepare(
              'UPDATE licencas
                  SET status="revogada", motivo_revogacao=?, obs_revogacao=?,
                      revogada_em=NOW(), revogada_por=?
                WHERE id=?')->execute([$motivo, ($obs ?: null), $u['id'], $id]);

            // produto/tier da licenca, para registrar no log
            $lr = db()->prepare(
              'SELECT l.chave, p.codigo AS pc, t.codigo AS tc
                 FROM licencas l
                 LEFT JOIN produtos p ON p.id=l.produto_id
                 LEFT JOIN tiers t    ON t.id=l.tier_id
                WHERE l.id=?');
            $lr->execute([$id]);
            $lrow = $lr->fetch() ?: [];

            log_acao_painel(
                $id, $lrow['chave'] ?? null, null, 'revogar', 'ok',
                $u['id'], $u['nome'] ?? null,
                $lrow['pc'] ?? null, $lrow['tc'] ?? null,
                'motivo: '.$motivo.($obs ? ' - '.$obs : ''));

            pos_acao('Licença revogada.', 'ok');
        }
    }
}


// --- editar (somente campos fora do payload assinado) ---------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='editar') {
    if (csrf_valido()) {
        $id  = (int)$_POST['id'];
        $u   = usuario_logado();
        $novoCli = (int)($_POST['cliente_id'] ?? 0) ?: null;
        $novoRev = (int)($_POST['revendedor_id'] ?? 0) ?: null;
        $maxT    = max(0, min(99, (int)($_POST['max_transferencias'] ?? 3)));
        $obs     = trim($_POST['observacao'] ?? '');

        // Le o estado ANTES de alterar, para registrar o que de fato
        // mudou. "Cadastro alterado" sem dizer o que mudou nao serve
        // para nada quando alguem for auditar dali a seis meses.
        $ant = db()->prepare(
          'SELECT l.chave, l.cliente_id, l.revendedor_id,
                  l.max_transferencias, l.observacao,
                  c.razao_social, u.nome AS rev_nome
             FROM licencas l
             LEFT JOIN clientes c ON c.id = l.cliente_id
             LEFT JOIN usuarios u ON u.id = l.revendedor_id
            WHERE l.id = ?');
        $ant->execute([$id]);
        $ant = $ant->fetch() ?: [];

        db()->prepare(
          'UPDATE licencas
              SET cliente_id=?, revendedor_id=?, max_transferencias=?, observacao=?
            WHERE id=?')->execute([$novoCli, $novoRev, $maxT, ($obs ?: null), $id]);

        $mudou = [];
        if ((int)($ant['cliente_id'] ?? 0) !== (int)$novoCli) {
            $de = $ant['razao_social'] ?? 'sem cliente';
            $paraNome = 'sem cliente';
            if ($novoCli) {
                $q = db()->prepare('SELECT razao_social FROM clientes WHERE id=?');
                $q->execute([$novoCli]);
                $paraNome = $q->fetchColumn() ?: ('id ' . $novoCli);
            }
            $mudou[] = "cliente: $de -> $paraNome";
        }
        if ((int)($ant['revendedor_id'] ?? 0) !== (int)$novoRev) {
            $de = $ant['rev_nome'] ?? 'venda direta';
            $paraNome = 'venda direta';
            if ($novoRev) {
                $q = db()->prepare('SELECT nome FROM usuarios WHERE id=?');
                $q->execute([$novoRev]);
                $paraNome = $q->fetchColumn() ?: ('id ' . $novoRev);
            }
            $mudou[] = "revendedor: $de -> $paraNome";
        }
        if ((int)($ant['max_transferencias'] ?? 3) !== $maxT)
            $mudou[] = 'limite de transferencias: '
                     . (int)($ant['max_transferencias'] ?? 3) . " -> $maxT";
        if (trim((string)($ant['observacao'] ?? '')) !== $obs)
            $mudou[] = 'anotacao alterada';

        log_acao_painel($id, $ant['chave'] ?? null, null, 'editar', 'ok',
            $u['id'], $u['nome'] ?? null, null, null,
            $mudou ? mb_substr(implode('; ', $mudou), 0, 255)
                   : 'salvo sem alteracao');
        pos_acao('Licença atualizada.', 'ok');
    }
}

// --- reenviar a chave por e-mail ------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='reenviar') {
    if (csrf_valido()) {
        $stE = db()->prepare(
          'SELECT l.*, c.razao_social, c.nome_fantasia,
                  p.codigo AS produto_codigo
             FROM licencas l
             LEFT JOIN clientes c ON c.id=l.cliente_id
             LEFT JOIN produtos p ON p.id=l.produto_id
            WHERE l.id = ?');
        $stE->execute([(int)$_POST['id']]);
        $rowE = $stE->fetch();
        if (!$rowE) pos_acao('Licença não encontrada.', 'erro');
        list($n, $txt) = enviar_licenca_email($rowE);
        pos_acao($txt, $n > 0 ? 'ok' : 'erro');
    }
}

// --- revogar --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='revogar') {
    if (csrf_valido()) {
        $id = (int)$_POST['id'];

        // motivo e obrigatorio: revogar sem registrar o porque deixa o
        // suporte sem resposta quando o cliente liga perguntando
        $motivosOk = ['inadimplencia','cancelamento','troca_licenca',
                      'uso_indevido','erro_emissao','outro'];
        $motivo = $_POST['motivo_revogacao'] ?? '';
        $obs    = trim($_POST['obs_revogacao'] ?? '');

        if (!in_array($motivo, $motivosOk, true)) {
            $msg = 'Selecione o motivo da revogacao.'; $tipo = 'erro';
        } elseif ($motivo === 'outro' && $obs === '') {
            $msg = 'Para o motivo "Outro", descreva na observacao.';
            $tipo = 'erro';
        } else {
            $u = usuario_logado();
            db()->prepare(
              'UPDATE licencas
                  SET status="revogada", motivo_revogacao=?, obs_revogacao=?,
                      revogada_em=NOW(), revogada_por=?
                WHERE id=?')->execute([$motivo, ($obs ?: null), $u['id'], $id]);

            // produto/tier da licenca, para registrar no log
            $lr = db()->prepare(
              'SELECT l.chave, p.codigo AS pc, t.codigo AS tc
                 FROM licencas l
                 LEFT JOIN produtos p ON p.id=l.produto_id
                 LEFT JOIN tiers t    ON t.id=l.tier_id
                WHERE l.id=?');
            $lr->execute([$id]);
            $lrow = $lr->fetch() ?: [];

            log_acao_painel(
                $id, $lrow['chave'] ?? null, null, 'revogar', 'ok',
                $u['id'], $u['nome'] ?? null,
                $lrow['pc'] ?? null, $lrow['tc'] ?? null,
                'motivo: '.$motivo.($obs ? ' - '.$obs : ''));

            pos_acao('Licença revogada.', 'ok');
        }
    }
}


// --- editar (somente campos fora do payload assinado) ---------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='editar') {
    if (csrf_valido()) {
        $id  = (int)$_POST['id'];
        $u   = usuario_logado();
        $novoCli = (int)($_POST['cliente_id'] ?? 0) ?: null;
        $novoRev = (int)($_POST['revendedor_id'] ?? 0) ?: null;
        $maxT    = max(0, min(99, (int)($_POST['max_transferencias'] ?? 3)));
        $obs     = trim($_POST['observacao'] ?? '');

        // Le o estado ANTES de alterar, para registrar o que de fato
        // mudou. "Cadastro alterado" sem dizer o que mudou nao serve
        // para nada quando alguem for auditar dali a seis meses.
        $ant = db()->prepare(
          'SELECT l.chave, l.cliente_id, l.revendedor_id,
                  l.max_transferencias, l.observacao,
                  c.razao_social, u.nome AS rev_nome
             FROM licencas l
             LEFT JOIN clientes c ON c.id = l.cliente_id
             LEFT JOIN usuarios u ON u.id = l.revendedor_id
            WHERE l.id = ?');
        $ant->execute([$id]);
        $ant = $ant->fetch() ?: [];

        db()->prepare(
          'UPDATE licencas
              SET cliente_id=?, revendedor_id=?, max_transferencias=?, observacao=?
            WHERE id=?')->execute([$novoCli, $novoRev, $maxT, ($obs ?: null), $id]);

        $mudou = [];
        if ((int)($ant['cliente_id'] ?? 0) !== (int)$novoCli) {
            $de = $ant['razao_social'] ?? 'sem cliente';
            $paraNome = 'sem cliente';
            if ($novoCli) {
                $q = db()->prepare('SELECT razao_social FROM clientes WHERE id=?');
                $q->execute([$novoCli]);
                $paraNome = $q->fetchColumn() ?: ('id ' . $novoCli);
            }
            $mudou[] = "cliente: $de -> $paraNome";
        }
        if ((int)($ant['revendedor_id'] ?? 0) !== (int)$novoRev) {
            $de = $ant['rev_nome'] ?? 'venda direta';
            $paraNome = 'venda direta';
            if ($novoRev) {
                $q = db()->prepare('SELECT nome FROM usuarios WHERE id=?');
                $q->execute([$novoRev]);
                $paraNome = $q->fetchColumn() ?: ('id ' . $novoRev);
            }
            $mudou[] = "revendedor: $de -> $paraNome";
        }
        if ((int)($ant['max_transferencias'] ?? 3) !== $maxT)
            $mudou[] = 'limite de transferencias: '
                     . (int)($ant['max_transferencias'] ?? 3) . " -> $maxT";
        if (trim((string)($ant['observacao'] ?? '')) !== $obs)
            $mudou[] = 'anotacao alterada';

        log_acao_painel($id, $ant['chave'] ?? null, null, 'editar', 'ok',
            $u['id'], $u['nome'] ?? null, null, null,
            $mudou ? mb_substr(implode('; ', $mudou), 0, 255)
                   : 'salvo sem alteracao');
        pos_acao('Licença atualizada.', 'ok');
    }
}

// --- renovar --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='renovar') {
    if (csrf_valido()) {
        $id    = (int)$_POST['id'];
        $u     = usuario_logado();

        /* Sem padrão nos dois campos.
           Renovar por 12 meses porque ninguém escolheu é erro que só
           aparece quando o cliente reclama da data. E valor vazio deixa
           a renovação fora da receita do mês sem ninguém notar. */
        $mesesTxt = trim($_POST['meses_renov'] ?? '');
        $meses    = (int)$mesesTxt;

        $vTxt   = str_replace(['.', ','], ['', '.'],
                              trim($_POST['valor_renov'] ?? ''));
        $vRenov = $vTxt === '' ? null : max(0, (float)$vTxt);

        if ($mesesTxt === '' || $meses <= 0 || $meses > 60)
            pos_acao('Escolha por quantos meses renovar.', 'erro');
        if ($vTxt === '')
            pos_acao('Informe o valor da renovação. Use 0,00 se for cortesia.',
                     'erro');

        $lr = db()->prepare(
          'SELECT l.chave, l.expira_em, p.codigo AS pc, t.codigo AS tc
             FROM licencas l
             LEFT JOIN produtos p ON p.id=l.produto_id
             LEFT JOIN tiers t    ON t.id=l.tier_id
            WHERE l.id=?');
        $lr->execute([$id]);
        $lrow = $lr->fetch();

        if (!$lrow) { $msg='Licença não encontrada.'; $tipo='erro'; }
        else {
            /* A renovação SUBSTITUI a validade: vale sempre hoje + N
               meses, nunca soma ao vencimento anterior.

               Somar acumulava. Nove renovações de 6 meses levavam a
               licença para 2034 sem ninguém perceber — o valor certo
               é o do último acordo, não a soma de todos.

               Efeito: cliente que renova antes de vencer perde os dias
               que sobravam. É consciente: o prazo passa a ser o que foi
               combinado agora. */
            $novo = date('Y-m-d', strtotime("+$meses months"));

            db()->prepare(
              'UPDATE licencas
                  SET expira_em=?, status=IF(fingerprint IS NULL,"nova","ativa"),
                      renovacoes=renovacoes+1, renovada_em=NOW()
                WHERE id=?')->execute([$novo, $id]);

            if ($vRenov !== null) {
                db()->prepare('UPDATE licencas SET valor=? WHERE id=?')
                    ->execute([$vRenov, $id]);
                db()->prepare(
                  'INSERT INTO financeiro_mov
                     (licenca_id, tipo, valor, meses, cliente_id,
                      revendedor_id, produto, tier, competencia, criado_por)
                   SELECT ?, "renovacao", ?, ?, l.cliente_id, l.revendedor_id,
                          ?, ?, DATE_FORMAT(NOW(),"%Y-%m"), ?
                     FROM licencas l WHERE l.id = ?')
                  ->execute([$id, $vRenov, $meses, $lrow['pc'], $lrow['tc'],
                             $u['id'], $id]);
            }

            log_acao_painel($id, $lrow['chave'], null, 'renovar', 'ok',
                $u['id'], $u['nome'] ?? null, $lrow['pc'], $lrow['tc'],
                "de {$lrow['expira_em']} para $novo (+{$meses}m)"
                . ($vRenov !== null ? ' - ' . moeda($vRenov) : ''));

            $antes = date('d/m/Y', strtotime($lrow['expira_em']));
            pos_acao('Licença renovada: ' . $antes . ' → '
                . date('d/m/Y', strtotime($novo))
                . '. O cliente recebe a nova validade na próxima '
                . 'revalidação (até 7 dias).', 'ok');
        }
    }
}

// rotulos legiveis dos motivos de revogacao
$ROTULO_MOTIVO = [
    'inadimplencia' => 'Inadimplência',
    'cancelamento'  => 'Cancelamento pelo cliente',
    'troca_licenca' => 'Substituída por outra licença',
    'uso_indevido'  => 'Uso indevido',
    'erro_emissao'  => 'Erro na emissão',
    'outro'         => 'Outro',
];

$clientes = db()->query(
  'SELECT id,razao_social,nome_fantasia FROM clientes ORDER BY razao_social')->fetchAll();
$preselect = (int)($_GET['cliente'] ?? 0);
if ($preselect) $abrirEmissao = true;

// padroes de emissao, editaveis em Configuracoes
$padMeses    = (int)cfg('validade_padrao_meses', 12);
$padCarencia = (int)cfg('carencia_padrao_dias', 15);
$padDemoDias = (int)cfg('demo_validade_dias', 30);

$produtos = db()->query(
  'SELECT id,codigo,nome FROM produtos WHERE ativo=1 ORDER BY codigo')->fetchAll();

// modulos vem do catalogo, nao mais escritos a mao no formulario
$modulosCat = db()->query(
  'SELECT m.*, p.codigo AS produto_codigo
     FROM modulos m LEFT JOIN produtos p ON p.id=m.produto_id
    WHERE m.ativo=1
    ORDER BY COALESCE(p.codigo,""), m.ordem, m.codigo')->fetchAll();
$tiers = db()->query(
  'SELECT id,produto_id,codigo,nome,nivel FROM tiers WHERE ativo=1
    ORDER BY produto_id, nivel')->fetchAll();

$revendedores = db()->query(
  "SELECT id, nome, empresa, nome_fantasia FROM usuarios
    WHERE papel='revendedor' AND ativo=1
    ORDER BY COALESCE(nome_fantasia,empresa,nome)")->fetchAll();

// todos os revendedores (inclusive inativos) para o filtro e a edicao
$revTodos = db()->query(
  "SELECT id, nome, empresa, nome_fantasia FROM usuarios
    WHERE papel='revendedor' ORDER BY COALESCE(nome_fantasia,empresa,nome)")->fetchAll();

/* =====================================================================
 *  FILTROS
 * ===================================================================== */
$fBusca   = trim($_GET['q'] ?? '');
$fCliente = (int)($_GET['f_cliente'] ?? 0);
$fRev     = trim($_GET['f_rev'] ?? '');      // id | 'direta'
$fProduto = trim($_GET['produto'] ?? '');
$fTier    = (int)($_GET['tier'] ?? 0);
$fStatus  = trim($_GET['status'] ?? '');
$fTipo    = trim($_GET['tipo_lic'] ?? '');
$fVenc    = trim($_GET['venc'] ?? '');       // 30 | 60 | 90 | vencidas
$fDe      = trim($_GET['de'] ?? '');
$fAte     = trim($_GET['ate'] ?? '');
$fOrdem   = trim($_GET['ordem'] ?? 'recentes');

$where = []; $args = [];

if ($fBusca !== '') {
    $where[] = '(l.chave LIKE ? OR c.razao_social LIKE ? OR c.nome_fantasia LIKE ? '
             . 'OR c.cnpj LIKE ? OR m.maq_nome LIKE ? OR l.fingerprint LIKE ?)';
    for ($i=0;$i<6;$i++) $args[] = '%'.$fBusca.'%';
}
if ($fCliente > 0) { $where[] = 'l.cliente_id = ?'; $args[] = $fCliente; }

if ($fRev === 'direta')      $where[] = 'l.revendedor_id IS NULL';
elseif ($fRev !== '')      { $where[] = 'l.revendedor_id = ?'; $args[] = (int)$fRev; }

if ($fProduto !== '') { $where[] = 'p.codigo = ?'; $args[] = $fProduto; }
if ($fTier > 0)       { $where[] = 'l.tier_id = ?'; $args[] = $fTier; }

switch ($fStatus) {
    case 'estoque':  $where[] = 'l.cliente_id IS NULL'; break;
    case 'naoativa': $where[] = "l.fingerprint IS NULL AND l.status<>'revogada'"; break;
    case '':         break;
    default:         $where[] = 'l.status = ?'; $args[] = $fStatus;
}
if ($fTipo !== '') { $where[] = 'l.tipo_licenca = ?'; $args[] = $fTipo; }

switch ($fVenc) {
    case 'vencidas':
        $where[] = 'l.expira_em < CURDATE()'; break;
    case '30': case '60': case '90':
        // valor vem de uma lista fixa; INTERVAL ? DAY nao funciona em
        // prepare nativo, por isso o inteiro entra direto
        $d = (int)$fVenc;
        $where[] = "l.expira_em BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $d DAY)";
        break;
}

if ($fDe  !== '') { $where[] = 'l.emitido_em >= ?'; $args[] = $fDe; }
if ($fAte !== '') { $where[] = 'l.emitido_em <= ?'; $args[] = $fAte; }

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$ORDENS = [
    'recentes' => 'l.id DESC',
    'antigas'  => 'l.id ASC',
    'vence'    => 'l.expira_em ASC',
    'cliente'  => 'c.razao_social ASC, l.expira_em ASC',
];
$orderSql = $ORDENS[$fOrdem] ?? $ORDENS['recentes'];

$juncoes =
  'FROM licencas l
     LEFT JOIN clientes c  ON c.id = l.cliente_id
     LEFT JOIN usuarios u  ON u.id = l.revendedor_id
     LEFT JOIN usuarios ur ON ur.id = l.revogada_por
     LEFT JOIN usuarios ue ON ue.id = l.criado_por
     LEFT JOIN produtos p  ON p.id = l.produto_id
     LEFT JOIN tiers t     ON t.id = l.tier_id
     LEFT JOIN maquinas m  ON m.fingerprint = l.fingerprint';

// --- exportacao CSV (respeita os filtros) ----------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $stX = db()->prepare(
      "SELECT l.chave, p.codigo AS produto, t.nome AS tier, l.tipo_licenca,
              c.razao_social, c.cnpj, u.nome AS revendedor, l.status,
              l.emitido_em, l.expira_em, l.fingerprint, m.maq_nome,
              m.ultimo_acesso, l.transferencias, l.renovacoes
         $juncoes $whereSql ORDER BY $orderSql");
    $stX->execute($args);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=licencas_'.date('Y-m-d').'.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM para o Excel abrir acentos certo
    fputcsv($out, ['Chave','Software','Tipo','Licenca','Cliente','CNPJ',
                   'Revendedor','Status','Emitida','Expira','Fingerprint',
                   'Maquina','Ultimo acesso','Transferencias','Renovacoes'], ';');
    while ($r = $stX->fetch(PDO::FETCH_NUM)) fputcsv($out, $r, ';');
    fclose($out);
    exit;
}

// --- paginacao --------------------------------------------------------
$porPagina = 40;
$pagina = max(1, (int)($_GET['pg'] ?? 1));
$offset = ($pagina - 1) * $porPagina;

$stC = db()->prepare("SELECT COUNT(*) $juncoes $whereSql");
$stC->execute($args);
$total = (int)$stC->fetchColumn();
$totalPaginas = max(1, (int)ceil($total / $porPagina));

$stL = db()->prepare(
  "SELECT l.*, c.razao_social, c.nome_fantasia AS cli_fantasia, c.cnpj,
          u.nome AS rev_nome, u.empresa AS rev_empresa, u.nome_fantasia AS rev_fantasia,
          ur.nome AS revogada_por_nome, ue.nome AS emitida_por_nome,
          p.codigo AS produto_codigo, t.codigo AS tier_codigo, t.nome AS tier_nome,
          m.maq_nome, m.maq_usuario, m.maq_so, m.primeiro_acesso,
          m.ultimo_acesso, m.aberturas, m.ip_ultimo,
          DATEDIFF(l.expira_em, CURDATE()) AS dias_restantes,
          DATEDIFF(NOW(), m.ultimo_acesso) AS dias_sem_ver
     $juncoes
   $whereSql
   ORDER BY $orderSql
   LIMIT $porPagina OFFSET $offset");
$stL->execute($args);
$licencas = $stL->fetchAll();

/* ---------------------------------------------------------------------
 *  HISTORICO POR LICENCA
 * ---------------------------------------------------------------------
 *  Carregado de uma vez para as licencas da pagina, em vez de uma
 *  consulta por linha. Com 40 licencas por pagina, seriam 40 idas ao
 *  banco so para preencher blocos que talvez ninguem abra.
 * ------------------------------------------------------------------- */
$histLic = [];
if ($licencas) {
    $ids = array_column($licencas, 'id');
    $inQ = implode(',', array_fill(0, count($ids), '?'));
    $stH = db()->prepare(
      "SELECT a.licenca_id, a.acao, a.resultado, a.detalhe,
              a.usuario_nome, a.fingerprint, a.criado_em
         FROM ativacoes_log a
        WHERE a.licenca_id IN ($inQ)
        ORDER BY a.id DESC");
    $stH->execute($ids);
    foreach ($stH->fetchAll() as $h)
        $histLic[(int)$h['licenca_id']][] = $h;
}

/* ---------------------------------------------------------------------
 *  VOLUME DE PESAGENS POR LICENCA
 * ---------------------------------------------------------------------
 *  A `pesagens_mensal` guarda por licenca e por maquina, entao da para
 *  ver qual balanca do cliente realmente trabalha. Um cliente com tres
 *  licencas onde so uma pesa e uma conversa diferente de um com as tres
 *  rodando.
 *
 *  Desenhado com barras em CSS, nao com Chart.js: sao ate 40 dossies
 *  por pagina, e um grafico em cada um deixaria a tela pesada para
 *  mostrar seis numeros.
 * ------------------------------------------------------------------- */
$pesagLic = [];
if ($licencas) {
    $ids = array_column($licencas, 'id');
    $inQ = implode(',', array_fill(0, count($ids), '?'));
    $stP = db()->prepare(
      "SELECT licenca_id, ano_mes, SUM(pesagens) AS n
         FROM pesagens_mensal
        WHERE licenca_id IN ($inQ)
          AND ano_mes >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m')
        GROUP BY licenca_id, ano_mes
        ORDER BY ano_mes");
    $stP->execute($ids);
    foreach ($stP->fetchAll() as $r)
        $pesagLic[(int)$r['licenca_id']][$r['ano_mes']] = (int)$r['n'];
}

function mesCurto(string $am): string {
    $m = ['01'=>'jan','02'=>'fev','03'=>'mar','04'=>'abr','05'=>'mai','06'=>'jun',
          '07'=>'jul','08'=>'ago','09'=>'set','10'=>'out','11'=>'nov','12'=>'dez'];
    [$a, $mm] = explode('-', $am);
    return ($m[$mm] ?? $mm) . '/' . substr($a, 2);
}

// rotulos das acoes registradas no log
$ROTULO_ACAO = [
    'emitir'              => 'Licença emitida',
    'ativar'              => 'Ativada na máquina',
    'revalidar'           => 'Revalidação',
    'renovar'             => 'Renovada',
    'editar'              => 'Cadastro alterado',
    'revogar'             => 'Revogada',
    'vincular_cliente'    => 'Vinculada ao cliente',
    'liberar_maquina'     => 'Máquina liberada',
    'atribuir_revendedor' => 'Atribuída a revendedor',
    'solicitar_troca'     => 'Troca solicitada',
    'aprovar_troca'       => 'Troca aprovada',
    'negar_troca'         => 'Troca negada',
    'offline'             => 'Ativação offline gerada',
];

// --- indicadores do filtro atual -------------------------------------
$stR = db()->prepare(
  "SELECT COUNT(*) AS n,
          SUM(l.status='ativa') AS ativas,
          SUM(l.cliente_id IS NULL) AS estoque,
          SUM(l.status='ativa' AND l.expira_em BETWEEN CURDATE()
              AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS vencendo,
          SUM(l.expira_em < CURDATE()) AS vencidas
     $juncoes $whereSql");
$stR->execute($args);
$resumo = $stR->fetch();

function linkLic(array $novo = []) {
    $b = [];
    foreach (['q','f_cliente','f_rev','produto','tier','status','tipo_lic',
              'venc','de','ate','ordem','pg'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $b[$k] = $_GET[$k];
    }
    return 'licencas.php?'.http_build_query(array_merge($b, $novo));
}
$temFiltro = $fBusca!=='' || $fCliente || $fRev!=='' || $fProduto!=='' || $fTier
          || $fStatus!=='' || $fTipo!=='' || $fVenc!=='' || $fDe!=='' || $fAte!=='';

/* Licenças recém-emitidas, para o card de confirmação.
   Carregado aqui e não dentro do HTML porque o título do card precisa
   saber a quantidade antes de renderizar. */
$recem = [];
if ($idsGerados) {
    $inQ = implode(',', array_fill(0, count($idsGerados), '?'));
    $stR = db()->prepare(
      "SELECT l.*, c.razao_social, c.nome_fantasia,
              p.codigo AS produto_codigo, t.nome AS tier_nome
         FROM licencas l
         LEFT JOIN clientes c ON c.id = l.cliente_id
         LEFT JOIN produtos p ON p.id = l.produto_id
         LEFT JOIN tiers    t ON t.id = l.tier_id
        WHERE l.id IN ($inQ)
        ORDER BY l.id");
    $stR->execute($idsGerados);
    $recem = $stR->fetchAll();
}

abre_pagina('Licenças', 'licencas');
?>
<div style="display:flex;justify-content:space-between;align-items:flex-start;
     gap:16px;flex-wrap:wrap">
  <div>
    <h1 class="titulo" style="margin-bottom:4px">Licenças</h1>
    <p class="subtitulo">Acompanhe, renove e revogue as chaves de ativação</p>
  </div>
  <a class="btn" href="emitir.php" style="margin-top:6px">+ Emitir licença</a>
</div>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>
<?php if ($chaveGerada): ?>
  <div class="card">
    <h3><?= count($recem) > 1 ? count($recem) . ' chaves geradas' : 'Chave gerada' ?></h3>

    <?php if (count($recem) > 1): ?>
      <table style="margin-top:4px">
        <tbody>
        <?php foreach ($recem as $i => $rl): ?>
          <tr>
            <td class="mono" style="width:32px;color:var(--texto-2)">
              <?= $i + 1 ?></td>
            <td class="mono" style="font-size:14px;letter-spacing:.5px">
              <?= e($rl['chave']) ?></td>
            <td style="text-align:right">
              <?= botao_whatsapp($rl, 'u'.$rl['id'], 'Copiar') ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="codigo"><?= e($chaveGerada) ?></div>
    <?php endif; ?>

    <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
      <?php if (count($recem) > 1): ?>
        <?= botao_whatsapp_lote($recem) ?>
      <?php elseif ($recem): ?>
        <?= botao_whatsapp($recem[0], 'nova'.$recem[0]['id']) ?>
      <?php endif; ?>

      <?php foreach ($recem as $rl): ?>
        <?php if (!empty($rl['cliente_id']) && count($recem) === 1): ?>
          <form method="post" action="licencas.php" style="display:inline">
            <input type="hidden" name="acao" value="reenviar">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $rl['id'] ?>">
            <button class="btn sec">Reenviar por e-mail</button>
          </form>
        <?php endif; ?>
      <?php endforeach; ?>

      <a class="btn sec" href="licencas.php">Voltar</a>
    </div>

    <p class="subtitulo" style="margin-top:12px">
      <?= count($recem) > 1
          ? 'Cada chave vale para uma máquina. Use o botão acima para enviar todas de uma vez.'
          : 'O cliente digita esta chave no software (ativação online) ou você a usa na aba <a href="offline.php">Ativação offline</a> se o PC dele não tiver internet.' ?>
    </p>
  </div>
<?php endif; ?>

<div class="stats">
  <div class="stat"><div class="n"><?= (int)$resumo['n'] ?></div>
    <div class="l"><?= $temFiltro ? 'No filtro' : 'Total emitidas' ?></div></div>
  <div class="stat"><div class="n" style="color:var(--verde)"><?= (int)$resumo['ativas'] ?></div>
    <div class="l">Ativas</div></div>
  <div class="stat"><div class="n" style="color:var(--ambar)"><?= (int)$resumo['vencendo'] ?></div>
    <div class="l">Vencem em 30 dias</div></div>
  <div class="stat"><div class="n" style="color:var(--vermelho)"><?= (int)$resumo['vencidas'] ?></div>
    <div class="l">Vencidas</div></div>
</div>

<div class="card">
  <h3>Filtros</h3>
  <form method="get">
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px">
      <div>
        <label>Buscar por chave, cliente, CNPJ ou máquina</label>
        <input type="text" name="q" value="<?= e($fBusca) ?>">
      </div>
      <div>
        <label>Cliente</label>
        <select name="f_cliente">
          <option value="">— todos —</option>
          <?php foreach ($clientes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $fCliente===(int)$c['id']?'selected':'' ?>>
              <?= e($c['nome_fantasia'] ?: $c['razao_social']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Origem</label>
        <select name="f_rev">
          <option value="">— todas —</option>
          <option value="direta" <?= $fRev==='direta'?'selected':'' ?>>Venda direta</option>
          <?php foreach ($revTodos as $r): ?>
            <option value="<?= $r['id'] ?>" <?= $fRev===(string)$r['id']?'selected':'' ?>>
              <?= e($r['nome_fantasia'] ?: ($r['empresa'] ?: $r['nome'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Software</label>
        <select name="produto">
          <option value="">— todos —</option>
          <?php foreach ($produtos as $p): ?>
            <option value="<?= e($p['codigo']) ?>" <?= $fProduto===$p['codigo']?'selected':'' ?>>
              <?= e($p['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Tipo de licença</label>
        <select name="tier">
          <option value="">— todos —</option>
          <?php foreach ($tiers as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $fTier===(int)$t['id']?'selected':'' ?>>
              <?= e($t['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Situação</label>
        <select name="status">
          <option value="">— todas —</option>
          <option value="ativa"    <?= $fStatus==='ativa'   ?'selected':'' ?>>Ativa</option>
          <option value="nova"     <?= $fStatus==='nova'    ?'selected':'' ?>>Nova</option>
          <option value="revogada" <?= $fStatus==='revogada'?'selected':'' ?>>Revogada</option>
          <option value="expirada" <?= $fStatus==='expirada'?'selected':'' ?>>Expirada</option>
          <option value="estoque"  <?= $fStatus==='estoque' ?'selected':'' ?>>Em estoque</option>
          <option value="naoativa" <?= $fStatus==='naoativa'?'selected':'' ?>>Nunca ativada</option>
        </select>
      </div>
      <div>
        <label>Venda / demo</label>
        <select name="tipo_lic">
          <option value="">— todos —</option>
          <option value="venda" <?= $fTipo==='venda'?'selected':'' ?>>Venda</option>
          <option value="demo"  <?= $fTipo==='demo' ?'selected':'' ?>>Demonstração</option>
        </select>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Vencimento</label>
        <select name="venc">
          <option value="">— qualquer —</option>
          <option value="30"       <?= $fVenc==='30'      ?'selected':'' ?>>Próximos 30 dias</option>
          <option value="60"       <?= $fVenc==='60'      ?'selected':'' ?>>Próximos 60 dias</option>
          <option value="90"       <?= $fVenc==='90'      ?'selected':'' ?>>Próximos 90 dias</option>
          <option value="vencidas" <?= $fVenc==='vencidas'?'selected':'' ?>>Já vencidas</option>
        </select>
      </div>
      <div><label>Emitidas de</label>
        <input type="date" name="de" value="<?= e($fDe) ?>"></div>
      <div><label>até</label>
        <input type="date" name="ate" value="<?= e($fAte) ?>"></div>
      <div>
        <label>Ordenar por</label>
        <select name="ordem">
          <option value="recentes" <?= $fOrdem==='recentes'?'selected':'' ?>>Mais recentes</option>
          <option value="antigas"  <?= $fOrdem==='antigas' ?'selected':'' ?>>Mais antigas</option>
          <option value="vence"    <?= $fOrdem==='vence'   ?'selected':'' ?>>Vencimento</option>
          <option value="cliente"  <?= $fOrdem==='cliente' ?'selected':'' ?>>Cliente</option>
        </select>
      </div>
    </div>

    <div style="margin-top:14px;display:flex;gap:8px;align-items:center">
      <button class="btn">Filtrar</button>
      <?php if ($temFiltro): ?>
        <a class="btn sec" href="licencas.php">Limpar</a>
      <?php endif; ?>
      <a class="btn sec" href="<?= e(linkLic(['export'=>'csv'])) ?>">Exportar CSV</a>
      <span class="subtitulo" style="margin:0">
        <?= number_format($total,0,',','.') ?> licença(s)
      </span>
    </div>
  </form>
</div>

<div class="card">
  <h3>Licenças emitidas</h3>
  <table>
    <thead><tr>
      <th>Chave</th><th>Software / Tipo</th><th>Cliente</th><th>Origem</th>
      <th>Emitida</th><th>Expira</th><th>Situação</th><th>Máquina</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$licencas): ?>
      <tr><td colspan="9" style="color:var(--texto-2)">
        Nenhuma licença para os filtros escolhidos.</td></tr>
    <?php else: foreach ($licencas as $l):
        $dr  = (int)$l['dias_restantes'];
        $cor = $dr < 0 ? 'var(--vermelho)' : ($dr <= 30 ? 'var(--ambar)' : 'var(--texto-2)');
        $revRot = $l['rev_fantasia'] ?: ($l['rev_empresa'] ?: $l['rev_nome']);
    ?>
      <tr>
        <td class="mono" style="font-size:11px">
          <a href="#" onclick="detalhe(<?= $l['id'] ?>);return false;"><?= e($l['chave']) ?></a>
          <?php if (($l['tipo_licenca'] ?? '')==='demo'): ?>
            <br><span class="badge nova" style="font-size:10px">demo</span>
          <?php endif; ?>
          <?php if ((int)$l['renovacoes'] > 0): ?>
            <br><span style="font-size:10px;color:var(--texto-2)">
              <?= (int)$l['renovacoes'] ?>ª renovação</span>
          <?php endif; ?>
        </td>
        <td class="mono" style="font-size:11px">
          <?= e(strtoupper($l['produto_codigo'] ?? '—')) ?>
          <?= $l['tier_nome'] ? '· '.e($l['tier_nome']) : '' ?></td>
        <td style="font-size:12px">
          <?php if ($l['cliente_id']): ?>
            <a href="cliente.php?id=<?= (int)$l['cliente_id'] ?>">
              <?= e($l['cli_fantasia'] ?: $l['razao_social']) ?></a>
          <?php else: ?>
            <span style="color:var(--texto-2)">— estoque —</span>
          <?php endif; ?>
        </td>
        <td style="font-size:11px">
          <?php if ($l['revendedor_id']): ?>
            <a href="revendedor.php?id=<?= (int)$l['revendedor_id'] ?>"><?= e($revRot) ?></a>
          <?php else: ?>
            <span style="color:var(--texto-2)">direta</span>
          <?php endif; ?>
        </td>
        <td class="mono" style="font-size:11px">
          <?= date('d/m/Y', strtotime($l['emitido_em'])) ?></td>
        <td class="mono" style="font-size:11px">
          <?= date('d/m/Y', strtotime($l['expira_em'])) ?>
          <br><span style="font-size:10px;color:<?= $cor ?>">
            <?= $dr < 0 ? abs($dr).'d atrás' : 'em '.$dr.'d' ?></span></td>
        <td>
          <span class="badge <?= e($l['status']) ?>"><?= e($l['status']) ?></span>
          <?php if ($l['status']==='revogada'): ?>
            <br><span style="font-size:10px;color:var(--texto-2)">
              <?= e($ROTULO_MOTIVO[$l['motivo_revogacao']] ?? 'sem motivo') ?></span>
          <?php endif; ?>
        </td>
        <td class="mono" style="font-size:11px">
          <?php if ($l['fingerprint']): ?>
            <?= e($l['maq_nome'] ?: substr($l['fingerprint'],0,12).'…') ?>
          <?php else: ?>
            <span style="color:var(--azul)">não ativada</span>
          <?php endif; ?>
        </td>
        <td><button type="button" class="btn sec pequeno"
                    onclick="detalhe(<?= $l['id'] ?>)">Detalhes</button></td>
      </tr>

      <tr id="det<?= $l['id'] ?>" style="display:none">
        <td colspan="9" style="background:var(--bg-3);padding:16px">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px">
            <div>
              <h4 style="margin:0 0 8px;font-size:12px;color:var(--ambar)">LICENÇA</h4>
              <table style="font-size:11px">
                <tr><td style="color:var(--texto-2)">Chave</td>
                    <td class="mono"><?= e($l['chave']) ?></td></tr>
                <tr><td style="color:var(--texto-2)">Módulos</td>
                    <td class="mono"><?= e($l['modulos'] ?: '—') ?></td></tr>
                <tr><td style="color:var(--texto-2)">Emitida por</td>
                    <td><?= e($l['emitida_por_nome'] ?: '—') ?></td></tr>
                <tr><td style="color:var(--texto-2)">Carência</td>
                    <td><?= (int)($l['carencia_dias'] ?? 0) ?> dias</td></tr>
                <tr><td style="color:var(--texto-2)">Transferências</td>
                    <td><?= (int)$l['transferencias'] ?> de
                        <?= (int)($l['max_transferencias'] ?? 3) ?></td></tr>
                <tr><td style="color:var(--texto-2)">Renovações</td>
                    <td><?= (int)$l['renovacoes'] ?>
                        <?= $l['renovada_em']
                            ? '· última '.date('d/m/Y', strtotime($l['renovada_em'])) : '' ?></td></tr>
              </table>
              <?php if (!empty($l['observacao'])): ?>
                <p style="font-size:11px;margin-top:8px;font-style:italic">
                  <?= e($l['observacao']) ?></p>
              <?php endif; ?>
            </div>

            <div>
              <h4 style="margin:0 0 8px;font-size:12px;color:var(--ambar)">MÁQUINA</h4>
              <?php if (!$l['fingerprint']): ?>
                <p style="font-size:11px;color:var(--texto-2)">Ainda não ativada.</p>
              <?php else: ?>
                <table style="font-size:11px">
                  <tr><td style="color:var(--texto-2)">Código</td>
                      <td class="mono"><?= e($l['fingerprint']) ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Nome do PC</td>
                      <td><?= e($l['maq_nome'] ?: '—') ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Usuário</td>
                      <td><?= e($l['maq_usuario'] ?: '—') ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Sistema</td>
                      <td><?= e($l['maq_so'] ?: '—') ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Último acesso</td>
                      <td><?= $l['ultimo_acesso']
                              ? date('d/m/Y H:i', strtotime($l['ultimo_acesso'])) : '—' ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Aberturas</td>
                      <td class="mono"><?= (int)$l['aberturas'] ?></td></tr>
                </table>
                <a class="btn sec pequeno" style="margin-top:8px"
                   href="maquina.php?fp=<?= urlencode($l['fingerprint']) ?>">Ver uso</a>
              <?php endif; ?>

              <?php $pz = $pesagLic[(int)$l['id']] ?? []; ?>
              <?php if ($pz):
                  $maxP = max($pz) ?: 1;
                  $medP = round(array_sum($pz) / count($pz));
              ?>
                <div style="margin-top:14px;border-top:1px solid var(--borda);
                     padding-top:10px">
                  <div style="font-size:11px;color:var(--texto-2);margin-bottom:6px">
                    PESAGENS ·
                    <b style="color:var(--texto)"><?= number_format($medP,0,',','.') ?></b>
                    por mês em média
                  </div>
                  <?php foreach ($pz as $am => $n):
                      // barra proporcional ao maior mes do periodo
                      $larg = max(2, round(($n / $maxP) * 100));
                  ?>
                    <div style="display:flex;align-items:center;gap:6px;
                         font-size:10px;margin-bottom:3px">
                      <span class="mono" style="width:38px;color:var(--texto-2)">
                        <?= e(mesCurto($am)) ?></span>
                      <span style="flex:1;background:var(--bg-3);height:12px;
                            border-radius:2px;overflow:hidden">
                        <span style="display:block;height:12px;width:<?= $larg ?>%;
                              background:var(--ambar)"></span>
                      </span>
                      <span class="mono" style="width:44px;text-align:right">
                        <?= number_format($n,0,',','.') ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php elseif ($l['fingerprint']): ?>
                <p style="font-size:10px;color:var(--texto-2);margin-top:12px">
                  Sem dados de pesagem. O software desta máquina ainda não
                  reporta o volume.
                </p>
              <?php endif; ?>
            </div>

            <div>
              <h4 style="margin:0 0 8px;font-size:12px;color:var(--ambar)">AÇÕES</h4>
              <div style="margin-bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
                <?= botao_whatsapp($l, 'lic'.$l['id']) ?>
                <?php if (!empty($l['cliente_id'])): ?>
                  <form method="post" action="<?= e(linkLic()) ?>" style="display:inline">
                    <input type="hidden" name="acao" value="reenviar">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                    <button class="btn sec pequeno"
                            title="Reenvia a chave para o e-mail do cliente">
                      Reenviar por e-mail</button>
                  </form>
                <?php endif; ?>
              </div>
              <?php if ($l['status']!=='revogada'): ?>
                <form method="post" action="<?= e(linkLic()) ?>"
                      style="display:flex;gap:8px;align-items:flex-end;margin-bottom:12px">
                  <input type="hidden" name="acao" value="renovar">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="id" value="<?= $l['id'] ?>">
<div><label style="font-size:11px">Valor (R$)</label>
                    <input name="valor_renov" inputmode="decimal" required
                           style="width:90px"
                           placeholder="<?= $l['valor']
                               ? number_format((float)$l['valor'],2,',','.')
                               : 'opcional' ?>"></div>
                  <div><label style="font-size:11px">Renovar por</label>
                    <select name="meses_renov" required>
                      <option value="">— por quanto? —</option>
                      <?php foreach ([6  => '6 meses',
                                      12 => '12 meses',
                                      24 => '24 meses',
                                      36 => '36 meses',
                                      48 => '48 meses',
                                      60 => '60 meses'] as $rv => $rr): ?>
                        <option value="<?= $rv ?>"><?= $rr ?></option>
                      <?php endforeach; ?>
                    </select></div>
                  <button class="btn pequeno">Renovar</button>
                </form>

                <button type="button" class="btn sec pequeno"
                        onclick="alternar('edt<?= $l['id'] ?>')">Editar</button>
                <button type="button" class="btn perigo pequeno"
                        onclick="abrirRevogar(<?= $l['id'] ?>, '<?= e($l['chave']) ?>')">
                  Revogar</button>

                <div id="edt<?= $l['id'] ?>" style="display:none;margin-top:12px">
                  <form method="post" action="<?= e(linkLic()) ?>">
                    <input type="hidden" name="acao" value="editar">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                    <label style="font-size:11px">Cliente</label>
                    <select name="cliente_id">
                      <option value="">— sem cliente (estoque) —</option>
                      <?php foreach ($clientes as $c): ?>
                        <option value="<?= $c['id'] ?>"
                          <?= (int)$l['cliente_id']===(int)$c['id']?'selected':'' ?>>
                          <?= e($c['nome_fantasia'] ?: $c['razao_social']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <label style="font-size:11px;margin-top:8px">Revendedor</label>
                    <select name="revendedor_id">
                      <option value="">— venda direta —</option>
                      <?php foreach ($revTodos as $r): ?>
                        <option value="<?= $r['id'] ?>"
                          <?= (int)$l['revendedor_id']===(int)$r['id']?'selected':'' ?>>
                          <?= e($r['nome_fantasia'] ?: ($r['empresa'] ?: $r['nome'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <label style="font-size:11px;margin-top:8px">Limite de transferências</label>
                    <input type="number" name="max_transferencias" min="0" max="99"
                           value="<?= (int)($l['max_transferencias'] ?? 3) ?>">
                    <label style="font-size:11px;margin-top:8px">Anotação interna</label>
                    <input name="observacao" value="<?= e($l['observacao'] ?? '') ?>"
                           placeholder="não aparece para o cliente">
                    <div style="margin-top:10px">
                      <button class="btn pequeno">Salvar</button>
                      <button type="button" class="btn sec pequeno" style="margin-left:6px"
                              onclick="alternar('edt<?= $l['id'] ?>')">Cancelar</button>
                    </div>
                  </form>
                  <p class="subtitulo" style="margin:8px 0 0;font-size:10px">
                    Software, tipo e módulos não são editáveis: mudariam o que
                    foi contratado sem rastro. Para isso, revogue e emita outra.
                  </p>
                </div>
              <?php else: ?>
                <table style="font-size:11px">
                  <tr><td style="color:var(--texto-2)">Motivo</td>
                      <td><?= e($ROTULO_MOTIVO[$l['motivo_revogacao']] ?? 'não informado') ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Quando</td>
                      <td><?= $l['revogada_em']
                              ? date('d/m/Y', strtotime($l['revogada_em'])) : '—' ?></td></tr>
                  <tr><td style="color:var(--texto-2)">Por</td>
                      <td><?= e($l['revogada_por_nome'] ?: '—') ?></td></tr>
                </table>
                <?php if (!empty($l['obs_revogacao'])): ?>
                  <p style="font-size:11px;font-style:italic;margin-top:6px">
                    "<?= e($l['obs_revogacao']) ?>"</p>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>

          <?php $hh = $histLic[(int)$l['id']] ?? []; ?>
          <div style="margin-top:16px;border-top:1px solid var(--borda);
               padding-top:12px">
            <h4 style="margin:0 0 8px;font-size:12px;color:var(--ambar)">
              HISTÓRICO
              <span style="color:var(--texto-2);font-weight:normal">
                (<?= count($hh) ?> registro<?= count($hh)==1?'':'s' ?>)</span>
            </h4>

            <?php if (!$hh): ?>
              <p style="font-size:11px;color:var(--texto-2);margin:0">
                Nenhum evento registrado para esta licença.
              </p>
            <?php else: ?>
              <table style="font-size:11px">
                <thead><tr>
                  <th style="width:110px">Quando</th>
                  <th style="width:150px">Evento</th>
                  <th style="width:120px">Quem</th>
                  <th>Detalhe</th>
                </tr></thead>
                <tbody>
                <?php foreach ($hh as $h):
                    $cor = $h['resultado']==='ok' ? 'var(--texto-2)'
                         : ($h['resultado']==='negado' ? 'var(--vermelho)'
                                                       : 'var(--ambar)');
                ?>
                  <tr>
                    <td class="mono" style="color:var(--texto-2)">
                      <?= date('d/m/Y H:i', strtotime($h['criado_em'])) ?></td>
                    <td style="color:<?= $cor ?>">
                      <?= e($ROTULO_ACAO[$h['acao']] ?? $h['acao']) ?>
                      <?php if ($h['resultado'] !== 'ok'): ?>
                        · <?= e($h['resultado']) ?>
                      <?php endif; ?>
                    </td>
                    <td><?= e($h['usuario_nome'] ?: 'sistema') ?></td>
                    <td style="color:var(--texto-2)">
                      <?= e($h['detalhe'] ?: '') ?>
                      <?php if (!empty($h['fingerprint'])): ?>
                        <span class="mono" style="font-size:10px">
                          <?= e(substr($h['fingerprint'], 0, 14)) ?>…</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php if ($totalPaginas > 1): ?>
    <div style="display:flex;gap:8px;align-items:center;margin-top:16px;justify-content:center">
      <?php if ($pagina > 1): ?>
        <a class="btn sec pequeno" href="<?= e(linkLic(['pg'=>$pagina-1])) ?>">‹ Anterior</a>
      <?php endif; ?>
      <span class="subtitulo" style="margin:0">
        Página <?= $pagina ?> de <?= $totalPaginas ?></span>
      <?php if ($pagina < $totalPaginas): ?>
        <a class="btn sec pequeno" href="<?= e(linkLic(['pg'=>$pagina+1])) ?>">Próxima ›</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<!-- modal de revogacao: exige motivo antes de confirmar -->
<div id="modalRevogar" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,.6);z-index:50;align-items:center;justify-content:center">
  <div class="card" style="max-width:520px;width:92%;margin:0">
    <h3 style="margin-top:0">Revogar licença</h3>
    <p class="subtitulo" style="margin-top:-6px">
      O software do cliente deixará de funcionar na próxima revalidação.
      Chave: <span class="mono" id="mrChave"></span>
    </p>
    <form method="post" action="<?= e(linkLic()) ?>">
      <input type="hidden" name="acao" value="revogar">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" id="mrId">
      <label>Motivo *</label>
      <select name="motivo_revogacao" id="mrMotivo" required>
        <option value="">— selecione —</option>
        <?php foreach ($ROTULO_MOTIVO as $k => $rot): ?>
          <option value="<?= e($k) ?>"><?= e($rot) ?></option>
        <?php endforeach; ?>
      </select>
      <label style="margin-top:12px">
        Observação <span id="mrObrig" style="display:none">*</span></label>
      <textarea name="obs_revogacao" id="mrObs" style="min-height:70px"
                placeholder="Detalhe o que aconteceu (fica no histórico da licença)"></textarea>
      <div style="margin-top:14px">
        <button class="btn perigo">Confirmar revogação</button>
        <button type="button" class="btn sec" style="margin-left:8px"
                onclick="document.getElementById('modalRevogar').style.display='none'">
          Cancelar</button>
      </div>
    </form>
  </div>
</div>

<?= script_copiar_licenca() ?>
<script>
function filtrarPorProduto() {
  var prod = document.getElementById('produto_sel');
  var tier = document.getElementById('tier_id');
  if (!prod || !tier) return;
  var pid = prod.value;

  // Guarda a lista completa na primeira passada e reconstroi o select
  // a cada troca. O atributo 'hidden' em <option> nao e respeitado por
  // todos os navegadores, entao remover e recriar e o caminho seguro.
  if (!window._tiers) {
    window._tiers = [];
    for (var i = 0; i < tier.options.length; i++) {
      var o = tier.options[i];
      if (o.value) window._tiers.push({
        v: o.value, t: o.text.replace(/\s+/g, ' ').trim(),
        p: o.getAttribute('data-produto')
      });
    }
  }

  var anterior = tier.value;
  while (tier.options.length > 1) tier.remove(1);

  var achou = 0;
  for (var k = 0; k < window._tiers.length; k++) {
    var it = window._tiers[k];
    if (it.p !== pid) continue;
    var op = document.createElement('option');
    op.value = it.v;
    op.text = it.t;
    if (it.v === anterior) op.selected = true;
    tier.appendChild(op);
    achou++;
  }

  tier.disabled = !pid;
  tier.options[0].text = !pid ? '\u2014 escolha o software \u2014'
                     : (achou ? '\u2014 selecione \u2014'
                              : '\u2014 nenhum tipo cadastrado \u2014');

  // modulos marcados com um software so aparecem nele
  var cod = prod.options[prod.selectedIndex]
            ? prod.options[prod.selectedIndex].getAttribute('data-codigo') : '';
  var mods = document.querySelectorAll('label[data-produto]');
  for (var j = 0; j < mods.length; j++) {
    var dp = mods[j].getAttribute('data-produto');
    mods[j].style.display = (!dp || dp === cod) ? '' : 'none';
  }
}
document.addEventListener('DOMContentLoaded', filtrarPorProduto);

function alternar(id) {
  const el = document.getElementById(id);
  el.style.display = (el.style.display === 'none') ? '' : 'none';
}
function detalhe(id) {
  const el = document.getElementById('det' + id);
  el.style.display = (el.style.display === 'none') ? '' : 'none';
}
function abrirRevogar(id, chave) {
  document.getElementById('mrId').value = id;
  document.getElementById('mrChave').textContent = chave;
  document.getElementById('mrMotivo').value = '';
  document.getElementById('mrObs').value = '';
  document.getElementById('modalRevogar').style.display = 'flex';
}
document.addEventListener('DOMContentLoaded', function () {
  var sel = document.getElementById('mrMotivo');
  if (sel) sel.addEventListener('change', function () {
    var outro = this.value === 'outro';
    document.getElementById('mrObs').required = outro;
    document.getElementById('mrObrig').style.display = outro ? '' : 'none';
  });
});

function trocarDestino() {
  const revenda = document.querySelector('input[name=destino]:checked').value === 'revenda';
  document.getElementById('boxCliente').style.display = revenda ? 'none' : 'grid';
  document.getElementById('boxRevenda').style.display = revenda ? '' : 'none';
  document.getElementById('selCliente').required    = !revenda;
  document.getElementById('selRevendedor').required = revenda;
  const qtd = document.getElementById('fQtd');
  if (!revenda) { qtd.value = 1; qtd.readOnly = true; } else { qtd.readOnly = false; }
  document.getElementById('lblCliente').style.borderColor =
      revenda ? 'var(--borda)' : 'var(--ambar)';
  document.getElementById('lblRevenda').style.borderColor =
      revenda ? 'var(--ambar)' : 'var(--borda)';
}
document.addEventListener('DOMContentLoaded', trocarDestino);
</script>
<?php fecha_pagina();
