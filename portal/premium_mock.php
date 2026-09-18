<?php
// SEC-004: concessão gratuita de demonstração desativada em produção.
http_response_code(404);
exit;

require_once __DIR__ . '/../app/session_boot.php'; require_once __DIR__.'/../app/db.php'; require_once __DIR__.'/../app/auth_utils.php';
$username=$_SESSION['cliente_username']??''; if($username===''){ header("Location: index.php"); exit; }
$msg=''; try{ $pdo=db(); $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"); $pdo->exec("SET time_zone='-04:00'");
  grant_premium_day($pdo,$username,'MOCK'); reconcile_premium_group($pdo,$username); $msg='Premium ativado por 24h.';
}catch(\Throwable $e){ $msg='Falha ao ativar Premium.'; }
?><!DOCTYPE html><html lang="pt-BR"><head>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Premium</title>
<link rel="stylesheet" href="assets/css/portal.css">
</head><body>
<div class="header inline"><a href="cliente.php">← Minha Conta</a><strong>Premium</strong><span></span></div>
<div class="container"><div class="card">
<p><?= htmlspecialchars($msg) ?></p>
<a class="btn primary" href="cliente.php">Voltar</a>
</div></div></body></html>
