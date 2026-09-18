<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/042_portal_v3_navigation_options.sql');
if ($sql === false) throw new RuntimeException('Nao foi possivel ler a migracao 042.');
$pdo->exec($sql);

echo "Migracao 042 aplicada: navegacao configuravel do Portal V3 habilitada.\n";
