<?php
// /dashboard/api/vendas_action.php
// Ações administrativas em pedidos (cancelar, reenviar WhatsApp, etc)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_json();

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/lib/promo_api.php';
require_once __DIR__ . '/../../app/mp_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
admin_require_csrf($input, true);
$order_id = (int)($input['order_id'] ?? 0);
$action = trim($input['action'] ?? '');

if ($order_id <= 0 || $action === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_parameters']);
    exit;
}

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone='-04:00'");
    ensure_vip_orders_payment_schema($pdo);
    
    // Busca pedido
    $stmt = $pdo->prepare("SELECT * FROM vip_orders WHERE id = ? LIMIT 1");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'order_not_found']);
        exit;
    }
    
    $admin_username = admin_name();
    $result = ['ok' => true];
    
    switch ($action) {
        case 'cancel':
            // Cancela pedido (apenas se estiver pending)
            if ($order['status'] !== 'pending') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'cannot_cancel_non_pending']);
                exit;
            }
            
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE vip_orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?")
                    ->execute([$order_id]);
                
                log_audit($pdo, $order_id, 'cancel', $order['status'], 'cancelled', 
                         'Cancelado manualmente pelo admin', $admin_username);
                
                $pdo->commit();
                $result['message'] = 'Pedido cancelado com sucesso';
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'resend_whatsapp':
            // Reenvia mensagem de WhatsApp
            if (empty($order['telefone'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'no_phone_number']);
                exit;
            }
            
            // Monta mensagem
            $nome = $order['nome'] ?? 'Cliente';
            $duracao = (int)($order['duracao_min'] ?? 1440);
            $valor = format_money_br($order['valor_centavos'] ?? 0);
            
            $msg_template = envv('PROMO_VIP_MESSAGE', 
                "🎉 Olá {nome}! Seu acesso VIP de {duracao} minutos foi ativado! Valor: {valor}. Aproveite!");
            
            $message = str_replace(
                ['{nome}', '{duracao}', '{valor}'],
                [$nome, $duracao, $valor],
                $msg_template
            );
            
            try {
                $phone_normalized = norm_msisdn_br($order['telefone']);
                $sent = promo_api_send($phone_normalized, $message);
                
                if ($sent) {
                    log_audit($pdo, $order_id, 'resend_whatsapp', null, null,
                             "WhatsApp reenviado para {$order['telefone']}", $admin_username);
                    $result['message'] = 'WhatsApp enviado com sucesso';
                } else {
                    throw new Exception('Falha ao enviar WhatsApp');
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'whatsapp_send_failed', 'details' => $e->getMessage()]);
                exit;
            }
            break;

        case 'refund':
            if (!table_has_column($pdo, 'vip_orders', 'refund_status')) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'refund_not_supported']);
                exit;
            }

            if (empty($order['mp_payment_id'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'missing_payment_id']);
                exit;
            }

            if (!in_array($order['status'], ['paid', 'refunded'], true)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'invalid_status_for_refund']);
                exit;
            }

            $amountInput = isset($input['amount_centavos']) ? (int) $input['amount_centavos'] : null;
            $orderAmount = (int) ($order['valor_centavos'] ?? 0);
            if ($orderAmount <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'invalid_order_amount']);
                exit;
            }

            $currentRefunded = (int) ($order['refund_amount_centavos'] ?? 0);
            if ($currentRefunded >= $orderAmount) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'already_refunded']);
                exit;
            }

            $refundAmount = $amountInput !== null ? $amountInput : ($orderAmount - $currentRefunded);
            if ($refundAmount <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'refund_amount_invalid']);
                exit;
            }
            if ($refundAmount > ($orderAmount - $currentRefunded)) {
                $refundAmount = $orderAmount - $currentRefunded;
            }

            try {
                $refundResponse = mp_refund_payment($order['mp_payment_id'], $refundAmount);
            } catch (Throwable $e) {
                error_log('refund error: ' . $e->getMessage());
                http_response_code(502);
                echo json_encode(['ok' => false, 'error' => 'refund_failed', 'details' => $e->getMessage()]);
                exit;
            }

            $refundId = isset($refundResponse['id']) ? (string) $refundResponse['id'] : null;
            $totalRefunded = $currentRefunded + $refundAmount;
            $isFullRefund = $totalRefunded >= $orderAmount;
            $newStatus = $isFullRefund ? 'refunded' : $order['status'];
            $refundStatus = $isFullRefund ? 'processed' : 'partial';

            $note = 'Reembolso via dashboard';
            if ($refundAmount < $orderAmount) {
                $note .= ' (parcial)';
            }

            $hasRefundNotes = table_has_column($pdo, 'vip_orders', 'refund_notes');

            $pdo->beginTransaction();
            try {
                if ($hasRefundNotes) {
                    $stmtUpd = $pdo->prepare("UPDATE vip_orders SET status = ?, refund_status = ?, refund_amount_centavos = ?, refunded_at = NOW(), mp_refund_id = ?, refund_notes = ?, updated_at = NOW() WHERE id = ?");
                    $stmtUpd->execute([
                        $newStatus,
                        $refundStatus,
                        $totalRefunded,
                        $refundId,
                        $note,
                        $order_id,
                    ]);
                } else {
                    $stmtUpd = $pdo->prepare("UPDATE vip_orders SET status = ?, refund_status = ?, refund_amount_centavos = ?, refunded_at = NOW(), mp_refund_id = ?, updated_at = NOW() WHERE id = ?");
                    $stmtUpd->execute([
                        $newStatus,
                        $refundStatus,
                        $totalRefunded,
                        $refundId,
                        $order_id,
                    ]);
                }

                log_audit($pdo, $order_id, 'refund', $order['status'], $newStatus, $note . ' · Valor: ' . format_money_br($refundAmount), $admin_username);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            $result['message'] = $isFullRefund
                ? 'Reembolso total processado com sucesso.'
                : 'Reembolso parcial processado com sucesso.';
            $result['refund'] = [
                'id' => $refundId,
                'amount_centavos' => $refundAmount,
                'amount_br' => format_money_br($refundAmount),
                'total_refunded_centavos' => $totalRefunded,
                'status' => $refundStatus,
            ];
            break;
            
        case 'view_details':
            // Retorna detalhes completos do pedido
            $result['order'] = $order;
            log_audit($pdo, $order_id, 'view_details', null, null,
                     'Detalhes visualizados', $admin_username);
            break;
            
        case 'mark_applied':
            // Marca como VIP aplicado manualmente (caso tenha falhado automaticamente)
            if ($order['vip_applied_at']) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'already_applied']);
                exit;
            }
            
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE vip_orders SET vip_applied_at = NOW(), updated_at = NOW() WHERE id = ?")
                    ->execute([$order_id]);
                
                log_audit($pdo, $order_id, 'mark_applied', null, null,
                         'Marcado como aplicado manualmente', $admin_username);
                
                $pdo->commit();
                $result['message'] = 'Pedido marcado como aplicado';
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'unknown_action']);
            exit;
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Throwable $e) {
    error_log("vendas_action error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error', 'details' => $e->getMessage()]);
}

function norm_msisdn_br($tel){
  $d = only_digits($tel);
  if ($d === '') return '';
  if (strpos($d,'55') !== 0) $d = '55'.$d;
  return $d;
}
