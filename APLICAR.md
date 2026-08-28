# Painel v2 — revendedores, transferências e demos

Pacote **aditivo**: nada do que funciona hoje deixa de funcionar. As
licenças existentes ficam todas com `revendedor_id = NULL` (suas,
venda direta) e continuam aparecendo exatamente como antes.

Aplique na ordem abaixo. Cada etapa é um commit — se algo der errado,
`git revert` volta o passo.

---

## Etapa 1 — banco (faça backup antes)

```bash
cd /var/www/licenca
mysqldump licencas > ~/backup_licencas_$(date +%F).sql
mysql licencas < sql/04_revendedores.sql
```

Confira:

```bash
mysql licencas -e "SHOW COLUMNS FROM licencas LIKE 'revendedor_id'; \
                   SHOW COLUMNS FROM licencas LIKE 'tipo_licenca'; \
                   SHOW TABLES LIKE 'trocas_cliente';"
```

## Etapa 2 — arquivos novos

Copie para o servidor:

- `painel/inc/escopo.php` — funções de isolamento (usado por todas as telas)
- `painel/minhas.php` — tela do revendedor

## Etapa 3 — patches nas telas existentes

### 3.1 `painel/inc/layout.php` — menu por papel

O menu hoje é igual para todos. Substitua o bloco `<nav>` por:

```php
  <nav class="nav">
    <?php if (($u['papel']??'')==='admin'): ?>
      <a href="index.php"    class="<?= $pagina==='painel'    ?'ativo':'' ?>">Painel</a>
      <a href="clientes.php" class="<?= $pagina==='clientes'  ?'ativo':'' ?>">Clientes</a>
      <a href="licencas.php" class="<?= $pagina==='licencas'  ?'ativo':'' ?>">Licenças</a>
      <a href="maquinas.php" class="<?= $pagina==='maquinas'  ?'ativo':'' ?>">Máquinas</a>
      <a href="relatorio.php"class="<?= $pagina==='relatorio' ?'ativo':'' ?>">Relatório</a>
      <a href="trocas.php"   class="<?= $pagina==='trocas'    ?'ativo':'' ?>">Trocas</a>
      <a href="offline.php"  class="<?= $pagina==='offline'   ?'ativo':'' ?>">Ativação offline</a>
      <a href="usuarios.php" class="<?= $pagina==='usuarios'  ?'ativo':'' ?>">Usuários</a>
    <?php else: ?>
      <a href="minhas.php"   class="<?= $pagina==='minhas'    ?'ativo':'' ?>">Minhas licenças</a>
      <a href="clientes.php" class="<?= $pagina==='clientes'  ?'ativo':'' ?>">Meus clientes</a>
      <a href="maquinas.php" class="<?= $pagina==='maquinas'  ?'ativo':'' ?>">Máquinas</a>
    <?php endif; ?>
  </nav>
```

### 3.2 `painel/licencas.php` — só admin, e emissão com revendedor

**a)** No topo, depois de `exige_login();`, adicione:

```php
require 'inc/escopo.php';
exige_admin_escopo();      // revendedor nao emite licenca
```

**b)** O cliente deixa de ser obrigatório na emissão (a licença vai
para o estoque do revendedor). Troque a validação:

```php
// ANTES:
if ($cliId<=0)         { $msg='Selecione um cliente.'; $tipo='erro'; }
elseif ($tierId<=0)    { ... }

// DEPOIS:
if ($tierId<=0)        { $msg='Selecione o software e o tipo de licença.'; $tipo='erro'; }
```

**c)** No INSERT, inclua as colunas novas. O `$revId` vem de um
`<select name="revendedor_id">` novo no formulário (opção vazia =
venda direta sua), e `$qtd` de um campo "quantidade" (para emitir
lote). Envolva o INSERT num laço quando `$qtd > 1`, gerando uma chave
nova a cada volta:

