#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/migration_framework.php';

date_default_timezone_set('America/Manaus');
$options = getopt('', ['status','verify','adopt-current','apply','schema-sha256:','confirm-adoption:']);
$actions = array_values(array_filter(['status','verify','adopt-current','apply'],static fn(string $name): bool => array_key_exists($name,$options)));
if (count($actions) > 1) {
    fwrite(STDERR,"Use somente uma ação: --status, --verify, --adopt-current ou --apply.\n");
    exit(64);
}
$action = $actions[0] ?? 'status';
$inventory = fs_migration_inventory(dirname(__DIR__,2) . '/migrations');
$runtime = db();
$runtime->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$runtime->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

$printStatus = static function (PDO $pdo) use ($inventory): int {
    $ledger = fs_migration_ledger($pdo);
    if (!$ledger) {
        printf("[PENDING] ledger ausente; %d migrações inventariadas; adoção controlada necessária.\n",count($inventory));
        return 2;
    }
    $errors = fs_migration_validate_ledger($inventory,$ledger);
    foreach ($inventory as $version=>$migration) {
        $row = $ledger[$version] ?? null;
        printf("[%s] %03d %s%s\n",$row?strtoupper((string)$row['state']):'PENDING',$version,$migration['name'],$row?'':' — não registrada');
    }
    foreach ($errors as $error) fwrite(STDERR,'[ERROR] '.$error.PHP_EOL);
    $pending = count(array_diff(array_keys($inventory),array_keys($ledger)));
    printf("Resumo: inventariadas=%d; registradas=%d; pendentes=%d; checksum_errors=%d.\n",count($inventory),count($ledger),$pending,count($errors));
    return $pending===0 && !$errors ? 0 : 2;
};

if ($action === 'status') exit($printStatus($runtime));

if ($action === 'verify') {
    $ledger = fs_migration_ledger($runtime);
    if (!$ledger) {fwrite(STDERR,"Ledger ausente. Execute primeiro a adoção controlada.\n");exit(2);}
    $errors = fs_migration_validate_ledger($inventory,$ledger);
    if ($errors) {foreach($errors as $error)fwrite(STDERR,'[ERROR] '.$error.PHP_EOL);exit(2);}
    $failures = 0;
    foreach ($ledger as $version=>$row) {
        $smoke = $inventory[$version]['smoke_path'] ?? null;
        if (!$smoke) continue;
        $result = fs_migration_run_smoke($smoke);
        printf("[%s] smoke %03d — %s; %d ms\n",$result['ok']?'READY':'FAILED',$version,$result['code'],$result['duration_ms']);
        if (!$result['ok']) $failures++;
    }
    printf("Verificação: checksums=ok; smokes_failed=%d.\n",$failures);
    exit($failures===0?0:2);
}

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR,"Adoção e aplicação de schema exigem root via sudo.\n");
    exit(77);
}
$admin = fs_migration_admin_db();
$lock = $admin->query("SELECT GET_LOCK('" . FS_MIGRATION_LOCK . "',0)");
if (!$lock || (int)$lock->fetchColumn() !== 1) {fwrite(STDERR,"Outra migração está em andamento.\n");exit(75);}

