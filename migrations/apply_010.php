<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("ALTER TABLE guest_orders
    ADD COLUMN IF NOT EXISTS payment_window_token VARCHAR(40) DEFAULT NULL AFTER radius_cleaned_at,
    ADD COLUMN IF NOT EXISTS payment_window_started_at DATETIME DEFAULT NULL AFTER payment_window_token,
    ADD COLUMN IF NOT EXISTS payment_window_expires_at DATETIME DEFAULT NULL AFTER payment_window_started_at,
    ADD COLUMN IF NOT EXISTS payment_window_closed_at DATETIME DEFAULT NULL AFTER payment_window_expires_at,
    ADD INDEX IF NOT EXISTS idx_guest_orders_payment_window (payment_window_token,payment_window_closed_at)");

$st = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guest_orders'
      AND COLUMN_NAME IN ('payment_window_token','payment_window_started_at','payment_window_expires_at','payment_window_closed_at')");
if ((int) $st->fetchColumn() !== 4) throw new RuntimeException('Colunas da janela de pagamento não encontradas.');

echo "Migração 010 aplicada. Janela temporária do Portal V3 habilitada.\n";
