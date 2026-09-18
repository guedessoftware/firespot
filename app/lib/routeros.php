<?php
/**
 * routeros.php v2 — SSH robusto p/ MikroTik
 * - Carrega .env (se existir)
 * - Usa php-ssh2 se disponível (senha ou chave)
 * - Fallback: binário ssh; suporta chave (-i) ou sshpass p/ senha
 */

function ros_env_load_once() {
  static $loaded = false;
  if ($loaded) return;
  $loaded = true;

  // 1) Já vem de ENV? ok
  if (getenv('ROS_HOST')) return;

  // 2) Procura a configuração externa antes do caminho legado no webroot.
  $candidates = array_filter([
    getenv('FIRESPOT_ENV_PATH') ?: null,
    getenv('ENV_PATH') ?: null,
    '/etc/firespot/firespot.env',
    '/etc/firespot/firespot.env',
    __DIR__ . '/../../.env',     // /hotspot/.env
    __DIR__ . '/../.env',        // /hotspot/app/.env
    __DIR__ . '/../../../.env',  // /var/www/html/.env
    isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'].'/.env' : null,
  ]);

  foreach ($candidates as $file) {
    if ($file && is_readable($file)) {
      ros_parse_env_file($file);
      break;
    }
  }
}

function ros_parse_env_file($path) {
  $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!$lines) return;
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $pos = strpos($line, '=');
    if ($pos === false) continue;
    $key = trim(substr($line, 0, $pos));
    $val = trim(substr($line, $pos + 1));
    $val = preg_replace('/^([\'"])(.*)\\1$/', '$2', $val); // remove aspas
    if ($key !== '') {
      putenv($key.'='.$val);
      $_ENV[$key] = $val; // útil em alguns ambientes
    }
  }
}

function ros_get($k, $default = null) {
  $v = getenv($k);
  return $v !== false ? $v : $default;
}

function ros_debug() {
  return (int)ros_get('ROS_DEBUG', 0) === 1;
}

function ros_redact_sensitive($value) {
  $value = (string)$value;
  return preg_replace('/\b(secret|password)=((?:"(?:\\\\.|[^"])*")|(?:\S+))/i', '$1="[REDACTED]"', $value);
}

class RosHostKeyException extends RuntimeException {}

function ros_normalize_host_key_fingerprint($fingerprint) {
  $fingerprint = strtolower(trim((string)$fingerprint));
  if ($fingerprint === '') return '';
  $fingerprint = preg_replace('/^(sha256|sha1|md5):/i', '', $fingerprint);
  return preg_replace('/[^a-z0-9+\/=]/i', '', (string)$fingerprint);
}

function ros_ssh2_host_key_fingerprint($connection) {
  if (!function_exists('ssh2_fingerprint')) {
    throw new RuntimeException('A extensão SSH2 não permite validar a chave do host.');
  }
  $flags = (defined('SSH2_FINGERPRINT_SHA256') ? SSH2_FINGERPRINT_SHA256 : 0)
    | (defined('SSH2_FINGERPRINT_HEX') ? SSH2_FINGERPRINT_HEX : 0);
  $fingerprint = trim((string)@ssh2_fingerprint($connection, $flags));
  if ($fingerprint === '') throw new RuntimeException('Não foi possível obter a chave do host SSH.');
  return $fingerprint;
}

function ros_assert_ssh2_host_key($connection, $expectedFingerprint) {
  $expected = ros_normalize_host_key_fingerprint($expectedFingerprint);
  if ($expected === '') return;
  $actual = ros_normalize_host_key_fingerprint(ros_ssh2_host_key_fingerprint($connection));
  if ($actual === '' || !hash_equals($expected, $actual)) {
    throw new RosHostKeyException('A chave SSH do NAS mudou. A conexão foi bloqueada para revisão de segurança.');
  }
}

/**
 * Executa uma lista de comandos no RouterOS.
 * @param array $commands ex.: ["/ip hotspot print", "/system identity print"]
 * @return array [ok=>bool, out=>array de saidas (por comando), err=>string|null]
 * @throws Exception em erro de conexão/autenticação
 */
