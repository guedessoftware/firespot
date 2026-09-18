<?php
// /dashboard/api/vendas_list.php — listagem paginada (read-only)
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
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = min(100, max(5, (int)($_GET['per_page'] ?? 20)));
$offset = ($page-1)*$per;

try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET time_zone='-04:00'");
  ensure_vip_orders_payment_schema($pdo);

  $hasPayment = table_has_column($pdo, 'vip_orders', 'payment_method');
  $hasRefund = table_has_column($pdo, 'vip_orders', 'refund_status');

  $where = [];
  $params = [];
  $dateExpr = 'COALESCE(o.paid_at, o.created_at)';
  if ($from !== '') { $where[] = "$dateExpr >= ?"; $params[] = $from.' 00:00:00'; }
  if ($to   !== '') { $where[] = "$dateExpr <= ?"; $params[] = $to.' 23:59:59'; }
  if ($status !== '') { $where[] = 'o.status = ?'; $params[] = $status; }
  if ($host !== '') { $where[] = 'o.host_code = ?'; $params[] = $host; }
  $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

  $sqlCnt = "SELECT COUNT(*) FROM vip_orders o {$whereSql}";
  $stCnt = $pdo->prepare($sqlCnt); $stCnt->execute($params); $total = (int)$stCnt->fetchColumn();

  // Query com todas as colunas novas
  $paymentSel = $hasPayment
    ? ', o.payment_method, o.payment_method_detail, o.payment_installments'
    : ", NULL AS payment_method, NULL AS payment_method_detail, NULL AS payment_installments";
  $refundSel = $hasRefund
    ? ', o.refund_status, o.refund_amount_centavos, o.refunded_at, o.mp_refund_id, o.refund_notes'
    : ", NULL AS refund_status, NULL AS refund_amount_centavos, NULL AS refunded_at, NULL AS mp_refund_id, NULL AS refund_notes";

  $baseSel = "SELECT o.id, o.external_ref, o.status, o.nome, o.cpf, o.email, o.telefone, 
                     o.valor_centavos AS amount_centavos, o.duracao_min, 
                     o.created_at, o.paid_at, o.vip_applied_at,
                     o.host_code, o.device_mac, o.device_ip, o.mp_payment_id, o.payment_expires_at,
                     $dateExpr AS reference_at" .
                     $paymentSel . $refundSel;
  $sql = $baseSel . " FROM vip_orders o {$whereSql} ORDER BY $dateExpr DESC, o.id DESC LIMIT ? OFFSET ?";
  $params[] = $per;
  $params[] = $offset;
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo json_encode(['ok'=>true,'rows'=>$rows,'page'=>$page,'per_page'=>$per,'total'=>$total], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
