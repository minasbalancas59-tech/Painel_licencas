<?php
/**
 * =====================================================================
 *  Layout compartilhado
 * =====================================================================
 *  Uso nas telas:
 *      abre_pagina('Clientes', 'clientes');   ... conteudo ...
 *      fecha_pagina();
 *
 *  MENU AGRUPADO
 *  Com dez telas, a barra plana virou uma parede de texto onde nada se
 *  destaca. Os itens estao agrupados por PAPEL na operacao, e a ordem
 *  segue a frequencia de uso: Comercial e Licencas sao o dia a dia;
 *  Sistema se mexe uma vez por mes.
 *
 *  O grupo fica destacado quando qualquer tela dele esta aberta, para
 *  o operador nunca perder a nocao de onde esta.
 *
 *  Para acrescentar uma tela, basta incluir no array $MENU abaixo.
 * =====================================================================
 */

function menu_estrutura(string $papel): array {
    if ($papel !== 'admin') {
        // revendedor: tres telas, sem submenu - agrupar tres itens seria
        // esconder o que ja cabia na tela
        return [
            ['rotulo'=>'Minhas licenças', 'url'=>'minhas.php',   'pagina'=>'minhas'],
            ['rotulo'=>'Meus clientes',   'url'=>'clientes.php', 'pagina'=>'clientes'],
            ['rotulo'=>'Máquinas',        'url'=>'maquinas.php', 'pagina'=>'maquinas'],
        ];
    }

    return [
        ['rotulo'=>'Painel', 'url'=>'index.php', 'pagina'=>'painel'],

        ['rotulo'=>'Comercial', 'itens'=>[
            ['rotulo'=>'Financeiro',   'url'=>'financeiro.php',
             'pagina'=>'financeiro',   'desc'=>'Receita e previsão de renovação'],
            ['rotulo'=>'Clientes',     'url'=>'clientes.php',
             'pagina'=>'clientes',     'desc'=>'Cadastro, contatos e uso'],
            ['rotulo'=>'Revendedores', 'url'=>'revendedores.php',
             'pagina'=>'revendedores', 'desc'=>'Parceiros e estoque deles'],
            ['rotulo'=>'Autocadastros','url'=>'autocadastros.php',
             'pagina'=>'autocadastros','desc'=>'Clientes registrados na ativação',
             'contador'=>'autocadastros_pendentes'],
        ]],

        ['rotulo'=>'Licenças', 'itens'=>[
            ['rotulo'=>'Emitir licença',   'url'=>'emitir.php',
             'pagina'=>'emitir',           'desc'=>'Gerar uma chave nova'],
            ['rotulo'=>'Licenças emitidas','url'=>'licencas.php',
             'pagina'=>'licencas',         'desc'=>'Acompanhar, renovar, revogar'],
            ['rotulo'=>'Ativação offline', 'url'=>'offline.php',
             'pagina'=>'offline',          'desc'=>'Para PC sem internet'],
            ['rotulo'=>'Trocas de cliente','url'=>'trocas.php',
             'pagina'=>'trocas',           'desc'=>'Pedidos dos revendedores',
             'contador'=>'trocas_pendentes'],
        ]],

        ['rotulo'=>'Monitoramento', 'itens'=>[
            ['rotulo'=>'Máquinas',  'url'=>'maquinas.php',
             'pagina'=>'maquinas',  'desc'=>'Onde o software está rodando'],
            ['rotulo'=>'Relatório', 'url'=>'relatorio.php',
             'pagina'=>'relatorio', 'desc'=>'Auditoria de ações'],
            ['rotulo'=>'Volume de pesagens', 'url'=>'pesagens.php',
             'pagina'=>'pesagens',  'desc'=>'Quanto cada cliente usa'],
        ]],

        ['rotulo'=>'Sistema', 'itens'=>[
            ['rotulo'=>'Catálogo',      'url'=>'catalogo.php',
             'pagina'=>'catalogo',      'desc'=>'Softwares, tipos e módulos'],
            ['rotulo'=>'Versões',       'url'=>'versoes.php',
             'pagina'=>'versoes',       'desc'=>'Instaladores para download'],
            ['rotulo'=>'Tabela de preços','url'=>'precos.php',
             'pagina'=>'precos',        'desc'=>'Anuidade e perpétua por tipo'],
            ['rotulo'=>'Usuários',      'url'=>'usuarios.php',
             'pagina'=>'usuarios',      'desc'=>'Logins administrativos'],
            ['rotulo'=>'Configurações', 'url'=>'configuracoes.php',
             'pagina'=>'config',        'desc'=>'E-mail, avisos e padrões'],
        ]],
    ];
}

/**
 * Quantas trocas aguardam decisao. Vira uma bolinha no menu: sem isso,
 * a fila so e vista por quem lembra de abrir a tela - e o revendedor
 * fica esperando sem saber se alguem viu.
 */
function trocas_pendentes(): int {
    static $n = null;
    if ($n !== null) return $n;
    // so o admin decide trocas; para o revendedor a consulta seria
    // trabalho a toa em toda pagina
    $u = usuario_logado();
    if (($u['papel'] ?? '') !== 'admin') return $n = 0;
    try {
        $n = (int)db()->query(
          "SELECT COUNT(*) FROM trocas_cliente WHERE status='pendente'")
          ->fetchColumn();
    } catch (Throwable $e) { $n = 0; }
    return $n;
}

