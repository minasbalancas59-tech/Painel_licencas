<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  TABELA DE PREÇOS
 * =====================================================================
 *  Uma tela só para preço, separada do Catálogo.
 *
 *  No Catálogo o preço era mais uma coluna entre código, nível e
 *  descrição — e ajustar a tabela virava um passeio por seis formulários
 *  de edição. Aqui você escolhe o software e edita todos os tipos dele
 *  de uma vez, num único salvar.
 *
 *  DOIS PREÇOS, porque são vendas diferentes:
 *    Anuidade  — um ano de uso; o sistema aplica proporcional
 *    Perpétua  — pagamento único, licença sem prazo prático
 *
 *  Não se calculam um do outro: a perpétua costuma valer 3 a 5
 *  anuidades, não 10 — senão ninguém compraria.
 * ===================================================================== */

$msg = ''; $tipo = '';
$u = usuario_logado();

function num_br(?string $v): ?float {
    $v = trim((string)$v);
    if ($v === '') return null;
    return max(0, (float)str_replace(['.', ','], ['', '.'], $v));
}
function brl(?float $v): string {
    return $v === null ? '—' : 'R$ ' . number_format($v, 2, ',', '.');
}
function campo_br(?float $v): string {
    return $v === null ? '' : number_format((float)$v, 2, ',', '.');
}

