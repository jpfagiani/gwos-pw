#!/bin/bash
# ============================================================================
# GWOS — Módulo 60-painel-web: painel de administração
# ============================================================================
# Instala o Nginx + PHP-FPM (a versão da própria distribuição), publica o
# painel, cria o comando 'gwos', as permissões sudo e as tarefas de cron.
#
# Depende do módulo 10-banco-mariadb (é dele que o painel lê tudo).
# Junto:   é por aqui que grupos, IPs, domínios, horários e NAT são editados;
#          ao salvar, o painel chama os scripts que regeram Squid, BIND9 e
#          nftables dos outros módulos.
#
# Este módulo precisa do repositório GWOS completo (app/, public/, scripts/).
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

iniciar_modulo "60-painel-web"

REPO="$(raiz_projeto)" || erro "Repositório GWOS não encontrado — este módulo precisa de app/, public/ e scripts/."
info "Projeto: ${REPO}"

# O banco é opcional. Com ele, o painel mostra grupos, IPs, domínios, horários
# e relatórios. Sem ele, sobe em MODO LEVE: só as telas cujos dados vivem em
# arquivo (módulos, rede, DNS, hora, nomes). É o que permite ter painel num
# servidor que só roda DNS, sem subir um MariaDB para guardar uma senha.
MODO_LEVE=1
if tem_banco && [ -f "$GWOS_DB_CONF" ]; then
    MODO_LEVE=0
    # shellcheck disable=SC1090
    . "$GWOS_DB_CONF"
    ok "Módulo 10-banco-mariadb encontrado — painel completo."
else
    aviso "Sem o módulo 10-banco-mariadb — painel em modo leve."
    aviso "Grupos, domínios, horários e relatórios ficam indisponíveis."
fi

# ---------------------------------------------------------------------------
# PHP — usa a versão da própria distribuição
# ---------------------------------------------------------------------------
# Debian 12 traz 8.2, Debian 13 traz 8.4. Qualquer uma serve para o painel.
# O repositório sury.org só entra em cena se o Debian for antigo demais — é de
# terceiros, e uma fonte a menos é uma coisa a menos para quebrar.
apt_update_uma_vez
PHP_VER="$(detectar_php_disponivel || echo '')"

if [ -n "$PHP_VER" ] && php_versao_suficiente "$PHP_VER" "$PHP_MINIMO"; then
    ok "PHP ${PHP_VER} disponível no Debian $(. /etc/os-release 2>/dev/null; echo "${VERSION_ID:-?}") — sem repositório extra."
else
    aviso "PHP da distribuição ${PHP_VER:-ausente} é inferior ao mínimo ${PHP_MINIMO}."
    info "Adicionando o repositório PHP (sury.org)..."
    instalar_pacotes curl gnupg2 lsb-release ca-certificates apt-transport-https
    curl -fsSL https://packages.sury.org/php/apt.gpg \
        | gpg --dearmor -o /etc/apt/trusted.gpg.d/php-sury.gpg
    echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" \
        > /etc/apt/sources.list.d/php-sury.list
    apt-get update -qq
    PHP_VER="$(detectar_php_disponivel || echo '8.4')"
    ok "Repositório sury.org adicionado — PHP ${PHP_VER}."
fi

instalar_pacotes nginx \
    "php${PHP_VER}" "php${PHP_VER}-fpm" "php${PHP_VER}-mysql" "php${PHP_VER}-mbstring" \
    "php${PHP_VER}-curl" "php${PHP_VER}-zip" "php${PHP_VER}-xml" "php${PHP_VER}-intl" \
    tar gzip unzip openssl

# Segredo efêmero usado apenas para provisionar/rotacionar a credencial inicial.
SENHA_INICIAL="$(openssl rand -hex 16)"
ADMIN_INICIAL_RESULTADO=""

