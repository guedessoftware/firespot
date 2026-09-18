<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['env:', 'apply']);
$envPath = (string) ($options['env'] ?? '/etc/firespot/firespot.env');
$apply = array_key_exists('apply', $options);
if ($apply && (!function_exists('posix_geteuid') || posix_geteuid() !== 0)) {
    throw new RuntimeException('Execute a rotação efetiva da PERSONAL_DATA_KEY como root.');
}
if (is_link($envPath) || !is_file($envPath) || !is_readable($envPath)) {
    throw new RuntimeException('Arquivo privado de ambiente inválido ou inacessível.');
}

$originalEnv = file_get_contents($envPath);
if (!is_string($originalEnv)) {
    throw new RuntimeException('Não foi possível ler o arquivo privado de ambiente.');
}
if (!preg_match('/^PERSONAL_DATA_KEY\s*=\s*(\S+)\s*$/m', $originalEnv, $match)) {
    throw new RuntimeException('PERSONAL_DATA_KEY ausente no arquivo privado.');
}
$rotationPending = preg_match('/^PERSONAL_DATA_KEY_ROTATION_PENDING\s*=\s*(?:1|true|yes|on)\s*$/mi', $originalEnv) === 1;
if (!$rotationPending) {
    echo "PERSONAL_DATA_KEY já está separada; nenhuma alteração necessária.\n";
    exit(0);
}

$decodeKey = static function (string $raw): string {
    $raw = trim($raw);
    $decoded = base64_decode($raw, true);
    $key = is_string($decoded) && strlen($decoded) === 32 ? $decoded : hash('sha256', $raw, true);
    if (strlen($key) !== 32) {
        throw new RuntimeException('PERSONAL_DATA_KEY inválida.');
    }
    return $key;
};
$oldKey = $decodeKey((string) $match[1]);
$newKey = random_bytes(32);

$decrypt = static function (string $encoded, string $key): string {
    if (!str_starts_with($encoded, 'pi1:')) {
        throw new RuntimeException('Formato de dado pessoal desconhecido.');
    }
    $blob = base64_decode(substr($encoded, 4), true);
    if (!is_string($blob) || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('Dado pessoal corrompido.');
    }
    $plain = sodium_crypto_secretbox_open(
        substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        $key
    );
    if (!is_string($plain)) {
        throw new RuntimeException('Dado pessoal incompatível com a chave atual.');
    }
    return $plain;
};
$encrypt = static function (string $plain, string $key): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'pi1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
};
$personalHash = static fn(string $normalized, string $key): string => hash_hmac('sha256', $normalized, $key);
$normalizeMac = static function (string $value): string {
    $value = strtoupper(trim(str_replace('-', ':', $value)));
    if (preg_match('/^[0-9A-F]{12}$/', $value)) {
        $value = implode(':', str_split($value, 2));
    }
    if (preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $value)) {
        return $value;
    }
    if (str_starts_with($value, 'DID:')) {
        return substr((string) preg_replace('/[^A-Z0-9:]/', '', $value), 0, 64);
    }
    return '';
};

putenv('FIRESPOT_ENV_PATH=' . $envPath);
require_once dirname(__DIR__) . '/db.php';
$pdo = db();

$activeChallenges = (int) $pdo->query("SELECT COUNT(*) FROM subscriber_login_challenges WHERE status='pending' AND expires_at>NOW()")->fetchColumn();
$activeInvites = (int) $pdo->query("SELECT COUNT(*) FROM subscriber_device_invites WHERE status='created' AND expires_at>NOW()")->fetchColumn();
$activeQueue = (int) $pdo->query("SELECT COUNT(*) FROM promo_queue WHERE status IN ('pending','sending')")->fetchColumn();
if ($activeChallenges || $activeInvites || $activeQueue) {
    throw new RuntimeException("Adie a rotação: desafios={$activeChallenges}, convites={$activeInvites}, mensagens={$activeQueue} ainda estão ativos.");
}

$encryptedTargets = [
    'subscriber_external_links' => ['document_encrypted', 'verified_phone_encrypted', 'verified_email_encrypted'],
    'promo_queue' => ['payload_encrypted'],
    'ad_leads' => ['name_encrypted', 'phone_encrypted'],
];
$plainByField = [];
$encryptedCount = 0;
foreach ($encryptedTargets as $table => $columns) {
    foreach ($columns as $column) {
        $statement = $pdo->query("SELECT id,`{$column}` encrypted_value FROM `{$table}` WHERE `{$column}` LIKE 'pi1:%'");
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $plainByField[$table][$column][(int) $row['id']] = $decrypt((string) $row['encrypted_value'], $oldKey);
            $encryptedCount++;
        }
    }
}

