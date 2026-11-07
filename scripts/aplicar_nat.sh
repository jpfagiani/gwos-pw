#!/bin/bash
# Aplica ou remove uma regra de NAT 1:1.
# Uso: aplicar_nat.sh <ativar|desativar> <ip_externo> <ip_interno>
# Executado pelo painel via sudo.

set -euo pipefail

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ -f "$GWOS_DIR/.env" ]; then set -a; source "$GWOS_DIR/.env"; set +a; fi

ACAO="$1"
IP_EXT="$2"
IP_INT="$3"
IFACE_WAN="${IFACE_WAN:-}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_BANCO="${DB_BANCO:-gwos}"
DB_USUARIO="${DB_USUARIO:-gwos}"
DB_SENHA="${DB_SENHA:-}"

mysql_q() {
    mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
        --batch --skip-column-names -e "$1"
}

# Valida IPs
for ip in "$IP_EXT" "$IP_INT"; do
    echo "$ip" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$' \
        || { echo "IP inválido: $ip"; exit 1; }
done

# Lê interface WAN do banco se não veio por env
[ -z "$IFACE_WAN" ] && IFACE_WAN=$(mysql_q "SELECT valor FROM configuracoes WHERE chave='iface_wan'")

case "$ACAO" in
    ativar)
        # Adiciona as regras ao conjunto gwos_nat em tempo real
        nft add rule ip gwos_nat prerouting \
            iif "$IFACE_WAN" ip daddr "$IP_EXT" dnat to "$IP_INT" \
            comment "nat1:1_${IP_EXT}"
        nft add rule ip gwos_nat postrouting \
            oif "$IFACE_WAN" ip saddr "$IP_INT" snat to "$IP_EXT" \
            comment "nat1:1_${IP_INT}"
        echo "NAT 1:1 ativado: $IP_EXT ↔ $IP_INT"
        ;;
    desativar)
        # Remove regras pelo comment
        nft -a list chain ip gwos_nat prerouting 2>/dev/null \
            | grep "nat1:1_${IP_EXT}" \
            | awk '{print $NF}' \
            | while read -r handle; do
                nft delete rule ip gwos_nat prerouting handle "$handle" 2>/dev/null || true
              done
        nft -a list chain ip gwos_nat postrouting 2>/dev/null \
            | grep "nat1:1_${IP_INT}" \
            | awk '{print $NF}' \
            | while read -r handle; do
                nft delete rule ip gwos_nat postrouting handle "$handle" 2>/dev/null || true
              done
        echo "NAT 1:1 desativado: $IP_EXT ↔ $IP_INT"
        ;;
    *)
        echo "Uso: $0 <ativar|desativar> <ip_externo> <ip_interno>"
        exit 1
        ;;
esac
