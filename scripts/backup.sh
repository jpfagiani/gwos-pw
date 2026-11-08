#!/bin/bash
# Backup automático — banco + arquivos de configuração.
# Configurar no cron: 0 2 * * * /opt/gwos/scripts/backup.sh

set -euo pipefail
umask 077

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$GWOS_DIR/scripts/ler_env.sh"
gwos_ler_env "$GWOS_DIR/.env"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_BANCO="${DB_BANCO:-gwos}"
DB_USUARIO="${DB_USUARIO:-gwos}"
DB_SENHA="${DB_SENHA:-}"
BACKUP_DIR="${BACKUP_DIR:-/var/lib/gwos/backups}"
RETENCAO=30  # dias

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

DATA=$(date +%Y%m%d_%H%M%S)
ARQUIVO="gwos_backup_${DATA}.tar.gz"
TMP="$(mktemp -d /tmp/gwos_backup.XXXXXX)"
mkdir "$TMP/gwos_bkp_${DATA}"
trap 'rm -rf "$TMP" "${BACKUP_DIR}/${ARQUIVO}.tmp"' EXIT

# Dump do banco
mysqldump -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" \
    --single-transaction --routines --triggers \
    "$DB_BANCO" > "$TMP/gwos_bkp_${DATA}/banco.sql"

# Arquivos de configuração
cp /etc/nftables.conf          "$TMP/gwos_bkp_${DATA}/" 2>/dev/null || true
cp /etc/bind/named.conf.local  "$TMP/gwos_bkp_${DATA}/" 2>/dev/null || true
cp /etc/bind/db.rpz.gwos       "$TMP/gwos_bkp_${DATA}/" 2>/dev/null || true
cp /etc/squid/squid.conf       "$TMP/gwos_bkp_${DATA}/" 2>/dev/null || true

# Compacta
tar -czf "$BACKUP_DIR/${ARQUIVO}.tmp" -C "$TMP" "gwos_bkp_${DATA}"
gzip -t "$BACKUP_DIR/${ARQUIVO}.tmp"
tar -tzf "$BACKUP_DIR/${ARQUIVO}.tmp" >/dev/null
mv "$BACKUP_DIR/${ARQUIVO}.tmp" "$BACKUP_DIR/$ARQUIVO"
(cd "$BACKUP_DIR" && sha256sum "$ARQUIVO" > "${ARQUIVO}.sha256")
rm -rf "$TMP"
trap - EXIT

TAMANHO=$(stat -c%s "$BACKUP_DIR/$ARQUIVO")

# Nome gerado pelo próprio script e tamanho numérico: ambos podem ser
# interpolados com segurança nesta instrução SQL fixa.
mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" -e \
    "INSERT INTO backups (arquivo, tamanho, tipo, status) VALUES ('$ARQUIVO', $TAMANHO, 'auto', 'ok')"

# Remove backups antigos
find "$BACKUP_DIR" -name "gwos_backup_*.tar.gz" -mtime +$RETENCAO -delete
find "$BACKUP_DIR" -name "gwos_backup_*.tar.gz.sha256" -mtime +$RETENCAO -delete

echo "Backup concluído: $ARQUIVO ($(numfmt --to=iec $TAMANHO))"