$exitCode = 1;
try {
    $ledger = fs_migration_ledger($admin);
    $errors = fs_migration_validate_ledger($inventory,$ledger);
    if ($errors) throw new RuntimeException(implode(',',$errors));

    if ($action === 'adopt-current') {
        if (($options['confirm-adoption'] ?? '') !== 'FIRESPOT_CURRENT_SCHEMA') {
            throw new InvalidArgumentException('Confirmação de adoção ausente.');
        }
        $snapshot = fs_schema_baseline_snapshot($runtime);
        $expected = strtolower(trim((string)($options['schema-sha256'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/',$expected) || !hash_equals($snapshot['sha256'],$expected)) {
            throw new RuntimeException('Fingerprint do schema não confere; adoção cancelada.');
        }
        foreach ($ledger as $version=>$row) {
            if ($version > FS_MIGRATION_LEGACY_CUTOFF) throw new RuntimeException('A adoção não pode ocorrer após migrações novas.');
        }
        foreach ($inventory as $version=>$migration) {
            if ($version > FS_MIGRATION_LEGACY_CUTOFF || !$migration['smoke_path'] || $version===22) continue;
            $result = fs_migration_run_smoke($migration['smoke_path']);
            printf("[%s] pré-adoção smoke %03d — %s\n",$result['ok']?'READY':'FAILED',$version,$result['code']);
            if (!$result['ok']) throw new RuntimeException(sprintf('Smoke %03d falhou antes da adoção.',$version));
        }
        echo "[EXPECTED] smoke 022 será fechado pela migração 044; os demais smokes legados passaram.\n";
        fs_migration_ensure_ledger($admin);
        $insert = $admin->prepare("INSERT INTO schema_migrations
            (version,name,checksum,sql_checksum,apply_checksum,smoke_checksum,state,batch,execution_ms,executor,applied_at)
            VALUES (?,?,?,?,?,?,'adopted',?,0,?,NOW())
            ON DUPLICATE KEY UPDATE version=version");
        $batch = date('YmdHis') . '-adopt';
        $executor = substr((string)(getenv('SUDO_USER') ?: getenv('USER') ?: 'root'),0,128);
        foreach ($inventory as $version=>$migration) {
            if ($version > FS_MIGRATION_LEGACY_CUTOFF || isset($ledger[$version])) continue;
            $insert->execute([$version,$migration['name'],$migration['checksum'],$migration['sql_checksum'],$migration['apply_checksum'],$migration['smoke_checksum'],$batch,$executor]);
            printf("[ADOPTED] %03d %s\n",$version,$migration['name']);
        }
        $exitCode = 0;
    } elseif ($action === 'apply') {
        for ($version=1; $version<=FS_MIGRATION_LEGACY_CUTOFF; $version++) {
            if (!isset($ledger[$version])) throw new RuntimeException(sprintf('Migração legada %03d não adotada.',$version));
        }
        $pending = array_filter($inventory,static fn(array $migration): bool => !$migration['legacy'] && !isset($ledger[$migration['version']]));
        if (!$pending) {echo "Nenhuma migração pendente.\n";$exitCode=0;}
        $batch = date('YmdHis') . '-apply';
        $executor = substr((string)(getenv('SUDO_USER') ?: getenv('USER') ?: 'root'),0,128);
        $insert = $admin->prepare("INSERT INTO schema_migrations
            (version,name,checksum,sql_checksum,apply_checksum,smoke_checksum,state,batch,execution_ms,executor,applied_at)
            VALUES (?,?,?,?,?,?,'applied',?,?,?,NOW())");
        foreach ($pending as $version=>$migration) {
            if ($migration['apply_path']) throw new RuntimeException(sprintf('Migração nova %03d não pode usar apply PHP disperso.',$version));
            $sql = file_get_contents($migration['sql_path']);
            if (!is_string($sql)) throw new RuntimeException(sprintf('Migração %03d ilegível.',$version));
            $statements = fs_migration_sql_statements($sql);
            if (!$statements) throw new RuntimeException(sprintf('Migração %03d vazia.',$version));
            $started = microtime(true);
            $transactional = !empty($migration['transactional']);
            if ($transactional) $admin->beginTransaction();
            try {
                foreach ($statements as $statement) $admin->exec($statement);
                $duration = (int)round((microtime(true)-$started)*1000);
                // Para DML transacional, a alteração e o registro no ledger
                // formam a mesma unidade atômica. Assim, uma interrupção não
                // deixa dados aplicados sem a respectiva versão registrada.
                if ($transactional) {
                    $insert->execute([$version,$migration['name'],$migration['checksum'],$migration['sql_checksum'],$migration['apply_checksum'],$migration['smoke_checksum'],$batch,$duration,$executor]);
                    $admin->commit();
                }
            } catch (Throwable $error) {
                if ($transactional && $admin->inTransaction()) $admin->rollBack();
                throw $error;
            }
            if ($migration['smoke_path']) {
                $result = fs_migration_run_smoke($migration['smoke_path']);
                if (!$result['ok']) throw new RuntimeException(sprintf('Smoke %03d falhou após aplicação.',$version));
            }
            if (!$transactional) {
                $duration = (int)round((microtime(true)-$started)*1000);
                $insert->execute([$version,$migration['name'],$migration['checksum'],$migration['sql_checksum'],$migration['apply_checksum'],$migration['smoke_checksum'],$batch,$duration,$executor]);
            }
            printf("[APPLIED] %03d %s — %d ms\n",$version,$migration['name'],$duration);
        }
        $exitCode = 0;
    }
} catch (Throwable $error) {
    fwrite(STDERR,'[FAILED] '.preg_replace('/[^A-Za-z0-9À-ÿ .,:;_\/-]+/u','',(string)$error->getMessage()).PHP_EOL);
    $exitCode = 2;
} finally {
    try {$admin->query("SELECT RELEASE_LOCK('" . FS_MIGRATION_LOCK . "')");} catch (Throwable $ignored) {}
}

if ($exitCode===0) {
    $statusCode=$printStatus($runtime);
    if ($action!=='adopt-current') $exitCode=$statusCode;
}
exit($exitCode);
