<?php

declare(strict_types=1);

@ini_set('display_errors','0');
require_once __DIR__ . '/../_boot.php';
require_once __DIR__ . '/../../app/portal_v3_payment_window.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function v3_webhook_reply(int $status, bool $ok): void
{
    http_response_code($status);
    echo json_encode(['ok'=>$ok]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') v3_webhook_reply(405,false);

$publicId = strtolower(trim((string)($_GET['order'] ?? '')));
$internalSignature = strtolower(trim((string)($_GET['sig'] ?? '')));
if (!preg_match('/^[a-f0-9]{32}$/D',$publicId)
    || !preg_match('/^[a-f0-9]{64}$/D',$internalSignature)
    || !hash_equals(fs_guest_webhook_signature($publicId),$internalSignature)) {
    v3_webhook_reply(401,false);
}

$raw = (string)file_get_contents('php://input');
$input = $raw !== '' ? json_decode($raw,true) : [];
if (!is_array($input)) v3_webhook_reply(400,false);

// PHP converte o parametro oficial `data.id` em `data_id`.
$signedDataId = trim((string)($_GET['data_id'] ?? ''));
$legacyPaymentId = trim((string)($_GET['id'] ?? ''));
$bodyPaymentId = trim((string)($input['data']['id'] ?? $input['id'] ?? ''));
if ($signedDataId !== '' && $bodyPaymentId !== '' && !hash_equals($signedDataId,$bodyPaymentId)) {
    v3_webhook_reply(400,false);
}
$paymentId = $signedDataId !== '' ? $signedDataId : ($bodyPaymentId !== '' ? $bodyPaymentId : $legacyPaymentId);
if (!preg_match('/^[0-9]{1,32}$/D',$paymentId)) v3_webhook_reply(400,false);

$eventType = strtolower(trim((string)($input['type'] ?? $_GET['type'] ?? $_GET['topic'] ?? '')));
if ($eventType !== '' && $eventType !== 'payment') v3_webhook_reply(400,false);

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $st = $pdo->prepare('SELECT * FROM guest_orders WHERE public_id=? LIMIT 1');
    $st->execute([$publicId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) v3_webhook_reply(404,false);

    $wallet = fs_guest_wallet_for_order($pdo,$order);
    $verification = fs_payment_webhook_verify(
        $wallet,
        $_SERVER['HTTP_X_SIGNATURE'] ?? null,
        $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        $signedDataId !== '' ? $signedDataId : null
    );
    $signatureRequired = fs_payment_webhook_signature_required($wallet,$order);
    $providerHeaderPresent = trim((string)($_SERVER['HTTP_X_SIGNATURE'] ?? '')) !== '';
    if (($signatureRequired || (!empty($verification['configured']) && $providerHeaderPresent))
        && empty($verification['valid'])) {
        error_log('[portal-v3 webhook auth] rejected reason=' . (string)$verification['reason']);
        v3_webhook_reply(401,false);
    }

    $storedPaymentId = trim((string)($order['provider_payment_id'] ?? ''));
    if ($storedPaymentId !== '' && !hash_equals($storedPaymentId,$paymentId)) {
        v3_webhook_reply(409,false);
    }

    $payment = fs_payment_get($wallet,$paymentId);
    $order = fs_guest_mark_from_provider($pdo,$order,$payment);
    if ($order['status'] === 'paid') {
        $order = fs_guest_finalize_paid_access($pdo,(int)$order['id']);
        $st = $pdo->prepare('SELECT * FROM partners WHERE id=? AND active=1 LIMIT 1');
        $st->execute([(int)$order['partner_id']]);
        $partner = $st->fetch(PDO::FETCH_ASSOC);
        // No fluxo novo, o CoA promove a sessao que ja esta autenticada e o
        // fechamento abaixo altera somente o ledger. Pedidos antigos mantem o
        // handoff pelo navegador ate a expiracao do bypass.
        if ($partner && !fs_v3_payment_window_paid_handoff_pending($order)) {
            fs_v3_payment_window_close($pdo,$partner,$order);
        }
    } elseif (strtolower((string)($payment['status'] ?? '')) === 'expired') {
        $st = $pdo->prepare('SELECT * FROM partners WHERE id=? AND active=1 LIMIT 1');
        $st->execute([(int)$order['partner_id']]);
        $partner = $st->fetch(PDO::FETCH_ASSOC);
        if ($partner) fs_v3_payment_window_close($pdo,$partner,$order);
        fs_v3_payment_window_release_expired_order($pdo,(int)$order['id']);
    }
    v3_webhook_reply(200,true);
} catch (Throwable $e) {
    // Falhas transitórias precisam gerar nova tentativa do provedor.
    error_log('[portal-v3 webhook] ' . get_class($e));
    v3_webhook_reply(503,false);
}

