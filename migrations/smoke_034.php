<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$required = [
    'partner_portal_configurations','subscriber_accounts','subscriber_external_links',
    'subscriber_benefit_profiles','subscriber_plan_mappings','subscriber_entitlements',
    'subscriber_devices','subscriber_device_identifiers','subscriber_device_invites',
    'subscriber_access_grants','subscriber_login_challenges','subscriber_trusted_devices','subscriber_audit',
];
foreach ($required as $table) {
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    if ((int)$st->fetchColumn() !== 1) throw new RuntimeException("Tabela ausente: {$table}");
}
$profileCount=(int)$pdo->query("SELECT COUNT(*) FROM subscriber_benefit_profiles WHERE code IN ('firenetwork_basic','firenetwork_family_3','firenetwork_family_5') AND active=1")->fetchColumn();
if ($profileCount !== 3) throw new RuntimeException('Perfis FIRENETWORK incompletos.');
$partnerCount=(int)$pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn();
$configCount=(int)$pdo->query('SELECT COUNT(*) FROM partner_portal_configurations')->fetchColumn();
if ($configCount < $partnerCount) throw new RuntimeException('Backfill das configurações do portal incompleto.');
$publishedDuplicates=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT partner_id FROM partner_portal_configurations WHERE state='published' GROUP BY partner_id HAVING COUNT(*)>1) x")->fetchColumn();
if ($publishedDuplicates !== 0) throw new RuntimeException('Há mais de uma configuração publicada por estabelecimento.');
$guestColumns=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guest_orders' AND COLUMN_NAME IN ('subscriber_account_id','subscriber_device_id')")->fetchColumn();
if ($guestColumns !== 2) throw new RuntimeException('Vínculo autenticado em guest_orders incompleto.');

echo "Smoke 034 OK: fundação do Portal V3 e benefícios FIRENETWORK consistente.\n";

