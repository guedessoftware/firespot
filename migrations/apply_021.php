<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/021_courtesy_legacy_radius_archive.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração 021.');
$pdo->exec($sql);

foreach (['courtesy_legacy_radius_cleanup_runs', 'courtesy_legacy_radius_archive'] as $table) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    if ((int)$st->fetchColumn() !== 1) throw new RuntimeException('Tabela ausente após migração 021: ' . $table);
}
echo "Migração 021 aplicada. Arquivo recuperável de credenciais legadas disponível.\n";
