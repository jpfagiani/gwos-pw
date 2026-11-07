<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>Login — GWOS</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/gwos.css">
</head>
<body class="login-page">
<div class="login-caixa">
    <div class="login-topo">
        <svg class="escudo" width="72" height="72" viewBox="0 0 48 48" aria-hidden="true"><path d="M24 3 40 9v13c0 10.2-6.6 18.1-16 22C14.6 40.1 8 32.2 8 22V9z" fill="#0866d9" stroke="#9cc4ff" stroke-width="2"/><circle cx="24" cy="23" r="9" fill="none" stroke="#fff" stroke-width="1.6"/><ellipse cx="24" cy="23" rx="4" ry="9" fill="none" stroke="#fff" stroke-width="1.4"/><path d="M15 23h18M16.5 18h15M16.5 28h15" stroke="#fff" stroke-width="1.2"/></svg>
        <h1>GWOS</h1>
        <p>Acesso ao painel de gerenciamento da rede</p>
    </div>
    <div class="login-cartao">
        <div>
            <?php
            $flash_reset = \App\Core\Session::flash('sucesso_login');
            if ($flash_reset): ?>
                <div class="alert alert-success py-2 small"><?= h($flash_reset) ?></div>
            <?php endif; ?>
            <?php if (!empty($erro)): ?>
                <div class="alert alert-danger py-2 small"><?= h($erro) ?></div>
            <?php endif; ?>

            <form method="POST" action="/login">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Usuário (e-mail)</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" class="form-control" placeholder="Usuário" autocomplete="username"
                               required autofocus value="<?= h($_POST['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold small">Senha</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="senha" class="form-control" placeholder="Senha" required autocomplete="current-password">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-entrar w-100">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Entrar
                </button>
            </form>

            <div class="text-center mt-3">
                <a href="/senha/reset" class="text-secondary small text-decoration-none">
                    <i class="bi bi-question-circle me-1"></i>Esqueci minha senha
                </a>
            </div>
        </div>
    </div>
    <div class="login-rodape"><i class="bi bi-lock-fill"></i> Acesso seguro à rede · GWOS v<?= h(config('app.versao', '2.0')) ?><br>Navegar na internet? <a href="/acesso">Acesso à Internet</a></div>
</div>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
