-- Preferencias de navegacao do Portal V3.
-- Os valores padrao preservam a jornada publicada antes desta migracao.

ALTER TABLE partner_portal_configurations
  ADD COLUMN IF NOT EXISTS welcome_screen_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER lead_capture_enabled,
  ADD COLUMN IF NOT EXISTS single_option_direct_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER welcome_screen_enabled;
