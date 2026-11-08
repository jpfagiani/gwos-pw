#!/bin/bash
# Gera as ACLs de horário do Squid a partir do banco de dados.
# Executado pelo painel via sudo após alterações em horários.

set -euo pipefail

erro() { echo "ERRO: $*" >&2; exit 1; }

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$GWOS_DIR/scripts/ler_env.sh"
gwos_ler_env "$GWOS_DIR/.env"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_BANCO="${DB_BANCO:-gwos}"
DB_USUARIO="${DB_USUARIO:-gwos}"
DB_SENHA="${DB_SENHA:-}"
SQUID_DIR="/etc/squid/conf.d"
SQUID_ALVO="$SQUID_DIR/gwos_horarios.conf"
mkdir -p "$SQUID_DIR"
BACKUP_DIR="$(mktemp -d /tmp/gwos_squid_horarios.XXXXXX)"
chmod 0700 "$BACKUP_DIR"
COMMITADO=0
HAD_ACL=0
if [ -f "$SQUID_ALVO" ]; then cp -p "$SQUID_ALVO" "$BACKUP_DIR/anterior.conf"; HAD_ACL=1; fi
SQUID_ACL="$(mktemp "$SQUID_DIR/.gwos_horarios.XXXXXX")"
restaurar_acl() {
    if [ "$HAD_ACL" = "1" ]; then cp -p "$BACKUP_DIR/anterior.conf" "$SQUID_ALVO"
    else rm -f "$SQUID_ALVO"; fi
}
limpar_acl() {
    rc=$?
    if [ "$COMMITADO" = "0" ]; then
        restaurar_acl
        if command -v squid >/dev/null 2>&1; then squid -k parse >/dev/null 2>&1 && squid -k reconfigure >/dev/null 2>&1 || true; fi
    fi
    rm -f "$SQUID_ACL"
    rm -rf "$BACKUP_DIR"
    return "$rc"
}
trap limpar_acl EXIT

cat > "$SQUID_ACL" <<'HEADER'
# Gerado automaticamente pelo GWOS — não editar manualmente.
# Última geração: TIMESTAMP
HEADER

sed -i "s/TIMESTAMP/$(date)/" "$SQUID_ACL"

# Dias: 0=Dom 1=Seg 2=Ter 3=Qua 4=Qui 5=Sex 6=Sab
DIA_MAP=("S" "M" "T" "W" "H" "F" "A")

# Busca regras ativas
mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
    --batch --skip-column-names \
    -e "SELECT id, dias_semana, hora_inicio, hora_fim, acao, grupo_id FROM horarios WHERE ativo = 1 ORDER BY id" | \
while IFS=$'\t' read -r id dias hora_ini hora_fim acao grupo_id; do

    # Converte bits para letras do Squid (SMTWHFA)
    dias_squid=""
    for i in $(seq 0 6); do
        if [ "${dias:$i:1}" = "1" ]; then
            dias_squid+="${DIA_MAP[$i]}"
        fi
    done

    [ -z "$dias_squid" ] && continue

    hora_ini_fmt="${hora_ini:0:5}"
    hora_fim_fmt="${hora_fim:0:5}"

    echo "" >> "$SQUID_ACL"
    echo "acl horario_${id} time ${dias_squid} ${hora_ini_fmt}-${hora_fim_fmt}" >> "$SQUID_ACL"

    if [ -n "$grupo_id" ] && [ "$grupo_id" != "NULL" ]; then
        # Busca IPs do grupo
        ips=$(mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
            --batch --skip-column-names \
            -e "SELECT endereco FROM ips WHERE grupo_id = $grupo_id AND ativo = 1 AND (expira_em IS NULL OR expira_em>NOW())")

        if [ -n "$ips" ]; then
            echo "acl grupo_ips_${id} src $(echo "$ips" | tr '\n' ' ')" >> "$SQUID_ACL"
            src_acl="grupo_ips_${id}"
        else
            src_acl=""
        fi
    else
        src_acl=""
    fi

    if [ "$acao" = "bloquear" ]; then
        if [ -n "$src_acl" ]; then
            echo "http_access deny ${src_acl} horario_${id}" >> "$SQUID_ACL"
        else
            # Bloquear sem grupo: aplica a todos os clientes controlados
            echo "http_access deny gwos_parciais horario_${id}" >> "$SQUID_ACL"
            echo "http_access deny horario_${id}" >> "$SQUID_ACL"
        fi
    else
        if [ -n "$src_acl" ]; then
            echo "http_access allow ${src_acl} horario_${id}" >> "$SQUID_ACL"
        else
            # Permitir sem grupo (ex: Livre sábado/domingo):
            # desbloqueia blacklist APENAS para parciais — bloqueados e sem
            # grupo já foram tratados antes do include no squid.conf
            echo "http_access allow gwos_parciais horario_${id}" >> "$SQUID_ACL"
        fi
    fi
done

install -m 0644 "$SQUID_ACL" "$SQUID_ALVO"
if command -v squid >/dev/null 2>&1; then
    squid -k parse || erro "ACL de horário reprovada no squid -k parse; configuração anterior restaurada."
    if [[ "${1:-}" != "--no-reconfigure" ]]; then squid -k reconfigure || erro "Squid não recarregou as regras de horário."; fi
fi

COMMITADO=1
trap - EXIT
rm -f "$SQUID_ACL"
rm -rf "$BACKUP_DIR"

echo "ACLs de horário aplicadas com sucesso."
