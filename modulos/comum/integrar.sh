#!/bin/bash
# ============================================================================
# GWOS — Integração entre módulos
# ============================================================================
# Instalado como /usr/local/sbin/gwos-integrar.
#
# Cada módulo funciona sozinho. Este script é o que faz eles conversarem:
# roda ao final de toda instalação/desinstalação de módulo, olha o que existe
# na máquina naquele momento e regrava só os pontos de contato:
#
#   BIND9    → encaminha o domínio interno ao dnsmasq (se o dnsmasq existir)
#   Squid    → resolve por 127.0.0.1 (se o BIND9 existir) ou pelo DNS externo
#   Squid    → ACLs das redes internas vindas do /etc/gwos/gwos.conf
#   nftables → redireciona 80/443 ao Squid (se o Squid existir) e
#              DNS ao BIND9 (se o BIND9 existir)
#   chrony   → libera as redes internas para sincronizar a hora
#
# Sem argumentos regrava tudo. Aceita: --silencioso
# ============================================================================

set -uo pipefail

for _l in "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/lib.sh" \
          "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || {
    echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

SILENCIOSO=0
[ "${1:-}" = "--silencioso" ] && SILENCIOSO=1
_msg() { [ "$SILENCIOSO" = "1" ] || "$@"; }

exigir_root
garantir_conf

_msg titulo "══ Integração entre módulos ══"

# ---------------------------------------------------------------------------
# 0. dnsmasq — regera a configuração com o domínio/porta atuais
# ---------------------------------------------------------------------------
if tem_dnsmasq && [ -x /usr/local/sbin/gwos-gerar-dnsmasq ]; then
    /usr/local/sbin/gwos-gerar-dnsmasq >/dev/null \
        && _msg ok "dnsmasq → ${DOMINIO_LOCAL} em 127.0.0.1:${DNSMASQ_PORTA}" \
        || falha "Falha ao regerar /etc/dnsmasq.d/gwos.conf."
fi

# ---------------------------------------------------------------------------
# 1. BIND9 — encaminhamento do domínio interno para o dnsmasq
# ---------------------------------------------------------------------------
if tem_bind9 && [ -d /etc/bind ]; then
    ARQ_INT="/etc/bind/named.conf.gwos-integracao"

    # -- 1a. Resolvers upstream ----------------------------------------------
    {
        echo "// Gerado por gwos-integrar a partir de DNS_FORWARDERS"
        echo "// em ${GWOS_CONF} — NÃO editar à mão."
        echo "forwarders {"
        for _dns in $DNS_FORWARDERS; do
            echo "    ${_dns};"
        done
        echo "};"
        echo "forward only;"
    } > /etc/bind/named.conf.gwos-forwarders
    chown bind:bind /etc/bind/named.conf.gwos-forwarders 2>/dev/null || true
    _msg ok "BIND9 → forwarders: ${DNS_FORWARDERS}"

    # -- 1b. Domínio interno --------------------------------------------------
    if tem_dnsmasq; then
        cat > "$ARQ_INT" <<ZONA
// Gerado por gwos-integrar — NÃO editar à mão.
// O domínio interno é resolvido pelo dnsmasq (módulo 25-dns-interno),
// que escuta em 127.0.0.1:${DNSMASQ_PORTA}.
zone "${DOMINIO_LOCAL}" {
    type forward;
    forward only;
    forwarders { 127.0.0.1 port ${DNSMASQ_PORTA}; };
};
ZONA
        _msg ok "BIND9 → dnsmasq: ${DOMINIO_LOCAL} encaminhado para 127.0.0.1:${DNSMASQ_PORTA}"
    else
        cat > "$ARQ_INT" <<ZONA
// Gerado por gwos-integrar — NÃO editar à mão.
// Módulo 25-dns-interno (dnsmasq) não instalado: nenhum encaminhamento
// do domínio ${DOMINIO_LOCAL}. Instale-o para resolver nomes da LAN.
ZONA
        _msg info "BIND9 sozinho — sem resolução de nomes internos (${DOMINIO_LOCAL})."
    fi

    # Garante o include no named.conf.local
    if [ -f /etc/bind/named.conf.local ] \
       && ! grep -q 'named.conf.gwos-integracao' /etc/bind/named.conf.local; then
        echo "include \"${ARQ_INT}\";" >> /etc/bind/named.conf.local
    fi
    chown bind:bind "$ARQ_INT" 2>/dev/null || true

    if named-checkconf >/dev/null 2>&1; then
        rndc reload >/dev/null 2>&1 || systemctl reload named >/dev/null 2>&1 || true
    else
        falha "named-checkconf reprovou a configuração — BIND9 não recarregado."
        named-checkconf || true
    fi
fi

