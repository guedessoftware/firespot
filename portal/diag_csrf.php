<?php declare(strict_types=1);
// SEC-004: nunca revelar identificador de sessão ou token CSRF.
http_response_code(404);
exit;

require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/csrf.php';
header('Content-Type: text/plain; charset=utf-8');
echo "session_name: " . session_name() . "\n";
echo "session_id:   " . session_id() . "\n";
echo "_csrf(sess):  " . ($_SESSION['_csrf'] ?? '(vazio)') . "\n";
