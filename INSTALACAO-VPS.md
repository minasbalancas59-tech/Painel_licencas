# Instalação na sua VPS — passo a passo

Guia específico para a sua situação: **Ubuntu/Debian, VPS com root, sem
servidor web ainda**, domínio `licencas.totalscale.com.br`.

Siga na ordem. Cada bloco é para colar no SSH.

---

## Passo 0 — DNS (faça primeiro, leva minutos para propagar)

No mesmo painel onde você criou `ftpnovo.totalscale.com.br`, crie um
registro **A**:

```
Tipo: A
Nome: licencas
Valor: (o IP da sua VPS - o mesmo do ftpnovo)
```

Confirme que propagou antes de seguir:

```bash
ping licencas.totalscale.com.br
# deve responder com o IP da sua VPS
```

---

## Passo 1 — Instalar o stack

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-mysql php-sodium php-curl \
                    mariadb-server certbot python3-certbot-nginx unzip
```

Confirme que o sodium (assinatura) está ativo:

```bash
php -m | grep -i sodium
# deve imprimir: sodium
```

Descubra a versão do php-fpm (você vai precisar no nginx):

```bash
ls /run/php/
# ex: php8.1-fpm.sock  -> a versao e 8.1
```

---

## Passo 2 — Banco de dados

```bash
sudo mysql_secure_installation
# responda: senha do root, remover anonimos (S), etc.
```

Crie o banco e um usuário dedicado (troque a senha!):

```bash
sudo mysql <<'SQL'
CREATE DATABASE licencas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'licenca_user'@'localhost' IDENTIFIED BY 'TROQUE_ESTA_SENHA';
GRANT ALL PRIVILEGES ON licencas.* TO 'licenca_user'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Importe o schema (assumindo que você subiu os arquivos para /tmp/licenca):

```bash
sudo mysql licencas < /tmp/licenca/sql/01_schema.sql
```

---

## Passo 3 — Subir os arquivos

Suba a pasta `licenca/` para a VPS (via SFTP, scp, ou git). O destino:

```bash
sudo mkdir -p /var/www
sudo cp -r /tmp/licenca /var/www/licenca
sudo chown -R www-data:www-data /var/www/licenca
```

---

## Passo 4 — Chaves de assinatura (a parte crítica)

Crie a pasta FORA do webroot e gere o par de chaves:

```bash
sudo mkdir -p /var/licenca/chaves
sudo chown www-data:www-data /var/licenca/chaves
sudo -u www-data php /var/www/licenca/setup/gerar_chaves.php
```

Isso cria `chave_privada.bin`, `chave_publica.bin` e `chave_publica.pas`.

**FAÇA BACKUP AGORA** da chave privada, em local seguro e offline:

```bash
sudo cat /var/licenca/chaves/chave_privada.bin | base64
# copie esse texto para um gerenciador de senhas / cofre.
# Se perder a chave, nao consegue mais emitir licencas validas.
```

Guarde também o conteúdo de `chave_publica.pas` — vai no Delphi:

```bash
sudo cat /var/licenca/chaves/chave_publica.pas
```

---

## Passo 5 — Configurar

Edite o `config.php` com a senha do banco que você criou:

```bash
sudo nano /var/www/licenca/api/lib/config.php
# ajuste DB_PASS. DB_HOST, DB_NAME, DB_USER e CHAVES_DIR ja estao corretos.
```

---

## Passo 6 — nginx + HTTPS

```bash
# copie a config (ajuste a versao do php no arquivo antes, se != 8.1)
sudo cp /var/www/licenca/setup/nginx.conf /etc/nginx/sites-available/licencas
sudo nano /etc/nginx/sites-available/licencas
#   -> confira a linha fastcgi_pass e a versao do socket php

sudo ln -s /etc/nginx/sites-available/licencas /etc/nginx/sites-enabled/
sudo nginx -t          # testa a config
sudo systemctl reload nginx
```

Gere o certificado HTTPS grátis (Let's Encrypt):

```bash
sudo certbot --nginx -d licencas.totalscale.com.br
# escolha redirecionar http -> https quando perguntar
```

---

## Passo 7 — Criar seu usuário admin

```bash
sudo -u www-data php /var/www/licenca/setup/criar_admin.php \
     voce@totalscale.com.br  SuaSenhaForte  "Seu Nome"
```

Acesse **https://licencas.totalscale.com.br/painel/** e entre.

---

## Passo 8 — Delphi

1. Baixe **libsodium.dll** (mesma arquitetura do seu exe: 32 ou 64 bits)
   de download.libsodium.org e coloque ao lado do Total Scale.exe.
2. Cole a chave pública (do Passo 4) na constante `CHAVE_PUBLICA_HEX`
   em `uAtivacao.pas`.
3. A URL em `uAtivacaoOnline.pas` já está apontada para o seu domínio.
4. Ligue sua checagem do Rockey2 na função `Rockey2Presente`.
5. Compile e teste: gere uma licença no painel, ative pelo Total Scale.

---

## Teste de ponta a ponta (antes de liberar a clientes)

1. Painel → Clientes → cadastre um cliente teste.
2. Painel → Licenças → emita uma licença de 1 mês para ele.
3. No Total Scale, abra a tela de ativação:
   - copie o Código da Máquina;
   - **teste offline**: cole a chave + código no painel (aba Ativação
     offline), gere o código, cole de volta no Total Scale;
   - **teste online**: em outra máquina limpa, digite a chave e ative.
4. Painel → Licenças → confirme que aparece como "ativa" com o
   fingerprint da máquina.
5. Teste a revogação: revogue e confirme que o software detecta.

---

## Manutenção

- **Renovação do HTTPS**: o certbot renova sozinho. Confira com
  `sudo certbot renew --dry-run`.
- **Backup do banco** (rode periodicamente ou em cron):
  ```bash
  mysqldump -u licenca_user -p licencas > backup_licencas_$(date +%F).sql
  ```
- **Backup da chave privada**: você já fez no Passo 4. Sem ela, um
  desastre no servidor te impede de emitir novas licenças.

---

## Se algo der errado

- **502 Bad Gateway**: a versão do php-fpm no nginx.conf não bate com a
  instalada. Rode `ls /run/php/` e ajuste a linha `fastcgi_pass`.
- **Erro de conexão com banco**: confira DB_PASS no config.php e teste
  `mysql -u licenca_user -p licencas`.
- **"Chave privada não encontrada"**: confira permissão da pasta
  `/var/licenca/chaves` (dono `www-data`).
- **Página em branco**: veja o log `sudo tail -f /var/log/nginx/error.log`
  e ative erros no PHP temporariamente para diagnosticar.
