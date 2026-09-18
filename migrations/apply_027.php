<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/027_partner_hotspots.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração 027.');
$pdo->exec($sql);

$constraintExists = static function (string $table, string $constraint) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    $st->execute([$table,$constraint]);
    return (int)$st->fetchColumn() > 0;
};

foreach ([
    ['guest_orders','fk_guest_orders_hotspot_partner','ALTER TABLE guest_orders ADD CONSTRAINT fk_guest_orders_hotspot_partner FOREIGN KEY (partner_id,hotspot_id) REFERENCES partner_hotspots(partner_id,id) ON DELETE RESTRICT'],
    ['courtesy_grants','fk_courtesy_grants_hotspot_partner','ALTER TABLE courtesy_grants ADD CONSTRAINT fk_courtesy_grants_hotspot_partner FOREIGN KEY (partner_id,hotspot_id) REFERENCES partner_hotspots(partner_id,id) ON DELETE RESTRICT'],
    ['custom_ads_events','fk_custom_ads_events_hotspot_partner','ALTER TABLE custom_ads_events ADD CONSTRAINT fk_custom_ads_events_hotspot_partner FOREIGN KEY (partner_id,hotspot_id) REFERENCES partner_hotspots(partner_id,id) ON DELETE RESTRICT'],
    ['ad_deliveries','fk_ad_deliveries_hotspot_partner','ALTER TABLE ad_deliveries ADD CONSTRAINT fk_ad_deliveries_hotspot_partner FOREIGN KEY (partner_id,hotspot_id) REFERENCES partner_hotspots(partner_id,id) ON DELETE RESTRICT'],
    ['login_tokens','fk_login_tokens_hotspot','ALTER TABLE login_tokens ADD CONSTRAINT fk_login_tokens_hotspot FOREIGN KEY (hotspot_id) REFERENCES partner_hotspots(id) ON DELETE RESTRICT'],
] as [$table,$name,$statement]) {
    if (!$constraintExists($table,$name)) $pdo->exec($statement);
}

echo "Migração 027 aplicada. Múltiplos hotspots por estabelecimento habilitados.\n";
