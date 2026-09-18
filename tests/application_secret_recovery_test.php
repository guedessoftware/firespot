<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$checks=0;
function application_secret_expect(bool $condition,string $message):void
{
    global $checks;$checks++;if(!$condition)throw new RuntimeException($message);
}

$temporaryDirectory=sys_get_temp_dir().'/firespot-master-key-'.bin2hex(random_bytes(8));
if(!mkdir($temporaryDirectory,0700,true))throw new RuntimeException('Não foi possível criar o diretório temporário.');
$keyPath=$temporaryDirectory.'/master.key';putenv('FIRESPOT_MASTER_KEY_PATH='.$keyPath);
require_once dirname(__DIR__).'/app/application_secret.php';

try{
    touch($keyPath);chmod($keyPath,0604);
    application_secret_expect(!fs_application_master_key_exists(),'Um placeholder vazio não pode ser considerado uma chave válida.');
    $created=fs_application_master_key(true);
    application_secret_expect(strlen($created)===32,'A chave recuperada deve ter 32 bytes.');
    application_secret_expect(fs_application_master_key_exists(),'A chave recuperada deve ficar disponível.');
    application_secret_expect(hash_equals($created,fs_application_master_key(false)),'A releitura deve retornar a mesma chave.');
    clearstatcache(true,$keyPath);
    application_secret_expect((fileperms($keyPath)&0777)===0600,'A chave recuperada deve usar permissão 0600.');

    chmod($keyPath,0400);
    application_secret_expect(hash_equals($created,fs_application_master_key(true)),'Uma chave válida somente para leitura deve ser reutilizada.');

    $corruptPath=$temporaryDirectory.'/corrupt.key';file_put_contents($corruptPath,"conteudo-invalido\n");chmod($corruptPath,0600);putenv('FIRESPOT_MASTER_KEY_PATH='.$corruptPath);
    $before=(string)file_get_contents($corruptPath);$rejected=false;
    try{fs_application_master_key(true);}catch(RuntimeException $error){$rejected=true;}
    application_secret_expect($rejected,'Uma chave não vazia e inválida deve ser rejeitada.');
    application_secret_expect(hash_equals($before,(string)file_get_contents($corruptPath)),'Uma chave inválida não pode ser sobrescrita silenciosamente.');
}finally{
    @unlink($keyPath);@unlink($temporaryDirectory.'/corrupt.key');@rmdir($temporaryDirectory);putenv('FIRESPOT_MASTER_KEY_PATH');
}

echo "OK: {$checks} verificações de recuperação da chave interna.\n";
