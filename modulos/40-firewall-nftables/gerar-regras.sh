#!/bin/bash
# ============================================================================
# GWOS — Gera e aplica /etc/nftables.conf
# Instalado como /usr/local/sbin/gwos-gerar-nftables
# ============================================================================
# Monta o conjunto de regras de acordo com o que existe na máquina:
#
#   Squid instalado  → redireciona 80/443 da LAN para o proxy transparente
#   BIND9 instalado  → força todo DNS da LAN a passar pelo resolver local
#   Banco + painel   → delega para scripts/aplicar_nftables.sh, que acrescenta
#                      grupos de IPs e NAT 1:1 vindos do banco
#
# Sem banco, gera o conjunto base — o gateway roteia, mascara e filtra.
# Toda regra é validada com 'nft -c' antes de substituir a atual.
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

# ---------------------------------------------------------------------------
# Com banco + painel, quem manda é o gerador completo do projeto
# ---------------------------------------------------------------------------
if [ "${GWOS_NFT_BASE:-0}" != "1" ] && tem_banco && tem_painel; then
    REPO="$(raiz_projeto || true)"
    if [ -n "$REPO" ] && [ -f "${REPO}/scripts/aplicar_nftables.sh" ]; then
        exec bash "${REPO}/scripts/aplicar_nftables.sh"
    fi
fi

# ---------------------------------------------------------------------------
# Conjunto base — sem banco
# ---------------------------------------------------------------------------
TMP="$(mktemp /tmp/gwos_nft.XXXXXX.conf)"
trap 'rm -f "$TMP"' EXIT

# Redes diretamente conectadas na LAN (IP principal + aliases). Tudo que é
# local é ROTEADO, nunca interceptado — cobre faixas fora do RFC 1918
# (ex.: 172.14.29.0/24) sem depender de nenhuma configuração extra.
REDES_LOCAIS=$(
    { ip -4 route show dev "$IFACE_LAN" scope link 2>/dev/null | awk '{print $1}'
      redes_internas | tr ' ' '\n'
    } | grep -E '^[0-9.]+/[0-9]+$' | sort -u
)
RETORNO_LOCAL=""
for _rede in $REDES_LOCAIS; do
    RETORNO_LOCAL+="        iif \"${IFACE_LAN}\" ip daddr ${_rede} return"$'\n'
done
[ -n "$RETORNO_LOCAL" ] || RETORNO_LOCAL="        # nenhuma rede local detectada na LAN"$'\n'

# DNS local — só faz sentido se o BIND9 existir
if tem_bind9; then
    REGRA_DNS="        # Força o DNS da LAN pelo resolver local (impede bypass da RPZ)
        iif \"${IFACE_LAN}\" udp dport 53 redirect
        iif \"${IFACE_LAN}\" tcp dport 53 redirect"
else
    REGRA_DNS="        # Módulo 20-dns-bind9 ausente — DNS da LAN sai direto"
fi

# Proxy transparente — só faz sentido se o Squid existir
if tem_squid; then
    REGRA_PROXY="        # Proxy transparente — apenas tráfego saindo para a internet
        iif \"${IFACE_LAN}\" ip saddr != @ip_bypass_proxy tcp dport 80 redirect to :${SQUID_PORTA}"
    if tem_ssl_bump; then
        REGRA_PROXY+="
        iif \"${IFACE_LAN}\" ip saddr != @ip_bypass_proxy tcp dport 443 redirect to :${SQUID_PORTA_SSL}"
    else
        REGRA_PROXY+="
        # HTTPS não interceptado: Squid sem SSL Bump (certgen ausente)"
    fi
else
    REGRA_PROXY="        # Módulo 50-proxy-squid ausente — HTTP/HTTPS saem direto"
fi

cat > "$TMP" <<NFT
#!/usr/sbin/nft -f
# ═══════════════════════════════════════════════════════════════
# GWOS — regras base geradas por gwos-gerar-nftables
# em $(date '+%Y-%m-%d %H:%M:%S'). NÃO editar à mão: rode
# 'gwos-gerar-nftables' (ou 'gwos reload nftables') para regerar.
# ═══════════════════════════════════════════════════════════════

flush ruleset

table ip gwos_nat {

    # IPs que passam direto, sem proxy (preenchido pelo painel)
    set ip_bypass_proxy {
        type ipv4_addr
        flags interval
        auto-merge
    }

    chain prerouting {
        type nat hook prerouting priority dstnat;

${REGRA_DNS}

        # Redes locais do gateway (IP principal + aliases): roteia, não intercepta
${RETORNO_LOCAL}
        # Demais redes internas alcançáveis por rota estática (faixas RFC 1918)
        iif "${IFACE_LAN}" ip daddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 } return

${REGRA_PROXY}
    }

    chain postrouting {
        type nat hook postrouting priority srcnat;
        oif "${IFACE_WAN}" masquerade
    }
}

table inet gwos {

    set ip_bloqueados {
        type ipv4_addr
        flags interval
        auto-merge
    }

    set ip_parciais {
        type ipv4_addr
        flags interval
        auto-merge
    }

    chain input {
        type filter hook input priority filter; policy drop;

        ct state { established, related } accept
        ct state invalid drop
        iif lo accept

        iif "${IFACE_LAN}" ip protocol icmp accept
        iif "${IFACE_LAN}" ip saddr @ip_bloqueados drop

        # Todo tráfego da LAN destinado ao gateway
        # (proxy, DNS, NTP, painel, SSH)
        iif "${IFACE_LAN}" accept

        iif "${IFACE_WAN}" icmp type { echo-reply, destination-unreachable, time-exceeded, parameter-problem } accept
        iif "${IFACE_WAN}" ct state new tcp dport 22 accept comment "SSH"
        iif "${IFACE_WAN}" ct state new drop
    }

    chain forward {
        type filter hook forward priority filter; policy drop;

        ct state { established, related } accept
        ct state invalid drop

        iif "${IFACE_LAN}" ip saddr @ip_bloqueados drop
        ip protocol icmp accept

        iif "${IFACE_LAN}" oif "${IFACE_LAN}" accept
        iif "${IFACE_WAN}" oif "${IFACE_LAN}" accept
        iif "${IFACE_LAN}" oif "${IFACE_WAN}" accept
    }
}
NFT

nft -c -f "$TMP" || {
    echo "[ERRO] Regras inválidas — /etc/nftables.conf NÃO foi alterado." >&2
    exit 1
}

install -m 755 "$TMP" /etc/nftables.conf
nft -f /etc/nftables.conf

echo "[OK] nftables aplicado (proxy: $(tem_squid && echo sim || echo nao), DNS local: $(tem_bind9 && echo sim || echo nao))."
