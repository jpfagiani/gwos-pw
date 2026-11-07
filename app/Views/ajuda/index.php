<?php $titulo = 'Ajuda'; $modulo = 'ajuda'; ?>
<div class="page-head"><div><h4>Ajuda</h4><div class="text-muted small">Guia rápido das telas do GWOS. O manual completo está em <code>MANUAL.md</code>.</div></div></div>
<div class="row g-3">
<?php foreach ([
 ['bi-house-door-fill','Dashboard','Estado da rede, WAN/LAN, tráfego 24 h, top consumidores, alertas, acessos em tempo real, ações rápidas, simulador, agenda, equipamentos e serviços. Valores ausentes aparecem como “Sem dados”, nunca como zero.','/'],
 ['bi-activity','Monitoramento','Conexões ativas, histórico, bloqueios e sessões de usuários, com filtros e atualização automática. HTTPS mostra só o destino (domínio/porta); não há interceptação de conteúdo.','/monitoramento'],
 ['bi-shield-lock-fill','Controle de Acesso','Grupos Liberados, Parciais e Bloqueados. Ações rápidas: alterar grupo, bloquear e liberar temporariamente (volta sozinho à política anterior). Toda ação tem prévia, confirmação e auditoria.','/controle-acesso'],
 ['bi-bar-chart-line-fill','Ranking e Equipamentos','Top 10/20/50 por período, ficha individual com histórico e ações por perfil; ações em lote na lista de equipamentos.','/ranking'],
 ['bi-journal-check','Políticas e Regras','Visão central de grupos, domínios, horários e NAT, com as liberações temporárias em vigor.','/politicas'],
 ['bi-calendar-week','Agenda e simulador','Calendário semanal por regra e simulação de “este acesso seria permitido?” usando a mesma lógica das regras cadastradas.','/agenda'],
 ['bi-bell-fill','Alertas e Eventos','Reconheça ou resolva eventos; há deduplicação e ação recomendada.','/alertas'],
 ['bi-wrench-adjustable','Diagnóstico','Serviços, interfaces, coleta, recursos e backup, medidos de verdade.','/diagnostico'],
 ['bi-key-fill','Acesso e senhas','Dois logins separados: painel (administradores) e portal de internet (usuários). Senha do administrador: menu do usuário → Alterar senha, ou <code>gwos senha &lt;email&gt;</code> no servidor.','/senha/trocar'],
] as [$ic,$t,$d,$l]): ?>
  <div class="col-md-6 col-xl-4"><div class="gw-card"><div class="gw-titulo"><span><i class="bi <?= $ic ?>"></i><?= h($t) ?></span></div><div class="gw-corpo small"><?= $d ?></div><div class="gw-rodape"><a href="<?= h($l) ?>">Abrir →</a></div></div></div>
<?php endforeach; ?>
</div>
<div class="card mt-3"><div class="card-body small"><strong>Atalhos:</strong> use a busca no topo para localizar IP, MAC, hostname, usuário ou domínio. Tema escuro: menu do usuário. Mais detalhes: <code>MANUAL.md</code>, <code>INSTALACAO_VM.md</code>, <code>INSTRUCOES_ATUALIZACAO.md</code>.</div></div>
