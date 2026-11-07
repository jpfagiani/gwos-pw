#!/bin/bash
# Exporta listas de domínios (whitelist/blacklist) para os arquivos do Squid.
# Executado pelo painel após qualquer alteração em domínios.
# Uso: gerar_squid_dominios.sh [--no-reconfigure]

set -euo pipefail

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ -f "$GWOS_DIR/.env" ]; then set -a; source "$GWOS_DIR/.env"; set +a; fi

NO_RECONFIGURE=0
[[ "${1:-}" == "--no-reconfigure" ]] && NO_RECONFIGURE=1

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_BANCO="${DB_BANCO:-gwos}"
DB_USUARIO="${DB_USUARIO:-gwos}"
DB_SENHA="${DB_SENHA:-}"

SQUID_DIR="/etc/squid/conf.d"
mkdir -p "$SQUID_DIR"

mysql_q() {
    mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
        --batch --skip-column-names -e "$1"
}

dedup_dominios() {
    awk '
    {
        dom = $0
        redundante = 0
        for (i in aceitos) {
            sufixo = aceitos[i]
            if (length(dom) > length(sufixo) &&
                substr(dom, length(dom) - length(sufixo) + 1) == sufixo) {
                redundante = 1
                break
            }
        }
        if (!redundante) {
            aceitos[length(aceitos) + 1] = dom
            print dom
        }
    }'
}

# Whitelist: domínios com prefixo "." para cobrir subdomínios no Squid
# Ex: gov.br → .gov.br (cobre receita.gov.br, www.gov.br, etc.)
# As listas sao montadas em temporarios e so entao substituem as de producao.
# O redirecionamento direto truncava o arquivo ANTES de a consulta rodar: um
# banco fora do ar, uma senha trocada ou a tabela travada deixavam a whitelist
# VAZIA — e o SSL Bump voltava a interceptar justamente os sites de governo
# que ela existe para proteger. O erro so apareceria no navegador do usuario.
TMP_W="$(mktemp)"; TMP_B="$(mktemp)"
trap 'rm -f "$TMP_W" "$TMP_B"' EXIT

if ! mysql_q "SELECT CONCAT('.', dominio) FROM dominios
              WHERE tipo='whitelist' AND ativo=1
              ORDER BY LENGTH(dominio), dominio" | dedup_dominios > "$TMP_W"; then
    echo "ERRO: consulta da whitelist falhou — listas NAO alteradas." >&2
    exit 1
fi

# Blacklist: mesma lógica
if ! mysql_q "SELECT CONCAT('.', dominio) FROM dominios
              WHERE tipo='blacklist' AND ativo=1
              ORDER BY LENGTH(dominio), dominio" | dedup_dominios > "$TMP_B"; then
    echo "ERRO: consulta da blacklist falhou — listas NAO alteradas." >&2
    exit 1
fi

# As duas consultas passaram: so agora os arquivos de producao mudam, para uma
# falha na segunda nao deixar as listas em estados de epocas diferentes.
install -m 644 "$TMP_W" "$SQUID_DIR/gwos_whitelist.txt"
install -m 644 "$TMP_B" "$SQUID_DIR/gwos_blacklist.txt"

# Recarrega Squid (parse + reconfigure) — apenas se não for --no-reconfigure
if [ "$NO_RECONFIGURE" -eq 0 ]; then
    squid -k parse && squid -k reconfigure
fi

echo "Listas de domínios Squid atualizadas com sucesso."
