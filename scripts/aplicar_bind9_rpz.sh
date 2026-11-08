#!/bin/bash
# Gera a zona RPZ do BIND9 a partir das blacklists do banco.
# Executado pelo painel via sudo.

set -euo pipefail

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$GWOS_DIR/scripts/ler_env.sh"
gwos_ler_env "$GWOS_DIR/.env"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_BANCO="${DB_BANCO:-gwos}"
DB_USUARIO="${DB_USUARIO:-gwos}"
DB_SENHA="${DB_SENHA:-}"
RPZ_ARQUIVO="/etc/bind/db.rpz.gwos"

SERIAL=$(date +%Y%m%d%H%M%S)

# A zona e montada num temporario e validada antes de valer. Antes o arquivo
# de producao era escrito direto: uma consulta que falhasse no meio deixava a
# RPZ truncada, e um dominio malformado no banco gerava uma zona que o BIND9
# recusa ao carregar. Nos dois casos o bloqueio parava de valer em silencio.
TMP="$(mktemp)"; TMP_DOM="$(mktemp)"
BACKUP_DIR="$(mktemp -d /tmp/gwos_rpz.XXXXXX)"; chmod 0700 "$BACKUP_DIR"
COMMITADO=0; HAD_RPZ=0
if [ -f "$RPZ_ARQUIVO" ]; then cp -p "$RPZ_ARQUIVO" "$BACKUP_DIR/rpz.anterior"; HAD_RPZ=1; fi
limpar_rpz() {
    rc=$?
    if [ "$COMMITADO" = "0" ]; then
        if [ "$HAD_RPZ" = "1" ]; then cp -p "$BACKUP_DIR/rpz.anterior" "$RPZ_ARQUIVO"
        else rm -f "$RPZ_ARQUIVO"; fi
        if command -v rndc >/dev/null 2>&1; then rndc reload rpz.gwos >/dev/null 2>&1 || systemctl reload named >/dev/null 2>&1 || true; fi
    fi
    rm -f "$TMP" "$TMP_DOM"; rm -rf "$BACKUP_DIR"
    return "$rc"
}
trap limpar_rpz EXIT

cat > "$TMP" <<ZONA
\$TTL 60
@   IN SOA localhost. root.localhost. (
        $SERIAL ; serial
        3600    ; refresh
        900     ; retry
        86400   ; expire
        60 )    ; minimum
    IN NS  localhost.

ZONA

# Adiciona domínios da blacklist
if ! mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" -sNe \
     "SELECT dominio FROM dominios WHERE tipo='blacklist' AND ativo=1" > "$TMP_DOM"; then
    echo "ERRO: consulta da blacklist falhou — RPZ NAO alterada." >&2
    exit 1
fi

while IFS= read -r dominio; do
    [ -n "$dominio" ] || continue
    printf '%s.    IN CNAME .\n' "$dominio"
    printf '*.%s.  IN CNAME .\n' "$dominio"
done < "$TMP_DOM" >> "$TMP"

# named-checkzone recusa dominio malformado antes de o arquivo virar producao.
# 'named-checkconf' sozinho, como estava aqui, NAO olha o conteudo das zonas.
if ! named-checkzone rpz.gwos "$TMP" >/dev/null 2>&1; then
    echo "ERRO: zona RPZ invalida — ${RPZ_ARQUIVO} NAO foi alterado." >&2
    named-checkzone rpz.gwos "$TMP" >&2 || true
    exit 1
fi

install -m 644 "$TMP" "$RPZ_ARQUIVO"
chown root:bind "$RPZ_ARQUIVO" 2>/dev/null || true

if ! rndc reload rpz.gwos >/dev/null 2>&1 && ! rndc reload >/dev/null 2>&1; then
    systemctl reload named >/dev/null 2>&1 || {
        echo "ERRO: o serviço DNS não confirmou o reload; restaurando a zona anterior." >&2
        exit 1
    }
fi

COMMITADO=1
trap - EXIT
rm -f "$TMP" "$TMP_DOM"; rm -rf "$BACKUP_DIR"

echo "RPZ aplicado com sucesso (serial: $SERIAL, $(wc -l < "$TMP_DOM") dominios)."
