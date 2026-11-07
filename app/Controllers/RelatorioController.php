<?php

namespace App\Controllers;

use App\Core\{Auth, Database, Controller};

class RelatorioController extends Controller
{
    public function auditoria(): void
    {
        Auth::exigir();
        [$inicio,$fim,$erro]=$this->periodo();
        $acao=trim((string)($_GET['acao']??'')); $ator=trim((string)($_GET['ator']??''));
        $where=['DATE(a.criado_em) BETWEEN ? AND ?']; $params=[$inicio,$fim];
        if($acao!==''){ $where[]='a.acao LIKE ?'; $params[]='%'.$acao.'%'; }
        if($ator!=='' && ctype_digit($ator)){ $where[]='a.admin_id=?'; $params[]=(int)$ator; }
        $auditoria=[];
        if($erro===null) {
            $limite=(($_GET['csv']??'')==='1')?50000:1000;
            $auditoria=Database::fetchAll('SELECT a.id,a.criado_em,a.acao,a.tabela,a.registro_id,a.ip,a.dados_antes,a.dados_depois,u.nome ator FROM auditoria a LEFT JOIN admins u ON u.id=a.admin_id WHERE '.implode(' AND ',$where).' ORDER BY a.criado_em DESC LIMIT '.$limite,$params);
            if(($_GET['csv']??'')==='1') {
                $operador=(Auth::admin()['perfil']??'')==='operador';
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="gwos-auditoria-'.$inicio.'-'.$fim.'.csv"');
                header('X-Content-Type-Options: nosniff'); $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
                fputcsv($out,['Data/hora','Operador','Ação','Tabela','Registro','Origem IP','Antes','Depois'],';');
                foreach($auditoria as $r) {
                    $ip=$operador?$this->mascararIp((string)$r['ip']):(string)$r['ip'];
                    $antes=$operador?'[restrito]':(string)($r['dados_antes']??''); $depois=$operador?'[restrito]':(string)($r['dados_depois']??'');
                    fputcsv($out,array_map([$this,'protegerCsv'],[$r['criado_em'],$r['ator']??'sistema',$r['acao'],$r['tabela']??'',(string)($r['registro_id']??''),$ip,$antes,$depois]),';');
                }
                fclose($out); exit;
            }
            if((Auth::admin()['perfil']??'')==='operador') foreach($auditoria as &$r){$r['ip']=$this->mascararIp((string)$r['ip']);$r['dados_antes']='[restrito]';$r['dados_depois']='[restrito]';} unset($r);
        }
        $this->view('relatorios/auditoria',compact('auditoria','inicio','fim','acao','ator','erro'));
    }

