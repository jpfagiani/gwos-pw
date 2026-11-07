#!/bin/bash
# GWOS CLI — ferramenta de administração via terminal
# Instalado em /usr/local/bin/gwos pelo install.sh
# Uso: gwos <comando> [opções]

set -euo pipefail

# ── Localiza o diretório do projeto ────────────────────────────────
GWOS_DIR="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
# Se executado de /usr/local/bin/gwos, GWOS_DIR será /usr/local/bin
# Usa o link simbólico para achar o diretório real
if [ -L "/usr/local/bin/gwos" ]; then
    GWOS_DIR="$(dirname "$(readlink -f /usr/local/bin/gwos)")"
    GWOS_DIR="$(cd "$GWOS_DIR/.." && pwd)"
fi
# Fallback: só entra em ação se o caminho resolvido acima não servir. Antes
# este laço rodava sempre e um /opt/gwos antigo vencia o link simbólico —
# o repositório pode estar em qualquer diretório (/opt/dns, /srv/gateway...).
if [ ! -f "$GWOS_DIR/.env" ]; then
    for D in /opt/gwos /opt/dns /srv/gwos "$HOME/gwos"; do
        [ -f "$D/.env" ] && GWOS_DIR="$D" && break
    done
fi

ENV_FILE="$GWOS_DIR/.env"
[ -f "$ENV_FILE" ] || { echo "ERRO: .env não encontrado (procurado em $GWOS_DIR)"; exit 1; }
set -a; source "$ENV_FILE"; set +a

# ── Cores ──────────────────────────────────────────────────────────
R='\033[0;31m'; G='\033[0;32m'; Y='\033[1;33m'
C='\033[0;36m'; B='\033[1m';    N='\033[0m'
ok()    { echo -e "${G}✔${N}  $*"; }
erro()  { echo -e "${R}✘${N}  $*" >&2; }
info()  { echo -e "${Y}»${N}  $*"; }
titulo(){ echo -e "\n${B}${C}$*${N}"; }

# ── MySQL helper ───────────────────────────────────────────────────
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_BANCO="${DB_BANCO:-gwos}"
DB_USUARIO="${DB_USUARIO:-gwos}"
DB_SENHA="${DB_SENHA:-}"

mq() {
    mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
        --batch --skip-column-names -e "$1" 2>/dev/null
}
mq_table() {
    mysql -h"$DB_HOST" -u"$DB_USUARIO" -p"$DB_SENHA" "$DB_BANCO" \
        -e "$1" 2>/dev/null
}

# ── Verificação de root ────────────────────────────────────────────
need_root() {
    [ "$(id -u)" -eq 0 ] || { erro "Este comando requer root. Use: sudo gwos $*"; exit 1; }
}

