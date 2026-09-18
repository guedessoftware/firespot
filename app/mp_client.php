<?php
// Cliente MP PIX compatível com PHP 7.4 (sem type hints/returns)

$__env = __DIR__ . '/env.php';
if (is_file($__env)) require_once $__env;

if (!function_exists('env')) {
  function env($key, $default = null) {
    $v = getenv($key);
    if ($v !== false) return $v;
    if (isset($_ENV[$key]))    return $_ENV[$key];
    if (isset($_SERVER[$key])) return $_SERVER[$key];
    return $default;
  }
}

// (nota) notification_url é configurada dentro de mp_create_pix_payment();
// removido bloco fora de função que poderia causar notices.


function mp_api_base() {
  $base = env('MERCADOPAGO_API_BASE', 'https://api.mercadopago.com');
  return $base ? $base : 'https://api.mercadopago.com';
}
function mp_token() {
  $t = env('MERCADOPAGO_ACCESS_TOKEN');
  if (!$t && defined('MP_ACCESS_TOKEN') && MP_ACCESS_TOKEN) $t = MP_ACCESS_TOKEN;
  if (!$t) throw new RuntimeException('MERCADOPAGO_ACCESS_TOKEN ausente: defina no .env ou MP_ACCESS_TOKEN em config.php');
  return $t;
}
function mp_public_key() {
  $pk = env('MERCADOPAGO_PUBLIC_KEY');
  if (!$pk) $pk = env('MP_PUBLIC_KEY');
  if (!$pk && defined('MP_PUBLIC_KEY')) $pk = MP_PUBLIC_KEY;
  return $pk ? $pk : '';
}
function mp_http_json($method, $url, $body = null) {
  if (!function_exists('curl_init')) throw new RuntimeException('Extensão cURL do PHP não está habilitada.');
  $ch = curl_init($url);
  $headers = array(
    'Authorization: Bearer ' . mp_token(),
    'Content-Type: application/json'
  );
  $opts = array(
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
  );
  if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
  curl_setopt_array($ch, $opts);
  $res = curl_exec($ch);
  if ($res === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException('curl_error: '.$err); }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $j = json_decode($res, true);
  if ($code >= 300) {
    $msg = (is_array($j) && isset($j['message'])) ? $j['message'] : $res;
    throw new RuntimeException('mp_http_'.$code.': '.$msg);
  }
  return is_array($j) ? $j : array();
}

