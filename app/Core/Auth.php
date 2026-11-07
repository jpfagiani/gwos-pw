<?php

namespace App\Core;

class Auth
{
    private const CHAVE_SESSAO   = '_gwos_admin';
    private const MAX_TENTATIVAS = 5;
    private const BLOQUEIO_MIN   = 30;

    public static function iniciar(): void
    {
        Session::iniciar();
    }

    public static function login(string $email, string $senha): bool
    {
        $admin = Usuarios::buscarPorEmail($email);

        if (!$admin || empty($admin['ativo'])) {
            return false;
        }

        // Verifica bloqueio por tentativas excessivas
        if ($admin['bloqueado_ate'] && new \DateTime() < new \DateTime($admin['bloqueado_ate'])) {
            return false;
        }

        if (!password_verify($senha, $admin['senha'])) {
            $tentativas = (int)($admin['tentativas'] ?? 0) + 1;
            $bloqueado  = null;
            if ($tentativas >= self::MAX_TENTATIVAS) {
                $bloqueado  = (new \DateTime('+' . self::BLOQUEIO_MIN . ' minutes'))->format('Y-m-d H:i:s');
                $tentativas = 0;
            }
            Usuarios::atualizar((int) $admin['id'], [
                'tentativas'    => $tentativas,
                'bloqueado_ate' => $bloqueado,
            ]);
            return false;
        }

        // Sucesso — zera contadores e atualiza ultimo login
        Usuarios::registrarLogin((int) $admin['id']);

        Session::definir(self::CHAVE_SESSAO, [
            'id'             => $admin['id'],
            'nome'           => $admin['nome'],
            'email'          => $admin['email'],
            'perfil'         => $admin['perfil'],
            'primeiro_login' => (bool)($admin['primeiro_login'] ?? false),
        ]);

        session_regenerate_id(true);

        return true;
    }

    public static function logout(): never
    {
        Session::destruir();
        redirect('/login');
    }

    public static function verificar(): bool
    {
        $dados = Session::obter(self::CHAVE_SESSAO);
        if (!is_array($dados) || empty($dados['id'])) return false;

        $admin = Usuarios::buscarPorId((int) $dados['id']);
        if (!$admin || empty($admin['ativo'])) {
            Session::destruir();
            return false;
        }

        // Atualiza o perfil da sessão quando ele for alterado no banco.
        $dados['perfil'] = $admin['perfil'];
        $dados['nome'] = $admin['nome'];
        $dados['email'] = $admin['email'];
        Session::definir(self::CHAVE_SESSAO, $dados);
        return true;
    }

    public static function exigir(): void
    {
        if (!self::verificar()) {
            redirect('/login');
        }

        // Forca troca de senha no primeiro acesso
        $admin = self::admin();
        if (!empty($admin['primeiro_login'])) {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            if (!str_starts_with($uri, '/senha')) {
                redirect('/senha/trocar');
            }
        }
    }

    public static function admin(): array|null
    {
        return Session::obter(self::CHAVE_SESSAO);
    }

    public static function id(): int|null
    {
        $admin = self::admin();
        return $admin ? (int) $admin['id'] : null;
    }

    // Gerenciamento de senha

    public static function trocarSenha(int $id, string $senhaAtual, string $novaSenha): bool
    {
        $admin = Usuarios::buscarPorId($id);
        if (!$admin) return false;

        // No primeiro login não exige a senha atual (ela é a padrão e será descartada)
        // Nos acessos seguintes, exige verificação da senha atual
        if (!$admin['primeiro_login'] && !password_verify($senhaAtual, $admin['senha'])) {
            return false;
        }

        // Se a gravação falhar, dizer que a senha mudou seria mentira — e o
        // administrador ficaria com a senha padrão achando que a trocou.
        if (!Usuarios::atualizar($id, [
            'senha'          => password_hash($novaSenha, PASSWORD_BCRYPT, ['cost' => 12]),
            'primeiro_login' => 0,
        ])) {
            return false;
        }

        $dados = Session::obter(self::CHAVE_SESSAO);
        if ($dados) {
            $dados['primeiro_login'] = false;
            Session::definir(self::CHAVE_SESSAO, $dados);
        }

        return true;
    }

    public static function gerarTokenReset(string $email): string|null
    {
        $admin = Usuarios::buscarPorEmail($email);
        if (!$admin) return null;

        $token  = strtoupper(bin2hex(random_bytes(16)));
        $expira = (new \DateTime('+24 hours'))->format('Y-m-d H:i:s');

        Usuarios::atualizar((int) $admin['id'], [
            'reset_token'  => $token,
            'reset_expira' => $expira,
        ]);

        return $token;
    }

    public static function resetarSenhaPorToken(string $email, string $token, string $novaSenha): bool
    {
        $admin = Usuarios::buscarPorEmail($email);

        if (!$admin || empty($admin['reset_token'])) return false;
        if (!hash_equals($admin['reset_token'], strtoupper(trim($token)))) return false;
        if (new \DateTime() > new \DateTime($admin['reset_expira'])) return false;

        return Usuarios::atualizar((int) $admin['id'], [
            'senha'          => password_hash($novaSenha, PASSWORD_BCRYPT, ['cost' => 12]),
            'reset_token'    => null,
            'reset_expira'   => null,
            'primeiro_login' => 0,
        ]);
    }

    public static function mensagemBloqueio(string $email): string|null
    {
        $admin = Usuarios::buscarPorEmail($email);
        if (!$admin || empty($admin['bloqueado_ate'])) return null;
        if (new \DateTime() < new \DateTime($admin['bloqueado_ate'])) {
            $ate = (new \DateTime($admin['bloqueado_ate']))->format('H:i');
            return "Conta bloqueada por excesso de tentativas. Tente novamente apos as {$ate}.";
        }
        return null;
    }
}
