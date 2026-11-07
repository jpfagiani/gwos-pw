<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Estado;
use App\Core\Firewall;
use App\Core\Modulos;
use App\Core\Session;
use App\Core\Tarefas;
use App\Core\Auditoria;

/**
 * GWOS — Tela do módulo 40-firewall-nftables.
 *
 * Só leitura e regeração. As regras são derivadas do gwos.conf, dos módulos
 * instalados e — quando há banco — dos grupos de IPs e do NAT 1:1. Editar
 * regra crua por formulário criaria uma segunda fonte de verdade que a
 * primeira regeração apagaria.
 */
class FirewallController extends Controller
{
    public function index(): void
    {
        Auth::exigir();
        $this->exigirModulo();

        $ruleset = Firewall::ruleset();

        $this->view('modulos/firewall', [
            'titulo'          => 'Firewall',
            'modulo'          => 'firewall-nftables',
            'ruleset'         => $ruleset['ok'] ? $ruleset['saida'] : '',
            'rulesetErro'     => $ruleset['ok'] ? null : $ruleset['saida'],
            'decisoes'        => Firewall::decisoes(),
            'redesRoteadas'   => Firewall::redesRoteadas(),
            'encaminhamento'  => Firewall::encaminhamentoAtivo(),
            'persistente'     => Firewall::persistente(),
            'servicos'        => Modulos::statusServicos('firewall-nftables'),
            'ifaceWan'        => Estado::obter('IFACE_WAN', '?'),
            'ifaceLan'        => Estado::obter('IFACE_LAN', '?'),
            'temBanco'        => Modulos::temBanco(),
            'aviso'           => Session::flash('fw_aviso'),
            'erro'            => Session::flash('fw_erro'),
        ]);
    }

    public function regerar(): void
    {
        Auth::exigir();
        $this->exigirModulo();
        $this->exigirCsrf();

        $id = Tarefas::enfileirar('firewall_apply', [], Auth::id(), 'firewall_apply');
        Auditoria::registrar('enfileirar_firewall', 'nftables', null, null, ['tarefa_id' => $id]);
        Session::flash('fw_aviso', 'Regeração enfileirada. Acompanhe o estado em Operações (' . $id . ').');

        $this->redirect('/modulos/firewall');
    }

    public function preview(): void
    {
        Auth::exigir();
        $this->exigirModulo();
        $script=config('sistema.scripts_dir').'/aplicar_nftables.sh';
        if(!is_executable($script)) $this->json(['erro'=>'O gerador do firewall não está instalado.'],503);
        $proc=@proc_open(['/usr/bin/timeout','20s','/usr/bin/sudo','-n',$script,'--preview'],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes,BASE_PATH,null,['bypass_shell'=>true]);
        if(!is_resource($proc)) $this->json(['erro'=>'Não foi possível iniciar a prévia segura.'],503);
        $diff=stream_get_contents($pipes[1],1000000); fclose($pipes[1]); $codigo=proc_close($proc);
        if($codigo!==0) $this->json(['erro'=>'A validação da prévia falhou. Confira permissões, banco e logs do firewall.'],422);
        $this->json(['diff'=>$diff,'validado'=>str_contains((string)$diff,'PREVIEW_VALIDADO')]);
    }

    private function exigirModulo(): void
    {
        if (!Modulos::temModulo('firewall-nftables')) {
            $this->erro404();
            exit;
        }
    }

    private function exigirCsrf(): void
    {
        if (!csrf_verificar()) {
            http_response_code(419);
            exit('Sessão expirada. Recarregue a página e tente de novo.');
        }
    }
}
