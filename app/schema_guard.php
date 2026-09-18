<?php

declare(strict_types=1);

/**
 * Verifica dependências de schema sem executar DDL em uma requisição web.
 * O cache é por conexão/tabela e somente confirma a lista de colunas pedida.
 */
function runtime_schema_require(PDO $pdo, string $table, array $columns = []): void
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new InvalidArgumentException('Nome de tabela inválido.');
    }
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table;
    if (!isset($cache[$key])) {
        $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $st->execute([$table]);
        $cache[$key] = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN) ?: [], true);
    }
    if (!$cache[$key]) {
        throw new RuntimeException("Estrutura ausente: {$table}. Aplique as migrações pendentes.");
    }
    foreach ($columns as $column) {
        if (!isset($cache[$key][$column])) {
            throw new RuntimeException("Estrutura ausente: {$table}.{$column}. Aplique as migrações pendentes.");
        }
    }
}