# ==================================================================
# STATUS
# ==================================================================
cmd_status() {
    titulo "══ GWOS Status ══"
    echo ""
    printf "  %-20s %s\n" "Serviço" "Estado"
    echo "  ──────────────────────────────────────────"

    for SVC in squid nginx php8.4-fpm named gwos-dnsmasq mariadb nftables chrony; do
        STATUS=$(systemctl is-active "$SVC" 2>/dev/null || echo "inativo")
        if [ "$STATUS" = "active" ]; then
            printf "  %-20s ${G}%s${N}\n" "$SVC" "● ativo"
        else
            printf "  %-20s ${R}%s${N}\n" "$SVC" "○ $STATUS"
        fi
    done

    echo ""
    titulo "  Rede"
    IFACE_WAN=$(mq "SELECT valor FROM configuracoes WHERE chave='iface_wan'" 2>/dev/null || echo "?")
    IFACE_LAN=$(mq "SELECT valor FROM configuracoes WHERE chave='iface_lan'" 2>/dev/null || echo "?")
    IP_GW=$(mq "SELECT valor FROM configuracoes WHERE chave='ip_gateway'" 2>/dev/null || echo "?")
    IP_WAN=$(ip -4 addr show "$IFACE_WAN" 2>/dev/null | awk '/inet /{print $2}' | head -1 || echo "sem IP")
    echo "  WAN ($IFACE_WAN): $IP_WAN"
    echo "  LAN ($IFACE_LAN): $IP_GW"

    echo ""
    titulo "  Squid"
    SQUID_CONN=$(ss -tnp 2>/dev/null | grep -c 'squid') || SQUID_CONN=0
    echo "  Conexões ativas: $SQUID_CONN"

    echo ""
    titulo "  Banco"
    TOTAL_IPS=$(mq "SELECT COUNT(*) FROM ips WHERE ativo=1" 2>/dev/null || echo "?")
    TOTAL_DOM=$(mq "SELECT COUNT(*) FROM dominios WHERE ativo=1" 2>/dev/null || echo "?")
    TOTAL_NAT=$(mq "SELECT COUNT(*) FROM nat_um_para_um WHERE ativo=1" 2>/dev/null || echo "?")
    echo "  IPs gerenciados : $TOTAL_IPS"
    echo "  Domínios ativos : $TOTAL_DOM"
    echo "  Regras NAT 1:1  : $TOTAL_NAT ativas"
    echo ""
}

# ==================================================================
# RELOAD
# ==================================================================
cmd_reload() {
    need_root "reload" "$@"
    ALVO="${1:-all}"

    case "$ALVO" in
        squid|all)
            info "Recarregando Squid..."
            squid -k parse && squid -k reconfigure && ok "Squid recarregado."
            ;;&
        nginx|all)
            info "Recarregando Nginx..."
            nginx -t && systemctl reload nginx && ok "Nginx recarregado."
            ;;&
        bind|named|all)
            info "Recarregando BIND9..."
            named-checkconf && systemctl reload named && ok "BIND9 recarregado."
            ;;&
        dnsmasq|dns-interno|all)
            info "Recarregando dnsmasq (nomes internos)..."
            systemctl reload gwos-dnsmasq && ok "dnsmasq recarregado."
            ;;&
        nftables|firewall|all)
            info "Reaplicando nftables..."
            bash "$GWOS_DIR/scripts/aplicar_nftables.sh" && ok "nftables aplicado."
            ;;&
        dominios|domains|all)
            info "Regenerando listas de domínios..."
            bash "$GWOS_DIR/scripts/gerar_squid_dominios.sh" && ok "Domínios atualizados."
            ;;
        *)
            erro "Serviço desconhecido: $ALVO"
            echo "  Opções: squid | nginx | bind | nftables | dominios | all"
            exit 1
            ;;
    esac
}

