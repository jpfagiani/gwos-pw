#!/bin/bash
# GWOS — Módulo 50-proxy-squid: verificação

set -uo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

carregar_conf
FALHAS=0
titulo "── Verificação: 50-proxy-squid ──"

if svc_ativo squid; then ok "Serviço squid ativo."
else falha "Serviço squid parado."; FALHAS=$((FALHAS+1)); fi

if squid -k parse >/dev/null 2>&1; then
    ok "squid -k parse sem erros."
else
    falha "squid -k parse com erros:"; squid -k parse 2>&1 | tail -5; FALHAS=$((FALHAS+1))
fi

for PORTA in "${SQUID_PORTA_FWD:-3127}" "${SQUID_PORTA:-3128}"; do
    if ss -lnt 2>/dev/null | grep -q ":${PORTA} "; then
        ok "Escutando na porta ${PORTA}."
    else
        falha "Nada escutando na porta ${PORTA}."; FALHAS=$((FALHAS+1))
    fi
done

if tem_ssl_bump; then
    if ss -lnt 2>/dev/null | grep -q ":${SQUID_PORTA_SSL:-3129} "; then
        ok "SSL Bump ativo na porta ${SQUID_PORTA_SSL:-3129}."
    else
        falha "CA existe mas a porta ${SQUID_PORTA_SSL:-3129} não escuta."; FALHAS=$((FALHAS+1))
    fi
    VAL=$(openssl x509 -in /etc/squid/ssl_cert/gwos-ca.crt -noout -enddate 2>/dev/null | cut -d= -f2)
    info "CA válida até: ${VAL:-?}"
else
    info "SSL Bump desativado (sem certgen) — HTTPS não é inspecionado."
fi

for ARQ in gwos_redes.conf gwos_integracao.conf gwos_horarios.conf \
           gwos_ips_bloqueados.txt gwos_ips_parciais.txt gwos_ips_liberados.txt \
           gwos_whitelist.txt gwos_blacklist.txt gwos_sites_livres.txt \
           gwos_ips_livres.txt gwos_tcp_outgoing.conf \
           gwos_ips_isentos.txt gwos_portal.conf; do
    [ -f "/etc/squid/conf.d/${ARQ}" ] || {
        falha "Ausente: /etc/squid/conf.d/${ARQ} — o Squid não sobe sem ele."
        FALHAS=$((FALHAS+1))
    }
done
[ "$FALHAS" -eq 0 ] && ok "Todos os arquivos incluídos existem."

DNS=$(grep -h '^dns_nameservers' /etc/squid/conf.d/gwos_integracao.conf 2>/dev/null | cut -d' ' -f2-)
info "Resolver do Squid: ${DNS:-?}"
if tem_bind9 && [ "${DNS:-}" != "127.0.0.1" ]; then
    falha "BIND9 instalado mas o Squid não usa 127.0.0.1 — rode gwos-integrar."
    FALHAS=$((FALHAS+1))
fi

if [ -r /var/log/squid/access.log ]; then
    LINHAS=$(wc -l < /var/log/squid/access.log 2>/dev/null) || LINHAS=0
    info "Registros no access.log: ${LINHAS}"
fi

if ! tem_painel; then
    VAZIAS=0
    for L in gwos_ips_liberados gwos_ips_parciais; do
        grep -qE '^[0-9]' "/etc/squid/conf.d/${L}.txt" 2>/dev/null || VAZIAS=$((VAZIAS+1))
    done
    [ "$VAZIAS" -eq 2 ] && aviso "Listas de grupos vazias e sem painel: 'http_access deny all' bloqueia todos."
fi

echo ""
[ "$FALHAS" -eq 0 ] && ok "50-proxy-squid OK." || falha "50-proxy-squid com ${FALHAS} problema(s)."
exit $(( FALHAS > 0 ? 1 : 0 ))
