CREATE TABLE IF NOT EXISTS courtesy_legacy_radius_cleanup_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(32) NOT NULL,
  age_days SMALLINT UNSIGNED NOT NULL,
  cutoff_at DATETIME NOT NULL,
  status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  candidate_usernames INT UNSIGNED NOT NULL DEFAULT 0,
  archived_rows INT UNSIGNED NOT NULL DEFAULT 0,
  removed_usernames INT UNSIGNED NOT NULL DEFAULT 0,
  error_detail VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_courtesy_legacy_cleanup_public (public_id),
  KEY ix_courtesy_legacy_cleanup_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courtesy_legacy_radius_archive (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  source_table ENUM('radcheck','radreply','radusergroup') NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  username VARCHAR(64) NOT NULL,
  row_data LONGTEXT NOT NULL,
  archived_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_courtesy_legacy_archive_row (run_id,source_table,source_id),
  KEY ix_courtesy_legacy_archive_username (username),
  CONSTRAINT fk_courtesy_legacy_archive_run FOREIGN KEY (run_id)
    REFERENCES courtesy_legacy_radius_cleanup_runs(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