# ==================================================================
# NAT 1:1
# ==================================================================
cmd_nat() {
    SUBCMD="${1:-list}"; shift 2>/dev/null || true

    case "$SUBCMD" in
        list|ls)
            titulo "══ Regras NAT 1:1 ══"
            mq_table "SELECT id, ip_externo, ip_interno,
                             IF(ativo, 'ativa', 'inativa') AS status,
                             COALESCE(descricao,'—') AS descricao
                      FROM nat_um_para_um
                      ORDER BY id"
            ;;

        add)
            need_root "nat add"
            IP_EXT="${1:-}"; IP_INT="${2:-}"; DESC="${3:-}"
            [ -z "$IP_EXT" ] || [ -z "$IP_INT" ] && {
                echo "Uso: gwos nat add <ip_externo> <ip_interno> [descricao]"
                exit 1
            }
            mq "INSERT INTO nat_um_para_um (ip_externo, ip_interno, descricao, ativo)
                VALUES ('$IP_EXT', '$IP_INT', '$DESC', 0)"
            ok "Regra adicionada (inativa). Use 'gwos nat ativar <id>' para ativar."
            ;;

        del|rm)
            need_root "nat del"
            ID="${1:-}"; [ -z "$ID" ] && { echo "Uso: gwos nat del <id>"; exit 1; }
            mq "DELETE FROM nat_um_para_um WHERE id=$ID"
            bash "$GWOS_DIR/scripts/aplicar_nftables.sh"
            ok "Regra #$ID removida e nftables atualizado."
            ;;

        ativar|enable)
            need_root "nat ativar"
            ID="${1:-}"; [ -z "$ID" ] && { echo "Uso: gwos nat ativar <id>"; exit 1; }
            mq "UPDATE nat_um_para_um SET ativo=1 WHERE id=$ID"
            bash "$GWOS_DIR/scripts/aplicar_nftables.sh"
            ok "Regra #$ID ativada e nftables atualizado."
            ;;

        desativar|disable)
            need_root "nat desativar"
            ID="${1:-}"; [ -z "$ID" ] && { echo "Uso: gwos nat desativar <id>"; exit 1; }
            mq "UPDATE nat_um_para_um SET ativo=0 WHERE id=$ID"
            bash "$GWOS_DIR/scripts/aplicar_nftables.sh"
            ok "Regra #$ID desativada e nftables atualizado."
            ;;

        apply|aplicar)
            need_root "nat aplicar"
            bash "$GWOS_DIR/scripts/aplicar_nftables.sh"
            ok "nftables reaplicado."
            ;;

        *)
            echo "Uso: gwos nat <list|add|del|ativar|desativar|aplicar>"
            ;;
    esac
}

# ==================================================================
# GRUPOS DE IPs
# ==================================================================
cmd_grupo() {
    SUBCMD="${1:-list}"; shift 2>/dev/null || true

    case "$SUBCMD" in
        list|ls)
            titulo "══ Grupos de IPs ══"
            mq_table "SELECT g.id, g.nome, g.tipo,
                             COUNT(i.id) AS total_ips,
                             IF(g.ativo,'sim','não') AS ativo
                      FROM ip_grupos g
                      LEFT JOIN ips i ON i.grupo_id=g.id AND i.ativo=1
                      GROUP BY g.id
                      ORDER BY g.tipo, g.nome"
            ;;

        ips)
            ID="${1:-}"; [ -z "$ID" ] && { echo "Uso: gwos grupo ips <grupo_id>"; exit 1; }
            titulo "══ IPs do Grupo #$ID ══"
            mq_table "SELECT i.id, i.endereco, IF(i.ativo,'sim','não') AS ativo
                      FROM ips i WHERE i.grupo_id=$ID ORDER BY i.endereco"
            ;;

        add-ip)
            need_root "grupo add-ip"
            GRUPO_ID="${1:-}"; IP="${2:-}"
            [ -z "$GRUPO_ID" ] || [ -z "$IP" ] && {
                echo "Uso: gwos grupo add-ip <grupo_id> <ip_ou_cidr>"
                exit 1
            }
            mq "INSERT INTO ips (grupo_id, endereco, ativo) VALUES ($GRUPO_ID, '$IP', 1)"
            bash "$GWOS_DIR/scripts/aplicar_nftables.sh"
            ok "IP $IP adicionado ao grupo #$GRUPO_ID e nftables atualizado."
            ;;

        del-ip)
            need_root "grupo del-ip"
            IP_ID="${1:-}"; [ -z "$IP_ID" ] && { echo "Uso: gwos grupo del-ip <ip_id>"; exit 1; }
            mq "DELETE FROM ips WHERE id=$IP_ID"
            bash "$GWOS_DIR/scripts/aplicar_nftables.sh"
            ok "IP #$IP_ID removido e nftables atualizado."
            ;;

        *)
            echo "Uso: gwos grupo <list|ips|add-ip|del-ip>"
            ;;
    esac
}

