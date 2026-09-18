<?php

require_once __DIR__ . '/../_boot.php';
header('Content-Type: application/json; charset=utf-8');

function v3_json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') v3_json_error('method_not_allowed', 405);
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) v3_json_error('invalid_payload');
if (!csrf_check((string) ($input['csrf'] ?? ''))) v3_json_error('Sessão expirada. Recarregue a página.', 403);

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $partner = v3_resolve_partner($pdo);
    if (!$partner) v3_json_error('Estabelecimento não encontrado ou Portal V3 inativo.', 404);
    if (!fs_portal_config_has_sales(fs_portal_config_for_partner($pdo,$partner))) v3_json_error('Este estabelecimento não oferece acesso pago.',403);
    $recoveredOrder = v3_recover_access($pdo, $partner);
    if ($recoveredOrder) {
        echo json_encode([
            'ok' => true,
            'resumed' => true,
            'order' => (string) $recoveredOrder['public_id'],
            'status' => (string) $recoveredOrder['status'],
            'granted' => $recoveredOrder['status'] === 'paid',
            'success_url' => 'index.php?' . v3_hotspot_query($partner),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $selection = $_SESSION['portal_v3_selection'] ?? [];
    if ((int) ($selection['partner_id'] ?? 0) !== (int) $partner['id']) throw new RuntimeException('Seleção de plano inválida.');
    if (fs_partner_hotspots_schema_ready($pdo) && (int)($selection['hotspot_id'] ?? 0) !== (int)(fs_partner_hotspot_id($partner) ?? 0)) throw new RuntimeException('A instalação da seleção foi alterada. Escolha o acesso novamente.');
    $plan = fs_guest_plan($pdo, $partner, (string) ($selection['source'] ?? ''), (int) ($selection['id'] ?? 0));
    if (!$plan) throw new RuntimeException('A opção escolhida não está disponível.');

    $paymentSubmission = isset($input['form_data']) && is_array($input['form_data']) ? $input['form_data'] : [];
    // O Payment Brick entrega { paymentMethod/selectedPaymentMethod, formData }.
    // Mantemos compatibilidade com clientes que já enviam somente o formData.
    $formData = isset($paymentSubmission['formData']) && is_array($paymentSubmission['formData'])
        ? $paymentSubmission['formData']
        : $paymentSubmission;
    $method = strtolower(trim((string) ($formData['payment_method_id'] ?? '')));
    $selectedMethod = strtolower(trim((string) (
        $paymentSubmission['selectedPaymentMethod']
        ?? $paymentSubmission['paymentMethod']
        ?? $input['selected_payment_method']
        ?? ''
    )));
    if ($method === '' && in_array($selectedMethod, ['pix', 'bank_transfer'], true)) {
        $method = 'pix';
        $formData['payment_method_id'] = 'pix';
    }
    if ($method === '') throw new RuntimeException('Selecione uma forma de pagamento.');
    // O Mercado Pago exige payer.email inclusive no Pix. Para preservar o
    // Portal V3 anônimo, nunca solicitamos esse dado do visitante: usamos o
    // identificador técnico aleatório da sessão e não coletamos telefone.
    if ($method === 'pix') {
        $payer = isset($formData['payer']) && is_array($formData['payer']) ? $formData['payer'] : [];
        $payer['email'] = v3_anonymous_payer_email();
        $formData['payer'] = $payer;
    }

    $now = time();
    $rate = isset($_SESSION['portal_v3_checkout_rate']) && is_array($_SESSION['portal_v3_checkout_rate']) ? $_SESSION['portal_v3_checkout_rate'] : [];
    if (($now - (int)($rate['window_started'] ?? 0)) >= 600) {
        $rate = ['window_started' => $now, 'count' => 0, 'last_at' => 0];
    }
    if (($now - (int)($rate['last_at'] ?? 0)) < 2 || (int)($rate['count'] ?? 0) >= 8) {
        v3_json_error('Aguarde alguns instantes antes de tentar novamente.', 429);
    }
    $device = v3_device_context();
    $deviceIp = filter_var((string)($device['ip'] ?? ''), FILTER_VALIDATE_IP) ? (string)$device['ip'] : '';
    $deviceMac = fs_guest_normalize_mac((string)($device['mac'] ?? ''));
    if ($deviceIp !== '') {
        $stRate = $pdo->prepare('SELECT COUNT(*) FROM guest_orders WHERE partner_id=? AND device_ip=? AND created_at >= DATE_SUB(NOW(),INTERVAL 10 MINUTE)');
        $stRate->execute([(int)$partner['id'], $deviceIp]);
        if ((int)$stRate->fetchColumn() >= 12) v3_json_error('Limite temporário de tentativas atingido. Tente novamente mais tarde.', 429);
    }
    if ($deviceMac !== '') {
        $stRate = $pdo->prepare('SELECT COUNT(*) FROM guest_orders WHERE partner_id=? AND device_mac=? AND created_at >= DATE_SUB(NOW(),INTERVAL 10 MINUTE)');
        $stRate->execute([(int)$partner['id'], $deviceMac]);
        if ((int)$stRate->fetchColumn() >= 6) v3_json_error('Este aparelho realizou muitas tentativas de pagamento. Aguarde alguns minutos.', 429);
    }
    $rate['count'] = (int)($rate['count'] ?? 0) + 1;
    $rate['last_at'] = $now;
    $_SESSION['portal_v3_checkout_rate'] = $rate;

    $wallet = fs_wallet_for_partner($pdo, $partner, true);
    fs_wallet_assert_usable($wallet);
    $walletId = isset($wallet['id']) ? (int) $wallet['id'] : null;
    $agreement=fs_monetization_current_agreement($pdo,(int)$partner['id']);
    $monetization=[];
    if(($wallet['source']??'')==='marketplace'&&$agreement){
        $monetization=['marketplace_account_id'=>(int)$wallet['marketplace_account_id'],'monetization_agreement_id'=>(int)$agreement['id'],'firespot_fee_cents'=>fs_monetization_access_fee($agreement,(int)$plan['price_cents']),'fee_type'=>$agreement['access_fee_type'],'fee_value'=>(int)$agreement['access_fee_value']];
    }
    if(!empty($input['link_account'])){
        if(!fs_subscriber_feature_enabled($pdo,'subscriber_authenticated_purchase_enabled',false))throw new RuntimeException('O vínculo de compras ainda não está disponível.');
        $account=fs_subscriber_current($pdo);$subscriberDevice=$account?fs_subscriber_device_current($pdo,array_merge($device,['partner_id'=>(int)$partner['id'],'hotspot_id'=>(int)(fs_partner_hotspot_id($partner)??0)])):null;
        if(!$account||!$subscriberDevice||(int)$subscriberDevice['account_id']!==(int)$account['id'])throw new RuntimeException('Entre na Minha Conta e autorize este aparelho antes de vincular a compra.');
        $monetization['subscriber_account_id']=(int)$account['id'];$monetization['subscriber_device_id']=(int)$subscriberDevice['id'];
    }
    $order = fs_guest_create_order($pdo, $partner, $plan, $device, $walletId, $monetization);
    if(!empty($monetization['subscriber_account_id']))fs_subscriber_audit($pdo,(int)$monetization['subscriber_account_id'],'subscriber',(int)$monetization['subscriber_account_id'],'purchase.linked_with_consent','guest_order',(int)$order['id'],['partner_id'=>(int)$partner['id'],'hotspot_id'=>(int)(fs_partner_hotspot_id($partner)??0)]);
    if ($method === 'pix') {
        $pdo->prepare('UPDATE guest_orders SET payment_expires_at=DATE_ADD(NOW(),INTERVAL 24 HOUR),updated_at=NOW() WHERE id=?')
            ->execute([(int)$order['id']]);
        $stExpiry = $pdo->prepare('SELECT payment_expires_at FROM guest_orders WHERE id=? LIMIT 1');
        $stExpiry->execute([(int)$order['id']]);
        $order['payment_expires_at'] = (string)$stExpiry->fetchColumn();
    }
    v3_remember_order($order, $order['order_token']);

    $notificationUrl = v3_public_base_url($pdo) . '/portal-v3/api/webhook.php?order=' . rawurlencode($order['public_id']) . '&sig=' . rawurlencode(fs_guest_webhook_signature($order['public_id'])) . '&source_news=webhooks';
    $payment = fs_payment_create($wallet, $order, $formData, $notificationUrl);
    if (($payment['id'] ?? '') === '') throw new RuntimeException('O provedor não retornou o identificador do pagamento.');
    v3_remember_pending_pix((string)$order['public_id'],$payment);

    $st = $pdo->prepare('SELECT * FROM guest_orders WHERE id=? LIMIT 1');
    $st->execute([(int) $order['id']]);
    $storedOrder = $st->fetch(PDO::FETCH_ASSOC);
    $storedOrder = fs_guest_mark_from_provider($pdo, $storedOrder, $payment['raw'] ?? []);
    $granted = false;
    if (($storedOrder['status'] ?? '') === 'paid') {
        fs_guest_finalize_paid_access($pdo, (int) $storedOrder['id']);
        v3_forget_pending_pix((string)$order['public_id']);
        $granted = true;
    }

    if (in_array((string)($storedOrder['status'] ?? ''), ['payment_failed','cancelled','refunded'], true)) {
        v3_forget_pending_pix((string)$order['public_id']);
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => 'O pagamento não foi aprovado. Verifique os dados ou escolha outra forma de pagamento.',
            'order' => $order['public_id'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'order' => $order['public_id'],
        'status' => $storedOrder['status'] ?? 'pending',
        'granted' => $granted,
        'qr_code' => $payment['qr_code'] ?? '',
        'qr_code_base64' => $payment['qr_code_base64'] ?? '',
        'ticket_url' => $payment['ticket_url'] ?? '',
        'success_url' => 'sucesso.php?order=' . rawurlencode($order['public_id']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    v3_json_error($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('[portal-v3 checkout] ' . $e->getMessage());
    v3_json_error('Não foi possível iniciar o pagamento. Tente novamente.', 500);
}
