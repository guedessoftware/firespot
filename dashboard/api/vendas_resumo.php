<?php
// /dashboard/api/vendas_resumo.php — KPIs de vendas (read-only)
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
  $hasRefund = table_has_column($pdo, 'vip_orders', 'refund_status');

  $baseWhere = [];
  $params = [];
  $dateExpr = 'COALESCE(paid_at, created_at)';
  if ($from !== '') { $baseWhere[] = "$dateExpr >= ?"; $params[] = $from.' 00:00:00'; }
  if ($to   !== '') { $baseWhere[] = "$dateExpr <= ?"; $params[] = $to.' 23:59:59'; }
  if ($status !== '') { $baseWhere[] = 'status = ?'; $params[] = $status; }
  if ($host !== '') { $baseWhere[] = 'host_code = ?'; $params[] = $host; }

  // Totais pagos
  $wherePaid = $baseWhere;
  if ($status === '') { $wherePaid[] = "status='paid'"; }
  $wherePaidSql = $wherePaid ? ('WHERE '.implode(' AND ', $wherePaid)) : '';
  $paid = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(valor_centavos),0) s FROM vip_orders {$wherePaidSql}");
  $paid->execute($params);
  $P = $paid->fetch(PDO::FETCH_ASSOC) ?: ['c'=>0,'s'=>0];

  // Pendentes
  $wherePend = $baseWhere;
  if ($status === '') { $wherePend[] = "status='pending'"; }
  $wherePendSql = $wherePend ? ('WHERE '.implode(' AND ', $wherePend)) : '';
  $pend = $pdo->prepare("SELECT COUNT(*) c FROM vip_orders {$wherePendSql}");
  $pend->execute($params);
  $Q = $pend->fetch(PDO::FETCH_ASSOC) ?: ['c'=>0];

  $paidCount = (int)($P['c'] ?? 0);
  $paidAmount= (int)($P['s'] ?? 0);
  $aov = $paidCount>0 ? (int)floor($paidAmount/$paidCount) : 0;

  $fmt = function($cent){ return 'R$ '.number_format(($cent/100.0), 2, ',', '.'); };

  $methods = [];
  if ($hasPayment) {
    $whereMethod = $wherePaid;
    $whereMethodSql = $whereMethod ? ('WHERE '.implode(' AND ', $whereMethod)) : '';
    $stm = $pdo->prepare("SELECT payment_method, COUNT(*) c, COALESCE(SUM(valor_centavos),0) s FROM vip_orders {$whereMethodSql} GROUP BY payment_method");
    $stm->execute($params);
    $rows = $stm->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
      $methodKey = $row['payment_method'] !== null && $row['payment_method'] !== '' ? $row['payment_method'] : 'desconhecido';
      $count = (int)($row['c'] ?? 0);
      $amount = (int)($row['s'] ?? 0);
      $methods[$methodKey] = [
        'count' => $count,
        'amount_centavos' => $amount,
        'amount_br' => $fmt($amount),
        'ticket_centavos' => $count > 0 ? (int) floor($amount / $count) : 0,
        'ticket_br' => $count > 0 ? $fmt((int) floor($amount / $count)) : $fmt(0),
      ];
    }
  }

  $refunds = null;
  if ($hasRefund) {
    $whereRefund = $baseWhere;
    $whereRefund[] = "refund_status IN ('partial','processed')";
    $whereRefundSql = $whereRefund ? ('WHERE '.implode(' AND ', $whereRefund)) : '';
    $str = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(refund_amount_centavos),0) s FROM vip_orders {$whereRefundSql}");
    $str->execute($params);
    $R = $str->fetch(PDO::FETCH_ASSOC) ?: ['c'=>0,'s'=>0];
    $refunds = [
      'count' => (int)($R['c'] ?? 0),
      'amount_centavos' => (int)($R['s'] ?? 0),
      'amount_br' => $fmt((int)($R['s'] ?? 0)),
    ];
  }

  echo json_encode([
    'ok'=>true,
    'totals'=>[
      'paid_count'=>$paidCount,
      'paid_amount_centavos'=>$paidAmount,
      'paid_amount_br'=>$fmt($paidAmount),
      'aov_centavos'=>$aov,
      'aov_br'=>$fmt($aov),
      'pending_count'=>(int)($Q['c'] ?? 0)
    ],
    'methods'=>$methods,
    'refunds'=>$refunds,
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
