<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

$msg=''; $tipo=''; $chaveGerada='';

// --- emitir nova licenca (v2: produto + tier) ----------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='emitir') {
    if (!csrf_valido()) { $msg='Sessão inválida.'; $tipo='erro'; }
    else {
        $cliId    = (int)($_POST['cliente_id'] ?? 0);
        $tierId   = (int)($_POST['tier_id'] ?? 0);
        $meses    = (int)($_POST['meses'] ?? 12);
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
        $modsCsv  = implode(',', array_map(fn($m)=>preg_replace('/[^A-Z]/','',$m), $mods));

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

                // grava a(s) licenca(s) - fingerprint fica NULL ate a ativacao
                $st = db()->prepare(
                  'INSERT INTO licencas
                     (cliente_id,revendedor_id,produto_id,tier_id,chave,modulos,
                      emitido_em,expira_em,carencia_dias,status,tipo_licenca,criado_por)
                   VALUES (?,?,?,?,?,?,?,?,?,"nova",?,?)');

                for ($i = 0; $i < $qtd; $i++) {
                    $chave = gerar_chave_licenca();
                    $st->execute([
                        ($cliId ?: null), $revId,
                        $t['produto_id'],   // vem do JOIN em resolver_tier()
                        $tierId, $chave, ($modsCsv ?: ''),
                        $emit, $exp, $carencia, $tipoLic, $u['id']
                    ]);
                    $licId = (int)db()->lastInsertId();
                    $geradas[] = $chave;

                    log_acao_painel(
                        $licId, $chave, null, 'emitir', 'ok',
                        $u['id'], $u['nome'] ?? null,
                        $t['produto_codigo'], $t['tier_codigo'],
                        "validade {$meses}m, carencia {$carencia}d, {$tipoLic}"
                        . ($revId ? ", revendedor {$revId}" : ''));
                }

                $chaveGerada = implode("\n", $geradas);
                $msg = count($geradas) . " licença(s) emitida(s) "
                     . "({$t['produto_codigo']} · {$t['tier_codigo']}"
                     . ($tipoLic === 'demo' ? ' · demonstração' : '') . ").";
                $tipo='ok';
            } catch (Throwable $ex) {
                $msg='Erro ao emitir: '.$ex->getMessage(); $tipo='erro';
            }
        }
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

            $msg = 'Licenca revogada.'; $tipo = 'ok';
        }
    }
}

// rotulos legiveis dos motivos (usados no form e na listagem)
$ROTULO_MOTIVO = [
    'inadimplencia' => 'Inadimplência',
    'cancelamento'  => 'Cancelamento pelo cliente',
    'troca_licenca' => 'Substituída por outra licença',
    'uso_indevido'  => 'Uso indevido',
    'erro_emissao'  => 'Erro na emissão',
    'outro'         => 'Outro',
];

$clientes = db()->query('SELECT id,razao_social FROM clientes ORDER BY razao_social')->fetchAll();
$preselect = (int)($_GET['cliente'] ?? 0);

