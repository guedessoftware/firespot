#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['confirm-empty-database:']);
if (($options['confirm-empty-database'] ?? '') !== 'FIRESPOT_EMPTY_DATABASE') {
    fwrite(STDERR, "Uso exclusivo em banco vazio: --confirm-empty-database=FIRESPOT_EMPTY_DATABASE\n");
    exit(64);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/migration_framework.php';

$pdo = null;
$locked = false;
$started = false;
$exitCode = 0;
try {
    $root = dirname(__DIR__, 2);
    $inventory = fs_migration_inventory($root . '/migrations');
    // Atualize este bootstrap somente após validar uma nova instalação isolada.
    if (max(array_keys($inventory)) !== 57) {
        throw new RuntimeException('Bootstrap validado somente até a migração 057.');
    }
    $baseline = fs_migration_sql_statements((string) file_get_contents($root . '/migrations/baseline/056_schema.sql'));
    $metadata = json_decode((string) file_get_contents($root . '/migrations/baseline/056_schema.json'), true);
    if (!$baseline || !is_array($metadata) || ($metadata['schema_version'] ?? '') !== '056' || !empty($metadata['data_included'])) {
        throw new RuntimeException('Baseline de estrutura inválido.');
    }

    // Apenas configurações e catálogos públicos: nenhuma conta, NAS ou cliente.
    $defaults = [];
    foreach ([16, 17, 18, 19, 34, 36, 37, 39, 40] as $version) {
        $statements = fs_migration_sql_statements((string) file_get_contents($inventory[$version]['sql_path']));
        foreach ($statements as $statement) {
            if (preg_match('/^INSERT(?:\s+IGNORE)?\s+INTO\s+(?:dashboard_user_access_stats_meta|courtesy_policy_defaults|app_settings|subscriber_benefit_profiles)\b/i', $statement)
                || ($version === 40 && preg_match('/^UPDATE\s+subscriber_benefit_profiles\b/i', $statement))) {
                $defaults[] = $statement;
            }
        }
    }
    $modern = [];
    for ($version = 44; $version <= 57; $version++) {
        $modern[$version] = fs_migration_sql_statements((string) file_get_contents($inventory[$version]['sql_path']));
    }

    $pdo = db();
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([FS_MIGRATION_LOCK]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Outra operação de schema está em andamento.');
    }
    $locked = true;
    $count = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    $routines = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn();
    $events = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn();
    if ($count !== 0 || $routines !== 0 || $events !== 0) {
        throw new RuntimeException('Banco não está vazio; nenhuma alteração foi executada.');
    }

    $started = true;
    foreach ($baseline as $statement) $pdo->exec($statement);
    $snapshot = fs_schema_baseline_snapshot($pdo);
    if ($snapshot['table_count'] !== (int) $metadata['application_table_count']) {
        throw new RuntimeException('Quantidade de tabelas diverge do baseline.');
    }
    foreach ($defaults as $statement) $pdo->exec($statement);
    foreach ($modern as $statements) {
        foreach ($statements as $statement) $pdo->exec($statement);
    }

    foreach (['admin_users', 'partners', 'nas', 'guest_orders', 'subscriber_accounts'] as $table) {
        if ((int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() !== 0) {
            throw new RuntimeException('Bootstrap criou dados operacionais inesperados.');
        }
    }
    $ad = $pdo->query('SELECT provider, enabled, test_mode FROM ad_platform_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (!$ad || $ad['provider'] !== 'off' || (int) $ad['enabled'] !== 0 || (int) $ad['test_mode'] !== 1) {
        throw new RuntimeException('Publicidade não iniciou no estado seguro.');
    }

    fs_migration_ensure_ledger($pdo);
    $insert = $pdo->prepare('INSERT INTO schema_migrations
        (version,name,checksum,sql_checksum,apply_checksum,smoke_checksum,state,batch,execution_ms,executor,applied_at)
        VALUES (?,?,?,?,?,?,?,?,0,?,NOW())');
    $pdo->beginTransaction();
    foreach ($inventory as $version => $migration) {
        $insert->execute([$version, $migration['name'], $migration['checksum'], $migration['sql_checksum'],
            $migration['apply_checksum'], $migration['smoke_checksum'], $version <= 56 ? 'adopted' : 'applied',
            gmdate('YmdHis') . '-fresh', 'fresh-database-bootstrap']);
    }
    $pdo->commit();
    echo "Banco novo inicializado: baseline 056, catálogos públicos e migração 057.\n";
    echo "Nenhuma conta, cliente ou equipamento foi criado. Crie o administrador conforme o README.\n";
    echo "Smokes de cenários de implantação não foram executados; consulte migrations/README.md.\n";
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    // Erros de SQL podem conter valores privados: exiba somente o SQLSTATE.
    $message = $error instanceof PDOException ? 'Erro de banco (SQLSTATE ' . $error->getCode() . ').' : $error->getMessage();
    fwrite(STDERR, '[FAILED] ' . $message . PHP_EOL);
    if ($started) fwrite(STDERR, "DDL não é transacional. Não reutilize este banco parcialmente inicializado; revise em ambiente isolado.\n");
    $exitCode = 2;
} finally {
    if ($locked && $pdo instanceof PDO) {
        try {
            $unlock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $unlock->execute([FS_MIGRATION_LOCK]);
        } catch (Throwable $ignored) {}
    }
}
exit($exitCode);
