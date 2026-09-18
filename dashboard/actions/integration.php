<?php

declare(strict_types=1);

require_once __DIR__.'/../../app/admin_auth.php';
admin_require_page();
require_once __DIR__.'/../../app/db.php';
require_once __DIR__.'/../../app/settings.php';
require_once __DIR__.'/../../app/subscriber_admin.php';
require_once __DIR__.'/../../app/hubsoft_cache.php';
require_once __DIR__.'/../../app/lib/promo_api.php';
require_once __DIR__.'/../../app/payment_provider.php';
require_once __DIR__.'/../../app/control_center_integrations.php';

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
    http_response_code(405);
    header('Allow: POST');
    echo 'Método não permitido.';
    exit;
}

$section=(string)($_POST['return_section']??'overview');
if(!in_array($section,['hubsoft','payments','messaging','adsense'],true))$section='overview';
$flash=['ok'=>false,'message'=>'Não foi possível atualizar a integração.'];
$testProvider=null;
try{
    if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sessão expirada. Recarregue a página.');
    admin_require_capability('system.integrations.manage');
    $pdo=db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $action=(string)($_POST['action']??'');
    if($action==='hubsoft_credentials_save'){
        fs_integration_hubsoft_save($pdo,$_POST,admin_id());
        $flash=['ok'=>true,'message'=>'Configuração HubSoft armazenada com criptografia.'];
        $section='hubsoft';
    }elseif($action==='hubsoft_connection_test'){
        $status=fs_subscriber_admin_hubsoft_status($pdo);
        if(empty($status['configured']))throw new RuntimeException('Salve e ative as credenciais técnicas antes do teste.');
        $capability=fs_hubsoft_capability_probe($pdo);
        try{fs_integration_hubsoft_record_test($pdo,!empty($capability['ok']),(string)($capability['code']??$capability['oauth_code']??'UNKNOWN'),admin_id());}catch(Throwable $auditError){}
        if(empty($capability['ok'])){
            if(($capability['code']??'')==='CLIENT_QUERY_HTTP_403')throw new RuntimeException('OAuth aceito, mas o HubSoft recusou a consulta pública de clientes (HTTP 403).');
            throw new RuntimeException('Teste funcional HubSoft falhou com o código seguro '.(string)($capability['code']??'UNKNOWN').'.');
        }
        $flash=['ok'=>true,'message'=>'OAuth e consulta pública de clientes validados.'];
        $section='hubsoft';
    }elseif($action==='payments_connection_test'){
        $testProvider='mercadopago';
        $wallet=fs_control_center_global_wallet($pdo,true);
        fs_wallet_assert_usable($wallet);
        fs_payment_validate_wallet_capabilities($wallet);
        fs_payment_webhook_self_test($wallet);
        if(($wallet['source']??'')==='database_global'&&(int)($wallet['id']??0)>0&&fs_payment_wallet_validation_schema_ready($pdo)){
            $validated=$pdo->prepare('UPDATE payment_wallets SET access_token_validated_at=NOW(),webhook_secret_validated_at=NOW(),updated_at=updated_at WHERE id=? AND partner_id IS NULL AND active=1');
            $validated->execute([(int)$wallet['id']]);
        }
        fs_control_center_record_integration_test($pdo,'mercadopago',true,'PAYMENT_AND_WEBHOOK_OK',admin_id());
        $flash=['ok'=>true,'message'=>'Access Token do Mercado Pago e assinatura local do webhook validados. Nenhum pagamento foi criado.'];
        $section='payments';
    }elseif($action==='hubsoft_cache_settings_save'){
        $runs=max(0,min(24,(int)($_POST['runs_per_day']??0)));
        settings_set('hubsoft_cache_runs_per_day',(string)$runs);
        $flash=['ok'=>true,'message'=>$runs===0?'Sincronização auxiliar desativada.':'Frequência da sincronização auxiliar atualizada.'];
        $section='hubsoft';
    }elseif($action==='messaging_save'){
        $enabled=!empty($_POST['promo_enabled'])?'1':'0';
        $base=promo_api_normalize_base($_POST['promo_api_base']??'');
        $user=trim((string)($_POST['promo_api_user']??''));
        $hash=trim((string)($_POST['promo_api_hash']??''));
        if($base===null)throw new InvalidArgumentException('Informe uma URL HTTPS válida e sem parâmetros.');
        if($user==='')throw new InvalidArgumentException('Informe o usuário técnico da mensageria.');
        if($hash==='')$hash=trim((string)settings_get('promo_api_hash',''));
        if($hash==='')throw new InvalidArgumentException('Informe o token da mensageria.');
        settings_set('promo_enabled',$enabled);
        settings_set('promo_api_base',$base);
        settings_set('promo_api_user',$user);
        settings_set('promo_api_hash',$hash);
        $flash=['ok'=>true,'message'=>'Integração de mensageria atualizada; o token não será exibido.'];
        $section='messaging';
    }elseif($action==='messaging_test'){
        $testProvider='messaging';
        $to=trim((string)($_POST['promo_test_to']??''));
        $message=trim((string)($_POST['promo_test_msg']??'Teste FireSpot: OK'));
        if($to==='')throw new InvalidArgumentException('Informe o destino do teste em formato internacional.');
        $result=promo_api_send($to,$message,['force'=>true]);
        if(empty($result['ok']))throw new RuntimeException('O provedor recusou o teste com o código seguro '.(string)($result['code']??'UNKNOWN').'.');
        fs_control_center_record_integration_test($pdo,'messaging',true,(string)($result['code']??'MESSAGE_ACCEPTED'),admin_id());
        $flash=['ok'=>true,'message'=>'Mensagem de teste aceita pelo provedor. O destino não foi registrado no aviso.'];
        $section='messaging';
    }elseif($action==='adsense_save'){
        $client=trim((string)($_POST['adsense_client']??''));
        $portal=trim((string)($_POST['adsense_slot_portal']??''));
        $video=trim((string)($_POST['adsense_slot_video']??''));
        if($client!==''&&!preg_match('/^ca-pub-[0-9]+$/',$client))throw new InvalidArgumentException('Client ID do AdSense inválido.');
        foreach([$portal,$video] as $slot)if($slot!==''&&!preg_match('/^[0-9]+$/',$slot))throw new InvalidArgumentException('Os slots do AdSense devem conter somente números.');
        settings_set('adsense_client',$client);
        settings_set('adsense_slot_portal',$portal);
        settings_set('adsense_slot_video',$video);
        settings_set('adsense_enabled',!empty($_POST['adsense_enabled'])?'1':'0');
        $flash=['ok'=>true,'message'=>'Integração Google AdSense atualizada.'];
        $section='adsense';
    }elseif($action==='adsense_configuration_test'){
        $testProvider='adsense';
        $client=trim((string)settings_get('adsense_client',env('ADSENSE_CLIENT','')));
        $portal=trim((string)settings_get('adsense_slot_portal',env('ADSENSE_SLOT_PORTAL','')));
        $video=trim((string)settings_get('adsense_slot_video',env('ADSENSE_SLOT_VIDEO','')));
        if($client===''||!preg_match('/^ca-pub-[0-9]+$/',$client))throw new RuntimeException('O Client ID do AdSense está ausente ou inválido.');
        foreach([$portal,$video] as $slot)if($slot!==''&&!preg_match('/^[0-9]+$/',$slot))throw new RuntimeException('Um slot do AdSense possui formato inválido.');
        fs_control_center_record_integration_test($pdo,'adsense',true,'IDENTIFIERS_VALID',admin_id());
        $flash=['ok'=>true,'message'=>'Identificadores do AdSense validados localmente. Nenhuma impressão ou receita foi simulada.'];
        $section='adsense';
    }else throw new InvalidArgumentException('Ação de integração inválida.');
}catch(Throwable $error){
    if($testProvider!==null&&isset($pdo)){
        try{fs_control_center_record_integration_test($pdo,$testProvider,false,strtoupper($testProvider).'_TEST_FAILED',admin_id());}catch(Throwable $ignored){}
    }
    $flash=['ok'=>false,'message'=>admin_public_error($error,'Não foi possível atualizar a integração.')];
}

$_SESSION['integrations_flash']=$flash;
header('Location: ../integracoes.php?'.http_build_query(['section'=>$section]),true,303);
exit;
