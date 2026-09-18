ALTER TABLE partner_portal_themes
  ADD COLUMN IF NOT EXISTS show_title TINYINT(1) NOT NULL DEFAULT 1 AFTER theme_preset;

