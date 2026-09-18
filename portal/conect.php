<?php
// /portal/conect.php?id=<code>[&open=1]
// Redireciona o usuário para o login do hotspot, sem vazar dados do token.
// - Não marca "usado" para bots ou pré-visualizações de link
// - Só consome token quando open=1 e user-agent parece humano
// - Tudo em TZ -04:00

@ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/partner_ads.php';

// -------- Helpers --------
function envv($k, $d = null)
{
    if (function_exists('env'))
        return env($k, $d);
    $v = getenv($k);
    return ($v !== false && $v !== '') ? $v : $d;
}
function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function client_ip()
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $v = $_SERVER[$h];
            if ($h === 'HTTP_X_FORWARDED_FOR') {
                $p = explode(',', $v);
                $v = trim($p[0]);
            }
            return $v;
        }
    }
    return '';
}
function is_bot_ua($ua)
{
    $ua = strtolower($ua ?? '');
    if ($ua === '')
        return true;
    $bots = [
        'facebookexternalhit',
        'facebot',
        'whatsapp',
        'telegrambot',
        'twitterbot',
        'slackbot',
        'discordbot',
        'googlebot',
        'bingbot',
        'yandex',
        'preview',
        'link',
        'crawler',
        'spider',
        'node-fetch',
        'curl',
        'wget',
        'python-requests',
        'okhttp',
        'postman'
    ];
    foreach ($bots as $b)
        if (strpos($ua, $b) !== false)
            return true;
    return false;
}

// fallback apenas se você não tiver a função global pronta no projeto
if (!function_exists('build_hotspot_login_url')) {
    function build_hotspot_login_url(string $username, string $password, ?string $destination = null): string
    {
        // Preferir DNS do estabelecimento quando disponível no banco; senão usar o próprio host
        try {
            if (session_status() === PHP_SESSION_NONE) { @session_start(); }
            $pdo = db();
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $dns = '';
            $hostHdr = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
            // 0) Se a requisição já carregou um token, tente ler partner_dns na tabela
            try {
                $codeParam = isset($_GET['id']) ? preg_replace('/\D+/', '', (string)$_GET['id']) : '';
                if ($codeParam !== '') {
                    $st0 = $pdo->prepare('SELECT partner_dns FROM login_tokens WHERE code=? LIMIT 1');
                    $st0->execute([$codeParam]);
                    $dnsTok = trim((string)($st0->fetchColumn() ?: ''));
                    if ($dnsTok !== '') { $dns = $dnsTok; }
                }
            } catch (\Throwable $e0) { /* ignore */ }
            // 1) Contexto de parceiro em sessão (pode ser id numérico ou code)
            $fast = isset($_SESSION['portal_fast_id']) ? trim((string)$_SESSION['portal_fast_id']) : '';
            if ($fast !== '' && $dns === '') {
                $hotspot=fs_partner_hotspot_resolve($pdo,$fast,false,true);
                if($hotspot){$dns=trim((string)($hotspot['dns_name']??''));}
                elseif (ctype_digit($fast)) {
                    $st = $pdo->prepare('SELECT dns_name FROM partners WHERE id=? AND active=1 LIMIT 1');
                    $st->execute([(int)$fast]);
                    $dns = trim((string)($st->fetchColumn() ?: ''));
                    if ($dns === '') {
                        $st2 = $pdo->prepare('SELECT dns_name FROM partners WHERE code=? AND active=1 LIMIT 1');
                        $st2->execute([$fast]);
                        $dns = trim((string)($st2->fetchColumn() ?: ''));
                    }
                } else {
                    $st = $pdo->prepare('SELECT dns_name FROM partners WHERE code=? AND active=1 LIMIT 1');
                    $st->execute([$fast]);
                    $dns = trim((string)($st->fetchColumn() ?: ''));
                }
            }
            // 2) Se não houver contexto, tenta casar pelo host atual
            if ($dns === '') {
                if ($hostHdr !== '') {
                    $st = $pdo->prepare('SELECT dns_name FROM partners WHERE dns_name=? AND active=1 LIMIT 1');
                    $st->execute([$hostHdr]);
                    $dns = trim((string)($st->fetchColumn() ?: ''));
                }
            }
            // 3) Monta URL de login: se tiver dns do parceiro, usa HTTPS no dns_name e dst no portal atual;
            //    caso contrário, usa o host atual (HTTP) para ambos.
            if ($dns !== '') {
                $loginBase = 'http://' . $dns; // Mikrotik Hotspot normalmente usa HTTP
                $dst       = $destination ?: $loginBase . '/status';
                return $loginBase . '/login?username=' . rawurlencode($username) . '&password=' . rawurlencode($password) . '&dst=' . rawurlencode($dst);
            } else if ($hostHdr !== '') {
                $loginBase = 'http://' . $hostHdr;
                $dst       = $destination ?: $loginBase . '/status';
                return $loginBase . '/login?username=' . rawurlencode($username) . '&password=' . rawurlencode($password) . '&dst=' . rawurlencode($dst);
            }
        } catch (\Throwable $e) { /* fallback abaixo */ }

        // Último recurso: tenta novamente com HTTP_HOST se existir
        $hostHdr = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
        if ($hostHdr !== '') {
            $base = 'http://' . $hostHdr;
            $dst  = $destination ?: $base . '/status';
            return $base . '/login?username=' . rawurlencode($username) . '&password=' . rawurlencode($password) . '&dst=' . rawurlencode($dst);
        }
        return '';
    }
}

