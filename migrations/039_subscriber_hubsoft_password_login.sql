-- Segundo método da Minha Conta: senha validada diretamente no HubSoft.
-- A tabela registra somente hashes de limitação e códigos operacionais; nunca
-- recebe a senha, o documento em claro ou a resposta remota.
CREATE TABLE IF NOT EXISTS subscriber_auth_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'hubsoft',
  auth_method VARCHAR(40) NOT NULL DEFAULT 'hubsoft_password',
  document_hash CHAR(64) NOT NULL,
  origin_hash CHAR(64) NULL,
  outcome ENUM('success','denied','error','blocked') NOT NULL,
  result_code VARCHAR(48) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_subscriber_auth_document (document_hash,outcome,created_at),
  KEY idx_subscriber_auth_origin (origin_hash,outcome,created_at),
  KEY idx_subscriber_auth_account (account_id,created_at),
  CONSTRAINT fk_subscriber_auth_account FOREIGN KEY (account_id) REFERENCES subscriber_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (skey,svalue) VALUES
  ('subscriber_retention_auth_attempt_days','30')
ON DUPLICATE KEY UPDATE skey=VALUES(skey);
