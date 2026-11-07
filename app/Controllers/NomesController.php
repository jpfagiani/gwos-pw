<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Estado;
use App\Core\Modulos;
use App\Core\Nomes;
use App\Core\Session;

/**
 * GWOS — Tela do módulo 25-dns-interno-dnsmasq: vincular um nome a um IP.
 */
class NomesController extends Controller
{
    public function index(): void
    {
        Auth::exigir();
        $this->exigirModulo();

        $this->view('modulos/nomes', [
            'titulo'        => 'Nomes internos',
            'modulo'        => 'dns-interno',
            'nomes'         => Nomes::listar(),
            'dominioLocal'  => Estado::obter('DOMINIO_LOCAL', 'local'),
            'porta'         => Estado::obter('DNSMASQ_PORTA', '5353'),
            'servicos'      => Modulos::statusServicos('dns-interno'),
            'temBind'       => Modulos::temModulo('dns-bind9'),
            'aviso'         => Session::flash('nomes_aviso'),
            'erro'          => Session::flash('nomes_erro'),
        ]);
    }

    public function adicionar(): void
    {
        Auth::exigir();
        $this->exigirModulo();
        $this->exigirCsrf();

        $host = trim($_POST['host'] ?? '');
        $ip   = trim($_POST['ip'] ?? '');

        if (Nomes::existe($host)) {
            Session::flash('nomes_erro', "O nome '{$host}' já existe. Use Alterar para trocar o IP.");
            $this->redirect('/modulos/nomes');
        }

        $r = Nomes::adicionar($host, $ip);
        $this->flash($r, "{$host} → {$ip} cadastrado. Propaga em até 60 s.");
        $this->redirect('/modulos/nomes');
    }

    public function atualizar(string $host): void
    {
        Auth::exigir();
        $this->exigirModulo();
        $this->exigirCsrf();

        $host = urldecode($host);
        $ip   = trim($_POST['ip'] ?? '');

        $r = Nomes::atualizar($host, $ip);
        $this->flash($r, "{$host} agora aponta para {$ip}.");
        $this->redirect('/modulos/nomes');
    }

    public function remover(string $host): void
    {
        Auth::exigir();
        $this->exigirModulo();
        $this->exigirCsrf();

        $host = urldecode($host);
        $r    = Nomes::remover($host);
        $this->flash($r, "{$host} removido.");
        $this->redirect('/modulos/nomes');
    }

    private function exigirModulo(): void
    {
        if (!Modulos::temModulo('dns-interno')) {
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

    /** @param array{ok:bool,saida:string} $resultado */
    private function flash(array $resultado, string $sucesso): void
    {
        if ($resultado['ok']) {
            Session::flash('nomes_aviso', $sucesso);
        } else {
            Session::flash('nomes_erro', $resultado['saida'] ?: 'Falha ao aplicar a alteração.');
        }
    }
}
