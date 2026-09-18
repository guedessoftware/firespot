<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/cli/migration_framework.php';

$checks=0;
function migration_expect(bool $condition,string $message):void{global$checks;$checks++;if(!$condition)throw new RuntimeException($message);}

$inventory=fs_migration_inventory(__DIR__.'/../migrations');
migration_expect(fs_migration_project_root()===dirname(__DIR__),'Raiz do projeto foi resolvida incorretamente.');
migration_expect(fs_migration_app_root()===dirname(__DIR__).'/app','Raiz da aplicação foi resolvida incorretamente.');
migration_expect(is_file(fs_migration_app_root().'/config.php'),'Configuração da aplicação não foi localizada pelo framework.');
migration_expect(count($inventory)===57,'Inventário não contém as 57 migrações.');
migration_expect(array_keys($inventory)===range(1,57),'Versões não são contíguas.');
migration_expect(!empty($inventory[43]['legacy'])&&empty($inventory[44]['legacy']),'Corte legado foi classificado incorretamente.');
migration_expect(!empty($inventory[44]['idempotent'])&&!empty($inventory[44]['transactional']),'Migração 044 não declarou segurança de repetição/transação.');
migration_expect(preg_match('/^[a-f0-9]{64}$/',$inventory[44]['checksum'])===1,'Checksum agregado inválido.');
migration_expect($inventory[44]['apply_path']===null,'Migração nova reintroduziu apply PHP disperso.');
migration_expect(is_string($inventory[44]['smoke_path']),'Migração 044 não possui smoke.');
foreach(range(45,51) as $version){
    migration_expect(!empty($inventory[$version]['idempotent']),'Migração nova não declarou idempotência: '.$version);
    migration_expect(empty($inventory[$version]['transactional']),'DDL MariaDB não deve simular transação: '.$version);
    migration_expect($inventory[$version]['apply_path']===null,'Migração nova reintroduziu apply PHP disperso: '.$version);
    migration_expect(is_string($inventory[$version]['smoke_path']),'Migração nova não possui smoke: '.$version);
}
migration_expect(!empty($inventory[52]['idempotent'])&&!empty($inventory[52]['transactional']),'Migração 052 DML não declarou repetição e transação atômicas.');
migration_expect($inventory[52]['apply_path']===null&&is_string($inventory[52]['smoke_path']),'Migração 052 reintroduziu apply disperso ou ficou sem smoke.');
migration_expect(!empty($inventory[53]['idempotent'])&&empty($inventory[53]['transactional']),'Migração 053 com DDL não declarou repetição segura ou simulou transação.');
migration_expect($inventory[53]['apply_path']===null&&is_string($inventory[53]['smoke_path']),'Migração 053 reintroduziu apply disperso ou ficou sem smoke.');
migration_expect(count(fs_migration_sql_statements((string)file_get_contents($inventory[53]['sql_path'])))===11,'Migração 053 não passa pelo parser com o conjunto revisado de instruções.');
migration_expect(!empty($inventory[54]['idempotent'])&&empty($inventory[54]['transactional']),'Migração 054 com DDL não declarou repetição segura ou simulou transação.');
migration_expect($inventory[54]['apply_path']===null&&is_string($inventory[54]['smoke_path']),'Migração 054 reintroduziu apply disperso ou ficou sem smoke.');
migration_expect(count(fs_migration_sql_statements((string)file_get_contents($inventory[54]['sql_path'])))===1,'Migração 054 não passa pelo parser como instrução única e idempotente.');
migration_expect(!empty($inventory[55]['idempotent'])&&empty($inventory[55]['transactional']),'Migração 055 com DDL não declarou repetição segura ou simulou transação.');
migration_expect($inventory[55]['apply_path']===null&&is_string($inventory[55]['smoke_path']),'Migração 055 reintroduziu apply disperso ou ficou sem smoke.');
migration_expect(count(fs_migration_sql_statements((string)file_get_contents($inventory[55]['sql_path'])))===11,'Migração 055 não passa pelo parser com as onze instruções revisadas.');
migration_expect(!empty($inventory[56]['idempotent'])&&empty($inventory[56]['transactional']),'Migração 056 com DDL não declarou repetição segura ou simulou transação.');
migration_expect($inventory[56]['apply_path']===null&&is_string($inventory[56]['smoke_path']),'Migração 056 reintroduziu apply disperso ou ficou sem smoke.');
migration_expect(count(fs_migration_sql_statements((string)file_get_contents($inventory[56]['sql_path'])))===2,'Migração 056 deve conter apenas a tabela e o backfill neutro.');
migration_expect(!empty($inventory[57]['idempotent'])&&empty($inventory[57]['transactional']),'Migração 057 com DDL não declarou repetição segura ou simulou transação.');
migration_expect($inventory[57]['apply_path']===null&&is_string($inventory[57]['smoke_path']),'Migração 057 reintroduziu apply disperso ou ficou sem smoke.');
migration_expect(count(fs_migration_sql_statements((string)file_get_contents($inventory[57]['sql_path'])))===11,'Migração 057 deve conter as seis tabelas, seeds neutros e entitlement do Plano Máximo.');

