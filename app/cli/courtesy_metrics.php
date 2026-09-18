<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../courtesy_rollout.php';
require_once __DIR__ . '/../radius_db.php';

$args = $argv ?? [];
$json = in_array('--json', $args, true);
$hours = 24;
foreach ($args as $arg) {
    if (preg_match('/^--hours=(\d+)$/', $arg, $match)) $hours = max(1, min(24 * 31, (int)$match[1]));
}
$cutoff = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));
$app = db();
$app->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$radius = fs_radius_db();

$setting = static function (string $key) use ($app): ?string {
    $st = $app->prepare('SELECT svalue FROM app_settings WHERE skey=? LIMIT 1');
    $st->execute([$key]);
    $value = $st->fetchColumn();
    return $value === false ? null : (string)$value;
};
$scalar = static function (string $sql, array $params = []) use ($app): int {
    $st = $app->prepare($sql);
    $st->execute($params);
    return (int)($st->fetchColumn() ?: 0);
};

$gates = [
    'cutover_enabled' => fs_courtesy_setting_bool($app, 'courtesy_cutover_enabled', false),
    'radius_ready' => fs_courtesy_setting_bool($app, 'courtesy_radius_ready', false),
    'mikrotik_ready' => fs_courtesy_setting_bool($app, 'courtesy_mikrotik_ready', false),
];
$requestedEnforce = $scalar("SELECT COUNT(*) FROM courtesy_portal_rollouts WHERE mode='enforce'");
$effectiveEnforce = 0;
foreach ($app->query('SELECT id FROM partners WHERE active=1')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $partnerId) {
    $policy = fs_courtesy_policy_resolve($app, (int)$partnerId);
    foreach (array_keys(fs_courtesy_rollout_portals()) as $portal) {
        if (fs_courtesy_rollout_resolve($app, (int)$partnerId, $portal, $policy)['effective_mode'] === 'enforce') $effectiveEnforce++;
    }
}

$st = $app->prepare("SELECT status,COUNT(*) total FROM courtesy_grants
    WHERE legacy_source IS NULL AND created_at>=? GROUP BY status ORDER BY status");
$st->execute([$cutoff]);
$grantStatus = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: []);
$st = $app->prepare("SELECT portal,COUNT(*) total FROM courtesy_grants
    WHERE legacy_source IS NULL AND created_at>=? GROUP BY portal ORDER BY portal");
$st->execute([$cutoff]);
$grantPortals = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: []);

