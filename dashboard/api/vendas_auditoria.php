<?php
// /dashboard/api/vendas_auditoria.php — auditoria de entregas VIP
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../app/session_boot.php';
if (!isset($_SESSION['admin'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/mp_client.php';

$ageMin = max(1, min(1440, (int)($_GET['min_age_min'] ?? 5))); // 1..1440 min
$verifyMp = (int)($_GET['verify_mp'] ?? 0) === 1;              // opcional
$verifyLimit = min(50, max(1, (int)($_GET['verify_limit'] ?? 20)));

try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("SET time_zone='-04:00'");

  // Detecta se existe a coluna vip_applied_at
  $hasVipApplied = false;
  $col = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='vip_orders' AND COLUMN_NAME='vip_applied_at'")->fetchColumn();
  $hasVipApplied = ((int)$col) > 0;

  $paidNoVip = [];
  if ($hasVipApplied) {
    $sql = "SELECT id, external_ref, nome, valor_centavos, duracao_min, created_at, paid_at
            FROM vip_orders
            WHERE status='paid' AND vip_applied_at IS NULL AND paid_at IS NOT NULL AND paid_at <= (NOW() - INTERVAL ? MINUTE)
            ORDER BY paid_at DESC
            LIMIT 200";
    $st  = $pdo->prepare($sql);
    $st->execute([$ageMin]);
    $paidNoVip = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  // Pendentes antigos com mp_payment_id — podem estar travados
  $sqlP = "SELECT id, external_ref, nome, valor_centavos, duracao_min, created_at, mp_payment_id
           FROM vip_orders
           WHERE status='pending' AND mp_payment_id IS NOT NULL AND created_at <= (NOW() - INTERVAL ? MINUTE)
           ORDER BY created_at DESC
           LIMIT 200";
  $stP = $pdo->prepare($sqlP);
  $stP->execute([$ageMin]);
  $pending = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Opcional: verificar no MP se alguns pendentes estão "approved"
  if ($verifyMp && !empty($pending)) {
    $n = 0;
    foreach ($pending as &$row) {
      if ($n >= $verifyLimit) break;
      try {
        $mpid = $row['mp_payment_id'];
        $j = mp_get_payment($mpid);
        $row['mp_status'] = $j['status'] ?? '';
      } catch (Throwable $e) {
        $row['mp_status'] = 'error';
      }
      $n++;
    }
    unset($row);
  }

  echo json_encode([
    'ok' => true,
    'has_vip_applied' => $hasVipApplied,
    'age_min' => $ageMin,
    'paid_without_vip' => $paidNoVip,
    'pending_stuck' => $pending,
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
