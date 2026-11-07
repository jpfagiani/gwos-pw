#!/bin/bash
# GWOS — atualizador. Faz tudo o que é preciso para subir uma versão nova:
#   valida o pacote → backup → copia o código (preserva .env e storage) →
#   verifica a sintaxe → reaplica os módulos instalados (migrations, cron,
#   sudoers) → confere → e VOLTA ATRÁS sozinho se algo falhar.
#
# Uso (como root, no servidor):
#   bash atualizar.sh /caminho/GWOS-v2.0.0-dev.zip
#   bash atualizar.sh /caminho/pasta-descompactada
# Opções:
#   --dir /opt/gwos        diretório da instalação (detecta sozinho se omitido)
#   --sem-backup-banco     não roda scripts/backup.sh (a cópia dos arquivos continua)
#   --sem-modulos          só copia o código (não reaplica módulos)
#   --simular              mostra o que faria, sem alterar nada
#   --sim                  não pergunta confirmação
set -euo pipefail
umask 022

R='\033[0;31m'; G='\033[0;32m'; Y='\033[1;33m'; B='\033[1m'; N='\033[0m'
LOG=/var/log/gwos-atualizacao.log
ok()    { echo -e "${G}[OK]${N} $*"  | tee -a "$LOG"; }
info()  { echo -e "${Y}[..]${N} $*"  | tee -a "$LOG"; }
aviso() { echo -e "${Y}[!]${N} $*"   | tee -a "$LOG"; }
falha() { echo -e "${R}[ERRO]${N} $*" | tee -a "$LOG" >&2; }

ORIGEM=""; DESTINO=""; SEM_BKP_BANCO=0; SEM_MODULOS=0; SIMULAR=0; SIM=0
while [ $# -gt 0 ]; do
    case "$1" in
        --dir) DESTINO="${2:-}"; shift 2 ;;
        --sem-backup-banco) SEM_BKP_BANCO=1; shift ;;
        --sem-modulos) SEM_MODULOS=1; shift ;;
        --simular) SIMULAR=1; shift ;;
        --sim) SIM=1; shift ;;
        -h|--help) sed -n 2,19p "$0"; exit 0 ;;
        -*) falha "Opção desconhecida: $1"; exit 2 ;;
        *) ORIGEM="$1"; shift ;;
    esac
done

[ -n "$ORIGEM" ] || { falha "Informe o ZIP ou a pasta do pacote novo. Ex.: bash atualizar.sh GWOS-v2.0.0-dev.zip"; exit 2; }
[ "$(id -u)" -eq 0 ] || { falha "Execute como root (sudo bash $0 ...)."; exit 2; }
mkdir -p "$(dirname "$LOG")"; echo "=== $(date '+%F %T') atualizar.sh $* ===" >> "$LOG"

# ── 1. Localiza a instalação atual ─────────────────────────────────────
if [ -z "$DESTINO" ]; then
    if [ -L /usr/local/bin/gwos ]; then
        DESTINO="$(cd "$(dirname "$(readlink -f /usr/local/bin/gwos)")/.." && pwd)"
    else
        for d in /opt/gwos /opt/dns /srv/gwos "$HOME/gwos"; do
            [ -f "$d/.env" ] && [ -d "$d/app" ] && { DESTINO="$d"; break; }
        done
    fi
fi
[ -n "$DESTINO" ] && [ -d "$DESTINO/app" ] && [ -d "$DESTINO/modulos" ] \
    || { falha "Instalação do GWOS não encontrada. Informe com --dir /caminho."; exit 1; }
DESTINO="$(cd "$DESTINO" && pwd)"
info "Instalação atual: ${B}${DESTINO}${N}"

# ── 2. Prepara o pacote novo ───────────────────────────────────────────
TMP="$(mktemp -d /tmp/gwos-atualizar.XXXXXX)"
trap 'rm -rf "$TMP"' EXIT
if [ -f "$ORIGEM" ]; then
    command -v unzip >/dev/null || { info "Instalando unzip..."; apt-get install -y -qq unzip >/dev/null; }
    unzip -tq "$ORIGEM" >/dev/null || { falha "ZIP corrompido: $ORIGEM"; exit 1; }
    unzip -q "$ORIGEM" -d "$TMP/novo"
    NOVO="$TMP/novo"
    # ZIP com uma pasta raiz única? Entra nela.
    if [ ! -d "$NOVO/app" ] && [ "$(find "$NOVO" -mindepth 1 -maxdepth 1 | wc -l)" -eq 1 ]; then
        NOVO="$(find "$NOVO" -mindepth 1 -maxdepth 1 -type d | head -1)"
    fi
