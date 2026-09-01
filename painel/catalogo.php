<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();
exige_admin_escopo();

/* =====================================================================
 *  CATALOGO - softwares, tipos de licenca e modulos
 * =====================================================================
 *  O que era seed SQL agora tem tela. Tres niveis:
 *
 *    SOFTWARE (produtos)  ts5, ts6, tslpr
 *      TIPO   (tiers)     light, basico, cameras... com NIVEL cumulativo
 *      MODULO (modulos)   TBE, RFID, LPR - marcados na emissao
 *
 *  DIFERENCA ENTRE TIPO E MODULO
 *    Tipo  = um so por licenca, cumulativo. Nivel 5 libera tudo de 1 a 5.
 *            No Delphi: NivelLiberado(n).
 *    Modulo= varios por licenca, independentes entre si.
 *            No Delphi: TemModulo('RFID').
 *
 *  CUIDADO AO EDITAR CODIGOS
 *  produto.codigo, tier.codigo e modulo.codigo entram no payload
 *  ASSINADO da licenca. Trocar o codigo de algo ja emitido faz o
 *  software do cliente deixar de reconhecer o que ele comprou na
 *  proxima revalidacao. Nome e descricao podem mudar a vontade; o
 *  codigo, so em item que nunca foi usado.
 * ===================================================================== */

$msg=''; $tipo='';
$u = usuario_logado();

