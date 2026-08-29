<?php
/**
 * =====================================================================
 *  Configuracao de e-mail - ACRESCENTE ao seu api/lib/config.php
 * =====================================================================
 *  NAO substitua o config.php: ele ja tem DB_HOST, DB_USER, DB_PASS,
 *  DB_NAME e CHAVES_DIR. Cole apenas as linhas abaixo no final dele,
 *  antes do fechamento (se houver), e ajuste os valores.
 * =====================================================================
 */

// para quem vai o resumo diario de vencimentos
define('EMAIL_ADMIN', 'seu-email@minasbalancas.com.br');

// --- servidor de envio ------------------------------------------------
// Porta 587 = STARTTLS (o mais comum). Porta 465 = SSL direto.
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'seu-email@gmail.com');
define('SMTP_PASS', 'senha-de-app-de-16-letras');
define('SMTP_DE',   'seu-email@gmail.com');
define('SMTP_DE_NOME', 'Painel de Licenças Total Scale');

/* ---------------------------------------------------------------------
 *  QUAL PROVEDOR USAR
 *
 *  Gmail / Google Workspace
 *    host smtp.gmail.com, porta 587.
 *    A senha NAO e a da conta: e uma "senha de app", gerada em
 *    myaccount.google.com/apppasswords (exige verificacao em duas
 *    etapas ligada). Limite de ~500 envios/dia, de sobra aqui.
 *
 *  Zoho Mail (gratuito com dominio proprio)
 *    host smtp.zoho.com, porta 587.
 *
 *  Provedor da hospedagem do dominio
 *    normalmente smtp.seudominio.com.br, porta 587.
 *
 *  RECOMENDACAO: use um e-mail do seu proprio dominio como remetente.
 *  Avisos de cobranca saindo de um @gmail.com caem em spam com muito
 *  mais frequencia, e e justamente o e-mail que nao pode se perder.
 * ------------------------------------------------------------------- */
