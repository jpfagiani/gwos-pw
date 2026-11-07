<?php
namespace App\Core;

class Alertas
{
    public static function coletar(): void
    {
        $ativos=[];
        $wan=(string)(Database::valor("SELECT valor FROM configuracoes WHERE chave='iface_wan'")??'');
        $wan=preg_replace('/[^a-zA-Z0-9_.:-]/','',$wan);
        if($wan!=='' && is_file("/sys/class/net/{$wan}/operstate") && trim((string)@file_get_contents("/sys/class/net/{$wan}/operstate"))!=='up')
            $ativos['wan_down']=['critico','Interface WAN indisponível',"A interface {$wan} está sem link.",'rede'];
        $probe=(string)(Database::valor("SELECT valor FROM configuracoes WHERE chave='alerta_dns_dominio'")?:'debian.org');
        if(preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i',$probe) && is_executable('/usr/bin/timeout') && is_executable('/usr/bin/getent')) {
            $pipes=[]; $proc=@proc_open(['/usr/bin/timeout','4','/usr/bin/getent','ahostsv4',$probe],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
            if(is_resource($proc)) { $dnsOut=stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); $dnsCode=proc_close($proc); if($dnsCode!==0 || trim((string)$dnsOut)==='') $ativos['dns_indisponivel']=['critico','Falha na resolução DNS',"O teste de resolução para {$probe} falhou ou excedeu quatro segundos.",'dns']; }
        }
        $pct=@disk_total_space('/')>0 ? round((1-@disk_free_space('/')/@disk_total_space('/'))*100) : null;
        $limite=(int)(Database::valor("SELECT valor FROM configuracoes WHERE chave='alerta_disco_pct'")?:85);
        if($pct!==null && $pct >= $limite) $ativos['disco_alto']=['aviso','Uso de disco elevado',"O sistema está usando {$pct}% do disco (limite {$limite}%).",'sistema'];
        $mem=@file_get_contents('/proc/meminfo');
        if($mem && preg_match('/^MemTotal:\s+(\d+)/m',$mem,$t) && preg_match('/^MemAvailable:\s+(\d+)/m',$mem,$a)) {
            $mp=(int)round((1-(int)$a[1]/(int)$t[1])*100); $ml=(int)(Database::valor("SELECT valor FROM configuracoes WHERE chave='alerta_memoria_pct'")?:92);
            if($mp >= $ml) $ativos['memoria_alta']=['aviso','Uso de memória elevado',"A memória está {$mp}% ocupada (limite {$ml}%).",'sistema'];
        }
        $limBloq=(int)(Database::valor("SELECT valor FROM configuracoes WHERE chave='alerta_bloqueios_5min'")?:50);
        $bloq=(int)(Database::valor("SELECT COUNT(*) FROM acessos_internet WHERE ocorrido_em>=NOW()-INTERVAL 5 MINUTE AND (status LIKE '%DENIED%' OR status LIKE '%BLOCKED%')")?:0);
        if($bloq >= $limBloq) $ativos['bloqueios_altos']=['aviso','Pico de bloqueios',"{$bloq} requisições foram bloqueadas nos últimos cinco minutos.",'squid'];
        $limBytes=(int)(Database::valor("SELECT valor FROM configuracoes WHERE chave='alerta_consumo_5min_bytes'")?:104857600);
        foreach(Database::fetchAll('SELECT ip,SUM(bytes) total FROM acessos_internet WHERE ocorrido_em>=NOW()-INTERVAL 5 MINUTE GROUP BY ip HAVING total>=? LIMIT 20',[$limBytes]) as $consumo) {
            $key='consumo_'.substr(hash('sha256',$consumo['ip']),0,20);
            $ativos[$key]=['aviso','Consumo elevado em um equipamento',"O IP {$consumo['ip']} registrou ".number_format((int)$consumo['total'])." bytes nos últimos cinco minutos.",'squid'];
        }
        foreach(Database::fetchAll("SELECT mac,COUNT(*) total FROM equipamentos WHERE mac IS NOT NULL AND mac<>'' GROUP BY mac HAVING total>1 LIMIT 20") as $duplicado) {
            $key='mac_duplicado_'.substr(hash('sha256',strtolower($duplicado['mac'])),0,20);
            $ativos[$key]=['aviso','Endereço MAC duplicado',"O endereço MAC {$duplicado['mac']} está associado a mais de um IP.",'inventario'];
        }
        foreach(Database::fetchAll("SELECT CONCAT('admin:',id) conta FROM admins WHERE bloqueado_ate>NOW() UNION ALL SELECT CONCAT('usuario:',id) conta FROM usuarios_internet WHERE bloqueado_ate>NOW() LIMIT 50") as $conta) {
            $key='login_bloqueado_'.substr(hash('sha256',$conta['conta']),0,24);
            $ativos[$key]=['aviso','Conta bloqueada por tentativas',"Uma conta foi bloqueada após tentativas de autenticação inválidas ({$conta['conta']}).",'autenticacao'];
        }
        $maxSessoes=max(1,min(20,(int)(Database::valor("SELECT valor FROM configuracoes WHERE chave='acesso_max_sessoes_usuario'")?:3)));
        foreach(Database::fetchAll('SELECT s.usuario_id,u.email,COUNT(*) total FROM sessoes_internet s JOIN usuarios_internet u ON u.id=s.usuario_id WHERE s.expira_em>NOW() GROUP BY s.usuario_id,u.email HAVING total>? LIMIT 50',[$maxSessoes]) as $conta) {
            $key='sessoes_excedidas_'.$conta['usuario_id'];
            $ativos[$key]=['aviso','Limite de sessões excedido',"A conta {$conta['email']} mantém {$conta['total']} dispositivos ativos (limite {$maxSessoes}).",'portal'];
        }
        foreach(Modulos::instalados() as $modulo) foreach($modulo['servicos'] as $servico) {
            $estado=trim((string)shell_exec('systemctl is-active '.escapeshellarg($servico).' 2>/dev/null'));
            if($estado!=='' && $estado!=='active') { $key='servico_'.preg_replace('/[^a-zA-Z0-9_-]/','_',$servico); $ativos[$key]=['critico','Serviço indisponível',"O serviço {$servico} está {$estado}.",'servicos']; }
        }
        $ultimoBackup=Database::fetch('SELECT criado_em,status FROM backups ORDER BY criado_em DESC LIMIT 1');
        $horas=(int)(Database::valor("SELECT valor FROM configuracoes WHERE chave='alerta_backup_horas'")?:30);
        if(!$ultimoBackup || $ultimoBackup['status']!=='ok' || strtotime($ultimoBackup['criado_em'])<time()-$horas*3600)
            $ativos['backup_atrasado']=['aviso','Backup atrasado ou com falha',$ultimoBackup ? "Último backup: {$ultimoBackup['status']} em {$ultimoBackup['criado_em']}." : 'Nenhum backup foi registrado.','backup'];
        foreach(['/etc/gwos/portal-acesso/portal.crt'=>'Portal de acesso','/etc/squid/ssl_cert/gwos-ca.crt'=>'CA do Squid'] as $cert=>$nome) {
            if (!is_readable($cert) || !function_exists('openssl_x509_parse')) continue;
            $dados=@openssl_x509_parse((string)@file_get_contents($cert));
            if (!is_array($dados) || empty($dados['validTo_time_t'])) continue;
            $dias=(int)floor(($dados['validTo_time_t']-time())/86400);
            if($dias<=30) $ativos['certificado_'.substr(hash('sha256',$cert),0,12)]=[$dias<=7?'critico':'aviso','Certificado próximo do vencimento',"{$nome}: vence em {$dias} dia(s).",'certificados'];
        }
        foreach(Tarefas::recentes(100) as $tarefa) {
            if(!in_array($tarefa['status']??'', ['failed','timeout','unconfirmed'], true) || empty($tarefa['id'])) continue;
            $fim=strtotime((string)($tarefa['finalizada_em']??'')); if(!$fim || $fim<time()-86400) continue;
            $key='tarefa_falhou_'.substr(hash('sha256',(string)$tarefa['id']),0,24);
            $ativos[$key]=[($tarefa['status']==='timeout'?'critico':'aviso'),'Operação GWOS com falha',($tarefa['titulo']??'Operação').': '.($tarefa['status']??'falha').'. Consulte o histórico de operações.','tarefas'];
        }
        foreach(Database::fetchAll("SELECT id,acao,tabela,criado_em FROM auditoria WHERE criado_em>=NOW()-INTERVAL 5 MINUTE AND tabela IN ('nftables','dominios','nat_um_para_um','horarios','ips','ip_grupos','configuracoes') ORDER BY id DESC LIMIT 50") as $evento) {
            $key='mudanca_operacional_'.$evento['id'];
            $ativos[$key]=['info','Mudança operacional registrada',"Ação {$evento['acao']} em {$evento['tabela']} às {$evento['criado_em']}. Consulte a auditoria.",'auditoria'];
        }
        foreach($ativos as $key=>$v) Database::execute(
            "INSERT INTO alertas (chave,severidade,titulo,mensagem,origem,estado,ocorrencias,criado_em,atualizado_em) VALUES (?,?,?,?,?,'aberto',1,NOW(),NOW()) ON DUPLICATE KEY UPDATE severidade=VALUES(severidade),titulo=VALUES(titulo),mensagem=VALUES(mensagem),ocorrencias=ocorrencias+1,atualizado_em=NOW()",
            [$key,$v[0],$v[1],$v[2],$v[3]]
        );
        $chaves=array_keys($ativos);
        if($chaves) {
            $marks=implode(',',array_fill(0,count($chaves),'?'));
            Database::execute("UPDATE alertas SET estado='resolvido',resolvido_em=NOW(),atualizado_em=NOW() WHERE estado<>'resolvido' AND chave NOT LIKE 'novo_dispositivo_%' AND chave NOT IN ({$marks})",$chaves);
        } else Database::execute("UPDATE alertas SET estado='resolvido',resolvido_em=NOW(),atualizado_em=NOW() WHERE estado<>'resolvido' AND chave NOT LIKE 'novo_dispositivo_%'");
    }
}
