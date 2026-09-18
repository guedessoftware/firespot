<?php
// /dashboard/api/vendas_series.php — séries por dia (read-only)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../app/session_boot.php';
if (!isset($_SESSION['admin'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';

$from = isset($_GET['from']) ? $_GET['from'] : '';
$to   = isset($_GET['to'])   ? $_GET['to']   : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$host = isset($_GET['host']) ? trim($_GET['host']) : '';

try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET time_zone='-04:00'");
  ensure_vip_orders_payment_schema($pdo);

  $hasPayment = table_has_column($pdo, 'vip_orders', 'payment_method');

  $dateExpr = 'COALESCE(paid_at, created_at)';
  $where = ["status='paid'"];
  $params = [];
  if ($from !== '') { $where[] = "$dateExpr >= ?"; $params[] = $from.' 00:00:00'; }
  if ($to   !== '') { $where[] = "$dateExpr <= ?"; $params[] = $to.' 23:59:59'; }
  if ($status !== '' && $status !== 'paid') { $where[] = 'status = ?'; $params[] = $status; }
  if ($host !== '') { $where[] = 'host_code = ?'; $params[] = $host; }
  $whereSql = 'WHERE '.implode(' AND ', $where);

  $select = "SELECT DATE($dateExpr) AS d, COUNT(*) AS paid_count, COALESCE(SUM(valor_centavos),0) AS paid_amount";
  if ($hasPayment) {
    $select .= ",
      COALESCE(SUM(CASE WHEN payment_method='pix' THEN valor_centavos ELSE 0 END),0) AS pix_amount,
      COALESCE(SUM(CASE WHEN payment_method='card' THEN valor_centavos ELSE 0 END),0) AS card_amount,
      COALESCE(SUM(CASE WHEN payment_method='pix' THEN 1 ELSE 0 END),0) AS pix_count,
      COALESCE(SUM(CASE WHEN payment_method='card' THEN 1 ELSE 0 END),0) AS card_count";
  }

  $sql = "$select
    FROM vip_orders
    {$whereSql}
    GROUP BY DATE($dateExpr)
    ORDER BY d ASC";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $respRows = array_map(function($r) use ($hasPayment) {
    $row = [
      'date'=> (string)$r['d'],
      'paid_count'=> (int)$r['paid_count'],
      'paid_amount'=> (int)$r['paid_amount']
    ];
    if ($hasPayment) {
      $row['pix_amount'] = (int)($r['pix_amount'] ?? 0);
      $row['card_amount'] = (int)($r['card_amount'] ?? 0);
      $row['pix_count'] = (int)($r['pix_count'] ?? 0);
      $row['card_count'] = (int)($r['card_count'] ?? 0);
    }
    return $row;
  }, $rows);

  echo json_encode(['ok'=>true,'rows'=>$respRows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
