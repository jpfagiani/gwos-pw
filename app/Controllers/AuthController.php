<?php

namespace App\Controllers;

use App\Core\{Auth, Session, Controller};

class AuthController extends Controller
{
    public function formulario(): void
    {
        if (Auth::verificar()) {
            $this->redirect('/');
        }

        $erro = Session::flash('erro_login');
        require BASE_PATH . '/app/Views/login.php';
    }

    public function autenticar(): void
    {
        if (!csrf_verificar()) {
            Session::flash('erro_login', 'Token de segurança inválido.');
            $this->redirect('/login');
        }

        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if ($email === '' || $senha === '') {
            Session::flash('erro_login', 'Preencha e-mail e senha.');
            $this->redirect('/login');
        }

        if (Auth::login($email, $senha)) {
            $this->redirect('/');
        }

        $msg = Auth::mensagemBloqueio($email) ?? 'E-mail ou senha incorretos.';
        Session::flash('erro_login', $msg);
        $this->redirect('/login');
    }

    public function sair(): never
    {
        Auth::logout();
    }
}
