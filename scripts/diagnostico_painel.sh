#!/bin/bash
# Diagnóstico rápido do painel GWOS
set -uo pipefail

GWOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$GWOS_DIR/.env"

sep() { echo ""; echo "──────────────────────────────────────────"; echo "  $*"; echo "──────────────────────────────────────────"; }

sep "SERVIÇOS"
for svc in nginx php8.4-fpm php8.3-fpm mariadb; do
    status=$(systemctl is-active "$svc" 2>/dev/null || echo "não instalado")
    printf "  %-20s %s\n" "$svc" "$status"
done

sep "PHP"
php_bin=$(command -v php 2>/dev/null || echo "não encontrado")
echo "  Binário : $php_bin"
[ -x "$php_bin" ] && echo "  Versão  : $(php -r 'echo PHP_VERSION;')"
fpm_sock=$(ls /run/php/php*.fpm.sock 2>/dev/null | head -1 || echo "nenhum socket")
echo "  Socket  : $fpm_sock"

sep "ARQUIVOS DO PAINEL"
echo "  Diretório : $GWOS_DIR"
if [ -f "$ENV_FILE" ]; then
    echo "  .env      : OK"
    grep -E '^(DB_HOST|DB_BANCO|DB_USUARIO|APP_URL|APP_DEBUG)=' "$ENV_FILE" | sed 's/^/    /'
else
    echo "  .env      : NÃO ENCONTRADO em $ENV_FILE"
fi
auth_file="$GWOS_DIR/app/Core/Auth.php"
if [ -f "$auth_file" ]; then
    echo "  Auth.php  : OK ($(wc -l < "$auth_file") linhas)"
else
    echo "  Auth.php  : NÃO ENCONTRADO"
fi

sep "BANCO DE DADOS — ADMIN"
if [ -f "$ENV_FILE" ]; then
    set -a; source "$ENV_FILE"; set +a
    resultado=$(
        _GW_HOST="${DB_HOST:-127.0.0.1}" \
        _GW_BANCO="${DB_BANCO:-gwos}" \
        _GW_USER="${DB_USUARIO:-gwos}" \
        _GW_PASS="${DB_SENHA}" \
        php << 'PHP'
<?php
try {
    $pdo = new PDO(
        "mysql:host={$_SERVER['_GW_HOST']};dbname={$_SERVER['_GW_BANCO']};charset=utf8mb4",
        $_SERVER['_GW_USER'],
        $_SERVER['_GW_PASS']
    );
    $row = $pdo->query("SELECT email, senha, ativo, tentativas, bloqueado_ate, primeiro_login FROM admins WHERE email='admin@gwos.local'")->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo "ADMIN NÃO ENCONTRADO\n"; exit; }
    echo "  email         : " . $row['email']         . "\n";
    echo "  ativo         : " . $row['ativo']          . "\n";
    echo "  tentativas    : " . $row['tentativas']     . "\n";
    echo "  bloqueado_ate : " . ($row['bloqueado_ate'] ?? 'null') . "\n";
    echo "  primeiro_login: " . $row['primeiro_login'] . "\n";
    echo "  hash presente : " . (!empty($row['senha']) ? "sim\n" : "não\n");
} catch (Exception $e) {
    echo "  ERRO BD: " . $e->getMessage() . "\n";
}
PHP
    )
    echo "$resultado"
else
    echo "  .env ausente — pulando verificação do banco"
fi

sep "NGINX"
nginx_conf=$(nginx -T 2>/dev/null | grep -E 'root|fastcgi_pass|server_name' | head -20 || echo "  nginx -T falhou")
echo "$nginx_conf" | sed 's/^/  /'

sep "LOGS RECENTES (últimas 5 linhas)"
for log in /var/log/nginx/error.log /var/log/gwos/app.log /opt/gwos/storage/logs/app.log; do
    [ -f "$log" ] || continue
    echo "  ── $log"
    tail -5 "$log" | sed 's/^/    /'
done

echo ""
echo "══ fim do diagnóstico ══"
echo ""
