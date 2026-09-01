<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  AUTOCADASTROS — conferência
 * =====================================================================
 *  Clientes que se cadastraram sozinhos ao ativar o software, no fluxo
 *  em que o revendedor repassa a chave sem usar o painel.
 *
 *  POR QUE UMA FILA E NÃO SÓ UM CADASTRO NORMAL
 *
 *  Telefone e e-mail vêm do próprio cliente, sem como validar. A razão
 *  social vem da Receita quando ela responde — mas ela nem sempre
 *  responde, e nesse caso fica o que ele digitou.
 *
 *  Mais importante: um CNPJ que já estava na base pode aparecer
 *  comprando de um revendedor diferente. Reatribuir sozinho seria mexer
 *  na carteira de alguém sem avisar — por isso o caso vira conflito e
 *  espera sua decisão.
 * ===================================================================== */

$msg = ''; $tipo = '';
$u = usuario_logado();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valido()) {
    $ac = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($ac === 'revisar' && $id) {
        db()->prepare(
          'UPDATE autocadastros SET revisado=1, revisado_por=?, revisado_em=NOW()
            WHERE id=?')->execute([$u['id'], $id]);

        // conferido também no cliente: os dados passaram pelo seu olho
        $st = db()->prepare('SELECT cliente_id FROM autocadastros WHERE id=?');
        $st->execute([$id]);
        if ($cid = (int)$st->fetchColumn()) {
            db()->prepare('UPDATE clientes SET conferido=1 WHERE id=?')
                ->execute([$cid]);
        }
        $_SESSION['flashA'] = ['Cadastro conferido.', 'ok'];
        header('Location: autocadastros.php'); exit;
    }

    // resolve o conflito transferindo o cliente para o revendedor da licença
    if ($ac === 'transferir' && $id) {
        $st = db()->prepare(
          'SELECT cliente_id, revendedor_id FROM autocadastros WHERE id=?');
        $st->execute([$id]);
        $r = $st->fetch();
        if ($r && $r['cliente_id']) {
            db()->prepare('UPDATE clientes SET revendedor_id=? WHERE id=?')
                ->execute([$r['revendedor_id'] ?: null, (int)$r['cliente_id']]);
            db()->prepare(
              'UPDATE autocadastros SET revisado=1, revisado_por=?, revisado_em=NOW(),
                      observacao=CONCAT(COALESCE(observacao,""), " · transferido")
                WHERE id=?')->execute([$u['id'], $id]);
            $_SESSION['flashA'] = ['Cliente transferido para o revendedor da licença.', 'ok'];
        }
        header('Location: autocadastros.php'); exit;
    }
}

if (!empty($_SESSION['flashA'])) {
    [$msg, $tipo] = $_SESSION['flashA'];
    unset($_SESSION['flashA']);
}

$fVer = trim($_GET['ver'] ?? 'pendentes');

$where = '';
if ($fVer === 'pendentes')      $where = 'WHERE a.revisado = 0';
elseif ($fVer === 'conflitos')  $where = 'WHERE a.resultado = "conflito"';
elseif ($fVer === 'revisados')  $where = 'WHERE a.revisado = 1';

$lista = db()->query(
  "SELECT a.*, l.chave, c.razao_social, c.nome_fantasia, c.conferido,
          p.codigo AS produto, t.nome AS tier,
          COALESCE(u.nome_fantasia, u.empresa, u.nome) AS rev_nome,
          ur.nome AS revisado_por_nome
     FROM autocadastros a
     JOIN licencas l   ON l.id = a.licenca_id
     LEFT JOIN clientes c ON c.id = a.cliente_id
     LEFT JOIN usuarios u ON u.id = a.revendedor_id
     LEFT JOIN usuarios ur ON ur.id = a.revisado_por
     LEFT JOIN produtos p ON p.id = l.produto_id
     LEFT JOIN tiers    t ON t.id = l.tier_id
   $where
   ORDER BY a.revisado, a.criado_em DESC LIMIT 200")->fetchAll();

$cont = db()->query(
  'SELECT COUNT(*) AS total,
          SUM(revisado = 0) AS pendentes,
          SUM(resultado = "conflito" AND revisado = 0) AS conflitos,
          SUM(receita_ok = 0) AS sem_receita
     FROM autocadastros')->fetch();

abre_pagina('Autocadastros', 'autocadastros');
?>
<h1 class="titulo">Autocadastros</h1>
<p class="subtitulo">
  Clientes que se registraram ao ativar o software, sem passar pelo painel
</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="stats">
  <div class="stat"><div class="n"><?= (int)$cont['total'] ?></div>
    <div class="l">Total recebidos</div></div>
  <div class="stat"><div class="n" style="color:var(--ambar)"><?= (int)$cont['pendentes'] ?></div>
    <div class="l">A conferir</div></div>
  <div class="stat"><div class="n" style="color:var(--vermelho)"><?= (int)$cont['conflitos'] ?></div>
    <div class="l">Conflitos abertos</div></div>
  <div class="stat"><div class="n"><?= (int)$cont['sem_receita'] ?></div>
    <div class="l">Sem dados da Receita</div></div>
</div>

<div style="display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap">
  <?php foreach (['pendentes'=>'A conferir', 'conflitos'=>'Conflitos',
                  'revisados'=>'Já conferidos', ''=>'Todos'] as $k => $rot): ?>
    <a class="btn <?= $fVer === $k ? '' : 'sec' ?> pequeno"
       href="autocadastros.php?ver=<?= e($k) ?>"><?= $rot ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$lista): ?>
  <div class="card"><p class="subtitulo" style="margin:0">
    <?= $fVer === 'pendentes'
        ? 'Nenhum cadastro aguardando conferência.'
        : 'Nada com este filtro.' ?>
  </p></div>
<?php else: foreach ($lista as $a):
    $conflito = $a['resultado'] === 'conflito';
    $borda = $a['revisado'] ? 'var(--borda)'
           : ($conflito ? 'var(--vermelho)' : 'var(--ambar)');
?>
  <div class="card" style="border-left:3px solid <?= $borda ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;
         gap:16px;flex-wrap:wrap">
      <div>
        <h3 style="margin:0 0 4px">
          <?php if ($a['cliente_id']): ?>
            <a href="cliente.php?id=<?= (int)$a['cliente_id'] ?>">
              <?= e($a['nome_fantasia'] ?: $a['razao_social']) ?></a>
          <?php else: ?>
            <?= e($a['razao_informada'] ?: 'sem nome') ?>
          <?php endif; ?>
        </h3>
        <p class="subtitulo" style="margin:0">
          <?= date('d/m/Y H:i', strtotime($a['criado_em'])) ?>
          · vendido por <b><?= e($a['rev_nome'] ?: 'venda direta') ?></b>
        </p>
      </div>
      <div style="display:flex;gap:6px;align-items:center">
        <span class="badge <?= $conflito ? 'revogada'
                              : ($a['resultado']==='criado' ? 'ativa' : 'nova') ?>">
          <?= $a['resultado'] === 'criado' ? 'cadastro novo'
              : ($a['resultado'] === 'reaproveitado' ? 'já existia' : 'conflito') ?>
        </span>
        <?php if (!$a['receita_ok']): ?>
          <span class="badge expirada" title="A Receita não respondeu na hora da ativação">
            sem Receita</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($conflito && !$a['revisado']): ?>
      <div style="background:var(--bg-3);border-left:2px solid var(--vermelho);
           padding:10px 14px;margin-top:12px;font-size:12px">
        <b>Este CNPJ já estava na base com outro vínculo.</b><br>
        <?= e($a['observacao'] ?: '') ?>.
        A licença foi vinculada normalmente, mas o cadastro do cliente
        continua como estava — decida se ele passa a ser deste revendedor.
      </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:14px">
      <div>
        <h4 style="margin:0 0 8px;font-size:12px;color:var(--ambar)">
          O QUE O CLIENTE INFORMOU</h4>
        <table style="font-size:12px">
          <tr><td style="color:var(--texto-2);width:90px">CNPJ</td>
              <td class="mono"><?= e($a['cnpj_informado']) ?></td></tr>
          <tr><td style="color:var(--texto-2)">Razão social</td>
              <td><?= e($a['razao_informada'] ?: '—') ?></td></tr>
          <tr><td style="color:var(--texto-2)">Contato</td>
              <td><?= e($a['contato_informado'] ?: '—') ?></td></tr>
          <tr><td style="color:var(--texto-2)">Telefone</td>
              <td><?= e($a['telefone_informado'] ?: '—') ?></td></tr>
          <tr><td style="color:var(--texto-2)">E-mail</td>
              <td><?= e($a['email_informado'] ?: '—') ?></td></tr>
          <tr><td style="color:var(--texto-2)">Cidade</td>
              <td><?= e(trim(($a['municipio_informado'] ?: '') . ' ' .
                             ($a['uf_informada'] ?: ''))) ?: '—' ?></td></tr>
        </table>
      </div>

      <div>
        <h4 style="margin:0 0 8px;font-size:12px;color:var(--ambar)">LICENÇA</h4>
        <table style="font-size:12px">
          <tr><td style="color:var(--texto-2);width:90px">Chave</td>
              <td class="mono" style="font-size:11px"><?= e($a['chave']) ?></td></tr>
          <tr><td style="color:var(--texto-2)">Software</td>
              <td><?= e(strtoupper($a['produto'] ?? '—')) ?>
                  <?= $a['tier'] ? '· '.e($a['tier']) : '' ?></td></tr>
          <tr><td style="color:var(--texto-2)">Máquina</td>
              <td class="mono" style="font-size:11px">
                <?= e($a['fingerprint'] ?: '—') ?></td></tr>
          <tr><td style="color:var(--texto-2)">IP</td>
              <td class="mono" style="font-size:11px"><?= e($a['ip'] ?: '—') ?></td></tr>
        </table>

        <?php if ($a['revisado']): ?>
          <p class="subtitulo" style="margin:12px 0 0">
            Conferido por <?= e($a['revisado_por_nome'] ?: '—') ?>
            em <?= $a['revisado_em']
                   ? date('d/m/Y', strtotime($a['revisado_em'])) : '—' ?>
          </p>
        <?php else: ?>
          <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
            <form method="post" style="display:inline">
              <input type="hidden" name="acao" value="revisar">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= $a['id'] ?>">
              <button class="btn pequeno">Conferido</button>
            </form>
            <?php if ($conflito): ?>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Transferir este cliente para o revendedor da licença?')">
                <input type="hidden" name="acao" value="transferir">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                <button class="btn sec pequeno">Transferir para o revendedor</button>
              </form>
            <?php endif; ?>
            <?php if ($a['cliente_id']): ?>
              <a class="btn sec pequeno" href="cliente.php?id=<?= (int)$a['cliente_id'] ?>">
                Abrir cadastro</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; endif; ?>
<?php fecha_pagina();
