<?php $titulo = 'Domínios'; $modulo = 'dominios';
$whitelist = array_filter($dominios, fn($d) => $d['tipo'] === 'whitelist');
$blacklist  = array_filter($dominios, fn($d) => $d['tipo'] === 'blacklist');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Domínios</h5>
    <button class="btn btn-sm btn-outline-secondary" onclick="aplicarDominios()">
        <i class="bi bi-play-fill me-1"></i>Aplicar listas
    </button>
</div>

<ul class="nav nav-tabs mb-3" id="tabDominios">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabWhite">
            <i class="bi bi-check-circle-fill text-success me-1"></i>
            Whitelist <span class="badge bg-success"><?= count($whitelist) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabBlack">
            <i class="bi bi-x-circle-fill text-danger me-1"></i>
            Blacklist <span class="badge bg-danger"><?= count($blacklist) ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">
    <?php foreach ([['tabWhite','whitelist','success','Whitelist'],['tabBlack','blacklist','danger','Blacklist']] as [$tabId, $tipo, $cor, $label]): ?>
    <div class="tab-pane fade <?= $tabId === 'tabWhite' ? 'show active' : '' ?>" id="<?= $tabId ?>">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex flex-column gap-2">
                <form class="d-flex gap-2" onsubmit="adicionarDominio(event, '<?= $tipo ?>')">
                    <input type="hidden" name="tipo" value="<?= $tipo ?>">
                    <input type="text" name="dominio" class="form-control form-control-sm"
                           placeholder="exemplo.com ou *.exemplo.com" required>
                    <button type="submit" class="btn btn-sm btn-<?= $cor ?> text-nowrap">
                        <i class="bi bi-plus-lg"></i> Adicionar à <?= $label ?>
                    </button>
                </form>
                <div id="erro-<?= $tipo ?>" class="text-danger small d-none"></div>
                <input type="search" class="form-control form-control-sm busca-dominio"
                       data-tabela="tbl-<?= $tipo ?>"
                       placeholder="Buscar domínio na <?= $label ?>…">
            </div>
            <div class="table-responsive">
                <table class="table table-hover small mb-0" id="tbl-<?= $tipo ?>">
                    <thead class="table-light">
                        <tr><th>Domínio</th><th>Origem</th><th class="text-end">Ações</th></tr>
                    </thead>
                    <tbody>
                        <?php $lista = ($tipo === 'whitelist') ? $whitelist : $blacklist; ?>
                        <?php if (empty($lista)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">Nenhum domínio.</td></tr>
                        <?php else: ?>
                            <?php foreach ($lista as $d): ?>
                                <tr>
                                    <td><code><?= h($d['dominio']) ?></code></td>
                                    <td><span class="badge bg-secondary"><?= h($d['origem']) ?></span></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-danger"
                                                onclick="removerDominio(<?= $d['id'] ?>, '<?= h($d['dominio']) ?>')">
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
    </div>
    <?php endforeach; ?>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;

async function adicionarDominio(e, tipo) {
    e.preventDefault();
    const form = e.target;
    const fd = new FormData(form);
    fd.append('_csrf', csrf);
    const res = await fetch('/dominios', { method: 'POST', body: fd });
    const data = await res.json();
    const erroEl = document.getElementById('erro-' + tipo);
    if (data.erro) {
        erroEl.textContent = data.erro;
        erroEl.classList.remove('d-none');
    } else {
        location.reload();
    }
}

async function removerDominio(id, dominio) {
    if (!confirm(`Remover "${dominio}"?`)) return;
    const fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('_method', 'DELETE');
    const res = await fetch('/dominios/' + id, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.erro) { alert(data.erro); } else { location.reload(); }
}

document.querySelectorAll('.busca-dominio').forEach(input => {
    input.addEventListener('input', function() {
        const termo = this.value.toLowerCase();
        const tabela = document.getElementById(this.dataset.tabela);
        tabela.querySelectorAll('tbody tr').forEach(tr => {
            const texto = tr.querySelector('code')?.textContent.toLowerCase() ?? '';
            tr.style.display = texto.includes(termo) ? '' : 'none';
        });
    });
});

async function aplicarDominios() {
    if (!confirm('Reaplicar listas de domínios agora?')) return;
    const fd = new FormData();
    fd.append('_csrf', csrf);
    const res = await fetch('/dominios/aplicar', { method: 'POST', body: fd });
    const data = await res.json();
    alert(data.sucesso || data.erro);
}
</script>
