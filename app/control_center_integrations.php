<?php

declare(strict_types=1);

require_once __DIR__ . '/payment_wallets.php';

function fs_control_center_integration_code(string $value, string $fallback = 'NOT_RECORDED'): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9_:-]+/', '_', $value) ?: '';
    return substr($value !== '' ? $value : $fallback, 0, 80);
}

/** @return array{last_test_at:?string,last_test_ok:?bool,last_test_code:string} */
function fs_control_center_integration_test_status(PDO $pdo, string $provider): array
{
    $provider = strtolower(trim($provider));
    if (!preg_match('/^[a-z0-9_]{2,32}$/', $provider)) {
        return ['last_test_at'=>null,'last_test_ok'=>null,'last_test_code'=>'PROVIDER_INVALID'];
    }
    $prefix = 'integration_' . $provider . '_last_test_';
    $statement = $pdo->prepare('SELECT skey,svalue FROM app_settings WHERE skey IN (?,?,?)');
    $statement->execute([$prefix . 'at',$prefix . 'ok',$prefix . 'code']);
    $values = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $values[(string)$row['skey']] = (string)$row['svalue'];
    $ok = array_key_exists($prefix . 'ok', $values) ? $values[$prefix . 'ok'] === '1' : null;
    return [
        'last_test_at'=>trim((string)($values[$prefix . 'at'] ?? '')) ?: null,
        'last_test_ok'=>$ok,
        'last_test_code'=>fs_control_center_integration_code((string)($values[$prefix . 'code'] ?? '')),
    ];
}

function fs_control_center_record_integration_test(PDO $pdo, string $provider, bool $ok, string $code, ?int $actorId = null): void
{
    $provider = strtolower(trim($provider));
    if (!preg_match('/^[a-z0-9_]{2,32}$/', $provider)) throw new InvalidArgumentException('Provedor de integração inválido.');
    $code = fs_control_center_integration_code($code, $ok ? 'TEST_OK' : 'TEST_FAILED');
    $prefix = 'integration_' . $provider . '_last_test_';
    $upsert = $pdo->prepare('INSERT INTO app_settings (skey,svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
    $upsert->execute([$prefix . 'at', date('Y-m-d H:i:s')]);
    $upsert->execute([$prefix . 'ok', $ok ? '1' : '0']);
    $upsert->execute([$prefix . 'code', $code]);
    try {
        $audit = $pdo->prepare('INSERT INTO system_integration_audit (provider,actor_id,action,metadata) VALUES (?,?,?,?)');
        $audit->execute([$provider,$actorId,$ok ? 'connection.test_succeeded' : 'connection.test_failed',json_encode(['code'=>$code],JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $ignored) {
    }
}

/**
 * Recupera a carteira global canônica para diagnóstico. A carteira persistida
 * prevalece; o ambiente privado é apenas fallback legado.
 */
function fs_control_center_global_wallet(PDO $pdo, bool $withSecrets = false): array
{
    if (fs_payment_schema_ready($pdo)) {
        $statement = $pdo->query('SELECT id FROM payment_wallets WHERE partner_id IS NULL AND active=1 ORDER BY updated_at DESC,id DESC LIMIT 1');
        $id = (int)($statement->fetchColumn() ?: 0);
        if ($id > 0) {
            $wallet = fs_wallet_by_id($pdo, $id, $withSecrets);
            if ($wallet) {
                $wallet['source'] = 'database_global';
                return $wallet;
            }
        }
    }
    $wallet = fs_global_wallet();
    if (!$withSecrets) unset($wallet['access_token'],$wallet['webhook_secret']);
    $wallet['credential_hint'] = trim((string)($wallet['public_key'] ?? '')) !== '' ? 'ambiente privado' : '';
    $wallet['webhook_secret_hint'] = trim((string)env('MERCADOPAGO_WEBHOOK_SECRET', '')) !== '' ? 'ambiente privado' : '';
    $wallet['access_token_configured'] = trim((string)env('MERCADOPAGO_ACCESS_TOKEN', '')) !== '' || (defined('MP_ACCESS_TOKEN') && trim((string)MP_ACCESS_TOKEN) !== '');
    $wallet['webhook_secret_configured'] = trim((string)env('MERCADOPAGO_WEBHOOK_SECRET', '')) !== '';
    return $wallet;
}

/** @return array{configured:bool,token_ready:bool,webhook_ready:bool,last_test_at:?string,source:string} */
function fs_control_center_global_wallet_status(PDO $pdo): array
{
    $wallet = fs_control_center_global_wallet($pdo, false);
    $database = ($wallet['source'] ?? '') === 'database_global';
    $tokenReady = $database
        ? trim((string)($wallet['credential_hint'] ?? '')) !== ''
        : !empty($wallet['access_token_configured']);
    $webhookReady = $database
        ? trim((string)($wallet['webhook_secret_hint'] ?? '')) !== ''
        : !empty($wallet['webhook_secret_configured']);
    $tokenValidated = trim((string)($wallet['access_token_validated_at'] ?? '')) ?: null;
    $webhookValidated = trim((string)($wallet['webhook_secret_validated_at'] ?? '')) ?: null;
    $validated = $tokenValidated !== null && $webhookValidated !== null ? [$tokenValidated,$webhookValidated] : [];
    rsort($validated);
    return [
        'configured'=>$tokenReady && $webhookReady && trim((string)($wallet['public_key'] ?? '')) !== '',
        'token_ready'=>$tokenReady,
        'webhook_ready'=>$webhookReady,
        'last_test_at'=>$validated[0] ?? null,
        'source'=>$database ? 'Carteira global no banco' : 'Arquivo privado legado',
    ];
}
