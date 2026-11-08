#!/bin/bash
# GWOS — atualizador. Faz tudo o que é preciso para subir uma versão nova:
#   valida o pacote → backup → copia o código (preserva .env e storage) →
#   verifica a sintaxe → reaplica os módulos instalados (migrations, cron,
#   sudoers) → confere → restaura o snapshot de arquivos em caso de falha.
#
# Uso (como root, no servidor):
#   bash atualizar.sh                         # procura o pacote sozinho (veja abaixo)
#   bash atualizar.sh /caminho/GWOS-v2.0.2-dev.zip
#   bash atualizar.sh /caminho/pasta-descompactada
#
# Sem argumento, procura o pacote novo (GWOS*.zip, o mais recente, ou uma pasta
# com app/ e modulos/) em: a pasta do próprio script, a pasta da instalação e as
# subpastas atualizacao/ e novo/, /root, o diretório atual. Se o script
# estiver dentro de um pacote recém-extraído (sem .env), esse pacote é a origem.
# Opções:
#   --dir /opt/gwos        diretório da instalação (detecta sozinho se omitido)
#   --sem-backup-banco     não roda scripts/backup.sh (a cópia dos arquivos continua)
#   --sem-modulos          só copia o código (não reaplica módulos)
#   --simular              mostra o que faria, sem alterar nada
#   --sim                  não pergunta confirmação
set -Eeuo pipefail
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

[ "$(id -u)" -eq 0 ] || { falha "Execute como root (sudo bash $0 ...)."; exit 2; }
mkdir -p "$(dirname "$LOG")"; echo "=== $(date '+%F %T') atualizar.sh $* ===" >> "$LOG"

# ── 0. Onde este script está? ──────────────────────────────────────────
AQUI="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
RAIZ_SCRIPT="$(cd "$AQUI/.." && pwd)"
if [ -d "$RAIZ_SCRIPT/app" ] && [ -d "$RAIZ_SCRIPT/modulos" ]; then
    if [ -f "$RAIZ_SCRIPT/.env" ]; then
        # Rodando de dentro da instalação: é ela que será atualizada.
        [ -n "$DESTINO" ] || DESTINO="$RAIZ_SCRIPT"
    elif [ -z "$ORIGEM" ]; then
        # Rodando de dentro de um pacote novo (sem .env): ele é a origem.
        ORIGEM="$RAIZ_SCRIPT"
    fi
fi

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

# ── 1b. Procura o pacote novo, se não foi informado ────────────────────
if [ -z "$ORIGEM" ]; then
    info "Procurando o pacote novo nas pastas do servidor..."
    CAND=()
    for d in "$AQUI" "$RAIZ_SCRIPT" "$DESTINO" "$DESTINO/atualizacao" "$DESTINO/novo" "$(dirname "$DESTINO")" /root /root/gwos-novo "$PWD"; do
        [ -d "$d" ] || continue
        while IFS= read -r z; do CAND+=("$z"); done < <(find "$d" -maxdepth 1 -type f -iname 'GWOS*.zip' 2>/dev/null)
        for sub in "$d/atualizacao" "$d/novo"; do
            [ -d "$sub/app" ] && [ -d "$sub/modulos" ] && [ "$(readlink -f "$sub")" != "$DESTINO" ] && CAND+=("$sub")
        done
    done
    MELHOR=""; MELHOR_T=0
    for c in "${CAND[@]:-}"; do
        [ -n "$c" ] || continue
        t="$(stat -c %Y "$c" 2>/dev/null || echo 0)"
        if [ "$t" -gt "$MELHOR_T" ]; then MELHOR="$c"; MELHOR_T="$t"; fi
    done
    if [ -z "$MELHOR" ]; then
        falha "Não achei nenhum pacote GWOS*.zip nem pasta de atualização."
        echo "  Coloque o ZIP em ${DESTINO}/atualizacao/ (ou em /root) e rode de novo, ou informe o caminho:"
        echo "    bash atualizar.sh /caminho/GWOS-v2.0.2-dev.zip"
        exit 1
    fi
    ORIGEM="$MELHOR"
    info "Pacote encontrado: ${B}${ORIGEM}${N}  ($(date -d @"$MELHOR_T" '+%d/%m/%Y %H:%M'))"
    [ "${#CAND[@]}" -gt 1 ] && aviso "Havia ${#CAND[@]} candidatos; usei o mais recente. Para outro, informe o caminho."
