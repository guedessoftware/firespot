<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/028_nas_base_provisioning.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração 028.');
$pdo->exec($sql);

echo "Migração 028 aplicada. Preparação-base dos NAS habilitada.\n";
