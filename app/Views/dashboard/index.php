<?php $titulo = 'Dashboard'; $modulo = 'dashboard';
$icEstado = ['ok' => 'bi-check-lg', 'aviso' => 'bi-exclamation-lg', 'erro' => 'bi-x-lg', 'nd' => 'bi-question-lg'];
$tipoCor = ['liberado' => 'success', 'parcial' => 'warning', 'bloqueado' => 'danger', 'isento' => 'info'];
$dias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
$maxRank = 0; foreach ($rankingHoje as $r) { $maxRank = max($maxRank, (int) $r['download_bytes'] + (int) $r['upload_bytes']); }
$podeEditar = pode_editar();
?>
<div class="row g-3 mb-3">
  <div class="col-xl-6">
    <div class="gw-card">
      <div class="gw-titulo"><span><i class="bi bi-bar-chart-fill"></i>Estado Geral da Rede</span></div>
      <div class="gw-estado">
        <div class="selo <?= h($estado[0]) ?>"><div class="ico"><i class="bi <?= $icEstado[$estado[0]] ?>"></i></div><div><div class="t"><?= h($estado[1]) ?></div><div class="d"><?= h($estado[2]) ?></div></div></div>
        <div class="num"><div class="r">Dispositivos Conectados</div><div class="v"><i class="bi bi-pc-display"></i><?= number_format($conectados) ?></div><div class="small text-muted">vistos em 15 min</div></div>
        <div class="num"><div class="r">Bloqueados</div><div class="v neg"><i class="bi bi-slash-circle"></i><?= number_format($bloqueiosHoje) ?></div><div class="small text-muted">acessos hoje</div></div>
        <div class="num"><div class="r">Tráfego Atual</div><div class="v"><i class="bi bi-arrow-down-up"></i><span id="trafegoAtual" style="font-size:1.35rem">—</span></div><div class="small text-muted">WAN (↓+↑)</div></div>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="gw-iface wan">
      <div class="tit"><i class="bi bi-globe2"></i> WAN – Internet</div>
      <?php $wOk = $wan['existe'] && in_array($wan['estado'], ['up', 'unknown'], true); ?>
      <div class="lin"><span class="p <?= $wOk ? '' : 'off' ?>"></span><span id="wanEstado"><?= !$wan['existe'] ? 'Interface não configurada' : ($wOk ? 'Conectada' : 'Sem link') ?></span></div>
      <div class="lin"><span class="p <?= $wan['ip'] ? '' : 'off' ?>"></span><span id="wanIp" class="font-monospace"><?= h($wan['ip'] ?? 'sem IP') ?></span></div>
      <div class="taxa" id="wanLabel">coletando…</div>
      <div class="spark"><canvas id="sparkWan"></canvas></div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="gw-iface lan">
      <div class="tit"><i class="bi bi-diagram-3-fill"></i> LAN – Interna</div>
      <?php $lOk = $lan['existe'] && in_array($lan['estado'], ['up', 'unknown'], true); ?>
      <div class="lin"><span class="p <?= $lOk ? '' : 'off' ?>"></span><span id="lanEstado"><?= !$lan['existe'] ? 'Interface não configurada' : ($lOk ? 'Ativa' : 'Sem link') ?></span></div>
      <div class="lin"><span class="p <?= $lan['ip'] ? '' : 'off' ?>"></span><span id="lanIp" class="font-monospace"><?= h($lan['ip'] ?? 'sem IP') ?></span></div>
      <div class="taxa" id="lanLabel">coletando…</div>
      <div class="spark"><canvas id="sparkLan"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-5">
    <div class="gw-card">
      <div class="gw-titulo"><span><i class="bi bi-graph-up"></i>Tráfego da Internet (últimas 24h)</span><span class="small text-muted fw-normal"><span class="me-2"><i class="bi bi-circle-fill" style="color:#0866d9;font-size:.55rem"></i> Download</span><span><i class="bi bi-circle-fill" style="color:#f59e0b;font-size:.55rem"></i> Upload</span></span></div>
      <div class="gw-corpo" style="min-height:230px;position:relative">
        <?php if ($hist24['amostras'] < 2): ?><div class="gw-vazio">Sem dados de interface no período. O coletor grava uma amostra por minuto (cron).</div><?php else: ?><canvas id="grafico24h" height="230"></canvas><?php endif; ?>
      </div>
      <div class="gw-rodape">Interface WAN · faixas de 30 min · bps = (Δ bytes × 8) / Δ s · última coleta: <?= h($hist24['ultima'] ?? 'nenhuma') ?></div>
    </div>
  </div>
  <div class="col-md-6 col-xl-4">
    <div class="gw-card">
      <div class="gw-titulo"><span><i class="bi bi-trophy-fill"></i>Top 10 Consumidores de Internet</span><a href="/ranking">Ver todos →</a></div>
      <div class="gw-corpo">
        <?php if (!$rankingHoje): ?><div class="gw-vazio">Sem tráfego medido nas últimas 24h.</div><?php else: ?>
        <ul class="gw-rank">
        <?php foreach ($rankingHoje as $i => $r): $tot = (int) $r['download_bytes'] + (int) $r['upload_bytes']; ?>
          <li><span class="text-muted"><?= $i + 1 ?></span><a class="text-decoration-none text-reset text-truncate" href="/equipamentos/ficha?ip=<?= urlencode($r['ip']) ?>" title="<?= h($r['ip']) ?>"><?= h($r['equipamento']) ?></a><div class="barra"><span style="width:<?= $maxRank > 0 ? max(2, round($tot * 100 / $maxRank)) : 0 ?>%"></span></div><span class="val"><?= formatar_bytes($tot) ?></span></li>
        <?php endforeach; ?></ul><?php endif; ?>
      </div>
      <div class="gw-rodape">Download + upload HTTP observados no Squid (24h).</div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="gw-card">
      <div class="gw-titulo"><span><i class="bi bi-bell-fill"></i>Alertas Recentes</span><a href="/alertas">Ver todos →</a></div>
      <?php if (!$alertasRecentes): ?><div class="gw-vazio">Sem alertas ativos.</div><?php else: ?>
      <ul class="gw-alertas">
      <?php foreach ($alertasRecentes as $a): $ic = ['critico' => 'bi-exclamation-triangle-fill', 'aviso' => 'bi-exclamation-circle-fill', 'info' => 'bi-info-circle-fill'][$a['severidade']] ?? 'bi-info-circle-fill'; ?>
        <li><span class="ic <?= h($a['severidade']) ?>"><i class="bi <?= $ic ?>"></i></span><div><div class="t"><?= h($a['titulo']) ?></div><div class="m"><?= h(mb_strimwidth((string) $a['mensagem'], 0, 60, '…')) ?></div></div><span class="h"><?= h(date('H:i', strtotime($a['atualizado_em']))) ?></span></li>
      <?php endforeach; ?></ul><?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-6">
    <div class="gw-card">
      <div class="gw-titulo"><span><i class="bi bi-display"></i>Monitoramento de Acessos em Tempo Real</span><span class="small fw-normal"><span class="st ok" id="rtEstado">Atualização: <?= date('H:i:s') ?></span> · <a href="/monitoramento">Ver mais →</a></span></div>
      <div class="gw-corpo sem-pad table-responsive">
        <table class="gw-tabela"><thead><tr><th>IP</th><th>Equipamento</th><th>Usuário</th><th>Destino</th><th>Tráfego</th><th>Status</th><th>Horário</th></tr></thead>
        <tbody id="rtCorpo">
        <?php foreach ($acessosRecentes as $a): ?><tr><td class="mono"><?= h($a['ip']) ?></td><td><?= h($a['equipamento']) ?></td><td><?= h($a['usuario']) ?></td><td class="text-truncate" style="max-width:150px"><?= h($a['destino']) ?></td><td>↓ <?= formatar_bytes($a['download']) ?><?= $a['upload'] === null ? '' : ' ↑ ' . formatar_bytes($a['upload']) ?></td><td><span class="st <?= $a['negado'] ? 'bad' : 'ok' ?>"><i class="bi bi-<?= $a['negado'] ? 'x-circle-fill' : 'check-circle-fill' ?>"></i><?= $a['negado'] ? 'Bloqueado' : 'Permitido' ?></span></td><td><?= h($a['hora']) ?></td></tr><?php endforeach; ?>
        <?php if (!$acessosRecentes): ?><tr><td colspan="7" class="gw-vazio">Sem acessos importados do Squid nas últimas 24h.</td></tr><?php endif; ?>
        </tbody></table>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="gw-card">
      <div class="gw-titulo"><span><i class="bi bi-lightning-charge-fill"></i>Controle Rápido de Acesso</span></div>
      <div class="gw-corpo">
        <div class="gw-grupos">
          <a class="gw-grupo liberado" href="/grupos"><i class="bi bi-people-fill"></i><div class="n">Liberados</div><div class="q">(<?= (int) $gruposTipo['liberado'] ?>)</div></a>
          <a class="gw-grupo parcial" href="/grupos"><i class="bi bi-people-fill"></i><div class="n">Parciais</div><div class="q">(<?= (int) $gruposTipo['parcial'] ?>)</div></a>
          <a class="gw-grupo bloqueado" href="/grupos"><i class="bi bi-person-fill-slash"></i><div class="n">Bloqueados</div><div class="q">(<?= (int) $gruposTipo['bloqueado'] ?>)</div></a>
        </div>
        <?php if ($podeEditar): ?>
        <div class="gw-acoes">
          <button class="btn btn-primary" data-gw-acao="alterar_grupo"><i class="bi bi-people"></i> Alterar Grupo</button>
          <button class="btn btn-primary" data-gw-acao="liberar_temporario"><i class="bi bi-clock-history"></i> Liberar Temporário</button>
          <button class="btn btn-danger" data-gw-acao="bloquear"><i class="bi bi-slash-circle"></i> Bloquear</button>
        </div>
        <?php else: ?><div class="small text-muted mt-3">Perfil somente leitura: ações rápidas indisponíveis.</div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="gw-card gw-simulador">
      <div class="gw-titulo"><span><i class="bi bi-search"></i>Simulador de Acesso</span></div>
      <div class="gw-corpo">
        <p class="small text-muted mb-2">Informe o IP do equipamento e o destino; nada é alterado.</p>
        <form id="formSim" class="d-grid gap-2">
          <input class="form-control form-control-sm font-monospace" name="ip" placeholder="IP do equipamento: 192.168.0.125" required maxlength="45">
          <input class="form-control form-control-sm" name="destino" placeholder="Destino: youtube.com" maxlength="253">
          <button class="btn btn-primary btn-sm"><i class="bi bi-play-circle"></i> Consultar</button>
        </form>
        <div id="simResultado"></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-5">
    <div class="gw-card">
      <div class="gw-titulo"><span><i class="bi bi-calendar3"></i>Agenda de Políticas de Acesso</span><a href="/agenda">Ver calendário →</a></div>
      <div class="gw-corpo">
        <?php if (!$agenda): ?><div class="gw-vazio">Nenhuma regra de horário ativa cadastrada.</div><?php else: ?>
        <table class="gw-agenda"><thead><tr><th>Regra / horário</th><?php foreach (['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'] as $d): ?><th><?= $d ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($agenda as $r): ?><tr><td title="<?= h($r['grupo_nome'] ?? 'Todos os grupos') ?>"><?= h(substr($r['hora_inicio'], 0, 2)) ?>h–<?= h(substr($r['hora_fim'], 0, 2)) ?>h <span class="text-muted fw-normal"><?= h(mb_strimwidth($r['nome'], 0, 14, '…')) ?></span></td>
          <?php foreach ([1,2,3,4,5,6,0] as $d): $on = (($r['dias_semana'][$d] ?? '0') === '1'); ?><td class="<?= !$on ? '' : ($r['acao'] === 'permitir' ? 'livre' : 'bloq') ?>" <?= $on ? '' : 'style="background:transparent;color:#94a3b8"' ?>><?= !$on ? '—' : ($r['acao'] === 'permitir' ? 'Livre' : 'Bloq.') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table>
        <div class="gw-legenda"><span><i style="background:#16a34a"></i>Acesso liberado</span><span><i style="background:#dc2626"></i>Bloqueado</span><span><i style="background:#cbd5e1"></i>Sem regra</span></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-7 col-xl-4">
    <div class="gw-card">
      <div class="gw-titulo"><span><i class="bi bi-pc-display-horizontal"></i>Equipamentos Conectados</span><a href="/equipamentos">Ver todos →</a></div>
      <div class="gw-corpo sem-pad table-responsive">
        <table class="gw-tabela"><thead><tr><th>IP</th><th>Nome</th><th>Grupo</th><th>Último acesso</th></tr></thead><tbody>
        <?php foreach ($equipamentos as $e): $tp = explode(' / ', (string) ($e['grupo_tipo'] ?? ''))[0]; ?>
          <tr><td class="mono"><a href="/equipamentos/ficha?ip=<?= urlencode($e['ip']) ?>"><?= h($e['ip']) ?></a></td><td><?= h($e['hostname'] ?: '—') ?></td><td><?php if ($e['grupo_nome']): ?><span class="badge bg-<?= $tipoCor[$tp] ?? 'secondary' ?>"><?= h($e['grupo_nome']) ?></span><?php else: ?><span class="text-muted">sem regra</span><?php endif; ?></td><td><?= $e['ultimo_acesso'] ? h(date('H:i', strtotime($e['ultimo_acesso']))) : '—' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$equipamentos): ?><tr><td colspan="4" class="gw-vazio">Nenhum equipamento inventariado ainda.</td></tr><?php endif; ?>
        </tbody></table>
      </div>
    </div>
  </div>
  <div class="col-md-5 col-xl-3">
    <div class="gw-card">
      <div class="gw-titulo"><span><i class="bi bi-hdd-network-fill"></i>Serviços do Servidor</span><a href="/diagnostico">Detalhes →</a></div>
      <div class="gw-corpo">
        <?php if (!$servicosLista): ?><div class="gw-vazio">Nenhum módulo com serviço registrado.</div><?php else: ?>
        <ul class="gw-servicos" id="listaServicos">
        <?php foreach ($servicosLista as $sv): ?><li><i class="bi bi-<?= $sv['ativo'] ? 'check-circle-fill ok' : 'x-circle-fill bad' ?>"></i><?= h($sv['nome']) ?><span class="est <?= $sv['ativo'] ? 'st ok' : 'st bad' ?>"><?= $sv['ativo'] ? 'Online' : 'Parado' ?></span></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
      <div class="gw-rodape">Só módulos realmente instalados. CPU, memória, disco e uptime em <a href="/diagnostico">Diagnóstico</a>.</div>
    </div>
  </div>
