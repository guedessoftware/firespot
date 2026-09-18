-- Distribuição pública: identificadores de estabelecimento/NAS abaixo são fictícios.
-- Não substituir a migração já registrada em uma instalação existente.
-- Autogestão contratual de NAS próprio no plano Multipontos v3.
--
-- Esta migração não acessa RouterOS, não move pontos e não altera o
-- inventário central `nas`. Ela classifica somente os dois equipamentos que o
-- operador confirmou pertencerem ao estabelecimento example_partner. O NAS FireSpot
-- e o ponto TESTE continuam sob responsabilidade central.

ALTER TABLE partner_nas_ownerships
  ADD COLUMN IF NOT EXISTS display_name VARCHAR(80) NULL AFTER management_mode;

ALTER TABLE partner_nas_assignments
  MODIFY COLUMN assignment_source ENUM('legacy_point','legacy_ownership','firespot','partner_owned') NOT NULL DEFAULT 'firespot';

UPDATE platform_plans
SET active=0,updated_at=NOW()
WHERE code='multipoint_advanced' AND version=2;

INSERT INTO platform_plans
  (code,version,name,description,internal_only,active,max_nas,max_hotspots,max_admin_users,max_report_range_days,custom_courtesy_overrides)
VALUES
  ('multipoint_advanced',3,'Multipontos e Gestão Avançada','NAS próprios, múltiplos pontos, Portal V3 personalizável, carteira própria, cortesia e métricas avançadas.',0,1,10,25,10,366,25)
ON DUPLICATE KEY UPDATE
  name=VALUES(name),description=VALUES(description),internal_only=VALUES(internal_only),active=VALUES(active),
  max_nas=VALUES(max_nas),max_hotspots=VALUES(max_hotspots),max_admin_users=VALUES(max_admin_users),
  max_report_range_days=VALUES(max_report_range_days),custom_courtesy_overrides=VALUES(custom_courtesy_overrides),updated_at=NOW();

INSERT INTO platform_plan_features (plan_id,feature_code,enabled)
SELECT current_plan.id,previous_features.feature_code,previous_features.enabled
FROM platform_plans current_plan
JOIN platform_plans previous_plan ON previous_plan.code=current_plan.code AND previous_plan.version=2
JOIN platform_plan_features previous_features ON previous_features.plan_id=previous_plan.id
WHERE current_plan.code='multipoint_advanced' AND current_plan.version=3
ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_at=NOW();

INSERT INTO platform_plan_features (plan_id,feature_code,enabled)
SELECT plan.id,features.feature_code,features.enabled
FROM platform_plans plan
CROSS JOIN (
  SELECT 'nas.view' feature_code,1 enabled UNION ALL
  SELECT 'nas.manage',0 UNION ALL
  SELECT 'nas.prepare',0 UNION ALL
  SELECT 'nas.retire',0 UNION ALL
  SELECT 'hotspots.view',1 UNION ALL
  SELECT 'hotspots.draft.manage',1 UNION ALL
  SELECT 'hotspots.apply',0
) features
WHERE plan.code='multipoint_advanced' AND plan.version=3
ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_at=NOW();

-- As três capacidades de mutação permanecem fechadas neste estágio. O
-- adotador valida ambos os NAS e as habilita na mesma transação que promove a
-- propriedade, evitando um portal parcialmente liberado se um acesso falhar.

INSERT INTO partner_subscription_events
  (partner_id,subscription_id,event_type,from_plan_code,to_plan_code,from_status,to_status,reason,actor_type,actor_id)
SELECT subscription.partner_id,subscription.id,'plan_version_migrated',
       CONCAT('multipoint_advanced@',previous_plan.version),'multipoint_advanced@3',subscription.status,subscription.status,
       'Migração 053: autogestão restrita a NAS próprio no plano Multipontos, mantendo vigência e estado.','system',NULL
FROM partner_subscriptions subscription
JOIN platform_plans previous_plan ON previous_plan.id=subscription.plan_id
WHERE subscription.is_current=1
  AND previous_plan.code='multipoint_advanced'
  AND previous_plan.version<3
  AND NOT EXISTS (
    SELECT 1 FROM partner_subscription_events event
    WHERE event.subscription_id=subscription.id
      AND event.event_type='plan_version_migrated'
      AND event.to_plan_code='multipoint_advanced@3'
  );

UPDATE partner_subscriptions subscription
JOIN platform_plans previous_plan ON previous_plan.id=subscription.plan_id
JOIN platform_plans current_plan ON current_plan.code='multipoint_advanced' AND current_plan.version=3 AND current_plan.active=1
SET subscription.plan_id=current_plan.id,subscription.updated_at=NOW()
WHERE subscription.is_current=1
  AND previous_plan.code='multipoint_advanced'
  AND previous_plan.version<3;

-- A classificação é propositalmente fechada pelo código do
-- estabelecimento e pelos nomes exatos confirmados. Uma propriedade anterior
-- nunca é sobrescrita ou transferida silenciosamente.
INSERT INTO partner_nas_ownerships
  (nas_id,partner_id,management_mode,display_name,status,credentials_ciphertext,credential_hint,created_by_type,created_by_id)
SELECT equipment.id,partner.id,'firespot_dedicated',equipment.shortname,'ready',NULL,NULL,'system',NULL
FROM partners partner
JOIN nas equipment ON equipment.shortname IN ('Demo-NAS-A','Demo-NAS-B')
WHERE partner.code='00000001'
  AND EXISTS (
    SELECT 1 FROM partner_hotspots point
    WHERE point.partner_id=partner.id AND point.nas_id=equipment.id
  )
  AND EXISTS (
    SELECT 1 FROM partner_nas_assignments assignment
    WHERE assignment.partner_id=partner.id AND assignment.nas_id=equipment.id AND assignment.status='ready'
  )
  AND NOT EXISTS (
    SELECT 1 FROM partner_nas_ownerships ownership WHERE ownership.nas_id=equipment.id
  );

UPDATE partner_nas_assignments assignment
JOIN partners partner ON partner.id=assignment.partner_id AND partner.code='00000001'
JOIN nas equipment ON equipment.id=assignment.nas_id AND equipment.shortname IN ('Demo-NAS-A','Demo-NAS-B')
JOIN partner_nas_ownerships ownership ON ownership.nas_id=equipment.id AND ownership.partner_id=partner.id AND ownership.management_mode='partner_owned'
SET assignment.assignment_source='partner_owned',assignment.status='ready',assignment.retired_at=NULL,assignment.updated_at=NOW();

INSERT INTO partner_admin_audit
  (partner_id,actor_type,actor_id,action,target_type,target_id,metadata,origin_hash)
SELECT partner.id,'system',NULL,'nas.ownership_staged','nas',CAST(equipment.id AS CHAR),
       JSON_OBJECT('management_mode','firespot_dedicated','migration',53),NULL
FROM partners partner
JOIN nas equipment ON equipment.shortname IN ('Demo-NAS-A','Demo-NAS-B')
JOIN partner_nas_ownerships ownership ON ownership.nas_id=equipment.id AND ownership.partner_id=partner.id AND ownership.management_mode IN ('firespot_dedicated','partner_owned')
WHERE partner.code='00000001'
  AND NOT EXISTS (
    SELECT 1 FROM partner_admin_audit audit
    WHERE audit.partner_id=partner.id AND audit.action='nas.ownership_staged'
      AND audit.target_type='nas' AND audit.target_id=CAST(equipment.id AS CHAR)
      AND JSON_UNQUOTE(JSON_EXTRACT(audit.metadata,'$.migration'))='53'
  );
