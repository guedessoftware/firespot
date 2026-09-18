<?php

declare(strict_types=1);

@ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/session_boot.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/ad_monetization.php';

function ad_lead_response(int $status,array $payload):void{http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')ad_lead_response(405,['ok'=>false,'error'=>'Método não permitido.']);
$input=$_POST;
if(!$input){$decoded=json_decode((string)file_get_contents('php://input'),true);if(is_array($decoded))$input=$decoded;}
if(!csrf_check((string)($input['csrf']??'')))ad_lead_response(403,['ok'=>false,'error'=>'Sessão expirada. Recarregue a página.']);
try{
    $lead=fs_ad_create_lead(db(),trim((string)($input['delivery_token']??'')),(string)($input['name']??''),(string)($input['phone']??''),!empty($input['consent']));
    ad_lead_response(200,['ok'=>true,'lead'=>$lead['public_id'],'duplicate'=>$lead['duplicate'],'message_queued'=>$lead['message_queued'],'notice'=>$lead['duplicate']?'Você já solicitou esta oferta recentemente.':'Oferta registrada. A conexão continuará normalmente.']);
}catch(InvalidArgumentException $e){ad_lead_response(422,['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('[ad lead] '.get_class($e).': '.$e->getMessage());ad_lead_response(409,['ok'=>false,'error'=>'Não foi possível registrar seu interesse agora. Você ainda pode continuar para conectar.']);}

