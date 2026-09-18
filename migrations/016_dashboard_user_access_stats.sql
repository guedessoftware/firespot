CREATE TABLE IF NOT EXISTS dashboard_user_access_stats (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_user_access_stats_meta (
  id TINYINT UNSIGNED NOT NULL,
  refreshed_at DATETIME DEFAULT NULL,
  duration_ms INT UNSIGNED DEFAULT NULL,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dashboard_user_access_stats_meta (id) VALUES (1);
