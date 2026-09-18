<?php

declare(strict_types=1);

require_once __DIR__ . '/subscriber_devices.php';
require_once __DIR__ . '/radius_db.php';

function fs_subscriber_radius_username(array $grant): string
{
    $public=strtolower((string)($grant['public_id']??''));if(!preg_match('/^[a-f0-9]{32}$/',$public))throw new InvalidArgumentException('Concessão FIRENETWORK inválida.');return 'fsn_'.substr($public,0,24);
}

function fs_subscriber_radius_expiration(int $timestamp): string
{
    $timezone=trim((string)env('RADIUS_TIMEZONE','America/Manaus'));try{$tz=new DateTimeZone($timezone?:'America/Manaus');}catch(Throwable $e){$tz=new DateTimeZone('America/Manaus');}return (new DateTimeImmutable('@'.$timestamp))->setTimezone($tz)->format('d M Y H:i:s');
}

function fs_subscriber_radius_group(PDO $app): ?string
{
    try{$st=$app->prepare("SELECT svalue FROM app_settings WHERE skey='subscriber_radius_group' LIMIT 1");$st->execute();$group=trim((string)($st->fetchColumn()?:''));return preg_match('/^[A-Za-z0-9_.:-]{1,64}$/',$group)?$group:null;}catch(Throwable $e){return null;}
}

function fs_subscriber_radius_delete(PDO $radius, string $username): void
{
    if($username==='')return;$owns=!$radius->inTransaction();if($owns)$radius->beginTransaction();
    try{$radius->prepare('DELETE FROM radcheck WHERE username=?')->execute([$username]);$radius->prepare('DELETE FROM radreply WHERE username=?')->execute([$username]);$radius->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$username]);if($owns)$radius->commit();}catch(Throwable $e){if($owns&&$radius->inTransaction())$radius->rollBack();throw $e;}
}

function fs_subscriber_radius_set_rate_limit(PDO $radius, string $username, array $rate): void
{
    $down=(int)($rate['download_kbps']??0);$up=(int)($rate['upload_kbps']??0);
    if($username===''||$down<=0||$up<=0)throw new RuntimeException('Limite de velocidade FIRENETWORK inválido.');
    $owns=!$radius->inTransaction();if($owns)$radius->beginTransaction();
    try{$radius->prepare("DELETE FROM radreply WHERE username=? AND attribute='Mikrotik-Rate-Limit'")->execute([$username]);$radius->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,'Mikrotik-Rate-Limit',':=',?)")->execute([$username,fs_mikrotik_rate_limit_value($down,$up)]);if($owns)$radius->commit();}catch(Throwable $e){if($owns&&$radius->inTransaction())$radius->rollBack();throw $e;}
}

function fs_subscriber_radius_install(PDO $app, PDO $radius, array $grant, array $device, array $entitlement, string $password, int $timeout): void
{
    $username=fs_subscriber_radius_username($grant);$timeout=max(60,$timeout);$expires=time()+$timeout;$owns=!$radius->inTransaction();if($owns)$radius->beginTransaction();
    try{
        fs_subscriber_radius_delete($radius,$username);
        $check=$radius->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,':=',?)");$check->execute([$username,'Cleartext-Password',$password]);$check->execute([$username,'Simultaneous-Use','1']);$check->execute([$username,'Expiration',fs_subscriber_radius_expiration($expires)]);
        $mac=fs_guest_normalize_mac((string)($grant['device_mac']??''));if($mac!==''){$station=$radius->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,'==',?)");$station->execute([$username,'Calling-Station-Id',$mac]);}
        $reply=$radius->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,?,':=',?)");$reply->execute([$username,'Session-Timeout',(string)$timeout]);$reply->execute([$username,'Acct-Interim-Interval','60']);
        fs_subscriber_radius_set_rate_limit($radius,$username,fs_subscriber_effective_rate_limit($entitlement));
        $group=fs_subscriber_radius_group($app);if($group!==null)$radius->prepare('INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)')->execute([$username,$group]);
        if($owns)$radius->commit();
    }catch(Throwable $e){if($owns&&$radius->inTransaction())$radius->rollBack();throw $e;}
}

function fs_subscriber_radius_password(PDO $radius, string $username): ?string
{
    $st=$radius->prepare("SELECT value FROM radcheck WHERE username=? AND attribute='Cleartext-Password' ORDER BY id DESC LIMIT 1");$st->execute([$username]);$value=$st->fetchColumn();return $value===false?null:(string)$value;
}

function fs_subscriber_radius_online(PDO $radius, string $username): bool
{
    $st=$radius->prepare("SELECT COUNT(*) FROM radacct WHERE username=? AND (acctstoptime IS NULL OR acctstoptime='0000-00-00 00:00:00')");$st->execute([$username]);return (int)$st->fetchColumn()>0;
}

