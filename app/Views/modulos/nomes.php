<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Nomes internos</h1>
        <p class="text-secondary small mb-0">
            Módulo <code>25-dns-interno-dnsmasq</code> — vincula um nome a um IP
            da rede, para acessar por nome em vez de decorar endereço.
        </p>
    </div>
    <div class="text-end">
        <?php foreach ($servicos as $servico => $ativo): ?>
            <span class="badge <?= $ativo ? 'text-bg-success' : 'text-bg-danger' ?>">
                <?= h($servico) ?>: <?= $ativo ? 'ativo' : 'parado' ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($aviso): ?><div class="alert alert-success py-2"><?= h($aviso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger py-2"><pre class="mb-0 small"><?= h($erro) ?></pre></div><?php endif; ?>

<?php if (!$temBind): ?>
    <div class="alert alert-warning py-2">
        <strong>Módulo 20-dns-bind9 ausente.</strong>
        Estes nomes só respondem em <code>127.0.0.1:<?= h($porta) ?></code> —
        as máquinas da rede não os alcançam. Instale o módulo de DNS para que a
        LAN resolva pela porta 53.
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><h2 class="h6 mb-0">Nomes cadastrados</h2></div>
    <div class="card-body">
        <?php if ($nomes === []): ?>
            <p class="text-secondary small mb-0">
                Nenhum nome cadastrado. Use o formulário abaixo — por exemplo
                <code>portal</code> apontando para o servidor do portal.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr class="small text-secondary">
                            <th>Nome</th><th>FQDN</th><th style="width: 12rem">IP</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($nomes as $nome): ?>
                        <tr>
                            <td><code><?= h($nome['host']) ?></code></td>
                            <td class="text-secondary small"><?= h($nome['fqdn']) ?></td>
                            <td>
                                <form method="post" class="d-flex gap-1"
                                      action="/modulos/nomes/<?= h(rawurlencode($nome['host'])) ?>/atualizar">
                                    <?= csrf_field() ?>
                                    <input class="form-control form-control-sm font-monospace"
                                           name="ip" value="<?= h($nome['ip']) ?>" required>
                                    <button class="btn btn-sm btn-outline-primary">Alterar</button>
                                </form>
                            </td>
                            <td class="text-end">
                                <form method="post"
                                      action="/modulos/nomes/<?= h(rawurlencode($nome['host'])) ?>/remover"
                                      onsubmit="return confirm('Remover <?= h($nome['host']) ?>?')">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-danger">Remover</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h2 class="h6 mb-0">Novo nome</h2></div>
    <div class="card-body">
        <form method="post" action="/modulos/nomes" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label small">Nome</label>
                <div class="input-group input-group-sm">
                    <input class="form-control font-monospace" name="host" placeholder="portal" required>
                    <span class="input-group-text">.<?= h($dominioLocal) ?></span>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label small">IP</label>
                <input class="form-control form-control-sm font-monospace" name="ip"
                       placeholder="10.14.29.8" required>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-sm btn-primary">Cadastrar</button>
            </div>
        </form>
        <p class="text-secondary small mt-3 mb-0">
            Só letras, números e hífen — sem ponto. O FQDN
            <code>nome.<?= h($dominioLocal) ?></code> é montado sozinho.
            Propaga nos clientes em até 60 segundos.
        </p>
    </div>
</div>

<div class="alert alert-light border small mb-0">
    <strong>O nome curto sozinho não resolve por DNS.</strong>
    Só <code>nome.<?= h($dominioLocal) ?></code> funciona: o BIND9 encaminha ao
    dnsmasq apenas o que termina no domínio interno, e um nome sem ponto vai
    parar nos resolvers externos. Para que <code>http://portal</code> funcione
    no navegador, entregue o sufixo de busca aos clientes pela opção 15 do DHCP
    (<code>domain-name = <?= h($dominioLocal) ?></code>).
</div>
