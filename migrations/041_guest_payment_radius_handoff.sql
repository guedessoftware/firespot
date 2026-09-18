-- Sessao RADIUS provisoria do Pix e promocao da mesma sessao via CoA.
-- Os campos ficam no pedido para tornar webhooks duplicados e reconciliacao
-- idempotentes, sem depender do estado da WebView do cliente.

ALTER TABLE guest_orders
  ADD COLUMN IF NOT EXISTS payment_access_mode VARCHAR(24) NULL AFTER payment_window_closed_at,
  ADD COLUMN IF NOT EXISTS radius_phase VARCHAR(24) NULL AFTER payment_access_mode,
  ADD COLUMN IF NOT EXISTS radius_provisional_started_at DATETIME NULL AFTER radius_phase,
  ADD COLUMN IF NOT EXISTS radius_provisional_expires_at DATETIME NULL AFTER radius_provisional_started_at,
  ADD COLUMN IF NOT EXISTS radius_paid_baseline_seconds INT UNSIGNED NULL AFTER radius_provisional_expires_at,
  ADD COLUMN IF NOT EXISTS radius_coa_status VARCHAR(24) NULL AFTER radius_paid_baseline_seconds,
  ADD COLUMN IF NOT EXISTS radius_coa_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER radius_coa_status,
  ADD COLUMN IF NOT EXISTS radius_coa_last_attempt_at DATETIME NULL AFTER radius_coa_attempts,
  ADD COLUMN IF NOT EXISTS radius_coa_applied_at DATETIME NULL AFTER radius_coa_last_attempt_at,
  ADD COLUMN IF NOT EXISTS radius_coa_error_code VARCHAR(64) NULL AFTER radius_coa_applied_at,
  ADD COLUMN IF NOT EXISTS radius_coa_radacctid BIGINT NULL AFTER radius_coa_error_code,
  ADD INDEX IF NOT EXISTS idx_guest_orders_radius_handoff (radius_phase,radius_coa_status,radius_coa_last_attempt_at);

ALTER TABLE nas_base_provisioning
  ADD COLUMN IF NOT EXISTS coa_status VARCHAR(16) NOT NULL DEFAULT 'unknown' AFTER routeros_version,
  ADD COLUMN IF NOT EXISTS coa_port SMALLINT UNSIGNED NOT NULL DEFAULT 3799 AFTER coa_status,
  ADD COLUMN IF NOT EXISTS coa_checked_at DATETIME NULL AFTER coa_port,
  ADD COLUMN IF NOT EXISTS coa_error_code VARCHAR(64) NULL AFTER coa_checked_at;

-- Janelas abertas antes desta versao continuam no transporte antigo ate o
-- proprio encerramento; nenhuma regra remota e reinterpretada no meio do Pix.
UPDATE guest_orders
SET payment_access_mode='legacy_binding'
WHERE payment_access_mode IS NULL AND payment_window_token IS NOT NULL;