elif [ -d "$ORIGEM" ]; then
    NOVO="$(cd "$ORIGEM" && pwd)"
else
    falha "Não encontrei: $ORIGEM"; exit 1
fi
for f in app/Core/Router.php routes/web.php modulos/comum/lib.sh database/schema.sql config/config.php; do
    [ -f "$NOVO/$f" ] || { falha "Pacote inválido: falta $f"; exit 1; }
done
VERSAO_NOVA="$(grep -o "'versao' *=> *'[^']*'" "$NOVO/config/config.php" | sed "s/.*=> *'//;s/'//" || true)"
VERSAO_ATUAL="$(grep -o "'versao' *=> *'[^']*'" "$DESTINO/config/config.php" 2>/dev/null | sed "s/.*=> *'//;s/'//" || true)"
info "Versão atual: ${VERSAO_ATUAL:-desconhecida}  →  nova: ${VERSAO_NOVA:-desconhecida}"

# Sintaxe do código novo ANTES de tocar em qualquer coisa
if command -v php >/dev/null; then
    ruins=0
    while IFS= read -r -d '' f; do php -l "$f" >/dev/null 2>&1 || { falha "Erro de sintaxe: ${f#$NOVO/}"; ruins=1; }; done < <(find "$NOVO" -name '*.php' -print0)
    [ "$ruins" -eq 0 ] || { falha "Pacote com erro de sintaxe PHP; nada foi alterado."; exit 1; }
    ok "Sintaxe PHP do pacote novo verificada."
fi
while IFS= read -r -d '' f; do bash -n "$f" || { falha "Erro de sintaxe shell: ${f#$NOVO/}"; exit 1; }; done < <(find "$NOVO" -name '*.sh' -print0)
ok "Sintaxe shell do pacote novo verificada."

if [ "$SIMULAR" -eq 1 ]; then
    info "Simulação: arquivos que seriam alterados em ${DESTINO}:"
    rsync -ani --exclude '.env' --exclude 'storage/' --exclude 'docs/capturas/' "$NOVO/" "$DESTINO/" | head -60
    ok "Simulação concluída; nada foi alterado."; exit 0
fi

if [ "$SIM" -ne 1 ]; then
    read -rp "Atualizar ${DESTINO} para ${VERSAO_NOVA:-a versão nova}? Será feito backup antes. [s/N] " r
    [[ "$r" =~ ^[sSyY]$ ]] || { info "Cancelado."; exit 0; }
fi

# ── 3. Backup ──────────────────────────────────────────────────────────
command -v rsync >/dev/null || { info "Instalando rsync..."; apt-get install -y -qq rsync >/dev/null; }
BKP_BASE=/var/lib/gwos/atualizacoes; mkdir -p "$BKP_BASE"; chmod 700 "$BKP_BASE"
BKP="$BKP_BASE/antes_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BKP"
rsync -a --exclude 'storage/tarefas/' "$DESTINO/" "$BKP/codigo/"
[ -d /etc/gwos ] && cp -a /etc/gwos "$BKP/etc-gwos"
[ -f /etc/cron.d/gwos ] && cp -a /etc/cron.d/gwos "$BKP/cron-gwos"
ok "Cópia do código e da configuração em ${BKP}"
if [ "$SEM_BKP_BANCO" -eq 0 ] && [ -x "$DESTINO/scripts/backup.sh" ] && [ -f "$DESTINO/.env" ]; then
    if bash "$DESTINO/scripts/backup.sh" >>"$LOG" 2>&1; then ok "Backup do banco concluído (/var/lib/gwos/backups)."
    else falha "Backup do banco falhou. Corrija ou use --sem-backup-banco (por sua conta e risco)."; exit 1; fi
else
    aviso "Backup do banco ignorado (sem .env/backup.sh ou --sem-backup-banco)."
fi

