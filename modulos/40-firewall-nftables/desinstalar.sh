#!/bin/bash
# GWOS — Módulo 40-firewall-nftables: desinstalação

set -euo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

exigir_root
titulo "══ Removendo o módulo 40-firewall-nftables ══"

aviso "O gateway deixa de mascarar (NAT) e de filtrar — a LAN perde a internet"
aviso "e a máquina fica sem firewall."
confirmar_uma_vez "Continuar?" || { echo "Cancelado."; exit 0; }

nft flush ruleset 2>/dev/null || true
svc_parar nftables
rm -f /etc/nftables.conf
rm -f /etc/sysctl.d/91-gwos-firewall.conf
rm -f /usr/local/sbin/gwos-gerar-nftables
ok "Regras e gerador removidos."

remover_pacotes nftables

desregistrar_modulo firewall-nftables
integrar --silencioso
ok "Módulo 40-firewall-nftables removido."
