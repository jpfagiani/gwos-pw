#!/bin/bash
# ============================================================================
# GWOS — Instala todos os módulos, na ordem
# ============================================================================
# Equivale ao instalador antigo, só que montado a partir dos módulos: cada um
# continua instalável sozinho, e este script apenas os executa em sequência.
#
#   bash instalar-todos.sh                  # tudo
#   bash instalar-todos.sh --escolher       # pergunta módulo a módulo
#   bash instalar-todos.sh 20-dns-bind9 30-hora-chrony   # só os listados
#   bash instalar-todos.sh --pular 00-base  # tudo menos os listados
#   bash instalar-todos.sh --listar         # mostra a ordem e sai
# ============================================================================

set -uo pipefail

MOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
. "${MOD_DIR}/comum/lib.sh"

ORDEM=(
    00-base
    10-banco-mariadb
    20-dns-bind9
    25-dns-interno-dnsmasq
    30-hora-chrony
    40-firewall-nftables
    50-proxy-squid
    60-painel-web
)

declare -A DESCRICAO=(
    [00-base]="Nome, IP e domínio desta máquina (gateway ou servidor)"
    [10-banco-mariadb]="Banco de dados (necessário para grupos, domínios, horários)"
    [20-dns-bind9]="Servidor DNS da rede, com bloqueio por domínio"
    [25-dns-interno-dnsmasq]="Nomes internos da LAN (portal, samba...)"
    [30-hora-chrony]="Servidor de hora (NTP) para a rede"
    [40-firewall-nftables]="Firewall e NAT — exige duas placas de rede"
    [50-proxy-squid]="Proxy HTTP/HTTPS com filtro de conteúdo"
    [60-painel-web]="Painel web de administração"
)

if [ "${1:-}" = "--listar" ]; then
    echo "Ordem de instalação:"
    for m in "${ORDEM[@]}"; do printf "  %-24s %s\n" "$m" "${DESCRICAO[$m]}"; done
    exit 0
fi

exigir_root

PULAR=()
SELECIONADOS=()
ESCOLHER=0

case "${1:-}" in
    --escolher) ESCOLHER=1 ;;
    --pular)    shift; PULAR=("$@") ;;
    "")         ;;
    *)          SELECIONADOS=("$@") ;;
esac

