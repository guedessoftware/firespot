<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../app/db.php';
$u = trim($_POST['username'] ?? '');
$ua = trim($_POST['ua'] ?? '');
$os = trim($_POST['os'] ?? '');

try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET time_zone='-04:00'");
  $st = $pdo->prepare(
  "UPDATE firespot.radacct
     SET device_info = CONCAT(IFNULL(device_info,''), ?)
   WHERE username=?
   ORDER BY acctstarttime DESC
   LIMIT 1"
);
$st->execute([ substr($os . ': ' . $ua, 0, 250), $u ]);

  try {
    $st->execute([substr($os . ': ' . $ua, 0, 250), $u]);
  } catch (\Throwable $e) {
  }
  echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
  echo json_encode(['ok' => false]);
}
