<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/integration_credentials.php';

/**
 * Cliente HTTP mínimo do HubSoft.
 *
 * Mantém o contrato legado de hubsoftRequest() (retorna `clientes`), mas
 * centraliza validação de URL, timeout, TLS, códigos HTTP e renovação do token.
 * Nenhuma resposta remota ou credencial é propagada ao visitante.
 */
function fs_hubsoft_configuration(): array
{
    return fs_integration_hubsoft_load(db());
}

function fs_hubsoft_base_url(?array $configuration=null): string
{
    $configuration=$configuration??fs_hubsoft_configuration();$url=rtrim(trim((string)($configuration['base_url']??'')),'/');
    $parts = parse_url($url);
    if ($url === '' || !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        throw new RuntimeException('Configuração segura do HubSoft indisponível.');
    }
    return $url;
}

function fs_hubsoft_timeout(string $kind): int
{
    return $kind === 'connect' ? 5 : 15;
}

function fs_hubsoft_endpoint_valid(string $endpoint): bool
{
    // Use a delimiter that is not part of the permitted URI characters. The
    // previous `~...~` expression also allowed `~` inside the character class,
    // which ended the pattern early and rejected every valid HubSoft endpoint.
    return preg_match('#^/api/[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]*$#D', $endpoint) === 1;
}

/** @return array{body:string,status:int} */
function fs_hubsoft_http(string $url, array $options): array
{
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Não foi possível iniciar a integração HubSoft.');
    curl_setopt_array($ch, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => fs_hubsoft_timeout('connect'),
        CURLOPT_TIMEOUT => fs_hubsoft_timeout('request'),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'FireSpot-HubSoft/1.0',
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        $number = curl_errno($ch);
        error_log('[hubsoft http] transport_error=' . $number);
        curl_close($ch);
        throw new RuntimeException('O HubSoft não respondeu dentro do prazo esperado.');
    }
    curl_close($ch);
    return ['body' => (string) $response, 'status' => $status];
}

function getAccessToken(bool $forceRefresh = false, ?array $configuration=null): string
{
    static $cachedToken = null;
    static $cachedExpiry = 0;
    static $cachedFingerprint = '';

    $configuration=$configuration??fs_hubsoft_configuration();$fingerprint=hash('sha256',json_encode($configuration,JSON_UNESCAPED_SLASHES)?:'');$now=time();
    if (!$forceRefresh && $cachedFingerprint===$fingerprint && is_string($cachedToken) && $cachedToken !== '' && $cachedExpiry > $now) return $cachedToken;

    $payload = json_encode([
        'client_id' => (string)($configuration['client_id']??''),
        'client_secret' => (string)($configuration['client_secret']??''),
        'username' => (string)($configuration['username']??''),
        'password' => (string)($configuration['password']??''),
        'grant_type' => (string)($configuration['grant_type']??'password'),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) throw new RuntimeException('Não foi possível preparar a autenticação HubSoft.');

    $result = fs_hubsoft_http(fs_hubsoft_base_url($configuration) . '/oauth/token', [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        error_log('[hubsoft oauth] http_status=' . $result['status']);
        throw new RuntimeException('A autenticação com o HubSoft não foi aceita (HTTP ' . $result['status'] . ').', $result['status']);
    }
    $json = json_decode($result['body'], true);
    if (!is_array($json) || !is_string($json['access_token'] ?? null) || trim($json['access_token']) === '') {
        error_log('[hubsoft oauth] invalid_json_or_token');
        throw new RuntimeException('O HubSoft retornou uma autenticação inválida.');
    }

    $ttl = isset($json['expires_in']) && is_numeric($json['expires_in']) ? max(60, (int) $json['expires_in']) : 3600;
    $cachedToken = trim($json['access_token']);
    $cachedExpiry = $now + max(30, $ttl - 30);
    $cachedFingerprint = $fingerprint;
    return $cachedToken;
}

/** @return array<int,mixed> */
function hubsoftRequest($endpoint, $method = 'GET', $body = null): array
{
    $endpoint = trim((string) $endpoint);
    $method = strtoupper(trim((string) $method));
    if (!fs_hubsoft_endpoint_valid($endpoint)) {
        throw new InvalidArgumentException('Endpoint HubSoft inválido.');
    }
    if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        throw new InvalidArgumentException('Método HubSoft inválido.');
    }
    if ($body !== null && !is_array($body)) throw new InvalidArgumentException('Corpo HubSoft inválido.');

    $encodedBody = null;
    if ($body !== null) {
        $encodedBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encodedBody)) throw new InvalidArgumentException('Corpo HubSoft inválido.');
    }

    $configuration=fs_hubsoft_configuration();
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $token = getAccessToken($attempt > 1,$configuration);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
        $options = [CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method];
        if ($encodedBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = $encodedBody;
        }
        $result = fs_hubsoft_http(fs_hubsoft_base_url($configuration) . $endpoint, $options);
        if ($result['status'] === 401 && $attempt < 2) continue;
        if ($result['status'] < 200 || $result['status'] >= 300) {
            error_log('[hubsoft api] http_status=' . $result['status']);
            throw new RuntimeException('A consulta ao HubSoft não foi concluída.', $result['status']);
        }
        $json = json_decode($result['body'], true);
        if (!is_array($json) || !isset($json['clientes']) || !is_array($json['clientes'])) {
            error_log('[hubsoft api] invalid_response_contract');
            throw new RuntimeException('O HubSoft retornou dados fora do formato esperado.');
        }
        return array_values($json['clientes']);
    }

    throw new RuntimeException('A sessão do HubSoft não pôde ser renovada.');
}
