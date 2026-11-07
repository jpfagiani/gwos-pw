<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title><?= h($titulo ?? 'GWOS') ?> — <?= h(config('app.nome', 'GWOS')) ?></title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/gwos.css">
    <script>try{if(localStorage.getItem('gwos_tema')==='escuro')document.documentElement.setAttribute('data-tema','escuro');}catch(e){}</script>
</head>
<body>
<?php
use App\Core\{Auth, Modulos, Database};
$adminNome   = Auth::admin()['nome'] ?? '';
$adminPerfil = Auth::admin()['perfil'] ?? '';
$iniciais = '';
foreach (array_slice(preg_split('/\s+/', trim($adminNome)) ?: [], 0, 2) as $parte) {
    $iniciais .= mb_strtoupper(mb_substr($parte, 0, 1));
}
$menuTemBanco = Modulos::temBanco();
$menuModulos  = Modulos::menu();
$menuAtual    = $modulo ?? '';
$alertasAbertosMenu = 0;
if ($menuTemBanco) {
    try { $alertasAbertosMenu = (int) Database::valor("SELECT COUNT(*) FROM alertas WHERE estado='aberto'"); } catch (\Throwable $e) { $alertasAbertosMenu = 0; }
}
$escudo = '<svg width="%d" height="%d" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M24 3 40 9v13c0 10.2-6.6 18.1-16 22C14.6 40.100 8 32.200 8 22V9z" fill="#0866d9" stroke="#9cc4ff" stroke-width="2"/><circle cx="24" cy="23" r="9" fill="none" stroke="#fff" stroke-width="1.6"/><ellipse cx="24" cy="23" rx="4" ry="9" fill="none" stroke="#fff" stroke-width="1.4"/><path d="M15 23h18M16.500 18h15M16.500 28h15" stroke="#fff" stroke-width="1.2"/></svg>';

// Menu principal (Relatório Mestre v2.0, imagem 3). Cada item: [rótulo, rota, ícone, marcadores ativos]
$menu = [];
if ($menuTemBanco) {
    $menu = [
        ['Dashboard', '/', 'bi-house-door-fill', ['dashboard']],
        ['Monitoramento', [
            ['Acessos em tempo real', '/monitoramento', ['monitoramento']],
            ['Bloqueios', '/monitoramento?decisao=bloqueado&aba=bloqueios', []],
            ['Sessões de usuários', '/internet', ['internet']],
            ['Registros do portal', '/internet/logs', []],
        ], 'bi-activity', ['monitoramento', 'internet']],
        ['Controle de Acesso', [
            ['Central de controle', '/controle-acesso', ['controle-acesso']],
            ['Grupos e IPs', '/grupos', ['grupos']],
            ['Domínios', '/dominios', ['dominios']],
        ], 'bi-shield-lock-fill', ['controle-acesso', 'grupos', 'dominios']],
        ['Ranking de Consumo', '/ranking', 'bi-bar-chart-line-fill', ['ranking']],
        ['Equipamentos', '/equipamentos', 'bi-pc-display', ['equipamentos']],
        ['Políticas e Regras', '/politicas', 'bi-journal-check', ['politicas', 'horarios', 'nat']],
        ['Agenda de Acessos', '/agenda', 'bi-calendar-week', ['agenda']],
        ['Alertas e Eventos', '/alertas', 'bi-bell-fill', ['alertas'], $alertasAbertosMenu],
        ['Relatórios', '/relatorios', 'bi-file-earmark-bar-graph-fill', ['relatorios']],
        ['Diagnóstico', '/diagnostico', 'bi-wrench-adjustable', ['diagnostico', 'tarefas']],
        ['Configurações', '/configuracoes', 'bi-gear-fill', ['configuracoes']],
        ['Ajuda', '/ajuda', 'bi-question-circle-fill', ['ajuda']],
    ];
} else {
    $menu = [
        ['Diagnóstico', '/diagnostico', 'bi-wrench-adjustable', ['diagnostico']],
        ['Ajuda', '/ajuda', 'bi-question-circle-fill', ['ajuda']],
    ];
}
?>
<div id="navBackdrop" aria-hidden="true"></div>

