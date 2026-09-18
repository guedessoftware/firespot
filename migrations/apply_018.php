<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/018_courtesy_radius_shadow.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração 018.');
$pdo->exec($sql);

$table = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='courtesy_shadow_events' LIMIT 1")->fetchColumn();
$columns = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='courtesy_grants'
      AND COLUMN_NAME IN ('provision_attempts','last_accounting_at','radius_cleaned_at')")->fetchColumn();
$policyColumns = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME IN ('courtesy_policy_defaults','courtesy_partner_policies')
      AND COLUMN_NAME='credit_validity_minutes'")->fetchColumn();
$enabled = $pdo->query("SELECT svalue FROM app_settings WHERE skey='courtesy_shadow_enabled' LIMIT 1")->fetchColumn();
if (!$table || $columns !== 3 || $policyColumns !== 2 || $enabled === false) {
    throw new RuntimeException('A estrutura de provisionamento/shadow não foi criada por completo.');
}

echo "Migração 018 aplicada. Provisionamento RADIUS e shadow mode estruturados.\n";
