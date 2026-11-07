#!/bin/bash
# ============================================================================
# GWOS — Biblioteca comum dos módulos de instalação
# ============================================================================
# Carregada por todos os modulos/*/instalar.sh, desinstalar.sh e verificar.sh.
#
# Fornece:
#   - saída padronizada (ok/info/erro/aviso/titulo)
#   - estado compartilhado em /etc/gwos/gwos.conf
#   - registro dos módulos instalados em /etc/gwos/modulos.d/
#   - detecção de quais módulos/serviços existem na máquina
#   - utilitários de rede, pacotes e serviços
#
# É a peça que permite instalar cada servidor isoladamente: quem precisa
# saber "qual é a LAN", "qual é a rede", "o BIND9 existe?" pergunta aqui,
# em vez de depender do instalador monolítico.
# ============================================================================

[ -n "${GWOS_LIB_CARREGADA:-}" ] && return 0
GWOS_LIB_CARREGADA=1

# ----------------------------------------------------------------------------
# Saída
# ----------------------------------------------------------------------------
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

ok()     { echo -e "${GREEN}[OK]${NC} $1"; }
info()   { echo -e "${YELLOW}[..]${NC} $1"; }
aviso()  { echo -e "${YELLOW}[!]${NC} $1"; }
erro()   { echo -e "${RED}[ERRO]${NC} $1" >&2; exit 1; }
falha()  { echo -e "${RED}[ERRO]${NC} $1" >&2; }           # não encerra
titulo() { echo -e "\n${BOLD}${CYAN}$1${NC}"; }

exigir_root() { [ "$(id -u)" -eq 0 ] || erro "Execute como root."; }

# ----------------------------------------------------------------------------
# Caminhos
# ----------------------------------------------------------------------------
GWOS_ETC="/etc/gwos"
GWOS_CONF="${GWOS_ETC}/gwos.conf"
GWOS_MODULOS="${GWOS_ETC}/modulos.d"
GWOS_DB_CONF="${GWOS_ETC}/db.conf"

# Diretório modulos/ (um nível acima de comum/)
GWOS_MODULOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Raiz do projeto GWOS (repositório) — vazio se o módulo foi copiado sozinho
raiz_projeto() {
    local d
    for d in "${GWOS_MODULOS_DIR}/.." "/opt/gwos" "/opt/dns" "/var/www/gwos"; do
        [ -f "${d}/public/index.php" ] && { (cd "$d" && pwd); return 0; }
    done
    return 1
}

# ----------------------------------------------------------------------------
# Conversões de rede
# ----------------------------------------------------------------------------
mascara_de_prefixo() {
    local p="${1:-24}" m=0 i
    for i in $(seq 1 "$p"); do m=$(( m | (1 << (32-i)) )); done
    echo "$(( (m>>24)&0xFF )).$(( (m>>16)&0xFF )).$(( (m>>8)&0xFF )).$(( m&0xFF ))"
}

prefixo_de_mascara() {
    local oct bit p=0
    IFS='.' read -r _m1 _m2 _m3 _m4 <<< "${1:-255.255.255.0}"
    for oct in $_m1 $_m2 $_m3 $_m4; do
        for bit in 128 64 32 16 8 4 2 1; do
            (( oct & bit )) && p=$((p+1)) || true
        done
    done
    echo "$p"
}

# rede_de_ip <ip> <prefixo>  →  10.0.0.0/8
rede_de_ip() {
    local ip="$1" pref="${2:-24}" a b c d bits m na
    IFS='.' read -r a b c d <<< "$ip"
    bits=$((32 - pref))
    m=$(( (0xFFFFFFFF << bits) & 0xFFFFFFFF ))
    na=$(( (a << 24 | b << 16 | c << 8 | d) & m ))
    echo "$(( (na>>24)&0xFF )).$(( (na>>16)&0xFF )).$(( (na>>8)&0xFF )).$(( na&0xFF ))/${pref}"
}

# Octetos 0-255 e prefixo 0-32 de verdade. A versão anterior só conferia o
# formato, então '999.1.1.1' e '10.0.0.1/99' passavam e só quebravam depois.
_OCT='(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])'
valida_ip()   { echo "$1" | grep -qE "^(${_OCT}\.){3}${_OCT}$"; }
valida_cidr() { echo "$1" | grep -qE "^(${_OCT}\.){3}${_OCT}/(3[0-2]|[12]?[0-9])$"; }

