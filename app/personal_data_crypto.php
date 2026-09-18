<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/credential_crypto.php';
require_once __DIR__ . '/application_secret.php';

function fs_personal_data_key(): string
{
    $raw = trim((string)env('PERSONAL_DATA_KEY', ''));
    if ($raw === '') {
        $paymentKey=trim((string)env('PAYMENT_CREDENTIAL_KEY',''));
        if($paymentKey!=='')return fs_credential_key();
        return fs_application_derived_key('personal-data-v1');
    }
    $decoded = base64_decode($raw, true);
    return is_string($decoded) && strlen($decoded) === 32 ? $decoded : hash('sha256', $raw, true);
}

function fs_personal_encrypt(string $plain): string
{
    if ($plain === '') throw new InvalidArgumentException('Dado pessoal vazio.');
    if (!function_exists('sodium_crypto_secretbox')) throw new RuntimeException('A extensão Sodium é necessária.');
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, fs_personal_data_key());
    return 'pi1:' . base64_encode($nonce . $cipher);
}

function fs_personal_decrypt(string $encoded): string
{
    if (strpos($encoded, 'pi1:') !== 0) throw new RuntimeException('Formato de dado pessoal desconhecido.');
    $blob = base64_decode(substr($encoded, 4), true);
    if (!is_string($blob) || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Dado pessoal corrompido.');
    $plain = sodium_crypto_secretbox_open(
        substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        fs_personal_data_key()
    );
    if (!is_string($plain)) throw new RuntimeException('Não foi possível descriptografar o dado pessoal.');
    return $plain;
}

function fs_personal_hash(string $normalized): string
{
    return hash_hmac('sha256', $normalized, fs_personal_data_key());
}

function fs_personal_mask_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) < 4) return '••••';
    return '•••• ••••-' . substr($digits, -4);
}
