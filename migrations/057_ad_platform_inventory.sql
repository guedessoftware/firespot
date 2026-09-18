-- Rede publicitária central FireSpot, políticas por estabelecimento/ponto e
-- comprovação de anúncios recompensados.
--
-- A instalação nasce desligada, usa modo de teste e não carrega tag externa,
-- não concede acesso, não cria crédito e não altera RouterOS.

CREATE TABLE IF NOT EXISTS ad_platform_settings (
  id TINYINT UNSIGNED NOT NULL,
  provider ENUM('off','mock','google_ad_manager') NOT NULL DEFAULT 'off',
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  test_mode TINYINT(1) NOT NULL DEFAULT 1,
  configuration_status ENUM('incomplete','test_ready','production_ready','suspended') NOT NULL DEFAULT 'incomplete',
  network_code VARCHAR(32) NULL,
  privacy_mode ENUM('non_personalized','consent_based') NOT NULL DEFAULT 'non_personalized',
  revenue_owner ENUM('firespot') NOT NULL DEFAULT 'firespot',
  updated_by_admin_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT chk_ad_platform_singleton CHECK (id=1),
  CONSTRAINT chk_ad_platform_boolean CHECK (enabled IN (0,1) AND test_mode IN (0,1)),
  CONSTRAINT chk_ad_platform_safe_enable CHECK (enabled=0 OR provider<>'off')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ad_platform_settings
  (id,provider,enabled,test_mode,configuration_status,privacy_mode,revenue_owner)
VALUES (1,'off',0,1,'incomplete','non_personalized','firespot')
ON DUPLICATE KEY UPDATE id=VALUES(id);

CREATE TABLE IF NOT EXISTS ad_platform_placements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(120) NOT NULL,
  format ENUM('banner','rewarded') NOT NULL,
  journey_stage ENUM('welcome','plan_selection','access_choice') NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  google_ad_unit_path VARCHAR(220) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ad_platform_placement_code (code),
  CONSTRAINT chk_ad_platform_placement_boolean CHECK (active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ad_platform_placements (code,name,format,journey_stage,active,priority)
VALUES
  ('welcome_banner','Banner de boas-vindas','banner','welcome',0,100),
  ('plans_banner','Banner da seleção de planos','banner','plan_selection',0,200),
  ('free_rewarded','Acesso gratuito recompensado','rewarded','access_choice',0,300)
ON DUPLICATE KEY UPDATE code=VALUES(code);

CREATE TABLE IF NOT EXISTS partner_ad_policies (
  partner_id INT NOT NULL,
  state ENUM('disabled','enabled') NOT NULL DEFAULT 'disabled',
  revenue_mode ENUM('firespot_managed','shared','partner_managed') NOT NULL DEFAULT 'firespot_managed',
  paid_banner_enabled TINYINT(1) NOT NULL DEFAULT 0,
  rewarded_access_enabled TINYINT(1) NOT NULL DEFAULT 0,
  allow_firespot_direct TINYINT(1) NOT NULL DEFAULT 1,
  allow_partner_owned TINYINT(1) NOT NULL DEFAULT 1,
  allow_google_backfill TINYINT(1) NOT NULL DEFAULT 1,
  rewarded_ad_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
  reward_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  max_rewards_per_device SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  reward_window_minutes INT UNSIGNED NOT NULL DEFAULT 1440,
  reward_cooldown_minutes INT UNSIGNED NOT NULL DEFAULT 1440,
  privacy_mode ENUM('inherit','non_personalized','consent_based') NOT NULL DEFAULT 'inherit',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by_type ENUM('firespot','partner_admin','system') NOT NULL DEFAULT 'system',
  updated_by_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (partner_id),
  CONSTRAINT chk_partner_ad_policy_boolean CHECK (paid_banner_enabled IN (0,1) AND rewarded_access_enabled IN (0,1) AND allow_firespot_direct IN (0,1) AND allow_partner_owned IN (0,1) AND allow_google_backfill IN (0,1)),
  CONSTRAINT chk_partner_ad_policy_reward CHECK (rewarded_ad_count BETWEEN 1 AND 2 AND reward_minutes BETWEEN 1 AND 120 AND max_rewards_per_device BETWEEN 1 AND 100 AND reward_window_minutes BETWEEN 60 AND 43200 AND reward_cooldown_minutes BETWEEN 0 AND 43200),
  CONSTRAINT fk_partner_ad_policy_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO partner_ad_policies (partner_id,state,updated_by_type)
SELECT id,'disabled','system' FROM partners
ON DUPLICATE KEY UPDATE partner_id=VALUES(partner_id);

CREATE TABLE IF NOT EXISTS hotspot_ad_policies (
  hotspot_id BIGINT UNSIGNED NOT NULL,
  partner_id INT NOT NULL,
  mode ENUM('inherit','custom') NOT NULL DEFAULT 'inherit',
  enabled_override TINYINT(1) NULL,
  paid_banner_override TINYINT(1) NULL,
  rewarded_access_override TINYINT(1) NULL,
  allow_firespot_direct_override TINYINT(1) NULL,
  allow_partner_owned_override TINYINT(1) NULL,
  allow_google_backfill_override TINYINT(1) NULL,
  rewarded_ad_count_override TINYINT UNSIGNED NULL,
  reward_minutes_override SMALLINT UNSIGNED NULL,
  max_rewards_per_device_override SMALLINT UNSIGNED NULL,
  reward_window_minutes_override INT UNSIGNED NULL,
  reward_cooldown_minutes_override INT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by_type ENUM('firespot','partner_admin','system') NOT NULL DEFAULT 'system',
  updated_by_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (hotspot_id),
  KEY idx_hotspot_ad_policy_partner (partner_id,hotspot_id),
  CONSTRAINT chk_hotspot_ad_policy_boolean CHECK ((enabled_override IS NULL OR enabled_override IN (0,1)) AND (paid_banner_override IS NULL OR paid_banner_override IN (0,1)) AND (rewarded_access_override IS NULL OR rewarded_access_override IN (0,1)) AND (allow_firespot_direct_override IS NULL OR allow_firespot_direct_override IN (0,1)) AND (allow_partner_owned_override IS NULL OR allow_partner_owned_override IN (0,1)) AND (allow_google_backfill_override IS NULL OR allow_google_backfill_override IN (0,1))),
  CONSTRAINT chk_hotspot_ad_policy_reward CHECK ((rewarded_ad_count_override IS NULL OR rewarded_ad_count_override BETWEEN 1 AND 2) AND (reward_minutes_override IS NULL OR reward_minutes_override BETWEEN 1 AND 120) AND (max_rewards_per_device_override IS NULL OR max_rewards_per_device_override BETWEEN 1 AND 100) AND (reward_window_minutes_override IS NULL OR reward_window_minutes_override BETWEEN 60 AND 43200) AND (reward_cooldown_minutes_override IS NULL OR reward_cooldown_minutes_override BETWEEN 0 AND 43200)),
  CONSTRAINT fk_hotspot_ad_policy_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id) ON DELETE CASCADE,
  CONSTRAINT fk_hotspot_ad_policy_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE,
  CONSTRAINT fk_hotspot_ad_policy_scope FOREIGN KEY (partner_id,hotspot_id) REFERENCES partner_hotspots(partner_id,id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO hotspot_ad_policies (hotspot_id,partner_id,mode,updated_by_type)
SELECT id,partner_id,'inherit','system' FROM partner_hotspots
ON DUPLICATE KEY UPDATE hotspot_id=VALUES(hotspot_id);

INSERT INTO platform_plan_features (plan_id,feature_code,enabled)
SELECT id,'ad.inventory.manage',1
FROM platform_plans
WHERE code='multipoint_advanced' AND active=1
ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_at=NOW();

CREATE TABLE IF NOT EXISTS ad_platform_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(32) NOT NULL,
  token_hash CHAR(64) NULL,
  source_code ENUM('firespot_direct','partner_owned','google_backfill') NOT NULL,
  provider_code ENUM('internal','mock','google_ad_manager') NOT NULL,
  placement_id INT UNSIGNED NOT NULL,
  partner_id INT NOT NULL,
  hotspot_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NULL,
  custom_ad_id BIGINT NULL,
  journey ENUM('paid','free','sponsored','hybrid') NOT NULL,
  state ENUM('offered','accepted','ready','granted','consumed','expired','rejected','error') NOT NULL DEFAULT 'offered',
  session_hash CHAR(64) NOT NULL,
  device_hash CHAR(64) NULL,
  provider_request_id VARCHAR(190) NULL,
  policy_version INT UNSIGNED NOT NULL,
  policy_snapshot LONGTEXT NOT NULL,
  required_ad_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
  completed_ad_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  reward_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  privacy_treatment ENUM('non_personalized','consent_based','limited') NOT NULL DEFAULT 'non_personalized',
  is_simulation TINYINT(1) NOT NULL DEFAULT 0,
  access_username VARCHAR(64) NULL,
  accepted_at DATETIME NULL,
  last_reward_at DATETIME NULL,
  ready_at DATETIME NULL,
  granted_at DATETIME NULL,
  consumed_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ad_platform_delivery_public (public_id),
  UNIQUE KEY uq_ad_platform_delivery_token (token_hash),
  KEY idx_ad_platform_delivery_reward (partner_id,hotspot_id,device_hash,state,created_at),
  KEY idx_ad_platform_delivery_provider (provider_code,placement_id,created_at),
  KEY idx_ad_platform_delivery_campaign (campaign_id,state),
  KEY idx_ad_platform_delivery_custom_ad (custom_ad_id,state),
  CONSTRAINT chk_ad_platform_delivery_boolean CHECK (is_simulation IN (0,1)),
  CONSTRAINT chk_ad_platform_delivery_reward CHECK (required_ad_count BETWEEN 1 AND 2 AND completed_ad_count<=required_ad_count AND reward_minutes BETWEEN 0 AND 120),
  CONSTRAINT chk_ad_platform_delivery_snapshot CHECK (JSON_VALID(policy_snapshot)),
  CONSTRAINT fk_ad_platform_delivery_placement FOREIGN KEY (placement_id) REFERENCES ad_platform_placements(id),
  CONSTRAINT fk_ad_platform_delivery_partner FOREIGN KEY (partner_id) REFERENCES partners(id),
  CONSTRAINT fk_ad_platform_delivery_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id),
  CONSTRAINT fk_ad_platform_delivery_scope FOREIGN KEY (partner_id,hotspot_id) REFERENCES partner_hotspots(partner_id,id),
  CONSTRAINT fk_ad_platform_delivery_campaign FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id),
  CONSTRAINT fk_ad_platform_delivery_custom_ad FOREIGN KEY (custom_ad_id) REFERENCES custom_ads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_ad_revenue_daily (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dimension_key CHAR(64) NOT NULL,
  revenue_date DATE NOT NULL,
  provider_code ENUM('mock','google_ad_manager') NOT NULL,
  placement_id INT UNSIGNED NOT NULL,
  partner_id INT NULL,
  hotspot_id BIGINT UNSIGNED NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BRL',
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  rewarded_completed INT UNSIGNED NOT NULL DEFAULT 0,
  estimated_cents BIGINT NOT NULL DEFAULT 0,
  finalized_cents BIGINT NULL,
  invalid_traffic_adjustment_cents BIGINT NOT NULL DEFAULT 0,
  state ENUM('estimated','finalized','adjusted') NOT NULL DEFAULT 'estimated',
  source_checksum CHAR(64) NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_platform_ad_revenue_dimension (dimension_key),
  KEY idx_platform_ad_revenue_date (revenue_date,provider_code,state),
  KEY idx_platform_ad_revenue_partner (partner_id,revenue_date),
  KEY idx_platform_ad_revenue_hotspot (hotspot_id,revenue_date),
  CONSTRAINT chk_platform_ad_revenue_currency CHECK (currency='BRL'),
  CONSTRAINT fk_platform_ad_revenue_placement FOREIGN KEY (placement_id) REFERENCES ad_platform_placements(id),
  CONSTRAINT fk_platform_ad_revenue_partner FOREIGN KEY (partner_id) REFERENCES partners(id),
  CONSTRAINT fk_platform_ad_revenue_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
