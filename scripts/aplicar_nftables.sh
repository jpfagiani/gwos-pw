#!/bin/bash
# Regenera e aplica as regras nftables + arquivos de IPs do Squid.
# Executado pelo painel via sudo — não editar manualmente.

set -euo pipefail

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$GWOS_DIR/scripts/ler_env.sh"
gwos_ler_env "$GWOS_DIR/.env"

# Estado compartilhado dos módulos (modulos/comum/lib.sh) — serve de reserva
# quando o banco não responde e diz quais servidores existem nesta máquina.
if [ -f /etc/gwos/gwos.conf ]; then set -a; . /etc/gwos/gwos.conf; set +a; fi

# Um módulo conta como presente pelo registro em /etc/gwos/modulos.d ou pelo
# binário instalado — assim as regras nunca redirecionam para um serviço morto.
_tem_modulo() { [ -f "/etc/gwos/modulos.d/$1" ]; }
tem_squid() { _tem_modulo proxy-squid || { command -v squid >/dev/null 2>&1 && [ -f /etc/squid/squid.conf ]; }; }
tem_bind9() { _tem_modulo dns-bind9   || command -v named >/dev/null 2>&1; }
tem_ssl_bump() { [ -f /etc/squid/ssl_cert/gwos-ca.crt ]; }

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_BANCO="${DB_BANCO:-gwos}"
DB_USUARIO="${DB_USUARIO:-gwos}"
DB_SENHA="${DB_SENHA:-}"

SQUID_DIR="/etc/squid/conf.d"
mkdir -p "$SQUID_DIR"
SQUID_OWNED_FILES=(gwos_ips_isentos.txt gwos_ips_bloqueados.txt gwos_ips_parciais.txt gwos_ips_liberados.txt gwos_tcp_outgoing.conf)
SQUID_BACKUP_DIR="$(mktemp -d /tmp/gwos_squid_rules.XXXXXX)"
chmod 0700 "$SQUID_BACKUP_DIR"
SQUID_CHANGES_COMMITTED=0
for _f in "${SQUID_OWNED_FILES[@]}"; do
    if [ -f "$SQUID_DIR/$_f" ]; then cp -p "$SQUID_DIR/$_f" "$SQUID_BACKUP_DIR/$_f"; else : > "$SQUID_BACKUP_DIR/.absent_$_f"; fi
done
restore_squid_files() {
    for _f in "${SQUID_OWNED_FILES[@]}"; do
        if [ -f "$SQUID_BACKUP_DIR/.absent_$_f" ]; then rm -f "$SQUID_DIR/$_f"
        elif [ -f "$SQUID_BACKUP_DIR/$_f" ]; then cp -p "$SQUID_BACKUP_DIR/$_f" "$SQUID_DIR/$_f"; fi
    done
}
cleanup_squid_files() {
    rc=$?
    if [ "$SQUID_CHANGES_COMMITTED" = "0" ]; then restore_squid_files; fi
    rm -rf "$SQUID_BACKUP_DIR"
    return "$rc"
}
trap cleanup_squid_files EXIT

mysql_q() {
    mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
        --batch --skip-column-names -e "$1"
}

# ------------------------------------------------------------------
# Lê configurações
# ------------------------------------------------------------------
# O banco é a fonte principal; /etc/gwos/gwos.conf cobre o caso de ele estar
# fora do ar — sem isso, uma consulta vazia geraria regras com interface em
# branco e derrubaria a rede.
CONF_WAN="${IFACE_WAN:-}"; CONF_LAN="${IFACE_LAN:-}"; CONF_REDE="${REDE_LAN:-}"

IFACE_WAN=$(mysql_q "SELECT valor FROM configuracoes WHERE chave='iface_wan'" || true)
IFACE_LAN=$(mysql_q "SELECT valor FROM configuracoes WHERE chave='iface_lan'" || true)
REDE_LAN=$(mysql_q  "SELECT valor FROM configuracoes WHERE chave='rede_lan'"  || true)
NAT_ATIVO=$(mysql_q "SELECT valor FROM configuracoes WHERE chave='nat_ativo'" || true)
SQUID_PORTA=$(mysql_q "SELECT valor FROM configuracoes WHERE chave='squid_porta'" || true)

