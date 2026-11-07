<?php $titulo = 'Horários'; $modulo = 'horarios';
$diasNomes = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
function formatarDias(string $dias): string {
    global $diasNomes;
    $out = [];
    for ($i = 0; $i < 7; $i++) {
        if (isset($dias[$i]) && $dias[$i] === '1') {
            $out[] = '<span class="badge bg-primary">' . $diasNomes[$i] . '</span>';
        }
    }
    return implode(' ', $out);
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Horários de Acesso</h5>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalHorario">
        <i class="bi bi-plus-lg me-1"></i>Novo Horário
    </button>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nome</th>
                    <th>Grupo</th>
                    <th>Dias</th>
                    <th>Horário</th>
                    <th>Ação</th>
                    <th class="text-end">Remover</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($horarios)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Nenhum horário cadastrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($horarios as $h): ?>
                        <tr>
                            <td class="fw-semibold"><?= h($h['nome']) ?></td>
                            <td><?= $h['grupo_nome'] ? h($h['grupo_nome']) : '<span class="text-muted">Todos</span>' ?></td>
                            <td><?= formatarDias($h['dias_semana']) ?></td>
                            <td>
                                <code><?= h(substr($h['hora_inicio'],0,5)) ?> — <?= h(substr($h['hora_fim'],0,5)) ?></code>
                            </td>
                            <td>
                                <span class="badge bg-<?= $h['acao'] === 'permitir' ? 'success' : 'danger' ?>">
                                    <?= h(ucfirst($h['acao'])) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-danger"
                                        onclick="removerHorario(<?= $h['id'] ?>, '<?= h($h['nome']) ?>')">
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

<!-- Modal -->
<div class="modal fade" id="modalHorario" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Novo Horário</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formHorario">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <div id="erroHorario" class="alert alert-danger d-none"></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nome</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Grupo (opcional)</label>
                        <select name="grupo_id" class="form-select">
                            <option value="">Todos os grupos</option>
                            <?php foreach ($grupos as $g): ?>
                                <option value="<?= $g['id'] ?>"><?= h($g['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Dias da semana</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php foreach ($diasNomes as $i => $dn): ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="dias[]" value="<?= $i ?>"
                                           id="dia<?= $i ?>" checked>
                                    <label class="form-check-label" for="dia<?= $i ?>"><?= $dn ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col">
                            <label class="form-label fw-semibold">Início</label>
                            <input type="time" name="hora_inicio" class="form-control" required>
                        </div>
                        <div class="col">
                            <label class="form-label fw-semibold">Fim</label>
                            <input type="time" name="hora_fim" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ação</label>
                        <select name="acao" class="form-select">
                            <option value="bloquear">Bloquear</option>
                            <option value="permitir">Permitir</option>
                        </select>
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

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;

document.getElementById('formHorario').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    // Constrói dias_semana como string de 7 bits
    let dias = '0000000';
    const checados = e.target.querySelectorAll('input[name="dias[]"]:checked');
    checados.forEach(cb => {
        const idx = parseInt(cb.value);
        dias = dias.substring(0, idx) + '1' + dias.substring(idx + 1);
    });
    fd.delete('dias[]');
    fd.set('dias_semana', dias);

    const res = await fetch('/horarios', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.erro) {
        document.getElementById('erroHorario').textContent = data.erro;
        document.getElementById('erroHorario').classList.remove('d-none');
    } else {
        location.reload();
    }
});

async function removerHorario(id, nome) {
    if (!confirm(`Remover horário "${nome}"?`)) return;
    const fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('_method', 'DELETE');
    const res = await fetch('/horarios/' + id, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.erro) { alert(data.erro); } else { location.reload(); }
}
</script>