# ---------------------------------------------------------------------------
# PHP-FPM
# ---------------------------------------------------------------------------
PHP_INI="/etc/php/${PHP_VER}/fpm/php.ini"
[ -f "$PHP_INI" ] || erro "php.ini não encontrado em ${PHP_INI} — instalação do PHP falhou."
sed -i "s|;date.timezone.*|date.timezone = America/Sao_Paulo|" "$PHP_INI"
sed -i "s|upload_max_filesize.*|upload_max_filesize = 64M|"    "$PHP_INI"
sed -i "s|post_max_size.*|post_max_size = 64M|"                "$PHP_INI"
svc_ativar "php${PHP_VER}-fpm"

PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
ok "PHP-FPM ${PHP_VER} configurado (${PHP_SOCK})."

# Guarda a versão no estado compartilhado — o painel usa para saber qual
# serviço monitorar, e o desinstalador para saber o que remover.
salvar_conf PHP_VERSAO "$PHP_VER"

# ---------------------------------------------------------------------------
# Nginx — o painel precisa de uma porta só dele
# ---------------------------------------------------------------------------
# Porta 8080 por convenção, em toda unidade — não é um plano B para quando a
# 80 está ocupada. Padronizar vale mais que ganhar a 80: quem dá suporte a
# vários presídios encontra o painel sempre no mesmo lugar, e a 80 fica para o
# portal de sistemas, que é o que os usuários acessam.
SITES_EXISTENTES="$(nginx_outros_sites)"
PORTA_PADRAO=8080
PORTA_SUGERIDA="$PORTA_PADRAO"

OCUPANTE_PADRAO="$(porta_em_uso "$PORTA_PADRAO")"
if [ -n "$OCUPANTE_PADRAO" ] && ! echo "$OCUPANTE_PADRAO" | grep -q nginx; then
    PORTA_SUGERIDA="$(primeira_porta_livre 8081 8090 9080 || echo 8081)"
    aviso "A porta padrão ${PORTA_PADRAO} está ocupada por: ${OCUPANTE_PADRAO}"
    echo  "      Liberá-la mantém esta máquina igual às demais unidades."
fi

titulo "── Porta do painel ──"
echo ""
echo "  Convenção do projeto, igual em todas as unidades:"
echo "     80    portal-sistemas  (atalhos — todos os usuários)"
echo "     8080  portal-gateway   (este painel — administração)"
echo "     8443  portal-samba     (arquivos — administração)"
echo ""
echo "  Mude só se a 8080 estiver ocupada por algo que não dá para mover."
echo "  Depois: gwos-definir PAINEL_PORTA <porta>"
echo ""
perguntar PAINEL_PORTA "Porta do painel GWOS" "$PORTA_SUGERIDA"

while ! echo "$PAINEL_PORTA" | grep -qE '^[0-9]{1,5}$' || [ "$PAINEL_PORTA" -lt 1 ] || [ "$PAINEL_PORTA" -gt 65535 ]; do
    aviso "Porta inválida: '${PAINEL_PORTA}'"
    perguntar PAINEL_PORTA "Porta do painel GWOS" "$PORTA_SUGERIDA"
done

OCUPANTE="$(porta_em_uso "$PAINEL_PORTA")"
while [ -n "$OCUPANTE" ] && ! echo "$OCUPANTE" | grep -q nginx; do
    aviso "A porta ${PAINEL_PORTA} está ocupada por: ${OCUPANTE}"
    perguntar PAINEL_PORTA "Outra porta para o painel" "$(primeira_porta_livre 8080 8081 8090 9080 || echo 8080)"
    OCUPANTE="$(porta_em_uso "$PAINEL_PORTA")"
done

salvar_conf PAINEL_PORTA "$PAINEL_PORTA"

