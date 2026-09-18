<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/023_runtime_schema_consolidation.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração 023.');
$pdo->exec($sql);
echo "Migração 023 aplicada. DDL de requisições consolidado.\n";
