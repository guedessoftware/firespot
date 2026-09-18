<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/payment_provider.php';

$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{
    $checks++;
    if(!$condition)throw new RuntimeException($message);
};

$secret='fixture-webhook-secret';
$timestamp=1704908010;
$dataId='999999999';
$requestId='2066ca19-c6f1-498a-be75-1923005edd06';
$manifest='id:'.$dataId.';request-id:'.$requestId.';ts:'.$timestamp.';';
$hash=hash_hmac('sha256',$manifest,$secret);
$wallet=['webhook_secret'=>$secret,'webhook_secret_configured_at'=>'2024-01-01 00:00:00'];

$expect(fs_payment_identity_api_base()==='https://api.mercadolibre.com','Validação de identidade não usa o endpoint oficial do Mercado Pago.');
$providerSource=(string)file_get_contents(__DIR__.'/../app/payment_provider.php');
$expect(str_contains($providerSource,'fs_payment_validate_access_token'),'Validação dedicada do Access Token não foi encontrada.');
$receiptsSource=(string)file_get_contents(__DIR__.'/../dashboard/recebimentos.php');
$centralSource=(string)file_get_contents(__DIR__.'/../app/partner_central.php');
$expect(substr_count($receiptsSource,'fs_payment_validate_access_token')===1&&substr_count($centralSource,'fs_payment_validate_wallet_capabilities')>=2,'Cadastro ou rotação da carteira ainda contorna a validação oficial do Access Token e da consulta de pagamentos.');

$valid=fs_payment_webhook_verify($wallet,'ts='.$timestamp.',v1='.$hash,$requestId,$dataId,$timestamp);
$expect($valid['configured']===true&&$valid['valid']===true&&$valid['reason']==='ok','Assinatura oficial válida foi rejeitada.');
$selfTest=fs_payment_webhook_self_test($wallet);
$expect($selfTest['configured']===true&&$selfTest['valid']===true&&$selfTest['reason']==='ok','Autoteste local da assinatura falhou.');

$reordered=fs_payment_webhook_verify($wallet,'v1='.$hash.', ts='.$timestamp,$requestId,$dataId,$timestamp);
$expect($reordered['valid']===true,'Parser depende indevidamente da ordem de ts e v1.');

$invalid=fs_payment_webhook_verify($wallet,'ts='.$timestamp.',v1='.str_repeat('0',64),$requestId,$dataId,$timestamp);
$expect($invalid['valid']===false&&$invalid['reason']==='signature_mismatch','Assinatura divergente foi aceita.');

$missing=fs_payment_webhook_verify($wallet,null,$requestId,$dataId,$timestamp);
$expect($missing['valid']===false&&$missing['reason']==='signature_missing','Ausência de x-signature não falhou.');

$malformed=fs_payment_webhook_verify($wallet,'ts=x,v1='.$hash,$requestId,$dataId,$timestamp);
$expect($malformed['valid']===false&&$malformed['reason']==='signature_malformed','Timestamp malformado foi aceito.');

$stale=fs_payment_webhook_verify($wallet,'ts='.$timestamp.',v1='.$hash,$requestId,$dataId,$timestamp+301,300);
$expect($stale['valid']===false&&$stale['reason']==='timestamp_out_of_tolerance','Replay fora da tolerância foi aceito.');

$delayedRetry=fs_payment_webhook_verify($wallet,'ts='.$timestamp.',v1='.$hash,$requestId,$dataId,$timestamp+900);
$expect($delayedRetry['valid']===true,'Retry legítimo foi rejeitado sem tolerância explicitamente configurada.');

$withoutRequestManifest='id:'.$dataId.';ts:'.$timestamp.';';
$withoutRequestHash=hash_hmac('sha256',$withoutRequestManifest,$secret);
$withoutRequest=fs_payment_webhook_verify($wallet,'ts='.$timestamp.',v1='.$withoutRequestHash,null,$dataId,$timestamp);
$expect($withoutRequest['valid']===true,'Manifesto oficial sem request-id não foi tratado.');

$unconfigured=fs_payment_webhook_verify([],null,null,$dataId,$timestamp);
$expect($unconfigured['configured']===false&&$unconfigured['reason']==='secret_not_configured','Carteira sem segredo não foi identificada.');

$expect(fs_payment_webhook_signature_required($wallet,['created_at'=>'2024-01-01 00:00:00'])===true,'Pedido no corte deveria exigir assinatura.');
$expect(fs_payment_webhook_signature_required($wallet,['created_at'=>'2023-12-31 23:59:59'])===false,'Pedido legado não preservou compatibilidade.');
$expect(fs_payment_webhook_signature_required(['webhook_secret'=>$secret],['created_at'=>'2026-09-06 00:00:00'])===false,'Segredo sem corte ativou exigência retroativa.');
$expect(fs_payment_webhook_signature_required(['webhook_secret'=>$secret,'webhook_secret_configured_at'=>'invalid'],['created_at'=>'2026-09-06 00:00:00'])===true,'Corte inválido rebaixou a segurança.');

$guestWebhook=(string)file_get_contents(__DIR__.'/../portal-v3/api/webhook.php');
$monetizationWebhook=(string)file_get_contents(__DIR__.'/../dashboard/api/monetization_webhook.php');
$checkout=(string)file_get_contents(__DIR__.'/../portal-v3/api/checkout_create.php');
$monetization=(string)file_get_contents(__DIR__.'/../app/monetization.php');
$expect(str_contains($guestWebhook,"REQUEST_METHOD")&&str_contains($guestWebhook,"!== 'POST'"),'Webhook de acesso aceita métodos não POST.');
$expect(str_contains($guestWebhook,'fs_payment_webhook_verify')&&str_contains($guestWebhook,'fs_payment_webhook_signature_required'),'Webhook de acesso não aplica a assinatura oficial e o corte.');
$expect(str_contains($guestWebhook,'provider_payment_id')&&str_contains($guestWebhook,'409'),'Webhook de acesso não fixa o pagamento ao pedido.');
$expect(str_contains($monetizationWebhook,'fs_payment_webhook_verify')&&str_contains($monetizationWebhook,'provider_payment_id'),'Webhook de monetização não aplica as mesmas garantias.');
$expect(str_contains($checkout,'source_news=webhooks')&&str_contains($monetization,'source_news=webhooks'),'Novas cobranças ainda permitem notificação IPN legada.');
$expect(str_contains($guestWebhook,'v3_webhook_reply(503,false)')&&str_contains($monetizationWebhook,'monetization_webhook_reply(503,false)'),'Falha transitória não solicita nova tentativa do provedor.');

echo "OK: {$checks} verificações de segurança dos webhooks passaram.\n";
