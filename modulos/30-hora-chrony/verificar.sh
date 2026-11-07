#!/bin/bash
# GWOS — Módulo 30-hora-chrony: verificação

set -uo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

carregar_conf
FALHAS=0
titulo "── Verificação: 30-hora-chrony ──"

if svc_ativo chrony; then ok "Serviço chrony ativo."
else falha "Serviço chrony parado."; FALHAS=$((FALHAS+1)); fi

if [ -f /etc/chrony/conf.d/gwos.conf ]; then
    ok "Configuração do GWOS presente."
    FONTE=$(grep '^server' /etc/chrony/conf.d/gwos.conf 2>/dev/null | awk '{print $2}' | paste -sd ' ' -)
    echo "     Fonte preferida: ${FONTE:-(nenhuma — só o pool público)}"
    echo "     Reserva        : $(grep '^pool'   /etc/chrony/conf.d/gwos.conf | awk '{print $2}' | paste -sd ' ' -)"
    echo "     Redes liberadas: $(grep '^allow'  /etc/chrony/conf.d/gwos.conf | awk '{print $2}' | paste -sd ' ' -)"
else
    falha "Ausente: /etc/chrony/conf.d/gwos.conf (rode gwos-integrar)."; FALHAS=$((FALHAS+1))
fi

if grep -qE '^\s*(confdir|include)\s+/etc/chrony/conf\.d' /etc/chrony/chrony.conf 2>/dev/null; then
    ok "chrony.conf carrega o conf.d."
else
    falha "chrony.conf NÃO carrega /etc/chrony/conf.d — a configuração é ignorada."
    FALHAS=$((FALHAS+1))
fi

if command -v chronyc >/dev/null 2>&1; then
    if chronyc tracking >/dev/null 2>&1; then
        REF=$(chronyc tracking 2>/dev/null | awk -F': ' '/Reference ID/{print $2}')
        DES=$(chronyc tracking 2>/dev/null | awk -F': ' '/System time/{print $2}')
        ok "Sincronizado com: ${REF:-?}"
        info "Desvio do relógio: ${DES:-?}"
    else
        falha "chronyc tracking não respondeu."; FALHAS=$((FALHAS+1))
    fi
    N=$(chronyc sources 2>/dev/null | grep -c '^\^') || N=0
    info "Fontes de tempo configuradas: ${N}"
fi

if ss -lnu 2>/dev/null | grep -q ':123'; then
    ok "Escutando NTP na porta 123 (serve a hora para a LAN)."
else
    falha "Nada escutando na porta 123 — a LAN não consegue sincronizar."
    FALHAS=$((FALHAS+1))
fi

echo ""
[ "$FALHAS" -eq 0 ] && ok "30-hora-chrony OK." || falha "30-hora-chrony com ${FALHAS} problema(s)."
exit $(( FALHAS > 0 ? 1 : 0 ))
