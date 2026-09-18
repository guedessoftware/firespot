<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../app/env.php';
if (env('DB_HOST') !== 'db') throw new RuntimeException('Bootstrap RADIUS exclusivo do serviço db local.');
require __DIR__ . '/../bootstrap.php';
$root = trim((string)file_get_contents('/run/secrets/db_root_password'));
$password = trim((string)file_get_contents('/run/secrets/radius_db_password'));
if (!preg_match('/^[a-f0-9]{64}$/D', $root) || !preg_match('/^[a-f0-9]{48}$/D', $password)) {
    throw new RuntimeException('Segredos SQL locais inválidos.');
}
try {
    $admin = new PDO('mysql:host=db;dbname=firespot_local;charset=utf8mb4', 'root', $root,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $admin->exec("CREATE USER IF NOT EXISTS 'firespot_radius'@'%' IDENTIFIED BY " . $admin->quote($password));
    foreach (['radcheck','radreply','radusergroup','radacct','radpostauth'] as $table) {
        $admin->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON firespot_local.{$table} TO 'firespot_radius'@'%'");
    }
    foreach (['nas','radgroupcheck','radgroupreply'] as $table) {
        $admin->exec("GRANT SELECT ON firespot_local.{$table} TO 'firespot_radius'@'%'");
    }
    new PDO('mysql:host=db;dbname=firespot_local', 'firespot_radius', $password);
} catch (PDOException $error) {
    fwrite(STDERR, 'Bootstrap SQL RADIUS falhou: SQLSTATE ' . $error->getCode() . "\n");
    exit(2);
}
echo "FreeRADIUS local: conta SQL com permissões restritas pronta.\n";
if (env('FIRESPOT_LAB') === '1') require __DIR__ . '/../lab/seed.php';
