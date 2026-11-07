<?php $titulo = 'Gerar Token de Reset'; $modulo = 'senha'; ob_start(); ?>

<div class="row justify-content-center">
    <div class="col-md-6">

        <?php if ($erro ?? null): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= h($erro) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (($token ?? null) && ($tokenEmail ?? null)): ?>
        <div class="alert alert-success">
            <h6 class="fw-bold mb-2"><i class="bi bi-check-circle-fill me-1"></i>Token gerado com sucesso!</h6>
            <p class="mb-1">Encaminhe ao usuário <strong><?= h($tokenEmail) ?></strong>:</p>
            <div class="d-flex align-items-center gap-2 mt-2">
                <span class="font-monospace fw-bold fs-4 text-success letter-spacing"
                      id="token-display"><?= h($token) ?></span>
                <button class="btn btn-sm btn-outline-success" onclick="copiar()">
                    <i class="bi bi-clipboard"></i> Copiar
                </button>
            </div>
            <div class="text-muted small mt-2">
                <i class="bi bi-clock me-1"></i>Válido por 24 horas.
                O usuário deve acessar <strong>/senha/reset</strong> para usar o token.
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-key-fill text-warning me-2"></i>Gerar Token de Reset de Senha
            </div>
            <div class="card-body p-4">
                <p class="text-secondary small mb-3">
                    Informe o e-mail do administrador que esqueceu a senha.
                    Um token de 8 caracteres será gerado — encaminhe ao usuário pelo canal seguro de sua preferência.
                </p>
                <form method="POST" action="/senha/gerar">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">E-mail do usuário</label>
                        <input type="email" name="email" class="form-control"
                               required placeholder="usuario@dominio.local">
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-warning text-dark fw-semibold">
                            <i class="bi bi-key-fill me-1"></i> Gerar Token
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="text-center mt-3">
            <a href="/" class="text-secondary small"><i class="bi bi-arrow-left me-1"></i>Voltar ao painel</a>
        </div>
    </div>
</div>

<style>.letter-spacing { letter-spacing: .2em; }</style>
<script>
function copiar() {
    const t = document.getElementById('token-display').textContent.trim();
    navigator.clipboard.writeText(t).then(() => {
        const btn = document.querySelector('[onclick="copiar()"]');
        btn.innerHTML = '<i class="bi bi-check2"></i> Copiado!';
        setTimeout(() => btn.innerHTML = '<i class="bi bi-clipboard"></i> Copiar', 2000);
    });
}
</script>
<?php $conteudo = ob_get_clean(); require BASE_PATH . '/app/Views/layout.php'; ?>
