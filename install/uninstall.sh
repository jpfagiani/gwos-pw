#!/bin/bash
# ============================================================================
# GWOS — Desinstalador
# ============================================================================
# Remove os módulos na ordem inversa da instalação. Para remover um servidor
# isolado, sem mexer nos outros:
#
#   bash modulos/50-proxy-squid/desinstalar.sh
#
# Execute como root: bash uninstall.sh
# ============================================================================

set -euo pipefail

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULOS="${GWOS_DIR}/modulos"

[ "$(id -u)" -eq 0 ] || { echo "[ERRO] Execute como root."; exit 1; }
[ -f "${MODULOS}/desinstalar-todos.sh" ] || {
    echo "[ERRO] ${MODULOS}/desinstalar-todos.sh não encontrado."
    exit 1
}

exec bash "${MODULOS}/desinstalar-todos.sh" "$@"
