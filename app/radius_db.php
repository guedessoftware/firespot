<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';

/**
 * Conexão central para o banco operacional do FreeRADIUS.
 *
 * Usa RADIUS_DB_* quando configurado. Caso contrário, mantém compatibilidade
 * com a instalação atual, na qual as tabelas RADIUS vivem no banco FireSpot.
 */
function fs_radius_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = trim((string) env('RADIUS_DB_HOST', ''));
    $name = trim((string) env('RADIUS_DB_NAME', ''));
    if ($host === '' || $name === '') {
        $pdo = db();
    } else {
        $port = '3306';
        if (strpos($host, ':') !== false) {
            [$hostOnly, $portMaybe] = explode(':', $host, 2);
            if ($hostOnly !== '') $host = $hostOnly;
            if (ctype_digit($portMaybe)) $port = $portMaybe;
        }
        $user = (string) env('RADIUS_DB_USER', '');
        $pass = (string) env('RADIUS_DB_PASS', '');
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    foreach (['radcheck', 'radreply', 'radusergroup', 'radacct'] as $table) {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
        $st->execute([$table]);
        if (!$st->fetchColumn()) throw new RuntimeException('Tabela RADIUS ausente: ' . $table);
    }
    return $pdo;
}