// catalogo de produtos e tiers para os selects encadeados
$produtos = db()->query('SELECT id,codigo,nome FROM produtos WHERE ativo=1 ORDER BY codigo')->fetchAll();
$tiers    = db()->query(
  'SELECT id,produto_id,codigo,nome,nivel FROM tiers WHERE ativo=1
    ORDER BY produto_id, nivel')->fetchAll();

// LEFT JOIN em clientes: licenca em estoque do revendedor ainda nao tem
// cliente. Com JOIN simples, essas licencas sumiriam da lista.
$licencas = db()->query(
  'SELECT l.*, c.razao_social, u.nome AS revendedor_nome,
          ur.nome AS revogada_por_nome,
          p.codigo AS produto_codigo, t.codigo AS tier_codigo, t.nome AS tier_nome
     FROM licencas l
     LEFT JOIN clientes c ON c.id=l.cliente_id
     LEFT JOIN usuarios u ON u.id=l.revendedor_id
     LEFT JOIN usuarios ur ON ur.id=l.revogada_por
     LEFT JOIN produtos p ON p.id=l.produto_id
     LEFT JOIN tiers t    ON t.id=l.tier_id
    ORDER BY l.id DESC LIMIT 200')->fetchAll();

// revendedores para o select de atribuicao
// so revendedores ATIVOS podem receber licenca nova
$revendedores = db()->query(
  "SELECT id, nome, empresa, nome_fantasia FROM usuarios
    WHERE papel='revendedor' AND ativo=1
    ORDER BY COALESCE(nome_fantasia,empresa,nome)")->fetchAll();

abre_pagina('Licenças', 'licencas');
?>
<h1 class="titulo">Licenças</h1>
<p class="subtitulo">Emita chaves de ativação e acompanhe o uso</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>
<?php if ($chaveGerada): ?>
  <div class="card">
    <h3>Chave gerada</h3>
    <div class="codigo"><?= e($chaveGerada) ?></div>
    <p class="subtitulo" style="margin-top:12px">
      O cliente digita esta chave no Total Scale (ativação online) ou você a usa
      na aba <a href="offline.php">Ativação offline</a> se o PC dele não tiver internet.
    </p>
  </div>
<?php endif; ?>

<div class="card">
  <h3>Emitir nova licença</h3>
  <form method="post">
    <input type="hidden" name="acao" value="emitir">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <label>Destino da licença</label>
    <div style="display:flex;gap:10px;margin-bottom:16px">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;
             border:1px solid var(--borda);border-radius:var(--radius);
             padding:10px 16px;flex:1" id="lblCliente">
        <input type="radio" name="destino" value="cliente" checked
               onchange="trocarDestino()" style="width:auto;margin:0">
        <span>
          <b>Cliente final</b><br>
          <span class="subtitulo" style="margin:0;font-size:11px">
            venda direta sua, já vinculada ao cliente</span>
        </span>
      </label>
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;
             border:1px solid var(--borda);border-radius:var(--radius);
             padding:10px 16px;flex:1" id="lblRevenda">
        <input type="radio" name="destino" value="revenda"
               onchange="trocarDestino()" style="width:auto;margin:0">
        <span>
          <b>Revenda</b><br>
          <span class="subtitulo" style="margin:0;font-size:11px">
            vai para o estoque do revendedor</span>
        </span>
      </label>
    </div>

    <div id="boxCliente" style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
      <div>
        <label>Cliente final *</label>
        <select name="cliente_id" id="selCliente">
          <option value="">— selecione o cliente —</option>
          <?php foreach ($clientes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $preselect===(int)$c['id']?'selected':'' ?>>
              <?= e($c['razao_social']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Validade</label>
        <select name="meses">
          <option value="1">1 mês (teste)</option>
          <option value="3">3 meses</option>
          <option value="6">6 meses</option>
          <option value="12" selected>12 meses</option>
          <option value="24">24 meses</option>
          <option value="120">10 anos (perpétua)</option>
        </select>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-top:14px">
      <div>
        <label>Software *</label>
        <select name="produto_sel" id="produto_sel" required>
          <option value="">— selecione —</option>
          <?php foreach ($produtos as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Tipo de licença *</label>
        <select name="tier_id" id="tier_id" required disabled>
          <option value="">— escolha o software —</option>
        </select>
      </div>
      <div>
        <label>Carência (dias após expirar)</label>
        <select name="carencia">
          <option value="0">0 (bloqueia no dia)</option>
          <option value="7">7 dias</option>
          <option value="15" selected>15 dias</option>
          <option value="30">30 dias</option>
        </select>
      </div>
    </div>

    <div id="boxRevenda" style="display:none;margin-top:14px">
      <label>Revendedor *</label>
      <select name="revendedor_id" id="selRevendedor">
        <option value="">— selecione o revendedor —</option>
        <?php foreach ($revendedores as $r): ?>
          <option value="<?= $r['id'] ?>">
            <?= e($r['nome_fantasia'] ?: ($r['empresa'] ?: $r['nome'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="subtitulo" style="margin:6px 0 0">
        A licença vai para o estoque dele. O cliente final é preenchido
        pelo próprio revendedor, quando vender.
      </p>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:14px">
      <div>
        <label>Tipo</label>
        <select name="tipo_licenca">
          <option value="venda" selected>Venda</option>
          <option value="demo">Demonstração</option>
        </select>
      </div>
      <div>
        <label>Quantidade (lote)</label>
        <input type="number" name="quantidade" id="fQtd" value="1" min="1" max="50">
      </div>
    </div>

    <label style="margin-top:14px">Módulos (opcional — compatibilidade)</label>
    <div style="display:flex;gap:20px;margin-top:6px">
      <label style="text-transform:none;margin:0"><input type="checkbox" name="modulos[]" value="TBE" style="width:auto"> TBE (pesagem)</label>
      <label style="text-transform:none;margin:0"><input type="checkbox" name="modulos[]" value="RFID" style="width:auto"> RFID</label>
      <label style="text-transform:none;margin:0"><input type="checkbox" name="modulos[]" value="LPR" style="width:auto"> LPR (câmera)</label>
    </div>

    <button class="btn" style="margin-top:16px">Emitir licença</button>
  </form>
</div>

<div class="card">
  <h3>Licenças emitidas</h3>
  <table>
    <thead><tr><th>Chave</th><th>Cliente</th><th>Software / Tipo</th><th>Expira</th><th>Máquina</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$licencas): ?>
      <tr><td colspan="7" style="color:var(--texto-2)">Nenhuma licença emitida.</td></tr>
    <?php else: foreach ($licencas as $l):
        $exp = strtotime($l['expira_em']);
        $venceu = $exp < strtotime(date('Y-m-d'));
        $prodTier = $l['produto_codigo']
            ? strtoupper($l['produto_codigo']).' · '.($l['tier_nome'] ?: $l['tier_codigo'])
            : ('—'.($l['modulos']? ' ('.$l['modulos'].')':''));
    ?>
      <tr>
        <td class="mono"><?= e($l['chave']) ?></td>
        <td><?= e($l['razao_social'] ?? '— estoque —') ?>
          <?php if (!empty($l['revendedor_nome'])): ?>
            <br><span style="font-size:10px;color:var(--texto-2)">
              rev: <?= e($l['revendedor_nome']) ?></span>
          <?php endif; ?>
          <?php if (($l['tipo_licenca'] ?? '') === 'demo'): ?>
            <br><span class="badge nova" style="font-size:10px">demonstração</span>
          <?php endif; ?>
        </td>
        <td class="mono" style="font-size:11px"><?= e($prodTier) ?></td>
        <td class="mono" style="<?= $venceu?'color:var(--vermelho)':'' ?>"><?= date('d/m/Y',$exp) ?></td>
        <td class="mono" style="font-size:11px"><?= e($l['fingerprint'] ? substr($l['fingerprint'],0,14).'…' : '—') ?></td>
        <td>
          <span class="badge <?= e($l['status']) ?>"><?= e($l['status']) ?></span>
          <?php if ($l['status']==='revogada'): ?>
            <br><span style="font-size:10px;color:var(--texto-2)"
                  title="<?= e($l['obs_revogacao'] ?? '') ?>">
              <?= e($ROTULO_MOTIVO[$l['motivo_revogacao']] ?? 'motivo não informado') ?>
              <?php if ($l['revogada_em']): ?>
                <br><?= date('d/m/Y', strtotime($l['revogada_em'])) ?>
                <?= $l['revogada_por_nome'] ? '· '.e($l['revogada_por_nome']) : '' ?>
              <?php endif; ?>
            </span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($l['status']!=='revogada'): ?>
          <button class="btn perigo pequeno"
                  onclick="abrirRevogar(<?= $l['id'] ?>, '<?= e($l['chave']) ?>')">
            Revogar
          </button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
// Selects encadeados: filtra os tiers pelo software escolhido.
const TIERS = <?= json_encode(array_map(fn($t)=>[
    'id'=>(int)$t['id'],'pid'=>(int)$t['produto_id'],
    'nome'=>$t['nome'],'nivel'=>(int)$t['nivel']
], $tiers), JSON_UNESCAPED_UNICODE) ?>;

const selProd = document.getElementById('produto_sel');
const selTier = document.getElementById('tier_id');

selProd.addEventListener('change', function(){
  const pid = parseInt(this.value||'0',10);
  selTier.innerHTML = '';
  if (!pid) {
    selTier.disabled = true;
    selTier.innerHTML = '<option value="">— escolha o software —</option>';
    return;
  }
  const lista = TIERS.filter(t=>t.pid===pid).sort((a,b)=>a.nivel-b.nivel);
  selTier.disabled = false;
  selTier.innerHTML = '<option value="">— selecione —</option>' +
    lista.map(t=>`<option value="${t.id}">${t.nome} (nível ${t.nivel})</option>`).join('');
});
</script>

<!-- modal de revogacao: exige motivo antes de confirmar -->
<div id="modalRevogar" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,.6);z-index:50;align-items:center;justify-content:center">
  <div class="card" style="max-width:520px;width:92%;margin:0">
    <h3 style="margin-top:0">Revogar licença</h3>
    <p class="subtitulo" style="margin-top:-6px">
      O software do cliente deixará de funcionar na próxima revalidação.
      Chave: <span class="mono" id="mrChave"></span>
    </p>
    <form method="post">
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
        Observação <span id="mrObrig" style="display:none">*</span>
      </label>
      <textarea name="obs_revogacao" id="mrObs" style="min-height:70px"
                placeholder="Detalhe o que aconteceu (fica no histórico da licença)"></textarea>

      <div style="margin-top:14px">
        <button class="btn perigo">Confirmar revogação</button>
        <button type="button" class="btn sec" style="margin-left:8px"
                onclick="document.getElementById('modalRevogar').style.display='none'">
          Cancelar
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function trocarDestino() {
  const revenda = document.querySelector('input[name=destino]:checked').value === 'revenda';
  document.getElementById('boxCliente').style.display  = revenda ? 'none' : 'grid';
  document.getElementById('boxRevenda').style.display  = revenda ? '' : 'none';
  document.getElementById('selCliente').required    = !revenda;
  document.getElementById('selRevendedor').required = revenda;
  // lote so faz sentido para estoque de revenda
  const qtd = document.getElementById('fQtd');
  if (!revenda) { qtd.value = 1; qtd.readOnly = true; } else { qtd.readOnly = false; }

  // destaque visual do cartao escolhido
  document.getElementById('lblCliente').style.borderColor =
      revenda ? 'var(--borda)' : 'var(--ambar)';
  document.getElementById('lblRevenda').style.borderColor =
      revenda ? 'var(--ambar)' : 'var(--borda)';
}
document.addEventListener('DOMContentLoaded', trocarDestino);

function abrirRevogar(id, chave) {
  document.getElementById('mrId').value = id;
  document.getElementById('mrChave').textContent = chave;
  document.getElementById('mrMotivo').value = '';
  document.getElementById('mrObs').value = '';
  document.getElementById('modalRevogar').style.display = 'flex';
}
// "Outro" sem explicacao nao serve de nada: exige a observacao
document.addEventListener('DOMContentLoaded', function () {
  var sel = document.getElementById('mrMotivo');
  if (!sel) return;
  sel.addEventListener('change', function () {
    var outro = this.value === 'outro';
    document.getElementById('mrObs').required = outro;
    document.getElementById('mrObrig').style.display = outro ? '' : 'none';
  });
});
</script>
<?php fecha_pagina();
