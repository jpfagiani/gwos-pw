#!/bin/bash
# ============================================================================
# GWOS — Gera o site nginx do painel (portal-gateway)
# Instalado como /usr/local/sbin/gwos-gerar-nginx
# ============================================================================
# A porta vem de PAINEL_PORTA em /etc/gwos/gwos.conf. Antes o vhost só era
# escrito pelo instalador, então mudar PAINEL_PORTA não movia nada — o painel
# continuava onde estava, e o valor no gwos.conf mentia.
#
#   gwos-definir PAINEL_PORTA 8080   # troca e regera
#   gwos-gerar-nginx                 # regera com o valor atual
#
# Convivência com outros portais na mesma máquina:
#   - nunca toma default_server se outro site já é o padrão daquela porta;
#   - numa máquina que já serve outros sites, não vira default_server nenhum,
#     para não capturar requisições destinadas a eles.
# ============================================================================

set -euo pipefail

for _l in "/etc/gwos/lib.sh" \
          "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../comum/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: lib.sh não encontrado." >&2; exit 1; }

exigir_root
garantir_conf

SITE="portal-gateway"
DISPONIVEL="/etc/nginx/sites-available/${SITE}"
HABILITADO="/etc/nginx/sites-enabled/${SITE}"

REPO="$(raiz_projeto)" || { echo "ERRO: projeto GWOS não encontrado." >&2; exit 1; }

PHP_VER="${PHP_VERSAO:-$(detectar_php_instalado || echo '')}"
[ -n "$PHP_VER" ] || { echo "ERRO: PHP-FPM não encontrado — reinstale o módulo 60." >&2; exit 1; }
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"

# ---------------------------------------------------------------------------
# A porta está livre? (ignorando o próprio site, que pode já estar no ar)
# ---------------------------------------------------------------------------
DONO="$(porta_em_uso "$PAINEL_PORTA")"
if [ -n "$DONO" ] && ! echo "$DONO" | grep -q 'nginx'; then
    echo "ERRO: a porta ${PAINEL_PORTA} está em uso por: ${DONO}" >&2
    echo "      Escolha outra: gwos-definir PAINEL_PORTA <porta>" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# default_server: só se ninguém mais servir nesta máquina
# ---------------------------------------------------------------------------
PADRAO=" default_server"
if [ "$(nginx_outros_sites)" -gt 0 ]; then
    PADRAO=""
elif grep -rlq "listen[^;]*${PAINEL_PORTA}[^;]*default_server" /etc/nginx/sites-enabled/ 2>/dev/null; then
    OUTRO=$(grep -rl "listen[^;]*${PAINEL_PORTA}[^;]*default_server" /etc/nginx/sites-enabled/ 2>/dev/null \
            | grep -vE "/(gwos|gwos-portal|${SITE})$" | head -1)
    [ -n "$OUTRO" ] && PADRAO=""
fi

# ---------------------------------------------------------------------------
# Escreve, valida e só então troca
# ---------------------------------------------------------------------------
TMP="$(mktemp /tmp/gwos_nginx.XXXXXX)"
trap 'rm -f "$TMP"' EXIT

cat > "$TMP" <<NGINX
# Gerado por gwos-gerar-nginx — NÃO editar à mão.
# Porta em PAINEL_PORTA de ${GWOS_CONF}; para trocar:
#   gwos-definir PAINEL_PORTA <porta>
server {
    listen ${PAINEL_PORTA}${PADRAO};
    server_name _;
    root ${REPO}/public;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. { deny all; }
    location = /favicon.ico { log_not_found off; }
}
NGINX

SALVAGUARDA=""
if [ -f "$DISPONIVEL" ]; then
    SALVAGUARDA="$(mktemp /tmp/gwos_nginx_bak.XXXXXX)"
    cp -a "$DISPONIVEL" "$SALVAGUARDA"
fi

install -m 644 "$TMP" "$DISPONIVEL"
ln -sf "$DISPONIVEL" "$HABILITADO"

if ! nginx -t >/dev/null 2>&1; then
    if [ -n "$SALVAGUARDA" ]; then
        cp -a "$SALVAGUARDA" "$DISPONIVEL"
    else
        rm -f "$DISPONIVEL" "$HABILITADO"
    fi
    rm -f "$SALVAGUARDA"
    echo "ERRO: nginx -t reprovou — nada foi alterado." >&2
    nginx -t >&2 || true
    exit 1
fi
rm -f "$SALVAGUARDA"

systemctl reload nginx >/dev/null 2>&1 || systemctl start nginx >/dev/null 2>&1 || true

echo "[OK] ${SITE} na porta ${PAINEL_PORTA}$([ -n "$PADRAO" ] && echo ' (default_server)' || echo ' (vhost comum)')."
