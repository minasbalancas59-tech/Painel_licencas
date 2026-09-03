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
 * Link fixo de download do produto — sempre a versão atual.
 *
 * Devolve string vazia se não houver instalador publicado: melhor
 * omitir o bloco do que mandar um link que dá erro.
 *
 * O link é do PRODUTO, não da versão. Um cliente que guardar a
 * mensagem e reinstalar daqui a um ano baixa a versão nova, não a
 * de hoje.
 */
function link_instalador(?string $produtoCodigo): string {
    static $cache = [];
    if (!$produtoCodigo) return '';
    if (isset($cache[$produtoCodigo])) return $cache[$produtoCodigo];

    $url = '';
    try {
        $st = db()->prepare(
          'SELECT p.token_download
             FROM produtos p
             JOIN versoes v ON v.produto_id = p.id
                           AND v.atual = 1 AND v.publicada = 1
            WHERE p.codigo = ? LIMIT 1');
        $st->execute([$produtoCodigo]);
        if ($tok = $st->fetchColumn()) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $url = ($https ? 'https://' : 'http://')
                 . ($_SERVER['HTTP_HOST'] ?? 'licencas.totalscale.com.br')
                 . '/baixar.php?p=' . $tok;
        }
    } catch (Throwable $e) { /* migração 20 ainda não aplicada */ }

    return $cache[$produtoCodigo] = $url;
}

/** Bloco institucional, igual em todas as mensagens. */
function rodape_institucional(): string {
    $q = "\n";
    return $q . '---' . $q
         . '*TOTAL SCALE*' . $q
         . 'Software de pesagem e automacao para balancas rodoviarias.' . $q
         . 'Controle de cargas em mineracao, agro, reciclagem e transporte.'
         . $q . $q
         . 'Precisa de mais alguma coisa? Fale com a gente.' . $q
         . '(31) 3357-4000 · www.totalscale.com.br';
}

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
    $t .= 'Obrigado por escolher o Total Scale. Sua licenca esta pronta.'
        . $q . $q;

    /* ---------------------------------------------------------------
     * Licenca SEM cliente = estoque do revendedor. A mensagem de
     * ativacao nao serve aqui: ele nao vai ativar nada, precisa e
     * vincular ao cliente dele antes de repassar. Enviar o passo a
     * passo agora faria ele ativar na propria maquina para "testar" -
     * e queimar a licenca.
     * --------------------------------------------------------------- */
    if (empty($lic['cliente_id'])) {
        if (!empty($lic['tier_nome'])) $t .= 'Tipo: ' . $lic['tier_nome'] . $q;
        if (!empty($lic['emitido_em']))
            $t .= 'Emitida em: ' . date('d/m/Y', strtotime($lic['emitido_em'])) . $q;
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
            . 'permite que voce libere a maquina se o PC do cliente queimar.'
            . $q;

        $dlE = link_instalador($lic['produto_codigo'] ?? null);
        if ($dlE !== '')
            $t .= $q . '*BAIXAR O SISTEMA*' . $q . $dlE . $q;

        $t .= rodape_institucional();
        return $t;
    }

    if ($cliente) $t .= 'Cliente: ' . $cliente . $q;
    if (!empty($lic['tier_nome'])) $t .= 'Tipo: ' . $lic['tier_nome'] . $q;
    if (!empty($lic['modulos']))   $t .= 'Modulos: ' . $lic['modulos'] . $q;
    if (!empty($lic['emitido_em']))
        $t .= 'Emitida em: ' . date('d/m/Y', strtotime($lic['emitido_em'])) . $q;

    $t .= $q;

    $t .= '*CHAVE DE REGISTRO*' . $q;
    $t .= $lic['chave'] . $q . $q;

    $dl = link_instalador($lic['produto_codigo'] ?? null);
    if ($dl !== '')
        $t .= '*BAIXAR O SISTEMA*' . $q . $dl . $q . $q;

    $t .= '*COMO ATIVAR*' . $q;
    $t .= '1) ' . ($dl !== '' ? 'Instale o ' : 'Abra o ')
        . ($nomeProduto ?: 'sistema') . $q;
    $t .= '2) Na tela de registro, cole a chave acima no campo '
        . '"Ativacao online"' . $q;
    $t .= '3) Clique em Ativar online' . $q . $q;

    $t .= 'A chave vale para UMA maquina. Ao ativar, ela fica vinculada '
        . 'ao computador. Se precisar trocar de PC, fale com a gente antes.'
        . $q . $q;

    $t .= '*SEM INTERNET NA MAQUINA?*' . $q;
    $t .= 'Na mesma tela, clique em "Copiar codigo", envie o codigo da '
        . 'maquina para nos, e devolvemos o codigo de ativacao offline.'
        . $q;

    $t .= rodape_institucional();

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


/**
 * Mensagem para um LOTE de licenças.
 *
 * Enviar dez mensagens separadas de uma remessa faria o revendedor
 * perder chaves na rolagem da conversa. Uma mensagem só, numerada,
 * é o que ele consegue guardar e conferir.
 *
 * @param array $lics linhas de licenças já com os JOINs resolvidos
 */
