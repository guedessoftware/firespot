-- FireSpot: consolida estruturas que eram criadas/alteradas em requisições.
-- Depois desta migração, rotas HTTP apenas verificam o schema.

CREATE TABLE IF NOT EXISTS app_settings (
  skey VARCHAR(100) NOT NULL,
  svalue TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radius_servers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  host VARCHAR(150) NOT NULL,
  port INT NOT NULL DEFAULT 1812,
  secret VARCHAR(180) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_host_port (host,port)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_profile (
  id TINYINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  subtitle VARCHAR(160) NOT NULL DEFAULT '',
  logo_letter VARCHAR(2) NOT NULL DEFAULT 'F',
  support_phone VARCHAR(32) NULL,
  support_whatsapp VARCHAR(32) NULL,
  support_email VARCHAR(120) NULL,
  support_site VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deleted_accounts_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reason VARCHAR(60) NOT NULL DEFAULT 'self-service',
  initiator VARCHAR(120) NULL,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_deleted_username (username),
  KEY idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS msg_whatsapp_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  msg TEXT NOT NULL,
  day_key DATE NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_day (username,day_key),
  KEY idx_phone_day (phone,day_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vip_passes (
  id INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  device_os VARCHAR(50) NULL,
  device_ua TEXT NULL,
  created_at DATETIME NOT NULL,
  start_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  status ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
  policy_down_kbps INT NOT NULL,
  policy_up_kbps INT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_vip_passes_username (username),
  KEY idx_vip_passes_status (status),
  KEY idx_vip_passes_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE vip_orders
  ADD COLUMN IF NOT EXISTS payment_method VARCHAR(16) NOT NULL DEFAULT 'pix' AFTER duracao_min,
  ADD COLUMN IF NOT EXISTS payment_method_detail VARCHAR(32) NULL AFTER payment_method,
  ADD COLUMN IF NOT EXISTS payment_installments TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER payment_method_detail,
  ADD COLUMN IF NOT EXISTS payment_expires_at DATETIME NULL AFTER payment_installments,
  ADD COLUMN IF NOT EXISTS mp_refund_id VARCHAR(64) NULL AFTER mp_payment_id,
  ADD COLUMN IF NOT EXISTS refund_status ENUM('none','requested','partial','processed','failed') NOT NULL DEFAULT 'none' AFTER vip_applied_at,
  ADD COLUMN IF NOT EXISTS refund_amount_centavos INT UNSIGNED NULL AFTER refund_status,
  ADD COLUMN IF NOT EXISTS refunded_at DATETIME NULL AFTER refund_amount_centavos,
  ADD COLUMN IF NOT EXISTS refund_notes VARCHAR(255) NULL AFTER refunded_at,
  MODIFY COLUMN status ENUM('pending','paid','cancelled','refunded') NOT NULL DEFAULT 'pending',
  ADD INDEX IF NOT EXISTS idx_payment_method (payment_method),
  ADD INDEX IF NOT EXISTS idx_refund_status (refund_status),
  ADD INDEX IF NOT EXISTS idx_payment_expires_at (payment_expires_at);

ALTER TABLE clientes_info
  ADD COLUMN IF NOT EXISTS vip_ativo TINYINT(1) NOT NULL DEFAULT 0 AFTER sexo,
  ADD COLUMN IF NOT EXISTS vip_until DATETIME NULL AFTER vip_ativo;

ALTER TABLE host_password_resets
  DROP COLUMN IF EXISTS reset_token,
  DROP COLUMN IF EXISTS reset_expires_at;
