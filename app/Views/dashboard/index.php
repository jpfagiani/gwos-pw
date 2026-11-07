<?php $titulo = 'Dashboard'; $modulo = 'dashboard'; ?>

<!-- Faixa superior: info do servidor + status dos serviços -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <!-- Info compacta -->
            <div class="d-flex align-items-center gap-1 text-muted small">
                <i class="bi bi-hdd-rack"></i>
                <span class="font-monospace text-info fw-semibold" id="infoIp">—</span>
            </div>
            <div class="vr"></div>
            <div class="d-flex align-items-center gap-1 text-muted small">
                <i class="bi bi-router"></i>
                <span class="font-monospace text-info fw-semibold" id="infoGw">—</span>
            </div>
            <div class="vr"></div>
            <div class="d-flex align-items-center gap-1 text-muted small">
                <i class="bi bi-globe"></i>
                <span class="font-monospace text-info fw-semibold" id="infoDns">—</span>
            </div>
            <div class="vr"></div>
            <div class="d-flex align-items-center gap-1 text-muted small">
                <i class="bi bi-activity"></i>
                <span class="text-success fw-semibold" id="infoConn">—</span>
                <span>IPs ativos hoje</span>
            </div>

            <div class="ms-auto d-flex flex-wrap gap-2">
                <?php
                $nomes = [
                    'squid'    => 'Squid',
                    'named'    => 'BIND9',
                    'nftables' => 'Firewall',
                    'nginx'    => 'Nginx',
                    'mariadb'  => 'MariaDB',
                    'chrony'   => 'Chrony',
                    'gwos-dnsmasq' => 'DNS interno',
                ];
                foreach ($servicos as $svc => $ativo): ?>
                    <span class="badge rounded-pill bg-<?= $ativo ? 'success' : 'danger' ?> d-flex align-items-center gap-1" style="font-size:.75rem;padding:.4em .75em">
                        <i class="bi bi-circle-fill" style="font-size:.5rem"></i>
                        <?= h($nomes[$svc] ?? $svc) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4" aria-label="Recursos do servidor">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><small class="text-muted">Carga (1, 5, 15 min)</small><div id="infoCarga" class="fw-semibold font-monospace">—</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><small class="text-muted">Memória</small><div id="infoMemoria" class="fw-semibold">—</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><small class="text-muted">Disco raiz</small><div id="infoDisco" class="fw-semibold">—</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><small class="text-muted">Ligado há</small><div id="infoUptime" class="fw-semibold">—</div></div></div></div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([['Equipamentos observados',$totalEquipamentos,'/equipamentos','pc-display'],['Sessões de internet ativas',$sessoesAtivas,'/internet','person-check'],['Bloqueios registrados hoje',$bloqueiosHoje,'/monitoramento?decisao=bloqueado','shield-x'],['Download HTTP hoje',formatar_bytes($bytesHoje),'/ranking','cloud-download'],['Upload HTTP hoje',$uploadHoje===null?'sem dados':formatar_bytes((int)$uploadHoje),'/ranking','cloud-upload'],['Alertas abertos',$alertasAbertos,'/alertas','bell']] as [$rotulo,$valor,$url,$icone]): ?>
    <div class="col-6 col-lg"><a class="text-decoration-none" href="<?=h($url)?>"><div class="card h-100"><div class="card-body py-3"><div class="text-muted small"><i class="bi bi-<?=h($icone)?> me-1"></i><?=h($rotulo)?></div><div class="fs-4 fw-bold text-dark"><?=h((string)$valor)?></div></div></div></a></div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
 <div class="col-lg-7"><div class="card h-100"><div class="card-header d-flex justify-content-between"><strong>Maiores volumes nas últimas 24h</strong><a href="/ranking">Ver ranking</a></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Dispositivo</th><th>IP</th><th class="text-end">Download HTTP</th><th class="text-end">Upload HTTP</th></tr></thead><tbody><?php foreach($rankingHoje as $r):?><tr><td><?=h($r['equipamento'])?></td><td class="font-monospace"><?=h($r['ip'])?></td><td class="text-end"><?=formatar_bytes((int)$r['download_bytes'])?></td><td class="text-end"><?=$r['upload_bytes']===null?'sem dados':formatar_bytes((int)$r['upload_bytes'])?></td></tr><?php endforeach;?><?php if(!$rankingHoje):?><tr><td colspan="4" class="text-muted text-center">Sem dados medidos nas últimas 24h.</td></tr><?php endif;?></tbody></table></div><div class="card-footer small text-muted">Volumes de requisições HTTP observadas no Squid. Não abrangem fluxos bypassados nem representam todo o tráfego de rede.</div></div></div>
 <div class="col-lg-5"><div class="card h-100"><div class="card-header d-flex justify-content-between"><strong>Alertas ativos</strong><a href="/alertas">Ver todos</a></div><div class="list-group list-group-flush"><?php foreach($alertasRecentes as $a):?><div class="list-group-item"><span class="badge bg-<?=($a['severidade']==='critico'?'danger':'warning')?> me-1"><?=h($a['severidade'])?></span><strong><?=h($a['titulo'])?></strong><div class="small text-muted"><?=h($a['mensagem'])?></div></div><?php endforeach;?><?php if(!$alertasRecentes):?><div class="list-group-item text-muted">Sem alertas ativos.</div><?php endif;?></div></div></div>
