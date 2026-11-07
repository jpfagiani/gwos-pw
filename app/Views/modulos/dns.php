<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">DNS</h1>
        <p class="text-secondary small mb-0">
            Módulo <code>20-dns-bind9</code> — resolver da rede, com bloqueio por
            domínio (RPZ) e encaminhamento dos domínios internos.
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

<?php if ($aviso): ?>
    <div class="alert alert-success py-2"><?= h($aviso) ?></div>
<?php endif; ?>
<?php if ($erro): ?>
    <div class="alert alert-danger py-2"><pre class="mb-0 small"><?= h($erro) ?></pre></div>
<?php endif; ?>

<?php if (!$rpzAtiva): ?>
    <div class="alert alert-warning py-2">
        <strong>RPZ não está ativa.</strong> A zona de bloqueio existe mas a
        diretiva <code>response-policy</code> não está em
        <code>named.conf.options</code> — nenhum domínio é bloqueado por DNS.
        Reinstale o módulo <code>20-dns-bind9</code> para corrigir.
    </div>
<?php endif; ?>

<!-- ================= Resolvers upstream ================= -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Resolvers upstream</h2>
        <button class="btn btn-sm btn-outline-secondary" id="btnTestarFwd">
            <i class="bi bi-activity"></i> Testar todos
        </button>
    </div>
    <div class="card-body">
        <p class="text-secondary small">
            Para onde vai tudo que não é domínio interno. A ordem é uma dica: o
            BIND escolhe pelo tempo de resposta, não pela posição.
        </p>

        <div class="alert alert-light border small">
            <i class="bi bi-exclamation-triangle text-warning"></i>
            O BIND só tenta o próximo resolver em <strong>timeout</strong> ou
            <strong>SERVFAIL</strong>. <code>NXDOMAIN</code> é resposta válida e
            encerra a busca — um resolver interno que negue domínios externos
            derruba a internet da rede sem aviso. Use <em>Testar todos</em> antes
            de confiar na lista.
        </div>

        <div id="resultadoFwd" class="mb-3"></div>

        <form method="post" action="/modulos/dns/forwarders">
            <?= csrf_field() ?>
            <label class="form-label small">Um por linha, ou separados por espaço</label>
            <textarea class="form-control font-monospace" name="forwarders" rows="3"
                      placeholder="10.14.8.20&#10;8.8.8.8"><?= h(implode("\n", $forwarders)) ?></textarea>
            <div class="d-flex justify-content-between align-items-center mt-2">
                <span class="text-secondary small">
                    Ao salvar, o <code>gwos-integrar</code> regera a configuração e recarrega o BIND9.
                </span>
                <button class="btn btn-primary btn-sm">Salvar resolvers</button>
            </div>
        </form>
    </div>
</div>

<!-- ================= Zonas ================= -->
<div class="card mb-4">
    <div class="card-header"><h2 class="h6 mb-0">Zonas de encaminhamento</h2></div>
    <div class="card-body">
        <p class="text-secondary small">
            Domínio inteiro enviado a um DNS específico, sem passar pelos
            resolvers acima. É o que mantém os sistemas da intranet funcionando
            de forma determinística.
        </p>

        <?php if ($zonasProjeto !== []): ?>
            <h3 class="h6 text-secondary small text-uppercase mt-3">Do projeto (somente leitura)</h3>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <?php foreach ($zonasProjeto as $zona): ?>
                        <tr>
                            <td style="width: 20rem"><code><?= h($zona['dominio']) ?></code></td>
                            <td class="font-monospace small"><?= h(implode(', ', $zona['forwarders'])) ?></td>
                            <td class="text-end">
                                <span class="badge text-bg-light text-secondary">named.conf.local</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-secondary small">
                Versionadas junto com o módulo. Para alterá-las, edite
                <code>modulos/20-dns-bind9/config/named.conf.local</code> no repositório.
            </p>
        <?php endif; ?>

        <h3 class="h6 text-secondary small text-uppercase mt-4">Suas zonas</h3>
        <?php if ($zonas === []): ?>
            <p class="text-secondary small">Nenhuma zona própria cadastrada.</p>
        <?php else: ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <?php foreach ($zonas as $zona): ?>
                        <tr>
                            <td style="width: 20rem"><code><?= h($zona['dominio']) ?></code></td>
                            <td class="font-monospace small"><?= h(implode(', ', $zona['forwarders'])) ?></td>
                            <td class="text-end">
                                <form method="post" action="/modulos/dns/zonas/<?= h(rawurlencode($zona['dominio'])) ?>/remover"
                                      onsubmit="return confirm('Remover a zona <?= h($zona['dominio']) ?>?')">
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

        <form method="post" action="/modulos/dns/zonas" class="row g-2 align-items-end mt-2">
            <?= csrf_field() ?>
            <div class="col-md-5">
                <label class="form-label small">Domínio</label>
                <input class="form-control form-control-sm font-monospace" name="dominio"
                       placeholder="sistema.sp.gov.br" required>
            </div>
            <div class="col-md-5">
                <label class="form-label small">DNS que responde por ele</label>
                <input class="form-control form-control-sm font-monospace" name="forwarders"
                       placeholder="10.1.6.222 10.14.8.16" required>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-sm btn-primary">Adicionar</button>
            </div>
        </form>
        <p class="text-secondary small mt-2 mb-0">
            Vai para <code>/etc/bind/named.conf.zonas-locais</code>, que sobrevive
            a reinstalações do módulo. Se o <code>named-checkconf</code> reprovar,
            a alteração é desfeita sozinha.
        </p>
    </div>
