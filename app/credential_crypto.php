<?php

require_once __DIR__ . '/env.php';

function fs_named_credential_key(string $environmentName, string $missingMessage): string
{
    $raw = trim((string) env($environmentName, ''));
    if ($raw === '') throw new RuntimeException($missingMessage);
    $decoded = base64_decode($raw, true);
    $key = is_string($decoded) && strlen($decoded) === 32 ? $decoded : hash('sha256', $raw, true);
    if (strlen($key) !== 32) throw new RuntimeException($environmentName . ' inválida.');
    return $key;
}

function fs_encrypt_named_credential(string $plain, string $environmentName, string $prefix, string $missingMessage): string
{
    if ($plain === '') throw new InvalidArgumentException('Credencial vazia.');
    if (!function_exists('sodium_crypto_secretbox')) throw new RuntimeException('A extensão Sodium é necessária para proteger credenciais.');
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain,$nonce,fs_named_credential_key($environmentName,$missingMessage));
    return $prefix . base64_encode($nonce . $cipher);
}

function fs_decrypt_named_credential(string $encoded, string $environmentName, string $prefix, string $missingMessage): string
{
    if (strpos($encoded,$prefix)!==0) throw new RuntimeException('Formato de credencial desconhecido.');
    $blob=base64_decode(substr($encoded,strlen($prefix)),true);
    if (!is_string($blob)||strlen($blob)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Credencial corrompida.');
    $nonce=substr($blob,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher=substr($blob,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain=sodium_crypto_secretbox_open($cipher,$nonce,fs_named_credential_key($environmentName,$missingMessage));
    if (!is_string($plain)) throw new RuntimeException('Não foi possível descriptografar a credencial.');
    return $plain;
}

function fs_credential_key(): string
{
    return fs_named_credential_key('PAYMENT_CREDENTIAL_KEY','Configure PAYMENT_CREDENTIAL_KEY antes de salvar carteiras.');
}

function fs_encrypt_credential(string $plain): string
{
    if ($plain === '') {
        throw new InvalidArgumentException('Credencial vazia.');
    }
    if (!function_exists('sodium_crypto_secretbox')) {
        throw new RuntimeException('A extensão Sodium é necessária para proteger credenciais.');
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, fs_credential_key());
    return 'sb1:' . base64_encode($nonce . $cipher);
}

function fs_decrypt_credential(string $encoded): string
{
    if (strpos($encoded, 'sb1:') !== 0) {
        throw new RuntimeException('Formato de credencial desconhecido.');
    }
    $blob = base64_decode(substr($encoded, 4), true);
    if (!is_string($blob) || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('Credencial corrompida.');
    }
    $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, fs_credential_key());
    if (!is_string($plain)) {
        throw new RuntimeException('Não foi possível descriptografar a credencial.');
    }
    return $plain;
}

function fs_credential_hint(string $credential): string
{
    $credential = trim($credential);
    if ($credential === '') return '';
    return '••••' . substr($credential, -6);
}
