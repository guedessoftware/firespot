<?php
// SEC-004: execução de RouterOS nunca é exposta como página de teste.
http_response_code(404);
exit;

require __DIR__.'/routeros.php';
$r = ros_exec([
  '/ip hotspot host print',
]);
print_r($r);