IFACE_WAN="${IFACE_WAN:-$CONF_WAN}"
IFACE_LAN="${IFACE_LAN:-$CONF_LAN}"
REDE_LAN="${REDE_LAN:-$CONF_REDE}"
NAT_ATIVO="${NAT_ATIVO:-1}"
SQUID_PORTA="${SQUID_PORTA:-3128}"
SQUID_PORTA_SSL="${SQUID_PORTA_SSL:-3129}"

[ -n "$IFACE_WAN" ] && [ -n "$IFACE_LAN" ] || {
    echo "ERRO: interfaces WAN/LAN não determinadas (banco vazio e /etc/gwos/gwos.conf ausente)."
    exit 1
}

# ------------------------------------------------------------------
# Gera listas de IPs por tipo
# ------------------------------------------------------------------
IPS_BLOQUEADOS=$(mysql_q \
    "SELECT i.endereco FROM ips i JOIN ip_grupos g ON g.id = i.grupo_id
     WHERE g.tipo='bloqueado' AND i.ativo=1 AND g.ativo=1 AND (i.expira_em IS NULL OR i.expira_em>NOW())")

IPS_PARCIAIS=$(mysql_q \
    "SELECT i.endereco FROM ips i JOIN ip_grupos g ON g.id = i.grupo_id
     WHERE g.tipo='parcial' AND i.ativo=1 AND g.ativo=1 AND (i.expira_em IS NULL OR i.expira_em>NOW())")

IPS_LIBERADOS=$(mysql_q \
    "SELECT i.endereco FROM ips i JOIN ip_grupos g ON g.id = i.grupo_id
     WHERE g.tipo='liberado' AND i.ativo=1 AND g.ativo=1 AND (i.expira_em IS NULL OR i.expira_em>NOW())")

# Isentos de login: navegam sem senha mas PASSAM pelo proxy (não entram em
# ip_bypass_proxy), então ficam registrados no log de acessos.
IPS_ISENTOS=$(mysql_q \
    "SELECT i.endereco FROM ips i JOIN ip_grupos g ON g.id = i.grupo_id
     WHERE g.tipo='isento' AND i.ativo=1 AND g.ativo=1 AND (i.expira_em IS NULL OR i.expira_em>NOW())")

# Escreve arquivos para o Squid
echo "$IPS_ISENTOS"    > "$SQUID_DIR/gwos_ips_isentos.txt"
echo "$IPS_BLOQUEADOS" > "$SQUID_DIR/gwos_ips_bloqueados.txt"
echo "$IPS_PARCIAIS"   > "$SQUID_DIR/gwos_ips_parciais.txt"
echo "$IPS_LIBERADOS"  > "$SQUID_DIR/gwos_ips_liberados.txt"

# ------------------------------------------------------------------
# Formata elementos para nft (separados por vírgula, ignora vazio)
# ------------------------------------------------------------------
fmt_nft() {
    echo "$1" | grep -v '^$' | paste -sd ',' - || true
}

ELEM_BLOQUEADOS=$(fmt_nft "$IPS_BLOQUEADOS")
ELEM_PARCIAIS=$(fmt_nft "$IPS_PARCIAIS")
ELEM_LIBERADOS=$(fmt_nft "$IPS_LIBERADOS")   # IPs que bypassam o proxy

# ------------------------------------------------------------------
# Regras 1:1 NAT
# ------------------------------------------------------------------
NAT_DNAT=""
NAT_SNAT=""
TCP_OUTGOING="# GWOS — tcp_outgoing_address gerado automaticamente"$'\n'
while IFS=$'\t' read -r ip_externo ip_interno; do
    [ -z "$ip_externo" ] || [ -z "$ip_interno" ] && continue
    NAT_DNAT+="        iif \"$IFACE_WAN\" ip daddr $ip_externo dnat to $ip_interno"$'\n'
    NAT_SNAT+="        oif \"$IFACE_WAN\" ip saddr $ip_interno snat to $ip_externo"$'\n'
    ACL_NOME="nat1to1_$(echo "$ip_interno" | tr '.' '_')"
    TCP_OUTGOING+="acl ${ACL_NOME} src ${ip_interno}"$'\n'
    TCP_OUTGOING+="tcp_outgoing_address ${ip_interno} ${ACL_NOME}"$'\n'
done < <(mysql_q "SELECT ip_externo, ip_interno FROM nat_um_para_um WHERE ativo=1" 2>/dev/null || true)
echo "$TCP_OUTGOING" > "$SQUID_DIR/gwos_tcp_outgoing.conf"

if tem_squid; then
    squid -k parse >/dev/null 2>&1 || erro "As listas geradas reprovaram squid -k parse; regras existentes preservadas."
fi

if [ "$NAT_ATIVO" = "1" ]; then
    MASQ="        oif \"$IFACE_WAN\" masquerade"
else
    MASQ="        # masquerade desativado"
fi

# Redes diretamente conectadas à interface LAN (IP principal + TODOS os
# aliases, ex.: enp0s8:1 da rede secundária). Qualquer sub-rede local do
# gateway é ROTEADA, não interceptada pelo proxy — cobre faixas fora do
# RFC 1918 (ex.: 172.14.29.0/24) e a rede secundária SEM depender do banco,
# sobrevivendo à regeneração das regras.
REDES_LOCAIS=$(
    { ip -4 route show dev "$IFACE_LAN" scope link 2>/dev/null | awk '{print $1}'
      [ -n "$REDE_LAN" ] && echo "$REDE_LAN"
    } | grep -E '^[0-9.]+/[0-9]+$' | sort -u
)
RETURN_REDE_LAN=""
for _rede in $REDES_LOCAIS; do
    RETURN_REDE_LAN+="        iif \"$IFACE_LAN\" ip daddr ${_rede} return"$'\n'
done
[ -z "$RETURN_REDE_LAN" ] && RETURN_REDE_LAN="        # nenhuma rede local detectada na LAN"

# ------------------------------------------------------------------
# Redirecionamentos condicionais — só apontam para serviço que existe.
# Numa instalação modular o Squid ou o BIND9 podem não estar presentes;
# redirecionar para uma porta morta deixaria a LAN sem internet/DNS.
# ------------------------------------------------------------------
if tem_bind9; then
    REGRA_DNS="        # Força DNS da LAN pelo BIND9 local (impede bypass de RPZ)
        iif \"$IFACE_LAN\" udp dport 53 redirect
        iif \"$IFACE_LAN\" tcp dport 53 redirect"
else
    REGRA_DNS="        # BIND9 ausente — DNS da LAN sai direto"
fi

if tem_squid; then
    REGRA_PROXY="        # Proxy transparente — apenas tráfego saindo para internet
        iif \"$IFACE_LAN\" ip saddr != @ip_bypass_proxy tcp dport 80 redirect to :${SQUID_PORTA}"
    if tem_ssl_bump; then
        REGRA_PROXY+="
        iif \"$IFACE_LAN\" ip saddr != @ip_bypass_proxy tcp dport 443 redirect to :${SQUID_PORTA_SSL}"
    else
        REGRA_PROXY+="
        # HTTPS não interceptado: Squid sem SSL Bump"
    fi
else
    REGRA_PROXY="        # Squid ausente — HTTP/HTTPS saem direto"
fi

# ------------------------------------------------------------------
# Elementos dos sets (evita bloco vazio que pode falhar no nft)
# ------------------------------------------------------------------
elem_set() { [ -n "$1" ] && echo "elements = { $1 }" || true; }

# ------------------------------------------------------------------
# Gera arquivo nftables
# ------------------------------------------------------------------
cat > /tmp/gwos_nftables_test.conf << NFTEOF
#!/usr/sbin/nft -f

flush ruleset

# ═══════════════════════════════════════════════════════════════
# NAT — tabela IPv4
# ═══════════════════════════════════════════════════════════════
table ip gwos_nat {

    # IPs que bypassam o proxy transparente (grupo "liberado")
    set ip_bypass_proxy {
        type ipv4_addr
        flags interval
        auto-merge
        $(elem_set "$ELEM_LIBERADOS")
    }

    chain prerouting {
        type nat hook prerouting priority dstnat;

        # 1:1 NAT — DNAT: IP público → IP interno
${NAT_DNAT}
${REGRA_DNS}

        # Redes locais do gateway (IP principal + aliases): roteia, não intercepta
${RETURN_REDE_LAN}
        # Demais redes internas alcançáveis por rota estática (faixas RFC 1918)
        iif "$IFACE_LAN" ip daddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 } return

${REGRA_PROXY}
    }

    chain postrouting {
        type nat hook postrouting priority srcnat;

        # 1:1 NAT — SNAT: IP interno → IP público fixo
${NAT_SNAT}
        # Masquerade para IPs sem 1:1 NAT
$MASQ
    }
}

# ═══════════════════════════════════════════════════════════════
# FILTRO — tabela inet (IPv4 + IPv6)
# ═══════════════════════════════════════════════════════════════
table inet gwos {

    set ip_bloqueados {
        type ipv4_addr
        flags interval
        auto-merge
        $(elem_set "$ELEM_BLOQUEADOS")
    }

    set ip_parciais {
        type ipv4_addr
        flags interval
        auto-merge
        $(elem_set "$ELEM_PARCIAIS")
    }

    # ─── INPUT ───────────────────────────────────────────────────
    chain input {
        type filter hook input priority filter; policy drop;

        ct state { established, related } accept
        ct state invalid drop
        iif lo accept

        # ICMP da LAN livre
        iif "$IFACE_LAN" ip protocol icmp accept

        # Bloqueia IPs banidos
        iif "$IFACE_LAN" ip saddr @ip_bloqueados drop

        # Aceita todo tráfego da LAN destinado ao gateway
        # (Squid 3128/3129, BIND9 53, SSH, NTP, etc.)
        iif "$IFACE_LAN" accept

        # ICMP de resposta da WAN
        iif "$IFACE_WAN" icmp type { echo-reply, destination-unreachable, time-exceeded, parameter-problem } accept

        # SSH da WAN
        iif "$IFACE_WAN" ct state new tcp dport 22 accept comment "SSH"

        # Descarta novas conexões da WAN não explicitadas
        iif "$IFACE_WAN" ct state new drop
    }

    # ─── FORWARD ─────────────────────────────────────────────────
    chain forward {
        type filter hook forward priority filter; policy drop;

        ct state { established, related } accept
        ct state invalid drop

        # Bloqueia IPs banidos em qualquer direção
        iif "$IFACE_LAN" ip saddr @ip_bloqueados drop

        # ICMP livre (ping/traceroute)
        ip protocol icmp accept

        # LAN ↔ LAN (hosts em sub-redes diferentes na mesma interface)
        iif "$IFACE_LAN" oif "$IFACE_LAN" accept

        # WAN → LAN livre (hosts externos acessam a rede interna)
        iif "$IFACE_WAN" oif "$IFACE_LAN" accept

        # LAN → WAN: IPs liberados passam direto (sem proxy)
        # HTTP/HTTPS dos demais já foram redirecionados ao Squid no prerouting
        iif "$IFACE_LAN" oif "$IFACE_WAN" accept
    }
}
NFTEOF

# ------------------------------------------------------------------
# Valida antes de aplicar
# ------------------------------------------------------------------
nft -c -f /tmp/gwos_nftables_test.conf || {
    echo "ERRO: regras inválidas — nftables não alterado."
    rm -f /tmp/gwos_nftables_test.conf
    exit 1
}

if [ "${1:-}" = "--preview" ]; then
    if tem_squid; then squid -k parse >/dev/null 2>&1 || erro "A prévia dos arquivos Squid falhou."; fi
    echo "PREVIEW_VALIDADO: ruleset aprovado por nft -c; nenhuma regra foi aplicada."
    set +e
    if [ -f /etc/nftables.conf ]; then
        diff -u --label atual /etc/nftables.conf --label proposto /tmp/gwos_nftables_test.conf
    else
        diff -u --label atual /dev/null --label proposto /tmp/gwos_nftables_test.conf
    fi
    rc=$?
    set -e
    rm -f /tmp/gwos_nftables_test.conf
    [ "$rc" -le 1 ] || erro "Falha ao produzir diff do firewall."
    restore_squid_files
    SQUID_CHANGES_COMMITTED=1
    rm -rf "$SQUID_BACKUP_DIR"
    trap - EXIT
    exit 0
fi

ROLLBACK_DIR="/var/lib/gwos/firewall-rollback"
install -d -o root -g root -m 0700 "$ROLLBACK_DIR"
STAMP="$(date +%Y%m%d_%H%M%S)_$$"
SNAPSHOT="$ROLLBACK_DIR/${STAMP}.ruleset"
RESTORE_FILE="$ROLLBACK_DIR/${STAMP}.restore"
PREVIOUS_CONFIG="$ROLLBACK_DIR/${STAMP}.nftables.conf"
HAD_CONFIG=0
nft list ruleset > "$SNAPSHOT"
chmod 0600 "$SNAPSHOT"
{
    echo 'flush ruleset'
    cat "$SNAPSHOT"
} > "$RESTORE_FILE"
chmod 0600 "$RESTORE_FILE"
if [ -f /etc/nftables.conf ]; then
    cp -p /etc/nftables.conf "$PREVIOUS_CONFIG"
    HAD_CONFIG=1
fi
nft -c -f "$RESTORE_FILE" || erro "O snapshot atual do firewall não pode ser validado; regras novas não foram aplicadas."

APLICACAO_INICIADA=0
rollback_firewall() {
    rc=$?
    if [ "$APLICACAO_INICIADA" = "1" ]; then
        echo "ERRO: aplicação do firewall falhou; restaurando snapshot $STAMP." >&2
        if ! nft -f "$RESTORE_FILE"; then
            echo "CRÍTICO: rollback automático falhou. Snapshot em $RESTORE_FILE" >&2
        fi
        if [ "$HAD_CONFIG" = "1" ]; then
            cp -p "$PREVIOUS_CONFIG" /etc/nftables.conf
        else
            rm -f /etc/nftables.conf
        fi
        echo "Rollback do ruleset anterior concluído." >&2
    fi
    if [ "$SQUID_CHANGES_COMMITTED" = "0" ]; then
        restore_squid_files
        if tem_squid; then squid -k parse >/dev/null 2>&1 && squid -k reconfigure >/dev/null 2>&1 || true; fi
    fi
    rm -f /tmp/gwos_nftables_test.conf
    rm -rf "$SQUID_BACKUP_DIR"
    return "$rc"
}
trap rollback_firewall EXIT

APLICACAO_INICIADA=1
cp /tmp/gwos_nftables_test.conf /etc/nftables.conf
nft -f /etc/nftables.conf
REGRAS_APOS=$(nft list ruleset)
grep -q 'table inet gwos' <<< "$REGRAS_APOS" || erro "Pós-checagem falhou: tabela de firewall GWOS ausente."
grep -q 'table ip gwos_nat' <<< "$REGRAS_APOS" || erro "Pós-checagem falhou: tabela NAT GWOS ausente."

mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
    -e "INSERT INTO regras_historico (modulo, descricao) VALUES ('nftables', 'Regras aplicadas via painel')" \
    2>/dev/null || true

rm -f /tmp/gwos_nftables_test.conf

# Recarrega Squid somente após parse. Falhas ainda acionam o rollback.
if tem_squid; then
    squid -k parse >/dev/null 2>&1 || erro "Pós-checagem Squid falhou; iniciando rollback."
    squid -k reconfigure 2>/dev/null || erro "Squid não reconfigurou; iniciando rollback."
fi

SQUID_CHANGES_COMMITTED=1
APLICACAO_INICIADA=0
trap - EXIT
rm -rf "$SQUID_BACKUP_DIR"

echo "nftables e arquivos Squid aplicados com sucesso."
