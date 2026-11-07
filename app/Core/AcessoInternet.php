<?php

namespace App\Core;

/**
 * Acesso à internet com login (portal cativo).
 *
 * Fluxo:
 *   1. Cadastro  — nome + e-mail @<domínio permitido>. Nada de senha ainda.
 *   2. O link enviado ao e-mail leva à tela "definir senha". É ali que a
 *      senha nasce: assim ela só pode ser criada por quem de fato lê aquela
 *      caixa de entrada (digitar o e-mail de outra pessoa não adianta).
 *   3. Login   — libera o IP da máquina até a sessão expirar.
 *   4. Esqueci a senha — mesmo caminho do passo 2, com link novo.
 *
 * O Squid não consulta o banco: este código espelha as sessões ativas em
 * SESSOES_ARQ (ip, expiração, e-mail), lido pelo helper gwos-squid-sessao.
 */
class AcessoInternet
{
    public const SESSOES_ARQ = '/var/lib/gwos/sessions/sessoes.txt';

    private const MAX_TENTATIVAS   = 5;
    private const BLOQUEIO_MIN     = 15;
    private const TOKEN_CONFIRMAR  = 1440;  // minutos (24 h)
    private const TOKEN_REDEFINIR  = 30;    // minutos
    private const MAX_TOKENS_USER  = 3;     // por hora, por usuário
    private const MAX_TOKENS_IP    = 10;    // por hora, por IP de origem

    // ── configuração ─────────────────────────────────────────────────────────
    public static function cfg(string $chave, string $padrao = ''): string
    {
        try {
            $v = Database::valor('SELECT valor FROM configuracoes WHERE chave = ?', [$chave]);
            return ($v === false || $v === null || $v === '') ? $padrao : (string) $v;
        } catch (\Throwable) {
            return $padrao;
        }
    }

    public static function dominioPermitido(): string
    {
        return strtolower(ltrim(self::cfg('acesso_dominio_email', 'sap.sp.gov.br'), '@'));
    }

    /** Só aceita exatamente o domínio configurado — subdomínios e "parecidos" não passam. */
    public static function emailPermitido(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $partes = explode('@', strtolower($email));
        return count($partes) === 2 && $partes[1] === self::dominioPermitido();
    }

    private static function tamanho(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    }

    public static function normalizarEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function ipCliente(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Base dos links enviados por e-mail. NUNCA usa o cabeçalho Host (o
     * cliente controla esse valor e poderia fazer o e-mail apontar para um
     * site dele): vem da configuração ou do endereço local da conexão.
     */
    public static function urlBase(): string
    {
        $fixa = rtrim(self::cfg('acesso_portal_url'), '/');
        if ($fixa !== '') {
            return $fixa;
        }
        $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $local  = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
        $porta  = $_SERVER['SERVER_PORT'] ?? ($https ? '443' : '80');
        $padrao = $https ? '443' : '80';
        return ($https ? 'https' : 'http') . '://' . $local . ($porta === $padrao ? '' : ':' . $porta);
    }

    // ── cadastro / confirmação / redefinição ────────────────────────────────
    /**
     * Passo 1 do cadastro. A resposta é a MESMA existindo ou não a conta,
     * para ninguém descobrir quem já está cadastrado.
     *
     * @return array{0:bool,1:string}  [aceito, mensagem para o usuário]
     */
    public static function solicitarCadastro(string $nome, string $email, string $ip): array
    {
        $nome  = trim(preg_replace('/\s+/', ' ', $nome));
        $email = self::normalizarEmail($email);

        if (self::tamanho($nome) < 3 || self::tamanho($nome) > 100) {
            return [false, 'Informe seu nome completo.'];
        }
        if (!self::emailPermitido($email)) {
            return [false, 'Só é aceito e-mail institucional @' . self::dominioPermitido() . '.'];
        }
        if (self::excedeuLimiteIp($ip)) {
            return [false, 'Muitas solicitações deste computador. Tente novamente em uma hora.'];
        }

        $generico = 'Se o e-mail for válido, enviamos um link para concluir o cadastro. '
                  . 'Abra o webmail institucional e clique no link (vale por 24 horas).';

        $usuario = Database::fetch('SELECT * FROM usuarios_internet WHERE email = ?', [$email]);

        if (!$usuario) {
            // Senha aleatória inutilizável até o dono do e-mail definir a dele
            $id = (int) Database::insert(
                'INSERT INTO usuarios_internet (nome, email, senha) VALUES (?, ?, ?)',
                [$nome, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT, ['cost' => 10])]
            );
            $usuario = Database::fetch('SELECT * FROM usuarios_internet WHERE id = ?', [$id]);
        }

        if (!(int) $usuario['ativo']) {
            return [true, $generico];   // conta desativada: não revela nem envia
        }

        if ($usuario['confirmado_em']) {
            // Já cadastrado: em vez de repetir o cadastro, lembra do "esqueci a senha"
            self::enviarLink($usuario, 'redefinir', $ip, true);
        } else {
            self::enviarLink($usuario, 'confirmar', $ip, false);
        }
        return [true, $generico];
    }

