-- FireSpot: painel administrativo seguro por estabelecimento.
-- Migração aditiva: mantém partner_code e registros históricos durante o cutover.

ALTER TABLE partners
  ADD COLUMN IF NOT EXISTS access_purpose ENUM('free','sponsored','paid','hybrid') NULL AFTER portal_mode,
  ADD COLUMN IF NOT EXISTS self_service_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER access_purpose,
  ADD COLUMN IF NOT EXISTS ads_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER self_service_enabled,
  ADD COLUMN IF NOT EXISTS allow_global_ads TINYINT(1) NOT NULL DEFAULT 0 AFTER ads_enabled,
  ADD INDEX IF NOT EXISTS idx_partners_access_purpose (access_purpose,self_service_enabled);

CREATE TABLE IF NOT EXISTS host_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(200) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  partner_code VARCHAR(32) NULL,
  name VARCHAR(150) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  auth_version INT UNSIGNED NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_host_users_email (email),
  KEY idx_host_users_partner_code (partner_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE host_users
  MODIFY COLUMN partner_code VARCHAR(32) NULL,
  ADD COLUMN IF NOT EXISTS auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER active,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

CREATE TABLE IF NOT EXISTS partner_admin_memberships (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  partner_id INT(11) NOT NULL,
  role ENUM('owner','manager','finance','marketing','viewer') NOT NULL DEFAULT 'viewer',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_type ENUM('firespot','partner_admin','migration') NOT NULL,
  created_by_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_partner_admin_membership (user_id,partner_id),
  KEY idx_partner_admin_membership_partner (partner_id,active,role),
  CONSTRAINT fk_partner_admin_membership_user FOREIGN KEY (user_id) REFERENCES host_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_admin_membership_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO partner_admin_memberships
  (user_id,partner_id,role,active,created_by_type,created_by_id,created_at,updated_at)
SELECT u.id,p.id,'owner',u.active,'migration',NULL,NOW(),NOW()
FROM host_users u
JOIN partners p ON p.code=u.partner_code
WHERE u.partner_code IS NOT NULL AND u.partner_code<>'';

CREATE TABLE IF NOT EXISTS partner_admin_invitations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT(11) NOT NULL,
  email VARCHAR(200) NOT NULL,
  role ENUM('owner','manager','finance','marketing','viewer') NOT NULL DEFAULT 'viewer',
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_by_type ENUM('firespot','partner_admin') NOT NULL,
  created_by_id BIGINT UNSIGNED NOT NULL,
  accepted_by_user_id BIGINT UNSIGNED NULL,
  accepted_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_partner_admin_invitation_token (token_hash),
  KEY idx_partner_admin_invitation_pending (partner_id,email,accepted_at,revoked_at,expires_at),
  CONSTRAINT fk_partner_admin_invitation_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_admin_invitation_user FOREIGN KEY (accepted_by_user_id) REFERENCES host_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_admin_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT(11) NOT NULL,
  actor_type ENUM('firespot','partner_admin','system') NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  target_type VARCHAR(64) NOT NULL,
  target_id VARCHAR(64) NULL,
  metadata LONGTEXT NULL,
  origin_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_partner_admin_audit_partner (partner_id,created_at),
  KEY idx_partner_admin_audit_actor (actor_type,actor_id,created_at),
  CONSTRAINT fk_partner_admin_audit_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_admin_login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  origin_hash CHAR(64) NOT NULL,
  identity_hash CHAR(64) NOT NULL,
  window_started_at DATETIME NOT NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  blocked_until DATETIME NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_partner_admin_login_scope (origin_hash,identity_hash),
  KEY idx_partner_admin_login_expiry (expires_at,blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS host_password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_host_password_resets_token (token_hash),
  KEY idx_host_password_resets_user (user_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE host_password_resets
  MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL,
  ADD COLUMN IF NOT EXISTS used_at DATETIME NULL AFTER expires_at,
  ADD COLUMN IF NOT EXISTS revoked_at DATETIME NULL AFTER used_at;

ALTER TABLE payment_wallets
  ADD COLUMN IF NOT EXISTS partner_id INT(11) NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_payment_wallets_partner (partner_id,active);

UPDATE payment_wallets w
JOIN (
  SELECT payment_wallet_id,MIN(id) partner_id
  FROM partners
  WHERE independent_billing=1 AND payment_wallet_id IS NOT NULL
  GROUP BY payment_wallet_id
  HAVING COUNT(*)=1
) p ON p.payment_wallet_id=w.id
SET w.partner_id=p.partner_id
WHERE w.partner_id IS NULL;

ALTER TABLE custom_ads
  ADD COLUMN IF NOT EXISTS partner_code VARCHAR(32) NULL AFTER active,
  ADD COLUMN IF NOT EXISTS start_date DATE NULL AFTER partner_code,
  ADD COLUMN IF NOT EXISTS end_date DATE NULL AFTER start_date;

ALTER TABLE custom_ads
  ADD COLUMN IF NOT EXISTS partner_id INT(11) NULL AFTER partner_code,
  ADD INDEX IF NOT EXISTS idx_custom_ads_partner_active (partner_id,active,start_date,end_date);

UPDATE custom_ads a
JOIN partners p ON p.code=a.partner_code
SET a.partner_id=p.id
WHERE a.partner_id IS NULL AND a.partner_code IS NOT NULL AND a.partner_code<>'';

ALTER TABLE custom_ads_events
  ADD COLUMN IF NOT EXISTS partner_id INT(11) NULL AFTER ad_id,
  ADD INDEX IF NOT EXISTS idx_custom_ads_events_partner (partner_id,created_at,event);

UPDATE custom_ads_events e
JOIN custom_ads a ON a.id=e.ad_id
SET e.partner_id=a.partner_id
WHERE e.partner_id IS NULL AND a.partner_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS ad_grants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  mac VARCHAR(64) NOT NULL,
  ip VARCHAR(64) NULL,
  minutes INT NOT NULL,
  granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ad_grants_username (username),
  KEY idx_ad_grants_mac (mac),
  KEY idx_ad_grants_granted (granted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ad_grants
  MODIFY COLUMN username VARCHAR(64) NOT NULL,
  MODIFY COLUMN mac VARCHAR(64) NOT NULL;

CREATE TABLE IF NOT EXISTS login_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(16) NOT NULL,
  username VARCHAR(64) NOT NULL,
  ip VARCHAR(64) NULL,
  mac VARCHAR(32) NULL,
  partner_code VARCHAR(32) NULL,
  partner_dns VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  used_by_ip VARCHAR(64) NULL,
  last_hit_at DATETIME NULL,
  last_hit_ip VARCHAR(64) NULL,
  last_hit_ua VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_login_tokens_code (code),
  KEY idx_login_tokens_expiry (expires_at,used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE login_tokens
  ADD COLUMN IF NOT EXISTS partner_code VARCHAR(32) NULL AFTER mac,
  ADD COLUMN IF NOT EXISTS partner_dns VARCHAR(255) NULL AFTER partner_code;
