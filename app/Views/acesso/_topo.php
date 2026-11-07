<?php /* Cabeçalho comum das telas públicas do portal de acesso. Espera $titulo. */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title><?= h($titulo ?? 'Acesso à internet') ?> — GWOS</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/gwos.css">
</head>
<body class="login-page">
<div class="login-caixa">
    <div class="login-topo">
        <svg class="escudo" width="64" height="64" viewBox="0 0 48 48" aria-hidden="true"><path d="M24 3 40 9v13c0 10.2-6.6 18.1-16 22C14.6 40.1 8 32.2 8 22V9z" fill="#0866d9" stroke="#9cc4ff" stroke-width="2"/><circle cx="24" cy="23" r="9" fill="none" stroke="#fff" stroke-width="1.6"/><ellipse cx="24" cy="23" rx="4" ry="9" fill="none" stroke="#fff" stroke-width="1.4"/><path d="M15 23h18M16.5 18h15M16.5 28h15" stroke="#fff" stroke-width="1.2"/></svg>
        <h1 style="font-size:1.9rem">GWOS</h1>
        <p>Portal de Acesso à Internet</p>
        <p class="small">Conectividade segura para sua produtividade</p>
    </div>
    <div class="login-cartao">
    <?php if (!empty($sucesso)): ?><div class="alert alert-success py-2 small"><?= h($sucesso) ?></div><?php endif; ?>
    <?php if (!empty($erro)): ?><div class="alert alert-danger py-2 small"><?= h($erro) ?></div><?php endif; ?>
