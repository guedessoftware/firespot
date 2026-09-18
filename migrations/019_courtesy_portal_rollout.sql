CREATE TABLE IF NOT EXISTS courtesy_portal_rollouts (
  partner_id INT(11) NOT NULL,
  portal VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  mode ENUM('legacy','shadow','enforce') NOT NULL DEFAULT 'legacy',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by VARCHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (partner_id,portal),
  KEY idx_courtesy_rollout_portal_mode (portal,mode),
  CONSTRAINT fk_courtesy_rollout_partner
    FOREIGN KEY (partner_id) REFERENCES partners (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO courtesy_portal_rollouts (partner_id,portal,mode)
SELECT p.id, portals.portal, portals.mode
  FROM partners p
 CROSS JOIN (
   SELECT 'qr_ad' AS portal, 'shadow' AS mode
   UNION ALL SELECT 'v2', 'shadow'
   UNION ALL SELECT 'classic_signup', 'shadow'
   UNION ALL SELECT 'v3', 'legacy'
 ) portals;

INSERT IGNORE INTO app_settings (skey,svalue) VALUES
  ('courtesy_cutover_enabled','0'),
  ('courtesy_radius_ready','0'),
  ('courtesy_mikrotik_ready','0');