```php
$revId = (int)($_POST['revendedor_id'] ?? 0) ?: null;
$tipoLic = ($_POST['tipo_licenca'] ?? 'venda') === 'demo' ? 'demo' : 'venda';
$qtd = max(1, min(50, (int)($_POST['quantidade'] ?? 1)));

$st = db()->prepare(
  'INSERT INTO licencas
     (cliente_id,revendedor_id,produto_id,tier_id,chave,modulos,
      emitido_em,expira_em,carencia_dias,status,tipo_licenca,criado_por)
   VALUES (?,?,?,?,?,?,?,?,?,"nova",?,?)');
$st->execute([
    ($cliId ?: null), $revId, $t['produto_id'], $tierId,
    $chave, ($modsCsv ?: ''), $emit, $exp, $carencia, $tipoLic, $u['id']
]);
```

**d)** A listagem usa `JOIN clientes` — com `cliente_id` agora podendo
ser `NULL`, isso esconderia as licenças em estoque. Troque por
`LEFT JOIN` e mostre o revendedor:

```php
$licencas = db()->query(
  'SELECT l.*, c.razao_social, u.nome AS revendedor_nome,
          p.codigo AS produto_codigo, t.codigo AS tier_codigo, t.nome AS tier_nome
     FROM licencas l
     LEFT JOIN clientes c ON c.id=l.cliente_id
     LEFT JOIN usuarios u ON u.id=l.revendedor_id
     LEFT JOIN produtos p ON p.id=l.produto_id
     LEFT JOIN tiers t    ON t.id=l.tier_id
    ORDER BY l.id DESC LIMIT 200')->fetchAll();
```

> Esse `JOIN` → `LEFT JOIN` é o ponto mais fácil de esquecer. Sem ele,
> você emite um lote para o revendedor e as licenças simplesmente não
> aparecem na sua lista.

### 3.3 `painel/clientes.php` — isolamento

No topo, após `exige_login();`:

```php
require 'inc/escopo.php';
```

No INSERT, grave o dono:

```php
$st = db()->prepare(
  'INSERT INTO clientes (razao_social,cnpj,contato,telefone,email,
                         observacao,criado_por,revendedor_id)
   VALUES (?,?,?,?,?,?,?,?)');
$st->execute([$razao, ..., usuario_logado()['id'], revendedor_atual()]);
```

Na listagem, aplique o escopo:

```php
[$wEsc, $aEsc] = escopo_where('c');
$sql = 'SELECT c.*, (SELECT COUNT(*) FROM licencas l WHERE l.cliente_id=c.id) AS n_lic
          FROM clientes c ' . ($wEsc ? "WHERE $wEsc" : '') . ' ORDER BY c.razao_social';
$st = db()->prepare($sql); $st->execute($aEsc);
$clientes = $st->fetchAll();
```

O botão "Emitir licença" da última coluna deve aparecer só para admin.

### 3.4 `painel/maquinas.php` e `maquina.php` — isolamento

Após `exige_login();`, adicione `require 'inc/escopo.php';` e some o
escopo ao WHERE que já existe:

```php
[$wEsc, $aEsc] = escopo_where('l');   // alias de licencas no $juncoes
if ($wEsc) { $where[] = $wEsc; $args = array_merge($args, $aEsc); }
```

Em `maquina.php`, chame `exige_licenca_do_usuario()` antes de mostrar
os dados — senão um revendedor acessa a máquina de outro trocando o
`fp` na URL.

### 3.5 `painel/relatorio.php` e `index.php` — só admin

```php
require 'inc/escopo.php';
exige_admin_escopo();
```

---

## O que fica para a próxima etapa

- `trocas.php` — fila de solicitações de troca de cliente (o revendedor
  pede, você aprova). A tabela `trocas_cliente` já foi criada pela
  migração.
- `revendedores.php` — sua visão consolidada: estoque, vinculadas e
  transferências por revendedor.
- `produtos.php` — CRUD de produtos e tiers, para cadastrar novos
  sistemas sem SQL na mão.

## Teste depois de aplicar

1. Entre como admin: tudo deve aparecer como antes.
2. Crie um usuário revendedor em `usuarios.php`.
3. Emita 2 licenças atribuídas a ele (uma venda, uma demo).
4. Entre como o revendedor: deve ver só as 2, sem menu de emissão,
   sem datas de vencimento.
5. Cadastre um cliente por ele, vincule, e teste "Liberar máquina"
   depois de ativar num PC de teste.
6. Confirme no `relatorio.php` (como admin) que as ações
   `vincular_cliente` e `liberar_maquina` foram registradas.
