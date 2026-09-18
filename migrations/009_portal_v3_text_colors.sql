ALTER TABLE partner_portal_themes
  ADD COLUMN IF NOT EXISTS text_color CHAR(7) DEFAULT NULL AFTER background_color,
  ADD COLUMN IF NOT EXISTS muted_text_color CHAR(7) DEFAULT NULL AFTER text_color,
  ADD COLUMN IF NOT EXISTS hero_text_color CHAR(7) DEFAULT NULL AFTER muted_text_color,
  ADD COLUMN IF NOT EXISTS button_text_color CHAR(7) DEFAULT NULL AFTER hero_text_color,
  ADD COLUMN IF NOT EXISTS footer_text_color CHAR(7) DEFAULT NULL AFTER button_text_color;
