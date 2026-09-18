<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/043_payment_webhook_signatures.sql');
if ($sql === false) throw new RuntimeException('Nao foi possivel ler a migracao 043.');
$pdo->exec($sql);

echo "Migracao 043 aplicada: segredos de webhook por carteira habilitados.\n";

