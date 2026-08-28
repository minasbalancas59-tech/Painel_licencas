<?php
require 'inc/auth.php';
require 'inc/layout.php';
exige_login();

$msg=''; $tipo=''; $codigoAtivacao='';

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='gerar') {
    if (!csrf_valido()) { $msg='Sessão inválida.'; $tipo='erro'; }
    else {
        $chave = strtoupper(trim($_POST['chave'] ?? ''));
        $fp    = trim($_POST['fingerprint'] ?? '');
        $u     = usuario_logado();

        if ($chave==='' || $fp==='') { $msg='Informe a chave e o código da máquina.'; $tipo='erro'; }
        else {
            $st = db()->prepare(
              'SELECT l.*, c.razao_social, c.cnpj,
                      p.codigo AS produto_codigo,
                      t.codigo AS tier_codigo, t.nivel AS tier_nivel
                 FROM licencas l
                 JOIN clientes c ON c.id=l.cliente_id
                 LEFT JOIN produtos p ON p.id=l.produto_id
                 LEFT JOIN tiers    t ON t.id=l.tier_id
                WHERE l.chave=? LIMIT 1');
            $st->execute([$chave]);
            $lic = $st->fetch();

            if (!$lic) { $msg='Chave de licença não encontrada.'; $tipo='erro';
                log_acao(null,$chave,$fp,'gerar_offline','negado','chave inexistente'); }
            elseif ($lic['status']==='revogada') { $msg='Esta licença está revogada.'; $tipo='erro';
                log_acao($lic['id'],$chave,$fp,'gerar_offline','negado','revogada'); }
            elseif (strtotime($lic['expira_em']) < strtotime(date('Y-m-d'))) {
                $msg='Esta licença está expirada.'; $tipo='erro';
                log_acao($lic['id'],$chave,$fp,'gerar_offline','negado','expirada'); }
            elseif (!empty($lic['fingerprint']) && $lic['fingerprint']!==$fp) {
                $msg='Esta licença já foi ativada em outra máquina.'; $tipo='erro';
                log_acao($lic['id'],$chave,$fp,'gerar_offline','negado','outra maquina'); }
            else {
                // vincula a maquina se ainda nao vinculada
                if (empty($lic['fingerprint'])) {
                    db()->prepare(
                      'UPDATE licencas SET fingerprint=?, status="ativa",
                              tipo_ativacao="offline", ativada_em=NOW() WHERE id=?')
                        ->execute([$fp,$lic['id']]);
                }

                $carencia = (int)($lic['carencia_dias'] ?? 15);
                // v2 se tem produto/tier; senao v1 (licencas antigas)
                if (!empty($lic['produto_codigo']) && !empty($lic['tier_codigo'])) {
                    $codigoAtivacao = emitir_licenca_assinada_v2([
                        'cliente'=>$lic['razao_social'], 'cnpj'=>$lic['cnpj'],
                        'chave'=>$lic['chave'], 'fingerprint'=>$fp,
                        'produto'=>$lic['produto_codigo'], 'tier'=>$lic['tier_codigo'],
                        'nivel'=>(int)$lic['tier_nivel'], 'modulos'=>$lic['modulos'],
                        'emitido'=>date('Y-m-d'), 'expira'=>$lic['expira_em'],
                        'carencia'=>$carencia,
                    ]);
                } else {
                    $codigoAtivacao = emitir_licenca_assinada([
                        'cliente'=>$lic['razao_social'], 'cnpj'=>$lic['cnpj'],
                        'chave'=>$lic['chave'], 'fingerprint'=>$fp,
                        'modulos'=>$lic['modulos'], 'emitido'=>date('Y-m-d'),
                        'expira'=>$lic['expira_em'],
                    ]);
                }

                // log estendido: quem gerou + produto/tier
                log_acao_painel($lic['id'],$chave,$fp,'gerar_offline','ok',
                    $u['id'], $u['nome'] ?? null,
                    $lic['produto_codigo'] ?? null, $lic['tier_codigo'] ?? null, '');
                $msg='Código de ativação gerado. Entregue ao cliente.'; $tipo='ok';
            }
        }
    }
}

abre_pagina('Ativação offline', 'offline');
?>
<h1 class="titulo">Ativação offline</h1>
<p class="subtitulo">Para PCs de cliente sem acesso à internet</p>

<div class="card">
  <h3>Como funciona</h3>
  <p style="color:var(--texto-2);font-size:13px;line-height:1.7;margin:0">
    1. No Total Scale do cliente, abra a tela de licença e copie o <b style="color:var(--texto)">Código da Máquina</b>.<br>
    2. Peça esse código ao cliente (telefone, e-mail ou WhatsApp) junto com a chave da licença.<br>
    3. Cole os dois abaixo e clique em <b style="color:var(--texto)">Gerar código de ativação</b>.<br>
    4. Envie o código gerado de volta ao cliente. Ele cola no Total Scale e o sistema é liberado.
  </p>
</div>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
  <h3>Gerar ativação</h3>
  <form method="post">
    <input type="hidden" name="acao" value="gerar">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <label>Chave da licença</label>
    <input name="chave" placeholder="TS6X-XXXX-XXXX-XXXX" style="font-family:var(--mono)" required>
    <label>Código da máquina (fornecido pelo cliente)</label>
    <textarea name="fingerprint" placeholder="Cole aqui o código exibido no Total Scale do cliente" required></textarea>
    <button class="btn">Gerar código de ativação</button>
  </form>

  <?php if ($codigoAtivacao): ?>
    <label style="margin-top:24px">Código de ativação — envie ao cliente</label>
    <div class="codigo" id="cod"><?= e($codigoAtivacao) ?></div>
    <button type="button" class="btn sec pequeno" style="margin-top:12px"
      onclick="navigator.clipboard.writeText(document.getElementById('cod').innerText);this.textContent='Copiado!'">
      Copiar código
    </button>
  <?php endif; ?>
</div>
<?php fecha_pagina();
