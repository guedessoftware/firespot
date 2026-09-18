<?php

declare(strict_types=1);

require_once __DIR__ . '/personal_data_crypto.php';

function fs_subscriber_feature_labels(): array
{
    return [
        'subscriber_access_enabled'=>'Resolver benefícios HubSoft',
        'subscriber_account_enabled'=>'Liberar Minha Conta',
        'subscriber_invites_enabled'=>'Liberar convites e aparelhos',
        'subscriber_radius_enabled'=>'Liberar acesso incluído no RADIUS',
        'subscriber_authenticated_purchase_enabled'=>'Vincular compra com consentimento',
    ];
}

function fs_subscriber_feature_enabled(PDO $pdo, string $key, bool $default = false): bool
{
    if (!array_key_exists($key,fs_subscriber_feature_labels())) return false;
    try {$st=$pdo->prepare('SELECT svalue FROM app_settings WHERE skey=? LIMIT 1');$st->execute([$key]);$value=$st->fetchColumn();return$value===false?$default:filter_var($value,FILTER_VALIDATE_BOOLEAN);}catch(Throwable $e){return$default;}
}

function fs_subscriber_feature_set(PDO $pdo, string $key, bool $enabled): void
{
    if (!array_key_exists($key,fs_subscriber_feature_labels())) throw new InvalidArgumentException('Controle de rollout inválido.');
    $pdo->prepare('INSERT INTO app_settings (skey,svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue),updated_at=NOW()')->execute([$key,$enabled?'1':'0']);
}

/**
 * Mantém o rollout coerente ao ativar ou desligar recursos relacionados.
 *
 * Ao ativar uma etapa, seus pré-requisitos são incluídos. Ao desligar uma
 * etapa já ativa, todos os recursos que dependem dela também são desligados.
 */
function fs_subscriber_feature_normalize(array $current, array $requested): array
{
    $state=[];
    foreach(fs_subscriber_feature_labels() as $key=>$label){
        $state[$key]=!empty($requested[$key]);
        $current[$key]=!empty($current[$key]);
    }

    $accessDisabled=$current['subscriber_access_enabled']&&!$state['subscriber_access_enabled'];
    if($accessDisabled){
        foreach(array_keys($state) as $key)$state[$key]=false;
        return $state;
    }

    $accountDisabled=$current['subscriber_account_enabled']&&!$state['subscriber_account_enabled'];
    if($accountDisabled){
        $state['subscriber_account_enabled']=false;
        $state['subscriber_invites_enabled']=false;
        $state['subscriber_radius_enabled']=false;
        $state['subscriber_authenticated_purchase_enabled']=false;
        return $state;
    }

    $invitesDisabled=$current['subscriber_invites_enabled']&&!$state['subscriber_invites_enabled'];
    if($invitesDisabled)$state['subscriber_radius_enabled']=false;
    elseif($state['subscriber_radius_enabled'])$state['subscriber_invites_enabled']=true;

    if($state['subscriber_invites_enabled']||$state['subscriber_authenticated_purchase_enabled'])$state['subscriber_account_enabled']=true;
    if($state['subscriber_account_enabled'])$state['subscriber_access_enabled']=true;
    return $state;
}

