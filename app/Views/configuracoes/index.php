<?php $titulo = 'Configurações'; $modulo = 'configuracoes'; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-gear me-1"></i> Parâmetros do Sistema
            </div>
            <div class="card-body">
                <form method="POST" action="/configuracoes" id="formConfiguracoes">
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
                                <input type="<?=!empty($cfg['sensivel'])?'password':'text'?>"
                                       name="config[<?= h($cfg['chave']) ?>]"
                                       class="form-control form-control-sm"
                                       value="<?= h($cfg['valor'] ?? '') ?>"<?php if(empty($cfg['sensivel'])): ?>
                                       data-original="<?= h($cfg['valor'] ?? '') ?>"<?php else: ?>
                                       autocomplete="new-password" placeholder="<?=!empty($cfg['tem_valor'])?'Já configurada; deixe em branco para manter':'Opcional'?>"<?php endif; ?>>
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

<div class="card mt-4">
    <div class="card-header fw-semibold">Histórico de versões e reversão</div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Data/hora</th><th>Operação</th><th>Operador</th><th>Chave</th><th>Antes</th><th>Depois</th><th></th></tr></thead>
        <tbody><?php foreach($historico as $v):?><tr>
            <td><?=h($v['criado_em'])?></td><td><code><?=h(substr($v['operacao_id'],0,12))?></code></td><td><?=h($v['operador']??'sistema')?></td><td><?=h($v['chave'])?></td>
            <td class="text-break" style="max-width:180px"><?=h($v['valor_anterior'])?></td><td class="text-break" style="max-width:180px"><?=h($v['valor_novo'])?></td>
            <td><?php if(!str_contains($v['valor_anterior'],'[valor sensível]')):?><form method="post" action="/configuracoes/historico/<?=h($v['id'])?>/reverter" onsubmit="return confirm('Reverter somente esta chave para o valor anterior? Reaplique o módulo correspondente depois.')"><?=csrf_field()?><button class="btn btn-sm btn-outline-warning">Reverter</button></form><?php endif;?></td>
        </tr><?php endforeach;?><?php if(!$historico):?><tr><td colspan="7" class="text-center text-muted py-3">Nenhuma alteração registrada.</td></tr><?php endif;?></tbody>
    </table></div>
</div>

<script>
document.getElementById('formConfiguracoes').addEventListener('submit',e=>{
 const changed=[...e.currentTarget.querySelectorAll('input[name^="config["]')].filter(i=>i.type==='password'?i.value!=='' : i.value!==i.dataset.original);
 if(!changed.length){e.preventDefault();return;}
 const lines=changed.map(i=>{const key=i.name.match(/^config\[(.*)\]$/)?.[1]||'configuração';return `• ${key}: ${i.type==='password'?'[valor sensível alterado]':`${i.dataset.original||'(vazio)'} → ${i.value||'(vazio)'}`}`;});
 if(!confirm('Confirme as alterações que serão gravadas como uma nova revisão:\n\n'+lines.join('\n')))e.preventDefault();
});
</script>
