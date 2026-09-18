<?php
require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_json();
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>false,'error'=>'endpoint_retired','message'=>'A exclusão direta foi substituída por desativação de vínculo.']);
