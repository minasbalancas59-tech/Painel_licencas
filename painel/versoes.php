<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  VERSÕES E INSTALADORES
 * =====================================================================
 *  Sobe o instalador de cada produto e gera o link para distribuir.
 *
 *  DOIS TIPOS DE LINK
 *
 *  Link fixo do produto  — sempre entrega a versão marcada como atual.
 *                          É o que você divulga uma vez e nunca mais
 *                          reenvia: publicou versão nova, quem tem o
 *                          link antigo já baixa a nova.
 *
 *  Link da versão        — aponta para um arquivo específico. Use
 *                          quando alguém precisa de uma versão que não
 *                          é a atual.
 *
 *  Os arquivos ficam em /var/licenca_arquivos, FORA do webroot: dentro
 *  dele qualquer um adivinharia a URL, e o backup diário empacotaria
 *  150 MB por versão para o Drive todo dia.
 * ===================================================================== */

const DIR_ARQ  = '/var/licenca_arquivos/instaladores';
const MANTER   = 3;   // versões guardadas por produto

$msg = ''; $tipo = '';
$u = usuario_logado();

function tam_legivel(int $b): string {
    if ($b >= 1073741824) return number_format($b/1073741824, 1, ',', '.').' GB';
    if ($b >= 1048576)    return number_format($b/1048576, 1, ',', '.').' MB';
    if ($b >= 1024)       return number_format($b/1024, 0, ',', '.').' KB';
    return $b . ' B';
}

function base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
}

/* O envio do instalador vem por XMLHttpRequest para o navegador poder
   mostrar o progresso. Nesse caso a resposta é JSON em vez de
   redirecionamento — a página não recarrega. */
$ajax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

