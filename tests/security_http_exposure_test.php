<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$bases = [];
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--base=') !== 0) continue;
    $base = rtrim(substr($argument, 7), '/');
    if ($base !== '') $bases[] = $base;
}
if (!$bases) $bases[] = 'https://firecdn.com.br';

$checks = [
    ['/.env', [403, 404]],
    ['/.env.firespot-key', [403, 404]],
    ['/.gitignore', [403, 404]],
    ['/AGENTS.md', [403, 404]],
    ['/PORTAL_V3.md', [403, 404]],
    ['/schema.sql', [403, 404]],
    ['/test_api.html', [403, 404]],
    ['/test_portal_v2.html', [403, 404]],
    ['/phpmyadmin/', [403, 404]],
    ['/archive/', [403, 404]],
    ['/backups/', [403, 404]],
    ['/docs/', [403, 404]],
    ['/installers/', [403, 404]],
    ['/migrations/apply_001.php', [403, 404]],
    ['/ops/firespot_security_finalize_root.sh', [403, 404]],
    ['/ops/apache/firespot-ssl.conf', [403, 404]],
    ['/tests/security_http_exposure_test.php', [403, 404]],
    ['/app/', [403, 404]],
    ['/app/cli/', [403, 404]],
    ['/app/lib/', [403, 404]],
    ['/app/lib/font/', [403, 404]],
    ['/app/diag_token.php', [403, 404]],
    ['/app/diag_env_url.php', [403, 404]],
    ['/app/teste_ros.php', [403, 404]],
    ['/portal/diag_csrf.php', [404]],
    ['/portal/premium_mock.php', [404]],
    ['/portal/api/premium_mock.php', [404]],
    ['/portal/api/vip_zero.php', [404, 410]],
    ['/portal/webhook_mp.php', [404, 410]],
    ['/portal/webhooks/mercadopago.php', [404, 410]],
    ['/portal/webhooks/mp_notify.php', [404, 410]],
    ['/portal/api/login_token_create.php', [404, 405]],
    ['/portal/api/account_delete.php', [401, 404]],
    ['/dashboard/api/user_delete.php', [401, 404]],
    ['/portal/api/trial_start.php', [404, 410]],
    ['/portal/api/pay_session_bind.php', [404, 410]],
    ['/portal/api/login_token_create.php', [400, 401, 404], 'POST'],
    ['/portal/api/trial_start.php', [404, 410], 'POST'],
    ['/portal/api/pay_session_bind.php', [404, 410], 'POST'],
    ['/portal/host/panel_actions.php', [404]],
    ['/portal/host/pages/infrastructure.php', [404]],
    ['/portal/host/pages/nas.php', [404]],
    ['/portal/host/pages/hotspots.php', [404]],
    ['/portal/host/pages/courtesy.php', [404]],
    ['/portal/host/pages/analytics.php', [404]],
    ['/portal/host/pages/audit.php', [404]],
    ['/portal/host/delete.php', [302, 401, 403, 404], 'GET', false],
    ['/portal/host/delete.php', [302, 401, 403, 404], 'POST', false],
    ['/portal/host/toggle_active.php', [302, 401, 403, 404], 'GET', false],
    ['/portal/host/toggle_active.php', [302, 401, 403, 404], 'POST', false],
    ['/portal/host/monetization_export.php', [302, 401, 403, 404], 'GET', false],
    ['/portal/host/monetization_export.php', [302, 401, 403, 404], 'POST', false],
    ['/portal/host/analytics_export.php', [302, 401, 403, 404], 'GET', false],
    ['/portal/host/analytics_export.php', [302, 401, 403, 404], 'POST', false],
    ['/portal-v3/api/webhook.php', [404, 405]],
    ['/portal-v3/api/webhook.php', [401, 404], 'POST'],
    ['/dashboard/api/monetization_webhook.php', [404, 405]],
    ['/dashboard/api/monetization_webhook.php', [401, 404], 'POST'],
    ['/dashboard/cron/update_concurrency.php', [403, 404]],
    ['/dashboard/cron/hubsoft_cache_refresh.php', [403, 404]],
];

function security_http_status(string $url, string $method = 'GET', bool $follow = true): int
{
    $command = 'curl --silent --show-error' . ($follow ? ' --location --max-redirs 3' : '') . ' --output /dev/null --max-time 12'
        . ' --request ' . escapeshellarg($method)
        . ' --write-out ' . escapeshellarg('%{http_code}')
        . ' ' . escapeshellarg($url);
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    if ($status !== 0) return 0;
    return (int) trim(implode('', $output));
}

$failures = [];
foreach ($bases as $base) {
    $baseHost = strtolower((string)(parse_url($base, PHP_URL_HOST) ?? ''));
    $basePath = rtrim((string)(parse_url($base, PHP_URL_PATH) ?? ''), '/');
    $legacyDefaultVhost = in_array($baseHost, ['127.0.0.1','localhost','::1'], true) && $basePath === '/hotspot';
    foreach ($checks as $check) {
        [$path, $allowed] = $check;
        $method = $check[2] ?? 'GET';
        $follow = $check[3] ?? true;
        $status = security_http_status($base . $path, $method, $follow);
        $ok = in_array($status, $allowed, true) || ($legacyDefaultVhost && in_array($status, [403,404], true));
        printf("[%s] %s %s%s -> %d\n", $ok ? 'OK' : 'FAIL', $method, $base, $path, $status);
        if (!$ok) $failures[] = [$base . $path, $status, $allowed];
    }
}

if ($failures) {
    fwrite(STDERR, count($failures) . " verificação(ões) de exposição falharam.\n");
    exit(1);
}

echo "Exposição HTTP crítica: bloqueada.\n";
