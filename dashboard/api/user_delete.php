<?php

declare(strict_types=1);

@ini_set('display_errors','0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_json();

function user_delete_error(int $status,string $error): void
{
    http_response_code($status);
    echo json_encode(['ok' => false,'error' => $error]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    user_delete_error(405,'method_not_allowed');
}

$payload = json_decode((string)file_get_contents('php://input'),true);
if (!is_array($payload)) $payload = $_POST;
admin_require_csrf($payload,true);

// Exclusão total é reservada ao papel administrativo máximo.
if (!admin_has_capability('customers.delete')) {
    user_delete_error(403,'forbidden');
}

$username = preg_replace('/\D+/','',(string)($payload['username'] ?? ''));
$confirmed = filter_var($payload['confirm'] ?? false,FILTER_VALIDATE_BOOLEAN);
if (!preg_match('/^[0-9]{11}$/',$username) || !$confirmed) {
    user_delete_error(400,'invalid_request');
}

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/radius_db.php';
require_once __DIR__ . '/../../app/account_deletion.php';
require_once __DIR__ . '/../../app/ad_monetization.php';

try {
    $app = db();
    $radius = fs_radius_db();
    $actor = admin_id() > 0 ? 'admin-id:' . admin_id() : 'admin-role:' . admin_role();
    $counts = account_deletion_execute(
        $app,
        $radius,
        $username,
        'admin-delete',
        $actor,
        static fn(string $phone): int => fs_ad_revoke_leads_by_phone($app,$phone)
    );
    echo json_encode(['ok' => true,'deleted_counts' => $counts]);
} catch (Throwable $error) {
    error_log('[user_delete] ' . get_class($error));
    user_delete_error(500,'user_delete_failed');
}
