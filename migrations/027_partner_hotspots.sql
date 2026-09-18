-- FireSpot: múltiplas instalações técnicas por estabelecimento.
-- Migração aditiva: mantém os campos técnicos legados em partners para rollback.

CREATE TABLE IF NOT EXISTS partner_hotspots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT(11) NOT NULL,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(150) NOT NULL,
  nas_id INT(11) NULL,
  nas_interface_id INT(10) UNSIGNED NULL,
  vlan_id INT(10) UNSIGNED NULL,
  gateway_ip VARCHAR(64) NULL,
  pool_start VARCHAR(64) NULL,
  pool_end VARCHAR(64) NULL,
  dns_servers VARCHAR(128) NULL,
  dns_name VARCHAR(150) NULL,
  radius_ip VARCHAR(64) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  default_partner_id INT(11) AS (CASE WHEN is_default=1 THEN partner_id ELSE NULL END) STORED,
  active_nas_id INT(11) AS (CASE WHEN active=1 AND vlan_id IS NOT NULL THEN nas_id ELSE NULL END) STORED,
  active_vlan_id INT(10) UNSIGNED AS (CASE WHEN active=1 AND nas_id IS NOT NULL THEN vlan_id ELSE NULL END) STORED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_partner_hotspot_code (code),
  UNIQUE KEY uq_partner_hotspot_pair (partner_id,id),
  UNIQUE KEY uq_partner_hotspot_default (default_partner_id),
  UNIQUE KEY uq_partner_hotspot_active_nas_vlan (active_nas_id,active_vlan_id),
  KEY idx_partner_hotspot_partner (partner_id,active,name),
  KEY idx_partner_hotspot_nas (nas_id,active),
  KEY idx_partner_hotspot_interface (nas_interface_id),
  CONSTRAINT fk_partner_hotspot_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_hotspot_nas FOREIGN KEY (nas_id) REFERENCES nas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_hotspot_interface FOREIGN KEY (nas_interface_id) REFERENCES nas_interfaces(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO partner_hotspots
  (partner_id,code,name,nas_id,nas_interface_id,vlan_id,gateway_ip,pool_start,pool_end,dns_servers,dns_name,radius_ip,is_default,active,created_at,updated_at)
SELECT
  p.id,p.code,'Principal',p.nas_id,
  CASE WHEN i.id IS NOT NULL AND i.nas_id=p.nas_id THEN p.nas_interface_id ELSE NULL END,
  p.vlan_id,p.gateway_ip,p.pool_start,p.pool_end,p.dns_servers,p.dns_name,p.radius_ip,1,p.active,p.created_at,p.updated_at
FROM partners p
LEFT JOIN partner_hotspots h ON h.partner_id=p.id AND h.is_default=1
LEFT JOIN nas_interfaces i ON i.id=p.nas_interface_id
WHERE h.id IS NULL;

ALTER TABLE guest_orders
  ADD COLUMN IF NOT EXISTS hotspot_id BIGINT UNSIGNED NULL AFTER partner_id,
  ADD INDEX IF NOT EXISTS idx_guest_orders_hotspot (hotspot_id,status,created_at);

ALTER TABLE courtesy_grants
  ADD COLUMN IF NOT EXISTS hotspot_id BIGINT UNSIGNED NULL AFTER partner_id,
  ADD INDEX IF NOT EXISTS idx_courtesy_hotspot (hotspot_id,status,created_at);

ALTER TABLE custom_ads_events
  ADD COLUMN IF NOT EXISTS hotspot_id BIGINT UNSIGNED NULL AFTER partner_id,
  ADD INDEX IF NOT EXISTS idx_custom_ads_event_hotspot (hotspot_id,event,created_at);

ALTER TABLE ad_deliveries
  ADD COLUMN IF NOT EXISTS hotspot_id BIGINT UNSIGNED NULL AFTER partner_id,
  ADD INDEX IF NOT EXISTS idx_ad_delivery_hotspot (hotspot_id,state,started_at);

ALTER TABLE login_tokens
  ADD COLUMN IF NOT EXISTS hotspot_id BIGINT UNSIGNED NULL AFTER partner_code,
  ADD INDEX IF NOT EXISTS idx_login_token_hotspot (hotspot_id,created_at);

UPDATE guest_orders o
JOIN partner_hotspots h ON h.partner_id=o.partner_id AND h.is_default=1
SET o.hotspot_id=h.id
WHERE o.hotspot_id IS NULL;

UPDATE courtesy_grants g
JOIN partner_hotspots h ON h.partner_id=g.partner_id AND h.is_default=1
SET g.hotspot_id=h.id
WHERE g.hotspot_id IS NULL AND g.partner_id IS NOT NULL;

UPDATE custom_ads_events e
JOIN partner_hotspots h ON h.partner_id=e.partner_id AND h.is_default=1
SET e.hotspot_id=h.id
WHERE e.hotspot_id IS NULL AND e.partner_id IS NOT NULL;

UPDATE ad_deliveries d
JOIN partner_hotspots h ON h.partner_id=d.partner_id AND h.is_default=1
SET d.hotspot_id=h.id
WHERE d.hotspot_id IS NULL;

UPDATE login_tokens t
JOIN partners p ON BINARY p.code=BINARY t.partner_code
JOIN partner_hotspots h ON h.partner_id=p.id AND h.is_default=1
SET t.hotspot_id=h.id
WHERE t.hotspot_id IS NULL;