    /** "Esqueci minha senha". Resposta sempre genérica. */
    public static function solicitarRedefinicao(string $email, string $ip): array
    {
        $email   = self::normalizarEmail($email);
        $generico = 'Se o e-mail estiver cadastrado, enviamos um link para criar uma nova senha (vale por 30 minutos).';

        if (!self::emailPermitido($email)) {
            return [true, $generico];
        }
        if (self::excedeuLimiteIp($ip)) {
            return [false, 'Muitas solicitações deste computador. Tente novamente em uma hora.'];
        }

        $usuario = Database::fetch('SELECT * FROM usuarios_internet WHERE email = ? AND ativo = 1', [$email]);
        if ($usuario) {
            $tipo = $usuario['confirmado_em'] ? 'redefinir' : 'confirmar';
            self::enviarLink($usuario, $tipo, $ip, false);
        }
        return [true, $generico];
    }

    private static function excedeuLimiteIp(string $ip): bool
    {
        $desde = date('Y-m-d H:i:s', time() - 3600);
        return (int) Database::valor(
            'SELECT COUNT(*) FROM tokens_internet WHERE ip = ? AND criado_em > ?', [$ip, $desde]
        ) >= self::MAX_TOKENS_IP;
    }

    /** Gera o token, grava só o hash e envia o link. Devolve false se limitado ou se o envio falhar. */
    private static function enviarLink(array $usuario, string $tipo, string $ip, bool $jaCadastrado): bool
    {
        $desde = date('Y-m-d H:i:s', time() - 3600);
        $recentes = (int) Database::valor(
            'SELECT COUNT(*) FROM tokens_internet WHERE usuario_id = ? AND criado_em > ?',
            [$usuario['id'], $desde]
        );
        if ($recentes >= self::MAX_TOKENS_USER) {
            return false;
        }

        $minutos = $tipo === 'confirmar' ? self::TOKEN_CONFIRMAR : self::TOKEN_REDEFINIR;
        $token   = bin2hex(random_bytes(32));

        // Só o link mais recente vale
        Database::execute(
            'UPDATE tokens_internet SET usado_em = ? WHERE usuario_id = ? AND usado_em IS NULL',
            [date('Y-m-d H:i:s'), $usuario['id']]
        );
        Database::insert(
            'INSERT INTO tokens_internet (usuario_id, tipo, token_hash, expira_em, ip) VALUES (?, ?, ?, ?, ?)',
            [$usuario['id'], $tipo, hash('sha256', $token), date('Y-m-d H:i:s', time() + $minutos * 60), $ip]
        );

        $link = self::urlBase() . '/acesso/senha?t=' . $token;
        $nome = explode(' ', $usuario['nome'])[0];

        if ($tipo === 'confirmar') {
            $assunto = 'Confirme seu cadastro de acesso à internet';
            $texto   = "Olá, {$nome}.\n\n"
                     . "Recebemos um pedido de cadastro para acesso à internet com este e-mail.\n"
                     . "Para confirmar e criar sua senha, abra o link abaixo (válido por 24 horas):\n\n"
                     . "{$link}\n\n"
                     . "Se não foi você, ignore esta mensagem: nada será ativado.\n";
        } else {
            $assunto = 'Criar nova senha de acesso à internet';
            $texto   = "Olá, {$nome}.\n\n"
                     . ($jaCadastrado ? "Este e-mail já tem cadastro. " : '')
                     . "Para criar uma nova senha, abra o link abaixo (válido por 30 minutos):\n\n"
                     . "{$link}\n\n"
                     . "Se não foi você, ignore esta mensagem: sua senha atual continua valendo.\n";
        }

        if (!Mailer::enviar($usuario['email'], $assunto, $texto)) {
            error_log('[GWOS acesso] falha ao enviar e-mail: ' . Mailer::$ultimoErro);
            return false;
        }
        return true;
    }

