#!/bin/bash
# ============================================================================
# GWOS — Módulo 10-banco-mariadb: banco de dados
# ============================================================================
# Instala o MariaDB, cria o banco 'gwos', o usuário da aplicação e carrega o
# schema. Guarda as credenciais em /etc/gwos/db.conf (0600) — é daí que os
# demais módulos e scripts descobrem como falar com o banco.
#
# Sozinho: um MariaDB com o schema do GWOS, sem painel e sem serviços de rede.
# Junto:   é a fonte de grupos de IPs, domínios, horários e NAT que alimenta
#          o Squid, o BIND9 (RPZ) e o nftables.
#
# Uso:
#   bash instalar.sh              # preserva o banco se ele já existir
#   bash instalar.sh --recriar    # APAGA e recria o banco do zero
# ============================================================================

set -euo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || {
    echo "ERRO: comum/lib.sh não encontrado. Copie a pasta 'comum/' junto com o módulo."
    exit 1; }

RECRIAR=0
[ "${1:-}" = "--recriar" ] && RECRIAR=1

iniciar_modulo "10-banco-mariadb"

REPO="$(raiz_projeto || true)"
SCHEMA=""
for c in "${MOD_DIR}/schema.sql" "${REPO:-}/database/schema.sql"; do
    [ -n "$c" ] && [ -f "$c" ] && { SCHEMA="$c"; break; }
done
[ -n "$SCHEMA" ] || erro "schema.sql não encontrado — este módulo precisa do repositório GWOS completo (database/schema.sql)."

# ---------------------------------------------------------------------------
# Pacote e serviço
# ---------------------------------------------------------------------------
instalar_pacotes mariadb-server openssl
svc_ativar mariadb
ok "MariaDB instalado e ativo."

# ---------------------------------------------------------------------------
# Banco, usuário e schema
# ---------------------------------------------------------------------------
BANCO_EXISTE=0
mysql -e "USE gwos" 2>/dev/null && BANCO_EXISTE=1

if [ "$BANCO_EXISTE" = "1" ] && [ "$RECRIAR" = "0" ]; then
    aviso "Banco 'gwos' já existe — dados preservados (use --recriar para zerar)."
    if [ -f "$GWOS_DB_CONF" ]; then
        # shellcheck disable=SC1090
        . "$GWOS_DB_CONF"
        DB_SENHA_NOVA="$DB_SENHA"
    else
        # Sem credenciais salvas: gera uma nova senha para o usuário existente
        DB_SENHA_NOVA=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24)
        mysql -e "ALTER USER 'gwos'@'localhost' IDENTIFIED BY '${DB_SENHA_NOVA}';" 2>/dev/null \
            || mysql -e "CREATE USER 'gwos'@'localhost' IDENTIFIED BY '${DB_SENHA_NOVA}';"
        mysql -e "GRANT ALL PRIVILEGES ON gwos.* TO 'gwos'@'localhost'; FLUSH PRIVILEGES;"
        aviso "Senha do usuário 'gwos' regerada (não havia ${GWOS_DB_CONF})."
    fi
else
    [ "$BANCO_EXISTE" = "1" ] && aviso "--recriar: o banco 'gwos' será APAGADO."
    DB_SENHA_NOVA=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24)
    mysql -e "DROP DATABASE IF EXISTS gwos;"
    mysql -e "CREATE DATABASE gwos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "DROP USER IF EXISTS 'gwos'@'localhost';"
    mysql -e "CREATE USER 'gwos'@'localhost' IDENTIFIED BY '${DB_SENHA_NOVA}';"
    mysql -e "GRANT ALL PRIVILEGES ON gwos.* TO 'gwos'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
    mysql gwos < "$SCHEMA"
    ok "Banco 'gwos' criado a partir de $(basename "$SCHEMA")."

fi

