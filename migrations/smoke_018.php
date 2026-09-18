<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$indexes = (int) $pdo->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='courtesy_shadow_events'
      AND INDEX_NAME IN ('idx_courtesy_shadow_partner_created','idx_courtesy_shadow_match_created','idx_courtesy_shadow_source_created')")->fetchColumn();
$columns = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='courtesy_grants'
      AND COLUMN_NAME IN ('provision_attempts','last_accounting_at','radius_cleaned_at')")->fetchColumn();
$policyColumns = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME IN ('courtesy_policy_defaults','courtesy_partner_policies')
      AND COLUMN_NAME='credit_validity_minutes'")->fetchColumn();
if ($indexes !== 3 || $columns !== 3 || $policyColumns !== 2) throw new RuntimeException('Estrutura da migração 018 incompleta.');

$enabled = (string) $pdo->query("SELECT svalue FROM app_settings WHERE skey='courtesy_shadow_enabled' LIMIT 1")->fetchColumn();
$events = (int) $pdo->query('SELECT COUNT(*) FROM courtesy_shadow_events')->fetchColumn();
echo 'Smoke 018 concluído. shadow_enabled=' . $enabled . ', eventos=' . $events . ".\n";