reverter() {
    falha "Falha na atualização. Voltando ao estado anterior..."
    rsync -a --delete --exclude '.env' --exclude 'storage/' "$BKP/codigo/" "$DESTINO/" || true
    [ -f "$BKP/cron-gwos" ] && cp -a "$BKP/cron-gwos" /etc/cron.d/gwos || true
    for s in $(systemctl list-units --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do systemctl restart "$s" 2>/dev/null || true; done
    aviso "Código e cron restaurados de ${BKP}. As migrations do banco são só aditivas e não precisam ser desfeitas."
    aviso "Detalhes em ${LOG}."
    exit 1
}
trap 'reverter' ERR

# ── 4. Copia o código novo (preserva .env, storage e capturas) ─────────
info "Copiando o código novo..."
rsync -a --exclude '.env' --exclude 'storage/' --exclude 'docs/capturas/' "$NOVO/" "$DESTINO/"
mkdir -p "$DESTINO/storage/tarefas"
chmod +x "$DESTINO"/scripts/*.sh "$DESTINO"/modulos/*.sh "$DESTINO"/modulos/*/*.sh 2>/dev/null || true
if id www-data >/dev/null 2>&1; then
    chown -R www-data:www-data "$DESTINO/public" "$DESTINO/storage" 2>/dev/null || true
fi
ok "Código atualizado."

# ── 5. Reaplica os módulos que já estão instalados ─────────────────────
REG=/etc/gwos/modulos.d
if [ "$SEM_MODULOS" -eq 0 ]; then
    cd "$DESTINO/modulos"
    if [ -f "$REG/banco-mariadb" ]; then
        info "Reaplicando 10-banco-mariadb (migrations)..."
        bash 10-banco-mariadb/instalar.sh >>"$LOG" 2>&1 && ok "Banco: migrations aplicadas." || { falha "10-banco-mariadb falhou (veja $LOG)"; false; }
    fi
    if [ -f "$REG/painel-web" ]; then
        info "Reaplicando 60-painel-web (cron, permissões, sudoers, nginx)..."
        # Conta de admin já personalizada: não pergunta nada. Se precisar de
        # senha e houver terminal, o próprio módulo pergunta.
        bash 60-painel-web/instalar.sh </dev/tty >>"$LOG" 2>&1 || bash 60-painel-web/instalar.sh >>"$LOG" 2>&1 \
            && ok "Painel reaplicado." || { falha "60-painel-web falhou (veja $LOG)"; false; }
    fi
    # Módulos de rede NÃO são reinstalados: mexem em DNS/firewall/proxy e não mudaram.
    aviso "Módulos de rede (DNS, firewall, proxy, hora) não foram reinstalados de propósito."
else
    aviso "--sem-modulos: migrations, cron e sudoers NÃO foram reaplicados."
fi

# ── 6. Confere ─────────────────────────────────────────────────────────
trap - ERR
cd "$DESTINO"
if command -v php >/dev/null && [ -f .env ]; then
    php -r 'chdir($argv[1]); require "bootstrap/app.php"; echo "bootstrap OK\n";' "$DESTINO" >>"$LOG" 2>&1 \
        && ok "Aplicação carrega sem erro." || { trap 'reverter' ERR; false; }
fi
if [ -x modulos/verificar-todos.sh ] && [ "$SEM_MODULOS" -eq 0 ]; then
    bash modulos/verificar-todos.sh 2>&1 | tee -a "$LOG" | tail -25 || aviso "verificar-todos.sh apontou itens; veja acima."
fi
URL="$(grep -E '^APP_URL=' .env 2>/dev/null | cut -d= -f2- || true)"
if [ -n "$URL" ] && command -v curl >/dev/null; then
    cod="$(curl -ks -o /dev/null -m 8 -w '%{http_code}' "${URL%/}/login" || true)"
    [ "$cod" = "200" ] && ok "Painel respondeu 200 em ${URL%/}/login" || aviso "Painel respondeu '${cod:-sem resposta}' em ${URL%/}/login — confira."
fi

echo ""
ok "Atualização concluída: ${VERSAO_ATUAL:-?} → ${VERSAO_NOVA:-?}"
echo -e "  Backup (código/config): ${B}${BKP}${N}"
echo -e "  Log: ${B}${LOG}${N}"
echo -e "  Próximo passo: entre no painel, abra ${B}Diagnóstico${N} e teste uma ação rápida em um PC de teste."
