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
    echo json_encode(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$publicId = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($input['order'] ?? '')));

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $partner = v3_resolve_partner($pdo);
    if (!$partner) throw new RuntimeException('Estabelecimento não encontrado.');
    $order = fs_guest_order_for_session($pdo, $publicId, v3_order_token($publicId));
    if (!$order || (int) $order['partner_id'] !== (int) $partner['id']) {
        throw new RuntimeException('Pedido não encontrado nesta sessão.');
    }
    $partner = v3_context_for_order($pdo,$order,$partner);
    if ((string) ($order['payment_method'] ?? '') !== 'pix') {
        throw new RuntimeException('A janela temporária é exclusiva do pagamento por Pix.');
    }

    $window = fs_v3_payment_window_start($pdo, $partner, $order);
    $windowPolicy = fs_v3_payment_window_status($pdo,$partner,$order);
    $connectUrl = !empty($window['connect_required'])
        ? 'payment-connect.php?order=' . rawurlencode($publicId)
        : null;
    echo json_encode(['ok' => true] + $window + ['payment_window'=>$windowPolicy,'connect_url'=>$connectUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (FsV3PaymentWindowLimit $e) {
    error_log('[portal-v3 payment window limit] ' . $e->getMessage());
    http_response_code(429);
    header('Retry-After: ' . $e->retryAfter());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'retry_after' => $e->retryAfter(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[portal-v3 payment window] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'Não foi possível liberar a internet temporária. O Pix continua válido; use dados móveis ou outro aparelho para pagar.',
    ], JSON_UNESCAPED_UNICODE);
}