</div>

<!-- Contadores resumo -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="bg-primary bg-opacity-10 rounded p-3">
                    <i class="bi bi-people-fill text-primary fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Grupos Ativos</div>
                    <div class="fw-bold fs-4"><?= $totalGrupos ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="bg-success bg-opacity-10 rounded p-3">
                    <i class="bi bi-pc-display text-success fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">IPs Gerenciados</div>
                    <div class="fw-bold fs-4"><?= $totalIps ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="bg-warning bg-opacity-10 rounded p-3">
                    <i class="bi bi-globe2 text-warning fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Domínios</div>
                    <div class="fw-bold fs-4"><?= $totalDominios ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="bg-info bg-opacity-10 rounded p-3">
                    <i class="bi bi-arrow-left-right text-info fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Regras NAT</div>
                    <div class="fw-bold fs-4"><?= $totalNat ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end mb-2"><small id="infoColeta" class="text-muted" aria-live="polite">Aguardando coleta…</small></div>
<!-- Gráficos de banda -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
                <span class="fw-semibold small"><i class="bi bi-arrow-down-circle text-primary me-1"></i>Rede interna (LAN)</span>
                <small class="text-muted font-monospace" id="lanLabel">aguardando…</small>
            </div>
            <div class="card-body py-2" style="height:80px">
                <canvas id="chartLAN"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
                <span class="fw-semibold small"><i class="bi bi-arrow-up-circle text-success me-1"></i>Rede externa (WAN)</span>
                <small class="text-muted font-monospace" id="wanLabel">aguardando…</small>
            </div>
            <div class="card-body py-2" style="height:80px">
                <canvas id="chartWAN"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Acessos em tempo real -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold small"><i class="bi bi-broadcast text-danger me-1"></i>Atividade recente do Squid</span>
        <span class="badge bg-secondary">atualiza a cada 5 s</span>
    </div>
    <div style="max-height:200px;overflow-y:auto">
        <table class="table table-hover table-sm mb-0 small">
            <thead class="table-light sticky-top">
                <tr>
                    <th style="width:115px">Data/Hora</th>
                    <th style="width:130px">IP</th>
                    <th>Destino</th>
                    <th style="width:110px">Grupo</th>
                    <th style="width:90px">Ação</th>
                </tr>
            </thead>
            <tbody id="rtLog">
                <tr><td colspan="5" class="text-center text-muted py-3">Carregando…</td></tr>
            </tbody>

        </table>
    </div>
</div>

