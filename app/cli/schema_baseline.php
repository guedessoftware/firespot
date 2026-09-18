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
$options = getopt('', ['fingerprint','write','check']);
$actions = array_values(array_filter(['fingerprint','write','check'],static fn(string $name): bool => array_key_exists($name,$options)));
if (count($actions)>1) {fwrite(STDERR,"Use somente --fingerprint, --write ou --check.\n");exit(64);}
$action = $actions[0] ?? 'fingerprint';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$snapshot = fs_schema_baseline_snapshot($pdo);
$inventory = fs_migration_inventory(dirname(__DIR__,2) . '/migrations');
$version = max(array_keys($inventory));
$label = sprintf('%03d',$version);
$directory = dirname(__DIR__,2) . '/migrations/baseline';
$sqlPath = $directory . '/' . $label . '_schema.sql';
$metadataPath = $directory . '/' . $label . '_schema.json';
$header = "-- FireSpot schema-only baseline v{$label}; nenhum dado de produção.\n"
    . "-- schema-sha256: {$snapshot['sha256']}\n"
    . "-- application-tables: {$snapshot['table_count']}\n\n";
$document = $header . $snapshot['sql'];

if ($action === 'fingerprint') {
    printf("schema_version=%s tables=%d sha256=%s\n",$label,$snapshot['table_count'],$snapshot['sha256']);
    exit(0);
}

if ($action === 'write') {
    if (!is_dir($directory)) throw new RuntimeException('Diretório de baseline ausente.');
    $metadata = [
        'schema_version'=>$label,
        'application_table_count'=>$snapshot['table_count'],
        'schema_sha256'=>$snapshot['sha256'],
        'data_included'=>false,
        'excluded_tables'=>['schema_migrations'],
        'generated_at'=>date(DATE_ATOM),
    ];
    if (file_put_contents($sqlPath,$document,LOCK_EX)===false) throw new RuntimeException('Não foi possível gravar o baseline SQL.');
    if (file_put_contents($metadataPath,json_encode($metadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX)===false) throw new RuntimeException('Não foi possível gravar os metadados do baseline.');
    chmod($sqlPath,0640);chmod($metadataPath,0640);
    printf("baseline=written version=%s tables=%d sha256=%s\n",$label,$snapshot['table_count'],$snapshot['sha256']);
    exit(0);
}

if (!is_file($sqlPath) || !is_file($metadataPath)) {fwrite(STDERR,"Baseline v{$label} ausente.\n");exit(2);}
$storedSql = file_get_contents($sqlPath);
$storedMetadata = json_decode((string)file_get_contents($metadataPath),true);
if (!is_string($storedSql) || !is_array($storedMetadata)) {fwrite(STDERR,"Baseline v{$label} ilegível.\n");exit(2);}
$ok = hash_equals($document,$storedSql)
    && hash_equals((string)($storedMetadata['schema_sha256']??''),$snapshot['sha256'])
    && (int)($storedMetadata['application_table_count']??-1)===$snapshot['table_count']
    && empty($storedMetadata['data_included']);
printf("baseline=%s version=%s tables=%d sha256=%s\n",$ok?'ok':'drift',$label,$snapshot['table_count'],$snapshot['sha256']);
exit($ok?0:2);
