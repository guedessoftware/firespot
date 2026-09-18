-- Fecha somente gates administrativos que já não possuem configuração
-- funcional publicável. Não infere finalidade e não altera portais, NAS ou RADIUS.

INSERT INTO partner_admin_audit
  (partner_id,actor_type,actor_id,action,target_type,target_id,metadata,origin_hash,created_at)
SELECT
  p.id,'system',NULL,'migration.self_service_disabled','partner',CAST(p.id AS CHAR),
  JSON_OBJECT('reason','MISSING_PURPOSE_OR_PUBLISHED_CONFIGURATION','migration','044'),NULL,NOW()
FROM partners p
WHERE p.self_service_enabled=1
  AND (
    p.access_purpose IS NULL
    OR NOT EXISTS (
      SELECT 1 FROM partner_portal_configurations c
      WHERE c.partner_id=p.id AND c.state='published'
    )
  );

UPDATE partners p
SET p.self_service_enabled=0,p.updated_at=NOW()
WHERE p.self_service_enabled=1
  AND (
    p.access_purpose IS NULL
    OR NOT EXISTS (
      SELECT 1 FROM partner_portal_configurations c
      WHERE c.partner_id=p.id AND c.state='published'
    )
  );
