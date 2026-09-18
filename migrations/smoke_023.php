<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/schema_guard.php';

$pdo = db();
foreach ([
    'app_settings' => ['skey','svalue'],
    'radius_servers' => ['id','host','secret'],
    'company_profile' => ['id','name'],
    'deleted_accounts_log' => ['id','payload'],
    'msg_whatsapp_log' => ['username','phone','day_key'],
    'vip_passes' => ['username','status','expires_at'],
    'vip_orders' => ['payment_method','payment_expires_at','refund_status'],
    'clientes_info' => ['vip_ativo','vip_until'],
] as $table => $columns) runtime_schema_require($pdo, $table, $columns);

$st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='host_password_resets' AND COLUMN_NAME IN ('reset_token','reset_expires_at')");
$st->execute();
if ((int)$st->fetchColumn() !== 0) throw new RuntimeException('Colunas antigas de reset ainda presentes.');

echo "Smoke 023 concluído. Rotas podem operar sem DDL.\n";