# ---------------------------------------------------------------------------
# O site 'default' de fábrica
# ---------------------------------------------------------------------------
# Ele escuta na 80 servindo a página "Welcome to nginx!". Se o painel foi para
# outra porta, esse placeholder fica SEGURANDO a 80 e impede qualquer aplicação
# de verdade de subir ali — foi o que aconteceu com o portal de sistemas.
# Nada se perde ao desabilitá-lo: ele não serve nada de ninguém.
if [ -e /etc/nginx/sites-enabled/default ]; then
    if grep -q 'index.nginx-debian.html' /etc/nginx/sites-available/default 2>/dev/null; then
        # Arquivo de fábrica, não customizado. Como o painel fica sempre na
        # 8080, o 'default' só estaria segurando a 80 — que pela convenção é
        # do portal de sistemas. Remover é o certo em toda instalação.
        rm -f /etc/nginx/sites-enabled/default
        nginx -t >/dev/null 2>&1 && systemctl reload nginx 2>/dev/null || true
        info "Site 'default' do Debian removido — a porta 80 fica livre para o"
        info "portal de sistemas (convenção do projeto)."
    else
        aviso "O site 'default' do nginx foi customizado — mantido intacto."
    fi
fi

# Nomes que o vhost já teve. Dois vhosts do mesmo painel na mesma porta
# faria o nginx recusar a configuração inteira.
for _antigo in gwos gwos-portal; do
    rm -f "/etc/nginx/sites-enabled/${_antigo}" "/etc/nginx/sites-available/${_antigo}"
done

install -m 755 "${MOD_DIR}/gerar-nginx.sh" /usr/local/sbin/gwos-gerar-nginx
install -m 755 "${MOD_DIR}/gerar-portal-acesso.sh" /usr/local/sbin/gwos-gerar-portal-acesso

if ! /usr/local/sbin/gwos-gerar-nginx; then
    falha "Não foi possível publicar o site do painel."
    journalctl -xeu nginx --no-pager -n 15 2>/dev/null | sed 's/^/      /' || true
    erro "Módulo interrompido."
fi

svc_ativar nginx
ok "Nginx configurado na porta ${PAINEL_PORTA}."

# ---------------------------------------------------------------------------
# .env
# ---------------------------------------------------------------------------
if [ "$PAINEL_PORTA" = "80" ]; then
    APP_URL_PAINEL="http://${IP_GATEWAY}"
else
    APP_URL_PAINEL="http://${IP_GATEWAY}:${PAINEL_PORTA}"
fi

if [ "$MODO_LEVE" = "0" ]; then
    cat > "${REPO}/.env" <<ENV
APP_URL=${APP_URL_PAINEL}
APP_DEBUG=false
DB_HOST=${DB_HOST}
DB_BANCO=${DB_BANCO}
DB_USUARIO=${DB_USUARIO}
DB_SENHA=${DB_SENHA}
ENV
else
    cat > "${REPO}/.env" <<ENV
APP_URL=${APP_URL_PAINEL}
APP_DEBUG=false
# Modo leve — sem banco de dados. Os usuários do painel ficam em
# /etc/gwos/painel-usuarios.json. Instale o módulo 10-banco-mariadb e
# reexecute este módulo para ligar o painel completo.
ENV
fi
chmod 600 "${REPO}/.env"
chown www-data:www-data "${REPO}/.env"
ok ".env do painel criado."

