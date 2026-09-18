<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("ALTER TABLE nas_health
    ADD COLUMN IF NOT EXISTS hotspot_host_count INT(11) UNSIGNED DEFAULT NULL AFTER interface_count");

$ready = (bool) $pdo->query("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nas_health' AND COLUMN_NAME='hotspot_host_count' LIMIT 1")->fetchColumn();
if (!$ready) throw new RuntimeException('A coluna de hosts do Hotspot não foi criada.');

echo "Migração 015 aplicada. Contagem de hosts do Hotspot habilitada na saúde dos NAS.\n";