# ==================================================================
# DOMÍNIOS
# ==================================================================
cmd_dominio() {
    SUBCMD="${1:-list}"; shift 2>/dev/null || true

    case "$SUBCMD" in
        list|ls)
            TIPO="${1:-whitelist}"
            titulo "══ Domínios ($TIPO) ══"
            mq_table "SELECT id, dominio, tipo, origem,
                             IF(ativo,'sim','não') AS ativo
                      FROM dominios
                      WHERE tipo='$TIPO'
                      ORDER BY dominio
                      LIMIT 50"
            echo "  (limitado a 50 — use o painel para ver todos)"
            ;;

        add)
            need_root "dominio add"
            DOM="${1:-}"; TIPO="${2:-whitelist}"
            [ -z "$DOM" ] && { echo "Uso: gwos dominio add <dominio> [whitelist|blacklist]"; exit 1; }
            mq "INSERT IGNORE INTO dominios (dominio, tipo, ativo, origem)
                VALUES ('$DOM', '$TIPO', 1, 'cli')"
            bash "$GWOS_DIR/scripts/gerar_squid_dominios.sh"
            ok "Domínio $DOM adicionado à $TIPO e Squid atualizado."
            ;;

        del|rm)
            need_root "dominio del"
            DOM="${1:-}"; [ -z "$DOM" ] && { echo "Uso: gwos dominio del <dominio>"; exit 1; }
            mq "DELETE FROM dominios WHERE dominio='$DOM'"
            bash "$GWOS_DIR/scripts/gerar_squid_dominios.sh"
            ok "Domínio $DOM removido e Squid atualizado."
            ;;

        buscar|search)
            TERMO="${1:-}"; [ -z "$TERMO" ] && { echo "Uso: gwos dominio buscar <termo>"; exit 1; }
            titulo "══ Busca: $TERMO ══"
            mq_table "SELECT id, dominio, tipo, IF(ativo,'sim','não') AS ativo
                      FROM dominios WHERE dominio LIKE '%$TERMO%' ORDER BY tipo, dominio"
            ;;

        *)
            echo "Uso: gwos dominio <list [whitelist|blacklist] | add | del | buscar>"
            ;;
    esac
}

# ==================================================================
# LOG
# ==================================================================
cmd_log() {
    SUBCMD="${1:-tail}"; shift 2>/dev/null || true

    case "$SUBCMD" in
        tail)
            LINHAS="${1:-50}"
            titulo "══ Squid — últimas $LINHAS linhas ══"
            tail -n "$LINHAS" /var/log/squid/access.log
            ;;

        live|follow)
            info "Monitorando /var/log/squid/access.log (Ctrl+C para sair)..."
            tail -f /var/log/squid/access.log
            ;;

        top)
            LINHAS="${1:-20}"
            titulo "══ Top $LINHAS domínios acessados (hoje) ══"
            TODAY=$(date +%Y-%m-%d)
            mq_table "SELECT dominio, SUM(total_requisicoes) AS requisicoes,
                             COUNT(DISTINCT ip_cliente) AS clientes
                      FROM relatorio_diario
                      WHERE data='$TODAY'
                      GROUP BY dominio
                      ORDER BY requisicoes DESC
                      LIMIT $LINHAS" 2>/dev/null \
                || awk '{print ($7 ~ /^[A-Z][A-Z0-9_-]*$/) ? $8 : $7}' /var/log/squid/access.log \
                   | grep -oP '(?<=://)[^/]+' \
                   | sort | uniq -c | sort -rn | head -"$LINHAS"
            ;;

        erros)
            titulo "══ Erros recentes do Squid ══"
            grep -E 'DENIED|ERR_|TCP_DENIED' /var/log/squid/access.log | tail -30
            ;;

        *)
            echo "Uso: gwos log <tail [N] | live | top [N] | erros>"
            ;;
    esac
}

