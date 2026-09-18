<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = (string)file_get_contents(__DIR__ . '/012_vip_idempotency_backfill.sql');
$affected = $pdo->exec($sql);
$remaining = (int)$pdo->query("SELECT COUNT(*) FROM vip_orders WHERE status='paid' AND vip_applied_at IS NULL AND username IS NOT NULL AND username<>''")->fetchColumn();
if ($remaining !== 0) {
    throw new RuntimeException('Ainda existem pedidos pagos legados sem marca de aplicação.');
}

echo "Migração 012 aplicada. Pedidos legados marcados: {$affected}.\n";
