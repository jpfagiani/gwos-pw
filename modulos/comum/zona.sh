#!/bin/bash
# ============================================================================
# GWOS — Zonas de encaminhamento do BIND9
# Instalado como /usr/local/sbin/gwos-zona
# ============================================================================
# Mexe só em /etc/bind/named.conf.zonas-locais — o arquivo que o instalador
# cria uma vez e nunca sobrescreve. É por aqui que o painel web acrescenta e
# remove zonas, sem nunca escrever em /etc por conta própria.
#
#   gwos-zona listar
#   gwos-zona adicionar cartoriosap.sp.gov.br "10.1.6.222 10.14.8.16"
#   gwos-zona remover   cartoriosap.sp.gov.br
#
# Toda alteração passa por named-checkconf ANTES de valer. Se a configuração
# não passar, o arquivo anterior volta e o BIND9 nem chega a ser recarregado.
# ============================================================================

set -euo pipefail

for _l in "/etc/gwos/lib.sh" \
          "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/lib.sh"; do
    # shellcheck disable=SC1090
    [ -f "$_l" ] && { . "$_l"; break; }
done
[ -n "${GWOS_LIB_CARREGADA:-}" ] || { echo "ERRO: lib.sh não encontrado." >&2; exit 1; }

ARQUIVO="/etc/bind/named.conf.zonas-locais"
MARCA_INI="// >>> gwos-zona"
MARCA_FIM="// <<< gwos-zona"

# ---------------------------------------------------------------------------
# Validação
# ---------------------------------------------------------------------------
valida_dominio() {
    # Sem curinga, sem barra, sem espaço — só rótulos separados por ponto
    echo "$1" | grep -qE '^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$' \
        && [ ${#1} -le 253 ]
}

valida_forwarders() {
    local ip
    [ -n "$1" ] || return 1
    for ip in $1; do valida_ip "$ip" || return 1; done
    return 0
}

garantir_arquivo() {
    [ -f "$ARQUIVO" ] || {
        echo "// GWOS — zonas do administrador (criado por gwos-zona)" > "$ARQUIVO"
        chown bind:bind "$ARQUIVO" 2>/dev/null || true
    }
}

# ---------------------------------------------------------------------------
# Aplica com rede de segurança
# ---------------------------------------------------------------------------
aplicar() {
    local temporario="$1"

    local salvaguarda
    salvaguarda=$(mktemp /tmp/gwos_zona_bak.XXXXXX)
    cp -a "$ARQUIVO" "$salvaguarda"

    cp "$temporario" "$ARQUIVO"
    chown bind:bind "$ARQUIVO" 2>/dev/null || true
    chmod 644 "$ARQUIVO"

    if ! named-checkconf >/dev/null 2>&1; then
        cp -a "$salvaguarda" "$ARQUIVO"
        rm -f "$salvaguarda" "$temporario"
        echo "ERRO: configuração inválida — nada foi alterado." >&2
        named-checkconf >&2 || true
        exit 1
    fi

    rm -f "$salvaguarda" "$temporario"
    rndc reload >/dev/null 2>&1 || systemctl reload named >/dev/null 2>&1 || true
}

# ---------------------------------------------------------------------------
# Comandos
# ---------------------------------------------------------------------------
cmd_listar() {
    garantir_arquivo
    # Sai como: dominio<TAB>forwarders — formato que o painel consome
    awk '
        /^[[:space:]]*zone[[:space:]]+"/ {
            match($0, /"[^"]+"/); z = substr($0, RSTART+1, RLENGTH-2); f = ""; next
        }
        /forwarders[[:space:]]*\{/ && z != "" {
            linha = $0
            gsub(/.*forwarders[[:space:]]*\{/, "", linha)
            gsub(/\}.*/, "", linha); gsub(/;/, " ", linha)
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", linha)
            gsub(/[[:space:]]+/, " ", linha)
            f = linha; next
        }
        /^[[:space:]]*\};/ && z != "" { print z "\t" f; z = ""; f = "" }
    ' "$ARQUIVO"
}

cmd_adicionar() {
    local dominio="$1" forwarders="$2"

    valida_dominio "$dominio"       || { echo "ERRO: domínio inválido: '${dominio}'" >&2; exit 1; }
    valida_forwarders "$forwarders" || { echo "ERRO: forwarders inválidos: '${forwarders}'" >&2; exit 1; }

    garantir_arquivo

    if cmd_listar | cut -f1 | grep -qx "$dominio"; then
        echo "ERRO: a zona '${dominio}' já existe. Remova antes de recriar." >&2
        exit 1
    fi

    # Uma zona igual em named.conf.local (as do governo) tornaria a
    # configuração inválida — o BIND recusa zona declarada duas vezes.
    if grep -qE "^[[:space:]]*zone[[:space:]]+\"${dominio}\"" /etc/bind/named.conf.local 2>/dev/null; then
        echo "ERRO: '${dominio}' já é declarada em named.conf.local (zona do projeto)." >&2
        exit 1
    fi

    local temporario
    temporario=$(mktemp /tmp/gwos_zona.XXXXXX)
    cp "$ARQUIVO" "$temporario"

    {
        echo ""
        echo "${MARCA_INI} ${dominio}"
        echo "zone \"${dominio}\" {"
        echo "    type forward;"
        echo "    forward only;"
        printf '    forwarders {'
        for ip in $forwarders; do printf ' %s;' "$ip"; done
        printf ' };\n'
        echo "};"
        echo "${MARCA_FIM} ${dominio}"
    } >> "$temporario"

    aplicar "$temporario"
    echo "[OK] Zona '${dominio}' encaminhada para: ${forwarders}"
}

cmd_remover() {
    local dominio="$1"
    valida_dominio "$dominio" || { echo "ERRO: domínio inválido: '${dominio}'" >&2; exit 1; }
    garantir_arquivo

    cmd_listar | cut -f1 | grep -qx "$dominio" || {
        echo "ERRO: zona '${dominio}' não encontrada em ${ARQUIVO}." >&2
        exit 1
    }

    local temporario
    temporario=$(mktemp /tmp/gwos_zona.XXXXXX)
    awk -v ini="${MARCA_INI} ${dominio}" -v fim="${MARCA_FIM} ${dominio}" '
        $0 == ini { pulando = 1; next }
        $0 == fim { pulando = 0; next }
        !pulando  { print }
    ' "$ARQUIVO" > "$temporario"

    aplicar "$temporario"
    echo "[OK] Zona '${dominio}' removida."
}

case "${1:-listar}" in
    listar)
        cmd_listar ;;
    adicionar)
        exigir_root
        [ $# -eq 3 ] || { echo "Uso: gwos-zona adicionar <dominio> \"<ip> [ip...]\"" >&2; exit 1; }
        cmd_adicionar "$2" "$3" ;;
    remover)
        exigir_root
        [ $# -eq 2 ] || { echo "Uso: gwos-zona remover <dominio>" >&2; exit 1; }
        cmd_remover "$2" ;;
    *)
        echo "Uso: gwos-zona <listar|adicionar <dominio> \"<ips>\"|remover <dominio>>" >&2
        exit 1 ;;
esac
