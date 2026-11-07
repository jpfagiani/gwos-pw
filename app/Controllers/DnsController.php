<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Dns;
use App\Core\Estado;
use App\Core\Modulos;
use App\Core\Session;

/**
 * GWOS — Tela do módulo 20-dns-bind9.
 *
 * Só existe se o módulo estiver instalado. Todas as alterações saem daqui por
 * gwos-definir ou gwos-zona, que validam com named-checkconf antes de aplicar
 * e desfazem sozinhos se a configuração não passar.
 */
class DnsController extends Controller
{
    public function index(): void
    {
        Auth::exigir();
        $this->exigirModulo();

        $this->view('modulos/dns', [
            'titulo'         => 'DNS',
            'modulo'         => 'dns-bind9',
            'forwarders'     => Dns::forwarders(),
            'zonas'          => Dns::zonas(),
            'zonasProjeto'   => Dns::zonasDoProjeto(),
            'rpzAtiva'       => Dns::rpzAtiva(),
            'bloqueados'     => Dns::totalBloqueados(),
            'servicos'       => Modulos::statusServicos('dns-bind9'),
            'dominioLocal'   => Estado::obter('DOMINIO_LOCAL', ''),
            'temDnsInterno'  => Modulos::temModulo('dns-interno'),
            // Session::flash() sem valor lê e descarta
            'aviso'          => Session::flash('dns_aviso'),
            'erro'           => Session::flash('dns_erro'),
        ]);
    }

    public function salvarForwarders(): void
    {
        Auth::exigir();
        $this->exigirModulo();
        $this->exigirCsrf();

        $ips = preg_split('/[\s,]+/', trim($_POST['forwarders'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $r = Dns::definirForwarders($ips);
        $this->flash($r, 'Resolvers atualizados: ' . implode(', ', $ips));

        $this->redirect('/modulos/dns');
    }

    public function adicionarZona(): void
    {
        Auth::exigir();
        $this->exigirModulo();
        $this->exigirCsrf();

        $dominio = trim($_POST['dominio'] ?? '');
        $ips     = preg_split('/[\s,]+/', trim($_POST['forwarders'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $r = Dns::adicionarZona($dominio, $ips);
        $this->flash($r, "Zona {$dominio} criada.");

        $this->redirect('/modulos/dns');
    }

    public function removerZona(string $dominio): void
    {
        Auth::exigir();
        $this->exigirModulo();
        $this->exigirCsrf();

        $r = Dns::removerZona(urldecode($dominio));
        $this->flash($r, 'Zona removida.');

        $this->redirect('/modulos/dns');
    }

    /** Teste de resolução ao vivo, consumido por fetch() na tela. */
    public function testar(): void
    {
        Auth::exigir();
        $this->exigirModulo();

        $nome     = trim($_GET['nome'] ?? '');
        $servidor = trim($_GET['servidor'] ?? '127.0.0.1');

        if ($nome === '') {
            $this->json(['ok' => false, 'erro' => 'Informe um nome.'], 400);
        }

        $this->json(Dns::resolver($nome, $servidor));
    }

    /** Testa todos os forwarders de uma vez. */
    public function testarForwarders(): void
    {
        Auth::exigir();
        $this->exigirModulo();

        $this->json(['resultado' => Dns::testarForwarders()]);
    }

    // -----------------------------------------------------------------

    private function exigirModulo(): void
    {
        if (!Modulos::temModulo('dns-bind9')) {
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
            Session::flash('dns_aviso', $sucesso);
        } else {
            Session::flash('dns_erro', $resultado['saida'] ?: 'Falha ao aplicar a alteração.');
        }
    }
}
