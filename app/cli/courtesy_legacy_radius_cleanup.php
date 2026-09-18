<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../radius_db.php';

$args = $argv ?? [];
$apply = in_array('--apply', $args, true);
$json = in_array('--json', $args, true);
$summaryOnly = in_array('--summary', $args, true);
$days = 30;
foreach ($args as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $match)) $days = max(7, min(3650, (int)$match[1]));
}
$cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
$app = db();
$radius = fs_radius_db();
$radius->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$usernames = $radius->query("SELECT DISTINCT username FROM radcheck
    WHERE username REGEXP '^ad[0-9]{6}[a-f0-9]{5}$'
       OR username REGEXP '^QRD?[0-9]+-'
       OR username LIKE 'QR-%'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$legacyPattern = "username REGEXP '^ad[0-9]{6}[a-f0-9]{5}$' OR username REGEXP '^QRD?[0-9]+-' OR username LIKE 'QR-%'";
$radiusActivity = [];
foreach ($radius->query("SELECT username,MAX(COALESCE(acctupdatetime,acctstoptime,acctstarttime)) last_seen,
    SUM(CASE WHEN acctstoptime IS NULL AND acctstarttime IS NOT NULL THEN 1 ELSE 0 END) open_sessions
    FROM radacct WHERE {$legacyPattern} GROUP BY username")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $radiusActivity[(string)$row['username']] = $row;
}
$adActivity = [];
try {
    foreach ($app->query("SELECT username,MAX(granted_at) last_seen FROM ad_grants WHERE {$legacyPattern} GROUP BY username")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $adActivity[(string)$row['username']] = (string)$row['last_seen'];
    }
} catch (Throwable $e) {
}
$partnerActivity = [];
try {
    foreach ($app->query("SELECT username,MAX(used_at) last_seen FROM partner_uses WHERE {$legacyPattern} GROUP BY username")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $partnerActivity[(string)$row['username']] = (string)$row['last_seen'];
    }
} catch (Throwable $e) {
}
$candidates = [];
$unknownAge = 0;
$online = 0;
foreach ($usernames as $usernameRaw) {
    $username = (string)$usernameRaw;
    $radiusLast = $radiusActivity[$username]['last_seen'] ?? null;
    $openSessions = (int)($radiusActivity[$username]['open_sessions'] ?? 0);
    if ((int)$openSessions > 0) {
        $online++;
        continue;
    }
    $timestamps = [];
    if ($radiusLast) $timestamps[] = (string)$radiusLast;
    if (!empty($adActivity[$username])) $timestamps[] = $adActivity[$username];
    if (!empty($partnerActivity[$username])) $timestamps[] = $partnerActivity[$username];
    if (!$timestamps) {
        $unknownAge++;
        continue;
    }
    rsort($timestamps);
    $lastSeen = $timestamps[0];
    if ($lastSeen < $cutoff) $candidates[] = ['username' => $username, 'last_seen_at' => $lastSeen];
}

$removed = 0;
$errors = [];
$archiveRun = null;
$archivedRows = 0;
if ($apply) {
    $schemaReady = (int)$app->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('courtesy_legacy_radius_cleanup_runs','courtesy_legacy_radius_archive')")->fetchColumn() === 2;
    if (!$schemaReady) {
        fwrite(STDERR, "A migração 021 é obrigatória antes de usar --apply.\n");
        exit(2);
    }
    $runPublicId = bin2hex(random_bytes(16));
    $nowSql = gmdate('Y-m-d H:i:s');
    $st = $app->prepare("INSERT INTO courtesy_legacy_radius_cleanup_runs
        (public_id,age_days,cutoff_at,status,candidate_usernames,created_at) VALUES (?,?,?,'running',?,?)");
    $st->execute([$runPublicId, $days, $cutoff, count($candidates), $nowSql]);
    $archiveRun = ['id' => (int)$app->lastInsertId(), 'public_id' => $runPublicId];
    $candidateLookup = array_fill_keys(array_column($candidates, 'username'), true);
    try {
        $app->beginTransaction();
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            $rows = $radius->query("SELECT * FROM {$table} WHERE {$legacyPattern}")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $batch = [];
            $flush = static function () use (&$batch, &$archivedRows, $app, $archiveRun, $table, $nowSql): void {
                if (!$batch) return;
                $values = [];
                $params = [];
                foreach ($batch as $row) {
                    $values[] = '(?,?,?,?,?,?)';
                    array_push($params, $archiveRun['id'], $table, (int)$row['id'], (string)$row['username'],
                        json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $nowSql);
                }
                $st = $app->prepare('INSERT IGNORE INTO courtesy_legacy_radius_archive
                    (run_id,source_table,source_id,username,row_data,archived_at) VALUES ' . implode(',', $values));
                $st->execute($params);
                $archivedRows += $st->rowCount();
                $batch = [];
            };
            foreach ($rows as $row) {
                if (!isset($candidateLookup[(string)$row['username']])) continue;
                $batch[] = $row;
                if (count($batch) >= 200) $flush();
            }
            $flush();
        }
        $app->commit();

        $radius->beginTransaction();
        foreach (array_chunk(array_keys($candidateLookup), 250) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
                $st = $radius->prepare("DELETE FROM {$table} WHERE username IN ({$placeholders})");
                $st->execute($chunk);
            }
        }
        $radius->commit();
        $removed = count($candidates);
        $st = $app->prepare("UPDATE courtesy_legacy_radius_cleanup_runs
            SET status='completed',archived_rows=?,removed_usernames=?,completed_at=? WHERE id=?");
        $st->execute([$archivedRows, $removed, gmdate('Y-m-d H:i:s'), $archiveRun['id']]);
    } catch (Throwable $e) {
        if ($app->inTransaction()) $app->rollBack();
        if ($radius->inTransaction()) $radius->rollBack();
        $errors[] = ['stage' => 'archive_or_delete', 'error' => $e->getMessage()];
        $st = $app->prepare("UPDATE courtesy_legacy_radius_cleanup_runs
            SET status='failed',archived_rows=?,error_detail=?,completed_at=? WHERE id=?");
        $st->execute([$archivedRows, substr($e->getMessage(), 0, 255), gmdate('Y-m-d H:i:s'), $archiveRun['id']]);
    }
}

$result = [
    'applied' => $apply,
    'age_days' => $days,
    'cutoff' => $cutoff,
    'legacy_credentials_scanned' => count($usernames),
    'candidates' => count($candidates),
    'online_skipped' => $online,
    'unknown_age_skipped' => $unknownAge,
    'removed' => $removed,
    'archived_rows' => $archivedRows,
    'archive_run' => $archiveRun,
    'errors' => $errors,
    'items' => $summaryOnly ? [] : $candidates,
];
if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo ($apply ? 'Aplicação' : 'Dry-run') . ': credenciais=' . count($usernames)
        . ', candidatas=' . count($candidates) . ', online ignoradas=' . $online
        . ', idade desconhecida ignoradas=' . $unknownAge . ', removidas=' . $removed . PHP_EOL;
    if (!$summaryOnly) foreach ($candidates as $candidate) echo '  ' . $candidate['username'] . ' — último uso ' . $candidate['last_seen_at'] . PHP_EOL;
    foreach ($errors as $error) fwrite(STDERR, '  erro ' . ($error['username'] ?? $error['stage'] ?? '?') . ': ' . $error['error'] . PHP_EOL);
    if (!$apply && $candidates) echo "Use --apply para remover somente as candidatas listadas.\n";
}
exit($errors ? 2 : 0);
