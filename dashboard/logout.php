<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    header('Location: login.php?logged_out=1');
    exit;
}

http_response_code(405);
header('Allow: POST');
header('Location: index.php');
exit;
