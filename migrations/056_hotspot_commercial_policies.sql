-- Política comercial efetiva por ponto Hotspot.
--
-- O backfill copia o comportamento publicado atual de cada estabelecimento.
-- Portanto, instalar esta migração não ativa, desativa ou altera nenhuma
-- jornada existente e não executa qualquer operação no RouterOS.

CREATE TABLE IF NOT EXISTS partner_hotspot_commercial_policies (
  hotspot_id BIGINT UNSIGNED NOT NULL,
  partner_id INT NOT NULL,
  paid_access_enabled TINYINT(1) NOT NULL DEFAULT 0,
  courtesy_mode ENUM('disabled','direct','sponsored') NOT NULL DEFAULT 'disabled',
  payment_window_enabled TINYINT(1) NOT NULL DEFAULT 0,
  payment_window_minutes TINYINT UNSIGNED NOT NULL DEFAULT 2,
  payment_window_daily_limit TINYINT UNSIGNED NOT NULL DEFAULT 3,
  payment_window_cooldown_minutes TINYINT UNSIGNED NOT NULL DEFAULT 10,
  payment_window_period_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 1440,
  updated_by_type ENUM('firespot','partner_admin','system') NOT NULL DEFAULT 'system',
  updated_by_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (hotspot_id),
  KEY idx_hotspot_commercial_partner (partner_id,hotspot_id),
  CONSTRAINT chk_hotspot_commercial_boolean CHECK (paid_access_enabled IN (0,1) AND payment_window_enabled IN (0,1)),
  CONSTRAINT chk_hotspot_commercial_window CHECK (payment_window_minutes BETWEEN 1 AND 5 AND payment_window_daily_limit BETWEEN 1 AND 12 AND payment_window_cooldown_minutes BETWEEN 5 AND 60 AND payment_window_period_minutes BETWEEN 60 AND 10080),
  CONSTRAINT fk_hotspot_commercial_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id) ON DELETE CASCADE,
  CONSTRAINT fk_hotspot_commercial_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO partner_hotspot_commercial_policies
  (hotspot_id,partner_id,paid_access_enabled,courtesy_mode,payment_window_enabled,payment_window_minutes,payment_window_daily_limit,payment_window_cooldown_minutes,payment_window_period_minutes,updated_by_type)
SELECT point.id,point.partner_id,
       COALESCE(config.paid_access_enabled,IF(partner.access_purpose IN ('paid','hybrid'),1,0)),
       COALESCE(config.courtesy_mode,CASE partner.access_purpose WHEN 'free' THEN 'direct' WHEN 'sponsored' THEN 'sponsored' WHEN 'hybrid' THEN 'direct' ELSE 'disabled' END),
       COALESCE(config.paid_access_enabled,IF(partner.access_purpose IN ('paid','hybrid'),1,0)),
       LEAST(5,GREATEST(1,COALESCE(partner.payment_window_minutes,2))),
       LEAST(12,GREATEST(1,COALESCE(partner.payment_window_daily_limit,3))),
       LEAST(60,GREATEST(5,COALESCE(partner.payment_window_cooldown_minutes,10))),
       LEAST(10080,GREATEST(60,COALESCE(partner.payment_window_period_minutes,1440))),
       'system'
FROM partner_hotspots point
JOIN partners partner ON partner.id=point.partner_id
LEFT JOIN partner_portal_configurations config ON config.partner_id=point.partner_id AND config.state='published'
ON DUPLICATE KEY UPDATE hotspot_id=VALUES(hotspot_id);
