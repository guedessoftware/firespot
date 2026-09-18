<?php

declare(strict_types=1);

require_once __DIR__ . '/subscriber_accounts.php';
require_once __DIR__ . '/guest_access.php';

function fs_subscriber_device_cookie_name(): string
{
    return 'FSDEVICE';
}

function fs_subscriber_device_set_cookie(string $value, int $expires): void
{
    $secure=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
    if(!headers_sent())setcookie(fs_subscriber_device_cookie_name(),$value,['expires'=>$expires,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    if($value==='')unset($_COOKIE[fs_subscriber_device_cookie_name()]);else $_COOKIE[fs_subscriber_device_cookie_name()]=$value;
}

function fs_subscriber_device_token_hash(string $token): string
{
    return fs_personal_hash('subscriber-device:' . $token);
}

function fs_subscriber_device_expire_invites(PDO $pdo, ?int $accountId=null): int
{
    $sql="UPDATE subscriber_device_invites SET status='expired',updated_at=NOW() WHERE status='created' AND expires_at<=NOW()";$params=[];
    if($accountId!==null){$sql.=' AND account_id=?';$params[]=$accountId;}$st=$pdo->prepare($sql);$st->execute($params);return $st->rowCount();
}

function fs_subscriber_device_expire_devices(PDO $pdo, ?int $accountId=null): int
{
    $sql="UPDATE subscriber_devices SET status='expired',status_reason='AUTHORIZATION_EXPIRED',updated_at=NOW() WHERE status='active' AND expires_at IS NOT NULL AND expires_at<=NOW()";$params=[];
    if($accountId!==null){$sql.=' AND account_id=?';$params[]=$accountId;}$st=$pdo->prepare($sql);$st->execute($params);return $st->rowCount();
}

function fs_subscriber_device_occupancy(PDO $pdo, int $accountId, bool $forUpdate=false): array
{
    fs_subscriber_device_expire_invites($pdo,$accountId);fs_subscriber_device_expire_devices($pdo,$accountId);
    $suffix=$forUpdate?' FOR UPDATE':'';
    $st=$pdo->prepare("SELECT id,status,replace_available_at FROM subscriber_devices WHERE account_id=? AND (status='active' OR (status='revoked' AND replace_available_at>NOW())) ORDER BY id".$suffix);$st->execute([$accountId]);$devices=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    $st=$pdo->prepare("SELECT id FROM subscriber_device_invites WHERE account_id=? AND status='created' AND expires_at>NOW() ORDER BY id".$suffix);$st->execute([$accountId]);$invites=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    $ent=fs_subscriber_entitlement($pdo,$accountId,$forUpdate);$limit=max(0,(int)($ent['device_limit']??0));
    return ['limit'=>$limit,'devices'=>count($devices),'invites'=>count($invites),'used'=>count($devices)+count($invites),'available'=>max(0,$limit-count($devices)-count($invites)),'entitlement'=>$ent];
}

function fs_subscriber_devices(PDO $pdo, int $accountId): array
{
    fs_subscriber_device_expire_devices($pdo,$accountId);$st=$pdo->prepare('SELECT * FROM subscriber_devices WHERE account_id=? ORDER BY (status=\'active\') DESC,(device_kind=\'primary\') DESC,updated_at DESC,id DESC');$st->execute([$accountId]);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function fs_subscriber_invites(PDO $pdo, int $accountId): array
{
    fs_subscriber_device_expire_invites($pdo,$accountId);$st=$pdo->prepare('SELECT id,public_id,label,status,authorization_mode,access_duration_minutes,authorization_until,expires_at,redeemed_at,created_at FROM subscriber_device_invites WHERE account_id=? ORDER BY created_at DESC LIMIT 30');$st->execute([$accountId]);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function fs_subscriber_invite_code(): string
{
    $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';$code='';for($i=0;$i<8;$i++)$code.=$alphabet[random_int(0,strlen($alphabet)-1)];return $code;
}

function fs_subscriber_invite_hash(string $kind, string $secret): string
{
    return fs_personal_hash('subscriber-invite-'.$kind.':'.strtoupper(trim($secret)));
}

function fs_subscriber_invite_create(PDO $pdo, int $accountId, array $input=[]): array
{
    if(!fs_subscriber_feature_enabled($pdo,'subscriber_invites_enabled',false))throw new RuntimeException('A emissão de convites ainda não foi liberada.');
    $label=substr(trim((string)($input['label']??'')),0,120);$mode=(string)($input['authorization_mode']??'while_authorized');
    if(!in_array($mode,['temporary','until_date','while_authorized'],true))throw new InvalidArgumentException('Validade do convite inválida.');
    $duration=null;$until=null;
    if($mode==='temporary'){$duration=max(15,min(43200,(int)($input['access_duration_minutes']??1440)));}
    if($mode==='until_date'){$raw=trim((string)($input['authorization_until']??''));$ts=strtotime($raw);if($ts===false||$ts<=time()+300||$ts>time()+366*86400)throw new InvalidArgumentException('Informe uma validade futura de até um ano.');$until=date('Y-m-d H:i:s',$ts);}
    $ttl=max(5,min(120,(int)($input['invite_ttl_minutes']??30)));$publicId=fs_subscriber_public_id();$token=bin2hex(random_bytes(24));$code=fs_subscriber_invite_code();
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $account=fs_subscriber_account($pdo,$accountId,true);$ent=fs_subscriber_entitlement($pdo,$accountId,true);
        if(!$account||$account['status']!=='active'||!fs_subscriber_entitlement_usable($ent))throw new RuntimeException('O benefício não está ativo.');
        $occupancy=fs_subscriber_device_occupancy($pdo,$accountId,true);if((int)$occupancy['available']<1)throw new RuntimeException('Todas as vagas de aparelhos estão ocupadas ou reservadas.');
        $st=$pdo->prepare("INSERT INTO subscriber_device_invites (public_id,account_id,label,token_hash,human_code_hash,status,authorization_mode,access_duration_minutes,authorization_until,expires_at,created_by_type,created_by_id) VALUES (?,?,?,?,?,'created',?,?,?,DATE_ADD(NOW(),INTERVAL ? MINUTE),'subscriber',?)");
        $st->execute([$publicId,$accountId,$label!==''?$label:null,fs_subscriber_invite_hash('token',$token),fs_subscriber_invite_hash('code',$code),$mode,$duration,$until,$ttl,$accountId]);$id=(int)$pdo->lastInsertId();
        fs_subscriber_audit($pdo,$accountId,'subscriber',$accountId,'invite.created','device_invite',$id,['authorization_mode'=>$mode,'ttl_minutes'=>$ttl]);if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['id'=>$id,'public_id'=>$publicId,'token'=>$token,'code'=>$code,'expires_at'=>date('Y-m-d H:i:s',time()+$ttl*60),'label'=>$label,'authorization_mode'=>$mode];
}

function fs_subscriber_invite_revoke(PDO $pdo, int $accountId, int $inviteId): void
{
    $st=$pdo->prepare("UPDATE subscriber_device_invites SET status='revoked',token_hash=SHA2(CONCAT(token_hash,':revoked'),256),human_code_hash=SHA2(CONCAT(human_code_hash,':revoked'),256),updated_at=NOW() WHERE id=? AND account_id=? AND status='created'");$st->execute([$inviteId,$accountId]);if($st->rowCount()!==1)throw new RuntimeException('Convite ativo não encontrado.');fs_subscriber_audit($pdo,$accountId,'subscriber',$accountId,'invite.revoked','device_invite',$inviteId);
}

function fs_subscriber_device_create(PDO $pdo, int $accountId, string $kind, ?string $label, string $mode, ?string $expiresAt, ?int $partnerId, ?int $hotspotId): array
{
    $token=bin2hex(random_bytes(32));$publicId=fs_subscriber_public_id();$label=substr(trim((string)$label),0,120);
    $st=$pdo->prepare("INSERT INTO subscriber_devices (public_id,account_id,label,device_kind,status,authorization_mode,device_token_hash,authorized_at,expires_at,first_seen_at,last_seen_at,last_partner_id,last_hotspot_id) VALUES (?,?,?,?,'active',?,?,NOW(),?,NOW(),NOW(),?,?)");
    $st->execute([$publicId,$accountId,$label!==''?$label:null,$kind,$mode,fs_subscriber_device_token_hash($token),$expiresAt,$partnerId,$hotspotId]);$deviceId=(int)$pdo->lastInsertId();
    $identifierHash=fs_subscriber_device_token_hash($token);$pdo->prepare("INSERT INTO subscriber_device_identifiers (device_id,identifier_type,identifier_hash,identifier_hint,partner_id,hotspot_id,active) VALUES (?,'browser_token',?,'cookie',?,?,1)")->execute([$deviceId,$identifierHash,$partnerId,$hotspotId]);
    return ['id'=>$deviceId,'public_id'=>$publicId,'token'=>$token,'expires_at'=>$expiresAt];
}

function fs_subscriber_invite_redeem(PDO $pdo, string $secret, array $context=[]): array
{
    if(!fs_subscriber_feature_enabled($pdo,'subscriber_invites_enabled',false))throw new RuntimeException('O resgate de convites ainda não foi liberado.');
    $secret=trim($secret);$byToken=preg_match('/^[a-f0-9]{48}$/i',$secret)===1;$hash=fs_subscriber_invite_hash($byToken?'token':'code',$secret);$column=$byToken?'token_hash':'human_code_hash';
    $partnerId=max(0,(int)($context['partner_id']??0))?:null;$hotspotId=max(0,(int)($context['hotspot_id']??0))?:null;
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare("SELECT * FROM subscriber_device_invites WHERE {$column}=? LIMIT 1 FOR UPDATE");$st->execute([$hash]);$invite=$st->fetch(PDO::FETCH_ASSOC);
        if(!$invite||$invite['status']!=='created'||strtotime((string)$invite['expires_at'])<=time())throw new RuntimeException('Convite inválido, expirado ou já utilizado.');
        $accountId=(int)$invite['account_id'];$account=fs_subscriber_account($pdo,$accountId,true);$ent=fs_subscriber_entitlement($pdo,$accountId,true);
        if(!$account||$account['status']!=='active'||!fs_subscriber_entitlement_usable($ent))throw new RuntimeException('O benefício deste convite não está ativo.');
        $occupancy=fs_subscriber_device_occupancy($pdo,$accountId,true);if((int)$occupancy['used']>(int)$occupancy['limit'])throw new RuntimeException('O limite de aparelhos foi atingido.');
        $mode=(string)$invite['authorization_mode'];$expiresAt=null;if($mode==='temporary')$expiresAt=date('Y-m-d H:i:s',time()+max(15,(int)$invite['access_duration_minutes'])*60);elseif($mode==='until_date')$expiresAt=$invite['authorization_until'];
        $device=fs_subscriber_device_create($pdo,$accountId,'guest',$invite['label']??'Convidado',$mode,$expiresAt,$partnerId,$hotspotId);
        $st=$pdo->prepare("UPDATE subscriber_device_invites SET status='redeemed',redeemed_at=NOW(),redeemed_device_id=?,token_hash=SHA2(CONCAT(token_hash,':used'),256),human_code_hash=SHA2(CONCAT(human_code_hash,':used'),256),updated_at=NOW() WHERE id=? AND status='created'");$st->execute([(int)$device['id'],(int)$invite['id']]);if($st->rowCount()!==1)throw new RuntimeException('O convite foi consumido por outra solicitação.');
        fs_subscriber_audit($pdo,$accountId,'subscriber',$accountId,'invite.redeemed','device',(int)$device['id'],['invite_id'=>(int)$invite['id'],'authorization_mode'=>$mode]);if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    fs_subscriber_device_set_cookie((string)$device['token'],$expiresAt?strtotime($expiresAt):time()+400*86400);return ['ok'=>true,'device'=>$device,'account_id'=>$accountId];
}

function fs_subscriber_authorize_primary(PDO $pdo, int $accountId, array $context=[]): array
{
    if(!fs_subscriber_feature_enabled($pdo,'subscriber_invites_enabled',false))throw new RuntimeException('A autorização de aparelhos ainda não foi liberada.');
    $existing=fs_subscriber_device_current($pdo,$context);if($existing&&(int)$existing['account_id']===$accountId)return $existing;
    $partnerId=max(0,(int)($context['partner_id']??0))?:null;$hotspotId=max(0,(int)($context['hotspot_id']??0))?:null;
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare("SELECT * FROM subscriber_devices WHERE account_id=? AND device_kind='primary' AND status='active' LIMIT 1 FOR UPDATE");$st->execute([$accountId]);$primary=$st->fetch(PDO::FETCH_ASSOC);
        if($primary)throw new RuntimeException('O aparelho principal já foi autorizado. Revogue-o na Minha Conta antes de substituir.');
        $occupancy=fs_subscriber_device_occupancy($pdo,$accountId,true);if((int)$occupancy['available']<1)throw new RuntimeException('Não há vaga disponível para este aparelho.');
        $device=fs_subscriber_device_create($pdo,$accountId,'primary','Meu aparelho','while_authorized',null,$partnerId,$hotspotId);fs_subscriber_audit($pdo,$accountId,'subscriber',$accountId,'device.primary_authorized','device',(int)$device['id']);if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    fs_subscriber_device_set_cookie((string)$device['token'],time()+400*86400);$current=fs_subscriber_device_by_id($pdo,(int)$device['id']);return $current?:$device;
}

function fs_subscriber_device_by_id(PDO $pdo, int $deviceId, bool $forUpdate=false): ?array
{
    $st=$pdo->prepare('SELECT * FROM subscriber_devices WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));$st->execute([$deviceId]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function fs_subscriber_device_current(PDO $pdo, array $context=[]): ?array
{
    fs_subscriber_device_expire_devices($pdo);$raw=trim((string)($_COOKIE[fs_subscriber_device_cookie_name()]??''));$device=null;
    if(preg_match('/^[a-f0-9]{64}$/',$raw)){$st=$pdo->prepare("SELECT * FROM subscriber_devices WHERE device_token_hash=? AND status='active' AND (expires_at IS NULL OR expires_at>NOW()) LIMIT 1");$st->execute([fs_subscriber_device_token_hash($raw)]);$device=$st->fetch(PDO::FETCH_ASSOC)?:null;}
    $mac=fs_guest_normalize_mac((string)($context['mac']??''));
    if(!$device&&$mac!==''){$hash=fs_personal_hash('device-mac:'.$mac);$st=$pdo->prepare("SELECT d.* FROM subscriber_device_identifiers i JOIN subscriber_devices d ON d.id=i.device_id WHERE i.identifier_type='mac' AND i.identifier_hash=? AND i.active=1 AND d.status='active' AND (d.expires_at IS NULL OR d.expires_at>NOW()) LIMIT 1");$st->execute([$hash]);$device=$st->fetch(PDO::FETCH_ASSOC)?:null;}
    if(!$device)return null;
    $partnerId=max(0,(int)($context['partner_id']??0))?:null;$hotspotId=max(0,(int)($context['hotspot_id']??0))?:null;
    $pdo->prepare('UPDATE subscriber_devices SET first_seen_at=COALESCE(first_seen_at,NOW()),last_seen_at=NOW(),last_partner_id=COALESCE(?,last_partner_id),last_hotspot_id=COALESCE(?,last_hotspot_id),updated_at=NOW() WHERE id=?')->execute([$partnerId,$hotspotId,(int)$device['id']]);
    if($mac!==''){$hash=fs_personal_hash('device-mac:'.$mac);try{$pdo->prepare("INSERT INTO subscriber_device_identifiers (device_id,identifier_type,identifier_hash,identifier_hint,partner_id,hotspot_id,active,first_seen_at,last_seen_at) VALUES (?,'mac',?,?,?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE partner_id=IF(device_id=VALUES(device_id),VALUES(partner_id),partner_id),hotspot_id=IF(device_id=VALUES(device_id),VALUES(hotspot_id),hotspot_id),active=IF(device_id=VALUES(device_id),1,active),last_seen_at=IF(device_id=VALUES(device_id),NOW(),last_seen_at),updated_at=NOW()")->execute([(int)$device['id'],$hash,substr($mac,-5),$partnerId,$hotspotId]);}catch(Throwable $e){error_log('[subscriber device identifier] '.$e->getMessage());}}
    return fs_subscriber_device_by_id($pdo,(int)$device['id']);
}

function fs_subscriber_device_revoke(PDO $pdo, int $accountId, int $deviceId, int $cooldownHours=24): void
{
    $cooldownHours=max(0,min(168,$cooldownHours));$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{$device=fs_subscriber_device_by_id($pdo,$deviceId,true);if(!$device||(int)$device['account_id']!==$accountId||$device['status']!=='active')throw new RuntimeException('Aparelho ativo não encontrado.');
        // A propriedade precisa ser validada antes de qualquer efeito no RADIUS.
        // Isso impede que um ID de aparelho pertencente a outra conta seja usado
        // para derrubar a credencial técnica daquele assinante.
        if(function_exists('fs_subscriber_radius_revoke_device'))fs_subscriber_radius_revoke_device($pdo,$deviceId);
        $pdo->prepare("UPDATE subscriber_devices SET status='revoked',status_reason='REVOKED_BY_OWNER',replace_available_at=DATE_ADD(NOW(),INTERVAL ? HOUR),device_token_hash=SHA2(CONCAT(device_token_hash,':revoked'),256),updated_at=NOW() WHERE id=?")->execute([$cooldownHours,$deviceId]);
        $pdo->prepare('UPDATE subscriber_device_identifiers SET active=0,updated_at=NOW() WHERE device_id=?')->execute([$deviceId]);
        $pdo->prepare("UPDATE subscriber_access_grants SET status='revoked',ended_at=COALESCE(ended_at,NOW()),updated_at=NOW() WHERE device_id=? AND status IN ('reserved','provisioning','active')")->execute([$deviceId]);
        fs_subscriber_audit($pdo,$accountId,'subscriber',$accountId,'device.revoked','device',$deviceId,['cooldown_hours'=>$cooldownHours]);if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    $raw=trim((string)($_COOKIE[fs_subscriber_device_cookie_name()]??''));if($raw!==''&&hash_equals((string)$device['device_token_hash'],fs_subscriber_device_token_hash($raw)))fs_subscriber_device_set_cookie('',time()-3600);
}
