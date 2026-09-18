<?php

declare(strict_types=1);

@ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../_boot.php';

function v3_ad_platform_response(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')v3_ad_platform_response(405,['ok'=>false,'error'=>'Método não permitido.']);
if(!csrf_check($_POST['csrf']??''))v3_ad_platform_response(403,['ok'=>false,'error'=>'Sessão expirada. Recarregue a página.']);

try{
    $pdo=db();
    $partner=v3_resolve_partner($pdo);
    if(!$partner)throw new RuntimeException('Estabelecimento indisponível.');
    $partnerId=(int)$partner['id'];$hotspotId=(int)(fs_partner_hotspot_id($partner)??0);
    $token=trim((string)($_POST['token']??''));$event=trim((string)($_POST['event']??''));
    $requestId=substr(trim((string)($_POST['provider_request_id']??'')),0,190)?:null;
    $delivery=fs_ad_platform_delivery_event($pdo,$token,$event,$partnerId,$hotspotId,$requestId);
    $payload=['ok'=>true,'state'=>(string)$delivery['state'],'completed'=>(int)$delivery['completed_ad_count'],'required'=>(int)$delivery['required_ad_count'],'simulation'=>(int)$delivery['is_simulation']===1];
    if($event==='accept'){
        $payload['provider']=(string)$delivery['provider_code'];
        $payload['ad_unit_path']=(string)($delivery['google_ad_unit_path']??'');
        $payload['test_mode']=(int)$delivery['platform_test_mode']===1;
        $payload['privacy_treatment']=(string)$delivery['privacy_treatment'];
    }
    v3_ad_platform_response(200,$payload);
}catch(Throwable $error){
    error_log('[portal-v3 ad platform] '.get_class($error).': '.$error->getMessage());
    v3_ad_platform_response(409,['ok'=>false,'error'=>'Não foi possível validar a exibição publicitária.','code'=>'AD_PLATFORM_EVENT_REJECTED']);
}
