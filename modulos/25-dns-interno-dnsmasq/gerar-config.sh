#!/bin/bash
# ============================================================================
# GWOS — Gera /etc/dnsmasq.d/gwos.conf a partir do modelo do módulo
# Instalado como /usr/local/sbin/gwos-gerar-dnsmasq
# ============================================================================
# Lê o domínio interno e a porta de /etc/gwos/gwos.conf, aplica no modelo e
# recarrega o serviço. Chamado pelo instalador do módulo e por gwos-integrar
# (para que trocas de domínio/porta se propaguem sozinhas).
# ============================================================================

set -euo pipefail

for _l in "/etc/gwos/lib.sh" \
          "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../comum/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: lib.sh não encontrado."; exit 1; }

exigir_root
garantir_conf

MODELO=""
for c in /etc/gwos/modelos/dnsmasq-gwos.conf.modelo \
         "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/config/dnsmasq-gwos.conf.modelo"; do
    [ -f "$c" ] && { MODELO="$c"; break; }
done
[ -n "$MODELO" ] || { echo "ERRO: modelo dnsmasq-gwos.conf.modelo não encontrado."; exit 1; }

mkdir -p /etc/dnsmasq.d
sed -e "s|@DOMINIO_LOCAL@|${DOMINIO_LOCAL}|g" \
    -e "s|@DNSMASQ_PORTA@|${DNSMASQ_PORTA}|g" \
    "$MODELO" > /etc/dnsmasq.d/gwos.conf
chmod 644 /etc/dnsmasq.d/gwos.conf

if dnsmasq --test --conf-file=/etc/dnsmasq.d/gwos.conf >/dev/null 2>&1; then
    systemctl is-active --quiet gwos-dnsmasq && systemctl restart gwos-dnsmasq
    echo "[OK] /etc/dnsmasq.d/gwos.conf gerado (${DOMINIO_LOCAL} em 127.0.0.1:${DNSMASQ_PORTA})."
else
    echo "[ERRO] dnsmasq --test reprovou a configuração gerada." >&2
    dnsmasq --test --conf-file=/etc/dnsmasq.d/gwos.conf || true
    exit 1
fi
