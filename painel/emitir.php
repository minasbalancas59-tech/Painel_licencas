<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
require 'inc/mensagem.php';
require 'inc/email_licenca.php';
require_once __DIR__ . '/../api/lib/config_db.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  EMISSÃO DE LICENÇA
 * =====================================================================
 *  Página própria porque o formulário cresceu: destino, produto, tipo,
 *  valor, validade, carência, quantidade e módulos não cabem mais num
 *  bloco retrátil dentro da lista sem virar uma parede de campos.
 *
 *  Organizado em três perguntas, na ordem em que a venda acontece:
 *    1. Para quem   — cliente final ou estoque de revendedor
 *    2. O que       — software e tipo de licença
 *    3. Condições   — valor, prazo, carência, quantidade e módulos
 * ===================================================================== */

$msg=''; $tipo=''; $chaveGerada=''; $idsGerados=[];
$u = usuario_logado();

function pos_acao(string $msg, string $tipo, string $chave = '',
                  array $ids = []): void {
    $_SESSION['flash'] = ['msg' => $msg, 'tipo' => $tipo,
                          'chave' => $chave, 'ids' => $ids];
    // volta para a lista: emitida a licença, o operador quer vê-la
    header('Location: licencas.php');
    exit;
}

function preco_sugerido(?float $base, int $meses, float $descRev = 0): ?float {
    if ($base === null) return null;
    $v = $base * ($meses / 12);
    if ($descRev > 0) $v = $v * (1 - $descRev / 100);
    return round($v, 2);
}

