<?php
// Modal único das ações rápidas (alterar grupo, bloquear, liberar temporário, revogar),
// com prévia e confirmação. Acionado por botões [data-gw-acao] em qualquer tela.
if (!pode_editar()) return;
try { $gruposModal = \App\Core\Politicas::grupos(); } catch (\Throwable $e) { $gruposModal = []; }
?>
<div class="modal fade" id="modalAcao" tabindex="-1" aria-labelledby="modalAcaoTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalAcaoTitulo">Ação rápida</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <div id="acaoErro" class="alert alert-danger py-2 small d-none" role="alert"></div>
        <div id="acaoPasso1">
          <div class="mb-3">
            <label class="form-label fw-semibold" for="acaoIps">Equipamento(s) — IP</label>
            <textarea id="acaoIps" class="form-control font-monospace" rows="2" placeholder="192.168.0.125 (separe vários por espaço ou vírgula)"></textarea>
            <div class="form-text">Atua só em regra de IP exato; redes (CIDR) continuam em Grupos e IPs. Máx. 50 por vez.</div>
          </div>
          <div class="mb-3 d-none" id="acaoGrupoBox">
            <label class="form-label fw-semibold" for="acaoGrupo">Grupo de destino</label>
            <select id="acaoGrupo" class="form-select">
              <?php foreach ($gruposModal as $g): ?><option value="<?= (int) $g['id'] ?>"><?= h($g['nome']) ?> (<?= h($g['tipo']) ?>)</option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3 d-none" id="acaoMinBox">
            <label class="form-label fw-semibold" for="acaoMin">Duração da liberação</label>
            <select id="acaoMin" class="form-select">
              <option value="30">30 minutos</option><option value="60" selected>1 hora</option><option value="240">4 horas</option>
              <option value="480">8 horas</option><option value="1440">24 horas</option><option value="10080">7 dias</option>
            </select>
            <div class="form-text">Ao vencer, o equipamento volta sozinho à política anterior.</div>
          </div>
        </div>
        <div id="acaoPasso2" class="d-none">
          <p class="mb-2" id="acaoPreviaTexto"></p>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>IP</th><th>Política atual</th><th>Depois</th></tr></thead><tbody id="acaoPreviaCorpo"></tbody></table></div>
        </div>
        <div id="acaoPasso3" class="d-none"><div id="acaoResultado"></div></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="acaoFechar">Cancelar</button>
        <button type="button" class="btn btn-outline-primary d-none" id="acaoVoltar">Voltar</button>
        <button type="button" class="btn btn-primary" id="acaoPrevia">Pré-visualizar</button>
        <button type="button" class="btn btn-danger d-none" id="acaoConfirmar">Confirmar e aplicar</button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
    const el = document.getElementById('modalAcao'); if (!el) return;
    const modal = new bootstrap.Modal(el);
    const $ = id => document.getElementById(id);
    const titulos = {alterar_grupo:'Alterar grupo', bloquear:'Bloquear equipamento', liberar_temporario:'Liberação temporária', revogar:'Revogar liberação temporária'};
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let acao = null;
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    function passo(n){ [1,2,3].forEach(i => $('acaoPasso'+i).classList.toggle('d-none', i !== n));
        $('acaoPrevia').classList.toggle('d-none', n !== 1); $('acaoConfirmar').classList.toggle('d-none', n !== 2);
        $('acaoVoltar').classList.toggle('d-none', n !== 2); $('acaoFechar').textContent = n === 3 ? 'Fechar' : 'Cancelar'; }
    function erro(m){ const e = $('acaoErro'); e.textContent = m || ''; e.classList.toggle('d-none', !m); }
    function dados(previa){ const f = new URLSearchParams(); f.set('_csrf', csrf); f.set('acao', acao); f.set('ips', $('acaoIps').value);
        if (acao === 'alterar_grupo') f.set('grupo_id', $('acaoGrupo').value);
        if (acao === 'liberar_temporario') f.set('minutos', $('acaoMin').value); if (previa) f.set('previa', '1'); return f; }
    async function enviar(previa){ const r = await fetch('/politicas/acao', {method:'POST', headers:{'Accept':'application/json','X-CSRF-Token':csrf}, body: dados(previa)});
        let j = {}; try { j = await r.json(); } catch(e) {} return {ok:r.ok, j}; }
    window.gwosAcao = function(a, ips){
        acao = a; erro(''); passo(1);
        $('modalAcaoTitulo').textContent = titulos[a] || 'Ação rápida'; $('acaoIps').value = (ips || []).join(' ');
        $('acaoGrupoBox').classList.toggle('d-none', a !== 'alterar_grupo'); $('acaoMinBox').classList.toggle('d-none', a !== 'liberar_temporario');
        $('acaoConfirmar').className = 'btn d-none ' + (a === 'bloquear' ? 'btn-danger' : 'btn-primary'); modal.show();
    };
    $('acaoPrevia').addEventListener('click', async () => {
        erro(''); const {ok, j} = await enviar(true); if (!ok) return erro(j.erro || 'Falha ao gerar a prévia.');
        $('acaoPreviaCorpo').innerHTML = j.linhas.map(l => '<tr><td class="font-monospace">'+esc(l.ip)+'</td><td>'+esc(l.atual)+'</td><td><strong>'+esc(l.depois)+'</strong></td></tr>').join('');
        $('acaoPreviaTexto').textContent = (titulos[acao] || 'Ação') + (j.minutos ? ' por ' + j.minutos + ' min' : '') + ' — confirme os ' + j.linhas.length + ' equipamento(s) abaixo:'; passo(2);
    });
    $('acaoVoltar').addEventListener('click', () => passo(1));
    $('acaoConfirmar').addEventListener('click', async () => {
        erro(''); $('acaoConfirmar').disabled = true; const {j} = await enviar(false); $('acaoConfirmar').disabled = false;
        if (j.resultados) {
            $('acaoResultado').innerHTML = '<div class="alert alert-'+(j.ok?'success':'warning')+' py-2">'+esc(j.resumo)+'</div><ul class="small mb-0">'+
                j.resultados.map(r => '<li><span class="font-monospace">'+esc(r.ip)+'</span> — '+esc(r.ok ? r.mensagem : r.erro)+'</li>').join('')+'</ul>'; passo(3);
        } else erro(j.erro || 'Falha ao aplicar.');
    });
    el.addEventListener('hidden.bs.modal', () => { if (!$('acaoPasso3').classList.contains('d-none')) location.reload(); });
    document.addEventListener('click', ev => { const b = ev.target.closest('[data-gw-acao]'); if (!b) return; ev.preventDefault();
        let ips = []; if (b.dataset.gwIps) ips = b.dataset.gwIps.split(',');
        else if (b.dataset.gwSelecao) ips = [...document.querySelectorAll(b.dataset.gwSelecao + ':checked')].map(x => x.value);
        window.gwosAcao(b.dataset.gwAcao, ips); });
})();
</script>
