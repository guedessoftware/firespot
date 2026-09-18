-- Migração: Correções e melhorias na tabela vip_orders
-- Data: 2025-12-05
-- Descrição: Adiciona rastreamento de origem, device tracking e índices de performance

-- 1. Adicionar colunas de rastreamento (se não existirem)
ALTER TABLE vip_orders 
  ADD COLUMN IF NOT EXISTS host_code VARCHAR(50) NULL COMMENT 'Código do hotspot de origem' AFTER external_ref,
  ADD COLUMN IF NOT EXISTS device_mac VARCHAR(17) NULL COMMENT 'MAC do dispositivo' AFTER host_code,
  ADD COLUMN IF NOT EXISTS device_ip VARCHAR(45) NULL COMMENT 'IP do dispositivo' AFTER device_mac,
  ADD COLUMN IF NOT EXISTS vip_applied_at DATETIME NULL COMMENT 'Quando VIP foi aplicado' AFTER radius_applied_sec;

-- 2. Adicionar índices de performance (se não existirem)
ALTER TABLE vip_orders
  ADD INDEX IF NOT EXISTS idx_host_code (host_code),
  ADD INDEX IF NOT EXISTS idx_created_status (created_at, status),
  ADD INDEX IF NOT EXISTS idx_paid_at (paid_at);

-- 3. Criar tabela de auditoria de vendas
CREATE TABLE IF NOT EXISTS vip_orders_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  admin_username VARCHAR(100) NULL,
  action VARCHAR(50) NOT NULL COMMENT 'cancel, resend_whatsapp, manual_apply, etc',
  old_status VARCHAR(20) NULL,
  new_status VARCHAR(20) NULL,
  notes TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_order (order_id),
  INDEX idx_admin (admin_username),
  INDEX idx_created (created_at),
  FOREIGN KEY (order_id) REFERENCES vip_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Adicionar trigger para auditoria automática de mudanças de status
DELIMITER $$

DROP TRIGGER IF EXISTS vip_orders_status_audit$$
CREATE TRIGGER vip_orders_status_audit
AFTER UPDATE ON vip_orders
FOR EACH ROW
BEGIN
  IF OLD.status != NEW.status THEN
    INSERT INTO vip_orders_audit (order_id, action, old_status, new_status, notes)
    VALUES (NEW.id, 'status_change', OLD.status, NEW.status, 
            CONCAT('Auto: ', OLD.status, ' -> ', NEW.status));
  END IF;
END$$

DELIMITER ;
