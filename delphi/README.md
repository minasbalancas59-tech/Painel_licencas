# Integração Delphi

Units de licenciamento dos softwares que usam o painel
`licencas.totalscale.com.br`.

```
comum/   compartilhado pelos dois produtos
ts5/     Total Scale 5 — licença web como fonte única (sem hardkey)
ts6/     Total Scale 6 — versão em produção hoje
```

## Antes de compilar

1. **`libsodium.dll`** precisa estar ao lado do executável, na mesma
   arquitetura do build (32 ou 64 bits). Sem ela, `uEd25519` não
   verifica assinatura e toda licença é rejeitada.
2. Confira a **chave pública** em `uAtivacao.pas`. Ela deve ser
   idêntica ao conteúdo de `/var/licenca/chaves/chave_publica.pas` no
   servidor. Se estiverem diferentes, nenhuma licença emitida será
   aceita.

## TS5 — o que muda

O TS5 não usa mais o dongle Rockey2: sem licença web válida, o sistema
não abre. A função `verificahardkey` do `Unit1.pas` foi reescrita para
ler a licença web, mas continua preenchendo as mesmas variáveis globais
(`licensa`, `tplicensa`, `tplicensaint`, `hardkeyautomacao`) que o
resto do código já lê — por isso os cerca de 40 pontos espalhados pelo
projeto não precisam de alteração.

Os três pontos de mudança no `Unit1.pas` estão em **`ts5/PATCH_Unit1.md`**.

## TS6 — referência

Os arquivos em `ts6/` são os que rodam em produção. Estão aqui como
referência e backup; o `uAtivacaoOnline.pas` dele ainda tem o fallback
para o dongle (`Principal.verificahardkey`), que o TS5 não tem.

## Aviso importante

Estes arquivos substituem uma versão anterior desta pasta que continha
a chave pública zerada (`0000...0000`), um placeholder de template.
Compilar aquela versão gerava um executável que rejeitava todas as
licenças. Se você tem um build feito a partir da pasta antiga,
recompile.