# ==================================================================
# BACKUP
# ==================================================================
cmd_backup() {
    need_root "backup"
    SUBCMD="${1:-criar}"; shift 2>/dev/null || true

    case "$SUBCMD" in
        criar)
            bash "$GWOS_DIR/scripts/backup.sh"
            ;;
        listar|list)
            titulo "══ Backups disponíveis ══"
            ls -lh /var/lib/gwos/backups/ 2>/dev/null | grep -v '^total' \
                || echo "  Nenhum backup encontrado."
            ;;
        restaurar)
            ARQ="${1:-}"; [ -z "$ARQ" ] && {
                echo "Uso: gwos backup restaurar <arquivo>"
                echo "Backups disponíveis:"
                ls /var/lib/gwos/backups/ 2>/dev/null
                exit 1
            }
            bash "$GWOS_DIR/scripts/restaurar_backup.sh" "$ARQ"
            ;;
        *)
            echo "Uso: gwos backup <criar | listar | restaurar <arquivo>>"
            ;;
    esac
}

# ==================================================================
# DNS INTERNO (zona cdpni.local)
# ==================================================================
cmd_dns() {
    need_root "dns"
    bash "$GWOS_DIR/scripts/aplicar_dns_hosts.sh" "$@"
}

# ==================================================================
# TROCA DE IP DO GATEWAY
# ==================================================================
cmd_ip() {
    need_root "ip" "$@"
    bash "$GWOS_DIR/scripts/trocar_ip.sh" "$@"
}

# ==================================================================
# DIAGNÓSTICO
# ==================================================================
cmd_diag() {
    titulo "══ GWOS Diagnóstico ══"
    echo ""

    # Squid parse
    info "Testando configuração do Squid..."
    squid -k parse 2>&1 && ok "squid.conf OK" || erro "squid.conf com erros"

    # BIND9
    info "Testando configuração do BIND9..."
    named-checkconf 2>&1 && ok "named.conf OK" || erro "named.conf com erros"

    # dnsmasq
    info "Testando dnsmasq (nomes internos)..."
    systemctl is-active gwos-dnsmasq &>/dev/null \
        && ok "gwos-dnsmasq ativo em 127.0.0.1:5353" \
        || erro "gwos-dnsmasq inativo"
    dig +short +time=2 @127.0.0.1 -p 5353 localhost &>/dev/null \
        && ok "dnsmasq respondendo em :5353" \
        || erro "dnsmasq não responde em :5353"

    # Nginx
    info "Testando configuração do Nginx..."
    nginx -t 2>&1 && ok "nginx.conf OK" || erro "nginx.conf com erros"

    # nftables
    info "Testando regras nftables..."
    nft -c -f /etc/nftables.conf 2>&1 && ok "nftables OK" || erro "nftables com erros"

    # Conectividade
    echo ""
    info "Testando conectividade..."
    ping -c1 -W2 8.8.8.8 &>/dev/null && ok "Saída para internet OK" || erro "Sem acesso à internet"
    dig +short @127.0.0.1 google.com &>/dev/null && ok "DNS local (BIND9) OK" || erro "DNS local não responde"

    # Arquivos Squid
    echo ""
    info "Arquivos conf.d do Squid:"
    for F in whitelist blacklist ips_bloqueados ips_parciais ips_liberados ips_isentos horarios tcp_outgoing portal; do
        ARQ="/etc/squid/conf.d/gwos_${F}.$([ "$F" = "horarios" ] || [ "$F" = "tcp_outgoing" ] || [ "$F" = "portal" ] && echo conf || echo txt)"
        [ -f "$ARQ" ] && printf "  %-45s %s linhas\n" "$ARQ" "$(wc -l < "$ARQ")" \
                      || printf "  %-45s ${R}AUSENTE${N}\n" "$ARQ"
    done
    echo ""
}

