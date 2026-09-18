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
$total = (int) $pdo->query('SELECT COUNT(*) FROM courtesy_portal_rollouts')->fetchColumn();
$enforced = (int) $pdo->query("SELECT COUNT(*) FROM courtesy_portal_rollouts WHERE mode='enforce'")->fetchColumn();
$classic = (int) $pdo->query("SELECT COUNT(*) FROM courtesy_portal_rollouts WHERE portal='classic'")->fetchColumn();
if ($total !== $partners * 5 || $classic !== 0) {
    throw new RuntimeException('Estado do rollout após a migração 020 está incompleto.');
}
echo 'Smoke 020 concluído. ' . $total . ' rollouts, ' . $enforced . " em enforce e chaves clássicas separadas.\n";
