<?php $titulo = 'Alterar Senha'; $modulo = 'senha'; ob_start(); ?>

<div class="row justify-content-center">
    <div class="col-md-5">

        <?php if (!empty($primeiroLogin)): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div><strong>Primeiro acesso detectado.</strong> Por segurança, defina uma nova senha antes de continuar.</div>
        </div>
        <?php endif; ?>

        <?php if ($erro ?? null): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= h($erro) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-key-fill text-primary me-2"></i>Alterar Senha
            </div>
            <div class="card-body p-4">
                <form method="POST" action="/senha/trocar" id="form-senha">
                    <?= csrf_field() ?>

                    <?php if (empty($primeiroLogin)): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Senha atual</label>
                        <div class="input-group">
                            <input type="password" name="senha_atual" id="inp-atual"
                                   class="form-control" required autocomplete="current-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggle('inp-atual',this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="senha_atual" value="">
                    <?php endif; ?>

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
                               class="form-control" required minlength="8"
                               autocomplete="new-password">
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle-fill me-1"></i> Salvar nova senha
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (empty($primeiroLogin)): ?>
        <div class="text-center mt-3">
            <a href="/" class="text-secondary small"><i class="bi bi-arrow-left me-1"></i>Voltar ao painel</a>
        </div>
        <?php endif; ?>
    </div>
</div>

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

    const labels = ['','Muito fraca','Fraca','Razoável','Forte','Muito forte'];
    const colors = ['','#dc3545','#fd7e14','#ffc107','#198754','#0d6efd'];
    const pct    = [0, 20, 40, 60, 80, 100];

    const barra = document.getElementById('barra-forca');
    const txt   = document.getElementById('txt-forca');
    barra.style.width      = pct[score] + '%';
    barra.style.background = colors[score];
    txt.textContent        = score > 0 ? labels[score] : '';
    txt.style.color        = colors[score];
}

document.getElementById('form-senha').addEventListener('submit', function(e) {
    const nova    = document.getElementById('inp-nova').value;
    const confirm = document.getElementById('inp-confirm').value;
    if (nova !== confirm) {
        e.preventDefault();
        alert('As senhas não coincidem.');
    }
});
</script>
<?php $conteudo = ob_get_clean(); require BASE_PATH . '/app/Views/layout.php'; ?>