# ==================================================================
# AJUDA
# ==================================================================
cmd_help() {
    echo ""
    echo -e "${B}${C}GWOS CLI${N} — Ferramenta de administração"
    echo ""
    echo -e "${B}Uso:${N} gwos <comando> [subcomando] [opções]"
    echo ""
    echo -e "${B}Comandos:${N}"
    echo "  status                       Status de todos os serviços"
    echo "  reload [serviço]             Recarregar (all|squid|nginx|bind|dnsmasq|nftables|dominios)"
    echo "  diag                         Diagnóstico completo do sistema"
    echo "  diag-painel                  Diagnóstico do painel web (PHP, arquivos, admins)"
    echo ""
    echo -e "${B}NAT 1:1:${N}"
    echo "  nat list                     Listar regras NAT"
    echo "  nat add <ext> <int> [desc]   Adicionar regra (criada inativa)"
    echo "  nat ativar <id>              Ativar regra e aplicar"
    echo "  nat desativar <id>           Desativar regra e aplicar"
    echo "  nat del <id>                 Remover regra"
    echo "  nat aplicar                  Reaplicar todas as regras"
    echo ""
    echo -e "${B}Grupos de IP:${N}"
    echo "  grupo list                   Listar grupos"
    echo "  grupo ips <id>               IPs de um grupo"
    echo "  grupo add-ip <id> <ip>       Adicionar IP ao grupo"
    echo "  grupo del-ip <ip_id>         Remover IP"
    echo ""
    echo -e "${B}Domínios:${N}"
    echo "  dominio list [whitelist|blacklist]"
    echo "  dominio add <dom> [tipo]     Adicionar domínio"
    echo "  dominio del <dom>            Remover domínio"
    echo "  dominio buscar <termo>       Pesquisar"
    echo ""
    echo -e "${B}Logs:${N}"
    echo "  log tail [N]                 Últimas N linhas (padrão 50)"
    echo "  log live                     Monitorar em tempo real"
    echo "  log top [N]                  Top domínios acessados hoje"
    echo "  log erros                    Acessos negados recentes"
    echo ""
    echo -e "${B}Backup:${N}"
    echo "  backup criar                 Criar backup agora"
    echo "  backup listar                Listar backups"
    echo "  backup restaurar <arquivo>   Restaurar backup"
    echo -e "${B}DNS interno (cdpni.local):${N}"
    echo "  dns list                     Listar hosts cadastrados"
    echo "  dns add <host> <ip>          Adicionar host (ex: gwos dns add cdpni 10.14.29.8)"
    echo "  dns update <host> <ip>       Alterar IP de um host (propaga em 60s)"
    echo "  dns del <host>               Remover host"
    echo ""
    echo -e "${B}Usuários/Senha:${N}"
    echo "  senha <email> [nova_senha]   Redefinir senha (sem senha, gera credencial aleatória)"
    echo "  senha listar                 Listar todos os admins"
    echo "  resetsenha <email>           Gerar token de reset e forçar troca"
    echo "  desbloqueio <email>          Desbloquear conta após tentativas excessivas"
    echo ""
    echo -e "${B}Sistema:${N}"
    echo "  ip <novo_ip> [rede_cidr]     Trocar IP do gateway com validação e backup"
    echo "                               Ex: gwos ip 172.14.29.20"
    echo "  update                       Atualizar GWOS via git (sem reinstalar)"
    echo ""
}

