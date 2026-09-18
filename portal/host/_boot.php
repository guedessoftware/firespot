<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/session_boot.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/partner_admin.php';

function host_db(): PDO
{
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone='-04:00'");
    return $pdo;
}

/** Compatibilidade temporária: verifica a migração sem executar DDL na requisição. */
function ensure_tables(): void
{
    if (!partner_admin_schema_ready(host_db())) {
        throw new RuntimeException('O painel está em manutenção. A migração 022 ainda não foi aplicada.');
    }
}

function host_h($value): string
{
    return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
}

function host_flash(string $type, string $message): void
{
    $_SESSION['host_flash'] = ['type'=>$type,'message'=>$message];
}

function host_admin_url(string $route = 'panel', array $query = []): string
{
    $paths = [
        'login' => '/admin/',
        'panel' => '/admin/painel.php',
        'select' => '/admin/unidade.php',
        'forgot' => '/admin/recuperar.php',
        'reset' => '/admin/redefinir.php',
        'invite' => '/admin/convite.php',
        'logout' => '/admin/sair.php',
    ];
    $url = $paths[$route] ?? $paths['panel'];
    if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    return $url;
}

function host_admin_asset_url(string $filename): string
{
    $filename = basename($filename);
    $path = __DIR__ . '/assets/' . $filename;
    $version = is_file($path) ? (string) filemtime($path) : '1';
    return '/portal/host/assets/' . rawurlencode($filename) . '?v=' . rawurlencode($version);
}

function host_redirect(string $page = 'summary'): void
{
    header('Location: ' . host_admin_url('panel', ['page'=>$page]));
    exit;
}
