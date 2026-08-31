<?php
/**
 * =====================================================================
 *  Mensagem de entrega da licença
 * =====================================================================
 *  Monta o texto que você envia ao cliente pelo WhatsApp com a chave e
 *  o passo a passo da ativação.
 *
 *  Existe porque a alternativa é digitar tudo à mão a cada emissão — e
 *  o cliente que recebe uma chave solta, sem instrução, liga no suporte
 *  perguntando o que fazer com ela.
 *
 *  O texto usa o negrito do WhatsApp (*asterisco*) e evita acentos nos
 *  rótulos: alguns aparelhos antigos trocam a acentuação ao encaminhar.
 * =====================================================================
 */

/**
 * @param array $lic linha de `licencas` com os JOINs de cliente,
 *                   produto e tier já resolvidos
 */
function mensagem_licenca(array $lic): string {
    $q = "\n";

    $cliente = $lic['nome_fantasia'] ?? ($lic['cli_fantasia'] ?? null);
    if (!$cliente) $cliente = $lic['razao_social'] ?? '';

    $produto = strtoupper($lic['produto_codigo'] ?? '');
    $nomeProduto = $produto === 'TS5'   ? 'Total Scale 5'
                 : ($produto === 'TS6'  ? 'Total Scale 6'
                 : ($produto === 'TSLPR'? 'TS LPR' : $produto));

    $t  = '*LICENCA ' . ($nomeProduto ?: 'TOTAL SCALE') . '*' . $q . $q;

    /* ---------------------------------------------------------------
     * Licenca SEM cliente = estoque do revendedor. A mensagem de
     * ativacao nao serve aqui: ele nao vai ativar nada, precisa e
     * vincular ao cliente dele antes de repassar. Enviar o passo a
     * passo agora faria ele ativar na propria maquina para "testar" -
     * e queimar a licenca.
     * --------------------------------------------------------------- */
    if (empty($lic['cliente_id'])) {
        if (!empty($lic['tier_nome'])) $t .= 'Tipo: ' . $lic['tier_nome'] . $q;
        if (!empty($lic['modulos']))   $t .= 'Modulos: ' . $lic['modulos'] . $q;
        if (($lic['tipo_licenca'] ?? '') === 'demo')
            $t .= 'Uso: DEMONSTRACAO' . $q;

        $t .= $q . '*CHAVE*' . $q . $lic['chave'] . $q . $q;

        $t .= '*ESTA LICENCA ESTA NO SEU ESTOQUE*' . $q . $q;
        $t .= '1) No painel, va em Minhas licencas' . $q;
        $t .= '2) Cadastre o cliente em Meus clientes, se ainda nao existir' . $q;
        $t .= '3) Vincule esta chave ao cliente' . $q;
        $t .= '4) So entao repasse a chave para ele ativar' . $q . $q;

        $t .= 'Vincular antes de repassar mantem o cadastro correto e '
            . 'permite que voce libere a maquina se o PC do cliente queimar.';
        return $t;
    }

    if ($cliente) $t .= 'Cliente: ' . $cliente . $q;
    if (!empty($lic['tier_nome'])) $t .= 'Tipo: ' . $lic['tier_nome'] . $q;
    if (!empty($lic['modulos']))   $t .= 'Modulos: ' . $lic['modulos'] . $q;

    $t .= $q;

    $t .= '*CHAVE DE REGISTRO*' . $q;
    $t .= $lic['chave'] . $q . $q;

    $t .= '*COMO ATIVAR*' . $q;
    $t .= '1) Abra o ' . ($nomeProduto ?: 'sistema') . $q;
    $t .= '2) Na tela de registro, cole a chave acima no campo '
        . '"Ativacao online"' . $q;
    $t .= '3) Clique em Ativar online' . $q . $q;

    $t .= 'A chave vale para UMA maquina. Ao ativar, ela fica vinculada '
        . 'ao computador. Se precisar trocar de PC, fale com a gente antes.'
        . $q . $q;

    $t .= '*SEM INTERNET NA MAQUINA?*' . $q;
    $t .= 'Na mesma tela, clique em "Copiar codigo", envie o codigo da '
        . 'maquina para nos, e devolvemos o codigo de ativacao offline.';

    return $t;
}

/**
 * Botão que copia a mensagem. Cada chamada precisa de um id único,
 * porque a mesma página lista várias licenças.
 */
function botao_whatsapp(array $lic, string $id, string $rotulo = ''): string {
    $texto = mensagem_licenca($lic);
    $json  = htmlspecialchars(json_encode($texto, JSON_UNESCAPED_UNICODE),
                              ENT_QUOTES, 'UTF-8');
    return '<button type="button" class="btn sec pequeno" '
         . 'data-msg="' . $json . '" '
         . 'onclick="copiarLicenca(this)" '
         . 'title="Copia a chave e o passo a passo para enviar ao cliente">'
         . ($rotulo !== '' ? $rotulo : 'Copiar p/ WhatsApp') . '</button>';
}

/** Script de cópia — inclua uma vez por página. */
function script_copiar_licenca(): string {
    return <<<'HTML'
<script>
function copiarLicenca(btn) {
  var txt = JSON.parse(btn.getAttribute('data-msg'));
  var ok = function () {
    var antes = btn.textContent;
    btn.textContent = 'Copiado!';
    setTimeout(function () { btn.textContent = antes; }, 1800);
  };
  // navigator.clipboard so existe em HTTPS; o fallback cobre o resto
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(txt).then(ok);
  } else {
    var ta = document.createElement('textarea');
    ta.value = txt;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); ok(); } catch (e) {}
    document.body.removeChild(ta);
  }
}
</script>
HTML;
}
