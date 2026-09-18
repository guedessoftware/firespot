<?php
declare(strict_types=1);

function fs_dashboard_user_stats_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT username,last_seen,visits_30d,online,used_seconds FROM dashboard_user_access_stats LIMIT 0');
        $pdo->query('SELECT refreshed_at,duration_ms,row_count FROM dashboard_user_access_stats_meta LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fs_dashboard_user_stats_ttl(): int
{
    return max(30, min(300, (int)(getenv('DASHBOARD_USER_STATS_TTL') ?: 60)));
}

function fs_dashboard_user_stats_state(PDO $pdo): array
{
    $row = $pdo->query('SELECT refreshed_at,duration_ms,row_count,
            CASE WHEN refreshed_at IS NULL THEN NULL ELSE GREATEST(0,TIMESTAMPDIFF(SECOND,refreshed_at,NOW())) END AS age_seconds
        FROM dashboard_user_access_stats_meta WHERE id=1 LIMIT 1')
        ->fetch(PDO::FETCH_ASSOC) ?: [];
    $refreshedAt = trim((string)($row['refreshed_at'] ?? ''));
    $age = $refreshedAt !== '' && isset($row['age_seconds']) ? max(0, (int)$row['age_seconds']) : PHP_INT_MAX;
    return [
        'refreshed_at' => $refreshedAt !== '' ? $refreshedAt : null,
        'duration_ms' => isset($row['duration_ms']) ? (int)$row['duration_ms'] : null,
        'row_count' => (int)($row['row_count'] ?? 0),
        'age_seconds' => $age,
        'stale' => $age > fs_dashboard_user_stats_ttl(),
    ];
}

function fs_dashboard_user_stats_refresh(PDO $pdo, bool $waitForLock = false): array
{
    if (!fs_dashboard_user_stats_schema_ready($pdo)) {
        return ['ok' => false, 'error' => 'schema_not_ready'];
    }

    $lock = $pdo->prepare('SELECT GET_LOCK(?,?)');
    $lock->execute(['firespot_dashboard_user_stats', $waitForLock ? 10 : 0]);
    if ((int)$lock->fetchColumn() !== 1) {
        return ['ok' => true, 'busy' => true, 'refreshed' => false];
    }

    $started = microtime(true);
    try {
        $grace = max(30, min(900, (int)(getenv('ONLINE_GRACE_SEC') ?: 180)));
        $sql = "INSERT INTO dashboard_user_access_stats
            (username,last_seen,visits_30d,online,used_seconds,updated_at)
          SELECT username,
                 MAX(COALESCE(acctupdatetime,acctstarttime)) AS last_seen,
                 SUM(acctstarttime >= (NOW() - INTERVAL 30 DAY)) AS visits_30d,
                 MAX(CASE
                       WHEN acctstoptime IS NULL
                        AND TIMESTAMPDIFF(SECOND,COALESCE(acctupdatetime,acctstarttime,NOW()),NOW()) <= {$grace}
                       THEN 1 ELSE 0
                     END) AS online,
                 COALESCE(SUM(acctsessiontime),0) AS used_seconds,
                 NOW()
            FROM radacct
           WHERE username IS NOT NULL AND username<>''
           GROUP BY username
          ON DUPLICATE KEY UPDATE
            last_seen=VALUES(last_seen),
            visits_30d=VALUES(visits_30d),
            online=VALUES(online),
            used_seconds=VALUES(used_seconds),
            updated_at=VALUES(updated_at)";
        $pdo->exec($sql);
        $rows = (int)$pdo->query('SELECT COUNT(*) FROM dashboard_user_access_stats')->fetchColumn();
        $durationMs = max(0, (int)round((microtime(true) - $started) * 1000));
        $pdo->prepare('UPDATE dashboard_user_access_stats_meta SET refreshed_at=NOW(),duration_ms=?,row_count=? WHERE id=1')
            ->execute([$durationMs, $rows]);
        return ['ok' => true, 'busy' => false, 'refreshed' => true, 'rows' => $rows, 'duration_ms' => $durationMs];
    } catch (Throwable $e) {
        error_log('[dashboard user stats] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'refresh_failed'];
    } finally {
        try {
            $unlock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $unlock->execute(['firespot_dashboard_user_stats']);
        } catch (Throwable $e) {
        }
    }
}
