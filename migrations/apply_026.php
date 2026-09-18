<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/026_firespot_monetization.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração 026.');
$pdo->exec($sql);

$constraintExists = static function (string $table, string $constraint) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    $st->execute([$table,$constraint]);
    return (int)$st->fetchColumn() > 0;
};

foreach ([
    ['custom_ads','fk_custom_ads_campaign','ALTER TABLE custom_ads ADD CONSTRAINT fk_custom_ads_campaign FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE RESTRICT'],
    ['guest_orders','fk_guest_orders_marketplace_account','ALTER TABLE guest_orders ADD CONSTRAINT fk_guest_orders_marketplace_account FOREIGN KEY (marketplace_account_id) REFERENCES marketplace_accounts(id) ON DELETE RESTRICT'],
    ['guest_orders','fk_guest_orders_monetization_agreement','ALTER TABLE guest_orders ADD CONSTRAINT fk_guest_orders_monetization_agreement FOREIGN KEY (monetization_agreement_id) REFERENCES partner_monetization_agreements(id) ON DELETE RESTRICT'],
    ['ad_pending_offers','fk_ad_pending_offer_delivery','ALTER TABLE ad_pending_offers ADD CONSTRAINT fk_ad_pending_offer_delivery FOREIGN KEY (delivery_id) REFERENCES ad_deliveries(id) ON DELETE RESTRICT'],
] as [$table,$name,$statement]) {
    if (!$constraintExists($table,$name)) $pdo->exec($statement);
}

echo "Migração 026 aplicada. Fundação de monetização habilitada.\n";