function fs_subscriber_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM subscriber_accounts LIMIT 0');
        $pdo->query('SELECT id FROM subscriber_entitlements LIMIT 0');
        $pdo->query('SELECT id FROM subscriber_devices LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fs_subscriber_digits(string $value): string
{
    return (string)preg_replace('/\D+/', '', $value);
}

function fs_subscriber_document_valid(string $value): bool
{
    $digits=fs_subscriber_digits($value);
    if (!in_array(strlen($digits),[11,14],true) || preg_match('/^(\d)\1+$/',$digits)) return false;
    if (strlen($digits)===11) {
        for ($t=9;$t<11;$t++) {
            $sum=0;for($i=0;$i<$t;$i++)$sum+=(int)$digits[$i]*(($t+1)-$i);
            $digit=(10*($sum%11))%11;if($digit===10)$digit=0;
            if((int)$digits[$t]!==$digit)return false;
        }
        return true;
    }
    $weights=[[5,4,3,2,9,8,7,6,5,4,3,2],[6,5,4,3,2,9,8,7,6,5,4,3,2]];
    for($round=0;$round<2;$round++){$sum=0;foreach($weights[$round] as $i=>$weight)$sum+=(int)$digits[$i]*$weight;$digit=$sum%11;$digit=$digit<2?0:11-$digit;if((int)$digits[12+$round]!==$digit)return false;}
    return true;
}

function fs_subscriber_document_hash(string $document): string
{
    $digits=fs_subscriber_digits($document);
    if (!fs_subscriber_document_valid($digits)) throw new InvalidArgumentException('CPF ou CNPJ inválido.');
    return fs_personal_hash('document:' . $digits);
}

function fs_subscriber_origin_hash(): string
{
    return fs_personal_hash('origin:' . trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')));
}

function fs_subscriber_public_id(): string
{
    return bin2hex(random_bytes(16));
}

function fs_subscriber_bool($value): bool
{
    if (is_bool($value)) return $value;
    return in_array(strtolower(trim((string)$value)),['1','true','sim','yes','ativo','active','habilitado','serviço habilitado','servico habilitado'],true);
}

function fs_subscriber_audit(PDO $pdo, ?int $accountId, string $actorType, ?int $actorId, string $action, string $targetType, $targetId = null, array $metadata = []): void
{
    $actorType=in_array($actorType,['subscriber','firespot','system'],true)?$actorType:'system';
    $clean=[];
    foreach($metadata as $key=>$value){if(preg_match('/document|cpf|cnpj|phone|email|secret|token|password|contact|payload/i',(string)$key))continue;$clean[(string)$key]=is_scalar($value)||$value===null?$value:'[structured]';}
    $st=$pdo->prepare('INSERT INTO subscriber_audit (account_id,actor_type,actor_id,action,target_type,target_id,metadata,origin_hash) VALUES (?,?,?,?,?,?,?,?)');
    $st->execute([$accountId,$actorType,$actorId,substr($action,0,80),substr($targetType,0,64),$targetId===null?null:substr((string)$targetId,0,64),$clean?json_encode($clean,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,fs_subscriber_origin_hash()]);
}

function fs_subscriber_profile_by_code(PDO $pdo, string $code): ?array
{
    $st=$pdo->prepare('SELECT * FROM subscriber_benefit_profiles WHERE code=? AND active=1 LIMIT 1');$st->execute([$code]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function fs_subscriber_account(PDO $pdo, int $accountId, bool $forUpdate=false): ?array
{
    if($accountId<=0)return null;$st=$pdo->prepare('SELECT * FROM subscriber_accounts WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));$st->execute([$accountId]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function fs_subscriber_account_by_document_hash(PDO $pdo, string $hash, bool $forUpdate=false): ?array
{
    $st=$pdo->prepare('SELECT * FROM subscriber_accounts WHERE document_hash=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));$st->execute([$hash]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function fs_subscriber_entitlement(PDO $pdo, int $accountId, bool $forUpdate=false): ?array
{
    $st=$pdo->prepare('SELECT e.*,p.code profile_code,p.name profile_name,p.device_limit,p.concurrent_limit,p.unlimited_time,p.revalidation_minutes,p.download_kbps profile_download_kbps,p.upload_kbps profile_upload_kbps,m.download_kbps mapping_download_kbps,m.upload_kbps mapping_upload_kbps FROM subscriber_entitlements e JOIN subscriber_benefit_profiles p ON p.id=e.benefit_profile_id LEFT JOIN subscriber_plan_mappings m ON m.id=e.plan_mapping_id WHERE e.account_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));$st->execute([$accountId]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

/** O mapeamento substitui o perfil; o perfil impede acesso ilimitado sem mapeamento. */
function fs_subscriber_effective_rate_limit(array $entitlement): array
{
    $profileDown=(int)($entitlement['profile_download_kbps']??0);$profileUp=(int)($entitlement['profile_upload_kbps']??0);
    $mappingDown=(int)($entitlement['mapping_download_kbps']??0);$mappingUp=(int)($entitlement['mapping_upload_kbps']??0);
    $down=$mappingDown>0?$mappingDown:$profileDown;$up=$mappingUp>0?$mappingUp:$profileUp;
    if($down<=0||$up<=0)throw new RuntimeException('O benefício FIRENETWORK está sem limite de velocidade válido.');
    return ['download_kbps'=>$down,'upload_kbps'=>$up,'source'=>$mappingDown>0&&$mappingUp>0?'mapping':'profile'];
}

function fs_subscriber_entitlement_usable(?array $entitlement, ?int $now=null): bool
{
    if(!$entitlement)return false;$now=$now??time();$status=(string)$entitlement['status'];
    if($status==='active')return empty($entitlement['valid_until'])||strtotime((string)$entitlement['valid_until'])>$now;
    return $status==='grace'&&!empty($entitlement['grace_until'])&&strtotime((string)$entitlement['grace_until'])>$now;
}

/** Resolve o único provedor implementado sem acoplar o domínio ao ERP. */
function fs_subscriber_provider_lookup(string $provider, string $document, ?callable $fetcher=null): array
{
    if($provider!=='hubsoft')throw new InvalidArgumentException('Provedor de assinantes não suportado.');
    require_once __DIR__ . '/subscriber_hubsoft.php';
    return fs_subscriber_hubsoft_lookup($document,$fetcher);
}

/** Valida a credencial do assinante no ERP sem trazê-la para o domínio local. */
function fs_subscriber_provider_authenticate(string $provider, string $username, string $password, ?callable $requester=null): array
{
    if($provider!=='hubsoft')throw new InvalidArgumentException('Provedor de assinantes não suportado.');
    require_once __DIR__ . '/subscriber_hubsoft.php';
    return fs_subscriber_hubsoft_authenticate($username,$password,$requester);
}

function fs_subscriber_plan_mapping(PDO $pdo, array $candidates): ?array
{
    if(!$candidates)return null;
    $best=null;
    foreach($candidates as $candidate){
        $kind=(string)($candidate['kind']??'');$id=(string)($candidate['id']??'');if(!in_array($kind,['plan','service','package'],true)||$id==='')continue;
        $st=$pdo->prepare('SELECT m.*,p.code profile_code,p.device_limit,p.concurrent_limit,COALESCE(NULLIF(m.download_kbps,0),p.download_kbps) effective_download_kbps,COALESCE(NULLIF(m.upload_kbps,0),p.upload_kbps) effective_upload_kbps FROM subscriber_plan_mappings m JOIN subscriber_benefit_profiles p ON p.id=m.benefit_profile_id WHERE m.provider=\'hubsoft\' AND m.external_kind=? AND m.external_id=? AND m.active=1 AND m.eligible_internet=1 AND p.active=1 LIMIT 1');
        $st->execute([$kind,$id]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if($row){$score=[(int)$row['device_limit'],(int)$row['concurrent_limit'],(int)$row['effective_download_kbps'],(int)$row['effective_upload_kbps']];$bestScore=$best?[(int)$best['device_limit'],(int)$best['concurrent_limit'],(int)$best['effective_download_kbps'],(int)$best['effective_upload_kbps']]:null;if($bestScore===null||$score>$bestScore)$best=$row;}
    }
    return $best;
}

/**
 * Materializa suspensão, upgrade e downgrade no domínio local. O titular
 * preserva primeiro o aparelho principal e depois as autorizações mais antigas.
 * Convites são reservas subordinadas ao limite restante.
 */
function fs_subscriber_reconcile_devices(PDO $pdo, int $accountId, bool $eligible, int $deviceLimit): array
{
    $deviceLimit=max(0,$deviceLimit);$revokedUsernames=[];
    $st=$pdo->prepare("SELECT id,device_kind,status FROM subscriber_devices WHERE account_id=? AND status IN ('active','over_limit','suspended') AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY (device_kind='primary') DESC,authorized_at,id FOR UPDATE");$st->execute([$accountId]);$devices=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    $keep=$eligible?array_slice($devices,0,$deviceLimit):[];$excess=$eligible?array_slice($devices,$deviceLimit):$devices;$keepIds=array_map('intval',array_column($keep,'id'));$excessIds=array_map('intval',array_column($excess,'id'));
    if($keepIds){$in=implode(',',array_fill(0,count($keepIds),'?'));$pdo->prepare("UPDATE subscriber_devices SET status='active',status_reason=NULL,updated_at=NOW() WHERE id IN ($in)")->execute($keepIds);$pdo->prepare("UPDATE subscriber_device_identifiers SET active=1,updated_at=NOW() WHERE device_id IN ($in)")->execute($keepIds);}
    if($excessIds){$in=implode(',',array_fill(0,count($excessIds),'?'));$nextStatus=$eligible?'over_limit':'suspended';$reason=$eligible?'ENTITLEMENT_DOWNGRADE':'ENTITLEMENT_SUSPENDED';$pdo->prepare("UPDATE subscriber_devices SET status=?,status_reason=?,updated_at=NOW() WHERE id IN ($in)")->execute(array_merge([$nextStatus,$reason],$excessIds));$pdo->prepare("UPDATE subscriber_device_identifiers SET active=0,updated_at=NOW() WHERE device_id IN ($in)")->execute($excessIds);$st=$pdo->prepare("SELECT radius_username FROM subscriber_access_grants WHERE device_id IN ($in) AND status IN ('reserved','provisioning','active') AND radius_username IS NOT NULL");$st->execute($excessIds);$revokedUsernames=array_values(array_filter(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN)?:[])));$pdo->prepare("UPDATE subscriber_access_grants SET status='revoked',failure_code=?,ended_at=COALESCE(ended_at,NOW()),updated_at=NOW() WHERE device_id IN ($in) AND status IN ('reserved','provisioning','active')")->execute(array_merge([$reason],$excessIds));}
    $available=max(0,$deviceLimit-count($keepIds));$st=$pdo->prepare("SELECT id FROM subscriber_device_invites WHERE account_id=? AND status='created' AND expires_at>NOW() ORDER BY created_at,id FOR UPDATE");$st->execute([$accountId]);$invites=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]);$expire=array_slice($invites,$available);
    if(!$eligible)$expire=$invites;
    if($expire){$in=implode(',',array_fill(0,count($expire),'?'));$pdo->prepare("UPDATE subscriber_device_invites SET status='revoked',token_hash=SHA2(CONCAT(token_hash,':entitlement'),256),human_code_hash=SHA2(CONCAT(human_code_hash,':entitlement'),256),updated_at=NOW() WHERE id IN ($in) AND status='created'")->execute($expire);}
    if($excessIds||$expire)fs_subscriber_audit($pdo,$accountId,'system',null,$eligible?'entitlement.limit_reconciled':'entitlement.access_suspended','account',$accountId,['device_limit'=>$deviceLimit,'devices_affected'=>count($excessIds),'invites_affected'=>count($expire)]);
    return ['active_devices'=>count($keepIds),'affected_devices'=>count($excessIds),'revoked_radius_usernames'=>$revokedUsernames];
}

function fs_subscriber_sync_document(PDO $pdo, string $document, array $options=[]): array
{
    if(!fs_subscriber_schema_ready($pdo))throw new RuntimeException('O domínio de assinantes ainda não foi instalado.');
    $digits=fs_subscriber_digits($document);$hash=fs_subscriber_document_hash($digits);
    $provider=(string)($options['provider']??'hubsoft');$fetcher=$options['fetcher']??null;$now=time();
    try{$lookup=fs_subscriber_provider_lookup($provider,$digits,is_callable($fetcher)?$fetcher:null);}catch(Throwable $e){
        $existing=fs_subscriber_account_by_document_hash($pdo,$hash);$ent=$existing?fs_subscriber_entitlement($pdo,(int)$existing['id']):null;
        if($existing&&$ent&&in_array((string)$ent['status'],['active','grace'],true)&&!empty($ent['grace_until'])&&strtotime((string)$ent['grace_until'])>$now){
            $pdo->prepare("UPDATE subscriber_entitlements SET status='grace',result_code='PROVIDER_UNAVAILABLE_CACHE',updated_at=NOW() WHERE account_id=?")->execute([(int)$existing['id']]);
            fs_subscriber_audit($pdo,(int)$existing['id'],'system',null,'entitlement.grace_used','entitlement',(int)$ent['id'],['provider'=>$provider]);
            return ['ok'=>true,'account'=>$existing,'entitlement'=>fs_subscriber_entitlement($pdo,(int)$existing['id']),'contact'=>null,'result_code'=>'PROVIDER_UNAVAILABLE_CACHE','cached'=>true];
        }
        error_log('[subscriber provider] '.get_class($e).': '.$e->getMessage());
        return ['ok'=>false,'result_code'=>'PROVIDER_UNAVAILABLE','message'=>'Não foi possível confirmar o benefício agora. Tente novamente.'];
    }
    if(empty($lookup['found']))return ['ok'=>false,'result_code'=>'NOT_FOUND','message'=>'Não foi possível confirmar os dados informados.'];
    if(empty($lookup['customer_id']))return ['ok'=>false,'result_code'=>'MISSING_CUSTOMER_ID','message'=>'O cadastro precisa ser revisado pela FIRENETWORK.'];
    $linked=$pdo->prepare('SELECT account_id,document_hash FROM subscriber_external_links WHERE provider=? AND external_customer_id=? LIMIT 1');
    $linked->execute([$provider,(string)$lookup['customer_id']]);$linked=$linked->fetch(PDO::FETCH_ASSOC);
    if($linked&&!hash_equals((string)$linked['document_hash'],$hash)){
        error_log('[subscriber provider] external customer document mismatch provider='.$provider);
        return ['ok'=>false,'result_code'=>'IDENTITY_MISMATCH','message'=>'O cadastro precisa ser revisado pela FIRENETWORK.'];
    }

    $mapping=fs_subscriber_plan_mapping($pdo,(array)($lookup['mapping_candidates']??[]));
    $profile=$mapping?fs_subscriber_profile_by_code($pdo,(string)$mapping['profile_code']):null;
    $resultCode='MAPPED';
    if(!$profile&&!empty($lookup['eligible_basic'])){$profile=fs_subscriber_profile_by_code($pdo,'firenetwork_basic');$resultCode='DEFAULT_BASIC_UNMAPPED';}
    if(!$profile)$profile=fs_subscriber_profile_by_code($pdo,'firenetwork_basic');
    if(!$profile)throw new RuntimeException('Perfil básico FIRENETWORK ausente.');
    $eligible=!empty($lookup['customer_active'])&&(!empty($lookup['internet_active'])||$mapping!==null);
    $status=$eligible?'active':'suspended';
    if(!$eligible)$resultCode=empty($lookup['customer_active'])?'CUSTOMER_INACTIVE':(!empty($lookup['mapping_candidates'])?'SERVICE_MAPPING_REQUIRED':'SERVICE_INACTIVE');
    $validUntil=date('Y-m-d H:i:s',$now+max(5,(int)($profile['revalidation_minutes']??480))*60);
    $graceUntil=date('Y-m-d H:i:s',$now+max(60,(int)($options['grace_minutes']??1440))*60);
    $phone=trim((string)($lookup['phone']??''));$email=strtolower(trim((string)($lookup['email']??'')));
    $displayName=trim((string)($lookup['display_name']??''));
    $snapshotCandidates=array_values(array_map(static function(array $candidate):array{
        $evidence=(string)($candidate['evidence']??'');if(!in_array($evidence,['service_text','network_auth'],true))$evidence='';
        return [
            'kind'=>(string)($candidate['kind']??''),'id'=>(string)($candidate['id']??''),
            'label'=>substr(trim((string)($candidate['label']??'')),0,180),
            'internet_detected'=>!empty($candidate['internet_detected']),'evidence'=>$evidence,
        ];
    },(array)($lookup['mapping_candidates']??[])));
    $snapshot=json_encode(['provider'=>$provider,'customer_id'=>(string)$lookup['customer_id'],'customer_active'=>(bool)$lookup['customer_active'],'internet_active'=>(bool)$lookup['internet_active'],'active_service_count'=>(int)($lookup['active_service_count']??0),'mapping_candidates'=>$snapshotCandidates,'result_code'=>$resultCode],JSON_UNESCAPED_SLASHES);
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $account=fs_subscriber_account_by_document_hash($pdo,$hash,true);
        if(!$account){$st=$pdo->prepare('INSERT INTO subscriber_accounts (public_id,status,display_name,document_hash) VALUES (?,\'active\',?,?)');$st->execute([fs_subscriber_public_id(),$displayName!==''?substr($displayName,0,150):null,$hash]);$account=fs_subscriber_account($pdo,(int)$pdo->lastInsertId(),true);}
        if(!$account)throw new RuntimeException('Falha ao criar a conta FIRENETWORK.');
        $accountId=(int)$account['id'];
        $link=$pdo->prepare('INSERT INTO subscriber_external_links (account_id,provider,external_customer_id,document_hash,document_encrypted,verified_phone_encrypted,verified_phone_hint,verified_email_encrypted,verified_email_hint,status,last_result_code,last_verified_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),document_hash=VALUES(document_hash),document_encrypted=VALUES(document_encrypted),verified_phone_encrypted=VALUES(verified_phone_encrypted),verified_phone_hint=VALUES(verified_phone_hint),verified_email_encrypted=VALUES(verified_email_encrypted),verified_email_hint=VALUES(verified_email_hint),status=VALUES(status),last_result_code=VALUES(last_result_code),last_verified_at=NOW(),updated_at=NOW()');
        $link->execute([$accountId,$provider,substr((string)$lookup['customer_id'],0,128),$hash,fs_personal_encrypt($digits),$phone!==''?fs_personal_encrypt($phone):null,$phone!==''?fs_personal_mask_phone($phone):null,$email!==''?fs_personal_encrypt($email):null,$email!==''?preg_replace('/(^.).*(@.*$)/','$1•••$2',$email):null,$eligible?'active':'suspended',$resultCode]);
        $mappingId=$mapping?(int)$mapping['id']:null;
        $st=$pdo->prepare('INSERT INTO subscriber_entitlements (account_id,benefit_profile_id,plan_mapping_id,provider,external_service_id,external_plan_id,status,result_code,source_snapshot,verified_at,valid_until,grace_until,revision) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?,?,1) ON DUPLICATE KEY UPDATE benefit_profile_id=VALUES(benefit_profile_id),plan_mapping_id=VALUES(plan_mapping_id),external_service_id=VALUES(external_service_id),external_plan_id=VALUES(external_plan_id),status=VALUES(status),result_code=VALUES(result_code),source_snapshot=VALUES(source_snapshot),verified_at=NOW(),valid_until=VALUES(valid_until),grace_until=VALUES(grace_until),revision=revision+1,updated_at=NOW()');
        $st->execute([$accountId,(int)$profile['id'],$mappingId,$provider,$lookup['primary_service_id']??null,$lookup['primary_plan_id']??null,$status,$resultCode,$snapshot,$validUntil,$graceUntil]);
        $pdo->prepare('UPDATE subscriber_accounts SET status=?,display_name=COALESCE(NULLIF(?,\'\'),display_name),updated_at=NOW() WHERE id=?')->execute([$eligible?'active':'suspended',$displayName,$accountId]);
        $deviceReconciliation=fs_subscriber_reconcile_devices($pdo,$accountId,$eligible,(int)$profile['device_limit']);
        $ent=fs_subscriber_entitlement($pdo,$accountId,true);
        fs_subscriber_audit($pdo,$accountId,'system',null,'entitlement.synced','entitlement',(int)($ent['id']??0),['provider'=>$provider,'result_code'=>$resultCode,'profile'=>(string)$profile['code'],'status'=>$status]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    if($ownsTransaction&&!empty($deviceReconciliation['revoked_radius_usernames'])&&function_exists('fs_radius_db')&&function_exists('fs_subscriber_radius_delete')){try{$radius=fs_radius_db();foreach($deviceReconciliation['revoked_radius_usernames'] as $username){fs_subscriber_radius_delete($radius,$username);$pdo->prepare('UPDATE subscriber_access_grants SET radius_cleaned_at=NOW(),updated_at=NOW() WHERE radius_username=? AND status=\'revoked\'')->execute([$username]);}}catch(Throwable $cleanupError){error_log('[subscriber entitlement radius cleanup] '.$cleanupError->getMessage());}}
    $contact=null;if($phone!=='')$contact=['type'=>'phone','value'=>$phone,'hint'=>fs_personal_mask_phone($phone)];elseif($email!=='')$contact=['type'=>'email','value'=>$email,'hint'=>preg_replace('/(^.).*(@.*$)/','$1•••$2',$email)];
    return ['ok'=>$eligible,'account'=>fs_subscriber_account($pdo,$accountId),'entitlement'=>fs_subscriber_entitlement($pdo,$accountId),'contact'=>$contact,'result_code'=>$resultCode,'cached'=>false,'message'=>$eligible?'Benefício FIRENETWORK confirmado.':'Este contrato não possui benefício ativo neste momento.'];
}

function fs_subscriber_refresh_account(PDO $pdo, int $accountId, array $options=[]): array
{
    $st=$pdo->prepare('SELECT provider,document_encrypted FROM subscriber_external_links WHERE account_id=? AND status<>\'revoked\' LIMIT 1');$st->execute([$accountId]);$link=$st->fetch(PDO::FETCH_ASSOC);
    if(!$link||empty($link['document_encrypted']))return ['ok'=>false,'result_code'=>'REAUTH_REQUIRED','message'=>'Entre novamente para atualizar o benefício.'];
    try{$document=fs_personal_decrypt((string)$link['document_encrypted']);}catch(Throwable $e){return ['ok'=>false,'result_code'=>'REAUTH_REQUIRED','message'=>'Entre novamente para atualizar o benefício.'];}
    $options['provider']=(string)$link['provider'];return fs_subscriber_sync_document($pdo,$document,$options);
}
