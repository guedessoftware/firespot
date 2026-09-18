<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/schema_guard.php';

$pdo = db();
runtime_schema_require($pdo,'custom_ads',['interest_button_text','skip_button_text']);
$invalid = (int)$pdo->query("SELECT COUNT(*) FROM custom_ads WHERE TRIM(interest_button_text)='' OR TRIM(skip_button_text)='' OR CHAR_LENGTH(interest_button_text)>60 OR CHAR_LENGTH(skip_button_text)>60")->fetchColumn();
if ($invalid !== 0) throw new RuntimeException('Existem campanhas com textos de botão inválidos.');
echo "Smoke 025 concluído. Textos de campanha estão consistentes.\n";
