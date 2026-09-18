<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal_v3_payment_window.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$dryRun = in_array('--dry-run', $argv ?? [], true);
$stExpired = $pdo->query("SELECT * FROM guest_orders
    WHERE status='pending'
      AND COALESCE(payment_expires_at,DATE_ADD(created_at,INTERVAL 24 HOUR))<=NOW()
    ORDER BY id LIMIT 500");
$expiredOrderRows = $stExpired->fetchAll(PDO::FETCH_ASSOC) ?: [];
$expiredOrders = 0;
$releasedAttempts = 0;
if (!$dryRun) {
    $expire = $pdo->prepare("UPDATE guest_orders
        SET status='cancelled',payment_status_detail='expired',updated_at=NOW()
        WHERE id=? AND status='pending'
          AND COALESCE(payment_expires_at,DATE_ADD(created_at,INTERVAL 24 HOUR))<=NOW()");
    foreach ($expiredOrderRows as $expiredOrder) {
        $expiredOrderId = (int)$expiredOrder['id'];
        fs_v3_payment_window_close($pdo,['id'=>(int)$expiredOrder['partner_id']],$expiredOrder);
        $expire->execute([$expiredOrderId]);
        if ($expire->rowCount() !== 1) continue;
        $expiredOrders++;
        $releasedAttempts += fs_v3_payment_window_release_expired_order($pdo,$expiredOrderId);
    }
}
$closedWindows = 0;
if (fs_v3_payment_window_schema_ready($pdo)) {
    $stWindows = $pdo->query("SELECT g.*
        FROM guest_orders g
        WHERE g.payment_window_token IS NOT NULL AND g.payment_window_closed_at IS NULL
          AND g.payment_window_expires_at < DATE_SUB(NOW(),INTERVAL 1 MINUTE)
        ORDER BY g.id LIMIT 100");
    $windowOrders = $stWindows->fetchAll();
    if ($dryRun) {
        $closedWindows = count($windowOrders);
    } else {
        foreach ($windowOrders as $windowOrder) {
            $partner = ['id' => (int)$windowOrder['partner_id']];
            if (fs_v3_payment_window_close($pdo, $partner, $windowOrder)) $closedWindows++;
        }
    }
}
$coaRows = $pdo->query("SELECT * FROM guest_orders
    WHERE status='paid' AND payment_access_mode='radius_preauth'
      AND COALESCE(radius_coa_status,'pending') NOT IN ('applied','manual_review')
    ORDER BY paid_at,id LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$coaCandidates=[];$coaTerminal=[];
foreach($coaRows as $coaRow){$policy=fs_guest_payment_radius_retry_policy($coaRow);if($policy['terminal'])$coaTerminal[]=(int)$coaRow['id'];elseif($policy['eligible'])$coaCandidates[]=(int)$coaRow['id'];}
$coaApplied = 0;
$coaPending = 0;
$coaManualReview = 0;
if (!$dryRun) {
    foreach (array_merge($coaTerminal,$coaCandidates) as $candidateId) {
        try {
            $reconciled = fs_guest_finalize_paid_access($pdo,(int)$candidateId);
            if ((string)($reconciled['radius_coa_status'] ?? '') === 'applied') $coaApplied++;
            elseif ((string)($reconciled['radius_coa_status'] ?? '') === 'manual_review') $coaManualReview++;
            else $coaPending++;
        } catch (Throwable $error) {
            $coaPending++;
            error_log('[cleanup_guest_access coa] order=' . (int)$candidateId . ' ' . get_class($error));
        }
    }
}
$st = $pdo->query("SELECT g.id,g.radius_username,g.status,
    COALESCE((SELECT CAST(rc.value AS UNSIGNED) FROM radcheck rc
      WHERE rc.username=g.radius_username AND rc.attribute='Max-All-Session' ORDER BY rc.id DESC LIMIT 1),0) AS allowed_seconds,
    COALESCE((SELECT SUM(COALESCE(ra.acctsessiontime,0)) FROM radacct ra
      WHERE ra.username=g.radius_username),0) AS used_seconds
  FROM guest_orders g
  WHERE g.radius_username IS NOT NULL AND g.radius_cleaned_at IS NULL AND g.access_granted_at IS NOT NULL
    AND g.status IN ('paid','payment_failed','cancelled','refunded')
  HAVING status<>'paid' OR allowed_seconds<=0 OR used_seconds>=allowed_seconds
  ORDER BY g.id LIMIT 500");
$orders = $st->fetchAll();
$cleaned = 0;
foreach ($dryRun ? [] : $orders as $order) {
    $pdo->beginTransaction();
    try {
        $username = (string)$order['radius_username'];
        $pdo->prepare('DELETE FROM radcheck WHERE username=?')->execute([$username]);
        $pdo->prepare('DELETE FROM radreply WHERE username=?')->execute([$username]);
        $pdo->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$username]);
        $pdo->prepare('UPDATE guest_orders SET radius_cleaned_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$order['id']]);
        $pdo->commit();
        $cleaned++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, '[cleanup_guest_access] pedido ' . $order['id'] . ': ' . $e->getMessage() . PHP_EOL);
    }
}
if ($dryRun) {
    echo '[cleanup_guest_access] dry_run=1 pedidos_expirados=' . count($expiredOrderRows) . ' credenciais_candidatas=' . count($orders) . ' janelas_candidatas=' . $closedWindows . ' coa_candidatos=' . count($coaCandidates) . ' coa_revisao=' . count($coaTerminal) . PHP_EOL;
} else {
    echo '[cleanup_guest_access] pedidos_expirados=' . $expiredOrders . ' tentativas_liberadas=' . $releasedAttempts . ' removidos=' . $cleaned . ' janelas_fechadas=' . $closedWindows . ' coa_aplicados=' . $coaApplied . ' coa_pendentes=' . $coaPending . ' coa_revisao=' . $coaManualReview . PHP_EOL;
}
