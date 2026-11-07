#!/bin/bash
# GWOS — Módulo 40-firewall-nftables: verificação

set -uo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _l in "${MOD_DIR}/../comum/lib.sh" "/etc/gwos/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: comum/lib.sh não encontrado."; exit 1; }

carregar_conf
FALHAS=0
titulo "── Verificação: 40-firewall-nftables ──"

if [ "$(sysctl -n net.ipv4.ip_forward 2>/dev/null)" = "1" ]; then
    ok "ip_forward ativo."
else
    falha "ip_forward desativado — o gateway não roteia."; FALHAS=$((FALHAS+1))
fi

if nft list ruleset 2>/dev/null | grep -q 'table ip gwos_nat'; then
    ok "Tabela gwos_nat carregada."
else
    falha "Tabela gwos_nat ausente do ruleset ativo."; FALHAS=$((FALHAS+1))
fi

if nft list ruleset 2>/dev/null | grep -q 'table inet gwos'; then
    ok "Tabela de filtro gwos carregada."
else
    falha "Tabela inet gwos ausente do ruleset ativo."; FALHAS=$((FALHAS+1))
fi

if nft list ruleset 2>/dev/null | grep -q 'masquerade'; then
    ok "Masquerade ativo na saída ${IFACE_WAN:-WAN}."
else
    falha "Sem masquerade — a LAN não alcança a internet."; FALHAS=$((FALHAS+1))
fi

if [ -f /etc/nftables.conf ] && nft -c -f /etc/nftables.conf >/dev/null 2>&1; then
    ok "/etc/nftables.conf válido (sobrevive ao reboot)."
else
    falha "/etc/nftables.conf ausente ou inválido."; FALHAS=$((FALHAS+1))
fi

if systemctl is-enabled nftables &>/dev/null; then
    ok "Serviço nftables habilitado no boot."
else
    falha "nftables não habilitado no boot — as regras somem ao reiniciar."
    FALHAS=$((FALHAS+1))
fi

# Coerência com os outros módulos
REDIR=$(nft list ruleset 2>/dev/null | grep -c "redirect to :${SQUID_PORTA:-3128}") || REDIR=0
if tem_squid; then
    [ "$REDIR" -gt 0 ] && ok "Redirecionamento para o Squid presente." \
        || { falha "Squid instalado mas sem redirecionamento — rode gwos-integrar."; FALHAS=$((FALHAS+1)); }
else
    [ "$REDIR" -eq 0 ] && info "Sem Squid e sem redirecionamento (coerente)." \
        || { falha "Redireciona para o Squid, que não está instalado."; FALHAS=$((FALHAS+1)); }
fi

if tem_bind9; then
    nft list ruleset 2>/dev/null | grep -q 'dport 53 redirect' \
        && ok "DNS da LAN forçado para o resolver local." \
        || { falha "BIND9 instalado mas o DNS não é forçado — rode gwos-integrar."; FALHAS=$((FALHAS+1)); }
fi

echo ""
[ "$FALHAS" -eq 0 ] && ok "40-firewall-nftables OK." || falha "40-firewall-nftables com ${FALHAS} problema(s)."
exit $(( FALHAS > 0 ? 1 : 0 ))
