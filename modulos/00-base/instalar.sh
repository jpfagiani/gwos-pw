#!/bin/bash
# ============================================================================
# GWOS — Módulo 00-base: identidade e rede da máquina
# ============================================================================
# É o único módulo que mexe em /etc/hostname e /etc/network/interfaces. Todos
# os outros só LEEM os parâmetros em /etc/gwos/gwos.conf.
#
# Dois modos:
#
#   GATEWAY  (duas placas) — roteia da internet para a rede interna. Configura
#            WAN, LAN, rede secundária e liga o encaminhamento de pacotes.
#
#   SERVIDOR (uma placa)   — só serve (DNS, hora, proxy, painel). Configura
#            nome, IP e domínio; não roteia nada.
#
# Antes este módulo simplesmente recusava máquinas de uma placa, e com isso
# ninguém perguntava nome nem IP num servidor de DNS. Não é a mesma coisa
# "não ser gateway" e "não precisar de configuração de rede".
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

exigir_root
titulo "══ Módulo 00-base — identidade e rede da máquina ══"
instalar_ferramentas_comuns
carregar_conf

instalar_pacotes ifupdown iproute2 sudo curl ca-certificates

# ===========================================================================
# 1. Interfaces disponíveis
# ===========================================================================
titulo "── Interfaces de rede ──"
echo ""

mapfile -t IFACES < <(listar_ifaces)
[ ${#IFACES[@]} -gt 0 ] || erro "Nenhuma interface de rede encontrada."

printf "  %-4s %-14s %-22s %-8s %s\n" "Nº" "Interface" "IP/Máscara" "Status" "Velocidade"
echo "  ──────────────────────────────────────────────────────────────"
IDX=1
for iface in "${IFACES[@]}"; do
    IP_CIDR=$(ip -4 addr show "$iface" 2>/dev/null | awk '/inet /{print $2}' | head -1)
    IP_CIDR="${IP_CIDR:-sem IP}"
    STATUS=$(cat "/sys/class/net/${iface}/operstate" 2>/dev/null || echo "?")
    case "$STATUS" in
        up)   SFMT="${GREEN}UP${NC}"   ;;
        down) SFMT="${RED}DOWN${NC}"   ;;
        *)    SFMT="${YELLOW}${STATUS}${NC}" ;;
    esac
    SPEED=$(cat "/sys/class/net/${iface}/speed" 2>/dev/null || echo "?")
    if [[ "$SPEED" =~ ^-?[0-9]+$ ]] && [ "$SPEED" -gt 0 ]; then SPEED="${SPEED} Mb/s"; else SPEED="?"; fi
    printf "  %-4s %-14s %-22s " "$IDX" "$iface" "$IP_CIDR"
    echo -e "${SFMT}    ${SPEED}"
    IDX=$((IDX + 1))
done
echo ""

