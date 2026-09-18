<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

const FS_MIGRATION_LEGACY_CUTOFF = 43;
const FS_MIGRATION_LOCK = 'firespot_schema_migrations';

function fs_migration_project_root(): string
{
    return dirname(__DIR__,2);
}

function fs_migration_app_root(): string
{
    return dirname(__DIR__);
}

/** @return array<int,array<string,mixed>> */
function fs_migration_inventory(?string $directory=null): array
{
    $directory = $directory ?? fs_migration_project_root() . '/migrations';
    $items = [];
    foreach (glob($directory . '/[0-9][0-9][0-9]_*.sql') ?: [] as $path) {
        $file = basename($path);
        if (!preg_match('/^(\d{3})_([a-z0-9_]+)\.sql$/', $file, $match)) {
            throw new RuntimeException('Nome de migração inválido: ' . $file);
        }
        $version = (int)$match[1];
        if (isset($items[$version])) throw new RuntimeException('Versão de migração duplicada: ' . $match[1]);
        $items[$version] = [
            'version'=>$version,
            'version_label'=>$match[1],
            'name'=>$match[2],
            'sql_path'=>$path,
            'apply_path'=>is_file($directory . '/apply_' . $match[1] . '.php') ? $directory . '/apply_' . $match[1] . '.php' : null,
            'smoke_path'=>is_file($directory . '/smoke_' . $match[1] . '.php') ? $directory . '/smoke_' . $match[1] . '.php' : null,
            'legacy'=>$version <= FS_MIGRATION_LEGACY_CUTOFF,
        ];
    }
    ksort($items, SORT_NUMERIC);
    $expected = 1;
    foreach (array_keys($items) as $version) {
        if ($version !== $expected) throw new RuntimeException(sprintf('Sequência de migrações interrompida: esperada %03d.', $expected));
        $expected++;
    }
    $metadata = [
        44=>['idempotent'=>true,'transactional'=>true,'description'=>'Fecha autogestão sem finalidade e registra auditoria.'],
        45=>['idempotent'=>true,'transactional'=>false,'description'=>'Cria catálogo de planos, assinaturas, entitlements e cotas tipadas.'],
        46=>['idempotent'=>true,'transactional'=>false,'description'=>'Cria propriedade de NAS, reservas e fila assíncrona de infraestrutura.'],
        47=>['idempotent'=>true,'transactional'=>false,'description'=>'Versiona cortesia comercial e adiciona override por instalação.'],
        48=>['idempotent'=>true,'transactional'=>false,'description'=>'Cria métricas diárias anônimas por instalação.'],
        49=>['idempotent'=>true,'transactional'=>false,'description'=>'Separa e registra validações do token e do webhook da carteira própria.'],
        50=>['idempotent'=>true,'transactional'=>false,'description'=>'Versiona modelos visuais, candidatos e migração manual do Portal V3.'],
        51=>['idempotent'=>true,'transactional'=>false,'description'=>'Centraliza NAS, versiona o plano Multipontos e libera rascunhos visuais ao estabelecimento.'],
        52=>['idempotent'=>true,'transactional'=>true,'description'=>'Migra assinaturas Multipontos vigentes da v1 aposentada para o contrato v2.'],
        53=>['idempotent'=>true,'transactional'=>false,'description'=>'Libera NAS próprios no Multipontos v3 e classifica os equipamentos confirmados do example_partner.'],
        54=>['idempotent'=>true,'transactional'=>false,'description'=>'Versiona solicitações de configuração dos pontos sem alterar o RouterOS.'],
        55=>['idempotent'=>true,'transactional'=>false,'description'=>'Separa a política de VLAN/rede do NAS e registra a máscara explícita de cada ponto.'],
        56=>['idempotent'=>true,'transactional'=>false,'description'=>'Individualiza cobrança, cortesia e janela Pix por ponto Hotspot sem alterar o RouterOS.'],
        57=>['idempotent'=>true,'transactional'=>false,'description'=>'Cria rede publicitária central, políticas por ponto e recompensas seguras, desativadas por padrão.'],
    ];
    foreach ($items as $version => &$item) {
        $item += $metadata[$version] ?? [];
        if (!$item['legacy'] && empty($item['idempotent'])) {
            throw new RuntimeException(sprintf('Migração %03d não declara idempotência.', $version));
        }
        $item['checksum'] = fs_migration_checksum($item);
        $item['sql_checksum'] = hash_file('sha256', $item['sql_path']);
        $item['apply_checksum'] = $item['apply_path'] ? hash_file('sha256', $item['apply_path']) : null;
        $item['smoke_checksum'] = $item['smoke_path'] ? hash_file('sha256', $item['smoke_path']) : null;
    }
    unset($item);
    return $items;
}

