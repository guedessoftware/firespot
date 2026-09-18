<?php

declare(strict_types=1);

require_once __DIR__ . '/subscriber_radius.php';
require_once __DIR__ . '/hubsoft_api.php';
require_once __DIR__ . '/settings.php';

/**
 * Retorna somente presença e prontidão da integração. Valores sensíveis nunca
 * fazem parte do resultado usado pelo painel administrativo.
 */
function fs_subscriber_admin_hubsoft_status(PDO $pdo): array
{
    $integration=fs_integration_hubsoft_status($pdo);$credentialsReady=!empty($integration['configured']);$secureUrl=!empty($integration['secure_url']);
    $cryptoReady=function_exists('sodium_crypto_secretbox')&&!empty($integration['key_ready']);
    $promoEnabled=filter_var(settings_get('promo_enabled',env('PROMO_ENABLED','0')),FILTER_VALIDATE_BOOLEAN);
    $promoConfigured=trim((string)settings_get('promo_api_base',env('PROMO_API_BASE','')))!==''&&trim((string)settings_get('promo_api_user',env('PROMO_API_USER','')))!==''&&trim((string)settings_get('promo_api_hash',env('PROMO_API_HASH','')))!=='';
    $mappingCount=0;$profileCount=0;
    try{$mappingCount=(int)$pdo->query("SELECT COUNT(*) FROM subscriber_plan_mappings WHERE provider='hubsoft' AND active=1 AND eligible_internet=1")->fetchColumn();$profileCount=(int)$pdo->query('SELECT COUNT(*) FROM subscriber_benefit_profiles WHERE active=1')->fetchColumn();}catch(Throwable $e){}
    return [
        'configured'=>$credentialsReady&&!empty($integration['active']),
        'credentials_ready'=>$credentialsReady,
        'secure_url'=>$secureUrl,
        'curl_ready'=>function_exists('curl_init'),
        'crypto_ready'=>$cryptoReady,
        'credential_source'=>$credentialsReady?'application':'missing',
        'integration'=>$integration,
        'otp_ready'=>$promoEnabled&&$promoConfigured,
        'promo_enabled'=>$promoEnabled,
        'promo_configured'=>$promoConfigured,
        'mapping_count'=>$mappingCount,
        'profile_count'=>$profileCount,
    ];
}

