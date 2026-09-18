ALTER TABLE partner_portal_themes
  MODIFY COLUMN theme_preset ENUM('modern','compact_blue','compact_light') NOT NULL DEFAULT 'modern',
  ADD COLUMN IF NOT EXISTS logo_light_path VARCHAR(255) DEFAULT NULL AFTER logo_path,
  ADD COLUMN IF NOT EXISTS logo_dark_path VARCHAR(255) DEFAULT NULL AFTER logo_light_path;

UPDATE partner_portal_themes
SET logo_light_path=COALESCE(logo_light_path,logo_path),
    logo_dark_path=COALESCE(logo_dark_path,logo_path),
    logo_path=NULL
WHERE logo_path IS NOT NULL;

-- O preset noturno antigo aplicava esta paleta apenas em tempo de execução.
-- Materializá-la uma única vez mantém o visual existente e libera a edição.
UPDATE partner_portal_themes
SET primary_color='#13aaf5',
    secondary_color='#087bcf',
    background_color='#050817'
WHERE theme_preset='compact_blue'
  AND primary_color='#ff9f1c'
  AND secondary_color='#ff6b00'
  AND background_color='#071225';
