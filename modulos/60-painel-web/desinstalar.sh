#!/bin/bash
# GWOS — Módulo 60-painel-web: desinstalação

set -euo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

exigir_root
titulo "══ Removendo o módulo 60-painel-web ══"

aviso "O painel e o comando 'gwos' deixam de existir. O banco NÃO é apagado."
confirmar_uma_vez "Continuar?" || { echo "Cancelado."; exit 0; }

REPO="$(raiz_projeto || true)"

# Remove SÓ o site do painel. Outros vhosts na mesma máquina (o portal de
# sistemas, um portal do Samba) continuam servidos — por isso a configuração
# sai antes de qualquer decisão sobre o serviço.
# Inclui o nome antigo 'gwos', de instalações feitas antes da convenção
# <servidor>-portal.
for _site in portal-gateway gwos-portal gwos; do
    rm -f "/etc/nginx/sites-available/${_site}" "/etc/nginx/sites-enabled/${_site}"
done
ok "Site do painel removido do nginx."

# O nginx só é parado se não houver mais nada sendo servido por ele.
OUTROS_SITES=$(nginx_outros_sites)
if [ "${OUTROS_SITES:-0}" -gt 0 ]; then
    if nginx -t >/dev/null 2>&1; then
        systemctl reload nginx 2>/dev/null || true
        aviso "nginx mantido em execução — ainda serve ${OUTROS_SITES} outro(s) site(s)."
    else
        falha "nginx -t reprovou após a remoção do site — verifique antes de recarregar."
    fi
    aviso "php8.4-fpm mantido — outros sites podem depender dele."
else
    svc_parar nginx
    svc_parar php8.4-fpm
    ok "nginx e php8.4-fpm parados (nenhum outro site nesta máquina)."
fi

rm -f /etc/sudoers.d/gwos
rm -f /etc/cron.d/gwos
rm -f /usr/local/bin/gwos
rm -f /usr/local/sbin/gwos-gerar-nginx /usr/local/sbin/gwos-gerar-portal-acesso
rm -f /etc/nginx/sites-enabled/portal-acesso /etc/nginx/sites-available/portal-acesso /etc/gwos/acesso-internet.ativo
rm -rf /etc/gwos/painel
rm -f /etc/gwos/painel-usuarios.json
ok "sudoers, cron e comando 'gwos' removidos."

if [ -n "$REPO" ]; then
    rm -f "${REPO}/public/gwos-ca.crt" "${REPO}/.env"
    ok "Arquivos do painel no projeto removidos (.env, CA publicada)."
fi

# Nginx e PHP costumam ser compartilhados — um portal, um sistema interno na
# mesma máquina. O remover_pacotes detecta e simplesmente não oferece.
PHP_VER="$(detectar_php_instalado || echo '')"
PKGS_PHP=""
if [ -n "$PHP_VER" ]; then
    for sufixo in "" -fpm -mysql -mbstring -curl -zip -xml -intl; do
        PKGS_PHP="${PKGS_PHP} php${PHP_VER}${sufixo}"
    done
fi
# shellcheck disable=SC2086
remover_pacotes nginx $PKGS_PHP

# Backups são dados seus, não resíduo de instalação — apagar sem volta seria
# a única ação irreversível deste script, então ficam.
rm -rf /var/lib/gwos/tmp 2>/dev/null || true
if [ -d /var/lib/gwos/backups ] && [ -n "$(ls -A /var/lib/gwos/backups 2>/dev/null)" ]; then
    QTD=$(find /var/lib/gwos/backups -type f | wc -l)
    aviso "MANTIDOS ${QTD} backup(s) em /var/lib/gwos/backups"
    echo  "      Para apagar também: rm -rf /var/lib/gwos"
else
    rm -rf /var/lib/gwos
    ok "Diretório de backups removido (estava vazio)."
fi

desregistrar_modulo painel-web
integrar --silencioso
ok "Módulo 60-painel-web removido."