</div>

<!-- ================= Diagnóstico ================= -->
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0">Testar resolução</h2></div>
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label small">Nome</label>
                        <input class="form-control form-control-sm font-monospace" id="testeNome"
                               value="google.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Servidor</label>
                        <input class="form-control form-control-sm font-monospace" id="testeServidor"
                               value="127.0.0.1">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button class="btn btn-sm btn-outline-primary" id="btnTestar">Resolver</button>
                    </div>
                </div>
                <pre class="bg-body-tertiary border rounded p-2 mt-3 mb-0 small" id="testeSaida"
                     style="min-height: 5rem">Nenhum teste executado.</pre>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0">Situação</h2></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-7">Domínios bloqueados (RPZ)</dt>
                    <dd class="col-5 text-end"><?= (int) $bloqueados ?></dd>

                    <dt class="col-7">RPZ ativa</dt>
                    <dd class="col-5 text-end">
                        <?= $rpzAtiva ? '<span class="text-success">sim</span>'
                                      : '<span class="text-danger">não</span>' ?>
                    </dd>

                    <dt class="col-7">Domínio interno</dt>
                    <dd class="col-5 text-end"><code><?= h($dominioLocal ?: '—') ?></code></dd>

                    <dt class="col-7">Nomes internos</dt>
                    <dd class="col-5 text-end">
                        <?php if ($temDnsInterno): ?>
                            <a href="/modulos/nomes">gerenciar</a>
                        <?php else: ?>
                            <span class="text-secondary">módulo 25 ausente</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const saida = document.getElementById('testeSaida');

    document.getElementById('btnTestar').addEventListener('click', async () => {
        const nome     = document.getElementById('testeNome').value.trim();
        const servidor = document.getElementById('testeServidor').value.trim();
        saida.textContent = 'Consultando...';
        try {
            const r = await fetch('/modulos/dns/testar?nome=' + encodeURIComponent(nome)
                                + '&servidor=' + encodeURIComponent(servidor));
            const d = await r.json();
            saida.textContent = d.ok
                ? d.respostas.join('\n')
                : (d.erro || 'Sem resposta.') + (d.respostas?.length ? '\n' + d.respostas.join('\n') : '');
        } catch (e) {
            saida.textContent = 'Falha ao consultar: ' + e.message;
        }
    });

    document.getElementById('btnTestarFwd').addEventListener('click', async (ev) => {
        const alvo = document.getElementById('resultadoFwd');
        ev.target.disabled = true;
        alvo.innerHTML = '<span class="text-secondary small">Testando cada resolver com google.com...</span>';
        try {
            const r = await fetch('/modulos/dns/testar-forwarders');
            const d = await r.json();
            alvo.innerHTML = d.resultado.map(f =>
                '<span class="badge me-1 ' + (f.ok ? 'text-bg-success' : 'text-bg-danger') + '">'
                + f.ip + (f.ok ? ' ok' : ' falhou') + '</span>'
            ).join('') || '<span class="text-secondary small">Nenhum resolver configurado.</span>';
            if (d.resultado.some(f => !f.ok)) {
                alvo.innerHTML += '<div class="text-danger small mt-2">'
                    + 'Um resolver que não resolve domínio externo pode derrubar a internet da rede. '
                    + 'Considere removê-lo da lista.</div>';
            }
        } catch (e) {
            alvo.innerHTML = '<span class="text-danger small">Falha: ' + e.message + '</span>';
        } finally {
            ev.target.disabled = false;
        }
    });
})();
</script>
