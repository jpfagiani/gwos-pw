#!/bin/bash
# ============================================================================
# GWOS — Instalador (Debian 12/13)
# ============================================================================
# A instalação foi desmembrada em módulos independentes, um por servidor:
#
#   modulos/00-base                  rede, hardware, estado compartilhado
#   modulos/10-banco-mariadb         banco de dados
#   modulos/20-dns-bind9             servidor DNS (RPZ)
#   modulos/25-dns-interno-dnsmasq   nomes internos da LAN
#   modulos/30-hora-chrony           servidor de hora (NTP)
#   modulos/40-firewall-nftables     firewall e NAT
#   modulos/50-proxy-squid           proxy HTTP/HTTPS com SSL Bump
#   modulos/60-painel-web            painel de administração
#
# Este script instala todos, na ordem. Para instalar um servidor isolado:
#
#   bash modulos/20-dns-bind9/instalar.sh
#
# Execute como root: bash install.sh
# ============================================================================

set -euo pipefail

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULOS="${GWOS_DIR}/modulos"

[ "$(id -u)" -eq 0 ] || { echo "[ERRO] Execute como root."; exit 1; }
[ -f "${MODULOS}/instalar-todos.sh" ] || {
    echo "[ERRO] ${MODULOS}/instalar-todos.sh não encontrado."
    echo "       O repositório parece incompleto."
    exit 1
}

exec bash "${MODULOS}/instalar-todos.sh" "$@"
