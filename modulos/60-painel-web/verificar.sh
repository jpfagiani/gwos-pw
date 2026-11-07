#!/bin/bash
# GWOS — Módulo 60-painel-web: verificação

set -uo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

carregar_conf
FALHAS=0
titulo "── Verificação: 60-painel-web ──"

PHP_VER="$(detectar_php_instalado || echo '')"
[ -n "$PHP_VER" ] && ok "PHP instalado: ${PHP_VER}" || falha "Nenhum PHP-FPM encontrado em /etc/php."

for SVC in nginx ${PHP_VER:+php${PHP_VER}-fpm}; do
    if svc_ativo "$SVC"; then ok "Serviço ${SVC} ativo."
    else falha "Serviço ${SVC} parado."; FALHAS=$((FALHAS+1)); fi
done

if nginx -t >/dev/null 2>&1; then ok "nginx -t sem erros."
else falha "nginx -t com erros:"; nginx -t 2>&1 | tail -3; FALHAS=$((FALHAS+1)); fi

REPO="$(raiz_projeto || true)"
if [ -n "$REPO" ]; then
    ok "Projeto em ${REPO}"
    if [ -f "${REPO}/.env" ]; then
        ok ".env presente."
        [ "$(stat -c '%a' "${REPO}/.env")" = "600" ] \
            && ok "Permissões do .env corretas (600)." \
            || { falha ".env com permissão $(stat -c '%a' "${REPO}/.env") — deveria ser 600."; FALHAS=$((FALHAS+1)); }
    else
        falha ".env ausente — o painel não conecta no banco."; FALHAS=$((FALHAS+1))
    fi
else
    falha "Repositório do projeto não encontrado."; FALHAS=$((FALHAS+1))
fi

if [ -f /etc/sudoers.d/gwos ] && visudo -c -f /etc/sudoers.d/gwos >/dev/null 2>&1; then
    ok "sudoers do GWOS válido."
else
    falha "sudoers ausente ou inválido — o painel não consegue aplicar regras."
    FALHAS=$((FALHAS+1))
fi

[ -x /usr/local/bin/gwos ] && ok "Comando 'gwos' instalado." \
    || { falha "Comando 'gwos' ausente."; FALHAS=$((FALHAS+1)); }

[ -f /etc/cron.d/gwos ] && ok "Tarefas de cron instaladas." \
    || { falha "/etc/cron.d/gwos ausente — sem backup nem parser de logs."; FALHAS=$((FALHAS+1)); }

if id -nG www-data 2>/dev/null | grep -qw proxy; then
    ok "www-data no grupo proxy (lê o log do Squid)."
else
    tem_squid && { falha "www-data fora do grupo proxy — dashboard sem dados de acesso."; FALHAS=$((FALHAS+1)); }
fi

if command -v curl >/dev/null 2>&1; then
    PORTA="${PAINEL_PORTA:-80}"
    COD=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:${PORTA}/" 2>/dev/null)
    case "$COD" in
        200|302|301) ok "Painel respondendo na porta ${PORTA} (HTTP ${COD})." ;;
        *) falha "Painel não respondeu na porta ${PORTA} (HTTP ${COD:-sem resposta})."; FALHAS=$((FALHAS+1)) ;;
    esac
fi

# ── A porta 80 está sendo desperdiçada pelo placeholder do nginx? ──────────
if [ -e /etc/nginx/sites-enabled/default ]    && grep -q 'index.nginx-debian.html' /etc/nginx/sites-available/default 2>/dev/null    && [ "${PAINEL_PORTA:-80}" != "80" ]; then
    aviso "O site 'default' do nginx ocupa a porta 80 sem servir nada."
    echo  "      Impede outras aplicações (portal de sistemas) de subir ali:"
    echo  "        rm -f /etc/nginx/sites-enabled/default && systemctl reload nginx"
fi

# ── Em que modo o painel está, e de onde ele lê os usuários ────────────────
# É a causa mais provável de "e-mail ou senha incorretos": o painel achar que
# está em modo leve, procurar um arquivo de usuários que não existe e recusar
# todo login sem nunca consultar o banco.
if tem_banco; then
    ok "Módulo de banco instalado — painel em modo completo."
    if [ -n "$REPO" ] && grep -q '^DB_SENHA=.\+' "${REPO}/.env" 2>/dev/null; then
        ok "O .env tem credenciais do banco (é por elas que o painel conecta)."
        if [ -f "$GWOS_DB_CONF" ]; then
            # shellcheck disable=SC1090
            . "$GWOS_DB_CONF"
            N=$(mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO"                 -sNe "SELECT COUNT(*) FROM admins WHERE ativo=1" 2>/dev/null) || N=""
            if [ -n "$N" ]; then
                ok "Administradores ativos na tabela: ${N}"
                PADRAO=$(mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO"                     -sNe "SELECT COUNT(*) FROM admins WHERE senha='\$2y\$12\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'" 2>/dev/null) || PADRAO=0
                if [ "${PADRAO:-0}" -gt 0 ]; then
                    falha "Foi detectada uma credencial legada de fábrica."
                    echo  "       Reexecute o módulo: bash modulos/60-painel-web/instalar.sh"
                    FALHAS=$((FALHAS+1))
                fi
            fi
        fi
    else
        falha "O .env não tem DB_SENHA — o painel cai em modo leve e recusa todo login."
        echo  "       Reexecute: bash modulos/60-painel-web/instalar.sh"
        FALHAS=$((FALHAS+1))
    fi
else
    info "Painel em modo leve — usuários em /etc/gwos/painel/usuarios.json"
    if [ -f /etc/gwos/painel/usuarios.json ] || [ -f /etc/gwos/painel-usuarios.json ]; then
        ok "Arquivo de usuários presente."
    else
        falha "Sem banco e SEM arquivo de usuários — nenhum login é possível."
        echo  "       Reexecute: bash modulos/60-painel-web/instalar.sh"
        FALHAS=$((FALHAS+1))
    fi
fi

echo ""
[ "$FALHAS" -eq 0 ] && ok "60-painel-web OK." || falha "60-painel-web com ${FALHAS} problema(s)."
exit $(( FALHAS > 0 ? 1 : 0 ))
