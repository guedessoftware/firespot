-- Solicitações versionadas de configuração dos pontos do estabelecimento.
--
-- A configuração efetiva em partner_hotspots não é alterada por este
-- fluxo. A Central FireSpot continua responsável pela revisão e por qualquer
-- aplicação remota no RouterOS.

CREATE TABLE IF NOT EXISTS partner_hotspot_configuration_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT NOT NULL,
  hotspot_id BIGINT UNSIGNED NOT NULL,
  requested_name VARCHAR(150) NOT NULL,
  requested_nas_id INT NOT NULL,
  requested_nas_interface_id INT UNSIGNED NOT NULL,
  reason VARCHAR(300) NULL,
  revision INT UNSIGNED NOT NULL,
  state ENUM('submitted','superseded','cancelled','approved','rejected') NOT NULL DEFAULT 'submitted',
  requested_by_user_id BIGINT UNSIGNED NULL,
  reviewed_by_admin_id INT UNSIGNED NULL,
  review_note VARCHAR(300) NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  active_hotspot_id BIGINT UNSIGNED AS (CASE WHEN state='submitted' THEN hotspot_id ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_partner_hotspot_configuration_revision (hotspot_id,revision),
  UNIQUE KEY uq_partner_hotspot_configuration_active (active_hotspot_id),
  KEY idx_partner_hotspot_configuration_partner (partner_id,state,updated_at),
  KEY idx_partner_hotspot_configuration_target_nas (requested_nas_id,requested_nas_interface_id),
  CONSTRAINT fk_partner_hotspot_configuration_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_hotspot_configuration_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_hotspot_configuration_nas FOREIGN KEY (requested_nas_id) REFERENCES nas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_hotspot_configuration_interface FOREIGN KEY (requested_nas_interface_id) REFERENCES nas_interfaces(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_hotspot_configuration_user FOREIGN KEY (requested_by_user_id) REFERENCES host_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_partner_hotspot_configuration_admin FOREIGN KEY (reviewed_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
