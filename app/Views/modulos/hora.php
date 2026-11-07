<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Hora</h1>
        <p class="text-secondary small mb-0">
            Módulo <code>30-hora-chrony</code> — mantém o relógio deste servidor
            certo e serve a hora para as máquinas da rede.
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

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0">Fontes de hora</h2></div>
            <div class="card-body">
                <form method="post" action="/modulos/hora">
                    <?= csrf_field() ?>

                    <label class="form-label small">Servidores preferidos</label>
                    <textarea class="form-control font-monospace" name="servidores" rows="2"
                              placeholder="10.14.8.20"><?= h(implode("\n", $servidores)) ?></textarea>
                    <div class="form-text small">
                        O servidor interno da rede. Se for o controlador de domínio,
                        é dele que o relógio das estações precisa se aproximar.
                    </div>

                    <label class="form-label small mt-3">Reserva (pool público)</label>
                    <textarea class="form-control font-monospace" name="pool" rows="2"
                              placeholder="pool.ntp.br"><?= h(implode("\n", $pool)) ?></textarea>
                    <div class="form-text small">
                        Entra em cena se a fonte interna cair — evita o relógio derivar.
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <span class="text-secondary small">
                            O chrony leva alguns minutos para estabilizar após a troca.
                        </span>
                        <button class="btn btn-primary btn-sm">Salvar fontes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0">Sincronização</h2></div>
            <div class="card-body">
                <?php if (!$temChronyc): ?>
                    <p class="text-secondary small mb-0">chronyc não disponível.</p>
                <?php elseif ($tracking === []): ?>
                    <p class="text-secondary small mb-0">
                        O chrony não respondeu. Verifique se o serviço está ativo.
                    </p>
                <?php else: ?>
                    <dl class="row mb-0 small">
                        <dt class="col-6">Referência</dt>
                        <dd class="col-6 text-end"><?= h($tracking['Reference ID'] ?? '—') ?></dd>

                        <dt class="col-6">Estrato</dt>
                        <dd class="col-6 text-end"><?= h($tracking['Stratum'] ?? '—') ?></dd>

                        <dt class="col-6">Desvio do relógio</dt>
                        <dd class="col-6 text-end"><?= h($tracking['System time'] ?? '—') ?></dd>

                        <dt class="col-6">Última atualização</dt>
                        <dd class="col-6 text-end"><?= h($tracking['Last offset'] ?? '—') ?></dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0">Fontes em uso</h2></div>
            <div class="card-body">
                <?php if ($fontes === []): ?>
                    <p class="text-secondary small mb-0">Nenhuma fonte reportada ainda.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr class="small text-secondary">
                                    <th>Fonte</th><th>Estado</th><th>Estrato</th><th class="text-end">Última</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($fontes as $fonte): ?>
                                <tr>
                                    <td class="font-monospace small"><?= h($fonte['nome']) ?></td>
                                    <td>
                                        <span class="badge <?= $fonte['estado'] === 'em uso' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                            <?= h($fonte['estado']) ?>
                                        </span>
                                    </td>
                                    <td><?= h($fonte['estrato']) ?></td>
                                    <td class="text-end small"><?= h($fonte['ultimo']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Quem sincroniza aqui</h2>
                <button class="btn btn-sm btn-outline-secondary" id="btnClientes">Consultar</button>
            </div>
            <div class="card-body">
                <p class="text-secondary small">
                    Redes autorizadas:
                    <?php if ($redes === []): ?>
                        <span class="text-danger">nenhuma — a rede não consegue sincronizar</span>
                    <?php else: ?>
                        <?php foreach ($redes as $rede): ?>
                            <code class="me-1"><?= h($rede) ?></code>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </p>
                <pre class="bg-body-tertiary border rounded p-2 mb-0 small" id="saidaClientes"
                     style="min-height: 6rem">Clique em Consultar.</pre>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('btnClientes').addEventListener('click', async (ev) => {
    const alvo = document.getElementById('saidaClientes');
    ev.target.disabled = true;
    alvo.textContent = 'Consultando...';
    try {
        const r = await fetch('/modulos/hora/clientes');
        const d = await r.json();
        alvo.textContent = d.saida || 'Sem resposta.';
    } catch (e) {
        alvo.textContent = 'Falha: ' + e.message;
    } finally {
        ev.target.disabled = false;
    }
});
</script>
