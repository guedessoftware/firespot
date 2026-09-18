<?php
// SEC-004: diagnóstico retirado do ambiente público.
http_response_code(404);
exit;

require __DIR__.'/env.php';
$u = env('MP_NOTIFICATION_URL');
echo "MP_NOTIFICATION_URL: ".($u ?: '(vazia)')."\n";
if ($u) {
  echo "valid? ".(filter_var($u, FILTER_VALIDATE_URL) ? 'yes' : 'no')."\n";
  echo "scheme: ".parse_url($u, PHP_URL_SCHEME)."\n";
  echo "host:   ".parse_url($u, PHP_URL_HOST)."\n";
}
