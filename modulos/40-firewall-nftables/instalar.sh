#!/bin/bash
# ============================================================================
# GWOS — Módulo 40-firewall-nftables: firewall e NAT
# ============================================================================
# Instala o nftables e gera o conjunto de regras do gateway: filtro de INPUT
# e FORWARD, masquerade para a WAN e os redirecionamentos do proxy e do DNS.
#
# Sozinho: gateway com NAT e firewall — a LAN navega direto, sem proxy.
# Junto:   passa a redirecionar 80/443 para o Squid (módulo 50) e o DNS para o
#          BIND9 (módulo 20); com painel + banco, os grupos de IPs e o NAT 1:1
#          entram nas regras automaticamente.
#
# As regras são sempre validadas com 'nft -c' antes de entrar em vigor.
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

iniciar_modulo "40-firewall-nftables"

instalar_pacotes nftables

# iptables-nft/ufw brigariam pelo mesmo ruleset
if systemctl is-enabled ufw &>/dev/null; then
    systemctl disable --now ufw 2>/dev/null || true
    aviso "ufw desativado — o GWOS gerencia o nftables diretamente."
fi

# Encaminhamento de pacotes: o módulo 00-base já faz isso, mas o firewall
# sozinho também precisa dele para o gateway funcionar.
if [ ! -f /etc/sysctl.d/90-gwos-base.conf ]; then
    cat > /etc/sysctl.d/91-gwos-firewall.conf <<SYSCTL
# GWOS — encaminhamento de pacotes (módulo 40-firewall-nftables)
net.ipv4.ip_forward = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.tcp_syncookies = 1
SYSCTL
    sysctl -p /etc/sysctl.d/91-gwos-firewall.conf >/dev/null
    ok "Encaminhamento de pacotes ativado."
fi

install -m 755 "${MOD_DIR}/gerar-regras.sh" /usr/local/sbin/gwos-gerar-nftables

backup_arquivo /etc/nftables.conf

# Na primeira geração ignoramos o banco: o painel ainda pode não existir e as
# tabelas de grupos/NAT estarem vazias. O gwos-integrar refaz depois com dados.
GWOS_NFT_BASE=1 /usr/local/sbin/gwos-gerar-nftables

systemctl enable nftables >/dev/null 2>&1 || systemctl enable nftables
ok "nftables habilitado no boot."

registrar_modulo firewall-nftables
integrar --silencioso

echo ""
ok "Módulo 40-firewall-nftables instalado."
echo -e "  Ver regras ativas : ${BOLD}nft list ruleset${NC}"
echo -e "  Regerar           : ${BOLD}gwos-gerar-nftables${NC}"
tem_squid || echo -e "  ${YELLOW}Sem o módulo 50-proxy-squid o tráfego HTTP/HTTPS sai sem filtro.${NC}"
tem_bind9 || echo -e "  ${YELLOW}Sem o módulo 20-dns-bind9 o DNS da LAN sai sem filtro.${NC}"
echo ""
