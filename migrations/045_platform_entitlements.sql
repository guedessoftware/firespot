-- Catálogo de produto, assinatura vigente, entitlements e cotas do portal.
-- A adoção preserva os acessos atuais em um plano interno legado; nenhuma
-- unidade recebe as novas funções avançadas sem atribuição explícita.

CREATE TABLE IF NOT EXISTS platform_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(500) NULL,
  internal_only TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  max_nas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_hotspots SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  max_admin_users SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  max_report_range_days SMALLINT UNSIGNED NOT NULL DEFAULT 31,
  custom_courtesy_overrides SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_platform_plan_version (code,version),
  KEY idx_platform_plans_active (active,internal_only,code,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_plan_features (
  plan_id BIGINT UNSIGNED NOT NULL,
  feature_code VARCHAR(96) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (plan_id,feature_code),
  KEY idx_platform_features_code (feature_code,enabled),
  CONSTRAINT fk_platform_plan_features_plan FOREIGN KEY (plan_id) REFERENCES platform_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  status ENUM('trial','active','grace','past_due','suspended','ended') NOT NULL DEFAULT 'active',
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  starts_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ends_at DATETIME NULL,
  grace_until DATETIME NULL,
  current_partner_id INT AS (CASE WHEN is_current=1 THEN partner_id ELSE NULL END) STORED,
  created_by_type ENUM('firespot','system') NOT NULL DEFAULT 'system',
  created_by_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_partner_subscription_current (current_partner_id),
  KEY idx_partner_subscriptions_history (partner_id,created_at),
  KEY idx_partner_subscriptions_plan (plan_id,status),
  CONSTRAINT fk_partner_subscriptions_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES platform_plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_feature_overrides (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT NOT NULL,
  feature_code VARCHAR(96) NOT NULL,
  enabled TINYINT(1) NOT NULL,
  limit_value INT UNSIGNED NULL,
  reason VARCHAR(300) NOT NULL,
  valid_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  valid_until DATETIME NULL,
  created_by_type ENUM('firespot','system') NOT NULL DEFAULT 'firespot',
  created_by_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_partner_feature_override_active (partner_id,feature_code,valid_from,valid_until),
  CONSTRAINT fk_partner_feature_overrides_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_subscription_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT NOT NULL,
  subscription_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  from_plan_code VARCHAR(64) NULL,
  to_plan_code VARCHAR(64) NULL,
  from_status VARCHAR(24) NULL,
  to_status VARCHAR(24) NULL,
  reason VARCHAR(300) NOT NULL,
  actor_type ENUM('firespot','system') NOT NULL DEFAULT 'system',
  actor_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_partner_subscription_events (partner_id,created_at),
  CONSTRAINT fk_partner_subscription_events_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_subscription_events_subscription FOREIGN KEY (subscription_id) REFERENCES partner_subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_plans
  (code,version,name,description,internal_only,active,max_nas,max_hotspots,max_admin_users,max_report_range_days,custom_courtesy_overrides)
VALUES
  ('essential',1,'Essencial','Portal básico do estabelecimento.',0,1,0,1,1,31,0),
  ('legacy_self_service',1,'Autogestão legada','Compatibilidade interna para unidades que já possuíam autogestão.',1,1,0,255,25,90,0),
  ('multipoint_advanced',1,'Multipontos e Gestão Avançada','NAS próprios, múltiplas instalações, cortesia e métricas avançadas.',0,1,10,25,10,366,25)
ON DUPLICATE KEY UPDATE
  name=VALUES(name),description=VALUES(description),internal_only=VALUES(internal_only),active=VALUES(active),
  max_nas=VALUES(max_nas),max_hotspots=VALUES(max_hotspots),max_admin_users=VALUES(max_admin_users),
  max_report_range_days=VALUES(max_report_range_days),custom_courtesy_overrides=VALUES(custom_courtesy_overrides);

INSERT INTO platform_plan_features (plan_id,feature_code,enabled)
SELECT p.id,f.feature_code,1
FROM platform_plans p
JOIN (
  SELECT 'essential' plan_code,'portal.basic' feature_code UNION ALL
  SELECT 'essential','branding.manage' UNION ALL
  SELECT 'essential','reports.basic' UNION ALL
  SELECT 'legacy_self_service','portal.basic' UNION ALL
  SELECT 'legacy_self_service','branding.manage' UNION ALL
  SELECT 'legacy_self_service','guest_plans.manage' UNION ALL
  SELECT 'legacy_self_service','reports.basic' UNION ALL
  SELECT 'legacy_self_service','finance.view' UNION ALL
  SELECT 'legacy_self_service','wallet.manage' UNION ALL
  SELECT 'legacy_self_service','team.manage' UNION ALL
  SELECT 'legacy_self_service','ads.manage' UNION ALL
  SELECT 'legacy_self_service','monetization.view' UNION ALL
  SELECT 'multipoint_advanced','portal.basic' UNION ALL
  SELECT 'multipoint_advanced','branding.manage' UNION ALL
  SELECT 'multipoint_advanced','guest_plans.manage' UNION ALL
  SELECT 'multipoint_advanced','reports.basic' UNION ALL
  SELECT 'multipoint_advanced','reports.advanced' UNION ALL
  SELECT 'multipoint_advanced','reports.export' UNION ALL
  SELECT 'multipoint_advanced','finance.view' UNION ALL
  SELECT 'multipoint_advanced','nas.view' UNION ALL
  SELECT 'multipoint_advanced','nas.manage' UNION ALL
  SELECT 'multipoint_advanced','nas.prepare' UNION ALL
  SELECT 'multipoint_advanced','nas.retire' UNION ALL
  SELECT 'multipoint_advanced','hotspots.view' UNION ALL
  SELECT 'multipoint_advanced','hotspots.draft.manage' UNION ALL
  SELECT 'multipoint_advanced','wallet.manage' UNION ALL
  SELECT 'multipoint_advanced','courtesy.view' UNION ALL
  SELECT 'multipoint_advanced','courtesy.manage' UNION ALL
  SELECT 'multipoint_advanced','courtesy.hotspot_override.manage' UNION ALL
  SELECT 'multipoint_advanced','team.manage'
) f ON f.plan_code=p.code AND p.version=1
ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_at=NOW();

-- A aplicação remota é um gate operacional por estabelecimento. A Central
-- habilita `hotspots.apply` por override temporário somente após o piloto.
INSERT INTO platform_plan_features (plan_id,feature_code,enabled)
SELECT id,'hotspots.apply',0 FROM platform_plans WHERE code='multipoint_advanced' AND version=1
ON DUPLICATE KEY UPDATE enabled=0,updated_at=NOW();

INSERT INTO app_settings (skey,svalue)
VALUES ('partner_hotspot_apply_pilot_approved','0')
ON DUPLICATE KEY UPDATE skey=VALUES(skey);

INSERT INTO partner_subscriptions
  (partner_id,plan_id,status,is_current,starts_at,created_by_type,created_by_id)
SELECT p.id,pp.id,CASE WHEN p.active=1 THEN 'active' ELSE 'ended' END,1,NOW(),'system',NULL
FROM partners p
JOIN platform_plans pp
  ON pp.code=CASE WHEN p.self_service_enabled=1 THEN 'legacy_self_service' ELSE 'essential' END
 AND pp.version=1
WHERE NOT EXISTS (SELECT 1 FROM partner_subscriptions s WHERE s.partner_id=p.id AND s.is_current=1);

INSERT INTO partner_subscription_events
  (partner_id,subscription_id,event_type,from_plan_code,to_plan_code,from_status,to_status,reason,actor_type,actor_id)
SELECT s.partner_id,s.id,'baseline_adopted',NULL,p.code,NULL,s.status,'Migração 045: preservação explícita do acesso existente.','system',NULL
FROM partner_subscriptions s
JOIN platform_plans p ON p.id=s.plan_id
WHERE s.is_current=1
  AND NOT EXISTS (
    SELECT 1 FROM partner_subscription_events e
    WHERE e.subscription_id=s.id AND e.event_type='baseline_adopted'
  );
