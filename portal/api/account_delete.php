<?php
// /portal/api/account_delete.php — Exclusão autoatendida da conta do cliente
@ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../app/session_boot.php';

if (empty($_SESSION['cliente_username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/radius_db.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/account_deletion.php';
require_once __DIR__ . '/../../app/ad_monetization.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$token = $_POST['csrf'] ?? '';
if (!csrf_check($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    exit;
}

$username = $_SESSION['cliente_username'];
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("SET time_zone='-04:00'");

try {
    $radius = fs_radius_db();
    account_deletion_execute(
        $pdo,
        $radius,
        (string)$username,
        'self-service',
        (string)$username,
        static fn(string $phone): int => fs_ad_revoke_leads_by_phone($pdo,$phone)
    );

    // encerra sessão atual
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[account_delete] '.get_class($e));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'account_delete_failed']);
}
