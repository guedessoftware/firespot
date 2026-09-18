ALTER TABLE partner_portal_themes
  ADD COLUMN IF NOT EXISTS theme_preset ENUM('modern','compact_blue') NOT NULL DEFAULT 'modern' AFTER partner_id;

