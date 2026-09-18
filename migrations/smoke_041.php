<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$required = [
    'guest_orders' => [
        'payment_access_mode','radius_phase','radius_provisional_started_at','radius_provisional_expires_at',
        'radius_paid_baseline_seconds','radius_coa_status','radius_coa_attempts','radius_coa_last_attempt_at',
        'radius_coa_applied_at','radius_coa_error_code','radius_coa_radacctid',
    ],
    'nas_base_provisioning' => ['coa_status','coa_port','coa_checked_at','coa_error_code'],
];
foreach ($required as $table => $columns) {
    $in = implode(',',array_fill(0,count($columns),'?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN ($in)");
    $st->execute(array_merge([$table],$columns));
    if ((int)$st->fetchColumn() !== count($columns)) throw new RuntimeException("Estrutura RADIUS/CoA incompleta em {$table}.");
}
echo "Smoke 041 OK: ledger de pre-autenticacao e prontidao CoA disponiveis.\n";

