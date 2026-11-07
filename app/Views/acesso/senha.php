<?php $titulo = 'Criar senha'; require __DIR__ . '/_topo.php'; ?>
<?php if (!$valido): ?>
    <div class="alert alert-warning small mb-3">Este link é inválido, já foi usado ou expirou.</div>
    <a class="btn btn-primary w-100 mb-2" href="/acesso/esqueci">Pedir um novo link</a>
    <a class="small" href="/acesso">&larr; Voltar</a>
<?php else: ?>
    <p class="small text-muted mb-3">Olá, <strong><?= h($usuario['nome']) ?></strong> (<?= h($usuario['email']) ?>).
       Crie a senha que você vai usar para liberar a internet.</p>
    <form method="POST" action="/acesso/senha" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="t" value="<?= h($token) ?>">
        <div class="mb-3">
            <label class="form-label small fw-semibold">Nova senha</label>
            <input type="password" name="senha" class="form-control" minlength="8" maxlength="72" required autofocus autocomplete="new-password">
            <div class="form-text">Mínimo de 8 caracteres, com letras e números.</div>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">Repita a senha</label>
            <input type="password" name="confirmar" class="form-control" minlength="8" maxlength="72" required autocomplete="new-password">
        </div>
        <button class="btn btn-primary w-100">Salvar senha</button>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/_fim.php'; ?>