selecionar_iface() {
    local PROMPT="$1" SUGESTAO="$2" RESULTADO="" ENT POS
    while true; do
        [ -n "$SUGESTAO" ] && \
            echo -e "  Sugestão: ${BOLD}${SUGESTAO}${NC}  (Enter para confirmar, ou digite outra)" >&2
        read -rp "  $PROMPT [${SUGESTAO:-nome ou número}]: " ENT
        ENT="${ENT:-$SUGESTAO}"
        if [[ "$ENT" =~ ^[0-9]+$ ]]; then
            POS=$((ENT - 1))
            if [ "$POS" -ge 0 ] && [ "$POS" -lt ${#IFACES[@]} ]; then
                RESULTADO="${IFACES[$POS]}"; break
            fi
            echo -e "  ${RED}Número inválido. Escolha entre 1 e ${#IFACES[@]}.${NC}" >&2
        elif [ -n "$ENT" ]; then
            if ip link show "$ENT" &>/dev/null; then RESULTADO="$ENT"; break; fi
            echo -e "  ${RED}Interface '${ENT}' não encontrada.${NC}" >&2
        else
            echo -e "  ${RED}Informe a interface.${NC}" >&2
        fi
    done
    echo "$RESULTADO"
}

# ===========================================================================
# 2. Papel desta máquina
# ===========================================================================
titulo "── Papel desta máquina ──"
echo ""

if [ ${#IFACES[@]} -lt 2 ]; then
    PAPEL="servidor"
    aviso "Uma única interface (${IFACES[0]}) — esta máquina não pode ser gateway."
    echo  "      Um gateway roteia de uma placa para a outra."
    echo  "      Modo SERVIDOR: nome, IP e domínio; sem roteamento."
else
    echo "  ${BOLD}gateway${NC}  — roteia a internet para a rede interna (2 placas)"
    echo -e "  ${BOLD}servidor${NC} — só serve DNS, hora, proxy ou painel"
    echo ""
    if confirmar "Esta máquina é o gateway da rede?"; then
        PAPEL="gateway"
    else
        PAPEL="servidor"
    fi
fi
ok "Papel: ${PAPEL}"

# ===========================================================================
# 3 a 7. Perguntas  —  tudo daqui até "Aplicar esta configuração?"
# ---------------------------------------------------------------------------
# Fica dentro deste laço: quem responde "refazer" no resumo volta para cá, com
# as respostas anteriores já como padrão. Antes, responder "não" no resumo
# encerrava o módulo com sucesso (exit 0) e a instalação seguia SEM o
# /etc/gwos/gwos.conf — os módulos seguintes então adivinhavam a rede, e
# adivinhavam errado (WAN=LAN, gateway = o IP antigo da máquina).
#
# O corpo não é indentado de propósito: são ~230 linhas, e indentar tudo
# esconderia a mudança real no meio de um diff gigante.
# ===========================================================================
while true; do

# ===========================================================================
# 3. Perfil da unidade
# ===========================================================================
titulo "── Perfil da unidade ──"
echo ""

mapfile -t PERFIS < <(listar_perfis)
if [ ${#PERFIS[@]} -gt 0 ]; then
    echo "  Perfis disponíveis:"
    IDX=1
    for perfil in "${PERFIS[@]}"; do
        printf "    %d) %s\n" "$IDX" "$perfil"
        IDX=$((IDX + 1))
    done
    printf "    %d) %s\n" "$IDX" "nenhum — perguntar tudo"
    echo ""
    perguntar ESCOLHA "Perfil" "${ESCOLHA:-1}"

    if [[ "$ESCOLHA" =~ ^[0-9]+$ ]] && [ "$ESCOLHA" -ge 1 ] && [ "$ESCOLHA" -le ${#PERFIS[@]} ]; then
        PERFIL="${PERFIS[$((ESCOLHA - 1))]}"
        carregar_perfil "$PERFIL" && ok "Perfil '${PERFIL}' carregado." \
            || aviso "Não foi possível ler o perfil '${PERFIL}'."
    else
        info "Sem perfil — os valores serão perguntados."
    fi
else
    aviso "Nenhum perfil em modulos/perfis/ — os valores serão perguntados."
fi

# ===========================================================================
# 4. Nome do servidor e domínio
# ===========================================================================
titulo "── Identidade ──"
echo ""

perguntar NOME_SERVIDOR "Nome deste servidor (hostname)" \
          "${NOME_SERVIDOR:-$(hostname -s 2>/dev/null || echo servidor)}"
while ! valida_hostname "$NOME_SERVIDOR"; do
    aviso "Nome inválido: '${NOME_SERVIDOR}' — só letras, números e hífen."
    perguntar NOME_SERVIDOR "Nome deste servidor" "$(hostname -s 2>/dev/null || echo servidor)"
done

perguntar DOMINIO_LOCAL "Domínio dos nomes da LAN" "${DOMINIO_LOCAL:-local}"
ok "Este servidor será ${NOME_SERVIDOR}.${DOMINIO_LOCAL}"

# ===========================================================================
# 5. Rede
# ===========================================================================
if [ "$PAPEL" = "gateway" ]; then
    # ─── Modo gateway: WAN + LAN ───────────────────────────────────────────
    titulo "── Interface WAN (saída para a Internet) ──"
    echo ""
    WAN_AUTO=$(ip route show default 2>/dev/null | awk '/default/{print $5}' | head -1 || true)
    IFACE_WAN=$(selecionar_iface "Interface WAN" "$WAN_AUTO")
    ok "WAN: $IFACE_WAN"

    titulo "── Interface LAN (rede interna) ──"
    echo ""
    LAN_AUTO=""
    for iface in "${IFACES[@]}"; do
        [ "$iface" != "$IFACE_WAN" ] && { LAN_AUTO="$iface"; break; }
    done
    IFACE_LAN=$(selecionar_iface "Interface LAN" "$LAN_AUTO")
    if [ "$IFACE_LAN" = "$IFACE_WAN" ]; then
        echo ""
        aviso "WAN e LAN não podem ser a mesma interface — um gateway roteia de"
        aviso "uma placa para a outra."
        echo "  Interfaces disponíveis: ${IFACES[*]}"
        echo ""
        # Dentro do laço de perguntas: volta ao início em vez de abortar.
        continue
    fi
    ok "LAN: $IFACE_LAN"

    titulo "── Rede interna ──"
    echo ""
    IP_LAN_CIDR=$(ip -4 addr show "$IFACE_LAN" 2>/dev/null | awk '/inet /{print $2}' | head -1 || true)
    if [ -n "$IP_LAN_CIDR" ]; then
        IP_SUGERIDO=$(echo "$IP_LAN_CIDR" | cut -d/ -f1)
        REDE_SUGERIDA=$(rede_de_ip "$IP_SUGERIDO" "$(echo "$IP_LAN_CIDR" | cut -d/ -f2)")
    else
        REDE_SUGERIDA="192.168.1.0/24"; IP_SUGERIDO="192.168.1.1"
    fi

    perguntar REDE_LAN "Rede interna em CIDR" "$REDE_SUGERIDA"
    while ! valida_cidr "$REDE_LAN"; do
        aviso "Rede inválida: '${REDE_LAN}' — use CIDR (ex: 192.168.1.0/24)"
        perguntar REDE_LAN "Rede interna em CIDR" "$REDE_SUGERIDA"
    done

    perguntar IP_GATEWAY "IP deste gateway na rede interna" "$IP_SUGERIDO"
    while ! valida_ip "$IP_GATEWAY" || ! ip_na_rede "$IP_GATEWAY" "$REDE_LAN"; do
        if ! valida_ip "$IP_GATEWAY"; then
            aviso "IP inválido: '${IP_GATEWAY}'"
        else
            aviso "${IP_GATEWAY} não pertence à rede ${REDE_LAN} que você declarou."
        fi
        perguntar IP_GATEWAY "IP deste gateway na rede interna" "$IP_SUGERIDO"
    done
    ok "Rede: $REDE_LAN  |  IP: $IP_GATEWAY"

    # Rede secundária
    titulo "── Rede secundária / alias (opcional) ──"
    echo ""
    echo "  IP secundário na LAN para rotear entre duas sub-redes."
    echo "  Exemplo: GWOS em 172.14.29.10 + alias 10.14.29.254 para a rede 10.x"
    echo ""
    REDE2_ATIVO=0; IP_ALIAS=""; REDE2_MASK="255.255.255.0"; REDE2_CIDR=""; REDE2_PREFIX=24
    if confirmar "Configurar rede secundária?"; then
        REDE2_ATIVO=1
        perguntar IP_ALIAS "IP do alias (ex: 10.14.29.254)" ""
        while ! valida_ip "$IP_ALIAS"; do
            aviso "IP inválido."
            perguntar IP_ALIAS "IP do alias" ""
        done
        perguntar REDE2_MASK "Máscara da rede secundária" "255.255.255.0"
        REDE2_PREFIX=$(prefixo_de_mascara "$REDE2_MASK")
        REDE2_CIDR=$(rede_de_ip "$IP_ALIAS" "$REDE2_PREFIX")
        ok "Alias: ${IFACE_LAN}:1  IP: ${IP_ALIAS}  Rede: ${REDE2_CIDR}"
    fi

    # WAN
    titulo "── Saída para a Internet (WAN) ──"
    echo ""
    WAN_IP_ATUAL=$(ip -4 addr show "$IFACE_WAN" 2>/dev/null | awk '/inet /{print $2}' | head -1 || true)
    WAN_GW_ATUAL=$(ip route show default 2>/dev/null | awk '/default/{print $3}' | head -1 || true)
    echo -e "  IP atual da WAN  : ${BOLD}${WAN_IP_ATUAL:-sem IP}${NC}"
    echo -e "  Gateway WAN atual: ${BOLD}${WAN_GW_ATUAL:-não detectado}${NC}"
    echo ""
    perguntar WAN_DHCP "WAN usa DHCP? (S/n)" "S"
    if [[ "$WAN_DHCP" =~ ^[Ss]$ ]]; then
        WAN_MODO="dhcp"; WAN_IP=""; WAN_MASK=""; WAN_GW=""; WAN_DNS=""
        ok "WAN: DHCP"
    else
        WAN_MODO="static"
        WAN_PREF=$(echo "$WAN_IP_ATUAL" | cut -d/ -f2)
        _WAN_IP_SUG="$(echo "$WAN_IP_ATUAL" | cut -d/ -f1)"
        perguntar WAN_IP "IP estático WAN" "$_WAN_IP_SUG"
        while ! valida_ip "$WAN_IP"; do
            aviso "IP inválido: '${WAN_IP}'"
            perguntar WAN_IP "IP estático WAN" "$_WAN_IP_SUG"
        done

        _WAN_MASK_SUG="$(mascara_de_prefixo "${WAN_PREF:-24}")"
        perguntar WAN_MASK "Máscara de rede" "$_WAN_MASK_SUG"
        while ! valida_mascara "$WAN_MASK"; do
            aviso "Máscara inválida: '${WAN_MASK}' — ex.: 255.255.255.0"
            perguntar WAN_MASK "Máscara de rede" "$_WAN_MASK_SUG"
        done
        WAN_PREF=$(prefixo_de_mascara "$WAN_MASK")

        # Sem gateway padrão o servidor não alcança a internet — e é essa a
        # razão de ele existir. Vazio é aceito, mas com o aviso na tela, e
        # nesse caso a linha 'gateway' NÃO vai para o interfaces: escrever
        # "gateway" sem valor faz o ifup abortar a interface inteira no boot.
        perguntar WAN_GW "Gateway padrão (vazio = sem rota para a internet)" "$WAN_GW_ATUAL"
        while [ -n "$WAN_GW" ] && ! gateway_valido "$WAN_GW" "$WAN_IP" "$WAN_PREF"; do
            perguntar WAN_GW "Gateway padrão (vazio = sem rota para a internet)" "$WAN_GW_ATUAL"
        done
        [ -n "$WAN_GW" ] || aviso "Sem gateway padrão: este servidor não sairá para a internet."
        ok "WAN: $WAN_IP / $WAN_MASK via ${WAN_GW:-sem gateway}"
    fi

else
    # ─── Modo servidor: uma interface ──────────────────────────────────────
    titulo "── Interface de rede ──"
    echo ""
    if [ ${#IFACES[@]} -eq 1 ]; then
        IFACE_LAN="${IFACES[0]}"
        ok "Interface: $IFACE_LAN"
    else
        IFACE_LAN=$(selecionar_iface "Interface deste servidor" "${IFACES[0]}")
    fi
    IFACE_WAN="$IFACE_LAN"   # servidor não roteia: a mesma placa para tudo

    titulo "── Endereço deste servidor ──"
    echo ""
    IP_ATUAL_CIDR=$(ip -4 addr show "$IFACE_LAN" 2>/dev/null | awk '/inet /{print $2}' | head -1 || true)
    GW_ATUAL=$(ip route show default 2>/dev/null | awk '/default/{print $3}' | head -1 || true)
    echo -e "  IP atual      : ${BOLD}${IP_ATUAL_CIDR:-sem IP}${NC}"
    echo -e "  Gateway atual : ${BOLD}${GW_ATUAL:-não detectado}${NC}"
    echo ""
    echo "  1) Manter como está (não mexer na configuração de rede)"
    echo "  2) IP fixo"
    echo "  3) DHCP"
    echo ""
    perguntar MODO_IP "Endereçamento" "1"

    case "$MODO_IP" in
        2)
            SRV_MODO="static"
            IP_SUGERIDO=$(echo "$IP_ATUAL_CIDR" | cut -d/ -f1)
            PREF_ATUAL=$(echo "$IP_ATUAL_CIDR" | cut -d/ -f2)
            perguntar IP_GATEWAY "IP deste servidor" "${IP_SUGERIDO:-192.168.1.10}"
            while ! valida_ip "$IP_GATEWAY"; do
                aviso "IP inválido: '${IP_GATEWAY}'"
                perguntar IP_GATEWAY "IP deste servidor" "${IP_SUGERIDO:-192.168.1.10}"
            done
            perguntar SRV_MASK "Máscara de rede" "$(mascara_de_prefixo "${PREF_ATUAL:-24}")"
            SRV_PREF=$(prefixo_de_mascara "$SRV_MASK")
            REDE_LAN=$(rede_de_ip "$IP_GATEWAY" "$SRV_PREF")
            perguntar SRV_GW "Gateway da rede (vazio = sem gateway)" "${GW_ATUAL:-}"
            while [ -n "$SRV_GW" ] && ! gateway_valido "$SRV_GW" "$IP_GATEWAY" "$SRV_PREF"; do
                perguntar SRV_GW "Gateway da rede (vazio = sem gateway)" "${GW_ATUAL:-}"
            done
            ok "IP fixo: ${IP_GATEWAY}/${SRV_PREF}  rede ${REDE_LAN}  via ${SRV_GW:-sem gateway}"
            ;;
        3)
            SRV_MODO="dhcp"
            IP_GATEWAY=$(echo "$IP_ATUAL_CIDR" | cut -d/ -f1)
            REDE_LAN=$(rede_de_ip "${IP_GATEWAY:-192.168.1.10}" "$(echo "${IP_ATUAL_CIDR:-/24}" | cut -d/ -f2)")
            ok "DHCP — o IP pode mudar; os nomes internos apontam para o IP atual."
            ;;
        *)
            SRV_MODO="manter"
            IP_GATEWAY=$(echo "$IP_ATUAL_CIDR" | cut -d/ -f1)
            REDE_LAN=$(rede_de_ip "${IP_GATEWAY:-192.168.1.10}" "$(echo "${IP_ATUAL_CIDR:-x/24}" | cut -d/ -f2)")
            if [ -z "$IP_GATEWAY" ]; then
                aviso "A interface ${IFACE_LAN} não tem IP — 'manter como está' não dá."
                echo  "      Escolha 2 (IP fixo) ou 3 (DHCP)."
                echo ""
                continue
            fi
            ok "Rede mantida: ${IP_GATEWAY} na rede ${REDE_LAN}"
            ;;
    esac
    REDE2_ATIVO=0; IP_ALIAS=""; REDE2_CIDR=""; REDE2_MASK="255.255.255.0"
fi

# ===========================================================================
# 6. DNS e hora
# ===========================================================================
titulo "── DNS e hora ──"
echo ""
echo "  Resolvers para onde vai tudo que não é domínio interno."
perguntar DNS_FORWARDERS "Resolvers (separados por espaço)" \
          "${DNS_FORWARDERS:-8.8.8.8 8.8.4.4 1.1.1.1}"

echo ""
echo "  Domínios com DNS próprio, no formato dominio:ip — separados por espaço."
echo "  Ex.: sistema.sp.gov.br:10.1.6.222   (vazio = nenhum)"
perguntar ZONAS_INTERNAS "Domínios internos" "${ZONAS_INTERNAS:-}"

echo ""
echo "  Servidor de hora da rede (vazio = só o pool público)."
perguntar NTP_SERVIDORES "Servidor NTP" "${NTP_SERVIDORES:-}"
perguntar NTP_POOL       "Pool de reserva" "${NTP_POOL:-pool.ntp.br}"

# ===========================================================================
# 7. Resumo
# ===========================================================================
titulo "── Resumo ──"
echo ""
echo -e "  Papel          : ${BOLD}${PAPEL}${NC}"
echo -e "  Servidor       : ${BOLD}${NOME_SERVIDOR}.${DOMINIO_LOCAL}${NC}"
if [ "$PAPEL" = "gateway" ]; then
    echo -e "  WAN            : ${BOLD}${IFACE_WAN}${NC} (${WAN_MODO})"
    echo -e "  LAN            : ${BOLD}${IFACE_LAN}${NC}"
    echo -e "  Rede interna   : ${BOLD}${REDE_LAN}${NC}"
    echo -e "  IP do gateway  : ${BOLD}${IP_GATEWAY}${NC}"
    [ "$REDE2_ATIVO" = "1" ] && \
        echo -e "  Rede 2         : ${BOLD}${REDE2_CIDR}  (${IFACE_LAN}:1 → ${IP_ALIAS})${NC}"
else
    echo -e "  Interface      : ${BOLD}${IFACE_LAN}${NC} (${SRV_MODO})"
    echo -e "  IP             : ${BOLD}${IP_GATEWAY}${NC}"
    echo -e "  Rede           : ${BOLD}${REDE_LAN}${NC}"
fi
echo -e "  Resolvers      : ${BOLD}${DNS_FORWARDERS}${NC}"
[ -n "${ZONAS_INTERNAS:-}" ] && echo -e "  Dom. internos  : ${BOLD}${ZONAS_INTERNAS}${NC}"
echo -e "  Hora           : ${BOLD}${NTP_SERVIDORES:-(só pool)} + ${NTP_POOL}${NC}"
echo ""
if [ "${GWOS_SEM_PERGUNTAS:-0}" = "1" ]; then
    echo "  Aplicar esta configuração? [s/n/c]: s  (automático)"
    break
fi

RESPOSTA=""
while [[ ! "$RESPOSTA" =~ ^[SsNnCc]$ ]]; do
    read -rp "  [s] aplicar   [n] refazer as perguntas   [c] cancelar: " RESPOSTA
done

case "$RESPOSTA" in
    [Ss]) break ;;
    [Cc]) echo ""
          echo "Cancelado — nada foi alterado."
          # 3 = cancelado pelo usuário. O orquestrador para a instalação aqui,
          # em vez de seguir para módulos que dependem deste.
          exit 3 ;;
    *)    echo ""
          aviso "Vamos refazer as perguntas — as respostas atuais ficam como padrão."
          echo "" ;;
esac

done   # fim do laço de perguntas

# ===========================================================================
# 8. Hostname
# ===========================================================================
titulo "══ Identidade ══"
definir_hostname "$NOME_SERVIDOR" "$DOMINIO_LOCAL" \
    && ok "Hostname: ${NOME_SERVIDOR}.${DOMINIO_LOCAL} (e /etc/hosts ajustado)" \
    || aviso "Não foi possível definir o hostname."

# ===========================================================================
# 9. Preparação do hardware (só em máquina física)
# ===========================================================================
titulo "══ Preparação do hardware ══"

VIRT="$(systemd-detect-virt 2>/dev/null || echo desconhecido)"
if [ "$VIRT" != "none" ]; then
    info "Ambiente virtualizado (${VIRT}) — firmware de hardware não é necessário."
else
    info "Máquina real detectada — habilitando firmware e microcode..."

    if ! grep -Rqs "non-free-firmware" /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null; then
        if [ -f /etc/apt/sources.list.d/debian.sources ]; then
            sed -i 's/^Components: \(.*\)/Components: \1 non-free-firmware/' \
                /etc/apt/sources.list.d/debian.sources
        elif [ -f /etc/apt/sources.list ]; then
            sed -Ei '/debian\.org/ s/^(deb[[:space:]].*[[:space:]]main)([[:space:]]|$)/\1 non-free-firmware\2/' \
                /etc/apt/sources.list
        fi
        apt-get update -qq
        apt_atualizado=1
        ok "Componente non-free-firmware habilitado."
    fi

    for pkg in firmware-linux firmware-realtek firmware-misc-nonfree; do
        instalar_opcional "$pkg"
    done

    CPU_VENDOR=$(grep -m1 vendor_id /proc/cpuinfo | awk '{print $3}' || true)
    case "$CPU_VENDOR" in
        GenuineIntel) instalar_opcional intel-microcode ;;
        AuthenticAMD) instalar_opcional amd64-microcode ;;
        *)            aviso "CPU não identificada (${CPU_VENDOR:-vazio}) — microcode não instalado." ;;
    esac

    if dmesg 2>/dev/null | grep -iE "firmware.*(failed|error)|failed to load firmware" | head -3 | grep -q .; then
        aviso "O kernel registrou falhas de firmware no boot atual — reinicie após a instalação."
    fi

    if systemctl is-enabled NetworkManager &>/dev/null; then
        systemctl disable --now NetworkManager 2>/dev/null || true
        aviso "NetworkManager desativado — as interfaces passam ao controle do ifupdown."
    fi
fi

# ===========================================================================
# 10. /etc/network/interfaces
# ===========================================================================
if [ "$PAPEL" = "servidor" ] && [ "$SRV_MODO" = "manter" ]; then
    info "Configuração de rede mantida como está — nada alterado."
else
    titulo "══ Configuração de rede ══"
    backup_arquivo /etc/network/interfaces

    {
        echo "# /etc/network/interfaces — gerado pelo módulo 00-base do GWOS"
        echo "# em $(date '+%Y-%m-%d %H:%M:%S'), modo ${PAPEL}."
        echo "# Para trocar o IP use 'gwos ip', nunca edite à mão."
        echo ""
        echo "source /etc/network/interfaces.d/*"
        echo ""
        echo "auto lo"
        echo "iface lo inet loopback"
        echo ""
    } > /etc/network/interfaces

    if [ "$PAPEL" = "gateway" ]; then
        {
            echo "# WAN — saída para a internet"
            echo "auto ${IFACE_WAN}"
            if [ "$WAN_MODO" = "dhcp" ]; then
                echo "iface ${IFACE_WAN} inet dhcp"
            else
                echo "iface ${IFACE_WAN} inet static"
                echo "    address ${WAN_IP}"
                echo "    netmask ${WAN_MASK}"
                [ -n "${WAN_GW:-}" ] && echo "    gateway ${WAN_GW}"
            fi
            echo ""
            echo "# LAN — rede interna (IP fixo — este servidor é o gateway)"
            echo "auto ${IFACE_LAN}"
            echo "iface ${IFACE_LAN} inet static"
            echo "    address ${IP_GATEWAY}"
            echo "    netmask $(mascara_de_prefixo "$(echo "$REDE_LAN" | cut -d/ -f2)")"
            echo ""
        } >> /etc/network/interfaces

        if [ "$REDE2_ATIVO" = "1" ]; then
            {
                echo "# Alias secundário — roteamento para a rede ${REDE2_CIDR}"
                echo "auto ${IFACE_LAN}:1"
                echo "iface ${IFACE_LAN}:1 inet static"
                echo "    address ${IP_ALIAS}"
                echo "    netmask ${REDE2_MASK}"
                echo ""
            } >> /etc/network/interfaces
        fi
    else
        {
            echo "# Interface única — este servidor não roteia"
            echo "auto ${IFACE_LAN}"
            if [ "$SRV_MODO" = "dhcp" ]; then
                echo "iface ${IFACE_LAN} inet dhcp"
            else
                echo "iface ${IFACE_LAN} inet static"
                echo "    address ${IP_GATEWAY}"
                echo "    netmask ${SRV_MASK}"
                [ -n "${SRV_GW:-}" ] && echo "    gateway ${SRV_GW}"
            fi
            echo ""
        } >> /etc/network/interfaces
    fi
    ok "/etc/network/interfaces gerado."

    # Fixa o nome das interfaces pelo MAC (só em máquina real)
    if [ "$VIRT" = "none" ]; then
        mkdir -p /etc/systemd/network
        for IFPIN in $(printf '%s\n%s\n' "$IFACE_WAN" "$IFACE_LAN" | sort -u); do
            MACPIN=$(cat "/sys/class/net/${IFPIN}/address" 2>/dev/null || true)
            if [ -n "$MACPIN" ] && [ "$MACPIN" != "00:00:00:00:00:00" ]; then
                cat > "/etc/systemd/network/70-gwos-${IFPIN}.link" <<LINKEOF
# Gerado pelo GWOS — mantém o nome '${IFPIN}' amarrado a esta placa física.
# Se trocar a placa de rede, apague este arquivo e reexecute o módulo 00-base.
[Match]
MACAddress=${MACPIN}

[Link]
Name=${IFPIN}
LINKEOF
                ok "Nome '${IFPIN}' fixado ao MAC ${MACPIN}."
            fi
        done
        update-initramfs -u -k all >/dev/null 2>&1 || true
    fi

    # Aplica sem reiniciar o serviço de rede (não derruba a sessão SSH)
    if [ "$PAPEL" = "gateway" ]; then
        LAN_PREF=$(echo "$REDE_LAN" | cut -d/ -f2)
        ip addr flush dev "$IFACE_LAN" 2>/dev/null || true
        ip addr add "${IP_GATEWAY}/${LAN_PREF}" dev "$IFACE_LAN" 2>/dev/null || true
        ip link set "$IFACE_LAN" up 2>/dev/null || true
        if [ "$REDE2_ATIVO" = "1" ]; then
            ip addr add "${IP_ALIAS}/${REDE2_PREFIX}" dev "$IFACE_LAN" 2>/dev/null || true
        fi
        if [ "$WAN_MODO" = "static" ]; then
            ip addr flush dev "$IFACE_WAN" 2>/dev/null || true
            ip addr add "${WAN_IP}/${WAN_PREF:-24}" dev "$IFACE_WAN" 2>/dev/null || true
            ip link set "$IFACE_WAN" up 2>/dev/null || true
            ip route replace default via "$WAN_GW" dev "$IFACE_WAN" 2>/dev/null || true
        fi
    elif [ "$SRV_MODO" = "static" ]; then
        # Adiciona o novo antes de remover o antigo — a sessão SSH não cai
        ip addr add "${IP_GATEWAY}/${SRV_PREF}" dev "$IFACE_LAN" 2>/dev/null || true
        ip link set "$IFACE_LAN" up 2>/dev/null || true
        [ -n "${SRV_GW:-}" ] && ip route replace default via "$SRV_GW" dev "$IFACE_LAN" 2>/dev/null || true
        aviso "O IP anterior continua ativo até o próximo reboot — assim a sessão não cai."
    fi
    ok "Configuração de rede aplicada."
fi

# ===========================================================================
# 11. Encaminhamento de pacotes — só no gateway
# ===========================================================================
if [ "$PAPEL" = "gateway" ]; then
    cat > /etc/sysctl.d/90-gwos-base.conf <<SYSCTL
# GWOS — parâmetros de kernel do gateway (módulo 00-base)
net.ipv4.ip_forward = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.tcp_syncookies = 1
SYSCTL
    sysctl -p /etc/sysctl.d/90-gwos-base.conf >/dev/null
    ok "Encaminhamento de pacotes ativado."
else
    # Um servidor que roteia sem querer confunde diagnóstico de rede.
    rm -f /etc/sysctl.d/90-gwos-base.conf
    info "Modo servidor — encaminhamento de pacotes não é habilitado."
fi

# ===========================================================================
# 12. Estado compartilhado
# ===========================================================================
escrever_conf
ok "Estado gravado em ${GWOS_CONF}."

registrar_modulo base
integrar

echo ""
ok "Módulo 00-base instalado (modo ${PAPEL})."
if [ "$PAPEL" = "gateway" ]; then
    echo -e "  Próximos: ${BOLD}10-banco-mariadb 20-dns-bind9 25-dns-interno-dnsmasq${NC}"
    echo -e "            ${BOLD}30-hora-chrony 40-firewall-nftables 50-proxy-squid 60-painel-web${NC}"
else
    echo -e "  Próximos: ${BOLD}20-dns-bind9 25-dns-interno-dnsmasq 30-hora-chrony 60-painel-web${NC}"
    echo    "  (firewall e NAT são do gateway — não se aplicam aqui)"
fi
echo ""
