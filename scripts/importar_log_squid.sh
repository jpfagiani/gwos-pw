#!/bin/bash
# Parseia /var/log/squid/access.log e insere/atualiza relatorio_diario no banco.
# Executado via cron a cada hora.

set -euo pipefail

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ -f "$GWOS_DIR/.env" ]; then set -a; source "$GWOS_DIR/.env"; set +a; fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_BANCO="${DB_BANCO:-gwos}"
DB_USUARIO="${DB_USUARIO:-gwos}"
DB_SENHA="${DB_SENHA:-}"
LOG_FILE="${LOG_FILE:-/var/log/squid/access.log}"

[ -f "$LOG_FILE" ] || { echo "Log não encontrado: $LOG_FILE"; exit 0; }

# Formato do log gwos:
# timestamp elapsed client action/status bytes method url user hierarchy/peer mime
# Ex: 1718600000.123   1234 192.168.1.10 TCP_MISS/200 5432 GET http://example.com/ - DIRECT/1.2.3.4 text/html

DATA_HOJE=$(date +%Y-%m-%d)

# Lê as últimas 50000 linhas para evitar reprocessar o log inteiro
tail -n 50000 "$LOG_FILE" | awk -v data="$DATA_HOJE" '
{
    ip      = $3
    status  = $4
    bytes   = $5
    url     = $7

    # Extrai domínio da URL
    gsub(/https?:\/\//, "", url)
    split(url, parts, "/")
    dominio = parts[1]
    # Remove porta se houver
    split(dominio, dp, ":")
    dominio = dp[1]

    if (dominio == "" || ip == "") next

    # Detecta se foi bloqueado (DENIED ou TCP_DENIED)
    bloqueado = (status ~ /DENIED/) ? 1 : 0

    chave = ip SUBSEP dominio
    acessos[chave]++
    total_bytes[chave] += bytes
    flag_bloqueado[chave] = bloqueado
    ip_arr[chave] = ip
    dom_arr[chave] = dominio
}
END {
    for (k in acessos) {
        printf "%s\t%s\t%d\t%d\t%d\n", \
            ip_arr[k], dom_arr[k], acessos[k], total_bytes[k], flag_bloqueado[k]
    }
}
' | while IFS=$'\t' read -r ip dominio acessos bytes bloqueado; do
    mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
        --batch --skip-column-names -e "
        INSERT INTO relatorio_diario (data, ip_cliente, dominio, acessos, bytes, bloqueado)
        VALUES ('${DATA_HOJE}', '${ip}', '${dominio}', ${acessos}, ${bytes}, ${bloqueado})
        ON DUPLICATE KEY UPDATE
            acessos   = acessos   + VALUES(acessos),
            bytes     = bytes     + VALUES(bytes),
            bloqueado = VALUES(bloqueado);
    " 2>/dev/null || true
done

echo "Importação de log concluída em $(date)."
