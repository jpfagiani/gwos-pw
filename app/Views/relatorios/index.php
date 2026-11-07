<?php $titulo = 'Relatórios'; $modulo = 'relatorios'; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" action="/relatorios" class="row g-2 align-items-end">
            <div class="col-sm-4">
                <label class="form-label fw-semibold small mb-1">Data início</label>
                <input type="date" name="data_inicio" class="form-control form-control-sm"
                       value="<?= h($data_inicio) ?>">
            </div>
            <div class="col-sm-4">
                <label class="form-label fw-semibold small mb-1">Data fim</label>
                <input type="date" name="data_fim" class="form-control form-control-sm"
                       value="<?= h($data_fim) ?>">
            </div>
            <div class="col-sm-auto">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-search me-1"></i>Filtrar
                </button>
            </div>
            <div class="col-sm-auto">
                <a class="btn btn-sm btn-outline-primary" href="/relatorios/exportar?data_inicio=<?= h($data_inicio) ?>&data_fim=<?= h($data_fim) ?>">
                    <i class="bi bi-download me-1"></i>Exportar CSV
                </a>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($erro)): ?><div class="alert alert-warning" role="alert"><?= h($erro) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted small"><?= number_format($total) ?> registro(s) encontrado(s)</span>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover small mb-0">
            <thead class="table-light">
                <tr>
                    <th>Data</th>
                    <th>IP Cliente</th>
                    <th>Domínio</th>
                    <th class="text-end">Requisições</th>
                    <th class="text-end">Tráfego</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($registros)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhum dado encontrado para o período.</td></tr>
                <?php else: ?>
                    <?php foreach ($registros as $r): ?>
                        <tr>
                            <td><?= h($r['data']) ?></td>
                            <td><span class="font-monospace text-primary fw-semibold"><?= h($r['ip_cliente']) ?></span></td>
                            <td><?= h($r['dominio']) ?></td>
                            <td class="text-end"><?= number_format($r['acessos']) ?></td>
                            <td class="text-end"><?= formatar_bytes((int)$r['bytes']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($total_paginas > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm justify-content-center">
            <?php for ($p = 1; $p <= $total_paginas; $p++): ?>
                <li class="page-item <?= $p === $pagina ? 'active' : '' ?>">
                    <a class="page-link" href="?pagina=<?= $p ?>&data_inicio=<?= h($data_inicio) ?>&data_fim=<?= h($data_fim) ?>">
                        <?= $p ?>
                    </a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