/* ---- salvar --------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valido()) {

    if (($_POST['acao'] ?? '') === 'salvar_precos') {
        $st = db()->prepare(
          'UPDATE tiers SET preco_base=?, preco_perpetuo=? WHERE id=?');

        $n = 0;
        foreach (($_POST['anual'] ?? []) as $tid => $val) {
            $tid = (int)$tid;
            $st->execute([
                num_br($val),
                num_br($_POST['perpetuo'][$tid] ?? ''),
                $tid,
            ]);
            $n++;
        }
        $_SESSION['flashP'] = [$n . ' preço(s) salvo(s).', 'ok'];
        header('Location: precos.php?produto=' . urlencode($_POST['produto'] ?? ''));
        exit;
    }

    // --- preco especial: criar ou substituir -------------------------
    if (($_POST['acao'] ?? '') === 'especial_salvar') {
        $tid  = (int)$_POST['tier_id'];
        $alvoT = ($_POST['alvo_tipo'] ?? '') === 'revendedor'
                 ? 'revendedor' : 'cliente';
        $alvoI = (int)$_POST['alvo_id'];
        $pb   = num_br($_POST['pe_base'] ?? '');
        $pp   = num_br($_POST['pe_perp'] ?? '');
        $vig  = trim($_POST['vigencia_ate'] ?? '') ?: null;

        if (!$tid || !$alvoI) {
            $_SESSION['flashP'] = ['Escolha o tipo de licença e a quem se aplica.', 'erro'];
        } elseif ($pb === null && $pp === null) {
            $_SESSION['flashP'] = ['Informe ao menos um dos dois preços.', 'erro'];
        } else {
            // ON DUPLICATE: refazer o acordo substitui, nao duplica
            db()->prepare(
              'INSERT INTO precos_especiais
                 (tier_id, alvo_tipo, alvo_id, preco_base, preco_perpetuo,
                  vigencia_ate, observacao, criado_por)
               VALUES (?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE
                 preco_base=VALUES(preco_base),
                 preco_perpetuo=VALUES(preco_perpetuo),
                 vigencia_ate=VALUES(vigencia_ate),
                 observacao=VALUES(observacao)')
              ->execute([$tid, $alvoT, $alvoI, $pb, $pp, $vig,
                         (trim($_POST['pe_obs'] ?? '') ?: null), $u['id']]);
            $_SESSION['flashP'] = ['Preço especial salvo.', 'ok'];
        }
        header('Location: precos.php?aba=especiais');
        exit;
    }

    if (($_POST['acao'] ?? '') === 'especial_remover') {
        db()->prepare('DELETE FROM precos_especiais WHERE id=?')
            ->execute([(int)$_POST['id']]);
        $_SESSION['flashP'] = ['Preço especial removido.', 'ok'];
        header('Location: precos.php?aba=especiais');
        exit;
    }

    // reajuste percentual em lote
    if (($_POST['acao'] ?? '') === 'reajustar') {
        $perc = (float)str_replace(',', '.', trim($_POST['percentual'] ?? '0'));
        $pid  = (int)$_POST['produto_id'];

        if ($perc == 0 || $pid === 0) {
            $_SESSION['flashP'] = ['Informe o percentual.', 'erro'];
        } else {
            // arredonda para o real cheio: preco com centavo quebrado
            // depois de reajuste fica estranho na proposta
            db()->prepare(
              'UPDATE tiers
                  SET preco_base     = ROUND(preco_base     * (1 + ?/100)),
                      preco_perpetuo = ROUND(preco_perpetuo * (1 + ?/100))
                WHERE produto_id = ?')->execute([$perc, $perc, $pid]);

            $_SESSION['flashP'] = [
                'Preços reajustados em ' . number_format($perc, 2, ',', '.') . '%.',
                'ok'];
        }
        header('Location: precos.php?produto=' . urlencode($_POST['produto'] ?? ''));
        exit;
    }
}

if (!empty($_SESSION['flashP'])) {
    [$msg, $tipo] = $_SESSION['flashP'];
    unset($_SESSION['flashP']);
}

/* ---- dados ---------------------------------------------------------- */
$produtos = db()->query(
  'SELECT p.id, p.codigo, p.nome,
          (SELECT COUNT(*) FROM tiers t
            WHERE t.produto_id = p.id AND t.ativo = 1) AS n_tiers,
          (SELECT COUNT(*) FROM tiers t
            WHERE t.produto_id = p.id AND t.ativo = 1
              AND t.preco_base IS NULL AND t.preco_perpetuo IS NULL) AS sem_preco
     FROM produtos p WHERE p.ativo = 1 ORDER BY p.codigo')->fetchAll();

$fProd = trim($_GET['produto'] ?? '');
if ($fProd === '' && $produtos) $fProd = $produtos[0]['codigo'];

$prodAtual = null;
foreach ($produtos as $p) if ($p['codigo'] === $fProd) $prodAtual = $p;

$tiers = [];
if ($prodAtual) {
    $st = db()->prepare(
      'SELECT t.*,
              (SELECT COUNT(*) FROM licencas l WHERE l.tier_id = t.id) AS n_lic
         FROM tiers t
        WHERE t.produto_id = ? AND t.ativo = 1
        ORDER BY t.nivel');
    $st->execute([$prodAtual['id']]);
    $tiers = $st->fetchAll();
}

$aba = (($_GET['aba'] ?? '') === 'especiais') ? 'especiais' : 'tabela';

/* ---- preços especiais ------------------------------------------------ */
$especiais = db()->query(
  "SELECT pe.*, t.nome AS tier_nome, t.nivel,
          p.codigo AS produto_codigo, p.nome AS produto_nome,
          COALESCE(c.nome_fantasia, c.razao_social) AS cliente_nome,
          COALESCE(u.nome_fantasia, u.empresa, u.nome) AS rev_nome,
          t.preco_base AS tab_base, t.preco_perpetuo AS tab_perp
     FROM precos_especiais pe
     JOIN tiers t    ON t.id = pe.tier_id
     JOIN produtos p ON p.id = t.produto_id
     LEFT JOIN clientes c ON c.id = pe.alvo_id AND pe.alvo_tipo='cliente'
     LEFT JOIN usuarios u ON u.id = pe.alvo_id AND pe.alvo_tipo='revendedor'
    ORDER BY pe.alvo_tipo, COALESCE(c.razao_social, u.nome), p.codigo, t.nivel")
  ->fetchAll();

$todosTiers = db()->query(
  'SELECT t.id, t.nome, t.nivel, p.nome AS produto_nome, p.codigo AS produto_codigo
     FROM tiers t JOIN produtos p ON p.id = t.produto_id
    WHERE t.ativo = 1 AND p.ativo = 1
    ORDER BY p.codigo, t.nivel')->fetchAll();

$listaClientes = db()->query(
  'SELECT id, razao_social, nome_fantasia FROM clientes ORDER BY razao_social')
  ->fetchAll();

$listaRev = db()->query(
  "SELECT id, nome, empresa, nome_fantasia, desconto_revenda FROM usuarios
    WHERE papel='revendedor' AND ativo=1
    ORDER BY COALESCE(nome_fantasia,empresa,nome)")->fetchAll();

abre_pagina('Tabela de preços', 'precos');
?>
<h1 class="titulo">Tabela de preços</h1>
<p class="subtitulo">
  Valores de referência usados ao emitir. O preço continua editável na
  hora da venda.
</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<div style="display:flex;gap:6px;margin-bottom:18px;border-bottom:1px solid var(--borda);
     padding-bottom:14px;flex-wrap:wrap">
  <a class="btn <?= $aba==='tabela' ? '' : 'sec' ?>"
     href="precos.php?produto=<?= e($fProd) ?>">Tabela padrão</a>
  <a class="btn <?= $aba==='especiais' ? '' : 'sec' ?>"
     href="precos.php?aba=especiais">
    Preços especiais
    <?php if ($especiais): ?>
      <i style="display:inline-block;font-style:normal;font-size:10px;
         font-weight:700;padding:2px 5px;margin-left:5px;border-radius:9px;
         background:var(--bg-3);color:var(--texto)"><?= count($especiais) ?></i>
    <?php endif; ?>
  </a>
</div>

<?php if ($aba === 'tabela'): ?>

<div style="display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap">
  <?php foreach ($produtos as $p): ?>
    <a class="btn <?= $p['codigo'] === $fProd ? '' : 'sec' ?>"
       href="precos.php?produto=<?= e($p['codigo']) ?>">
      <?= e($p['nome']) ?>
      <?php if ((int)$p['sem_preco'] > 0): ?>
        <i class="pino" style="display:inline-block;font-style:normal;
           font-size:10px;font-weight:700;padding:2px 5px;margin-left:5px;
           border-radius:9px;background:var(--vermelho);color:#fff"><?=
           (int)$p['sem_preco'] ?></i>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if (!$prodAtual): ?>
  <div class="card"><p style="margin:0">
    Nenhum software cadastrado. Comece em <a href="catalogo.php">Catálogo</a>.
  </p></div>
<?php elseif (!$tiers): ?>
  <div class="card"><p style="margin:0">
    <?= e($prodAtual['nome']) ?> ainda não tem tipos de licença.
    Cadastre em <a href="catalogo.php">Catálogo</a>.
  </p></div>
<?php else: ?>

<form method="post">
  <input type="hidden" name="acao" value="salvar_precos">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="produto" value="<?= e($fProd) ?>">

  <div class="card">
    <h3><?= e($prodAtual['nome']) ?></h3>
    <p class="subtitulo" style="margin-top:-6px">
      <b>Anuidade</b>: um ano de uso — o sistema aplica proporcional
      (6 meses cobra metade, 24 meses o dobro).<br>
      <b>Perpétua</b>: pagamento único, licença sem prazo prático.
    </p>

    <table>
      <thead><tr>
        <th style="width:40px">Nível</th>
        <th>Tipo de licença</th>
        <th style="width:170px;text-align:right">Anuidade (R$)</th>
        <th style="width:170px;text-align:right">Perpétua (R$)</th>
        <th style="width:110px;text-align:right">Relação</th>
        <th style="width:90px;text-align:right">Licenças</th>
      </tr></thead>
      <tbody>
      <?php foreach ($tiers as $t):
          $pa = $t['preco_base']     !== null ? (float)$t['preco_base']     : null;
          $pp = $t['preco_perpetuo'] !== null ? (float)$t['preco_perpetuo'] : null;
          // quantas anuidades a perpetua vale - o numero que mostra se a
          // tabela faz sentido comercial
          $rel = ($pa && $pp) ? $pp / $pa : null;
      ?>
        <tr>
          <td class="mono" style="text-align:center"><?= (int)$t['nivel'] ?></td>
          <td>
            <b><?= e($t['nome']) ?></b>
            <?php if ($t['descricao']): ?>
              <br><span style="font-size:11px;color:var(--texto-2)">
                <?= e($t['descricao']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <input name="anual[<?= $t['id'] ?>]" inputmode="decimal"
                   class="mono" style="text-align:right"
                   value="<?= e(campo_br($pa)) ?>" placeholder="0,00">
          </td>
          <td>
            <input name="perpetuo[<?= $t['id'] ?>]" inputmode="decimal"
                   class="mono" style="text-align:right"
                   value="<?= e(campo_br($pp)) ?>" placeholder="0,00">
          </td>
          <td class="mono" style="text-align:right;font-size:12px;
              color:<?= ($rel !== null && $rel < 2) ? 'var(--ambar)' : 'var(--texto-2)' ?>">
            <?php if ($rel !== null): ?>
              <?= number_format($rel, 1, ',', '.') ?>×
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td class="mono" style="text-align:right;color:var(--texto-2)">
            <?= (int)$t['n_lic'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <p class="subtitulo" style="margin-top:12px;font-size:11px">
      A coluna <b>Relação</b> mostra quantas anuidades a perpétua vale.
      Abaixo de 2× costuma ser prejuízo: o cliente paga uma vez e usa
      por anos. Deixe em branco o preço que não se aplica.
    </p>

    <div style="margin-top:16px">
      <button class="btn">Salvar preços</button>
      <a class="btn sec" style="margin-left:8px"
         href="precos.php?produto=<?= e($fProd) ?>">Descartar</a>
    </div>
  </div>
</form>

<div class="card">
  <h3>Reajuste em lote</h3>
  <p class="subtitulo" style="margin-top:-6px">
    Aplica um percentual a todos os preços de <?= e($prodAtual['nome']) ?>,
    arredondando para o real cheio. Não altera licenças já vendidas.
  </p>
  <form method="post"
        onsubmit="return confirm('Reajustar todos os preços de <?= e($prodAtual['nome']) ?>?')"
        style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="acao" value="reajustar">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="produto" value="<?= e($fProd) ?>">
    <input type="hidden" name="produto_id" value="<?= (int)$prodAtual['id'] ?>">
    <div style="max-width:160px">
      <label>Percentual (%)</label>
      <input name="percentual" inputmode="decimal" placeholder="ex: 8,5">
      <span class="subtitulo" style="margin:4px 0 0;display:block;font-size:11px">
        negativo para reduzir</span>
    </div>
    <button class="btn sec">Aplicar reajuste</button>
  </form>
</div>

<div class="card">
  <h3>Simulação</h3>
  <p class="subtitulo" style="margin-top:-6px">
    Como os valores desta tabela aparecem na emissão, por prazo.
  </p>
  <table>
    <thead><tr>
      <th>Tipo</th>
      <?php foreach ([6, 12, 24, 36] as $mm): ?>
        <th style="text-align:right"><?= $mm ?> meses</th>
      <?php endforeach; ?>
      <th style="text-align:right">Perpétua</th>
    </tr></thead>
    <tbody>
    <?php foreach ($tiers as $t):
        $pa = $t['preco_base'] !== null ? (float)$t['preco_base'] : null;
        $pp = $t['preco_perpetuo'] !== null ? (float)$t['preco_perpetuo'] : null;
    ?>
      <tr>
        <td><?= e($t['nome']) ?></td>
        <?php foreach ([6, 12, 24, 36] as $mm): ?>
          <td class="mono" style="text-align:right;font-size:12px">
            <?= $pa !== null ? brl(round($pa * $mm / 12, 2)) : '—' ?></td>
        <?php endforeach; ?>
        <td class="mono" style="text-align:right;font-size:12px;color:var(--ambar)">
          <?= brl($pp) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php else: /* ---------------- ABA PREÇOS ESPECIAIS ---------------- */ ?>

<div class="card" style="border-left:3px solid var(--azul)">
  <h3 style="margin-top:0">Qual preço o sistema usa</h3>
  <p style="margin:0;font-size:13px;line-height:1.7">
    Do mais específico ao mais geral — o primeiro que existir vence:
  </p>
  <ol style="font-size:13px;line-height:1.8;margin:10px 0 0;padding-left:20px">
    <li>Preço especial do <b>cliente</b> — em venda direta</li>
    <li>Preço especial do <b>revendedor</b> — quando a venda é por ele</li>
    <li>Tabela menos o <b>desconto percentual</b> do revendedor</li>
    <li>Tabela cheia</li>
  </ol>
  <p style="margin:12px 0 0;font-size:12px;color:var(--texto-2)">
    Se um revendedor tem desconto geral <b>e</b> preço especial num tipo,
    o especial vence — o percentual não é aplicado de novo em cima dele.
  </p>
</div>

<div class="card">
  <h3>Novo preço especial</h3>
  <form method="post">
    <input type="hidden" name="acao" value="especial_salvar">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div style="display:grid;grid-template-columns:1fr 2fr;gap:16px">
      <div>
        <label>Aplica-se a *</label>
        <select name="alvo_tipo" id="peTipo" onchange="trocarAlvo()">
          <option value="cliente">Cliente final</option>
          <option value="revendedor">Revendedor</option>
        </select>
      </div>
      <div>
        <label>Quem *</label>
        <select name="alvo_id" id="peAlvo" required>
          <optgroup label="Clientes" id="grpCli">
            <?php foreach ($listaClientes as $c): ?>
              <option value="<?= $c['id'] ?>" data-t="cliente">
                <?= e($c['nome_fantasia'] ?: $c['razao_social']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="Revendedores" id="grpRev">
            <?php foreach ($listaRev as $r): ?>
              <option value="<?= $r['id'] ?>" data-t="revendedor">
                <?= e($r['nome_fantasia'] ?: ($r['empresa'] ?: $r['nome'])) ?>
                <?php if ($r['desconto_revenda'] > 0): ?>
                  (desconto geral <?= rtrim(rtrim(number_format(
                      (float)$r['desconto_revenda'],1,',','.'),'0'),',') ?>%)
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        </select>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:16px;margin-top:14px">
      <div>
        <label>Tipo de licença *</label>
        <select name="tier_id" required>
          <option value="">— selecione —</option>
          <?php foreach ($todosTiers as $t): ?>
            <option value="<?= $t['id'] ?>">
              <?= e($t['produto_nome']) ?> · <?= e($t['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Anuidade (R$)</label>
        <input name="pe_base" inputmode="decimal" placeholder="usa a tabela"></div>
      <div><label>Perpétua (R$)</label>
        <input name="pe_perp" inputmode="decimal" placeholder="usa a tabela"></div>
      <div><label>Vigência até</label>
        <input type="date" name="vigencia_ate"></div>
    </div>

    <div style="margin-top:14px">
      <label>Observação</label>
      <input name="pe_obs" placeholder="ex: contrato 2026, condição de lançamento">
    </div>

    <p class="subtitulo" style="margin:10px 0 0;font-size:11px">
      Deixe um preço em branco para usar o da tabela naquele modelo.
      Sem vigência, o acordo vale por tempo indeterminado.
    </p>

    <div style="margin-top:16px">
      <button class="btn">Salvar preço especial</button>
    </div>
  </form>
</div>

<div class="card">
  <h3>Acordos vigentes (<?= count($especiais) ?>)</h3>
  <?php if (!$especiais): ?>
    <p class="subtitulo" style="margin:0">
      Nenhum preço especial cadastrado. Todos os clientes e revendedores
      seguem a tabela padrão.
    </p>
  <?php else: ?>
    <table>
      <thead><tr>
        <th>Quem</th><th>Tipo de licença</th>
        <th style="text-align:right">Anuidade</th>
        <th style="text-align:right">Perpétua</th>
        <th>Vigência</th><th>Observação</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($especiais as $pe):
          $vencido = $pe['vigencia_ate'] &&
                     strtotime($pe['vigencia_ate']) < strtotime(date('Y-m-d'));
      ?>
        <tr style="<?= $vencido ? 'opacity:.55' : '' ?>">
          <td>
            <?= e($pe['alvo_tipo'] === 'cliente'
                  ? ($pe['cliente_nome'] ?: '(cliente removido)')
                  : ($pe['rev_nome'] ?: '(revendedor removido)')) ?>
            <br><span style="font-size:11px;color:var(--texto-2)">
              <?= $pe['alvo_tipo'] === 'cliente' ? 'cliente final' : 'revendedor' ?>
            </span>
          </td>
          <td style="font-size:12px">
            <?= e($pe['produto_nome']) ?> · <b><?= e($pe['tier_nome']) ?></b></td>
          <td class="mono" style="text-align:right;font-size:12px">
            <?php if ($pe['preco_base'] !== null): ?>
              <?= brl((float)$pe['preco_base']) ?>
              <?php if ($pe['tab_base']): ?>
                <br><span style="font-size:10px;color:var(--texto-2);
                      text-decoration:line-through">
                  <?= brl((float)$pe['tab_base']) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--texto-2)">tabela</span>
            <?php endif; ?>
          </td>
          <td class="mono" style="text-align:right;font-size:12px">
            <?php if ($pe['preco_perpetuo'] !== null): ?>
              <?= brl((float)$pe['preco_perpetuo']) ?>
              <?php if ($pe['tab_perp']): ?>
                <br><span style="font-size:10px;color:var(--texto-2);
                      text-decoration:line-through">
                  <?= brl((float)$pe['tab_perp']) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--texto-2)">tabela</span>
            <?php endif; ?>
          </td>
          <td style="font-size:11px">
            <?php if (!$pe['vigencia_ate']): ?>
              <span style="color:var(--texto-2)">sem prazo</span>
            <?php else: ?>
              <span style="color:<?= $vencido ? 'var(--vermelho)' : 'var(--texto)' ?>">
                <?= date('d/m/Y', strtotime($pe['vigencia_ate'])) ?>
                <?= $vencido ? ' · vencido' : '' ?></span>
            <?php endif; ?>
          </td>
          <td style="font-size:11px;color:var(--texto-2)">
            <?= e($pe['observacao'] ?: '') ?></td>
          <td>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Remover este preço especial?')">
              <input type="hidden" name="acao" value="especial_remover">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= $pe['id'] ?>">
              <button class="btn sec pequeno">Remover</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="subtitulo" style="margin-top:12px;font-size:11px">
      Acordos vencidos aparecem apagados e deixam de valer na emissão —
      o sistema volta a usar a tabela.
    </p>
  <?php endif; ?>
</div>

<script>
/* Mostra só os alvos do tipo escolhido. Um select com clientes e
   revendedores juntos daria erro silencioso: escolher "revendedor" e
   deixar um cliente selecionado gravaria o acordo no alvo errado. */
function trocarAlvo() {
  var tipo = document.getElementById('peTipo').value;
  document.getElementById('grpCli').style.display =
      tipo === 'cliente' ? '' : 'none';
  document.getElementById('grpRev').style.display =
      tipo === 'revendedor' ? '' : 'none';

  var sel = document.getElementById('peAlvo');
  var op = sel.options[sel.selectedIndex];
  if (!op || op.getAttribute('data-t') !== tipo) {
    for (var i = 0; i < sel.options.length; i++) {
      if (sel.options[i].getAttribute('data-t') === tipo) {
        sel.selectedIndex = i; break;
      }
    }
  }
}
document.addEventListener('DOMContentLoaded', trocarAlvo);
</script>

<?php endif; ?>

<script>
/* Formata enquanto digita: o operador digita 1800 e vê 1.800,00 ao sair
   do campo. Sem isso, "1800" e "1.800,00" na mesma tabela confundem
   quem está conferindo a lista inteira. */
document.querySelectorAll('input[inputmode=decimal]').forEach(function (c) {
  c.addEventListener('blur', function () {
    var t = c.value.trim();
    if (t === '') return;
    var n = parseFloat(t.replace(/\./g, '').replace(',', '.'));
    if (isNaN(n)) { c.value = ''; return; }
    c.value = n.toFixed(2).replace('.', ',')
               .replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  });
});
</script>
<?php fecha_pagina();