# Um gateway é o vizinho que leva o tráfego para fora: precisa existir na
# mesma rede do IP desta máquina. Sem esta checagem dava para responder
# 127.0.0.1 e o instalador gravava "via 127.0.0.1" sem reclamar — a máquina
# aceita a rota e simplesmente não sai da rede.
# Mascara de rede tem que ser contigua: 255.255.255.0 vale, 255.0.255.0 nao.
# prefixo_de_mascara() so conta bits, entao aceitaria a segunda em silencio e
# geraria uma rede sem sentido.
valida_mascara() {
    local m="$1"
    valida_ip "$m" || return 1
    [ "$(mascara_de_prefixo "$(prefixo_de_mascara "$m")")" = "$m" ]
}

# O IP precisa pertencer a rede declarada. Sem isto da para dizer que a LAN e
# 192.168.0.0/24 e que o gateway e 10.0.0.1 — o instalador aceitava, e so o
# ifup no reboot reclamava.
ip_na_rede() {
    local ip="$1" cidr="$2" pref
    pref="${cidr#*/}"
    [ "$(rede_de_ip "$ip" "$pref")" = "$cidr" ]
}

gateway_valido() {
    local gw="$1" ip="$2" pref="$3" rede
    if ! valida_ip "$gw"; then
        aviso "Gateway inválido: '${gw}'"
        return 1
    fi
    case "$gw" in
        127.*)   aviso "127.x é o próprio computador — não leva a rede a lugar nenhum."; return 1 ;;
        0.0.0.0) aviso "0.0.0.0 não é um gateway."; return 1 ;;
    esac
    if [ "$gw" = "$ip" ]; then
        aviso "O gateway não pode ser o IP desta máquina (${ip})."
        return 1
    fi
    rede="$(rede_de_ip "$ip" "$pref")"
    if [ "$(rede_de_ip "$gw" "$pref")" != "$rede" ]; then
        aviso "O gateway ${gw} está fora da rede ${rede}."
        echo  "      Ele precisa ser um vizinho na mesma rede deste servidor."
        return 1
    fi
    return 0
}

listar_ifaces() {
    ip -o link show 2>/dev/null \
        | awk -F': ' '{print $2}' \
        | grep -Ev '^(lo|docker|veth|br-|virbr|tun|tap)' \
        | sort
}

# ----------------------------------------------------------------------------
# Estado compartilhado — /etc/gwos/gwos.conf
# ----------------------------------------------------------------------------
# Chaves:
#   IFACE_WAN IFACE_LAN REDE_LAN IP_GATEWAY
#   REDE2_ATIVO IP_ALIAS REDE2_CIDR REDE2_MASK
#   DOMINIO_LOCAL SQUID_PORTA SQUID_PORTA_SSL SQUID_PORTA_FWD DNSMASQ_PORTA
# ----------------------------------------------------------------------------

carregar_conf() {
    mkdir -p "$GWOS_ETC" "$GWOS_MODULOS"
    chmod 755 "$GWOS_ETC"
    # shellcheck disable=SC1090
    [ -f "$GWOS_CONF" ] && { set -a; . "$GWOS_CONF"; set +a; }
    return 0
}

