<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/lib/promo_api.php';

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$endpoint = 'https://meujames.com/api/playsms';
$expect(promo_api_normalize_base($endpoint) === $endpoint, 'O endpoint correto do MeuJames foi alterado.');
$expect(promo_api_normalize_base('https://meujames.com/api/') === $endpoint, 'A configuração antiga /api não foi promovida para /api/playsms.');
$expect(promo_api_normalize_base($endpoint . '/') === $endpoint, 'A barra final não foi normalizada.');
$expect(promo_api_normalize_base('http://meujames.com/api/playsms') === null, 'Endpoint sem HTTPS foi aceito.');
$expect(promo_api_normalize_base($endpoint . '?op=pv') === null, 'A API Base aceitou parâmetros ou credenciais.');

$expect(promo_api_normalize_destination('(92) 99999-9999') === '92999999999', 'Destino nacional não foi normalizado.');
$expect(promo_api_normalize_destination('+55 92 99999-9999') === '92999999999', 'Código do Brasil não foi removido para o MeuJames.');
$expect(promo_api_normalize_destination('005592999999999') === '92999999999', 'Prefixo internacional 0055 não foi normalizado.');
$expect(promo_api_normalize_destination('ENCRYPTED') === null, 'Marcador interno foi aceito como destino.');
$expect(promo_api_normalize_destination('9999') === null, 'Destino incompleto foi aceito.');

$url = promo_api_build_url($endpoint, 'usuario@gmail.com', 'abc123', 'Teste FireSpot: OK', '92999999999');
$parts = parse_url($url);
parse_str((string)($parts['query'] ?? ''), $query);
$expect(($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'meujames.com' && ($parts['path'] ?? '') === '/api/playsms', 'A URL foi construída para o endpoint incorreto.');
$expect($query === ['op'=>'pv','u'=>'usuario@gmail.com','h'=>'abc123','msg'=>'Teste FireSpot: OK','to'=>'92999999999'], 'Os parâmetros do PlaySMS divergiram do contrato.');
$expect(str_contains($url, 'u=usuario%40gmail.com') && str_contains($url, 'to=92999999999'), 'Usuário ou destino não foram codificados com segurança.');

$ok = '{"data":[{"status":"OK","error":"0","smslog_id":"30"}],"error_string":null}';
$expect(promo_api_response_error($ok) === null, 'Uma resposta OK do PlaySMS foi recusada.');
$expect(promo_api_response_error('{"data":[{"status":"ERROR","error":"100"}]}') === 'PLAYSMS_STATUS_ERROR', 'Erro JSON do PlaySMS foi aceito.');
$expect(promo_api_response_error('ERR 104') === 'PLAYSMS_ERR_104', 'Erro textual do PlaySMS foi aceito.');
$expect(promo_api_response_error('') === 'EMPTY_RESPONSE', 'Resposta vazia foi considerada envio concluído.');

echo "OK: {$checks} verificações da API de mensageria.\n";
