<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Firewall</h1>
        <p class="text-secondary small mb-0">
            Módulo <code>40-firewall-nftables</code> — filtro, NAT e os
            redirecionamentos que levam a rede ao proxy e ao DNS local.
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

<div class="alert alert-light border small">
    <i class="bi bi-info-circle"></i>
    As regras não são editadas aqui — elas são <strong>derivadas</strong> do
    <code>gwos.conf</code>, dos módulos instalados e, quando há banco, dos
    grupos de IPs e do NAT 1:1. Um formulário de regra crua viraria uma segunda
    fonte de verdade, e a primeira regeração a apagaria. Para mudar o
    comportamento, mude a origem e regere.
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0">O que está valendo, e por quê</h2></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                        <?php foreach ($decisoes as $d): ?>
                            <tr>
                                <td style="width: 2rem">
                                    <i class="bi <?= $d['situacao'] ? 'bi-check-circle-fill text-success' : 'bi-dash-circle text-secondary' ?>"></i>
                                </td>
                                <td><?= h($d['item']) ?></td>
                                <td class="text-secondary small"><?= h($d['detalhe']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0">Situação</h2></div>
            <div class="card-body">
                <dl class="row mb-3 small">
                    <dt class="col-7">Interface WAN</dt>
                    <dd class="col-5 text-end"><code><?= h($ifaceWan) ?></code></dd>

                    <dt class="col-7">Interface LAN</dt>
                    <dd class="col-5 text-end"><code><?= h($ifaceLan) ?></code></dd>

                    <dt class="col-7">Encaminhamento</dt>
                    <dd class="col-5 text-end">
                        <?= $encaminhamento ? '<span class="text-success">ativo</span>'
                                            : '<span class="text-danger">desligado</span>' ?>
                    </dd>

                    <dt class="col-7">Sobrevive ao reboot</dt>
                    <dd class="col-5 text-end">
                        <?= $persistente ? '<span class="text-success">sim</span>'
                                         : '<span class="text-danger">não</span>' ?>
                    </dd>

                    <dt class="col-7">Grupos do banco</dt>
                    <dd class="col-5 text-end">
                        <?= $temBanco ? '<span class="text-success">sim</span>'
                                      : '<span class="text-secondary">sem banco</span>' ?>
                    </dd>
                </dl>

                <form method="post" action="/modulos/firewall/regerar" class="d-grid"
                      onsubmit="return confirm('Regerar e aplicar as regras agora?')">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-primary">Regerar regras</button>
                </form>
                <button class="btn btn-sm btn-outline-primary w-100 mt-2" id="btnPreviewFirewall" type="button">Validar e comparar alterações</button>
                <div id="previewFirewall" class="mt-2 d-none"><div id="estadoPreview" class="small mb-1"></div><pre id="diffFirewall" class="bg-body-tertiary border rounded p-2 small" style="max-height:18rem;overflow:auto;white-space:pre-wrap"></pre></div>
                <p class="text-secondary small mt-2 mb-0">
                    A prévia executa <code>nft -c</code> e mostra o diff sem alterar regras. Na aplicação, o estado anterior é versionado e restaurado se a pós-checagem falhar.
                </p>
            </div>
        </div>
    </div>
</div>

<?php if ($redesRoteadas !== []): ?>
<div class="card mb-4">
    <div class="card-header"><h2 class="h6 mb-0">Sub-redes roteadas (não interceptadas)</h2></div>
    <div class="card-body">
        <p class="text-secondary small">
            Tráfego para estas redes é roteado direto, sem passar pelo proxy —
            inclusive faixas fora do RFC 1918. A lista vem das rotas da interface
            LAN, então acompanha os aliases sem depender de configuração.
        </p>
        <?php foreach ($redesRoteadas as $rede): ?>
            <span class="badge text-bg-light text-dark border me-1 font-monospace"><?= h($rede) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Regras em vigor</h2>
        <button class="btn btn-sm btn-outline-secondary" id="btnAlternar">Mostrar</button>
    </div>
    <div class="card-body d-none" id="blocoRuleset">
        <?php if ($rulesetErro): ?>
            <div class="alert alert-danger py-2 small mb-0"><?= h($rulesetErro) ?></div>
        <?php else: ?>
            <pre class="bg-body-tertiary border rounded p-2 mb-0 small"
                 style="max-height: 30rem; overflow: auto"><?= h($ruleset) ?></pre>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('btnAlternar').addEventListener('click', (ev) => {
    const bloco = document.getElementById('blocoRuleset');
    const oculto = bloco.classList.toggle('d-none');
    ev.target.textContent = oculto ? 'Mostrar' : 'Ocultar';
});
document.getElementById('btnPreviewFirewall').addEventListener('click', async ev => {
    const area=document.getElementById('previewFirewall'),estado=document.getElementById('estadoPreview'),diff=document.getElementById('diffFirewall');
    area.classList.remove('d-none');estado.textContent='Gerando diff e validando ruleset…';diff.textContent='';ev.currentTarget.disabled=true;
    try {
        const csrf=document.querySelector('form[action="/modulos/firewall/regerar"] input[name="_csrf"]').value;
        const body=new FormData();body.append('_csrf',csrf);
        const res=await fetch('/modulos/firewall/preview',{method:'POST',body,headers:{'Accept':'application/json'}});
        const data=await res.json();if(!res.ok)throw new Error(data.erro||'Falha ao validar prévia.');
        estado.textContent=data.validado?'Prévia validada; nenhuma regra foi aplicada.':'Prévia gerada; confira o resultado.';diff.textContent=data.diff||'Sem diferenças.';
    } catch(e) {estado.textContent=e.message||'Falha ao gerar prévia.';}
    finally {ev.currentTarget.disabled=false;}
});
</script>
