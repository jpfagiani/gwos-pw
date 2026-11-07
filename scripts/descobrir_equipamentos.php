<?php
require dirname(__DIR__) . '/bootstrap/app.php';
use App\Core\Database;

function salvarEquipamento(string $ip, ?string $mac, ?string $host, string $origem, ?string $leaseExpira=null, ?string $interface=null): void {
    if(!filter_var($ip,FILTER_VALIDATE_IP)) return;
    if($mac!==null && !preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/i',$mac))$mac=null;
    $host=($host && $host!=='*')?substr($host,0,253):null;
    $novo=!Database::fetch('SELECT id FROM equipamentos WHERE ip=?',[$ip]);
    Database::execute("INSERT INTO equipamentos (ip,mac,hostname,origem,lease_expira_em,interface_rede) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE mac=COALESCE(VALUES(mac),mac),hostname=COALESCE(VALUES(hostname),hostname),lease_expira_em=COALESCE(VALUES(lease_expira_em),lease_expira_em),interface_rede=COALESCE(VALUES(interface_rede),interface_rede),origem=IF(origem='manual','manual',IF(VALUES(origem)='dhcp','dhcp',IF(origem='dhcp','dhcp',VALUES(origem))))",[$ip,$mac,$host,$origem,$leaseExpira,$interface]);
    if($novo) {
        $chave='novo_dispositivo_'.substr(hash('sha256',$ip),0,24);
        Database::execute("INSERT INTO alertas (chave,severidade,titulo,mensagem,origem,estado,criado_em,atualizado_em) VALUES (?,'info','Novo equipamento observado',?,'inventario','aberto',NOW(),NOW()) ON DUPLICATE KEY UPDATE atualizado_em=NOW()",[$chave,"Novo endereço observado na rede: {$ip}" ]);
    }
}

try {
    foreach(['/var/lib/misc/dnsmasq.leases','/var/lib/dnsmasq/dnsmasq.leases'] as $file) if(is_readable($file)) {
        foreach(file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line) { $p=preg_split('/\s+/',trim($line)); if(count($p)>=4 && (int)$p[0]>time()) salvarEquipamento($p[2],$p[1],$p[3],'dhcp',date('Y-m-d H:i:s',(int)$p[0])); }
    }
    $lease='/var/lib/dhcp/dhcpd.leases';
    if(is_readable($lease)) {
        $txt=(string)file_get_contents($lease);
        preg_match_all('/lease\s+([0-9a-f:.]+)\s*\{(.*?)\}/is',$txt,$blocks,PREG_SET_ORDER);
        foreach($blocks as $b) {
            if(!preg_match('/binding state active/i',$b[2]))continue;
            preg_match('/hardware ethernet\s+([0-9a-f:]+)/i',$b[2],$m); preg_match('/client-hostname\s+"([^"]+)"/i',$b[2],$h);
            $expira=null; if(preg_match('/ends\s+\d+\s+(\d{4}\/\d{2}\/\d{2})\s+(\d{2}:\d{2}:\d{2})/i',$b[2],$fim)) { $dt=\DateTime::createFromFormat('!Y/m/d H:i:s',$fim[1].' '.$fim[2]); if($dt)$expira=$dt->format('Y-m-d H:i:s'); }
            salvarEquipamento($b[1],$m[1]??null,$h[1]??null,'dhcp',$expira);
        }
    }
    $out=[]; $cmd=PHP_OS_FAMILY==='Windows'?null:(is_executable('/usr/sbin/ip')?'/usr/sbin/ip':'/sbin/ip');
    if($cmd && function_exists('proc_open')) {
        $p=proc_open([$cmd,'-o','neigh','show'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(is_resource($p)) { $out=stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); }
    }
    foreach(preg_split('/\R/',(string)$out) as $line) {
        if(!preg_match('/^([0-9a-f:.]+)\s+dev\s+(\S+)(?:\s+lladdr\s+([0-9a-f:]+))?.*?\s(REACHABLE|DELAY|PROBE|STALE|PERMANENT|NOARP|FAILED)$/i',$line,$m))continue;
        if(strtoupper($m[4])==='FAILED')continue;
        salvarEquipamento($m[1],$m[3]??null,null,'vizinho',null,$m[2]);
    }
    foreach(Database::fetchAll('SELECT ip FROM sessoes_internet WHERE expira_em>NOW()') as $sessao) salvarEquipamento($sessao['ip'],null,null,'sessao');
    Database::execute('UPDATE sessoes_internet s JOIN equipamentos e ON e.ip=s.ip SET s.mac=e.mac,s.hostname=e.hostname WHERE s.expira_em>NOW()');
} catch(Throwable $e) { fwrite(STDERR,'['.date('c').'] '.$e->getMessage()."\n"); exit(1); }
