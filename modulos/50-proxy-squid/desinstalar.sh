#!/bin/bash
# GWOS — Módulo 50-proxy-squid: desinstalação

set -euo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

exigir_root
titulo "══ Removendo o módulo 50-proxy-squid ══"

aviso "A LAN passa a navegar sem filtro de conteúdo."
aviso "A CA do SSL Bump será apagada — os clientes precisarão de uma nova depois."
confirmar_uma_vez "Continuar?" || { echo "Cancelado."; exit 0; }

svc_parar squid
rm -f  /etc/squid/squid.conf
rm -rf /etc/squid/conf.d /etc/squid/ssl_cert
rm -rf /var/lib/squid/ssl_db
ok "Configuração, listas e CA removidas."

REPO="$(raiz_projeto || true)"
[ -n "$REPO" ] && rm -f "${REPO}/public/gwos-ca.crt"

remover_pacotes squid squid-openssl sarg

desregistrar_modulo proxy-squid

# Tira o redirecionamento 80/443 do firewall — senão a LAN fica sem internet
integrar --silencioso
ok "Firewall reajustado (sem redirecionamento para o proxy)."

ok "Módulo 50-proxy-squid removido."
