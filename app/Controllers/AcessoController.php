<?php

namespace App\Controllers;

use App\Core\{AcessoInternet, Session, Controller};

/**
 * Portal do usuário (público): é para cá que o Squid redireciona quem tenta
 * navegar sem sessão. Não exige login de administrador.
 */
class AcessoController extends Controller
{
    // GET /acesso
    public function formulario(): void
    {
        $ip     = AcessoInternet::ipCliente();
        $sessao = AcessoInternet::sessaoDoIp($ip);
        $url    = $this->urlDestino($_GET['url'] ?? '');

        view('acesso/login', [
            'sessao'  => $sessao,
            'ip'      => $ip,
            'url'     => $url,
            'erro'    => Session::flash('erro_acesso'),
            'sucesso' => Session::flash('sucesso_acesso'),
            'dominio' => AcessoInternet::dominioPermitido(),
        ]);
    }

    // POST /acesso
    public function entrar(): void
    {
        $url = $this->urlDestino($_POST['url'] ?? '');
        if (!csrf_verificar()) {
            Session::flash('erro_acesso', 'Sessão expirada. Tente de novo.');
            $this->redirect('/acesso' . $this->qs($url));
        }

        [$ok, $msg, $usuario] = AcessoInternet::entrar(
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['senha'] ?? ''),
            AcessoInternet::ipCliente()
        );

        if (!$ok) {
            Session::flash('erro_acesso', $msg);
            $this->redirect('/acesso' . $this->qs($url));
        }

        session_regenerate_id(true);
        Session::flash('sucesso_acesso', 'Acesso liberado. Bom trabalho, ' . explode(' ', $usuario['nome'])[0] . '!');
        $this->redirect('/acesso' . $this->qs($url));
    }

    // POST /acesso/sair
    public function sair(): void
    {
        if (csrf_verificar()) {
            AcessoInternet::sair(AcessoInternet::ipCliente());
            Session::flash('sucesso_acesso', 'Você saiu. A internet deste computador foi bloqueada novamente.');
        }
        $this->redirect('/acesso');
    }

    // GET /acesso/cadastro
    public function formularioCadastro(): void
    {
        view('acesso/cadastro', [
            'erro'    => Session::flash('erro_acesso'),
            'dominio' => AcessoInternet::dominioPermitido(),
        ]);
    }

    // POST /acesso/cadastro
    public function cadastrar(): void
    {
        if (!csrf_verificar()) {
            Session::flash('erro_acesso', 'Sessão expirada. Tente de novo.');
            $this->redirect('/acesso/cadastro');
        }
        [$ok, $msg] = AcessoInternet::solicitarCadastro(
            (string) ($_POST['nome'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            AcessoInternet::ipCliente()
        );
        Session::flash($ok ? 'sucesso_acesso' : 'erro_acesso', $msg);
        $this->redirect($ok ? '/acesso' : '/acesso/cadastro');
    }

    // GET /acesso/esqueci
    public function formularioEsqueci(): void
    {
        view('acesso/esqueci', [
            'erro'    => Session::flash('erro_acesso'),
            'dominio' => AcessoInternet::dominioPermitido(),
        ]);
    }

    // POST /acesso/esqueci
    public function esqueci(): void
    {
        if (!csrf_verificar()) {
            Session::flash('erro_acesso', 'Sessão expirada. Tente de novo.');
            $this->redirect('/acesso/esqueci');
        }
        [$ok, $msg] = AcessoInternet::solicitarRedefinicao(
            (string) ($_POST['email'] ?? ''),
            AcessoInternet::ipCliente()
        );
        Session::flash($ok ? 'sucesso_acesso' : 'erro_acesso', $msg);
        $this->redirect($ok ? '/acesso' : '/acesso/esqueci');
    }

    // GET /acesso/senha?t=TOKEN — criar senha (cadastro novo) ou nova senha (esqueci)
    public function formularioSenha(): void
    {
        $token = (string) ($_GET['t'] ?? '');
        $t     = AcessoInternet::tokenValido($token);
        view('acesso/senha', [
            'token'   => $token,
            'valido'  => $t !== null,
            'usuario' => $t,
            'erro'    => Session::flash('erro_acesso'),
        ]);
    }

    // POST /acesso/senha
    public function definirSenha(): void
    {
        $token = (string) ($_POST['t'] ?? '');
        if (!csrf_verificar()) {
            Session::flash('erro_acesso', 'Sessão expirada. Tente de novo.');
            $this->redirect('/acesso/senha?t=' . urlencode($token));
        }
        [$ok, $msg] = AcessoInternet::definirSenha(
            $token,
            (string) ($_POST['senha'] ?? ''),
            (string) ($_POST['confirmar'] ?? '')
        );
        if ($ok) {
            Session::flash('sucesso_acesso', $msg);
            $this->redirect('/acesso');
        }
        Session::flash('erro_acesso', $msg);
        $this->redirect('/acesso/senha?t=' . urlencode($token));
    }

    /** Só http(s) — o destino original que o Squid mandou junto no redirecionamento. */
    private function urlDestino(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 1500 || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        return $url;
    }

    private function qs(string $url): string
    {
        return $url === '' ? '' : '?url=' . rawurlencode($url);
    }
}