fi
# Nunca "atualizar" a instalação com ela mesma
[ "$(readlink -f "$ORIGEM")" != "$DESTINO" ] || { falha "A origem é a própria instalação. Informe o pacote novo."; exit 1; }

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
for f in app/Core/Router.php routes/web.php modulos/comum/lib.sh database/schema.sql config/config.php scripts/backup.sh scripts/ler_env.sh; do
    [ -f "$NOVO/$f" ] || { falha "Pacote inválido: falta $f"; exit 1; }
done
VERSAO_NOVA="$(grep -o "'versao' *=> *'[^']*'" "$NOVO/config/config.php" | sed "s/.*=> *'//;s/'//" || true)"
VERSAO_ATUAL="$(grep -o "'versao' *=> *'[^']*'" "$DESTINO/config/config.php" 2>/dev/null | sed "s/.*=> *'//;s/'//" || true)"
info "Versão atual: ${VERSAO_ATUAL:-desconhecida}  →  nova: ${VERSAO_NOVA:-desconhecida}"
if [ -n "$VERSAO_NOVA" ] && [ "$VERSAO_NOVA" = "$VERSAO_ATUAL" ]; then
    aviso "O pacote tem a MESMA versão da instalação. Se não era isso, informe o caminho do pacote certo."
elif [ -n "$VERSAO_NOVA" ] && [ -n "$VERSAO_ATUAL" ] && [ "$(printf '%s\n%s\n' "$VERSAO_NOVA" "$VERSAO_ATUAL" | sort -V | head -1)" = "$VERSAO_NOVA" ]; then
    aviso "O pacote parece MAIS ANTIGO que a instalação (${VERSAO_NOVA} < ${VERSAO_ATUAL})."
    if [ "$SIM" -eq 1 ]; then falha "Recusado em modo --sim: versão mais antiga. Rode sem --sim para confirmar."; exit 1; fi
fi

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
BKP="$(mktemp -d "$BKP_BASE/antes_$(date +%Y%m%d_%H%M%S)_XXXXXX")"
rsync -a --exclude 'storage/tarefas/' "$DESTINO/" "$BKP/codigo/"
# Snapshot dos arquivos gerenciados pelos módulos reaplicados.
DIRS_ESTADO=(/etc/gwos /etc/nginx/sites-available /etc/nginx/sites-enabled)
ARQS_ESTADO=(/etc/cron.d/gwos /etc/sudoers /etc/sudoers.d/gwos
    /usr/local/bin/gwos /usr/local/sbin/gwos-gerar-nginx /usr/local/sbin/gwos-gerar-portal-acesso)
for caminho in "${DIRS_ESTADO[@]}" "${ARQS_ESTADO[@]}"; do
    if [ -e "$caminho" ] || [ -L "$caminho" ]; then
        cp -a --parents "$caminho" "$BKP"
    fi
done
ok "Cópia do código e da configuração em ${BKP}"
if [ "$SEM_BKP_BANCO" -eq 0 ] && [ -f "$DESTINO/.env" ]; then
    # Usa o leitor literal da versão nova também no backup pré-atualização.
    mkdir -p "$TMP/backup/scripts"
    cp "$NOVO/scripts/"{backup,ler_env}.sh "$TMP/backup/scripts/"
    install -m 0600 "$DESTINO/.env" "$TMP/backup/.env"
    if bash "$TMP/backup/scripts/backup.sh" >>"$LOG" 2>&1; then
        ok "Backup do banco concluído (/var/lib/gwos/backups)."
    else
        falha "Backup do banco falhou. Corrija ou use --sem-backup-banco."; exit 1
    fi
else
    aviso "Backup do banco ignorado (sem .env ou --sem-backup-banco)."
fi