# ---------------------------------------------------------------------------
# 2. Squid — resolver e ACLs de rede
# ---------------------------------------------------------------------------
if tem_squid && [ -d /etc/squid ]; then
    mkdir -p /etc/squid/conf.d

    # -- 2a. Resolver DNS -----------------------------------------------------
    if tem_bind9; then
        DNS_SQUID="127.0.0.1"
        DNS_ORIGEM="BIND9 local (RPZ ativo)"
    else
        DNS_SQUID=$(awk '/^nameserver/{print $2}' /etc/resolv.conf 2>/dev/null \
                    | grep -v '^127\.' | head -2 | paste -sd ' ' -)
        DNS_SQUID="${DNS_SQUID:-8.8.8.8 1.1.1.1}"
        DNS_ORIGEM="resolvers externos (módulo 20-dns-bind9 ausente)"
    fi

    cat > /etc/squid/conf.d/gwos_integracao.conf <<SQINT
# Gerado por gwos-integrar — NÃO editar à mão.
# Resolver do Squid: ${DNS_ORIGEM}
dns_nameservers ${DNS_SQUID}
dns_defnames off
SQINT
    _msg ok "Squid → DNS: ${DNS_SQUID} (${DNS_ORIGEM})"

    # -- 2b. Portas de escuta -------------------------------------------------
    # Mesmas portas que o firewall usa no redirecionamento — geradas de um
    # lugar só para que nftables e Squid nunca discordem.
    CERTGEN=""
    for _p in /usr/lib/squid/security_file_certgen \
              /usr/libexec/squid/security_file_certgen \
              /usr/lib/squid4/security_file_certgen; do
        [ -x "$_p" ] && { CERTGEN="$_p"; break; }
    done

    {
        echo "# Gerado por gwos-integrar a partir de ${GWOS_CONF} — NÃO editar à mão."
        echo "http_port ${SQUID_PORTA_FWD}"
        echo "http_port ${SQUID_PORTA} intercept"
        if [ -n "$CERTGEN" ] && tem_ssl_bump; then
            echo "https_port ${SQUID_PORTA_SSL} intercept ssl-bump \\"
            echo "    generate-host-certificates=on \\"
            echo "    dynamic_cert_mem_cache_size=16MB \\"
            echo "    cert=/etc/squid/ssl_cert/gwos-ca.crt \\"
            echo "    key=/etc/squid/ssl_cert/gwos-ca.key"
            echo "sslcrtd_program ${CERTGEN} -s /var/lib/squid/ssl_db -M 16MB"
            echo "sslcrtd_children 8 startup=2 idle=2"
        else
            echo "# SSL Bump desativado (sem certgen ou sem CA) — HTTPS não é interceptado."
        fi
    } > /etc/squid/conf.d/gwos_portas.conf
    _msg ok "Squid → portas: ${SQUID_PORTA_FWD} (explícito), ${SQUID_PORTA} (HTTP)$(tem_ssl_bump && [ -n "$CERTGEN" ] && echo ", ${SQUID_PORTA_SSL} (HTTPS)" || echo "")"

    # -- 2c. ACLs das redes internas -----------------------------------------
    {
        echo "# Gerado por gwos-integrar a partir de ${GWOS_CONF} — NÃO editar à mão."
        for rede in $(redes_internas); do
            echo "acl localnet src ${rede}"
        done
        for rede in $(redes_internas); do
            echo "acl rede_interna dst ${rede}"
        done
    } > /etc/squid/conf.d/gwos_redes.conf
    _msg ok "Squid → redes internas: $(redes_internas | tr '\n' ' ')"

    # -- 2d. Página de login para quem não tem sessão --------------------------
    # Só aponta para o portal se ele existe (marcador criado por
    # gerar-portal-acesso.sh); senão o usuário seria mandado a uma porta morta.
    {
        echo "# Gerado por gwos-integrar — NÃO editar à mão."
        if [ -f /etc/gwos/acesso-internet.ativo ] && [ -n "${IP_GATEWAY:-}" ]; then
            echo "deny_info 302:https://${IP_GATEWAY}:${ACESSO_PORTA:-8444}/acesso?url=%u all"
        else
            echo "deny_info ERR_ACCESS_DENIED all"
        fi
    } > /etc/squid/conf.d/gwos_portal.conf
    [ -f /etc/gwos/acesso-internet.ativo ] \
        && _msg ok "Squid → login: https://${IP_GATEWAY:-?}:${ACESSO_PORTA:-8444}/acesso" \
        || _msg ok "Squid → login: portal de acesso não ativado (página padrão de negado)"

    if squid -k parse >/dev/null 2>&1; then
        svc_ativo squid && { squid -k reconfigure >/dev/null 2>&1 || true; }
    else
        falha "squid -k parse reprovou a configuração — Squid não recarregado."
    fi
fi

