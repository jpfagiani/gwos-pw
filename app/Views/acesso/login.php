<?php $titulo = 'Entrar'; require __DIR__ . '/_topo.php'; ?>

<?php if (!empty($sessao)): ?>
    <p class="mb-1">Conectado como <strong><?= h($sessao['nome']) ?></strong></p>
    <p class="text-muted small"><?= h($sessao['email']) ?><br>
       Sessão válida até <?= h(date('d/m H:i', strtotime($sessao['expira_em']))) ?></p>
    <?php if (!empty($url)): ?>
        <a class="btn btn-primary w-100 mb-2" href="<?= h($url) ?>" rel="noopener noreferrer">
            Continuar para <?= h(parse_url($url, PHP_URL_HOST) ?: 'o site') ?>
        </a>
    <?php endif; ?>
    <form method="POST" action="/acesso/sair">
        <?= csrf_field() ?>
        <button class="btn btn-outline-secondary w-100">Sair e bloquear este computador</button>
    </form>
<?php else: ?>
    <form method="POST" action="/acesso" autocomplete="on">
        <?= csrf_field() ?>
        <input type="hidden" name="url" value="<?= h($url) ?>">
        <div class="mb-3">
            <label class="form-label small fw-semibold">E-mail institucional</label>
            <input type="email" name="email" class="form-control" placeholder="nome@<?= h($dominio) ?>" required autofocus autocomplete="username">
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">Senha</label>
            <input type="password" name="senha" class="form-control" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary w-100">Entrar</button>
    </form>
    <div class="d-flex justify-content-between small mt-3">
        <a href="/acesso/cadastro">Primeiro acesso</a>
        <a href="/acesso/esqueci">Esqueci minha senha</a>
    </div>
    <p class="text-muted small mt-3 mb-0">Cada conta pode manter até <?= h(\App\Core\AcessoInternet::cfg('acesso_max_sessoes_usuario', '3')) ?> dispositivos conectados ao mesmo tempo. Ao atingir o limite, encerre uma sessão ativa antes de entrar em outro dispositivo.</p>
<?php endif; ?>

<?php require __DIR__ . '/_fim.php'; ?>