escrever_conf() {
    mkdir -p "$GWOS_ETC"
    cat > "$GWOS_CONF" <<CONF
# /etc/gwos/gwos.conf — estado compartilhado entre os módulos do GWOS
# Gerado em $(date '+%Y-%m-%d %H:%M:%S'). Editável, mas prefira 'gwos ip'
# para trocar o IP do gateway (ele revalida e recarrega tudo).

# ── Identidade da máquina ───────────────────────────────────────────────
NOME_SERVIDOR=${NOME_SERVIDOR:-$(hostname -s 2>/dev/null || echo servidor)}
# gateway = roteia entre duas placas; servidor = só serve (DNS, hora, proxy)
PAPEL=${PAPEL:-servidor}

# ── Interfaces ──────────────────────────────────────────────────────────
IFACE_WAN=${IFACE_WAN}
IFACE_LAN=${IFACE_LAN}

# ── Rede interna principal ──────────────────────────────────────────────
REDE_LAN=${REDE_LAN}
IP_GATEWAY=${IP_GATEWAY}

# ── Rede secundária (alias LAN:1) ───────────────────────────────────────
REDE2_ATIVO=${REDE2_ATIVO:-0}
IP_ALIAS=${IP_ALIAS:-}
REDE2_CIDR=${REDE2_CIDR:-}
REDE2_MASK=${REDE2_MASK:-255.255.255.0}

# ── Nomes internos ──────────────────────────────────────────────────────
DOMINIO_LOCAL=${DOMINIO_LOCAL:-cdpni.local}

# ── Resolvers upstream do BIND9 ─────────────────────────────────────────
# Padrão neutro: resolvers públicos. Os DNS internos da sua unidade entram
# aqui pelo perfil (modulos/perfis/) ou pelas perguntas do módulo 00-base.
DNS_FORWARDERS="${DNS_FORWARDERS:-8.8.8.8 8.8.4.4 1.1.1.1}"

# ── Zonas internas: domínio:ip[,ip] separados por espaço ────────────────
# Domínios que devem ser resolvidos por um DNS específico, não pelos
# forwarders acima. Ex.: "prodesp.sp.gov.br:10.1.6.222 outro.gov.br:10.2.3.4"
# O módulo 20-dns-bind9 transforma isto em zonas de encaminhamento.
ZONAS_INTERNAS="${ZONAS_INTERNAS:-}"

# ── Fontes de hora do chrony ────────────────────────────────────────────
# NTP_SERVIDORES é a fonte preferida (servidor de hora da sua rede); vazio
# significa usar só o pool público. NTP_POOL é a reserva.
NTP_SERVIDORES="${NTP_SERVIDORES:-}"
NTP_POOL="${NTP_POOL:-pool.ntp.br}"

# ── Painel ──────────────────────────────────────────────────────────────
# Versão do PHP em uso, detectada da distribuição pelo módulo 60-painel-web.
PHP_VERSAO=${PHP_VERSAO:-}
# Porta do painel: 8080 por convenção, em TODA unidade.
# A padronização vale mais que ganhar a porta 80: quem der suporte a vários
# presídios encontra sempre o painel no mesmo lugar, e a 80 fica livre para o
# portal de sistemas, que é o que os usuários acessam.
#
#   80    portal-sistemas   (atalhos — todo mundo usa)
#   8080  portal-gateway    (GWOS — administração)
#   8443  portal-samba      (arquivos — administração)
PAINEL_PORTA=${PAINEL_PORTA:-8080}
ACESSO_PORTA=${ACESSO_PORTA:-8444}

# ── Portas dos serviços ─────────────────────────────────────────────────
SQUID_PORTA_FWD=${SQUID_PORTA_FWD:-3127}
SQUID_PORTA=${SQUID_PORTA:-3128}
SQUID_PORTA_SSL=${SQUID_PORTA_SSL:-3129}
DNSMASQ_PORTA=${DNSMASQ_PORTA:-5353}
CONF
    chmod 644 "$GWOS_CONF"
}

# salvar_conf <chave> <valor> — altera uma chave preservando o resto
salvar_conf() {
    carregar_conf
    local chave="$1" valor="$2"
    printf -v "$chave" '%s' "$valor"
    escrever_conf
}

# Detecta rede a partir do sistema, sem perguntar nada.
# Usado quando um módulo é instalado isoladamente, sem o módulo 00-base.
detectar_rede() {
    local ifaces iface ip_cidr pref
    mapfile -t ifaces < <(listar_ifaces)

    IFACE_WAN="${IFACE_WAN:-$(ip route show default 2>/dev/null | awk '/default/{print $5}' | head -1)}"
    [ -n "${IFACE_WAN:-}" ] || IFACE_WAN="${ifaces[0]:-eth0}"

    if [ -z "${IFACE_LAN:-}" ]; then
        for iface in "${ifaces[@]}"; do
            [ "$iface" != "$IFACE_WAN" ] && { IFACE_LAN="$iface"; break; }
        done
    fi
    # Máquina de uma placa só: LAN = WAN (proxy/DNS isolado ainda funciona)
    IFACE_LAN="${IFACE_LAN:-$IFACE_WAN}"

    if [ -z "${IP_GATEWAY:-}" ] || [ -z "${REDE_LAN:-}" ]; then
        ip_cidr=$(ip -4 addr show "$IFACE_LAN" 2>/dev/null | awk '/inet /{print $2}' | head -1)
        if [ -n "$ip_cidr" ]; then
            IP_GATEWAY="${IP_GATEWAY:-$(echo "$ip_cidr" | cut -d/ -f1)}"
            pref=$(echo "$ip_cidr" | cut -d/ -f2)
            REDE_LAN="${REDE_LAN:-$(rede_de_ip "$IP_GATEWAY" "$pref")}"
        else
            IP_GATEWAY="${IP_GATEWAY:-192.168.1.1}"
            REDE_LAN="${REDE_LAN:-192.168.1.0/24}"
        fi
    fi
}

