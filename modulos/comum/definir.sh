#!/bin/bash
# ============================================================================
# GWOS — Altera uma chave de /etc/gwos/gwos.conf com validação
# Instalado como /usr/local/sbin/gwos-definir
# ============================================================================
# É o único caminho de escrita que o painel web tem para o estado compartilhado.
# O painel roda como www-data e NÃO escreve em /etc: ele chama este script pelo
# sudo, com a chave e o valor. Aqui a chave passa por uma lista fechada e o
# valor por um validador antes de qualquer gravação — sem isso, um formulário
# web viraria escrita arbitrária em arquivo de root.
#
#   gwos-definir DNS_FORWARDERS "8.8.8.8 1.1.1.1"
#   gwos-definir NTP_SERVIDORES "10.14.8.20"
#   gwos-definir --listar
#
# Ao final roda o gwos-integrar, que regenera as configurações dos serviços.
#
# NÃO aceita IFACE_*, IP_GATEWAY nem REDE_LAN de propósito: trocar o IP do
# gateway tem procedimento próprio ('gwos ip'), que valida com ifquery, faz
# backup e adiciona o IP novo antes de remover o antigo. Mudar isso por
# formulário web derrubaria a máquina sem rede de segurança.
# ============================================================================

set -euo pipefail

for _l in "/etc/gwos/lib.sh" \
          "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: lib.sh não encontrado." >&2; exit 1; }

# ---------------------------------------------------------------------------
# Validadores
# ---------------------------------------------------------------------------
val_lista_ip() {   # um ou mais IPv4 separados por espaço
    local v
    [ -n "$1" ] || return 1
    for v in $1; do valida_ip "$v" || return 1; done
    return 0
}

val_lista_host() { # IPv4 ou nome de host, separados por espaço
    local v
    [ -n "$1" ] || return 1
    for v in $1; do
        valida_ip "$v" && continue
        echo "$v" | grep -qE '^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$' || return 1
    done
    return 0
}

val_dominio() {
    echo "$1" | grep -qE '^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$'
}

val_porta() {
    echo "$1" | grep -qE '^[0-9]{1,5}$' && [ "$1" -ge 1 ] && [ "$1" -le 65535 ]
}

# ---------------------------------------------------------------------------
# Chaves permitidas — chave|validador|descrição
# ---------------------------------------------------------------------------
CHAVES="
DNS_FORWARDERS|val_lista_ip|Resolvers upstream do BIND9
NTP_SERVIDORES|val_lista_host|Servidores de hora preferidos
NTP_POOL|val_lista_host|Pool NTP de reserva
DOMINIO_LOCAL|val_dominio|Domínio dos nomes internos
SQUID_PORTA|val_porta|Porta HTTP transparente do Squid
SQUID_PORTA_SSL|val_porta|Porta HTTPS (SSL Bump) do Squid
SQUID_PORTA_FWD|val_porta|Porta do proxy explícito
DNSMASQ_PORTA|val_porta|Porta do dnsmasq interno
PAINEL_PORTA|val_porta|Porta do painel web (nginx)
"

listar_chaves() {
    echo "Chaves que este comando aceita:"
    echo "$CHAVES" | grep -v '^$' | while IFS='|' read -r k _ d; do
        printf "  %-18s %s\n" "$k" "$d"
    done
    echo ""
    echo "Interfaces, IP do gateway e rede NÃO passam por aqui — use 'gwos ip'."
}

[ "${1:-}" = "--listar" ] && { listar_chaves; exit 0; }

[ $# -eq 2 ] || {
    echo "Uso: gwos-definir <chave> <valor>" >&2
    echo "     gwos-definir --listar" >&2
    exit 1
}

exigir_root
CHAVE="$1"
VALOR="$2"

LINHA=$(echo "$CHAVES" | grep "^${CHAVE}|" || true)
[ -n "$LINHA" ] || {
    echo "ERRO: chave não permitida: ${CHAVE}" >&2
    echo "" >&2
    listar_chaves >&2
    exit 1
}

VALIDADOR=$(echo "$LINHA" | cut -d'|' -f2)

# Recusa qualquer coisa que possa quebrar o formato do arquivo
case "$VALOR" in
    *$'\n'*|*$'\r'*|*'#'*)
        echo "ERRO: valor contém caractere não permitido (quebra de linha ou '#')." >&2
        exit 1 ;;
esac

"$VALIDADOR" "$VALOR" || {
    echo "ERRO: valor inválido para ${CHAVE}: '${VALOR}'" >&2
    exit 1
}

carregar_conf
ANTERIOR="${!CHAVE:-}"
[ "$ANTERIOR" = "$VALOR" ] && { echo "[..] ${CHAVE} já vale '${VALOR}' — nada alterado."; exit 0; }

backup_arquivo "$GWOS_CONF"
salvar_conf "$CHAVE" "$VALOR"
echo "[OK] ${CHAVE}: '${ANTERIOR}' → '${VALOR}'"

# Regenera as configurações de serviço afetadas. Cada gerador valida com a
# ferramenta do próprio serviço (named-checkconf, nft -c, squid -k parse)
# antes de aplicar, então uma configuração ruim não derruba nada.
integrar --silencioso
echo "[OK] Serviços reconfigurados."
