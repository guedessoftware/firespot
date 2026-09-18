<?php
declare(strict_types=1);
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
  http_response_code(404);
  exit;
}
function applyVipPolicy(string $username, int $upKbps, int $downKbps, int $durationMin): bool {
  error_log(sprintf('[VIP] Aplicando VIP para %s: up=%dkbps down=%dkbps por %d min', $username, $upKbps, $downKbps, $durationMin));
  return true;
}
