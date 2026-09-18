-- FireSpot: configuração funcional do Portal V3, contas FIRENETWORK,
-- benefícios HubSoft, convites, aparelhos e concessões por dispositivo.
-- Migração aditiva: não altera rotas nem remove estruturas legadas.

CREATE TABLE IF NOT EXISTS partner_portal_configurations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT(11) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  state ENUM('draft','published','superseded','discarded','archived') NOT NULL DEFAULT 'draft',
  source ENUM('preset','custom','migration','rollback') NOT NULL DEFAULT 'custom',
  preset_code VARCHAR(48) NULL,
  preset_version INT UNSIGNED NULL,
  subscriber_access_mode ENUM('inherit','allow','deny') NOT NULL DEFAULT 'inherit',
  courtesy_mode ENUM('disabled','direct','sponsored') NOT NULL DEFAULT 'disabled',
  paid_access_enabled TINYINT(1) NOT NULL DEFAULT 0,
  promotional_ads_enabled TINYINT(1) NOT NULL DEFAULT 0,
  allow_global_ads TINYINT(1) NOT NULL DEFAULT 0,
  lead_capture_enabled TINYINT(1) NOT NULL DEFAULT 0,
  validation_snapshot LONGTEXT NULL,
  validation_hash CHAR(64) NULL,
  base_revision_id BIGINT UNSIGNED NULL,
  created_by_type ENUM('firespot','partner_admin','system') NOT NULL DEFAULT 'system',
  created_by_id BIGINT UNSIGNED NULL,
  validated_at DATETIME NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  current_published_partner_id INT(11) AS (CASE WHEN state='published' THEN partner_id ELSE NULL END) STORED,
  current_draft_partner_id INT(11) AS (CASE WHEN state='draft' THEN partner_id ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_partner_portal_config_revision (partner_id,revision),
  UNIQUE KEY uq_partner_portal_config_published (current_published_partner_id),
  UNIQUE KEY uq_partner_portal_config_draft (current_draft_partner_id),
  KEY idx_partner_portal_config_state (partner_id,state,updated_at),
  KEY idx_partner_portal_config_base (base_revision_id),
  CONSTRAINT fk_partner_portal_config_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO partner_portal_configurations
  (partner_id,revision,state,source,preset_code,preset_version,subscriber_access_mode,courtesy_mode,
   paid_access_enabled,promotional_ads_enabled,allow_global_ads,lead_capture_enabled,published_at,created_by_type)
SELECT
  p.id,1,
  CASE WHEN p.access_purpose IS NOT NULL OR p.portal_mode='v3' THEN 'published' ELSE 'draft' END,
  'migration',
  CASE
    WHEN p.access_purpose='free' THEN 'free_quick'
    WHEN p.access_purpose='sponsored' THEN 'free_sponsored'
    WHEN p.access_purpose='paid' THEN 'paid'
    WHEN p.access_purpose='hybrid' AND COALESCE(cp.requires_ad,d.requires_ad,0)=0 THEN 'hybrid'
    WHEN p.access_purpose IS NULL AND p.portal_mode='v3' THEN 'paid'
    ELSE NULL
  END,
  CASE WHEN p.access_purpose IS NOT NULL OR p.portal_mode='v3' THEN 1 ELSE NULL END,
  'inherit',
  CASE
    WHEN p.access_purpose='free' THEN 'direct'
    WHEN p.access_purpose='sponsored' THEN 'sponsored'
    WHEN p.access_purpose='hybrid' AND COALESCE(cp.requires_ad,d.requires_ad,0)=1 THEN 'sponsored'
    WHEN p.access_purpose='hybrid' THEN 'direct'
    ELSE 'disabled'
  END,
  CASE WHEN p.access_purpose IN ('paid','hybrid') OR (p.access_purpose IS NULL AND p.portal_mode='v3') THEN 1 ELSE 0 END,
  p.ads_enabled,p.allow_global_ads,0,
  CASE WHEN p.access_purpose IS NOT NULL OR p.portal_mode='v3' THEN NOW() ELSE NULL END,
  'system'
FROM partners p
LEFT JOIN courtesy_partner_policies cp ON cp.partner_id=p.id
LEFT JOIN courtesy_policy_defaults d ON d.id=1
WHERE NOT EXISTS (SELECT 1 FROM partner_portal_configurations x WHERE x.partner_id=p.id);

CREATE TABLE IF NOT EXISTS subscriber_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(32) NOT NULL,
  status ENUM('active','suspended','anonymized') NOT NULL DEFAULT 'active',
  display_name VARCHAR(150) NULL,
  document_hash CHAR(64) NOT NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  anonymized_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriber_account_public (public_id),
  UNIQUE KEY uq_subscriber_account_document (document_hash),
  KEY idx_subscriber_account_status (status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriber_external_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'hubsoft',
  external_customer_id VARCHAR(128) NOT NULL,
  document_hash CHAR(64) NOT NULL,
  document_encrypted TEXT NULL,
  verified_phone_encrypted TEXT NULL,
  verified_phone_hint VARCHAR(24) NULL,
  verified_email_encrypted TEXT NULL,
  verified_email_hint VARCHAR(180) NULL,
  status ENUM('active','suspended','revoked','error') NOT NULL DEFAULT 'active',
  last_result_code VARCHAR(48) NULL,
  last_verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriber_external_customer (provider,external_customer_id),
  UNIQUE KEY uq_subscriber_external_account (provider,account_id),
  KEY idx_subscriber_external_document (provider,document_hash),
  CONSTRAINT fk_subscriber_external_account FOREIGN KEY (account_id) REFERENCES subscriber_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriber_benefit_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(48) NOT NULL,
  name VARCHAR(120) NOT NULL,
  unlimited_time TINYINT(1) NOT NULL DEFAULT 1,
  device_limit SMALLINT UNSIGNED NOT NULL,
  concurrent_limit SMALLINT UNSIGNED NOT NULL,
  revalidation_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 480,
  active TINYINT(1) NOT NULL DEFAULT 1,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriber_benefit_code (code),
  KEY idx_subscriber_benefit_active (active,code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO subscriber_benefit_profiles
  (code,name,unlimited_time,device_limit,concurrent_limit,revalidation_minutes,active,revision)
VALUES
  ('firenetwork_basic','FIRENETWORK Básico',1,1,1,480,1,1),
  ('firenetwork_family_3','FIRENETWORK Família 3',1,3,3,480,1,1),
  ('firenetwork_family_5','FIRENETWORK Família 5',1,5,5,480,1,1)
ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=NOW();

CREATE TABLE IF NOT EXISTS subscriber_plan_mappings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(32) NOT NULL DEFAULT 'hubsoft',
  external_kind ENUM('plan','service','package') NOT NULL DEFAULT 'plan',
  external_id VARCHAR(128) NOT NULL,
  external_label VARCHAR(180) NULL,
  eligible_internet TINYINT(1) NOT NULL DEFAULT 1,
  benefit_profile_id BIGINT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriber_plan_mapping (provider,external_kind,external_id),
  KEY idx_subscriber_plan_profile (benefit_profile_id,active),
  CONSTRAINT fk_subscriber_plan_profile FOREIGN KEY (benefit_profile_id) REFERENCES subscriber_benefit_profiles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriber_entitlements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  benefit_profile_id BIGINT UNSIGNED NOT NULL,
  plan_mapping_id BIGINT UNSIGNED NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'hubsoft',
  external_service_id VARCHAR(128) NULL,
  external_plan_id VARCHAR(128) NULL,
  status ENUM('active','grace','suspended','revoked','error') NOT NULL DEFAULT 'error',
  result_code VARCHAR(48) NULL,
  source_snapshot LONGTEXT NULL,
  verified_at DATETIME NULL,
  valid_until DATETIME NULL,
  grace_until DATETIME NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriber_entitlement_account (account_id),
  KEY idx_subscriber_entitlement_status (status,grace_until,updated_at),
  KEY idx_subscriber_entitlement_profile (benefit_profile_id,status),
  CONSTRAINT fk_subscriber_entitlement_account FOREIGN KEY (account_id) REFERENCES subscriber_accounts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscriber_entitlement_profile FOREIGN KEY (benefit_profile_id) REFERENCES subscriber_benefit_profiles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscriber_entitlement_mapping FOREIGN KEY (plan_mapping_id) REFERENCES subscriber_plan_mappings(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriber_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(32) NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(120) NULL,
  device_kind ENUM('primary','guest') NOT NULL DEFAULT 'guest',
  status ENUM('active','expired','revoked','over_limit','suspended') NOT NULL DEFAULT 'active',
  authorization_mode ENUM('temporary','until_date','while_authorized') NOT NULL DEFAULT 'while_authorized',
  device_token_hash CHAR(64) NOT NULL,
  authorized_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  replace_available_at DATETIME NULL,
  first_seen_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  last_partner_id INT(11) NULL,
  last_hotspot_id BIGINT UNSIGNED NULL,
  status_reason VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  primary_account_id BIGINT UNSIGNED AS (CASE WHEN device_kind='primary' AND status='active' THEN account_id ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriber_device_public (public_id),
  UNIQUE KEY uq_subscriber_device_token (device_token_hash),
  UNIQUE KEY uq_subscriber_device_primary (primary_account_id),
  KEY idx_subscriber_device_account (account_id,status,last_seen_at),
  KEY idx_subscriber_device_partner (last_partner_id,last_hotspot_id,last_seen_at),
  CONSTRAINT fk_subscriber_device_account FOREIGN KEY (account_id) REFERENCES subscriber_accounts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscriber_device_partner FOREIGN KEY (last_partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscriber_device_hotspot FOREIGN KEY (last_hotspot_id) REFERENCES partner_hotspots(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriber_device_identifiers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id BIGINT UNSIGNED NOT NULL,
  identifier_type ENUM('mac','browser_token') NOT NULL,
  identifier_hash CHAR(64) NOT NULL,
  identifier_hint VARCHAR(32) NULL,
  partner_id INT(11) NULL,
  hotspot_id BIGINT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriber_device_identifier (identifier_type,identifier_hash),
  KEY idx_subscriber_identifier_device (device_id,active,last_seen_at),
  KEY idx_subscriber_identifier_hotspot (partner_id,hotspot_id,last_seen_at),
  CONSTRAINT fk_subscriber_identifier_device FOREIGN KEY (device_id) REFERENCES subscriber_devices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscriber_identifier_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscriber_identifier_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriber_device_invites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(32) NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(120) NULL,
  token_hash CHAR(64) NOT NULL,
  human_code_hash CHAR(64) NOT NULL,
  status ENUM('created','redeemed','revoked','expired') NOT NULL DEFAULT 'created',
  authorization_mode ENUM('temporary','until_date','while_authorized') NOT NULL DEFAULT 'while_authorized',
  access_duration_minutes INT UNSIGNED NULL,
  authorization_until DATETIME NULL,
  expires_at DATETIME NOT NULL,
  redeemed_at DATETIME NULL,
  redeemed_device_id BIGINT UNSIGNED NULL,
  created_by_type ENUM('subscriber','firespot','system') NOT NULL DEFAULT 'subscriber',
  created_by_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriber_invite_public (public_id),
  UNIQUE KEY uq_subscriber_invite_token (token_hash),
  UNIQUE KEY uq_subscriber_invite_human (human_code_hash),
  KEY idx_subscriber_invite_account (account_id,status,expires_at),
  CONSTRAINT fk_subscriber_invite_account FOREIGN KEY (account_id) REFERENCES subscriber_accounts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscriber_invite_device FOREIGN KEY (redeemed_device_id) REFERENCES subscriber_devices(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriber_access_grants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(32) NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  entitlement_id BIGINT UNSIGNED NOT NULL,
  partner_id INT(11) NOT NULL,
  hotspot_id BIGINT UNSIGNED NOT NULL,
  idempotency_key_hash CHAR(64) NOT NULL,
  status ENUM('reserved','provisioning','active','expired','revoked','failed') NOT NULL DEFAULT 'reserved',
  radius_username VARCHAR(64) NULL,
  device_mac VARCHAR(64) NULL,
  device_ip VARCHAR(45) NULL,
  reservation_expires_at DATETIME NOT NULL,
  activated_at DATETIME NULL,
  revalidate_at DATETIME NULL,
  expires_at DATETIME NULL,
  ended_at DATETIME NULL,
  radius_cleaned_at DATETIME NULL,
  failure_code VARCHAR(48) NULL,
  failure_detail VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriber_grant_public (public_id),
  UNIQUE KEY uq_subscriber_grant_idempotency (idempotency_key_hash),
  KEY idx_subscriber_grant_account (account_id,status,created_at),
  KEY idx_subscriber_grant_device (device_id,status,updated_at),
  KEY idx_subscriber_grant_hotspot (partner_id,hotspot_id,status,created_at),
  KEY idx_subscriber_grant_radius (radius_username,status),
  CONSTRAINT fk_subscriber_grant_account FOREIGN KEY (account_id) REFERENCES subscriber_accounts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscriber_grant_device FOREIGN KEY (device_id) REFERENCES subscriber_devices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscriber_grant_entitlement FOREIGN KEY (entitlement_id) REFERENCES subscriber_entitlements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_subscriber_grant_hotspot FOREIGN KEY (partner_id,hotspot_id) REFERENCES partner_hotspots(partner_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriber_login_challenges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(32) NOT NULL,
  account_id BIGINT UNSIGNED NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'hubsoft',
  document_hash CHAR(64) NOT NULL,
  contact_type ENUM('phone','email') NOT NULL,
  contact_hint VARCHAR(180) NOT NULL,
  target_encrypted TEXT NOT NULL,
  code_hash CHAR(64) NOT NULL,
  status ENUM('pending','verified','expired','blocked') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  queue_id BIGINT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  origin_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriber_login_challenge_public (public_id),
  KEY idx_subscriber_login_document (document_hash,status,created_at),
  KEY idx_subscriber_login_expiry (status,expires_at),
  CONSTRAINT fk_subscriber_login_account FOREIGN KEY (account_id) REFERENCES subscriber_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriber_trusted_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  user_agent_hash CHAR(64) NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriber_trusted_token (token_hash),
  KEY idx_subscriber_trusted_account (account_id,expires_at,revoked_at),
  CONSTRAINT fk_subscriber_trusted_account FOREIGN KEY (account_id) REFERENCES subscriber_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriber_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NULL,
  actor_type ENUM('subscriber','firespot','system') NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  target_type VARCHAR(64) NOT NULL,
  target_id VARCHAR(64) NULL,
  metadata LONGTEXT NULL,
  origin_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_subscriber_audit_account (account_id,created_at),
  KEY idx_subscriber_audit_action (action,created_at),
  CONSTRAINT fk_subscriber_audit_account FOREIGN KEY (account_id) REFERENCES subscriber_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE guest_orders
  ADD COLUMN IF NOT EXISTS subscriber_account_id BIGINT UNSIGNED NULL AFTER hotspot_id,
  ADD COLUMN IF NOT EXISTS subscriber_device_id BIGINT UNSIGNED NULL AFTER subscriber_account_id,
  ADD INDEX IF NOT EXISTS idx_guest_orders_subscriber (subscriber_account_id,status,created_at),
  ADD INDEX IF NOT EXISTS idx_guest_orders_subscriber_device (subscriber_device_id,status,created_at);
