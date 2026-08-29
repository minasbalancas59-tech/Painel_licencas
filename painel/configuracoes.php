<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
require_once __DIR__ . '/../api/lib/config_db.php';
require_once __DIR__ . '/../api/lib/smtp.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  CONFIGURACOES
 * =====================================================================
 *  O que o operador ajusta fica aqui. O config.php guarda apenas o que
 *  nao pode mudar em runtime: acesso ao banco e caminho das chaves.
 *
 *  A senha SMTP e guardada CIFRADA (ver api/lib/config_db.php) e nunca
 *  volta preenchida no formulario - o campo em branco significa
 *  "manter a atual".
 * ===================================================================== */

$msg=''; $tipo='';
$u = usuario_logado();

// ---- salvar ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='salvar') {
    if (!csrf_valido()) { $msg='Sessão inválida.'; $tipo='erro'; }
    else {
        try {
            cfg_salvar('email_admin',  trim($_POST['email_admin'] ?? ''),  false, $u['id']);
            cfg_salvar('smtp_host',    trim($_POST['smtp_host'] ?? ''),    false, $u['id']);
            cfg_salvar('smtp_porta',   (string)(int)($_POST['smtp_porta'] ?? 587), false, $u['id']);
            cfg_salvar('smtp_usuario', trim($_POST['smtp_usuario'] ?? ''), false, $u['id']);
            cfg_salvar('smtp_de',      trim($_POST['smtp_de'] ?? ''),      false, $u['id']);
            cfg_salvar('smtp_de_nome', trim($_POST['smtp_de_nome'] ?? ''), false, $u['id']);

            // campo em branco = manter a senha atual
            $senha = $_POST['smtp_senha'] ?? '';
            if ($senha !== '') cfg_salvar('smtp_senha', $senha, true, $u['id']);

            cfg_salvar('aviso_ativo',      isset($_POST['aviso_ativo'])?'1':'0', false, $u['id']);
            cfg_salvar('aviso_revendedor', isset($_POST['aviso_revendedor'])?'1':'0', false, $u['id']);

            // marcos: so numeros, ordenados do maior para o menor
            $marcos = array_filter(array_map('intval',
                        explode(',', $_POST['aviso_marcos'] ?? '')),
                        fn($n) => $n >= 0 && $n <= 365);
            rsort($marcos);
            cfg_salvar('aviso_marcos',
                       implode(',', array_unique($marcos)) ?: '30,15,7,0',
                       false, $u['id']);

            $msg='Configurações salvas.'; $tipo='ok';
        } catch (Throwable $e) {
            $msg='Erro ao salvar: '.$e->getMessage(); $tipo='erro';
        }
    }
}

// ---- teste de envio --------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='testar') {
    if (csrf_valido()) {
        $para = trim($_POST['email_teste'] ?? '') ?: cfg('email_admin');
        if ($para === '') { $msg='Informe um e-mail para o teste.'; $tipo='erro'; }
        else {
            $erro = null;
            $html = '<div style="font-family:Arial,sans-serif">'
                  . '<h2>Teste de envio</h2>'
                  . '<p>Se você está lendo isto, o servidor de e-mail do painel '
                  . 'de licenças está configurado corretamente.</p>'
                  . '<p style="color:#93a1ac;font-size:12px">Enviado em '
                  . date('d/m/Y H:i') . '.</p></div>';
            if (smtp_enviar($para, 'Teste do painel de licenças', $html, $erro)) {
                $msg = "E-mail de teste enviado para $para. "
                     . "Verifique a caixa de entrada e o spam.";
                $tipo='ok';
            } else {
                $msg = 'Falha no envio: ' . $erro; $tipo='erro';
            }
        }
    }
}

// recarrega apos salvar (o cache estatico de cfg_todas ficou velho)
$c = db()->query('SELECT chave, valor, cifrado FROM configuracoes')->fetchAll();
$conf = [];
foreach ($c as $r) {
    $conf[$r['chave']] = $r['cifrado'] ? '' : (string)($r['valor'] ?? '');
    if ($r['cifrado']) $conf['_tem_'.$r['chave']] = !empty($r['valor']);
}
function cv(array $conf, string $k, string $padrao=''): string {
    return e($conf[$k] ?? $padrao);
}

// última execução do cron, lida do log
$ultimoLog = '';
foreach (['/var/log/avisos_licenca.log'] as $arq) {
    if (is_readable($arq)) {
        $linhas = array_slice(file($arq, FILE_IGNORE_NEW_LINES), -6);
        $ultimoLog = implode("\n", $linhas);
    }
}