function fs_subscriber_admin_profiles(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM subscriber_benefit_profiles ORDER BY device_limit,id')->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function fs_subscriber_admin_mappings(PDO $pdo): array
{
    return $pdo->query("SELECT m.*,p.code profile_code,p.name profile_name,p.device_limit,p.concurrent_limit,p.download_kbps profile_download_kbps,p.upload_kbps profile_upload_kbps,
        COALESCE(NULLIF(m.download_kbps,0),p.download_kbps) effective_download_kbps,
        COALESCE(NULLIF(m.upload_kbps,0),p.upload_kbps) effective_upload_kbps,
        (SELECT COUNT(*) FROM subscriber_entitlements e WHERE e.plan_mapping_id=m.id) linked_entitlements,
        (SELECT COUNT(*) FROM subscriber_entitlements e WHERE e.plan_mapping_id=m.id AND e.status IN ('active','grace')) active_entitlements
        FROM subscriber_plan_mappings m
        JOIN subscriber_benefit_profiles p ON p.id=m.benefit_profile_id
        ORDER BY m.active DESC,m.external_kind,m.external_label,m.external_id")->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function fs_subscriber_admin_mapping_save(PDO $pdo, array $input): int
{
    $id=max(0,(int)($input['id']??0));$kind=(string)($input['external_kind']??'plan');
    $externalId=substr(trim((string)($input['external_id']??'')),0,128);$label=substr(trim((string)($input['external_label']??'')),0,180);
    $profileId=max(0,(int)($input['benefit_profile_id']??0));
    $download=max(0,(int)($input['download_kbps']??0));$upload=max(0,(int)($input['upload_kbps']??0));
    if(!in_array($kind,['plan','service','package'],true))throw new InvalidArgumentException('Tipo de identificador inválido.');
    if($externalId===''||$profileId<=0)throw new InvalidArgumentException('Informe o ID estável e o perfil de benefício.');
    if($download<64||$download>10000000||$upload<64||$upload>10000000)throw new InvalidArgumentException('Informe download e upload entre 64 e 10.000.000 Kbps.');
    $st=$pdo->prepare('SELECT id FROM subscriber_benefit_profiles WHERE id=? AND active=1 LIMIT 1');$st->execute([$profileId]);if(!$st->fetchColumn())throw new InvalidArgumentException('Perfil de benefício inválido.');
    if($id>0){$st=$pdo->prepare('UPDATE subscriber_plan_mappings SET external_kind=?,external_id=?,external_label=?,eligible_internet=?,benefit_profile_id=?,download_kbps=?,upload_kbps=?,active=?,updated_at=NOW() WHERE id=? AND provider=\'hubsoft\'');$st->execute([$kind,$externalId,$label?:null,!empty($input['eligible_internet'])?1:0,$profileId,$download,$upload,!empty($input['active'])?1:0,$id]);if($st->rowCount()<1){$check=$pdo->prepare('SELECT id FROM subscriber_plan_mappings WHERE id=?');$check->execute([$id]);if(!$check->fetchColumn())throw new RuntimeException('Mapeamento não encontrado.');}return $id;}
    $st=$pdo->prepare("INSERT INTO subscriber_plan_mappings (provider,external_kind,external_id,external_label,eligible_internet,benefit_profile_id,download_kbps,upload_kbps,active) VALUES ('hubsoft',?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE external_label=VALUES(external_label),eligible_internet=VALUES(eligible_internet),benefit_profile_id=VALUES(benefit_profile_id),download_kbps=VALUES(download_kbps),upload_kbps=VALUES(upload_kbps),active=VALUES(active),updated_at=NOW()");
    $st->execute([$kind,$externalId,$label?:null,!empty($input['eligible_internet'])?1:0,$profileId,$download,$upload,!empty($input['active'])?1:0]);
    if($pdo->lastInsertId())return(int)$pdo->lastInsertId();$find=$pdo->prepare("SELECT id FROM subscriber_plan_mappings WHERE provider='hubsoft' AND external_kind=? AND external_id=?");$find->execute([$kind,$externalId]);return(int)$find->fetchColumn();
}

function fs_subscriber_admin_mapping_toggle(PDO $pdo, int $id, bool $active): void
{
    $st=$pdo->prepare("UPDATE subscriber_plan_mappings SET active=?,updated_at=NOW() WHERE id=? AND provider='hubsoft'");$st->execute([$active?1:0,$id]);if($st->rowCount()!==1)throw new RuntimeException('Mapeamento não encontrado ou já estava neste estado.');
}

function fs_subscriber_admin_mapping_delete(PDO $pdo, int $id, int $actorId): array
{
    if($id<=0)throw new InvalidArgumentException('Mapeamento inválido.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare("SELECT m.*,p.code profile_code,p.name profile_name FROM subscriber_plan_mappings m JOIN subscriber_benefit_profiles p ON p.id=m.benefit_profile_id WHERE m.id=? AND m.provider='hubsoft' LIMIT 1 FOR UPDATE");$st->execute([$id]);$mapping=$st->fetch(PDO::FETCH_ASSOC);
        if(!$mapping)throw new RuntimeException('Mapeamento HubSoft não encontrado.');
        if(!empty($mapping['active']))throw new RuntimeException('Desative o mapeamento antes de excluí-lo.');
        $countStatement=$pdo->prepare('SELECT COUNT(*) FROM subscriber_entitlements WHERE plan_mapping_id=?');$countStatement->execute([$id]);$linked=(int)$countStatement->fetchColumn();
        if($linked>0)throw new RuntimeException("Este mapeamento ainda possui {$linked} assinante(s) vinculado(s). Migre-o antes de excluir.");
        fs_subscriber_audit($pdo,null,'firespot',$actorId,'plan_mapping.deleted','plan_mapping',$id,[
            'provider'=>'hubsoft','external_kind'=>(string)$mapping['external_kind'],'external_id'=>(string)$mapping['external_id'],
            'profile_code'=>(string)$mapping['profile_code'],
        ]);
        $delete=$pdo->prepare("DELETE FROM subscriber_plan_mappings WHERE id=? AND provider='hubsoft' AND active=0");$delete->execute([$id]);
        if($delete->rowCount()!==1)throw new RuntimeException('O mapeamento mudou durante a exclusão. Atualize a página e tente novamente.');
        if($ownsTransaction)$pdo->commit();
        return ['id'=>$id,'linked_entitlements'=>0];
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

function fs_subscriber_admin_mapping_migrate(PDO $pdo, int $sourceId, int $targetId, int $actorId): array
{
    if($sourceId<=0||$targetId<=0||$sourceId===$targetId)throw new InvalidArgumentException('Selecione dois mapeamentos diferentes para a migração.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lockIds=[$sourceId,$targetId];sort($lockIds,SORT_NUMERIC);
        $st=$pdo->prepare("SELECT m.*,p.code profile_code,p.name profile_name,p.active profile_active FROM subscriber_plan_mappings m JOIN subscriber_benefit_profiles p ON p.id=m.benefit_profile_id WHERE m.provider='hubsoft' AND m.id IN (?,?) ORDER BY m.id FOR UPDATE");$st->execute($lockIds);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$byId=[];foreach($rows as $row)$byId[(int)$row['id']]=$row;
        $source=$byId[$sourceId]??null;$target=$byId[$targetId]??null;
        if(!$source||!$target)throw new RuntimeException('O mapeamento de origem ou destino não foi encontrado.');
        if(!empty($source['active']))throw new RuntimeException('Desative o mapeamento de origem antes de migrá-lo.');
        if(empty($target['active'])||empty($target['eligible_internet'])||empty($target['profile_active']))throw new RuntimeException('O destino precisa estar ativo, elegível para internet e usar um perfil ativo.');

        $countStatement=$pdo->prepare('SELECT COUNT(*) FROM subscriber_entitlements WHERE plan_mapping_id=?');$countStatement->execute([$sourceId]);$linked=(int)$countStatement->fetchColumn();
        $metadata=[
            'source_mapping_id'=>$sourceId,'target_mapping_id'=>$targetId,
            'source_kind'=>(string)$source['external_kind'],'source_external_id'=>(string)$source['external_id'],
            'target_kind'=>(string)$target['external_kind'],'target_external_id'=>(string)$target['external_id'],
            'source_profile'=>(string)$source['profile_code'],'target_profile'=>(string)$target['profile_code'],
            'linked_entitlements'=>$linked,'requires_revalidation'=>true,
        ];
        fs_subscriber_audit($pdo,null,'firespot',$actorId,'plan_mapping.migrated','plan_mapping',$targetId,$metadata);
        if($linked>0){
            $metadataJson=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $audit=$pdo->prepare("INSERT INTO subscriber_audit (account_id,actor_type,actor_id,action,target_type,target_id,metadata,origin_hash)
                SELECT e.account_id,'firespot',?,'plan_mapping.migrated','entitlement',CAST(e.id AS CHAR),?,?
                FROM subscriber_entitlements e WHERE e.plan_mapping_id=?");
            $audit->execute([$actorId,$metadataJson,fs_subscriber_origin_hash(),$sourceId]);
            $move=$pdo->prepare("UPDATE subscriber_entitlements SET plan_mapping_id=?,benefit_profile_id=?,result_code='MAPPING_MIGRATION_PENDING',verified_at=NULL,valid_until=NOW(),grace_until=NOW(),revision=revision+1,updated_at=NOW() WHERE plan_mapping_id=?");
            $move->execute([$targetId,(int)$target['benefit_profile_id'],$sourceId]);
            if($move->rowCount()!==$linked)throw new RuntimeException('Nem todos os vínculos foram transferidos. A migração foi cancelada.');
        }
        $delete=$pdo->prepare("DELETE FROM subscriber_plan_mappings WHERE id=? AND provider='hubsoft' AND active=0");$delete->execute([$sourceId]);
        if($delete->rowCount()!==1)throw new RuntimeException('O mapeamento de origem mudou durante a migração. A operação foi cancelada.');
        if($ownsTransaction)$pdo->commit();
        return [
            'source_id'=>$sourceId,'target_id'=>$targetId,'linked_entitlements'=>$linked,
            'source_label'=>(string)$source['external_kind'].':'.(string)$source['external_id'],
            'target_label'=>(string)$target['external_kind'].':'.(string)$target['external_id'],
        ];
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

function fs_subscriber_admin_unknown_ids(PDO $pdo): array
{
    $st=$pdo->query("SELECT source_snapshot,result_code,COUNT(*) total,MAX(updated_at) last_seen FROM subscriber_entitlements WHERE result_code IN ('DEFAULT_BASIC_UNMAPPED','SERVICE_MAPPING_REQUIRED','SERVICE_INACTIVE') GROUP BY source_snapshot,result_code ORDER BY last_seen DESC LIMIT 200");$items=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $snapshot=json_decode((string)$row['source_snapshot'],true);if(!is_array($snapshot))continue;
        foreach((array)($snapshot['mapping_candidates']??[]) as $candidate){
            $kind=(string)($candidate['kind']??'');$id=(string)($candidate['id']??'');if(!in_array($kind,['plan','service','package'],true)||$id==='')continue;$key=$kind.':'.$id;
            $label=substr(trim((string)($candidate['label']??'')),0,180);$detected=!empty($candidate['internet_detected']);
            if(!isset($items[$key]))$items[$key]=['kind'=>$kind,'external_id'=>$id,'label'=>$label,'internet_detected'=>$detected,'total'=>0,'last_seen'=>$row['last_seen'],'result_codes'=>[]];
            if($items[$key]['label']===''&&$label!=='')$items[$key]['label']=$label;if($detected)$items[$key]['internet_detected']=true;
            $items[$key]['total']+=(int)$row['total'];$items[$key]['result_codes'][(string)$row['result_code']]=true;if((string)$row['last_seen']>(string)$items[$key]['last_seen'])$items[$key]['last_seen']=$row['last_seen'];
        }
    }
    if(!$items)return[];$mappings=fs_subscriber_admin_mappings($pdo);$known=[];foreach($mappings as $mapping)$known[$mapping['external_kind'].':'.$mapping['external_id']]=true;
    $items=array_values(array_filter($items,static fn(array $item):bool=>empty($known[$item['kind'].':'.$item['external_id']])));
    foreach($items as &$item)$item['result_codes']=array_keys($item['result_codes']);unset($item);
    usort($items,static fn(array $a,array $b):int=>((int)$b['internet_detected']<=>(int)$a['internet_detected'])?:strcmp((string)$b['last_seen'],(string)$a['last_seen'])?:strcmp($a['kind'].':'.$a['external_id'],$b['kind'].':'.$b['external_id']));return$items;
}

function fs_subscriber_admin_metrics(PDO $pdo): array
{
    $scalar=static function(PDO $pdo,string $sql):int{try{return(int)$pdo->query($sql)->fetchColumn();}catch(Throwable $e){return 0;}};
    $group=static function(PDO $pdo,string $sql):array{try{return$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){return[];}};
    return [
        'accounts'=>$scalar($pdo,'SELECT COUNT(*) FROM subscriber_accounts'),
        'active_entitlements'=>$scalar($pdo,"SELECT COUNT(*) FROM subscriber_entitlements WHERE status='active'"),
        'active_devices'=>$scalar($pdo,"SELECT COUNT(*) FROM subscriber_devices WHERE status='active'"),
        'active_grants'=>$scalar($pdo,"SELECT COUNT(*) FROM subscriber_access_grants WHERE status='active'"),
        'entitlements'=>$group($pdo,'SELECT status,result_code,COUNT(*) total FROM subscriber_entitlements GROUP BY status,result_code ORDER BY status,result_code'),
        'invites'=>$group($pdo,'SELECT status,COUNT(*) total FROM subscriber_device_invites GROUP BY status ORDER BY status'),
        'grants'=>$group($pdo,'SELECT status,COALESCE(failure_code,\'\') failure_code,COUNT(*) total FROM subscriber_access_grants GROUP BY status,failure_code ORDER BY status,failure_code'),
        'auth_attempts'=>$group($pdo,"SELECT outcome,result_code,COUNT(*) total FROM subscriber_auth_attempts WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY outcome,result_code ORDER BY outcome,result_code"),
        'profiles'=>$group($pdo,"SELECT p.code,p.name,p.device_limit,COUNT(e.id) entitlements,COALESCE(SUM((SELECT COUNT(*) FROM subscriber_devices d WHERE d.account_id=e.account_id AND d.status='active')),0) active_devices FROM subscriber_benefit_profiles p LEFT JOIN subscriber_entitlements e ON e.benefit_profile_id=p.id GROUP BY p.id,p.code,p.name,p.device_limit ORDER BY p.device_limit"),
        'partners'=>$group($pdo,"SELECT p.id,p.name,COUNT(g.id) accesses,COUNT(DISTINCT g.device_id) devices,SUM(g.status='failed') failures FROM subscriber_access_grants g JOIN partners p ON p.id=g.partner_id WHERE g.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY p.id,p.name ORDER BY accesses DESC LIMIT 50"),
    ];
}

function fs_subscriber_admin_find_account(PDO $pdo, string $query): ?array
{
    $query=trim($query);if($query==='')return null;
    if(preg_match('/^[a-f0-9]{32}$/i',$query)){$st=$pdo->prepare('SELECT id FROM subscriber_accounts WHERE public_id=? LIMIT 1');$st->execute([strtolower($query)]);}else{$st=$pdo->prepare('SELECT account_id id FROM subscriber_external_links WHERE provider=\'hubsoft\' AND external_customer_id=? LIMIT 1');$st->execute([substr($query,0,128)]);}
    $accountId=(int)($st->fetchColumn()?:0);return$accountId>0?fs_subscriber_admin_account($pdo,$accountId):null;
}

function fs_subscriber_admin_account(PDO $pdo, int $accountId): ?array
{
    $st=$pdo->prepare('SELECT a.id,a.public_id,a.status,a.display_name,a.last_login_at,a.created_at,l.external_customer_id,l.verified_phone_hint,l.verified_email_hint,l.last_result_code,l.last_verified_at,e.status entitlement_status,e.result_code,e.verified_at,e.valid_until,e.grace_until,p.name profile_name,p.device_limit,p.concurrent_limit FROM subscriber_accounts a LEFT JOIN subscriber_external_links l ON l.account_id=a.id AND l.provider=\'hubsoft\' LEFT JOIN subscriber_entitlements e ON e.account_id=a.id LEFT JOIN subscriber_benefit_profiles p ON p.id=e.benefit_profile_id WHERE a.id=? LIMIT 1');$st->execute([$accountId]);$account=$st->fetch(PDO::FETCH_ASSOC);if(!$account)return null;
    $st=$pdo->prepare('SELECT id,public_id,label,device_kind,status,authorization_mode,authorized_at,expires_at,replace_available_at,last_seen_at,last_partner_id,last_hotspot_id,status_reason FROM subscriber_devices WHERE account_id=? ORDER BY (status=\'active\') DESC,updated_at DESC');$st->execute([$accountId]);$account['devices']=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    $st=$pdo->prepare('SELECT g.public_id,g.status,g.failure_code,g.activated_at,g.ended_at,g.created_at,p.name partner_name,h.name hotspot_name FROM subscriber_access_grants g JOIN partners p ON p.id=g.partner_id JOIN partner_hotspots h ON h.id=g.hotspot_id WHERE g.account_id=? ORDER BY g.id DESC LIMIT 30');$st->execute([$accountId]);$account['grants']=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    $st=$pdo->prepare('SELECT public_id,plan_name,amount_cents,status,created_at FROM guest_orders WHERE subscriber_account_id=? ORDER BY id DESC LIMIT 30');$st->execute([$accountId]);$account['orders']=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    return$account;
}

function fs_subscriber_admin_release_cooldown(PDO $pdo, int $accountId, int $deviceId, string $reason, int $actorId): void
{
    $reason=trim($reason);if(strlen($reason)<5)throw new InvalidArgumentException('Informe um motivo de atendimento com pelo menos 5 caracteres.');
    $st=$pdo->prepare("UPDATE subscriber_devices SET replace_available_at=NOW(),updated_at=NOW() WHERE id=? AND account_id=? AND status='revoked' AND replace_available_at>NOW()");$st->execute([$deviceId,$accountId]);if($st->rowCount()!==1)throw new RuntimeException('Cooldown ativo não encontrado.');
    fs_subscriber_audit($pdo,$accountId,'firespot',$actorId,'device.cooldown_released','device',$deviceId,['reason'=>substr($reason,0,180)]);
}

function fs_subscriber_admin_revoke_device(PDO $pdo, int $accountId, int $deviceId, string $reason, int $actorId): void
{
    $reason=trim($reason);if(strlen($reason)<5)throw new InvalidArgumentException('Informe o motivo da revogação.');
    fs_subscriber_device_revoke($pdo,$accountId,$deviceId);fs_subscriber_audit($pdo,$accountId,'firespot',$actorId,'device.revoked_by_support','device',$deviceId,['reason'=>substr($reason,0,180)]);
}
