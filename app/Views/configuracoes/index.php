<?php $titulo = 'Configurações'; $modulo = 'configuracoes'; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-gear me-1"></i> Parâmetros do Sistema
            </div>
            <div class="card-body">
                <form method="POST" action="/configuracoes">
                    <?= csrf_field() ?>

                    <?php if (empty($configuracoes)): ?>
                        <p class="text-muted">Nenhuma configuração cadastrada no banco.</p>
                    <?php else: ?>
                        <?php foreach ($configuracoes as $cfg): ?>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">
                                    <?= h($cfg['chave']) ?>
                                    <?php if ($cfg['descricao']): ?>
                                        <span class="text-muted fw-normal">— <?= h($cfg['descricao']) ?></span>
                                    <?php endif; ?>
                                </label>
                                <input type="text"
                                       name="config[<?= h($cfg['chave']) ?>]"
                                       class="form-control form-control-sm"
                                       value="<?= h($cfg['valor'] ?? '') ?>">
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy me-1"></i>Salvar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