# ==================================================================
# UPDATE — puxa git e copia arquivos da aplicação
# ==================================================================
cmd_update() {
    need_root
    local REPO="$GWOS_DIR"
    local WEB_DIR="/var/www/gwos"

    titulo "══ GWOS Update ══"
    echo ""

    # Verifica se é repositório git
    if ! git -C "$REPO" rev-parse --is-inside-work-tree &>/dev/null; then
        erro "Diretório $REPO não é um repositório git."
        echo "  Clone o repositório do GWOS em $REPO antes de atualizar."
        exit 1
    fi

    # Commit atual antes do pull
    local ANTES
    ANTES=$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo "?")
    info "Versão atual: $ANTES"

    # Pull
    info "Buscando atualizações..."
    git -C "$REPO" fetch origin main 2>&1 | sed 's/^/  /'
    local REMOTO
    REMOTO=$(git -C "$REPO" rev-parse --short origin/main 2>/dev/null || echo "?")

    if [ "$ANTES" = "$REMOTO" ]; then
        ok "Já está na versão mais recente ($ANTES). Nada a fazer."
        exit 0
    fi

    git -C "$REPO" pull --ff-only origin main 2>&1 | sed 's/^/  /'
    local DEPOIS
    DEPOIS=$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo "?")
    ok "Atualizado: $ANTES → $DEPOIS"

    # Mostra commits novos
    echo ""
    info "Commits aplicados:"
    git -C "$REPO" log --oneline "${ANTES}..${DEPOIS}" 2>/dev/null | sed 's/^/  /'

    # Copia arquivos da aplicação para o web root
    echo ""
    info "Copiando arquivos da aplicação..."
    for DIR in app bootstrap config public routes scripts; do
        if [ -d "$REPO/$DIR" ]; then
            cp -r "$REPO/$DIR/." "$WEB_DIR/$DIR/"
        fi
    done
    ok "Arquivos copiados para $WEB_DIR"

    # Reaplica as configurações de serviço que vêm dos módulos (dnsmasq, BIND9,
    # Squid, nftables). Cada módulo tem seu próprio gerador; gwos-integrar
    # chama todos com os parâmetros atuais de /etc/gwos/gwos.conf.
    if [ -x /usr/local/sbin/gwos-integrar ]; then
        /usr/local/sbin/gwos-integrar --silencioso
        ok "Configurações de serviço reaplicadas (gwos-integrar)"
    fi

    # Ajusta permissões
    chown -R www-data:www-data "$WEB_DIR/app" "$WEB_DIR/public" "$WEB_DIR/bootstrap" "$WEB_DIR/routes" 2>/dev/null || true
    chmod -R 755 "$WEB_DIR/scripts" 2>/dev/null || true

    # Reinicia PHP-FPM para limpar opcache
    if systemctl is-active php8.4-fpm &>/dev/null; then
        systemctl reload php8.4-fpm
        ok "PHP-FPM recarregado (opcache limpo)"
    elif systemctl is-active php8.3-fpm &>/dev/null; then
        systemctl reload php8.3-fpm
        ok "PHP-FPM recarregado (opcache limpo)"
    fi

    echo ""
    ok "Atualização concluída. Versão: $DEPOIS"
}

