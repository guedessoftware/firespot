<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Script para aplicar migração 001
require_once __DIR__ . '/../app/db.php';

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Aplicando migração 001_fix_vip_orders.sql...\n\n";
    
    // 1. Adicionar colunas de rastreamento
    echo "1. Adicionando colunas de rastreamento...\n";
    try {
        $pdo->exec("ALTER TABLE vip_orders ADD COLUMN host_code VARCHAR(50) NULL COMMENT 'Código do hotspot de origem' AFTER external_ref");
        echo "  ✓ Coluna host_code adicionada\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "  - Coluna host_code já existe\n";
        } else throw $e;
    }
    
    try {
        $pdo->exec("ALTER TABLE vip_orders ADD COLUMN device_mac VARCHAR(17) NULL COMMENT 'MAC do dispositivo' AFTER host_code");
        echo "  ✓ Coluna device_mac adicionada\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "  - Coluna device_mac já existe\n";
        } else throw $e;
    }
    
    try {
        $pdo->exec("ALTER TABLE vip_orders ADD COLUMN device_ip VARCHAR(45) NULL COMMENT 'IP do dispositivo' AFTER device_mac");
        echo "  ✓ Coluna device_ip adicionada\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "  - Coluna device_ip já existe\n";
        } else throw $e;
    }
    
    try {
        $pdo->exec("ALTER TABLE vip_orders ADD COLUMN vip_applied_at DATETIME NULL COMMENT 'Quando VIP foi aplicado' AFTER radius_applied_sec");
        echo "  ✓ Coluna vip_applied_at adicionada\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "  - Coluna vip_applied_at já existe\n";
        } else throw $e;
    }
    
    // 2. Adicionar índices
    echo "\n2. Adicionando índices de performance...\n";
    try {
        $pdo->exec("ALTER TABLE vip_orders ADD INDEX idx_host_code (host_code)");
        echo "  ✓ Índice idx_host_code adicionado\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key') !== false) {
            echo "  - Índice idx_host_code já existe\n";
        } else throw $e;
    }
    
    try {
        $pdo->exec("ALTER TABLE vip_orders ADD INDEX idx_created_status (created_at, status)");
        echo "  ✓ Índice idx_created_status adicionado\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key') !== false) {
            echo "  - Índice idx_created_status já existe\n";
        } else throw $e;
    }
    
    try {
        $pdo->exec("ALTER TABLE vip_orders ADD INDEX idx_paid_at (paid_at)");
        echo "  ✓ Índice idx_paid_at adicionado\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key') !== false) {
            echo "  - Índice idx_paid_at já existe\n";
        } else throw $e;
    }
    
    // 3. Criar tabela de auditoria
    echo "\n3. Criando tabela de auditoria...\n";
    $pdo->exec("
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
          INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Tabela vip_orders_audit criada/verificada\n";
    
    echo "\n✅ Migração concluída com sucesso!\n";
    
} catch (Exception $e) {
    echo "\n❌ ERRO: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
