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

$st = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_portal_themes'
      AND COLUMN_NAME IN ('text_color','muted_text_color','hero_text_color','button_text_color','footer_text_color')");
if ((int) $st->fetchColumn() !== 5) throw new RuntimeException('Colunas de cores de texto não encontradas.');

echo "Migração 009 aplicada. Cores de texto do Portal V3 habilitadas.\n";
