<?php

declare(strict_types=1);

/**
 * Chave mestra local do FireSpot.
 *
 * Ela é criada automaticamente, nunca fica no banco e não contém configuração
 * de nenhuma integração. Em produção, o arquivo fica fora do DocumentRoot.
 */
function fs_application_master_key_path(): string
{
    $configured = trim((string) getenv('FIRESPOT_MASTER_KEY_PATH'));
    if ($configured !== '') return $configured;
    return '/etc/firespot/master.key';
}

function fs_application_master_key_exists(): bool
{
    try {
        fs_application_master_key(false);
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

function fs_application_master_key(bool $create=false): string
{
    $path=fs_application_master_key_path();
    if(is_link($path))throw new RuntimeException('O caminho da chave interna do FireSpot não pode ser um link simbólico.');

    if(!$create){
        if(!is_file($path))throw new RuntimeException('A chave interna do FireSpot ainda não foi criada.');
        $encoded=@file_get_contents($path);
        if(!is_string($encoded))throw new RuntimeException('A chave interna do FireSpot não pôde ser lida.');
        $decoded=base64_decode(trim($encoded),true);
        if(!is_string($decoded)||strlen($decoded)!==32)throw new RuntimeException('A chave interna do FireSpot está inválida.');
        return $decoded;
    }

    // Em produção a chave pode ser somente leitura para o usuário web.
    // Se ela já for válida, não exija abertura para escrita apenas porque
    // o chamador também aceita criar uma chave ausente.
    if(is_file($path)){
        try{return fs_application_master_key(false);}catch(RuntimeException $error){
            $current=@file_get_contents($path);
            if(!is_string($current)||trim($current)!=='')throw $error;
        }
    }

    $directory=dirname($path);
    if(!is_dir($directory)){
        if(!@mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Não foi possível preparar o diretório da chave interna do FireSpot.');
        @chmod($directory,0700);
    }
    if(is_link($path))throw new RuntimeException('O caminho da chave interna do FireSpot não pode ser um link simbólico.');

    // c+ permite recuperar com segurança o placeholder vazio criado durante o
    // provisionamento. O bloqueio impede duas requisições de criarem chaves
    // diferentes e um arquivo não vazio jamais é sobrescrito silenciosamente.
    $handle=@fopen($path,'c+b');
    if($handle===false)throw new RuntimeException('Não foi possível criar a chave interna do FireSpot.');
    try{
        if(!flock($handle,LOCK_EX))throw new RuntimeException('Não foi possível bloquear a chave interna do FireSpot.');
        rewind($handle);$current=stream_get_contents($handle);
        if(!is_string($current))throw new RuntimeException('A chave interna do FireSpot não pôde ser lida.');
        if(trim($current)!==''){
            $decoded=base64_decode(trim($current),true);
            if(!is_string($decoded)||strlen($decoded)!==32)throw new RuntimeException('A chave interna do FireSpot está inválida.');
            @chmod($path,0600);
            return $decoded;
        }

        $decoded=random_bytes(32);$contents=base64_encode($decoded)."\n";
        if(!ftruncate($handle,0)||fseek($handle,0)!==0)throw new RuntimeException('Não foi possível preparar a chave interna do FireSpot.');
        $written=0;$length=strlen($contents);
        while($written<$length){$chunk=fwrite($handle,substr($contents,$written));if($chunk===false||$chunk===0)throw new RuntimeException('Não foi possível gravar a chave interna do FireSpot.');$written+=$chunk;}
        if(!fflush($handle))throw new RuntimeException('Não foi possível persistir a chave interna do FireSpot.');
        if(function_exists('fsync'))@fsync($handle);
        if(!@chmod($path,0600))throw new RuntimeException('Não foi possível restringir a chave interna do FireSpot.');
        return $decoded;
    }finally{flock($handle,LOCK_UN);fclose($handle);}
}

function fs_application_derived_key(string $context, bool $create=false): string
{
    $context=trim($context);if($context==='')throw new InvalidArgumentException('Contexto criptográfico inválido.');
    return hash_hmac('sha256','firespot:'.$context,fs_application_master_key($create),true);
}
