-- Migração: Adiciona detalhes do método de pagamento e suporte a reembolsos
-- Data: 2025-12-08
-- Descrição: Inclui colunas para rastrear método de pagamento e status de reembolso dos pedidos VIP

ALTER TABLE vip_orders
  ADD COLUMN IF NOT EXISTS payment_method VARCHAR(16) NOT NULL DEFAULT 'pix' AFTER duracao_min,
  ADD COLUMN IF NOT EXISTS payment_method_detail VARCHAR(32) NULL AFTER payment_method,
  ADD COLUMN IF NOT EXISTS payment_installments TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER payment_method_detail,
  ADD COLUMN IF NOT EXISTS mp_refund_id VARCHAR(64) NULL AFTER mp_payment_id,
  ADD COLUMN IF NOT EXISTS refund_status ENUM('none','requested','partial','processed','failed') NOT NULL DEFAULT 'none' AFTER vip_applied_at,
  ADD COLUMN IF NOT EXISTS refund_amount_centavos INT UNSIGNED NULL AFTER refund_status,
  ADD COLUMN IF NOT EXISTS refunded_at DATETIME NULL AFTER refund_amount_centavos,
  ADD COLUMN IF NOT EXISTS refund_notes VARCHAR(255) NULL AFTER refunded_at;

-- Atualiza enum do status para comportar reembolsos completos
ALTER TABLE vip_orders
  MODIFY COLUMN status ENUM('pending','paid','cancelled','refunded') NOT NULL DEFAULT 'pending';

-- Índices auxiliares
ALTER TABLE vip_orders
  ADD INDEX IF NOT EXISTS idx_payment_method (payment_method),
  ADD INDEX IF NOT EXISTS idx_refund_status (refund_status);