# Garante que /etc/gwos/gwos.conf existe. Nunca mexe em /etc/network/interfaces
# — só o módulo 00-base reconfigura a rede de verdade.
garantir_conf() {
    carregar_conf
    if [ ! -f "$GWOS_CONF" ]; then
        detectar_rede
        escrever_conf
        aviso "Sem ${GWOS_CONF} — parâmetros detectados automaticamente:"
        echo  "      WAN=${IFACE_WAN}  LAN=${IFACE_LAN}  rede=${REDE_LAN}  gateway=${IP_GATEWAY}"
        echo  "      Ajuste o arquivo (ou instale o módulo 00-base) se estiver errado."
        carregar_conf
    fi
    # Preenche buracos em conf antigo/parcial
    local antes="${IFACE_WAN:-}${IFACE_LAN:-}${REDE_LAN:-}${IP_GATEWAY:-}"
    detectar_rede
    [ "$antes" = "${IFACE_WAN}${IFACE_LAN}${REDE_LAN}${IP_GATEWAY}" ] || escrever_conf
    carregar_conf
}

# ----------------------------------------------------------------------------
# Identidade da máquina
# ----------------------------------------------------------------------------
# definir_hostname <nome> — troca o hostname e acerta o /etc/hosts.
# A entrada 127.0.1.1 importa: sem ela o sudo demora vários segundos a cada
# comando (tenta resolver o próprio nome e espera o timeout do DNS) e alguns
# serviços recusam subir.
definir_hostname() {
    local novo="$1" dominio="${2:-${DOMINIO_LOCAL:-local}}"
    [ -n "$novo" ] || return 1

    hostnamectl set-hostname "$novo" 2>/dev/null || echo "$novo" > /etc/hostname

    backup_arquivo /etc/hosts
    if grep -qE '^127\.0\.1\.1[[:space:]]' /etc/hosts 2>/dev/null; then
        sed -i "s|^127\.0\.1\.1[[:space:]].*|127.0.1.1	${novo}.${dominio} ${novo}|" /etc/hosts
    else
        printf '127.0.1.1	%s.%s %s
' "$novo" "$dominio" "$novo" >> /etc/hosts
    fi
    return 0
}

valida_hostname() {
    echo "$1" | grep -qE '^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$'
}

# ----------------------------------------------------------------------------
# Perfis de unidade
# ----------------------------------------------------------------------------
# Cada unidade tem DNS interno, servidor de hora e domínios próprios. Em vez
# de fixá-los no código, ficam em modulos/perfis/<unidade>.conf — assim o
# mesmo repositório serve a qualquer unidade sem editar script nenhum.
GWOS_PERFIS_DIR="${GWOS_MODULOS_DIR}/perfis"

