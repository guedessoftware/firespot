<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/019_courtesy_portal_rollout.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração 019.');
$pdo->exec($sql);

$table = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='courtesy_portal_rollouts' LIMIT 1")->fetchColumn();
$missing = (int) $pdo->query("SELECT COUNT(*) FROM partners p CROSS JOIN (
    SELECT 'qr_ad' portal UNION ALL SELECT 'v2' UNION ALL SELECT 'classic_signup' UNION ALL SELECT 'v3'
  ) expected LEFT JOIN courtesy_portal_rollouts r ON r.partner_id=p.id AND r.portal=expected.portal
  WHERE r.partner_id IS NULL")->fetchColumn();
$settings = (int) $pdo->query("SELECT COUNT(*) FROM app_settings WHERE skey IN ('courtesy_cutover_enabled','courtesy_radius_ready','courtesy_mikrotik_ready')")->fetchColumn();
if (!$table || $missing !== 0 || $settings !== 3) {
    throw new RuntimeException('A estrutura de rollout da cortesia não foi criada por completo.');
}

echo "Migração 019 aplicada. Estrutura e rollouts preservados; gates existentes não foram resetados.\n";
