<?php
/**
 * Layout compartilhado. Uso:
 *   $titulo = 'Clientes'; $pagina = 'clientes';
 *   require 'inc/topo.php';  ... conteudo ...  require 'inc/rodape.php';
 */
function abre_pagina(string $titulo, string $pagina): void {
    $u = usuario_logado();
    ?><!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titulo) ?> · <?= e(APP_NOME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/estilo.css">
</head>
<body>
  <div class="topo">
    <div class="marca">TOTAL<b>SCALE</b> · LICENÇAS</div>
    <div class="usuario">
      <?= e($u['nome']) ?> (<?= e($u['papel']) ?>)
      <a href="logout.php">sair</a>
    </div>
  </div>
  <nav class="nav">
    <?php if (($u['papel']??'')==='admin'): ?>
      <a href="index.php"    class="<?= $pagina==='painel'    ?'ativo':'' ?>">Painel</a>
      <a href="clientes.php" class="<?= $pagina==='clientes'  ?'ativo':'' ?>">Clientes</a>
      <a href="licencas.php" class="<?= $pagina==='licencas'  ?'ativo':'' ?>">Licenças</a>
      <a href="maquinas.php" class="<?= $pagina==='maquinas'  ?'ativo':'' ?>">Máquinas</a>
      <a href="relatorio.php"class="<?= $pagina==='relatorio' ?'ativo':'' ?>">Relatório</a>
      <a href="revendedores.php" class="<?= $pagina==='revendedores'?'ativo':'' ?>">Revendedores</a>
      <a href="catalogo.php" class="<?= $pagina==='catalogo' ?'ativo':'' ?>">Catálogo</a>
      <a href="offline.php"  class="<?= $pagina==='offline'   ?'ativo':'' ?>">Ativação offline</a>
      <a href="usuarios.php" class="<?= $pagina==='usuarios'  ?'ativo':'' ?>">Usuários</a>
      <a href="configuracoes.php" class="<?= $pagina==='config' ?'ativo':'' ?>">Configurações</a>
    <?php else: ?>
      <a href="minhas.php"   class="<?= $pagina==='minhas'    ?'ativo':'' ?>">Minhas licenças</a>
      <a href="clientes.php" class="<?= $pagina==='clientes'  ?'ativo':'' ?>">Meus clientes</a>
      <a href="maquinas.php" class="<?= $pagina==='maquinas'  ?'ativo':'' ?>">Máquinas</a>
    <?php endif; ?>
  </nav>
  <div class="wrap">
<?php
}
function fecha_pagina(): void {
    ?>
  </div>
</body>
</html>
<?php
}
