<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$withoutExpiry = (int)$pdo->query("SELECT COUNT(*) FROM guest_orders
    WHERE payment_method='pix' AND provider_payment_id IS NOT NULL
      AND payment_expires_at IS NULL")->fetchColumn();
if ($withoutExpiry !== 0) {
    fwrite(STDERR,"Smoke 033 falhou: {$withoutExpiry} pedidos Pix sem expiração.\n");
    exit(1);
}
$expiredFailures = (int)$pdo->query("SELECT COUNT(*) FROM guest_orders
    WHERE status='payment_failed' AND LOWER(COALESCE(payment_status_detail,''))='expired'")->fetchColumn();
if ($expiredFailures !== 0) {
    fwrite(STDERR,"Smoke 033 falhou: {$expiredFailures} pedidos expirados ainda classificados como falha.\n");
    exit(1);
}

echo "Smoke 033 OK: todos os pedidos Pix possuem vencimento explícito.\n";
