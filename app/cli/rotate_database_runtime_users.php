<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once dirname(__DIR__).'/db.php';

$envPath='/etc/firespot/firespot.env';
foreach(array_slice($argv,1) as $argument){if(str_starts_with($argument,'--env='))$envPath=substr($argument,6);}
$documentRoot=realpath(dirname(__DIR__,2));$envParent=realpath(dirname($envPath));
if($envPath===''||$envPath[0]!=='/'||!is_file($envPath)||!is_readable($envPath)||!is_writable($envPath))throw new RuntimeException('Arquivo privado de ambiente indisponível.');
if($documentRoot!==false&&$envParent!==false&&str_starts_with($envParent.DIRECTORY_SEPARATOR,$documentRoot.DIRECTORY_SEPARATOR))throw new RuntimeException('O arquivo de ambiente não pode ficar no DocumentRoot.');
$originalOwner=fileowner($envPath);$originalGroup=filegroup($envPath);$originalMode=fileperms($envPath)&0777;

$admin=db();$schema=(string)DB_DATABASE;
if(!preg_match('/^[A-Za-z0-9_]+$/',$schema))throw new RuntimeException('Nome de banco incompatível com a rotação segura.');
$appUser='firespot_app';$radiusUser='firespot_radius_app';
$appPassword=rtrim(strtr(base64_encode(random_bytes(36)),'+/','-_'),'=');
$radiusPassword=rtrim(strtr(base64_encode(random_bytes(36)),'+/','-_'),'=');

$accountSql=static fn(PDO $pdo,string $user,string $host):string=>$pdo->quote($user).'@'.$pdo->quote($host);
foreach([[$appUser,$appPassword],[$radiusUser,$radiusPassword]] as [$user,$password]){
    foreach(['localhost','127.0.0.1'] as $host){
        $account=$accountSql($admin,$user,$host);$quotedPassword=$admin->quote($password);
        $admin->exec("CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$quotedPassword}");
        $admin->exec("ALTER USER {$account} IDENTIFIED BY {$quotedPassword}");
        $admin->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$account}");
    }
}
foreach(['localhost','127.0.0.1'] as $host){
    $admin->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON `'.$schema.'`.* TO '.$accountSql($admin,$appUser,$host));
    foreach(['radcheck','radreply','radusergroup','radacct','radpostauth'] as $table){
        $exists=$admin->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1');$exists->execute([$schema,$table]);if(!$exists->fetchColumn())continue;
        $admin->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON `'.$schema.'`.`'.$table.'` TO '.$accountSql($admin,$radiusUser,$host));
    }
    foreach(['nas','radgroupcheck','radgroupreply'] as $table){
        $exists=$admin->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1');$exists->execute([$schema,$table]);if(!$exists->fetchColumn())continue;
        $admin->exec('GRANT SELECT ON `'.$schema.'`.`'.$table.'` TO '.$accountSql($admin,$radiusUser,$host));
    }
}

$newConnection=static function(string $user,string $password)use($schema):PDO{return new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$schema.';charset=utf8mb4',$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);};
$app=$newConnection($appUser,$appPassword);if((int)$app->query('SELECT 1')->fetchColumn()!==1)throw new RuntimeException('A conta restrita da aplicação não conectou.');
$probe='__firespot_privilege_probe_'.bin2hex(random_bytes(5));$ddlDenied=false;
try{$app->exec('CREATE TABLE `'.$probe.'` (id INT)');}catch(PDOException $error){$ddlDenied=true;}
if(!$ddlDenied){$admin->exec('DROP TABLE IF EXISTS `'.$schema.'`.`'.$probe.'`');throw new RuntimeException('A conta restrita recebeu privilégio DDL inesperado.');}
$radius=$newConnection($radiusUser,$radiusPassword);if((int)$radius->query('SELECT COUNT(*)>=0 FROM radcheck')->fetchColumn()!==1)throw new RuntimeException('A conta restrita do RADIUS não leu radcheck.');
$unrelatedDenied=false;try{$radius->query('SELECT 1 FROM admins LIMIT 1')->fetchColumn();}catch(PDOException $error){$unrelatedDenied=true;}
if(!$unrelatedDenied)throw new RuntimeException('A conta restrita do RADIUS recebeu acesso a tabela administrativa.');

$handle=fopen($envPath,'c+');if($handle===false)throw new RuntimeException('Não foi possível abrir o arquivo privado.');
try{
    if(!flock($handle,LOCK_EX))throw new RuntimeException('Não foi possível bloquear o arquivo privado.');
    rewind($handle);$contents=stream_get_contents($handle);if(!is_string($contents))throw new RuntimeException('Não foi possível ler o arquivo privado.');
    $updates=['DB_HOST'=>'127.0.0.1','DB_DATABASE'=>$schema,'DB_USERNAME'=>$appUser,'DB_PASSWORD'=>$appPassword,'RADIUS_DB_HOST'=>'127.0.0.1','RADIUS_DB_NAME'=>$schema,'RADIUS_DB_USER'=>$radiusUser,'RADIUS_DB_PASS'=>$radiusPassword];
    foreach($updates as $name=>$value){$line=$name.'='.$value;if(preg_match('/^'.preg_quote($name,'/').'\s*=.*$/m',$contents))$contents=preg_replace('/^'.preg_quote($name,'/').'\s*=.*$/m',$line,$contents,1);else$contents.=($contents===''||str_ends_with($contents,"\n")?'':"\n").$line."\n";}
    $temporary=tempnam(dirname($envPath),'.firespot-db-env-');if($temporary===false)throw new RuntimeException('Não foi possível preparar a configuração restrita.');
    try{if(file_put_contents($temporary,$contents,LOCK_EX)!==strlen($contents))throw new RuntimeException('Não foi possível gravar a configuração restrita.');if($originalOwner!==false)@chown($temporary,$originalOwner);if($originalGroup!==false)@chgrp($temporary,$originalGroup);chmod($temporary,$originalMode);if(!rename($temporary,$envPath))throw new RuntimeException('Não foi possível ativar a configuração restrita.');}finally{if(is_file($temporary))@unlink($temporary);}
    chmod($envPath,$originalMode);
}finally{flock($handle,LOCK_UN);fclose($handle);}

echo "Contas de execução criadas e testadas: {$appUser}, {$radiusUser}. O usuário administrativo anterior não foi revogado.\n";
