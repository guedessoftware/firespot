<?php

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/payment_wallets.php';

function fs_payment_local_timezone(): DateTimeZone
{
    $name = trim((string)env('APP_TZ', env('RADIUS_TIMEZONE', 'America/Manaus')));
    try {
        return new DateTimeZone($name !== '' ? $name : 'America/Manaus');
    } catch (Throwable $e) {
        return new DateTimeZone('America/Manaus');
    }
}

/** Formato aceito pelo Mercado Pago: ISO 8601 com milissegundos e fuso. */
function fs_payment_expiration_iso(DateTimeInterface $expiration): string
{
    return $expiration->format('Y-m-d\TH:i:s.000P');
}

function fs_payment_api_base(array $wallet): string
{
    if (($wallet['provider'] ?? '') === 'mercadopago') {
        return rtrim((string) env('MERCADOPAGO_API_BASE', 'https://api.mercadopago.com'), '/');
    }
    throw new RuntimeException('Provedor de pagamento não suportado.');
}

function fs_payment_identity_api_base(): string
{
    return rtrim((string)env('MERCADOPAGO_IDENTITY_API_BASE', 'https://api.mercadolibre.com'), '/');
}

function fs_payment_http_url(array $wallet, string $method, string $url, ?array $body = null, ?string $idempotencyKey = null): array
{
    fs_wallet_assert_usable($wallet);
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL não está habilitada.');
    }

    $headers = [
        'Authorization: Bearer ' . $wallet['access_token'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Falha ao comunicar com o provedor: ' . $error);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($raw, true);
    if ($status >= 300) {
        $message = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? 'Pagamento recusado pelo provedor.') : 'Pagamento recusado pelo provedor.';
        throw new RuntimeException('Mercado Pago HTTP ' . $status . ': ' . $message);
    }
    return is_array($json) ? $json : [];
}

