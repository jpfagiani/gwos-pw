<?php
namespace App\Controllers;
use App\Core\{Auth,Database,Controller,Monitoramento,Auditoria};

class OperacionalController extends Controller
{
    public function monitoramento(): void { Auth::exigir(); $f=array_intersect_key($_GET,array_flip(['ip','busca','protocolo','interface','decisao','periodo','grupo'])); $f['periodo']=$f['periodo']??'24h'; $periodos=Monitoramento::periodosPermitidos(); $acessos=Monitoramento::acessos($f); if(($_GET['json']??'')==='1')$this->json(['acessos'=>$acessos,'conexoes'=>Monitoramento::conexoesAtivas(),'coletado_em'=>date(DATE_ATOM)]); $grupos=Database::fetchAll('SELECT id,nome FROM ip_grupos WHERE ativo=1 ORDER BY nome'); $this->view('operacional/monitoramento',compact('acessos','f','periodos','grupos')); }
    public function ranking(): void { Auth::exigir(); $periodo=(string)($_GET['periodo']??'24h'); if(!isset(Monitoramento::periodosPermitidos()[$periodo]))$periodo='24h'; $limite=(int)($_GET['limite']??10); if(!in_array($limite,[10,20,50],true))$limite=10; $dados=Monitoramento::ranking($periodo,$limite); $periodos=Monitoramento::periodosPermitidos(); $this->view('operacional/ranking',compact('dados','periodo','limite','periodos')); }
    public function equipamentos(): void { Auth::exigir(); $equipamentos=Monitoramento::dispositivos(); $this->view('operacional/equipamentos',compact('equipamentos')); }
    public function fichaEquipamento(): void { Auth::exigir(); $ip=trim((string)($_GET['ip']??'')); if(!filter_var($ip,FILTER_VALIDATE_IP)){http_response_code(400);exit('Endereço IP inválido.');} $equipamentos=Monitoramento::dispositivos(); $equipamento=null; foreach($equipamentos as $e)if($e['ip']===$ip){$equipamento=$e;break;} if(!$equipamento){http_response_code(404);exit('Equipamento não encontrado.');} $historico=Monitoramento::acessos(['ip'=>$ip,'periodo'=>'30d'],100); $this->view('operacional/ficha_equipamento',compact('equipamento','historico')); }
    public function controleAcesso(): void { Auth::exigir(); $grupos=Database::fetchAll("SELECT g.id,g.nome,g.tipo,COUNT(i.id) quantidade FROM ip_grupos g LEFT JOIN ips i ON i.grupo_id=g.id AND i.ativo=1 AND (i.expira_em IS NULL OR i.expira_em>NOW()) WHERE g.ativo=1 GROUP BY g.id ORDER BY FIELD(g.tipo,'bloqueado','parcial','isento','liberado'),g.nome"); $sessoes=Database::fetchAll('SELECT s.id,s.ip,s.criado_em,s.expira_em,u.nome,u.email FROM sessoes_internet s JOIN usuarios_internet u ON u.id=s.usuario_id WHERE s.expira_em>NOW() ORDER BY s.expira_em'); $this->view('operacional/controle_acesso',compact('grupos','sessoes')); }
    public function alertas(): void { Auth::exigir(); $alertas=Database::fetchAll('SELECT * FROM alertas ORDER BY FIELD(estado,\'aberto\',\'reconhecido\',\'resolvido\'), FIELD(severidade,\'critico\',\'aviso\',\'info\'), atualizado_em DESC LIMIT 300'); $this->view('operacional/alertas',compact('alertas')); }
    public function reconhecer(string $id): void { Auth::exigir(); $a=Database::fetch('SELECT * FROM alertas WHERE id=? AND estado=\'aberto\'',[$id]); if(!$a){http_response_code(404);exit('Alerta não encontrado ou já reconhecido.');} Database::execute('UPDATE alertas SET estado=\'reconhecido\',reconhecido_por=?,reconhecido_em=NOW() WHERE id=?',[Auth::id(),$id]); Auditoria::registrar('reconhecer_alerta','alertas',(int)$id,$a,['estado'=>'reconhecido']); $this->redirect('/alertas'); }
    public function resolverAlerta(string $id): void { Auth::exigir(); $a=Database::fetch('SELECT * FROM alertas WHERE id=? AND estado<>\'resolvido\'',[$id]); if(!$a){http_response_code(404);exit('Alerta não encontrado ou já resolvido.');} Database::execute('UPDATE alertas SET estado=\'resolvido\',resolvido_em=NOW(),atualizado_em=NOW() WHERE id=?',[$id]); Auditoria::registrar('resolver_alerta','alertas',(int)$id,$a,['estado'=>'resolvido']); $this->redirect('/alertas'); }
    public function agenda(): void { Auth::exigir(); $horarios=Database::fetchAll('SELECT h.*,g.nome grupo_nome FROM horarios h LEFT JOIN ip_grupos g ON g.id=h.grupo_id WHERE h.ativo=1 ORDER BY h.id'); $grupos=Database::fetchAll('SELECT id,nome FROM ip_grupos WHERE ativo=1 ORDER BY nome'); $this->view('operacional/agenda',compact('horarios','grupos')); }
    public function simular(): void {
        Auth::exigir(); $ip=trim((string)($_GET['ip']??'')); $quando=(string)($_GET['quando']??date('Y-m-d\TH:i')); $destino=strtolower(rtrim(trim((string)($_GET['destino']??'')),'.')); $protocolo=strtolower((string)($_GET['protocolo']??'https')); $porta=(int)($_GET['porta']??($protocolo==='http'?80:443)); $resultado=null;
        if(filter_var($ip,FILTER_VALIDATE_IP) && ($ts=strtotime($quando))!==false) {
            if (!in_array($protocolo,['http','https'],true)) $protocolo='https';
            if ($porta<1 || $porta>65535) $porta=$protocolo==='http'?80:443;
            if ($destino!=='' && !filter_var($destino,FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i',$destino)) $destino='';
            $dia=(int)date('w',$ts); $hora=date('H:i:s',$ts); $grupo=null;
            $membros=Database::fetchAll('SELECT i.endereco,g.id,g.nome,g.tipo FROM ips i JOIN ip_grupos g ON g.id=i.grupo_id WHERE i.ativo=1 AND g.ativo=1 AND (i.expira_em IS NULL OR i.expira_em>FROM_UNIXTIME(?))',[$ts]);
            $correspondencias=array_values(array_filter($membros,fn($m)=>$this->ipPertence($ip,$m['endereco'])));
            usort($correspondencias,fn($a,$b)=>$this->prefixoRede($b['endereco'])<=>$this->prefixoRede($a['endereco']));
            $grupo=$correspondencias[0]??null;
            $regras=Database::fetchAll('SELECT h.*,g.nome grupo_nome FROM horarios h LEFT JOIN ip_grupos g ON g.id=h.grupo_id WHERE h.ativo=1 AND (h.grupo_id IS NULL OR h.grupo_id=?) ORDER BY h.id',[$grupo['id']??0]);
            $match=[]; foreach($regras as $r) if(($r['dias_semana'][$dia]??'0')==='1' && $hora >= $r['hora_inicio'] && $hora < $r['hora_fim']) $match[]=$r;
            $vencedora=$match[0]??null;
            $dominioRegra=null;
            if ($destino!=='' && !filter_var($destino,FILTER_VALIDATE_IP)) {
                $dominios=Database::fetchAll("SELECT dominio,tipo FROM dominios WHERE ativo=1 AND tipo IN ('whitelist','blacklist') ORDER BY (tipo='whitelist') DESC,LENGTH(dominio) DESC");
                foreach($dominios as $d) { $nome=strtolower(ltrim($d['dominio'],'.')); if($destino===$nome || str_ends_with($destino,'.'.$nome)) { $dominioRegra=$d; if($d['tipo']==='whitelist') break; } }
            }
            $portaSegura=in_array($porta,[21,70,80,210,280,443,488,591,777,8443],true)||$porta>=1025;
            if ($protocolo==='https' && !in_array($porta,[443,8443],true)) $portaSegura=false;
            if (!$portaSegura) {$decisao='bloquear';$motivo='A porta não está permitida pela ACL Safe_ports do Squid.';}
            elseif ($dominioRegra && $dominioRegra['tipo']==='whitelist') {$decisao='permitir';$motivo='O destino corresponde a uma whitelist aplicada antes das políticas de grupo.';}
            elseif(($grupo['tipo']??'')==='bloqueado') {$decisao='bloquear';$motivo='O grupo está bloqueado; somente destinos de whitelist passam antes desta regra.';}
            elseif(($grupo['tipo']??'')==='liberado') {$decisao='permitir';$motivo='O grupo bypassa o proxy; regras Squid de horário e blacklist não se aplicam.';}
            elseif(($grupo['tipo']??'')==='isento') {$decisao='permitir';$motivo='O grupo isento navega sem autenticação e passa pelo proxy.';}
            elseif($vencedora) {$decisao=$vencedora['acao'];$motivo='Regra de horário vencedora pela ordem aplicada: '.$vencedora['nome'].'.';}
            elseif(($grupo['tipo']??'')==='parcial' && $dominioRegra && $dominioRegra['tipo']==='blacklist') {$decisao='bloquear';$motivo='O domínio está na blacklist de conteúdo para o grupo parcial.';}
            elseif(($grupo['tipo']??'')==='parcial') {$decisao='permitir';$motivo='Sem regra horária ou blacklist correspondente, o grupo parcial pode navegar.';}
            else { $sessao=Database::fetch('SELECT id FROM sessoes_internet WHERE ip=? AND expira_em>NOW()',[$ip]); if($sessao){$decisao='permitir';$motivo='Existe sessão válida do portal para este IP.';} else {$decisao='login necessário';$motivo='Sem grupo permissivo ou sessão válida; o Squid encaminha ao portal.';} }
            $resultado=['grupo'=>$grupo,'regras'=>$match,'vencedora'=>$vencedora,'dominio_regra'=>$dominioRegra,'decisao'=>$decisao,'motivo'=>$motivo,'destino'=>$destino,'protocolo'=>$protocolo,'porta'=>$porta,'aviso'=>'Simulação baseada em políticas registradas e na ordem do squid.conf. Não consulta a configuração carregada do Squid; listas SNI, destinos resolvidos e estado de rede podem diferir.'];
        }
        $this->view('operacional/simulador',compact('ip','quando','destino','protocolo','porta','resultado'));
    }
    private function prefixoRede(string $rede): int { [$base,$bits]=array_pad(explode('/',$rede,2),2,null); return $bits!==null&&ctype_digit($bits)?(int)$bits:(filter_var($base,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)?128:32); }
    private function ipPertence(string $ip,string $rede): bool {
        [$base,$prefixo]=array_pad(explode('/',$rede,2),2,null); $a=@inet_pton($ip); $b=@inet_pton($base);
        if($a===false||$b===false||strlen($a)!==strlen($b)) return false;
        if($prefixo===null) return hash_equals($a,$b);
        $bits=(int)$prefixo; if($bits<0||$bits>strlen($a)*8)return false; $bytes=intdiv($bits,8); $resto=$bits%8;
        if($bytes && substr($a,0,$bytes)!==substr($b,0,$bytes))return false;
        if($resto){$mask=(0xff<<(8-$resto))&0xff;if((ord($a[$bytes])&$mask)!==(ord($b[$bytes])&$mask))return false;}
        return true;
    }
}
