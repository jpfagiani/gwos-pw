<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="text-muted mb-0">Mapeamento 1:1 entre IPs públicos e IPs internos da rede LAN.</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" onclick="aplicarTodas()">
            <i class="bi bi-arrow-repeat me-1"></i> Reaplicar Todas
        </button>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNova">
            <i class="bi bi-plus-lg me-1"></i> Nova Regra
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>IP Externo (WAN)</th>
                    <th>IP Interno (LAN)</th>
                    <th>Descrição</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$entradas): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        Nenhuma regra NAT 1:1 cadastrada.
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($entradas as $e): ?>
                <tr>
                    <td><code><?= h($e['ip_externo']) ?></code></td>
                    <td><code><?= h($e['ip_interno']) ?></code></td>
                    <td class="text-muted small"><?= h($e['descricao'] ?? '—') ?></td>
                    <td>
                        <?php if ($e['ativo']): ?>
                            <span class="badge bg-success">Ativo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm <?= $e['ativo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                onclick="toggleNat(<?= $e['id'] ?>, <?= $e['ativo'] ? 'true' : 'false' ?>)">
                            <i class="bi <?= $e['ativo'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i>
                            <?= $e['ativo'] ? 'Desativar' : 'Ativar' ?>
                        </button>
                        <button class="btn btn-sm btn-outline-danger"
                                data-confirmar="Remover NAT <?= h($e['ip_externo']) ?> → <?= h($e['ip_interno']) ?>?"
                                onclick="removerNat(<?= $e['id'] ?>)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal nova regra -->
<div class="modal fade" id="modalNova" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="formNova">
                <div class="modal-header">
                    <h5 class="modal-title">Nova Regra NAT 1:1</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">IP Externo (WAN) <span class="text-danger">*</span></label>
                        <input type="text" name="ip_externo" class="form-control"
                               placeholder="Ex: 200.100.10.5" required>
                        <div class="form-text">IP público que será mapeado para um host interno.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">IP Interno (LAN) <span class="text-danger">*</span></label>
                        <input type="text" name="ip_interno" class="form-control"
                               placeholder="Ex: 192.168.1.50" required>
                        <div class="form-text">Host na rede interna que receberá o tráfego.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <input type="text" name="descricao" class="form-control"
                               placeholder="Ex: Servidor Web Principal">
                    </div>
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        A regra será criada <strong>inativa</strong>. Clique em <strong>Ativar</strong> quando quiser aplicá-la.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar Regra</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const CSRF_NAT = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

document.getElementById('formNova').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('_csrf', CSRF_NAT);
    const r = await fetch('/nat', { method: 'POST', body: fd });
    const j = await r.json();
    if (j.erro) { mostrarAlerta(j.erro, 'danger'); return; }
    mostrarAlerta(j.sucesso, 'success');
    bootstrap.Modal.getInstance(document.getElementById('modalNova')).hide();
    setTimeout(() => location.reload(), 800);
});

async function toggleNat(id, ativo) {
    const msg = ativo ? 'Desativar esta regra NAT?' : 'Ativar esta regra NAT?';
    if (!confirm(msg)) return;
    const fd = new FormData();
    fd.append('_csrf', CSRF_NAT);
    const r = await fetch(`/nat/${id}/toggle`, { method: 'POST', body: fd });
    const j = await r.json();
    if (j.erro) { mostrarAlerta(j.erro, 'danger'); return; }
    mostrarAlerta(j.sucesso, 'success');
    setTimeout(() => location.reload(), 800);
}

async function removerNat(id) {
    if (!confirm('Remover esta regra NAT? As regras ativas serão desativadas no firewall.')) return;
    const r = await fetch(`/nat/${id}`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_csrf=' + encodeURIComponent(CSRF_NAT)
    });
    const j = await r.json();
    if (j.erro) { mostrarAlerta(j.erro, 'danger'); return; }
    mostrarAlerta(j.sucesso, 'success');
    setTimeout(() => location.reload(), 800);
}

async function aplicarTodas() {
    if (!confirm('Reaplicar todas as regras nftables (NAT + firewall)?')) return;
    const fd = new FormData();
    fd.append('_csrf', CSRF_NAT);
    const r = await fetch('/nat/aplicar', { method: 'POST', body: fd });
    const j = await r.json();
    mostrarAlerta(j.sucesso ?? j.erro, j.erro ? 'danger' : 'success');
}
</script>