function resp_json(array $d, int $http = 200): void {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valido() && $ajax)
    resp_json(['ok' => false, 'msg' => 'Sessão expirada. Recarregue a página.'], 403);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valido()) {
    $ac = $_POST['acao'] ?? '';

    /* ---- enviar instalador ---------------------------------------- */
    if ($ac === 'enviar') {
        $pid    = (int)($_POST['produto_id'] ?? 0);
        $versao = trim($_POST['versao'] ?? '');
        $notas  = trim($_POST['notas'] ?? '');
        $marcar = !empty($_POST['marcar_atual']);
        $f      = $_FILES['arquivo'] ?? null;

        try {
            if (!$pid || $versao === '')
                throw new RuntimeException('Informe o software e a versão.');

            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                // a mensagem do PHP é críptica; traduz o que importa
                $e = $f['error'] ?? -1;
                if ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE)
                    throw new RuntimeException(
                        'Arquivo maior que o limite do servidor. '
                      . 'Rode o setup_downloads.sh para liberar.');
                if ($e === UPLOAD_ERR_NO_FILE)
                    throw new RuntimeException('Escolha o arquivo do instalador.');
                if ($e === UPLOAD_ERR_PARTIAL)
                    throw new RuntimeException(
                        'O envio foi interrompido. Tente de novo.');
                throw new RuntimeException('Falha no envio (código ' . $e . ').');
            }

            if (!is_dir(DIR_ARQ))
                throw new RuntimeException(
                    'Pasta ' . DIR_ARQ . ' não existe. Rode o setup_downloads.sh.');
            if (!is_writable(DIR_ARQ))
                throw new RuntimeException(
                    'Sem permissão de escrita em ' . DIR_ARQ . '.');

            // extensão: só o que faz sentido distribuir
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['exe', 'zip', 'msi', 'rar', '7z'], true))
                throw new RuntimeException(
                    'Formato não aceito. Use exe, msi, zip, rar ou 7z.');

            $st = db()->prepare('SELECT codigo FROM produtos WHERE id=?');
            $st->execute([$pid]);
            $cod = $st->fetchColumn();
            if (!$cod) throw new RuntimeException('Software não encontrado.');

            $verLimpa = preg_replace('/[^0-9A-Za-z._-]/', '', $versao);
            $token    = bin2hex(random_bytes(10));
            $nomeDisco = $cod . '_' . $verLimpa . '_' . substr($token,0,6) . '.' . $ext;
            $destino   = DIR_ARQ . '/' . $nomeDisco;

            if (!move_uploaded_file($f['tmp_name'], $destino))
                throw new RuntimeException('Não foi possível gravar o arquivo.');

            // hash para o cliente conferir que o download veio inteiro
            $sha = hash_file('sha256', $destino);

            db()->beginTransaction();
            try {
                db()->prepare(
                  'INSERT INTO versoes
                     (produto_id, versao, token, arquivo, nome_original,
                      tamanho, sha256, notas, enviado_por)
                   VALUES (?,?,?,?,?,?,?,?,?)')
                  ->execute([$pid, $versao, $token, $nomeDisco,
                             mb_substr($f['name'],0,255), filesize($destino),
                             $sha, ($notas ?: null), $u['id']]);
                $novoId = (int)db()->lastInsertId();

                if ($marcar) {
                    db()->prepare('UPDATE versoes SET atual=0 WHERE produto_id=?')
                        ->execute([$pid]);
                    db()->prepare('UPDATE versoes SET atual=1 WHERE id=?')
                        ->execute([$novoId]);
                }
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                @unlink($destino);
                throw $e;
            }

            /* --- expurgo: guarda as MANTER últimas -------------------
             * Sem isto o disco cresce para sempre. Versão marcada como
             * atual nunca é apagada, mesmo que seja antiga. */
            $velhas = db()->prepare(
              'SELECT id, arquivo FROM versoes
                WHERE produto_id=? AND atual=0
                ORDER BY id DESC LIMIT 100 OFFSET ' . MANTER);
            $velhas->execute([$pid]);
            $n = 0;
            foreach ($velhas->fetchAll() as $v) {
                @unlink(DIR_ARQ . '/' . $v['arquivo']);
                db()->prepare('DELETE FROM versoes WHERE id=?')->execute([$v['id']]);
                $n++;
            }

            $txt = 'Versão ' . $versao . ' publicada.'
                 . ($marcar ? ' Marcada como atual.' : '')
                 . ($n ? " $n versão(ões) antiga(s) removida(s)." : '');

            if ($ajax) resp_json(['ok' => true, 'msg' => $txt]);
            $_SESSION['flashV'] = [$txt, 'ok'];
        } catch (Throwable $e) {
            if ($ajax) resp_json(['ok' => false, 'msg' => $e->getMessage()], 400);
            $_SESSION['flashV'] = [$e->getMessage(), 'erro'];
        }
        header('Location: versoes.php'); exit;
    }

    /* ---- marcar como atual ---------------------------------------- */
    if ($ac === 'marcar') {
        $id = (int)$_POST['id'];
        $st = db()->prepare('SELECT produto_id, versao FROM versoes WHERE id=?');
        $st->execute([$id]);
        if ($v = $st->fetch()) {
            db()->prepare('UPDATE versoes SET atual=0 WHERE produto_id=?')
                ->execute([$v['produto_id']]);
            db()->prepare('UPDATE versoes SET atual=1, publicada=1 WHERE id=?')
                ->execute([$id]);
            $_SESSION['flashV'] = ['Versão ' . $v['versao'] . ' agora é a atual.', 'ok'];
        }
        header('Location: versoes.php'); exit;
    }

    /* ---- publicar / despublicar ----------------------------------- */
    if ($ac === 'publicar') {
        db()->prepare('UPDATE versoes SET publicada = 1 - publicada WHERE id=? AND atual=0')
            ->execute([(int)$_POST['id']]);
        $_SESSION['flashV'] = ['Situação alterada.', 'ok'];
        header('Location: versoes.php'); exit;
    }

    /* ---- remover --------------------------------------------------- */
    if ($ac === 'remover') {
        $id = (int)$_POST['id'];
        $st = db()->prepare('SELECT arquivo, atual FROM versoes WHERE id=?');
        $st->execute([$id]);
        $v = $st->fetch();
        if (!$v) {
            $_SESSION['flashV'] = ['Versão não encontrada.', 'erro'];
        } elseif ($v['atual']) {
            // apagar a atual deixaria o link fixo apontando para o vazio
            $_SESSION['flashV'] = [
                'Não é possível remover a versão atual. Marque outra primeiro.',
                'erro'];
        } else {
            @unlink(DIR_ARQ . '/' . $v['arquivo']);
            db()->prepare('DELETE FROM versoes WHERE id=?')->execute([$id]);
            $_SESSION['flashV'] = ['Versão removida.', 'ok'];
        }
        header('Location: versoes.php'); exit;
    }
}

if (!empty($_SESSION['flashV'])) {
    [$msg, $tipo] = $_SESSION['flashV'];
    unset($_SESSION['flashV']);
}

