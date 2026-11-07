<?php
require dirname(__DIR__) . '/bootstrap/app.php';
try { App\Core\Alertas::coletar(); }
catch (Throwable $e) { fwrite(STDERR, '['.date('c').'] '.$e->getMessage()."\n"); exit(1); }
