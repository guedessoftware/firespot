<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../account_deletion.php';

$apply = in_array('--apply',$argv,true);
$pdo = db();
$rows = $pdo->query('SELECT id,username,deleted_at,reason,initiator,payload FROM deleted_accounts_log ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pending = [];
foreach ($rows as $row) {
    $payload = json_decode((string)$row['payload'],true);
    $isV2 = is_array($payload) && (int)($payload['version'] ?? 0) === 2;
    $anonymous = str_starts_with((string)$row['username'],'anon:');
    $actor = (string)($row['initiator'] ?? '');
    $safeActor = $actor === 'system' || str_starts_with($actor,'anon:');
    if (!$isV2 || !$anonymous || !$safeActor) $pending[] = [$row,is_array($payload) ? $payload : []];
}

echo 'Registros a sanear: ' . count($pending) . PHP_EOL;
if (!$apply || !$pending) exit(0);

// Falha antes da transação se nenhuma chave adequada estiver disponível.
account_deletion_audit_key();
$pdo->beginTransaction();
try {
    $update = $pdo->prepare('UPDATE deleted_accounts_log SET username=?,initiator=?,payload=? WHERE id=?');
    foreach ($pending as [$row,$payload]) {
        $subject = str_starts_with((string)$row['username'],'anon:')
            ? (string)$row['username']
            : account_deletion_reference((string)$row['username'],'subject');
        $rawActor = trim((string)($row['initiator'] ?? ''));
        $actor = $rawActor === '' || $rawActor === 'system'
            ? 'system'
            : (str_starts_with($rawActor,'anon:') ? $rawActor : account_deletion_reference($rawActor,'actor'));
        $receipt = account_deletion_public_receipt($payload,(string)$row['deleted_at']);
        $json = json_encode($receipt,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $update->execute([$subject,$actor,$json,(int)$row['id']]);
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

$unsafePayloads = (int)$pdo->query("SELECT COUNT(*) FROM deleted_accounts_log
    WHERE payload LIKE '%Cleartext-Password%'
       OR payload LIKE '%\"cpf\":%'
       OR payload LIKE '%\"telefone\":%'
       OR payload LIKE '%\"email\":%'
       OR payload LIKE '%\"radcheck\":[%'")->fetchColumn();
$plainSubjects = (int)$pdo->query("SELECT COUNT(*) FROM deleted_accounts_log WHERE username NOT LIKE 'anon:%'")->fetchColumn();
if ($unsafePayloads !== 0 || $plainSubjects !== 0) {
    throw new RuntimeException('A verificação posterior encontrou dados legados.');
}

echo 'Registros saneados: ' . count($pending) . PHP_EOL;
