<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$path='/etc/firespot/firespot.env';
foreach(array_slice($argv,1) as $argument){if(str_starts_with($argument,'--env='))$path=substr($argument,6);}
$documentRoot=realpath(dirname(__DIR__,2));$parent=realpath(dirname($path));
if($path===''||$path[0]!=='/'||!is_file($path)||!is_readable($path)||!is_writable($path))throw new RuntimeException('Arquivo privado de ambiente indisponível para atualização.');
if($documentRoot!==false&&$parent!==false&&str_starts_with($parent.DIRECTORY_SEPARATOR,$documentRoot.DIRECTORY_SEPARATOR))throw new RuntimeException('O arquivo de segredos não pode ficar dentro do DocumentRoot.');
$originalOwner=fileowner($path);$originalGroup=filegroup($path);$originalMode=fileperms($path)&0777;

$handle=fopen($path,'c+');if($handle===false)throw new RuntimeException('Não foi possível abrir o arquivo privado.');
try{
    if(!flock($handle,LOCK_EX))throw new RuntimeException('Não foi possível bloquear o arquivo privado.');
    rewind($handle);$contents=stream_get_contents($handle);if(!is_string($contents))throw new RuntimeException('Não foi possível ler o arquivo privado.');
    $keys=[];
    foreach(preg_split('/\R/',$contents)?:[] as $line){$trimmed=trim($line);if($trimmed===''||in_array($trimmed[0],['#',';'],true))continue;$separator=strpos($trimmed,'=');if($separator===false)continue;$keys[trim(substr($trimmed,0,$separator))]=trim(substr($trimmed,$separator+1));}
    $created=[];
    foreach(['INTERNAL_API_KEY'=>48,'APP_KEY'=>32,'ACCOUNT_DELETION_AUDIT_KEY'=>32] as $name=>$bytes){
        if(trim((string)($keys[$name]??''))!=='')continue;
        $value=rtrim(strtr(base64_encode(random_bytes($bytes)),'+/','-_'),'=');
        $contents.=($contents===''||str_ends_with($contents,"\n")?'':"\n").$name.'='.$value."\n";$created[]=$name;
    }
    if($created){
        $temporary=tempnam(dirname($path),'.firespot-env-');if($temporary===false)throw new RuntimeException('Não foi possível preparar a atualização privada.');
        try{if(file_put_contents($temporary,$contents,LOCK_EX)!==strlen($contents))throw new RuntimeException('Não foi possível gravar a atualização privada.');if($originalOwner!==false)@chown($temporary,$originalOwner);if($originalGroup!==false)@chgrp($temporary,$originalGroup);chmod($temporary,$originalMode);if(!rename($temporary,$path))throw new RuntimeException('Não foi possível ativar a atualização privada.');}finally{if(is_file($temporary))@unlink($temporary);}
    }
    chmod($path,$originalMode);
    echo $created?'Chaves internas inicializadas: '.implode(', ',$created).".\n":"Chaves internas já estavam configuradas; nenhuma alteração.\n";
}finally{flock($handle,LOCK_UN);fclose($handle);}
