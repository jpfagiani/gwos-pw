<?php
/**
 * GWOS — Bootstrap para uso via CLI (gwos-cli.sh)
 * Inicializa constantes, autoloader e helpers sem sessão HTTP.
 */

define('GWOS', true);
define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Core/helpers.php';

spl_autoload_register(function (string $classe): void {
    $arquivo = BASE_PATH . '/' . str_replace(['\\', 'App/'], ['/', 'app/'], $classe) . '.php';
    if (file_exists($arquivo)) {
        require $arquivo;
    }
});

date_default_timezone_set(config('app.timezone') ?: 'America/Sao_Paulo');
