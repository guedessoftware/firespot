<?php
// /dashboard/api/vendas_export.php — CSV
header('Content-Type: text/csv; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../app/session_boot.php';
if (!isset($_SESSION['admin'])) { http_response_code(401); echo 'unauthorized'; exit; }

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';

$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
$status = $_GET['status'] ?? '';
$host = $_GET['host'] ?? '';

$fh = fopen('php://output', 'w');
fputcsv($fh, ['ID','Ref','Status','Nome','CPF','Valor (centavos)','Minutos','Método','Detalhe método','Parcelas','Criado em','Pago em','Expira em','VIP aplicado em','Status reembolso','Valor reembolso (centavos)','Reembolsado em','Notas reembolso'], ';');

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

  // Tenta com vip_applied_at; se falhar, volta sem a coluna
  $paymentSel = $hasPayment ? ', o.payment_method, o.payment_method_detail, o.payment_installments' : ", NULL AS payment_method, NULL AS payment_method_detail, NULL AS payment_installments";
  $refundSel = $hasRefund ? ', o.refund_status, o.refund_amount_centavos, o.refunded_at, o.refund_notes' : ", NULL AS refund_status, NULL AS refund_amount_centavos, NULL AS refunded_at, NULL AS refund_notes";

  $sqlTry = "SELECT o.id, o.external_ref, o.status, o.nome, o.cpf, o.valor_centavos, o.duracao_min, o.created_at, o.paid_at, o.payment_expires_at, o.vip_applied_at AS vip_applied_at" .
            $paymentSel . $refundSel .
            " FROM vip_orders o {$whereSql} ORDER BY $dateExpr DESC, o.id DESC";
  try {
    $st = $pdo->prepare($sqlTry); $st->execute($params);
  } catch (Throwable $e) {
    $sqlFb = "SELECT o.id, o.external_ref, o.status, o.nome, o.cpf, o.valor_centavos, o.duracao_min, o.created_at, o.paid_at, o.payment_expires_at, NULL AS vip_applied_at, NULL AS payment_method, NULL AS payment_method_detail, NULL AS payment_installments, NULL AS refund_status, NULL AS refund_amount_centavos, NULL AS refunded_at, NULL AS refund_notes FROM vip_orders o {$whereSql} ORDER BY $dateExpr DESC, o.id DESC";
    $st = $pdo->prepare($sqlFb); $st->execute($params);
  }
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($fh, [
      $r['id'],
      $r['external_ref'],
      $r['status'],
      $r['nome'],
      $r['cpf'],
      $r['valor_centavos'],
      $r['duracao_min'],
      $r['payment_method'],
      $r['payment_method_detail'],
      $r['payment_installments'],
      $r['created_at'],
      $r['paid_at'],
      $r['payment_expires_at'],
      $r['vip_applied_at'],
      $r['refund_status'],
      $r['refund_amount_centavos'],
      $r['refunded_at'],
      $r['refund_notes'],
    ], ';');
  }
} catch (Throwable $e) {
  // escreve uma linha de erro no CSV
  fputcsv($fh, ['ERRO', $e->getMessage()], ';');
}

fclose($fh);
