<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

function fs_normalize_public_base_url(string $value): string
{
    $base = rtrim(trim($value), '/');
    if ($base === '' || strlen($base) > 300 || !filter_var($base, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Informe uma URL pública válida, como https://firecdn.com.br.');
    }

    $parts = parse_url($base);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!is_array($parts)
        || !in_array($scheme, ['http', 'https'], true)
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new InvalidArgumentException('A URL pública deve conter somente esquema, domínio e caminho base.');
    }

    return $base;
}

/**
 * Origem pública confiável para links assinados, webhooks e arquivos enviados
 * ao NAS. A configuração do painel tem prioridade sobre APP_URL; o valor fixo
 * é apenas um fallback seguro. Nunca deriva a origem de HTTP_HOST.
 */
function fs_public_base_url(?PDO $pdo = null): string
{
    $configured = '';
    if ($pdo instanceof PDO) {
        try {
            $st = $pdo->prepare("SELECT svalue FROM app_settings WHERE skey='public_base_url' LIMIT 1");
            $st->execute();
            $configured = trim((string) ($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            // A migração pode ainda não existir; nesse caso usa o fallback.
        }
    }
    if ($configured === '') $configured = trim((string) env('APP_URL', ''));
    if ($configured === '') $configured = 'https://firecdn.com.br';
    return fs_normalize_public_base_url($configured);
}

function fs_public_host(?PDO $pdo = null): string
{
    $host = strtolower((string) parse_url(fs_public_base_url($pdo), PHP_URL_HOST));
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
        throw new RuntimeException('A URL pública não possui um domínio utilizável.');
    }
    return $host;
}
