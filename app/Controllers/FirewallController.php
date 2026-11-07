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