</div>

<div class="gw-atalhos mb-2">
  <a class="gw-atalho" href="/monitoramento"><i class="bi bi-activity"></i> Monitoramento</a>
  <a class="gw-atalho" href="/equipamentos"><i class="bi bi-pc-display"></i> Equipamentos</a>
  <a class="gw-atalho" href="/controle-acesso"><i class="bi bi-shield-lock-fill"></i> Controle de Acesso</a>
  <a class="gw-atalho" href="/politicas"><i class="bi bi-journal-check"></i> Políticas</a>
  <a class="gw-atalho" href="/relatorios"><i class="bi bi-file-earmark-bar-graph-fill"></i> Relatórios</a>
  <a class="gw-atalho" href="/diagnostico"><i class="bi bi-wrench-adjustable"></i> Diagnóstico</a>
</div>

<script src="/assets/js/chart.umd.min.js"></script>
<script>
(function(){
    const hist = <?= json_encode($hist24, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    const mbps = v => v === null ? null : +(v/1e6).toFixed(3);
    const cor = getComputedStyle(document.documentElement).getPropertyValue('--gw-muted') || '#64748b';
    const g24 = document.getElementById('grafico24h');
    if (g24 && window.Chart) new Chart(g24, {type:'line', data:{labels:hist.labels, datasets:[
        {label:'Download', data:hist.rx.map(mbps), borderColor:'#0866d9', backgroundColor:'#0866d933', fill:true, tension:.35, pointRadius:0, borderWidth:2, spanGaps:false},
        {label:'Upload', data:hist.tx.map(mbps), borderColor:'#f59e0b', backgroundColor:'#f59e0b22', fill:true, tension:.35, pointRadius:0, borderWidth:2, spanGaps:false}]},
        options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}, tooltip:{callbacks:{label:c => c.dataset.label+': '+(c.parsed.y===null?'sem dados':c.parsed.y+' Mbps')}}},
        scales:{x:{ticks:{maxTicksLimit:7, color:cor}, grid:{display:false}}, y:{beginAtZero:true, ticks:{color:cor, callback:v=>v+' Mbps'}}}}});

    const pts = 40;
    function spark(id, c1, c2){ const el = document.getElementById(id); if(!el||!window.Chart) return null;
        return new Chart(el, {type:'line', data:{labels:Array(pts).fill(''), datasets:[
            {data:Array(pts).fill(null), borderColor:c1, backgroundColor:c1+'55', fill:true, tension:.4, pointRadius:0, borderWidth:1.5},
            {data:Array(pts).fill(null), borderColor:c2, backgroundColor:c2+'33', fill:true, tension:.4, pointRadius:0, borderWidth:1.5}]},
            options:{responsive:true, maintainAspectRatio:false, animation:false, plugins:{legend:{display:false}, tooltip:{enabled:false}}, scales:{x:{display:false}, y:{display:false, min:0}}}}); }
    const sW = spark('sparkWan', '#ffffff', '#fde68a'), sL = spark('sparkLan', '#ffffff', '#bbf7d0');
    const fmt = b => b >= 1e9 ? (b/1e9).toFixed(2)+' Gbps' : b >= 1e6 ? (b/1e6).toFixed(1)+' Mbps' : b >= 1e3 ? (b/1e3).toFixed(0)+' Kbps' : Math.round(b)+' bps';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const bytes = n => { n = Number(n)||0; if (n < 1024) return n+' B'; const u=['KB','MB','GB','TB']; let i=-1; do { n/=1024; i++; } while (n>=1024 && i<3); return n.toFixed(1)+' '+u[i]; };
    let last = null, busy = false, intervalo = 5000, timer;
    function push(ch, a, b){ if(!ch) return; [a,b].forEach((v,i)=>{ ch.data.datasets[i].data.push(v); ch.data.datasets[i].data.shift(); }); ch.update(); }

    function atualizar(){
        if (busy) return; busy = true;
        fetch('/dashboard/info', {headers:{'Accept':'application/json'}, cache:'no-store'}).then(r => { if(!r.ok) throw 0; return r.json(); }).then(d => {
            const ok = [d.wan_rx,d.wan_tx,d.lan_rx,d.lan_tx].every(Number.isFinite), agora = Date.now();
            if (!ok) { last = null; ['wanLabel','lanLabel'].forEach(i => document.getElementById(i).textContent = 'sem dados de interface'); document.getElementById('trafegoAtual').textContent = '—'; }
            else if (last) {
                const dt = Math.max(1, (agora - last.em)/1000), dl = (a,b) => a >= b ? (a-b)*8/dt : null;
                const wr = dl(d.wan_rx,last.wan_rx), wt = dl(d.wan_tx,last.wan_tx), lr = dl(d.lan_rx,last.lan_rx), lt = dl(d.lan_tx,last.lan_tx);
                if ([wr,wt,lr,lt].some(v => v === null)) { ['wanLabel','lanLabel'].forEach(i => document.getElementById(i).textContent = 'contador reiniciado; coletando'); document.getElementById('trafegoAtual').textContent = '—'; }
                else {
                    push(sW, wr, wt); push(sL, lr, lt);
                    document.getElementById('wanLabel').textContent = '↓ '+fmt(wr)+'  ↑ '+fmt(wt);
                    document.getElementById('lanLabel').textContent = '↓ '+fmt(lr)+'  ↑ '+fmt(lt);
                    document.getElementById('trafegoAtual').textContent = fmt(wr+wt);
                }
            }
            if (ok) last = {wan_rx:d.wan_rx, wan_tx:d.wan_tx, lan_rx:d.lan_rx, lan_tx:d.lan_tx, em:agora};
            if (d.wan && d.wan.ip) document.getElementById('wanIp').textContent = d.wan.ip;
            const rt = document.getElementById('rtEstado'); rt.className = 'st ok'; rt.textContent = 'Atualização: ' + new Date().toLocaleTimeString('pt-BR');
            if (Array.isArray(d.acessos) && d.acessos.length) document.getElementById('rtCorpo').innerHTML = d.acessos.map(a =>
                '<tr><td class="mono">'+esc(a.ip)+'</td><td>'+esc(a.equipamento)+'</td><td>'+esc(a.usuario)+'</td><td class="text-truncate" style="max-width:150px">'+esc(a.destino)+'</td><td>↓ '+bytes(a.download)+(a.upload===null?'':' ↑ '+bytes(a.upload))+
                '</td><td><span class="st '+(a.negado?'bad':'ok')+'"><i class="bi bi-'+(a.negado?'x-circle-fill':'check-circle-fill')+'"></i>'+(a.negado?'Bloqueado':'Permitido')+'</span></td><td>'+esc(a.hora)+'</td></tr>').join('');
            intervalo = 5000;
        }).catch(() => { last = null; ['wanLabel','lanLabel'].forEach(i => document.getElementById(i).textContent = 'falha na coleta');
            const rt = document.getElementById('rtEstado'); rt.className = 'st bad'; rt.textContent = 'Falha na coleta'; intervalo = Math.min(60000, intervalo*2);
        }).finally(() => { busy = false; clearTimeout(timer); timer = setTimeout(atualizar, intervalo); });
    }
    atualizar();

    async function servicos(){ try { const r = await fetch('/dashboard/servicos', {cache:'no-store'}); if(!r.ok) return; const j = await r.json(); const ul = document.getElementById('listaServicos'); if(!ul) return;
        ul.innerHTML = j.servicos.map(s => '<li><i class="bi bi-'+(s.ativo?'check-circle-fill ok':'x-circle-fill bad')+'"></i>'+esc(s.nome)+'<span class="est st '+(s.ativo?'ok':'bad')+'">'+(s.ativo?'Online':'Parado')+'</span></li>').join(''); } catch(e){} }
    setInterval(servicos, 30000);

    document.getElementById('formSim').addEventListener('submit', async ev => {
        ev.preventDefault(); const f = new FormData(ev.target), p = new URLSearchParams({json:'1', ip:f.get('ip'), destino:f.get('destino')});
        const box = document.getElementById('simResultado'); box.innerHTML = '<div class="resultado nd">Consultando…</div>';
        try { const r = await fetch('/agenda/simulador?'+p, {headers:{'Accept':'application/json'}}); const j = await r.json();
            if (!r.ok || j.erro) { box.innerHTML = '<div class="resultado nd">'+esc(j.erro||'Não foi possível simular.')+'</div>'; return; }
            const cls = j.decisao === 'permitir' ? 'permitir' : (j.decisao === 'bloquear' ? 'bloquear' : 'nd'), ic = cls === 'permitir' ? 'check-circle-fill' : (cls === 'bloquear' ? 'x-circle-fill' : 'question-circle-fill');
            box.innerHTML = '<div class="resultado '+cls+'"><i class="bi bi-'+ic+'"></i><div><strong>'+esc(j.destino||'Destino não informado')+' — '+esc(j.decisao.toUpperCase())+'</strong><div class="small">'+esc(j.motivo)+'</div><div class="small text-muted">Grupo: '+esc(j.grupo||'nenhum')+'</div></div></div>';
        } catch(e) { box.innerHTML = '<div class="resultado nd">Falha ao consultar.</div>'; }
    });
})();
</script>