listar_perfis() {
    [ -d "$GWOS_PERFIS_DIR" ] || return 0
    local arq
    for arq in "$GWOS_PERFIS_DIR"/*.conf; do
        [ -f "$arq" ] || continue
        basename "$arq" .conf
    done | sort
}

# carregar_perfil <nome> — define as variáveis do perfil no ambiente atual
carregar_perfil() {
    local arq="${GWOS_PERFIS_DIR}/${1}.conf"
    [ -f "$arq" ] || return 1
    # shellcheck disable=SC1090
    set -a; . "$arq"; set +a
    return 0
}

# Todas as redes internas conhecidas (principal + secundária), separadas por espaço
redes_internas() {
    local r="$REDE_LAN"
    [ "${REDE2_ATIVO:-0}" = "1" ] && [ -n "${REDE2_CIDR:-}" ] && r="$r $REDE2_CIDR"
    echo "$r"
}

# ----------------------------------------------------------------------------
# Registro de módulos
# ----------------------------------------------------------------------------
registrar_modulo() {
    local nome="$1"
    mkdir -p "$GWOS_MODULOS"
    cat > "${GWOS_MODULOS}/${nome}" <<REG
instalado_em=$(date '+%Y-%m-%d %H:%M:%S')
origem=${GWOS_MODULOS_DIR}
REG
}

desregistrar_modulo() { rm -f "${GWOS_MODULOS}/${1}"; }
modulo_registrado()   { [ -f "${GWOS_MODULOS}/${1}" ]; }

modulos_instalados() {
    [ -d "$GWOS_MODULOS" ] || return 0
    find "$GWOS_MODULOS" -maxdepth 1 -type f -printf '%f\n' 2>/dev/null | sort
}

# ----------------------------------------------------------------------------
# Detecção de serviços — marcador do módulo OU serviço presente na máquina
# ----------------------------------------------------------------------------
tem_bind9()    { modulo_registrado dns-bind9        || command -v named    >/dev/null 2>&1; }
tem_dnsmasq()  { modulo_registrado dns-interno      || [ -f /etc/dnsmasq.d/gwos.conf ]; }
tem_chrony()   { modulo_registrado hora-chrony      || command -v chronyd  >/dev/null 2>&1; }
tem_firewall() { modulo_registrado firewall-nftables|| command -v nft      >/dev/null 2>&1; }
tem_squid()    { modulo_registrado proxy-squid      || { command -v squid >/dev/null 2>&1 && [ -f /etc/squid/squid.conf ]; }; }
tem_banco()    { modulo_registrado banco-mariadb    || [ -f "$GWOS_DB_CONF" ]; }
tem_painel()   { modulo_registrado painel-web; }

# Squid com suporte a SSL Bump (certgen presente e CA gerada)
tem_ssl_bump() { [ -f /etc/squid/ssl_cert/gwos-ca.crt ]; }

# ----------------------------------------------------------------------------
# Pacotes e serviços
# ----------------------------------------------------------------------------
apt_atualizado=0
apt_update_uma_vez() {
    [ "$apt_atualizado" = "1" ] && return 0
    info "Atualizando lista de pacotes..."
    apt-get update -qq
    apt_atualizado=1
}

# instalar_pacotes <pkg>...  (obrigatórios — aborta se falhar)
instalar_pacotes() {
    apt_update_uma_vez
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@" \
        || erro "Falha ao instalar: $*"
}

# instalar_opcional <pkg>  (melhor esforço)
instalar_opcional() {
    apt_update_uma_vez
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$1" 2>/dev/null \
        && ok "Pacote opcional instalado: $1" \
        || aviso "Pacote opcional indisponível: $1"
}

# Quem está escutando numa porta TCP. Vazio se estiver livre.
# Cobre 0.0.0.0:80, *:80, [::]:80 e IP:80.
porta_em_uso() {
    local porta="$1"
    ss -lntp 2>/dev/null \
        | awk -v p=":${porta}\$" 'NR>1 && $4 ~ p {print $NF}' \
        | grep -oE '\(\("[^"]+' | sed 's/.*(("//' \
        | sort -u | paste -sd ', ' - || true
}

# Primeira porta livre a partir da lista dada
primeira_porta_livre() {
    local p
    for p in "$@"; do
        [ -z "$(porta_em_uso "$p")" ] && { echo "$p"; return 0; }
    done
    return 1
}

svc_ativar()  { systemctl enable --now "$1" >/dev/null 2>&1 || systemctl enable --now "$1"; }
svc_parar()   { systemctl stop "$1" 2>/dev/null || true; systemctl disable "$1" 2>/dev/null || true; }
svc_ativo()   { systemctl is-active --quiet "$1"; }

# ----------------------------------------------------------------------------
# Remoção segura de pacotes
# ----------------------------------------------------------------------------
# O GWOS costuma dividir a máquina com outras coisas — um portal atrás do mesmo
# nginx, um banco de outra aplicação, o dnsmasq do libvirt. Remover o pacote
# nesses casos derruba o vizinho. Estas funções detectam uso por terceiros e
# então o desinstalador nem oferece a remoção.
#
# MOTIVO_USO recebe a explicação quando o pacote está em uso.
# ----------------------------------------------------------------------------
MOTIVO_USO=""

# ----------------------------------------------------------------------------
# Versão do PHP
# ----------------------------------------------------------------------------
# Cada Debian traz uma versão diferente (bookworm: 8.2, trixie: 8.4). Usar a do
# próprio sistema evita depender do repositório sury.org, que é de terceiros e
# uma fonte a mais para quebrar numa atualização.
# ----------------------------------------------------------------------------
PHP_MINIMO="8.1"     # abaixo disto o painel não roda

# Versão que o apt oferece nesta distribuição (ex.: 8.2 no Debian 12)
detectar_php_disponivel() {
    local v
    # O metapacote 'php' aponta para a versão padrão da distribuição
    v=$(apt-cache depends php 2>/dev/null \
        | sed -n 's/.*Depends: php\([0-9]\+\.[0-9]\+\).*/\1/p' | head -1)
    if [ -n "$v" ]; then echo "$v"; return 0; fi

    for v in 8.4 8.3 8.2 8.1; do
        apt-cache show "php${v}" >/dev/null 2>&1 && { echo "$v"; return 0; }
    done
    return 1
}

