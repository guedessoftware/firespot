<?php
declare(strict_types=1);
require_once __DIR__.'/_boot.php';
require_once __DIR__.'/../../app/partner_central.php';
require_once __DIR__.'/../../portal-v3/_boot.php';

$pdo=host_db();
try{
    $context=partner_admin_require_feature_context($pdo,'theme.manage','portal','portal.presentation.manage',false);
    $partnerId=(int)$context['partner_id'];
    $stage=(string)($_GET['stage']??'welcome');if(!in_array($stage,['welcome','options','plans'],true))$stage='welcome';
    $viewport=(string)($_GET['viewport']??'desktop');if(!in_array($viewport,['mobile','desktop'],true))$viewport='desktop';
    $presentation=fs_portal_presentation_get($pdo,$partnerId,'draft')?:fs_portal_presentation_get($pdo,$partnerId,'candidate');
    if(!$presentation)throw new RuntimeException('Nenhuma apresentação em preparação.');
    $configId=(int)($presentation['portal_configuration_id']??0);
    $config=$configId>0?fs_portal_config_by_id($pdo,$partnerId,$configId):null;
    $config=$config?:fs_portal_config_get($pdo,$partnerId,'draft')?:fs_portal_config_for_partner($pdo,$context);
    $theme=portal_theme_get($pdo,$context,[]);$hasSales=fs_portal_config_has_sales($config);
    $plans=$hasSales?[
        ['id'=>1,'source'=>'global','name'=>'Acesso rápido','price_cents'=>500,'duration_minutes'=>60,'download_kbps'=>10000,'upload_kbps'=>5000],
        ['id'=>2,'source'=>'partner','name'=>'Dia completo','price_cents'=>1500,'duration_minutes'=>1440,'download_kbps'=>20000,'upload_kbps'=>10000],
    ]:[];
    $vm=fs_portal_view_model([
        'preview'=>true,'preview_asset_base'=>'../../portal-v3/','preview_viewport'=>$viewport,'stage'=>$stage,'partner_id'=>$partnerId,'presentation'=>$presentation,'theme'=>$theme,
        'has_courtesy'=>fs_portal_config_has_courtesy($config),'is_sponsored'=>fs_portal_config_is_sponsored($config),'courtesy_minutes'=>20,'courtesy_can_start'=>true,'courtesy_url'=>'courtesy.php?hotspot=PREVIEW',
        'has_sales'=>$hasSales,'plans_url'=>'index.php?hotspot=PREVIEW&step=plans','subscriber_enabled'=>(string)($config['subscriber_access_mode']??'inherit')!=='deny','subscriber_url'=>'subscriber.php?hotspot=PREVIEW',
        'plans'=>$plans,'checkout_url'=>'checkout.php','back_url'=>'index.php?hotspot=PREVIEW','hotspot_query'=>'hotspot=PREVIEW',
    ]);
    header('Cache-Control: no-store');
    require fs_portal_skin_view_file((string)$vm['skin']['code']);
}catch(Throwable $error){http_response_code(404);echo '<!doctype html><meta charset="utf-8"><title>Prévia indisponível</title><p>'.host_h(partner_admin_public_error($error,'Prévia indisponível.')).'</p>';}
