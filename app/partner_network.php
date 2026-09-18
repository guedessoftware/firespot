<?php

declare(strict_types=1);

/**
 * Janela curta em que o próprio Hotspot pode reconhecer o aparelho e
 * reconectá-lo sem reabrir a jornada do portal. O RADIUS continua validando
 * a credencial e os limites de sessão; isto não cria acesso por MAC puro.
 */
const FS_HOTSPOT_SHORT_RECONNECT_TIMEOUT = '20m';

/**
 * Normaliza o nome privado usado pelo portal cativo.
 *
 * `.local` pertence ao fluxo de mDNS em celulares e não deve ser usado como
 * domínio unicast do Hotspot. Instalações antigas são promovidas para a zona
 * privada `hotspot.internal`, preservando o primeiro rótulo configurado.
 */
function partner_network_dns_name(?string $value, string $fallback): string
{
    $fallback = strtolower(trim($fallback));
    $fallback = preg_replace('/[^a-z0-9-]+/', '-', $fallback) ?: '';
    $fallback = trim($fallback, '-');
    if ($fallback === '') $fallback = 'firespot';

    $value = strtolower(trim((string)$value));
    if ($value === '') $value = $fallback . '.hotspot.internal';
    $value = preg_replace('/[^a-z0-9.-]+/', '-', $value) ?: '';
    $value = trim($value, '-.');

    if ($value === '' || $value === 'local') {
        $value = $fallback . '.hotspot.internal';
    } elseif (str_ends_with($value, '.local')) {
        $prefix = trim(substr($value, 0, -6), '-.');
        $value = ($prefix !== '' ? $prefix : $fallback) . '.hotspot.internal';
    }

    if (!preg_match('/^(?=.{1,150}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $value)) {
        throw new InvalidArgumentException('Nome DNS inválido. Use rótulos de até 63 caracteres separados por ponto.');
    }
    return $value;
}

/**
 * Nomes automáticos são mantidos no banco apenas como identificadores da
 * instalação. Eles não são publicados como `dns-name` no RouterOS, pois o
 * navegador cativo pode usar DNS particular e falhar antes de chegar ao
 * FireSpot. Domínios públicos personalizados continuam sendo preservados.
 */
function partner_network_routeros_dns_name(string $value): string
{
    $value = strtolower(rtrim(trim($value), '.'));
    if ($value !== '' && str_ends_with($value, '.hotspot.internal')) return '';
    return $value;
}

/**
 * Determina a máscara a partir do intervalo salvo sem alterar redes antigas.
 * Pools que atravessam o terceiro octeto usam /16; os demais permanecem /24.
 */
function partner_network_profile(string $gateway, string $poolStart, string $poolEnd): array
{
    $parse = static function (string $ip): ?array {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return null;
        $parts = array_map('intval', explode('.', $ip));
        return count($parts) === 4 ? $parts : null;
    };

    $gatewayParts = $parse(trim($gateway));
    $startParts = $parse(trim($poolStart));
    $endParts = $parse(trim($poolEnd));
    $candidate = $startParts ?? $gatewayParts;
    if ($candidate === null) {
        return ['prefix' => 24, 'cidr' => '<NETWORK_CIDR>'];
    }

    $prefix = 24;
    if ($gatewayParts && $startParts && $endParts
        && $gatewayParts[0] === $startParts[0] && $gatewayParts[1] === $startParts[1]
        && $startParts[0] === $endParts[0] && $startParts[1] === $endParts[1]
        && $startParts[2] !== $endParts[2]) {
        $prefix = 16;
    }

    $network = $prefix === 16
        ? [$candidate[0], $candidate[1], 0, 0]
        : [$candidate[0], $candidate[1], $candidate[2], 0];

    return ['prefix' => $prefix, 'cidr' => implode('.', $network) . '/' . $prefix];
}
