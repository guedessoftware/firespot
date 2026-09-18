<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();admin_require_capability('partners.view');
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/control_center_partners.php';
require_once __DIR__ . '/../portal-v3/_boot.php';

$partnerId=max(0,(int)($_GET['partner_id']??0));$stage=(string)($_GET['stage']??'welcome');$viewport=(string)($_GET['viewport']??'desktop');
if(!in_array($stage,['welcome','options','plans'],true))$stage='welcome';
if(!in_array($viewport,['mobile','desktop'],true))$viewport='desktop';
$pdo=db();$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
try{
    $partner=partner_central_partner($pdo,$partnerId);
    $presentation=fs_portal_presentation_get($pdo,$partnerId,'draft')?:fs_portal_presentation_get($pdo,$partnerId,'candidate');
    if(!$presentation)throw new RuntimeException('Nenhuma apresentação em preparação.');
    $configId=(int)($presentation['portal_configuration_id']??0);
    $config=$configId>0?fs_portal_config_by_id($pdo,$partnerId,$configId):null;
    $config=$config?:fs_portal_config_get($pdo,$partnerId,'draft')?:fs_portal_config_for_partner($pdo,$partner);
    $theme=portal_theme_get($pdo,$partner,[]);
    $hasCourtesy=fs_portal_config_has_courtesy($config);$hasSales=fs_portal_config_has_sales($config);
    $isSponsored=fs_portal_config_is_sponsored($config);
    $subscriber=(string)($config['subscriber_access_mode']??'inherit')!=='deny';
    $plans=$hasSales?[
        ['id'=>1,'source'=>'global','name'=>'Acesso rápido','price_cents'=>500,'duration_minutes'=>60,'download_kbps'=>10000,'upload_kbps'=>5000],
        ['id'=>2,'source'=>'partner','name'=>'Dia completo','price_cents'=>1500,'duration_minutes'=>1440,'download_kbps'=>20000,'upload_kbps'=>10000],
    ]:[];
    $vm=fs_portal_view_model([
        'preview'=>true,'preview_viewport'=>$viewport,'stage'=>$stage,'partner_id'=>$partnerId,'presentation'=>$presentation,'theme'=>$theme,
        'has_courtesy'=>$hasCourtesy,'is_sponsored'=>$isSponsored,'courtesy_minutes'=>20,'courtesy_can_start'=>true,'courtesy_url'=>'courtesy.php?hotspot=PREVIEW',
        'has_sales'=>$hasSales,'plans_url'=>'index.php?hotspot=PREVIEW&step=plans','subscriber_enabled'=>$subscriber,'subscriber_url'=>'subscriber.php?hotspot=PREVIEW',
        'plans'=>$plans,'checkout_url'=>'checkout.php','back_url'=>'index.php?hotspot=PREVIEW','hotspot_query'=>'hotspot=PREVIEW',
    ]);
    require fs_portal_skin_view_file((string)$vm['skin']['code']);
}catch(Throwable $error){http_response_code(404);echo '<!doctype html><meta charset="utf-8"><title>Prévia indisponível</title><p>'.htmlspecialchars(admin_public_error($error,'Prévia indisponível.'),ENT_QUOTES,'UTF-8').'</p>';}
