<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$required = [
    'subscriber_benefit_profiles' => ['download_kbps', 'upload_kbps'],
    'subscriber_plan_mappings' => ['download_kbps', 'upload_kbps'],
];
foreach ($required as $table => $columns) {
    $in = implode(',', array_fill(0, count($columns), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN ($in)");
    $st->execute(array_merge([$table], $columns));
    if ((int) $st->fetchColumn() !== count($columns)) throw new RuntimeException("Limites ausentes em {$table}.");
}

$invalidProfiles = (int) $pdo->query('SELECT COUNT(*) FROM subscriber_benefit_profiles WHERE active=1 AND (download_kbps=0 OR upload_kbps=0)')->fetchColumn();
if ($invalidProfiles !== 0) throw new RuntimeException('Existe perfil FIRENETWORK ativo sem limite de velocidade.');

echo "Smoke 040 OK: perfis e mapeamentos possuem limites de velocidade.\n";
