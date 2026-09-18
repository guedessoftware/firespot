<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../radius_db.php';
require_once __DIR__ . '/../courtesy_radius.php';
require_once __DIR__ . '/../lib/routeros.php';

function fs_nas_probe_arg(array $args, string $name, ?string $default = null): ?string
{
    foreach ($args as $arg) {
        if (strpos((string) $arg, '--' . $name . '=') === 0) return substr((string) $arg, strlen($name) + 3);
    }
    return $default;
}

function fs_nas_probe_wait(callable $callback, int $milliseconds = 6000)
{
    $deadline = microtime(true) + ($milliseconds / 1000);
    do {
        $result = $callback();
        if ($result) return $result;
        usleep(300000);
    } while (microtime(true) < $deadline);
    return null;
}

function fs_nas_probe_terse_value(string $line, string $key): string
{
    if (preg_match('/(?:^|\s)' . preg_quote($key, '/') . '=([^\s]+)/', $line, $match)) return trim($match[1]);
    return '';
}

$args = $argv ?? [];
$apply = in_array('--apply', $args, true);
$markReady = in_array('--mark-ready', $args, true);
$json = in_array('--json', $args, true);
$useInactiveHost = in_array('--use-inactive-host', $args, true);
$nasId = max(0, (int) fs_nas_probe_arg($args, 'nas-id', '0'));
$probeIp = trim((string) fs_nas_probe_arg($args, 'ip', '127.0.0.1'));
if ($nasId <= 0) {
    fwrite(STDERR, "Informe --nas-id=ID.\n");
    exit(2);
}
if (!$useInactiveHost && !filter_var($probeIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    fwrite(STDERR, "O IP do probe deve ser IPv4.\n");
    exit(2);
}
if ($markReady && !$apply) {
    fwrite(STDERR, "--mark-ready exige --apply.\n");
    exit(2);
}

$app = db();
$app->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$app->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$st = $app->prepare('SELECT id,nasname,shortname,server,mgmt_username,mgmt_password,mgmt_port FROM nas WHERE id=? LIMIT 1');
$st->execute([$nasId]);
$nas = $st->fetch(PDO::FETCH_ASSOC);
if (!$nas) {
    fwrite(STDERR, "NAS não encontrado.\n");
    exit(2);
}
$connection = [
    'host' => filter_var((string) ($nas['server'] ?? ''), FILTER_VALIDATE_IP) ? (string) $nas['server'] : (string) $nas['nasname'],
    'user' => (string) ($nas['mgmt_username'] ?? ''),
    'pass' => (string) ($nas['mgmt_password'] ?? ''),
    'port' => (int) ($nas['mgmt_port'] ?? 22),
];
if ($connection['host'] === '' || $connection['user'] === '' || $connection['pass'] === '') {
    fwrite(STDERR, "O NAS não possui gerenciamento configurado.\n");
    exit(2);
}

if ($useInactiveHost) {
    $inventory = ros_exec([
        '/ip hotspot host print terse without-paging',
        '/ip hotspot active print terse without-paging',
    ], $connection);
    $activeIps = [];
    foreach (preg_split('/\r?\n/', trim((string) ($inventory['out'][1] ?? ''))) ?: [] as $line) {
        $address = fs_nas_probe_terse_value($line, 'address');
        if ($address !== '') $activeIps[$address] = true;
    }
    $probeIp = '';
    foreach (preg_split('/\r?\n/', trim((string) ($inventory['out'][0] ?? ''))) ?: [] as $line) {
        if (!preg_match('/^\s*\d+\s+([^\s]*)\s/', $line, $flags)) continue;
        $flagText = strtoupper((string) ($flags[1] ?? ''));
        if (strpos($flagText, 'D') === false || strpos($flagText, 'A') !== false || strpos($flagText, 'P') !== false || strpos($flagText, 'S') !== false) continue;
        $originalAddress = fs_nas_probe_terse_value($line, 'address');
        $translatedAddress = fs_nas_probe_terse_value($line, 'to-address');
        $candidate = filter_var($translatedAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $translatedAddress : $originalAddress;
        if (!filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || isset($activeIps[$candidate]) || isset($activeIps[$originalAddress])) continue;
        $probeIp = $candidate;
        break;
    }
    if ($probeIp === '') {
        fwrite(STDERR, "O NAS não possui host dinâmico e inativo disponível para o probe.\n");
        exit(2);
    }
}

$preview = [
    'nas_id' => $nasId,
    'nas_name' => (string) ($nas['shortname'] ?: $nas['nasname']),
    'probe_ip' => $probeIp,
    'actions' => ['temporary_radius_user', 'routeros_hotspot_login', 'accounting_start_stop', 'cleanup'],
];
if (!$apply) {
    echo ($json ? json_encode(['applied' => false, 'preview' => $preview], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) :
        'Dry-run: probe via ' . $preview['nas_name'] . ' no IP ' . $probeIp . ". Use --apply para executar.\n");
    exit(0);
}

$radius = fs_radius_db();
$username = 'cty_probe_' . bin2hex(random_bytes(6));
$password = bin2hex(random_bytes(10));
$activeSeen = false;
$accountingStart = false;
$accountingStop = false;
$postAuthReply = null;
$loginOutput = '';
$cleanupErrors = [];

$cleanup = static function () use ($radius, $username, &$cleanupErrors): void {
    foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
        try { $radius->prepare('DELETE FROM ' . $table . ' WHERE username=?')->execute([$username]); }
        catch (Throwable $e) { $cleanupErrors[] = $table . ': ' . $e->getMessage(); }
    }
    try { $radius->prepare('DELETE FROM radacct WHERE username=?')->execute([$username]); }
    catch (Throwable $e) { $cleanupErrors[] = 'radacct: ' . $e->getMessage(); }
    try {
        $hasPostAuth = (bool) $radius->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='radpostauth' LIMIT 1")->fetchColumn();
        if ($hasPostAuth) $radius->prepare('DELETE FROM radpostauth WHERE username=?')->execute([$username]);
    } catch (Throwable $e) { $cleanupErrors[] = 'radpostauth: ' . $e->getMessage(); }
};

$cleanup();
$cleanupErrors = [];
try {
    $check = $radius->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,':=',?)");
    $check->execute([$username, 'Cleartext-Password', $password]);
    $check->execute([$username, 'Max-All-Session', '120']);
    $check->execute([$username, 'Simultaneous-Use', '1']);
    $check->execute([$username, 'Expiration', fs_courtesy_radius_expiration_value(time() + 600)]);
    $reply = $radius->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,?,':=',?)");
    $reply->execute([$username, 'Session-Timeout', '120']);
    $reply->execute([$username, 'Acct-Interim-Interval', '60']);

    $login = ros_exec([
        '/ip hotspot active login user=' . $username . ' password=' . $password . ' ip=' . $probeIp,
    ], $connection);
    $loginOutput = trim((string) ($login['out'][0] ?? ''));

    $postAuthReply = fs_nas_probe_wait(static function () use ($radius, $username): ?string {
        $hasPostAuth = (bool) $radius->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='radpostauth' LIMIT 1")->fetchColumn();
        if (!$hasPostAuth) return null;
        $st = $radius->prepare('SELECT reply FROM radpostauth WHERE username=? ORDER BY id DESC LIMIT 1');
        $st->execute([$username]);
        $reply = $st->fetchColumn();
        return $reply === false ? null : (string) $reply;
    }, 3000);

    $activeSeen = (bool) fs_nas_probe_wait(static function () use ($connection, $username): bool {
        $result = ros_exec(['/ip hotspot active print count-only where user="' . $username . '"'], $connection);
        return (int) trim((string) ($result['out'][0] ?? '0')) > 0;
    });
    $accountingStart = (bool) fs_nas_probe_wait(static function () use ($radius, $username): bool {
        $st = $radius->prepare('SELECT 1 FROM radacct WHERE username=? AND acctstarttime IS NOT NULL LIMIT 1');
        $st->execute([$username]);
        return (bool) $st->fetchColumn();
    });

    ros_exec(['/ip hotspot active remove [find where user="' . $username . '"]'], $connection);
    $accountingStop = (bool) fs_nas_probe_wait(static function () use ($radius, $username): bool {
        $st = $radius->prepare('SELECT 1 FROM radacct WHERE username=? AND acctstoptime IS NOT NULL LIMIT 1');
        $st->execute([$username]);
        return (bool) $st->fetchColumn();
    });
} catch (Throwable $e) {
    $loginOutput = trim($loginOutput . ' ' . $e->getMessage());
} finally {
    try { ros_exec(['/ip hotspot active remove [find where user="' . $username . '"]'], $connection); }
    catch (Throwable $e) { $cleanupErrors[] = 'routeros: ' . $e->getMessage(); }
    $cleanup();
}