function fs_migration_checksum(array $migration): string
{
    $context = hash_init('sha256');
    // O smoke pode evoluir; somente o artefato que altera dados/schema é imutável.
    foreach (['sql_path','apply_path'] as $key) {
        $path = $migration[$key] ?? null;
        if (!$path) continue;
        hash_update($context, basename((string)$path) . "\0");
        $contents = file_get_contents((string)$path);
        if (!is_string($contents)) throw new RuntimeException('Não foi possível ler ' . basename((string)$path) . '.');
        hash_update($context, $contents . "\0");
    }
    return hash_final($context);
}

function fs_migration_ledger_exists(PDO $pdo): bool
{
    $statement = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'");
    return (int)$statement->fetchColumn() === 1;
}

function fs_migration_admin_db(): PDO
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('Operação de schema exige execução como root.');
    }
    require_once fs_migration_app_root() . '/config.php';
    $database = defined('DB_DATABASE') ? (string)DB_DATABASE : '';
    $host = defined('DB_HOST') ? strtolower(trim((string)DB_HOST)) : '';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $database)) throw new RuntimeException('Nome do banco inválido.');
    if (!in_array(preg_replace('/:\d+$/', '', $host), ['localhost','127.0.0.1','::1'], true)) {
        throw new RuntimeException('Banco remoto exige credencial administrativa externa; não use a conta da aplicação.');
    }
    foreach (['/run/mysqld/mysqld.sock','/var/run/mysqld/mysqld.sock'] as $socket) {
        if (!file_exists($socket)) continue;
        return new PDO(
            'mysql:unix_socket=' . $socket . ';dbname=' . $database . ';charset=utf8mb4',
            'root',
            '',
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
                PDO::MYSQL_ATTR_INIT_COMMAND=>'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ]
        );
    }
    throw new RuntimeException('Socket administrativo do MariaDB não encontrado.');
}