function moeda(?float $v): string {
    return $v === null ? '—' : 'R$ ' . number_format($v, 2, ',', '.');
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
        // vem como "1.234,56" do formulario brasileiro
        $valorTxt = str_replace(['.', ','], ['', '.'],
                                trim($_POST['valor'] ?? ''));
        $valorLic = $valorTxt === '' ? null : max(0, (float)$valorTxt);
        $modelo   = (($_POST['modelo'] ?? '') === 'perpetua')
                    ? 'perpetua' : 'assinatura';
        // reforco no servidor: nao depende do JS ter feito seu papel
        if ($modelo === 'perpetua') $meses = 120;
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

                    db()->prepare('UPDATE licencas SET modelo=? WHERE id=?')
                        ->execute([$modelo, $licId]);

                    // Cada emissao e um evento de receita com data
                    // propria. Guardar o valor so na licenca nao
                    // serviria: uma licenca renovada tres vezes teria
                    // um valor e tres receitas em meses diferentes.
                    if ($valorLic !== null) {
                        db()->prepare(
                          'UPDATE licencas SET valor=?, modelo=? WHERE id=?')
                          ->execute([$valorLic, $modelo, $licId]);

                        db()->prepare(
                          'INSERT INTO financeiro_mov
                             (licenca_id, tipo, modelo, valor, valor_tabela,
                              meses, cliente_id, revendedor_id, produto, tier,
                              competencia, criado_por)
                           VALUES (?,"emissao",?,?,?,?,?,?,?,?,
                                   DATE_FORMAT(NOW(),"%Y-%m"),?)')
                          ->execute([$licId, $modelo, $valorLic,
                                     ($modelo === 'perpetua'
                                        ? ($t['preco_perpetuo'] ?? null)
                                        : ($t['preco_base'] ?? null)), $meses,
                                     $cliId ?: null, $revId ?: null,
                                     $t['produto_codigo'], $t['tier_codigo'],
                                     $u['id']]);
                    }

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

/* ---- dados dos seletores ------------------------------------------- */
$clientes = db()->query(
  'SELECT id,razao_social,nome_fantasia FROM clientes ORDER BY razao_social')
  ->fetchAll();
$preselect = (int)($_GET['cliente'] ?? 0);

$padMeses    = (int)cfg('validade_padrao_meses', 12);
$padCarencia = (int)cfg('carencia_padrao_dias', 15);

$produtos = db()->query(
  'SELECT id,codigo,nome FROM produtos WHERE ativo=1 ORDER BY codigo')->fetchAll();

$tiers = db()->query(
  'SELECT id,produto_id,codigo,nome,nivel,preco_base,preco_perpetuo
     FROM tiers WHERE ativo=1
    ORDER BY produto_id, nivel')->fetchAll();

$modulosCat = db()->query(
  'SELECT m.*, p.codigo AS produto_codigo
     FROM modulos m LEFT JOIN produtos p ON p.id=m.produto_id
    WHERE m.ativo=1
    ORDER BY COALESCE(p.codigo,""), m.ordem, m.codigo')->fetchAll();

$revendedores = db()->query(
  "SELECT id, nome, empresa, nome_fantasia, desconto_revenda FROM usuarios
    WHERE papel='revendedor' AND ativo=1
    ORDER BY COALESCE(nome_fantasia,empresa,nome)")->fetchAll();

/* ---------------------------------------------------------------------
 *  PREÇOS ESPECIAIS
 * ---------------------------------------------------------------------
 *  Carregados de uma vez e resolvidos no navegador. Consultar o servidor
 *  a cada troca de cliente daria uma ida ao banco por clique, e a
 *  tabela toda cabe em poucos KB.
 *
 *  Acordos vencidos ficam de fora: o sistema volta à tabela sozinho.
 * ------------------------------------------------------------------- */
$peLinhas = db()->query(
  "SELECT tier_id, alvo_tipo, alvo_id, preco_base, preco_perpetuo
     FROM precos_especiais
    WHERE vigencia_ate IS NULL OR vigencia_ate >= CURDATE()")->fetchAll();

$peMapa = ['cliente' => [], 'revendedor' => []];
foreach ($peLinhas as $r) {
    $peMapa[$r['alvo_tipo']][(int)$r['alvo_id']][(int)$r['tier_id']] = [
        'b' => $r['preco_base']     !== null ? (float)$r['preco_base']     : null,
        'p' => $r['preco_perpetuo'] !== null ? (float)$r['preco_perpetuo'] : null,
    ];
}

abre_pagina('Emitir licença', 'emitir');
?>
<h1 class="titulo">Emitir licença</h1>
<p class="subtitulo">
  <a href="licencas.php">&larr; voltar para a lista</a>
</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<form method="post">
<div class="card">
  <h3>1 · Para quem</h3>
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
          <?php foreach ([1   => '1 mês (teste)',
                          3   => '3 meses',
                          6   => '6 meses',
                          12  => '12 meses (1 ano)',
                          24  => '24 meses (2 anos)',
                          36  => '36 meses (3 anos)',
                          48  => '48 meses (4 anos)',
                          60  => '60 meses (5 anos)',
                          120 => '10 anos (perpétua)'] as $mv => $mr): ?>
            <option value="<?= $mv ?>" <?= $mv===$padMeses?'selected':'' ?>>
              <?= $mr ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

</div>

<div class="card">
  <h3>2 · O que</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div>
        <label>Software *</label>
        <select name="produto_sel" id="produto_sel" required
                onchange="filtrarPorProduto()">
          <option value="">— selecione —</option>
          <?php foreach ($produtos as $p): ?>
            <option value="<?= $p['id'] ?>" data-codigo="<?= e($p['codigo']) ?>">
              <?= e($p['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Tipo de licença *</label>
        <select name="tier_id" id="tier_id" required disabled>
          <option value="">— escolha o software —</option>
          <?php foreach ($tiers as $t): ?>
            <option value="<?= $t['id'] ?>" data-produto="<?= $t['produto_id'] ?>"
                    data-preco="<?= $t['preco_base'] !== null
                                    ? (float)$t['preco_base'] : '' ?>"
                    data-perp="<?= $t['preco_perpetuo'] !== null
                                   ? (float)$t['preco_perpetuo'] : '' ?>">
              nível <?= (int)$t['nivel'] ?> · <?= e($t['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
</div>

<div class="card">
  <h3>3 · Condições</h3>

    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
      <label id="lblAssin" style="flex:1;min-width:200px;border:2px solid var(--ambar);
             border-radius:var(--radius);padding:10px 14px;cursor:pointer;
             text-transform:none;margin:0">
        <input type="radio" name="modelo" value="assinatura" checked
               style="width:auto"> <b>Assinatura</b>
        <span style="display:block;font-size:11px;color:var(--texto-2);
              margin-top:2px">Cobrança por período, renovada no vencimento</span>
      </label>
      <label id="lblPerp" style="flex:1;min-width:200px;border:2px solid var(--borda);
             border-radius:var(--radius);padding:10px 14px;cursor:pointer;
             text-transform:none;margin:0">
        <input type="radio" name="modelo" value="perpetua"
               style="width:auto"> <b>Perpétua</b>
        <span style="display:block;font-size:11px;color:var(--texto-2);
              margin-top:2px">Pagamento único, licença emitida por 10 anos</span>
      </label>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
      <div>
        <label>Valor cobrado (R$)</label>
        <input name="valor" id="fValor" inputmode="decimal"
               placeholder="deixe vazio se não for registrar">
        <span class="subtitulo" id="dicaValor"
              style="margin:4px 0 0;display:block;font-size:11px"></span>
      </div>
      <div>
        <label>Carência (dias após expirar)</label>
        <select name="carencia">
          <?php foreach ([0=>'0 (bloqueia no dia)', 7=>'7 dias',
                          15=>'15 dias', 30=>'30 dias'] as $cv => $cr): ?>
            <option value="<?= $cv ?>" <?= $cv===$padCarencia?'selected':'' ?>>
              <?= $cr ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div id="boxRevenda" style="display:none;margin-top:14px">
      <label>Revendedor *</label>
      <select name="revendedor_id" id="selRevendedor">
        <option value="">— selecione o revendedor —</option>
        <?php foreach ($revendedores as $r): ?>
          <option value="<?= $r['id'] ?>"
                  data-desconto="<?= (float)($r['desconto_revenda'] ?? 0) ?>">
            <?= e($r['nome_fantasia'] ?: ($r['empresa'] ?: $r['nome'])) ?>
            <?php if (($r['desconto_revenda'] ?? 0) > 0): ?>
              (-<?= rtrim(rtrim(number_format((float)$r['desconto_revenda'],1,',','.'),'0'),',') ?>%)
            <?php endif; ?>
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

    <label style="margin-top:14px">Módulos</label>
    <div style="display:flex;gap:20px;margin-top:6px;flex-wrap:wrap">
      <?php if (!$modulosCat): ?>
        <span class="subtitulo" style="margin:0">
          Nenhum módulo cadastrado. Adicione em
          <a href="catalogo.php">Catálogo</a>.
        </span>
      <?php else: foreach ($modulosCat as $mo): ?>
        <label style="text-transform:none;margin:0"
               data-produto="<?= e($mo['produto_codigo'] ?? '') ?>"
               title="<?= e($mo['descricao'] ?? '') ?>">
          <input type="checkbox" name="modulos[]" value="<?= e($mo['codigo']) ?>"
                 style="width:auto"> <?= e($mo['nome']) ?>
        </label>
      <?php endforeach; endif; ?>
    </div>

</div>

<div class="card">
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <button class="btn">Emitir licença</button>
    <a class="btn sec" href="licencas.php">Cancelar</a>
    <span class="subtitulo" style="margin:0">
      A chave é gerada na hora e enviada por e-mail ao cliente, se ele
      tiver e-mail cadastrado.
    </span>
  </div>
</form>
</div>

<script>
// JSON_FORCE_OBJECT: sem ele, um mapa vazio vira [] em vez de {} e o
// acesso PE.cliente[id] quebra o script inteiro
var PE = <?= json_encode($peMapa, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT) ?>;

// marca o campo como digitado à mão, para a sugestão não sobrescrever
document.addEventListener('DOMContentLoaded', function () {
  var c = document.getElementById('fValor');
  if (c) c.addEventListener('input', function () { c.dataset.auto = '0'; });
});

function filtrarPorProduto() {
  var prod = document.getElementById('produto_sel');
  var tier = document.getElementById('tier_id');
  if (!prod || !tier) return;
  var pid = prod.value;

  if (!window._tiers) {
    window._tiers = [];
    for (var i = 0; i < tier.options.length; i++) {
      var o = tier.options[i];
      if (o.value) window._tiers.push({
        v: o.value, t: o.text.replace(/\s+/g, ' ').trim(),
        p: o.getAttribute('data-produto'),
        pr: o.getAttribute('data-preco')
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
    op.value = it.v; op.text = it.t;
    op.setAttribute('data-preco', it.pr || '');
    if (it.v === anterior) op.selected = true;
    tier.appendChild(op);
    achou++;
  }

  tier.disabled = !pid;
  tier.options[0].text = !pid ? '\u2014 escolha o software \u2014'
                     : (achou ? '\u2014 selecione \u2014'
                              : '\u2014 nenhum tipo cadastrado \u2014');

  var cod = prod.options[prod.selectedIndex]
            ? prod.options[prod.selectedIndex].getAttribute('data-codigo') : '';
  var mods = document.querySelectorAll('label[data-produto]');
  for (var j = 0; j < mods.length; j++) {
    var dp = mods[j].getAttribute('data-produto');
    mods[j].style.display = (!dp || dp === cod) ? '' : 'none';
  }
  sugerirValor();
}

function trocarModelo() {
  var perp = document.querySelector('input[name=modelo]:checked').value === 'perpetua';
  document.getElementById('lblAssin').style.borderColor =
      perp ? 'var(--borda)' : 'var(--ambar)';
  document.getElementById('lblPerp').style.borderColor =
      perp ? 'var(--ambar)' : 'var(--borda)';

  // Perpetua e emitida com 10 anos: e o que o Delphi entende como
  // "sem prazo pratico".
  //
  // NAO uso disabled: campo desabilitado nao e enviado no POST, e a
  // licenca chegaria sem 'meses' - caindo no padrao de 12. Deixo o
  // select ativo mas so com a opcao valida.
  var meses = document.querySelector('select[name=meses]');
  if (perp) {
    meses.value = '120';
    for (var i = 0; i < meses.options.length; i++)
      meses.options[i].disabled = (meses.options[i].value !== '120');
  } else {
    for (var j = 0; j < meses.options.length; j++)
      meses.options[j].disabled = false;
    if (meses.value === '120') meses.value = '12';
  }

  sugerirValor();
}

function sugerirValor() {
  var tier  = document.getElementById('tier_id');
  var meses = document.querySelector('select[name=meses]');
  var campo = document.getElementById('fValor');
  var dica  = document.getElementById('dicaValor');
  if (!tier || !meses || !campo) return;

  var perp = document.querySelector('input[name=modelo]:checked').value === 'perpetua';
  var op = tier.options[tier.selectedIndex];
  if (!op || !op.value) { dica.textContent = ''; return; }

  var tid = parseInt(op.value, 10);
  var destino = document.querySelector('input[name=destino]:checked');
  var ehRevenda = destino && destino.value === 'revenda';

  /* HIERARQUIA — o primeiro que existir vence:
       1. preço especial do cliente        (venda direta)
       2. preço especial do revendedor     (venda por ele)
       3. tabela menos o desconto % dele
       4. tabela cheia

     O especial NÃO acumula com o percentual: aplicar os dois daria
     desconto sobre desconto, e o erro só apareceria na margem do mês. */
  var base = null, origem = '';

  if (!ehRevenda) {
    var cli = document.getElementById('selCliente');
    var cid = cli ? parseInt(cli.value, 10) : 0;
    var pc = cid && PE.cliente[cid] ? PE.cliente[cid][tid] : null;
    if (pc) {
      var vv = perp ? pc.p : pc.b;
      if (vv !== null && vv !== undefined) { base = vv; origem = 'acordo do cliente'; }
    }
  } else {
    var rev = document.getElementById('selRevendedor');
    var rid = rev ? parseInt(rev.value, 10) : 0;
    var pr = rid && PE.revendedor[rid] ? PE.revendedor[rid][tid] : null;
    if (pr) {
      var vr = perp ? pr.p : pr.b;
      if (vr !== null && vr !== undefined) { base = vr; origem = 'acordo do revendedor'; }
    }
  }

  var desc = 0;
  if (base === null) {
    base = parseFloat(op.getAttribute(perp ? 'data-perp' : 'data-preco'));
    if (!base || isNaN(base)) {
      dica.textContent = 'Sem preço de tabela para ' +
                         (perp ? 'perpétua' : 'assinatura') + '.';
      return;
    }
    origem = 'tabela';
    if (ehRevenda) {
      var rv = document.getElementById('selRevendedor');
      var ro = rv && rv.selectedIndex >= 0 ? rv.options[rv.selectedIndex] : null;
      desc = ro ? parseFloat(ro.getAttribute('data-desconto') || 0) : 0;
    }
  }

  var v = perp ? base : base * (parseInt(meses.value, 10) / 12);
  if (desc > 0) v = v * (1 - desc / 100);

  var fmt = v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  dica.textContent = fmt + ' · ' + origem +
                     (desc > 0 ? ' (-' + desc + '%)' : '');
  dica.style.color = (origem === 'tabela') ? '' : 'var(--verde)';
  if (!campo.value || campo.dataset.auto === '1') {
    campo.value = fmt;
    campo.dataset.auto = '1';
  }
}

function trocarDestino() {
  var revenda = document.querySelector('input[name=destino]:checked').value === 'revenda';
  document.getElementById('boxCliente').style.display = revenda ? 'none' : 'grid';
  document.getElementById('boxRevenda').style.display = revenda ? '' : 'none';
  document.getElementById('selCliente').required    = !revenda;
  document.getElementById('selRevendedor').required = revenda;
  var qtd = document.getElementById('fQtd');
  if (!revenda) { qtd.value = 1; qtd.readOnly = true; } else { qtd.readOnly = false; }
  document.getElementById('lblCliente').style.borderColor =
      revenda ? 'var(--borda)' : 'var(--ambar)';
  document.getElementById('lblRevenda').style.borderColor =
      revenda ? 'var(--ambar)' : 'var(--borda)';
  sugerirValor();
}

document.addEventListener('DOMContentLoaded', function () {
  trocarDestino();
  filtrarPorProduto();
  ['tier_id', 'selRevendedor', 'selCliente'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', sugerirValor);
  });
  var m = document.querySelector('select[name=meses]');
  if (m) m.addEventListener('change', sugerirValor);
  document.querySelectorAll('input[name=destino]').forEach(function (r) {
    r.addEventListener('change', trocarDestino);
  });
  document.querySelectorAll('input[name=modelo]').forEach(function (r) {
    r.addEventListener('change', trocarModelo);
  });
  trocarModelo();
});
</script>
<?php fecha_pagina();