<!-- Últimos Acessos -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-clock-history me-1"></i> Últimos Acessos</span>
        <small class="text-muted fw-normal">25 mais recentes do log do Squid</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 small">
            <thead class="table-light">
                <tr>
                    <th style="width:135px">Data/Hora</th>
                    <th style="width:130px">IP Cliente</th>
                    <th>Domínio</th>
                    <th style="width:110px">Grupo</th>
                    <th style="width:80px" class="text-end">Tráfego</th>
                    <th style="width:90px">Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ultimosAcessos)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Nenhum acesso no log do Squid.</td></tr>
                <?php else: ?>
                    <?php foreach ($ultimosAcessos as $a): ?>
                        <tr>
                            <td class="font-monospace text-muted" style="font-size:.8rem"><?= h($a['data_hora']) ?></td>
                            <td><span class="font-monospace text-primary fw-semibold"><?= h($a['ip_cliente']) ?></span></td>
                            <td class="text-truncate" style="max-width:220px"><?= h($a['dominio']) ?></td>
                            <td><span class="badge bg-secondary"><?= h($a['grupo']) ?></span></td>
                            <td class="text-end font-monospace" style="font-size:.8rem"><?= formatar_bytes((int)$a['bytes']) ?></td>
                            <td>
                                <span class="badge bg-<?= $a['bloqueado'] ? 'danger' : 'success' ?>">
                                    <?= $a['bloqueado'] ? 'Negado' : 'Permitido' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="/assets/js/chart.umd.min.js"></script>
