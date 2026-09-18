<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/integration_credentials.php';

$pdo = db();
foreach (['system_integrations', 'system_integration_audit'] as $table) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $statement->execute([$table]);
    if ((int) $statement->fetchColumn() !== 1) {
        throw new RuntimeException("Tabela {$table} ausente.");
    }
}

$row = fs_integration_hubsoft_row($pdo);
if (!$row) {
    echo "Smoke 038 OK: estrutura pronta; HubSoft aguarda configuração pelo painel.\n";
    exit(0);
}

foreach (['client_secret_encrypted', 'username_encrypted', 'password_encrypted'] as $field) {
    if (!str_starts_with((string) $row[$field], 'ic1:')) {
        throw new RuntimeException("Campo {$field} não está criptografado.");
    }
}

if (!is_readable(fs_application_master_key_path())) {
    echo "Smoke 038 OK: credenciais criptografadas; a chave está restrita ao usuário da aplicação.\n";
    exit(0);
}

$loaded = fs_integration_hubsoft_load($pdo, true);
if (
    trim($loaded['client_secret']) === ''
    || trim($loaded['username']) === ''
    || trim($loaded['password']) === ''
) {
    throw new RuntimeException('Credenciais HubSoft não puderam ser abertas.');
}

echo "Smoke 038 OK: integração HubSoft armazenada e criptografada.\n";
