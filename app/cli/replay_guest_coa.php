<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../guest_payment_radius.php';

$options = getopt('', ['order:', 'apply', 'confirm-session:', 'json']);
$identifier = trim((string)($options['order'] ?? ''));
$apply = array_key_exists('apply', $options);
$json = array_key_exists('json', $options);
$confirmedSession = trim((string)($options['confirm-session'] ?? ''));

$emit = static function (array $result, int $exitCode = 0) use ($json): never {
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        foreach ($result as $key => $value) {
            if (is_bool($value)) $value = $value ? 'sim' : 'nao';
            if ($value === null) $value = '-';
            echo $key . '=' . $value . PHP_EOL;
        }
    }
    exit($exitCode);
};

if ($identifier === '' || (!ctype_digit($identifier) && !preg_match('/^[a-f0-9]{32}$/i', $identifier))) {
    fwrite(STDERR, "Uso: php app/cli/replay_guest_coa.php --order=<id|public_id> [--json]\n");
    fwrite(STDERR, "     sudo php app/cli/replay_guest_coa.php --order=<id|public_id> --apply --confirm-session=<radacctid> [--json]\n");
    exit(64);
}
if ($apply && (!function_exists('posix_geteuid') || posix_geteuid() !== 0)) {
    fwrite(STDERR, "O replay com --apply exige root. O diagnostico sem --apply e somente leitura.\n");
    exit(77);
}
if ($apply && (!ctype_digit($confirmedSession) || (int)$confirmedSession <= 0)) {
    fwrite(STDERR, "--apply exige --confirm-session=<radacctid> obtido no diagnostico imediatamente anterior.\n");
    exit(64);
}

