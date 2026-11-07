<?php

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

\App\Core\Auth::iniciar();

$router = new \App\Core\Router();

require BASE_PATH . '/routes/web.php';
require BASE_PATH . '/routes/modulos.php';
require BASE_PATH . '/routes/api.php';

$router->resolver();