    /** Valida um token ainda não usado e não expirado. */
    public static function tokenValido(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $linha = Database::fetch(
            'SELECT t.id AS token_id, t.tipo, u.*
               FROM tokens_internet t JOIN usuarios_internet u ON u.id = t.usuario_id
              WHERE t.token_hash = ? AND t.usado_em IS NULL AND t.expira_em > ? AND u.ativo = 1',
            [hash('sha256', $token), date('Y-m-d H:i:s')]
        );
        return $linha ?: null;
    }

    /** Define a senha a partir do link (cadastro novo ou redefinição). */
    public static function definirSenha(string $token, string $senha, string $confirmar): array
    {
        $erro = self::validarSenha($senha);
        if ($erro) {
            return [false, $erro];
        }
        if ($senha !== $confirmar) {
            return [false, 'As senhas não coincidem.'];
        }

        $t = self::tokenValido($token);
        if (!$t) {
            return [false, 'Link inválido ou expirado. Peça um novo.'];
        }

        $pdo = Database::conectar();
        $pdo->beginTransaction();
        try {
            // "usado_em IS NULL" no UPDATE garante uso único mesmo com duas abas
            $usou = Database::execute(
                'UPDATE tokens_internet SET usado_em = ? WHERE id = ? AND usado_em IS NULL',
                [date('Y-m-d H:i:s'), $t['token_id']]
            );
            if ($usou !== 1) {
                $pdo->rollBack();
                return [false, 'Link inválido ou expirado. Peça um novo.'];
            }
            Database::execute(
                'UPDATE usuarios_internet
                    SET senha = ?, confirmado_em = COALESCE(confirmado_em, ?), tentativas = 0, bloqueado_ate = NULL
                  WHERE id = ?',
                [password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]), date('Y-m-d H:i:s'), $t['id']]
            );
            // Troca de senha derruba as sessões abertas dessa conta
            Database::execute('DELETE FROM sessoes_internet WHERE usuario_id = ?', [$t['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[GWOS acesso] definirSenha: ' . $e->getMessage());
            return [false, 'Erro interno ao gravar a senha.'];
        }

        self::sincronizar();
        return [true, 'Senha criada! Faça o login para liberar a internet.'];
    }

    public static function validarSenha(string $senha): ?string
    {
        if (strlen($senha) < 8) {
            return 'A senha deve ter pelo menos 8 caracteres.';
        }
        if (strlen($senha) > 72) {      // limite real do bcrypt
            return 'A senha pode ter no máximo 72 caracteres.';
        }
        if (!preg_match('/[A-Za-z]/', $senha) || !preg_match('/\d/', $senha)) {
            return 'A senha deve ter letras e números.';
        }
        return null;
    }

    // ── login / sessão ──────────────────────────────────────────────────────
    /** @return array{0:bool,1:string,2:?array} [ok, mensagem, usuário] */
    public static function entrar(string $email, string $senha, string $ip): array
    {
        $falha = 'E-mail ou senha incorretos, ou cadastro ainda não confirmado.';
        $email = self::normalizarEmail($email);
        $usuario = $email !== '' ? Database::fetch('SELECT * FROM usuarios_internet WHERE email = ?', [$email]) : false;

        if (!$usuario) {
            // Gasta o mesmo tempo de um login real (não revela se o e-mail existe)
            password_verify($senha, '$2y$12$LRGqTqHtKpZxFDfRVh237eTgCb4m2VvkDVum2cTnjQwXQlljO/94G');
            return [false, $falha, null];
        }

        if ($usuario['bloqueado_ate'] && time() < strtotime($usuario['bloqueado_ate'])) {
            return [false, 'Muitas tentativas. Tente novamente em alguns minutos ou use "Esqueci minha senha".', null];
        }

        if (!password_verify($senha, $usuario['senha']) || !(int) $usuario['ativo'] || !$usuario['confirmado_em']) {
            $tent = (int) $usuario['tentativas'] + 1;
            $bloq = null;
            if ($tent >= self::MAX_TENTATIVAS) {
                $bloq = date('Y-m-d H:i:s', time() + self::BLOQUEIO_MIN * 60);
                $tent = 0;
            }
            Database::execute('UPDATE usuarios_internet SET tentativas = ?, bloqueado_ate = ? WHERE id = ?',
                [$tent, $bloq, $usuario['id']]);
            return [false, $falha, null];
        }

        $horas  = max(1, (int) self::cfg('acesso_sessao_horas', '8'));
        $expira = date('Y-m-d H:i:s', time() + $horas * 3600);

        // Um IP tem no máximo uma sessão: quem loga por último assume a máquina
        Database::execute(
            'REPLACE INTO sessoes_internet (usuario_id, ip, expira_em) VALUES (?, ?, ?)',
            [$usuario['id'], $ip, $expira]
        );
        Database::execute(
            'UPDATE usuarios_internet SET tentativas = 0, bloqueado_ate = NULL, ultimo_login = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $usuario['id']]
        );

        if (!self::sincronizar()) {
            Database::execute('DELETE FROM sessoes_internet WHERE ip = ?', [$ip]);
            return [false, 'Não foi possível liberar o acesso (erro interno). Avise o administrador.', null];
        }

        $usuario['expira_em'] = $expira;
        return [true, 'Acesso liberado.', $usuario];
    }

