<?php $titulo = 'Grupos & IPs'; $modulo = 'grupos'; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Grupos de IPs</h5>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" onclick="aplicarNftables()">
            <i class="bi bi-play-fill me-1"></i>Aplicar nftables
        </button>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalGrupo">
            <i class="bi bi-plus-lg me-1"></i>Novo Grupo
        </button>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th>Descrição</th>
                    <th class="text-center">IPs</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grupos)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">Nenhum grupo cadastrado.</td></tr>
                <?php else: ?>
                    <?php
                    $cores = ['bloqueado' => 'danger', 'parcial' => 'warning', 'liberado' => 'success', 'isento' => 'info'];
                    foreach ($grupos as $g): ?>
                        <tr>
                            <td class="fw-semibold"><?= h($g['nome']) ?></td>
                            <td>
                                <span class="badge bg-<?= $cores[$g['tipo']] ?? 'secondary' ?>">
                                    <?= h(ucfirst($g['tipo'])) ?>
                                </span>
                            </td>
                            <td class="text-muted small"><?= h($g['descricao'] ?? '') ?></td>
                            <td class="text-center">
                                <button id="btn-ips-<?= $g['id'] ?>" class="btn btn-sm btn-outline-primary" onclick="verIps(<?= $g['id'] ?>, '<?= h($g['nome']) ?>')">
                                    <i class="bi bi-pc-display me-1"></i><span class="ip-count"><?= (int)$g['total_ips'] ?></span>
                                </button>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-danger"
                                        onclick="removerGrupo(<?= $g['id'] ?>, '<?= h($g['nome']) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Criar Grupo -->
<div class="modal fade" id="modalGrupo" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Novo Grupo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formGrupo">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <div id="erroGrupo" class="alert alert-danger d-none"></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nome</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tipo de acesso</label>
                        <select name="tipo" class="form-select" required>
                            <option value="">Selecione...</option>
                            <option value="bloqueado">Bloqueado</option>
                            <option value="parcial">Parcial</option>
                            <option value="liberado">Liberado (sem proxy, sem registro)</option>
                            <option value="isento">Isento de login (passa pelo proxy, com registro)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Descrição</label>
                        <textarea name="descricao" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal IPs -->
<div class="modal fade" id="modalIps" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tituloModalIps">IPs do Grupo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formIp" class="row g-2 mb-3 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="grupo_id" id="ipGrupoId">
                    <div class="col-md-4"><label class="form-label small">IP ou rede</label><input type="text" name="endereco" class="form-control form-control-sm" placeholder="192.168.1.10" required></div>
                    <div class="col-md-3"><label class="form-label small">Descrição</label><input type="text" name="descricao" class="form-control form-control-sm" placeholder="Opcional"></div>
                    <div class="col-md-3"><label class="form-label small">Expiração opcional</label><input type="datetime-local" name="expira_em" class="form-control form-control-sm"></div>
                    <div class="col-md-2"><button type="submit" class="btn btn-sm btn-primary text-nowrap w-100">
                        <i class="bi bi-plus-lg"></i> Adicionar
                    </button></div>
                </form>
                <div id="erroIp" class="alert alert-danger d-none small"></div>
                <div id="listaIps"></div>
            </div>
        </div>
    </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const escaparHtml = valor => String(valor ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

document.getElementById('formGrupo').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await fetch('/grupos', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.erro) {
        document.getElementById('erroGrupo').textContent = data.erro;
        document.getElementById('erroGrupo').classList.remove('d-none');
    } else {
        location.reload();
    }
});

async function removerGrupo(id, nome) {
    if (!confirm(`Remover grupo "${nome}" e todos os seus IPs?`)) return;
    const fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('_method', 'DELETE');
    const res = await fetch('/grupos/' + id, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.erro) { alert(data.erro); } else { location.reload(); }
}

async function verIps(id, nome) {
    document.getElementById('tituloModalIps').textContent = 'IPs — ' + nome;
    document.getElementById('ipGrupoId').value = id;
    await carregarIps(id);
    new bootstrap.Modal(document.getElementById('modalIps')).show();
}

async function carregarIps(id) {
    const res = await fetch('/grupos/' + id + '/ips');
    const data = await res.json();
    const lista = document.getElementById('listaIps');
    if (!data.ips || data.ips.length === 0) {
        lista.innerHTML = '<p class="text-muted small">Nenhum IP cadastrado.</p>';
        return;
    }
    lista.innerHTML = '<table class="table table-sm align-middle"><thead><tr><th>Status</th><th>IP</th><th>Descrição</th><th></th></tr></thead><tbody>' +
        data.ips.map(ip =>
            `<tr>
              <td><span class="badge rounded-pill" style="background:#198754;font-size:.7rem">● ativo</span></td>
              <td><span class="font-monospace fw-semibold text-primary">${escaparHtml(ip.endereco)}</span></td>
              <td class="text-muted small">${escaparHtml(ip.descricao || '')}${ip.expira_em ? `<div class="text-warning">Expira: ${escaparHtml(ip.expira_em)}</div>` : ''}</td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-secondary" title="Remover" onclick="removerIp(${ip.id}, '${ip.endereco}', ${id})">
                  <i class="bi bi-trash3"></i>
                </button>
              </td>
            </tr>`
        ).join('') + '</tbody></table>';
}

document.getElementById('formIp').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const gid = document.getElementById('ipGrupoId').value;
    const res = await fetch('/grupos/' + gid + '/ips', { method: 'POST', body: fd });
    const data = await res.json();
    const erroEl = document.getElementById('erroIp');
    if (data.erro) {
        erroEl.textContent = data.erro;
        erroEl.classList.remove('d-none');
    } else {
        erroEl.classList.add('d-none');
        e.target.reset();
        document.getElementById('ipGrupoId').value = gid;
        await carregarIps(gid);
        atualizarContador(gid);
    }
});

async function removerIp(id, endereco, grupoId) {
    if (!confirm(`Remover IP ${endereco}?`)) return;
    const fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('_method', 'DELETE');
    const res = await fetch('/ips/' + id, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.erro) { alert(data.erro); } else {
        await carregarIps(grupoId);
        atualizarContador(grupoId);
    }
}

async function atualizarContador(gid) {
    const res = await fetch('/grupos/' + gid + '/ips');
    const data = await res.json();
    const btn = document.getElementById('btn-ips-' + gid);
    if (btn) btn.querySelector('.ip-count').textContent = data.ips ? data.ips.length : 0;
}

async function aplicarNftables() {
    if (!confirm('Reaplicar regras nftables agora?')) return;
    const fd = new FormData();
    fd.append('_csrf', csrf);
    const res = await fetch('/grupos/aplicar', { method: 'POST', body: fd });
    const data = await res.json();
    alert(data.sucesso || data.erro);
}
</script>
