<?php

require_once __DIR__ . '/../_boot.php';
require_once __DIR__ . '/../../app/portal_v3_payment_window.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !csrf_check((string) ($input['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
    exit;
}
$publicId = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($input['order'] ?? '')));

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $partner = v3_resolve_partner($pdo);
    if (!$partner) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'partner_not_found']);
        exit;
    }
    $order = fs_guest_order_for_session($pdo, $publicId, v3_order_token($publicId));
    if (!$order || (int) $order['partner_id'] !== (int) $partner['id']) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'order_not_found']);
        exit;
    }
    $partner = v3_context_for_order($pdo,$order,$partner);

    $pix = v3_pending_pix($publicId);
    $providerStatus = '';
    if ($order['status'] === 'pending' && !empty($order['provider_payment_id'])) {
        try {
            $wallet = fs_guest_wallet_for_order($pdo, $order);
            $payment = fs_payment_get($wallet, (string) $order['provider_payment_id']);
            $providerStatus = strtolower((string)($payment['status'] ?? ''));
            $pix = v3_remember_pending_pix($publicId,$payment) ?? $pix;
            $order = fs_guest_mark_from_provider($pdo, $order, $payment);
        } catch (Throwable $e) {
            error_log('[portal-v3 status provider] ' . $e->getMessage());
        }
    }

    if ($order['status'] === 'pending' && fs_guest_payment_is_expired($order)) {
        $stExpired = $pdo->prepare("UPDATE guest_orders
            SET status='cancelled',payment_status_detail='expired',updated_at=NOW()
            WHERE id=? AND status='pending'");
        $stExpired->execute([(int)$order['id']]);
        if ($stExpired->rowCount() === 1) {
            $order['status'] = 'cancelled';
            $order['payment_status_detail'] = 'expired';
        }
    }

    $failedStatus = in_array((string)$order['status'],['payment_failed','cancelled','refunded'],true);
    $expiredPayment = $providerStatus === 'expired'
        || strtolower((string)($order['payment_status_detail'] ?? '')) === 'expired'
        || fs_guest_payment_is_expired($order);
    if ($failedStatus && $expiredPayment) {
        fs_v3_payment_window_close($pdo,$partner,$order);
        fs_v3_payment_window_release_expired_order($pdo,(int)$order['id']);
    }

    $granted = !empty($order['access_granted_at']);
    if ($order['status'] === 'paid') {
        $order = fs_guest_finalize_paid_access($pdo, (int) $order['id']);
        $granted = !empty($order['access_granted_at']);
    }
    if ($order['status'] === 'paid' && $granted) {
        v3_forget_pending_pix($publicId);
        if ((string)($order['payment_access_mode'] ?? '') === FS_GUEST_PAYMENT_ACCESS_RADIUS
            && (string)($order['radius_coa_status'] ?? '') === 'applied') {
            fs_v3_payment_window_close($pdo,$partner,$order,false);
        }
    } elseif ($order['status'] === 'pending'
        && !empty($order['payment_window_token'])
        && empty($order['payment_window_closed_at'])
        && (strtotime((string)($order['payment_window_expires_at'] ?? '')) ?: PHP_INT_MAX) <= time()) {
        // O temporizador do RouterOS já remove o bypass. Esta confirmação
        // idempotente sincroniza o histórico antes de devolver o login HTTP.
        if (fs_v3_payment_window_close($pdo,$partner,$order)) {
            $order['payment_window_closed_at'] = date('Y-m-d H:i:s');
        }
    }
    $credit = $order['status'] === 'paid'
        ? fs_guest_credit_balance($pdo, $order)
        : ['remaining_seconds' => 0,'online'=>false];
    $radiusHandoff = (string)($order['payment_access_mode'] ?? '') === FS_GUEST_PAYMENT_ACCESS_RADIUS;
    $activeDeviceSession = null;
    if ($granted && $order['status'] === 'paid') {
        try {
            $activeDeviceSession = fs_guest_radius_active_session_for_device($pdo,$order,v3_device_context());
        } catch (Throwable $sessionError) {
            error_log('[portal-v3 status session] ' . get_class($sessionError));
        }
    }
    $connected = $granted && is_array($activeDeviceSession)
        && (!$radiusHandoff || (string)($order['radius_coa_status'] ?? '') === 'applied');
    $handoffRetryAfter = 0;
    if ($radiusHandoff && !$connected && !empty($credit['online'])) {
        $handoffRetryAfter = max(0,fs_guest_local_datetime_timestamp((string)($order['radius_provisional_expires_at'] ?? ''))-time());
    }
    $paymentWindow = $order['status'] === 'pending'
        ? fs_v3_payment_window_status($pdo,$partner,$order)
        : ['enabled'=>false,'state'=>'not_applicable','can_start'=>false];
    $reauthUrl = $order['status'] === 'pending' ? fs_v3_payment_window_reauth_url($partner) : null;
    $restartUrl = null;
    if ($failedStatus) {
        v3_forget_order($publicId,(int)$partner['id']);
        $restartUrl = 'index.php?' . v3_hotspot_query($partner) . '&payment=' . ($expiredPayment ? 'expired' : 'not_approved');
    }
    echo json_encode([
        'ok' => true,
        'status' => $order['status'],
        'granted' => $granted,
        'remaining_seconds' => (int) $credit['remaining_seconds'],
        'connected' => $connected,
        'handoff_status' => $radiusHandoff ? (string)($order['radius_coa_status'] ?? 'pending') : 'legacy',
        'handoff_attempts' => $radiusHandoff ? (int)($order['radius_coa_attempts'] ?? 0) : 0,
        'handoff_retry_after' => $handoffRetryAfter,
        'connect_url' => $granted && !$connected ? 'connect.php?order=' . rawurlencode($publicId) : null,
        'payment_window' => $paymentWindow,
        'reauth_url' => $reauthUrl,
        'restart_url' => $restartUrl,
        'payment_expired' => $expiredPayment,
        'pix' => $order['status'] === 'pending' ? $pix : null,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[portal-v3 status] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'status_unavailable']);
}
