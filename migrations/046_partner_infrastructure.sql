-- Propriedade exclusiva de NAS, rascunhos versionados e fila operacional.
-- Nenhum equipamento remoto é acessado durante a migração.

CREATE TABLE IF NOT EXISTS partner_nas_ownerships (
  nas_id INT NOT NULL,
  partner_id INT NOT NULL,
  management_mode ENUM('partner_owned','firespot_dedicated') NOT NULL,
  status ENUM('pending','verifying','verified','preparing','ready','error','retired') NOT NULL DEFAULT 'pending',
  credentials_ciphertext MEDIUMTEXT NULL,
  credential_hint VARCHAR(32) NULL,
  host_key_fingerprint VARCHAR(128) NULL,
  credential_version INT UNSIGNED NOT NULL DEFAULT 1,
  last_verified_at DATETIME NULL,
  last_synced_at DATETIME NULL,
  retired_at DATETIME NULL,
  created_by_type ENUM('firespot','partner_admin','system') NOT NULL,
  created_by_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (nas_id),
  KEY idx_partner_nas_ownership (partner_id,status,management_mode),
  CONSTRAINT fk_partner_nas_ownership_nas FOREIGN KEY (nas_id) REFERENCES nas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_nas_ownership_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotspot_change_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT NOT NULL,
  nas_id INT NOT NULL,
  hotspot_id BIGINT UNSIGNED NULL,
  operation ENUM('nas_verify','nas_sync','nas_prepare','hotspot_apply','hotspot_deactivate','hotspot_cleanup') NOT NULL,
  payload LONGTEXT NULL,
  status ENUM('queued','running','retry','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
  idempotency_key CHAR(64) NOT NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  worker_token CHAR(36) NULL,
  error_code VARCHAR(64) NULL,
  error_detail VARCHAR(255) NULL,
  requested_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hotspot_request_idempotency (partner_id,idempotency_key),
  KEY idx_hotspot_requests_worker (status,available_at,id),
  KEY idx_hotspot_requests_resource (partner_id,nas_id,hotspot_id,created_at),
  CONSTRAINT fk_hotspot_request_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_hotspot_request_nas FOREIGN KEY (nas_id) REFERENCES nas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_hotspot_request_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id) ON DELETE RESTRICT,
  CONSTRAINT fk_hotspot_request_user FOREIGN KEY (requested_by_user_id) REFERENCES host_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE hotspot_change_requests
  MODIFY COLUMN operation ENUM('nas_verify','nas_sync','nas_prepare','hotspot_apply','hotspot_deactivate','hotspot_cleanup') NOT NULL;

CREATE TABLE IF NOT EXISTS partner_network_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT NOT NULL,
  nas_id INT NOT NULL,
  hotspot_id BIGINT UNSIGNED NULL,
  vlan_id INT UNSIGNED NOT NULL,
  network_cidr VARCHAR(43) NOT NULL,
  state ENUM('reserved','applied','released') NOT NULL DEFAULT 'reserved',
  active_nas_vlan VARCHAR(64) AS (CASE WHEN state IN ('reserved','applied') THEN CONCAT(nas_id,':',vlan_id) ELSE NULL END) STORED,
  active_nas_network VARCHAR(128) AS (CASE WHEN state IN ('reserved','applied') THEN CONCAT(nas_id,':',network_cidr) ELSE NULL END) STORED,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_partner_network_active_nas_vlan (active_nas_vlan),
  UNIQUE KEY uq_partner_network_active_nas_network (active_nas_network),
  KEY idx_partner_network_reservation (partner_id,state,expires_at),
  CONSTRAINT fk_partner_network_reservation_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_network_reservation_nas FOREIGN KEY (nas_id) REFERENCES nas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_network_reservation_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE partner_network_reservations
  ADD COLUMN IF NOT EXISTS active_nas_network VARCHAR(128)
    AS (CASE WHEN state IN ('reserved','applied') THEN CONCAT(nas_id,':',network_cidr) ELSE NULL END) STORED
    AFTER active_nas_vlan;

CREATE UNIQUE INDEX IF NOT EXISTS uq_partner_network_active_nas_network
  ON partner_network_reservations (active_nas_network);

ALTER TABLE partner_hotspots
  ADD COLUMN IF NOT EXISTS desired_config_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER active,
  ADD COLUMN IF NOT EXISTS applied_config_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER desired_config_version,
  ADD COLUMN IF NOT EXISTS management_state ENUM('legacy','draft','queued','applying','ready','error','retired') NOT NULL DEFAULT 'legacy' AFTER applied_config_version,
  ADD COLUMN IF NOT EXISTS last_change_request_id BIGINT UNSIGNED NULL AFTER management_state;

-- O legado herdava `partners.active` mesmo quando a unidade nunca recebeu um
-- NAS. Preserva o cadastro e registra a correção, mas não inventa associação
-- técnica nem acessa equipamento remoto.
INSERT INTO partner_admin_audit
  (partner_id,actor_type,actor_id,action,target_type,target_id,metadata,origin_hash)
SELECT h.partner_id,'system',NULL,'hotspot.active_without_nas_reconciled','partner_hotspot',CAST(h.id AS CHAR),'{"previous_active":true,"reason":"nas_required"}',NULL
FROM partner_hotspots h
WHERE h.active=1 AND h.nas_id IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM partner_admin_audit a
    WHERE a.partner_id=h.partner_id
      AND a.action='hotspot.active_without_nas_reconciled'
      AND a.target_type='partner_hotspot'
      AND a.target_id=(CONVERT(CAST(h.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci)
  );

UPDATE partner_hotspots
SET active=0,management_state='legacy',updated_at=NOW()
WHERE active=1 AND nas_id IS NULL;

ALTER TABLE partner_hotspots
  ADD CONSTRAINT IF NOT EXISTS chk_partner_hotspots_active_nas
  CHECK (active=0 OR nas_id IS NOT NULL);

CREATE INDEX IF NOT EXISTS idx_partner_hotspots_management ON partner_hotspots (partner_id,management_state,active);
