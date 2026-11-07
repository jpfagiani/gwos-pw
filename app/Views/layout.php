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
    <style>
        :root {
            --gw-navy: #102b4e;
            --gw-navy-2: #0b1f38;
            --gw-navy-hover: #193b63;
            --gw-active: #1b426e;
            --gw-accent: #0866d9;
            --gw-muted: #d0dbea;
            --gw-section: #9aacc2;
            --gw-page: #f3f6fa;
            --gw-border: #dce4ee;
        }
        body { background: var(--gw-page); }

        /* ── Sidebar ─────────────────────────────────────── */
        #sidebar {
            min-height: 100vh; width: 240px;
            background: var(--gw-navy); color: var(--gw-muted);
            position: fixed; top: 0; left: 0;
            display: flex; flex-direction: column; z-index: 100;
        }
        #sidebar .logo {
            display: flex; align-items: center; gap: .65rem;
            padding: 1rem 1.1rem;
            border-bottom: 1px solid var(--gw-navy-2);
        }
        #sidebar .logo .logo-tile {
            width: 34px; height: 34px; flex: none;
            border-radius: 9px; background: var(--gw-accent);
            display: flex; align-items: center; justify-content: center;
        }
        #sidebar .logo .logo-nome {
            color: #fff; font-weight: 600; font-size: 1.05rem;
            letter-spacing: .5px; line-height: 1.15;
        }
        #sidebar .logo .logo-sub {
            color: var(--gw-section); font-size: .68rem;
            font-family: var(--bs-font-monospace);
        }
        #sidebar .nav-section {
            font-size: .68rem; text-transform: uppercase;
            letter-spacing: .08em; color: var(--gw-section);
            padding: .9rem 1.1rem .25rem;
        }
        #sidebar .nav-link {
            color: var(--gw-muted); font-size: .9rem;
            display: flex; align-items: center; gap: .6rem;
            padding: .5rem .8rem; margin: 1px .5rem;
            border-radius: 8px; border-left: 3px solid transparent;
        }
        #sidebar .nav-link:hover { background: var(--gw-navy-hover); color: #fff; }
        #sidebar .nav-link.active {
            background: var(--gw-active); color: #fff;
            border-left-color: var(--gw-accent); border-radius: 0 8px 8px 0;
        }
        #sidebar .nav-link i { font-size: .95rem; width: 1.1rem; text-align: center; }
        #sidebar .sair {
            margin-top: auto; border-top: 1px solid var(--gw-navy-2);
        }
        #sidebar .sair .nav-link { color: #e07a7a; margin: .4rem .5rem; }
        #sidebar .sair .nav-link:hover { background: rgba(224,122,122,.12); color: #f1a1a1; }

        /* ── Conteúdo / topbar ───────────────────────────── */
        #content { margin-left: 240px; min-height: 100vh; }
        #topbar {
            background: #fff; border-bottom: 1px solid var(--gw-border);
            padding: .65rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
        }
        #topbar .titulo { font-weight: 600; color: #1e293b; font-size: 1rem; }
        #topbar .relogio {
            font-size: .78rem; color: #475569; background: var(--gw-page);
            border-radius: 6px; padding: .25rem .6rem;
        }
        #topbar .avatar {
            width: 30px; height: 30px; border-radius: 50%;
            background: #dbe9fe; color: #1d4ed8;
            display: flex; align-items: center; justify-content: center;
            font-size: .72rem; font-weight: 600;
        }
        #topbar .perfil-badge {
            font-size: .68rem; background: #ede9fe; color: #5b21b6;
            border-radius: 5px; padding: .2rem .45rem; font-weight: 500;
        }

        /* ── Refinamento global (cards, tabelas, badges) ─── */
        .card { border: 1px solid var(--gw-border); border-radius: 12px; }
        .card.shadow-sm, .card.border-0 { border: 1px solid var(--gw-border) !important; box-shadow: 0 1px 2px rgba(15,23,42,.04) !important; }
        .card-header { background: #fff !important; border-bottom: 1px solid #e9eef4; border-radius: 12px 12px 0 0 !important; }
        .card > .table-responsive:last-child .table,
        .card > div:last-child > .table { border-bottom-left-radius: 12px; border-bottom-right-radius: 12px; overflow: hidden; }

        .table thead th {
            font-size: .7rem; text-transform: uppercase; letter-spacing: .05em;
            color: #64748b; font-weight: 600; border-bottom-width: 1px;
        }
        .table-light { --bs-table-bg: #f8fafc; }

        .badge { font-weight: 500; }
        .badge.bg-success   { background: #e7f6ec !important; color: #166534 !important; }
        .badge.bg-danger    { background: #fdebec !important; color: #991b1b !important; }
        .badge.bg-secondary { background: #eef2f7 !important; color: #475569 !important; }
        .badge.bg-warning   { background: #faeeda !important; color: #854f0b !important; }
        .badge.bg-info      { background: #dbe9fe !important; color: #1d4ed8 !important; }
        .badge.bg-primary   { background: #dbe9fe !important; color: #1d4ed8 !important; }
        .badge.bg-dark      { background: #e2e8f0 !important; color: #334155 !important; }

        .btn { border-radius: 8px; }
        .btn-primary { background: var(--gw-accent); border-color: var(--gw-accent); }
        .btn-primary:hover { background: #2569d3; border-color: #2569d3; }
        .form-control, .form-select { border-radius: 8px; }
        .alert { border-radius: 10px; }
        .modal-content { border-radius: 14px; border: none; }
        .text-info { color: #1d4ed8 !important; }
        #navBackdrop { display:none; }
        .menu-toggle { display:none; }
        #sidebar nav { overflow-y:auto; flex:1; }
        #sidebar .sair button { color:#fca5a5; font-size:.9rem; padding:.5rem .8rem; margin:.4rem .5rem; }
        #sidebar .sair button:hover { background:rgba(224,122,122,.12)!important; color:#fff; }
        :focus-visible { outline:3px solid #60a5fa; outline-offset:2px; }
        @media (max-width: 767.98px) {
            #sidebar { width:min(290px, 86vw); transform:translateX(-105%); transition:transform .2s ease; }
            #sidebar.aberto { transform:translateX(0); box-shadow:0 0 28px #0b1f3866; }
            #content { margin-left:0; }
            #navBackdrop.visivel { display:block; position:fixed; inset:0; z-index:90; background:#0b1f3880; }
            .menu-toggle { display:inline-flex; align-items:center; justify-content:center; }
            #topbar { padding:.65rem .85rem; }
            #topbar .usuario-nome, #topbar .perfil-badge { display:none; }
            .container-fluid.p-4 { padding:1rem!important; }
        }
        @media (prefers-reduced-motion: reduce) { #sidebar { transition:none; } }
    </style>
</head>
<body>

<?php
$adminNome   = \App\Core\Auth::admin()['nome'] ?? '';
$adminPerfil = \App\Core\Auth::admin()['perfil'] ?? '';
$iniciais = '';
foreach (array_slice(preg_split('/\s+/', trim($adminNome)) ?: [], 0, 2) as $parte) {
    $iniciais .= mb_strtoupper(mb_substr($parte, 0, 1));
}
$hostPainel = parse_url(config('app.url', ''), PHP_URL_HOST) ?: '';
?>

<div id="navBackdrop" aria-hidden="true"></div>
<aside id="sidebar" aria-label="Navegação principal">
    <div class="logo">
        <div class="logo-tile">
            <svg width="21" height="21" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2 L20 5 V11 C20 16.2 16.6 19.8 12 21.5 C7.4 19.8 4 16.2 4 11 V5 Z" fill="#fff" opacity=".14"/>
                <path d="M12 2 L20 5 V11 C20 16.2 16.6 19.8 12 21.5 C7.4 19.8 4 16.2 4 11 V5 Z" fill="none" stroke="#fff" stroke-width="1.4"/>
                <path d="M8.4 9.1 H12.4 A2 2 0 0 1 14.4 11.1 V13.8" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round"/>
                <circle cx="8.4" cy="9.1" r="1.4" fill="#fff"/>
                <circle cx="14.4" cy="14.2" r="1.4" fill="#fff"/>
            </svg>
        </div>
        <div>
            <div class="logo-nome">GWOS</div>
            <?php if ($hostPainel): ?><div class="logo-sub"><?= h($hostPainel) ?></div><?php endif; ?>
        </div>
    </div>
<?php
    // Menu dirigido pelos módulos instalados. As seções que dependem do banco
    // só aparecem se o módulo 10-banco-mariadb existir — sem ele o painel roda
    // em modo leve, com as telas que vivem em arquivo.
    $menuTemBanco  = \App\Core\Modulos::temBanco();
    $menuModulos   = \App\Core\Modulos::menu();
    $menuAtual     = $modulo ?? '';
?>
    <nav class="nav flex-column pt-1">
        <span class="nav-section">Principal</span>
        <?php if ($menuTemBanco): ?>
        <a class="nav-link <?= $menuAtual === 'dashboard' ? 'active' : '' ?>" href="/">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <?php endif; ?>
        <a class="nav-link <?= $menuAtual === 'modulos' ? 'active' : '' ?>" href="/modulos">
            <i class="bi bi-grid-3x3-gap-fill"></i> Módulos
        </a>

        <?php if ($menuTemBanco): ?>
        <span class="nav-section">Controle de acesso</span>
        <a class="nav-link <?= $menuAtual === 'grupos' ? 'active' : '' ?>" href="/grupos">
            <i class="bi bi-people-fill"></i> Grupos e IPs
        </a>
        <a class="nav-link <?= $menuAtual === 'dominios' ? 'active' : '' ?>" href="/dominios">
            <i class="bi bi-globe2"></i> Domínios
        </a>
        <a class="nav-link <?= $menuAtual === 'horarios' ? 'active' : '' ?>" href="/horarios">
            <i class="bi bi-clock-fill"></i> Horários
        </a>
        <a class="nav-link <?= in_array($menuAtual, ['agenda'] , true) ? 'active' : '' ?>" href="/agenda">
            <i class="bi bi-calendar-week"></i> Agenda e simulador
        </a>
        <a class="nav-link <?= $menuAtual === 'monitoramento' ? 'active' : '' ?>" href="/monitoramento">
            <i class="bi bi-activity"></i> Monitoramento
        </a>
        <a class="nav-link <?= $menuAtual === 'ranking' ? 'active' : '' ?>" href="/ranking">
            <i class="bi bi-bar-chart-line"></i> Ranking de consumo
        </a>
        <a class="nav-link <?= $menuAtual === 'equipamentos' ? 'active' : '' ?>" href="/equipamentos">
            <i class="bi bi-pc-display"></i> Equipamentos
        </a>
        <a class="nav-link <?= $menuAtual === 'controle-acesso' ? 'active' : '' ?>" href="/controle-acesso">
            <i class="bi bi-shield-check"></i> Controle de acesso
        </a>
        <a class="nav-link <?= $menuAtual === 'alertas' ? 'active' : '' ?>" href="/alertas">
            <i class="bi bi-bell"></i> Alertas e eventos
        </a>
        <?php endif; ?>

        <?php foreach ($menuModulos as $secao => $itens): ?>
        <span class="nav-section"><?= h($secao) ?></span>
            <?php foreach ($itens as $item): ?>
        <a class="nav-link <?= $menuAtual === $item['marcador'] ? 'active' : '' ?>" href="<?= h($item['rota']) ?>">
            <i class="bi <?= h($item['icone']) ?>"></i> <?= h($item['titulo']) ?>
        </a>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?php if ($menuTemBanco): ?>
        <span class="nav-section">Rede</span>
        <a class="nav-link <?= $menuAtual === 'nat' ? 'active' : '' ?>" href="/nat">
            <i class="bi bi-arrow-left-right"></i> NAT 1:1
        </a>

        <span class="nav-section">Relatórios</span>
        <a class="nav-link <?= $menuAtual === 'internet' ? 'active' : '' ?>" href="/internet">
            <i class="bi bi-person-lock"></i> Acesso com login
        </a>
        <a class="nav-link <?= $menuAtual === 'relatorios' ? 'active' : '' ?>" href="/relatorios">
            <i class="bi bi-bar-chart-fill"></i> Relatórios
        </a>
        <?php endif; ?>

        <span class="nav-section">Operações</span>
        <a class="nav-link <?= $menuAtual === 'tarefas' ? 'active' : '' ?>" href="/tarefas">
            <i class="bi bi-list-task"></i> Histórico de tarefas
        </a>

        <span class="nav-section">Sistema</span>
        <?php if ($menuTemBanco): ?>
        <a class="nav-link <?= $menuAtual === 'configuracoes' ? 'active' : '' ?>" href="/configuracoes">
            <i class="bi bi-gear-fill"></i> Configurações
        </a>
        <?php endif; ?>
        <a class="nav-link <?= ($modulo ?? '') === 'senha' ? 'active' : '' ?>" href="/senha/trocar">
            <i class="bi bi-key-fill"></i> Alterar senha
        </a>
        <?php if ($adminPerfil === 'superadmin'): ?>
        <a class="nav-link" href="/senha/gerar">
            <i class="bi bi-person-lock"></i> Reset de senha
        </a>
        <?php endif; ?>
    </nav>
    <div class="sair">
        <form method="post" action="/logout" class="m-0">
            <?= csrf_field() ?>
            <button class="nav-link w-100 text-start border-0 bg-transparent" type="submit">
                <i class="bi bi-box-arrow-left"></i> Sair
            </button>
        </form>
    </div>
</aside>

<div id="content">
    <div id="topbar">
        <div class="d-flex align-items-center gap-2">
            <button id="btnSidebar" class="btn btn-light btn-sm menu-toggle" type="button" aria-label="Abrir menu" aria-controls="sidebar" aria-expanded="false"><i class="bi bi-list fs-5"></i></button>
            <span class="titulo"><?= h($titulo ?? '') ?></span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span id="relogioTopo" class="relogio font-monospace"></span>
            <span class="avatar"><?= h($iniciais ?: '?') ?></span>
            <span class="small text-secondary usuario-nome"><?= h($adminNome) ?></span>
            <?php if ($adminPerfil): ?><span class="perfil-badge"><?= h($adminPerfil) ?></span><?php endif; ?>
        </div>
    </div>

    <div class="container-fluid p-4">
        <?php
        $flash_sucesso = \App\Core\Session::flash('sucesso');
        $flash_erro    = \App\Core\Session::flash('erro');
        if ($flash_sucesso): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= h($flash_sucesso) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($flash_erro): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= h($flash_erro) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?= $conteudo ?? '' ?>
    </div>
</div>

<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
<script>
(function(){
    const dias = ['dom','seg','ter','qua','qui','sex','sáb'];
    function tick(){
        const n = new Date(), el = document.getElementById('relogioTopo');
        if(el) el.textContent = n.toTimeString().slice(0,8)+' · '+dias[n.getDay()]+' '+n.toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'});
    }
    tick(); setInterval(tick, 1000);
})();
</script>
</body>
</html>
