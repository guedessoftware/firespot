#!/usr/bin/env php
<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../db.php';
require_once __DIR__.'/../nas_credentials.php';

$pdo=db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

try{
    $statement=$pdo->prepare("SELECT partner.id partner_id,equipment.id nas_id,equipment.shortname,equipment.nasname,
            equipment.mgmt_username,equipment.mgmt_password,equipment.mgmt_port,equipment.secret,
            ownership.management_mode,ownership.status,ownership.credentials_ciphertext,ownership.host_key_fingerprint,
            assignment.assignment_source
        FROM partners partner
        JOIN nas equipment ON equipment.shortname IN ('Demo-NAS-A','Demo-NAS-B')
        JOIN partner_nas_ownerships ownership ON ownership.nas_id=equipment.id AND ownership.partner_id=partner.id
        JOIN partner_nas_assignments assignment ON assignment.partner_id=partner.id AND assignment.nas_id=equipment.id
        WHERE partner.code='00000001'
        ORDER BY equipment.id");
    $statement->execute();$rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    if(count($rows)!==2)throw new RuntimeException('A adoção exige exatamente Demo-NAS-A e Demo-NAS-B vinculados ao example_partner.');

    $prepared=[];$alreadyReady=0;
    foreach($rows as $row){
        if((string)$row['management_mode']==='partner_owned'){
            $credentials=fs_nas_credentials_decrypt((string)$row['credentials_ciphertext']);
            if((string)$row['assignment_source']!=='partner_owned')throw new RuntimeException('NAS próprio parcialmente adotado; restaure o backup antes de repetir.');
            unset($credentials);$alreadyReady++;continue;
        }
        if((string)$row['management_mode']!=='firespot_dedicated')throw new RuntimeException('O NAS possui modo de gestão incompatível com a adoção.');
        $host=trim((string)$row['nasname']);$username=trim((string)$row['mgmt_username']);$password=(string)$row['mgmt_password'];$radiusSecret=(string)$row['secret'];
        if(!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new RuntimeException('O NAS confirmado não possui IPv4 literal de gerenciamento.');
        $ciphertext=fs_nas_credentials_encrypt($username,$password,$radiusSecret);
        $prepared[(int)$row['nas_id']]=[
            'partner_id'=>(int)$row['partner_id'],'name'=>(string)$row['shortname'],'ciphertext'=>$ciphertext,
            'hint'=>fs_credential_hint($password),
            'legacy_digest'=>hash('sha256',$username."\0".$password."\0".$radiusSecret),
        ];
        unset($password,$radiusSecret);
        echo 'classified='.preg_replace('/[^A-Za-z0-9 _:\-]/','',(string)$row['shortname'])." credentials=protected verification=pending\n";
    }

    if(count($prepared)+$alreadyReady!==2)throw new RuntimeException('A adoção não reuniu os dois NAS esperados.');

    $pdo->beginTransaction();
    try{
        foreach($prepared as $nasId=>$item){
            $lock=$pdo->prepare("SELECT n.mgmt_username,n.mgmt_password,n.secret,o.management_mode
                FROM nas n JOIN partner_nas_ownerships o ON o.nas_id=n.id
                WHERE n.id=? AND o.partner_id=? FOR UPDATE");
            $lock->execute([$nasId,$item['partner_id']]);$current=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$current||(string)$current['management_mode']!=='firespot_dedicated')throw new RuntimeException('A propriedade do NAS mudou durante a validação.');
            $digest=hash('sha256',(string)$current['mgmt_username']."\0".(string)$current['mgmt_password']."\0".(string)$current['secret']);
            if(!hash_equals($item['legacy_digest'],$digest))throw new RuntimeException('As credenciais do NAS mudaram durante a validação.');
            $update=$pdo->prepare("UPDATE partner_nas_ownerships
                SET management_mode='partner_owned',status='pending',credentials_ciphertext=?,credential_hint=?,host_key_fingerprint=NULL,last_verified_at=NULL,updated_at=NOW()
                WHERE nas_id=? AND partner_id=? AND management_mode='firespot_dedicated'");
            $update->execute([$item['ciphertext'],$item['hint'],$nasId,$item['partner_id']]);
            if($update->rowCount()!==1)throw new RuntimeException('Não foi possível promover a propriedade do NAS.');
            $pdo->prepare("UPDATE partner_nas_assignments SET status='ready',assignment_source='partner_owned',retired_at=NULL,updated_at=NOW() WHERE partner_id=? AND nas_id=?")
                ->execute([$item['partner_id'],$nasId]);
            $pdo->prepare('UPDATE nas SET mgmt_password=NULL WHERE id=?')->execute([$nasId]);
            $audit=$pdo->prepare("INSERT INTO partner_admin_audit (partner_id,actor_type,actor_id,action,target_type,target_id,metadata,origin_hash)
                VALUES (?,'system',NULL,'nas.ownership_classified','nas',?,JSON_OBJECT('management_mode','partner_owned','migration',53,'remote_verification','pending'),NULL)");
            $audit->execute([$item['partner_id'],(string)$nasId]);
        }
        $plan=$pdo->query("SELECT id FROM platform_plans WHERE code='multipoint_advanced' AND version=3 AND active=1 LIMIT 1 FOR UPDATE")->fetchColumn();
        if(!$plan)throw new RuntimeException('Plano Multipontos v3 não está ativo para concluir a adoção.');
        $features=$pdo->prepare("UPDATE platform_plan_features SET enabled=1,updated_at=NOW() WHERE plan_id=? AND feature_code IN ('nas.manage','nas.prepare','nas.retire')");
        $features->execute([(int)$plan]);
        $featureCount=$pdo->prepare("SELECT COUNT(*) FROM platform_plan_features WHERE plan_id=? AND enabled=1 AND feature_code IN ('nas.manage','nas.prepare','nas.retire')");$featureCount->execute([(int)$plan]);
        if((int)$featureCount->fetchColumn()!==3)throw new RuntimeException('Catálogo de capacidades do plano divergiu.');
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}

    echo 'adoption='.($alreadyReady===2?'already_complete':'complete')." nas=2 legacy_passwords=cleared remote_changes=none verification=deferred\n";
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,'ADOPTION_FAILED: '.preg_replace('/[^A-Za-z0-9À-ÿ .,:;_\/-]+/u','',(string)$error->getMessage())."\n");
    exit(2);
}
