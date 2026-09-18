-- Política do equipamento para criação determinística dos pontos Hotspot.
--
-- O NAS define RADIUS, acesso/telemetria e os limites de alocação. O ponto
-- consome uma VLAN e uma sub-rede dessa política. Nenhum ponto existente e
-- nenhum RouterOS são alterados por esta migração.

CREATE TABLE IF NOT EXISTS nas_hotspot_allocation_policies (
  nas_id INT NOT NULL,
  vlan_start SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  vlan_end SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  network_template VARCHAR(64) NOT NULL DEFAULT '10.{vlan}.0.0',
  prefix_length TINYINT UNSIGNED NOT NULL DEFAULT 24,
  gateway_offset INT UNSIGNED NOT NULL DEFAULT 1,
  pool_start_offset INT UNSIGNED NOT NULL DEFAULT 2,
  pool_end_reserve INT UNSIGNED NOT NULL DEFAULT 1,
  default_dns_servers VARCHAR(255) NOT NULL DEFAULT '1.1.1.1,8.8.8.8',
  updated_by_type ENUM('firespot','partner_admin','system') NOT NULL DEFAULT 'system',
  updated_by_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (nas_id),
  CONSTRAINT chk_nas_hotspot_policy_vlan CHECK (vlan_start BETWEEN 1 AND 255 AND vlan_end BETWEEN vlan_start AND 255),
  CONSTRAINT chk_nas_hotspot_policy_prefix CHECK (prefix_length BETWEEN 16 AND 30),
  CONSTRAINT chk_nas_hotspot_policy_offsets CHECK (gateway_offset>=1 AND pool_start_offset>=1 AND pool_end_reserve>=1),
  CONSTRAINT fk_nas_hotspot_policy_nas FOREIGN KEY (nas_id) REFERENCES nas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nas_hotspot_allocation_policies
  (nas_id,vlan_start,vlan_end,network_template,prefix_length,gateway_offset,pool_start_offset,pool_end_reserve,default_dns_servers,updated_by_type)
SELECT n.id,100,200,'10.{vlan}.0.0',24,1,2,1,'1.1.1.1,8.8.8.8','system'
FROM nas n
ON DUPLICATE KEY UPDATE nas_id=VALUES(nas_id);

ALTER TABLE partners
  ADD COLUMN IF NOT EXISTS network_prefix_length TINYINT UNSIGNED NULL AFTER vlan_id;

UPDATE partners
SET network_prefix_length=CASE
  WHEN gateway_ip IS NOT NULL AND pool_start IS NOT NULL AND pool_end IS NOT NULL
    AND SUBSTRING_INDEX(gateway_ip,'.',2)=SUBSTRING_INDEX(pool_start,'.',2)
    AND SUBSTRING_INDEX(pool_start,'.',2)=SUBSTRING_INDEX(pool_end,'.',2)
    AND SUBSTRING_INDEX(SUBSTRING_INDEX(pool_start,'.',3),'.',-1)<>SUBSTRING_INDEX(SUBSTRING_INDEX(pool_end,'.',3),'.',-1)
  THEN 16 ELSE 24 END
WHERE network_prefix_length IS NULL;

ALTER TABLE partners
  MODIFY COLUMN network_prefix_length TINYINT UNSIGNED NOT NULL DEFAULT 24 AFTER vlan_id;

ALTER TABLE partner_hotspots
  ADD COLUMN IF NOT EXISTS network_prefix_length TINYINT UNSIGNED NULL AFTER vlan_id;

UPDATE partner_hotspots
SET network_prefix_length=CASE
  WHEN gateway_ip IS NOT NULL AND pool_start IS NOT NULL AND pool_end IS NOT NULL
    AND SUBSTRING_INDEX(gateway_ip,'.',2)=SUBSTRING_INDEX(pool_start,'.',2)
    AND SUBSTRING_INDEX(pool_start,'.',2)=SUBSTRING_INDEX(pool_end,'.',2)
    AND SUBSTRING_INDEX(SUBSTRING_INDEX(pool_start,'.',3),'.',-1)<>SUBSTRING_INDEX(SUBSTRING_INDEX(pool_end,'.',3),'.',-1)
  THEN 16 ELSE 24 END
WHERE network_prefix_length IS NULL;

ALTER TABLE partner_hotspots
  MODIFY COLUMN network_prefix_length TINYINT UNSIGNED NOT NULL DEFAULT 24 AFTER vlan_id;

ALTER TABLE partner_hotspot_configuration_requests
  ADD COLUMN IF NOT EXISTS requested_dns_servers VARCHAR(255) NULL AFTER requested_nas_interface_id;

UPDATE partner_hotspot_configuration_requests request
JOIN partner_hotspots point ON point.id=request.hotspot_id
SET request.requested_dns_servers=COALESCE(NULLIF(point.dns_servers,''),'1.1.1.1,8.8.8.8')
WHERE request.requested_dns_servers IS NULL;

ALTER TABLE partner_hotspot_configuration_requests
  MODIFY COLUMN requested_dns_servers VARCHAR(255) NOT NULL AFTER requested_nas_interface_id;
