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

if ($scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partner_hotspots'") !== 1) {
    $failures[] = 'partner_hotspots ausente';
}

$partnerCount = $scalar('SELECT COUNT(*) FROM partners');
$defaultCount = $scalar('SELECT COUNT(*) FROM partner_hotspots WHERE is_default=1');
if ($partnerCount !== $defaultCount) $failures[] = "hotspots padrão={$defaultCount}; partners={$partnerCount}";

if ($scalar('SELECT COUNT(*) FROM (SELECT partner_id FROM partner_hotspots WHERE is_default=1 GROUP BY partner_id HAVING COUNT(*)<>1) x') !== 0) {
    $failures[] = 'estabelecimento sem exatamente um hotspot padrão';
}
if ($scalar('SELECT COUNT(*) FROM partner_hotspots h JOIN nas_interfaces i ON i.id=h.nas_interface_id WHERE h.nas_id IS NULL OR i.nas_id<>h.nas_id') !== 0) {
    $failures[] = 'interface vinculada a outro NAS';
}
if ($scalar('SELECT COUNT(*) FROM guest_orders WHERE hotspot_id IS NULL') !== 0) $failures[] = 'pedido sem hotspot';
if ($scalar('SELECT COUNT(*) FROM ad_deliveries WHERE hotspot_id IS NULL') !== 0) $failures[] = 'entrega de anúncio sem hotspot';
if ($scalar('SELECT COUNT(*) FROM guest_orders o JOIN partner_hotspots h ON h.id=o.hotspot_id WHERE h.partner_id<>o.partner_id') !== 0) $failures[] = 'pedido com hotspot de outro estabelecimento';
if ($scalar('SELECT COUNT(*) FROM courtesy_grants g JOIN partner_hotspots h ON h.id=g.hotspot_id WHERE h.partner_id<>g.partner_id') !== 0) $failures[] = 'cortesia com hotspot de outro estabelecimento';

if ($failures) {
    fwrite(STDERR,"Smoke 027 falhou:\n- " . implode("\n- ",$failures) . "\n");
    exit(1);
}

echo "Smoke 027 OK: {$partnerCount} estabelecimentos com hotspot padrão e vínculos íntegros.\n";
