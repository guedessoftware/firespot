<?php

declare(strict_types=1);

require_once __DIR__.'/application_secret.php';

function fs_integration_encrypt(string $plain, bool $createKey=false): string
{
    if($plain==='')throw new InvalidArgumentException('Credencial vazia.');
    if(!function_exists('sodium_crypto_secretbox'))throw new RuntimeException('A extensão Sodium é necessária para proteger integrações.');
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$cipher=sodium_crypto_secretbox($plain,$nonce,fs_application_derived_key('integration-credentials-v1',$createKey));
    return 'ic1:'.base64_encode($nonce.$cipher);
}

function fs_integration_decrypt(string $encoded): string
{
    if(!str_starts_with($encoded,'ic1:'))throw new RuntimeException('Formato de credencial de integração desconhecido.');
    $blob=base64_decode(substr($encoded,4),true);if(!is_string($blob)||strlen($blob)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('Credencial de integração corrompida.');
    $plain=sodium_crypto_secretbox_open(substr($blob,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($blob,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),fs_application_derived_key('integration-credentials-v1'));
    if(!is_string($plain))throw new RuntimeException('Não foi possível abrir a credencial da integração.');return $plain;
}

function fs_integration_hubsoft_row(PDO $pdo, bool $forUpdate=false): ?array
{
    try{$st=$pdo->query("SELECT * FROM system_integrations WHERE provider='hubsoft' LIMIT 1".($forUpdate?' FOR UPDATE':''));$row=$st->fetch(PDO::FETCH_ASSOC);return$row?:null;}catch(Throwable $e){return null;}
}

/** Confirma que o conjunto armazenado pode ser reutilizado sem expor segredos. */
function fs_integration_hubsoft_credentials_reusable(?array $row): bool
{
    if(!$row||!fs_application_master_key_exists())return false;
    try{
        foreach(['client_secret_encrypted','username_encrypted','password_encrypted'] as $field){
            if(!str_starts_with((string)($row[$field]??''),'ic1:')||trim(fs_integration_decrypt((string)$row[$field]))==='')return false;
        }
        return true;
    }catch(Throwable $error){return false;}
}

/** Retorna somente campos seguros para a interface administrativa. */
function fs_integration_hubsoft_status(PDO $pdo): array
{
    $row=fs_integration_hubsoft_row($pdo);$keyReady=fs_application_master_key_exists();$stored=$row&&str_starts_with((string)$row['client_secret_encrypted'],'ic1:')&&str_starts_with((string)$row['username_encrypted'],'ic1:')&&str_starts_with((string)$row['password_encrypted'],'ic1:');$reusable=fs_integration_hubsoft_credentials_reusable($row);
    $parts=$row?parse_url((string)$row['base_url']):null;$secureUrl=is_array($parts)&&strtolower((string)($parts['scheme']??''))==='https'&&!empty($parts['host']);
    return [
        'exists'=>(bool)$row,'configured'=>$stored&&$reusable&&$secureUrl&&trim((string)($row['client_id']??''))!==''&&function_exists('curl_init'),'active'=>(bool)($row['active']??false),
        'base_url'=>(string)($row['base_url']??''),'client_id'=>(string)($row['client_id']??''),'grant_type'=>(string)($row['grant_type']??'password'),'has_username'=>$reusable,'requires_full_credentials'=>(bool)$row&&!$reusable,
        'key_ready'=>$keyReady,'secure_url'=>$secureUrl,'last_test_at'=>$reusable?($row['last_test_at']??null):null,'last_test_ok'=>$reusable&&isset($row['last_test_ok'])?(bool)$row['last_test_ok']:null,'last_test_code'=>$reusable?($row['last_test_code']??null):($row?'CREDENTIALS_RECOVERY_REQUIRED':null),'updated_at'=>$row['updated_at']??null,
    ];
}

/** Retorna a configuração completa exclusivamente para o cliente HTTP interno. */
function fs_integration_hubsoft_load(PDO $pdo, bool $allowInactive=false): array
{
    $row=fs_integration_hubsoft_row($pdo);if(!$row||(!$allowInactive&&empty($row['active'])))throw new RuntimeException('A integração HubSoft não está ativa.');
    try{return ['base_url'=>(string)$row['base_url'],'client_id'=>(string)$row['client_id'],'client_secret'=>fs_integration_decrypt((string)$row['client_secret_encrypted']),'username'=>fs_integration_decrypt((string)$row['username_encrypted']),'password'=>fs_integration_decrypt((string)$row['password_encrypted']),'grant_type'=>(string)$row['grant_type']];}
    catch(Throwable $e){error_log('[hubsoft credentials] unable_to_decrypt');throw new RuntimeException('As credenciais armazenadas do HubSoft não puderam ser abertas.');}
}

function fs_integration_hubsoft_save(PDO $pdo, array $input, ?int $actorId=null): void
{
    $baseUrl=rtrim(trim((string)($input['base_url']??'')),'/');$parts=parse_url($baseUrl);if($baseUrl===''||!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))throw new InvalidArgumentException('Informe uma URL HTTPS válida do HubSoft, sem parâmetros ou credenciais.');
    $clientId=substr(trim((string)($input['client_id']??'')),0,120);if($clientId==='')throw new InvalidArgumentException('Informe o Client ID do HubSoft.');
    $username=trim((string)($input['username']??''));$clientSecret=trim((string)($input['client_secret']??''));$password=(string)($input['password']??'');$active=!empty($input['active']);
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $existing=fs_integration_hubsoft_row($pdo,true);$usernameEncrypted=$existing['username_encrypted']??null;$secretEncrypted=$existing['client_secret_encrypted']??null;$passwordEncrypted=$existing['password_encrypted']??null;
        $reusable=fs_integration_hubsoft_credentials_reusable($existing);
        if(!$reusable&&($username===''||$clientSecret===''||$password===''))throw new InvalidArgumentException('A chave anterior não está disponível. Preencha novamente Usuário técnico, Client Secret e Senha técnica para recuperar a integração.');
        if($username!=='')$usernameEncrypted=fs_integration_encrypt(substr($username,0,190),true);if($clientSecret!=='')$secretEncrypted=fs_integration_encrypt($clientSecret,true);if($password!=='')$passwordEncrypted=fs_integration_encrypt($password,true);
        if(!$usernameEncrypted||!$secretEncrypted||!$passwordEncrypted)throw new InvalidArgumentException('Informe usuário, senha e Client Secret na primeira configuração.');
        $st=$pdo->prepare("INSERT INTO system_integrations (provider,display_name,base_url,client_id,client_secret_encrypted,username_encrypted,password_encrypted,grant_type,active,updated_by) VALUES ('hubsoft','HubSoft ERP',?,?,?,?,?,'password',?,?) ON DUPLICATE KEY UPDATE base_url=VALUES(base_url),client_id=VALUES(client_id),client_secret_encrypted=VALUES(client_secret_encrypted),username_encrypted=VALUES(username_encrypted),password_encrypted=VALUES(password_encrypted),grant_type='password',active=VALUES(active),updated_by=VALUES(updated_by),last_test_at=NULL,last_test_ok=NULL,last_test_code='CREDENTIALS_CHANGED',updated_at=NOW()");
        $st->execute([$baseUrl,$clientId,$secretEncrypted,$usernameEncrypted,$passwordEncrypted,$active?1:0,$actorId]);
        $invalidate=$pdo->prepare("INSERT INTO app_settings (skey,svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)");
        $invalidate->execute(['hubsoft_capability_last_at','']);
        $invalidate->execute(['hubsoft_capability_last_ok','0']);
        $invalidate->execute(['hubsoft_capability_last_code','CREDENTIALS_CHANGED']);
        $auditAction=$existing&&!$reusable?'credentials.recovered':'credentials.saved';
        $pdo->prepare("INSERT INTO system_integration_audit (provider,actor_id,action,metadata) VALUES ('hubsoft',?,?,?)")->execute([$actorId,$auditAction,json_encode(['active'=>$active,'base_host'=>(string)$parts['host']],JSON_UNESCAPED_SLASHES)]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
}

function fs_integration_hubsoft_record_test(PDO $pdo, bool $ok, string $code, ?int $actorId=null): void
{
    $code=substr(preg_replace('/[^A-Z0-9_:-]+/i','_',strtoupper(trim($code)))?:'UNKNOWN',0,80);$pdo->prepare("UPDATE system_integrations SET last_test_at=NOW(),last_test_ok=?,last_test_code=?,updated_at=updated_at WHERE provider='hubsoft'")->execute([$ok?1:0,$code]);
    $pdo->prepare("INSERT INTO system_integration_audit (provider,actor_id,action,metadata) VALUES ('hubsoft',?,?,?)")->execute([$actorId,$ok?'connection.test_succeeded':'connection.test_failed',json_encode(['code'=>$code],JSON_UNESCAPED_SLASHES)]);
}