$passed = $activeSeen && $accountingStart && $accountingStop && !$cleanupErrors;
if ($passed && $markReady) {
    $app->prepare("INSERT INTO app_settings (skey,svalue) VALUES ('courtesy_radius_ready','1') ON DUPLICATE KEY UPDATE svalue='1'")->execute();
}
$result = [
    'passed' => $passed,
    'marked_ready' => $passed && $markReady,
    'nas_id' => $nasId,
    'nas_name' => $preview['nas_name'],
    'probe_ip' => $probeIp,
    'router_active_seen' => $activeSeen,
    'accounting_start_seen' => $accountingStart,
    'accounting_stop_seen' => $accountingStop,
    'post_auth_reply' => $postAuthReply,
    'cleanup_ok' => !$cleanupErrors,
    'router_message' => substr($loginOutput, 0, 255),
    'cleanup_errors' => $cleanupErrors,
];
if ($json) echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
else {
    echo '[' . ($passed ? 'OK' : 'FALHOU') . '] NAS=' . $preview['nas_name']
        . ' active=' . (int) $activeSeen . ' start=' . (int) $accountingStart . ' stop=' . (int) $accountingStop
        . ' cleanup=' . (int) !$cleanupErrors . ($loginOutput !== '' ? ' mensagem=' . substr($loginOutput, 0, 160) : '') . PHP_EOL;
    if ($passed && $markReady) echo "Gate courtesy_radius_ready atualizado para 1; o cutover global permanece inalterado.\n";
}
exit($passed ? 0 : 2);
