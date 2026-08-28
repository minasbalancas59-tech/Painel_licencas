<?php
require 'inc/auth.php';
require 'inc/layout.php';
exige_login();

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
        $modsCsv  = implode(',', array_map(fn($m)=>preg_replace('/[^A-Z]/','',$m), $mods));

        if ($cliId<=0)         { $msg='Selecione um cliente.'; $tipo='erro'; }
        elseif ($tierId<=0)    { $msg='Selecione o software e o tipo de licença.'; $tipo='erro'; }
        elseif ($meses<=0)     { $msg='Validade inválida.'; $tipo='erro'; }
        else {
            try {
                // resolve produto/tier/nivel a partir do tier escolhido
                $t = resolver_tier($tierId);   // produto_codigo, tier_codigo, nivel...

                // busca dados do cliente para o payload assinado
                $cli = db()->prepare('SELECT razao_social,cnpj FROM clientes WHERE id=?');
                $cli->execute([$cliId]);
                $cliRow = $cli->fetch();
                if (!$cliRow) throw new RuntimeException('Cliente não encontrado.');

                $chave = gerar_chave_licenca();
                $emit  = date('Y-m-d');
                $exp   = date('Y-m-d', strtotime("+$meses months"));
                $u     = usuario_logado();

                // grava a licenca (fingerprint fica NULL ate a ativacao)
                $st = db()->prepare(
                  'INSERT INTO licencas
                     (cliente_id,produto_id,tier_id,chave,modulos,
                      emitido_em,expira_em,carencia_dias,status,criado_por)
                   VALUES (?,?,?,?,?,?,?,?,"nova",?)');
                $st->execute([
                    $cliId,
                    $t['produto_id'],   // vem do JOIN em resolver_tier()
                    $tierId, $chave, ($modsCsv ?: ''),
                    $emit, $exp, $carencia, $u['id']
                ]);
                $licId = (int)db()->lastInsertId();

                // registra na auditoria (quem emitiu, produto/tier)
                log_acao_painel(
                    $licId, $chave, null, 'emitir', 'ok',
                    $u['id'], $u['nome'] ?? null,
                    $t['produto_codigo'], $t['tier_codigo'],
                    "validade {$meses}m, carencia {$carencia}d"
                );

                $chaveGerada = $chave;
                $msg = "Licença emitida ({$t['produto_codigo']} · {$t['tier_codigo']}). ".
                       "Entregue a chave abaixo ao cliente para ativação.";
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
        $id=(int)$_POST['id'];
        db()->prepare('UPDATE licencas SET status="revogada" WHERE id=?')->execute([$id]);
        $u = usuario_logado();
        // busca produto/tier da licenca para registrar no log
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
            $lrow['pc'] ?? null, $lrow['tc'] ?? null, 'via painel');
        $msg='Licença revogada.'; $tipo='ok';
    }
}

$clientes = db()->query('SELECT id,razao_social FROM clientes ORDER BY razao_social')->fetchAll();
$preselect = (int)($_GET['cliente'] ?? 0);

// catalogo de produtos e tiers para os selects encadeados
$produtos = db()->query('SELECT id,codigo,nome FROM produtos WHERE ativo=1 ORDER BY codigo')->fetchAll();
$tiers    = db()->query(
  'SELECT id,produto_id,codigo,nome,nivel FROM tiers WHERE ativo=1
    ORDER BY produto_id, nivel')->fetchAll();

$licencas = db()->query(
  'SELECT l.*, c.razao_social, p.codigo AS produto_codigo, t.codigo AS tier_codigo, t.nome AS tier_nome
     FROM licencas l
     JOIN clientes c   ON c.id=l.cliente_id
     LEFT JOIN produtos p ON p.id=l.produto_id
     LEFT JOIN tiers t    ON t.id=l.tier_id
    ORDER BY l.id DESC LIMIT 200')->fetchAll();

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

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
      <div>
        <label>Cliente *</label>
        <select name="cliente_id" required>
          <option value="">— selecione —</option>
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
        <td><?= e($l['razao_social']) ?></td>
        <td class="mono" style="font-size:11px"><?= e($prodTier) ?></td>
        <td class="mono" style="<?= $venceu?'color:var(--vermelho)':'' ?>"><?= date('d/m/Y',$exp) ?></td>
        <td class="mono" style="font-size:11px"><?= e($l['fingerprint'] ? substr($l['fingerprint'],0,14).'…' : '—') ?></td>
        <td><span class="badge <?= e($l['status']) ?>"><?= e($l['status']) ?></span></td>
        <td>
          <?php if ($l['status']!=='revogada'): ?>
          <form method="post" onsubmit="return confirm('Revogar esta licença? O software do cliente deixará de funcionar.')" style="display:inline">
            <input type="hidden" name="acao" value="revogar">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $l['id'] ?>">
            <button class="btn perigo pequeno">Revogar</button>
          </form>
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
<?php fecha_pagina();