<script>
(function(){
    const historicoLAN = <?= json_encode($historicoLAN, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    const historicoWAN = <?= json_encode($historicoWAN, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    const pts = 30;
    const serie = valores => [...Array(Math.max(0,pts-valores.length)).fill(null), ...valores.slice(-pts)];
    const lbs = [...Array(Math.max(0,pts-historicoLAN.labels.length)).fill(''), ...historicoLAN.labels.slice(-pts)];
    const rxL = serie(historicoLAN.rx), txL = serie(historicoLAN.tx);
    const rxW = serie(historicoWAN.rx), txW = serie(historicoWAN.tx);
    let last = null;

    function mkChart(id, d1, d2, c1, c2){
        return new Chart(document.getElementById(id),{
            type:'line',
            data:{labels:lbs,datasets:[
                {data:d1,borderColor:c1,borderWidth:1.5,pointRadius:0,fill:true,backgroundColor:c1+'33',tension:0.4},
                {data:d2,borderColor:c2,borderWidth:1.5,pointRadius:0,fill:true,backgroundColor:c2+'33',tension:0.4}
            ]},
            options:{responsive:true,maintainAspectRatio:false,animation:false,
                plugins:{legend:{display:false},tooltip:{enabled:false}},
                scales:{x:{display:false},y:{display:false,min:0}}}
        });
    }
    const cL = mkChart('chartLAN', rxL, txL, '#2f81f7', '#1d9e75');
    const cW = mkChart('chartWAN', rxW, txW, '#1d9e75', '#e5484d');

    function fmt(b){ if(b>=1e6) return (b/1e6).toFixed(1)+' Mbps'; if(b>=1e3) return (b/1e3).toFixed(0)+' Kbps'; return Math.round(b)+' bps'; }

    // Escapa HTML — o log do Squid contém dados controlados pelos clientes
    // da rede (URLs); injetar sem escapar seria XSS no navegador do admin
    function esc(s){
        return String(s ?? '').replace(/[&<>"']/g,
            c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    let coletaEmAndamento = false, intervalo = 5000, timer;
    function atualizar(){
        if (coletaEmAndamento) return;
        coletaEmAndamento = true;
        fetch('/dashboard/info', {headers:{'Accept':'application/json'}, cache:'no-store'}).then(r=>{ if(!r.ok) throw new Error('http'); return r.json(); }).then(d=>{
            document.getElementById('infoIp').textContent  = d.ip;
            document.getElementById('infoGw').textContent  = d.gw;
            document.getElementById('infoDns').textContent = d.dns;
            document.getElementById('infoConn').textContent= d.conn;
            document.getElementById('infoCarga').textContent = d.carga ?? 'indisponível';
            document.getElementById('infoMemoria').textContent = d.memoria ? `${d.memoria.usada_mb} / ${d.memoria.total_mb} MB (${d.memoria.usada_pct}%)` : 'indisponível';
            document.getElementById('infoDisco').textContent = d.disco ? `${d.disco.usada_gb} / ${d.disco.total_gb} GB (${d.disco.usada_pct}%)` : 'indisponível';
            if (d.uptime !== null && Number.isFinite(d.uptime)) { const dias=Math.floor(d.uptime/86400), horas=Math.floor((d.uptime%86400)/3600); document.getElementById('infoUptime').textContent=`${dias} d ${horas} h`; }

            const valido = [d.lan_rx,d.lan_tx,d.wan_rx,d.wan_tx].every(Number.isFinite);
            const agora = Date.now();
            const coleta = document.getElementById('infoColeta');
            if (coleta) coleta.textContent = 'Atualização da tela: ' + new Date(d.coletado_em).toLocaleTimeString('pt-BR') + ' · histórico do sistema em intervalo de 1 min';
            if(!valido){
                last = null;
                document.getElementById('lanLabel').textContent = 'sem dados de interface';
                document.getElementById('wanLabel').textContent = 'sem dados de interface';
            } else if(last){
                const dt = Math.max(1, (agora - last.em) / 1000);
                const delta = (atual, anterior) => atual >= anterior ? ((atual - anterior) * 8 / dt) : null;
                const dLanRx = delta(d.lan_rx,last.lan_rx), dLanTx = delta(d.lan_tx,last.lan_tx);
                const dWanRx = delta(d.wan_rx,last.wan_rx), dWanTx = delta(d.wan_tx,last.wan_tx);
                if ([dLanRx,dLanTx,dWanRx,dWanTx].some(v=>v===null)) {
                    rxL.push(null); rxL.shift(); txL.push(null); txL.shift();
                    rxW.push(null); rxW.shift(); txW.push(null); txW.shift();
                    cL.update(); cW.update();
                    document.getElementById('lanLabel').textContent = 'contador reiniciado; coletando';
                    document.getElementById('wanLabel').textContent = 'contador reiniciado; coletando';
                } else {
                    rxL.push(dLanRx); rxL.shift(); txL.push(dLanTx); txL.shift();
                    rxW.push(dWanRx); rxW.shift(); txW.push(dWanTx); txW.shift();
                    lbs.push(new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})); lbs.shift();
                    cL.options.scales.y.max = Math.max(...rxL,...txL)*1.2||1;
                    cW.options.scales.y.max = Math.max(...rxW,...txW)*1.2||1;
                    cL.update(); cW.update();
                    document.getElementById('lanLabel').textContent = '↓ '+fmt(dLanRx)+' · ↑ '+fmt(dLanTx);
                    document.getElementById('wanLabel').textContent = '↓ '+fmt(dWanRx)+' · ↑ '+fmt(dWanTx);
                }
            }
            if (valido) last = { lan_rx: d.lan_rx, lan_tx: d.lan_tx, wan_rx: d.wan_rx, wan_tx: d.wan_tx, em: agora };

            if(d.log && d.log.length){
                document.getElementById('rtLog').innerHTML = d.log.map(r=>`
                    <tr>
                        <td class="font-monospace">${esc(r.hora)}</td>
                        <td class="font-monospace text-primary">${esc(r.ip)}</td>
                        <td class="text-truncate" style="max-width:200px">${esc(r.dominio)}</td>
                        <td><span class="badge bg-secondary">${esc(r.grupo||'—')}</span></td>
                        <td><span class="badge bg-${r.bloqueado?'danger':'success'}">${r.bloqueado?'Negado':'Permitido'}</span></td>
                    </tr>`).join('');
            } else {
                document.getElementById('rtLog').innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Sem registros recentes</td></tr>';
            }
            intervalo = 5000;
        }).catch(()=>{
            last = null;
            document.getElementById('lanLabel').textContent = 'falha na coleta';
            document.getElementById('wanLabel').textContent = 'falha na coleta';
            intervalo = Math.min(60000, intervalo * 2);
        }).finally(()=>{ coletaEmAndamento = false; clearTimeout(timer); timer = setTimeout(atualizar, intervalo); });
    }
    atualizar();
})();
</script>