function ros_exec(array $commands, array $connection = []) {
  ros_env_load_once();

  $host   = trim((string)($connection['host'] ?? ros_get('ROS_HOST', '192.168.88.1')));
  $user   = trim((string)($connection['user'] ?? ros_get('ROS_USER', 'admin')));
  $pass   = (string)($connection['pass'] ?? ros_get('ROS_PASS', ''));
  $port   = (int)($connection['port'] ?? ros_get('ROS_PORT', 22));
  if ($host === '' || $user === '') throw new Exception('Host ou usuário SSH do MikroTik não configurado');
  if ($port < 1 || $port > 65535) $port = 22;

  // Uma conexão específica de NAS não herda por acidente a chave de outro
  // roteador. Chamadas antigas continuam usando integralmente o .env global.
  $key    = array_key_exists('key', $connection) ? $connection['key'] : (empty($connection) ? ros_get('ROS_KEY_PATH') : null);
  $keypwd = array_key_exists('key_passphrase', $connection) ? $connection['key_passphrase'] : (empty($connection) ? ros_get('ROS_KEY_PASSPHRASE') : null);
  $expectedHostKey = trim((string)($connection['expected_host_key_fingerprint'] ?? ''));
  $debug  = ros_debug();

  $out = [];

  // ===== Primeiro: php-ssh2 (recomendado)
  if (
    function_exists('ssh2_connect') &&
    function_exists('ssh2_exec') &&
    function_exists('ssh2_auth_password') &&
    function_exists('ssh2_auth_pubkey_file')
  ) {
    if ($debug) error_log("[ROS][ssh2] connecting $user@$host:$port");

    $conn = @ssh2_connect($host, $port);
    if (!$conn) throw new Exception('SSH connect falhou');
    // A validação ocorre em cada conexão real e sempre antes da autenticação.
    ros_assert_ssh2_host_key($conn, $expectedHostKey);

    if ($key && is_file($key)) {
      // tenta autenticar por chave
      $pub = $key.'.pub';
      if (!is_file($pub)) {
        // tenta heurística: converte caminho id_rsa -> id_rsa.pub
        $pub = preg_replace('/(\\.pem|)$/', '.pub', $key);
      }
      if ($debug) error_log("[ROS][ssh2] auth pubkey key=$key pub=$pub");
      if (!@ssh2_auth_pubkey_file($conn, $user, $pub, $key, $keypwd ?: null)) {
        throw new Exception('SSH auth (chave) falhou');
      }
    } else {
      if ($debug) error_log("[ROS][ssh2] auth password");
      if (!@ssh2_auth_password($conn, $user, $pass)) {
        throw new Exception('SSH auth (senha) falhou');
      }
    }

    foreach ($commands as $cmd) {
      if ($debug) error_log('[ROS][ssh2] exec: '.ros_redact_sensitive($cmd));
      $stream = @ssh2_exec($conn, $cmd);
      if (!$stream) throw new Exception('Falha ao executar: '.$cmd);
      // Define SSH2_STREAM_STDERR if not defined
      if (!defined('SSH2_STREAM_STDERR')) {
        define('SSH2_STREAM_STDERR', 2);
      }
      // Fetch STDERR stream if function exists, else set to null
      if (function_exists('ssh2_fetch_stream')) {
        $errstr = @ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
      } else {
        $errstr = null;
      }
      stream_set_blocking($stream, true);
      if ($errstr) stream_set_blocking($errstr, true);

      $stdout = stream_get_contents($stream);
      $stderr = $errstr ? stream_get_contents($errstr) : '';

      fclose($stream);
      if ($errstr) fclose($errstr);

      if ($debug) error_log('[ROS][ssh2] out: '.ros_redact_sensitive(trim($stdout)));
      if ($stderr && $debug) error_log('[ROS][ssh2] err: '.ros_redact_sensitive(trim($stderr)));
      $out[] = trim($stdout ?: $stderr);
    }

    return ['ok' => true, 'out' => $out, 'err' => null];
  }

  // ===== Fallback: binário ssh
  if ($expectedHostKey !== '') {
    throw new RuntimeException('A conexão protegida por chave de host exige a extensão PHP SSH2.');
  }
  $sshbin = trim(shell_exec('command -v ssh')) ?: '/usr/bin/ssh';
  if (!is_executable($sshbin)) {
    throw new Exception('Sem SSH disponível: instale php-ssh2 ou o binário ssh');
  }

  // Preferir chave no fallback
  if ($key && is_file($key)) {
    foreach ($commands as $cmd) {
      $full = sprintf(
        "%s -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -p %d -i %s %s@%s %s 2>&1",
        escapeshellcmd($sshbin),
        $port,
        escapeshellarg($key),
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($cmd)
      );
      if ($debug) error_log('[ROS][ssh-bin] '.$user.'@'.$host.':'.$port.' exec: '.ros_redact_sensitive($cmd));
      $ret = shell_exec($full);
      if ($debug) error_log('[ROS][ssh-bin] out: '.ros_redact_sensitive(trim((string)$ret)));
      $out[] = trim((string)$ret);
    }
    return ['ok' => true, 'out' => $out, 'err' => null];
  }

  // Senha no fallback requer sshpass
  $sshpass = trim(shell_exec('command -v sshpass'));
  if ($pass && $sshpass) {
    foreach ($commands as $cmd) {
      $full = sprintf(
        "%s -p %s %s -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -p %d %s@%s %s 2>&1",
        escapeshellcmd($sshpass),
        escapeshellarg($pass),
        escapeshellcmd($sshbin),
        $port,
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($cmd)
      );
      if ($debug) error_log('[ROS][sshpass] '.$user.'@'.$host.':'.$port.' exec: '.ros_redact_sensitive($cmd));
      $ret = shell_exec($full);
      if ($debug) error_log('[ROS][sshpass] out: '.ros_redact_sensitive(trim((string)$ret)));
      $out[] = trim((string)$ret);
    }
    return ['ok' => true, 'out' => $out, 'err' => null];
  }

  // Sem ssh2, sem chave e sem sshpass
  throw new Exception('SSH por senha requer php-ssh2 ou sshpass. Instale php-ssh2 OU configure ROS_KEY_PATH.');
}

/** Utilitário: token aleatório p/ comentar ip-binding/scripts */
function ros_safe_token($len = 10){
  return substr(bin2hex(random_bytes(max(4,$len/2))), 0, $len);
}
