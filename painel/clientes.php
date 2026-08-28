<?php
require 'inc/auth.php';
require 'inc/layout.php';
require 'inc/escopo.php';
exige_login();

/* =====================================================================
 *  CLIENTES
 * =====================================================================
 *  O formulario de cadastro fica RECOLHIDO por padrao: a lista e o que
 *  se usa no dia a dia; cadastrar e eventual. Ele abre sozinho quando
 *  a busca nao encontra ninguem (provavel cliente novo) ou quando um
 *  cadastro falha, para nao perder o que ja foi digitado.
 * ===================================================================== */

$msg=''; $tipo=''; $abrirForm=false; $old=[];

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='novo') {
    if (!csrf_valido()) { $msg='Sessão inválida. Recarregue a página.'; $tipo='erro'; }
    else {
        $razao    = trim($_POST['razao_social'] ?? '');
        $fantasia = trim($_POST['nome_fantasia'] ?? '');
        $cnpj     = trim($_POST['cnpj'] ?? '');
        $municipio= trim($_POST['municipio'] ?? '');
        $uf       = strtoupper(substr(trim($_POST['uf'] ?? ''), 0, 2));
        $contato  = trim($_POST['contato'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $obs      = trim($_POST['observacao'] ?? '');

        if ($razao === '') {
            $msg='Informe a razão social.'; $tipo='erro';
            $abrirForm=true; $old=$_POST;
        } else {
            try {
                db()->beginTransaction();
                $st = db()->prepare(
                  'INSERT INTO clientes (razao_social,nome_fantasia,cnpj,
                                         contato,telefone,email,municipio,uf,
                                         observacao,criado_por,revendedor_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $st->execute([$razao,($fantasia?:null),$cnpj,
                              $contato,$telefone,$email,
                              ($municipio?:null),($uf?:null),$obs,
                              usuario_logado()['id'], revendedor_atual()]);
                $novoId = (int)db()->lastInsertId();

                // primeiro contato ja entra na tabela de contatos; os
                // demais sao adicionados na ficha do cliente
                if ($contato !== '' || $telefone !== '' || $email !== '') {
                    db()->prepare(
                      'INSERT INTO cliente_contatos
                         (cliente_id,nome,telefone,email,principal)
                       VALUES (?,?,?,?,1)')
                      ->execute([$novoId,
                                 ($contato ?: 'Contato principal'),
                                 ($telefone ?: null), ($email ?: null)]);
                }
                db()->commit();
                $msg='Cliente cadastrado.'; $tipo='ok';
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                $msg='Não foi possível cadastrar: '.$e->getMessage();
                $tipo='erro'; $abrirForm=true; $old=$_POST;
            }
        }
    }
}

// ---- busca ----------------------------------------------------------
$busca = trim($_GET['q'] ?? '');

[$wEsc, $aEsc] = escopo_where('c');
$where = []; $args = [];
if ($wEsc) { $where[] = $wEsc; $args = array_merge($args, $aEsc); }
if ($busca !== '') {
    // busca tambem nos contatos: e comum lembrar do nome da pessoa e
    // nao da razao social da empresa
    $where[] = '(c.razao_social LIKE ? OR c.nome_fantasia LIKE ? '
             . 'OR c.cnpj LIKE ? OR c.municipio LIKE ? '
             . 'OR EXISTS (SELECT 1 FROM cliente_contatos cc '
             . '            WHERE cc.cliente_id=c.id AND ('
             . '              cc.nome LIKE ? OR cc.telefone LIKE ? '
             . '              OR cc.email LIKE ?)))';
    for ($i=0; $i<7; $i++) $args[] = '%'.$busca.'%';
}
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$st = db()->prepare(
  "SELECT c.*,
          (SELECT cc.nome FROM cliente_contatos cc
            WHERE cc.cliente_id=c.id
            ORDER BY cc.principal DESC, cc.id LIMIT 1) AS contato_nome,
          (SELECT COUNT(*) FROM cliente_contatos cc
            WHERE cc.cliente_id=c.id) AS n_contatos,
          (SELECT COUNT(*) FROM licencas l WHERE l.cliente_id=c.id) AS n_lic,
          (SELECT COUNT(*) FROM licencas l
            WHERE l.cliente_id=c.id AND l.status='ativa') AS n_ativas,
          (SELECT MAX(m.ultimo_acesso) FROM maquinas m
            WHERE m.cliente_id=c.id) AS visto_em
     FROM clientes c
   $whereSql
   ORDER BY c.razao_social");
$st->execute($args);
$clientes = $st->fetchAll();

// busca sem resultado: provavelmente e um cliente novo -> abre o form
if ($busca !== '' && !$clientes) $abrirForm = true;

function v($old, $campo) { return e($old[$campo] ?? ''); }

$ehRev = !eh_admin();
abre_pagina($ehRev ? 'Meus clientes' : 'Clientes', 'clientes');
?>
<h1 class="titulo"><?= $ehRev ? 'Meus clientes' : 'Clientes' ?></h1>
<p class="subtitulo">Busque um cliente para ver licenças e uso, ou cadastre um novo</p>

<?php if ($msg): ?><div class="aviso <?= $tipo ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <div style="flex:1;min-width:240px">
      <label>Buscar por nome, CNPJ, contato, telefone ou e-mail</label>
      <input type="text" name="q" value="<?= e($busca) ?>"
             placeholder="ex: Mineração, 12.345, joão, (31)..." autofocus>
    </div>
    <button class="btn">Buscar</button>
    <?php if ($busca !== ''): ?>
      <a class="btn sec" href="clientes.php">Limpar</a>
    <?php endif; ?>
    <button type="button" class="btn sec" onclick="abrirCadastro()">+ Novo cliente</button>
  </form>
  <?php if ($busca !== ''): ?>
    <p class="subtitulo" style="margin:12px 0 0">
      <?= count($clientes) ?> resultado(s) para "<?= e($busca) ?>"
    </p>
  <?php endif; ?>
</div>

<div class="card" id="boxCadastro" style="<?= $abrirForm ? '' : 'display:none' ?>">
  <h3>Novo cliente</h3>
  <?php if ($busca !== '' && !$clientes): ?>
    <p class="subtitulo" style="margin-top:-6px">
      Nenhum cliente encontrado para "<?= e($busca) ?>". Cadastre abaixo.
    </p>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="acao" value="novo">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <div style="flex:0 0 220px">
        <label>CNPJ</label>
        <input name="cnpj" id="fCnpj" value="<?= v($old,'cnpj') ?>"
               placeholder="00.000.000/0000-00">
      </div>
      <button type="button" class="btn sec" onclick="buscarCnpj()">
        Buscar dados na Receita
      </button>
      <span id="cnpjStatus" class="subtitulo" style="margin:0 0 8px"></span>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px">
      <div>
        <label>Razão social *</label>
        <input name="razao_social" id="fRazao" required
               value="<?= $old ? v($old,'razao_social') : e($busca) ?>">
      </div>
      <div>
        <label>Nome fantasia</label>
        <input name="nome_fantasia" id="fFantasia" value="<?= v($old,'nome_fantasia') ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-top:12px">
      <div><label>Município</label>
        <input name="municipio" id="fMunicipio" value="<?= v($old,'municipio') ?>"></div>
      <div><label>UF</label>
        <input name="uf" id="fUf" maxlength="2" value="<?= v($old,'uf') ?>"></div>
    </div>

    <h3 style="margin:20px 0 4px;font-size:13px">Contato principal</h3>
    <p class="subtitulo" style="margin:0 0 10px">
      Outros contatos podem ser adicionados depois, na ficha do cliente.
    </p>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
      <div><label>Nome</label><input name="contato" value="<?= v($old,'contato') ?>"></div>
      <div><label>Telefone</label>
        <input name="telefone" id="fTelefone" value="<?= v($old,'telefone') ?>"></div>
      <div><label>E-mail</label>
        <input name="email" id="fEmail" type="email" value="<?= v($old,'email') ?>"></div>
    </div>
    <label style="margin-top:12px">Observação</label>
    <textarea name="observacao" style="min-height:60px"><?= v($old,'observacao') ?></textarea>
    <div style="margin-top:12px">
      <button class="btn">Cadastrar cliente</button>
      <button type="button" class="btn sec" style="margin-left:8px"
              onclick="document.getElementById('boxCadastro').style.display='none'">
        Cancelar
      </button>
    </div>
  </form>
</div>

<div class="card">
  <h3><?= $busca !== '' ? 'Resultados' : 'Todos os clientes' ?>
      (<?= count($clientes) ?>)</h3>
  <table>
    <thead><tr>
      <th>Cliente</th><th>CNPJ</th><th>Local</th><th>Contato</th>
      <th>Licenças</th><th>Ativas</th><th>Visto por último</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$clientes): ?>
      <tr><td colspan="8" style="color:var(--texto-2)">
        <?= $busca !== '' ? 'Nenhum cliente encontrado.' : 'Nenhum cliente cadastrado.' ?>
      </td></tr>
    <?php else: foreach ($clientes as $c): ?>
      <tr>
        <td>
          <a href="cliente.php?id=<?= $c['id'] ?>">
            <b><?= e($c['nome_fantasia'] ?: $c['razao_social']) ?></b></a>
          <?php if ($c['nome_fantasia']): ?>
            <br><span style="font-size:10px;color:var(--texto-2)">
              <?= e($c['razao_social']) ?></span>
          <?php endif; ?>
        </td>
        <td class="mono" style="font-size:12px"><?= e($c['cnpj'] ?: '—') ?></td>
        <td style="font-size:11px;color:var(--texto-2)">
          <?= e($c['municipio'] ? $c['municipio'].($c['uf']?'/'.$c['uf']:'') : '—') ?>
        </td>
        <td style="font-size:12px">
          <?= e($c['contato_nome'] ?: '—') ?>
          <?php if ((int)$c['n_contatos'] > 1): ?>
            <span style="font-size:10px;color:var(--texto-2)">
              +<?= (int)$c['n_contatos'] - 1 ?></span>
          <?php endif; ?>
        </td>
        <td class="mono"><?= (int)$c['n_lic'] ?></td>
        <td class="mono" style="color:var(--verde)"><?= (int)$c['n_ativas'] ?></td>
        <td style="font-size:11px;color:var(--texto-2)">
          <?= $c['visto_em'] ? date('d/m/Y H:i', strtotime($c['visto_em'])) : '—' ?>
        </td>
        <td><a class="btn sec pequeno" href="cliente.php?id=<?= $c['id'] ?>">Ver detalhes</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
function buscarCnpj() {
  const campo  = document.getElementById('fCnpj');
  const status = document.getElementById('cnpjStatus');
  const cnpj   = campo.value.replace(/\D/g, '');

  if (cnpj.length !== 14) {
    status.textContent = 'Digite os 14 dígitos do CNPJ.';
    status.style.color = 'var(--vermelho)';
    return;
  }
  status.textContent = 'Consultando...';
  status.style.color = 'var(--texto-2)';

  fetch('cnpj.php?cnpj=' + cnpj)
    .then(r => r.json())
    .then(j => {
      if (!j.ok) {
        status.textContent = j.erro || 'Não encontrado.';
        status.style.color = 'var(--vermelho)';
        return;
      }
      const d = j.dados;
      // nao sobrescreve o que o usuario ja digitou
      const por = (id, val) => {
        const el = document.getElementById(id);
        if (el && val && !el.value) el.value = val;
      };
      por('fRazao', d.razao_social);
      por('fFantasia', d.nome_fantasia);
      por('fMunicipio', d.municipio);
      por('fUf', d.uf);
      por('fTelefone', d.telefone);
      por('fEmail', d.email);

      status.textContent = d.situacao ? ('Receita: ' + d.situacao) : 'Dados carregados.';
      status.style.color = (d.situacao && d.situacao.toUpperCase() !== 'ATIVA')
                           ? 'var(--ambar)' : 'var(--verde)';
    })
    .catch(() => {
      status.textContent = 'Falha na consulta.';
      status.style.color = 'var(--vermelho)';
    });
}

function abrirCadastro() {
  const box = document.getElementById('boxCadastro');
  box.style.display = '';
  box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  const campo = box.querySelector('input[name="razao_social"]');
  if (campo) campo.focus();
}
</script>
<?php fecha_pagina();
