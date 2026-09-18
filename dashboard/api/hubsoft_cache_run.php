<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_json();
if (!admin_has_capability('subscribers.manage')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}
$payload = json_decode((string)file_get_contents('php://input'), true);
admin_require_csrf(is_array($payload) ? $payload : [], true);

require_once __DIR__ . '/../../app/hubsoft_cache.php';

try {
    $result = hubsoft_cache_refresh([
        'ignore_limit' => true,
        'source' => 'manual'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'exception',
        'message' => admin_public_error($e, 'Não foi possível executar a sincronização auxiliar.')
    ]);
    exit;
}

$status = hubsoft_cache_status();
$response = [
    'ok' => !empty($result['ok']),
    'message' => (string) ($result['message'] ?? ''),
    'status' => $status,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
