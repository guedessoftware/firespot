<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_guard.php';

function radius_servers_table_ensure(PDO $pdo): void
{
  runtime_schema_require($pdo, 'radius_servers', ['id','name','host','port','secret']);
}

function radius_servers_all(PDO $pdo): array
{
  radius_servers_table_ensure($pdo);
  $stmt = $pdo->query('SELECT id, name, host, port, secret, created_at, updated_at FROM radius_servers ORDER BY name');
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function radius_server_create(PDO $pdo, array $data): int
{
  radius_servers_table_ensure($pdo);
  $stmt = $pdo->prepare('INSERT INTO radius_servers (name, host, port, secret) VALUES (?,?,?,?)');
  $stmt->execute([
    $data['name'],
    $data['host'],
    $data['port'],
    $data['secret'],
  ]);
  return (int)$pdo->lastInsertId();
}

function radius_server_delete(PDO $pdo, int $id): void
{
  radius_servers_table_ensure($pdo);
  $stmt = $pdo->prepare('DELETE FROM radius_servers WHERE id=? LIMIT 1');
  $stmt->execute([$id]);
}

function radius_server_find(PDO $pdo, int $id): ?array
{
  radius_servers_table_ensure($pdo);
  $stmt = $pdo->prepare('SELECT id, name, host, port, secret FROM radius_servers WHERE id=? LIMIT 1');
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
