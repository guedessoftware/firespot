-- FireSpot: mídia rica e fluxo de interesse sem interromper a autenticação.
-- Migração aditiva; image_url permanece durante a compatibilidade com superfícies legadas.

ALTER TABLE custom_ads
  ADD COLUMN IF NOT EXISTS media_type ENUM('image','video') NOT NULL DEFAULT 'image' AFTER title,
  ADD COLUMN IF NOT EXISTS media_url VARCHAR(500) NULL AFTER media_type,
  ADD COLUMN IF NOT EXISTS poster_url VARCHAR(500) NULL AFTER media_url,
  ADD COLUMN IF NOT EXISTS fit_mode ENUM('contain','cover') NOT NULL DEFAULT 'contain' AFTER poster_url;

UPDATE custom_ads
SET media_url=image_url
WHERE media_url IS NULL OR media_url='';

-- O valor antigo 0 significava "usar padrão" e produzia tempos diferentes
-- entre portais. A campanha agora guarda sempre o prazo efetivo.
UPDATE custom_ads
SET duration_sec=CASE
  WHEN duration_sec<5 THEN 15
  WHEN duration_sec>180 THEN 180
  ELSE duration_sec
END;

ALTER TABLE custom_ads
  MODIFY COLUMN media_url VARCHAR(500) NOT NULL,
  MODIFY COLUMN image_url VARCHAR(500) NOT NULL,
  ADD INDEX IF NOT EXISTS idx_custom_ads_media (media_type,active);

ALTER TABLE custom_ads_events
  MODIFY COLUMN event ENUM(
    'impression',
    'interest_yes',
    'interest_no',
    'view_complete',
    'skipped',
    'destination_open'
  ) NOT NULL;

CREATE TABLE IF NOT EXISTS ad_pending_offers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  partner_id INT(11) NOT NULL,
  ad_id BIGINT NOT NULL,
  expires_at DATETIME NOT NULL,
  opened_at DATETIME NULL,
  dismissed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ad_pending_offer_token (token_hash),
  KEY idx_ad_pending_offer_expiry (expires_at,opened_at,dismissed_at),
  KEY idx_ad_pending_offer_partner (partner_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
