<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../courtesy_radius.php';

$args = $argv ?? [];
$apply = in_array('--apply', $args, true);
$json = in_array('--json', $args, true);
$quiet = in_array('--quiet', $args, true);
$app = db();
$app->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$radius = fs_radius_db();
$now = time();
$nowSql = gmdate('Y-m-d H:i:s', $now);
$lockName = 'firespot_courtesy_reconcile';
$lockStmt = $app->prepare('SELECT GET_LOCK(?,0)');
$lockStmt->execute([$lockName]);
if ((int)$lockStmt->fetchColumn() !== 1) {
    $result = ['ok' => true, 'skipped' => true, 'reason' => 'ALREADY_RUNNING'];
    echo $json ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL : "Reconciliação já está em execução; esta chamada foi ignorada.\n";
    exit(0);
}
register_shutdown_function(static function () use ($app, $lockName): void {
    try {
        $st = $app->prepare('SELECT RELEASE_LOCK(?)');
        $st->execute([$lockName]);
    } catch (Throwable $e) {
    }
});

$expiredReservations = (int) $app->query("SELECT COUNT(*) FROM courtesy_grants
    WHERE status='reserved' AND reservation_expires_at<=UTC_TIMESTAMP()")->fetchColumn();
$staleProvisioning = (int) $app->query("SELECT COUNT(*) FROM courtesy_grants
    WHERE status='provisioning' AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)")->fetchColumn();
$active = (int) $app->query("SELECT COUNT(*) FROM courtesy_grants WHERE status='active'")->fetchColumn();
$cleanupCandidates = (int) $app->query("SELECT COUNT(*) FROM courtesy_grants
    WHERE status IN ('exhausted','expired','revoked','failed')
      AND radius_username IS NOT NULL AND radius_cleaned_at IS NULL")->fetchColumn();

if (!$apply) {
    $result = [
        'ok' => true,
        'apply' => false,
        'expired_reservations' => $expiredReservations,
        'stale_provisioning' => $staleProvisioning,
        'active' => $active,
        'cleanup_candidates' => $cleanupCandidates,
    ];
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo 'Dry-run: reservas expiradas=' . $expiredReservations
            . ', provisionamentos parados=' . $staleProvisioning
            . ', ativas=' . $active
            . ', limpezas pendentes=' . $cleanupCandidates . PHP_EOL;
        echo "Execute novamente com --apply para reconciliar.\n";
    }
    exit;
}

$app->prepare("UPDATE courtesy_grants
    SET status='expired',ended_at=COALESCE(ended_at,?),updated_at=?
    WHERE status='reserved' AND reservation_expires_at<=?")
    ->execute([$nowSql, $nowSql, $nowSql]);

$st = $app->prepare("SELECT public_id FROM courtesy_grants
    WHERE status='provisioning' AND updated_at<? ORDER BY id LIMIT 500");
$st->execute([gmdate('Y-m-d H:i:s', $now - 600)]);
$errors = [];
foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $publicId) {
    try {
        $grant = fs_courtesy_radius_grant($app, (string) $publicId);
        if (!$grant) continue;
        $username = fs_courtesy_radius_username($grant);
        fs_courtesy_radius_delete_credentials($radius, $username);
        $app->prepare("UPDATE courtesy_grants
            SET status='failed',failure_code='PROVISION_TIMEOUT',failure_detail='Provisionamento não concluído no prazo',
                ended_at=?,radius_cleaned_at=?,updated_at=? WHERE id=? AND status='provisioning'")
            ->execute([$nowSql, $nowSql, $nowSql, (int) $grant['id']]);
    } catch (Throwable $e) {
        $errors[] = ['stage' => 'provisioning', 'public_id' => (string)$publicId, 'error' => $e->getMessage()];
    }
}

$st = $app->query("SELECT public_id FROM courtesy_grants WHERE status='active' ORDER BY id LIMIT 1000");
$reconciled = 0;
foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $publicId) {
    try {
        $result = fs_courtesy_radius_reconcile($app, (string) $publicId, $radius, $now);
        if (!empty($result['changed'])) $reconciled++;
    } catch (Throwable $e) {
        $errors[] = ['stage' => 'active', 'public_id' => (string)$publicId, 'error' => $e->getMessage()];
    }
}

$st = $app->query("SELECT id,radius_username FROM courtesy_grants
    WHERE status IN ('exhausted','expired','revoked','failed')
      AND radius_username IS NOT NULL AND radius_cleaned_at IS NULL
    ORDER BY id LIMIT 1000");
$cleaned = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $grant) {
    try {
        $username = (string) $grant['radius_username'];
        $open = $radius->prepare('SELECT COUNT(*) FROM radacct WHERE username=? AND acctstoptime IS NULL');
        $open->execute([$username]);
        if ((int) $open->fetchColumn() > 0) continue;
        fs_courtesy_radius_delete_credentials($radius, $username);
        $app->prepare('UPDATE courtesy_grants SET radius_cleaned_at=?,updated_at=? WHERE id=?')
            ->execute([$nowSql, $nowSql, (int) $grant['id']]);
        $cleaned++;
    } catch (Throwable $e) {
        $errors[] = ['stage' => 'cleanup', 'grant_id' => (int)$grant['id'], 'error' => $e->getMessage()];
    }
}

$result = [
    'ok' => count($errors) === 0,
    'apply' => true,
    'expired_reservations' => $expiredReservations,
    'stale_provisioning' => $staleProvisioning,
    'active_scanned' => $active,
    'active_changed' => $reconciled,
    'credentials_cleaned' => $cleaned,
    'errors' => $errors,
];
$heartbeat = $app->prepare("INSERT INTO app_settings (skey,svalue) VALUES (?,?)
    ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)");
$heartbeat->execute(['courtesy_reconcile_last_run_at', $nowSql]);
$heartbeat->execute(['courtesy_reconcile_last_ok', count($errors) === 0 ? '1' : '0']);
if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} elseif (!$quiet || $errors) {
    echo 'Reconciliação concluída: ativas alteradas=' . $reconciled . ', credenciais limpas=' . $cleaned . ', erros=' . count($errors) . ".\n";
    foreach ($errors as $error) fwrite(STDERR, '[courtesy reconcile ' . $error['stage'] . '] ' . ($error['public_id'] ?? $error['grant_id'] ?? '?') . ': ' . $error['error'] . PHP_EOL);
}
exit(count($errors) === 0 ? 0 : 2);
