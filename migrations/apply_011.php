<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = (string)file_get_contents(__DIR__ . '/011_dashboard_operations_schema.sql');
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) {
    $pdo->exec($statement);
}

$required = ['custom_ads', 'custom_ads_events', 'promo_queue', 'nas_health'];
$placeholders = implode(',', array_fill(0, count($required), '?'));
$st = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$placeholders})");
$st->execute($required);
$found = $st->fetchAll(PDO::FETCH_COLUMN);
$missing = array_values(array_diff($required, $found));
if ($missing) {
    throw new RuntimeException('Tabelas ausentes: ' . implode(', ', $missing));
}

echo "Migração 011 aplicada. Estruturas operacionais do dashboard estão prontas.\n";
