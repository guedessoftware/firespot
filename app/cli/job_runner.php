#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';

date_default_timezone_set('America/Manaus');

$jobs = [
    'cleanup_guest_access' => ['script'=>__DIR__ . '/cleanup_guest_access.php', 'args'=>[], 'timeout'=>50],
    'courtesy_reconcile' => ['script'=>__DIR__ . '/courtesy_reconcile.php', 'args'=>['--apply', '--quiet'], 'timeout'=>50],
    'ad_reconcile' => ['script'=>__DIR__ . '/ad_reconcile.php', 'args'=>[], 'timeout'=>50],
    'subscriber_radius_reconcile' => ['script'=>__DIR__ . '/subscriber_radius_reconcile.php', 'args'=>[], 'timeout'=>90],
    'subscriber_cleanup' => ['script'=>__DIR__ . '/subscriber_cleanup.php', 'args'=>[], 'timeout'=>90],
    'subscriber_entitlement_reconcile' => ['script'=>__DIR__ . '/subscriber_entitlement_reconcile.php', 'args'=>['--limit=100'], 'timeout'=>240],
    'subscriber_retention' => ['script'=>__DIR__ . '/subscriber_retention.php', 'args'=>[], 'timeout'=>180],
    'privacy_cleanup' => ['script'=>__DIR__ . '/privacy_cleanup.php', 'args'=>[], 'timeout'=>180],
    'dashboard_concurrency' => ['script'=>dirname(__DIR__, 2) . '/dashboard/cron/update_concurrency.php', 'args'=>[], 'timeout'=>50],
    'hubsoft_cache' => ['script'=>__DIR__ . '/hubsoft_cache_refresh.php', 'args'=>[], 'timeout'=>600],
    'partner_infrastructure' => ['script'=>__DIR__ . '/partner_infrastructure_worker.php', 'args'=>[], 'timeout'=>240],
    'partner_analytics' => ['script'=>__DIR__ . '/partner_analytics_rollup.php', 'args'=>[], 'timeout'=>240],
];

$jobName = trim((string)($argv[1] ?? ''));
if ($jobName === '--list') {
    foreach (array_keys($jobs) as $available) echo $available . PHP_EOL;
    exit(0);
}
if (!isset($jobs[$jobName])) {
    fwrite(STDERR, "Uso: php app/cli/job_runner.php <job>\nUse --list para ver os jobs permitidos.\n");
    exit(64);
}

$job = $jobs[$jobName];
if (!is_file($job['script']) || !is_readable($job['script'])) {
    fwrite(STDERR, 'job=' . $jobName . " status=script_unavailable\n");
    exit(66);
}

$app = db();
$app->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$app->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$lockName = 'firespot_job_' . $jobName;
$lock = $app->prepare('SELECT GET_LOCK(?,0)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) {
    echo 'job=' . $jobName . " status=already_running\n";
    exit(0);
}

$setting = $app->prepare("INSERT INTO app_settings (skey,svalue) VALUES (?,?)
    ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)");
$set = static function (string $suffix, string $value) use ($setting, $jobName): void {
    $setting->execute(['ops_job_' . $jobName . '_' . $suffix, $value]);
};
$now = static fn(): string => date('Y-m-d H:i:s');
$started = microtime(true);
$set('started_at', $now());
$set('heartbeat_at', $now());
$set('state', 'running');

$command = array_merge(['/usr/bin/php', $job['script']], $job['args']);
$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', '/dev/null', 'a'],
    2 => ['file', '/dev/null', 'a'],
];
$process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), null, ['bypass_shell'=>true]);
if (!is_resource($process)) {
    $set('state', 'failed');
    $set('last_ok', '0');
    $set('last_exit_code', '127');
    fwrite(STDERR, 'job=' . $jobName . " status=start_failed\n");
    exit(127);
}

$exitCode = null;
$timedOut = false;
$lastHeartbeat = $started;
while (true) {
    $status = proc_get_status($process);
    if (!$status['running']) {
        $exitCode = (int)$status['exitcode'];
        break;
    }
    $elapsed = microtime(true) - $started;
    if ($elapsed >= (int)$job['timeout']) {
        $timedOut = true;
        proc_terminate($process);
        usleep(500000);
        $status = proc_get_status($process);
        if ($status['running']) proc_terminate($process, 9);
        $exitCode = 124;
        break;
    }
    if (microtime(true) - $lastHeartbeat >= 15) {
        $set('heartbeat_at', $now());
        $lastHeartbeat = microtime(true);
    }
    usleep(250000);
}
$closedCode = proc_close($process);
if ($exitCode === null || $exitCode < 0) $exitCode = $closedCode >= 0 ? $closedCode : 1;
if ($timedOut) $exitCode = 124;

$durationMs = (int)round((microtime(true) - $started) * 1000);
$ok = $exitCode === 0;
$set('heartbeat_at', $now());
$set('finished_at', $now());
$set('last_duration_ms', (string)$durationMs);
$set('last_exit_code', (string)$exitCode);
$set('last_ok', $ok ? '1' : '0');
$set('state', $timedOut ? 'timeout' : ($ok ? 'ok' : 'failed'));

try {
    $release = $app->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
} catch (Throwable $ignored) {
}

echo 'job=' . $jobName
    . ' status=' . ($timedOut ? 'timeout' : ($ok ? 'ok' : 'failed'))
    . ' exit=' . $exitCode
    . ' duration_ms=' . $durationMs . PHP_EOL;
exit($exitCode);
