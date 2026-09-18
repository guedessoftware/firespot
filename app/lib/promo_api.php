<?php
// Carrega settings para permitir controle via painel (app_settings)
if (!function_exists('settings_get')) {
  $maybe = __DIR__ . '/../settings.php';
  if (is_file($maybe)) require_once $maybe;
}

if (!function_exists('promo_is_enabled')) {
  function promo_is_enabled(): bool {
    // Prioriza o valor no banco (app_settings). Fallback: ENV.
    $val = null;
    if (function_exists('settings_get')) {
      $val = settings_get('promo_enabled', null);
    }
    if ($val === null) {
      $val = getenv('PROMO_ENABLED');
    }
    if ($val === false || $val === null) return false; // desabilitado por padrão se não definido
    $v = strtolower(trim((string)$val));
    return in_array($v, ['1','true','yes','on','y','sim'], true);
  }
}

if (!function_exists('promo_clean_number')) {
  function promo_clean_number($s) {
    $s = preg_replace('/[^+0-9]/','', (string)$s);
    if (strpos($s, '00') === 0) { $s = '+'.substr($s, 2); }
    return $s;
  }
}

if (!function_exists('promo_api_normalize_destination')) {
  /**
   * O MeuJames/playSMS configurado para o FireSpot recebe o destino no formato
   * nacional: DDD + número. Aceitamos entradas brasileiras com +55/0055, mas
   * nunca enviamos marcadores internos ou um destino de tamanho ambíguo.
   */
  function promo_api_normalize_destination($value): ?string {
    $digits = preg_replace('/\D+/', '', (string)$value);
    if (!is_string($digits) || $digits === '') return null;
    if (str_starts_with($digits, '0055')) $digits = substr($digits, 4);
    elseif (str_starts_with($digits, '55') && in_array(strlen($digits), [12,13], true)) $digits = substr($digits, 2);
    if (!preg_match('/^[1-9][0-9]{9,10}$/', $digits)) return null;
    return $digits;
  }
}

if (!function_exists('promo_api_normalize_base')) {
  /**
   * Normaliza somente o endpoint, nunca a URL completa com credenciais.
   * A configuração antiga aceitava `/api`, embora o MeuJames exponha o
   * serviço em `/api/playsms`.
   */
  function promo_api_normalize_base($value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    $parts = parse_url($value);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || trim((string)($parts['host'] ?? '')) === ''
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
      return null;
    }
    $path = '/' . ltrim((string)($parts['path'] ?? ''), '/');
    $path = rtrim($path, '/');
    if ($path === '/api') $path .= '/playsms';
    if ($path === '') return null;
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    return 'https://' . strtolower((string)$parts['host']) . $port . $path;
  }
}

if (!function_exists('promo_api_response_error')) {
  /** Retorna somente um código seguro; a resposta bruta não vai para a UI. */
  function promo_api_response_error($response): ?string {
    $body = trim((string)$response);
    if ($body === '') return 'EMPTY_RESPONSE';
    if (preg_match('/\bERR\s*([0-9]{3})\b/i', $body, $match)) return 'PLAYSMS_ERR_' . $match[1];
    $json = json_decode($body, true);
    if (!is_array($json)) return null;

    $errorString = trim((string)($json['error_string'] ?? ''));
    if ($errorString !== '' && strtolower($errorString) !== 'null') return 'PLAYSMS_ERROR';
    $rows = isset($json['data']) && is_array($json['data']) ? $json['data'] : [$json];
    if (isset($rows['status'])) $rows = [$rows];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $status = strtoupper(trim((string)($row['status'] ?? '')));
      $error = trim((string)($row['error'] ?? ''));
      if ($status !== '' && !in_array($status, ['OK','SUCCESS','SENT','QUEUED'], true)) return 'PLAYSMS_STATUS_' . preg_replace('/[^A-Z0-9_-]+/', '_', $status);
      if ($error !== '' && $error !== '0') return 'PLAYSMS_ERROR_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $error);
    }
    return null;
  }
}

if (!function_exists('promo_api_build_url')) {
  function promo_api_build_url(string $base, string $user, string $hash, string $message, string $to): string {
    return $base
      . '?op=pv'
      . '&u=' . rawurlencode($user)
      . '&h=' . rawurlencode($hash)
      . '&msg=' . rawurlencode($message)
      . '&to=' . rawurlencode($to);
  }
}

if (!function_exists('promo_api_send')) {
  function promo_api_send($to, $msg, array $opts = []) {
    // Toggle global — quando desabilitado, não envia e retorna como "skipped" porém OK
    $force = !empty($opts['force']);
    if (!$force && function_exists('promo_is_enabled') && !promo_is_enabled()) {
      return ['ok' => true, 'skipped' => true, 'reason' => 'PROMO_DISABLED'];
    }

    // Config leitura: banco -> ENV -> padrão
    $base = null; $user = null; $hash = null;
    if (function_exists('settings_get')) {
      $base = settings_get('promo_api_base', null);
      $user = settings_get('promo_api_user', null);
      $hash = settings_get('promo_api_hash', null);
    }
    if ($base === null || $base === '') $base = getenv('PROMO_API_BASE') ?: 'https://meujames.com/api/playsms';
    if ($user === null || $user === '') $user = getenv('PROMO_API_USER') ?: '';
    if ($hash === null || $hash === '') $hash = getenv('PROMO_API_HASH') ?: '';

    $base = promo_api_normalize_base($base);

    // Verifica configuração mínima
    if ($base === null) {
      return ['ok' => false, 'code' => 0, 'err' => 'INVALID_BASE_URL', 'resp' => ''];
    }
    if ($user === '' || $hash === '') {
      return ['ok' => false, 'code' => 0, 'err' => 'MISSING_CONFIG', 'resp' => ''];
    }

    $to = promo_api_normalize_destination($to);
    if ($to === null) return ['ok' => false, 'code' => 0, 'err' => 'INVALID_DEST', 'resp' => ''];
    $url = promo_api_build_url($base, (string)$user, (string)$hash, (string)$msg, (string)$to);

    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_USERAGENT => 'FireSpot/1.0',
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $apiError = ($err === '' && $code >= 200 && $code < 300)
      ? promo_api_response_error($resp)
      : null;
    $ok = ($err==='' && $code>=200 && $code<300 && $apiError === null);
    if ($apiError !== null) $err = $apiError;
    if (!$ok) {
      // Log seguro (sem credenciais). Não expõe hash/usuário/URL completa.
      @error_log('[promo_api] send_error code='.$code.' err='.($err ?: 'HTTP').' base='.parse_url($base, PHP_URL_HOST));
    }

    return ['ok' => $ok,
            'code'=>$code, 'err'=>$err, 'resp'=>'', 'url'=>$base.'?op=pv&...'];
  }
}
