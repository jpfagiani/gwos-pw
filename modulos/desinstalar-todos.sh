#!/bin/bash
# ============================================================================
# GWOS — Remove todos os módulos, na ordem inversa
# ============================================================================
#   bash desinstalar-todos.sh                     # tudo
#   bash desinstalar-todos.sh 50-proxy-squid      # só os listados
# ============================================================================

set -uo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
. "${MOD_DIR}/comum/lib.sh"

exigir_root

ORDEM=(
    60-painel-web
    50-proxy-squid
    40-firewall-nftables
    30-hora-chrony
    25-dns-interno-dnsmasq
    20-dns-bind9
    10-banco-mariadb
    00-base
)

SELECIONADOS=("$@")
selecionado() {
    local m="$1" s
    [ ${#SELECIONADOS[@]} -eq 0 ] && return 0
    for s in "${SELECIONADOS[@]}"; do [ "$s" = "$m" ] && return 0; done
    return 1
}

echo -e "\n${BOLD}${RED}ATENÇÃO: isto remove o GWOS desta máquina.${NC}\n"
[ ${#SELECIONADOS[@]} -gt 0 ] && echo -e "  Módulos: ${BOLD}${SELECIONADOS[*]}${NC}\n"
confirmar "Confirma?" || { echo "Cancelado."; exit 0; }

# A partir daqui, sem mais perguntas: os módulos removem tudo o que é do GWOS
# e mantêm apenas os pacotes que outra coisa nesta máquina usa.
export GWOS_SEM_PERGUNTAS=1

for m in "${ORDEM[@]}"; do
    selecionado "$m" || continue
    [ -f "${MOD_DIR}/${m}/desinstalar.sh" ] || continue
    bash "${MOD_DIR}/${m}/desinstalar.sh" || falha "Falha ao remover ${m} — seguindo."
done

echo ""
ok "Remoção concluída."
echo -e "  O diretório do projeto foi mantido: ${BOLD}$(raiz_projeto || echo '(não encontrado)')${NC}"
echo ""