# Registra somente migrações concluídas. Na primeira atualização de uma
# instalação antiga, os arquivos idempotentes são reconciliados uma vez.
if [ -n "${REPO:-}" ] && [ -d "${REPO}/database/migrations" ]; then
    mysql gwos -e 'CREATE TABLE IF NOT EXISTS gwos_migrations (
        arquivo VARCHAR(150) NOT NULL PRIMARY KEY,
        aplicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB' || erro "Não foi possível criar o registro de migrações."
    for mig in "${REPO}/database/migrations/"*.sql; do
        [ -f "$mig" ] || continue
        nome_mig=$(basename "$mig")
        [[ "$nome_mig" =~ ^[0-9]+_[a-z0-9_]+\.sql$ ]] || erro "Nome de migração inválido: $nome_mig"
        aplicado=$(mysql -Nse "SELECT 1 FROM gwos_migrations WHERE arquivo='$nome_mig'" gwos)             || erro "Não foi possível consultar o registro de migrações."
        [ "$aplicado" = 1 ] && continue
        mysql gwos < "$mig" || erro "Falha na migração $nome_mig; veja o erro SQL acima."
        mysql gwos -e "INSERT INTO gwos_migrations (arquivo) VALUES ('$nome_mig')"             || erro "Não foi possível registrar $nome_mig."
        ok "Migração aplicada: $nome_mig"
    done
fi

DB_SENHA="$DB_SENHA_NOVA"

# ---------------------------------------------------------------------------
# Credenciais compartilhadas
# ---------------------------------------------------------------------------
cat > "$GWOS_DB_CONF" <<DBCONF
# /etc/gwos/db.conf — credenciais do banco GWOS (lido pelos módulos e scripts)
DB_HOST=127.0.0.1
DB_BANCO=gwos
DB_USUARIO=gwos
DB_SENHA=${DB_SENHA}
DBCONF
chmod 600 "$GWOS_DB_CONF"
ok "Credenciais gravadas em ${GWOS_DB_CONF}."

# .env do projeto — usado pelo painel e pelos scripts em scripts/
if [ -n "${REPO:-}" ]; then
    if [ -f "${REPO}/.env" ]; then
        backup_arquivo "${REPO}/.env"
        sed -i "s|^DB_SENHA=.*|DB_SENHA=${DB_SENHA}|" "${REPO}/.env"
    else
        cat > "${REPO}/.env" <<ENV
APP_URL=http://${IP_GATEWAY}
APP_DEBUG=false
DB_HOST=127.0.0.1
DB_BANCO=gwos
DB_USUARIO=gwos
DB_SENHA=${DB_SENHA}
ENV
    fi
    chown root:root "$REPO"
    chmod go-w "$REPO"
    chown root:www-data "${REPO}/.env"
    chmod 0640 "${REPO}/.env"
    ok "${REPO}/.env atualizado."
fi

# ---------------------------------------------------------------------------
# Sincroniza a tabela 'configuracoes' com o estado da máquina
# ---------------------------------------------------------------------------
mysql gwos -e "
    UPDATE configuracoes SET valor='${IFACE_WAN}'  WHERE chave='iface_wan';
    UPDATE configuracoes SET valor='${IFACE_LAN}'  WHERE chave='iface_lan';
    UPDATE configuracoes SET valor='${REDE_LAN}'   WHERE chave='rede_lan';
    UPDATE configuracoes SET valor='${IP_GATEWAY}' WHERE chave='ip_gateway';
    UPDATE configuracoes SET valor='${SQUID_PORTA:-3128}' WHERE chave='squid_porta';
" 2>/dev/null || aviso "Não foi possível sincronizar a tabela 'configuracoes'."
ok "Parâmetros de rede sincronizados com o banco."

# ---------------------------------------------------------------------------
# Não cria nem redefine contas do painel. O módulo 60 provisiona o primeiro
# administrador com uma senha aleatória de uso inicial.
# ---------------------------------------------------------------------------
rm -f /usr/local/sbin/gwos-senha-padrao

registrar_modulo banco-mariadb
integrar --silencioso

echo ""
ok "Módulo 10-banco-mariadb instalado."
echo -e "  Banco     : ${BOLD}gwos${NC}   Usuário: ${BOLD}gwos${NC}"
echo -e "  Credenciais: ${BOLD}${GWOS_DB_CONF}${NC}"
echo ""