/* ---- dados ---------------------------------------------------------- */
$produtos = db()->query(
  'SELECT id, codigo, nome, token_download FROM produtos
    WHERE ativo=1 ORDER BY codigo')->fetchAll();

$versoes = db()->query(
  "SELECT v.*, p.codigo AS produto_codigo, p.nome AS produto_nome,
          u.nome AS enviado_por_nome,
          (SELECT COUNT(*) FROM downloads_log d
            WHERE d.versao_id = v.id
              AND d.criado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS dl30
     FROM versoes v
     JOIN produtos p ON p.id = v.produto_id
     LEFT JOIN usuarios u ON u.id = v.enviado_por
    ORDER BY p.codigo, v.id DESC")->fetchAll();

$porProduto = [];
foreach ($versoes as $v) $porProduto[$v['produto_id']][] = $v;

// limites reais do servidor, para avisar antes de o envio falhar
$limPhp = min(
    (int)str_replace(['M','m'], '', ini_get('upload_max_filesize')),
    (int)str_replace(['M','m'], '', ini_get('post_max_size')));

$espaco = @disk_free_space('/var');
$dirOk  = is_dir(DIR_ARQ) && is_writable(DIR_ARQ);

abre_pagina('Versões', 'versoes');
?>
<h1 class="titulo">Versões e instaladores</h1>
<p class="subtitulo">
  Publique o instalador e distribua o link. Quem tem o link fixo sempre
  baixa a versão mais recente.
</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<?php if (!$dirOk): ?>
  <div class="card" style="border-left:3px solid var(--vermelho)">
    <h3 style="margin-top:0">Servidor não preparado</h3>
    <p style="margin:0;font-size:13px">
      A pasta <span class="mono"><?= DIR_ARQ ?></span> não existe ou não
      tem permissão de escrita. Rode no servidor:
    </p>
    <div class="codigo" style="margin-top:10px;font-size:12px">bash /root/uploads/setup_downloads.sh</div>
  </div>
<?php elseif ($limPhp < 50): ?>
  <div class="card" style="border-left:3px solid var(--ambar)">
    <h3 style="margin-top:0">Limite de envio baixo</h3>
    <p style="margin:0;font-size:13px">
      O servidor aceita no máximo <b><?= $limPhp ?> MB</b> por envio —
      instaladores costumam passar disso. Rode
      <span class="mono">setup_downloads.sh</span> para liberar.
    </p>
  </div>
<?php endif; ?>

<div class="card">
  <h3>Publicar versão</h3>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="acao" value="enviar">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr 2fr;gap:16px">
      <div>
        <label>Software *</label>
        <select name="produto_id" required>
          <option value="">— selecione —</option>
          <?php foreach ($produtos as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Versão *</label>
        <input name="versao" required placeholder="5.14.2" maxlength="30">
      </div>
      <div>
        <label>Instalador *</label>
        <input type="file" name="arquivo" required
               accept=".exe,.msi,.zip,.rar,.7z">
        <span class="subtitulo" style="margin:4px 0 0;display:block;font-size:11px">
          até <?= $limPhp ?> MB · exe, msi, zip, rar ou 7z
        </span>
      </div>
    </div>

    <label style="margin-top:14px">O que mudou nesta versão</label>
    <textarea name="notas" style="min-height:70px"
              placeholder="Aparece na lista, para você lembrar depois"></textarea>

    <label style="display:flex;align-items:center;gap:8px;margin-top:14px;
           text-transform:none">
      <input type="checkbox" name="marcar_atual" value="1" checked
             style="width:auto">
      Marcar como versão atual — quem usar o link fixo baixa esta
    </label>

    <div style="margin-top:16px">
      <button class="btn" id="btnPublicar">Publicar</button>
      <span class="subtitulo" id="avisoEnvio" style="margin-left:12px">
        Envio pode demorar alguns minutos. Não feche a página.
      </span>
    </div>

    <!-- Progresso do envio. Sem isto, um arquivo de 150 MB parece
         travado e a pessoa fecha a página no meio. -->
    <div id="boxProgresso" style="display:none;margin-top:18px">
      <div style="display:flex;justify-content:space-between;
           align-items:baseline;margin-bottom:6px">
        <span id="txtProgresso" style="font-size:13px">Enviando…</span>
        <span id="pctProgresso" class="mono" style="font-size:13px;
              color:var(--ambar)">0%</span>
      </div>
      <div style="background:var(--bg-3);height:10px;border-radius:5px;
           overflow:hidden">
        <div id="barraProgresso" style="height:10px;width:0%;
             background:var(--ambar);transition:width .2s"></div>
      </div>
      <div id="detProgresso" class="subtitulo"
           style="margin:6px 0 0;font-size:11px"></div>
    </div>

    <div id="msgResultado" style="display:none;margin-top:18px"></div>
  </form>
</div>

<?php foreach ($produtos as $p):
    $lista = $porProduto[$p['id']] ?? [];
    $linkFixo = base_url() . '/baixar.php?p=' . $p['token_download'];
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;
       gap:16px;flex-wrap:wrap">
    <div>
      <h3 style="margin:0 0 4px"><?= e($p['nome']) ?></h3>
      <p class="subtitulo" style="margin:0">
        <?= count($lista) ?> versão(ões) publicada(s)
      </p>
    </div>
  </div>

  <div style="background:var(--bg-3);padding:12px 14px;margin-top:12px;
       border-left:2px solid var(--ambar)">
    <div style="font-size:11px;color:var(--texto-2);margin-bottom:6px">
      LINK FIXO — sempre entrega a versão atual
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <code class="mono" style="font-size:12px;flex:1;min-width:260px;
            word-break:break-all"><?= e($linkFixo) ?></code>
      <button type="button" class="btn sec pequeno"
              data-link="<?= e($linkFixo) ?>" onclick="copiarLink(this)">
        Copiar link</button>
    </div>
    <p class="subtitulo" style="margin:8px 0 0;font-size:11px">
      Divulgue este. Ao publicar uma versão nova, quem já tem o link
      passa a baixar a nova sem você reenviar nada.
    </p>
  </div>

  <?php if (!$lista): ?>
    <p class="subtitulo" style="margin-top:14px">
      Nenhum instalador publicado para este software ainda.
    </p>
  <?php else: ?>
    <table style="margin-top:14px">
      <thead><tr>
        <th>Versão</th><th>Arquivo</th><th style="text-align:right">Tamanho</th>
        <th style="text-align:right">Downloads</th><th>Publicada em</th>
        <th>Situação</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($lista as $v):
          $linkVer = base_url() . '/baixar.php?t=' . $v['token'];
      ?>
        <tr>
          <td>
            <b><?= e($v['versao']) ?></b>
            <?php if ($v['notas']): ?>
              <br><span style="font-size:11px;color:var(--texto-2)">
                <?= e(mb_substr($v['notas'], 0, 90)) ?></span>
            <?php endif; ?>
          </td>
          <td class="mono" style="font-size:11px">
            <?= e($v['nome_original'] ?: $v['arquivo']) ?>
            <?php if ($v['sha256']): ?>
              <br><span style="font-size:10px;color:var(--texto-2)"
                    title="Confira depois de baixar, para saber se o arquivo veio inteiro">
                sha <?= e(substr($v['sha256'], 0, 12)) ?>…</span>
            <?php endif; ?>
          </td>
          <td class="mono" style="text-align:right;font-size:12px">
            <?= tam_legivel((int)$v['tamanho']) ?></td>
          <td class="mono" style="text-align:right">
            <?= (int)$v['downloads'] ?>
            <?php if ((int)$v['dl30'] > 0): ?>
              <br><span style="font-size:10px;color:var(--texto-2)">
                <?= (int)$v['dl30'] ?> em 30d</span>
            <?php endif; ?>
          </td>
          <td class="mono" style="font-size:11px">
            <?= date('d/m/Y', strtotime($v['criado_em'])) ?></td>
          <td>
            <?php if ($v['atual']): ?>
              <span class="badge ativa">atual</span>
            <?php elseif ($v['publicada']): ?>
              <span class="badge nova">publicada</span>
            <?php else: ?>
              <span class="badge expirada">fora do ar</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap">
            <button type="button" class="btn sec pequeno"
                    data-link="<?= e($linkVer) ?>" onclick="copiarLink(this)">
              Link</button>
            <?php if (!$v['atual']): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="acao" value="marcar">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= $v['id'] ?>">
                <button class="btn sec pequeno">Tornar atual</button>
              </form>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Remover esta versão e apagar o arquivo?')">
                <input type="hidden" name="acao" value="remover">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= $v['id'] ?>">
                <button class="btn perigo pequeno">Remover</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card">
  <h3>Como funciona</h3>
  <p style="font-size:13px;line-height:1.8;margin:0">
    <b>Os arquivos ficam fora da pasta do site</b>, em
    <span class="mono"><?= DIR_ARQ ?></span>. Ninguém baixa adivinhando
    a URL: o download passa por um script que valida o link e registra
    quem baixou.<br><br>
    <b>Guardamos as <?= MANTER ?> últimas versões</b> de cada software.
    Ao publicar uma nova, as mais antigas são apagadas — a marcada como
    atual nunca é removida.<br><br>
    <b>O espaço em disco</b> hoje é de
    <?= $espaco ? tam_legivel((int)$espaco) : '—' ?> livres.
  </p>
</div>

<script>
/* Envio com barra de progresso.

   O POST comum não dá visibilidade nenhuma: a página fica em branco
   por minutos e a pessoa acha que travou. Com XMLHttpRequest dá para
   acompanhar os bytes enviados e mostrar quanto falta. */
(function () {
  var form = document.querySelector('form[enctype]');
  if (!form) return;

  var btn   = document.getElementById('btnPublicar');
  var box   = document.getElementById('boxProgresso');
  var barra = document.getElementById('barraProgresso');
  var pct   = document.getElementById('pctProgresso');
  var txt   = document.getElementById('txtProgresso');
  var det   = document.getElementById('detProgresso');
  var aviso = document.getElementById('avisoEnvio');
  var res   = document.getElementById('msgResultado');

  function mb(b) { return (b / 1048576).toFixed(1).replace('.', ',') + ' MB'; }

  function mostrar(ok, texto) {
    res.style.display = '';
    res.className = 'aviso ' + (ok ? 'ok' : 'erro');
    res.textContent = texto;
    res.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  form.addEventListener('submit', function (ev) {
    var arq = form.querySelector('input[type=file]');
    if (!arq || !arq.files.length) return;   // deixa o HTML reclamar

    ev.preventDefault();
    res.style.display = 'none';
    box.style.display = '';
    aviso.textContent = '';
    btn.disabled = true;
    btn.textContent = 'Enviando…';

    var xhr = new XMLHttpRequest();
    var t0 = Date.now();

    xhr.upload.addEventListener('progress', function (e) {
      if (!e.lengthComputable) return;
      var p = Math.round((e.loaded / e.total) * 100);
      barra.style.width = p + '%';
      pct.textContent = p + '%';

      var seg = (Date.now() - t0) / 1000;
      var vel = seg > 0 ? e.loaded / seg : 0;
      var falta = vel > 0 ? Math.round((e.total - e.loaded) / vel) : 0;

      det.textContent = mb(e.loaded) + ' de ' + mb(e.total) +
        (vel > 0 ? ' · ' + mb(vel) + '/s' : '') +
        (falta > 0 && p < 100 ? ' · faltam ' + (falta > 60
            ? Math.ceil(falta / 60) + ' min' : falta + 's') : '');
    });

    // 100% enviado não é 100% pronto: o servidor ainda grava o arquivo
    // e calcula o hash. Sem este aviso, a barra cheia e nada
    // acontecendo parece travamento.
    xhr.upload.addEventListener('load', function () {
      txt.textContent = 'Processando no servidor…';
      det.textContent = 'Gravando o arquivo e conferindo a integridade.';
    });

    xhr.addEventListener('load', function () {
      btn.disabled = false;
      btn.textContent = 'Publicar';
      box.style.display = 'none';
      aviso.textContent = 'Envio pode demorar alguns minutos. Não feche a página.';

      var r;
      try { r = JSON.parse(xhr.responseText); }
      catch (e) {
        mostrar(false, 'Resposta inesperada do servidor (HTTP ' +
                       xhr.status + ').');
        return;
      }

      mostrar(r.ok, r.msg || (r.ok ? 'Enviado.' : 'Falha no envio.'));
      if (r.ok) {
        form.reset();
        // recarrega para a versão nova aparecer na lista
        setTimeout(function () { location.reload(); }, 2500);
      }
    });

    xhr.addEventListener('error', function () {
      btn.disabled = false;
      btn.textContent = 'Publicar';
      box.style.display = 'none';
      mostrar(false, 'A conexão caiu durante o envio. Tente de novo.');
    });

    xhr.addEventListener('abort', function () {
      btn.disabled = false;
      btn.textContent = 'Publicar';
      box.style.display = 'none';
      mostrar(false, 'Envio cancelado.');
    });

    xhr.open('POST', 'versoes.php');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send(new FormData(form));
  });
})();

function copiarLink(btn) {
  var txt = btn.getAttribute('data-link');
  var ok = function () {
    var antes = btn.textContent;
    btn.textContent = 'Copiado!';
    setTimeout(function () { btn.textContent = antes; }, 1800);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(txt).then(ok);
  } else {
    var ta = document.createElement('textarea');
    ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); ok(); } catch (e) {}
    document.body.removeChild(ta);
  }
}
</script>
<?php fecha_pagina();
