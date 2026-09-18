<?php
@ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/session_boot.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/mp_client.php';
require_once __DIR__ . '/../../app/vip_order_session.php';

header('Content-Type: application/json; charset=utf-8');

function payment_status_error(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    payment_status_error(405, 'method_not_allowed');
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrf = (string) ($input['csrf_token'] ?? $input['csrf'] ?? '');
if (!csrf_check($csrf)) {
    payment_status_error(403, 'csrf_invalid');
}

$ref = trim((string) ($input['ref'] ?? ''));
if ($ref === '') {
    payment_status_error(400, 'missing_ref');
}

$isAdmin = isset($_SESSION['admin']) && $_SESSION['admin'] === true;
if (!$isAdmin && !vip_order_session_owns($ref)) {
    payment_status_error(403, 'order_session_mismatch');
}

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $st = $pdo->prepare('SELECT id,status,mp_payment_id,payment_expires_at FROM vip_orders WHERE external_ref=? LIMIT 1');
    $st->execute([$ref]);
    $order = $st->fetch();
    if (!$order) {
        payment_status_error(404, 'order_not_found');
    }

    if ((string) $order['status'] === 'paid') {
        echo json_encode(['ok' => true, 'status' => 'paid']);
        exit;
    }

    $tzManaus = new DateTimeZone('America/Manaus');
    $expiresAt = null;
    if (!empty($order['payment_expires_at'])) {
        $expiresAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $order['payment_expires_at'], $tzManaus);
        if (!$expiresAt) {
            try {
                $expiresAt = new DateTimeImmutable((string) $order['payment_expires_at'], $tzManaus);
            } catch (Throwable $e) {
                $expiresAt = null;
            }
        }
    }

    $mpStatus = '';
    if (!empty($order['mp_payment_id'])) {
        try {
            $payment = mp_get_payment((string) $order['mp_payment_id']);
            $mpStatus = strtolower((string) ($payment['status'] ?? ''));
            $paymentRef = trim((string) ($payment['external_reference'] ?? ''));
            if ($paymentRef !== $ref) {
                error_log('[payment_status] Referência divergente para a ordem ' . (int) $order['id']);
                payment_status_error(409, 'payment_reference_mismatch');
            }
        } catch (Throwable $e) {
            error_log('[payment_status] Falha ao consultar provedor: ' . $e->getMessage());
            $mpStatus = '';
        }

        if ($mpStatus === 'approved') {
            $pdo->prepare('UPDATE vip_orders SET status="paid", paid_at=COALESCE(paid_at,NOW()), updated_at=NOW() WHERE id=?')
                ->execute([(int) $order['id']]);
            echo json_encode(['ok' => true, 'status' => 'paid']);
            exit;
        }

        if (in_array($mpStatus, ['cancelled', 'rejected', 'expired'], true)) {
            $pdo->prepare('UPDATE vip_orders SET status="cancelled", updated_at=NOW() WHERE id=? AND status="pending"')
                ->execute([(int) $order['id']]);
            echo json_encode(['ok' => true, 'status' => 'expired']);
            exit;
        }
    }

    if ($expiresAt && new DateTimeImmutable('now', $tzManaus) >= $expiresAt) {
        $pdo->prepare('UPDATE vip_orders SET status="cancelled", updated_at=NOW() WHERE id=? AND status="pending"')
            ->execute([(int) $order['id']]);
        echo json_encode(['ok' => true, 'status' => 'expired']);
        exit;
    }

    echo json_encode(['ok' => true, 'status' => 'pending']);
} catch (Throwable $e) {
    error_log('[payment_status] ' . $e->getMessage());
    payment_status_error(500, 'status_check_failed');
}
