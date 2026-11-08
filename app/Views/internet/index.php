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

<?php if (!empty($linkGerado)): ?>
<div class="alert alert-success">
    <div class="fw-semibold mb-1"><i class="bi bi-link-45deg"></i> Link para <?= h($linkGerado['email']) ?> (vale <?= (int) $linkGerado['minutos'] >= 60 ? (int) ($linkGerado['minutos'] / 60) . ' h' : (int) $linkGerado['minutos'] . ' min' ?>, uso único)</div>
    <input type="text" readonly class="form-control form-control-sm font-monospace" value="<?= h($linkGerado['link']) ?>" onclick="this.select()">
    <div class="small mt-1">Repasse ao usuário. Esta mensagem não aparece de novo.</div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-person-plus me-1"></i>Cadastrar usuário (admin digita a senha)</div>
    <div class="card-body">
        <form method="POST" action="/internet/usuarios" class="row g-2 align-items-end" autocomplete="off">
            <?= csrf_field() ?>
            <div class="col-md-3"><label class="form-label small fw-semibold mb-1">Nome completo</label><input name="nome" class="form-control form-control-sm" required minlength="3"></div>
            <div class="col-md-3"><label class="form-label small fw-semibold mb-1">E-mail (@<?= h($dominio) ?>)</label><input type="email" name="email" class="form-control form-control-sm" required></div>
            <div class="col-md-2"><label class="form-label small fw-semibold mb-1">Senha</label><input type="password" name="senha" class="form-control form-control-sm" required minlength="8" autocomplete="new-password"></div>
            <div class="col-md-2"><label class="form-label small fw-semibold mb-1">Confirmar</label><input type="password" name="confirmar" class="form-control form-control-sm" required minlength="8" autocomplete="new-password"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-check2 me-1"></i>Criar</button></div>
        </form>
        <div class="form-text">Mínimo 8 caracteres, com letras e números. O usuário já entra confirmado, sem depender de e-mail.</div>
    </div>
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
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalSenha"
                                data-id="<?= (int) $u['id'] ?>" data-email="<?= h($u['email']) ?>"><i class="bi bi-key"></i> Senha</button>
                        <form method="POST" action="/internet/usuarios/<?= (int) $u['id'] ?>/link" class="d-inline">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-primary" title="Gerar link para o usuário criar a própria senha"><i class="bi bi-link-45deg"></i> Link</button>
                        </form>
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

<div class="modal fade" id="modalSenha" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><form method="POST" class="modal-content" id="formSenha" autocomplete="off">
    <?= csrf_field() ?>
    <div class="modal-header"><h6 class="modal-title">Definir senha — <span id="senhaEmail"></span></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <label class="form-label small fw-semibold">Nova senha</label><input type="password" name="senha" class="form-control mb-2" required minlength="8" autocomplete="new-password">
      <label class="form-label small fw-semibold">Confirmar</label><input type="password" name="confirmar" class="form-control" required minlength="8" autocomplete="new-password">
      <div class="form-text">As sessões abertas desse usuário serão encerradas.</div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary btn-sm">Salvar senha</button></div>
  </form></div>
</div>
<script>
document.getElementById('modalSenha').addEventListener('show.bs.modal', function (e) {
  var b = e.relatedTarget;
  document.getElementById('formSenha').action = '/internet/usuarios/' + b.dataset.id + '/senha';
  document.getElementById('senhaEmail').textContent = b.dataset.email;
});
</script>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-envelope me-1"></i>Envio de e-mail (SMTP)
        <?php if ($smtp['host'] === ''): ?><span class="badge bg-warning text-dark ms-2">não configurado</span><?php endif; ?></div>
    <div class="card-body">
        <form method="POST" action="/internet/smtp" class="row g-2 align-items-end mb-3" autocomplete="off">
            <?= csrf_field() ?>
            <div class="col-md-3"><label class="form-label small fw-semibold mb-1">Servidor</label><input name="host" class="form-control form-control-sm" value="<?= h($smtp['host']) ?>" placeholder="smtp.exemplo.gov.br"></div>
            <div class="col-md-1"><label class="form-label small fw-semibold mb-1">Porta</label><input name="porta" class="form-control form-control-sm" value="<?= h($smtp['porta']) ?>"></div>
            <div class="col-md-2"><label class="form-label small fw-semibold mb-1">Segurança</label>
                <select name="seguranca" class="form-select form-select-sm">
                    <?php foreach (['nenhuma' => 'Nenhuma', 'starttls' => 'STARTTLS', 'ssl' => 'SSL/TLS'] as $k => $r): ?>
                        <option value="<?= $k ?>" <?= $smtp['seguranca'] === $k ? 'selected' : '' ?>><?= $r ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-2"><label class="form-label small fw-semibold mb-1">Usuário</label><input name="usuario" class="form-control form-control-sm" value="<?= h($smtp['usuario']) ?>"></div>
            <div class="col-md-2"><label class="form-label small fw-semibold mb-1">Senha</label><input type="password" name="senha" class="form-control form-control-sm" placeholder="<?= $smtp['tem_senha'] ? '(mantida)' : '' ?>" autocomplete="new-password"></div>
            <div class="col-md-2"><label class="form-label small fw-semibold mb-1">Remetente</label><input name="remetente" class="form-control form-control-sm" value="<?= h($smtp['remetente']) ?>" placeholder="internet@<?= h($dominio) ?>"></div>
            <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Salvar e-mail</button></div>
        </form>
        <form method="POST" action="/internet/teste-email" class="row g-2 align-items-end border-top pt-3">
            <?= csrf_field() ?>
            <div class="col-sm-6">
                <label class="form-label small fw-semibold mb-1">Enviar e-mail de teste para</label>
                <input type="email" name="para" class="form-control form-control-sm" placeholder="voce@<?= h($dominio) ?>" required>
            </div>
            <div class="col-sm-auto"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-send me-1"></i>Testar</button></div>
        </form>
        <div class="form-text mt-2">Sem e-mail funcionando, use "Cadastrar usuário" ou o botão "Link" na lista.</div>
    </div>
</div>
