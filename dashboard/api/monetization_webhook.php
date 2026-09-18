<?php

declare(strict_types=1);

@ini_set('display_errors','0');
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/monetization.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function monetization_webhook_reply(int $status,bool $ok):void
{
    http_response_code($status);
    echo json_encode(['ok'=>$ok]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') monetization_webhook_reply(405,false);
$public=strtolower(trim((string)($_GET['order']??'')));
$internalSignature=strtolower(trim((string)($_GET['sig']??'')));
if(!preg_match('/^[a-f0-9]{32}$/D',$public)
    ||!preg_match('/^[a-f0-9]{64}$/D',$internalSignature)
    ||!hash_equals(fs_monetization_order_signature($public),$internalSignature)) {
    monetization_webhook_reply(401,false);
}

$raw=(string)file_get_contents('php://input');
$payload=$raw!==''?json_decode($raw,true):[];
if(!is_array($payload))monetization_webhook_reply(400,false);
$signedDataId=trim((string)($_GET['data_id']??''));
$legacyPaymentId=trim((string)($_GET['id']??''));
$bodyPaymentId=trim((string)($payload['data']['id']??$payload['id']??''));
if($signedDataId!==''&&$bodyPaymentId!==''&&!hash_equals($signedDataId,$bodyPaymentId))monetization_webhook_reply(400,false);
$paymentId=$signedDataId!==''?$signedDataId:($bodyPaymentId!==''?$bodyPaymentId:$legacyPaymentId);
if(!preg_match('/^[0-9]{1,32}$/D',$paymentId))monetization_webhook_reply(400,false);
$eventType=strtolower(trim((string)($payload['type']??$_GET['type']??$_GET['topic']??'')));
if($eventType!==''&&$eventType!=='payment')monetization_webhook_reply(400,false);

try{
    $pdo=db();
    $st=$pdo->prepare('SELECT * FROM monetization_orders WHERE public_id=? LIMIT 1');
    $st->execute([$public]);
    $order=$st->fetch(PDO::FETCH_ASSOC);
    if(!$order)monetization_webhook_reply(404,false);
    $storedPaymentId=trim((string)($order['provider_payment_id']??''));
    if($storedPaymentId!==''&&!hash_equals($storedPaymentId,$paymentId))monetization_webhook_reply(409,false);

    $wallet=fs_global_wallet();
    $verification=fs_payment_webhook_verify($wallet,$_SERVER['HTTP_X_SIGNATURE']??null,$_SERVER['HTTP_X_REQUEST_ID']??null,$signedDataId!==''?$signedDataId:null);
    $signatureRequired=fs_payment_webhook_signature_required($wallet,$order);
    $providerHeaderPresent=trim((string)($_SERVER['HTTP_X_SIGNATURE']??''))!=='';
    if(($signatureRequired||(!empty($verification['configured'])&&$providerHeaderPresent))&&empty($verification['valid'])){
        error_log('[monetization webhook auth] rejected reason='.(string)$verification['reason']);
        monetization_webhook_reply(401,false);
    }

    $payment=fs_payment_get($wallet,$paymentId);
    fs_monetization_order_mark($pdo,$order,$payment);
    monetization_webhook_reply(200,true);
}catch(Throwable $e){
    error_log('[monetization webhook] '.get_class($e));
    monetization_webhook_reply(503,false);
}

