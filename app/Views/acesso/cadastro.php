<?php $titulo = 'Primeiro acesso'; require __DIR__ . '/_topo.php'; ?>
<p class="small text-muted">Informe seu nome e seu e-mail <strong>@<?= h($dominio) ?></strong>.
   Enviaremos um link para você confirmar o e-mail e criar sua senha.</p>
<form method="POST" action="/acesso/cadastro">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label small fw-semibold">Nome completo</label>
        <input type="text" name="nome" class="form-control" maxlength="100" required autofocus autocomplete="name">
    </div>
    <div class="mb-3">
        <label class="form-label small fw-semibold">E-mail institucional</label>
        <input type="email" name="email" class="form-control" placeholder="nome@<?= h($dominio) ?>" required autocomplete="email">
    </div>
    <button class="btn btn-primary w-100">Enviar link de confirmação</button>
</form>
<p class="small mt-3 mb-0"><a href="/acesso">&larr; Voltar</a></p>
<?php require __DIR__ . '/_fim.php'; ?>
