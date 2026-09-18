<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/034_portal_accounts_entitlements.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração 034.');
$pdo->exec($sql);

$constraintExists = static function (string $table, string $constraint) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    $st->execute([$table,$constraint]);
    return (int)$st->fetchColumn() > 0;
};

foreach ([
    ['partner_portal_configurations','fk_partner_portal_config_base','ALTER TABLE partner_portal_configurations ADD CONSTRAINT fk_partner_portal_config_base FOREIGN KEY (base_revision_id) REFERENCES partner_portal_configurations(id) ON DELETE RESTRICT'],
    ['guest_orders','fk_guest_orders_subscriber_account','ALTER TABLE guest_orders ADD CONSTRAINT fk_guest_orders_subscriber_account FOREIGN KEY (subscriber_account_id) REFERENCES subscriber_accounts(id) ON DELETE RESTRICT'],
    ['guest_orders','fk_guest_orders_subscriber_device','ALTER TABLE guest_orders ADD CONSTRAINT fk_guest_orders_subscriber_device FOREIGN KEY (subscriber_device_id) REFERENCES subscriber_devices(id) ON DELETE RESTRICT'],
] as [$table,$name,$statement]) {
    if (!$constraintExists($table,$name)) $pdo->exec($statement);
}

echo "Migração 034 aplicada. Configuração funcional, Minha Conta e benefícios por aparelho habilitados.\n";