function fs_migration_ensure_ledger(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version INT UNSIGNED NOT NULL,
        name VARCHAR(190) NOT NULL,
        checksum CHAR(64) NOT NULL,
        sql_checksum CHAR(64) NOT NULL,
        apply_checksum CHAR(64) NULL,
        smoke_checksum CHAR(64) NULL,
        state ENUM('adopted','applied') NOT NULL,
        batch CHAR(24) NOT NULL,
        execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
        executor VARCHAR(128) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (version),
        KEY idx_schema_migrations_applied (applied_at,batch)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** @return array<int,array<string,mixed>> */
function fs_migration_ledger(PDO $pdo): array
{
    if (!fs_migration_ledger_exists($pdo)) return [];
    $rows = $pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $indexed = [];
    foreach ($rows as $row) $indexed[(int)$row['version']] = $row;
    return $indexed;
}

/** @return list<string> */
function fs_migration_validate_ledger(array $inventory, array $ledger): array
{
    $errors = [];
    foreach ($ledger as $version => $row) {
        if (!isset($inventory[$version])) {
            $errors[] = sprintf('LEDGER_UNKNOWN_VERSION_%03d', $version);
            continue;
        }
        if (!hash_equals((string)$row['checksum'], (string)$inventory[$version]['checksum'])) {
            $errors[] = sprintf('CHECKSUM_MISMATCH_%03d', $version);
        }
    }
    return $errors;
}

/** @return list<string> */
function fs_migration_sql_statements(string $sql): array
{
    if (preg_match('/\b(?:DELIMITER|CREATE\s+(?:DEFINER\s*=\s*\S+\s+)?(?:PROCEDURE|FUNCTION|TRIGGER|EVENT))\b/i', $sql)) {
        throw new RuntimeException('SQL complexo exige uma migração explicitamente não repetível e revisão manual.');
    }
    $statements = [];
    $buffer = '';
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $length = strlen($sql);
    for ($i=0; $i<$length; $i++) {
        $char = $sql[$i];
        $next = $i+1 < $length ? $sql[$i+1] : '';
        if ($lineComment) {
            if ($char === "\n") {$lineComment=false;$buffer.=$char;}
            continue;
        }
        if ($blockComment) {
            if ($char === '*' && $next === '/') {$blockComment=false;$i++;}
            continue;
        }
        if ($quote === null && $char === '-' && $next === '-' && ($i+2 >= $length || ctype_space($sql[$i+2]))) {$lineComment=true;$i++;continue;}
        if ($quote === null && $char === '#') {$lineComment=true;continue;}
        if ($quote === null && $char === '/' && $next === '*') {$blockComment=true;$i++;continue;}
        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\' && $i+1 < $length) {$buffer.=$sql[++$i];continue;}
            if ($char === $quote) {
                if ($next === $quote) {$buffer.=$sql[++$i];continue;}
                $quote = null;
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {$quote=$char;$buffer.=$char;continue;}
        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') $statements[] = $statement;
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }
    if ($quote !== null || $blockComment) throw new RuntimeException('SQL incompleto ou comentário não encerrado.');
    $tail = trim($buffer);
    if ($tail !== '') $statements[] = $tail;
    return $statements;
}

/** @return array{ok:bool,exit_code:int,duration_ms:int,code:string} */
function fs_migration_run_smoke(string $path, int $timeout=90): array
{
    if (!is_file($path) || !preg_match('/^smoke_\d{3}\.php$/', basename($path))) {
        throw new InvalidArgumentException('Smoke de migração inválido.');
    }
    $started = microtime(true);
    $process = proc_open(['/usr/bin/php',$path],[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes,fs_migration_project_root(),null,['bypass_shell'=>true]);
    if (!is_resource($process)) return ['ok'=>false,'exit_code'=>127,'duration_ms'=>0,'code'=>'SMOKE_START_FAILED'];
    $exitCode = null;
    while (true) {
        $status = proc_get_status($process);
        if (!$status['running']) {$exitCode=(int)$status['exitcode'];break;}
        if (microtime(true)-$started >= $timeout) {proc_terminate($process);usleep(250000);$status=proc_get_status($process);if($status['running'])proc_terminate($process,9);$exitCode=124;break;}
        usleep(100000);
    }
    $closed = proc_close($process);
    if ($exitCode === null || $exitCode < 0) $exitCode = $closed >= 0 ? $closed : 1;
    return ['ok'=>$exitCode===0,'exit_code'=>$exitCode,'duration_ms'=>(int)round((microtime(true)-$started)*1000),'code'=>$exitCode===0?'SMOKE_OK':($exitCode===124?'SMOKE_TIMEOUT':'SMOKE_FAILED')];
}

/** @return array{sql:string,table_count:int,sha256:string,tables:list<string>} */
function fs_schema_baseline_snapshot(PDO $pdo): array
{
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'schema_migrations'
        ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $parts = ["SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;","SET FOREIGN_KEY_CHECKS=0;"];
    foreach ($tables as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', (string)$table)) throw new RuntimeException('Tabela com nome não suportado no baseline.');
        $row = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
        $create = isset($row[1]) ? (string)$row[1] : '';
        if ($create === '') throw new RuntimeException('SHOW CREATE TABLE falhou para ' . $table . '.');
        $create = str_replace(["\r\n","\r"], "\n", $create);
        $create = preg_replace('/\sAUTO_INCREMENT=\d+\b/', '', $create) ?? $create;
        $parts[] = $create . ';';
    }
    $parts[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $sql = implode("\n\n", $parts) . "\n";
    return ['sql'=>$sql,'table_count'=>count($tables),'sha256'=>hash('sha256',$sql),'tables'=>array_values(array_map('strval',$tables))];
}
