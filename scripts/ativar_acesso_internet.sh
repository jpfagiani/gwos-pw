#!/bin/bash
# ============================================================================
# GWOS — Ativa o acesso à internet com login num servidor JÁ instalado
# ============================================================================
#   sudo bash scripts/ativar_acesso_internet.sh
#
# Faz, nesta ordem (idempotente; pode rodar de novo):
#   1. aplica a migração 002 no banco (usuários, sessões, logs, grupo "isento")
#   2. cria /var/lib/gwos e instala o helper de sessão do Squid
#   3. instala o squid.conf novo (com backup) e as listas novas; se o Squid
#      reprovar a configuração, VOLTA o squid.conf anterior e aborta
#   4. gera o site HTTPS do portal (porta ACESSO_PORTA, padrão 8444)
#   5. regera listas/regras (isentos, whitelist com o webmail) e o redirecionamento
#   6. agenda o importador de acessos (cron, a cada minuto)
#
# Antes de usar: confira o SMTP em Painel > Configurações (chaves smtp_*) e
# teste em Painel > Acesso com login > "Enviar e-mail de teste".
# ============================================================================
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"

V='\033[0;32m'; A='\033[1;33m'; R='\033[0;31m'; N='\033[0m'
ok()   { echo -e "${V}[OK]${N} $1"; }
info() { echo -e "${A}[..]${N} $1"; }
erro() { echo -e "${R}[ERRO]${N} $1" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || erro "Execute como root:  sudo bash scripts/ativar_acesso_internet.sh"
[ -f "$RAIZ/.env" ] || erro "$RAIZ/.env não encontrado — o painel (módulo 60) precisa estar instalado."
command -v mysql >/dev/null  || erro "mysql (cliente) não encontrado — o módulo 10-banco-mariadb precisa estar instalado."
command -v php   >/dev/null  || erro "php não encontrado."
command -v perl  >/dev/null  || erro "perl não encontrado (o helper do Squid usa perl)."

set -a; . "$RAIZ/.env"; set +a
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_BANCO="${DB_BANCO:-gwos}"; DB_USUARIO="${DB_USUARIO:-gwos}"; DB_SENHA="${DB_SENHA:-}"
MYSQL=(mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO")

# 1. Banco ------------------------------------------------------------------
info "Aplicando a migração do banco..."
for mig in "$RAIZ"/database/migrations/002_acesso_internet.sql "$RAIZ"/database/migrations/004_operacional_v2.sql "$RAIZ"/database/migrations/005_trafego_direcional.sql "$RAIZ"/database/migrations/006_historico_sessoes.sql "$RAIZ"/database/migrations/007_importacao_squid_idempotente.sql; do
    [ -f "$mig" ] || continue
    "${MYSQL[@]}" < "$mig" || erro "Falha ao aplicar $(basename "$mig")."
done
ok "Banco e migrations do portal/monitoramento atualizados."

# 2. Diretório de sessões + helper -----------------------------------------
mkdir -p /var/lib/gwos/sessions
chown root:root /var/lib/gwos && chmod 0755 /var/lib/gwos
if getent group proxy >/dev/null; then chown root:proxy /var/lib/gwos/sessions; else chown root:www-data /var/lib/gwos/sessions; fi
chmod 2770 /var/lib/gwos/sessions
if [ -f /var/lib/gwos/sessoes.txt ] && [ ! -f /var/lib/gwos/sessions/sessoes.txt ]; then
    install -o www-data -g "$(stat -c %G /var/lib/gwos/sessions)" -m 0640 /var/lib/gwos/sessoes.txt /var/lib/gwos/sessions/sessoes.txt
    rm -f /var/lib/gwos/sessoes.txt
fi
[ -f /var/lib/gwos/sessions/sessoes.txt ] || install -o www-data -g "$(stat -c %G /var/lib/gwos/sessions)" -m 0640 /dev/null /var/lib/gwos/sessions/sessoes.txt
if getent group proxy >/dev/null; then chown www-data:proxy /var/lib/gwos/sessions/sessoes.txt; fi
chmod 0640 /var/lib/gwos/sessions/sessoes.txt
install -m 755 "$RAIZ/modulos/50-proxy-squid/helpers/gwos-sessao.pl" /usr/local/sbin/gwos-squid-sessao
perl -c /usr/local/sbin/gwos-squid-sessao >/dev/null 2>&1 || erro "Helper do Squid com erro de sintaxe."
ok "Helper do Squid instalado (/usr/local/sbin/gwos-squid-sessao)."

# 3. Squid --------------------------------------------------------------------
if [ -f /etc/squid/squid.conf ]; then
    mkdir -p /etc/squid/conf.d
    [ -f /etc/squid/conf.d/gwos_ips_isentos.txt ] || : > /etc/squid/conf.d/gwos_ips_isentos.txt
    [ -f /etc/squid/conf.d/gwos_portal.conf ] || \
        printf '# Gerado por gwos-integrar.\ndeny_info ERR_ACCESS_DENIED all\n' > /etc/squid/conf.d/gwos_portal.conf

    BAK="/etc/squid/squid.conf.bak.acesso.$(date +%Y%m%d%H%M%S)"
    cp -a /etc/squid/squid.conf "$BAK"
    install -m 644 "$RAIZ/modulos/50-proxy-squid/config/squid.conf" /etc/squid/squid.conf
    if squid -k parse >/dev/null 2>&1; then
        ok "squid.conf novo validado (backup: $BAK)."
    else
        squid -k parse 2>&1 | tail -5 || true
        cp -a "$BAK" /etc/squid/squid.conf
        erro "O Squid reprovou o squid.conf novo — configuração anterior restaurada. Nada foi alterado."
    fi
else
    erro "Squid não instalado (/etc/squid/squid.conf ausente)."
fi

# 4. Portal HTTPS -------------------------------------------------------------
info "Gerando o portal de acesso (nginx + certificado)..."
bash "$RAIZ/modulos/60-painel-web/gerar-portal-acesso.sh"
install -m 755 "$RAIZ/modulos/60-painel-web/gerar-portal-acesso.sh" /usr/local/sbin/gwos-gerar-portal-acesso

# 5. Listas, regras e redirecionamento ----------------------------------------
info "Regerando listas e regras..."
bash "$RAIZ/scripts/gerar_squid_dominios.sh" --no-reconfigure >/dev/null 2>&1 || info "(whitelist não regerada — use Domínios > Aplicar)"
bash "$RAIZ/scripts/aplicar_nftables.sh" >/dev/null 2>&1 || info "(nftables não reaplicado — use Grupos > Aplicar nftables)"
if [ -x /usr/local/sbin/gwos-integrar ]; then
    /usr/local/sbin/gwos-integrar >/dev/null 2>&1 || info "(gwos-integrar retornou erro — veja: gwos diag)"
fi
if squid -k parse >/dev/null 2>&1; then
    squid -k reconfigure 2>/dev/null || true
    ok "Squid recarregado."
else
    erro "squid -k parse reprovou após regerar — veja: squid -k parse"
fi

# 6. Cron ---------------------------------------------------------------------
LINHA="* * * * * root /usr/bin/php ${RAIZ}/scripts/importar_acessos_squid.php >> /var/log/gwos_acessos.log 2>&1"
touch /etc/cron.d/gwos
grep -qF "importar_acessos_squid.php" /etc/cron.d/gwos || echo "$LINHA" >> /etc/cron.d/gwos
chmod 644 /etc/cron.d/gwos
ok "Importador de acessos agendado (a cada minuto)."

IP="$(. /etc/gwos/gwos.conf 2>/dev/null; echo "${IP_GATEWAY:-?}")"
echo ""
ok "Acesso com login ativado."
echo "   Portal      : https://${IP}:${ACESSO_PORTA:-8444}/acesso"
echo "   Administração: painel > Acesso com login  (usuários, sessões, registro de acessos)"
echo "   Isentar um PC: painel > Grupos > novo grupo tipo \"Isento\" > adicionar o IP"
echo "   SMTP        : painel > Configurações (smtp_*) e teste o envio"