    public function index(): void
    {
        Auth::exigir();
        [$data_inicio, $data_fim, $erro] = $this->periodo();
        $pagina = max(1, min(100000, (int) ($_GET['pagina'] ?? 1)));
        $por_pag = 50;
        $offset = ($pagina - 1) * $por_pag;

        $total = $registros = 0; $trafego=[]; $sessoes=[]; $eventos=[]; $novos=[]; $resumo=['requisicoes'=>0,'download'=>0,'upload'=>null,'bloqueios'=>0];
        if ($erro === null) {
            $filtros=[$data_inicio.' 00:00:00',$data_fim.' 23:59:59'];
            $total=(int)Database::valor('SELECT COUNT(*) FROM (SELECT DATE(ocorrido_em),ip,dominio FROM acessos_internet WHERE ocorrido_em BETWEEN ? AND ? GROUP BY DATE(ocorrido_em),ip,dominio) r',$filtros);
            $registros=Database::fetchAll('SELECT DATE(ocorrido_em) data,ip ip_cliente,dominio,COUNT(*) acessos,COALESCE(SUM(bytes),0) bytes FROM acessos_internet WHERE ocorrido_em BETWEEN ? AND ? GROUP BY DATE(ocorrido_em),ip,dominio ORDER BY data DESC,acessos DESC LIMIT ? OFFSET ?',[$filtros[0],$filtros[1],$por_pag,$offset]);
            $resumo=Database::fetch("SELECT COUNT(*) requisicoes,COALESCE(SUM(a.bytes),0) download,SUM(a.request_bytes) upload,SUM(CASE WHEN a.status LIKE '%DENIED%' OR a.status LIKE '%BLOCKED%' THEN 1 ELSE 0 END) bloqueios FROM acessos_internet a WHERE a.ocorrido_em BETWEEN ? AND ?",$filtros) ?: $resumo;
            $trafego=Database::fetchAll("SELECT a.ip,COALESCE(e.hostname,a.ip) equipamento,e.mac,COALESCE(gp.grupo,'Sem grupo identificado') grupo,COALESCE(e.interface_rede,'Não coletada') interface_rede,COUNT(*) requisicoes,COALESCE(SUM(a.bytes),0) download,SUM(a.request_bytes) upload,SUM(CASE WHEN a.status LIKE '%DENIED%' OR a.status LIKE '%BLOCKED%' THEN 1 ELSE 0 END) bloqueios FROM acessos_internet a LEFT JOIN equipamentos e ON e.ip=a.ip LEFT JOIN (SELECT i.endereco,GROUP_CONCAT(DISTINCT g.nome ORDER BY g.nome SEPARATOR ' / ') grupo FROM ips i JOIN ip_grupos g ON g.id=i.grupo_id WHERE i.ativo=1 AND g.ativo=1 AND (i.expira_em IS NULL OR i.expira_em>NOW()) GROUP BY i.endereco) gp ON gp.endereco=a.ip WHERE a.ocorrido_em BETWEEN ? AND ? GROUP BY a.ip,e.hostname,e.mac,gp.grupo,e.interface_rede ORDER BY download DESC LIMIT 500",$filtros);
            $sessoes=Database::fetchAll('SELECT nome_usuario,email,ip,mac,hostname,grupo_snapshot,iniciou_em,encerrou_em,duracao_segundos,motivo FROM sessoes_internet_historico WHERE encerrou_em BETWEEN ? AND ? ORDER BY encerrou_em DESC LIMIT 1000',$filtros);
            $eventos=Database::fetchAll("SELECT atualizado_em AS criado_em,severidade,titulo,mensagem,origem,estado,ocorrencias FROM alertas WHERE atualizado_em BETWEEN ? AND ? AND chave NOT LIKE 'novo_dispositivo_%' ORDER BY criado_em DESC LIMIT 500",$filtros);
            $novos=Database::fetchAll("SELECT atualizado_em AS criado_em,titulo,mensagem,estado FROM alertas WHERE atualizado_em BETWEEN ? AND ? AND chave LIKE 'novo_dispositivo_%' ORDER BY criado_em DESC LIMIT 500",$filtros);
            if ((Auth::admin()['perfil'] ?? '') === 'operador') {
                foreach($trafego as &$t) { $t['ip']=$this->mascararIp((string)$t['ip']); $t['mac']='[restrito]'; $t['equipamento']='[restrito]'; } unset($t);
                foreach($sessoes as &$h) { $h['ip']=$this->mascararIp((string)$h['ip']); $h['email']='[restrito]'; $h['nome_usuario']='[restrito]'; $h['mac']='[restrito]'; } unset($h);
                foreach($eventos as &$ev) $ev['mensagem']='[restrito]'; unset($ev);
                foreach($novos as &$nd) $nd['mensagem']=$this->mascararTextoIps((string)$nd['mensagem']); unset($nd);
                foreach ($registros as &$registro) $registro['ip_cliente'] = $this->mascararIp((string) $registro['ip_cliente']);
                unset($registro);
            }
        }

        $total_paginas = (int) ceil($total / $por_pag);

        $this->view('relatorios/index', compact(
            'registros', 'pagina', 'total_paginas', 'total', 'data_inicio', 'data_fim', 'erro', 'trafego', 'sessoes', 'eventos', 'novos', 'resumo'
        ));
    }

