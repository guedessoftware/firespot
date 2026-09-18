<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("ALTER TABLE guest_orders
  ADD INDEX IF NOT EXISTS idx_guest_orders_partner_mac_credit
    (partner_id,device_mac,status,radius_cleaned_at,paid_at)");

$st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guest_orders'
    AND INDEX_NAME='idx_guest_orders_partner_mac_credit'");
$st->execute();
if ((int) $st->fetchColumn() < 1) {
    throw new RuntimeException('Índice de recuperação não encontrado após a migração.');
}

echo "Migração 004 aplicada e validada. Pedidos e portais anteriores preservados.\n";
