#!/usr/bin/env php
<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../db.php';
require_once __DIR__.'/../partner_hotspots.php';
require_once __DIR__.'/../partner_hotspot_commercial.php';
require_once __DIR__.'/../portal_configuration.php';
require_once __DIR__.'/migration_framework.php';

$options=getopt('',['preflight','database-name','pending','pending-latest','backup-tables','hotspot-digest','nas-base-digest','queue-digest','behavior-digest','assert-final']);
$actions=array_keys($options);if(count($actions)!==1){fwrite(STDERR,"Informe exatamente uma ação de verificação.\n");exit(64);}
$action=$actions[0];$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

$digestQuery=static function(PDO $pdo,string $sql):string{return hash('sha256',(string)json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));};
$hotspotDigest=static fn(PDO $pdo):string=>$digestQuery($pdo,'SELECT id,partner_id,code,name,nas_id,nas_interface_id,vlan_id,network_prefix_length,gateway_ip,pool_start,pool_end,dns_servers,dns_name,radius_ip,is_default,active,desired_config_version,applied_config_version,management_state,last_change_request_id FROM partner_hotspots ORDER BY id');
$baseDigest=static fn(PDO $pdo):string=>$digestQuery($pdo,'SELECT n.id,n.nasname,n.shortname,n.type,n.secret,n.mgmt_username,n.mgmt_password,n.mgmt_port,b.radius_server_id,b.status,b.config_revision,b.routeros_version,b.coa_status,b.coa_port,b.provisioned_at,r.name radius_name,r.host radius_host,r.port radius_port FROM nas n LEFT JOIN nas_base_provisioning b ON b.nas_id=n.id LEFT JOIN radius_servers r ON r.id=b.radius_server_id ORDER BY n.id');
$queueDigest=static fn(PDO $pdo):string=>$digestQuery($pdo,'SELECT id,partner_id,nas_id,hotspot_id,operation,status,attempt_count,max_attempts,idempotency_key,created_at,started_at,finished_at FROM hotspot_change_requests ORDER BY id');
$behaviorDigest=static function(PDO $pdo):string{
    $sql="SELECT point.id hotspot_id,point.partner_id,
        COALESCE(config.paid_access_enabled,IF(partner.access_purpose IN ('paid','hybrid'),1,0)) paid_access_enabled,
        COALESCE(config.courtesy_mode,CASE partner.access_purpose WHEN 'free' THEN 'direct' WHEN 'sponsored' THEN 'sponsored' WHEN 'hybrid' THEN 'direct' ELSE 'disabled' END) courtesy_mode,
        COALESCE(config.paid_access_enabled,IF(partner.access_purpose IN ('paid','hybrid'),1,0)) payment_window_enabled,
        LEAST(5,GREATEST(1,COALESCE(partner.payment_window_minutes,2))) payment_window_minutes,
        LEAST(12,GREATEST(1,COALESCE(partner.payment_window_daily_limit,3))) payment_window_daily_limit,
        LEAST(60,GREATEST(5,COALESCE(partner.payment_window_cooldown_minutes,10))) payment_window_cooldown_minutes,
        LEAST(10080,GREATEST(60,COALESCE(partner.payment_window_period_minutes,1440))) payment_window_period_minutes
      FROM partner_hotspots point JOIN partners partner ON partner.id=point.partner_id
      LEFT JOIN partner_portal_configurations config ON config.partner_id=point.partner_id AND config.state='published'
      ORDER BY point.id";
    $fallback=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];
    if(fs_partner_hotspot_commercial_schema_ready($pdo)){
        $stored=$pdo->query('SELECT hotspot_id,partner_id,paid_access_enabled,courtesy_mode,payment_window_enabled,payment_window_minutes,payment_window_daily_limit,payment_window_cooldown_minutes,payment_window_period_minutes FROM partner_hotspot_commercial_policies ORDER BY hotspot_id')->fetchAll(PDO::FETCH_ASSOC)?:[];
        $byHotspot=[];foreach($stored as $row)$byHotspot[(int)$row['hotspot_id']]=$row;
        foreach($fallback as &$row)if(isset($byHotspot[(int)$row['hotspot_id']]))$row=$byHotspot[(int)$row['hotspot_id']];unset($row);
    }
    // PDO pode devolver expressões do SELECT como strings e colunas TINYINT
    // como inteiros. O digest mede semântica, portanto normaliza tipos antes
    // de comparar o estado anterior com o backfill materializado.
    $numeric=['hotspot_id','partner_id','paid_access_enabled','payment_window_enabled','payment_window_minutes','payment_window_daily_limit','payment_window_cooldown_minutes','payment_window_period_minutes'];
    foreach($fallback as &$row){foreach($numeric as $field)$row[$field]=(int)$row[$field];$row['courtesy_mode']=(string)$row['courtesy_mode'];}unset($row);
    return hash('sha256',(string)json_encode($fallback,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
};

try{
    if($action==='database-name'){$name=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();if(!preg_match('/^[A-Za-z0-9_]+$/',$name))throw new RuntimeException('Nome de banco inválido.');echo $name."\n";exit;}
    if($action==='backup-tables'){
        $tables=['partner_hotspot_commercial_policies','partner_hotspots','courtesy_hotspot_policy_overrides','courtesy_policies','courtesy_policy_revisions','partner_portal_configurations','partners','guest_orders','guest_payment_windows','nas','nas_base_provisioning','radius_servers','hotspot_change_requests','partner_admin_audit','schema_migrations'];
        $exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');foreach($tables as $table){$exists->execute([$table]);if((int)$exists->fetchColumn()===1)echo $table."\n";}exit;
    }
    if($action==='hotspot-digest'){echo $hotspotDigest($pdo)."\n";exit;}
    if($action==='nas-base-digest'){echo $baseDigest($pdo)."\n";exit;}
    if($action==='queue-digest'){echo $queueDigest($pdo)."\n";exit;}
    if($action==='behavior-digest'){echo $behaviorDigest($pdo)."\n";exit;}

    $inventory=fs_migration_inventory(dirname(__DIR__,2).'/migrations');$ledger=fs_migration_ledger($pdo);
    $inventoryVersions=array_keys($inventory);$inventoryLast=(int)max($inventoryVersions);
    if($inventoryVersions!==range(1,$inventoryLast)||!isset($inventory[56]))throw new RuntimeException('Inventário inesperado; a sequência precisa conter a migração 056.');
    $errors=fs_migration_validate_ledger($inventory,$ledger);if($errors)throw new RuntimeException('Ledger inválido: '.implode(',',$errors));
    $registered=array_keys($ledger);if($registered!==range(1,count($registered))||count($registered)<55||count($registered)>$inventoryLast)throw new RuntimeException('Ledger não está em um prefixo seguro que contenha a preparação comercial.');
    // Compatibilidade: --pending continua pertencendo ao finalizador da
    // política comercial 056. Finalizadores posteriores usam
    // --pending-latest para não confundir uma versão antiga já aplicada com
    // a última migração inventariada.
    if($action==='pending'){echo isset($ledger[56])?'':"56\n";exit;}
    if($action==='pending-latest'){
        $pending=array_values(array_diff($inventoryVersions,$registered));
        if(!$pending)exit;
        if(count($pending)!==1||(int)$pending[0]!==$inventoryLast)throw new RuntimeException('As pendências não correspondem somente à última migração inventariada.');
        echo $inventoryLast."\n";exit;
    }

    $partnerId=(int)$pdo->query("SELECT id FROM partners WHERE code='00000001' LIMIT 1")->fetchColumn();if($partnerId<=0)throw new RuntimeException('Estabelecimento example_partner não encontrado.');
    $owned=$pdo->prepare("SELECT COUNT(*) FROM partner_hotspots point JOIN partner_nas_ownerships ownership ON ownership.nas_id=point.nas_id AND ownership.partner_id=point.partner_id AND ownership.management_mode='partner_owned' AND ownership.status<>'retired' WHERE point.partner_id=? AND point.name IN ('Principal','flutuante')");$owned->execute([$partnerId]);
    if((int)$owned->fetchColumn()!==2)throw new RuntimeException('Os pontos Principal e flutuante não permanecem em NAS do estabelecimento.');
    $activeQueue=(int)$pdo->query("SELECT COUNT(*) FROM hotspot_change_requests WHERE status IN ('queued','running','retry')")->fetchColumn();
    if($action==='preflight'){echo 'preflight=ok migration_state='.(isset($ledger[56])?'applied':'pending').' point_behavior=preserved routeros=untouched active_queue='.$activeQueue."\n";exit;}
    if($action==='assert-final'){
        if(!isset($ledger[56])||!fs_partner_hotspot_commercial_schema_ready($pdo))throw new RuntimeException('Migração 056 ausente ou ilegível.');
        $result=fs_migration_run_smoke((string)$inventory[56]['smoke_path']);if(!$result['ok'])throw new RuntimeException('Smoke 056 falhou: '.$result['code']);
        echo 'final=ok schema=056 commercial_policy=per_hotspot existing_behavior=preserved routeros=untouched active_queue='.$activeQueue."\n";exit;
    }
    throw new RuntimeException('Ação desconhecida.');
}catch(Throwable $error){fwrite(STDERR,'CHECK_FAILED: '.preg_replace('/[^A-Za-z0-9À-ÿ .,:;_\/-]+/u','',(string)$error->getMessage())."\n");exit(2);}
