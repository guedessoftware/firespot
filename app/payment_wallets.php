<?php

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/credential_crypto.php';
require_once __DIR__ . '/marketplace.php';

function fs_payment_schema_ready(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_wallets'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function fs_payment_wallet_validation_schema_ready(PDO $pdo): bool
{
    try {
        $statement=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_wallets' AND COLUMN_NAME IN ('access_token_validated_at','webhook_secret_validated_at')");
        return (int)$statement->fetchColumn()===2;
    } catch (Throwable $error) {
        return false;
    }
}

/**
 * Catálogo extensível dos gateways de recebimento. Somente integrações com
 * implementação completa podem ser selecionadas e persistidas.
 *
 * @return array<string,array{label:string,available:bool}>
 */
function fs_payment_gateway_catalog(): array
{
    return [
        'mercadopago'=>['label'=>'Mercado Pago','available'=>true],
    ];
}

function fs_payment_gateway_label(string $provider): string
{
    $catalog=fs_payment_gateway_catalog();
    return (string)($catalog[$provider]['label']??'Gateway não identificado');
}

function fs_global_wallet(): array
{
    $token = trim((string) env('MERCADOPAGO_ACCESS_TOKEN', ''));
    if ($token === '' && defined('MP_ACCESS_TOKEN')) $token = trim((string) MP_ACCESS_TOKEN);
    $publicKey = trim((string) env('MERCADOPAGO_PUBLIC_KEY', env('MP_PUBLIC_KEY', '')));
    if ($publicKey === '' && defined('MP_PUBLIC_KEY')) $publicKey = trim((string) MP_PUBLIC_KEY);
    return [
        'id' => null,
        'provider' => 'mercadopago',
        'name' => 'Carteira global FireSpot',
        'environment' => (string) env('MP_ENV', 'production'),
        'public_key' => $publicKey,
        'access_token' => $token,
        'webhook_secret' => trim((string) env('MERCADOPAGO_WEBHOOK_SECRET', '')),
        'webhook_secret_configured_at' => trim((string) env('MERCADOPAGO_WEBHOOK_SIGNATURE_REQUIRED_AFTER', '')),
        'active' => 1,
        'source' => 'global',
    ];
}

function fs_wallet_by_id(PDO $pdo, int $id, bool $withSecret = false): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM payment_wallets WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $wallet = $st->fetch(PDO::FETCH_ASSOC);
    if (!$wallet) return null;
    if ($withSecret) {
        $wallet['access_token'] = fs_decrypt_credential((string) $wallet['access_token_encrypted']);
        $wallet['webhook_secret'] = !empty($wallet['webhook_secret_encrypted'])
            ? fs_decrypt_credential((string) $wallet['webhook_secret_encrypted'])
            : '';
    }
    unset($wallet['access_token_encrypted'],$wallet['webhook_secret_encrypted']);
    $wallet['source'] = 'database';
    return $wallet;
}

function fs_wallet_for_partner(PDO $pdo, array $partner, bool $withSecret = false): array
{
    $marketplace = fs_marketplace_wallet_for_partner($pdo, $partner, $withSecret);
    if ($marketplace !== null) return $marketplace;
    $independent = (int) ($partner['independent_billing'] ?? 0) === 1;
    $walletId = (int) ($partner['payment_wallet_id'] ?? 0);
    if (!$independent) {
        return fs_global_wallet();
    }
    if ($walletId <= 0) {
        throw new RuntimeException('O estabelecimento independente não possui carteira vinculada.');
    }
    $wallet = fs_wallet_by_id($pdo, $walletId, $withSecret);
    if (!$wallet || (int) ($wallet['active'] ?? 0) !== 1) {
        throw new RuntimeException('A carteira do estabelecimento está inativa ou não foi encontrada.');
    }
    if (!array_key_exists('partner_id', $wallet) || (int)($wallet['partner_id'] ?? 0) !== (int)($partner['id'] ?? 0)) {
        throw new RuntimeException('A carteira selecionada não pertence a este estabelecimento.');
    }
    return $wallet;
}

function fs_wallets_for_partner(PDO $pdo, int $partnerId, bool $activeOnly = false): array
{
    if ($partnerId <= 0) return [];
    $validationColumns=fs_payment_wallet_validation_schema_ready($pdo)
        ? 'access_token_validated_at,webhook_secret_validated_at'
        : 'NULL access_token_validated_at,NULL webhook_secret_validated_at';
    $sql = 'SELECT id,partner_id,provider,name,environment,public_key,credential_hint,webhook_secret_hint,webhook_secret_configured_at,' . $validationColumns . ',active,created_at,updated_at
        FROM payment_wallets WHERE partner_id=?';
    if ($activeOnly) $sql .= ' AND active=1';
    $sql .= ' ORDER BY active DESC,updated_at DESC,id DESC';
    $st = $pdo->prepare($sql);
    $st->execute([$partnerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fs_wallet_assert_usable(array $wallet): void
{
    $provider=(string)($wallet['provider']??'');
    $gateway=fs_payment_gateway_catalog()[$provider]??null;
    if(!$gateway||empty($gateway['available'])){
        throw new RuntimeException('Provedor de pagamento ainda não suportado.');
    }
    if (trim((string) ($wallet['public_key'] ?? '')) === '') {
        throw new RuntimeException('A carteira não possui chave pública.');
    }
    if (trim((string) ($wallet['access_token'] ?? '')) === '') {
        throw new RuntimeException('A carteira não possui Access Token.');
    }
}
