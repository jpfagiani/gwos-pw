<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>Login — GWOS</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.min.css">
    <style>
        body {
            background: #0e1621;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card { width: 100%; max-width: 370px; }
        .logo-tile {
            width: 52px; height: 52px; border-radius: 14px;
            background: #2f81f7;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .card { border: none; border-radius: 14px; }
        .form-control, .input-group-text { border-radius: 8px; }
        .input-group .form-control { border-top-left-radius: 0; border-bottom-left-radius: 0; }
        .input-group-text { background: #f8fafc; color: #94a3b8; border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .btn-primary { background: #2f81f7; border-color: #2f81f7; border-radius: 8px; padding: .55rem; }
        .btn-primary:hover { background: #2569d3; border-color: #2569d3; }
        .sub { color: #5c6f85; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="text-center mb-4">
        <div class="logo-tile">
            <svg width="30" height="30" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2 L20 5 V11 C20 16.2 16.6 19.8 12 21.5 C7.4 19.8 4 16.2 4 11 V5 Z" fill="#fff" opacity=".14"/>
                <path d="M12 2 L20 5 V11 C20 16.2 16.6 19.8 12 21.5 C7.4 19.8 4 16.2 4 11 V5 Z" fill="none" stroke="#fff" stroke-width="1.4"/>
                <path d="M8.4 9.1 H12.4 A2 2 0 0 1 14.4 11.1 V13.8" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round"/>
                <circle cx="8.4" cy="9.1" r="1.4" fill="#fff"/>
                <circle cx="14.4" cy="14.2" r="1.4" fill="#fff"/>
            </svg>
        </div>
        <h3 class="text-white mt-2 fw-bold" style="letter-spacing:.5px">GWOS</h3>
        <p class="sub small mb-0">Gateway Web OS</p>
    </div>

    <div class="card shadow-lg">
        <div class="card-body p-4">
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
                    <label class="form-label fw-semibold small">E-mail</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" class="form-control" placeholder="admin@exemplo.com"
                               required autofocus value="<?= h($_POST['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold small">Senha</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="senha" class="form-control" placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 fw-semibold">
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
</div>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