$parsed=fs_migration_sql_statements("-- comentário\nINSERT INTO t VALUES ('a;b');\n/* bloco */ UPDATE t SET c=\"x;y\"; # fim\n");
migration_expect(count($parsed)===2,'Parser separou ponto e vírgula dentro de literal.');
migration_expect(str_contains($parsed[0],"'a;b'"),'Parser alterou literal SQL.');
migration_expect(str_starts_with($parsed[1],'UPDATE'),'Parser preservou comentário indevido.');
$migration044=(string)file_get_contents($inventory[44]['sql_path']);
migration_expect(count(fs_migration_sql_statements($migration044))===2,'Migração 044 não possui exatamente duas instruções.');
$frameworkSource=(string)file_get_contents(__DIR__.'/../app/cli/migration_framework.php');
migration_expect(str_contains($frameworkSource,"PDO::MYSQL_ATTR_INIT_COMMAND=>'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'"),'Conexão administrativa de migração não fixa a collation canônica.');
$runnerSource=(string)file_get_contents(__DIR__.'/../app/cli/migrations.php');
$atomicMarker=strpos($runnerSource,'Para DML transacional');
$atomicLedger=$atomicMarker===false?false:strpos($runnerSource,'$insert->execute',$atomicMarker);
$atomicCommit=$atomicMarker===false?false:strpos($runnerSource,'$admin->commit()',$atomicMarker);
migration_expect($atomicMarker!==false&&$atomicLedger!==false&&$atomicCommit!==false&&$atomicLedger<$atomicCommit,'Runner não registra DML e ledger na mesma transação.');
migration_expect(str_contains($runnerSource,'if (!$transactional)')&&str_contains($runnerSource,'if ($transactional && $admin->inTransaction()) $admin->rollBack()'),'Runner perdeu a separação segura entre DML transacional e DDL não transacional.');
$migration046=(string)file_get_contents($inventory[46]['sql_path']);
migration_expect(str_contains($migration046,'COLLATE utf8mb4_unicode_ci'),'Reconciliação da migração 046 compara identificadores com collation implícita do servidor.');
$complexRejected=false;try{fs_migration_sql_statements('DELIMITER $$ CREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW BEGIN END$$');}catch(RuntimeException $error){$complexRejected=true;}
migration_expect($complexRejected,'Runner aceitou SQL complexo sem revisão manual.');

$ledger=[43=>['checksum'=>$inventory[43]['checksum']],44=>['checksum'=>str_repeat('0',64)],99=>['checksum'=>str_repeat('a',64)]];
$errors=fs_migration_validate_ledger($inventory,$ledger);
migration_expect(in_array('CHECKSUM_MISMATCH_044',$errors,true),'Drift de checksum não foi detectado.');
migration_expect(in_array('LEDGER_UNKNOWN_VERSION_099',$errors,true),'Versão desconhecida no ledger não foi detectada.');

foreach(array_merge(glob(__DIR__.'/../migrations/apply_*.php')?:[],glob(__DIR__.'/../migrations/smoke_*.php')?:[]) as $path){
    $source=(string)file_get_contents($path);
    migration_expect(str_contains($source,'PHP_SAPI'),'Script de migração alcançável por HTTP: '.basename($path));
}

$snapshot=fs_schema_baseline_snapshot(db());
migration_expect($snapshot['table_count']>=88,'Baseline não contém as 88 tabelas da aplicação.');
migration_expect(!in_array('schema_migrations',$snapshot['tables'],true),'Ledger operacional vazou para o baseline da aplicação.');
migration_expect(!preg_match('/AUTO_INCREMENT=\d+/',$snapshot['sql']),'Baseline preservou contador de produção.');
migration_expect(!str_contains(strtoupper($snapshot['sql']),' DEFINER='),'Baseline preservou DEFINER.');
migration_expect(hash_equals(hash('sha256',$snapshot['sql']),$snapshot['sha256']),'Fingerprint do baseline não é reproduzível.');

echo "OK: {$checks} verificações do framework de migrações.\n";
