-- Rascunho/publicação da política comercial de cortesia e override por ponto.
-- Os controles técnicos de RADIUS, rollout e reserva permanecem apenas na
-- tabela efetiva administrada pela FireSpot.

CREATE TABLE IF NOT EXISTS courtesy_policy_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT NOT NULL,
  revision INT UNSIGNED NOT NULL,
  state ENUM('draft','published','superseded','discarded') NOT NULL,
  enabled TINYINT(1) NOT NULL,
  grant_minutes SMALLINT UNSIGNED NOT NULL,
  credit_validity_minutes INT UNSIGNED NOT NULL,
  consumption_mode ENUM('online','elapsed') NOT NULL,
  auth_mode ENUM('anonymous','account','account_device') NOT NULL,
  device_max_grants SMALLINT UNSIGNED NULL,
  device_period_minutes INT UNSIGNED NULL,
  account_max_grants SMALLINT UNSIGNED NULL,
  account_period_minutes INT UNSIGNED NULL,
  cooldown_after_end_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  current_draft_partner_id INT AS (CASE WHEN state='draft' THEN partner_id ELSE NULL END) STORED,
  current_published_partner_id INT AS (CASE WHEN state='published' THEN partner_id ELSE NULL END) STORED,
  created_by_user_id BIGINT UNSIGNED NULL,
  published_by_user_id BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_courtesy_policy_revision (partner_id,revision),
  UNIQUE KEY uq_courtesy_policy_draft (current_draft_partner_id),
  UNIQUE KEY uq_courtesy_policy_published (current_published_partner_id),
  CONSTRAINT fk_courtesy_policy_revision_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_courtesy_policy_revision_creator FOREIGN KEY (created_by_user_id) REFERENCES host_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_courtesy_policy_revision_publisher FOREIGN KEY (published_by_user_id) REFERENCES host_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courtesy_hotspot_policy_overrides (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT NOT NULL,
  hotspot_id BIGINT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL,
  state ENUM('draft','published','superseded','discarded','retired') NOT NULL,
  enabled TINYINT(1) NULL,
  grant_minutes SMALLINT UNSIGNED NULL,
  credit_validity_minutes INT UNSIGNED NULL,
  consumption_mode ENUM('online','elapsed') NULL,
  auth_mode ENUM('anonymous','account','account_device') NULL,
  device_max_grants SMALLINT UNSIGNED NULL,
  device_period_minutes INT UNSIGNED NULL,
  account_max_grants SMALLINT UNSIGNED NULL,
  account_period_minutes INT UNSIGNED NULL,
  cooldown_after_end_minutes INT UNSIGNED NULL,
  override_mask VARCHAR(255) NOT NULL DEFAULT 'enabled,grant,auth,device_limit,account_limit,cooldown',
  current_state_key VARCHAR(96) AS (CASE WHEN state IN ('draft','published') THEN CONCAT(partner_id,':',hotspot_id,':',state) ELSE NULL END) STORED,
  created_by_user_id BIGINT UNSIGNED NULL,
  published_by_user_id BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_courtesy_hotspot_override_revision (partner_id,hotspot_id,revision),
  UNIQUE KEY uq_courtesy_hotspot_override_current (current_state_key),
  KEY idx_courtesy_hotspot_override (partner_id,hotspot_id,state),
  CONSTRAINT fk_courtesy_hotspot_override_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_courtesy_hotspot_override_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id) ON DELETE RESTRICT,
  CONSTRAINT fk_courtesy_hotspot_override_creator FOREIGN KEY (created_by_user_id) REFERENCES host_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_courtesy_hotspot_override_publisher FOREIGN KEY (published_by_user_id) REFERENCES host_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE courtesy_hotspot_policy_overrides
  ADD COLUMN IF NOT EXISTS override_mask VARCHAR(255) NOT NULL DEFAULT 'enabled,grant,auth,device_limit,account_limit,cooldown' AFTER cooldown_after_end_minutes;

INSERT INTO courtesy_policy_revisions
  (partner_id,revision,state,enabled,grant_minutes,credit_validity_minutes,consumption_mode,auth_mode,device_max_grants,device_period_minutes,account_max_grants,account_period_minutes,cooldown_after_end_minutes,published_at)
SELECT p.partner_id,1,'published',p.enabled,p.grant_minutes,p.credit_validity_minutes,p.consumption_mode,p.auth_mode,p.device_max_grants,p.device_period_minutes,p.account_max_grants,p.account_period_minutes,p.cooldown_after_end_minutes,NOW()
FROM courtesy_partner_policies p
WHERE NOT EXISTS (SELECT 1 FROM courtesy_policy_revisions r WHERE r.partner_id=p.partner_id);
