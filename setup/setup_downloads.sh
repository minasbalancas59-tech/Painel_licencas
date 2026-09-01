#!/bin/bash
# =====================================================================
#  Preparo do servidor para hospedar instaladores
# =====================================================================
#  Rode como root:  bash setup_downloads.sh
#
#  O QUE FAZ
#    1. cria a pasta dos arquivos FORA do webroot
#    2. libera o tamanho de upload no PHP e no nginx
#
#  POR QUE FORA DO WEBROOT
#  Dentro de /var/www qualquer um adivinha a URL e baixa sem passar
#  pelo painel. Aqui o download passa por um PHP que valida o token e
#  registra quem baixou.
#
#  E POR QUE ISSO PROTEGE O BACKUP
#  O backup diario empacota /var/www/licenca inteiro e manda para o
#  Drive. Um instalador de 150 MB por versao, com dez versoes, seriam
#  1,5 GB subindo TODO DIA. Fora do webroot, o backup nem ve a pasta -
#  e instalador e regeneravel pelo Delphi, nao precisa de backup
#  rotativo.
# =====================================================================

set -e

echo "=== 1. pasta dos arquivos ==="
mkdir -p /var/licenca_arquivos/instaladores
chown -R www-data:www-data /var/licenca_arquivos
chmod 750 /var/licenca_arquivos
ls -ld /var/licenca_arquivos/instaladores

echo
echo "=== 2. limites do PHP ==="
INI=/etc/php/8.1/fpm/conf.d/99-totalscale.ini
cat > $INI <<'EOF'
; Instaladores do Total Scale passam de 100 MB.
; O padrao do PHP e 2 MB de upload e 8 MB de POST - o arquivo seria
; recusado sem mensagem util para quem esta enviando.
upload_max_filesize = 300M
post_max_size = 320M

; upload lento em conexao ruim nao pode morrer no meio
max_input_time = 600
memory_limit = 256M
EOF
echo "criado $INI:"
cat $INI

echo
echo "=== 3. limite do nginx ==="
CONF=$(ls /etc/nginx/sites-enabled/*licenc* | head -1)
cp "$CONF" /root/nginx_licencas_antes_upload.bak

if grep -q "client_max_body_size" "$CONF"; then
    echo "ja existe client_max_body_size - conferir manualmente:"
    grep -n "client_max_body_size" "$CONF"
else
    # entra logo apos a linha do root, dentro do server de HTTPS
    sed -i '0,/root \/var\/www\/licenca\/painel;/s//root \/var\/www\/licenca\/painel;\n\n    # instaladores passam de 100 MB; o padrao do nginx e 1 MB\n    client_max_body_size 320m;\n    client_body_timeout 600s;/' "$CONF"
    echo "adicionado em $CONF:"
    grep -n -A1 "client_max_body_size" "$CONF"
fi

echo
echo "=== 4. aplicar ==="
nginx -t
systemctl reload nginx
systemctl restart php8.1-fpm

echo
echo "=== 5. conferencia ==="
php -i | grep -E "upload_max_filesize|post_max_size" | head -2
echo
echo "Pronto. Espaco disponivel:"
df -h /var | tail -1
