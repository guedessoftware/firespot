<?php

declare(strict_types=1);

function fs_hotspot_private_dns_name(string $value): bool
{
    $value = strtolower(rtrim(trim($value), '.'));
    return $value !== '' && str_ends_with($value, '.hotspot.internal');
}

function fs_hotspot_dns_name_valid(string $value): bool
{
    $value = strtolower(rtrim(trim($value), '.'));
    if ($value === '' || strlen($value) > 150) return false;
    return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+$/', $value) === 1;
}

/**
 * Converte os formatos que o RouterOS pode usar ao transportar os bytes do
 * HTTP-CHAP para o portal externo: hexadecimal, sequencias octais ou bytes
 * puros enviados pelo formulario do Hotspot.
 */
function fs_hotspot_chap_binary(string $value, int $expectedBytes): ?string
{
    if ($value === '' || $expectedBytes <= 0) return null;

    $hex = $value;
    if (str_starts_with(strtolower($hex), '0x')) $hex = substr($hex, 2);
    if (strlen($hex) === $expectedBytes * 2 && ctype_xdigit($hex)) {
        $decoded = hex2bin($hex);
        return $decoded === false ? null : $decoded;
    }

    if (preg_match('/^(?:\\\\[0-7]{3})+$/D', $value) === 1) {
        $decoded = '';
        for ($offset = 0, $length = strlen($value); $offset < $length; $offset += 4) {
            $byte = octdec(substr($value, $offset + 1, 3));
            if ($byte > 255) return null;
            $decoded .= chr($byte);
        }
        return strlen($decoded) === $expectedBytes ? $decoded : null;
    }

    return strlen($value) === $expectedBytes ? $value : null;
}

/**
 * Retorna exatamente o valor que deve ser enviado no campo `password` do
 * endpoint /login do RouterOS. Em HTTP-CHAP esse campo recebe o MD5 de
 * chap-id + senha + chap-challenge; em PAP recebe a senha original.
 *
 * Um contexto CHAP parcial ou malformado falha fechado para nunca enviar a
 * senha em texto puro a um endpoint que anunciou HTTP-CHAP.
 */
function fs_hotspot_login_password(string $password, string $chapId, string $chapChallenge): ?string
{
    if ($chapId === '' && $chapChallenge === '') return $password;
    if ($chapId === '' || $chapChallenge === '') return null;

    $id = fs_hotspot_chap_binary($chapId, 1);
    $challenge = fs_hotspot_chap_binary($chapChallenge, 16);
    if ($id === null || $challenge === null) return null;

    return md5($id . $password . $challenge);
}

/**
 * Valida o endpoint local de autenticação da instalação e converte a zona
 * privada automática para o gateway IPv4 correspondente.
 */
function fs_hotspot_login_url(array $hotspot, string $url): ?string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)) return null;
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return null;

    $gateway = strtolower(rtrim(trim((string)($hotspot['gateway_ip'] ?? '')), '.'));
    $configuredDns = strtolower(rtrim(trim((string)($hotspot['dns_name'] ?? '')), '.'));
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    $allowedHosts = array_values(array_unique(array_filter([$gateway, $configuredDns])));
    $path = rawurldecode((string)($parts['path'] ?? ''));
    if ($host === '' || !in_array($host, $allowedHosts, true) || $path !== '/login') return null;

    if ($host === $configuredDns && fs_hotspot_private_dns_name($configuredDns)) {
        if (!filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return null;
        $port = isset($parts['port']) && (int)$parts['port'] !== 80 && (int)$parts['port'] !== 443
            ? ':' . (int)$parts['port']
            : '';
        $query = isset($parts['query']) && (string)$parts['query'] !== '' ? '?' . (string)$parts['query'] : '';
        return 'http://' . $gateway . $port . '/login' . $query;
    }

    return $url;
}

/**
 * Constrói a reabertura do portal após o término de uma liberação temporária.
 * Domínios personalizados são preservados; a zona automática nunca é
 * devolvida ao navegador sem ser convertida para o gateway da instalação.
 */
function fs_hotspot_reauth_url(array $hotspot): ?string
{
    $dnsName = strtolower(rtrim(trim((string)($hotspot['dns_name'] ?? '')), '.'));
    $gateway = trim((string)($hotspot['gateway_ip'] ?? ''));
    if (fs_hotspot_dns_name_valid($dnsName)) {
        return fs_hotspot_login_url($hotspot, 'http://' . $dnsName . '/login');
    }
    if (filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return fs_hotspot_login_url($hotspot, 'http://' . $gateway . '/login');
    }
    return null;
}