# ---------------------------------------------------------------------------
# 3. nftables — redirecionamentos condicionais
# ---------------------------------------------------------------------------
if tem_firewall; then
    if [ -x /usr/local/sbin/gwos-gerar-nftables ]; then
        if /usr/local/sbin/gwos-gerar-nftables >/dev/null; then
            _msg ok "nftables regerado (proxy: $(tem_squid && echo sim || echo não), DNS local: $(tem_bind9 && echo sim || echo não))"
        else
            falha "Falha ao regerar as regras nftables."
        fi
    else
        _msg aviso "gwos-gerar-nftables ausente — instale o módulo 40-firewall-nftables."
    fi
fi

# ---------------------------------------------------------------------------
# 3b. Painel — o site do nginx acompanha PAINEL_PORTA
# ---------------------------------------------------------------------------
# Sem isto, mudar PAINEL_PORTA no gwos.conf não movia nada: o vhost só era
# escrito pelo instalador, e o valor no arquivo passava a mentir.
if tem_painel && [ -x /usr/local/sbin/gwos-gerar-nginx ]; then
    if /usr/local/sbin/gwos-gerar-nginx >/dev/null; then
        _msg ok "Painel → nginx na porta ${PAINEL_PORTA}"
    else
        falha "Não foi possível regerar o site do painel (porta ${PAINEL_PORTA} ocupada?)."
    fi
fi

# ---------------------------------------------------------------------------
# 4. chrony — libera as redes internas
# ---------------------------------------------------------------------------
if tem_chrony && [ -d /etc/chrony ]; then
    mkdir -p /etc/chrony/conf.d
    {
        echo "# Gerado por gwos-integrar — NÃO editar à mão."
        echo "# Fontes em NTP_SERVIDORES / NTP_POOL de ${GWOS_CONF}."
        echo ""
        echo "# Fonte preferida — servidor de hora interno da rede."
        for _ntp in $NTP_SERVIDORES; do
            echo "server ${_ntp} iburst prefer"
        done
        echo ""
        echo "# Reserva — se a fonte interna cair, o relógio não deriva."
        for _pool in $NTP_POOL; do
            echo "pool ${_pool} iburst"
        done
        echo ""
        echo "# Clientes autorizados a sincronizar com este gateway."
        for rede in $(redes_internas); do
            echo "allow ${rede}"
        done
        echo ""
        echo "# Serve a hora local mesmo sem alcançar nenhuma fonte."
        echo "local stratum 10"
    } > /etc/chrony/conf.d/gwos.conf

    # Debian usa 'confdir /etc/chrony/conf.d'; garante que está ativo
    if [ -f /etc/chrony/chrony.conf ] \
       && ! grep -qE '^\s*(confdir|include)\s+/etc/chrony/conf\.d' /etc/chrony/chrony.conf; then
        echo "confdir /etc/chrony/conf.d" >> /etc/chrony/chrony.conf
    fi
    svc_ativo chrony && systemctl restart chrony >/dev/null 2>&1 || true
    _msg ok "chrony → fonte: ${NTP_SERVIDORES} (reserva: ${NTP_POOL})"
    _msg ok "chrony → clientes autorizados: $(redes_internas)"
fi

# ---------------------------------------------------------------------------
# 5. Painel + banco — repopula listas geradas a partir do banco
# ---------------------------------------------------------------------------
if tem_painel && tem_banco && REPO="$(raiz_projeto)"; then
    if tem_squid && [ -f "${REPO}/scripts/gerar_squid_dominios.sh" ]; then
        bash "${REPO}/scripts/gerar_squid_dominios.sh" --no-reconfigure >/dev/null 2>&1 || true
        bash "${REPO}/scripts/gerar_squid_acl.sh"      --no-reconfigure >/dev/null 2>&1 || true
        svc_ativo squid && { squid -k reconfigure >/dev/null 2>&1 || true; }
        _msg ok "Squid → listas de domínios e horários recarregadas do banco."
    fi
    if tem_bind9 && [ -f "${REPO}/scripts/aplicar_bind9_rpz.sh" ]; then
        bash "${REPO}/scripts/aplicar_bind9_rpz.sh" >/dev/null 2>&1 || true
        _msg ok "BIND9 → zona RPZ regerada a partir do banco."
    fi
    # As regras com grupos de IPs e NAT 1:1 já entraram no passo 3:
    # gwos-gerar-nftables delega para scripts/aplicar_nftables.sh quando
    # o banco e o painel existem.
fi

# ---------------------------------------------------------------------------
# Resumo
# ---------------------------------------------------------------------------
if [ "$SILENCIOSO" != "1" ]; then
    echo ""
    echo -e "  ${BOLD}Módulos GWOS presentes nesta máquina:${NC}"
    if [ -z "$(modulos_instalados)" ]; then
        echo "    (nenhum registrado em ${GWOS_MODULOS})"
    else
        while read -r m; do
            [ -n "$m" ] && echo "    • ${m}"
        done < <(modulos_instalados)
    fi
    echo ""
fi
