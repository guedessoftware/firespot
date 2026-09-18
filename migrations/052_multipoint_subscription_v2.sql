-- Conclui a sucessão comercial iniciada na migração 051.
--
-- Somente assinaturas que já estavam vigentes no plano Multipontos v1 são
-- apontadas para a v2. A conta, o status, o início da vigência e o portal
-- permanecem intactos; o evento registra a troca de contrato. Nenhum modo de
-- portal, ponto, NAS ou equipamento de rede é alterado.

INSERT INTO partner_subscription_events
  (partner_id,subscription_id,event_type,from_plan_code,to_plan_code,from_status,to_status,reason,actor_type,actor_id)
SELECT s.partner_id,s.id,'plan_version_migrated','multipoint_advanced@1','multipoint_advanced@2',s.status,s.status,
       'Migração 052: sucessão segura do plano Multipontos v1 para v2, mantendo vigência e estado.','system',NULL
FROM partner_subscriptions s
JOIN platform_plans old_plan ON old_plan.id=s.plan_id
WHERE s.is_current=1
  AND old_plan.code='multipoint_advanced'
  AND old_plan.version=1
  AND NOT EXISTS (
    SELECT 1 FROM partner_subscription_events event
    WHERE event.subscription_id=s.id
      AND event.event_type='plan_version_migrated'
      AND event.to_plan_code='multipoint_advanced@2'
  );

UPDATE partner_subscriptions subscription
JOIN platform_plans old_plan ON old_plan.id=subscription.plan_id
JOIN platform_plans new_plan ON new_plan.code='multipoint_advanced' AND new_plan.version=2 AND new_plan.active=1
SET subscription.plan_id=new_plan.id,subscription.updated_at=NOW()
WHERE subscription.is_current=1
  AND old_plan.code='multipoint_advanced'
  AND old_plan.version=1;