/** Cria pagamento PIX */
if (!function_exists('mp_create_pix_payment')) {
  function mp_create_pix_payment($amount_cents, $external_ref, $description, $payer, $metadata = array(), $expires_at_iso = null) {
    $amount = round($amount_cents / 100, 2);
    $payload = array(
      'transaction_amount' => $amount,
      'description'        => $description,
      'payment_method_id'  => 'pix',
      'external_reference' => $external_ref,
      'payer' => array(
        'first_name'     => isset($payer['first_name']) ? $payer['first_name'] : null,
        'email'          => isset($payer['email']) ? $payer['email'] : null,
        'identification' => array(
          'type'   => 'CPF',
          'number' => isset($payer['cpf']) ? $payer['cpf'] : null,
        ),
      ),
    );
    if (!empty($metadata)) $payload['metadata'] = $metadata;
    if (!empty($payer['phone_area']) || !empty($payer['phone_number'])) {
      $payload['payer']['phone'] = array(
        'area_code' => isset($payer['phone_area']) ? $payer['phone_area'] : null,
        'number'    => isset($payer['phone_number']) ? $payer['phone_number'] : null,
      );
    }
    if ($expires_at_iso) {
      $payload['date_of_expiration'] = $expires_at_iso;
    }
    $notif = env('MP_NOTIFICATION_URL');
    if (!empty($notif)) $payload['notification_url'] = $notif;

    $j = mp_http_json('POST', mp_api_base().'/v1/payments', $payload);
    $poi = array();
    if (isset($j['point_of_interaction']['transaction_data'])) {
      $poi = $j['point_of_interaction']['transaction_data'];
    }
    $qr_b64 = isset($poi['qr_code_base64']) && $poi['qr_code_base64'] ? 'data:image/png;base64,'.$poi['qr_code_base64'] : null;
    return array(
      'id'             => isset($j['id']) ? $j['id'] : null,
      'status'         => isset($j['status']) ? $j['status'] : null,
      'qr_code'        => isset($poi['qr_code']) ? $poi['qr_code'] : null,
      'qr_code_base64' => $qr_b64,
      'ticket_url'     => isset($poi['ticket_url']) ? $poi['ticket_url'] : null,
      'raw'            => $j,
    );
  }
}
if (!function_exists('mp_get_payment')) {
  function mp_get_payment($payment_id) {
    return mp_http_json('GET', mp_api_base().'/v1/payments/'.urlencode($payment_id));
  }
}
if (!function_exists('mp_create_card_payment')) {
  function mp_create_card_payment($amount_cents, $external_ref, $description, $payer, $card)
  {
    $amount = round($amount_cents / 100, 2);
    $token = isset($card['token']) ? trim($card['token']) : '';
    if ($token === '') {
      throw new InvalidArgumentException('Token do cartão ausente');
    }

    $methodId = isset($card['payment_method_id']) ? trim($card['payment_method_id']) : '';
    if ($methodId === '') {
      throw new InvalidArgumentException('Método de pagamento inválido');
    }

    $installments = isset($card['installments']) ? (int) $card['installments'] : 1;
    if ($installments <= 0) {
      $installments = 1;
    }

    $issuerData = isset($card['issuer']) && is_array($card['issuer']) ? $card['issuer'] : [];
    $issuerId = null;
    $issuerName = null;
    if (isset($card['issuer_id']) && $card['issuer_id'] !== '') {
      $issuerId = $card['issuer_id'];
    }
    if ($issuerId === null && isset($issuerData['id']) && $issuerData['id'] !== '') {
      $issuerId = $issuerData['id'];
    }
    if (isset($issuerData['name']) && $issuerData['name'] !== '') {
      $issuerName = $issuerData['name'];
    }

    $payload = array(
      'transaction_amount' => $amount,
      'token'              => $token,
      'description'        => $description,
      'installments'       => $installments,
      'payment_method_id'  => $methodId,
      'external_reference' => $external_ref,
      'payer' => array(
        'first_name'     => isset($payer['first_name']) ? $payer['first_name'] : null,
        'email'          => isset($payer['email']) ? $payer['email'] : null,
        'identification' => array(
          'type'   => 'CPF',
          'number' => isset($payer['cpf']) ? $payer['cpf'] : null,
        ),
      ),
    );

    if ($issuerId !== null) {
      $payload['issuer_id'] = $issuerId;
    }
    if ($issuerName !== null) {
      $payload['issuer'] = array('name' => $issuerName);
    }

    if (!empty($payer['phone_area']) || !empty($payer['phone_number'])) {
      $payload['payer']['phone'] = array(
        'area_code' => isset($payer['phone_area']) ? $payer['phone_area'] : null,
        'number'    => isset($payer['phone_number']) ? $payer['phone_number'] : null,
      );
    }

    if (!empty($card['capture']) && $card['capture'] === false) {
      $payload['capture'] = false;
    }

    if (isset($card['statement_descriptor']) && $card['statement_descriptor'] !== '') {
      $payload['statement_descriptor'] = $card['statement_descriptor'];
    }

    if (!empty($card['metadata']) && is_array($card['metadata'])) {
      $payload['metadata'] = $card['metadata'];
    }

    $notif = env('MP_NOTIFICATION_URL');
    if (!empty($notif)) $payload['notification_url'] = $notif;

    $j = mp_http_json('POST', mp_api_base().'/v1/payments', $payload);
    return is_array($j) ? $j : array();
  }
}

if (!function_exists('mp_refund_payment')) {
  /**
   * Solicita reembolso (total ou parcial) de um pagamento Mercado Pago
   * @param string|int $payment_id ID do pagamento no Mercado Pago
   * @param int|null $amount_cents Valor em centavos (null para reembolso total)
   * @return array Resposta decodificada da API
   */
  function mp_refund_payment($payment_id, $amount_cents = null)
  {
    if (!$payment_id) {
      throw new InvalidArgumentException('ID do pagamento ausente para reembolso');
    }

    $url = mp_api_base() . '/v1/payments/' . urlencode($payment_id) . '/refunds';
    $payload = null;
    if ($amount_cents !== null) {
      $amount = round($amount_cents / 100, 2);
      if ($amount <= 0) {
        throw new InvalidArgumentException('Valor do reembolso deve ser maior que zero');
      }
      $payload = array('amount' => $amount);
    }

    return mp_http_json('POST', $url, $payload);
  }
}