    public function exportar(): void
    {
        Auth::exigir();
        [$inicio, $fim, $erro] = $this->periodo();
        if ($erro !== null) { http_response_code(422); exit($erro); }
        $tipo=(string)($_GET['tipo']??'trafego'); $operador=(Auth::admin()['perfil']??'')==='operador';
        $ini=$inicio.' 00:00:00'; $fim=$fim.' 23:59:59';
        if($tipo==='sessoes') {
            $linhas=Database::fetchAll('SELECT nome_usuario,email,ip,mac,grupo_snapshot,iniciou_em,encerrou_em,duracao_segundos,motivo FROM sessoes_internet_historico WHERE encerrou_em BETWEEN ? AND ? ORDER BY encerrou_em DESC LIMIT 50000',[$ini,$fim]);
            $cabecalho=['Usuário','E-mail','IP','MAC','Grupo no login','Início','Encerramento','Duração (segundos)','Motivo']; $nome='sessoes';
        } elseif($tipo==='alertas' || $tipo==='novos_dispositivos') {
            $novo=$tipo==='novos_dispositivos'; $like=$novo?" AND chave LIKE 'novo_dispositivo_%'":" AND chave NOT LIKE 'novo_dispositivo_%'";
            $linhas=Database::fetchAll('SELECT atualizado_em AS criado_em,titulo,mensagem,estado,severidade,origem,ocorrencias FROM alertas WHERE atualizado_em BETWEEN ? AND ?'.$like.' ORDER BY criado_em DESC LIMIT 50000',[$ini,$fim]);
            $cabecalho=$novo?['Data','Evento','Detalhe','Estado']:['Data','Severidade','Evento','Detalhe','Origem','Estado','Ocorrências']; $nome=$tipo;
        } else {
            $linhas=Database::fetchAll("SELECT a.ip,COALESCE(e.hostname,a.ip) equipamento,e.mac,COALESCE(gp.grupo,'Sem grupo identificado') grupo,COALESCE(e.interface_rede,'Não coletada') interface_rede,COUNT(*) requisicoes,COALESCE(SUM(a.bytes),0) download,SUM(a.request_bytes) upload,SUM(CASE WHEN a.status LIKE '%DENIED%' OR a.status LIKE '%BLOCKED%' THEN 1 ELSE 0 END) bloqueios FROM acessos_internet a LEFT JOIN equipamentos e ON e.ip=a.ip LEFT JOIN (SELECT i.endereco,GROUP_CONCAT(DISTINCT g.nome ORDER BY g.nome SEPARATOR ' / ') grupo FROM ips i JOIN ip_grupos g ON g.id=i.grupo_id WHERE i.ativo=1 AND g.ativo=1 AND (i.expira_em IS NULL OR i.expira_em>NOW()) GROUP BY i.endereco) gp ON gp.endereco=a.ip WHERE a.ocorrido_em BETWEEN ? AND ? GROUP BY a.ip,e.hostname,e.mac,gp.grupo,e.interface_rede ORDER BY download DESC LIMIT 50000",[$ini,$fim]);
            $cabecalho=['IP','Equipamento','MAC','Grupo','Interface','Requisições','Download (bytes)','Upload (bytes)','Bloqueios']; $nome='trafego';
        }
        header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="gwos-'.$nome.'-'.$inicio.'-'.$fim.'.csv"'); header('X-Content-Type-Options: nosniff');
        $saida=fopen('php://output','w'); fwrite($saida,"\xEF\xBB\xBF"); fputcsv($saida,$cabecalho,';');
        foreach($linhas as $linha) {
            if($operador && isset($linha['ip'])) $linha['ip']=$this->mascararIp((string)$linha['ip']);
            if($operador && isset($linha['email'])) $linha['email']='[restrito]';
            if($operador && isset($linha['nome_usuario'])) $linha['nome_usuario']='[restrito]';
            if($operador && isset($linha['mac'])) $linha['mac']='[restrito]';
            if($operador && isset($linha['equipamento'])) $linha['equipamento']='[restrito]';
            if($operador && isset($linha['mensagem'])) $linha['mensagem']=$this->mascararTextoIps((string)$linha['mensagem']);
            if($tipo==='sessoes') $valores=[$linha['nome_usuario'],$linha['email'],$linha['ip'],$linha['mac'],$linha['grupo_snapshot'],$linha['iniciou_em'],$linha['encerrou_em'],$linha['duracao_segundos'],$linha['motivo']];
            elseif($tipo==='alertas') $valores=[$linha['criado_em'],$linha['severidade'],$linha['titulo'],$linha['mensagem'],$linha['origem'],$linha['estado'],$linha['ocorrencias']];
            elseif($tipo==='novos_dispositivos') $valores=[$linha['criado_em'],$linha['titulo'],$linha['mensagem'],$linha['estado']];
            else $valores=[$linha['ip'],$linha['equipamento'],$linha['mac'],$linha['grupo'],$linha['interface_rede'],$linha['requisicoes'],$linha['download'],$linha['upload'],$linha['bloqueios']];
            fputcsv($saida,array_map([$this,'protegerCsv'],$valores),';');
        }
        fclose($saida); exit;
    }

    private function periodo(): array
    {
        $inicio = (string) ($_GET['data_inicio'] ?? date('Y-m-01'));
        $fim = (string) ($_GET['data_fim'] ?? date('Y-m-d'));
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $fim);
        if (!$a || !$b || $a->format('Y-m-d') !== $inicio || $b->format('Y-m-d') !== $fim) {
            return [$inicio, $fim, 'Informe datas válidas no formato AAAA-MM-DD.'];
        }
        if ($a > $b) return [$inicio, $fim, 'A data inicial deve ser anterior à data final.'];
        if ($a->diff($b)->days > 366) return [$inicio, $fim, 'O intervalo máximo do relatório é de 367 dias.'];
        return [$inicio, $fim, null];
    }

    private function mascararIp(string $ip): string
    {
        $binario = @inet_pton($ip);
        if ($binario === false) return 'mascarado';
        if (strlen($binario) === 4) {
            return inet_ntop(substr($binario, 0, 3) . "\0") ?: 'mascarado';
        }
        // IPv6: preserva o prefixo /56 e zera os 72 bits restantes.
        return inet_ntop(substr($binario, 0, 7) . str_repeat("\0", 9)) ?: 'mascarado';
    }

    private function mascararTextoIps(string $texto): string
    {
        $texto=preg_replace('/[a-z0-9._%+-]+@[a-z0-9.-]+\\.[a-z]{2,}/i','[e-mail restrito]',$texto) ?? '[restrito]';
        $texto=preg_replace('/(?<![a-f0-9])(?:[a-f0-9]{2}:){5}[a-f0-9]{2}(?![a-f0-9])/i','[MAC restrito]',$texto) ?? '[restrito]';
        return preg_replace_callback('/(?<![a-f0-9:])(?:\\d{1,3}\\.){3}\\d{1,3}(?![a-f0-9:])/i', fn($m)=>$this->mascararIp($m[0]), $texto) ?? '[restrito]';
    }

    private function protegerCsv(mixed $valor): string
    {
        $texto = (string) $valor;
        if (preg_match('/^[\s]*[=+\-@]/u', $texto)) $texto = "'" . $texto;
        return $texto;
    }
}
