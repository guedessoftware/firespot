<?php
// SEC-004: diagnóstico retirado do ambiente público.
http_response_code(404);
exit;

require __DIR__.'/env.php';
require __DIR__.'/mercadopago_pix.php';
#header('Content-Type: text/plain; charset=utf-8');
if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle) {
    return $needle === '' || strpos((string)$haystack, (string)$needle) === 0;
  }
}

try {
  $t = mp_token();
  #var_dump($t);
  echo 'TOKEN: '.substr($t,0,8)."…\n";
  echo 'MODO: '.(str_starts_with($t,'APP_USR-')?'PRODUCAO(LIVE)':'TESTE')."\n";
  $me = mp_http_json('GET', mp_api_base().'/users/me');
  echo 'CONTA: '.($me['nickname'] ?? '??')."\n";
} catch (Throwable $e) {
  echo 'ERRO: '.$e->getMessage()."\n";
}
