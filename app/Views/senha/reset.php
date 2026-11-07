<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>Redefinir Senha — GWOS</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.min.css">
    <style>
        body { background: #f4f6f9; }
        .login-card { max-width: 420px; margin: 80px auto; }
        .brand { font-size: 1.5rem; font-weight: 700; letter-spacing: 1px; color: #1a2332; }
    </style>
</head>
<body>
<div class="login-card px-3">
    <div class="text-center mb-4">
        <div class="brand"><i class="bi bi-shield-lock-fill text-primary me-2"></i>GWOS</div>
        <p class="text-secondary mt-1 small">Redefinição de senha via token</p>
    </div>

    <?php if ($sucesso ?? null): ?>
    <div class="alert alert-success"><?= h($sucesso) ?></div>
    <?php endif; ?>

    <?php if ($erro ?? null): ?>
    <div class="alert alert-danger"><?= h($erro) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <p class="text-secondary small mb-3">
                Solicite ao administrador do sistema um <strong>token de redefinição</strong>
                para o seu e-mail. Em seguida, preencha os campos abaixo.
            </p>
            <form method="POST" action="/senha/reset" id="form-reset">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label class="form-label fw-semibold">E-mail</label>
                    <input type="email" name="email" class="form-control" required
                           placeholder="seu@email.local" autocomplete="email">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Token</label>
                    <input type="text" name="token" class="form-control font-monospace text-uppercase"
                           required maxlength="8" placeholder="Ex: A1B2C3D4" autocomplete="off"
                           style="letter-spacing:.15em; font-size:1.1rem">
                    <div class="form-text">Token de 8 caracteres fornecido pelo administrador.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Nova senha</label>
                    <div class="input-group">
                        <input type="password" name="nova_senha" id="inp-nova"
                               class="form-control" required minlength="8"
                               autocomplete="new-password" oninput="checarForca(this.value)">
                        <button type="button" class="btn btn-outline-secondary" onclick="toggle('inp-nova',this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="progress mt-2" style="height:4px">
                        <div id="barra-forca" class="progress-bar" style="width:0%"></div>
                    </div>
                    <small id="txt-forca" class="text-muted"></small>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Confirmar nova senha</label>
                    <input type="password" name="confirmar" id="inp-confirm"
                           class="form-control" required minlength="8" autocomplete="new-password">
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle-fill me-1"></i> Redefinir senha
                    </button>
                </div>
            </form>
        </div>
    </div>
    <div class="text-center mt-3">
        <a href="/login" class="text-secondary small"><i class="bi bi-arrow-left me-1"></i>Voltar ao login</a>
    </div>
</div>

<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script>
function toggle(id, btn) {
    const el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
    btn.querySelector('i').className = el.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
function checarForca(val) {
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const colors = ['','#dc3545','#fd7e14','#ffc107','#198754','#0d6efd'];
    const pct    = [0, 20, 40, 60, 80, 100];
    const labels = ['','Muito fraca','Fraca','Razoável','Forte','Muito forte'];
    const barra = document.getElementById('barra-forca');
    const txt   = document.getElementById('txt-forca');
    barra.style.width = pct[score] + '%';
    barra.style.background = colors[score];
    txt.textContent = score > 0 ? labels[score] : '';
    txt.style.color = colors[score];
}
document.getElementById('form-reset').addEventListener('submit', e => {
    if (document.getElementById('inp-nova').value !== document.getElementById('inp-confirm').value) {
        e.preventDefault(); alert('As senhas não coincidem.');
    }
});
// Token input: auto maiúsculo
document.querySelector('input[name=token]').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});
</script>
</body>
</html>
