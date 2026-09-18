<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../radius_db.php';

$args = $argv ?? [];
$apply = in_array('--apply', $args, true);
$json = in_array('--json', $args, true);
$runPublicId = '';
$usernameFilter = '';
foreach ($args as $arg) {
    if (preg_match('/^--run=([a-f0-9]{32})$/', $arg, $match)) $runPublicId = $match[1];
    if (preg_match('/^--username=(.+)$/', $arg, $match)) $usernameFilter = trim($match[1]);
}
if ($runPublicId === '') {
    fwrite(STDERR, "Uso: php app/cli/courtesy_legacy_radius_restore.php --run=<public_id> [--username=<temp>] [--apply] [--json]\n");
    exit(2);
}

$app = db();
$radius = fs_radius_db();
$st = $app->prepare('SELECT * FROM courtesy_legacy_radius_cleanup_runs WHERE public_id=? LIMIT 1');
$st->execute([$runPublicId]);
$run = $st->fetch(PDO::FETCH_ASSOC);
if (!$run) {
    fwrite(STDERR, "Execução de limpeza não encontrada.\n");
    exit(2);
}
$sql = 'SELECT * FROM courtesy_legacy_radius_archive WHERE run_id=?';
$params = [(int)$run['id']];
if ($usernameFilter !== '') {
    $sql .= ' AND username=?';
    $params[] = $usernameFilter;
}
$sql .= ' ORDER BY id';
$st = $app->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byTable = [];
foreach ($rows as $row) $byTable[$row['source_table']] = ($byTable[$row['source_table']] ?? 0) + 1;

$inserted = 0;
$skipped = 0;
$errors = [];
if ($apply) {
    $columnsByTable = [];
    foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
        $columnsByTable[$table] = array_fill_keys($radius->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN) ?: [], true);
    }
    $radius->beginTransaction();
    try {
        foreach ($rows as $archive) {
            $table = (string)$archive['source_table'];
            $data = json_decode((string)$archive['row_data'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !isset($columnsByTable[$table])) throw new RuntimeException('Linha arquivada inválida.');
            unset($data['id']);
            $data = array_intersect_key($data, $columnsByTable[$table]);
            if (!$data || empty($data['username'])) throw new RuntimeException('Linha arquivada sem dados restauráveis.');
            $columns = array_keys($data);
            $quoted = array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', $columns);
            $st = $radius->prepare("INSERT IGNORE INTO {$table} (" . implode(',', $quoted) . ') VALUES ('
                . implode(',', array_fill(0, count($columns), '?')) . ')');
            $st->execute(array_values($data));
            if ($st->rowCount() === 1) $inserted++; else $skipped++;
        }
        $radius->commit();
    } catch (Throwable $e) {
        if ($radius->inTransaction()) $radius->rollBack();
        $inserted = 0;
        $errors[] = $e->getMessage();
    }
}

$result = [
    'applied' => $apply,
    'run' => $runPublicId,
    'username_filter' => $usernameFilter !== '' ? $usernameFilter : null,
    'archived_rows' => count($rows),
    'by_table' => $byTable,
    'inserted' => $inserted,
    'skipped_existing' => $skipped,
    'errors' => $errors,
];
if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo ($apply ? 'Restauração' : 'Dry-run') . ': linhas=' . count($rows) . ', inseridas=' . $inserted . ', já existentes=' . $skipped . PHP_EOL;
    if (!$apply) echo "Use --apply para restaurar as credenciais arquivadas.\n";
    foreach ($errors as $error) fwrite(STDERR, $error . PHP_EOL);
}
exit($errors ? 2 : 0);
