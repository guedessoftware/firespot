<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!function_exists('posix_geteuid')||posix_geteuid()!==0)throw new RuntimeException('Execute a configuração do FreeRADIUS como root.');

require_once dirname(__DIR__).'/env.php';

$enabledPath='/etc/freeradius/3.0/mods-enabled/sql';$target=realpath($enabledPath);
if($target===false||!is_file($target)||!is_readable($target)||!is_writable($target))throw new RuntimeException('Módulo SQL ativo do FreeRADIUS não encontrado.');
$values=[
    'server'=>trim((string)env('RADIUS_DB_HOST','')),
    'login'=>(string)env('RADIUS_DB_USER',''),
    'password'=>(string)env('RADIUS_DB_PASS',''),
];
$database=trim((string)env('RADIUS_DB_NAME',''));
foreach($values as $name=>$value)if($value===''||str_contains($value,"\n")||str_contains($value,"\r")||str_contains($value,'"')||str_contains($value,'\\'))throw new RuntimeException('Valor RADIUS inválido para '.$name.'.');
if($database===''||str_contains($database,"\n")||str_contains($database,"\r")||str_contains($database,'"')||str_contains($database,'\\'))throw new RuntimeException('Valor RADIUS inválido para o banco.');

$contents=file_get_contents($target);if(!is_string($contents))throw new RuntimeException('Não foi possível ler o módulo SQL do FreeRADIUS.');
foreach($values as $name=>$value){
    $pattern='/^(\s*)'.preg_quote($name,'/').'\s*=.*$/m';
    if(!preg_match($pattern,$contents))throw new RuntimeException('Diretiva '.$name.' ausente no módulo SQL do FreeRADIUS.');
    $contents=preg_replace_callback($pattern,static fn(array $match):string=>$match[1].$name.' = "'.$value.'"',$contents,1);
}
$databaseDirective=null;
foreach(['radius_db','database'] as $candidate){if(preg_match('/^(\s*)'.preg_quote($candidate,'/').'\s*=.*$/m',$contents)){$databaseDirective=$candidate;break;}}
if($databaseDirective===null)throw new RuntimeException('Diretiva radius_db/database ausente no módulo SQL do FreeRADIUS.');
$databasePattern='/^(\s*)'.preg_quote($databaseDirective,'/').'\s*=.*$/m';
$contents=preg_replace_callback($databasePattern,static fn(array $match):string=>$match[1].$databaseDirective.' = "'.$database.'"',$contents,1);

$owner=fileowner($target);$group=filegroup($target);$mode=fileperms($target)&0777;$temporary=tempnam(dirname($target),'.firespot-radius-sql-');
if($temporary===false)throw new RuntimeException('Não foi possível preparar o módulo SQL do FreeRADIUS.');
try{
    if(file_put_contents($temporary,$contents,LOCK_EX)!==strlen($contents))throw new RuntimeException('Não foi possível gravar o módulo SQL do FreeRADIUS.');
    if($owner!==false)chown($temporary,$owner);if($group!==false)chgrp($temporary,$group);chmod($temporary,$mode);
    if(!rename($temporary,$target))throw new RuntimeException('Não foi possível ativar o módulo SQL do FreeRADIUS.');
}finally{if(is_file($temporary))@unlink($temporary);}

$dsn='mysql:host='.$values['server'].';port=3306;dbname='.$database.';charset=utf8mb4';
$pdo=new PDO($dsn,$values['login'],$values['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['radcheck','radreply','radusergroup','radacct'] as $table)$pdo->query('SELECT 1 FROM `'.$table.'` LIMIT 1');
echo "Módulo SQL do FreeRADIUS atualizado e conta restrita validada.\n";
