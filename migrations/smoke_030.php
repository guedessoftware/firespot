<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$checks = [
    ['TABLES','nas_ppp_active_sessions',null],
    ['COLUMNS','nas_interfaces','interface_type'],
    ['COLUMNS','nas_health','ppp_active_count'],
];
$missing = [];
foreach ($checks as [$kind,$table,$column]) {
    if ($kind === 'TABLES') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $st->execute([$table]);
        if ((int)$st->fetchColumn() !== 1) $missing[] = $table;
        continue;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table,$column]);
    if ((int)$st->fetchColumn() !== 1) $missing[] = $table . '.' . $column;
}
if ($missing) { fwrite(STDERR,'Smoke 030 falhou: ' . implode(', ',$missing) . " ausente(s).\n"); exit(1); }
echo "Smoke 030 OK: inventários estrutural e PPP disponíveis.\n";
