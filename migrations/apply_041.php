<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/041_guest_payment_radius_handoff.sql');
if ($sql === false) throw new RuntimeException('Nao foi possivel ler a migracao 041.');
$pdo->exec($sql);

echo "Migracao 041 aplicada: pre-autenticacao Pix e promocao RADIUS/CoA habilitadas.\n";

