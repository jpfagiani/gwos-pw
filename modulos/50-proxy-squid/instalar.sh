#!/bin/bash
# ============================================================================
# GWOS — Módulo 50-proxy-squid: proxy HTTP/HTTPS com SSL Bump
# ============================================================================
# Instala o Squid nas três portas do GWOS:
#   3127  forward-proxy explícito
#   3128  HTTP transparente   (o firewall redireciona para cá)
#   3129  HTTPS transparente com SSL Bump
#
# Gera a CA usada na inspeção de HTTPS e a publica para download.
#
# Sozinho: proxy explícito funcionando na porta 3127 — configure o navegador
#          apontando para IP_DO_GATEWAY:3127. Sem o painel, as listas de
#          grupos ficam vazias; veja a nota sobre 'http_access deny all' no
#          final da instalação.
# Junto:   o firewall (módulo 40) intercepta 80/443; o BIND9 (módulo 20) vira
#          o resolver; o painel (módulo 60) alimenta grupos, domínios e
#          horários a partir do banco (módulo 10).
# ============================================================================

set -euo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || {
    echo "ERRO: comum/lib.sh não encontrado. Copie a pasta 'comum/' junto com o módulo."
    exit 1; }

iniciar_modulo "50-proxy-squid"

# ---------------------------------------------------------------------------
# Pacote — squid-openssl traz o SSL Bump; no Debian 13 é exclusivo com squid
# ---------------------------------------------------------------------------
instalar_pacotes openssl
if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq squid-openssl 2>/dev/null; then
    ok "Squid instalado com suporte a SSL (squid-openssl)."
else
    aviso "squid-openssl indisponível — instalando o squid padrão (sem SSL Bump)."
    instalar_pacotes squid
fi

# SARG é opcional (relatórios estáticos); o painel tem os seus próprios
instalar_opcional sarg

# ---------------------------------------------------------------------------
# Configuração
# ---------------------------------------------------------------------------
backup_arquivo /etc/squid/squid.conf
install -m 644 "${MOD_DIR}/config/squid.conf" /etc/squid/squid.conf

mkdir -p /etc/squid/conf.d
for lista in gwos_ips_liberados:squid_ips_liberados.txt \
             gwos_ips_parciais:squid_ips_parciais.txt \
             gwos_ips_bloqueados:squid_ips_bloqueados.txt; do
    destino="/etc/squid/conf.d/${lista%%:*}.txt"
    origem="${MOD_DIR}/config/${lista##*:}"
    [ -f "$destino" ] || install -m 644 "$origem" "$destino"
done
[ -f /etc/squid/conf.d/gwos_horarios.conf ] || \
    install -m 644 "${MOD_DIR}/config/squid_horarios.conf" /etc/squid/conf.d/gwos_horarios.conf

# Listas geradas pelo painel — criadas vazias para o Squid não recusar o start
for vazio in gwos_whitelist.txt gwos_blacklist.txt gwos_ips_livres.txt \
             gwos_sites_livres.txt gwos_tcp_outgoing.conf gwos_ips_isentos.txt; do
    [ -f "/etc/squid/conf.d/${vazio}" ] || : > "/etc/squid/conf.d/${vazio}"
done

# Login de usuários (portal cativo): helper que o Squid consulta e pasta onde o
# portal grava as sessões ativas (o PHP escreve, o Squid só lê).
install -m 755 "${MOD_DIR}/helpers/gwos-sessao.pl" /usr/local/sbin/gwos-squid-sessao
mkdir -p /var/lib/gwos/sessions
chown root:root /var/lib/gwos && chmod 0755 /var/lib/gwos
if getent group proxy >/dev/null; then chown root:proxy /var/lib/gwos/sessions; else chown root:www-data /var/lib/gwos/sessions; fi
chmod 2770 /var/lib/gwos/sessions
if [ -f /var/lib/gwos/sessoes.txt ] && [ ! -f /var/lib/gwos/sessions/sessoes.txt ]; then
    install -o www-data -g "$(stat -c %G /var/lib/gwos/sessions)" -m 0640 /var/lib/gwos/sessoes.txt /var/lib/gwos/sessions/sessoes.txt
    rm -f /var/lib/gwos/sessoes.txt
fi
[ -f /var/lib/gwos/sessions/sessoes.txt ] || install -o www-data -g "$(stat -c %G /var/lib/gwos/sessions)" -m 0640 /dev/null /var/lib/gwos/sessions/sessoes.txt
if getent group proxy >/dev/null; then chown www-data:proxy /var/lib/gwos/sessions/sessoes.txt; fi
chmod 0640 /var/lib/gwos/sessions/sessoes.txt
# Até o gwos-integrar apontar para o portal de login, "deny all" mostra a página padrão
[ -f /etc/squid/conf.d/gwos_portal.conf ] || \
    printf '# Gerado por gwos-integrar.\ndeny_info ERR_ACCESS_DENIED all\n' > /etc/squid/conf.d/gwos_portal.conf

# Placeholders dos arquivos de integração (gwos-integrar regrava depois)
[ -f /etc/squid/conf.d/gwos_redes.conf ] || {
    echo "# Gerado por gwos-integrar." > /etc/squid/conf.d/gwos_redes.conf
    for rede in $(redes_internas); do
        echo "acl localnet src ${rede}"      >> /etc/squid/conf.d/gwos_redes.conf
        echo "acl rede_interna dst ${rede}"  >> /etc/squid/conf.d/gwos_redes.conf
    done
}
[ -f /etc/squid/conf.d/gwos_integracao.conf ] || \
    printf '# Gerado por gwos-integrar.\ndns_nameservers 8.8.8.8 1.1.1.1\ndns_defnames off\n' \
        > /etc/squid/conf.d/gwos_integracao.conf

