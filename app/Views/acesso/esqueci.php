<?php $titulo = 'Esqueci minha senha'; require __DIR__ . '/_topo.php'; ?>
<p class="small text-muted">Informe seu e-mail <strong>@<?= h($dominio) ?></strong>. Enviaremos um link para criar uma nova senha.</p>
<form method="POST" action="/acesso/esqueci">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label small fw-semibold">E-mail institucional</label>
        <input type="email" name="email" class="form-control" placeholder="nome@<?= h($dominio) ?>" required autofocus autocomplete="email">
    </div>
    <button class="btn btn-primary w-100">Enviar link</button>
</form>
<p class="small mt-3 mb-0"><a href="/acesso">&larr; Voltar</a></p>
<?php require __DIR__ . '/_fim.php'; ?>
