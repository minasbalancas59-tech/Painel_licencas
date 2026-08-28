# Sistema de Licenciamento Web — Total Scale

Substitui o dongle Rockey2 por licenciamento via web, com registro de
clientes pelo navegador, validade de uso e ativação **online** e
**offline**. O Rockey2 continua funcionando em paralelo durante a
transição.

## Como a segurança funciona (leia antes)

O sistema é baseado em **assinatura digital Ed25519**, não em
"pergunta ao servidor se pode rodar". Você gera **um par de chaves**:

- **Chave privada** → fica só na sua VPS. Assina cada licença. Nunca sai de lá.
- **Chave pública** → embutida no seu `.exe` Delphi. Só serve para *verificar*.

Uma licença é um pequeno JSON assinado contendo cliente, máquina e data
de validade. O Delphi valida a assinatura **localmente**, sem internet.
Ninguém forja uma licença sem a chave privada — nem tendo o código-fonte
do cliente. Por isso o modo offline é tão seguro quanto o online.

> **Guarde um backup da chave privada.** Se perdê-la, não consegue mais
> emitir licenças válidas para os executáveis já distribuídos.

## Estrutura dos arquivos

```
sql/01_schema.sql            Banco de dados (MySQL/MariaDB)
setup/gerar_chaves.php       Gera o par de chaves (rode 1 vez)
setup/criar_admin.php        Cria o usuário admin do painel
setup/nginx.conf.exemplo     Configuração do servidor web
api/ativar.php               Endpoint de ativação online (o Delphi chama)
api/lib/config.php           << EDITE: banco, chaves, domínio
api/lib/licenca.php          Núcleo (assinatura, chaves, banco)
painel/                      Painel web (você acessa pelo navegador)
delphi/uAtivacao.pas         Núcleo Delphi: fingerprint + validação
delphi/uEd25519.pas          Verificação Ed25519 (via libsodium.dll)
delphi/uAtivacaoOnline.pas   Ativação online + fallback Rockey2
delphi/uFrmAtivacao_exemplo  Exemplo de tela de ativação
```

## Instalação na VPS (resumo)

1. **Banco**: crie o banco e um usuário dedicado, importe o schema:
   ```bash
   mysql -u root -p < sql/01_schema.sql
   ```

2. **Chaves** (fora do webroot, ex. `/var/licenca/chaves`):
   ```bash
   php setup/gerar_chaves.php
   # faça backup de chave_privada.bin em local seguro
   ```

3. **Config**: edite `api/lib/config.php` com os dados do banco, o
   caminho `CHAVES_DIR` e seu domínio.

4. **Web**: aponte o site para a pasta `licenca/`, usando o
   `setup/nginx.conf.exemplo` (ou o `.htaccess` no Apache). Gere HTTPS
   com o certbot — licença por HTTP é pedir para ser interceptada.

5. **Admin**:
   ```bash
   php setup/criar_admin.php  voce@email.com  SuaSenhaForte  "Seu Nome"
   ```

6. Acesse `https://seudominio/painel/` e entre.

## No Delphi

1. Instale **libsodium.dll** (32 ou 64 bits, conforme seu exe) ao lado
   do executável. Baixe em download.libsodium.org.
2. Cole a chave pública gerada (arquivo `chave_publica.pas`) na
   constante `CHAVE_PUBLICA_HEX` em `uAtivacao.pas`.
3. Em `uAtivacaoOnline.pas`, ajuste `URL_API_ATIVAR` para o seu domínio.
4. Ligue sua checagem atual do Rockey2 na função `Rockey2Presente`
   (assim os dois convivem).
5. Na inicialização do sistema, chame `SoftwareLiberado(msg)`. Se
   retornar `False`, abra a tela de ativação.

## Fluxo do dia a dia

**Cadastrar cliente** → aba Clientes → preencher e salvar.

**Emitir licença** → aba Licenças → escolher cliente, validade e
módulos → gera uma **chave** (TS6X-...). Entregue ao cliente.

**Ativação online** (PC com internet): o cliente digita a chave no
Total Scale e clica em ativar. Pronto.

**Ativação offline** (PC sem internet):
1. O cliente lê o **Código da Máquina** na tela de ativação e te passa.
2. Você abre a aba **Ativação offline**, cola a chave + o código da
   máquina, gera o **Código de Ativação**.
3. Manda de volta; o cliente cola no Total Scale. Liberado.

**Revogar** → aba Licenças → botão Revogar. O software do cliente para
de validar (na próxima verificação online, ou imediatamente se estava
dependendo de re-ativação).

## Proteção contra fraude de relógio (offline)

No modo offline, um cliente poderia atrasar a data do Windows para
driblar a expiração. O módulo Delphi grava a maior data já vista no
registro; se o relógio voltar mais de 2 dias, detecta a manipulação.
Não é infalível, mas eleva bastante a barra.

## Limitações honestas

- **1 licença = 1 máquina** (amarra em HD + MachineGuid). Trocar o HD ou
  reinstalar o Windows muda o fingerprint e exige nova ativação — igual
  ao comportamento do dongle. Ajuste `ObterFingerprint` se quiser
  tolerância a mudanças de hardware.
- **libsodium.dll**: é a forma mais simples e segura de ter Ed25519 no
  Delphi. Se não quiser DLL, dá para usar uma implementação Pascal pura,
  trocando só o corpo de `Ed25519_Verify`.
- Este é um sistema sólido para o seu porte, mas licenciamento é um jogo
  de elevar custo de quebra, não de invencibilidade absoluta. A
  assinatura protege contra forja; ofuscar o executável (ex.: com um
  protector) complementa contra engenharia reversa da checagem.
```
