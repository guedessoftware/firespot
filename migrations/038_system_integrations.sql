CREATE TABLE IF NOT EXISTS system_integrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(40) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  base_url VARCHAR(300) NOT NULL,
  client_id VARCHAR(120) NOT NULL,
  client_secret_encrypted MEDIUMTEXT NOT NULL,
  username_encrypted MEDIUMTEXT NOT NULL,
  password_encrypted MEDIUMTEXT NOT NULL,
  grant_type VARCHAR(40) NOT NULL DEFAULT 'password',
  active TINYINT(1) NOT NULL DEFAULT 0,
  last_test_at DATETIME NULL,
  last_test_ok TINYINT(1) NULL,
  last_test_code VARCHAR(80) NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_system_integrations_provider (provider),
  KEY idx_system_integrations_active (active,provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_integration_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(40) NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_system_integration_audit_provider (provider,created_at),
  KEY idx_system_integration_audit_actor (actor_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