$app = db();
$app->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$app->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$radius = fs_radius_db();
$radius->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$radius->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$load = static function () use ($app, $identifier): ?array {
    if (ctype_digit($identifier)) {
        $st = $app->prepare('SELECT * FROM guest_orders WHERE id=? LIMIT 1');
        $st->execute([(int)$identifier]);
    } else {
        $st = $app->prepare('SELECT * FROM guest_orders WHERE public_id=? LIMIT 1');
        $st->execute([strtolower($identifier)]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

$order = $load();
if (!$order) $emit(['ok'=>false, 'code'=>'ORDER_NOT_FOUND'], 66);
if ((string)$order['status'] !== 'paid' || (string)($order['payment_access_mode'] ?? '') !== FS_GUEST_PAYMENT_ACCESS_RADIUS) {
    $emit([
        'ok'=>false,
        'code'=>'ORDER_NOT_ELIGIBLE',
        'order_id'=>(int)$order['id'],
        'payment_status'=>(string)$order['status'],
        'coa_status'=>(string)($order['radius_coa_status'] ?? ''),
    ], 65);
}
if ((string)($order['radius_coa_status'] ?? '') === 'applied') {
    $emit([
        'ok'=>true,
        'code'=>'COA_ALREADY_APPLIED',
        'order_id'=>(int)$order['id'],
        'coa_status'=>'applied',
        'radacctid'=>$order['radius_coa_radacctid'] === null ? null : (int)$order['radius_coa_radacctid'],
    ]);
}

$resolve = static function (array $candidate) use ($app, $radius): array {
    try {
        $resolved = fs_guest_payment_radius_context($app, $radius, $candidate);
        return ['ok'=>true, 'resolved'=>$resolved];
    } catch (Throwable $error) {
        $known = in_array($error->getMessage(), [
            'RADIUS_SESSION_NOT_FOUND',
            'RADIUS_SESSION_AMBIGUOUS',
            'A instalacao original do pedido nao foi encontrada.',
            'A instalacao nao possui NAS associado.',
            'NAS ou segredo RADIUS nao encontrado.',
            'O endereco RADIUS do NAS e invalido.',
        ], true);
        return ['ok'=>false, 'code'=>$known ? $error->getMessage() : 'CONTEXT_LOOKUP_FAILED'];
    }
};

$sessionCheck = $resolve($order);
$preview = [
    'ok'=>(bool)$sessionCheck['ok'],
    'mode'=>$apply ? 'apply' : 'dry-run',
    'order_id'=>(int)$order['id'],
    'coa_status'=>(string)($order['radius_coa_status'] ?? ''),
    'coa_attempts'=>(int)($order['radius_coa_attempts'] ?? 0),
    'last_error'=>(string)($order['radius_coa_error_code'] ?? ''),
    'session_ready'=>(bool)$sessionCheck['ok'],
    'radacctid'=>$sessionCheck['ok'] ? (int)$sessionCheck['resolved']['session']['radacctid'] : null,
    'nas_id'=>$sessionCheck['ok'] ? (int)$sessionCheck['resolved']['context']['nas_id'] : null,
    'code'=>$sessionCheck['ok'] ? 'READY_FOR_EXPLICIT_REPLAY' : (string)$sessionCheck['code'],
];
if (!$apply) $emit($preview, $sessionCheck['ok'] ? 0 : 2);
if (!$sessionCheck['ok']) $emit($preview, 2);
if ((int)$confirmedSession !== (int)$sessionCheck['resolved']['session']['radacctid']) {
    $preview['ok'] = false;
    $preview['code'] = 'SESSION_CONFIRMATION_MISMATCH';
    $emit($preview, 65);
}

$lockName = 'firespot_guest_coa_replay_' . (int)$order['id'];
$lock = $app->prepare('SELECT GET_LOCK(?,0)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) {
    $preview['ok'] = false;
    $preview['code'] = 'REPLAY_ALREADY_RUNNING';
    $emit($preview, 75);
}

try {
    $order = $load();
    if (!$order || (string)$order['status'] !== 'paid') {
        $preview['ok'] = false;
        $preview['code'] = 'ORDER_CHANGED';
        $emit($preview, 65);
    }
    if ((string)($order['radius_coa_status'] ?? '') === 'applied') {
        $preview['ok'] = true;
        $preview['code'] = 'COA_ALREADY_APPLIED';
        $preview['coa_status'] = 'applied';
        $emit($preview);
    }
    $freshSession = $resolve($order);
    if (!$freshSession['ok'] || (int)$freshSession['resolved']['session']['radacctid'] !== (int)$confirmedSession) {
        $preview['ok'] = false;
        $preview['code'] = 'SESSION_CHANGED';
        $preview['session_ready'] = false;
        $emit($preview, 65);
    }

    $reset = $app->prepare("UPDATE guest_orders
        SET radius_coa_status='pending',radius_coa_attempts=0,radius_coa_last_attempt_at=NULL,
            radius_coa_error_code='MANUAL_REPLAY_REQUESTED',radius_coa_radacctid=?,updated_at=NOW()
        WHERE id=? AND status='paid' AND COALESCE(radius_coa_status,'pending')<>'applied'");
    $reset->execute([(int)$confirmedSession, (int)$order['id']]);
    if ($reset->rowCount() !== 1) {
        $preview['ok'] = false;
        $preview['code'] = 'ORDER_CHANGED';
        $emit($preview, 65);
    }

    $final = fs_guest_finalize_paid_access($app, (int)$order['id'], $radius);
    $applied = (string)($final['radius_coa_status'] ?? '') === 'applied';
    $emit([
        'ok'=>$applied,
        'mode'=>'apply',
        'order_id'=>(int)$order['id'],
        'coa_status'=>(string)($final['radius_coa_status'] ?? ''),
        'coa_attempts'=>(int)($final['radius_coa_attempts'] ?? 0),
        'last_error'=>(string)($final['radius_coa_error_code'] ?? ''),
        'radacctid'=>$final['radius_coa_radacctid'] === null ? null : (int)$final['radius_coa_radacctid'],
        'code'=>$applied ? 'COA_REPLAY_APPLIED' : 'COA_REPLAY_NOT_APPLIED',
    ], $applied ? 0 : 2);
} finally {
    try {
        $release = $app->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    } catch (Throwable $ignored) {
    }
}
