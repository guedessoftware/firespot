<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$partners = (int) $pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn();
$rollouts = (int) $pdo->query('SELECT COUNT(*) FROM courtesy_portal_rollouts')->fetchColumn();
$missing = (int) $pdo->query("SELECT COUNT(*) FROM partners p CROSS JOIN (
    SELECT 'qr_ad' portal UNION ALL SELECT 'v2' UNION ALL SELECT 'classic_signup' UNION ALL SELECT 'v3'
  ) expected LEFT JOIN courtesy_portal_rollouts r ON r.partner_id=p.id AND r.portal=expected.portal
  WHERE r.partner_id IS NULL")->fetchColumn();
$enforced = (int) $pdo->query("SELECT COUNT(*) FROM courtesy_portal_rollouts WHERE mode='enforce'")->fetchColumn();
$cutover = (string) $pdo->query("SELECT svalue FROM app_settings WHERE skey='courtesy_cutover_enabled' LIMIT 1")->fetchColumn();
if ($rollouts < $partners * 4 || $missing !== 0 || ($enforced > 0 && $cutover !== '1')) {
    throw new RuntimeException('Estrutura ou gates do rollout não estão coerentes.');
}
echo 'Smoke 019 concluído. ' . $rollouts . ' rollouts compatíveis, ' . $enforced . " em enforce e gate coerente.\n";
