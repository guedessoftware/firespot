<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$column = $pdo->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partners' AND COLUMN_NAME='payment_window_minutes' LIMIT 1")->fetchColumn();
$table = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guest_payment_windows' LIMIT 1")->fetchColumn();
$indexes = (int) $pdo->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guest_payment_windows'
      AND INDEX_NAME IN ('uq_guest_payment_windows_token','idx_guest_payment_windows_device','idx_guest_payment_windows_expiry','idx_guest_payment_windows_order')")->fetchColumn();

if ((string)$column !== '2') throw new RuntimeException('O padrão da janela Pix não é 2 minutos.');
if (!$table || $indexes !== 4) throw new RuntimeException('Tabela ou índices de proteção da janela Pix incompletos.');

echo "Smoke 014 concluído. Política da janela Pix pronta.\n";
