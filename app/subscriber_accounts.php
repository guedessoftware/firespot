<?php

declare(strict_types=1);

require_once __DIR__ . '/session_boot.php';
require_once __DIR__ . '/subscriber_provider.php';

function fs_subscriber_otp_hash(string $challengePublicId, string $code): string
{
    return fs_personal_hash('otp:' . $challengePublicId . ':' . $code);
}

function fs_subscriber_cookie_name(): string
{
    return 'FSACCOUNT';
}

function fs_subscriber_set_cookie(string $value, int $expires): void
{
    $secure=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
    if(!headers_sent())setcookie(fs_subscriber_cookie_name(),$value,['expires'=>$expires,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    if($value==='')unset($_COOKIE[fs_subscriber_cookie_name()]);else $_COOKIE[fs_subscriber_cookie_name()]=$value;
}

function fs_subscriber_clear_cookie(): void
{
    fs_subscriber_set_cookie('',time()-3600);
}

function fs_subscriber_rate_allowed(PDO $pdo, string $documentHash, string $originHash): bool
{
    $st=$pdo->prepare("SELECT COUNT(*) FROM subscriber_login_challenges WHERE document_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)");$st->execute([$documentHash]);if((int)$st->fetchColumn()>=3)return false;
    $st=$pdo->prepare("SELECT COUNT(*) FROM subscriber_login_challenges WHERE origin_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)");$st->execute([$originHash]);return (int)$st->fetchColumn()<8;
}

function fs_subscriber_password_schema_ready(PDO $pdo): bool
{
    try{$pdo->query('SELECT id FROM subscriber_auth_attempts LIMIT 0');return true;}catch(Throwable $e){return false;}
}

function fs_subscriber_password_rate_allowed(PDO $pdo, string $documentHash, string $originHash): bool
{
    if(!fs_subscriber_password_schema_ready($pdo))throw new RuntimeException('A autenticação por senha ainda não foi instalada.');
    $codes=['INVALID_CREDENTIALS','IDENTITY_MISMATCH'];$in=implode(',',array_fill(0,count($codes),'?'));
    $st=$pdo->prepare("SELECT COUNT(*) FROM subscriber_auth_attempts WHERE document_hash=? AND outcome='denied' AND result_code IN ($in) AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)");
    $st->execute(array_merge([$documentHash],$codes));if((int)$st->fetchColumn()>=5)return false;
    $st=$pdo->prepare("SELECT COUNT(*) FROM subscriber_auth_attempts WHERE origin_hash=? AND outcome='denied' AND result_code IN ($in) AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)");
    $st->execute(array_merge([$originHash],$codes));return (int)$st->fetchColumn()<20;
}

function fs_subscriber_password_attempt(PDO $pdo, string $documentHash, string $originHash, string $outcome, string $resultCode, ?int $accountId=null): void
{
    if(!in_array($outcome,['success','denied','error','blocked'],true))$outcome='error';
    $st=$pdo->prepare("INSERT INTO subscriber_auth_attempts (account_id,provider,auth_method,document_hash,origin_hash,outcome,result_code) VALUES (?,'hubsoft','hubsoft_password',?,?,?,?)");
    $st->execute([$accountId,$documentHash,$originHash,$outcome,substr($resultCode,0,48)]);
}

/**
 * Cria um desafio sem revelar se o documento existe. O OTP real é enviado
 * somente ao telefone confirmado pelo HubSoft e permanece criptografado até
 * o worker consumi-lo.
 */
function fs_subscriber_login_start(PDO $pdo, string $document, array $options=[]): array
{
    if(!fs_subscriber_feature_enabled($pdo,'subscriber_access_enabled',false))throw new RuntimeException('O benefício FIRENETWORK ainda não foi liberado.');
    $digits=fs_subscriber_digits($document);$documentHash=fs_subscriber_document_hash($digits);$originHash=fs_subscriber_origin_hash();
    if(!fs_subscriber_rate_allowed($pdo,$documentHash,$originHash))throw new RuntimeException('Muitas tentativas. Aguarde alguns minutos antes de tentar novamente.');
    $sync=fs_subscriber_sync_document($pdo,$digits,$options);
    $real=!empty($sync['ok'])&&!empty($sync['account'])&&is_array($sync['contact']??null)&&($sync['contact']['type']??'')==='phone';
    $accountId=$real?(int)$sync['account']['id']:null;$target=$real?(string)$sync['contact']['value']:'unavailable';$hint=$real?(string)$sync['contact']['hint']:'contato cadastrado';
    $publicId=fs_subscriber_public_id();$code=(string)random_int(100000,999999);$expiresMinutes=max(3,min(10,(int)($options['expires_minutes']??5)));
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE subscriber_login_challenges SET status='expired',updated_at=NOW() WHERE document_hash=? AND status='pending'")->execute([$documentHash]);
        $st=$pdo->prepare("INSERT INTO subscriber_login_challenges (public_id,account_id,provider,document_hash,contact_type,contact_hint,target_encrypted,code_hash,status,max_attempts,expires_at,origin_hash) VALUES (? ,?,'hubsoft',?,'phone',?,?,?,'pending',5,DATE_ADD(NOW(),INTERVAL ? MINUTE),?)");
        $st->execute([$publicId,$accountId,$documentHash,$hint,fs_personal_encrypt($target),fs_subscriber_otp_hash($publicId,$code),$expiresMinutes,$originHash]);$challengeId=(int)$pdo->lastInsertId();
        if($real){
            $message='Seu código de acesso à Minha Conta FIRENETWORK é '.$code.'. Ele expira em '.$expiresMinutes.' minutos. Não compartilhe.';
            $payload=fs_personal_encrypt(json_encode(['to'=>$target,'message'=>$message],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            $idem=hash('sha256','subscriber_otp:'.$publicId);
            $queue=$pdo->prepare("INSERT INTO promo_queue (to_msisdn,msg,purpose,reference_type,reference_id,idempotency_key,status,attempts,max_attempts,payload_encrypted,scheduled_at,created_at,updated_at) VALUES ('ENCRYPTED','ENCRYPTED','subscriber_otp','login_challenge',?,?, 'pending',0,5,?,NOW(),NOW(),NOW())");
            $queue->execute([$challengeId,$idem,$payload]);$queueId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE subscriber_login_challenges SET queue_id=? WHERE id=?')->execute([$queueId,$challengeId]);
        }
        if($accountId)fs_subscriber_audit($pdo,$accountId,'subscriber',$accountId,'login.challenge_created','login_challenge',$challengeId,['channel'=>'phone','result_code'=>(string)($sync['result_code']??'UNKNOWN')]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    // Habilitado somente em teste automatizado explícito; nunca por querystring.
    $result=['ok'=>true,'challenge'=>$publicId,'contact_hint'=>$hint,'expires_in'=>$expiresMinutes*60,'message'=>'Se os dados estiverem elegíveis, o código será enviado ao contato cadastrado.'];
    if(!empty($options['test_return_code']))$result['_test_code']=$code;
    return $result;
}

function fs_subscriber_login_verify(PDO $pdo, string $publicId, string $code, bool $remember=true): array
{
    $publicId=strtolower(trim($publicId));$code=fs_subscriber_digits($code);
    if(!preg_match('/^[a-f0-9]{32}$/',$publicId)||!preg_match('/^\d{6}$/',$code))throw new RuntimeException('Código inválido ou expirado.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare('SELECT * FROM subscriber_login_challenges WHERE public_id=? LIMIT 1 FOR UPDATE');$st->execute([$publicId]);$challenge=$st->fetch(PDO::FETCH_ASSOC);
        if(!$challenge||$challenge['status']!=='pending'||strtotime((string)$challenge['expires_at'])<=time()||empty($challenge['account_id']))throw new RuntimeException('Código inválido ou expirado.');
        if((int)$challenge['attempts']>=(int)$challenge['max_attempts'])throw new RuntimeException('Código inválido ou expirado.');
        if(!hash_equals((string)$challenge['code_hash'],fs_subscriber_otp_hash($publicId,$code))){
            $attempts=(int)$challenge['attempts']+1;$status=$attempts>=(int)$challenge['max_attempts']?'blocked':'pending';$pdo->prepare('UPDATE subscriber_login_challenges SET attempts=?,status=?,updated_at=NOW() WHERE id=?')->execute([$attempts,$status,(int)$challenge['id']]);if($ownsTransaction)$pdo->commit();throw new RuntimeException('Código inválido ou expirado.');
        }
        $account=fs_subscriber_account($pdo,(int)$challenge['account_id'],true);
        $ent=fs_subscriber_entitlement($pdo,(int)$challenge['account_id'],true);
        if(!$account||$account['status']!=='active'||!fs_subscriber_entitlement_usable($ent))throw new RuntimeException('O benefício não está ativo neste momento.');
        $pdo->prepare("UPDATE subscriber_login_challenges SET status='verified',verified_at=NOW(),target_encrypted='PURGED',code_hash=SHA2(CONCAT(code_hash,':used'),256),updated_at=NOW() WHERE id=?")->execute([(int)$challenge['id']]);
        $pdo->prepare('UPDATE subscriber_accounts SET last_login_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$account['id']]);
        fs_subscriber_audit($pdo,(int)$account['id'],'subscriber',(int)$account['id'],'login.verified','account',(int)$account['id']);
        $trustedToken=null;
        if($remember){$trustedToken=bin2hex(random_bytes(32));$uaHash=fs_personal_hash('ua:'.substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500));$pdo->prepare('INSERT INTO subscriber_trusted_devices (account_id,token_hash,user_agent_hash,expires_at,last_used_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 30 DAY),NOW())')->execute([(int)$account['id'],fs_personal_hash('trusted:'.$trustedToken),$uaHash]);}
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    session_regenerate_id(true);$_SESSION['subscriber_account_id']=(int)$account['id'];$_SESSION['subscriber_session_version']=(int)$account['session_version'];$_SESSION['subscriber_authenticated_at']=time();$_SESSION['subscriber_auth_method']='otp';
    if($trustedToken!==null)fs_subscriber_set_cookie($trustedToken,time()+30*86400);
    return ['ok'=>true,'account'=>$account,'entitlement'=>$ent];
}

/**
 * Segundo método da Minha Conta. A senha é encaminhada somente ao endpoint de
 * autenticação do HubSoft e deixa de existir no fluxo assim que a decisão é
 * recebida; banco, sessão, cookie, auditoria e logs guardam apenas o método e o
 * resultado operacional.
 */
function fs_subscriber_login_password(PDO $pdo, string $document, string $password, bool $remember=true, array $options=[]): array
{
    if(!fs_subscriber_feature_enabled($pdo,'subscriber_access_enabled',false)||!fs_subscriber_feature_enabled($pdo,'subscriber_account_enabled',false))throw new RuntimeException('A Minha Conta ainda não foi liberada.');
    $digits=fs_subscriber_digits($document);$documentHash=fs_subscriber_document_hash($digits);$originHash=fs_subscriber_origin_hash();
    if(strlen($password)<1||strlen($password)>255)throw new RuntimeException('CPF/CNPJ ou senha não conferem.');
    if(!fs_subscriber_password_rate_allowed($pdo,$documentHash,$originHash)){
        fs_subscriber_password_attempt($pdo,$documentHash,$originHash,'blocked','RATE_LIMITED');
        throw new RuntimeException('Muitas tentativas. Aguarde 15 minutos antes de tentar novamente.');
    }

    $authenticator=$options['authenticator']??null;
    try{$authentication=fs_subscriber_provider_authenticate('hubsoft',$digits,$password,is_callable($authenticator)?$authenticator:null);}
    catch(Throwable $e){
        fs_subscriber_password_attempt($pdo,$documentHash,$originHash,'error','PROVIDER_UNAVAILABLE');
        error_log('[subscriber password auth] provider_unavailable='.get_class($e));
        throw new RuntimeException('Não foi possível validar a senha no HubSoft agora. Tente novamente.');
    }finally{unset($password);}

    if(empty($authentication['authenticated'])){
        $resultCode=(string)($authentication['result_code']??'INVALID_CREDENTIALS');
        if(in_array($resultCode,['REMOTE_FORBIDDEN','REMOTE_LOCKED','REMOTE_RATE_LIMITED'],true)){
            fs_subscriber_password_attempt($pdo,$documentHash,$originHash,'blocked',$resultCode);
            throw new RuntimeException('O HubSoft bloqueou temporariamente novas tentativas. Aguarde alguns minutos ou use o código por SMS.');
        }
        $resultCode=in_array($resultCode,['INVALID_CREDENTIALS','IDENTITY_MISMATCH'],true)?$resultCode:'INVALID_CREDENTIALS';
        fs_subscriber_password_attempt($pdo,$documentHash,$originHash,'denied',$resultCode);
        throw new RuntimeException('CPF/CNPJ ou senha não conferem.');
    }

    $syncOptions=[];foreach(['provider','fetcher','grace_minutes'] as $key)if(array_key_exists($key,$options))$syncOptions[$key]=$options[$key];
    try{$sync=fs_subscriber_sync_document($pdo,$digits,$syncOptions);}catch(Throwable $e){
        fs_subscriber_password_attempt($pdo,$documentHash,$originHash,'error','BENEFIT_RESOLUTION_ERROR');
        error_log('[subscriber password auth] benefit_resolution_error='.get_class($e));
        throw new RuntimeException('A senha foi validada, mas não foi possível consultar o benefício agora. Tente novamente.');
    }
    $account=is_array($sync['account']??null)?$sync['account']:null;$accountId=$account?(int)$account['id']:null;
    if(empty($sync['ok'])||!$account){
        fs_subscriber_password_attempt($pdo,$documentHash,$originHash,'denied',(string)($sync['result_code']??'BENEFIT_UNAVAILABLE'),$accountId);
        throw new RuntimeException((string)($sync['message']??'Este contrato não possui benefício ativo neste momento.'));
    }

    $authenticatedCustomerId=trim((string)($authentication['customer_id']??''));
    if($authenticatedCustomerId!==''){
        $st=$pdo->prepare("SELECT external_customer_id FROM subscriber_external_links WHERE account_id=? AND provider='hubsoft' AND status<>'revoked' LIMIT 1");$st->execute([(int)$account['id']]);$linkedCustomerId=trim((string)$st->fetchColumn());
        if($linkedCustomerId===''||!hash_equals($linkedCustomerId,$authenticatedCustomerId)){
            fs_subscriber_password_attempt($pdo,$documentHash,$originHash,'denied','IDENTITY_MISMATCH',(int)$account['id']);
            error_log('[subscriber password auth] authenticated customer mismatch');
            throw new RuntimeException('Não foi possível confirmar a identidade desta conta.');
        }
    }

    $ent=fs_subscriber_entitlement($pdo,(int)$account['id']);
    if($account['status']!=='active'||!fs_subscriber_entitlement_usable($ent)){
        fs_subscriber_password_attempt($pdo,$documentHash,$originHash,'denied','BENEFIT_INACTIVE',(int)$account['id']);
        throw new RuntimeException('O benefício não está ativo neste momento.');
    }

    $trustedToken=null;$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $pdo->prepare('UPDATE subscriber_accounts SET last_login_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$account['id']]);
        if($remember){$trustedToken=bin2hex(random_bytes(32));$uaHash=fs_personal_hash('ua:'.substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500));$pdo->prepare('INSERT INTO subscriber_trusted_devices (account_id,token_hash,user_agent_hash,expires_at,last_used_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 30 DAY),NOW())')->execute([(int)$account['id'],fs_personal_hash('trusted:'.$trustedToken),$uaHash]);}
        fs_subscriber_password_attempt($pdo,$documentHash,$originHash,'success','AUTHENTICATED',(int)$account['id']);
        fs_subscriber_audit($pdo,(int)$account['id'],'subscriber',(int)$account['id'],'login.verified','account',(int)$account['id'],['method'=>'hubsoft_password']);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}

    session_regenerate_id(true);$_SESSION['subscriber_account_id']=(int)$account['id'];$_SESSION['subscriber_session_version']=(int)$account['session_version'];$_SESSION['subscriber_authenticated_at']=time();$_SESSION['subscriber_auth_method']='hubsoft_password';
    if($trustedToken!==null)fs_subscriber_set_cookie($trustedToken,time()+30*86400);
    return ['ok'=>true,'account'=>$account,'entitlement'=>$ent,'auth_method'=>'hubsoft_password'];
}

function fs_subscriber_current(PDO $pdo): ?array
{
    $accountId=(int)($_SESSION['subscriber_account_id']??0);
    if($accountId>0){$account=fs_subscriber_account($pdo,$accountId);if($account&&$account['status']==='active'&&(int)$account['session_version']===(int)($_SESSION['subscriber_session_version']??0))return $account;unset($_SESSION['subscriber_account_id'],$_SESSION['subscriber_session_version'],$_SESSION['subscriber_authenticated_at'],$_SESSION['subscriber_auth_method']);}
    $raw=trim((string)($_COOKIE[fs_subscriber_cookie_name()]??''));if(!preg_match('/^[a-f0-9]{64}$/',$raw))return null;
    $hash=fs_personal_hash('trusted:'.$raw);$uaHash=fs_personal_hash('ua:'.substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500));
    $st=$pdo->prepare('SELECT t.*,a.status account_status,a.session_version FROM subscriber_trusted_devices t JOIN subscriber_accounts a ON a.id=t.account_id WHERE t.token_hash=? AND t.revoked_at IS NULL AND t.expires_at>NOW() LIMIT 1');$st->execute([$hash]);$trusted=$st->fetch(PDO::FETCH_ASSOC);
    if(!$trusted||$trusted['account_status']!=='active'||(!empty($trusted['user_agent_hash'])&&!hash_equals((string)$trusted['user_agent_hash'],$uaHash))){fs_subscriber_clear_cookie();return null;}
    $pdo->prepare('UPDATE subscriber_trusted_devices SET last_used_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$trusted['id']]);
    $account=fs_subscriber_account($pdo,(int)$trusted['account_id']);if(!$account)return null;
    session_regenerate_id(true);$_SESSION['subscriber_account_id']=(int)$account['id'];$_SESSION['subscriber_session_version']=(int)$account['session_version'];$_SESSION['subscriber_authenticated_at']=time();$_SESSION['subscriber_auth_method']='trusted';return $account;
}

function fs_subscriber_require(PDO $pdo): array
{
    $account=fs_subscriber_current($pdo);if(!$account){if(!headers_sent())header('Location: /conta/?expired=1');exit;}return $account;
}

function fs_subscriber_logout(PDO $pdo, bool $all=false): void
{
    $accountId=(int)($_SESSION['subscriber_account_id']??0);$raw=trim((string)($_COOKIE[fs_subscriber_cookie_name()]??''));
    if($raw!=='')$pdo->prepare('UPDATE subscriber_trusted_devices SET revoked_at=NOW(),updated_at=NOW() WHERE token_hash=?')->execute([fs_personal_hash('trusted:'.$raw)]);
    if($all&&$accountId>0){$pdo->prepare('UPDATE subscriber_trusted_devices SET revoked_at=NOW(),updated_at=NOW() WHERE account_id=? AND revoked_at IS NULL')->execute([$accountId]);$pdo->prepare('UPDATE subscriber_accounts SET session_version=session_version+1,updated_at=NOW() WHERE id=?')->execute([$accountId]);}
    if($accountId>0)fs_subscriber_audit($pdo,$accountId,'subscriber',$accountId,$all?'login.all_sessions_revoked':'login.logged_out','account',$accountId);
    unset($_SESSION['subscriber_account_id'],$_SESSION['subscriber_session_version'],$_SESSION['subscriber_authenticated_at'],$_SESSION['subscriber_auth_method']);fs_subscriber_clear_cookie();session_regenerate_id(true);
}

function fs_subscriber_recent_otp(int $maxAgeSeconds=600): bool
{
    $authenticatedAt=(int)($_SESSION['subscriber_authenticated_at']??0);
    return ($_SESSION['subscriber_auth_method']??'')==='otp'&&$authenticatedAt>0&&$authenticatedAt>=time()-max(60,$maxAgeSeconds);
}

function fs_subscriber_reauthentication_start(PDO $pdo, int $accountId): array
{
    $st=$pdo->prepare("SELECT document_encrypted FROM subscriber_external_links WHERE account_id=? AND provider='hubsoft' AND status<>'revoked' LIMIT 1");$st->execute([$accountId]);$encrypted=$st->fetchColumn();
    if(!is_string($encrypted)||$encrypted==='')throw new RuntimeException('Não foi possível confirmar novamente esta conta.');
    try{$document=fs_personal_decrypt($encrypted);}catch(Throwable $e){throw new RuntimeException('Não foi possível confirmar novamente esta conta.');}
    return fs_subscriber_login_start($pdo,$document);
}

/**
 * Anonimiza a conta local sem alterar o contrato no HubSoft. Pedidos e eventos
 * permanecem referenciando uma identidade interna sem PII para fins legítimos.
 */
function fs_subscriber_account_anonymize(PDO $pdo, int $accountId): void
{
    if($accountId<=0)throw new InvalidArgumentException('Conta inválida.');
    $st=$pdo->prepare("SELECT id FROM subscriber_devices WHERE account_id=? AND status='active'");$st->execute([$accountId]);$deviceIds=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]);
    if(function_exists('fs_subscriber_radius_revoke_device'))foreach($deviceIds as $deviceId)fs_subscriber_radius_revoke_device($pdo,$deviceId);
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $account=fs_subscriber_account($pdo,$accountId,true);if(!$account||$account['status']!=='active')throw new RuntimeException('A conta não está disponível para exclusão.');
        $anonymousHash=hash('sha256','subscriber-anonymized:'.$account['public_id'].':'.random_bytes(16));
        $pdo->prepare('DELETE i FROM subscriber_device_identifiers i JOIN subscriber_devices d ON d.id=i.device_id WHERE d.account_id=?')->execute([$accountId]);
        $pdo->prepare("UPDATE subscriber_device_invites SET label=NULL,status=IF(status='redeemed','redeemed','revoked'),token_hash=SHA2(CONCAT(token_hash,':anonymized'),256),human_code_hash=SHA2(CONCAT(human_code_hash,':anonymized'),256),updated_at=NOW() WHERE account_id=?")->execute([$accountId]);
        $pdo->prepare("UPDATE subscriber_devices SET label=NULL,status='revoked',status_reason='ACCOUNT_ANONYMIZED',replace_available_at=NULL,device_token_hash=SHA2(CONCAT(device_token_hash,':anonymized'),256),updated_at=NOW() WHERE account_id=?")->execute([$accountId]);
        $pdo->prepare("UPDATE subscriber_access_grants SET ended_at=IF(status IN ('reserved','provisioning','active'),COALESCE(ended_at,NOW()),ended_at),status=IF(status IN ('reserved','provisioning','active'),'revoked',status),device_mac=NULL,device_ip=NULL,failure_detail=NULL,updated_at=NOW() WHERE account_id=?")->execute([$accountId]);
        $pdo->prepare("UPDATE subscriber_entitlements SET status='revoked',result_code='ACCOUNT_ANONYMIZED',source_snapshot=NULL,external_service_id=NULL,external_plan_id=NULL,valid_until=NOW(),grace_until=NULL,updated_at=NOW() WHERE account_id=?")->execute([$accountId]);
        $pdo->prepare("UPDATE subscriber_external_links SET external_customer_id=?,document_hash=?,document_encrypted=NULL,verified_phone_encrypted=NULL,verified_phone_hint=NULL,verified_email_encrypted=NULL,verified_email_hint=NULL,status='revoked',last_result_code='ACCOUNT_ANONYMIZED',updated_at=NOW() WHERE account_id=?")->execute(['anon:'.$account['public_id'],$anonymousHash,$accountId]);
        $pdo->prepare("UPDATE subscriber_login_challenges SET account_id=NULL,document_hash=SHA2(CONCAT(document_hash,':anonymized:',id),256),target_encrypted='PURGED',code_hash=SHA2(CONCAT(code_hash,':anonymized'),256),origin_hash=NULL,status=IF(status='pending','expired',status),updated_at=NOW() WHERE account_id=?")->execute([$accountId]);
        if(fs_subscriber_password_schema_ready($pdo))$pdo->prepare("UPDATE subscriber_auth_attempts SET account_id=NULL,document_hash=SHA2(CONCAT(document_hash,':anonymized:',id),256),origin_hash=NULL WHERE account_id=? OR document_hash=?")->execute([$accountId,(string)$account['document_hash']]);
        $pdo->prepare('DELETE FROM subscriber_trusted_devices WHERE account_id=?')->execute([$accountId]);
        $pdo->prepare('UPDATE subscriber_audit SET metadata=NULL,origin_hash=NULL WHERE account_id=?')->execute([$accountId]);
        fs_subscriber_audit($pdo,$accountId,'subscriber',$accountId,'account.anonymized','account',$accountId);
        $pdo->prepare("UPDATE subscriber_accounts SET status='anonymized',display_name=NULL,document_hash=?,session_version=session_version+1,anonymized_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$anonymousHash,$accountId]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
}
