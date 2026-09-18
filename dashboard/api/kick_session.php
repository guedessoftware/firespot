<?php

declare(strict_types=1);

@ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_json();
if (!admin_has_capability('partner.network.manage')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}
admin_require_csrf($payload, true);

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/radius_db.php';
require_once __DIR__ . '/../../app/session_kick.php';

try {
    $result = fs_session_kick(db(), fs_radius_db(), $payload);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (FsSessionKickException $error) {
    error_log('[kick session] code=' . $error->publicCode());
    http_response_code($error->httpStatus());
    echo json_encode([
        'ok' => false,
        'error' => $error->publicCode(),
        'message' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('[kick session] unexpected=' . get_class($error));
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'kick_failed',
        'message' => 'Não foi possível concluir o encerramento da sessão.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

