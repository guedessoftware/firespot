<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$envPath='/etc/firespot/firespot.env';
$dryRun=false;
foreach(array_slice($argv,1) as $argument){if(str_starts_with($argument,'--env='))$envPath=substr($argument,6);elseif($argument==='--dry-run')$dryRun=true;}
if($envPath===''||$envPath[0]!=='/'||!is_file($envPath)||!is_readable($envPath)||!is_writable($envPath))throw new RuntimeException('Arquivo privado de ambiente indisponível.');
$originalOwner=fileowner($envPath);$originalGroup=filegroup($envPath);$originalMode=fileperms($envPath)&0777;
putenv('FIRESPOT_ENV_PATH='.$envPath);
require_once dirname(__DIR__).'/db.php';
require_once dirname(__DIR__).'/credential_crypto.php';

$oldKey=fs_credential_key();$newEncoded=base64_encode(random_bytes(32));$newKey=base64_decode($newEncoded,true);
if(!is_string($newKey)||strlen($newKey)!==32)throw new RuntimeException('Falha ao gerar a nova chave de credenciais.');
$decrypt=static function(string $encoded,string $key):string{
    if(!str_starts_with($encoded,'sb1:'))throw new RuntimeException('Formato de credencial inesperado durante a rotação.');
    $blob=base64_decode(substr($encoded,4),true);if(!is_string($blob)||strlen($blob)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('Credencial corrompida durante a rotação.');
    $plain=sodium_crypto_secretbox_open(substr($blob,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($blob,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$key);if(!is_string($plain))throw new RuntimeException('Uma credencial existente não pôde ser aberta; rotação cancelada.');return $plain;
};
$encrypt=static function(string $plain,string $key):string{$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);return 'sb1:'.base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$key));};

$targets=[
    'payment_wallets'=>['access_token_encrypted','webhook_secret_encrypted'],
    'marketplace_accounts'=>['access_token_encrypted','refresh_token_encrypted'],
    'marketplace_oauth_states'=>['code_verifier_encrypted'],
];
$pdo=db();$changes=[];
foreach($targets as $table=>$columns){
    $exists=$pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');$exists->execute([$table]);if(!$exists->fetchColumn())continue;
    $available=[];foreach($columns as $column){$check=$pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');$check->execute([$table,$column]);if($check->fetchColumn())$available[]=$column;}
    if(!$available)continue;$select='SELECT id,'.implode(',',$available).' FROM `'.$table.'`';
    foreach($pdo->query($select,PDO::FETCH_ASSOC) as $row){foreach($available as $column){$encoded=(string)($row[$column]??'');if($encoded===''||!str_starts_with($encoded,'sb1:'))continue;$plain=$decrypt($encoded,$oldKey);$rotated=$encrypt($plain,$newKey);if(!hash_equals($plain,$decrypt($rotated,$newKey)))throw new RuntimeException('Falha ao validar uma credencial recriptografada.');$changes[]=[$table,$column,(int)$row['id'],$rotated];}}
}

$originalEnv=file_get_contents($envPath);if(!is_string($originalEnv))throw new RuntimeException('Não foi possível ler o arquivo privado.');
if(!preg_match('/^PAYMENT_CREDENTIAL_KEY\s*=.*$/m',$originalEnv))throw new RuntimeException('PAYMENT_CREDENTIAL_KEY não foi encontrada no arquivo privado.');
$newEnv=preg_replace('/^PAYMENT_CREDENTIAL_KEY\s*=.*$/m','PAYMENT_CREDENTIAL_KEY='.$newEncoded,$originalEnv,1);
if(!is_string($newEnv))throw new RuntimeException('Não foi possível preparar a configuração privada.');
// A instalação legada usa PAYMENT_CREDENTIAL_KEY também para dados
// pessoais quando PERSONAL_DATA_KEY está ausente. Preserve exatamente esse
// material sob o nome correto antes de rotacionar a chave de pagamentos; a
// migração independente dos hashes pessoais continua explicitamente pendente.
if(!preg_match('/^PERSONAL_DATA_KEY\s*=\s*\S+/m',$originalEnv)){
    $newEnv.=($newEnv===''||str_ends_with($newEnv,"\n")?'':"\n").'PERSONAL_DATA_KEY='.base64_encode($oldKey)."\nPERSONAL_DATA_KEY_ROTATION_PENDING=1\n";
}
if($dryRun){echo 'Pré-validação concluída; credenciais que serão recriptografadas: '.count($changes).".\n";exit(0);}

$pdo->beginTransaction();$envActivated=false;
try{
    foreach($changes as [$table,$column,$id,$rotated]){$statement=$pdo->prepare('UPDATE `'.$table.'` SET `'.$column.'`=? WHERE id=?');$statement->execute([$rotated,$id]);if($statement->rowCount()!==1)throw new RuntimeException('Uma credencial mudou durante a rotação.');}
    $temporary=tempnam(dirname($envPath),'.firespot-payment-key-');if($temporary===false)throw new RuntimeException('Não foi possível preparar a nova chave.');
    try{if(file_put_contents($temporary,$newEnv,LOCK_EX)!==strlen($newEnv))throw new RuntimeException('Não foi possível gravar a nova chave.');if($originalOwner!==false)@chown($temporary,$originalOwner);if($originalGroup!==false)@chgrp($temporary,$originalGroup);chmod($temporary,$originalMode);if(!rename($temporary,$envPath))throw new RuntimeException('Não foi possível ativar a nova chave.');$envActivated=true;}finally{if(is_file($temporary))@unlink($temporary);}
    $pdo->commit();
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    if($envActivated){$restore=tempnam(dirname($envPath),'.firespot-payment-restore-');if($restore!==false){file_put_contents($restore,$originalEnv,LOCK_EX);if($originalOwner!==false)@chown($restore,$originalOwner);if($originalGroup!==false)@chgrp($restore,$originalGroup);chmod($restore,$originalMode);rename($restore,$envPath);}}
    throw $error;
}
chmod($envPath,$originalMode);
echo 'PAYMENT_CREDENTIAL_KEY rotacionada; credenciais recriptografadas: '.count($changes).".\n";
