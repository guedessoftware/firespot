<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../app/db.php';
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

$legacyCurrent=(int)$pdo->query("SELECT COUNT(*) FROM partner_subscriptions s
    JOIN platform_plans p ON p.id=s.plan_id
    WHERE s.is_current=1 AND p.code='multipoint_advanced' AND p.version=1")->fetchColumn();
if($legacyCurrent!==0)throw new RuntimeException('Assinatura vigente ainda aponta para o plano Multipontos v1 aposentado.');

$invalidCurrent=(int)$pdo->query("SELECT COUNT(*) FROM partner_subscriptions s
    JOIN platform_plans p ON p.id=s.plan_id
    LEFT JOIN platform_plan_features f ON f.plan_id=p.id AND f.feature_code='portal.presentation.manage'
    WHERE s.is_current=1 AND p.code='multipoint_advanced'
      AND (p.version<2 OR p.active<>1 OR COALESCE(f.enabled,0)<>1)")->fetchColumn();
if($invalidCurrent!==0)throw new RuntimeException('Assinatura Multipontos vigente não recebeu a sucessão iniciada na v2.');

$invalidMigrationEvent=(int)$pdo->query("SELECT COUNT(*) FROM partner_subscription_events e
    JOIN partner_subscriptions s ON s.id=e.subscription_id
    JOIN platform_plans p ON p.id=s.plan_id
    WHERE e.event_type='plan_version_migrated' AND e.to_plan_code='multipoint_advanced@2'
      AND (p.code<>'multipoint_advanced' OR p.version<2)")->fetchColumn();
if($invalidMigrationEvent!==0)throw new RuntimeException('Evento da v2 aponta para assinatura fora de sua linha de sucessão.');

echo "Smoke 052 OK: sucessão das assinaturas Multipontos iniciada no contrato v2.\n";
