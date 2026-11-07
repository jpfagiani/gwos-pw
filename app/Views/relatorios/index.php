<?php $titulo = 'Relatórios'; $modulo = 'relatorios'; ?>
<style>@media print{#sidebar,#topbar,form,.btn,.pagination{display:none!important}#content{margin:0!important}.container-fluid{padding:0!important}}</style>

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
                <a class="btn btn-sm btn-outline-primary" href="/relatorios/exportar?tipo=trafego&data_inicio=<?= h($data_inicio) ?>&data_fim=<?= h($data_fim) ?>">
                    <i class="bi bi-download me-1"></i>Exportar CSV
                </a>
            </div>
            <div class="col-sm-auto"><button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-file-earmark-pdf me-1"></i>Imprimir / PDF</button></div>
            <div class="col-sm-auto"><a class="btn btn-sm btn-outline-dark" href="/relatorios/auditoria"><i class="bi bi-shield-check me-1"></i>Auditoria</a></div>
        </form>
    </div>
</div>


<div class="row g-3 mb-3">
 <?php foreach (['Requisições'=>number_format((int)$resumo['requisicoes']),'Download'=>formatar_bytes((int)$resumo['download']),'Upload'=>($resumo['upload']===null?'Sem medição legada':formatar_bytes((int)$resumo['upload'])),'Bloqueios'=>number_format((int)$resumo['bloqueios'])] as $rotulo=>$valor): ?>
 <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small"><?= h($rotulo) ?></div><div class="fs-5 fw-semibold"><?= h($valor) ?></div></div></div></div>
 <?php endforeach; ?>
</div>
<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white fw-semibold">Tráfego por equipamento, grupo e interface</div><div class="table-responsive"><table class="table table-hover table-sm small mb-0"><thead class="table-light"><tr><th>Equipamento</th><th>IP</th><th>MAC</th><th>Grupo</th><th>Interface</th><th class="text-end">Download</th><th class="text-end">Upload</th><th class="text-end">Bloqueios</th></tr></thead><tbody><?php if(!$trafego): ?><tr><td colspan="8" class="text-center text-muted py-3">Sem tráfego no período.</td></tr><?php else: foreach($trafego as $t): ?><tr><td><?= h($t['equipamento']) ?></td><td class="font-monospace"><?= h($t['ip']) ?></td><td><?= h($t['mac']??'—') ?></td><td><?= h($t['grupo']) ?></td><td><?= h($t['interface_rede']) ?></td><td class="text-end"><?= formatar_bytes((int)$t['download']) ?></td><td class="text-end"><?= $t['upload']===null?'Sem medição':formatar_bytes((int)$t['upload']) ?></td><td class="text-end"><?= number_format((int)$t['bloqueios']) ?></td></tr><?php endforeach; endif; ?></tbody></table></div><div class="card-footer small text-muted">Upload disponível somente para registros coletados após a atualização do formato Squid; interfaces não observadas são exibidas como não coletadas.</div></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white fw-semibold">Histórico de sessões encerradas <a class="btn btn-sm btn-outline-primary float-end" href="/relatorios/exportar?tipo=sessoes&data_inicio=<?= h($data_inicio) ?>&data_fim=<?= h($data_fim) ?>">CSV</a></div><div class="table-responsive"><table class="table table-hover table-sm small mb-0"><thead class="table-light"><tr><th>Usuário</th><th>E-mail</th><th>IP</th><th>MAC</th><th>Grupo no login</th><th>Início</th><th>Encerramento</th><th>Duração</th><th>Motivo</th></tr></thead><tbody><?php if(!$sessoes): ?><tr><td colspan="9" class="text-center text-muted py-3">O histórico começa a ser preenchido após aplicar a migração 006 e encerrar sessões.</td></tr><?php else: foreach($sessoes as $h): ?><tr><td><?= h($h['nome_usuario']) ?></td><td><?= h($h['email']) ?></td><td class="font-monospace"><?= h($h['ip']) ?></td><td><?= h($h['mac']??'—') ?></td><td><?= h($h['grupo_snapshot']??'Sem grupo no login') ?></td><td><?= h($h['iniciou_em']) ?></td><td><?= h($h['encerrou_em']) ?></td><td><?= number_format((int)$h['duracao_segundos']/60,0) ?> min</td><td><?= h($h['motivo']) ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div>


<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white fw-semibold">Alertas e eventos operacionais <a class="btn btn-sm btn-outline-primary float-end" href="/relatorios/exportar?tipo=alertas&data_inicio=<?= h($data_inicio) ?>&data_fim=<?= h($data_fim) ?>">CSV</a></div><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead class="table-light"><tr><th>Data</th><th>Severidade</th><th>Evento</th><th>Detalhe</th><th>Origem</th><th>Estado</th><th>Ocorrências</th></tr></thead><tbody><?php if(!$eventos): ?><tr><td colspan="7" class="text-center text-muted py-3">Sem alertas no período.</td></tr><?php else: foreach($eventos as $ev): ?><tr><td><?= h($ev['criado_em']) ?></td><td><?= h($ev['severidade']) ?></td><td><?= h($ev['titulo']) ?></td><td><?= h($ev['mensagem']) ?></td><td><?= h($ev['origem']) ?></td><td><?= h($ev['estado']) ?></td><td><?= number_format((int)$ev['ocorrencias']) ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white fw-semibold">Novos dispositivos observados <a class="btn btn-sm btn-outline-primary float-end" href="/relatorios/exportar?tipo=novos_dispositivos&data_inicio=<?= h($data_inicio) ?>&data_fim=<?= h($data_fim) ?>">CSV</a></div><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead class="table-light"><tr><th>Data</th><th>Evento</th><th>Detalhe</th><th>Estado</th></tr></thead><tbody><?php if(!$novos): ?><tr><td colspan="4" class="text-center text-muted py-3">Sem novos dispositivos observados no período.</td></tr><?php else: foreach($novos as $nd): ?><tr><td><?= h($nd['criado_em']) ?></td><td><?= h($nd['titulo']) ?></td><td><?= h($nd['mensagem']) ?></td><td><?= h($nd['estado']) ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div>

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
