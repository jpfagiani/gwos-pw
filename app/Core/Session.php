<?php

namespace App\Core;

class Session
{
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $nome    = config('session.nome', 'GWOS_SESSION');
        $duracao = (int) config('session.duracao', 7200);

        session_name($nome);
        session_set_cookie_params([
            'lifetime' => $duracao,
            'path'     => '/',
            'secure'   => str_starts_with(config('app.url', ''), 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $agora = time();
        $inatividadeMax = max(300, (int) config('session.inatividade', 1800));
        $duracaoMax = max($inatividadeMax, (int) config('session.duracao_maxima', 28800));
        $criada = (int) ($_SESSION['_gwos_criada_em'] ?? $agora);
        $atividade = (int) ($_SESSION['_gwos_atividade_em'] ?? $agora);
        if (($agora - $atividade) > $inatividadeMax || ($agora - $criada) > $duracaoMax) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_gwos_criada_em'] = $agora;
        } else {
            $_SESSION['_gwos_criada_em'] = $criada;
        }
        $_SESSION['_gwos_atividade_em'] = $agora;
    }

    public static function definir(string $chave, mixed $valor): void
    {
        $_SESSION[$chave] = $valor;
    }

    public static function obter(string $chave, mixed $padrao = null): mixed
    {
        return $_SESSION[$chave] ?? $padrao;
    }

    public static function remover(string $chave): void
    {
        unset($_SESSION[$chave]);
    }

    public static function flash(string $chave, mixed $valor = null): mixed
    {
        if ($valor !== null) {
            $_SESSION['_flash'][$chave] = $valor;
            return null;
        }

        $val = $_SESSION['_flash'][$chave] ?? null;
        unset($_SESSION['_flash'][$chave]);
        return $val;
    }

    public static function destruir(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }
}
