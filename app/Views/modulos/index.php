<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Módulos</h1>
        <p class="text-secondary small mb-0">
            Servidores instalados nesta máquina. A lista vem de
            <code>/etc/gwos/modulos.d</code> e se atualiza sozinha — instalou pelo
            terminal, aparece aqui.
        </p>
    </div>
    <?php if (!$temBanco): ?>
        <span class="badge text-bg-warning">modo leve — sem banco de dados</span>
    <?php endif; ?>
</div>

<?php if (!$temEstado): ?>
    <div class="alert alert-warning">
        <strong>/etc/gwos/gwos.conf não encontrado.</strong>
        Nenhum módulo GWOS parece instalado nesta máquina.
    </div>
<?php endif; ?>

<?php if ($instalados === []): ?>
    <div class="alert alert-secondary">
        Nenhum módulo registrado. Instale o primeiro com
        <code>bash modulos/20-dns-bind9/instalar.sh</code>.
    </div>
<?php else: ?>

<div class="row g-3 mb-4">
<?php foreach ($instalados as $marcador => $mod): ?>
    <?php
        $servicos = $status[$marcador] ?? [];
        $parados  = array_keys(array_filter($servicos, fn($ativo) => !$ativo));
    ?>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi <?= h($mod['icone']) ?> fs-5"></i>
                        <div>
                            <h2 class="h6 mb-0"><?= h($mod['titulo']) ?></h2>
                            <div class="text-secondary small"><?= h($mod['resumo']) ?></div>
                        </div>
                    </div>
                    <?php if ($servicos === []): ?>
                        <span class="badge text-bg-secondary">sem serviço</span>
                    <?php elseif ($parados === []): ?>
                        <span class="badge text-bg-success">ativo</span>
                    <?php else: ?>
                        <span class="badge text-bg-danger">parado</span>
                    <?php endif; ?>
                </div>

                <?php if ($servicos !== []): ?>
                    <div class="mt-3 small">
                        <?php foreach ($servicos as $servico => $ativo): ?>
                            <span class="me-3">
                                <i class="bi <?= $ativo ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i>
                                <code><?= h($servico) ?></code>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="mt-3 d-flex align-items-center justify-content-between">
                    <span class="text-secondary small">
                        <?= $mod['instalado_em'] ? 'Instalado em ' . h($mod['instalado_em']) : 'Pasta ' . h($mod['pasta']) ?>
                    </span>
                    <?php if ($mod['tela'] === true && $mod['rota']): ?>
                        <a class="btn btn-sm btn-primary" href="<?= h($mod['rota']) ?>">Configurar</a>
                    <?php elseif ($mod['rota']): ?>
                        <span class="badge text-bg-light text-secondary">tela em construção</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($estado !== []): ?>
<div class="card mb-4">
    <div class="card-header">
        <h2 class="h6 mb-0">Estado compartilhado</h2>
    </div>
    <div class="card-body">
        <p class="text-secondary small">
            De <code>/etc/gwos/gwos.conf</code> — é o que todos os módulos leem.
            Interfaces, IP e rede só mudam por <code>gwos ip</code>.
        </p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <tbody>
                <?php foreach ($estado as $chave => $valor): ?>
                    <tr>
                        <th class="text-nowrap" style="width: 14rem"><code><?= h($chave) ?></code></th>
                        <td><?= $valor === '' ? '<span class="text-secondary">—</span>' : h($valor) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($ausentes !== []): ?>
<div class="card">
    <div class="card-header">
        <h2 class="h6 mb-0">Disponíveis para instalar</h2>
    </div>
    <div class="card-body">
        <p class="text-secondary small">
            Rode no terminal, como root. Cada módulo se integra sozinho aos que
            já estão aqui — não é preciso reinstalar nada.
        </p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <tbody>
                <?php foreach ($ausentes as $mod): ?>
                    <tr>
                        <td style="width: 12rem">
                            <i class="bi <?= h($mod['icone']) ?>"></i> <?= h($mod['titulo']) ?>
                        </td>
                        <td class="text-secondary small"><?= h($mod['resumo']) ?></td>
                        <td class="text-nowrap"><code><?= h($mod['comando']) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
