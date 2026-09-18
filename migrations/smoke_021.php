<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$runColumns = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='courtesy_legacy_radius_cleanup_runs'")->fetchColumn();
$archiveColumns = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='courtesy_legacy_radius_archive'")->fetchColumn();
if ($runColumns < 11 || $archiveColumns < 7) throw new RuntimeException('Estrutura da migração 021 incompleta.');
echo "Smoke 021 concluído. Arquivo de limpeza legado íntegro.\n";
