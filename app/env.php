<?php
// Loader simples de variáveis de ambiente (compatível com PHP 7.x).
// Em produção, o arquivo deve ficar fora do DocumentRoot.
if (!function_exists('load_env')) {

  function load_env($path) {
    if (!is_file($path) || !is_readable($path)) return false;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return false;
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
      $pos = strpos($line, '=');
      if ($pos === false) continue;
      $key = trim(substr($line, 0, $pos));
      $val = trim(substr($line, $pos + 1));
      if ((strlen($val) > 1) && (($val[0] === '"' && substr($val,-1) === '"') || ($val[0] === "'" && substr($val,-1) === "'"))) {
        $val = substr($val, 1, -1);
      }
      if (getenv($key) === false) {
        putenv("$key=$val");
        $_ENV[$key]    = $val;
        $_SERVER[$key] = $val;
      }
    }
    return true;
  }
}
if (!function_exists('env')) {
  function env($key, $default = null) {
    $v = getenv($key);
    if ($v !== false) return $v;
    if (isset($_ENV[$key]))    return $_ENV[$key];
    if (isset($_SERVER[$key])) return $_SERVER[$key];
    return $default;
  }
}
$root = dirname(__DIR__); // /hotspot
$configuredPath = trim((string) getenv('FIRESPOT_ENV_PATH'));
$envCandidates = array_filter([
  $configuredPath !== '' ? $configuredPath : null,
  '/etc/firespot/firespot.env',
  '/etc/firespot/firespot.env',
  // Compatibilidade para desenvolvimento. Produção não deve manter este arquivo.
  $root.'/.env',
]);
foreach ($envCandidates as $envPath) {
  if (load_env($envPath)) break;
}