/** Quantos autocadastros aguardam conferência. Vira bolinha no menu. */
function autocadastros_pendentes(): int {
    static $n = null;
    if ($n !== null) return $n;
    $u = usuario_logado();
    if (($u['papel'] ?? '') !== 'admin') return $n = 0;
    try {
        $n = (int)db()->query(
          'SELECT COUNT(*) FROM autocadastros WHERE revisado = 0')->fetchColumn();
    } catch (Throwable $e) { $n = 0; }
    return $n;
}

function abre_pagina(string $titulo, string $pagina): void {
    $u = usuario_logado();
    $MENU = menu_estrutura($u['papel'] ?? '');
    ?><!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titulo) ?> · <?= e(APP_NOME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/estilo.css">
  <style>
    /* --- menu agrupado -------------------------------------------- */
    /* o estilo.css define .nav como flex; mantemos isso e so damos
       contexto de posicionamento para os submenus flutuarem */
    .nav { position: relative; z-index: 20; align-items: stretch; }
    .nav .grupo { position: relative; display: flex; align-items: stretch; }
    .nav .grupo > .rotulo {
      display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
      white-space: nowrap;
    }
    .nav .grupo > .rotulo::after {
      content: ''; width: 0; height: 0;
      border-left: 4px solid transparent; border-right: 4px solid transparent;
      border-top: 4px solid currentColor; opacity: .55;
    }
    .nav .submenu {
      display: none; position: absolute; top: 100%; left: 0; min-width: 230px;
      background: var(--bg-2); border: 1px solid var(--borda);
      border-radius: var(--radius); padding: 6px;
      box-shadow: 0 8px 24px rgba(0,0,0,.45);
    }
    /* hover no desktop, clique no toque - o :focus-within cobre teclado */
    .nav .grupo:hover .submenu,
    .nav .grupo:focus-within .submenu,
    .nav .grupo.aberto .submenu { display: block; }

    .nav .submenu a {
      display: block; padding: 9px 12px; border-radius: 4px;
      border-bottom: 0; text-decoration: none; line-height: 1.3;
    }
    .nav .submenu a:hover { background: var(--bg-3); }
    .nav .submenu a b { display: block; font-weight: 600; font-size: 13px; }
    .nav .submenu a span {
      display: block; font-size: 11px; color: var(--texto-2); margin-top: 2px;
    }
    .nav .submenu a.ativo { background: var(--bg-3); }
    .nav .submenu a.ativo b { color: var(--ambar); }

    .nav .pino {
      display: inline-block; font-style: normal; font-size: 10px;
      font-weight: 700; line-height: 1; padding: 3px 6px; margin-left: 5px;
      border-radius: 9px; background: var(--ambar); color: #14171a;
      vertical-align: middle;
    }

    @media (max-width: 720px) {
      .nav { display: flex; flex-wrap: wrap; }
      .nav .submenu { position: static; box-shadow: none; min-width: 0; }
    }
  </style>
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
    <?php foreach ($MENU as $m): ?>
      <?php if (!isset($m['itens'])): ?>
        <a href="<?= e($m['url']) ?>"
           class="<?= $pagina===$m['pagina'] ? 'ativo' : '' ?>"><?= e($m['rotulo']) ?></a>
      <?php else:
        // o grupo acende quando qualquer tela dentro dele esta aberta
        $ativo = false; $pend = 0;
        foreach ($m['itens'] as $i) {
            if ($pagina === $i['pagina']) $ativo = true;
            if (!empty($i['contador']) && function_exists($i['contador'])) {
                $pend += (int)call_user_func($i['contador']);
            }
        }
      ?>
        <span class="grupo" tabindex="0">
          <a class="rotulo <?= $ativo ? 'ativo' : '' ?>"
             href="<?= e($m['itens'][0]['url']) ?>"><?= e($m['rotulo']) ?>
            <?php if ($pend): ?><i class="pino"><?= $pend ?></i><?php endif; ?>
          </a>
          <span class="submenu">
            <?php foreach ($m['itens'] as $i): ?>
              <?php $c = (!empty($i['contador']) && function_exists($i['contador']))
                          ? (int)call_user_func($i['contador']) : 0; ?>
              <a href="<?= e($i['url']) ?>"
                 class="<?= $pagina===$i['pagina'] ? 'ativo' : '' ?>">
                <b><?= e($i['rotulo']) ?>
                  <?php if ($c): ?><i class="pino"><?= $c ?></i><?php endif; ?>
                </b>
                <?php if (!empty($i['desc'])): ?>
                  <span><?= e($i['desc']) ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </span>
        </span>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="wrap">
<?php
}

function fecha_pagina(): void {
    ?>
  </div>
  <script>
    // No toque nao existe hover: o primeiro clique no grupo abre o
    // submenu em vez de navegar direto para a primeira tela dele.
    (function () {
      if (!window.matchMedia('(hover: none)').matches) return;
      document.querySelectorAll('.nav .grupo > .rotulo').forEach(function (r) {
        r.addEventListener('click', function (ev) {
          var g = r.parentElement;
          if (!g.classList.contains('aberto')) {
            ev.preventDefault();
            document.querySelectorAll('.nav .grupo.aberto')
                    .forEach(function (o) { o.classList.remove('aberto'); });
            g.classList.add('aberto');
          }
        });
      });
    })();
  </script>
</body>
</html>
<?php
}
