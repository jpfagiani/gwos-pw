<?php $titulo = 'Acesso à internet'; $modulo = 'internet'; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Acesso à internet com login</h5>
    <a class="btn btn-sm btn-outline-primary" href="/internet/logs"><i class="bi bi-journal-text me-1"></i>Registro de acessos</a>
</div>

<div class="alert alert-info small">
    Quem não está em nenhum grupo de IP precisa entrar com e-mail <strong>@<?= h($dominio) ?></strong> e senha.
    Para dispensar a senha de um computador, cadastre o IP dele num grupo do tipo <strong>Isento</strong>
    (em <a href="/grupos">Grupos</a>): ele navega livremente e continua aparecendo no registro de acessos.
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Sessões ativas (<?= count($sessoes) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle small mb-0">
            <thead class="table-light"><tr><th>Usuário</th><th>E-mail</th><th>IP da máquina</th><th>Desde</th><th>Expira</th><th class="text-end">Ação</th></tr></thead>
            <tbody>
            <?php if (!$sessoes): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">Nenhuma sessão ativa.</td></tr>
            <?php endif; ?>
            <?php foreach ($sessoes as $s): ?>
                <tr>
                    <td><?= h($s['nome']) ?></td>
                    <td><?= h($s['email']) ?></td>
                    <td class="font-monospace text-primary"><?= h($s['ip']) ?></td>
                    <td><?= h(date('d/m H:i', strtotime($s['criado_em']))) ?></td>
                    <td><?= h(date('d/m H:i', strtotime($s['expira_em']))) ?></td>
                    <td class="text-end">
                        <form method="POST" action="/internet/sessoes/<?= (int) $s['id'] ?>/derrubar" class="d-inline"
                              onsubmit="return confirm('Encerrar a sessão de <?= h($s['email']) ?>?')">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-power"></i> Derrubar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Usuários cadastrados (<?= count($usuarios) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle small mb-0">
            <thead class="table-light"><tr><th>Nome</th><th>E-mail</th><th>Situação</th><th>Último login</th><th>Cadastro</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$usuarios): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">Nenhum usuário cadastrado ainda.</td></tr>
            <?php endif; ?>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?= h($u['nome']) ?></td>
                    <td><?= h($u['email']) ?></td>
                    <td>
                        <?php if (!(int) $u['ativo']): ?><span class="badge bg-secondary">Desativado</span>
                        <?php elseif (!$u['confirmado_em']): ?><span class="badge bg-warning text-dark">Aguardando confirmação</span>
                        <?php else: ?><span class="badge bg-success">Ativo</span><?php endif; ?>
                    </td>
                    <td><?= $u['ultimo_login'] ? h(date('d/m/Y H:i', strtotime($u['ultimo_login']))) : '—' ?></td>
                    <td><?= h(date('d/m/Y', strtotime($u['criado_em']))) ?></td>
                    <td class="text-end text-nowrap">
                        <form method="POST" action="/internet/usuarios/<?= (int) $u['id'] ?>/alternar" class="d-inline">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-secondary"><?= (int) $u['ativo'] ? 'Desativar' : 'Ativar' ?></button>
                        </form>
                        <form method="POST" action="/internet/usuarios/<?= (int) $u['id'] ?>/excluir" class="d-inline"
                              onsubmit="return confirm('Excluir <?= h($u['email']) ?>? O histórico de acessos é mantido.')">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Envio de e-mail (SMTP <?= h($smtp['host'] ?: 'não configurado') ?>:<?= h($smtp['porta']) ?>)</div>
    <div class="card-body">
        <form method="POST" action="/internet/teste-email" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Enviar e-mail de teste para</label>
                <input type="email" name="para" class="form-control form-control-sm" placeholder="voce@<?= h($dominio) ?>" required>
            </div>
            <div class="col-sm-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Testar</button></div>
        </form>
        <div class="form-text mt-2">Servidor, porta, segurança, usuário e remetente ficam em <a href="/configuracoes">Configurações</a> (chaves <code>smtp_*</code>).</div>
    </div>
</div>