# Versão já instalada nesta máquina
detectar_php_instalado() {
    local d v
    for d in /etc/php/*/fpm; do
        [ -d "$d" ] || continue
        v=$(basename "$(dirname "$d")")
        echo "$v"
        return 0
    done
    return 1
}

# php_versao_suficiente <tem> <minimo> — 0 se 'tem' >= 'minimo'
php_versao_suficiente() {
    local a="${1%%.*}" b="${1##*.}" c="${2%%.*}" d="${2##*.}"
    [ "$a" -gt "$c" ] && return 0
    [ "$a" -lt "$c" ] && return 1
    [ "$b" -ge "$d" ]
}

# Sites de terceiros no nginx — nem o do GWOS nem o 'default' de fábrica.
# O Debian habilita o 'default' em toda instalação nova; contá-lo faria uma
# máquina limpa parecer compartilhada.
nginx_outros_sites() {
    local n=0
    if [ -d /etc/nginx/sites-enabled ]; then
        n=$(find /etc/nginx/sites-enabled -maxdepth 1 \( -type f -o -type l \) \
              ! -name 'gwos' ! -name 'gwos-portal' ! -name 'portal-gateway' ! -name 'default' 2>/dev/null | wc -l)
    fi
    if [ -d /etc/nginx/conf.d ]; then
        n=$(( n + $(find /etc/nginx/conf.d -maxdepth 1 -name '*.conf' \
              ! -name 'gwos*' ! -name 'default*' 2>/dev/null | wc -l) ))
    fi
    echo "$n"
}

# Bancos que não são do GWOS
mariadb_outros_bancos() {
    mysql -sNe "SHOW DATABASES" 2>/dev/null \
        | grep -vxE 'information_schema|performance_schema|mysql|sys|gwos' \
        | paste -sd ', ' - || true
}

# pacote_em_uso <pacote> → 0 se há indício de uso por outra coisa
pacote_em_uso() {
    local pkg="$1" n outros
    MOTIVO_USO=""

    case "$pkg" in
        nginx*)
            n=$(nginx_outros_sites)
            if [ "${n:-0}" -gt 0 ]; then
                MOTIVO_USO="há ${n} site(s) além do GWOS em /etc/nginx"
                return 0
            fi ;;

        php*)
            n=$(nginx_outros_sites)
            if [ "${n:-0}" -gt 0 ]; then
                MOTIVO_USO="outros sites do nginx podem depender do PHP"
                return 0
            fi
            if [ -d /etc/apache2/sites-enabled ] && \
               [ -n "$(ls -A /etc/apache2/sites-enabled 2>/dev/null)" ]; then
                MOTIVO_USO="há sites do Apache nesta máquina"
                return 0
            fi ;;

        mariadb*|mysql*)
            outros=$(mariadb_outros_bancos)
            if [ -n "$outros" ]; then
                MOTIVO_USO="há outros bancos de dados: ${outros}"
                return 0
            fi ;;

        dnsmasq*)
            if [ -d /etc/libvirt ] || ip link show virbr0 &>/dev/null; then
                MOTIVO_USO="o libvirt usa dnsmasq para as redes virtuais"
                return 0
            fi
            n=$(find /etc/dnsmasq.d -maxdepth 1 -type f ! -name 'gwos.conf' 2>/dev/null | wc -l)
            if [ "${n:-0}" -gt 0 ]; then
                MOTIVO_USO="há outras configurações em /etc/dnsmasq.d"
                return 0
            fi ;;

        chrony*)
            MOTIVO_USO="é o relógio desta máquina — sem ele o horário deriva"
            return 0 ;;

        nftables*)
            if command -v docker >/dev/null 2>&1 || [ -d /etc/libvirt ]; then
                MOTIVO_USO="Docker ou libvirt dependem do nftables para suas redes"
                return 0
            fi ;;

        bind9*)
            if [ -s /etc/bind/named.conf.zonas-locais ] && \
               grep -qE '^\s*zone\s' /etc/bind/named.conf.zonas-locais 2>/dev/null; then
                MOTIVO_USO="há zonas suas em /etc/bind/named.conf.zonas-locais"
                return 0
            fi ;;
    esac

    # Genérico: a remoção arrastaria outros pacotes instalados?
    local colateral
    colateral=$(LC_ALL=C apt-get -s remove "$pkg" 2>/dev/null \
        | awk '/^The following packages will be REMOVED/{f=1;next} /^[A-Z]/{f=0} f' \
        | tr -s ' \n' ' ' | sed 's/^ *//;s/ *$//')
    if [ -n "$colateral" ]; then
        local extras
        extras=$(echo "$colateral" | tr ' ' '\n' | grep -vx "$pkg" | paste -sd ', ' -)
        if [ -n "$extras" ]; then
            MOTIVO_USO="removê-lo levaria junto: ${extras}"
            return 0
        fi
    fi

    return 1
}

