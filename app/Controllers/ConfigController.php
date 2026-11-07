<?php

namespace App\Controllers;

use App\Core\{Auth, Database, Controller, Auditoria, Session};

class ConfigController extends Controller
{
    public function index(): void
    {
        Auth::exigir();

        $configuracoes = Database::fetchAll(
            'SELECT chave, valor, descricao FROM configuracoes ORDER BY chave'
        );
        $historico = Database::fetchAll(
            'SELECT h.id,h.operacao_id,h.admin_id,h.chave,h.valor_anterior,h.valor_novo,h.criado_em,a.nome operador
             FROM configuracoes_historico h LEFT JOIN admins a ON a.id=h.admin_id
             ORDER BY h.id DESC LIMIT 100'
        );
        foreach($configuracoes as &$cfg) {
            $cfg['sensivel']=(bool)preg_match('/senha|secret|token/i',$cfg['chave']);
            if($cfg['sensivel']) {$cfg['tem_valor']=$cfg['valor']!=='';$cfg['valor']='';}
        }
        unset($cfg);

        $this->view('configuracoes/index', compact('configuracoes','historico'));
    }

    public function salvar(): void
    {
        Auth::exigir();
        if (!csrf_verificar()) { json_resposta(['erro' => 'Token inválido.'], 403); }

        $dados = $_POST['config'] ?? [];

        if (!is_array($dados)) {
            json_resposta(['erro' => 'Dados inválidos.'], 422);
        }

        $atuais=[];
        foreach(Database::fetchAll('SELECT chave,valor FROM configuracoes') as $row) $atuais[$row['chave']]=$row['valor'];
        $alteracoes=[];
        foreach($dados as $chave=>$valor) {
            if(!is_string($chave)||!array_key_exists($chave,$atuais)||!is_scalar($valor)) json_resposta(['erro'=>'A lista contém uma configuração inválida. Recarregue a tela.'],422);
            $novo=trim((string)$valor); if(strlen($novo)>2000) json_resposta(['erro'=>"O valor de {$chave} é muito longo."],422);
            if(preg_match('/senha|secret|token/i',$chave) && $novo==='') continue;
            if($novo===(string)$atuais[$chave]) continue;
            $erro=$this->validar($chave,$novo); if($erro!==null) json_resposta(['erro'=>$erro],422);
            $alteracoes[$chave]=$novo;
        }
        $wan=$alteracoes['iface_wan']??($atuais['iface_wan']??''); $lan=$alteracoes['iface_lan']??($atuais['iface_lan']??'');
        if($wan!=='' && $wan===$lan) json_resposta(['erro'=>'As interfaces WAN e LAN precisam ser diferentes.'],422);
        $rede=$alteracoes['rede_lan']??($atuais['rede_lan']??''); $gateway=$alteracoes['ip_gateway']??($atuais['ip_gateway']??'');
        if(($rede!==($atuais['rede_lan']??'')||$gateway!==($atuais['ip_gateway']??'')) && $rede!=='' && $gateway!=='' && !$this->ipDentroCidr($gateway,$rede)) json_resposta(['erro'=>'O IP do gateway precisa pertencer à rede LAN configurada.'],422);

        if($alteracoes) {
            $db=Database::conectar(); $db->beginTransaction(); $operacao=bin2hex(random_bytes(16));
            try {
                foreach($alteracoes as $chave=>$novo) {
                    Database::execute('UPDATE configuracoes SET valor=? WHERE chave=?',[$novo,$chave]);
                    $sensivel=(bool)preg_match('/senha|secret|token/i',$chave);
                    Database::execute('INSERT INTO configuracoes_historico (operacao_id,admin_id,chave,valor_anterior,valor_novo) VALUES (?,?,?,?,?)',[$operacao,Auth::id(),$chave,$sensivel?'[valor sensível]':(string)$atuais[$chave],$sensivel?'[valor sensível]':$novo]);
                }
                $db->commit();
            } catch(\Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
            $antes=[];$depois=[]; foreach($alteracoes as $chave=>$novo){$antes[$chave]=$atuais[$chave];$depois[$chave]=$novo;}
            Auditoria::registrar('salvar_configuracoes','configuracoes',null,$antes,$depois+['_operacao_id'=>$operacao]);
        }

        Session::flash('sucesso', 'Configurações salvas.');
        $this->redirect('/configuracoes');
    }

    public function reverter(string $id): void
    {
        Auth::exigir();
        $linha=Database::fetch('SELECT * FROM configuracoes_historico WHERE id=?',[$id]);
        if(!$linha || str_contains($linha['valor_anterior'],'[valor sensível]')) { Session::flash('erro','Esta revisão não pode ser revertida automaticamente.'); $this->redirect('/configuracoes'); }
        $atual=Database::fetch('SELECT valor FROM configuracoes WHERE chave=?',[$linha['chave']]);
        if(!$atual) { Session::flash('erro','A configuração não existe mais.'); $this->redirect('/configuracoes'); }
        if(in_array($linha['chave'],['iface_wan','iface_lan'],true)) {
            $outra=$linha['chave']==='iface_wan'?'iface_lan':'iface_wan';
            if((string)Database::valor('SELECT valor FROM configuracoes WHERE chave=?',[$outra])===$linha['valor_anterior']) { Session::flash('erro','Reversão recusada: WAN e LAN não podem usar a mesma interface.'); $this->redirect('/configuracoes'); }
        }
        $erro=$this->validar($linha['chave'],$linha['valor_anterior']);
        if($erro!==null) { Session::flash('erro','Reversão recusada: '.$erro); $this->redirect('/configuracoes'); }
        if(in_array($linha['chave'],['rede_lan','ip_gateway'],true)) {
            $outra=$linha['chave']==='rede_lan'?'ip_gateway':'rede_lan';
            $valorOutro=(string)(Database::valor('SELECT valor FROM configuracoes WHERE chave=?',[$outra])??'');
            $novaRede=$linha['chave']==='rede_lan'?$linha['valor_anterior']:$valorOutro;
            $novoGateway=$linha['chave']==='ip_gateway'?$linha['valor_anterior']:$valorOutro;
            if($novaRede!==''&&$novoGateway!==''&&!$this->ipDentroCidr($novoGateway,$novaRede)){Session::flash('erro','Reversão recusada: o gateway ficaria fora da rede LAN atual.');$this->redirect('/configuracoes');}
        }
        $db=Database::conectar(); $db->beginTransaction(); $op=bin2hex(random_bytes(16));
        try {
            Database::execute('UPDATE configuracoes SET valor=? WHERE chave=?',[$linha['valor_anterior'],$linha['chave']]);
            Database::execute('INSERT INTO configuracoes_historico (operacao_id,admin_id,chave,valor_anterior,valor_novo) VALUES (?,?,?,?,?)',[$op,Auth::id(),$linha['chave'],$atual['valor'],$linha['valor_anterior']]);
            $db->commit();
        } catch(\Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
        Auditoria::registrar('reverter_configuracao','configuracoes',(int)$linha['id'],[$linha['chave']=>$atual['valor']],[$linha['chave']=>$linha['valor_anterior'],'_operacao_id'=>$op]);
        Session::flash('sucesso','Valor revertido no banco e nova revisão registrada. Reaplique os módulos afetados se a configuração depender de arquivo ou serviço.');
        $this->redirect('/configuracoes');
    }

    private function validar(string $chave,string $valor): ?string
    {
        if(in_array($chave,['iface_wan','iface_lan'],true) && ($valor==='' || !preg_match('/^[a-zA-Z0-9_.:-]{1,32}$/',$valor) || !is_dir('/sys/class/net/'.$valor))) return "A interface {$chave} não existe neste servidor.";
        if($chave==='ip_gateway' && $valor!=='' && filter_var($valor,FILTER_VALIDATE_IP)===false) return 'O IP do gateway é inválido.';
        if($chave==='rede_lan' && $valor!=='' && !$this->cidrValido($valor)) return 'A rede LAN deve estar no formato IPv4/prefixo válido.';
        if(str_ends_with($chave,'_porta') && $valor!=='' && (!ctype_digit($valor)||(int)$valor<1||(int)$valor>65535)) return "A porta {$chave} precisa estar entre 1 e 65535.";
        if(in_array($chave,['alerta_disco_pct','alerta_memoria_pct'],true) && (!ctype_digit($valor)||(int)$valor<1||(int)$valor>100)) return "O limite {$chave} deve ficar entre 1 e 100.";
        if(in_array($chave,['alerta_bloqueios_5min','alerta_consumo_5min_bytes','alerta_backup_horas'],true) && (!ctype_digit($valor)||(int)$valor<1)) return "O limite {$chave} deve ser um inteiro positivo.";
        if($chave==='smtp_seguranca' && !in_array($valor,['nenhuma','starttls','ssl'],true)) return 'Segurança SMTP aceita somente nenhuma, starttls ou ssl.';
        if($chave==='smtp_remetente' && $valor!=='' && filter_var($valor,FILTER_VALIDATE_EMAIL)===false) return 'O remetente SMTP precisa ser um endereço de e-mail válido.';
        if($chave==='acesso_dominio_email' && $valor!=='' && !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',$valor)) return 'O domínio institucional de e-mail é inválido.';
        if($chave==='acesso_portal_url' && $valor!=='') { $u=parse_url($valor); if(!is_array($u)||!in_array(strtolower($u['scheme']??''),['http','https'],true)||empty($u['host'])||isset($u['user'])||isset($u['pass'])) return 'A URL do portal deve usar HTTP/HTTPS e não pode conter credenciais.'; }
        return null;
    }

    private function cidrValido(string $valor): bool
    {
        if(!preg_match('/^([^\/]+)\/(\d{1,2})$/',$valor,$m)||filter_var($m[1],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false)return false;
        return (int)$m[2]>=1 && (int)$m[2]<=30;
    }

    private function ipDentroCidr(string $ip,string $cidr): bool
    {
        if(!$this->cidrValido($cidr)||filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false)return false;
        [$base,$prefix]=explode('/',$cidr,2);$mask=(0xFFFFFFFF << (32-(int)$prefix))&0xFFFFFFFF;
        $baseInt=ip2long($base); return (($baseInt&$mask)===$baseInt) && ((ip2long($ip)&$mask)===$baseInt);
    }
}
