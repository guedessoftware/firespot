<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$columns = ['webhook_secret_encrypted','webhook_secret_hint','webhook_secret_configured_at'];
$in = implode(',',array_fill(0,count($columns),'?'));
$st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_wallets' AND COLUMN_NAME IN ($in)");
$st->execute($columns);
if ((int)$st->fetchColumn() !== count($columns)) throw new RuntimeException('Estrutura de assinatura de webhook incompleta.');

$invalid = (int)$pdo->query("SELECT COUNT(*) FROM payment_wallets
    WHERE (webhook_secret_encrypted IS NULL)<>(webhook_secret_hint IS NULL)
       OR (webhook_secret_encrypted IS NULL)<>(webhook_secret_configured_at IS NULL)")->fetchColumn();
if ($invalid !== 0) throw new RuntimeException('Carteira com configuracao parcial de assinatura de webhook.');

echo "Smoke 043 OK: segredos de webhook consistentes e protegidos.\n";

