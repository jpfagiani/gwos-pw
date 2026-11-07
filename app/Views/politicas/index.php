<?php $titulo = 'Políticas e Regras'; $modulo = 'politicas'; $cor = ['liberado' => 'success', 'parcial' => 'warning', 'bloqueado' => 'danger', 'isento' => 'info'];
$desc = ['liberado' => 'Acesso amplo conforme regras gerais.', 'parcial' => 'Internet com restrições de streaming, redes sociais e categorias.', 'bloqueado' => 'Sem acesso externo, mantendo recursos internos.', 'isento' => 'Navega sem login, mas passa pelo proxy.']; ?>
<div class="page-head"><div><h4>Políticas e Regras</h4><div class="text-muted small">Visão central do que governa o acesso. Edição em cada tela de origem.</div></div></div>
<div class="row g-3 mb-3">
<?php foreach ($grupos as $g): ?><div class="col-md-6 col-xl-3"><div class="gw-card"><div class="gw-titulo"><span><?= h($g['nome']) ?></span><span class="badge bg-<?= $cor[$g['tipo']] ?? 'secondary' ?>"><?= h($g['tipo']) ?></span></div><div class="gw-corpo"><div class="fs-2 fw-bold"><?= (int) $g['quantidade'] ?></div><div class="small text-muted">IPs/redes ativos · <?= h($desc[$g['tipo']] ?? '') ?></div></div><div class="gw-rodape"><a href="/grupos">Editar em Grupos e IPs →</a></div></div></div><?php endforeach; ?>
<?php if (!$grupos): ?><div class="col-12"><div class="alert alert-secondary">Nenhum grupo cadastrado. Crie os grupos Liberados, Parciais e Bloqueados em <a href="/grupos">Grupos e IPs</a>.</div></div><?php endif; ?>
</div>
<div class="gw-atalhos mb-3">
  <a class="gw-atalho" href="/dominios"><i class="bi bi-globe2"></i> Domínios: <?= $resumo['dominios_white'] ?> whitelist · <?= $resumo['dominios_black'] ?> blacklist</a>
  <a class="gw-atalho" href="/horarios"><i class="bi bi-clock-fill"></i> Horários ativos: <?= $resumo['horarios'] ?></a>
  <a class="gw-atalho" href="/agenda"><i class="bi bi-calendar-week"></i> Agenda e simulador</a>
  <a class="gw-atalho" href="/nat"><i class="bi bi-arrow-left-right"></i> NAT 1:1: <?= $resumo['nat'] ?></a>
</div>
<div class="gw-card"><div class="gw-titulo"><span><i class="bi bi-clock-history"></i>Liberações temporárias em vigor (<?= (int) $resumo['temporarias'] ?>)</span></div>
<div class="gw-corpo sem-pad"><table class="gw-tabela"><thead><tr><th>IP</th><th>Grupo</th><th>Expira em</th><th></th></tr></thead><tbody>
<?php foreach ($temporarias as $t): ?><tr><td class="mono"><?= h($t['endereco']) ?></td><td><?= h($t['grupo_nome']) ?></td><td><?= h($t['expira_em']) ?></td><td class="text-end"><?php if (pode_editar()): ?><button class="btn btn-sm btn-outline-danger" data-gw-acao="revogar" data-gw-ips="<?= h($t['endereco']) ?>">Revogar</button><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$temporarias): ?><tr><td colspan="4" class="gw-vazio">Nenhuma liberação temporária ativa.</td></tr><?php endif; ?></tbody></table></div></div>
