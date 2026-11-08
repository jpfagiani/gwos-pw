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

    public const MSG_SEM_EMAIL = 'O envio de e-mail está indisponível no momento. '
        . 'Procure o administrador da rede: ele pode criar sua senha de acesso diretamente.';

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

        Mailer::$ultimoErro = '';
        if ($usuario['confirmado_em']) {
            // Já cadastrado: em vez de repetir o cadastro, lembra do "esqueci a senha"
            $enviou = self::enviarLink($usuario, 'redefinir', $ip, true);
        } else {
            $enviou = self::enviarLink($usuario, 'confirmar', $ip, false);
        }
        if (!$enviou && Mailer::$ultimoErro !== '') {
            return [false, self::MSG_SEM_EMAIL];
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
            Mailer::$ultimoErro = '';
            if (!self::enviarLink($usuario, $tipo, $ip, false) && Mailer::$ultimoErro !== '') {
                return [false, self::MSG_SEM_EMAIL];
            }
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
            // Preserva o histórico antes de encerrar sessões por troca de senha.
            foreach (Database::fetchAll('SELECT s.*,u.nome,u.email FROM sessoes_internet s JOIN usuarios_internet u ON u.id=s.usuario_id WHERE s.usuario_id=? FOR UPDATE', [$t['id']]) as $sessao) self::arquivarSessao($sessao, 'senha_alterada');
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

    // ── gestão pelo administrador ───────────────────────────────────────────
    /** Cria (ou confirma) um usuário já com a senha digitada pelo admin. */
    public static function criarUsuarioAdmin(string $nome, string $email, string $senha): array
    {
        $nome  = trim(preg_replace('/\s+/', ' ', $nome));
        $email = self::normalizarEmail($email);
        if (self::tamanho($nome) < 3 || self::tamanho($nome) > 100) {
            return [false, 'Informe o nome completo (mínimo 3 letras).'];
        }
        if (!self::emailPermitido($email)) {
            return [false, 'Só é aceito e-mail institucional @' . self::dominioPermitido() . '.'];
        }
        if ($erro = self::validarSenha($senha)) {
            return [false, $erro];
        }
        if (Database::fetch('SELECT id FROM usuarios_internet WHERE email = ?', [$email])) {
            return [false, 'Já existe um usuário com esse e-mail. Use "Definir senha" na lista.'];
        }
        $id = (int) Database::insert(
            'INSERT INTO usuarios_internet (nome, email, senha, confirmado_em) VALUES (?, ?, ?, ?)',
            [$nome, $email, password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]), date('Y-m-d H:i:s')]
        );
        return [true, $id];
    }

    /** Define/redefine a senha de um usuário (admin digita). Encerra as sessões dele. */
    public static function definirSenhaAdmin(int $id, string $senha): array
    {
        if ($erro = self::validarSenha($senha)) {
            return [false, $erro];
        }
        $u = Database::fetch('SELECT id FROM usuarios_internet WHERE id = ?', [$id]);
        if (!$u) {
            return [false, 'Usuário não encontrado.'];
        }
        Database::execute(
            'UPDATE usuarios_internet SET senha = ?, confirmado_em = COALESCE(confirmado_em, ?), tentativas = 0, bloqueado_ate = NULL WHERE id = ?',
            [password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]), date('Y-m-d H:i:s'), $id]
        );
        Database::execute('UPDATE tokens_internet SET usado_em = ? WHERE usuario_id = ? AND usado_em IS NULL', [date('Y-m-d H:i:s'), $id]);
        foreach (Database::fetchAll('SELECT s.*,u.nome,u.email FROM sessoes_internet s JOIN usuarios_internet u ON u.id=s.usuario_id WHERE s.usuario_id=?', [$id]) as $sessao) {
            self::arquivarSessao($sessao, 'senha_alterada');
        }
        Database::execute('DELETE FROM sessoes_internet WHERE usuario_id = ?', [$id]);
        self::sincronizar();
        return [true, 'Senha definida.'];
    }

    /** Gera um link de senha (sem enviar e-mail) para o admin repassar. Sem limite de taxa. */
    public static function gerarLinkAdmin(int $id): ?array
    {
        $u = Database::fetch('SELECT * FROM usuarios_internet WHERE id = ? AND ativo = 1', [$id]);
        if (!$u) {
            return null;
        }
        $tipo    = $u['confirmado_em'] ? 'redefinir' : 'confirmar';
        $minutos = $tipo === 'confirmar' ? self::TOKEN_CONFIRMAR : self::TOKEN_REDEFINIR;
        $token   = bin2hex(random_bytes(32));
        Database::execute('UPDATE tokens_internet SET usado_em = ? WHERE usuario_id = ? AND usado_em IS NULL', [date('Y-m-d H:i:s'), $id]);
        Database::insert(
            'INSERT INTO tokens_internet (usuario_id, tipo, token_hash, expira_em, ip) VALUES (?, ?, ?, ?, ?)',
            [$id, $tipo, hash('sha256', $token), date('Y-m-d H:i:s', time() + $minutos * 60), 'admin']
        );
        return ['link' => self::urlBase() . '/acesso/senha?t=' . $token, 'minutos' => $minutos, 'email' => $u['email']];
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

        $maxSessoes = max(1, min(20, (int) self::cfg('acesso_max_sessoes_usuario', '3')));
        $horas  = max(1, (int) self::cfg('acesso_sessao_horas', '8'));
        $expira = date('Y-m-d H:i:s', time() + $horas * 3600);
        $pdo = Database::conectar();
        $pdo->beginTransaction();
        try {
            // Serializa logins da mesma conta para impedir que duas requisições
            // concorrentes ultrapassem o limite de dispositivos ativos.
            Database::fetch('SELECT id FROM usuarios_internet WHERE id=? FOR UPDATE', [$usuario['id']]);
            $sessoesEmOutrosIps = (int) Database::valor(
                'SELECT COUNT(*) FROM sessoes_internet WHERE usuario_id=? AND ip<>? AND expira_em>NOW()',
                [$usuario['id'], $ip]
            );
            if ($sessoesEmOutrosIps >= $maxSessoes) {
                $pdo->rollBack();
                return [false, 'Limite de dispositivos simultâneos atingido. Encerre uma sessão ativa ou contate o administrador.', null];
            }
            // Um IP tem no máximo uma sessão; arquiva a anterior antes de assumir a máquina.
            $anterior=Database::fetch('SELECT s.*,u.nome,u.email FROM sessoes_internet s JOIN usuarios_internet u ON u.id=s.usuario_id WHERE s.ip=? FOR UPDATE', [$ip]);
            if ($anterior) self::arquivarSessao($anterior, 'substituida');
            $grupos=implode(' / ', Monitoramento::gruposParaIp($ip));
            Database::execute(
                'REPLACE INTO sessoes_internet (usuario_id, ip, expira_em, grupo_snapshot) VALUES (?, ?, ?, ?)',
                [$usuario['id'], $ip, $expira, $grupos ?: null]
            );
            Database::execute(
                'UPDATE usuarios_internet SET tentativas = 0, bloqueado_ate = NULL, ultimo_login = ? WHERE id = ?',
                [date('Y-m-d H:i:s'), $usuario['id']]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[GWOS acesso] criar sessão: ' . $e->getMessage());
            return [false, 'Não foi possível liberar o acesso (erro interno). Avise o administrador.', null];
        }

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
        $s=Database::fetch('SELECT s.*,u.nome,u.email FROM sessoes_internet s JOIN usuarios_internet u ON u.id=s.usuario_id WHERE s.ip=?',[$ip]); if($s) self::arquivarSessao($s,'logout_usuario');
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
        $s=Database::fetch('SELECT s.*,u.nome,u.email FROM sessoes_internet s JOIN usuarios_internet u ON u.id=s.usuario_id WHERE s.id=?',[$id]); if($s) self::arquivarSessao($s,'revogada_admin');
        Database::execute('DELETE FROM sessoes_internet WHERE id = ?', [$id]);
        self::sincronizar();
    }

    public static function alternarAtivo(int $id): void
    {
        $u=Database::fetch('SELECT ativo FROM usuarios_internet WHERE id=?',[$id]); if($u && (int)$u['ativo']===1){ foreach(Database::fetchAll('SELECT s.*,u.nome,u.email FROM sessoes_internet s JOIN usuarios_internet u ON u.id=s.usuario_id WHERE s.usuario_id=?',[$id]) as $s) self::arquivarSessao($s,'conta_desativada'); Database::execute('DELETE FROM sessoes_internet WHERE usuario_id=?',[$id]); }
        Database::execute('UPDATE usuarios_internet SET ativo = 1 - ativo WHERE id = ?', [$id]);
        // Desativar derruba as sessões; sincronizar() já ignora quem está inativo
        self::sincronizar();
    }

    public static function excluirUsuario(int $id): void
    {
        foreach(Database::fetchAll('SELECT s.*,u.nome,u.email FROM sessoes_internet s JOIN usuarios_internet u ON u.id=s.usuario_id WHERE s.usuario_id=?',[$id]) as $s) self::arquivarSessao($s,'usuario_excluido');
        Database::execute('DELETE FROM usuarios_internet WHERE id = ?', [$id]);  // cascata: sessões e tokens
        self::sincronizar();
    }

    // ── ponte com o Squid ───────────────────────────────────────────────────
    /** Persiste o encerramento sem depender da existência futura da conta. */
    private static function arquivarSessao(array $s, string $motivo): void
    {
        $fim=date('Y-m-d H:i:s'); $inicio=(string)($s['criado_em']??$fim);
        $duracao=max(0,strtotime($fim)-(strtotime($inicio)?:strtotime($fim)));
        Database::execute('INSERT INTO sessoes_internet_historico (usuario_id,nome_usuario,email,ip,mac,hostname,grupo_snapshot,iniciou_em,expira_previsto_em,encerrou_em,duracao_segundos,motivo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$s['usuario_id']??null,(string)($s['nome']??''),(string)($s['email']??''),(string)$s['ip'],$s['mac']??null,$s['hostname']??null,$s['grupo_snapshot']??null,$inicio,$s['expira_em'],$fim,$duracao,$motivo]);
    }

    /**
     * Regrava SESSOES_ARQ com as sessões válidas: "ip<TAB>expira_epoch<TAB>e-mail".
     * Escrita atômica (arquivo temporário + rename) para o helper nunca ler
     * um arquivo pela metade.
     */
    public static function sincronizar(): bool
    {
        $pdo=Database::conectar();
        try {
            $pdo->beginTransaction();
            foreach(Database::fetchAll('SELECT s.*,u.nome,u.email FROM sessoes_internet s JOIN usuarios_internet u ON u.id=s.usuario_id WHERE s.expira_em<=? FOR UPDATE',[date('Y-m-d H:i:s')]) as $s) self::arquivarSessao($s,'expirada');
            Database::execute('DELETE FROM sessoes_internet WHERE expira_em <= ?', [date('Y-m-d H:i:s')]);
            $linhas = Database::fetchAll(
                'SELECT s.ip, s.expira_em, u.email
                   FROM sessoes_internet s JOIN usuarios_internet u ON u.id = s.usuario_id
                  WHERE u.ativo = 1 AND s.expira_em > ?',
                [date('Y-m-d H:i:s')]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if($pdo->inTransaction()) $pdo->rollBack();
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
