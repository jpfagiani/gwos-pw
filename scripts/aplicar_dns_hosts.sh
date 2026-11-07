#!/bin/bash
# GWOS — Gerencia nomes internos da LAN via /etc/hosts + dnsmasq
# Chamado internamente pelo comando: gwos dns
# Não execute diretamente.

set -euo pipefail

HOSTS_FILE="/etc/hosts"
MARKER="# gwos-dns"   # identifica linhas gerenciadas pelo GWOS

# Domínio interno definido pelo módulo 00-base (modulos/comum/lib.sh)
DOMINIO_LOCAL="cdpni.local"
# shellcheck disable=SC1091
[ -f /etc/gwos/gwos.conf ] && . /etc/gwos/gwos.conf

_reload() {
    # O serviço instalado pelo GWOS é gwos-dnsmasq (o dnsmasq padrão fica desativado)
    systemctl reload gwos-dnsmasq 2>/dev/null \
        || systemctl restart gwos-dnsmasq
}

cmd_list() {
    echo ""
    echo "  Hosts internos da LAN (gerenciados pelo GWOS):"
    echo "  ──────────────────────────────────────────────"
    if grep -q "$MARKER" "$HOSTS_FILE" 2>/dev/null; then
        grep "$MARKER" "$HOSTS_FILE" \
            | awk '{printf "  %-18s %s\n", $1, $2}'
    else
        echo "  (nenhum host cadastrado — use: gwos dns add <host> <ip>)"
    fi
    echo ""
}

cmd_add() {
    local ip="$1" host="$2"
    local domain="${3:-$DOMINIO_LOCAL}"

    if grep -qP "^\S+\s+${host}\s" "$HOSTS_FILE" 2>/dev/null || \
       grep -qP "^\S+\s+\S+\s+${host}($|\s)" "$HOSTS_FILE" 2>/dev/null; then
        echo "AVISO: host '$host' já existe. Use 'gwos dns update' para alterar." >&2
        exit 1
    fi

    echo "${ip}  ${host} ${host}.${domain}  ${MARKER}" >> "$HOSTS_FILE"
    _reload
    echo "OK: ${host} → ${ip} adicionado. Clientes resolvem em até 60s."
}

cmd_update() {
    local host="$1" ip_novo="$2"

    if ! grep -q "${MARKER}" "$HOSTS_FILE" || \
       ! grep "${MARKER}" "$HOSTS_FILE" | grep -qP "\s${host}(\s|$)"; then
        echo "ERRO: host '$host' não encontrado. Use 'gwos dns add' para criar." >&2
        exit 1
    fi

    local ip_antigo
    ip_antigo=$(grep "${MARKER}" "$HOSTS_FILE" | grep -P "\s${host}(\s|$)" | awk '{print $1}')

    sed -i "/${MARKER}/{ /\s${host}\s/ s|^[0-9.]*|${ip_novo}| }" "$HOSTS_FILE"
    _reload
    echo "OK: ${host} atualizado de ${ip_antigo} → ${ip_novo}."
    echo "    Clientes recebem o novo IP em até 60s (TTL da zona)."
}

cmd_del() {
    local host="$1"

    if ! grep -q "${MARKER}" "$HOSTS_FILE" || \
       ! grep "${MARKER}" "$HOSTS_FILE" | grep -qP "\s${host}(\s|$)"; then
        echo "ERRO: host '$host' não encontrado." >&2
        exit 1
    fi

    sed -i "/${MARKER}/{ /\s${host}\s/d }" "$HOSTS_FILE"
    _reload
    echo "OK: ${host} removido."
}

SUBCMD="${1:-list}"; shift 2>/dev/null || true

case "$SUBCMD" in
    list|ls)
        cmd_list ;;
    add)
        [[ $# -ge 2 ]] || { echo "Uso: gwos dns add <host> <ip> [dominio]"; exit 1; }
        cmd_add "$2" "$1" "${3:-$DOMINIO_LOCAL}" ;;
    update|change)
        [[ $# -ge 2 ]] || { echo "Uso: gwos dns update <host> <novo-ip>"; exit 1; }
        cmd_update "$1" "$2" ;;
    del|rm)
        [[ $# -ge 1 ]] || { echo "Uso: gwos dns del <host>"; exit 1; }
        cmd_del "$1" ;;
    *)
        echo "Uso: gwos dns <list|add <host> <ip>|update <host> <ip>|del <host>>"
        exit 1 ;;
esac
