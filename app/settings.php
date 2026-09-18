<?php
// Simple key/value settings stored in DB
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_guard.php';

function settings_table_ensure(PDO $pdo): void {
  runtime_schema_require($pdo, 'app_settings', ['skey','svalue','updated_at']);
}

function settings_get(string $key, $default = null) {
  $pdo = db();
  settings_table_ensure($pdo);
  $st = $pdo->prepare('SELECT svalue FROM app_settings WHERE skey=? LIMIT 1');
  $st->execute([$key]);
  $v = $st->fetchColumn();
  if ($v === false) return $default;
  return $v;
}

function settings_set(string $key, $value): void {
  $pdo = db();
  settings_table_ensure($pdo);
  $st = $pdo->prepare('INSERT INTO app_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
  $st->execute([$key, (string)$value]);
}

function settings_all(): array {
  $pdo = db();
  settings_table_ensure($pdo);
  $rows = $pdo->query('SELECT skey, svalue, updated_at FROM app_settings ORDER BY skey')->fetchAll(PDO::FETCH_ASSOC) ?: [];
  return $rows;
}
