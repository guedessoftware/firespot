<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("ALTER TABLE partners
    ADD COLUMN IF NOT EXISTS payment_window_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 2 AFTER window_per_device_minutes");

$pdo->exec("CREATE TABLE IF NOT EXISTS guest_payment_windows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    partner_id INT(11) NOT NULL,
    device_mac VARCHAR(64) NOT NULL,
    device_ip VARCHAR(45) NOT NULL,
    token VARCHAR(40) NOT NULL,
    started_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    closed_at DATETIME DEFAULT NULL,
    close_reason VARCHAR(32) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guest_payment_windows_token (token),
    KEY idx_guest_payment_windows_device (partner_id,device_mac,started_at),
    KEY idx_guest_payment_windows_expiry (closed_at,expires_at),
    KEY idx_guest_payment_windows_order (order_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("INSERT IGNORE INTO guest_payment_windows
    (order_id,partner_id,device_mac,device_ip,token,started_at,expires_at,closed_at,close_reason)
  SELECT id,partner_id,COALESCE(device_mac,''),COALESCE(device_ip,''),payment_window_token,
         payment_window_started_at,payment_window_expires_at,payment_window_closed_at,
         CASE
           WHEN payment_window_closed_at IS NOT NULL THEN 'legacy_closed'
           WHEN payment_window_expires_at <= NOW() THEN 'legacy_expired'
           ELSE NULL
         END
    FROM guest_orders
   WHERE payment_window_token IS NOT NULL
     AND payment_window_started_at IS NOT NULL
     AND payment_window_expires_at IS NOT NULL");

$hasPartnerSetting = (bool) $pdo->query("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partners' AND COLUMN_NAME='payment_window_minutes' LIMIT 1")->fetchColumn();
$hasAuditTable = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guest_payment_windows' LIMIT 1")->fetchColumn();
if (!$hasPartnerSetting || !$hasAuditTable) {
    throw new RuntimeException('A política da janela temporária não foi criada por completo.');
}

echo "Migração 014 aplicada. Duração por estabelecimento e proteção das janelas Pix habilitadas.\n";
