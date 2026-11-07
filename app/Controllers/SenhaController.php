<?php

namespace App\Controllers;

use App\Core\{Auth, Session, Controller};

class SenhaController extends Controller
{
    // GET /senha/trocar — formulario de troca de senha (logado)
    public function formularioTrocar(): void
    {
        Auth::exigir();
        $erro    = Session::flash('erro_senha');
        $sucesso = Session::flash('sucesso_senha');
        $primeiroLogin = Auth::admin()['primeiro_login'] ?? false;
        require BASE_PATH . '/app/Views/senha/trocar.php';
    }

    // POST /senha/trocar
    public function trocar(): void
    {
        Auth::exigir();

        $senhaAtual = $_POST['senha_atual'] ?? '';
        $novaSenha  = $_POST['nova_senha']  ?? '';
        $confirmar  = $_POST['confirmar']   ?? '';

        if (strlen($novaSenha) < 8) {
            Session::flash('erro_senha', 'A nova senha deve ter pelo menos 8 caracteres.');
            $this->redirect('/senha/trocar');
        }

        if ($novaSenha !== $confirmar) {
            Session::flash('erro_senha', 'As senhas não coincidem.');
            $this->redirect('/senha/trocar');
        }

        if (Auth::trocarSenha(Auth::id(), $senhaAtual, $novaSenha)) {
            Session::flash('sucesso', 'Senha alterada com sucesso!');
            $this->redirect('/');
        }

        Session::flash('erro_senha', 'Senha atual incorreta.');
        $this->redirect('/senha/trocar');
    }

    // GET /senha/reset — formulario publico de reset por token
    public function formularioReset(): void
    {
        if (Auth::verificar()) {
            $this->redirect('/senha/trocar');
        }
        $erro    = Session::flash('erro_reset');
        $sucesso = Session::flash('sucesso_reset');
        require BASE_PATH . '/app/Views/senha/reset.php';
    }

    // POST /senha/reset
    public function reset(): void
    {
        $email     = trim($_POST['email']      ?? '');
        $token     = trim($_POST['token']      ?? '');
        $novaSenha = $_POST['nova_senha']       ?? '';
        $confirmar = $_POST['confirmar']        ?? '';

        if (strlen($novaSenha) < 8) {
            Session::flash('erro_reset', 'A nova senha deve ter pelo menos 8 caracteres.');
            $this->redirect('/senha/reset');
        }

        if ($novaSenha !== $confirmar) {
            Session::flash('erro_reset', 'As senhas não coincidem.');
            $this->redirect('/senha/reset');
        }

        if (Auth::resetarSenhaPorToken($email, $token, $novaSenha)) {
            Session::flash('sucesso_login', 'Senha redefinida com sucesso! Faça login com a nova senha.');
            $this->redirect('/login');
        }

        Session::flash('erro_reset', 'Token inválido, expirado ou e-mail não encontrado.');
        $this->redirect('/senha/reset');
    }

    // GET /senha/gerar — superadmin gera token para outro usuario
    public function formularioGerar(): void
    {
        Auth::exigir();
        if (Auth::admin()['perfil'] !== 'superadmin') {
            $this->redirect('/');
        }
        $erro    = Session::flash('erro_gerar');
        $token   = Session::flash('token_gerado');
        $tokenEmail = Session::flash('token_email');
        require BASE_PATH . '/app/Views/senha/gerar.php';
    }

    // POST /senha/gerar
    public function gerar(): void
    {
        Auth::exigir();
        if (Auth::admin()['perfil'] !== 'superadmin') {
            $this->redirect('/');
        }

        $email = trim($_POST['email'] ?? '');

        $token = Auth::gerarTokenReset($email);
        if ($token) {
            Session::flash('token_gerado', $token);
            Session::flash('token_email', $email);
        } else {
            Session::flash('erro_gerar', 'E-mail não encontrado ou usuário inativo.');
        }

        $this->redirect('/senha/gerar');
    }
}
