<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/schema_guard.php';

$pdo = db();
runtime_schema_require($pdo, 'custom_ads', ['media_type','media_url','poster_url','fit_mode','image_url','duration_sec']);
runtime_schema_require($pdo, 'custom_ads_events', ['event','partner_id']);
runtime_schema_require($pdo, 'ad_pending_offers', ['token_hash','partner_id','ad_id','expires_at','opened_at','dismissed_at']);

$missingMedia = (int)$pdo->query("SELECT COUNT(*) FROM custom_ads WHERE media_url IS NULL OR media_url=''")->fetchColumn();
if ($missingMedia !== 0) throw new RuntimeException('Existem anúncios sem media_url após a migração.');
$invalidDuration = (int)$pdo->query('SELECT COUNT(*) FROM custom_ads WHERE duration_sec<5 OR duration_sec>180')->fetchColumn();
if ($invalidDuration !== 0) throw new RuntimeException('Existem anúncios fora do intervalo de tempo obrigatório.');

$column = $pdo->query("SHOW COLUMNS FROM custom_ads_events LIKE 'event'")->fetch(PDO::FETCH_ASSOC);
$type = strtolower((string)($column['Type'] ?? ''));
foreach (['view_complete','skipped','destination_open'] as $event) {
    if (strpos($type, "'{$event}'") === false) throw new RuntimeException("Evento {$event} ausente no esquema.");
}

echo "Smoke 024 concluído. Mídias e eventos estão consistentes.\n";
