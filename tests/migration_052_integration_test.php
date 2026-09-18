<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=dirname(__DIR__);
require_once $root.'/app/db.php';
require_once $root.'/app/cli/migration_framework.php';

$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$digest=static function(PDO $pdo,string $sql):string{return hash('sha256',(string)json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC),JSON_UNESCAPED_SLASHES));};
$sql=(string)file_get_contents($root.'/migrations/052_multipoint_subscription_v2.sql');
$statements=fs_migration_sql_statements($sql);
$expect(count($statements)===2,'Migração 052 deve possuir somente auditoria e sucessão do plano.');

$pdo->beginTransaction();
try{
    $candidate=$pdo->query("SELECT s.id,s.plan_id FROM partner_subscriptions s JOIN platform_plans p ON p.id=s.plan_id
        WHERE s.is_current=1 AND p.code='multipoint_advanced' ORDER BY s.id LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    $expect((bool)$candidate,'Teste exige uma assinatura Multipontos vigente.');
    $subscriptionId=(int)$candidate['id'];$originalPlanId=(int)$candidate['plan_id'];
    $v1=(int)$pdo->query("SELECT id FROM platform_plans WHERE code='multipoint_advanced' AND version=1 LIMIT 1")->fetchColumn();
    $v2=(int)$pdo->query("SELECT id FROM platform_plans WHERE code='multipoint_advanced' AND version=2 LIMIT 1")->fetchColumn();
    $expect($v1>0&&$v2>0,'Versões comerciais v1/v2 ausentes.');
    // Depois de uma sucessão posterior, a v2 fica historicamente inativa.
    // O ensaio reabre a v2 somente dentro desta transação descartável para
    // continuar provando a semântica original da migração 052.
    $pdo->prepare('UPDATE platform_plans SET active=1 WHERE id=?')->execute([$v2]);
    $pdo->prepare('UPDATE partner_subscriptions SET plan_id=? WHERE id=?')->execute([$v1,$subscriptionId]);
    $pdo->prepare("DELETE FROM partner_subscription_events WHERE subscription_id=? AND event_type='plan_version_migrated' AND to_plan_code='multipoint_advanced@2'")->execute([$subscriptionId]);

    $portalDigest=$digest($pdo,'SELECT id,portal_mode FROM partners ORDER BY id');
    $hotspotDigest=$digest($pdo,'SELECT id,partner_id,nas_id,active FROM partner_hotspots ORDER BY id');
    $nasDigest=$digest($pdo,'SELECT partner_id,nas_id,status,assignment_source FROM partner_nas_assignments ORDER BY partner_id,nas_id');
    foreach($statements as $statement)$pdo->exec($statement);
    foreach($statements as $statement)$pdo->exec($statement);

    $currentPlan=(int)$pdo->query('SELECT plan_id FROM partner_subscriptions WHERE id='.$subscriptionId)->fetchColumn();
    $expect($currentPlan===$v2,'Assinatura vigente não migrou para o plano Multipontos v2.');
    $event=$pdo->prepare("SELECT COUNT(*) FROM partner_subscription_events WHERE subscription_id=? AND event_type='plan_version_migrated' AND to_plan_code='multipoint_advanced@2'");$event->execute([$subscriptionId]);
    $expect((int)$event->fetchColumn()===1,'Repetição da migração duplicou ou perdeu o evento de auditoria.');
    $expect(hash_equals($portalDigest,$digest($pdo,'SELECT id,portal_mode FROM partners ORDER BY id')),'Migração alterou o modo de algum portal.');
    $expect(hash_equals($hotspotDigest,$digest($pdo,'SELECT id,partner_id,nas_id,active FROM partner_hotspots ORDER BY id')),'Migração alterou pontos.');
    $expect(hash_equals($nasDigest,$digest($pdo,'SELECT partner_id,nas_id,status,assignment_source FROM partner_nas_assignments ORDER BY partner_id,nas_id')),'Migração alterou atribuições de NAS.');
    $pdo->rollBack();
    $restored=(int)$pdo->query('SELECT plan_id FROM partner_subscriptions WHERE id='.$subscriptionId)->fetchColumn();
    $expect($restored===$originalPlanId,'Rollback do ensaio não restaurou a assinatura original.');
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}

echo 'OK: '.$checks." verificações transacionais da sucessão Multipontos v2.\n";