$accountDocuments = [];
$links = $pdo->query("SELECT id,account_id,document_encrypted FROM subscriber_external_links WHERE document_encrypted LIKE 'pi1:%'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($links as $link) {
    $accountId = (int) $link['account_id'];
    $document = preg_replace('/\D+/', '', $decrypt((string) $link['document_encrypted'], $oldKey));
    if (!is_string($document) || $document === '') {
        throw new RuntimeException('Documento protegido inválido no vínculo ' . (int) $link['id'] . '.');
    }
    if (isset($accountDocuments[$accountId]) && !hash_equals($accountDocuments[$accountId], $document)) {
        throw new RuntimeException('Vínculos divergentes para a conta ' . $accountId . '.');
    }
    $accountDocuments[$accountId] = $document;
}
$unrecoverableAccounts = 0;
foreach ($pdo->query("SELECT id FROM subscriber_accounts WHERE status<>'anonymized'")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $accountId) {
    if (!isset($accountDocuments[(int) $accountId])) {
        $unrecoverableAccounts++;
    }
}
if ($unrecoverableAccounts > 0) {
    throw new RuntimeException("Existem {$unrecoverableAccounts} conta(s) sem documento recuperável; nenhuma alteração foi feita.");
}

$resolvedMacs = [];
$macRows = $pdo->query("SELECT i.id,i.identifier_hash,g.device_mac FROM subscriber_device_identifiers i JOIN subscriber_access_grants g ON g.device_id=i.device_id WHERE i.identifier_type='mac' AND i.active=1 AND g.device_mac IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($macRows as $row) {
    $mac = $normalizeMac((string) $row['device_mac']);
    if ($mac !== '' && hash_equals((string) $row['identifier_hash'], $personalHash('device-mac:' . $mac, $oldKey))) {
        $resolvedMacs[(int) $row['id']] = $mac;
    }
}
$activeMacCount = (int) $pdo->query("SELECT COUNT(*) FROM subscriber_device_identifiers WHERE identifier_type='mac' AND active=1")->fetchColumn();
if (count($resolvedMacs) !== $activeMacCount) {
    throw new RuntimeException('Há identificadores MAC ativos que não podem ser migrados sem interromper a identificação do aparelho.');
}

