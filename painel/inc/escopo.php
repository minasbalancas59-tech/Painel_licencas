<?php
/**
 * =====================================================================
 *  Escopo por revendedor
 * =====================================================================
 *  O painel passa a ter dois tipos de visao:
 *
 *    admin      - ve tudo, de todos os revendedores, e emite licencas
 *    revendedor - ve SOMENTE o proprio estoque e os proprios clientes,
 *                 e nao emite licenca nenhuma
 *
 *  Inclua no topo das paginas, depois de inc/auth.php:
 *      require 'inc/escopo.php';
 *
 *  IMPORTANTE: antes desta mudanca, qualquer usuario logado via
 *  qualquer pagina (exceto usuarios.php) enxergava a carteira inteira.
 *  As funcoes abaixo existem para que isso nao dependa de o
 *  desenvolvedor lembrar de filtrar em cada consulta.
 * =====================================================================
 */

function eh_admin(): bool {
    $u = usuario_logado();
    return ($u['papel'] ?? '') === 'admin';
}

/** id do revendedor logado, ou null se for admin */
function revendedor_atual(): ?int {
    if (eh_admin()) return null;
    $u = usuario_logado();
    return isset($u['id']) ? (int)$u['id'] : null;
}

/** barra o acesso de quem nao e admin (use nas telas so suas) */
function exige_admin_escopo(): void {
    if (!eh_admin()) {
        http_response_code(403);
        exit('Acesso restrito ao administrador.');
    }
}

/**
 * Devolve [sqlWhere, args] para filtrar por revendedor.
 *
 *   $alias  - alias da tabela na consulta (ex: 'l' para licencas)
 *   $coluna - coluna que guarda o revendedor (padrao 'revendedor_id')
 *
 * Admin recebe ['', []] - sem restricao.
 * Revendedor recebe ['l.revendedor_id = ?', [id]].
 *
 * Uso tipico:
 *   [$wEsc, $aEsc] = escopo_where('l');
 *   if ($wEsc) { $where[] = $wEsc; $args = array_merge($args, $aEsc); }
 */
function escopo_where(string $alias, string $coluna = 'revendedor_id'): array {
    $rev = revendedor_atual();
    if ($rev === null) return ['', []];
    return ["$alias.$coluna = ?", [$rev]];
}

/**
 * Confere se uma licenca pertence ao usuario logado.
 * Admin passa sempre. Revendedor so na propria licenca.
 * Retorna a linha da licenca, ou encerra com 403.
 *
 * Use SEMPRE antes de qualquer acao que altere uma licenca vinda de
 * um id do formulario - sem isto, um revendedor poderia mandar o id
 * de uma licenca de outro na mao e mexer nela.
 */
function exige_licenca_do_usuario(int $licencaId): array {
    $st = db()->prepare(
        'SELECT l.*, c.razao_social,
                p.codigo AS produto_codigo,
                t.codigo AS tier_codigo, t.nome AS tier_nome
           FROM licencas l
           LEFT JOIN clientes c ON c.id = l.cliente_id
           LEFT JOIN produtos p ON p.id = l.produto_id
           LEFT JOIN tiers    t ON t.id = l.tier_id
          WHERE l.id = ?');
    $st->execute([$licencaId]);
    $lic = $st->fetch();

    if (!$lic) {
        http_response_code(404);
        exit('Licença não encontrada.');
    }
    $rev = revendedor_atual();
    if ($rev !== null && (int)$lic['revendedor_id'] !== $rev) {
        http_response_code(403);
        exit('Esta licença não pertence a você.');
    }
    return $lic;
}

/** mesma ideia para clientes */
function exige_cliente_do_usuario(int $clienteId): array {
    $st = db()->prepare('SELECT * FROM clientes WHERE id = ?');
    $st->execute([$clienteId]);
    $cli = $st->fetch();

    if (!$cli) {
        http_response_code(404);
        exit('Cliente não encontrado.');
    }
    $rev = revendedor_atual();
    if ($rev !== null && (int)$cli['revendedor_id'] !== $rev) {
        http_response_code(403);
        exit('Este cliente não pertence a você.');
    }
    return $cli;
}

/**
 * Rotulo curto da situacao da licenca, para o badge colorido.
 *
 * A data de vencimento e mostrada normalmente ao revendedor: o proprio
 * software avisa o cliente quando esta perto de expirar, entao esconder
 * do parceiro so atrapalharia o atendimento.
 */
function situacao_licenca(array $lic): array {
    if ($lic['status'] === 'revogada')  return ['Revogada', 'revogada'];

    $hoje   = new DateTime('today');
    $expira = new DateTime($lic['expira_em']);
    $dias   = (int)$hoje->diff($expira)->format('%r%a');
    $carencia = (int)($lic['carencia_dias'] ?? 15);

    if ($dias < 0) {
        if (abs($dias) <= $carencia) return ['Em carência', 'nova'];
        return ['Expirada', 'revogada'];
    }
    if ($dias <= 15) return ['Vence em breve', 'nova'];
    if (empty($lic['fingerprint'])) return ['Não ativada', 'nova'];
    return ['Ativa', 'ativa'];
}

/** quantas transferencias ainda restam nesta licenca */
function transferencias_restantes(array $lic): int {
    $max = (int)($lic['max_transferencias'] ?? 3);
    $uso = (int)($lic['transferencias'] ?? 0);
    return max(0, $max - $uso);
}
