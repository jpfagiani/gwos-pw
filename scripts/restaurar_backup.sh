#!/bin/bash
# Restaura um backup GWOS.
# Uso: restaurar_backup.sh /caminho/para/arquivo.tar.gz

set -euo pipefail
umask 077

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$GWOS_DIR/scripts/ler_env.sh"
gwos_ler_env "$GWOS_DIR/.env"

ARQUIVO="${1:?Informe o arquivo de backup}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_BANCO="${DB_BANCO:-gwos}"
DB_USUARIO="${DB_USUARIO:-gwos}"
DB_SENHA="${DB_SENHA:-}"
TMP="$(mktemp -d /tmp/gwos_restaurar.XXXXXX)"
trap 'rm -rf "$TMP"' EXIT

[ -f "$ARQUIVO" ] || { echo "Arquivo não encontrado: $ARQUIVO"; exit 1; }

if [ -f "${ARQUIVO}.sha256" ]; then
    (cd "$(dirname "$ARQUIVO")" && sha256sum -c "$(basename "$ARQUIVO").sha256")
else
    echo "AVISO: sem checksum lateral; verificando somente a integridade gzip/tar."
fi
gzip -t "$ARQUIVO"
tar -tvzf "$ARQUIVO" | awk '
    $1 !~ /^[-d]/ { bad=1 }
    !($NF ~ /^gwos_bkp_[0-9_]+\/$/ || $NF ~ /^gwos_bkp_[0-9_]+\/(banco\.sql|nftables\.conf|named\.conf\.local|db\.rpz\.gwos|squid\.conf)$/) { bad=1 }
    END { if (bad) { print "Arquivo contém membro inesperado ou caminho inseguro." > "/dev/stderr"; exit 1 } }
'

tar -xzf "$ARQUIVO" -C "$TMP" --strip-components=1

# ------------------------------------------------------------------
# Preserva a configuração de REDE ATUAL antes de restaurar o banco.
# Um backup antigo pode ter sido feito com outro IP/interfaces — restaurá-lo
# não pode reverter a rede do servidor (causa perda de acesso).
# ------------------------------------------------------------------
mysql_q() {
    mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
        --batch --skip-column-names -e "$1" 2>/dev/null
}

declare -A REDE_ATUAL
for CHAVE in iface_wan iface_lan rede_lan ip_gateway; do
    REDE_ATUAL[$CHAVE]=$(mysql_q "SELECT valor FROM configuracoes WHERE chave='$CHAVE'" || true)
done

# Restaurar banco
[ -f "$TMP/banco.sql" ] && mysql --binary-mode -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" < "$TMP/banco.sql"

# Reaplica as chaves de rede atuais (não podem vir do backup)
for CHAVE in iface_wan iface_lan rede_lan ip_gateway; do
    [ -n "${REDE_ATUAL[$CHAVE]}" ] && \
        mysql_q "UPDATE configuracoes SET valor='${REDE_ATUAL[$CHAVE]}' WHERE chave='$CHAVE'"
done

# Restaurar configurações de DNS (não dependem do IP do gateway)
[ -f "$TMP/named.conf.local"   ] && cp "$TMP/named.conf.local"    /etc/bind/named.conf.local
[ -f "$TMP/db.rpz.gwos"        ] && cp "$TMP/db.rpz.gwos"         /etc/bind/db.rpz.gwos

# nftables NÃO é copiado do backup: é regenerado a partir do banco já
# restaurado, com as interfaces/rede ATUAIS (validado com nft -c internamente).
GWOS_SCRIPTS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
bash "$GWOS_SCRIPTS/aplicar_nftables.sh" || { echo "ERRO: nftables não foi confirmado após a restauração." >&2; exit 1; }

# squid.conf também não é sobrescrito pelo backup (pode conter rede antiga);
# as listas/ACLs do Squid são regeneradas do banco restaurado.
DB_HOST="$DB_HOST" DB_BANCO="$DB_BANCO" DB_USUARIO="$DB_USUARIO" DB_SENHA="$DB_SENHA" \
    bash "$GWOS_SCRIPTS/gerar_squid_dominios.sh"

# Reinicia serviços
systemctl reload-or-restart named squid

echo "Backup restaurado com sucesso (configuração de rede atual preservada)."