function em_uso_produto(int $id): int {
    $st = db()->prepare('SELECT COUNT(*) FROM licencas WHERE produto_id=?');
    $st->execute([$id]); return (int)$st->fetchColumn();
}
function em_uso_tier(int $id): int {
    $st = db()->prepare('SELECT COUNT(*) FROM licencas WHERE tier_id=?');
    $st->execute([$id]); return (int)$st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_valido()) {
    $ac = $_POST['acao'] ?? '';
    try {
        /* ---------------- softwares ---------------- */
        if ($ac === 'produto_novo') {
            $cod = strtolower(preg_replace('/[^a-zA-Z0-9_]/','', $_POST['codigo'] ?? ''));
            $nome = trim($_POST['nome'] ?? '');
            if ($cod==='' || $nome==='') throw new RuntimeException('Informe código e nome.');
            db()->prepare('INSERT INTO produtos (codigo,nome,descricao,ativo) VALUES (?,?,?,1)')
                ->execute([$cod,$nome,(trim($_POST['descricao'] ?? '') ?: null)]);
            $msg="Software \"$nome\" cadastrado."; $tipo='ok';
        }
        elseif ($ac === 'produto_editar') {
            $id = (int)$_POST['id'];
            $nome = trim($_POST['nome'] ?? '');
            if ($nome==='') throw new RuntimeException('O nome não pode ficar vazio.');
            // codigo so muda se nunca foi usado
            if (em_uso_produto($id) === 0) {
                $cod = strtolower(preg_replace('/[^a-zA-Z0-9_]/','', $_POST['codigo'] ?? ''));
                db()->prepare('UPDATE produtos SET codigo=?, nome=?, descricao=? WHERE id=?')
                    ->execute([$cod,$nome,(trim($_POST['descricao'] ?? '') ?: null),$id]);
            } else {
                db()->prepare('UPDATE produtos SET nome=?, descricao=? WHERE id=?')
                    ->execute([$nome,(trim($_POST['descricao'] ?? '') ?: null),$id]);
            }
            $msg='Software atualizado.'; $tipo='ok';
        }
        elseif ($ac === 'produto_ativo') {
            db()->prepare('UPDATE produtos SET ativo = 1 - ativo WHERE id=?')
                ->execute([(int)$_POST['id']]);
            $msg='Situação do software alterada.'; $tipo='ok';
        }

        /* ---------------- tipos (tiers) ---------------- */
        elseif ($ac === 'tier_novo') {
            $pid  = (int)$_POST['produto_id'];
            $cod  = strtolower(preg_replace('/[^a-zA-Z0-9_]/','', $_POST['codigo'] ?? ''));
            $nome = trim($_POST['nome'] ?? '');
            $niv  = max(1, min(99, (int)($_POST['nivel'] ?? 1)));
            if (!$pid || $cod==='' || $nome==='')
                throw new RuntimeException('Preencha software, código e nome.');
            $preco = str_replace(['.', ','], ['', '.'],
                                 trim($_POST['preco_base'] ?? ''));
            db()->prepare(
              'INSERT INTO tiers
                 (produto_id,codigo,nome,descricao,nivel,preco_base,ativo)
               VALUES (?,?,?,?,?,?,1)')
              ->execute([$pid,$cod,$nome,(trim($_POST['descricao'] ?? '') ?: null),
                         $niv, ($preco === '' ? null : (float)$preco)]);
            $msg="Tipo \"$nome\" cadastrado."; $tipo='ok';
        }
        elseif ($ac === 'tier_editar') {
            $id = (int)$_POST['id'];
            $nome = trim($_POST['nome'] ?? '');
            $niv  = max(1, min(99, (int)($_POST['nivel'] ?? 1)));
            if ($nome==='') throw new RuntimeException('O nome não pode ficar vazio.');
            // O preco SEMPRE pode mudar, mesmo com licencas emitidas:
            // e tabela de referencia para as proximas vendas, nao um
            // valor que ja foi cobrado. O que foi cobrado esta em
            // financeiro_mov e nao e alterado por aqui.
            $preco = str_replace(['.', ','], ['', '.'],
                                 trim($_POST['preco_base'] ?? ''));
            $preco = $preco === '' ? null : (float)$preco;

            if (em_uso_tier($id) === 0) {
                $cod = strtolower(preg_replace('/[^a-zA-Z0-9_]/','', $_POST['codigo'] ?? ''));
                db()->prepare(
                  'UPDATE tiers SET codigo=?, nome=?, descricao=?, nivel=?,
                          preco_base=? WHERE id=?')
                  ->execute([$cod,$nome,(trim($_POST['descricao'] ?? '') ?: null),
                             $niv,$preco,$id]);
            } else {
                // nivel travado: mudar reclassifica licencas ja emitidas
                db()->prepare(
                  'UPDATE tiers SET nome=?, descricao=?, preco_base=? WHERE id=?')
                  ->execute([$nome,(trim($_POST['descricao'] ?? '') ?: null),
                             $preco,$id]);
            }
            $msg='Tipo atualizado.'; $tipo='ok';
        }
        elseif ($ac === 'tier_ativo') {
            db()->prepare('UPDATE tiers SET ativo = 1 - ativo WHERE id=?')
                ->execute([(int)$_POST['id']]);
            $msg='Situação do tipo alterada.'; $tipo='ok';
        }

        /* ---------------- modulos ---------------- */
        elseif ($ac === 'modulo_novo') {
            $cod = strtoupper(preg_replace('/[^a-zA-Z0-9]/','', $_POST['codigo'] ?? ''));
            $nome = trim($_POST['nome'] ?? '');
            if ($cod==='' || $nome==='') throw new RuntimeException('Informe código e nome.');
            db()->prepare(
              'INSERT INTO modulos (codigo,nome,descricao,produto_id,ordem,ativo)
               VALUES (?,?,?,?,?,1)')
              ->execute([$cod,$nome,(trim($_POST['descricao'] ?? '') ?: null),
                         ((int)($_POST['produto_id'] ?? 0) ?: null),
                         (int)($_POST['ordem'] ?? 0)]);
            $msg="Módulo \"$cod\" cadastrado."; $tipo='ok';
        }
        elseif ($ac === 'modulo_editar') {
            db()->prepare(
              'UPDATE modulos SET nome=?, descricao=?, produto_id=?, ordem=? WHERE id=?')
              ->execute([trim($_POST['nome'] ?? ''),
                         (trim($_POST['descricao'] ?? '') ?: null),
                         ((int)($_POST['produto_id'] ?? 0) ?: null),
                         (int)($_POST['ordem'] ?? 0), (int)$_POST['id']]);
            $msg='Módulo atualizado.'; $tipo='ok';
        }
        elseif ($ac === 'modulo_ativo') {
            db()->prepare('UPDATE modulos SET ativo = 1 - ativo WHERE id=?')
                ->execute([(int)$_POST['id']]);
            $msg='Situação do módulo alterada.'; $tipo='ok';
        }
    } catch (Throwable $e) {
        $msg = 'Erro: ' . $e->getMessage(); $tipo='erro';
    }
}

// ---- dados ----------------------------------------------------------
$produtos = db()->query(
  'SELECT p.*,
          (SELECT COUNT(*) FROM tiers t WHERE t.produto_id=p.id) AS n_tiers,
          (SELECT COUNT(*) FROM licencas l WHERE l.produto_id=p.id) AS n_lic
     FROM produtos p ORDER BY p.codigo')->fetchAll();

$tiers = db()->query(
  'SELECT t.*, p.codigo AS produto_codigo,
          (SELECT COUNT(*) FROM licencas l WHERE l.tier_id=t.id) AS n_lic
     FROM tiers t LEFT JOIN produtos p ON p.id=t.produto_id
    ORDER BY p.codigo, t.nivel')->fetchAll();

$modulos = db()->query(
  'SELECT m.*, p.codigo AS produto_codigo
     FROM modulos m LEFT JOIN produtos p ON p.id=m.produto_id
    ORDER BY COALESCE(p.codigo,""), m.ordem, m.codigo')->fetchAll();

abre_pagina('Catálogo', 'catalogo');
?>
<h1 class="titulo">Catálogo</h1>
<p class="subtitulo">Softwares, tipos de licença e módulos disponíveis para emissão</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card" style="border-left:3px solid var(--azul)">
  <p style="margin:0;font-size:12px">
    <b>Tipo</b> é único por licença e cumulativo — nível 5 libera tudo de 1 a 5
    (no software: <span class="mono">NivelLiberado</span>).
    <b>Módulo</b> é independente, vários por licença
    (<span class="mono">TemModulo</span>).
    <br><br>
    Os <b>códigos</b> entram na licença assinada. Depois que algo é emitido,
    o código fica travado: alterá-lo faria o software do cliente deixar de
    reconhecer o que ele comprou. Nome e descrição podem mudar sempre.
  </p>
</div>

<!-- ============================ SOFTWARES ============================ -->
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h3 style="margin:0">Softwares (<?= count($produtos) ?>)</h3>
    <button type="button" class="btn sec pequeno" onclick="alternar('novoProduto')">
      + Novo software</button>
  </div>

  <div id="novoProduto" style="display:none;margin-top:14px">
    <form method="post">
      <input type="hidden" name="acao" value="produto_novo">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div style="display:grid;grid-template-columns:1fr 2fr 3fr;gap:14px">
        <div><label>Código *</label>
          <input name="codigo" required placeholder="ts7" maxlength="20"></div>
        <div><label>Nome *</label>
          <input name="nome" required placeholder="Total Scale 7"></div>
        <div><label>Descrição</label><input name="descricao"></div>
      </div>
      <div style="margin-top:12px">
        <button class="btn">Cadastrar</button>
        <button type="button" class="btn sec" style="margin-left:8px"
                onclick="alternar('novoProduto')">Cancelar</button>
      </div>
    </form>
  </div>

  <table style="margin-top:14px">
    <thead><tr><th>Código</th><th>Nome</th><th>Tipos</th>
      <th>Licenças</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($produtos as $p): ?>
      <tr id="pver<?= $p['id'] ?>">
        <td class="mono"><?= e($p['codigo']) ?></td>
        <td><b><?= e($p['nome']) ?></b>
          <?php if ($p['descricao']): ?>
            <br><span style="font-size:11px;color:var(--texto-2)">
              <?= e($p['descricao']) ?></span>
          <?php endif; ?></td>
        <td class="mono"><?= (int)$p['n_tiers'] ?></td>
        <td class="mono"><?= (int)$p['n_lic'] ?></td>
        <td><span class="badge <?= $p['ativo']?'ativa':'expirada' ?>">
          <?= $p['ativo']?'ativo':'inativo' ?></span></td>
        <td style="white-space:nowrap">
          <button type="button" class="btn sec pequeno"
                  onclick="editar('p', <?= $p['id'] ?>)">Editar</button>
          <form method="post" style="display:inline">
            <input type="hidden" name="acao" value="produto_ativo">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button class="btn sec pequeno"><?= $p['ativo']?'Desativar':'Ativar' ?></button>
          </form>
        </td>
      </tr>
      <tr id="pedt<?= $p['id'] ?>" style="display:none">
        <td colspan="6" style="background:var(--bg-3)">
          <form method="post">
            <input type="hidden" name="acao" value="produto_editar">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 2fr 3fr;gap:14px">
              <div><label>Código
                  <?php if ((int)$p['n_lic']>0): ?>
                    <span style="text-transform:none;color:var(--texto-2)">· travado</span>
                  <?php endif; ?></label>
                <input name="codigo" value="<?= e($p['codigo']) ?>"
                       <?= (int)$p['n_lic']>0 ? 'readonly' : '' ?>></div>
              <div><label>Nome *</label>
                <input name="nome" required value="<?= e($p['nome']) ?>"></div>
              <div><label>Descrição</label>
                <input name="descricao" value="<?= e($p['descricao'] ?? '') ?>"></div>
            </div>
            <?php if ((int)$p['n_lic']>0): ?>
              <p class="subtitulo" style="margin:8px 0 0;font-size:11px">
                <?= (int)$p['n_lic'] ?> licença(s) já usam este código.
              </p>
            <?php endif; ?>
            <div style="margin-top:10px">
              <button class="btn pequeno">Salvar</button>
              <button type="button" class="btn sec pequeno" style="margin-left:6px"
                      onclick="editar('p', <?= $p['id'] ?>)">Cancelar</button>
            </div>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ============================== TIPOS ============================== -->
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h3 style="margin:0">Tipos de licença (<?= count($tiers) ?>)</h3>
    <button type="button" class="btn sec pequeno" onclick="alternar('novoTier')">
      + Novo tipo</button>
  </div>

  <div id="novoTier" style="display:none;margin-top:14px">
    <form method="post">
      <input type="hidden" name="acao" value="tier_novo">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr 2fr 1fr 1fr 2fr;gap:14px">
        <div><label>Software *</label>
          <select name="produto_id" required>
            <option value="">— selecione —</option>
            <?php foreach ($produtos as $p): ?>
              <option value="<?= $p['id'] ?>"><?= e($p['nome']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label>Código *</label><input name="codigo" required placeholder="premium"></div>
        <div><label>Nome *</label><input name="nome" required placeholder="Premium"></div>
        <div><label>Nível *</label>
          <input name="nivel" type="number" min="1" max="99" value="1" required></div>
        <div><label>Preço anual (R$)</label>
          <input name="preco_base" inputmode="decimal" placeholder="0,00"></div>
        <div><label>Descrição</label><input name="descricao"></div>
      </div>
      <p class="subtitulo" style="margin:8px 0 0;font-size:11px">
        O nível é cumulativo: um cliente com nível 4 tem acesso a tudo dos
        níveis 1 a 4. Use números distintos dentro do mesmo software.
      </p>
      <div style="margin-top:12px">
        <button class="btn">Cadastrar</button>
        <button type="button" class="btn sec" style="margin-left:8px"
                onclick="alternar('novoTier')">Cancelar</button>
      </div>
    </form>
  </div>

  <table style="margin-top:14px">
    <thead><tr><th>Software</th><th>Nível</th><th>Código</th><th>Nome</th>
      <th style="text-align:right">Preço anual</th>
      <th>Licenças</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tiers as $t): ?>
      <tr id="tver<?= $t['id'] ?>">
        <td class="mono" style="font-size:11px"><?= e(strtoupper($t['produto_codigo'] ?? '—')) ?></td>
        <td class="mono"><?= (int)$t['nivel'] ?></td>
        <td class="mono" style="font-size:11px"><?= e($t['codigo']) ?></td>
        <td><b><?= e($t['nome']) ?></b>
          <?php if ($t['descricao']): ?>
            <br><span style="font-size:11px;color:var(--texto-2)">
              <?= e($t['descricao']) ?></span>
          <?php endif; ?></td>
        <td class="mono" style="text-align:right">
          <?= $t['preco_base'] !== null
              ? 'R$ ' . number_format((float)$t['preco_base'],2,',','.')
              : '<span style="color:var(--texto-2)">—</span>' ?></td>
        <td class="mono"><?= (int)$t['n_lic'] ?></td>
        <td><span class="badge <?= $t['ativo']?'ativa':'expirada' ?>">
          <?= $t['ativo']?'ativo':'inativo' ?></span></td>
        <td style="white-space:nowrap">
          <button type="button" class="btn sec pequeno"
                  onclick="editar('t', <?= $t['id'] ?>)">Editar</button>
          <form method="post" style="display:inline">
            <input type="hidden" name="acao" value="tier_ativo">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <button class="btn sec pequeno"><?= $t['ativo']?'Desativar':'Ativar' ?></button>
          </form>
        </td>
      </tr>
      <tr id="tedt<?= $t['id'] ?>" style="display:none">
        <td colspan="8" style="background:var(--bg-3)">
          <form method="post">
            <input type="hidden" name="acao" value="tier_editar">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 2fr 1fr 1fr 3fr;gap:14px">
              <div><label>Código
                  <?php if ((int)$t['n_lic']>0): ?>
                    <span style="text-transform:none;color:var(--texto-2)">· travado</span>
                  <?php endif; ?></label>
                <input name="codigo" value="<?= e($t['codigo']) ?>"
                       <?= (int)$t['n_lic']>0 ? 'readonly' : '' ?>></div>
              <div><label>Nome *</label>
                <input name="nome" required value="<?= e($t['nome']) ?>"></div>
              <div><label>Nível
                  <?php if ((int)$t['n_lic']>0): ?>
                    <span style="text-transform:none;color:var(--texto-2)">· travado</span>
                  <?php endif; ?></label>
                <input name="nivel" type="number" min="1" max="99"
                       value="<?= (int)$t['nivel'] ?>"
                       <?= (int)$t['n_lic']>0 ? 'readonly' : '' ?>></div>
              <div><label>Preço anual (R$)</label>
                <input name="preco_base" inputmode="decimal"
                       value="<?= $t['preco_base'] !== null
                           ? number_format((float)$t['preco_base'],2,',','.') : '' ?>"></div>
              <div><label>Descrição</label>
                <input name="descricao" value="<?= e($t['descricao'] ?? '') ?>"></div>
            </div>
            <?php if ((int)$t['n_lic']>0): ?>
              <p class="subtitulo" style="margin:8px 0 0;font-size:11px">
                <?= (int)$t['n_lic'] ?> licença(s) usam este tipo. Mudar o
                nível reclassificaria o que esses clientes já contrataram.
                O preço pode mudar: vale para as próximas vendas, não
                altera o que já foi cobrado.
              </p>
            <?php endif; ?>
            <div style="margin-top:10px">
              <button class="btn pequeno">Salvar</button>
              <button type="button" class="btn sec pequeno" style="margin-left:6px"
                      onclick="editar('t', <?= $t['id'] ?>)">Cancelar</button>
            </div>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ============================= MÓDULOS ============================= -->
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h3 style="margin:0">Módulos (<?= count($modulos) ?>)</h3>
    <button type="button" class="btn sec pequeno" onclick="alternar('novoModulo')">
      + Novo módulo</button>
  </div>

  <div id="novoModulo" style="display:none;margin-top:14px">
    <form method="post">
      <input type="hidden" name="acao" value="modulo_novo">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div style="display:grid;grid-template-columns:1fr 2fr 1fr 3fr 1fr;gap:14px">
        <div><label>Código *</label>
          <input name="codigo" required placeholder="NFE" maxlength="20"
                 style="text-transform:uppercase"></div>
        <div><label>Nome *</label>
          <input name="nome" required placeholder="Nota fiscal eletrônica"></div>
        <div><label>Software</label>
          <select name="produto_id">
            <option value="">— todos —</option>
            <?php foreach ($produtos as $p): ?>
              <option value="<?= $p['id'] ?>"><?= e($p['nome']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label>Descrição</label><input name="descricao"></div>
        <div><label>Ordem</label>
          <input name="ordem" type="number" value="0"></div>
      </div>
      <div style="margin-top:12px">
        <button class="btn">Cadastrar</button>
        <button type="button" class="btn sec" style="margin-left:8px"
                onclick="alternar('novoModulo')">Cancelar</button>
      </div>
    </form>
  </div>

  <table style="margin-top:14px">
    <thead><tr><th>Código</th><th>Nome</th><th>Software</th>
      <th>Ordem</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($modulos as $m): ?>
      <tr id="mver<?= $m['id'] ?>">
        <td class="mono"><?= e($m['codigo']) ?></td>
        <td><b><?= e($m['nome']) ?></b>
          <?php if ($m['descricao']): ?>
            <br><span style="font-size:11px;color:var(--texto-2)">
              <?= e($m['descricao']) ?></span>
          <?php endif; ?></td>
        <td class="mono" style="font-size:11px">
          <?= $m['produto_codigo'] ? e(strtoupper($m['produto_codigo'])) : 'todos' ?></td>
        <td class="mono"><?= (int)$m['ordem'] ?></td>
        <td><span class="badge <?= $m['ativo']?'ativa':'expirada' ?>">
          <?= $m['ativo']?'ativo':'inativo' ?></span></td>
        <td style="white-space:nowrap">
          <button type="button" class="btn sec pequeno"
                  onclick="editar('m', <?= $m['id'] ?>)">Editar</button>
          <form method="post" style="display:inline">
            <input type="hidden" name="acao" value="modulo_ativo">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $m['id'] ?>">
            <button class="btn sec pequeno"><?= $m['ativo']?'Desativar':'Ativar' ?></button>
          </form>
        </td>
      </tr>
      <tr id="medt<?= $m['id'] ?>" style="display:none">
        <td colspan="6" style="background:var(--bg-3)">
          <form method="post">
            <input type="hidden" name="acao" value="modulo_editar">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $m['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 2fr 1fr 3fr 1fr;gap:14px">
              <div><label>Código · travado</label>
                <input value="<?= e($m['codigo']) ?>" readonly></div>
              <div><label>Nome *</label>
                <input name="nome" required value="<?= e($m['nome']) ?>"></div>
              <div><label>Software</label>
                <select name="produto_id">
                  <option value="">— todos —</option>
                  <?php foreach ($produtos as $p): ?>
                    <option value="<?= $p['id'] ?>"
                      <?= (int)$m['produto_id']===(int)$p['id']?'selected':'' ?>>
                      <?= e($p['nome']) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label>Descrição</label>
                <input name="descricao" value="<?= e($m['descricao'] ?? '') ?>"></div>
              <div><label>Ordem</label>
                <input name="ordem" type="number" value="<?= (int)$m['ordem'] ?>"></div>
            </div>
            <p class="subtitulo" style="margin:8px 0 0;font-size:11px">
              O código do módulo nunca é editável: ele vai no CSV assinado
              da licença e é o que o software procura em TemModulo().
              Para trocar, desative este e crie outro.
            </p>
            <div style="margin-top:10px">
              <button class="btn pequeno">Salvar</button>
              <button type="button" class="btn sec pequeno" style="margin-left:6px"
                      onclick="editar('m', <?= $m['id'] ?>)">Cancelar</button>
            </div>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function alternar(id) {
  const el = document.getElementById(id);
  el.style.display = (el.style.display === 'none') ? '' : 'none';
}
function editar(pref, id) {
  const ver = document.getElementById(pref + 'ver' + id);
  const edt = document.getElementById(pref + 'edt' + id);
  const abrindo = edt.style.display === 'none';
  edt.style.display = abrindo ? '' : 'none';
  ver.style.display = abrindo ? 'none' : '';
}
</script>
<?php fecha_pagina();