function mensagem_lote(array $lics): string {
    if (!$lics) return '';
    if (count($lics) === 1) return mensagem_licenca($lics[0]);

    $q = "\n";
    $p = $lics[0];

    $produto = strtoupper($p['produto_codigo'] ?? '');
    $nomeProduto = $produto === 'TS5'   ? 'Total Scale 5'
                 : ($produto === 'TS6'  ? 'Total Scale 6'
                 : ($produto === 'TSLPR'? 'TS LPR' : $produto));

    $t  = '*' . count($lics) . ' LICENCAS ' . ($nomeProduto ?: 'TOTAL SCALE')
        . '*' . $q . $q;

    if (!empty($p['tier_nome'])) $t .= 'Tipo: ' . $p['tier_nome'] . $q;
    if (!empty($p['modulos']))   $t .= 'Modulos: ' . $p['modulos'] . $q;
    if (!empty($p['emitido_em']))
        $t .= 'Emitidas em: ' . date('d/m/Y', strtotime($p['emitido_em'])) . $q;

    $t .= $q . '*CHAVES*' . $q;
    $i = 1;
    foreach ($lics as $l) {
        $t .= $i . ') ' . $l['chave'] . $q;
        $i++;
    }

    // lote sempre vai para estoque: se tivesse cliente, seria uma só
    $t .= $q . '*ESTAS LICENCAS ESTAO NO SEU ESTOQUE*' . $q . $q;
    $t .= '1) No painel, va em Minhas licencas' . $q;
    $t .= '2) Cadastre o cliente em Meus clientes, se ainda nao existir' . $q;
    $t .= '3) Vincule uma chave ao cliente' . $q;
    $t .= '4) So entao repasse aquela chave para ele ativar' . $q . $q;

    $t .= 'Cada chave vale para UMA maquina. Vincular antes de repassar '
        . 'mantem o cadastro correto e permite liberar a maquina se o PC '
        . 'do cliente queimar.' . $q;

    $dlL = link_instalador($p['produto_codigo'] ?? null);
    if ($dlL !== '')
        $t .= $q . '*BAIXAR O SISTEMA*' . $q . $dlL . $q;

    $t .= rodape_institucional();

    return $t;
}

/** Botão que copia a mensagem do lote inteiro. */
function botao_whatsapp_lote(array $lics, string $rotulo = ''): string {
    $texto = mensagem_lote($lics);
    $json  = htmlspecialchars(json_encode($texto, JSON_UNESCAPED_UNICODE),
                              ENT_QUOTES, 'UTF-8');
    if ($rotulo === '')
        $rotulo = 'Copiar as ' . count($lics) . ' chaves p/ WhatsApp';
    return '<button type="button" class="btn" '
         . 'data-msg="' . $json . '" onclick="copiarLicenca(this)" '
         . 'title="Copia todas as chaves numa mensagem só">'
         . $rotulo . '</button>';
}


/**
 * Mensagem de RENOVAÇÃO.
 *
 * Texto próprio, não o de emissão: o cliente já tem o sistema
 * instalado e não vai ativar nada. O que ele precisa saber é a
 * validade nova e que ela chega sozinha — sem isso, liga perguntando
 * por que a tela ainda mostra a data antiga.
 *
 * @param array  $lic         licença com os JOINs resolvidos
 * @param string $validadeAnt data anterior, formato Y-m-d
 * @param string $tierAntes   preenchido só quando houve troca de tipo
 */
function mensagem_renovacao(array $lic, string $validadeAnt = '',
                            string $tierAntes = ''): string {
    $q = "\n";

    $cliente = $lic['nome_fantasia'] ?? ($lic['cli_fantasia'] ?? null);
    if (!$cliente) $cliente = $lic['razao_social'] ?? '';

    $produto = strtoupper($lic['produto_codigo'] ?? '');
    $nomeProduto = $produto === 'TS5'   ? 'Total Scale 5'
                 : ($produto === 'TS6'  ? 'Total Scale 6'
                 : ($produto === 'TSLPR'? 'TS LPR' : $produto));

    $t = '*LICENCA RENOVADA*' . $q . $q;

    if ($cliente) $t .= 'Cliente: ' . $cliente . $q;
    $t .= 'Software: ' . ($nomeProduto ?: 'Total Scale') . $q;

    if ($tierAntes !== '' && !empty($lic['tier_nome']))
        $t .= 'Tipo: ' . $tierAntes . ' -> *' . $lic['tier_nome'] . '*' . $q;
    elseif (!empty($lic['tier_nome']))
        $t .= 'Tipo: ' . $lic['tier_nome'] . $q;

    if (!empty($lic['modulos'])) $t .= 'Modulos: ' . $lic['modulos'] . $q;

    $t .= $q . '*NOVA VALIDADE*' . $q;
    if ($validadeAnt !== '')
        $t .= date('d/m/Y', strtotime($validadeAnt)) . '  ->  ';
    $t .= '*' . date('d/m/Y', strtotime($lic['expira_em'])) . '*' . $q . $q;

    $t .= '*Registro:* ' . $lic['chave'] . $q . $q;

    /* O ponto que evita o chamado: a data no sistema não muda na hora.
       Sem este aviso, o cliente abre o Total Scale, vê a validade
       antiga e liga achando que a renovação não foi feita. */
    $t .= 'A nova validade chega ao sistema automaticamente em ate 7 '
        . 'dias, desde que a maquina tenha internet.' . $q . $q
        . 'Para atualizar na hora: abra o sistema, va em *Ajuda > '
        . 'Registro do sistema* e clique em *Buscar no servidor*.';

    $t .= rodape_institucional();

    return $t;
}

/** Botão que copia a mensagem de renovação. */
function botao_whatsapp_renovacao(array $lic, string $validadeAnt = '',
                                  string $tierAntes = '',
                                  string $rotulo = ''): string {
    $texto = mensagem_renovacao($lic, $validadeAnt, $tierAntes);
    $json  = htmlspecialchars(json_encode($texto, JSON_UNESCAPED_UNICODE),
                              ENT_QUOTES, 'UTF-8');
    return '<button type="button" class="btn" '
         . 'data-msg="' . $json . '" onclick="copiarLicenca(this)" '
         . 'title="Copia o aviso de renovacao para enviar ao cliente">'
         . ($rotulo !== '' ? $rotulo : 'Copiar aviso p/ WhatsApp')
         . '</button>';
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