$avisosRecentes = [];
try {
    $avisosRecentes = db()->query(
      'SELECT a.marco, a.destino, a.enviado_em, l.chave
         FROM avisos_vencimento a
         LEFT JOIN licencas l ON l.id=a.licenca_id
        ORDER BY a.id DESC LIMIT 10')->fetchAll();
} catch (Throwable $e) { /* migracao 11 ainda nao aplicada */ }

abre_pagina('Configurações', 'config');
?>
<h1 class="titulo">Configurações</h1>
<p class="subtitulo">Servidor de e-mail e avisos automáticos de vencimento</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<form method="post">
  <input type="hidden" name="acao" value="salvar">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

  <div class="card">
    <h3>Servidor de e-mail (SMTP)</h3>
    <p class="subtitulo" style="margin-top:-6px">
      Usado para enviar os avisos de vencimento. Prefira um endereço do seu
      próprio domínio: aviso saindo de @gmail.com cai em spam com muito
      mais frequência.
    </p>

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
      <div><label>Servidor</label>
        <input name="smtp_host" value="<?= cv($conf,'smtp_host') ?>"
               placeholder="smtp.seudominio.com.br"></div>
      <div><label>Porta</label>
        <input name="smtp_porta" type="number" value="<?= cv($conf,'smtp_porta','587') ?>">
        <span class="subtitulo" style="margin:4px 0 0;display:block;font-size:11px">
          587 = STARTTLS · 465 = SSL</span></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px">
      <div><label>Usuário</label>
        <input name="smtp_usuario" value="<?= cv($conf,'smtp_usuario') ?>"></div>
      <div>
        <label>Senha
          <?php if (!empty($conf['_tem_smtp_senha'])): ?>
            <span style="color:var(--verde);text-transform:none">· já configurada</span>
          <?php endif; ?>
        </label>
        <input name="smtp_senha" type="password" autocomplete="new-password"
               placeholder="<?= !empty($conf['_tem_smtp_senha'])
                    ? 'deixe em branco para manter' : 'senha ou senha de app' ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px">
      <div><label>Remetente (e-mail)</label>
        <input name="smtp_de" value="<?= cv($conf,'smtp_de') ?>"
               placeholder="igual ao usuário, se vazio"></div>
      <div><label>Remetente (nome exibido)</label>
        <input name="smtp_de_nome" value="<?= cv($conf,'smtp_de_nome','Painel de Licenças') ?>"></div>
    </div>
  </div>

  <div class="card">
    <h3>Avisos de vencimento</h3>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
      <div><label>Enviar resumo diário para</label>
        <input name="email_admin" type="email" value="<?= cv($conf,'email_admin') ?>"
               placeholder="seu-email@minasbalancas.com.br"></div>
      <div><label>Avisar quantos dias antes</label>
        <input name="aviso_marcos" value="<?= cv($conf,'aviso_marcos','30,15,7,0') ?>"
               placeholder="30,15,7,0">
        <span class="subtitulo" style="margin:4px 0 0;display:block;font-size:11px">
          separados por vírgula · 0 = no dia</span></div>
    </div>

    <div style="margin-top:14px;display:flex;gap:24px;flex-wrap:wrap">
      <label style="display:flex;align-items:center;gap:8px;text-transform:none;margin:0">
        <input type="checkbox" name="aviso_ativo" style="width:auto"
               <?= ($conf['aviso_ativo'] ?? '1')==='1' ? 'checked' : '' ?>>
        Avisos automáticos ligados
      </label>
      <label style="display:flex;align-items:center;gap:8px;text-transform:none;margin:0">
        <input type="checkbox" name="aviso_revendedor" style="width:auto"
               <?= ($conf['aviso_revendedor'] ?? '1')==='1' ? 'checked' : '' ?>>
        Avisar também cada revendedor sobre os clientes dele
      </label>
    </div>

    <div style="margin-top:16px">
      <button class="btn">Salvar configurações</button>
    </div>
  </div>
</form>

<div class="card">
  <h3>Testar envio</h3>
  <p class="subtitulo" style="margin-top:-6px">
    Salve as configurações antes de testar.
  </p>
  <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="acao" value="testar">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div style="flex:1;min-width:240px">
      <label>Enviar teste para</label>
      <input name="email_teste" type="email"
             placeholder="<?= cv($conf,'email_admin') ?: 'e-mail de destino' ?>">
    </div>
    <button class="btn sec">Enviar e-mail de teste</button>
  </form>
</div>

<div class="card">
  <h3>Agendamento</h3>
  <p class="subtitulo" style="margin-top:-6px">
    Os avisos só saem se o cron estiver agendado no servidor. Rode uma vez,
    por SSH:
  </p>
  <div class="codigo" style="font-size:11px">0 8 * * * /usr/bin/php /var/www/licenca/cron/avisar_vencimentos.php >> /var/log/avisos_licenca.log 2>&1</div>
  <?php if ($ultimoLog): ?>
    <h3 style="margin-top:18px;font-size:13px">Últimas execuções</h3>
    <pre style="font-size:11px;color:var(--texto-2);white-space:pre-wrap;margin:0"><?= e($ultimoLog) ?></pre>
  <?php else: ?>
    <p class="subtitulo" style="margin:12px 0 0">
      Nenhum registro em /var/log/avisos_licenca.log ainda.
    </p>
  <?php endif; ?>
</div>

<?php if ($avisosRecentes): ?>
<div class="card">
  <h3>Avisos enviados recentemente</h3>
  <table>
    <thead><tr><th>Quando</th><th>Chave</th><th>Marco</th><th>Destino</th></tr></thead>
    <tbody>
    <?php foreach ($avisosRecentes as $a): ?>
      <tr>
        <td class="mono" style="font-size:11px">
          <?= date('d/m/Y H:i', strtotime($a['enviado_em'])) ?></td>
        <td class="mono" style="font-size:11px"><?= e($a['chave'] ?: '—') ?></td>
        <td><?= $a['marco']==='vencida' ? 'vencida' : $a['marco'].' dias' ?></td>
        <td style="font-size:11px"><?= e($a['destino'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php fecha_pagina();
