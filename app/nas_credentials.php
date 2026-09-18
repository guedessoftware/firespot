<?php

declare(strict_types=1);

require_once __DIR__ . '/credential_crypto.php';

const FS_NAS_CREDENTIAL_PREFIX='nsb1:';

function fs_nas_credentials_encrypt(string $username, string $password, string $radiusSecret): string
{
    $username=trim($username);
    if ($username===''||strlen($username)>64) throw new InvalidArgumentException('Informe um usuário SSH válido.');
    if ($password===''||strlen($password)>512) throw new InvalidArgumentException('Informe uma senha SSH válida.');
    if ($radiusSecret===''||strlen($radiusSecret)>60) throw new InvalidArgumentException('Informe um shared secret de até 60 caracteres.');
    $payload=json_encode(['username'=>$username,'password'=>$password,'radius_secret'=>$radiusSecret],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) throw new RuntimeException('Não foi possível serializar a credencial do NAS.');
    return fs_encrypt_named_credential($payload,'NAS_CREDENTIAL_KEY',FS_NAS_CREDENTIAL_PREFIX,'Configure NAS_CREDENTIAL_KEY antes de cadastrar NAS próprios.');
}

/** @return array{username:string,password:string,radius_secret:string} */
function fs_nas_credentials_decrypt(string $encoded): array
{
    $plain=fs_decrypt_named_credential($encoded,'NAS_CREDENTIAL_KEY',FS_NAS_CREDENTIAL_PREFIX,'Configure NAS_CREDENTIAL_KEY para operar NAS próprios.');
    $payload=json_decode($plain,true);
    if (!is_array($payload)||trim((string)($payload['username']??''))===''||(string)($payload['password']??'')===''||(string)($payload['radius_secret']??'')==='') {
        throw new RuntimeException('Credencial do NAS incompleta ou corrompida.');
    }
    return ['username'=>(string)$payload['username'],'password'=>(string)$payload['password'],'radius_secret'=>(string)$payload['radius_secret']];
}

function fs_nas_credentials_for_operation(PDO $pdo, array $nas): array
{
    $nasId=(int)($nas['id']??0);
    if ($nasId<=0) return $nas;
    try {
        $statement=$pdo->prepare("SELECT credentials_ciphertext,status,host_key_fingerprint FROM partner_nas_ownerships WHERE nas_id=? AND management_mode='partner_owned' LIMIT 1");
        $statement->execute([$nasId]);
        $ownership=$statement->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $nas;
    }
    if (!$ownership) return $nas;
    if ((string)$ownership['status']==='retired') throw new RuntimeException('O NAS está aposentado.');
    $encoded=(string)($ownership['credentials_ciphertext']??'');
    if ($encoded==='') throw new RuntimeException('As credenciais de gerenciamento deste NAS foram revogadas.');
    $credentials=fs_nas_credentials_decrypt($encoded);
    $nas['mgmt_username']=$credentials['username'];
    $nas['mgmt_password']=$credentials['password'];
    $nas['secret']=$credentials['radius_secret'];
    $nas['expected_host_key_fingerprint']=trim((string)($ownership['host_key_fingerprint']??''));
    $nas['host_key_required']=true;
    return $nas;
}
