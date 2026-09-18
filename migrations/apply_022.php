<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents(__DIR__ . '/022_partner_admin_portal.sql');
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração 022.');
$pdo->exec($sql);

$constraintExists = static function (string $table, string $constraint) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    $st->execute([$table, $constraint]);
    return (int)$st->fetchColumn() > 0;
};

// Resets antigos não tinham FK. A migração só cria a restrição depois de
// confirmar que não há órfãos, sem apagar qualquer registro.
$orphanResets = (int)$pdo->query('SELECT COUNT(*) FROM host_password_resets r LEFT JOIN host_users u ON u.id=r.user_id WHERE u.id IS NULL')->fetchColumn();
if ($orphanResets === 0 && !$constraintExists('host_password_resets', 'fk_host_password_resets_user')) {
    $pdo->exec('ALTER TABLE host_password_resets ADD CONSTRAINT fk_host_password_resets_user FOREIGN KEY (user_id) REFERENCES host_users(id) ON DELETE RESTRICT');
}

// Alguns ambientes antigos possuíam dois índices UNIQUE idênticos em
// token_hash. Mantemos uma única garantia sem tocar em tokens existentes.
$resetIndexes = $pdo->query('SHOW INDEX FROM host_password_resets')->fetchAll(PDO::FETCH_ASSOC);
$uniqueTokenIndexes = [];
foreach ($resetIndexes as $index) {
    if ((int)$index['Non_unique'] === 0 && (string)$index['Column_name'] === 'token_hash') {
        $uniqueTokenIndexes[(string)$index['Key_name']] = true;
    }
}
if (count($uniqueTokenIndexes) > 1 && isset($uniqueTokenIndexes['uniq_token'])) {
    $pdo->exec('ALTER TABLE host_password_resets DROP INDEX uniq_token');
}

foreach ([
    ['payment_wallets', 'fk_payment_wallets_partner', 'ALTER TABLE payment_wallets ADD CONSTRAINT fk_payment_wallets_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT'],
    ['custom_ads', 'fk_custom_ads_partner', 'ALTER TABLE custom_ads ADD CONSTRAINT fk_custom_ads_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT'],
    ['custom_ads_events', 'fk_custom_ads_events_partner', 'ALTER TABLE custom_ads_events ADD CONSTRAINT fk_custom_ads_events_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE RESTRICT'],
] as [$table, $name, $statement]) {
    if (!$constraintExists($table, $name)) $pdo->exec($statement);
}

$tables = ['partner_admin_memberships','partner_admin_invitations','partner_admin_audit','partner_admin_login_attempts'];
foreach ($tables as $table) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    if ((int)$st->fetchColumn() !== 1) throw new RuntimeException('Tabela ausente após migração 022: ' . $table);
}

$migrated = (int)$pdo->query('SELECT COUNT(*) FROM partner_admin_memberships')->fetchColumn();
echo "Migração 022 aplicada. Vínculos administrativos migrados: {$migrated}.\n";
