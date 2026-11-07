#!/bin/bash
# GWOS — Módulo 10-banco-mariadb: verificação

set -uo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

carregar_conf
FALHAS=0
titulo "── Verificação: 10-banco-mariadb ──"

if svc_ativo mariadb; then ok "Serviço mariadb ativo."
else falha "Serviço mariadb parado."; FALHAS=$((FALHAS+1)); fi

if [ -f "$GWOS_DB_CONF" ]; then
    ok "Credenciais presentes: ${GWOS_DB_CONF}"
    # shellcheck disable=SC1090
    . "$GWOS_DB_CONF"
    if mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" -e "SELECT 1" >/dev/null 2>&1; then
        ok "Conexão com o banco '${DB_BANCO}' como '${DB_USUARIO}' OK."
        TAB=$(mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
              -sNe "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_BANCO}'" 2>/dev/null)
        if [ "${TAB:-0}" -ge 10 ]; then ok "Schema carregado (${TAB} tabelas)."
        else falha "Schema incompleto (${TAB:-0} tabelas)."; FALHAS=$((FALHAS+1)); fi
        ADM=$(mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
              -sNe "SELECT COUNT(*) FROM admins" 2>/dev/null)
        ok "Administradores cadastrados: ${ADM:-0}"
    else
        falha "Não foi possível conectar com as credenciais salvas."; FALHAS=$((FALHAS+1))
    fi
else
    falha "Ausente: ${GWOS_DB_CONF}"; FALHAS=$((FALHAS+1))
fi

echo ""
[ "$FALHAS" -eq 0 ] && ok "10-banco-mariadb OK." || falha "10-banco-mariadb com ${FALHAS} problema(s)."
exit $(( FALHAS > 0 ? 1 : 0 ))
