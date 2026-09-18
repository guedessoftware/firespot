<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/marketplace.php';
require_once __DIR__ . '/../app/partner_admin.php';
require_once __DIR__ . '/../app/admin_auth.php';

$pdo=db();
$partnerContext=partner_admin_context($pdo);
$central=admin_is_authenticated();
$fallback=$partnerContext?'/admin/painel.php?page=monetization':'/dashboard/monetizacao.php';
try{
    $state=trim((string)($_GET['state']??''));$code=trim((string)($_GET['code']??''));
    if($state===''||$code==='')throw new RuntimeException('A autorização foi cancelada ou retornou incompleta.');
    $oauth=fs_marketplace_oauth_state($pdo,$state);if(!$oauth)throw new RuntimeException('Autorização OAuth expirada ou já utilizada.');
    $actorType=(string)$oauth['created_by_type'];
    if($actorType==='partner_admin'){
        $authorizedContext=partner_admin_require_feature_context($pdo,'wallet.manage','monetization','monetization.view',true);
        if((int)$authorizedContext['user_id']!==(int)$oauth['created_by_id']||(int)$authorizedContext['partner_id']!==(int)$oauth['partner_id'])throw new RuntimeException('A sessão que iniciou a autorização não está mais ativa.');
        $account=fs_marketplace_oauth_finish($pdo,$state,$code,'partner_admin',(int)$authorizedContext['user_id']);
        partner_admin_audit($pdo,(int)$account['partner_id'],'partner_admin',(int)$authorizedContext['user_id'],'marketplace.authorized','marketplace_account',(int)$account['id']);
        $_SESSION['host_flash']=['type'=>'success','message'=>'Conta Mercado Pago autorizada para o Marketplace.'];
        header('Location: /admin/painel.php?page=monetization',true,303);exit;
    }
    if(!$central||admin_id()<=0||(int)$oauth['created_by_id']!==admin_id())throw new RuntimeException('A sessão administrativa que iniciou a autorização não está mais ativa.');
    admin_require_capability('monetization.manage');
    $account=fs_marketplace_oauth_finish($pdo,$state,$code,'firespot',admin_id());
    $_SESSION['monetization_flash']=['ok'=>true,'message'=>'Conta Mercado Pago autorizada para o Marketplace.'];
    header('Location: /dashboard/monetizacao.php',true,303);exit;
}catch(Throwable $e){
    $message=$e instanceof RuntimeException||$e instanceof InvalidArgumentException?$e->getMessage():'Não foi possível concluir a autorização do Mercado Pago.';
    if($partnerContext)$_SESSION['host_flash']=['type'=>'error','message'=>substr($message,0,250)];else $_SESSION['monetization_flash']=['ok'=>false,'message'=>substr($message,0,250)];
    header('Location: '.$fallback,true,303);exit;
}
