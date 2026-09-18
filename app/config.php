<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

$databaseEnvironment=[
    'DB_HOST'=>trim((string)env('DB_HOST','')),
    'DB_DATABASE'=>trim((string)env('DB_DATABASE','')),
    'DB_USERNAME'=>trim((string)env('DB_USERNAME','')),
    'DB_PASSWORD'=>(string)env('DB_PASSWORD',''),
];
if(!in_array('',array_values($databaseEnvironment),true)){
    foreach($databaseEnvironment as $name=>$value)if(!defined($name))define($name,$value);
    return;
}

$configuredRuntimePath=trim((string)getenv('FIRESPOT_RUNTIME_CONFIG_PATH'));
$runtimeCandidates=array_filter([
    $configuredRuntimePath!==''?$configuredRuntimePath:null,
    '/etc/firespot/runtime-config.php',
    '/etc/firespot/runtime-config.php',
]);
$externalRuntimeConfig=null;
foreach($runtimeCandidates as $candidate){
    if(is_file($candidate)&&is_readable($candidate)){$externalRuntimeConfig=$candidate;break;}
}
if($externalRuntimeConfig===null)throw new RuntimeException('Configuração privada da aplicação indisponível.');

require_once $externalRuntimeConfig;
