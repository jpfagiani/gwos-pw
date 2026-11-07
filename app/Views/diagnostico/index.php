<?php $titulo = 'Diagnóstico'; $modulo = 'diagnostico';
$rot = ['ok' => ['OK', 'check-circle-fill'], 'warn' => ['Atenção', 'exclamation-circle-fill'], 'bad' => ['Falha', 'x-circle-fill'], 'nd' => ['Sem dados', 'dash-circle']]; ?>
<div class="page-head"><div><h4>Diagnóstico</h4><div class="text-muted small">Verificações reais de serviços, rede, coleta, recursos e backup. Nada aqui é simulado: o que não pôde ser medido aparece como “Sem dados”.</div></div>
<div class="d-flex gap-2"><a class="btn btn-outline-primary btn-sm" href="/tarefas"><i class="bi bi-list-task"></i> Histórico de tarefas</a><a class="btn btn-primary btn-sm" href="/diagnostico"><i class="bi bi-arrow-repeat"></i> Reexecutar</a></div></div>
<div class="row g-3">
<?php foreach ($checks as $grupo => $itens): ?>
  <div class="col-lg-6"><div class="gw-card"><div class="gw-titulo"><span><i class="bi bi-clipboard2-pulse"></i><?= h($grupo) ?></span></div>
    <div class="gw-corpo sem-pad"><table class="gw-tabela"><tbody>
    <?php foreach ($itens as $c): [$t, $ic] = $rot[$c['estado']]; ?>
      <tr><td style="width:38%"><?= h($c['nome']) ?></td><td><span class="st <?= $c['estado'] === 'ok' ? 'ok' : ($c['estado'] === 'bad' ? 'bad' : ($c['estado'] === 'warn' ? 'warn' : 'nd')) ?>"><i class="bi bi-<?= $ic ?>"></i><?= $t ?></span></td><td class="text-muted"><?= h($c['detalhe']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div></div></div>
<?php endforeach; ?>
</div>
<?php if (!$temBanco): ?><div class="alert alert-info mt-3 small">Modo leve: sem o módulo de banco, as verificações de rede/coleta/tarefas não se aplicam.</div><?php endif; ?>