<header id="topbar">
    <a class="marca" href="/" aria-label="GWOS — página inicial">
        <?= sprintf($escudo, 38, 38) ?>
        <span><div class="nome">GWOS</div><div class="sub">Gerenciador de rede e controle de acesso</div></span>
    </a>
    <button id="btnSidebar" class="menu-toggle" type="button" aria-label="Abrir ou fechar o menu" aria-controls="sidebar" aria-expanded="false"><i class="bi bi-list"></i></button>
    <?php if ($menuTemBanco): ?>
    <form class="busca ms-2" action="/busca" method="get" role="search">
        <i class="bi bi-search"></i>
        <input class="form-control" type="search" name="q" placeholder="Buscar IP, MAC, host, usuário ou domínio" aria-label="Busca global" maxlength="120" value="<?= h($_GET['q'] ?? '') ?>">
    </form>
    <?php endif; ?>
    <div class="ms-auto d-flex align-items-center gap-3">
        <span class="status-pill" id="statusServidor" title="Resposta do painel"><span class="dot"></span><span class="txt">Servidor Online</span></span>
        <span class="relogio" id="relogioTopo"></span>
        <?php if ($menuTemBanco): ?>
        <a class="sino" href="/alertas" aria-label="Alertas abertos: <?= (int) $alertasAbertosMenu ?>"><i class="bi bi-bell"></i><?php if ($alertasAbertosMenu > 0): ?><span class="contagem"><?= $alertasAbertosMenu > 99 ? '99+' : (int) $alertasAbertosMenu ?></span><?php endif; ?></a>
        <?php endif; ?>
        <div class="dropdown">
            <button class="usuario dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="avatar"><?= h($iniciais ?: '?') ?></span>
                <span class="usuario-nome"><?= h($adminNome) ?></span>
                <?php if ($adminPerfil): ?><span class="perfil-badge"><?= h($adminPerfil) ?></span><?php endif; ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><a class="dropdown-item" href="/senha/trocar"><i class="bi bi-key-fill me-2"></i>Alterar senha</a></li>
                <?php if ($adminPerfil === 'superadmin'): ?><li><a class="dropdown-item" href="/senha/gerar"><i class="bi bi-person-lock me-2"></i>Reset de senha</a></li><?php endif; ?>
                <li><button class="dropdown-item" type="button" id="btnTema"><i class="bi bi-moon-stars me-2"></i>Tema escuro</button></li>
                <li><hr class="dropdown-divider"></li>
                <li><form method="post" action="/logout" class="m-0"><?= csrf_field() ?><button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sair</button></form></li>
            </ul>
        </div>
    </div>
</header>

<aside id="sidebar" aria-label="Navegação principal">
    <nav class="nav flex-column">
    <?php foreach ($menu as $i => $item):
        $rotulo = $item[0]; $icone = $item[2]; $marcas = $item[3]; $contagem = $item[4] ?? 0;
        if (is_array($item[1])): $aberto = in_array($menuAtual, $marcas, true); ?>
        <div class="nav-grupo <?= $aberto ? 'ativo' : '' ?>">
            <button type="button" data-bs-toggle="collapse" data-bs-target="#sub<?= $i ?>" aria-expanded="<?= $aberto ? 'true' : 'false' ?>">
                <i class="bi <?= h($icone) ?>"></i> <?= h($rotulo) ?> <i class="bi bi-chevron-down seta"></i>
            </button>
            <div class="collapse sub <?= $aberto ? 'show' : '' ?>" id="sub<?= $i ?>">
                <?php foreach ($item[1] as $sub): ?>
                <?php
                    $su = parse_url($sub[1]); parse_str($su['query'] ?? '', $sq);
                    $caminhoAtual = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
                    $subAtivo = ($su['path'] ?? '') === $caminhoAtual && ($sq['aba'] ?? '') === ($_GET['aba'] ?? '');
                ?>
                <a class="nav-link <?= $subAtivo ? 'active' : '' ?>" href="<?= h($sub[1]) ?>"><?= h($sub[0]) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <a class="nav-link <?= in_array($menuAtual, $marcas, true) ? 'active' : '' ?>" href="<?= h($item[1]) ?>">
            <i class="bi <?= h($icone) ?>"></i> <?= h($rotulo) ?>
            <?php if ($contagem > 0): ?><span class="badge-menu"><?= (int) $contagem ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
    <?php endforeach; ?>

        <span class="nav-section">Servidor</span>
        <a class="nav-link <?= $menuAtual === 'modulos' ? 'active' : '' ?>" href="/modulos"><i class="bi bi-grid-3x3-gap-fill"></i> Módulos</a>
        <?php foreach ($menuModulos as $secao => $itens): foreach ($itens as $mi): ?>
        <a class="nav-link <?= $menuAtual === $mi['marcador'] ? 'active' : '' ?>" href="<?= h($mi['rota']) ?>"><i class="bi <?= h($mi['icone']) ?>"></i> <?= h($mi['titulo']) ?></a>
        <?php endforeach; endforeach; ?>
        <?php if ($menuTemBanco): ?>
        <a class="nav-link <?= $menuAtual === 'tarefas' ? 'active' : '' ?>" href="/tarefas"><i class="bi bi-list-task"></i> Histórico de tarefas</a>
        <?php endif; ?>
    </nav>
    <div class="painel-marca">
        <?= sprintf($escudo, 40, 40) ?>
        <div class="t">GWOS</div>
        <div class="s">Sua rede sob controle</div>
        <form method="post" action="/logout" class="m-0"><?= csrf_field() ?><button class="btn btn-sm btn-light w-100" type="submit"><i class="bi bi-box-arrow-right"></i> Sair</button></form>
    </div>