# ---------------------------------------------------------------------------
# Uma placa só: os módulos de gateway não se aplicam. Avisa antes de começar,
# em vez de deixar o 00-base falhar no meio da instalação.
# ---------------------------------------------------------------------------
NUM_IFACES=$(listar_ifaces | grep -c . || true)
if [ "${NUM_IFACES:-0}" -lt 2 ] && [ "$ESCOLHER" = "0" ] && [ ${#SELECIONADOS[@]} -eq 0 ]; then
    echo ""
    aviso "Esta máquina tem ${NUM_IFACES} interface de rede."
    echo "  O 00-base roda assim mesmo, em modo SERVIDOR: pergunta nome, IP e"
    echo "  domínio, sem configurar roteamento."
    echo "  Só o 40-firewall-nftables é pulado — NAT exige duas placas."
    echo ""
    if confirmar "Escolher quais módulos instalar?"; then
        ESCOLHER=1
    else
        PULAR=(40-firewall-nftables)
    fi
fi

# ---------------------------------------------------------------------------
# Seleção interativa
# ---------------------------------------------------------------------------
if [ "$ESCOLHER" = "1" ]; then
    titulo "── Escolha dos módulos ──"
    echo ""
    echo "  Responda s para instalar, Enter para pular."
    echo ""
    for m in "${ORDEM[@]}"; do
        echo -e "  ${BOLD}${m}${NC}"
        echo "    ${DESCRICAO[$m]}"
        if confirmar "Instalar?"; then
            SELECIONADOS+=("$m")
        fi
        echo ""
    done

    if [ ${#SELECIONADOS[@]} -eq 0 ]; then
        echo "Nenhum módulo escolhido. Nada a fazer."
        exit 0
    fi
    echo -e "  Serão instalados: ${BOLD}${SELECIONADOS[*]}${NC}"
    echo ""
    confirmar "Confirma?" || { echo "Cancelado."; exit 0; }
fi

pular() {
    local m="$1" p
    for p in ${PULAR[@]+"${PULAR[@]}"}; do [ "$p" = "$m" ] && return 0; done
    return 1
}
selecionado() {
    local m="$1" s
    [ ${#SELECIONADOS[@]} -eq 0 ] && return 0
    for s in "${SELECIONADOS[@]}"; do [ "$s" = "$m" ] && return 0; done
    return 1
}

echo -e "\n${BOLD}${CYAN}══════════════════════════════════════════════${NC}"
echo -e "${BOLD}${CYAN}  GWOS — instalação modular${NC}"
echo -e "${BOLD}${CYAN}══════════════════════════════════════════════${NC}"

FALHOU=()
INSTALADOS=()
PULADOS=()
CANCELADO=0

for m in "${ORDEM[@]}"; do
    selecionado "$m" || continue
    pular "$m" && { aviso "Pulando ${m}."; PULADOS+=("$m"); continue; }
    [ -f "${MOD_DIR}/${m}/instalar.sh" ] || { aviso "Módulo ${m} não encontrado — pulando."; continue; }

    bash "${MOD_DIR}/${m}/instalar.sh"
    CODIGO=$?

    case "$CODIGO" in
        0)  INSTALADOS+=("$m") ;;
        2)  # O módulo decidiu que não se aplica a esta máquina (ex.: 00-base
            # numa máquina de uma placa só). Não é erro — segue em frente.
            PULADOS+=("$m") ;;
        3)  # Cancelado pelo usuário no resumo. Seguir para os próximos
            # módulos seria pior que parar: sem o gwos.conf que este módulo
            # escreveria, os seguintes adivinham a rede e acertam raramente.
            CANCELADO=1
            echo ""
            aviso "Módulo ${m} cancelado — a instalação para por aqui."
            echo "      Nada do que viria depois foi instalado."
            echo "      Para recomeçar: bash modulos/instalar-todos.sh --escolher"
            break ;;
        *)  FALHOU+=("$m")
            falha "Módulo ${m} falhou (código ${CODIGO})."
            confirmar "Continuar com os próximos módulos?" || break ;;
    esac
done

# Passe final de integração: agora que todos existem, refaz as amarrações.
# Numa instalação cancelada não há o que amarrar.
# Cancelado: nada foi amarrado e nada foi instalado. O resumo de sucesso
# abaixo (com senha, endereços do painel e "Servidor: ?.?") só confundiria.
if [ "$CANCELADO" = "1" ]; then
    echo ""
    echo -e "${BOLD}${YELLOW}══════════════════════════════════════════════${NC}"
    echo -e "${YELLOW}${BOLD}  Instalação cancelada — nada foi alterado.${NC}"
    echo -e "${BOLD}${YELLOW}══════════════════════════════════════════════${NC}"
    echo ""
    [ ${#INSTALADOS[@]} -gt 0 ] &&         echo -e "  Já estavam instalados antes do cancelamento: ${BOLD}${INSTALADOS[*]}${NC}"
    echo -e "  Recomeçar: ${BOLD}bash ${MOD_DIR}/instalar-todos.sh --escolher${NC}"
    echo ""
    exit 1
fi

integrar

echo ""
echo -e "${BOLD}${CYAN}══════════════════════════════════════════════${NC}"
if [ ${#FALHOU[@]} -eq 0 ]; then
    echo -e "${GREEN}${BOLD}  GWOS instalado com sucesso!${NC}"
else
    echo -e "${YELLOW}${BOLD}  GWOS instalado com pendências.${NC}"
    echo -e "  Módulos com falha: ${BOLD}${FALHOU[*]}${NC}"
fi
echo -e "${BOLD}${CYAN}══════════════════════════════════════════════${NC}"
echo ""

[ ${#INSTALADOS[@]} -gt 0 ] && echo -e "  Instalados  : ${BOLD}${INSTALADOS[*]}${NC}"
[ ${#PULADOS[@]}   -gt 0 ] && echo -e "  Pulados     : ${PULADOS[*]}"

carregar_conf
echo -e "  Servidor    : ${BOLD}${NOME_SERVIDOR:-?}.${DOMINIO_LOCAL:-?}${NC} (${PAPEL:-?})"
if [ "${PAPEL:-servidor}" = "gateway" ]; then
    echo -e "  Interfaces  : WAN ${BOLD}${IFACE_WAN:-?}${NC}  |  LAN ${BOLD}${IFACE_LAN:-?}${NC}"
    echo -e "  Rede LAN    : ${BOLD}${REDE_LAN:-?}${NC}"
else
    echo -e "  Endereço    : ${BOLD}${IP_GATEWAY:-?}${NC} em ${BOLD}${IFACE_LAN:-?}${NC}"
fi
if [[ " ${INSTALADOS[*]} " == *" 60-painel-web "* ]]; then
    if [ "${PAINEL_PORTA:-80}" = "80" ]; then
        echo -e "  Painel      : ${BOLD}http://${IP_GATEWAY:-?}${NC}"
    else
        echo -e "  Painel      : ${BOLD}http://${IP_GATEWAY:-?}:${PAINEL_PORTA}${NC}"
    fi
    echo -e "  Login       : ${BOLD}admin@gwos.local${NC}"
    echo "  Senha: a que você digitou durante a instalação do módulo 60-painel-web."
fi
if tem_ssl_bump; then
    echo -e "  Certificado : ${BOLD}http://${IP_GATEWAY}/gwos-ca.crt${NC} — instale nos clientes"
fi
echo ""
echo -e "  Conferir tudo: ${BOLD}bash ${MOD_DIR}/verificar-todos.sh${NC}"
echo ""

[ ${#FALHOU[@]} -eq 0 ]
