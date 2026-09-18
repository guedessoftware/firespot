<?php

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_json();
if (!admin_has_capability('partner.network.manage')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'forbidden']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}
admin_require_csrf($_POST,true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/nas_sync.php';

try {
    $nasId = (int)($_POST['id'] ?? 0);
    if ($nasId <= 0) throw new InvalidArgumentException('ID de NAS inválido.');
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    echo json_encode(fs_nas_sync($pdo,$nasId),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>admin_public_error($error,'Não foi possível sincronizar o NAS.')],JSON_UNESCAPED_UNICODE);
}