# ---------------------------------------------------------------------------
# Usuários do painel em arquivo (só no modo leve)
# ---------------------------------------------------------------------------
if [ "$MODO_LEVE" = "1" ]; then
    # Diretório próprio: a gravação do painel é atômica (tmp + rename) e
    # criar o .tmp exige escrita NO DIRETÓRIO. Com o arquivo solto em
    # /etc/gwos (755 root), o www-data lia mas não gravava — e a troca de
    # senha falhava sem dizer.
    DIR_PAINEL="/etc/gwos/painel"
    ARQ_USUARIOS="${DIR_PAINEL}/usuarios.json"
    mkdir -p "$DIR_PAINEL"
    chown root:www-data "$DIR_PAINEL"
    chmod 2770 "$DIR_PAINEL"

    # Instalação anterior guardava o arquivo um nível acima
    if [ -f /etc/gwos/painel-usuarios.json ] && [ ! -f "$ARQ_USUARIOS" ]; then
        mv /etc/gwos/painel-usuarios.json "$ARQ_USUARIOS"
        info "Usuários do painel migrados para ${ARQ_USUARIOS}."
    fi
    if [ -f "$ARQ_USUARIOS" ]; then
        aviso "Usuários do painel preservados (${ARQ_USUARIOS})."
        ESTADO_ADMIN=$(GWOS_USERS_FILE="$ARQ_USUARIOS" GWOS_INIT_PASS="$SENHA_INICIAL" GWOS_LEGACY_PASS='gwos@2025' php <<'PHP'
<?php
$arquivo = getenv('GWOS_USERS_FILE');
$usuarios = json_decode((string) @file_get_contents($arquivo), true);
if (!is_array($usuarios)) exit(1);
$mudou = false;
foreach ($usuarios as &$usuario) {
    if (($usuario['email'] ?? '') === 'admin@gwos.local' && password_verify(getenv('GWOS_LEGACY_PASS'), (string) ($usuario['senha'] ?? ''))) {
        $usuario['senha'] = password_hash(getenv('GWOS_INIT_PASS'), PASSWORD_BCRYPT, ['cost' => 12]);
        $usuario['primeiro_login'] = 1;
        $mudou = true;
    }
}
unset($usuario);
if ($mudou) {
    $tmp = $arquivo . '.tmp';
    if (file_put_contents($tmp, json_encode($usuarios, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) exit(1);
    chmod($tmp, 0660);
    if (!rename($tmp, $arquivo)) exit(1);
    echo 'ROTACIONADO';
}
PHP
)
        if [ "$ESTADO_ADMIN" = "ROTACIONADO" ]; then ADMIN_INICIAL_RESULTADO="$SENHA_INICIAL"; fi
    else
        HASH=$(GWOS_INIT_PASS="$SENHA_INICIAL" php -r 'echo password_hash(getenv("GWOS_INIT_PASS"), PASSWORD_BCRYPT, ["cost" => 12]);')
        cat > "$ARQ_USUARIOS" <<USUARIOS
[
    {
        "id": 1,
        "nome": "Administrador",
        "email": "admin@gwos.local",
        "senha": "${HASH}",
        "perfil": "superadmin",
        "ativo": 1,
        "primeiro_login": 1,
        "tentativas": 0,
        "bloqueado_ate": null,
        "ultimo_login": null,
        "reset_token": null,
        "reset_expira": null
    }
]
USUARIOS
        ok "Usuário do painel criado em ${ARQ_USUARIOS}."
        ADMIN_INICIAL_RESULTADO="$SENHA_INICIAL"
    fi
    # O painel precisa escrever aqui (tentativas, bloqueio, troca de senha)
    chown root:www-data "$ARQ_USUARIOS"
    chmod 660 "$ARQ_USUARIOS"

    # Confere de verdade, em vez de supor: um erro aqui só apareceria quando
    # o administrador tentasse trocar a senha.
    if sudo -u www-data test -r "$ARQ_USUARIOS" && sudo -u www-data test -w "$DIR_PAINEL"; then
        ok "www-data lê os usuários e pode gravar em ${DIR_PAINEL}."
    else
        aviso "www-data NÃO tem acesso a ${ARQ_USUARIOS} — o login vai falhar."
        ls -ld "$DIR_PAINEL" "$ARQ_USUARIOS" | sed 's/^/      /'
    fi

fi

# ---------------------------------------------------------------------------
# Sudo — o painel roda como www-data e precisa aplicar as regras
# ---------------------------------------------------------------------------
# O sudo é dependência dura do painel: ele roda como www-data e aplica as
# regras chamando scripts por sudo. Numa instalação mínima do Debian o pacote
# pode simplesmente não existir — e aí não há /etc/sudoers nem /etc/sudoers.d,
# e a escrita do arquivo de permissões falha no meio da instalação.
instalar_pacotes sudo

[ -f /etc/sudoers ] || erro "/etc/sudoers não existe mesmo após instalar o sudo — instalação abortada."

# Um /etc/sudoers sem regra para o root deixa a máquina sem sudo nenhum —
# "root is not in the sudoers file". Versões anteriores deste módulo criavam
# exatamente isso quando o pacote sudo não estava instalado: o >> gerava um
# arquivo com uma linha só. Se for esse o caso, restaura o padrão do Debian.
if ! grep -qE '^[[:space:]]*root[[:space:]]+ALL' /etc/sudoers; then
    aviso "/etc/sudoers não tem regra para o root — o sudo desta máquina está quebrado."
    backup_arquivo /etc/sudoers
    SUDOERS_NOVO="$(mktemp /tmp/gwos_sudoers_base.XXXXXX)"
    cat > "$SUDOERS_NOVO" <<'SUDOERS'
# Restaurado pelo módulo 60-painel-web do GWOS — padrão do Debian.
Defaults        env_reset
Defaults        mail_badpass
Defaults        secure_path="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

root    ALL=(ALL:ALL) ALL
%sudo   ALL=(ALL:ALL) ALL

@includedir /etc/sudoers.d
SUDOERS
    if visudo -c -f "$SUDOERS_NOVO" >/dev/null 2>&1; then
        install -m 440 -o root -g root "$SUDOERS_NOVO" /etc/sudoers
        rm -f "$SUDOERS_NOVO"
        ok "/etc/sudoers restaurado (root e %sudo voltam a ter permissão)."
    else
        rm -f "$SUDOERS_NOVO"
        erro "Não foi possível montar um /etc/sudoers válido — corrija à mão antes de seguir."
    fi
fi

# O diretório vem com o pacote, mas pode ter sido removido. Modo 0750 é o que
# o sudo exige; mais permissivo que isso ele recusa ler.
mkdir -p /etc/sudoers.d
chmod 0750 /etc/sudoers.d

# Acrescentar o @includedir num /etc/sudoers válido é seguro; num arquivo
# quebrado, não. Por isso a alteração é validada antes de valer — um sudoers
# inválido tranca o sudo da máquina inteira, inclusive para consertar.
if ! grep -q '^@includedir /etc/sudoers.d' /etc/sudoers; then
    SUDOERS_BAK="$(mktemp /tmp/gwos_sudoers.XXXXXX)"
    cp -a /etc/sudoers "$SUDOERS_BAK"
    echo '@includedir /etc/sudoers.d' >> /etc/sudoers
    if visudo -c -f /etc/sudoers >/dev/null 2>&1; then
        rm -f "$SUDOERS_BAK"
        ok "/etc/sudoers passa a carregar o sudoers.d."
    else
        cp -a "$SUDOERS_BAK" /etc/sudoers
        rm -f "$SUDOERS_BAK"
        erro "A alteração em /etc/sudoers foi reprovada — arquivo restaurado."
    fi
fi

SUDOERS_TMP="$(mktemp /tmp/gwos_sudoers_gwos.XXXXXX)"
{
    echo "Defaults:www-data !requiretty"
    for s in aplicar_nftables aplicar_nat aplicar_bind9_rpz gerar_squid_dominios \
             gerar_squid_acl backup restaurar_backup importar_log_squid aplicar_dns_hosts; do
        [ -f "${REPO}/scripts/${s}.sh" ] && \
            echo "www-data ALL=(root) NOPASSWD: ${REPO}/scripts/${s}.sh"
    done
    echo "www-data ALL=(root) NOPASSWD: /usr/local/sbin/gwos-gerar-nftables"
    echo "www-data ALL=(root) NOPASSWD: /usr/local/sbin/gwos-integrar"
    # Únicos caminhos de escrita do painel fora do banco. A lista de chaves, a
    # validação dos valores e o named-checkconf ficam dentro dos scripts.
    echo "www-data ALL=(root) NOPASSWD: /usr/local/sbin/gwos-definir"
    echo "www-data ALL=(root) NOPASSWD: /usr/local/sbin/gwos-zona"
    # Leitura e recarga dos serviços, com par (ação, módulo) de lista fechada
    echo "www-data ALL=(root) NOPASSWD: /usr/local/sbin/gwos-servico"
} > "$SUDOERS_TMP"

# Valida ANTES de pôr em /etc/sudoers.d: um arquivo invalido ali faz o sudo
# recusar a configuracao inteira, inclusive a do administrador.
if ! visudo -c -f "$SUDOERS_TMP" >/dev/null 2>&1; then
    falha "O sudoers gerado é inválido:"
    visudo -c -f "$SUDOERS_TMP" 2>&1 | sed 's/^/      /' || true
    rm -f "$SUDOERS_TMP"
    erro "Nada foi instalado em /etc/sudoers.d — o sudo desta máquina continua intacto."
fi

install -m 440 -o root -g root "$SUDOERS_TMP" /etc/sudoers.d/gwos
rm -f "$SUDOERS_TMP"
ok "Permissões sudo configuradas."

chmod +x "${REPO}/scripts/"*.sh

# ---------------------------------------------------------------------------
# Comando 'gwos'
# ---------------------------------------------------------------------------
ln -sf "${REPO}/scripts/gwos-cli.sh" /usr/local/bin/gwos
chmod +x /usr/local/bin/gwos
ok "Comando 'gwos' disponível no terminal."

# ---------------------------------------------------------------------------
# Cron
# ---------------------------------------------------------------------------
cat > /etc/cron.d/gwos <<CRON
# GWOS — tarefas automáticas (módulo 60-painel-web)
0 2 * * * root /bin/bash ${REPO}/scripts/backup.sh >> /var/log/gwos_backup.log 2>&1
*/5 * * * * root /usr/bin/php ${REPO}/scripts/parsear_logs.php >> /var/log/gwos_parser.log 2>&1
0 * * * * root /bin/bash ${REPO}/scripts/importar_log_squid.sh >> /var/log/gwos_import_log.log 2>&1
* * * * * root /usr/bin/php ${REPO}/scripts/importar_acessos_squid.php >> /var/log/gwos_acessos.log 2>&1
* * * * * www-data /usr/bin/php ${REPO}/scripts/processar_tarefas.php >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/gwos
ok "Tarefas de cron instaladas."

# ---------------------------------------------------------------------------
# Diretórios e permissões
# ---------------------------------------------------------------------------
mkdir -p /var/lib/gwos/backups /var/lib/gwos/sessions "${REPO}/storage/tarefas"
chown root:root /var/lib/gwos
chmod 0755 /var/lib/gwos
if getent group proxy >/dev/null; then chown root:proxy /var/lib/gwos/sessions; else chown root:www-data /var/lib/gwos/sessions; fi
chmod 2770 /var/lib/gwos/sessions
if [ -f /var/lib/gwos/sessoes.txt ] && [ ! -f /var/lib/gwos/sessions/sessoes.txt ]; then
    install -o www-data -g "$(stat -c %G /var/lib/gwos/sessions)" -m 0640 /var/lib/gwos/sessoes.txt /var/lib/gwos/sessions/sessoes.txt
    rm -f /var/lib/gwos/sessoes.txt
fi
[ -f /var/lib/gwos/sessions/sessoes.txt ] || install -o www-data -g "$(stat -c %G /var/lib/gwos/sessions)" -m 0640 /dev/null /var/lib/gwos/sessions/sessoes.txt
if getent group proxy >/dev/null; then chown www-data:proxy /var/lib/gwos/sessions/sessoes.txt; fi
chmod 0640 /var/lib/gwos/sessions/sessoes.txt
chown root:root /var/lib/gwos/backups
chmod 0700 /var/lib/gwos/backups
chown -R www-data:www-data "${REPO}/public" "${REPO}/storage"
chmod 2770 "${REPO}/storage/tarefas"
usermod -aG proxy www-data 2>/dev/null || true   # leitura do access.log do Squid
systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || true
ok "Diretórios e permissões ajustados."

# ---------------------------------------------------------------------------
# Primeiro administrador: credencial aleatória, impressa apenas uma vez.
# ---------------------------------------------------------------------------
if [ "$MODO_LEVE" = "0" ]; then
    ESTADO_ADMIN=$(GWOS_DB_HOST="$DB_HOST" GWOS_DB_NAME="$DB_BANCO" GWOS_DB_USER="$DB_USUARIO" GWOS_DB_PASS="$DB_SENHA" GWOS_INIT_PASS="$SENHA_INICIAL" GWOS_LEGACY_PASS='gwos@2025' php <<'PHP'
<?php
try {
    $pdo = new PDO('mysql:host=' . getenv('GWOS_DB_HOST') . ';dbname=' . getenv('GWOS_DB_NAME') . ';charset=utf8mb4', getenv('GWOS_DB_USER'), getenv('GWOS_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $email = 'admin@gwos.local';
    $stmt = $pdo->prepare('SELECT id, senha FROM admins WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        $stmt = $pdo->prepare('INSERT INTO admins (nome,email,senha,perfil,primeiro_login) VALUES (?,?,?,?,1)');
        $stmt->execute(['Administrador', $email, password_hash(getenv('GWOS_INIT_PASS'), PASSWORD_BCRYPT, ['cost' => 12]), 'superadmin']);
        echo 'CRIADO';
    } elseif (password_verify(getenv('GWOS_LEGACY_PASS'), (string) $admin['senha'])) {
        $stmt = $pdo->prepare('UPDATE admins SET senha=?, primeiro_login=1 WHERE id=?');
        $stmt->execute([password_hash(getenv('GWOS_INIT_PASS'), PASSWORD_BCRYPT, ['cost' => 12]), $admin['id']]);
        echo 'ROTACIONADO';
    } else {
        echo 'EXISTENTE';
    }
} catch (Throwable $e) { fwrite(STDERR, "Falha ao provisionar o administrador inicial.\n"); exit(1); }
PHP
)
    case "$ESTADO_ADMIN" in
        CRIADO|ROTACIONADO) ADMIN_INICIAL_RESULTADO="$SENHA_INICIAL" ;;
        EXISTENTE) ;;
        *) aviso "Não foi possível confirmar a conta inicial; consulte o diagnóstico sem exibir credenciais." ;;
    esac
fi

# Publica a CA do Squid, se existir
if tem_ssl_bump; then
    install -m 644 /etc/squid/ssl_cert/gwos-ca.crt "${REPO}/public/gwos-ca.crt"
    chown www-data:www-data "${REPO}/public/gwos-ca.crt"
    ok "CA do Squid publicada para download."
fi

registrar_modulo painel-web
integrar

echo ""
ok "Módulo 60-painel-web instalado."
echo -e "  URL          : ${BOLD}${APP_URL_PAINEL}${NC}"
echo -e "  Login        : ${BOLD}admin@gwos.local${NC}"
if [ -n "$ADMIN_INICIAL_RESULTADO" ]; then
    echo -e "  Senha inicial: ${BOLD}${ADMIN_INICIAL_RESULTADO}${NC}  ${YELLOW}(anote agora; troca obrigatória no primeiro acesso)${NC}"
else
    echo "  Senha inicial: conta preservada; use a credencial administrativa já cadastrada."
fi
if [ "$MODO_LEVE" = "1" ]; then
    echo ""
    aviso "Modo leve: o painel mostra os módulos e o que vive em arquivo."
    echo  "      Para o painel completo: bash ../10-banco-mariadb/instalar.sh"
    echo  "      e depois reexecute este módulo."
fi
echo ""
echo -e "  Módulos instalados nesta máquina: ${BOLD}$(modulos_instalados | paste -sd ', ' -)${NC}"
echo -e "  A tela ${BOLD}Módulos${NC} do painel se atualiza sozinha quando você instalar outro."
echo ""
