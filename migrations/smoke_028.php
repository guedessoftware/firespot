<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$failures = [];
$scalar = static function (string $sql) use ($pdo): int {
    return (int)$pdo->query($sql)->fetchColumn();
};

if ($scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nas_base_provisioning'") !== 1) {
    $failures[] = 'nas_base_provisioning ausente';
}
if (!$failures && $scalar('SELECT COUNT(*) FROM nas n LEFT JOIN nas_base_provisioning b ON b.nas_id=n.id WHERE b.nas_id IS NULL') !== 0) {
    $failures[] = 'NAS sem registro de preparação-base';
}
if (!$failures && $scalar('SELECT COUNT(*) FROM nas_base_provisioning b LEFT JOIN nas n ON n.id=b.nas_id WHERE n.id IS NULL') !== 0) {
    $failures[] = 'preparação-base órfã';
}
if (!$failures && $scalar("SELECT COUNT(*) FROM nas_base_provisioning WHERE status='ready' AND (config_revision<1 OR radius_server_id IS NULL OR provisioned_at IS NULL)") !== 0) {
    $failures[] = 'preparação pronta sem revisão, RADIUS ou data';
}

if ($failures) {
    fwrite(STDERR,"Smoke 028 falhou:\n- " . implode("\n- ",$failures) . "\n");
    exit(1);
}

$nasCount = $scalar('SELECT COUNT(*) FROM nas_base_provisioning');
echo "Smoke 028 OK: {$nasCount} NAS com estado de preparação-base.\n";
