ALTER TABLE courtesy_grants
  ADD COLUMN IF NOT EXISTS provision_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER failure_detail,
  ADD COLUMN IF NOT EXISTS last_accounting_at DATETIME DEFAULT NULL AFTER provision_attempts,
  ADD COLUMN IF NOT EXISTS radius_cleaned_at DATETIME DEFAULT NULL AFTER last_accounting_at;

ALTER TABLE courtesy_policy_defaults
  ADD COLUMN IF NOT EXISTS credit_validity_minutes INT UNSIGNED NOT NULL DEFAULT 1440 AFTER grant_minutes;

ALTER TABLE courtesy_partner_policies
  ADD COLUMN IF NOT EXISTS credit_validity_minutes INT UNSIGNED NOT NULL DEFAULT 1440 AFTER grant_minutes;

CREATE TABLE IF NOT EXISTS courtesy_shadow_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT(11) DEFAULT NULL,
  partner_code VARCHAR(32) NOT NULL,
  portal VARCHAR(32) NOT NULL,
  source VARCHAR(32) NOT NULL,
  device_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  account_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  legacy_allowed TINYINT(1) NOT NULL,
  legacy_code VARCHAR(64) NOT NULL,
  legacy_minutes SMALLINT UNSIGNED DEFAULT NULL,
  legacy_retry_at DATETIME DEFAULT NULL,
  policy_allowed TINYINT(1) DEFAULT NULL,
  policy_code VARCHAR(64) DEFAULT NULL,
  policy_minutes SMALLINT UNSIGNED DEFAULT NULL,
  policy_retry_at DATETIME DEFAULT NULL,
  policy_revision VARCHAR(32) DEFAULT NULL,
  decision_match TINYINT(1) DEFAULT NULL,
  auth_present TINYINT(1) NOT NULL DEFAULT 0,
  device_associated TINYINT(1) NOT NULL DEFAULT 0,
  ad_completed TINYINT(1) NOT NULL DEFAULT 0,
  active_paid TINYINT(1) NOT NULL DEFAULT 0,
  provider TINYINT(1) NOT NULL DEFAULT 0,
  error_code VARCHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_courtesy_shadow_partner_created (partner_id,created_at),
  KEY idx_courtesy_shadow_match_created (decision_match,created_at),
  KEY idx_courtesy_shadow_source_created (portal,source,created_at),
  CONSTRAINT fk_courtesy_shadow_partner
    FOREIGN KEY (partner_id) REFERENCES partners (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (skey,svalue) VALUES ('courtesy_shadow_enabled','1');
