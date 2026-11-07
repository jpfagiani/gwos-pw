#!/bin/bash
# GWOS — Módulo 00-base: desinstalação
# Restaura o /etc/network/interfaces anterior (backup mais recente) e remove
# o estado compartilhado. NÃO remove pacotes — ifupdown/iproute2 são do sistema.

set -euo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

exigir_root
titulo "══ Removendo o módulo 00-base ══"

aviso "Isto pode derrubar a rede desta máquina."
confirmar_uma_vez "Continuar?" || { echo "Cancelado."; exit 0; }

BACKUP=$(ls -1t /etc/network/interfaces.bak.* 2>/dev/null | head -1 || true)
if [ -n "$BACKUP" ]; then
    cp -a "$BACKUP" /etc/network/interfaces
    ok "/etc/network/interfaces restaurado de ${BACKUP}."
    aviso "Reinicie a rede para valer: systemctl restart networking (ou reinicie a máquina)."
else
    aviso "Nenhum backup de /etc/network/interfaces encontrado — arquivo mantido."
fi

rm -f /etc/sysctl.d/90-gwos-base.conf
rm -f /etc/systemd/network/70-gwos-*.link
sysctl -w net.ipv4.ip_forward=0 >/dev/null 2>&1 || true
ok "sysctl e fixação de nomes de interface removidos."

desregistrar_modulo base

if [ -z "$(modulos_instalados)" ]; then
    rm -f "$GWOS_CONF" /etc/gwos/lib.sh /usr/local/sbin/gwos-integrar
    rmdir "$GWOS_MODULOS" "$GWOS_ETC" 2>/dev/null || true
    ok "Estado compartilhado removido (nenhum outro módulo instalado)."
else
    aviso "${GWOS_CONF} mantido — ainda há módulos GWOS instalados."
    integrar --silencioso
fi

ok "Módulo 00-base removido."
