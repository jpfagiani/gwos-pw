<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Proxy</h1>
        <p class="text-secondary small mb-0">
            Módulo <code>50-proxy-squid</code> — filtro de conteúdo HTTP e HTTPS.
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

<?php if (!$temFirewall): ?>
    <div class="alert alert-warning py-2">
        <strong>Módulo 40-firewall-nftables ausente.</strong>
        Sem ele não há interceptação transparente — só funciona o proxy
        explícito, configurado no navegador de cada máquina.
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0">Portas de escuta</h2></div>
            <div class="card-body">
                <p class="text-secondary small">
                    As mesmas portas vão para o Squid e para o redirecionamento do
                    firewall — por isso saem de um lugar só. Mudar aqui regera os
                    dois lados juntos.
                </p>
                <form method="post" action="/modulos/proxy/portas" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-md-4">
                        <label class="form-label small">Explícito</label>
                        <input class="form-control form-control-sm font-monospace" name="porta_fwd"
                               value="<?= h($portas['explícito (forward-proxy)']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">HTTP transparente</label>
                        <input class="form-control form-control-sm font-monospace" name="porta_http"
                               value="<?= h($portas['HTTP transparente']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">HTTPS (SSL Bump)</label>
                        <input class="form-control form-control-sm font-monospace" name="porta_ssl"
                               value="<?= h($portas['HTTPS (SSL Bump)']) ?>">
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                        <button class="btn btn-sm btn-primary">Salvar portas</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0">Inspeção de HTTPS</h2></div>
            <div class="card-body">
                <?php if ($sslBump): ?>
                    <p class="small mb-2">
                        <span class="badge text-bg-success">SSL Bump ativo</span>
                    </p>
                    <dl class="row small mb-3">
                        <dt class="col-6">CA válida até</dt>
                        <dd class="col-6 text-end"><?= h($validadeCa ?: '—') ?></dd>
                    </dl>
                    <a class="btn btn-sm btn-outline-primary" href="/gwos-ca.crt" download>
                        <i class="bi bi-download"></i> Baixar certificado da CA
                    </a>
                    <p class="text-secondary small mt-2 mb-0">
                        Instale nos navegadores como Autoridade Certificadora
                        Confiável, senão todo HTTPS interceptado dá alerta.
                    </p>
                <?php else: ?>
                    <p class="small mb-2"><span class="badge text-bg-secondary">SSL Bump desativado</span></p>
                    <p class="text-secondary small mb-0">
                        Sem <code>security_file_certgen</code> ou sem CA gerada, o
                        HTTPS passa sem inspeção — a blacklist só vale para HTTP.
                        Instale o <code>squid-openssl</code> e reexecute o módulo.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Listas e arquivos incluídos</h2>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" id="btnVerificar">Verificar configuração</button>
            <form method="post" action="/modulos/proxy/recarregar" class="d-inline">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-primary">Recarregar Squid</button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <p class="text-secondary small">
            O <code>squid.conf</code> inclui todos estes arquivos.
            <strong>Se qualquer um faltar, o Squid não sobe</strong> — por isso a
            lista mostra também os ausentes.
        </p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr class="small text-secondary">
                        <th>Arquivo</th><th>Conteúdo</th><th class="text-end">Entradas</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($listas as $lista): ?>
                    <tr class="<?= $lista['existe'] ? '' : 'table-danger' ?>">
                        <td class="font-monospace small"><?= h($lista['arquivo']) ?></td>
                        <td class="text-secondary small"><?= h($lista['descricao']) ?></td>
                        <td class="text-end">
                            <?php if ($lista['existe']): ?>
                                <?= (int) $lista['entradas'] ?>
                            <?php else: ?>
                                <span class="text-danger">ausente</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$temBanco): ?>
            <div class="alert alert-warning small mt-3 mb-0">
                Sem o módulo de banco as listas de grupos ficam vazias e a regra
                <code>http_access deny all</code> bloqueia todo mundo. Para usar o
                proxy isolado, libere a rede no <code>squid.conf</code>.
            </div>
        <?php endif; ?>
        <pre class="bg-body-tertiary border rounded p-2 mt-3 mb-0 small d-none"
             id="saidaVerificar" style="max-height: 20rem; overflow: auto"></pre>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="h6 mb-0">Últimos acessos</h2></div>
    <div class="card-body">
        <?php if (!$logLegivel): ?>
            <p class="text-secondary small mb-0">
                <code>/var/log/squid/access.log</code> ilegível. O usuário
                <code>www-data</code> precisa estar no grupo <code>proxy</code>:
                <code>usermod -aG proxy www-data &amp;&amp; systemctl restart php8.4-fpm</code>
            </p>
        <?php elseif ($acessos === []): ?>
            <p class="text-secondary small mb-0">Nenhum acesso registrado ainda.</p>
        <?php else: ?>
            <pre class="bg-body-tertiary border rounded p-2 mb-0 small"
                 style="max-height: 22rem; overflow: auto"><?= h(implode("\n", $acessos)) ?></pre>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('btnVerificar').addEventListener('click', async (ev) => {
    const alvo = document.getElementById('saidaVerificar');
    ev.target.disabled = true;
    alvo.classList.remove('d-none');
    alvo.textContent = 'Verificando...';
    try {
        const r = await fetch('/modulos/proxy/verificar');
        const d = await r.json();
        alvo.textContent = d.saida || (d.ok ? 'Configuração sem erros.' : 'Sem resposta.');
    } catch (e) {
        alvo.textContent = 'Falha: ' + e.message;
    } finally {
        ev.target.disabled = false;
    }
});
</script>
