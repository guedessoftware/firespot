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
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_portal_themes'
      AND COLUMN_NAME IN ('logo_light_path','logo_dark_path')");
$st->execute();
if ((int)$st->fetchColumn() !== 2) throw new RuntimeException('Colunas dos logotipos claro e escuro não encontradas.');

$st = $pdo->query("SHOW COLUMNS FROM partner_portal_themes LIKE 'theme_preset'");
$column = $st->fetch(PDO::FETCH_ASSOC);
if (!$column || strpos((string)$column['Type'], 'compact_light') === false) {
    throw new RuntimeException('Preset compacto claro não encontrado após a migração.');
}

echo "Migração 008 aplicada. Logos duplos e compacto claro habilitados.\n";
