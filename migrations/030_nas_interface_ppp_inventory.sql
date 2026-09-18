-- Separa infraestrutura estática do NAS das sessões PPP dinâmicas.

ALTER TABLE nas_interfaces
  ADD COLUMN IF NOT EXISTS interface_type VARCHAR(32) NULL AFTER interface_name;

ALTER TABLE nas_health
  ADD COLUMN IF NOT EXISTS ppp_active_count INT UNSIGNED NULL AFTER hotspot_host_count;

CREATE TABLE IF NOT EXISTS nas_ppp_active_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nas_id INT(11) NOT NULL,
  session_key CHAR(64) NOT NULL,
  username VARCHAR(128) NOT NULL,
  service VARCHAR(32) NULL,
  caller_id VARCHAR(128) NULL,
  address VARCHAR(64) NULL,
  uptime VARCHAR(64) NULL,
  session_id VARCHAR(64) NULL,
  synced_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nas_ppp_active_session (nas_id,session_key),
  KEY idx_nas_ppp_active_user (nas_id,username),
  CONSTRAINT fk_nas_ppp_active_nas FOREIGN KEY (nas_id) REFERENCES nas(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