function fs_subscriber_radius_accounting_seen(PDO $radius, string $username): bool
{
    if ($username === '') return false;
    $st=$radius->prepare('SELECT COUNT(*) FROM radacct WHERE username=?');$st->execute([$username]);return (int)$st->fetchColumn()>0;
}

function fs_subscriber_grant(PDO $app, string $publicId, bool $forUpdate=false): ?array
{
    if(!preg_match('/^[a-f0-9]{32}$/',$publicId))return null;$st=$app->prepare('SELECT * FROM subscriber_access_grants WHERE public_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));$st->execute([$publicId]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function fs_subscriber_radius_reconcile_account(PDO $app, int $accountId, ?PDO $radius=null): array
{
    $radius=$radius??fs_radius_db();
    $account=fs_subscriber_account($app,$accountId);
    $entitlement=fs_subscriber_entitlement($app,$accountId);
    $accountAuthorized=$account&&$account['status']==='active'&&fs_subscriber_entitlement_usable($entitlement);
    $st=$app->prepare("SELECT g.*,d.status device_status,d.expires_at device_expires_at FROM subscriber_access_grants g JOIN subscriber_devices d ON d.id=g.device_id WHERE g.account_id=? AND g.status='active'");
    $st->execute([$accountId]);$ended=0;$failed=0;
    foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $grant){
        $username=(string)($grant['radius_username']??'');
        $revalidate=!empty($grant['revalidate_at'])?strtotime((string)$grant['revalidate_at']):0;
        $activated=!empty($grant['activated_at'])?strtotime((string)$grant['activated_at']):0;
        $old=$activated>0&&$activated<time()-180;
        $deviceAuthorized=(string)$grant['device_status']==='active'&&(empty($grant['device_expires_at'])||strtotime((string)$grant['device_expires_at'])>time());
        $status=null;$failure=null;
        if(!$accountAuthorized){$status='revoked';$failure='ENTITLEMENT_INACTIVE';}
        elseif(!$deviceAuthorized){$status='expired';$failure='AUTHORIZATION_EXPIRED';}
        elseif($revalidate>0&&$revalidate<=time()){$status='expired';}
        elseif($old&&!fs_subscriber_radius_online($radius,$username)){
            if(fs_subscriber_radius_accounting_seen($radius,$username))$status='expired';
            else{$status='failed';$failure='MIKROTIK_LOGIN_NOT_CONFIRMED';$failed++;}
        }
        if($status===null)continue;
        $app->prepare("UPDATE subscriber_access_grants SET status=?,failure_code=COALESCE(?,failure_code),ended_at=NOW(),updated_at=NOW() WHERE id=? AND status='active'")->execute([$status,$failure,(int)$grant['id']]);
        try{fs_subscriber_radius_delete($radius,$username);$app->prepare('UPDATE subscriber_access_grants SET radius_cleaned_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$grant['id']]);}catch(Throwable $e){error_log('[subscriber radius reconcile] cleanup_failed');}
        $ended++;
    }
    return ['ended'=>$ended,'failed'=>$failed];
}

function fs_subscriber_radius_active_for_device(PDO $app, int $deviceId): ?array
{
    $st=$app->prepare("SELECT * FROM subscriber_access_grants WHERE device_id=? AND status='active' AND revalidate_at>NOW() ORDER BY id DESC LIMIT 1");$st->execute([$deviceId]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function fs_subscriber_radius_prepare_reconnect(PDO $app, array $grant, ?PDO $radius=null): array
{
    $radius=$radius??fs_radius_db();if($grant['status']!=='active'||strtotime((string)$grant['revalidate_at'])<=time())throw new RuntimeException('Esta concessão precisa ser renovada.');
    $username=(string)$grant['radius_username'];$password=fs_subscriber_radius_password($radius,$username);if($password===null||$password==='')throw new RuntimeException('A credencial FIRENETWORK precisa ser renovada.');
    $entitlement=fs_subscriber_entitlement($app,(int)$grant['account_id']);if(!$entitlement||!fs_subscriber_entitlement_usable($entitlement))throw new RuntimeException('O benefício FIRENETWORK precisa ser renovado.');
    fs_subscriber_radius_set_rate_limit($radius,$username,fs_subscriber_effective_rate_limit($entitlement));
    return ['ok'=>true,'grant'=>$grant,'username'=>$username,'password'=>$password,'remaining_seconds'=>max(60,strtotime((string)$grant['revalidate_at'])-time())];
}

function fs_subscriber_radius_reserve(PDO $app, int $accountId, int $deviceId, array $partner, string $idempotencyKey, ?PDO $radius=null): array
{
    if(!fs_subscriber_feature_enabled($app,'subscriber_radius_enabled',false))throw new RuntimeException('O acesso incluído ainda não foi liberado no RADIUS.');
    $radius=$radius??fs_radius_db();$partnerId=(int)($partner['id']??0);$hotspotId=(int)(fs_partner_hotspot_id($partner)??0);if($partnerId<=0||$hotspotId<=0)throw new RuntimeException('Instalação FireSpot inválida.');
    fs_subscriber_radius_reconcile_account($app,$accountId,$radius);
    $active=fs_subscriber_radius_active_for_device($app,$deviceId);if($active)return fs_subscriber_radius_prepare_reconnect($app,$active,$radius);
    $ent=fs_subscriber_entitlement($app,$accountId);if(!$ent||!fs_subscriber_entitlement_usable($ent)){fs_subscriber_refresh_account($app,$accountId);$ent=fs_subscriber_entitlement($app,$accountId);}
    if(!$ent||!fs_subscriber_entitlement_usable($ent))throw new RuntimeException('O benefício FIRENETWORK precisa ser renovado na Minha Conta.');
    $idempotencyHash=fs_personal_hash('subscriber-grant:'.$accountId.':'.$deviceId.':'.$partnerId.':'.$hotspotId.':'.$idempotencyKey);
    $ownsTransaction=!$app->inTransaction();if($ownsTransaction)$app->beginTransaction();
    try{
        $account=fs_subscriber_account($app,$accountId,true);$device=fs_subscriber_device_by_id($app,$deviceId,true);$ent=fs_subscriber_entitlement($app,$accountId,true);
        if(!$account||$account['status']!=='active'||!$device||(int)$device['account_id']!==$accountId||$device['status']!=='active'||(!empty($device['expires_at'])&&strtotime((string)$device['expires_at'])<=time())||!fs_subscriber_entitlement_usable($ent))throw new RuntimeException('A conta ou o aparelho não está autorizado.');
        $st=$app->prepare('SELECT * FROM subscriber_access_grants WHERE idempotency_key_hash=? LIMIT 1 FOR UPDATE');$st->execute([$idempotencyHash]);$existing=$st->fetch(PDO::FETCH_ASSOC);if($existing){if($ownsTransaction)$app->commit();if($existing['status']==='active')return fs_subscriber_radius_prepare_reconnect($app,$existing,$radius);throw new RuntimeException('Esta solicitação já está em processamento.');}
        $st=$app->prepare("SELECT COUNT(*) FROM subscriber_access_grants WHERE account_id=? AND ((status IN ('reserved','provisioning') AND reservation_expires_at>NOW()) OR status='active')");$st->execute([$accountId]);if((int)$st->fetchColumn()>=(int)$ent['concurrent_limit'])throw new RuntimeException('O limite de conexões simultâneas da conta foi atingido.');
        $publicId=fs_subscriber_public_id();$deviceCtx=['mac'=>fs_guest_normalize_mac((string)($partner['_device_mac']??'')),'ip'=>filter_var((string)($partner['_device_ip']??''),FILTER_VALIDATE_IP)?(string)$partner['_device_ip']:null];
        $st=$app->prepare("INSERT INTO subscriber_access_grants (public_id,account_id,device_id,entitlement_id,partner_id,hotspot_id,idempotency_key_hash,status,device_mac,device_ip,reservation_expires_at) VALUES (?,?,?,?,?,?,?,'reserved',?,?,DATE_ADD(NOW(),INTERVAL 120 SECOND))");$st->execute([$publicId,$accountId,$deviceId,(int)$ent['id'],$partnerId,$hotspotId,$idempotencyHash,$deviceCtx['mac']?:null,$deviceCtx['ip']]);$grantId=(int)$app->lastInsertId();fs_subscriber_audit($app,$accountId,'subscriber',$accountId,'grant.reserved','access_grant',$grantId,['partner_id'=>$partnerId,'hotspot_id'=>$hotspotId]);if($ownsTransaction)$app->commit();
    }catch(Throwable $e){if($ownsTransaction&&$app->inTransaction())$app->rollBack();throw $e;}
    return fs_subscriber_radius_provision($app,$publicId,$radius);
}

function fs_subscriber_radius_provision(PDO $app, string $publicId, ?PDO $radius=null): array
{
    $radius=$radius??fs_radius_db();$ownsTransaction=!$app->inTransaction();if($ownsTransaction)$app->beginTransaction();
    try{$grant=fs_subscriber_grant($app,$publicId,true);if(!$grant)throw new InvalidArgumentException('Concessão não encontrada.');if($grant['status']==='active'){if($ownsTransaction)$app->commit();return fs_subscriber_radius_prepare_reconnect($app,$grant,$radius);}if($grant['status']!=='reserved'||strtotime((string)$grant['reservation_expires_at'])<=time())throw new RuntimeException('A reserva expirou.');$device=fs_subscriber_device_by_id($app,(int)$grant['device_id'],true);$ent=fs_subscriber_entitlement($app,(int)$grant['account_id'],true);if(!$device||$device['status']!=='active'||!fs_subscriber_entitlement_usable($ent))throw new RuntimeException('O aparelho não está mais autorizado.');$app->prepare("UPDATE subscriber_access_grants SET status='provisioning',failure_code=NULL,failure_detail=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$grant['id']]);if($ownsTransaction)$app->commit();}catch(Throwable $e){if($ownsTransaction&&$app->inTransaction())$app->rollBack();throw $e;}
    $timeout=max(300,(int)$ent['revalidation_minutes']*60);if(!empty($device['expires_at']))$timeout=min($timeout,max(60,strtotime((string)$device['expires_at'])-time()));$password=bin2hex(random_bytes(16));$username=fs_subscriber_radius_username($grant);
    try{$rate=fs_subscriber_effective_rate_limit($ent);fs_subscriber_radius_install($app,$radius,$grant,$device,$ent,$password,$timeout);$st=$app->prepare("UPDATE subscriber_access_grants SET status='active',radius_username=?,activated_at=NOW(),revalidate_at=DATE_ADD(NOW(),INTERVAL ? SECOND),expires_at=COALESCE(?,DATE_ADD(NOW(),INTERVAL ? SECOND)),updated_at=NOW() WHERE id=? AND status='provisioning'");$st->execute([$username,$timeout,$device['expires_at']??null,$timeout,(int)$grant['id']]);if($st->rowCount()!==1)throw new RuntimeException('O ledger recusou a ativação.');fs_subscriber_audit($app,(int)$grant['account_id'],'system',null,'grant.activated','access_grant',(int)$grant['id'],['partner_id'=>(int)$grant['partner_id'],'hotspot_id'=>(int)$grant['hotspot_id'],'download_kbps'=>$rate['download_kbps'],'upload_kbps'=>$rate['upload_kbps'],'rate_source'=>$rate['source']]);}catch(Throwable $e){try{fs_subscriber_radius_delete($radius,$username);}catch(Throwable $cleanup){}$app->prepare("UPDATE subscriber_access_grants SET status='failed',failure_code='RADIUS_PROVISION_FAILED',failure_detail=?,ended_at=NOW(),updated_at=NOW() WHERE id=? AND status='provisioning'")->execute([substr($e->getMessage(),0,255),(int)$grant['id']]);throw $e;}
    $active=fs_subscriber_grant($app,$publicId);return ['ok'=>true,'grant'=>$active,'username'=>$username,'password'=>$password,'remaining_seconds'=>$timeout];
}

function fs_subscriber_radius_revoke_device(PDO $app, int $deviceId, ?PDO $radius=null): int
{
    $radius=$radius??fs_radius_db();$st=$app->prepare("SELECT id,radius_username FROM subscriber_access_grants WHERE device_id=? AND status IN ('reserved','provisioning','active')");$st->execute([$deviceId]);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($rows as $row){$username=(string)($row['radius_username']??'');if($username!=='')try{fs_subscriber_radius_delete($radius,$username);}catch(Throwable $e){error_log('[subscriber radius revoke] '.$e->getMessage());}$app->prepare("UPDATE subscriber_access_grants SET status='revoked',ended_at=NOW(),radius_cleaned_at=IF(?<>'',NOW(),radius_cleaned_at),updated_at=NOW() WHERE id=?")->execute([$username,(int)$row['id']]);}
    return count($rows);
}

function fs_subscriber_radius_cleanup(PDO $app, ?PDO $radius=null): array
{
    $radius=$radius??fs_radius_db();$st=$app->query("SELECT * FROM subscriber_access_grants WHERE status IN ('expired','revoked','failed') AND radius_username IS NOT NULL AND radius_cleaned_at IS NULL LIMIT 500");$cleaned=0;
    foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $grant){try{fs_subscriber_radius_delete($radius,(string)$grant['radius_username']);$app->prepare('UPDATE subscriber_access_grants SET radius_cleaned_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$grant['id']]);$cleaned++;}catch(Throwable $e){error_log('[subscriber radius cleanup] '.$e->getMessage());}}
    $app->exec("UPDATE subscriber_access_grants SET status='expired',ended_at=NOW(),updated_at=NOW() WHERE status IN ('reserved','provisioning') AND reservation_expires_at<=NOW()");return ['cleaned'=>$cleaned];
}
