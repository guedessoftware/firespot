<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/dashboard_user_stats.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!fs_dashboard_user_stats_schema_ready($pdo)) throw new RuntimeException('Estrutura do resumo de Clientes incompleta.');
$state = fs_dashboard_user_stats_state($pdo);
if (($state['refreshed_at'] ?? null) === null || (int)($state['row_count'] ?? 0) < 1) {
    throw new RuntimeException('O resumo de Clientes ainda não possui dados.');
}

echo 'Smoke 016 concluído. Resumo com ' . (int)$state['row_count'] . ' usuários; idade ' . (int)$state['age_seconds'] . " s.\n";
