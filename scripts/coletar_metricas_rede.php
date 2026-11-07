<?php
require dirname(__DIR__) . '/bootstrap/app.php';
use App\Core\Database;
try {
    foreach(['iface_lan','iface_wan'] as $chave) {
        $iface=(string)(Database::valor('SELECT valor FROM configuracoes WHERE chave=?',[$chave])??'');
        if(!preg_match('/^[a-zA-Z0-9_.:-]{1,32}$/',$iface)) continue;
        $base='/sys/class/net/'.$iface.'/statistics/';
        $rx=@file_get_contents($base.'rx_bytes'); $tx=@file_get_contents($base.'tx_bytes');
        if($rx===false||$tx===false||!ctype_digit(trim($rx))||!ctype_digit(trim($tx))) continue;
        Database::execute('INSERT INTO metricas_rede (interface,rx_bytes,tx_bytes,coletado_em) VALUES (?,?,?,NOW())',[$iface,$rx,$tx]);
    }
    Database::execute('DELETE FROM metricas_rede WHERE coletado_em<NOW()-INTERVAL 7 DAY');
} catch(Throwable $e) { fwrite(STDERR,'['.date('c').'] '.$e->getMessage()."\n"); exit(1); }
