<?php
require dirname(__DIR__) . '/bootstrap/app.php';
use App\Core\{Database,Tarefas,Auditoria,Politicas};
try {
    $expirados=Database::fetchAll('SELECT id,endereco,grupo_id,expira_em,restaurar_ips FROM ips WHERE ativo=1 AND expira_em IS NOT NULL AND expira_em<=NOW() LIMIT 500');
    if(!$expirados) exit(0);
    foreach($expirados as $ip) {
        Database::execute('UPDATE ips SET ativo=0 WHERE id=? AND ativo=1 AND expira_em<=NOW()',[(int)$ip['id']]);
        if (!empty($ip['restaurar_ips'])) Politicas::restaurar(Database::conectar(), (string)$ip['restaurar_ips']);
        Auditoria::registrar('expirar_politica','ips',(int)$ip['id'],['ativo'=>1,'expira_em'=>$ip['expira_em']],['ativo'=>0]);
    }
    Tarefas::enfileirar('firewall_apply',[],null,'firewall_apply');
    Tarefas::enfileirar('hours_squid',[],null,'hours_squid');
} catch(Throwable $e) { fwrite(STDERR,'['.date('c').'] '.$e->getMessage()."\n"); exit(1); }
