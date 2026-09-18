<?php
require_once __DIR__ . '/../app/session_boot.php';
$username = $_SESSION['cliente_username'] ?? '';
?><!DOCTYPE html>
<html lang="pt-BR">

<head>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conectar</title>
    <link rel="stylesheet" href="assets/css/portal.css">
    <script defer src="assets/js/portal.js"></script>
</head>

<body>
    <div class="header inline"><a href="cliente.php">← Minha Conta</a><strong>Conectar</strong><span></span></div>
    <div class="container">
        <div class="card">
            <h3>Quase lá…</h3>
            <p>Seu usuário <strong><?= htmlspecialchars($username) ?></strong> está pronto. Conclua a autenticação na
                página do Mikrotik/Hotspot.</p>
            <p class="muted">Integre aqui o redirecionamento automático para <code>$link-login</code> do Mikrotik.</p>
        </div>
    </div>
    <script>FIRESPOT_PORTAL?.sendDevice(<?= json_encode($username) ?>);</script>
</body>

</html>