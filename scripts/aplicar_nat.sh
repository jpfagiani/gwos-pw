#!/bin/bash
# Aplica ou remove uma regra de NAT 1:1.
# Uso: aplicar_nat.sh <ativar|desativar> <ip_externo> <ip_interno>
# Executado pelo painel via sudo.

set -euo pipefail

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$GWOS_DIR/scripts/ler_env.sh"
gwos_ler_env "$GWOS_DIR/.env"

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
[[ "$IFACE_WAN" =~ ^[a-zA-Z0-9_.:-]{1,32}$ ]] || { echo "Interface WAN inválida." >&2; exit 1; }
TMP_NFT="$(mktemp /tmp/gwos_nat.XXXXXX.nft)"
trap 'rm -f "$TMP_NFT"' EXIT

case "$ACAO" in
    ativar)
        cat > "$TMP_NFT" <<NFT
add rule ip gwos_nat prerouting iif "$IFACE_WAN" ip daddr $IP_EXT dnat to $IP_INT comment "nat1:1_${IP_EXT}"
add rule ip gwos_nat postrouting oif "$IFACE_WAN" ip saddr $IP_INT snat to $IP_EXT comment "nat1:1_${IP_INT}"
NFT
        nft -c -f "$TMP_NFT"
        nft -f "$TMP_NFT"
        nft -a list chain ip gwos_nat prerouting | grep -Fq "nat1:1_${IP_EXT}" || { echo "Pós-checagem DNAT falhou." >&2; exit 1; }
        nft -a list chain ip gwos_nat postrouting | grep -Fq "nat1:1_${IP_INT}" || { echo "Pós-checagem SNAT falhou." >&2; exit 1; }
        echo "NAT 1:1 ativado: $IP_EXT ↔ $IP_INT"
        ;;
    desativar)
        # Remove regras pelo comment
        while IFS= read -r handle; do [[ "$handle" =~ ^[0-9]+$ ]] && echo "delete rule ip gwos_nat prerouting handle $handle"; done < <(nft -a list chain ip gwos_nat prerouting 2>/dev/null | awk -v tag="nat1:1_${IP_EXT}" 'index($0,tag){print $NF}') > "$TMP_NFT"
        while IFS= read -r handle; do [[ "$handle" =~ ^[0-9]+$ ]] && echo "delete rule ip gwos_nat postrouting handle $handle"; done < <(nft -a list chain ip gwos_nat postrouting 2>/dev/null | awk -v tag="nat1:1_${IP_INT}" 'index($0,tag){print $NF}') >> "$TMP_NFT"
        if [ -s "$TMP_NFT" ]; then nft -c -f "$TMP_NFT"; nft -f "$TMP_NFT"; fi
        if nft -a list chain ip gwos_nat prerouting 2>/dev/null | grep -Fq "nat1:1_${IP_EXT}" || nft -a list chain ip gwos_nat postrouting 2>/dev/null | grep -Fq "nat1:1_${IP_INT}"; then echo "Pós-checagem: regras NAT ainda ativas." >&2; exit 1; fi
        echo "NAT 1:1 desativado: $IP_EXT ↔ $IP_INT"
        ;;
    *)
        echo "Uso: $0 <ativar|desativar> <ip_externo> <ip_interno>"
        exit 1
        ;;
esac
