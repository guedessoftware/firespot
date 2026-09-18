<?php
declare(strict_types=1);

// Biblioteca legada: não pode executar o exemplo embutido por uma requisição.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}
/**
 * Retorna o link de conexão (https://.../portal/conect.php?id=...) chamando a API login_token_create.php
 *
 * Requisitos:
 * - Estar logado no dashboard (usa o PHPSESSID atual).
 * - Se sua API exigir CSRF, deixe $_SESSION['csrf'] setado (ou passe no 4º parâmetro).
 *
 * @param string      $username  CPF do usuário (só números)
 * @param int         $ttl_min   Validade do token em minutos (1–30)
 * @param string|null $baseUrl   Base do site; null usa a configuração central
 * @param string|null $csrf      Token CSRF opcional (se null, tenta $_SESSION['csrf'])
 * @return string                URL pronta para redirecionar o cliente
 * @throws RuntimeException      Quando a chamada falhar
 */
function login_token_link(string $username, int $ttl_min = 10, ?string $baseUrl = null, ?string $csrf = null): string
{
    require_once __DIR__ . '/../../app/session_boot.php';
    require_once __DIR__ . '/../../app/public_url.php';
    require_once __DIR__ . '/../../app/db.php';

    $username = preg_replace('/\D+/', '', $username);
    if ($username === '') {
        throw new RuntimeException('username inválido');
    }

    $ttl_min = max(1, min(30, (int)$ttl_min));
    $baseUrl = $baseUrl === null || trim($baseUrl) === '' ? fs_public_base_url(db()) : fs_normalize_public_base_url($baseUrl);
    $url = $baseUrl . '/portal/api/login_token_create.php';

    $payload = json_encode([
        'username' => $username,
        'ttl_min'  => $ttl_min,
    ], JSON_UNESCAPED_UNICODE);

    $headers = ['Content-Type: application/json'];
    // Se sua API exigir CSRF, envie o header:
    $csrf = $csrf ?? ($_SESSION['csrf'] ?? null);
    if (!empty($csrf)) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        // Envia o cookie de sessão atual para autenticar
    // Se seu dashboard usa outro cookie, ajuste aqui. Para portal, usamos HSSESSID por padrão
    CURLOPT_COOKIE         => session_name() . '=' . session_id(),
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('cURL error: ' . $err);
    }
    if ($code !== 200) {
        throw new RuntimeException('HTTP ' . $code . ' ao criar token');
    }

    $j = json_decode($resp, true);
    if (!is_array($j) || empty($j['ok']) || empty($j['link'])) {
        throw new RuntimeException('Resposta inválida da API');
    }

    return (string)$j['link'];
}