</aside>

<div id="content">
    <div class="corpo">
        <?php
        $flash_sucesso = \App\Core\Session::flash('sucesso');
        $flash_erro    = \App\Core\Session::flash('erro');
        if ($flash_sucesso): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert"><?= h($flash_sucesso) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($flash_erro): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= h($flash_erro) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <?= $conteudo ?? '' ?>
    </div>
</div>

<footer id="rodape">
    <span><i class="bi bi-shield-check"></i> GWOS v<?= h(config('app.versao', '2.0')) ?> | Sistema de Gerenciamento de Rede</span>
    <span class="dir">Última atualização: <span id="rodapeHora"><?= date('d/m/Y H:i:s') ?></span> &nbsp; <span class="dot" id="rodapeDot"></span><span id="rodapeEstado">Online</span></span>
</footer>

<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
<?php if ($menuTemBanco) { require __DIR__ . '/partials/acao_rapida.php'; } ?>
<script>
(function(){
    const pad = n => String(n).padStart(2,'0');
    function tick(){
        const n = new Date(), el = document.getElementById('relogioTopo');
        if (el) el.textContent = pad(n.getDate())+'/'+pad(n.getMonth()+1)+'/'+n.getFullYear()+' '+pad(n.getHours())+':'+pad(n.getMinutes())+':'+pad(n.getSeconds());
    }
    tick(); setInterval(tick, 1000);

    // Pulso do painel: se o servidor para de responder, o selo muda de verdade.
    const pill = document.getElementById('statusServidor'), dot = pill.querySelector('.dot'), txt = pill.querySelector('.txt');
    const rDot = document.getElementById('rodapeDot'), rEst = document.getElementById('rodapeEstado'), rHora = document.getElementById('rodapeHora');
    function estado(ok){
        dot.classList.toggle('off', !ok); txt.textContent = ok ? 'Servidor Online' : 'Sem resposta do servidor';
        rDot.style.background = ok ? '' : '#dc2626'; rEst.textContent = ok ? 'Online' : 'Offline';
    }
    async function pulso(){
        try {
            const r = await fetch('/ping', {cache:'no-store', headers:{'Accept':'application/json'}});
            if (!r.ok) throw new Error('http');
            const d = await r.json(); estado(true); rHora.textContent = d.hora || rHora.textContent;
        } catch (e) { estado(false); }
    }
    setInterval(pulso, 30000);

    const tema = document.getElementById('btnTema');
    if (tema) tema.addEventListener('click', () => {
        const escuro = document.documentElement.getAttribute('data-tema') === 'escuro';
        if (escuro) document.documentElement.removeAttribute('data-tema'); else document.documentElement.setAttribute('data-tema','escuro');
        try { localStorage.setItem('gwos_tema', escuro ? 'claro' : 'escuro'); } catch (e) {}
    });
})();
</script>
</body>
</html>
