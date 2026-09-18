<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../courtesy_access.php';

$args = $argv ?? [];
$apply = in_array('--apply', $args, true);
$json = in_array('--json', $args, true);
$partnerSelector = '';
$portal = 'v2';
foreach ($args as $arg) {
    if (preg_match('/^--partner=(.+)$/', $arg, $match)) $partnerSelector = trim($match[1]);
    if (preg_match('/^--portal=(.+)$/', $arg, $match)) $portal = strtolower(trim($match[1]));
}
if ($partnerSelector === '' || !array_key_exists($portal, fs_courtesy_rollout_portals())) {
    fwrite(STDERR, "Uso: php app/cli/courtesy_access_smoke.php --partner=<id|code> [--portal=v2] [--apply] [--json]\n");
    exit(2);
}

$app = db();
$app->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (ctype_digit($partnerSelector)) {
    $st = $app->prepare('SELECT id,code,name FROM partners WHERE id=? AND active=1 LIMIT 1');
    $st->execute([(int)$partnerSelector]);
} else {
    $st = $app->prepare('SELECT id,code,name FROM partners WHERE code=? AND active=1 LIMIT 1');
    $st->execute([$partnerSelector]);
}
$partner = $st->fetch(PDO::FETCH_ASSOC);
if (!$partner) {
    fwrite(STDERR, "Estabelecimento ativo não encontrado.\n");
    exit(2);
}
$policy = fs_courtesy_policy_resolve($app, (int)$partner['id']);
$rollout = fs_courtesy_rollout_resolve($app, (int)$partner['id'], $portal, $policy);
$preview = [
    'partner_id' => (int)$partner['id'],
    'partner_code' => (string)$partner['code'],
    'partner_name' => (string)$partner['name'],
    'portal' => $portal,
    'effective_mode' => $rollout['effective_mode'],
    'enforcement_method' => $policy['enforcement_method'],
    'cleanup' => 'temporary grant and RADIUS credentials are removed',
];
if (!$apply) {
    $result = ['applied' => false, 'preview' => $preview];
    echo $json ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : 'Dry-run: ' . json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
if ($rollout['effective_mode'] !== 'enforce') {
    fwrite(STDERR, 'O rollout precisa estar efetivamente em enforce; atual=' . $rollout['effective_mode'] . '.\n');
    exit(2);
}

$radius = fs_radius_db();
$suffix = strtoupper(bin2hex(random_bytes(4)));
$mac = '02:FE:' . implode(':', str_split($suffix, 2));
$context = [
    'partner_id' => (int)$partner['id'],
    'portal' => $portal,
    'source' => 'cutover_smoke',
    'device_key' => $mac,
    'mac' => $mac,
    'account_key' => 'courtesy-smoke',
    'account_username' => 'courtesy-smoke',
    'associated_device' => true,
    'ad_completed' => true,
    'has_active_paid' => false,
    'is_provider' => false,
    'idempotency_key' => 'smoke_' . bin2hex(random_bytes(16)),
];
$publicId = '';
$radiusUsername = '';
$passed = false;
$cleanupOk = false;
try {
    $issued = fs_courtesy_access_issue($app, $context, $radius);
    if (empty($issued['handled']) || empty($issued['allowed'])) {
        throw new RuntimeException('Fachada recusou o smoke: ' . ($issued['code'] ?? 'UNKNOWN') . ' — ' . ($issued['message'] ?? ''));
    }
    $publicId = (string)($issued['grant']['public_id'] ?? '');
    $radiusUsername = (string)($issued['grant']['username'] ?? '');
    if ($publicId === '' || $radiusUsername === '' || empty($issued['grant']['password'])) {
        throw new RuntimeException('Fachada não retornou a credencial completa.');
    }
    $st = $radius->prepare('SELECT COUNT(*) FROM radcheck WHERE username=?');
    $st->execute([$radiusUsername]);
    $checkCount = (int)$st->fetchColumn();
    $st = $radius->prepare('SELECT COUNT(*) FROM radreply WHERE username=?');
    $st->execute([$radiusUsername]);
    $replyCount = (int)$st->fetchColumn();
    if ($checkCount < 4 || $replyCount < 2) throw new RuntimeException('Atributos RADIUS incompletos no smoke.');
    $passed = true;
} finally {
    try {
        if ($radiusUsername !== '') fs_courtesy_radius_delete_credentials($radius, $radiusUsername);
        if ($publicId !== '') {
            $st = $app->prepare("DELETE FROM courtesy_grants WHERE public_id=? AND source='cutover_smoke'");
            $st->execute([$publicId]);
            $cleanupOk = $st->rowCount() === 1;
        } else {
            $cleanupOk = true;
        }
    } catch (Throwable $cleanupError) {
        error_log('[courtesy access smoke cleanup] ' . $cleanupError->getMessage());
    }
}

$result = [
    'applied' => true,
    'passed' => $passed,
    'cleanup_ok' => $cleanupOk,
    'partner' => $preview,
];
if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo 'Smoke central: ' . ($passed && $cleanupOk ? 'APROVADO' : 'FALHOU') . '; limpeza=' . ($cleanupOk ? 'ok' : 'pendente') . PHP_EOL;
}
exit($passed && $cleanupOk ? 0 : 2);
