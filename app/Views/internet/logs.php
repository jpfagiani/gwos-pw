<?php $titulo = 'Registro de acessos'; $modulo = 'internet'; ?>
<?php $qs = http_build_query(array_filter($f, fn($v) => $v !== '')); ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Registro de acessos</h5>
    <a class="btn btn-sm btn-outline-primary" href="/internet"><i class="bi bi-people me-1"></i>Usuários e sessões</a>
</div>

<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <form method="GET" action="/internet/logs" class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small fw-semibold mb-1">De</label>
            <input type="date" name="data_inicio" value="<?= h($f['data_inicio']) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-semibold mb-1">Até</label>
            <input type="date" name="data_fim" value="<?= h($f['data_fim']) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-semibold mb-1">IP da máquina</label>
            <input type="text" name="ip" value="<?= h($f['ip']) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-semibold mb-1">E-mail</label>
            <input type="text" name="email" value="<?= h($f['email']) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-semibold mb-1">Site</label>
            <input type="text" name="dominio" value="<?= h($f['dominio']) ?>" class="form-control form-control-sm"></div>
        <div class="col-12 col-md-auto d-flex gap-2">
            <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Filtrar</button>
            <a class="btn btn-sm btn-outline-success" href="/internet/logs?<?= h($qs) ?>&csv=1"><i class="bi bi-file-earmark-spreadsheet me-1"></i>CSV</a>
        </div>
    </form>
</div></div>

<div class="text-muted small mb-2"><?= number_format($total, 0, ',', '.') ?> registro(s)</div>

<div class="card border-0 shadow-sm"><div class="table-responsive">
    <table class="table table-hover table-sm align-middle small mb-0">
        <thead class="table-light"><tr>
            <th>Data/hora</th><th>IP</th><th>Usuário</th><th>Método</th><th>Site</th><th>Resultado</th>
        </tr></thead>
        <tbody>
        <?php if (!$registros): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">Nenhum acesso no período.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td class="text-nowrap"><?= h(date('d/m/Y H:i:s', strtotime($r['ocorrido_em']))) ?></td>
                <td class="font-monospace text-primary"><?= h($r['ip']) ?></td>
                <td>
                    <?php if ($r['email']): ?>
                        <?= h($r['nome'] ?: '') ?> <span class="text-muted">&lt;<?= h($r['email']) ?>&gt;</span>
                    <?php else: ?><span class="text-muted">IP isento de login</span><?php endif; ?>
                </td>
                <td><?= h($r['metodo']) ?></td>
                <td style="max-width:420px" class="text-truncate" title="<?= h($r['url']) ?>"><?= h($r['dominio']) ?></td>
                <td><span class="badge bg-<?= str_contains($r['status'], 'DENIED') ? 'danger' : 'secondary' ?>"><?= h($r['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<?php if ($paginas > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center flex-wrap">
    <?php
    $janela = range(max(1, $pagina - 5), min($paginas, $pagina + 5));
    foreach ($janela as $p): ?>
        <li class="page-item <?= $p === $pagina ? 'active' : '' ?>">
            <a class="page-link" href="/internet/logs?<?= h($qs) ?>&p=<?= $p ?>"><?= $p ?></a>
        </li>
    <?php endforeach; ?>
</ul></nav>
<?php endif; ?>
