#!/usr/bin/env php
<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../db.php';
require_once __DIR__.'/../nas_credentials.php';
require_once __DIR__.'/migration_framework.php';

$options=getopt('',['preflight','database-name','pending','backup-tables','portal-mode-digest','hotspot-digest','nas-core-digest','target-active-queue','assert-final']);
$actions=array_keys($options);
if(count($actions)!==1){fwrite(STDERR,"Informe exatamente uma ação de verificação.\n");exit(64);}
$action=$actions[0];
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

$digest=static function(PDO $pdo,string $sql):string{
    return hash('sha256',(string)json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[],JSON_UNESCAPED_SLASHES));
};
$partnerId=static function(PDO $pdo):int{
    $ids=$pdo->query("SELECT id FROM partners WHERE code='00000001' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)?:[];
    if(count($ids)!==1)throw new RuntimeException('A implantação exige um único estabelecimento com código 00000001.');
    return (int)$ids[0];
};

try{
    if($action==='database-name'){
        $name=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if(!preg_match('/^[A-Za-z0-9_]+$/',$name))throw new RuntimeException('Nome de banco inválido.');
        echo $name."\n";exit;
    }
    if($action==='pending'){
        $inventory=fs_migration_inventory(dirname(__DIR__,2).'/migrations');$ledger=fs_migration_ledger($pdo);
        echo implode(',',array_diff(array_keys($inventory),array_keys($ledger)))."\n";exit;
    }
    if($action==='backup-tables'){
        $tables=['partners','partner_hotspots','nas','nas_interfaces','nas_base_provisioning','nas_health','partner_nas_ownerships','partner_nas_assignments','hotspot_change_requests','partner_network_reservations','platform_plans','platform_plan_features','partner_subscriptions','partner_subscription_events','partner_admin_audit','schema_migrations'];
        $exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        foreach($tables as $table){$exists->execute([$table]);if((int)$exists->fetchColumn()===1)echo $table."\n";}
        exit;
    }
    if($action==='portal-mode-digest'){echo $digest($pdo,'SELECT id,portal_mode FROM partners ORDER BY id')."\n";exit;}
    if($action==='hotspot-digest'){echo $digest($pdo,'SELECT * FROM partner_hotspots ORDER BY id')."\n";exit;}
    if($action==='nas-core-digest'){echo $digest($pdo,'SELECT id,nasname,shortname,type,ports,secret,server,community,mgmt_username,mgmt_port,description FROM nas ORDER BY id')."\n";exit;}
    if($action==='target-active-queue'){
        $id=$partnerId($pdo);$statement=$pdo->prepare("SELECT COUNT(*) FROM hotspot_change_requests request JOIN nas equipment ON equipment.id=request.nas_id WHERE request.partner_id=? AND equipment.shortname IN ('Demo-NAS-A','Demo-NAS-B') AND request.status IN ('queued','running','retry')");
        $statement->execute([$id]);echo (int)$statement->fetchColumn()."\n";exit;
    }

    $inventory=fs_migration_inventory(dirname(__DIR__,2).'/migrations');$ledger=fs_migration_ledger($pdo);
    if(array_keys($inventory)!==range(1,53))throw new RuntimeException('Inventário inesperado; esperadas exatamente as migrações 001-053.');
    $errors=fs_migration_validate_ledger($inventory,$ledger);if($errors)throw new RuntimeException('Ledger inválido: '.implode(',',$errors));
    $registered=array_keys($ledger);$expectedPrefix=range(1,count($registered));
    if($registered!==$expectedPrefix||!in_array(count($registered),[52,53],true))throw new RuntimeException('Ledger não está no prefixo seguro 001-052 ou 001-053.');
    $id=$partnerId($pdo);
    $targets=$pdo->prepare("SELECT n.id,n.shortname FROM nas n WHERE n.shortname IN ('Demo-NAS-A','Demo-NAS-B') AND EXISTS (SELECT 1 FROM partner_hotspots h WHERE h.partner_id=? AND h.nas_id=n.id) ORDER BY n.shortname");
    $targets->execute([$id]);$names=array_column($targets->fetchAll(PDO::FETCH_ASSOC)?:[],'shortname');sort($names);
    if($names!==['Demo-NAS-A','Demo-NAS-B'])throw new RuntimeException('Os dois NAS confirmados não estão vinculados ao example_partner.');
    $bindings=$pdo->prepare("SELECT h.name,n.shortname FROM partner_hotspots h JOIN nas n ON n.id=h.nas_id WHERE h.partner_id=? AND h.name IN ('Principal','flutuante','TESTE')");
    $bindings->execute([$id]);$map=[];foreach($bindings->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$map[(string)$row['name']]=(string)$row['shortname'];
    if(($map['Principal']??'')!=='Demo-NAS-A'||($map['flutuante']??'')!=='Demo-NAS-B'||($map['TESTE']??'')!=='FireSpot')throw new RuntimeException('O vínculo confirmado entre pontos e NAS divergiu.');
    $foreign=$pdo->prepare("SELECT COUNT(*) FROM partner_nas_ownerships o JOIN nas n ON n.id=o.nas_id WHERE n.shortname IN ('Demo-NAS-A','Demo-NAS-B') AND o.partner_id<>?");$foreign->execute([$id]);
    if((int)$foreign->fetchColumn()!==0)throw new RuntimeException('Um NAS confirmado já pertence a outro estabelecimento.');
    if(strlen(trim((string)getenv('NAS_CREDENTIAL_KEY')))<32){
        // A leitura canônica do arquivo de ambiente ocorre dentro da rotina.
        fs_nas_credentials_encrypt('preflight','preflight-password','preflight-radius-secret');
    }else fs_nas_credentials_encrypt('preflight','preflight-password','preflight-radius-secret');
    if(!function_exists('ssh2_connect')||!function_exists('ssh2_fingerprint'))throw new RuntimeException('Extensão SSH2 indisponível.');

    if($action==='preflight'){
        echo 'preflight=ok partner=example_partner targets=2 migration_state='.(count($registered)===52?'pending':'applied')."\n";exit;
    }
    if($action==='assert-final'){
        if(count($registered)!==53)throw new RuntimeException('Migração 053 ausente do ledger.');
        $plan=$pdo->query("SELECT id,max_nas,max_hotspots FROM platform_plans WHERE code='multipoint_advanced' AND version=3 AND active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if(!$plan||(int)$plan['max_nas']!==10||(int)$plan['max_hotspots']!==25)throw new RuntimeException('Contrato Multipontos v3 inválido.');
        $features=$pdo->prepare("SELECT feature_code,enabled FROM platform_plan_features WHERE plan_id=? AND feature_code IN ('nas.manage','nas.prepare','nas.retire','hotspots.apply')");$features->execute([(int)$plan['id']]);$map=[];foreach($features->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$map[$row['feature_code']]=(int)$row['enabled'];
        if(($map['nas.manage']??0)!==1||($map['nas.prepare']??0)!==1||($map['nas.retire']??0)!==1||($map['hotspots.apply']??1)!==0)throw new RuntimeException('Fronteira de capacidades do plano v3 inválida.');
        $final=$pdo->prepare("SELECT n.shortname,o.status,o.credentials_ciphertext,o.host_key_fingerprint,n.mgmt_password,a.assignment_source
            FROM partner_nas_ownerships o JOIN nas n ON n.id=o.nas_id JOIN partner_nas_assignments a ON a.partner_id=o.partner_id AND a.nas_id=o.nas_id
            WHERE o.partner_id=? AND o.management_mode='partner_owned' AND o.status<>'retired' AND n.shortname IN ('Demo-NAS-A','Demo-NAS-B') ORDER BY n.shortname");
        $final->execute([$id]);$rows=$final->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(count($rows)!==2)throw new RuntimeException('Os dois NAS do example_partner não foram promovidos juntos.');
        $verified=0;
        foreach($rows as $row){
            fs_nas_credentials_decrypt((string)$row['credentials_ciphertext']);
            if($row['mgmt_password']!==null||(string)$row['assignment_source']!=='partner_owned')throw new RuntimeException('NAS próprio sem credencial protegida ou atribuição coerente.');
            $hasPinnedKey=trim((string)$row['host_key_fingerprint'])!=='';
            if(in_array((string)$row['status'],['verified','ready'],true)&&!$hasPinnedKey)throw new RuntimeException('NAS marcado como verificado sem chave SSH fixada.');
            if($hasPinnedKey)$verified++;
        }
        $central=$pdo->prepare("SELECT COUNT(*) FROM partner_nas_ownerships o JOIN nas n ON n.id=o.nas_id WHERE o.partner_id=? AND n.shortname='FireSpot' AND o.management_mode='partner_owned'");$central->execute([$id]);
        if((int)$central->fetchColumn()!==0)throw new RuntimeException('NAS FireSpot foi transferido indevidamente.');
        echo "final=ok plan=multipoint_advanced@3 partner_owned_nas=2 ssh_verified={$verified} firespot_nas=central hotspot_apply=blocked\n";exit;
    }
    throw new RuntimeException('Ação desconhecida.');
}catch(Throwable $error){
    fwrite(STDERR,'CHECK_FAILED: '.preg_replace('/[^A-Za-z0-9À-ÿ .,:;_\/-]+/u','',(string)$error->getMessage())."\n");
    exit(2);
}
