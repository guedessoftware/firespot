CREATE TABLE IF NOT EXISTS portal_skin_catalog (
  code VARCHAR(64) NOT NULL,
  version SMALLINT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL,
  description VARCHAR(300) NOT NULL,
  manifest_json LONGTEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (code,version),
  KEY idx_portal_skin_catalog_active (active,label),
  CONSTRAINT chk_portal_skin_manifest_json CHECK (JSON_VALID(manifest_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO portal_skin_catalog (code,version,label,description,manifest_json,active) VALUES
('balanced',1,'Equilibrado','Marca, mensagem e modalidades em equilíbrio.','{"regions":["brand","message","options","legal"],"components":["welcome","courtesy","paid","subscriber","plans"],"content":{"headline":80,"message":240,"legal":300},"media":{"hero":"16:9","logo":"4:1"}}',1),
('quick-connect',1,'Conexão rápida','Baixa densidade e ação principal em destaque.','{"regions":["brand","primary_action","legal"],"components":["welcome","courtesy","paid","subscriber","plans"],"content":{"headline":60,"message":160,"legal":300},"media":{"hero":"3:2","logo":"4:1"}}',1),
('sponsored-focus',1,'Patrocinado em destaque','Região de alta visibilidade para a peça publicitária.','{"regions":["brand","sponsor","options","legal"],"components":["welcome","courtesy","paid","subscriber","plans","sponsor"],"content":{"headline":80,"message":220,"legal":300},"media":{"hero":"9:16","logo":"4:1","sponsor":"9:16"}}',1),
('access-catalog',1,'Catálogo de acesso','Prioriza comparação e compra dos planos disponíveis.','{"regions":["brand","plans","options","legal"],"components":["welcome","courtesy","paid","subscriber","plans"],"content":{"headline":80,"message":180,"legal":300},"media":{"hero":"16:9","logo":"4:1"}}',1),
('institutional',1,'Institucional','Maior presença de marca e conteúdo institucional.','{"regions":["brand","hero","message","options","legal"],"components":["welcome","courtesy","paid","subscriber","plans"],"content":{"headline":100,"message":400,"legal":300},"media":{"hero":"16:9","logo":"4:1"}}',1)
ON DUPLICATE KEY UPDATE code=VALUES(code);

CREATE TABLE IF NOT EXISTS partner_portal_presentations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT NOT NULL,
  revision INT UNSIGNED NOT NULL,
  state ENUM('draft','candidate','published','superseded','discarded') NOT NULL DEFAULT 'draft',
  source ENUM('legacy_theme','manual','rollback') NOT NULL DEFAULT 'manual',
  skin_code VARCHAR(64) NOT NULL,
  skin_version SMALLINT UNSIGNED NOT NULL,
  portal_configuration_id BIGINT UNSIGNED NULL,
  identity_json LONGTEXT NOT NULL,
  content_json LONGTEXT NOT NULL,
  validation_snapshot LONGTEXT NULL,
  validation_hash CHAR(64) NULL,
  validated_at DATETIME NULL,
  base_presentation_id BIGINT UNSIGNED NULL,
  created_by_type ENUM('firespot','system') NOT NULL DEFAULT 'system',
  created_by_id BIGINT UNSIGNED NULL,
  published_by_id BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  draft_partner_id INT AS (CASE WHEN state='draft' THEN partner_id ELSE NULL END) STORED,
  candidate_partner_id INT AS (CASE WHEN state='candidate' THEN partner_id ELSE NULL END) STORED,
  published_partner_id INT AS (CASE WHEN state='published' THEN partner_id ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_partner_portal_presentation_revision (partner_id,revision),
  UNIQUE KEY uq_partner_portal_presentation_draft (draft_partner_id),
  UNIQUE KEY uq_partner_portal_presentation_candidate (candidate_partner_id),
  UNIQUE KEY uq_partner_portal_presentation_published (published_partner_id),
  KEY idx_partner_portal_presentation_skin (skin_code,skin_version),
  KEY idx_partner_portal_presentation_config (portal_configuration_id),
  KEY idx_partner_portal_presentation_base (base_presentation_id),
  CONSTRAINT chk_partner_portal_identity_json CHECK (JSON_VALID(identity_json)),
  CONSTRAINT chk_partner_portal_content_json CHECK (JSON_VALID(content_json)),
  CONSTRAINT chk_partner_portal_validation_json CHECK (validation_snapshot IS NULL OR JSON_VALID(validation_snapshot)),
  CONSTRAINT fk_partner_portal_presentation_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_portal_presentation_skin FOREIGN KEY (skin_code,skin_version) REFERENCES portal_skin_catalog(code,version) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_portal_presentation_config FOREIGN KEY (portal_configuration_id) REFERENCES partner_portal_configurations(id) ON DELETE SET NULL,
  CONSTRAINT fk_partner_portal_presentation_base FOREIGN KEY (base_presentation_id) REFERENCES partner_portal_presentations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_portal_migrations (
  partner_id INT NOT NULL,
  status ENUM('legacy_active','draft','ready','v3_active','rollback_available') NOT NULL DEFAULT 'legacy_active',
  source_portal_mode ENUM('inherit','classic','v2','v3') NOT NULL,
  draft_presentation_id BIGINT UNSIGNED NULL,
  candidate_presentation_id BIGINT UNSIGNED NULL,
  published_presentation_id BIGINT UNSIGNED NULL,
  previous_presentation_id BIGINT UNSIGNED NULL,
  inventory_json LONGTEXT NOT NULL,
  checklist_json LONGTEXT NULL,
  checklist_hash CHAR(64) NULL,
  preview_mobile_approved TINYINT(1) NOT NULL DEFAULT 0,
  preview_desktop_approved TINYINT(1) NOT NULL DEFAULT 0,
  preview_approved_by_id BIGINT UNSIGNED NULL,
  preview_approved_at DATETIME NULL,
  prepared_by_id BIGINT UNSIGNED NULL,
  prepared_at DATETIME NULL,
  ready_by_id BIGINT UNSIGNED NULL,
  ready_at DATETIME NULL,
  activated_by_id BIGINT UNSIGNED NULL,
  activated_at DATETIME NULL,
  rollback_until DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (partner_id),
  KEY idx_partner_portal_migrations_status (status,updated_at),
  CONSTRAINT chk_partner_portal_inventory_json CHECK (JSON_VALID(inventory_json)),
  CONSTRAINT chk_partner_portal_checklist_json CHECK (checklist_json IS NULL OR JSON_VALID(checklist_json)),
  CONSTRAINT chk_partner_portal_preview_mobile CHECK (preview_mobile_approved IN (0,1)),
  CONSTRAINT chk_partner_portal_preview_desktop CHECK (preview_desktop_approved IN (0,1)),
  CONSTRAINT fk_partner_portal_migration_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_portal_migration_draft FOREIGN KEY (draft_presentation_id) REFERENCES partner_portal_presentations(id) ON DELETE SET NULL,
  CONSTRAINT fk_partner_portal_migration_candidate FOREIGN KEY (candidate_presentation_id) REFERENCES partner_portal_presentations(id) ON DELETE SET NULL,
  CONSTRAINT fk_partner_portal_migration_published FOREIGN KEY (published_presentation_id) REFERENCES partner_portal_presentations(id) ON DELETE SET NULL,
  CONSTRAINT fk_partner_portal_migration_previous FOREIGN KEY (previous_presentation_id) REFERENCES partner_portal_presentations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO partner_portal_migrations (partner_id,status,source_portal_mode,inventory_json)
SELECT p.id,IF(p.portal_mode='v3','v3_active','legacy_active'),p.portal_mode,
  JSON_OBJECT('portal_mode',p.portal_mode,'theme_preset',COALESCE(t.theme_preset,'modern'),'prepared',FALSE)
FROM partners p LEFT JOIN partner_portal_themes t ON t.partner_id=p.id
ON DUPLICATE KEY UPDATE partner_id=VALUES(partner_id);
