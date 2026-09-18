<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$column = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nas_health' AND COLUMN_NAME='hotspot_host_count' LIMIT 1")->fetchColumn();
if (stripos((string)$column, 'unsigned') === false) {
    throw new RuntimeException('A coluna de hosts do Hotspot está ausente ou possui tipo inválido.');
}

echo "Smoke 015 concluído. Contagem de hosts pronta para atualização.\n";
