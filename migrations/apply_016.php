<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/dashboard_user_stats.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE IF NOT EXISTS dashboard_user_access_stats (
    username VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    last_seen DATETIME DEFAULT NULL,
    visits_30d INT UNSIGNED NOT NULL DEFAULT 0,
    online TINYINT(1) NOT NULL DEFAULT 0,
    used_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (username),
    KEY idx_dashboard_user_stats_last_seen (last_seen),
    KEY idx_dashboard_user_stats_visits (visits_30d),
    KEY idx_dashboard_user_stats_online (online,last_seen)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS dashboard_user_access_stats_meta (
    id TINYINT UNSIGNED NOT NULL,
    refreshed_at DATETIME DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec('INSERT IGNORE INTO dashboard_user_access_stats_meta (id) VALUES (1)');

$result = fs_dashboard_user_stats_refresh($pdo, true);
if (empty($result['ok'])) throw new RuntimeException('Não foi possível gerar o resumo inicial de Clientes.');

echo 'Migração 016 aplicada. Resumo de clientes: ' . (int)$result['rows'] . ' usuários em ' . (int)$result['duration_ms'] . " ms.\n";
