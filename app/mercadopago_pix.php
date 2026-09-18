<?php declare(strict_types=1);

// /hotspot/app/mercadopago_pix.php
// Cliente MP para criar PIX via /v1/payments

// Carrega .env (se existir)
$__env = __DIR__ . '/env.php';
if (is_file($__env)) require_once $__env;

// Fallback simples para env() caso env.php não tenha sido incluído
if (!function_exists('env')) {
  function env(string $key, $default = null) {
    $v = getenv($key);
    return $v !== false ? $v : $default;
  }
}

/** Base da API (pode sobrescrever via .env) */
function mp_api_base(): string {
  return env('MERCADOPAGO_API_BASE', 'https://api.mercadopago.com');
}

/** Access Token (pega do .env MERCADOPAGO_ACCESS_TOKEN, ou constante MP_ACCESS_TOKEN) */
function mp_token(): string {
  $t = env('MERCADOPAGO_ACCESS_TOKEN');
 
  if (!$t) {
    throw new RuntimeException('MERCADOPAGO_ACCESS_TOKEN ausente: defina no .env ou MP_ACCESS_TOKEN em config.php');
  }
  return $t;
}

/** HTTP JSON helper (cURL) */
function mp_http_json(string $method, string $url, ?array $body = null): array {
  $ch = curl_init($url);
  $headers = [
    'Authorization: Bearer ' . mp_token(),
    'Content-Type: application/json'
  ];
  $opts = [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
  ];
  if ($body !== null) {
    $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
  }
  curl_setopt_array($ch, $opts);
  $res  = curl_exec($ch);
  if ($res === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('curl_error: ' . $err);
  }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $j = json_decode($res, true);
  if ($code >= 300) {
    // Tenta detalhar erro do MP
    $msg = is_array($j) ? ($j['message'] ?? $res) : $res;
    throw new RuntimeException('mp_http_'.$code.': '.$msg);
  }
  return is_array($j) ? $j : [];
}

/**
 * Cria pagamento PIX no Mercado Pago.
 *
 * @param int    $amount_cents   Valor em centavos (ex.: 990 = R$ 9,90)
 * @param string $external_ref   Referência única do seu sistema (usada no webhook)
 * @param string $description    Descrição (ex.: "Acesso VIP - Plano X")
 * @param array  $payer          [
 *                                'first_name'   => string,
 *                                'email'        => string,
 *                                'cpf'          => string (11 dígitos),
 *                                'phone_area'   => string (ex.: "11"),
 *                                'phone_number' => string (sem DDD, ex.: "999999999")
 *                              ]
 * @param array  $metadata       (opcional) ex.: ['order_id'=>123]
 * @return array ['id','status','qr_code','qr_code_base64','ticket_url','raw'=>...]
 */
if (!function_exists('mp_create_pix_payment')) {
  function mp_create_pix_payment(
    int $amount_cents,
    string $external_ref,
    string $description,
    array $payer,
    array $metadata = []
  ): array {
    // MP espera valor em reais (float, 2 casas)
    $amount = round($amount_cents / 100, 2);

    // Monta payload
    $payload = [
      'transaction_amount' => $amount,
      'description'        => $description,
      'payment_method_id'  => 'pix',
      'external_reference' => $external_ref,
      'payer' => [
        'first_name'     => $payer['first_name'] ?? null,
        'email'          => $payer['email']      ?? null,
        'identification' => [
          'type'   => 'CPF',
          'number' => $payer['cpf'] ?? null,
        ],
      ],
      'metadata' => (object)$metadata, // objeto vazio se não houver
    ];

    // Telefone é opcional, adiciona se houver
    $area   = $payer['phone_area']   ?? null;
    $number = $payer['phone_number'] ?? null;
    if ($area || $number) {
      $payload['payer']['phone'] = [
        'area_code' => $area,
        'number'    => $number,
      ];
    }

    // notification_url (opcional) via .env
    $notif = env('MP_NOTIFICATION_URL');
    if ($notif) $payload['notification_url'] = $notif;

    // Chama a API
    $j = mp_http_json('POST', mp_api_base().'/v1/payments', $payload);

    // Extrai dados úteis
    $poi = $j['point_of_interaction']['transaction_data'] ?? [];
    return [
      'id'             => $j['id']             ?? null,
      'status'         => $j['status']         ?? null,
      'qr_code'        => $poi['qr_code']      ?? null,
      'qr_code_base64' => isset($poi['qr_code_base64']) ? ('data:image/png;base64,'.$poi['qr_code_base64']) : null,
      'ticket_url'     => $poi['ticket_url']   ?? null,
      'raw'            => $j,
    ];
  }
}

/** Consulta pagamento por ID (útil para webhook/polling) */
if (!function_exists('mp_get_payment')) {
  function mp_get_payment(string $payment_id): array {
    return mp_http_json('GET', mp_api_base().'/v1/payments/'.urlencode($payment_id));
  }
}
