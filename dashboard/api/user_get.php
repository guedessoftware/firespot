<?php // stub for api/user_get.php ?>
<?php
@ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
try {
    if (session_status() === PHP_SESSION_NONE)
        require_once __DIR__ . '/../../app/session_boot.php';
    if (empty($_SESSION['admin'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
    require_once __DIR__ . '/../../app/db.php';
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $u = preg_replace('/\D+/', '', (string) ($_GET['u'] ?? ''));
    if ($u === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing username']);
        exit;
    }

    $st = $pdo->prepare("SELECT ci.cpf AS username, ci.nome, ci.telefone, ci.email, ci.data_nascimento AS nascimento, ci.sexo FROM clientes_info ci WHERE ci.cpf=? LIMIT 1");
    $st->execute([$u]);
    $ci = $st->fetch() ?: ['username' => $u, 'nome' => '', 'telefone' => '', 'email' => '', 'nascimento' => '', 'sexo' => ''];

    $st = $pdo->prepare("SELECT value FROM radcheck WHERE username=? AND attribute='Max-All-Session' LIMIT 1");
    $st->execute([$u]);
    $max = (int) ($st->fetchColumn() ?: 0);
    $maxMin = (int) round($max / 60);

    $st = $pdo->prepare("SELECT groupname FROM radusergroup WHERE username=? ORDER BY priority LIMIT 1");
    $st->execute([$u]);
    $grp = (string) ($st->fetchColumn() ?: '');

    echo json_encode([
        'ok' => true,
        'user' => [
            'username' => $u,
            'nome' => (string) $ci['nome'],
            'telefone' => (string) $ci['telefone'],
            'email' => (string) ($ci['email'] ?? ''),
            'nascimento' => (string) ($ci['nascimento'] ?? ''),
            'sexo' => (string) ($ci['sexo'] ?? ''),
            'group' => $grp,
            'max_minutes' => $maxMin,
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
