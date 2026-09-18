-- Governança do painel do estabelecimento.
--
-- A FireSpot continua sendo a única responsável pelo cadastro, credenciais,
-- preparação e retirada de NAS. O estabelecimento pode manter rascunhos de
-- pontos somente nos NAS que a Central lhe atribuiu explicitamente.

CREATE TABLE IF NOT EXISTS partner_nas_assignments (
  partner_id INT NOT NULL,
  nas_id INT NOT NULL,
  status ENUM('ready','retired') NOT NULL DEFAULT 'ready',
  assignment_source ENUM('legacy_point','legacy_ownership','firespot') NOT NULL DEFAULT 'firespot',
  assigned_by_id BIGINT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retired_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (partner_id,nas_id),
  KEY idx_partner_nas_assignment_nas (nas_id,status,partner_id),
  CONSTRAINT fk_partner_nas_assignment_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_nas_assignment_nas FOREIGN KEY (nas_id) REFERENCES nas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserva todas as associações técnicas que já existiam sem tocar no NAS.
INSERT INTO partner_nas_assignments
  (partner_id,nas_id,status,assignment_source,assigned_by_id)
SELECT DISTINCT h.partner_id,h.nas_id,'ready','legacy_point',NULL
FROM partner_hotspots h
WHERE h.nas_id IS NOT NULL
ON DUPLICATE KEY UPDATE partner_id=VALUES(partner_id);

INSERT INTO partner_nas_assignments
  (partner_id,nas_id,status,assignment_source,assigned_by_id)
SELECT o.partner_id,o.nas_id,IF(o.status='retired','retired','ready'),'legacy_ownership',NULL
FROM partner_nas_ownerships o
ON DUPLICATE KEY UPDATE
  status=IF(partner_nas_assignments.status='retired',VALUES(status),partner_nas_assignments.status),
  updated_at=NOW();

-- A autoria de um rascunho visual também pode ser do administrador do próprio
-- estabelecimento. Publicação e ativação continuam fora deste fluxo.
ALTER TABLE partner_portal_presentations
  MODIFY COLUMN created_by_type ENUM('firespot','partner_admin','system') NOT NULL DEFAULT 'system';

-- Nova versão comercial. Contratos na v1 continuam historicamente íntegros;
-- somente novas atribuições usam a v2, sem cota ou autogestão de NAS.
UPDATE platform_plans
SET active=0,updated_at=NOW()
WHERE code='multipoint_advanced' AND version=1;

INSERT INTO platform_plans
  (code,version,name,description,internal_only,active,max_nas,max_hotspots,max_admin_users,max_report_range_days,custom_courtesy_overrides)
VALUES
  ('multipoint_advanced',2,'Multipontos e Gestão Avançada','Múltiplos pontos em NAS atribuídos pela FireSpot, Portal V3 personalizável, carteira própria, cortesia e métricas avançadas.',0,1,0,25,10,366,25)
ON DUPLICATE KEY UPDATE code=VALUES(code);

INSERT INTO platform_plan_features (plan_id,feature_code,enabled)
SELECT p.id,f.feature_code,1
FROM platform_plans p
CROSS JOIN (
  SELECT 'portal.basic' feature_code UNION ALL
  SELECT 'branding.manage' UNION ALL
  SELECT 'portal.presentation.manage' UNION ALL
  SELECT 'guest_plans.manage' UNION ALL
  SELECT 'reports.basic' UNION ALL
  SELECT 'reports.advanced' UNION ALL
  SELECT 'reports.export' UNION ALL
  SELECT 'finance.view' UNION ALL
  SELECT 'nas.view' UNION ALL
  SELECT 'hotspots.view' UNION ALL
  SELECT 'hotspots.draft.manage' UNION ALL
  SELECT 'wallet.manage' UNION ALL
  SELECT 'courtesy.view' UNION ALL
  SELECT 'courtesy.manage' UNION ALL
  SELECT 'courtesy.hotspot_override.manage' UNION ALL
  SELECT 'team.manage' UNION ALL
  SELECT 'ads.manage' UNION ALL
  SELECT 'monetization.view'
) f
WHERE p.code='multipoint_advanced' AND p.version=2
ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_at=NOW();

-- Aplicação remota e todas as operações de NAS permanecem explicitamente fora
-- do plano do estabelecimento, inclusive se o gate piloto mudar no futuro.
INSERT INTO platform_plan_features (plan_id,feature_code,enabled)
SELECT p.id,f.feature_code,0
FROM platform_plans p
CROSS JOIN (
  SELECT 'hotspots.apply' feature_code UNION ALL
  SELECT 'nas.manage' UNION ALL
  SELECT 'nas.prepare' UNION ALL
  SELECT 'nas.retire'
) f
WHERE p.code='multipoint_advanced' AND p.version=2
ON DUPLICATE KEY UPDATE enabled=0,updated_at=NOW();
