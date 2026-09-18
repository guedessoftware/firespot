<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

putenv('PERSONAL_DATA_KEY=' . base64_encode(str_repeat('M', 32)));
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/subscriber_admin.php';

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$profiles = $pdo->query("SELECT code,id FROM subscriber_benefit_profiles WHERE code IN ('firenetwork_basic','firenetwork_family_3') AND active=1")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
if (count($profiles) !== 2) throw new RuntimeException('Perfis necessários ao teste não estão disponíveis.');

$suffix = bin2hex(random_bytes(6));
$sourceExternalId = 'test-source-' . $suffix;
$targetExternalId = 'test-target-' . $suffix;
$publicId = bin2hex(random_bytes(16));
$documentHash = hash('sha256', 'subscriber-mapping-test:' . $suffix);

$pdo->beginTransaction();
try {
    $insertMapping = $pdo->prepare("INSERT INTO subscriber_plan_mappings (provider,external_kind,external_id,external_label,eligible_internet,benefit_profile_id,download_kbps,upload_kbps,active) VALUES ('hubsoft','service',?,?,1,?,?,?,?)");
    $insertMapping->execute([$sourceExternalId, 'Origem de teste', (int) $profiles['firenetwork_basic'], 7000, 2000, 0]);
    $sourceId = (int) $pdo->lastInsertId();
    $insertMapping->execute([$targetExternalId, 'Destino de teste', (int) $profiles['firenetwork_family_3'], 15000, 5000, 1]);
    $targetId = (int) $pdo->lastInsertId();

    fs_subscriber_admin_mapping_save($pdo, ['id'=>$targetId,'external_kind'=>'service','external_id'=>$targetExternalId,'external_label'=>'Destino atualizado','benefit_profile_id'=>(int)$profiles['firenetwork_family_3'],'download_kbps'=>18000,'upload_kbps'=>6000,'eligible_internet'=>1,'active'=>1]);
    $updatedMapping=$pdo->query('SELECT download_kbps,upload_kbps FROM subscriber_plan_mappings WHERE id='.$targetId)->fetch();
    $expect((int)$updatedMapping['download_kbps']===18000&&(int)$updatedMapping['upload_kbps']===6000,'A edição do mapeamento não persistiu os limites de velocidade.');

    $pdo->prepare("INSERT INTO subscriber_accounts (public_id,status,display_name,document_hash) VALUES (?,'active','Conta de teste',?)")->execute([$publicId, $documentHash]);
    $accountId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO subscriber_entitlements (account_id,benefit_profile_id,plan_mapping_id,provider,status,result_code,verified_at,valid_until,grace_until) VALUES (?,?,?,'hubsoft','active','TEST_MAPPING',NOW(),DATE_ADD(NOW(),INTERVAL 8 HOUR),DATE_ADD(NOW(),INTERVAL 1 DAY))")->execute([$accountId, (int) $profiles['firenetwork_basic'], $sourceId]);
    $entitlementId = (int) $pdo->lastInsertId();

    $mappings = fs_subscriber_admin_mappings($pdo);
    $sourceList = array_values(array_filter($mappings, static fn(array $mapping): bool => (int) $mapping['id'] === $sourceId));
    $expect(count($sourceList) === 1 && (int) $sourceList[0]['linked_entitlements'] === 1, 'A listagem não informa os clientes vinculados ao mapeamento.');
    $expect((int)$sourceList[0]['effective_download_kbps']===7000&&(int)$sourceList[0]['effective_upload_kbps']===2000,'A listagem não informa a velocidade específica do mapeamento.');

    try {
        fs_subscriber_admin_mapping_delete($pdo, $sourceId, 1);
        $deleteWithLinksBlocked = false;
    } catch (RuntimeException $error) {
        $deleteWithLinksBlocked = str_contains($error->getMessage(), 'Migre-o antes de excluir');
    }
    $expect($deleteWithLinksBlocked, 'Mapeamento inativo com cliente vinculado pôde ser excluído.');

    $migration = fs_subscriber_admin_mapping_migrate($pdo, $sourceId, $targetId, 1);
    $expect((int) $migration['linked_entitlements'] === 1, 'Migração não informou a quantidade correta de vínculos.');
    $expect((int) $pdo->query('SELECT COUNT(*) FROM subscriber_plan_mappings WHERE id=' . $sourceId)->fetchColumn() === 0, 'Mapeamento antigo permaneceu após a migração concluída.');
    $entitlement = $pdo->query('SELECT * FROM subscriber_entitlements WHERE id=' . $entitlementId)->fetch();
    $expect((int) $entitlement['plan_mapping_id'] === $targetId && (int) $entitlement['benefit_profile_id'] === (int) $profiles['firenetwork_family_3'], 'Vínculo ou perfil não foi transferido para o destino.');
    $expect($entitlement['result_code'] === 'MAPPING_MIGRATION_PENDING' && $entitlement['verified_at'] === null, 'Migração não marcou o benefício para revalidação no HubSoft.');
    $expect(strtotime((string) $entitlement['valid_until']) <= time() && strtotime((string) $entitlement['grace_until']) <= time(), 'Benefício antigo permaneceu utilizável sem revalidação.');
    $audit = $pdo->prepare("SELECT COUNT(*) FROM subscriber_audit WHERE account_id=? AND action='plan_mapping.migrated' AND target_type='entitlement'");
    $audit->execute([$accountId]);
    $expect((int) $audit->fetchColumn() === 1, 'Migração não foi registrada no histórico da conta.');

    try {
        fs_subscriber_admin_mapping_delete($pdo, $targetId, 1);
        $activeDeleteBlocked = false;
    } catch (RuntimeException $error) {
        $activeDeleteBlocked = str_contains($error->getMessage(), 'Desative');
    }
    $expect($activeDeleteBlocked, 'Mapeamento ativo pôde ser excluído.');

    fs_subscriber_admin_mapping_toggle($pdo, $targetId, false);
    try {
        fs_subscriber_admin_mapping_delete($pdo, $targetId, 1);
        $targetWithLinksBlocked = false;
    } catch (RuntimeException $error) {
        $targetWithLinksBlocked = str_contains($error->getMessage(), 'Migre-o antes de excluir');
    }
    $expect($targetWithLinksBlocked, 'Destino inativo ainda vinculado pôde ser excluído.');

    $pdo->prepare('UPDATE subscriber_entitlements SET plan_mapping_id=NULL WHERE id=?')->execute([$entitlementId]);
    fs_subscriber_admin_mapping_delete($pdo, $targetId, 1);
    $expect((int) $pdo->query('SELECT COUNT(*) FROM subscriber_plan_mappings WHERE id=' . $targetId)->fetchColumn() === 0, 'Mapeamento inativo sem vínculos não foi excluído.');

    $pdo->rollBack();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

echo "Mapeamentos HubSoft OK: {$checks} verificações.\n";
