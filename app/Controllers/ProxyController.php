<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Modulos;
use App\Core\Proxy;
use App\Core\Session;

/**
 * GWOS — Tela do módulo 50-proxy-squid.
 */
class ProxyController extends Controller
{
    public function index(): void
    {
        Auth::exigir();
        $this->exigirModulo();

        $this->view('modulos/proxy', [
            'titulo'      => 'Proxy',
            'modulo'      => 'proxy-squid',
            'portas'      => Proxy::portas(),
            'sslBump'     => Proxy::sslBumpAtivo(),
            'validadeCa'  => Proxy::validadeCa(),
            'listas'      => Proxy::listas(),
            'acessos'     => Proxy::ultimosAcessos(),
            'logLegivel'  => Proxy::logLegivel(),
            'servicos'    => Modulos::statusServicos('proxy-squid'),
            'temFirewall' => Modulos::temModulo('firewall-nftables'),
            'temBanco'    => Modulos::temBanco(),
            'aviso'       => Session::flash('proxy_aviso'),
            'erro'        => Session::flash('proxy_erro'),
        ]);
    }

    public function salvarPortas(): void
    {
        Auth::exigir();
        $this->exigirModulo();
        $this->exigirCsrf();

        $campos = [
            'SQUID_PORTA_FWD' => trim($_POST['porta_fwd'] ?? ''),
            'SQUID_PORTA'     => trim($_POST['porta_http'] ?? ''),
            'SQUID_PORTA_SSL' => trim($_POST['porta_ssl'] ?? ''),
        ];

        // Duas portas iguais fariam o Squid recusar a configuração
        $valores = array_values(array_filter($campos, fn($v) => $v !== ''));
        if (count($valores) !== count(array_unique($valores))) {
            Session::flash('proxy_erro', 'As três portas precisam ser diferentes entre si.');
            $this->redirect('/modulos/proxy');
        }

        foreach ($campos as $chave => $valor) {
            if ($valor === '') {
                continue;
            }
            $r = Proxy::definirPorta($chave, $valor);
            if (!$r['ok']) {
                Session::flash('proxy_erro', $r['saida']);
                $this->redirect('/modulos/proxy');
            }
        }

        // O gwos-definir já rodou o gwos-integrar, que regravou as portas do
        // Squid e as regras do firewall. Falta o Squid reler.
        $recarga = Proxy::recarregar();
        if ($recarga['ok']) {
            Session::flash('proxy_aviso', 'Portas atualizadas no Squid e no firewall.');
        } else {
            Session::flash('proxy_erro', $recarga['saida']);
        }

        $this->redirect('/modulos/proxy');
    }

    public function recarregar(): void
    {
        Auth::exigir();
        $this->exigirModulo();
        $this->exigirCsrf();

        $r = Proxy::recarregar();
        if ($r['ok']) {
            Session::flash('proxy_aviso', $r['saida'] ?: 'Squid recarregado.');
        } else {
            Session::flash('proxy_erro', $r['saida'] ?: 'Falha ao recarregar o Squid.');
        }

        $this->redirect('/modulos/proxy');
    }

    /** Saída do squid -k parse, para diagnóstico. */
    public function verificar(): void
    {
        Auth::exigir();
        $this->exigirModulo();

        $this->json(Proxy::verificarConfiguracao());
    }

    private function exigirModulo(): void
    {
        if (!Modulos::temModulo('proxy-squid')) {
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
