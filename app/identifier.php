<?php
// Common identifier utilities for CPF and Brazilian mobile numbers

declare(strict_types=1);

/** Return only digits from the input string. */
function fs_digits_only(string $value): string
{
    return preg_replace('/\D+/', '', $value);
}

/** Normalize CPF to 11 digits (without formatting). */
function fs_normalize_cpf(string $value): string
{
    $digits = fs_digits_only($value);
    return strlen($digits) > 11 ? substr($digits, 0, 11) : $digits;
}

/** Validate CPF digits using standard checksum. */
function fs_is_valid_cpf(string $digits): bool
{
    $digits = fs_digits_only($digits);
    if (strlen($digits) !== 11) {
        return false;
    }
    if (preg_match('/^(\d)\1{10}$/', $digits)) {
        return false;
    }

    $calc = function (int $length) use ($digits): int {
        $sum = 0;
        $weight = $length + 1;
        for ($i = 0; $i < $length; $i++) {
            $sum += (int) $digits[$i] * ($weight - $i);
        }
        $mod = ($sum * 10) % 11;
        return $mod === 10 ? 0 : $mod;
    };

    return $calc(9) === (int) $digits[9] && $calc(10) === (int) $digits[10];
}

/** Normalize Brazilian phone to 11 digits without DDI. */
function fs_normalize_phone(string $value): string
{
    $digits = fs_digits_only($value);
    if ($digits === '') {
        return '';
    }

    $digits = ltrim($digits, '0');
    if (strlen($digits) > 11 && substr($digits, 0, 2) === '55') {
        $digits = substr($digits, 2);
    }

    if (strlen($digits) > 11) {
        $digits = substr($digits, -11);
    }

    return $digits;
}

/** List of valid Brazilian DDD codes. */
function fs_valid_brazil_ddd(): array
{
    return [
        '11','12','13','14','15','16','17','18','19',
        '21','22','24','27','28',
        '31','32','33','34','35','37','38',
        '41','42','43','44','45','46','47','48','49',
        '51','53','54','55',
        '61','62','63','64','65','66','67','68','69',
        '71','73','74','75','77','79',
        '81','82','83','84','85','86','87','88','89',
        '91','92','93','94','95','96','97','98','99'
    ];
}

/** Validate normalized 11-digit phone. */
function fs_validate_phone_digits(string $digits): array
{
    $digits = fs_digits_only($digits);
    if (strlen($digits) < 11) {
        return ['valid' => false, 'reason' => 'too_short'];
    }
    if (strlen($digits) > 11) {
        return ['valid' => false, 'reason' => 'too_long'];
    }
    if (preg_match('/^(\d)\1+$/', $digits)) {
        return ['valid' => false, 'reason' => 'repeated_digits'];
    }
    $ddd = substr($digits, 0, 2);
    if (!in_array($ddd, fs_valid_brazil_ddd(), true)) {
        return ['valid' => false, 'reason' => 'invalid_ddd'];
    }
    if ($digits[2] !== '9') {
        return ['valid' => false, 'reason' => 'invalid_mobile'];
    }
    return ['valid' => true];
}

/** Format CPF as 000.000.000-00 */
function fs_format_cpf(string $digits): string
{
    $digits = fs_digits_only($digits);
    if (strlen($digits) !== 11) {
        return $digits;
    }
    return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9);
}

/** Format phone as (DD) 9XXXX-XXXX */
function fs_format_phone(string $digits): string
{
    $digits = fs_digits_only($digits);
    if (strlen($digits) !== 11) {
        return $digits;
    }
    return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 5) . '-' . substr($digits, 7);
}

/** Translate validation reason to user-friendly Portuguese message. */
function fs_identifier_reason_message(string $reason): string
{
    switch ($reason) {
        case 'empty':
            return 'Informe seu telefone com DDD ou um CPF válido.';
        case 'too_short':
            return 'Informe 11 dígitos (DDD + 9 + número) ou um CPF com 11 dígitos.';
        case 'too_long':
            return 'Digite apenas os números do telefone com DDD (11 dígitos) ou um CPF com 11 dígitos.';
        case 'invalid_cpf':
            return 'CPF inválido. Verifique os números e tente novamente.';
        case 'invalid_phone':
            return 'Telefone inválido. Use DDD + 9 + número, apenas dígitos.';
        case 'invalid_ddd':
            return 'DDD inválido. Verifique o código da sua região (ex.: 11, 21, 92).';
        case 'invalid_mobile':
            return 'Número móvel inválido. Após o DDD, celulares devem começar com 9.';
        case 'repeated_digits':
            return 'Número inválido. Evite sequências com todos os dígitos iguais.';
        default:
            return 'Não reconhecemos esse número. Tente novamente.';
    }
}

/** Classify identifier as CPF or phone. */
function fs_identifier_classify(string $value): array
{
    $raw = trim($value);
    if ($raw === '') {
        return [
            'valid' => false,
            'reason' => 'empty',
            'sanitized' => '',
            'formatted' => '',
            'type' => null,
            'raw' => $raw,
        ];
    }

    $digits = fs_digits_only($raw);
    if (strlen($digits) === 11 && fs_is_valid_cpf($digits)) {
        $cpf = substr($digits, 0, 11);
        return [
            'valid' => true,
            'type' => 'cpf',
            'sanitized' => $cpf,
            'formatted' => fs_format_cpf($cpf),
            'raw' => $raw,
        ];
    }

    $phone = fs_normalize_phone($digits);
    $check = fs_validate_phone_digits($phone);
    if ($check['valid'] ?? false) {
        return [
            'valid' => true,
            'type' => 'phone',
            'sanitized' => $phone,
            'formatted' => fs_format_phone($phone),
            'raw' => $raw,
        ];
    }

    $reason = $check['reason'] ?? (strlen($digits) === 11 ? 'invalid_cpf' : 'invalid_phone');
    return [
        'valid' => false,
        'reason' => $reason,
        'sanitized' => $phone,
        'formatted' => $raw,
        'type' => null,
        'raw' => $raw,
    ];
}
