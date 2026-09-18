#!/usr/bin/env php
<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../portal_skin.php';
require_once __DIR__.'/migration_framework.php';

$options=getopt('',['database-name','pending','portal-mode-digest','hotspot-digest','nas-assignment-digest','backup-tables','assert-not-activated','assert-multipoint-v2']);
$actions=array_keys($options);
if(count($actions)!==1){fwrite(STDERR,"Informe exatamente uma ação de verificação.\n");exit(64);}
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$action=$actions[0];

if($action==='database-name'){
    $name=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if(!preg_match('/^[A-Za-z0-9_]+$/',$name))throw new RuntimeException('Nome do banco inválido.');
    echo $name."\n";exit;
}
if($action==='pending'){
    $inventory=fs_migration_inventory(dirname(__DIR__,2).'/migrations');$ledger=fs_migration_ledger($pdo);
    echo implode(',',array_diff(array_keys($inventory),array_keys($ledger)))."\n";exit;
}
if($action==='portal-mode-digest'){
    $rows=$pdo->query('SELECT id,portal_mode FROM partners ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)?:[];
    echo hash('sha256',(string)json_encode($rows,JSON_UNESCAPED_SLASHES))."\n";exit;
}
if($action==='hotspot-digest'){
    $rows=$pdo->query('SELECT * FROM partner_hotspots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)?:[];
    echo hash('sha256',(string)json_encode($rows,JSON_UNESCAPED_SLASHES))."\n";exit;
}
if($action==='nas-assignment-digest'){
    $rows=$pdo->query('SELECT * FROM partner_nas_assignments ORDER BY partner_id,nas_id')->fetchAll(PDO::FETCH_ASSOC)?:[];
    echo hash('sha256',(string)json_encode($rows,JSON_UNESCAPED_SLASHES))."\n";exit;
}
if($action==='backup-tables'){
    $candidates=['partners','partner_hotspots','partner_nas_ownerships','partner_nas_assignments','partner_portal_configurations','partner_portal_themes','portal_skin_catalog','partner_portal_presentations','partner_portal_migrations','platform_plans','platform_plan_features','partner_subscriptions','partner_subscription_events','partner_admin_audit','schema_migrations'];
    $exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach($candidates as $table){$exists->execute([$table]);if((int)$exists->fetchColumn()===1)echo $table."\n";}
    exit;
}
if($action==='assert-not-activated'){
    if(!fs_portal_skin_schema_ready($pdo))throw new RuntimeException('Migração visual ausente.');
    $presentations=(int)$pdo->query('SELECT COUNT(*) FROM partner_portal_presentations')->fetchColumn();
    $unexpected=(int)$pdo->query("SELECT COUNT(*) FROM partner_portal_migrations WHERE status IN ('draft','ready','rollback_available') OR draft_presentation_id IS NOT NULL OR candidate_presentation_id IS NOT NULL OR published_presentation_id IS NOT NULL")->fetchColumn();
    $invalidLegacy=(int)$pdo->query("SELECT COUNT(*) FROM partner_portal_migrations WHERE status='v3_active' AND source_portal_mode<>'v3'")->fetchColumn();
    if($presentations!==0||$unexpected!==0||$invalidLegacy!==0)throw new RuntimeException('A instalação criou ou ativou apresentação indevida.');
    echo "portal_presentations=0 migration_candidates=0 activation=none\n";exit;
}
if($action==='assert-multipoint-v2'){
    $legacyCurrent=(int)$pdo->query("SELECT COUNT(*) FROM partner_subscriptions s JOIN platform_plans p ON p.id=s.plan_id WHERE s.is_current=1 AND p.code='multipoint_advanced' AND p.version=1")->fetchColumn();
    $invalidCurrent=(int)$pdo->query("SELECT COUNT(*) FROM partner_subscriptions s JOIN platform_plans p ON p.id=s.plan_id LEFT JOIN platform_plan_features f ON f.plan_id=p.id AND f.feature_code='portal.presentation.manage' WHERE s.is_current=1 AND p.code='multipoint_advanced' AND (p.version<>2 OR p.active<>1 OR COALESCE(f.enabled,0)<>1)")->fetchColumn();
    $currentV2=(int)$pdo->query("SELECT COUNT(*) FROM partner_subscriptions s JOIN platform_plans p ON p.id=s.plan_id WHERE s.is_current=1 AND p.code='multipoint_advanced' AND p.version=2 AND p.active=1")->fetchColumn();
    if($legacyCurrent!==0||$invalidCurrent!==0)throw new RuntimeException('A sucessão comercial Multipontos v2 não foi concluída.');
    echo "multipoint_current_v2={$currentV2} legacy_current=0 invalid_current=0\n";exit;
}