$shadowEvents = $scalar('SELECT COUNT(*) FROM courtesy_shadow_events WHERE created_at>=?', [$cutoff]);
$shadowMismatches = $scalar('SELECT COUNT(*) FROM courtesy_shadow_events WHERE created_at>=? AND decision_match=0', [$cutoff]);
$staleProvisioning = $scalar("SELECT COUNT(*) FROM courtesy_grants WHERE status='provisioning' AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)");
$expiredReservations = $scalar("SELECT COUNT(*) FROM courtesy_grants WHERE status='reserved' AND reservation_expires_at<=UTC_TIMESTAMP()");
$pendingCleanup = $scalar("SELECT COUNT(*) FROM courtesy_grants
    WHERE status IN ('exhausted','expired','revoked','failed') AND radius_username IS NOT NULL AND radius_cleaned_at IS NULL");
$failedRecent = $scalar("SELECT COUNT(*) FROM courtesy_grants WHERE legacy_source IS NULL AND status='failed' AND created_at>=?", [$cutoff]);
$activeCount = $scalar("SELECT COUNT(*) FROM courtesy_grants WHERE legacy_source IS NULL AND status='active'");

$activeRows = $app->query("SELECT public_id,radius_username FROM courtesy_grants
    WHERE legacy_source IS NULL AND status='active'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$activeMissingCredentials = 0;
foreach ($activeRows as $grant) {
    $username = trim((string)($grant['radius_username'] ?? ''));
    if ($username === '') {
        $activeMissingCredentials++;
        continue;
    }
    $st = $radius->prepare("SELECT COUNT(*) FROM radcheck WHERE username=? AND attribute='Cleartext-Password'");
    $st->execute([$username]);
    if ((int)$st->fetchColumn() !== 1) $activeMissingCredentials++;
}

$orphanCredentials = 0;
$radiusCourtesyUsers = $radius->query("SELECT DISTINCT username FROM radcheck WHERE username LIKE 'cty\\_%' ESCAPE '\\\\'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
foreach ($radiusCourtesyUsers as $username) {
    $st = $app->prepare("SELECT 1 FROM courtesy_grants WHERE radius_username=? AND status IN ('provisioning','active') LIMIT 1");
    $st->execute([(string)$username]);
    if (!$st->fetchColumn()) $orphanCredentials++;
}

$reconcileLastRun = $setting('courtesy_reconcile_last_run_at');
$reconcileLastOk = $setting('courtesy_reconcile_last_ok');
$reconcileAge = $reconcileLastRun ? max(0, time() - (strtotime($reconcileLastRun . ' UTC') ?: 0)) : null;
$alerts = [];
$alert = static function (string $severity, string $code, string $message) use (&$alerts): void {
    $alerts[] = ['severity' => $severity, 'code' => $code, 'message' => $message];
};
if ($staleProvisioning > 0) $alert('critical', 'STALE_PROVISIONING', $staleProvisioning . ' provisionamento(s) parado(s).');
if ($activeMissingCredentials > 0) $alert('critical', 'ACTIVE_CREDENTIAL_MISSING', $activeMissingCredentials . ' concessão(ões) ativa(s) sem credencial RADIUS íntegra.');
if ($orphanCredentials > 0) $alert('warning', 'ORPHAN_RADIUS_CREDENTIAL', $orphanCredentials . ' credencial(is) cty_ sem concessão ativa correspondente.');
if ($pendingCleanup > 0) $alert('warning', 'PENDING_CLEANUP', $pendingCleanup . ' credencial(is) aguardando limpeza.');
if ($failedRecent > 0) $alert('warning', 'RECENT_FAILURES', $failedRecent . ' falha(s) de concessão no período.');
if ($shadowMismatches > 0) $alert('warning', 'SHADOW_MISMATCH', $shadowMismatches . ' divergência(s) de decisão no período.');
if ($effectiveEnforce > 0 && ($reconcileAge === null || $reconcileAge > 180 || $reconcileLastOk !== '1')) {
    $alert('critical', 'RECONCILE_STALE', 'Reconciliação ausente, antiga ou com falha enquanto há rollout em enforce.');
}

$result = [
    'generated_at' => gmdate(DATE_ATOM),
    'period_hours' => $hours,
    'gates' => $gates,
    'rollouts' => ['requested_enforce' => $requestedEnforce, 'effective_enforce' => $effectiveEnforce],
    'grants' => [
        'created' => array_sum($grantStatus),
        'by_status' => $grantStatus,
        'by_portal' => $grantPortals,
        'active_now' => $activeCount,
        'failed_in_period' => $failedRecent,
    ],
    'shadow' => ['events' => $shadowEvents, 'mismatches' => $shadowMismatches],
    'health' => [
        'expired_reservations' => $expiredReservations,
        'stale_provisioning' => $staleProvisioning,
        'pending_cleanup' => $pendingCleanup,
        'active_missing_credentials' => $activeMissingCredentials,
        'orphan_radius_credentials' => $orphanCredentials,
        'reconcile_last_run_at' => $reconcileLastRun,
        'reconcile_last_ok' => $reconcileLastOk,
        'reconcile_age_seconds' => $reconcileAge,
    ],
    'alerts' => $alerts,
];

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo 'Cortesia — ' . $hours . 'h: grants=' . $result['grants']['created']
        . ', ativos=' . $activeCount . ', falhas=' . $failedRecent
        . ', shadow=' . $shadowEvents . ', divergências=' . $shadowMismatches . PHP_EOL;
    echo 'Rollouts: solicitados enforce=' . $requestedEnforce . ', efetivos=' . $effectiveEnforce
        . ' | reconciliação=' . ($reconcileLastRun ?: 'nunca') . PHP_EOL;
    if (!$alerts) echo "Saúde: sem alertas.\n";
    foreach ($alerts as $item) echo '[' . strtoupper($item['severity']) . '] ' . $item['code'] . ': ' . $item['message'] . PHP_EOL;
}

$critical = array_filter($alerts, static fn(array $item): bool => $item['severity'] === 'critical');
exit($critical ? 2 : 0);
