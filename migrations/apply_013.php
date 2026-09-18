<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec((string) file_get_contents(__DIR__ . '/013_payment_session_schema.sql'));

$hasAmountCents = (bool) $pdo->query(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='payments_session'
        AND COLUMN_NAME='amount_centavos'
      LIMIT 1"
)->fetchColumn();

if (!$hasAmountCents) {
    $pdo->exec('ALTER TABLE payments_session ADD COLUMN amount_centavos INT UNSIGNED NULL AFTER amount');
}

echo "Migração 013 aplicada. Estrutura de payments_session validada.\n";
