#!/bin/bash
# ============================================================================
# GWOS — Verifica todos os módulos instalados
# ============================================================================
#   bash verificar-todos.sh              # só os módulos registrados
#   bash verificar-todos.sh --todos      # roda a verificação de todos
# ============================================================================

set -uo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
. "${MOD_DIR}/comum/lib.sh"

carregar_conf

# módulo → marcador de registro
declare -A MARCADOR=(
    [00-base]=base
    [10-banco-mariadb]=banco-mariadb
    [20-dns-bind9]=dns-bind9
    [25-dns-interno-dnsmasq]=dns-interno
    [30-hora-chrony]=hora-chrony
    [40-firewall-nftables]=firewall-nftables
    [50-proxy-squid]=proxy-squid
    [60-painel-web]=painel-web
)
ORDEM=(00-base 10-banco-mariadb 20-dns-bind9 25-dns-interno-dnsmasq
       30-hora-chrony 40-firewall-nftables 50-proxy-squid 60-painel-web)

TODOS=0
[ "${1:-}" = "--todos" ] && TODOS=1

echo -e "\n${BOLD}${CYAN}══ GWOS — verificação dos módulos ══${NC}"

COM_FALHA=()
VERIFICADOS=0

for m in "${ORDEM[@]}"; do
    if [ "$TODOS" = "0" ] && ! modulo_registrado "${MARCADOR[$m]}"; then
        continue
    fi
    [ -f "${MOD_DIR}/${m}/verificar.sh" ] || continue
    VERIFICADOS=$((VERIFICADOS+1))
    bash "${MOD_DIR}/${m}/verificar.sh" || COM_FALHA+=("$m")
done

echo ""
if [ "$VERIFICADOS" -eq 0 ]; then
    aviso "Nenhum módulo GWOS registrado nesta máquina."
    echo   "  Use --todos para verificar mesmo assim."
    exit 0
fi

echo -e "${BOLD}${CYAN}══ Resumo ══${NC}"
if [ ${#COM_FALHA[@]} -eq 0 ]; then
    ok "${VERIFICADOS} módulo(s) verificado(s) — tudo certo."
    exit 0
else
    falha "Com problemas: ${COM_FALHA[*]}"
    exit 1
fi