# Portas — o gwos-integrar regrava no fim; aqui só o mínimo para o Squid
# passar no 'squid -k parse' antes de a CA existir.
printf '# Gerado por gwos-integrar.\nhttp_port %s\nhttp_port %s intercept\n' \
    "${SQUID_PORTA_FWD}" "${SQUID_PORTA}" > /etc/squid/conf.d/gwos_portas.conf
ok "Configuração e listas instaladas."

# ---------------------------------------------------------------------------
# SSL Bump — CA e banco de certificados dinâmicos
# ---------------------------------------------------------------------------
CERTGEN=""
for p in /usr/lib/squid/security_file_certgen \
         /usr/libexec/squid/security_file_certgen \
         /usr/lib/squid4/security_file_certgen; do
    [ -x "$p" ] && { CERTGEN="$p"; break; }
done

if [ -n "$CERTGEN" ]; then
    SSL_DIR="/etc/squid/ssl_cert"
    mkdir -p "$SSL_DIR"

    if [ -f "${SSL_DIR}/gwos-ca.crt" ] && [ -f "${SSL_DIR}/gwos-ca.key" ]; then
        aviso "CA existente preservada — os clientes não precisam reinstalar o certificado."
    else
        openssl req -new -newkey rsa:4096 -days 3650 -nodes -x509 \
            -subj "/C=BR/ST=SP/O=GWOS/CN=GWOS Gateway CA" \
            -keyout "${SSL_DIR}/gwos-ca.key" \
            -out    "${SSL_DIR}/gwos-ca.crt"
        ok "CA do SSL Bump gerada (validade 10 anos)."
    fi
    chmod 640 "${SSL_DIR}/gwos-ca.key" "${SSL_DIR}/gwos-ca.crt"
    chown root:proxy "${SSL_DIR}/gwos-ca.key" "${SSL_DIR}/gwos-ca.crt"

    SSL_DB="/var/lib/squid/ssl_db"
    mkdir -p /var/lib/squid
    chown proxy:proxy /var/lib/squid
    [ -d "$SSL_DB" ] && rm -rf "$SSL_DB"
    runuser -u proxy -- "$CERTGEN" -c -s "$SSL_DB" -M 16MB
    ok "Banco de certificados dinâmicos criado."

    # O caminho do certgen e a porta HTTPS entram no gwos_portas.conf, gerado
    # pelo gwos-integrar no fim desta instalação.

    # Publica a CA para download, se o painel existir
    if REPO="$(raiz_projeto)"; then
        install -m 644 "${SSL_DIR}/gwos-ca.crt" "${REPO}/public/gwos-ca.crt"
        chown root:root "${REPO}/public/gwos-ca.crt" 2>/dev/null || true
        ok "CA publicada em http://${IP_GATEWAY}/gwos-ca.crt"
    else
        aviso "Projeto não encontrado — copie ${SSL_DIR}/gwos-ca.crt para os clientes à mão."
    fi
else
    aviso "security_file_certgen não encontrado — SSL Bump desativado."
    aviso "Instale squid-openssl e reexecute este módulo para inspecionar HTTPS."
    # As portas ficam no arquivo gerado; aqui só as diretivas de SSL que
    # continuam no squid.conf e seriam fatais num Squid sem suporte a TLS.
    sed -i 's|^ssl_bump|#ssl_bump|g'       /etc/squid/squid.conf
    sed -i 's|^acl step1|#acl step1|g'     /etc/squid/squid.conf
    sed -i 's|ssl::server_name|dstdomain|g' /etc/squid/squid.conf
fi

# ---------------------------------------------------------------------------
# Cache, permissões e start
# ---------------------------------------------------------------------------
squid -z 2>/dev/null || true
usermod -aG proxy www-data 2>/dev/null || true

squid -k parse >/dev/null 2>&1 || {
    falha "squid -k parse reprovou a configuração:"
    squid -k parse || true
    erro "Squid não iniciado."
}
ok "Configuração validada."

svc_ativar squid
ok "Squid ativo."

registrar_modulo proxy-squid
integrar --silencioso

echo ""
ok "Módulo 50-proxy-squid instalado."
echo -e "  Proxy explícito : ${BOLD}${IP_GATEWAY}:${SQUID_PORTA_FWD}${NC}"
if tem_ssl_bump; then
    echo -e "  Certificado CA  : ${BOLD}/etc/squid/ssl_cert/gwos-ca.crt${NC} — instale nos clientes"
fi
tem_firewall || echo -e "  ${YELLOW}Sem o módulo 40-firewall-nftables não há interceptação transparente.${NC}"
if ! tem_painel; then
    echo ""
    aviso "Sem o painel (módulo 60) as listas de grupos ficam vazias e a regra"
    aviso "'http_access deny all' bloqueia todo mundo. Para usar o proxy isolado:"
    echo  "      sed -i 's/^http_access deny all/http_access allow localnet\\nhttp_access deny all/' /etc/squid/squid.conf"
    echo  "      squid -k reconfigure"
fi
echo ""
