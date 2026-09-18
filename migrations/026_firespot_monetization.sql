-- FireSpot: monetização, Marketplace Mercado Pago, publicidade e leads.
-- Migração aditiva. Nenhum pedido, anúncio ou evento histórico é removido.

CREATE TABLE IF NOT EXISTS partner_monetization_agreements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT(11) NOT NULL,
  model ENUM('subscription','revenue_share','hybrid') NOT NULL,
  monthly_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
  access_fee_type ENUM('none','percentage','fixed') NOT NULL DEFAULT 'none',
  access_fee_value INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'basis points for percentage; cents for fixed',
  advertising_enabled TINYINT(1) NOT NULL DEFAULT 0,
  billing_source ENUM('mercadopago','external_firenetwork') NOT NULL DEFAULT 'mercadopago',
  status ENUM('draft','active','suspended','ended') NOT NULL DEFAULT 'draft',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  paid_until DATE NULL,
  accepted_at DATETIME NULL,
  created_by_admin_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_partner_monetization_current (partner_id,status,starts_at,ends_at),
  CONSTRAINT fk_partner_monetization_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketplace_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT(11) NOT NULL,
  provider ENUM('mercadopago','external_firenetwork') NOT NULL DEFAULT 'mercadopago',
  seller_user_id VARCHAR(64) NULL,
  public_key VARCHAR(255) NULL,
  access_token_encrypted TEXT NULL,
  refresh_token_encrypted TEXT NULL,
  credential_hint VARCHAR(32) NULL,
  token_expires_at DATETIME NULL,
  status ENUM('pending','active','expired','revoked','error') NOT NULL DEFAULT 'pending',
  last_error VARCHAR(255) NULL,
  authorized_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_marketplace_account_partner_provider (partner_id,provider),
  KEY idx_marketplace_account_status (status,token_expires_at),
  CONSTRAINT fk_marketplace_account_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketplace_oauth_states (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT(11) NOT NULL,
  state_hash CHAR(64) NOT NULL,
  redirect_uri VARCHAR(500) NOT NULL,
  code_verifier_encrypted TEXT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by_type ENUM('firespot','partner_admin') NOT NULL,
  created_by_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_marketplace_oauth_state (state_hash),
  KEY idx_marketplace_oauth_expiry (partner_id,expires_at,used_at),
  CONSTRAINT fk_marketplace_oauth_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS advertisers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  legal_name VARCHAR(180) NOT NULL,
  trade_name VARCHAR(180) NULL,
  tax_id VARCHAR(20) NULL,
  contact_name VARCHAR(150) NULL,
  contact_email VARCHAR(200) NULL,
  contact_phone VARCHAR(32) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_advertisers_active (active,legal_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  advertiser_id BIGINT UNSIGNED NULL,
  name VARCHAR(180) NOT NULL,
  campaign_type ENUM('institutional','commercial') NOT NULL DEFAULT 'commercial',
  status ENUM('draft','awaiting_payment','active','paused','exhausted','completed','cancelled') NOT NULL DEFAULT 'draft',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  budget_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  funded_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  reserved_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  spent_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  advertiser_view_cpm_cents INT UNSIGNED NOT NULL DEFAULT 0,
  advertiser_view_remainder_millis SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'milésimos de centavo acumulados para CPM',
  advertiser_click_cents INT UNSIGNED NOT NULL DEFAULT 0,
  advertiser_lead_cents INT UNSIGNED NOT NULL DEFAULT 0,
  lead_capture_enabled TINYINT(1) NOT NULL DEFAULT 0,
  offer_message VARCHAR(500) NULL,
  offer_valid_until DATE NULL,
  consent_version VARCHAR(32) NOT NULL DEFAULT 'offer-v1',
  frequency_window_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  max_views_per_device SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  external_reference VARCHAR(64) NULL,
  created_by_admin_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ad_campaign_external_ref (external_reference),
  KEY idx_ad_campaign_eligibility (status,starts_at,ends_at),
  KEY idx_ad_campaign_advertiser (advertiser_id,status),
  CONSTRAINT fk_ad_campaign_advertiser FOREIGN KEY (advertiser_id) REFERENCES advertisers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_campaign_partners (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  partner_id INT(11) NOT NULL,
  status ENUM('invited','active','suspended','ended') NOT NULL DEFAULT 'active',
  partner_view_cpm_cents INT UNSIGNED NOT NULL DEFAULT 0,
  partner_view_remainder_millis SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'milésimos de centavo acumulados para CPM',
  partner_click_cents INT UNSIGNED NOT NULL DEFAULT 0,
  partner_lead_cents INT UNSIGNED NOT NULL DEFAULT 0,
  max_views_per_device SMALLINT UNSIGNED NULL,
  joined_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ad_campaign_partner (campaign_id,partner_id),
  KEY idx_ad_campaign_partner_active (partner_id,status,campaign_id),
  CONSTRAINT fk_ad_campaign_partner_campaign FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ad_campaign_partner_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE custom_ads
  ADD COLUMN IF NOT EXISTS campaign_id BIGINT UNSIGNED NULL AFTER partner_id,
  ADD COLUMN IF NOT EXISTS ad_kind ENUM('owned','institutional','commercial') NOT NULL DEFAULT 'owned' AFTER campaign_id,
  ADD COLUMN IF NOT EXISTS lead_capture_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER skip_button_text,
  ADD COLUMN IF NOT EXISTS offer_message VARCHAR(500) NULL AFTER lead_capture_enabled,
  ADD INDEX IF NOT EXISTS idx_custom_ads_campaign (campaign_id,active);

UPDATE custom_ads SET ad_kind='institutional' WHERE partner_id IS NULL AND campaign_id IS NULL;
UPDATE custom_ads SET ad_kind='commercial' WHERE campaign_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS ad_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(32) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  campaign_id BIGINT UNSIGNED NULL,
  ad_id BIGINT NOT NULL,
  partner_id INT(11) NOT NULL,
  session_hash CHAR(64) NOT NULL,
  device_hash CHAR(64) NULL,
  access_username VARCHAR(64) NULL,
  state ENUM('started','completed','connected','rejected','expired') NOT NULL DEFAULT 'started',
  is_simulation TINYINT(1) NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  ready_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  connected_at DATETIME NULL,
  qualified_view_at DATETIME NULL,
  qualified_click_at DATETIME NULL,
  qualified_lead_at DATETIME NULL,
  rejection_code VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ad_delivery_public (public_id),
  UNIQUE KEY uq_ad_delivery_token (token_hash),
  KEY idx_ad_delivery_eligibility (campaign_id,partner_id,state,started_at),
  KEY idx_ad_delivery_frequency (campaign_id,partner_id,device_hash,started_at),
  KEY idx_ad_delivery_radius (state,access_username,completed_at),
  CONSTRAINT fk_ad_delivery_campaign FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ad_delivery_ad FOREIGN KEY (ad_id) REFERENCES custom_ads(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ad_delivery_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_leads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(32) NOT NULL,
  delivery_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NULL,
  ad_id BIGINT NOT NULL,
  partner_id INT(11) NOT NULL,
  name_encrypted TEXT NULL,
  phone_encrypted TEXT NULL,
  phone_hash CHAR(64) NULL,
  phone_last4 CHAR(4) NULL,
  consent_version VARCHAR(32) NOT NULL,
  consent_text_hash CHAR(64) NOT NULL,
  consent_at DATETIME NOT NULL,
  status ENUM('pending','qualified','duplicate','rejected','revoked','anonymized') NOT NULL DEFAULT 'pending',
  duplicate_of_id BIGINT UNSIGNED NULL,
  message_queue_id BIGINT UNSIGNED NULL,
  message_status ENUM('none','queued','accepted','failed','cancelled') NOT NULL DEFAULT 'none',
  qualified_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  anonymized_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ad_lead_public (public_id),
  UNIQUE KEY uq_ad_lead_delivery (delivery_id),
  KEY idx_ad_lead_partner (partner_id,status,created_at),
  KEY idx_ad_lead_dedupe (campaign_id,partner_id,phone_hash,status),
  CONSTRAINT fk_ad_lead_delivery FOREIGN KEY (delivery_id) REFERENCES ad_deliveries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ad_lead_campaign FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ad_lead_ad FOREIGN KEY (ad_id) REFERENCES custom_ads(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ad_lead_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ad_lead_duplicate FOREIGN KEY (duplicate_of_id) REFERENCES ad_leads(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ad_deliveries
  ADD COLUMN IF NOT EXISTS access_username VARCHAR(64) NULL AFTER device_hash,
  ADD COLUMN IF NOT EXISTS qualified_click_at DATETIME NULL AFTER qualified_view_at,
  ADD COLUMN IF NOT EXISTS qualified_lead_at DATETIME NULL AFTER qualified_click_at,
  ADD INDEX IF NOT EXISTS idx_ad_delivery_radius (state,access_username,completed_at);

ALTER TABLE promo_queue
  MODIFY COLUMN status ENUM('pending','sending','sent','error','cancelled') NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS purpose VARCHAR(32) NOT NULL DEFAULT 'legacy' AFTER msg,
  ADD COLUMN IF NOT EXISTS reference_type VARCHAR(32) NULL AFTER purpose,
  ADD COLUMN IF NOT EXISTS reference_id BIGINT UNSIGNED NULL AFTER reference_type,
  ADD COLUMN IF NOT EXISTS idempotency_key CHAR(64) NULL AFTER reference_id,
  ADD COLUMN IF NOT EXISTS max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER attempts,
  ADD COLUMN IF NOT EXISTS payload_encrypted TEXT NULL AFTER max_attempts,
  ADD COLUMN IF NOT EXISTS accepted_at DATETIME NULL AFTER sent_at,
  ADD COLUMN IF NOT EXISTS failed_at DATETIME NULL AFTER accepted_at,
  ADD UNIQUE INDEX IF NOT EXISTS uq_promo_queue_idempotency (idempotency_key),
  ADD INDEX IF NOT EXISTS idx_promo_queue_reference (reference_type,reference_id);

ALTER TABLE marketplace_oauth_states
  ADD COLUMN IF NOT EXISTS code_verifier_encrypted TEXT NULL AFTER redirect_uri;

ALTER TABLE ad_pending_offers
  ADD COLUMN IF NOT EXISTS delivery_id BIGINT UNSIGNED NULL AFTER ad_id,
  ADD INDEX IF NOT EXISTS idx_ad_pending_delivery (delivery_id);

CREATE TABLE IF NOT EXISTS partner_settlements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT(11) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status ENUM('open','approved','paid','cancelled') NOT NULL DEFAULT 'open',
  advertising_cents BIGINT NOT NULL DEFAULT 0,
  adjustment_cents BIGINT NOT NULL DEFAULT 0,
  total_cents BIGINT NOT NULL DEFAULT 0,
  payment_method ENUM('mercadopago','pix') NULL,
  provider_reference VARCHAR(120) NULL,
  approved_by_admin_id BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  paid_at DATETIME NULL,
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_partner_settlement_period (partner_id,period_start,period_end),
  KEY idx_partner_settlement_status (partner_id,status,period_end),
  CONSTRAINT fk_partner_settlement_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monetization_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT(11) NOT NULL,
  agreement_id BIGINT UNSIGNED NULL,
  campaign_id BIGINT UNSIGNED NULL,
  source_type ENUM('ad_view','ad_click','ad_lead','adjustment') NOT NULL,
  source_id VARCHAR(64) NOT NULL,
  event_key CHAR(64) NOT NULL,
  amount_cents BIGINT NOT NULL,
  status ENUM('pending','approved','rejected','reversed','settled') NOT NULL DEFAULT 'pending',
  reason_code VARCHAR(40) NULL,
  rule_snapshot LONGTEXT NULL,
  occurred_at DATETIME NOT NULL,
  approved_at DATETIME NULL,
  settlement_id BIGINT UNSIGNED NULL,
  reversed_entry_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_monetization_ledger_event (event_key),
  KEY idx_monetization_ledger_partner (partner_id,status,occurred_at),
  KEY idx_monetization_ledger_campaign (campaign_id,status,occurred_at),
  KEY idx_monetization_ledger_settlement (settlement_id),
  CONSTRAINT fk_monetization_ledger_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_monetization_ledger_agreement FOREIGN KEY (agreement_id) REFERENCES partner_monetization_agreements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_monetization_ledger_campaign FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_monetization_ledger_settlement FOREIGN KEY (settlement_id) REFERENCES partner_settlements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_monetization_ledger_reverse FOREIGN KEY (reversed_entry_id) REFERENCES monetization_ledger(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_settlement_items (
  settlement_id BIGINT UNSIGNED NOT NULL,
  ledger_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (settlement_id,ledger_id),
  UNIQUE KEY uq_partner_settlement_ledger (ledger_id),
  CONSTRAINT fk_partner_settlement_item_settlement FOREIGN KEY (settlement_id) REFERENCES partner_settlements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_settlement_item_ledger FOREIGN KEY (ledger_id) REFERENCES monetization_ledger(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monetization_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(32) NOT NULL,
  order_token_hash CHAR(64) NOT NULL,
  external_ref VARCHAR(64) NOT NULL,
  order_type ENUM('campaign','subscription') NOT NULL,
  campaign_id BIGINT UNSIGNED NULL,
  partner_id INT(11) NULL,
  agreement_id BIGINT UNSIGNED NULL,
  wallet_id INT(11) NULL,
  provider ENUM('mercadopago') NOT NULL DEFAULT 'mercadopago',
  provider_payment_id VARCHAR(96) NULL,
  payer_email VARCHAR(200) NULL,
  payment_method VARCHAR(32) NULL,
  status ENUM('pending','paid','payment_failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  amount_cents BIGINT UNSIGNED NOT NULL,
  qr_code TEXT NULL,
  ticket_url VARCHAR(500) NULL,
  payment_status_detail VARCHAR(96) NULL,
  expires_at DATETIME NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_monetization_order_public (public_id),
  UNIQUE KEY uq_monetization_order_external (external_ref),
  UNIQUE KEY uq_monetization_order_provider (provider,provider_payment_id),
  KEY idx_monetization_order_campaign (campaign_id,status),
  KEY idx_monetization_order_partner (partner_id,status,created_at),
  CONSTRAINT fk_monetization_order_campaign FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_monetization_order_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_monetization_order_agreement FOREIGN KEY (agreement_id) REFERENCES partner_monetization_agreements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_monetization_order_wallet FOREIGN KEY (wallet_id) REFERENCES payment_wallets(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE guest_orders
  ADD COLUMN IF NOT EXISTS marketplace_account_id BIGINT UNSIGNED NULL AFTER wallet_id,
  ADD COLUMN IF NOT EXISTS monetization_agreement_id BIGINT UNSIGNED NULL AFTER marketplace_account_id,
  ADD COLUMN IF NOT EXISTS provider_fee_cents INT UNSIGNED NULL AFTER amount_cents,
  ADD COLUMN IF NOT EXISTS firespot_fee_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER provider_fee_cents,
  ADD COLUMN IF NOT EXISTS partner_net_cents INT UNSIGNED NULL AFTER firespot_fee_cents,
  ADD COLUMN IF NOT EXISTS fee_snapshot LONGTEXT NULL AFTER partner_net_cents,
  ADD INDEX IF NOT EXISTS idx_guest_orders_marketplace (marketplace_account_id,status,created_at),
  ADD INDEX IF NOT EXISTS idx_guest_orders_agreement (monetization_agreement_id,status,created_at);

ALTER TABLE monetization_orders
  MODIFY COLUMN provider ENUM('mercadopago','external_firenetwork') NOT NULL DEFAULT 'mercadopago',
  MODIFY COLUMN payer_email VARCHAR(200) NULL;