function fs_payment_http(array $wallet, string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
{
    return fs_payment_http_url($wallet, $method, fs_payment_api_base($wallet) . $path, $body, $idempotencyKey);
}

/**
 * Valida o token em uma consulta autenticada e somente leitura no endpoint de
 * identidade documentado pelo Mercado Pago. Nenhuma carteira é persistida por
 * esta função.
 */
function fs_payment_validate_access_token(array $wallet): array
{
    try {
        return fs_payment_http_url($wallet, 'GET', fs_payment_identity_api_base() . '/users/me');
    } catch (RuntimeException $e) {
        if (preg_match('/^Mercado Pago HTTP (401|403):/', $e->getMessage(), $match)) {
            $environment = (string)($wallet['environment'] ?? 'production') === 'sandbox' ? 'Testes' : 'Produção';
            throw new RuntimeException(
                'O Mercado Pago recusou o Access Token (HTTP ' . $match[1] . '). ' .
                'Use o Access Token — não a Public Key, o Client ID ou o Client Secret — da mesma aplicação e do ambiente ' . $environment . '. ' .
                ($environment === 'Produção' ? 'Confirme também que as credenciais de produção foram ativadas. ' : '') .
                'Nenhuma credencial foi alterada.'
            );
        }
        throw $e;
    }
}

/**
 * Confirma, sem criar cobrança, que a credencial identifica a conta e pode
 * consultar pagamentos. A segunda verificação evita persistir um token que
 * autentica, mas não possui a capacidade usada pela conciliação.
 *
 * @return array{identity:array<string,mixed>,payments_query:bool}
 */
function fs_payment_validate_wallet_capabilities(array $wallet): array
{
    $identity = fs_payment_validate_access_token($wallet);
    try {
        fs_payment_http($wallet,'GET','/v1/payments/search?sort=date_created&criteria=desc&limit=1&offset=0');
    } catch (RuntimeException $error) {
        if (preg_match('/^Mercado Pago HTTP (401|403):/',$error->getMessage(),$match)) {
            throw new RuntimeException(
                'O Mercado Pago autenticou a credencial, mas recusou a consulta de pagamentos (HTTP ' . $match[1] . '). ' .
                'Confirme que o Access Token pertence à aplicação e à conta recebedora corretas. Nenhuma credencial foi alterada.'
            );
        }
        throw $error;
    }
    return ['identity'=>$identity,'payments_query'=>true];
}

/**
 * Valida a assinatura HMAC enviada pelo Mercado Pago em x-signature.
 * O formato segue o SDK oficial: id:<data.id>;request-id:<id>;ts:<ts>;.
 * A funcao nao decide a compatibilidade de pedidos antigos; o endpoint usa
 * fs_payment_webhook_signature_required() para essa politica de rollout.
 *
 * @return array{configured:bool,valid:bool,reason:string,request_id:string}
 */
function fs_payment_webhook_verify(
    array $wallet,
    ?string $xSignature,
    ?string $xRequestId,
    ?string $dataId,
    ?int $now = null,
    ?int $toleranceSeconds = null
): array {
    $secret = trim((string)($wallet['webhook_secret'] ?? ''));
    $requestId = trim((string)$xRequestId);
    if ($secret === '') {
        return ['configured'=>false,'valid'=>false,'reason'=>'secret_not_configured','request_id'=>$requestId];
    }

    $header = trim((string)$xSignature);
    if ($header === '') {
        return ['configured'=>true,'valid'=>false,'reason'=>'signature_missing','request_id'=>$requestId];
    }
    if (strlen($header) > 1024 || strlen($requestId) > 255) {
        return ['configured'=>true,'valid'=>false,'reason'=>'header_malformed','request_id'=>''];
    }

    $timestamp = null;
    $received = null;
    foreach (explode(',', $header) as $part) {
        $pair = explode('=', $part, 2);
        if (count($pair) !== 2) continue;
        $key = strtolower(trim($pair[0]));
        $value = trim($pair[1]);
        if ($key === 'ts') $timestamp = $value;
        elseif ($key === 'v1') $received = strtolower($value);
    }
    if ($timestamp === null || !ctype_digit($timestamp)
        || $received === null || !preg_match('/^[a-f0-9]{64}$/D',$received)) {
        return ['configured'=>true,'valid'=>false,'reason'=>'signature_malformed','request_id'=>$requestId];
    }

    $parts = [];
    $normalizedDataId = trim((string)$dataId);
    if ($normalizedDataId !== '') $parts[] = 'id:' . $normalizedDataId;
    if ($requestId !== '') $parts[] = 'request-id:' . $requestId;
    $parts[] = 'ts:' . $timestamp;
    $manifest = implode(';',$parts) . ';';
    $expected = hash_hmac('sha256',$manifest,$secret);
    if (!hash_equals($expected,$received)) {
        return ['configured'=>true,'valid'=>false,'reason'=>'signature_mismatch','request_id'=>$requestId];
    }

    if ($toleranceSeconds === null) {
        $configuredTolerance = trim((string)env('MERCADOPAGO_WEBHOOK_TOLERANCE_SECONDS',''));
        $toleranceSeconds = $configuredTolerance === '' ? 0 : (int)$configuredTolerance;
    }
    $toleranceSeconds = max(0,min(604800,$toleranceSeconds));
    if ($toleranceSeconds > 0 && abs(($now ?? time())-(int)$timestamp) > $toleranceSeconds) {
        return ['configured'=>true,'valid'=>false,'reason'=>'timestamp_out_of_tolerance','request_id'=>$requestId];
    }
    return ['configured'=>true,'valid'=>true,'reason'=>'ok','request_id'=>$requestId];
}

/** Pedidos anteriores ao cadastro do segredo preservam o callback HMAC legado. */
function fs_payment_webhook_signature_required(array $wallet, array $order): bool
{
    $configuredAt = trim((string)($wallet['webhook_secret_configured_at'] ?? ''));
    if ($configuredAt === '') return false;
    $createdAt = trim((string)($order['created_at'] ?? ''));
    if ($createdAt === '') return true;
    try {
        $zone = fs_payment_local_timezone();
        $cutover = new DateTimeImmutable($configuredAt,$zone);
        $created = new DateTimeImmutable($createdAt,$zone);
        return $created->getTimestamp() >= $cutover->getTimestamp();
    } catch (Throwable $e) {
        // Configuracao de corte invalida nao pode rebaixar silenciosamente a seguranca.
        return true;
    }
}

function fs_payment_webhook_secret_assert(string $secret): void
{
    $length = strlen(trim($secret));
    if ($length < 16 || $length > 512) {
        throw new InvalidArgumentException('A assinatura secreta do webhook parece inválida.');
    }
}

/**
 * Exercita localmente o mesmo manifesto HMAC aceito pelo endpoint. Não envia
 * dados ao provedor e não cria pedido ou cobrança.
 *
 * @return array{configured:bool,valid:bool,reason:string,request_id:string}
 */
function fs_payment_webhook_self_test(array $wallet): array
{
    $secret=trim((string)($wallet['webhook_secret']??''));
    fs_payment_webhook_secret_assert($secret);
    $timestamp=time();
    $dataId='firespot-self-test-' . bin2hex(random_bytes(8));
    $requestId=bin2hex(random_bytes(16));
    $manifest='id:'.$dataId.';request-id:'.$requestId.';ts:'.$timestamp.';';
    $signature=hash_hmac('sha256',$manifest,$secret);
    $result=fs_payment_webhook_verify($wallet,'ts='.$timestamp.',v1='.$signature,$requestId,$dataId,$timestamp,60);
    if (!$result['valid']) throw new RuntimeException('O teste local da assinatura do webhook falhou. Nenhuma credencial foi alterada.');
    return $result;
}

function fs_payment_create(array $wallet, array $order, array $formData, string $notificationUrl): array
{
    $method = strtolower(trim((string) ($formData['payment_method_id'] ?? '')));
    $payer = isset($formData['payer']) && is_array($formData['payer']) ? $formData['payer'] : [];
    $email = trim((string) ($payer['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('O provedor de pagamento exige um e-mail válido.');
    }

    $payload = [
        'transaction_amount' => round(((int) $order['amount_cents']) / 100, 2),
        'description' => 'Acesso Wi-Fi - ' . (string) $order['plan_name'],
        'payment_method_id' => $method,
        'external_reference' => (string) $order['external_ref'],
        'notification_url' => $notificationUrl,
        'payer' => ['email' => $email],
        'metadata' => [
            'guest_order_id' => (int) $order['id'],
            'partner_id' => (int) $order['partner_id'],
        ],
    ];
    if (($wallet['source'] ?? '') === 'marketplace' && (int)($order['firespot_fee_cents'] ?? 0) > 0) {
        $payload['application_fee'] = round(((int)$order['firespot_fee_cents']) / 100, 2);
        $payload['metadata']['monetization_agreement_id'] = (int)($order['monetization_agreement_id'] ?? 0);
    }

    // O Pix deve vencer no mesmo instante conhecido pelo FireSpot. Sem este
    // campo, o portal dependeria apenas do prazo implícito do provedor e não
    // conseguiria retirar o QR Code com precisão.
    if ($method === 'pix') {
        $expiresAt = trim((string)($order['payment_expires_at'] ?? ''));
        try {
            $expiration = $expiresAt !== ''
                ? new DateTimeImmutable($expiresAt, fs_payment_local_timezone())
                : new DateTimeImmutable('+24 hours', fs_payment_local_timezone());
        } catch (Throwable $e) {
            $expiration = new DateTimeImmutable('+24 hours', fs_payment_local_timezone());
        }
        if ($expiration->getTimestamp() <= time()) {
            $expiration = new DateTimeImmutable('+24 hours', fs_payment_local_timezone());
        }
        $payload['date_of_expiration'] = fs_payment_expiration_iso($expiration);
    }

    $identification = isset($payer['identification']) && is_array($payer['identification']) ? $payer['identification'] : [];
    $idType = trim((string) ($identification['type'] ?? ''));
    $idNumber = preg_replace('/\D+/', '', (string) ($identification['number'] ?? ''));
    if ($idType !== '' && $idNumber !== '') {
        $payload['payer']['identification'] = ['type' => $idType, 'number' => $idNumber];
    }

    if ($method !== 'pix') {
        $token = trim((string) ($formData['token'] ?? ''));
        if ($token === '') throw new InvalidArgumentException('Token do cartão ausente.');
        $payload['token'] = $token;
        $payload['installments'] = max(1, (int) ($formData['installments'] ?? 1));
        $issuerId = trim((string) ($formData['issuer_id'] ?? ''));
        if ($issuerId !== '') $payload['issuer_id'] = $issuerId;
    }

    $response = fs_payment_http(
        $wallet,
        'POST',
        '/v1/payments',
        $payload,
        'guest-' . (string) $order['public_id']
    );
    $transaction = $response['point_of_interaction']['transaction_data'] ?? [];
    return [
        'id' => isset($response['id']) ? (string) $response['id'] : '',
        'status' => strtolower((string) ($response['status'] ?? 'pending')),
        'status_detail' => (string) ($response['status_detail'] ?? ''),
        'payment_method_id' => (string) ($response['payment_method_id'] ?? $method),
        'qr_code' => (string) ($transaction['qr_code'] ?? ''),
        'qr_code_base64' => !empty($transaction['qr_code_base64']) ? 'data:image/png;base64,' . $transaction['qr_code_base64'] : '',
        'ticket_url' => (string) ($transaction['ticket_url'] ?? ''),
        'date_of_expiration' => (string) ($response['date_of_expiration'] ?? ($payload['date_of_expiration'] ?? '')),
        'raw' => $response,
    ];
}

function fs_payment_get(array $wallet, string $paymentId): array
{
    if (!preg_match('/^[0-9]{1,32}$/D',$paymentId)) throw new InvalidArgumentException('ID do pagamento inválido.');
    return fs_payment_http($wallet, 'GET', '/v1/payments/' . rawurlencode($paymentId));
}

function fs_payment_pix_display_payload(array $payment): ?array
{
    $transaction = $payment['point_of_interaction']['transaction_data'] ?? null;
    if (!is_array($transaction)) $transaction = $payment;

    $code = trim((string)($transaction['qr_code'] ?? ''));
    if (strlen($code) > 8192) $code = '';

    $encoded = trim((string)($transaction['qr_code_base64'] ?? ''));
    if (str_starts_with($encoded,'data:image/png;base64,')) {
        $encoded = substr($encoded,22);
    }
    $encoded = preg_replace('/\s+/', '', $encoded) ?? '';
    $image = '';
    if ($encoded !== '' && strlen($encoded) <= 1500000 && preg_match('/^[A-Za-z0-9+\/=]+$/D',$encoded)) {
        $decoded = base64_decode($encoded,true);
        if (is_string($decoded) && str_starts_with($decoded,"\x89PNG\r\n\x1a\n")) {
            $image = 'data:image/png;base64,' . $encoded;
        }
    }
    if ($code === '' && $image === '') return null;
    return ['qr_code'=>$code,'qr_code_base64'=>$image];
}

function fs_payment_create_pix(array $wallet, array $order, string $description, string $payerEmail, string $notificationUrl, string $idempotencyPrefix='monetization'): array
{
    if(!filter_var($payerEmail,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Informe um e-mail válido para a cobrança.');
    $payload=['transaction_amount'=>round(((int)$order['amount_cents'])/100,2),'description'=>substr($description,0,200),'payment_method_id'=>'pix','external_reference'=>(string)$order['external_ref'],'notification_url'=>$notificationUrl,'payer'=>['email'=>$payerEmail],'metadata'=>['monetization_order_id'=>(int)$order['id'],'order_type'=>(string)$order['order_type']]];
    $response=fs_payment_http($wallet,'POST','/v1/payments',$payload,$idempotencyPrefix.'-'.(string)$order['public_id']);
    $transaction=$response['point_of_interaction']['transaction_data']??[];
    return ['id'=>(string)($response['id']??''),'status'=>(string)($response['status']??'pending'),'status_detail'=>(string)($response['status_detail']??''),'qr_code'=>(string)($transaction['qr_code']??''),'qr_code_base64'=>!empty($transaction['qr_code_base64'])?'data:image/png;base64,'.(string)$transaction['qr_code_base64']:'','ticket_url'=>(string)($transaction['ticket_url']??''),'raw'=>$response];
}

function fs_payment_normalize_status(string $providerStatus): string
{
    $providerStatus = strtolower(trim($providerStatus));
    if ($providerStatus === 'approved') return 'paid';
    if (in_array($providerStatus,['refunded','charged_back'],true)) return 'refunded';
    if (in_array($providerStatus,['cancelled','canceled','expired'],true)) return 'cancelled';
    if ($providerStatus === 'rejected') return 'payment_failed';
    return 'pending';
}
