#!/bin/bash
# GWOS — Módulo 25-dns-interno-dnsmasq: verificação

set -uo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

carregar_conf
FALHAS=0
titulo "── Verificação: 25-dns-interno-dnsmasq ──"

if svc_ativo gwos-dnsmasq; then ok "Serviço gwos-dnsmasq ativo."
else falha "Serviço gwos-dnsmasq parado."; FALHAS=$((FALHAS+1)); fi

if [ -f /etc/dnsmasq.d/gwos.conf ]; then
    ok "Configuração presente: /etc/dnsmasq.d/gwos.conf"
    if dnsmasq --test --conf-file=/etc/dnsmasq.d/gwos.conf >/dev/null 2>&1; then
        ok "Sintaxe da configuração OK."
    else
        falha "Sintaxe inválida."; FALHAS=$((FALHAS+1))
    fi
else
    falha "Ausente: /etc/dnsmasq.d/gwos.conf"; FALHAS=$((FALHAS+1))
fi

if ss -lnu 2>/dev/null | grep -q "127.0.0.1:${DNSMASQ_PORTA}"; then
    ok "Escutando em 127.0.0.1:${DNSMASQ_PORTA}."
else
    falha "Nada escutando em 127.0.0.1:${DNSMASQ_PORTA}."; FALHAS=$((FALHAS+1))
fi

HOSTS=$(grep -cE "\.${DOMINIO_LOCAL}(\s|$)" /etc/hosts 2>/dev/null) || HOSTS=0
info "Nomes cadastrados em /etc/hosts para ${DOMINIO_LOCAL}: ${HOSTS}"

if command -v dig >/dev/null 2>&1 && [ "${HOSTS:-0}" -gt 0 ]; then
    PRIMEIRO=$(grep -oE "[a-zA-Z0-9_-]+\.${DOMINIO_LOCAL}" /etc/hosts 2>/dev/null | head -1)
    if [ -n "$PRIMEIRO" ]; then
        if dig +short +time=3 +tries=1 "$PRIMEIRO" @127.0.0.1 -p "$DNSMASQ_PORTA" 2>/dev/null | grep -qE '^[0-9]'; then
            ok "Resolveu ${PRIMEIRO} pelo dnsmasq."
        else
            falha "Não resolveu ${PRIMEIRO} pelo dnsmasq."; FALHAS=$((FALHAS+1))
        fi
    fi
fi

tem_bind9 && ok "BIND9 presente — a LAN alcança estes nomes pela porta 53." \
          || info "Sem o módulo 20-dns-bind9: só resolvem em 127.0.0.1:${DNSMASQ_PORTA}."

echo ""
[ "$FALHAS" -eq 0 ] && ok "25-dns-interno-dnsmasq OK." || falha "25-dns-interno-dnsmasq com ${FALHAS} problema(s)."
exit $(( FALHAS > 0 ? 1 : 0 ))
