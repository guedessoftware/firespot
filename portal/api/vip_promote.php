<?php
@ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/session_boot.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/vip_order_session.php';
require_once __DIR__ . '/radius_vip.php';
require_once __DIR__ . '/../../app/mp_client.php';

header('Content-Type: application/json; charset=utf-8');

function vip_promote_error(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'err' => $error]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    vip_promote_error(405, 'method_not_allowed');
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrf = (string) ($input['csrf_token'] ?? $input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!csrf_check($csrf)) {
    vip_promote_error(403, 'csrf_invalid');
}

$ref = trim((string) ($input['ref'] ?? ''));
if ($ref === '') {
    vip_promote_error(400, 'missing_ref');
}

$isAdmin = isset($_SESSION['admin']) && $_SESSION['admin'] === true;
if (!$isAdmin && !vip_order_session_owns($ref)) {
    vip_promote_error(403, 'order_session_mismatch');
}

try {
    $pdo = db();
    $st = $pdo->prepare('SELECT status, mp_payment_id FROM vip_orders WHERE external_ref=? LIMIT 1');
    $st->execute([$ref]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        vip_promote_error(404, 'order_not_found');
    }

    if ((string) $order['status'] !== 'paid') {
        $paymentId = trim((string) ($order['mp_payment_id'] ?? ''));
        if ($paymentId === '') {
            vip_promote_error(409, 'payment_not_confirmed');
        }

        $payment = mp_get_payment($paymentId);
        if (($payment['status'] ?? '') !== 'approved' || (string) ($payment['external_reference'] ?? '') !== $ref) {
            vip_promote_error(409, 'payment_not_confirmed');
        }

        $pdo->prepare("UPDATE vip_orders SET status='paid', paid_at=COALESCE(paid_at,NOW()), updated_at=NOW() WHERE external_ref=?")
            ->execute([$ref]);
    }

    echo json_encode(vip_apply_radius($ref, []));
} catch (Throwable $e) {
    error_log('[vip_promote] ' . $e->getMessage());
    vip_promote_error(500, $isAdmin ? $e->getMessage() : 'vip_activation_failed');
}
