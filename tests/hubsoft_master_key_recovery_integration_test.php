<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once dirname(__DIR__).'/app/db.php';

$checks=0;
function hubsoft_recovery_expect(bool $condition,string $message):void
{
    global $checks;$checks++;if(!$condition)throw new RuntimeException($message);
}

$temporaryDirectory=sys_get_temp_dir().'/firespot-hubsoft-recovery-'.bin2hex(random_bytes(8));
if(!mkdir($temporaryDirectory,0700,true))throw new RuntimeException('Não foi possível criar o diretório temporário.');
$keyPath=$temporaryDirectory.'/master.key';touch($keyPath);chmod($keyPath,0600);putenv('FIRESPOT_MASTER_KEY_PATH='.$keyPath);
require_once dirname(__DIR__).'/app/integration_credentials.php';

$pdo=db();$pdo->beginTransaction();
try{
    $partialRejected=false;
    try{fs_integration_hubsoft_save($pdo,['base_url'=>'https://api.example.invalid','client_id'=>'test-client','username'=>'replacement-user','active'=>1],null);}catch(InvalidArgumentException $error){$partialRejected=str_contains($error->getMessage(),'Preencha novamente');}
    hubsoft_recovery_expect($partialRejected,'A recuperação parcial das credenciais deve ser rejeitada.');
    hubsoft_recovery_expect(!fs_application_master_key_exists(),'Uma tentativa incompleta não deve criar a chave.');

    fs_integration_hubsoft_save($pdo,[
        'base_url'=>'https://api.example.invalid','client_id'=>'test-client','username'=>'replacement-user',
        'client_secret'=>'replacement-secret','password'=>'replacement-password','active'=>1,
    ],null);
    hubsoft_recovery_expect(fs_application_master_key_exists(),'A substituição completa deve recuperar a chave.');
    $loaded=fs_integration_hubsoft_load($pdo,true);
    hubsoft_recovery_expect($loaded['username']==='replacement-user','O usuário substituto deve ser legível.');
    hubsoft_recovery_expect($loaded['client_secret']==='replacement-secret','O Client Secret substituto deve ser legível.');
    hubsoft_recovery_expect($loaded['password']==='replacement-password','A senha substituta deve ser legível.');
    $status=fs_integration_hubsoft_status($pdo);
    hubsoft_recovery_expect(!empty($status['configured'])&&empty($status['requires_full_credentials']),'A integração recuperada deve ficar pronta.');
    $audit=$pdo->query("SELECT action FROM system_integration_audit WHERE provider='hubsoft' ORDER BY id DESC LIMIT 1")->fetchColumn();
    hubsoft_recovery_expect($audit==='credentials.recovered','A recuperação deve ter evento de auditoria específico.');
}finally{
    if($pdo->inTransaction())$pdo->rollBack();@unlink($keyPath);@rmdir($temporaryDirectory);putenv('FIRESPOT_MASTER_KEY_PATH');
}

echo "OK: {$checks} verificações de recuperação transacional do HubSoft.\n";
