-- Preparação mínima e idempotente de NAS MikroTik, separada das instalações Hotspot.

CREATE TABLE IF NOT EXISTS nas_base_provisioning (
  nas_id INT NOT NULL,
  radius_server_id INT UNSIGNED NULL,
  status ENUM('pending','applying','ready','error') NOT NULL DEFAULT 'pending',
  config_revision SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  routeros_version VARCHAR(64) NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt_at DATETIME NULL,
  provisioned_at DATETIME NULL,
  last_error_code VARCHAR(64) NULL,
  last_error_detail VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (nas_id),
  KEY idx_nas_base_radius (radius_server_id),
  KEY idx_nas_base_status (status,updated_at),
  CONSTRAINT fk_nas_base_nas FOREIGN KEY (nas_id) REFERENCES nas(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_nas_base_radius FOREIGN KEY (radius_server_id) REFERENCES radius_servers(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO nas_base_provisioning (nas_id,status)
SELECT id,'pending' FROM nas;

-- Reaproveita o RADIUS já associado às instalações existentes, sem configurar
-- remotamente os equipamentos durante a migração.
UPDATE nas_base_provisioning b
JOIN (
  SELECT h.nas_id,MIN(r.id) radius_server_id
  FROM partner_hotspots h
  JOIN radius_servers r ON r.host=h.radius_ip
  WHERE h.nas_id IS NOT NULL
  GROUP BY h.nas_id
) x ON x.nas_id=b.nas_id
SET b.radius_server_id=x.radius_server_id
WHERE b.radius_server_id IS NULL;

UPDATE nas_base_provisioning b
JOIN (SELECT MIN(id) radius_server_id,COUNT(*) total FROM radius_servers) r ON r.total=1
SET b.radius_server_id=r.radius_server_id
WHERE b.radius_server_id IS NULL;
