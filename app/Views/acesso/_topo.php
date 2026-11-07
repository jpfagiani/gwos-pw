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
    <style>
        body { background:#0e1621; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:1rem; }
        .box { width:100%; max-width:400px; }
        .card { border:none; border-radius:14px; }
        .form-control { border-radius:8px; }
        .btn-primary { background:#2f81f7; border-color:#2f81f7; border-radius:8px; padding:.55rem; }
        .btn-primary:hover { background:#2569d3; border-color:#2569d3; }
        .logo { width:52px; height:52px; border-radius:14px; background:#2f81f7; display:inline-flex; align-items:center; justify-content:center; color:#fff; font-size:1.6rem; }
        .sub { color:#8aa0b8; }
        a { text-decoration:none; }
    </style>
</head>
<body>
<div class="box">
    <div class="text-center mb-4">
        <div class="logo"><i class="bi bi-shield-lock"></i></div>
        <h4 class="text-white mt-2 fw-bold">Acesso à internet</h4>
        <p class="sub small mb-0">Identifique-se para navegar</p>
    </div>
    <div class="card shadow-lg"><div class="card-body p-4">
    <?php if (!empty($sucesso)): ?><div class="alert alert-success py-2 small"><?= h($sucesso) ?></div><?php endif; ?>
    <?php if (!empty($erro)): ?><div class="alert alert-danger py-2 small"><?= h($erro) ?></div><?php endif; ?>
