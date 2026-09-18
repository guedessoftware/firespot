#!/usr/bin/env php
<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../db.php';
require_once __DIR__.'/../partner_infrastructure.php';
require_once __DIR__.'/migration_framework.php';

$options=getopt('',['preflight','database-name','pending','backup-tables','hotspot-digest','nas-base-digest','queue-digest','assert-final']);
$actions=array_keys($options);if(count($actions)!==1){fwrite(STDERR,"Informe exatamente uma ação de verificação.\n");exit(64);}
$action=$actions[0];$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

$digestQuery=static function(PDO $pdo,string $sql):string{return hash('sha256',(string)json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));};
// Compara somente identidade, vínculo e configuração efetiva. A migração 055
// preenche network_prefix_length e, por causa do ON UPDATE do MariaDB, pode
// tocar updated_at sem que qualquer configuração anterior tenha mudado.
$hotspotDigest=static fn(PDO $pdo):string=>$digestQuery($pdo,'SELECT id,partner_id,code,name,nas_id,nas_interface_id,vlan_id,gateway_ip,pool_start,pool_end,dns_servers,dns_name,radius_ip,is_default,active,desired_config_version,applied_config_version,management_state,last_change_request_id FROM partner_hotspots ORDER BY id');
$baseDigest=static fn(PDO $pdo):string=>$digestQuery($pdo,'SELECT n.id,n.nasname,n.shortname,n.type,n.secret,n.mgmt_username,n.mgmt_password,n.mgmt_port,b.radius_server_id,b.status,b.config_revision,b.routeros_version,b.coa_status,b.coa_port,b.provisioned_at,r.name radius_name,r.host radius_host,r.port radius_port FROM nas n LEFT JOIN nas_base_provisioning b ON b.nas_id=n.id LEFT JOIN radius_servers r ON r.id=b.radius_server_id ORDER BY n.id');
$queueDigest=static fn(PDO $pdo):string=>$digestQuery($pdo,'SELECT id,partner_id,nas_id,hotspot_id,operation,status,attempt_count,max_attempts,idempotency_key,created_at,started_at,finished_at FROM hotspot_change_requests ORDER BY id');

try{
    if($action==='database-name'){$name=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();if(!preg_match('/^[A-Za-z0-9_]+$/',$name))throw new RuntimeException('Nome de banco inválido.');echo $name."\n";exit;}
    if($action==='backup-tables'){
        $tables=['nas_hotspot_allocation_policies','partner_hotspots','partner_hotspot_configuration_requests','partner_network_reservations','partner_nas_ownerships','partner_nas_assignments','nas','nas_base_provisioning','radius_servers','hotspot_change_requests','partner_admin_audit','schema_migrations'];
        $exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');foreach($tables as $table){$exists->execute([$table]);if((int)$exists->fetchColumn()===1)echo $table."\n";}exit;
    }
    if($action==='hotspot-digest'){echo $hotspotDigest($pdo)."\n";exit;}
    if($action==='nas-base-digest'){echo $baseDigest($pdo)."\n";exit;}
    if($action==='queue-digest'){echo $queueDigest($pdo)."\n";exit;}

    $inventory=fs_migration_inventory(dirname(__DIR__,2).'/migrations');$ledger=fs_migration_ledger($pdo);
    if(array_keys($inventory)!==range(1,55))throw new RuntimeException('Inventário inesperado; esperadas exatamente as migrações 001-055.');
    $errors=fs_migration_validate_ledger($inventory,$ledger);if($errors)throw new RuntimeException('Ledger inválido: '.implode(',',$errors));
    $registered=array_keys($ledger);if($registered!==range(1,count($registered))||!in_array(count($registered),[54,55],true))throw new RuntimeException('Ledger não está no prefixo seguro 001-054 ou 001-055.');
    if($action==='pending'){echo isset($ledger[55])?'':"55\n";exit;}

    $partnerId=(int)$pdo->query("SELECT id FROM partners WHERE code='00000001' LIMIT 1")->fetchColumn();if($partnerId<=0)throw new RuntimeException('Estabelecimento example_partner não encontrado.');
    $bindings=$pdo->prepare("SELECT h.name,n.shortname,h.gateway_ip FROM partner_hotspots h JOIN nas n ON n.id=h.nas_id WHERE h.partner_id=? AND h.name IN ('Principal','flutuante','TESTE')");$bindings->execute([$partnerId]);$map=[];foreach($bindings->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$map[(string)$row['name']]=$row;
    if(($map['Principal']['shortname']??'')!=='Demo-NAS-A'||($map['Principal']['gateway_ip']??'')!=='10.5.5.1'||($map['flutuante']['shortname']??'')!=='Demo-NAS-B'||($map['flutuante']['gateway_ip']??'')!=='10.101.0.1'||($map['TESTE']['shortname']??'')!=='FireSpot'||($map['TESTE']['gateway_ip']??'')!=='10.115.0.1')throw new RuntimeException('O vínculo ou gateway efetivo dos três pontos divergiu.');
    $activeQueue=(int)$pdo->query("SELECT COUNT(*) FROM hotspot_change_requests WHERE status IN ('queued','running','retry')")->fetchColumn();
    if($action==='preflight'){echo 'preflight=ok migration_state='.(isset($ledger[55])?'applied':'pending').' active_points=preserved radius_base=preserved active_queue='.$activeQueue."\n";exit;}
    if($action==='assert-final'){
        if(!isset($ledger[55])||!fs_nas_hotspot_policy_schema_ready($pdo)||!fs_partner_hotspot_network_prefix_schema_ready($pdo)||!fs_partner_hotspot_configuration_dns_schema_ready($pdo))throw new RuntimeException('Migração 055 ausente ou ilegível.');
        $result=fs_migration_run_smoke((string)$inventory[55]['smoke_path']);if(!$result['ok'])throw new RuntimeException('Smoke 055 falhou: '.$result['code']);
        echo 'final=ok schema=055 nas_policy=ready point_prefix=explicit point_dns=versioned existing_points=preserved routeros=untouched active_queue='.$activeQueue."\n";exit;
    }
    throw new RuntimeException('Ação desconhecida.');
}catch(Throwable $error){fwrite(STDERR,'CHECK_FAILED: '.preg_replace('/[^A-Za-z0-9À-ÿ .,:;_\/-]+/u','',(string)$error->getMessage())."\n");exit(2);}