$summary = [
    'encrypted_values' => $encryptedCount,
    'accounts' => count($accountDocuments),
    'active_macs' => count($resolvedMacs),
    'trusted_sessions_revoked' => (int) $pdo->query('SELECT COUNT(*) FROM subscriber_trusted_devices WHERE revoked_at IS NULL')->fetchColumn(),
];
if (!$apply) {
    echo 'DRY-RUN OK: ' . json_encode($summary, JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$owner = fileowner($envPath);
$group = filegroup($envPath);
$mode = fileperms($envPath) & 0777;
$newEnv = preg_replace('/^PERSONAL_DATA_KEY\s*=.*$/m', 'PERSONAL_DATA_KEY=' . base64_encode($newKey), $originalEnv, 1);
$newEnv = preg_replace('/^PERSONAL_DATA_KEY_ROTATION_PENDING\s*=.*(?:\R|$)/mi', '', (string) $newEnv);
$newEnv = preg_replace('/^PERSONAL_DATA_KEY_ROTATED_AT\s*=.*(?:\R|$)/mi', '', (string) $newEnv);
$newEnv = rtrim((string) $newEnv) . "\nPERSONAL_DATA_KEY_ROTATED_AT=" . gmdate('c') . "\n";
$temporaryEnv = tempnam(dirname($envPath), '.firespot-personal-key-');
if ($temporaryEnv === false) {
    throw new RuntimeException('Não foi possível preparar o novo arquivo privado.');
}
$envReplaced = false;

$pdo->beginTransaction();
try {
    $updateEncrypted = [];
    foreach ($plainByField as $table => $columns) {
        foreach ($columns as $column => $rows) {
            $updateEncrypted[$table][$column] = $pdo->prepare("UPDATE `{$table}` SET `{$column}`=? WHERE id=?");
            foreach ($rows as $id => $plain) {
                $updateEncrypted[$table][$column]->execute([$encrypt($plain, $newKey), $id]);
            }
        }
    }

    $updateAccount = $pdo->prepare('UPDATE subscriber_accounts SET document_hash=?,updated_at=NOW() WHERE id=?');
    $updateLinks = $pdo->prepare('UPDATE subscriber_external_links SET document_hash=?,updated_at=NOW() WHERE account_id=?');
    $updateAttempts = $pdo->prepare('UPDATE subscriber_auth_attempts SET document_hash=? WHERE account_id=?');
    foreach ($accountDocuments as $accountId => $document) {
        $hash = $personalHash('document:' . $document, $newKey);
        $updateAccount->execute([$hash, $accountId]);
        $updateLinks->execute([$hash, $accountId]);
        $updateAttempts->execute([$hash, $accountId]);
    }

    $pdo->exec("UPDATE subscriber_login_challenges SET status=IF(status='pending','expired',status),target_encrypted='PURGED',document_hash=SHA2(CONCAT(document_hash,':personal-key-rotation:',id),256),code_hash=SHA2(CONCAT(code_hash,':personal-key-rotation:',id),256),origin_hash=NULL,updated_at=NOW()");
    $pdo->exec("UPDATE promo_queue SET status=IF(status IN ('pending','sending'),'cancelled',status),payload_encrypted=NULL,to_msisdn=IF(purpose IN ('subscriber_otp','ad_offer'),'PURGED',to_msisdn),msg=IF(purpose IN ('subscriber_otp','ad_offer'),'PURGED',msg),updated_at=NOW() WHERE purpose='subscriber_otp'");
    $pdo->exec("UPDATE subscriber_trusted_devices SET token_hash=SHA2(CONCAT(token_hash,':personal-key-rotation:',id),256),user_agent_hash=NULL,revoked_at=COALESCE(revoked_at,NOW()),updated_at=NOW()");
    $pdo->exec("UPDATE subscriber_devices SET device_token_hash=SHA2(CONCAT(device_token_hash,':personal-key-rotation:',id),256),updated_at=NOW()");
    $pdo->exec("UPDATE subscriber_device_invites SET token_hash=SHA2(CONCAT(token_hash,':personal-key-rotation:',id),256),human_code_hash=SHA2(CONCAT(human_code_hash,':personal-key-rotation:',id),256),status=IF(status='created','expired',status),updated_at=NOW()");
    $pdo->exec("UPDATE subscriber_access_grants SET idempotency_key_hash=SHA2(CONCAT(idempotency_key_hash,':personal-key-rotation:',id),256),updated_at=NOW()");
    $pdo->exec("UPDATE subscriber_device_identifiers SET identifier_hash=SHA2(CONCAT(identifier_hash,':personal-key-rotation:',id),256),active=0,updated_at=NOW() WHERE identifier_type<>'mac'");
    $updateMac = $pdo->prepare('UPDATE subscriber_device_identifiers SET identifier_hash=?,updated_at=NOW() WHERE id=?');
    foreach ($resolvedMacs as $identifierId => $mac) {
        $updateMac->execute([$personalHash('device-mac:' . $mac, $newKey), $identifierId]);
    }
    $pdo->exec("UPDATE subscriber_auth_attempts SET document_hash=SHA2(CONCAT(document_hash,':personal-key-rotation:',id),256) WHERE account_id IS NULL");
    $pdo->exec("UPDATE subscriber_auth_attempts SET origin_hash=IF(origin_hash IS NULL,NULL,SHA2(CONCAT(origin_hash,':personal-key-rotation'),256))");
    $pdo->exec("UPDATE subscriber_audit SET origin_hash=IF(origin_hash IS NULL,NULL,SHA2(CONCAT(origin_hash,':personal-key-rotation'),256))");

    if (isset($plainByField['ad_leads']['phone_encrypted'])) {
        $updatePhoneHash = $pdo->prepare('UPDATE ad_leads SET phone_hash=? WHERE id=?');
        foreach ($plainByField['ad_leads']['phone_encrypted'] as $leadId => $phone) {
            $normalized = '+' . preg_replace('/\D+/', '', $phone);
            $updatePhoneHash->execute([$personalHash($normalized, $newKey), $leadId]);
        }
    }

    if (file_put_contents($temporaryEnv, $newEnv, LOCK_EX) !== strlen($newEnv)) {
        throw new RuntimeException('Não foi possível gravar o novo arquivo privado.');
    }
    if ($owner !== false) chown($temporaryEnv, $owner);
    if ($group !== false) chgrp($temporaryEnv, $group);
    chmod($temporaryEnv, $mode);
    if (!rename($temporaryEnv, $envPath)) {
        throw new RuntimeException('Não foi possível ativar a nova chave de dados pessoais.');
    }
    $envReplaced = true;
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($envReplaced) {
        $restore = tempnam(dirname($envPath), '.firespot-personal-key-restore-');
        if ($restore !== false) {
            file_put_contents($restore, $originalEnv, LOCK_EX);
            if ($owner !== false) chown($restore, $owner);
            if ($group !== false) chgrp($restore, $group);
            chmod($restore, $mode);
            rename($restore, $envPath);
        }
    }
    throw $error;
} finally {
    if (is_file($temporaryEnv)) unlink($temporaryEnv);
}

echo 'PERSONAL_DATA_KEY rotacionada: ' . json_encode($summary, JSON_UNESCAPED_SLASHES) . "\n";
