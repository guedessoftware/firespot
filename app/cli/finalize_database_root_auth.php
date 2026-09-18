<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!function_exists('posix_geteuid')||posix_geteuid()!==0)throw new RuntimeException('Execute a rotação final do banco como root.');

$socketDsn='mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4';
try{
    $alreadyLocal=new PDO($socketDsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if((int)$alreadyLocal->query('SELECT 1')->fetchColumn()===1){echo "MariaDB root já está restrito ao socket local.\n";exit(0);}
}catch(PDOException $ignored){}

$legacyConfig='/etc/firespot/runtime-config.php';
if(!is_file($legacyConfig)||!is_readable($legacyConfig))throw new RuntimeException('Configuração administrativa legada indisponível.');
require $legacyConfig;
foreach(['DB_HOST','DB_DATABASE','DB_USERNAME','DB_PASSWORD'] as $name)if(!defined($name))throw new RuntimeException('Configuração administrativa incompleta.');
if((string)DB_USERNAME!=='root')throw new RuntimeException('A configuração legada não pertence ao root esperado.');

$host=(string)DB_HOST;$port=3306;if(str_contains($host,':')){[$host,$configuredPort]=explode(':',$host,2);if(ctype_digit($configuredPort))$port=(int)$configuredPort;}
$pdo=new PDO('mysql:host='.$host.';port='.$port.';dbname='.DB_DATABASE.';charset=utf8mb4',(string)DB_USERNAME,(string)DB_PASSWORD,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$plugin=$pdo->query("SELECT PLUGIN_STATUS FROM information_schema.PLUGINS WHERE PLUGIN_NAME='unix_socket' LIMIT 1")->fetchColumn();
if(strtoupper((string)$plugin)!=='ACTIVE')throw new RuntimeException('Plugin unix_socket do MariaDB não está ativo; root não foi alterado.');
$pdo->exec("ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket");

$socketPdo=new PDO($socketDsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if((int)$socketPdo->query('SELECT 1')->fetchColumn()!==1)throw new RuntimeException('A autenticação administrativa por socket não foi confirmada.');
$oldPasswordRejected=false;
try{new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.DB_DATABASE.';charset=utf8mb4','root',(string)DB_PASSWORD,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}catch(PDOException $error){$oldPasswordRejected=true;}
if(!$oldPasswordRejected)throw new RuntimeException('A senha administrativa anterior ainda foi aceita por TCP.');
echo "MariaDB root restrito ao socket local; a senha anterior foi invalidada.\n";
