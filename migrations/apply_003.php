<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$statements = [
    "CREATE TABLE IF NOT EXISTS payment_wallets (
      id INT(11) NOT NULL AUTO_INCREMENT, provider VARCHAR(32) NOT NULL DEFAULT 'mercadopago',
      name VARCHAR(120) NOT NULL, environment ENUM('sandbox','production') NOT NULL DEFAULT 'production',
      public_key VARCHAR(255) NOT NULL, access_token_encrypted TEXT NOT NULL, credential_hint VARCHAR(32) DEFAULT NULL,
      active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id), KEY idx_payment_wallets_provider_active (provider,active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "ALTER TABLE partners
      ADD COLUMN IF NOT EXISTS portal_mode ENUM('inherit','classic','v2','v3') NOT NULL DEFAULT 'inherit' AFTER require_auth,
      ADD COLUMN IF NOT EXISTS independent_billing TINYINT(1) NOT NULL DEFAULT 0 AFTER portal_mode,
      ADD COLUMN IF NOT EXISTS payment_wallet_id INT(11) DEFAULT NULL AFTER independent_billing,
      ADD INDEX IF NOT EXISTS idx_partners_portal_mode (portal_mode),
      ADD INDEX IF NOT EXISTS idx_partners_payment_wallet (payment_wallet_id)",
    "CREATE TABLE IF NOT EXISTS partner_payment_plans (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, partner_id INT(11) NOT NULL, name VARCHAR(120) NOT NULL,
      description VARCHAR(255) DEFAULT NULL, price_cents INT UNSIGNED NOT NULL, duration_minutes INT UNSIGNED NOT NULL,
      download_kbps INT UNSIGNED NOT NULL DEFAULT 0, upload_kbps INT UNSIGNED NOT NULL DEFAULT 0,
      active TINYINT(1) NOT NULL DEFAULT 1, sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id), KEY idx_partner_payment_plans_partner (partner_id,active,sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS guest_orders (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, public_id CHAR(32) NOT NULL, order_token_hash CHAR(64) NOT NULL,
      external_ref VARCHAR(64) NOT NULL, partner_id INT(11) NOT NULL, wallet_id INT(11) DEFAULT NULL,
      provider VARCHAR(32) NOT NULL DEFAULT 'mercadopago', provider_payment_id VARCHAR(96) DEFAULT NULL,
      payment_method VARCHAR(32) DEFAULT NULL, payment_status_detail VARCHAR(96) DEFAULT NULL,
      status ENUM('pending','paid','payment_failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
      plan_source ENUM('global','partner') NOT NULL, plan_id BIGINT UNSIGNED NOT NULL, plan_name VARCHAR(120) NOT NULL,
      amount_cents INT UNSIGNED NOT NULL, duration_minutes INT UNSIGNED NOT NULL,
      download_kbps INT UNSIGNED NOT NULL DEFAULT 0, upload_kbps INT UNSIGNED NOT NULL DEFAULT 0,
      device_mac VARCHAR(64) DEFAULT NULL, device_ip VARCHAR(45) DEFAULT NULL, radius_username VARCHAR(64) DEFAULT NULL,
      access_granted_at DATETIME DEFAULT NULL, radius_cleaned_at DATETIME DEFAULT NULL,
      payment_window_token VARCHAR(40) DEFAULT NULL, payment_window_started_at DATETIME DEFAULT NULL,
      payment_window_expires_at DATETIME DEFAULT NULL, payment_window_closed_at DATETIME DEFAULT NULL,
      payment_expires_at DATETIME DEFAULT NULL,
      paid_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id), UNIQUE KEY uq_guest_orders_public_id (public_id), UNIQUE KEY uq_guest_orders_external_ref (external_ref),
      KEY idx_guest_orders_payment (provider,provider_payment_id), KEY idx_guest_orders_partner_status (partner_id,status,created_at),
      KEY idx_guest_orders_partner_ip (partner_id,device_ip,created_at),
      KEY idx_guest_orders_partner_mac_credit (partner_id,device_mac,status,radius_cleaned_at,paid_at),
      KEY idx_guest_orders_payment_window (payment_window_token,payment_window_closed_at),
      KEY idx_guest_orders_wallet (wallet_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "ALTER TABLE guest_orders ADD INDEX IF NOT EXISTS idx_guest_orders_partner_ip (partner_id,device_ip,created_at)",
    "ALTER TABLE guest_orders ADD INDEX IF NOT EXISTS idx_guest_orders_partner_mac_credit (partner_id,device_mac,status,radius_cleaned_at,paid_at)",
];

foreach ($statements as $index => $sql) {
    $pdo->exec($sql);
    echo 'Etapa ' . ($index + 1) . '/' . count($statements) . " concluída.\n";
}
$tables = ['payment_wallets','partner_payment_plans','guest_orders'];
foreach ($tables as $table) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    if (!(bool)$st->fetchColumn()) throw new RuntimeException('Tabela não encontrada após migração: ' . $table);
}
$v3Active = (int)$pdo->query("SELECT COUNT(*) FROM partners WHERE portal_mode='v3'")->fetchColumn();
echo "Migração 003 aplicada e validada. Hosts V3 ativos: {$v3Active}.\n";
