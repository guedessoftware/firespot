<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/portal_theme.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
portal_theme_ensure_table($pdo);

$st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_portal_themes' AND COLUMN_NAME='theme_preset'");
$st->execute();
if ((int)$st->fetchColumn() < 1) throw new RuntimeException('Coluna de presets não encontrada após a migração.');

echo "Migração 006 aplicada. Presets visuais do Portal V3 habilitados.\n";
