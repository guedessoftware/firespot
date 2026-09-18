<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$expected = [
    'payment_window_minutes' => '2',
    'payment_window_daily_limit' => '3',
    'payment_window_cooldown_minutes' => '10',
    'payment_window_period_minutes' => '1440',
];
$st = $pdo->prepare("SELECT COLUMN_NAME,COLUMN_DEFAULT FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partners' AND COLUMN_NAME IN (?,?,?,?)");
$st->execute(array_keys($expected));
$found = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $column) $found[(string)$column['COLUMN_NAME']] = (string)$column['COLUMN_DEFAULT'];
if ($found !== $expected) {
    fwrite(STDERR,'Smoke 032 falhou: ' . json_encode($found,JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
echo "Smoke 032 OK: política individual das janelas Pix disponível.\n";
