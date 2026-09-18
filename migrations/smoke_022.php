<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$required = [
    'partners' => ['access_purpose','self_service_enabled','ads_enabled','allow_global_ads'],
    'host_users' => ['email','password_hash','partner_code','auth_version'],
    'payment_wallets' => ['partner_id'],
    'custom_ads' => ['partner_code','partner_id','start_date','end_date'],
    'custom_ads_events' => ['partner_id'],
    'host_password_resets' => ['used_at','revoked_at'],
    'ad_grants' => ['username','mac'],
    'login_tokens' => ['partner_code','partner_dns'],
];
foreach ($required as $table => $columns) {
    foreach ($columns as $column) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $st->execute([$table, $column]);
        if ((int)$st->fetchColumn() !== 1) throw new RuntimeException("Coluna ausente: {$table}.{$column}");
    }
}

$orphans = (int)$pdo->query('SELECT COUNT(*) FROM partner_admin_memberships m LEFT JOIN host_users u ON u.id=m.user_id LEFT JOIN partners p ON p.id=m.partner_id WHERE u.id IS NULL OR p.id IS NULL')->fetchColumn();
if ($orphans !== 0) throw new RuntimeException('Há vínculos administrativos órfãos.');

$crossAds = (int)$pdo->query("SELECT COUNT(*) FROM custom_ads a JOIN partners p ON p.code=a.partner_code WHERE a.partner_id IS NOT NULL AND a.partner_id<>p.id")->fetchColumn();
if ($crossAds !== 0) throw new RuntimeException('Há anúncios com partner_code e partner_id divergentes.');

$crossEvents = (int)$pdo->query('SELECT COUNT(*) FROM custom_ads_events e JOIN custom_ads a ON a.id=e.ad_id WHERE e.partner_id IS NOT NULL AND a.partner_id IS NOT NULL AND e.partner_id<>a.partner_id')->fetchColumn();
if ($crossEvents !== 0) throw new RuntimeException('Há métricas atribuídas a outra unidade.');

$walletMismatch = (int)$pdo->query('SELECT COUNT(*) FROM partners p JOIN payment_wallets w ON w.id=p.payment_wallet_id WHERE p.independent_billing=1 AND (w.partner_id IS NULL OR w.partner_id<>p.id)')->fetchColumn();
if ($walletMismatch !== 0) throw new RuntimeException('Há carteira independente sem propriedade correta.');

$missingMembership = (int)$pdo->query("SELECT COUNT(*) FROM host_users u JOIN partners p ON p.code=u.partner_code LEFT JOIN partner_admin_memberships m ON m.user_id=u.id AND m.partner_id=p.id WHERE u.partner_code IS NOT NULL AND u.partner_code<>'' AND m.id IS NULL")->fetchColumn();
if ($missingMembership !== 0) throw new RuntimeException('Há identidade legada sem vínculo migrado.');

$invalidGate = (int)$pdo->query('SELECT COUNT(*) FROM partners WHERE self_service_enabled=1 AND access_purpose IS NULL')->fetchColumn();
if ($invalidGate !== 0) throw new RuntimeException('Há painel ativado sem finalidade definida.');

$st = $pdo->query("SHOW INDEX FROM host_password_resets WHERE Non_unique=0 AND Column_name='token_hash'");
$tokenIndexes = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $index) $tokenIndexes[(string)$index['Key_name']] = true;
if (count($tokenIndexes) !== 1) throw new RuntimeException('A unicidade de token_hash no reset precisa de exatamente um índice.');

echo "Smoke 022 concluído. Estrutura e migração administrativa íntegras.\n";
