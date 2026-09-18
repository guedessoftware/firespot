<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_json();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}
admin_require_csrf($_POST, true);

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/dashboard_user_stats.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!fs_dashboard_user_stats_schema_ready($pdo)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'stats_schema_not_ready']);
    exit;
}

$state = fs_dashboard_user_stats_state($pdo);
if (empty($state['stale'])) {
    echo json_encode(['ok' => true, 'refreshed' => false, 'state' => $state]);
    exit;
}

$result = fs_dashboard_user_stats_refresh($pdo, false);
if (empty($result['ok'])) http_response_code(503);
echo json_encode($result + ['state' => fs_dashboard_user_stats_state($pdo)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
