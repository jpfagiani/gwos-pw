#!/bin/bash
# GWOS — Troca o IP do gateway na LAN de forma segura.
# Chamado por: sudo gwos ip <novo_ip> [nova_rede_cidr]
#
# Princípios de segurança (para nunca derrubar a rede):
#   1. NUNCA executa "systemctl restart networking".
#   2. O novo /etc/network/interfaces é gerado em arquivo temporário e
#      VALIDADO (ifquery) antes de substituir o original.
#   3. Backup do interfaces é criado antes de qualquer alteração.
#   4. O novo IP é ADICIONADO à interface antes de remover o antigo —
#      em nenhum momento a interface fica sem IP.
#   5. Cada serviço (BIND, Squid, nftables) é validado com sua própria
#      ferramenta (named-checkconf, squid -k parse, nft -c) antes do reload.

set -euo pipefail

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$GWOS_DIR/scripts/ler_env.sh"
gwos_ler_env "$GWOS_DIR/.env"

R='\033[0;31m'; G='\033[0;32m'; Y='\033[1;33m'; B='\033[1m'; N='\033[0m'
ok()   { echo -e "${G}[OK]${N} $*"; }
info() { echo -e "${Y}[..]${N} $*"; }
aviso(){ echo -e "${Y}[!]${N} $*"; }
erro() { echo -e "${R}[ERRO]${N} $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || erro "Execute como root: sudo gwos ip <novo_ip> [nova_rede_cidr]"

NOVO_IP="${1:-}"
NOVA_REDE="${2:-}"

if [ -z "$NOVO_IP" ]; then
    echo "Uso: gwos ip <novo_ip> [nova_rede_cidr]"
    echo "  Ex.: gwos ip 172.14.29.20              (mantém a rede atual)"
    echo "       gwos ip 10.14.29.1 10.14.29.0/24  (muda IP e rede)"
    exit 1
fi

# ------------------------------------------------------------------
# Validações de formato
# ------------------------------------------------------------------
valida_ip() {
    [[ "$1" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || return 1
    local o
    for o in "${BASH_REMATCH[@]:1}"; do [ "$o" -le 255 ] || return 1; done
}

valida_ip "$NOVO_IP" || erro "IP inválido: $NOVO_IP"

if [ -n "$NOVA_REDE" ]; then
    [[ "$NOVA_REDE" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}/[0-9]{1,2}$ ]] \
        || erro "Rede inválida: $NOVA_REDE — use CIDR (ex: 10.14.29.0/24)"
fi

# ------------------------------------------------------------------
# Lê configuração atual (banco + interface)
# ------------------------------------------------------------------
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_BANCO="${DB_BANCO:-gwos}"
DB_USUARIO="${DB_USUARIO:-gwos}"
DB_SENHA="${DB_SENHA:-}"

mysql_q() {
    mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
        --batch --skip-column-names -e "$1" 2>/dev/null
}

IFACE_LAN=$(mysql_q "SELECT valor FROM configuracoes WHERE chave='iface_lan'" || true)
IP_ANTIGO=$(mysql_q  "SELECT valor FROM configuracoes WHERE chave='ip_gateway'" || true)
REDE_ANTIGA=$(mysql_q "SELECT valor FROM configuracoes WHERE chave='rede_lan'" || true)

[ -n "$IFACE_LAN" ] || erro "iface_lan não encontrada no banco. Verifique o .env e o MariaDB."
ip link show "$IFACE_LAN" &>/dev/null || erro "Interface '$IFACE_LAN' não existe neste servidor."

# Fallback: detecta IP atual direto da interface se o banco estiver vazio
if [ -z "$IP_ANTIGO" ]; then
    IP_ANTIGO=$(ip -4 addr show "$IFACE_LAN" | awk '/inet /{print $2}' | cut -d/ -f1 | head -1)
fi
[ -n "$IP_ANTIGO" ] || erro "Não foi possível determinar o IP atual do gateway."

REDE="${NOVA_REDE:-$REDE_ANTIGA}"
[ -n "$REDE" ] || erro "rede_lan não encontrada no banco — informe a rede: gwos ip $NOVO_IP <rede_cidr>"

PREFIX="${REDE#*/}"
REDE_BASE="${REDE%/*}"
[ "$PREFIX" -ge 1 ] && [ "$PREFIX" -le 30 ] || erro "Prefixo inválido: /$PREFIX"
valida_ip "$REDE_BASE" || erro "Endereço de rede inválido: $REDE_BASE"

# ------------------------------------------------------------------
# Confere que o novo IP pertence à rede alvo
# ------------------------------------------------------------------
ip_para_int() {
    local a b c d; IFS='.' read -r a b c d <<< "$1"
    echo $(( (a << 24) | (b << 16) | (c << 8) | d ))
}
MASCARA_INT=$(( (0xFFFFFFFF << (32 - PREFIX)) & 0xFFFFFFFF ))
if [ $(( $(ip_para_int "$NOVO_IP") & MASCARA_INT )) -ne $(( $(ip_para_int "$REDE_BASE") & MASCARA_INT )) ]; then
    erro "O IP $NOVO_IP não pertence à rede $REDE."
fi
REDE_NORMALIZADA=$(( $(ip_para_int "$REDE_BASE") & MASCARA_INT ))
[ "$REDE_NORMALIZADA" -eq "$(ip_para_int "$REDE_BASE")" ] || erro "A rede $REDE_BASE não é o endereço-base alinhado ao prefixo /$PREFIX."
HOST_MASK=$(( 0xFFFFFFFF ^ MASCARA_INT ))
HOST_BITS=$(( $(ip_para_int "$NOVO_IP") & HOST_MASK ))
[ "$HOST_BITS" -ne 0 ] && [ "$HOST_BITS" -ne "$HOST_MASK" ] || erro "O IP novo não pode ser o endereço de rede ou broadcast."

MASK="$(( (MASCARA_INT>>24)&0xFF )).$(( (MASCARA_INT>>16)&0xFF )).$(( (MASCARA_INT>>8)&0xFF )).$(( MASCARA_INT&0xFF ))"

echo ""
echo -e "  ${B}Troca de IP do gateway${N}"
echo "  ─────────────────────────────────────"
echo "  Interface LAN : $IFACE_LAN"
echo "  IP atual      : $IP_ANTIGO"
echo "  IP novo       : $NOVO_IP"
echo "  Rede          : $REDE  (máscara $MASK)"
echo ""
read -rp "  Confirmar? [s/N]: " CONF
[[ "$CONF" =~ ^[Ss]$ ]] || { echo "Cancelado — nada foi alterado."; exit 0; }

# ==================================================================
# 1. Gera novo /etc/network/interfaces em temporário e VALIDA
# ==================================================================
info "Gerando novo /etc/network/interfaces..."

TMP=$(mktemp /tmp/gwos_interfaces.XXXXXX)
trap 'rm -f "$TMP"' EXIT
cp /etc/network/interfaces "$TMP"

# Altera address/netmask APENAS dentro da stanza da LAN
# (a âncora exata "iface $IFACE_LAN inet static" não casa com o alias :1)
sed -i "/^iface ${IFACE_LAN} inet static$/,/^[[:space:]]*$/ {
    s|^\([[:space:]]*address[[:space:]]\+\).*|\1${NOVO_IP}|
    s|^\([[:space:]]*netmask[[:space:]]\+\).*|\1${MASK}|
}" "$TMP"

grep -qE "^[[:space:]]*address[[:space:]]+${NOVO_IP}$" "$TMP" \
    || erro "Não foi possível localizar a stanza 'iface ${IFACE_LAN} inet static' — arquivo fora do padrão. Nada foi alterado."

# Validação de sintaxe do ifupdown ANTES de gravar
if command -v ifquery &>/dev/null; then
    ifquery --list --allow=auto -i "$TMP" >/dev/null 2>&1 \
        || erro "Arquivo gerado reprovado na validação do ifquery — nada foi alterado."
    ok "Sintaxe validada pelo ifquery."
else
    aviso "ifquery não encontrado — validação de sintaxe pulada."
fi

BACKUP="/etc/network/interfaces.bak.$(date +%Y%m%d%H%M%S)"
cp /etc/network/interfaces "$BACKUP"

# Snapshot de todos os estados que este procedimento pode alterar. O endereço
# antigo permanece ativo até as validações de serviço e do firewall passarem.
ROLLBACK_DIR="/var/lib/gwos/ip-changes"
install -d -o root -g root -m 0700 "$ROLLBACK_DIR"
STAMP="$(date +%Y%m%d_%H%M%S)_$$"
ROLLBACK_NFT="$ROLLBACK_DIR/${STAMP}.ruleset"
ROLLBACK_NFT_APPLY="$ROLLBACK_DIR/${STAMP}.restore"
ROLLBACK_BIND="$ROLLBACK_DIR/${STAMP}.named.conf.options"
ROLLBACK_SQUID="$ROLLBACK_DIR/${STAMP}.squid.conf"
ROLLBACK_NFT_CONF="$ROLLBACK_DIR/${STAMP}.nftables.conf"
ROLLBACK_ENV="$ROLLBACK_DIR/${STAMP}.env"
HAD_BIND=0; HAD_SQUID=0; HAD_NFT_CONF=0; HAD_ENV=0
[ ! -f /etc/bind/named.conf.options ] || { cp -p /etc/bind/named.conf.options "$ROLLBACK_BIND"; HAD_BIND=1; }
[ ! -f /etc/squid/squid.conf ] || { cp -p /etc/squid/squid.conf "$ROLLBACK_SQUID"; HAD_SQUID=1; }
[ ! -f /etc/nftables.conf ] || { cp -p /etc/nftables.conf "$ROLLBACK_NFT_CONF"; HAD_NFT_CONF=1; }
[ ! -f "$GWOS_DIR/.env" ] || { cp -p "$GWOS_DIR/.env" "$ROLLBACK_ENV"; HAD_ENV=1; }
nft list ruleset > "$ROLLBACK_NFT"
{
    echo 'flush ruleset'
    cat "$ROLLBACK_NFT"
} > "$ROLLBACK_NFT_APPLY"
chmod 0600 "$ROLLBACK_DIR"/*
nft -c -f "$ROLLBACK_NFT_APPLY" || erro "Snapshot do firewall não passou na validação; nada foi alterado."

CIDR_ANTIGO=$(ip -o -4 addr show dev "$IFACE_LAN" | awk -v ip="$IP_ANTIGO" 'index($4,ip"/")==1 {print $4; exit}')
if [ -z "$CIDR_ANTIGO" ]; then CIDR_ANTIGO="$IP_ANTIGO/$PREFIX"; fi
ROLLBACK_ATIVO=1
restaurar_troca_ip() {
    rc=$?
    if [ "$ROLLBACK_ATIVO" = "1" ]; then
        trap - EXIT
        aviso "Falha na troca do IP; restaurando o estado anterior. Snapshot: $ROLLBACK_DIR/$STAMP"
        cp -p "$BACKUP" /etc/network/interfaces 2>/dev/null || true
        [ "$HAD_BIND" = "0" ] || cp -p "$ROLLBACK_BIND" /etc/bind/named.conf.options 2>/dev/null || true
        [ "$HAD_SQUID" = "0" ] || cp -p "$ROLLBACK_SQUID" /etc/squid/squid.conf 2>/dev/null || true
        [ "$HAD_NFT_CONF" = "0" ] || cp -p "$ROLLBACK_NFT_CONF" /etc/nftables.conf 2>/dev/null || true
        [ "$HAD_ENV" = "0" ] || cp -p "$ROLLBACK_ENV" "$GWOS_DIR/.env" 2>/dev/null || true
        mysql_q "UPDATE configuracoes SET valor='${IP_ANTIGO}' WHERE chave='ip_gateway'" 2>/dev/null || true
        mysql_q "UPDATE configuracoes SET valor='${REDE_ANTIGA}' WHERE chave='rede_lan'" 2>/dev/null || true
        ip addr del "${NOVO_IP}/${PREFIX}" dev "$IFACE_LAN" 2>/dev/null || true
        ip addr add "$CIDR_ANTIGO" dev "$IFACE_LAN" 2>/dev/null || true
        nft -f "$ROLLBACK_NFT_APPLY" 2>/dev/null || echo "CRÍTICO: restaure manualmente o firewall com $ROLLBACK_NFT_APPLY" >&2
        command -v named-checkconf >/dev/null 2>&1 && named-checkconf >/dev/null 2>&1 && systemctl reload named 2>/dev/null || true
        command -v squid >/dev/null 2>&1 && squid -k parse >/dev/null 2>&1 && squid -k reconfigure 2>/dev/null || true
        rm -f "$TMP"
        aviso "Rollback concluído; confirme o endereço pelo console local antes de voltar a usar a rede."
    fi
    return "$rc"
}
trap restaurar_troca_ip EXIT

cp "$TMP" /etc/network/interfaces
ok "/etc/network/interfaces atualizado (backup: $BACKUP)"

# ==================================================================
# 2. Aplica em runtime — adiciona o novo IP SEM remover o antigo ainda
# ==================================================================
info "Adicionando ${NOVO_IP}/${PREFIX} em ${IFACE_LAN}..."
ip addr add "${NOVO_IP}/${PREFIX}" dev "$IFACE_LAN" 2>/dev/null || true
ip link set "$IFACE_LAN" up
ok "Novo IP ativo na interface (o antigo continua até o fim do processo)."

# ==================================================================
# 3. Atualiza banco e .env
# ==================================================================
info "Atualizando banco de dados e .env..."
mysql_q "UPDATE configuracoes SET valor='${NOVO_IP}' WHERE chave='ip_gateway'" || erro "Falha ao atualizar IP do gateway no banco."
mysql_q "UPDATE configuracoes SET valor='${REDE}'    WHERE chave='rede_lan'"   || erro "Falha ao atualizar a rede LAN no banco."
if [ -f "$GWOS_DIR/.env" ]; then
    sed -i "s|^APP_URL=.*|APP_URL=http://${NOVO_IP}|" "$GWOS_DIR/.env"
fi
ok "Banco e .env atualizados."

# ==================================================================
# 4. BIND9 — remove IP antigo se estiver gravado (instalações antigas)
# ==================================================================
if grep -q "${IP_ANTIGO};" /etc/bind/named.conf.options 2>/dev/null; then
    info "Atualizando IP antigo no named.conf.options..."
    sed -i "s|${IP_ANTIGO};|${NOVO_IP};|g" /etc/bind/named.conf.options
fi
if command -v named-checkconf >/dev/null 2>&1; then
    named-checkconf 2>/dev/null || erro "named-checkconf reprovou a nova configuração; iniciando rollback."
    systemctl reload named 2>/dev/null || erro "BIND9 não recarregou; iniciando rollback."
    ok "BIND9 validado e recarregado."
fi

# ==================================================================
# 5. Squid — atualiza acl localnet se a rede mudou
# ==================================================================
if [ -n "$NOVA_REDE" ] && [ "$NOVA_REDE" != "$REDE_ANTIGA" ] && [ -n "$REDE_ANTIGA" ]; then
    if grep -q "acl localnet src ${REDE_ANTIGA}" /etc/squid/squid.conf 2>/dev/null; then
        sed -i "s|acl localnet src ${REDE_ANTIGA}|acl localnet src ${REDE}|" /etc/squid/squid.conf
    fi
    if grep -q "acl rede_interna dst ${REDE_ANTIGA}" /etc/squid/squid.conf 2>/dev/null; then
        sed -i "s|acl rede_interna dst ${REDE_ANTIGA}|acl rede_interna dst ${REDE}|" /etc/squid/squid.conf
    fi
fi
if command -v squid >/dev/null 2>&1; then
    squid -k parse &>/dev/null || erro "squid -k parse reprovou a nova configuração; iniciando rollback."
    squid -k reconfigure 2>/dev/null || erro "Squid não recarregou; iniciando rollback."
    ok "Squid validado e recarregado."
fi

# ==================================================================
# 6. nftables — regenera do banco (valida com nft -c antes de aplicar)
# ==================================================================
info "Regenerando regras nftables..."
bash "$GWOS_DIR/scripts/aplicar_nftables.sh" \
    && ok "nftables reaplicado." \
    || erro "Falha ao regenerar nftables; a troca de IP será revertida."

# ==================================================================
# 7. Remove o IP antigo da interface (último passo)
# ==================================================================
if [ "$IP_ANTIGO" != "$NOVO_IP" ]; then
    CIDR_ANTIGO=$(ip -4 addr show "$IFACE_LAN" | awk -v ip="$IP_ANTIGO" '$2 ~ "^"ip"/" {print $2}' | head -1)
    if [ -n "$CIDR_ANTIGO" ]; then
        info "Removendo IP antigo ${CIDR_ANTIGO}..."
        ip addr del "$CIDR_ANTIGO" dev "$IFACE_LAN" || true
    fi
fi

ip -4 addr show dev "$IFACE_LAN" | grep -Fq "${NOVO_IP}/${PREFIX}" || erro "Pós-checagem falhou: IP novo não está ativo."
ROLLBACK_ATIVO=0
trap - EXIT
rm -f "$TMP"

# ==================================================================
# Resumo
# ==================================================================
echo ""
echo -e "${G}${B}════════════════════════════════════════════════${N}"
echo -e "${G}${B}  IP do gateway alterado com sucesso!${N}"
echo -e "${G}${B}════════════════════════════════════════════════${N}"
echo ""
echo -e "  Painel      : ${B}http://${NOVO_IP}${N}"
echo -e "  Certificado : ${B}http://${NOVO_IP}/gwos-ca.crt${N}"
echo -e "  Backup rede : ${B}${BACKUP}${N}"
echo ""
if [ -n "$NOVA_REDE" ] && [ "$NOVA_REDE" != "$REDE_ANTIGA" ]; then
    aviso "A REDE mudou (${REDE_ANTIGA:-?} → ${REDE}): atualize gateway/DNS nos clientes da LAN."
fi
HOSTS_ANTIGOS=$(grep '# gwos-dns' /etc/hosts 2>/dev/null | grep -F "$IP_ANTIGO" || true)
if [ -n "$HOSTS_ANTIGOS" ]; then
    aviso "Hosts do DNS interno ainda apontam para o IP antigo:"
    echo "$HOSTS_ANTIGOS" | awk '{print "      " $1 "  " $2 "   →  atualize com: gwos dns update " $2 " <novo_ip>"}'
fi
echo -e "  ${Y}A configuração persiste após reboot via /etc/network/interfaces.${N}"
echo -e "  ${Y}Se algo der errado, restaure com: cp ${BACKUP} /etc/network/interfaces${N}"
echo ""
