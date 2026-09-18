<?php
// Define timezone padrão do PHP cedo (respeita variável de ambiente APP_TZ/PHP_TZ)
// Evita divergência entre systemd e PHP-FPM/Apache
try {
  $cfgTz = ini_get('date.timezone');
  if (!$cfgTz || $cfgTz === '') {
    $tz = getenv('APP_TZ');
    if (!$tz || $tz === '') { $tz = getenv('PHP_TZ'); }
    if (!$tz || $tz === '') { $tz = 'America/Manaus'; }
    @ini_set('date.timezone', $tz);
    if (@date_default_timezone_set($tz) === false) { @date_default_timezone_set('UTC'); }
  }
} catch (Throwable $e) { /* ignore */ }

// Inicia sessão com segurança, sem mexer em headers já enviados
if (session_status() === PHP_SESSION_NONE) {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

  if (!headers_sent()) {
    // defina o nome apenas se for o padrão (evita warning)
    if (session_name() === 'PHPSESSID') {
      @session_name('HSSESSID');
    }
    @session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/',
      'domain'   => '',
      'secure'   => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
  @session_start();
}