# remover_pacotes <pkg>...
# Só oferece a remoção dos que não estão em uso por terceiros. Os demais são
# mantidos, com o motivo na tela — não há pergunta a responder errado.
remover_pacotes() {
    local pkg seguros=() bloqueados=()

    for pkg in "$@"; do
        dpkg -l "$pkg" 2>/dev/null | grep -q '^ii' || continue
        if pacote_em_uso "$pkg"; then
            bloqueados+=("${pkg}|${MOTIVO_USO}")
        else
            seguros+=("$pkg")
        fi
    done

    if [ ${#bloqueados[@]} -gt 0 ]; then
        echo ""
        aviso "Pacotes MANTIDOS porque outra coisa nesta máquina depende deles:"
        for pkg in "${bloqueados[@]}"; do
            printf "      %-18s %s\n" "${pkg%%|*}" "${pkg#*|}"
        done
        echo ""
    fi

    if [ ${#seguros[@]} -eq 0 ]; then
        info "Nenhum pacote exclusivo do GWOS a remover."
        return 0
    fi

    # Sem pergunta: quem mandou desinstalar já decidiu. O que protege o
    # vizinho é a checagem de uso acima, não uma confirmação a mais.
    info "Removendo: ${seguros[*]}"
    DEBIAN_FRONTEND=noninteractive apt-get remove --purge -y -qq "${seguros[@]}" 2>/dev/null || true
    apt-get autoremove -y -qq 2>/dev/null || true
    ok "Removidos: ${seguros[*]}"
}


backup_arquivo() {
    [ -f "$1" ] || return 0
    cp -a "$1" "${1}.bak.$(date +%Y%m%d%H%M%S)"
}

perguntar() {
    local __var="$1" __prompt="$2" __padrao="${3:-}" __resp
    read -rp "  ${__prompt} [${__padrao}]: " __resp
    printf -v "$__var" '%s' "${__resp:-$__padrao}"
}

# Com GWOS_SEM_PERGUNTAS=1 responde "sim" sozinho. Os desinstaladores usam isso
# para perguntar UMA vez no começo e depois seguir até o fim: quem decidiu
# desinstalar não quer confirmar cada passo.
confirmar() {
    local resp
    if [ "${GWOS_SEM_PERGUNTAS:-0}" = "1" ]; then
        echo "  $1 [s/N]: s  (automático)"
        return 0
    fi
    read -rp "  $1 [s/N]: " resp
    [[ "${resp:-N}" =~ ^[Ss]$ ]]
}

# Pergunta uma única vez e, a partir daí, o módulo segue sem interrupções.
confirmar_uma_vez() {
    [ "${GWOS_SEM_PERGUNTAS:-0}" = "1" ] && return 0
    confirmar "$1" || return 1
    export GWOS_SEM_PERGUNTAS=1
    return 0
}

# ----------------------------------------------------------------------------
# Ferramentas comuns no sistema
# ----------------------------------------------------------------------------
# Instala lib.sh e integrar.sh fora do repositório, para que a integração
# funcione mesmo que a pasta modulos/ seja apagada ou movida depois.
instalar_ferramentas_comuns() {
    mkdir -p "$GWOS_ETC" "$GWOS_MODULOS"
    local origem="${GWOS_MODULOS_DIR}/comum"
    [ -f "${origem}/lib.sh" ]      && install -m 644 "${origem}/lib.sh"      "${GWOS_ETC}/lib.sh"
    [ -f "${origem}/integrar.sh" ] && install -m 755 "${origem}/integrar.sh" /usr/local/sbin/gwos-integrar
    [ -f "${origem}/definir.sh" ]  && install -m 755 "${origem}/definir.sh"  /usr/local/sbin/gwos-definir
    [ -f "${origem}/zona.sh" ]     && install -m 755 "${origem}/zona.sh"     /usr/local/sbin/gwos-zona
    [ -f "${origem}/servico.sh" ]  && install -m 755 "${origem}/servico.sh"  /usr/local/sbin/gwos-servico
    return 0
}

# Re-costura as integrações entre os módulos presentes.
integrar() {
    if [ -x /usr/local/sbin/gwos-integrar ]; then
        /usr/local/sbin/gwos-integrar "$@"
    elif [ -f "${GWOS_MODULOS_DIR}/comum/integrar.sh" ]; then
        bash "${GWOS_MODULOS_DIR}/comum/integrar.sh" "$@"
    else
        aviso "integrar.sh não encontrado — integração entre módulos não executada."
    fi
}

# Cabeçalho padrão de módulo: root + conf + ferramentas comuns
iniciar_modulo() {
    local nome="$1"
    exigir_root
    titulo "══ Módulo: ${nome} ══"
    instalar_ferramentas_comuns
    garantir_conf
}

# ---------------------------------------------------------------------------
# Senha do administrador do painel: digitada por quem instala (não aleatória).
# Resultado em ADMIN_SENHA. Fontes, em ordem: variável GWOS_ADMIN_SENHA
# (instalação automatizada) ou pergunta oculta no terminal, com confirmação.
# ---------------------------------------------------------------------------
pedir_senha_admin() {
    ADMIN_SENHA=""
    if [ -n "${GWOS_ADMIN_SENHA:-}" ]; then
        if [ "${#GWOS_ADMIN_SENHA}" -lt 8 ]; then
            erro "GWOS_ADMIN_SENHA precisa ter ao menos 8 caracteres."
        fi
        ADMIN_SENHA="$GWOS_ADMIN_SENHA"
        return 0
    fi
    if [ ! -r /dev/tty ] || [ ! -w /dev/tty ]; then
        erro "Sem terminal para pedir a senha do administrador. Exporte GWOS_ADMIN_SENHA (mín. 8 caracteres) e rode de novo."
    fi
    local s1 s2 tentativas=0
    echo "" > /dev/tty
    echo -e "${BOLD}Defina a senha do administrador do painel (admin@gwos.local).${NC}" > /dev/tty
    while [ "$tentativas" -lt 5 ]; do
        printf 'Senha (mín. 8 caracteres): ' > /dev/tty;  IFS= read -rs s1 < /dev/tty; echo "" > /dev/tty
        printf 'Repita a senha          : ' > /dev/tty;  IFS= read -rs s2 < /dev/tty; echo "" > /dev/tty
        if [ "${#s1}" -lt 8 ]; then
            echo -e "${RED}[!]${NC} A senha precisa ter ao menos 8 caracteres." > /dev/tty
        elif [ "$s1" != "$s2" ]; then
            echo -e "${RED}[!]${NC} As senhas não conferem." > /dev/tty
        else
            ADMIN_SENHA="$s1"
            return 0
        fi
        tentativas=$((tentativas + 1))
    done
    erro "Senha do administrador não definida após 5 tentativas."
}