    public static function sessaoDoIp(string $ip): ?array
    {
        $s = Database::fetch(
            'SELECT s.*, u.nome, u.email FROM sessoes_internet s JOIN usuarios_internet u ON u.id = s.usuario_id
              WHERE s.ip = ? AND s.expira_em > ? AND u.ativo = 1',
            [$ip, date('Y-m-d H:i:s')]
        );
        return $s ?: null;
    }

    public static function sair(string $ip): void
    {
        Database::execute('DELETE FROM sessoes_internet WHERE ip = ?', [$ip]);
        self::sincronizar();
    }

    // ── administração ───────────────────────────────────────────────────────
    public static function sessoesAtivas(): array
    {
        return Database::fetchAll(
            'SELECT s.id, s.ip, s.criado_em, s.expira_em, u.nome, u.email
               FROM sessoes_internet s JOIN usuarios_internet u ON u.id = s.usuario_id
              WHERE s.expira_em > ? ORDER BY s.criado_em DESC',
            [date('Y-m-d H:i:s')]
        );
    }

    public static function usuarios(): array
    {
        return Database::fetchAll(
            'SELECT id, nome, email, ativo, confirmado_em, ultimo_login, criado_em, bloqueado_ate
               FROM usuarios_internet ORDER BY nome'
        );
    }

    public static function derrubarSessao(int $id): void
    {
        Database::execute('DELETE FROM sessoes_internet WHERE id = ?', [$id]);
        self::sincronizar();
    }

    public static function alternarAtivo(int $id): void
    {
        Database::execute('UPDATE usuarios_internet SET ativo = 1 - ativo WHERE id = ?', [$id]);
        // Desativar derruba as sessões; sincronizar() já ignora quem está inativo
        self::sincronizar();
    }

    public static function excluirUsuario(int $id): void
    {
        Database::execute('DELETE FROM usuarios_internet WHERE id = ?', [$id]);  // cascata: sessões e tokens
        self::sincronizar();
    }

    // ── ponte com o Squid ───────────────────────────────────────────────────
    /**
     * Regrava SESSOES_ARQ com as sessões válidas: "ip<TAB>expira_epoch<TAB>e-mail".
     * Escrita atômica (arquivo temporário + rename) para o helper nunca ler
     * um arquivo pela metade.
     */
    public static function sincronizar(): bool
    {
        try {
            Database::execute('DELETE FROM sessoes_internet WHERE expira_em <= ?', [date('Y-m-d H:i:s')]);
            $linhas = Database::fetchAll(
                'SELECT s.ip, s.expira_em, u.email
                   FROM sessoes_internet s JOIN usuarios_internet u ON u.id = s.usuario_id
                  WHERE u.ativo = 1 AND s.expira_em > ?',
                [date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            error_log('[GWOS acesso] sincronizar (banco): ' . $e->getMessage());
            return false;
        }

        $texto = '';
        foreach ($linhas as $l) {
            // e-mail já é validado no cadastro; remove qualquer separador por segurança
            $email = preg_replace('/[\s]+/', '', $l['email']);
            $texto .= $l['ip'] . "\t" . strtotime($l['expira_em']) . "\t" . $email . "\n";
        }

        $dir = dirname(self::SESSOES_ARQ);
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log("[GWOS acesso] {$dir} inexistente ou sem escrita para o PHP.");
            return false;
        }
        $tmp = self::SESSOES_ARQ . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $texto) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        return rename($tmp, self::SESSOES_ARQ);
    }
}
