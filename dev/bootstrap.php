#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/env.php';
if (env('APP_ENV') !== 'local' || env('DB_DATABASE') !== 'firespot_local') {
    fwrite(STDERR, "Comando exclusivo do banco firespot_local em APP_ENV=local.\n");
    exit(64);
}
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/cli/migration_framework.php';

$pdo = db();
$count = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
if ($count === 0) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../app/cli/install_fresh_database.php')
        . ' --confirm-empty-database=FIRESPOT_EMPTY_DATABASE';
    passthru($command, $code);
    if ($code !== 0) exit($code);
} else {
    $ledger = fs_migration_ledger($pdo);
    $inventory = fs_migration_inventory();
    if (count($ledger) !== count($inventory) || fs_migration_validate_ledger($inventory, $ledger)) {
        fwrite(STDERR, "Banco local existente sem ledger compatível. Revise antes de continuar.\n");
        exit(2);
    }
}

$password = (string) env('LOCAL_ADMIN_PASSWORD', '');
if (strlen($password) < 16) throw new RuntimeException('Senha local ausente ou inválida.');
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('INSERT INTO admin_users (username,password_hash,role) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE username=VALUES(username)');
    $st->execute(['admin_local', password_hash($password, PASSWORD_DEFAULT), 'admin']);
    $setting = $pdo->prepare('INSERT INTO app_settings (skey,svalue) VALUES (?,?)
        ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
    $setting->execute(['public_base_url', (string) env('APP_URL')]);
    $setting->execute(['local_development_initialized', '1']);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
echo 'Ambiente pronto: ' . env('APP_URL') . "/dashboard/login.php — usuário admin_local.\n";
echo "Banco de desenvolvimento sem dados de produção. Cadastre demonstrações pelo painel.\n";
