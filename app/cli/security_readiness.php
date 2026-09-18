<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once dirname(__DIR__).'/db.php';
require_once dirname(__DIR__).'/radius_db.php';
require_once dirname(__DIR__).'/integration_credentials.php';
require_once dirname(__DIR__).'/credential_crypto.php';
require_once dirname(__DIR__).'/hubsoft_cache.php';

$pdo=db();$radius=fs_radius_db();
$walletTotal=0;$walletSigned=0;
try{
    $walletTotal=(int)$pdo->query("SELECT COUNT(*) FROM payment_wallets WHERE active=1")->fetchColumn();
    foreach($pdo->query("SELECT webhook_secret_encrypted FROM payment_wallets WHERE active=1") as $wallet){$encoded=(string)($wallet['webhook_secret_encrypted']??'');if($encoded!==''&&str_starts_with($encoded,'sb1:')){try{if(trim(fs_decrypt_credential($encoded))!=='')$walletSigned++;}catch(Throwable $ignored){}}}
}catch(Throwable $ignored){}
$hubsoft=fs_integration_hubsoft_status($pdo);
$hubsoftCapability=fs_hubsoft_capability_status($pdo);
$mode=static function(string $path):?string{clearstatcache(true,$path);return file_exists($path)?substr(sprintf('%o',fileperms($path)),-4):null;};
$configOutput=[];$configExit=1;exec('apache2ctl configtest 2>&1',$configOutput,$configExit);
$certificateOnDisk=is_file('/etc/letsencrypt/live/firecdn.com.br/fullchain.pem')&&is_file('/etc/letsencrypt/live/firecdn.com.br/privkey.pem');
$liveCertificateReady=false;
if(function_exists('stream_socket_client')&&function_exists('openssl_x509_parse')){
    $context=stream_context_create(['ssl'=>['capture_peer_cert'=>true,'verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>'firecdn.com.br','SNI_enabled'=>true]]);
    $socket=@stream_socket_client('ssl://127.0.0.1:443',$socketError,$socketMessage,5,STREAM_CLIENT_CONNECT,$context);
    if(is_resource($socket)){
        $parameters=stream_context_get_params($socket);$certificate=$parameters['options']['ssl']['peer_certificate']??null;$parsed=$certificate?openssl_x509_parse($certificate):false;
        $liveCertificateReady=is_array($parsed)&&(int)($parsed['validTo_time_t']??0)>time()+604800;
        fclose($socket);
    }
}
$httpStatus=static function(string $url,?string $resolve=null):int{
    if(!function_exists('curl_init'))return 0;
    $curl=curl_init($url);$options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
    if($resolve!==null)$options[CURLOPT_RESOLVE]=[$resolve];curl_setopt_array($curl,$options);curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return$status;
};
$httpsReady=$httpStatus('https://firecdn.com.br/','firecdn.com.br:443:127.0.0.1')>=200;
$httpRedirectStatus=$httpStatus('http://firecdn.com.br/','firecdn.com.br:80:127.0.0.1');
$privatePathStatus=$httpStatus('https://firecdn.com.br/app/','firecdn.com.br:443:127.0.0.1');
$vhostLiveReady=$httpsReady&&in_array($httpRedirectStatus,[301,308],true)&&in_array($privatePathStatus,[403,404],true);
$certificateReady=($certificateOnDisk&&$configExit===0)||($liveCertificateReady&&$httpsReady);
$staged='/opt/firespot-ops/firespot_security_finalize_root.sh';
$rootEvidence=(glob('/var/backups/firespot-incident-*',GLOB_ONLYDIR)?:[]);
$internalKeys=[];foreach(['INTERNAL_API_KEY','APP_KEY','ACCOUNT_DELETION_AUDIT_KEY','PERSONAL_DATA_KEY'] as $name)$internalKeys[$name]=trim((string)env($name,''))!=='';$personalRotationPending=filter_var(env('PERSONAL_DATA_KEY_ROTATION_PENDING','0'),FILTER_VALIDATE_BOOLEAN);
$dbUser=(string)$pdo->query('SELECT CURRENT_USER()')->fetchColumn();$radiusUser=(string)$radius->query('SELECT CURRENT_USER()')->fetchColumn();
$permissionReady=$mode('/var/www/html/hotspot')==='0755'&&$mode('/var/www/html/hotspot/dashboard')==='0755'&&$mode('/var/www/html/hotspot/dashboard/assets')==='0755';
$baseCredentialRotationReady=str_starts_with($dbUser,'firespot_app@')&&str_starts_with($radiusUser,'firespot_radius_app@')&&$internalKeys['INTERNAL_API_KEY']&&$internalKeys['APP_KEY']&&$internalKeys['ACCOUNT_DELETION_AUDIT_KEY'];
$credentialRotationReady=$baseCredentialRotationReady&&$internalKeys['PERSONAL_DATA_KEY']&&!$personalRotationPending;
$report=[
    ['id'=>1,'demand'=>'Assinatura do webhook Mercado Pago','state'=>$walletTotal>0&&$walletSigned===$walletTotal?'ready':'pending','detail'=>$walletSigned.'/'.$walletTotal.' carteira(s) ativa(s) com segredo legível'],
    ['id'=>2,'demand'=>'Certificado em disco','state'=>$certificateReady?'ready':'pending','detail'=>'TLS ativo='.($liveCertificateReady?'válido por mais de 7 dias':'não validado').'; configtest='.($configExit===0?'ok':($liveCertificateReady?'restrito a root':'falhou'))],
    ['id'=>3,'demand'=>'Permissões dos três diretórios','state'=>$permissionReady?'ready':'pending','detail'=>'raiz='.($mode('/var/www/html/hotspot')??'ausente').'; dashboard='.($mode('/var/www/html/hotspot/dashboard')??'ausente').'; assets='.($mode('/var/www/html/hotspot/dashboard/assets')??'ausente')],
    ['id'=>4,'demand'=>'Vhost endurecido','state'=>$configExit===0||$vhostLiveReady?'ready':'staged','detail'=>'HTTPS='.($httpsReady?'ok':'falhou').'; HTTP='.$httpRedirectStatus.'; caminho_privado='.$privatePathStatus],
    ['id'=>5,'demand'=>'Rotação de credenciais','state'=>$credentialRotationReady?'ready':($baseCredentialRotationReady?'partial':'pending'),'detail'=>'app_db='.strtok($dbUser,'@').'; radius_db='.strtok($radiusUser,'@').'; PERSONAL_DATA_KEY='.(!$internalKeys['PERSONAL_DATA_KEY']?'pendente':($personalRotationPending?'preservada/migração pendente':'separada'))],
    ['id'=>6,'demand'=>'Evidências privilegiadas','state'=>$rootEvidence?'ready':'partial','detail'=>$rootEvidence?'coleta root detectada':'182 arquivos na coleta restrita; complemento root pendente'],
    ['id'=>7,'demand'=>'HubSoft','state'=>!empty($hubsoft['configured'])&&!empty($hubsoft['active'])&&!empty($hubsoft['last_test_ok'])&&!empty($hubsoftCapability['ok'])?'ready':'pending','detail'=>!empty($hubsoft['requires_full_credentials'])?'reenviar conjunto completo e testar OAuth':(empty($hubsoft['last_test_ok'])?'executar teste após salvar':(!empty($hubsoftCapability['ok'])?'OAuth e consulta de clientes validados':'OAuth validado; consulta de clientes='.$hubsoftCapability['code']))],
];
foreach($report as $item)printf("[%s] %d. %s — %s\n",strtoupper($item['state']),$item['id'],$item['demand'],$item['detail']);
exit(count(array_filter($report,static fn(array $item):bool=>$item['state']!=='ready'))===0?0:2);