# ==================================================================
# RESET DE SENHA / DESBLOQUEIO
# ==================================================================
cmd_resetsenha() {
    local email="${1:-}"
    [ -n "$email" ] || { erro "Uso: gwos resetsenha <email>"; exit 1; }
    TOKEN=$(php -r "
        require '${GWOS_DIR}/bootstrap/app.php';
        \$t = \App\Core\Auth::gerarTokenReset('${email}');
        echo \$t ?? '';
    " 2>/dev/null)
    if [ -z "$TOKEN" ]; then
        erro "E-mail não encontrado ou usuário inativo: ${email}"
        exit 1
    fi
    echo ""
    echo -e "${G}Token gerado para ${email}:${N}"
    echo -e "  ${B}${TOKEN}${N}"
    echo ""
    echo "Encaminhe o token ao usuário. Ele deve acessar:"
    echo "  http://$(hostname -I 2>/dev/null | awk '{print $1}'):${PAINEL_PORTA:-8080}/senha/reset"
    echo "O token expira em 24 horas."
    echo ""
}

cmd_desbloqueio() {
    local email="${1:-}"
    [ -n "$email" ] || { erro "Uso: gwos desbloqueio <email>"; exit 1; }
    mysql gwos -e "UPDATE admins SET tentativas=0, bloqueado_ate=NULL WHERE email='${email}';" 2>/dev/null
    ok "Conta desbloqueada: ${email}"
}

cmd_senha() {
    local email="${1:-}"
    local nova="${2:-}"

    if [ -z "$email" ]; then
        erro "Uso: gwos senha <email> [nova_senha]"
        exit 1
    fi

    # Lista admins se nenhum email dado
    if [ "$email" = "listar" ]; then
        echo ""
        mysql gwos --table -e "SELECT id, nome, email, ativo, tentativas, bloqueado_ate FROM admins;" 2>/dev/null
        echo ""
        return
    fi

    # Confirma que o admin existe
    local existe
    existe=$(mysql gwos -sNe "SELECT COUNT(*) FROM admins WHERE email='${email}';" 2>/dev/null)
    if [ "${existe:-0}" = "0" ]; then
        erro "Admin não encontrado: ${email}"
        echo "Use: gwos senha listar  — para ver os admins cadastrados"
        exit 1
    fi

    # Sem senha definida pelo operador, gera uma credencial aleatória.
    if [ -z "$nova" ]; then
        nova="$(openssl rand -hex 16)"
    fi

    # Variáveis passadas via ambiente — evita qualquer expansão de $ ou ' no código PHP
    local resultado
    resultado=$(
        _GW_HOST="${DB_HOST:-127.0.0.1}" \
        _GW_BANCO="${DB_BANCO:-gwos}" \
        _GW_USER="${DB_USUARIO:-gwos}" \
        _GW_PASS="${DB_SENHA}" \
        _GW_EMAIL="${email}" \
        _GW_NOVA="${nova}" \
        php << 'PHP'
<?php
try {
    $pdo = new PDO(
        "mysql:host={$_SERVER['_GW_HOST']};dbname={$_SERVER['_GW_BANCO']};charset=utf8mb4",
        $_SERVER['_GW_USER'],
        $_SERVER['_GW_PASS']
    );
    $hash = password_hash($_SERVER['_GW_NOVA'], PASSWORD_BCRYPT, ['cost' => 12]);
    $st   = $pdo->prepare(
        'UPDATE admins SET senha=?, tentativas=0, bloqueado_ate=NULL, primeiro_login=1 WHERE email=?'
    );
    $st->execute([$hash, $_SERVER['_GW_EMAIL']]);
    echo 'ok';
} catch (Exception $e) {
    echo 'erro:' . $e->getMessage();
}
PHP
    )

    if [[ "$resultado" != "ok" ]]; then
        erro "Falha ao atualizar senha: ${resultado#erro:}"
        exit 1
    fi

    echo ""
    ok "Senha redefinida com sucesso!"
    echo "  Admin : ${email}"
    echo "  Senha temporária: ${nova}"
    echo "  (será solicitada troca no próximo acesso)"
    echo ""
}

# ==================================================================
# DISPATCHER
# ==================================================================
CMD="${1:-help}"; shift 2>/dev/null || true

case "$CMD" in
    status)             cmd_status ;;
    reload)             cmd_reload "$@" ;;
    nat)                cmd_nat "$@" ;;
    grupo|group)        cmd_grupo "$@" ;;
    dominio|domain)     cmd_dominio "$@" ;;
    log|logs)           cmd_log "$@" ;;
    backup)             cmd_backup "$@" ;;
    diag|diagnostico)   cmd_diag ;;
    diag-painel)        bash "$GWOS_DIR/scripts/diagnostico_painel.sh" ;;
    dns)                cmd_dns "$@" ;;
    ip|trocar-ip)       cmd_ip "$@" ;;
    update|atualizar)   cmd_update ;;
    resetsenha)         cmd_resetsenha "$@" ;;
    desbloqueio)        cmd_desbloqueio "$@" ;;
    senha)              cmd_senha "$@" ;;
    help|--help|-h)     cmd_help ;;
    *)
        erro "Comando desconhecido: $CMD"
        cmd_help
        exit 1
        ;;
esac