reverter() {
    trap - ERR
    falha "Falha na atualização. Restaurando os arquivos do snapshot..."
    local falhou=0 caminho unidade
    rsync -a --delete --exclude 'storage/' "$BKP/codigo/" "$DESTINO/" || falhou=1
    if [ -f "$DESTINO/.env" ]; then
        chown root:www-data "$DESTINO/.env" && chmod 0640 "$DESTINO/.env" || falhou=1
    fi
    if [ "$SEM_MODULOS" -eq 0 ]; then
        for caminho in "${DIRS_ESTADO[@]}"; do
            if [ -d "$BKP$caminho" ]; then
                rsync -a --delete "$BKP$caminho/" "$caminho/" || falhou=1
            fi
        done
        for caminho in "${ARQS_ESTADO[@]}"; do
            if [ -e "$BKP$caminho" ] || [ -L "$BKP$caminho" ]; then
                cp -a --remove-destination "$BKP$caminho" "$caminho" || falhou=1
            else
                rm -f -- "$caminho" || falhou=1
            fi
        done
        if systemctl is-active --quiet nginx; then
            nginx -t >>"$LOG" 2>&1 && systemctl reload nginx >>"$LOG" 2>&1 || falhou=1
        fi
    fi
    for unidade in $(systemctl list-units --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do
        systemctl restart "$unidade" >>"$LOG" 2>&1 || falhou=1
    done
    if [ "$falhou" -eq 0 ]; then
        aviso "Arquivos do snapshot restaurados de ${BKP}."
    else
        falha "Reversão incompleta. Confira o snapshot em ${BKP} e o log."
    fi
    aviso "O banco mantém as migrations executadas. Detalhes em ${LOG}."
    exit 1
}
trap 'reverter' ERR

# ── 4. Copia o código novo (preserva .env, storage e capturas) ─────────
info "Copiando o código novo..."
rsync -a --exclude '.env' --exclude 'storage/' --exclude 'docs/capturas/' "$NOVO/" "$DESTINO/"
mkdir -p "$DESTINO/storage/tarefas"
chmod +x "$DESTINO"/scripts/*.sh "$DESTINO"/modulos/*.sh "$DESTINO"/modulos/*/*.sh 2>/dev/null || true
if id www-data >/dev/null 2>&1; then
    chown root:root "$DESTINO"
    chmod go-w "$DESTINO"
    chown -R root:root "$DESTINO/public"
    chmod -R go-w "$DESTINO/public"
    chown -R www-data:www-data "$DESTINO/storage"
    if [ -f "$DESTINO/.env" ]; then
        chown root:www-data "$DESTINO/.env"
        chmod 0640 "$DESTINO/.env"
    fi
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
cd "$DESTINO"
if command -v php >/dev/null && [ -f .env ]; then
    php -r 'chdir($argv[1]); require "bootstrap/app.php"; echo "bootstrap OK\n";' "$DESTINO" >>"$LOG" 2>&1 \
        && ok "Aplicação carrega sem erro." || { false; }
fi
if [ -x modulos/verificar-todos.sh ] && [ "$SEM_MODULOS" -eq 0 ]; then
    bash modulos/verificar-todos.sh 2>&1 | tee -a "$LOG" | tail -25 || aviso "verificar-todos.sh apontou itens; veja acima."
fi
URL="$(grep -E '^APP_URL=' .env 2>/dev/null | cut -d= -f2- || true)"
if [ -n "$URL" ] && command -v curl >/dev/null; then
    cod="$(curl -ks -o /dev/null -m 8 -w '%{http_code}' "${URL%/}/login" || true)"
    [ "$cod" = "200" ] && ok "Painel respondeu 200 em ${URL%/}/login" || aviso "Painel respondeu '${cod:-sem resposta}' em ${URL%/}/login — confira."
fi

trap - ERR
echo ""
ok "Atualização concluída: ${VERSAO_ATUAL:-?} → ${VERSAO_NOVA:-?}"
echo -e "  Backup (código/config): ${B}${BKP}${N}"
echo -e "  Log: ${B}${LOG}${N}"
echo -e "  Próximo passo: entre no painel, abra ${B}Diagnóstico${N} e teste uma ação rápida em um PC de teste."
