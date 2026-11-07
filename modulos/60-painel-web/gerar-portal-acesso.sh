#!/bin/bash
# ============================================================================
# GWOS — Gera o site nginx do portal de acesso (login para navegar)
# Instalado como /usr/local/sbin/gwos-gerar-portal-acesso
# ============================================================================
# Serve SOMENTE as telas públicas /acesso* (login, primeiro acesso, esqueci a
# senha) em HTTPS na porta ACESSO_PORTA (padrão 8444). O painel de
# administração NÃO é exposto nessa porta: qualquer outro caminho dá 404.
#
# Certificado: assinado pela CA do SSL Bump (/etc/squid/ssl_cert/gwos-ca.*) se
# ela existir — os computadores que já confiam nela não mostram aviso — senão
# autoassinado. É refeito sozinho se o IP do gateway mudar.
#
#   gwos-gerar-portal-acesso         # (re)gera e recarrega o nginx
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

SITE="portal-acesso"
DISPONIVEL="/etc/nginx/sites-available/${SITE}"
HABILITADO="/etc/nginx/sites-enabled/${SITE}"
PORTA="${ACESSO_PORTA:-8444}"
IP="${IP_GATEWAY:?IP_GATEWAY não definido em /etc/gwos/gwos.conf}"

REPO="$(raiz_projeto)" || { echo "ERRO: projeto GWOS não encontrado." >&2; exit 1; }
PHP_VER="${PHP_VERSAO:-$(detectar_php_instalado || echo '')}"
[ -n "$PHP_VER" ] || { echo "ERRO: PHP-FPM não encontrado — instale o módulo 60-painel-web." >&2; exit 1; }
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"

DONO="$(porta_em_uso "$PORTA")"
if [ -n "$DONO" ] && ! echo "$DONO" | grep -q 'nginx'; then
    echo "ERRO: a porta ${PORTA} está em uso por: ${DONO}" >&2
    echo "      Escolha outra: ACESSO_PORTA=<porta> gwos-gerar-portal-acesso" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Certificado (refeito se faltar ou se o IP do gateway não consta nele)
# ---------------------------------------------------------------------------
CERT_DIR="/etc/gwos/portal-acesso"
CRT="${CERT_DIR}/portal.crt"; KEY="${CERT_DIR}/portal.key"
CA_CRT="/etc/squid/ssl_cert/gwos-ca.crt"; CA_KEY="/etc/squid/ssl_cert/gwos-ca.key"
mkdir -p "$CERT_DIR"; chmod 750 "$CERT_DIR"

cert_ok() {
    [ -s "$CRT" ] && [ -s "$KEY" ] || return 1
    openssl x509 -in "$CRT" -noout -ext subjectAltName 2>/dev/null | grep -q "IP Address:${IP}" || return 1
    openssl x509 -in "$CRT" -noout -checkend 2592000 >/dev/null 2>&1   # vale por mais 30 dias
}

if ! cert_ok; then
    EXT="$(mktemp /tmp/gwos_portal_ext.XXXXXX)"
    CSR="$(mktemp /tmp/gwos_portal_csr.XXXXXX)"
    trap 'rm -f "$EXT" "$CSR"' EXIT
    printf 'subjectAltName=IP:%s\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n' "$IP" > "$EXT"

    openssl req -new -newkey rsa:2048 -nodes -keyout "$KEY" -out "$CSR" \
        -subj "/O=GWOS/CN=${IP}" >/dev/null 2>&1
    if [ -s "$CA_CRT" ] && [ -s "$CA_KEY" ]; then
        openssl x509 -req -in "$CSR" -CA "$CA_CRT" -CAkey "$CA_KEY" -CAcreateserial \
            -days 825 -sha256 -extfile "$EXT" -out "$CRT" >/dev/null 2>&1
        ORIGEM="assinado pela CA do GWOS"
    else
        openssl x509 -req -in "$CSR" -signkey "$KEY" -days 825 -sha256 -extfile "$EXT" -out "$CRT" >/dev/null 2>&1
        ORIGEM="autoassinado (instale a CA do GWOS nos computadores para evitar o aviso)"
    fi
    chmod 640 "$KEY"; chmod 644 "$CRT"
    echo "[OK] Certificado do portal de acesso gerado — ${ORIGEM}."
fi

# ---------------------------------------------------------------------------
# Site nginx
# ---------------------------------------------------------------------------
TMP="$(mktemp /tmp/gwos_nginx_acesso.XXXXXX)"
trap 'rm -f "$TMP" "${EXT:-}" "${CSR:-}"' EXIT

cat > "$TMP" <<NGINX
# Gerado por gwos-gerar-portal-acesso — NÃO editar à mão.
limit_req_zone \$binary_remote_addr zone=gwos_acesso:1m rate=30r/m;

server {
    listen ${PORTA} ssl;
    server_name _;
    root ${REPO}/public;

    ssl_certificate     ${CRT};
    ssl_certificate_key ${KEY};
    ssl_protocols       TLSv1.2 TLSv1.3;

    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Cache-Control "no-store" always;

    # Só o portal de acesso e seus arquivos estáticos. O painel administrativo
    # (/login, /grupos, /internet ...) NÃO é servido nesta porta.
    location = /            { return 302 /acesso; }
    location = /favicon.svg { }
    location /assets/       { }

    location /acesso {
        limit_req zone=gwos_acesso burst=20 nodelay;
        include fastcgi_params;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
    }

    location / { return 404; }
}
NGINX

SALVAGUARDA=""
if [ -f "$DISPONIVEL" ]; then
    SALVAGUARDA="$(mktemp /tmp/gwos_nginx_acesso_bak.XXXXXX)"
    cp -a "$DISPONIVEL" "$SALVAGUARDA"
fi

install -m 644 "$TMP" "$DISPONIVEL"
ln -sf "$DISPONIVEL" "$HABILITADO"

if ! nginx -t >/dev/null 2>&1; then
    if [ -n "$SALVAGUARDA" ]; then cp -a "$SALVAGUARDA" "$DISPONIVEL"; else rm -f "$DISPONIVEL" "$HABILITADO"; fi
    rm -f "$SALVAGUARDA"
    echo "ERRO: nginx -t reprovou — nada foi alterado." >&2
    nginx -t >&2 || true
    exit 1
fi
rm -f "$SALVAGUARDA"

systemctl reload nginx >/dev/null 2>&1 || systemctl start nginx >/dev/null 2>&1 || true

# Marcador lido pelo gwos-integrar: só então o Squid passa a redirecionar para cá
touch /etc/gwos/acesso-internet.ativo
echo "[OK] ${SITE} em https://${IP}:${PORTA}/acesso"
