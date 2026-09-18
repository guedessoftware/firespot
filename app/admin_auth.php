<?php
declare(strict_types=1);

require_once __DIR__ . '/session_boot.php';
require_once __DIR__ . '/csrf.php';

function admin_is_authenticated(): bool
{
    return isset($_SESSION['admin']) && $_SESSION['admin'] === true;
}

function admin_require_page(): void
{
    if (admin_is_authenticated()) {
        return;
    }
    $target = basename((string)($_SERVER['REQUEST_URI'] ?? 'index.php'));
    if (!headers_sent()) {
        header('Location: login.php?expired=1&next=' . rawurlencode($target));
    }
    exit;
}

function admin_require_json(): void
{
    if (admin_is_authenticated()) {
        return;
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

function admin_request_csrf(?array $payload = null): string
{
    foreach (['HTTP_X_CSRF_TOKEN', 'HTTP_X_CSRF'] as $header) {
        if (!empty($_SERVER[$header])) {
            return trim((string)$_SERVER[$header]);
        }
    }
    $source = is_array($payload) ? $payload : $_POST;
    foreach (['csrf', '_csrf'] as $field) {
        if (!empty($source[$field])) {
            return trim((string)$source[$field]);
        }
    }
    return '';
}

function admin_require_csrf(?array $payload = null, bool $json = false): void
{
    if (csrf_check(admin_request_csrf($payload))) {
        return;
    }
    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    } else {
        echo 'Sessão expirada ou token de segurança inválido. Recarregue a página.';
    }
    exit;
}

function admin_name(): string
{
    $name = trim((string)($_SESSION['admin_name'] ?? ''));
    return $name !== '' ? $name : 'Administrador';
}

function admin_role(): string
{
    $role = trim((string)($_SESSION['admin_role'] ?? 'admin'));
    return $role !== '' ? $role : 'admin';
}

function admin_id(): int
{
    return (int)($_SESSION['admin_id'] ?? 0);
}

function admin_has_capability(string $capability): bool
{
    $role = admin_role();
    if ($role === 'admin') return true;
    $map = [
        'manager' => [
            'partners.view','partners.manage','partner.portal.manage','partner.theme.manage',
            'partner.plans.manage','partner.administrators.view','reports.view',
            'monetization.view','subscribers.view','subscribers.manage',
        ],
        'viewer' => ['partners.view','reports.view'],
    ];
    return in_array($capability,$map[$role] ?? [],true);
}

function admin_require_capability(string $capability): void
{
    if (!admin_has_capability($capability)) throw new RuntimeException('Seu papel administrativo não permite esta ação.');
}

function admin_public_error(Throwable $error, string $fallback = 'Não foi possível concluir a operação.'): string
{
    if ($error instanceof PDOException) {
        error_log('[dashboard database] ' . $error->getMessage());
        return $fallback;
    }
    if ($error instanceof InvalidArgumentException || $error instanceof RuntimeException) {
        $message = trim($error->getMessage());
        return $message !== '' ? substr($message,0,300) : $fallback;
    }
    error_log('[dashboard unexpected] ' . get_class($error) . ': ' . $error->getMessage());
    return $fallback;
}
