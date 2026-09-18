#!/usr/bin/env php
<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../db.php';
require_once __DIR__.'/../partner_infrastructure.php';
require_once __DIR__.'/migration_framework.php';

$options=getopt('',['preflight','database-name','pending','backup-tables','hotspot-digest','assert-final']);
$actions=array_keys($options);
if(count($actions)!==1){fwrite(STDERR,"Informe exatamente uma ação de verificação.\n");exit(64);}
$action=$actions[0];$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

$partnerId=static function(PDO $pdo):int{
    $ids=$pdo->query("SELECT id FROM partners WHERE code='00000001' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)?:[];
    if(count($ids)!==1)throw new RuntimeException('A implantação exige um único estabelecimento example_partner.');
    return (int)$ids[0];
};
$digest=static fn(PDO $pdo):string=>hash('sha256',(string)json_encode($pdo->query('SELECT * FROM partner_hotspots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)?:[],JSON_UNESCAPED_SLASHES));

try{
    if($action==='database-name'){
        $name=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();if(!preg_match('/^[A-Za-z0-9_]+$/',$name))throw new RuntimeException('Nome de banco inválido.');echo $name."\n";exit;
    }
    if($action==='backup-tables'){
        $tables=['partner_hotspots','partner_hotspot_configuration_requests','partner_nas_ownerships','partner_nas_assignments','nas','nas_interfaces','partner_admin_audit','schema_migrations'];
        $exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        foreach($tables as $table){$exists->execute([$table]);if((int)$exists->fetchColumn()===1)echo $table."\n";}exit;
    }
    if($action==='hotspot-digest'){echo $digest($pdo)."\n";exit;}

    $inventory=fs_migration_inventory(dirname(__DIR__,2).'/migrations');$ledger=fs_migration_ledger($pdo);
    if(array_keys($inventory)!==range(1,54))throw new RuntimeException('Inventário inesperado; esperadas exatamente as migrações 001-054.');
    $errors=fs_migration_validate_ledger($inventory,$ledger);if($errors)throw new RuntimeException('Ledger inválido: '.implode(',',$errors));
    $registered=array_keys($ledger);$expected=range(1,count($registered));
    if($registered!==$expected||!in_array(count($registered),[53,54],true))throw new RuntimeException('Ledger não está no prefixo seguro 001-053 ou 001-054.');
    if($action==='pending'){echo isset($ledger[54])?'':"54\n";exit;}

    $id=$partnerId($pdo);
    $bindings=$pdo->prepare("SELECT h.name,n.shortname FROM partner_hotspots h JOIN nas n ON n.id=h.nas_id WHERE h.partner_id=? AND h.name IN ('Principal','flutuante','TESTE')");$bindings->execute([$id]);$map=[];foreach($bindings->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$map[(string)$row['name']]=(string)$row['shortname'];
    if(($map['Principal']??'')!=='Demo-NAS-A'||($map['flutuante']??'')!=='Demo-NAS-B'||($map['TESTE']??'')!=='FireSpot')throw new RuntimeException('O vínculo efetivo entre pontos e NAS divergiu.');
    $owned=$pdo->prepare("SELECT COUNT(*) FROM partner_nas_ownerships o JOIN nas n ON n.id=o.nas_id WHERE o.partner_id=? AND o.management_mode='partner_owned' AND o.status<>'retired' AND n.shortname IN ('Demo-NAS-A','Demo-NAS-B')");$owned->execute([$id]);
    if((int)$owned->fetchColumn()!==2)throw new RuntimeException('Os dois NAS do example_partner não estão sob propriedade do estabelecimento.');
    $central=$pdo->prepare("SELECT COUNT(*) FROM partner_nas_ownerships o JOIN nas n ON n.id=o.nas_id WHERE o.partner_id=? AND o.management_mode='partner_owned' AND n.shortname='FireSpot'");$central->execute([$id]);
    if((int)$central->fetchColumn()!==0)throw new RuntimeException('O NAS FireSpot foi transferido indevidamente.');
    $applyGate=$pdo->prepare("SELECT COALESCE(MAX(f.enabled),0) FROM partner_subscriptions s JOIN platform_plan_features f ON f.plan_id=s.plan_id WHERE s.partner_id=? AND s.is_current=1 AND f.feature_code='hotspots.apply'");$applyGate->execute([$id]);
    if((int)$applyGate->fetchColumn()!==0)throw new RuntimeException('A aplicação remota não pode ser liberada pelo portal nesta implantação.');

    if($action==='preflight'){echo 'preflight=ok partner=example_partner migration_state='.(isset($ledger[54])?'applied':'pending').' effective_hotspots=preserved remote_apply=blocked'."\n";exit;}
    if($action==='assert-final'){
        if(!isset($ledger[54])||!fs_partner_hotspot_configuration_schema_ready($pdo))throw new RuntimeException('Migração 054 ausente ou ilegível.');
        $result=fs_migration_run_smoke((string)$inventory[54]['smoke_path']);if(!$result['ok'])throw new RuntimeException('Smoke 054 falhou: '.$result['code']);
        echo 'final=ok schema=054 point_requests=versioned effective_hotspots=preserved firespot_test=read_only remote_apply=blocked'."\n";exit;
    }
    throw new RuntimeException('Ação desconhecida.');
}catch(Throwable $error){fwrite(STDERR,'CHECK_FAILED: '.preg_replace('/[^A-Za-z0-9À-ÿ .,:;_\/-]+/u','',(string)$error->getMessage())."\n");exit(2);}
