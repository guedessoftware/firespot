<?php // stub for api/user_save.php ?>
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
    require_once __DIR__ . '/../../app/csrf.php';
    require_once __DIR__ . '/../../app/db.php';

    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true) ?: [];
    if (!csrf_check($j['_csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
        exit;
    }

    $mode = ($j['mode'] ?? 'create');
    $user = preg_replace('/\D+/', '', (string) ($j['username'] ?? ''));
    $nome = trim((string) ($j['nome'] ?? ''));
    $tel = preg_replace('/\D+/', '', (string) ($j['telefone'] ?? ''));
    $email = trim((string) ($j['email'] ?? ''));
    $nasc = trim((string) ($j['nascimento'] ?? ''));
    $sexo = trim((string) ($j['sexo'] ?? ''));
    $senha = (string) ($j['senha'] ?? '');
    $group = trim((string) ($j['group'] ?? ''));
    $excl = (bool) ($j['exclusive'] ?? false);
    $maxMin = trim((string) ($j['max_minutes'] ?? ''));

    if ($user === '' || strlen($user) !== 11) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'username inválido (CPF 11 dígitos)']);
        exit;
    }
    if ($mode === 'create' && $senha === "") {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'senha obrigatória na criação']);
        exit;
    }
    if ($senha !== '' && strlen($senha) < 6) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'senha muito curta']);
        exit;
    }
    if ($sexo !== '' && !in_array($sexo, ['M', 'F', 'O'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'sexo inválido']);
        exit;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'email inválido']);
        exit;
    }

    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->beginTransaction();

    // clientes_info upsert (apenas colunas existentes)
    $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clientes_info'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $has = function ($c) use ($cols) {
        return in_array($c, $cols, true); };
    $map = ['cpf' => $user, 'nome' => $nome, 'telefone' => $tel];
    if ($has('email'))
        $map['email'] = $email;
    if ($has('data_nascimento'))
        $map['data_nascimento'] = $nasc ?: null;
    if ($has('sexo'))
        $map['sexo'] = $sexo ?: null;

    $insCols = [];
    $qs = [];
    $vals = [];
    $updates = [];
    foreach ($map as $k => $v) {
        $insCols[] = "`$k`";
        $qs[] = '?';
        $vals[] = $v;
        if ($k !== 'cpf') {
            $updates[] = "`$k`=VALUES(`$k`)";
        }
    }
    $sqlCI = 'INSERT INTO clientes_info (' . implode(',', $insCols) . ') VALUES (' . implode(',', $qs) . ') ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
    $pdo->prepare($sqlCI)->execute($vals);

    // radcheck password
    if ($senha !== '') {
        $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute='Cleartext-Password'")->execute([$user]);
        $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,':=',?)")
            ->execute([$user, 'Cleartext-Password', $senha]);
    }

    // Max-All-Session (min -> seg)
    if ($maxMin !== '') {
        $maxSec = max(0, (int) $maxMin) * 60;
        $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute='Max-All-Session'")->execute([$user]);
        $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,':=',?)")
            ->execute([$user, 'Max-All-Session', $maxSec]);
    }

    // group
    if ($group !== '') {
        if ($excl) {
            $pdo->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$user]);
        }
        $st = $pdo->prepare('SELECT 1 FROM radusergroup WHERE username=? AND groupname=? LIMIT 1');
        $st->execute([$user, $group]);
        if (!$st->fetchColumn()) {
            $pdo->prepare('INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)')->execute([$user, $group]);
        }
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