// Lê token
$code = isset($_GET['id']) ? preg_replace('/\D+/', '', (string) $_GET['id']) : '';
$open = isset($_GET['open']) ? (string) $_GET['open'] : '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip = client_ip();

if ($code === '') {
    http_response_code(400);
    echo "<!doctype html><meta charset='utf-8'><title>Link inválido</title><p>Link inválido.</p>";
    exit;
}

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // TZ local
    $pdo->exec("SET time_zone='-04:00'");

    // Registra hit do token (não consome)
    try {
        $pdo->prepare("UPDATE login_tokens
                     SET last_hit_at = NOW(),
                         last_hit_ip = ?,
                         last_hit_ua = ?
                   WHERE code = ?")
            ->execute([$ip, substr($ua, 0, 255), $code]);
    } catch (\Throwable $e) {
    }

    // Busca o token
    $st = $pdo->prepare("SELECT id, code, username, ip AS token_ip, mac AS token_mac,
                              used_at, used_by_ip, created_at, expires_at
                         FROM login_tokens
                        WHERE code = ?
                        LIMIT 1");
    $st->execute([$code]);
    $row = $st->fetch();

    if (!$row) {
        http_response_code(404);
        echo "<!doctype html><meta charset='utf-8'><title>Token não encontrado</title><p>Este link não existe.</p>";
        exit;
    }

    // Expirado?
    $exp = strtotime((string) $row['expires_at']);
    if (!$exp || $exp < time()) {
        http_response_code(410);
        echo "<!doctype html><meta charset='utf-8'><title>Link expirado</title>
          <p>Seu link expirou. Gere um novo no portal.</p>";
        exit;
    }

    // Se for BOT: mostra landing simples, NÃO consome o token
    if (is_bot_ua($ua) || $open !== '1') {
        // Página neutra para o usuário clicar (evita preview consumir o token)
        $self = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $_SERVER['REQUEST_URI'];
        $click = preg_match('/[?&]open=1\b/', $self) ? $self : $self . (strpos($self, '?') !== false ? '&' : '?') . 'open=1';
        echo "<!doctype html>
<meta charset='utf-8'>
<meta name='robots' content='noindex,nofollow'>
<meta name='viewport' content='width=device-width, initial-scale=1'>
<title>Conectar</title>
<style>
  body{font-family:system-ui,Segoe UI,Roboto,Ubuntu,sans-serif;background:#0b0b0b;color:#fff;display:grid;place-items:center;min-height:100vh;margin:0}
  .card{background:#111;border:1px solid #333;border-radius:14px;padding:20px;max-width:480px;box-shadow:0 10px 24px rgba(0,0,0,.35)}
  .btn{display:inline-block;padding:12px 16px;border-radius:12px;background:#22c55e;color:#111;text-decoration:none;font-weight:700}
  .muted{color:#aaa}
</style>
<div class='card'>
  <h2>Conectar ao Wi-Fi</h2>
  <p class='muted'>Clique no botão abaixo para iniciar sua conexão com segurança.</p>
  <p><a class='btn' href='" . h($click) . "'>Conectar agora</a></p>
</div>";
        exit;
    }

    // A partir daqui: requisição humana + open=1 → consome token (idempotente)
    if (!empty($row['used_at'])) {
        // Se já usado, ainda assim podemos permitir reuso por alguns minutos do mesmo IP
        $used = strtotime((string) $row['used_at']);
        $allow_secs = 180; // 3 min de tolerância p/ usuário voltar
        if ($row['used_by_ip'] === $ip && $used && (time() - $used) < $allow_secs) {
            // segue para o login mesmo já usado (tolerância)
        } else {
            http_response_code(409);
            echo "<!doctype html><meta charset='utf-8'><title>Link já utilizado</title>
            <p>Este link já foi utilizado. Solicite um novo no portal.</p>";
            exit;
        }
    }

    $username = (string) $row['username'];
    if ($username === '') {
        http_response_code(500);
        echo "<!doctype html><meta charset='utf-8'><p>Falha: token sem usuário.</p>";
        exit;
    }

    // Buscar senha no RADIUS (radcheck → Cleartext-Password)
    $pw = '';
    try {
        $s = $pdo->prepare("SELECT value FROM radcheck
                         WHERE username=? AND attribute='Cleartext-Password' LIMIT 1");
        $s->execute([$username]);
        $pw = (string) $s->fetchColumn();
    } catch (\Throwable $e) {
    }
    if ($pw === '') {
        http_response_code(500);
        echo "<!doctype html><meta charset='utf-8'><p>Credencial não encontrada. Contate o suporte.</p>";
        exit;
    }

    // Marca uso (idempotente)
    $pdo->prepare("UPDATE login_tokens
                    SET used_at = NOW(),
                        used_by_ip = ?
                  WHERE id = ? AND (used_at IS NULL OR used_by_ip = ?)")
        ->execute([$ip, (int) $row['id'], $ip]);

    // Redireciona para o login do hotspot (HTTP interno)
    $offerDestination = partner_ads_session_offer_url($pdo);
    $loginUrl = build_hotspot_login_url($username, $pw, $offerDestination);
    if ($loginUrl === '') {
        http_response_code(500);
        echo "<!doctype html><meta charset='utf-8'><p>Hotspot não configurado. Defina HS_LOGIN_URL.</p>";
        exit;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: ' . $loginUrl, true, 302);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo "<!doctype html><meta charset='utf-8'><p>Erro interno.</p>";
    exit;
}
