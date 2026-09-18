<?php

require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/company.php';
require_once __DIR__ . '/../app/guest_access.php';
require_once __DIR__ . '/../app/portal_theme.php';
require_once __DIR__ . '/../app/partner_purpose.php';
require_once __DIR__ . '/../app/portal_configuration.php';
require_once __DIR__ . '/../app/portal_skin.php';
require_once __DIR__ . '/../app/portal_view_model.php';
require_once __DIR__ . '/../app/public_url.php';
require_once __DIR__ . '/../app/courtesy_shadow.php';
require_once __DIR__ . '/../app/subscriber_radius.php';
require_once __DIR__ . '/../app/hotspot_login.php';
require_once __DIR__ . '/../app/guest_payment_radius.php';
require_once __DIR__ . '/../app/partner_portal_metrics.php';
require_once __DIR__ . '/../app/partner_hotspot_commercial.php';
require_once __DIR__ . '/../app/ad_platform.php';

function v3_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function v3_money(int $cents): string
{
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function v3_duration(int $minutes): string
{
    if ($minutes % 1440 === 0) {
        $days = (int) ($minutes / 1440);
        return $days . ($days === 1 ? ' dia' : ' dias');
    }
    if ($minutes % 60 === 0) {
        $hours = (int) ($minutes / 60);
        return $hours . ($hours === 1 ? ' hora' : ' horas');
    }
    return $minutes . ' minutos';
}

function v3_speed(int $kbps): string
{
    if ($kbps <= 0) return 'sem limite específico';
    if ($kbps >= 1000) return rtrim(rtrim(number_format($kbps / 1000, 1, ',', ''), '0'), ',') . ' Mbps';
    return $kbps . ' Kbps';
}

function v3_theme_current(): array
{
    if (!empty($GLOBALS['portal_v3_theme']) && is_array($GLOBALS['portal_v3_theme'])) {
        return $GLOBALS['portal_v3_theme'];
    }
    return portal_theme_visual(portal_theme_defaults());
}

function v3_theme_layout(?array $theme = null): string
{
    $theme = $theme ?: v3_theme_current();
    return strpos((string)($theme['theme_preset'] ?? ''), 'compact_') === 0 ? 'compact' : 'modern';
}

function v3_theme_css(?array $theme = null): string
{
    $theme = $theme ?: v3_theme_current();
    $variables = [
        'bg' => $theme['background_color'],
        'bg2' => $theme['background_secondary'],
        'panel' => $theme['panel_color'],
        'ink' => $theme['text_color'],
        'muted' => $theme['muted_color'],
        'brand' => $theme['primary_color'],
        'brand2' => $theme['secondary_color'],
        'accent-ink' => $theme['accent_text'],
        'brand-rgb' => $theme['primary_rgb'],
        'brand2-rgb' => $theme['secondary_rgb'],
        'line' => $theme['line_color'],
        'soft' => $theme['soft_color'],
        'hero1' => $theme['hero_primary'],
        'hero2' => $theme['hero_secondary'],
        'hero-ink' => $theme['hero_text'],
        'on-brand' => $theme['on_brand'],
        'footer-ink' => $theme['footer_text'],
        'notice-bg' => $theme['notice_color'],
        'notice-ink' => $theme['notice_text'],
        'popular-bg' => $theme['popular_color'],
        'popular-ink' => $theme['popular_text'],
    ];
    $css = ':root{';
    foreach ($variables as $name => $value) $css .= '--' . $name . ':' . (string)$value . ';';
    return '<style id="portal-v3-theme">' . $css . '}</style>';
}

function v3_brand_mark(?array $theme = null): string
{
    $theme = $theme ?: v3_theme_current();
    $brand = v3_h($theme['brand_name'] ?? 'FireSpot');
    $signal = '<span class="portal-signal" aria-hidden="true"><i></i><i></i><i></i><i></i></span>';
    if (!empty($theme['logo_url'])) {
        return $signal . '<div class="brand-mark has-logo"><img src="' . v3_h($theme['logo_url']) . '" alt="Logotipo de ' . $brand . '"></div>';
    }
    return $signal . '<div class="brand-mark" aria-label="' . $brand . '">' . v3_h($theme['logo_letter'] ?? 'F') . '</div>';
}

/** Banner confiável gerado pelo servidor; retorna vazio sempre que a regra não estiver pronta. */
function v3_ad_platform_banner(PDO $pdo,array $partner,string $placement,string $stage): string
{
    try{
        $runtime=fs_ad_platform_runtime($pdo,$partner,$placement,'paid',$stage);
        $choice=fs_ad_platform_inventory_choice($runtime,[]);
        if(!$choice||!in_array((string)$choice['provider'],['mock','google_ad_manager'],true))return '';
        $device=v3_device_context();
        $delivery=fs_ad_platform_delivery_begin($pdo,$partner,$choice,['mac'=>$device['mac']??'','ip'=>$device['ip']??'','journey'=>'paid','personalization_consent'=>!empty($_SESSION['ad_personalization_consent'])]);
        $id='fs-ad-'.preg_replace('/[^a-z0-9-]/','',(string)$placement).'-'.substr((string)$delivery['public_id'],0,8);
        $label='<span>Publicidade</span>';
        if((string)$delivery['provider']==='mock'){
            fs_ad_platform_delivery_event($pdo,(string)$delivery['token'],'impression',(int)$partner['id'],(int)(fs_partner_hotspot_id($partner)??0),'mock-banner');
            return '<aside class="v3-ad-banner v3-ad-banner--mock" aria-label="Publicidade simulada">'.$label.'<strong>Espaço publicitário de teste</strong><small>Nenhuma impressão ou receita real.</small></aside>';
        }
        $csrf=json_encode(csrf_token(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);$token=json_encode((string)$delivery['token'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);$path=json_encode((string)$delivery['ad_unit_path'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);$privacy=json_encode((string)$delivery['privacy_treatment'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);$testMode=json_encode(!empty($delivery['is_simulation']));
        return '<aside class="v3-ad-banner" aria-label="Publicidade">'.$label.'<div id="'.v3_h($id).'" class="v3-ad-banner-slot"></div></aside><script>(()=>{const slotId='.json_encode($id).',csrf='.$csrf.',token='.$token.',path='.$path.',privacy='.$privacy.',testMode='.$testMode.';window.googletag=window.googletag||{cmd:[]};if(!document.querySelector(\'script[data-fs-gpt]\')){const s=document.createElement(\'script\');s.async=true;s.src=\'https://securepubads.g.doubleclick.net/tag/js/gpt.js\';s.dataset.fsGpt=\'1\';document.head.appendChild(s);}googletag.cmd.push(()=>{if(testMode)googletag.setConfig({adsenseAttributes:{adsense_test_mode:\'on\'}});if(privacy===\'limited\')googletag.pubads().setPrivacySettings({limitedAds:true});else if(privacy===\'non_personalized\')googletag.pubads().setPrivacySettings({nonPersonalizedAds:true});const slot=googletag.defineSlot(path,[[320,50],[300,100]],slotId);if(!slot)return;slot.addService(googletag.pubads());googletag.pubads().addEventListener(\'impressionViewable\',event=>{if(event.slot!==slot)return;fetch(\'api/ad_platform_event.php\',{method:\'POST\',credentials:\'same-origin\',headers:{\'Content-Type\':\'application/x-www-form-urlencoded\'},body:new URLSearchParams({csrf,token,event:\'impression\'})}).catch(()=>{});});googletag.enableServices();googletag.display(slotId);});})();</script>';
    }catch(Throwable $error){
        error_log('[portal-v3 banner inventory] '.$error->getMessage());
        return '';
    }
}

function v3_fail(string $message, int $status = 503): void
{
    http_response_code($status);
    $safe = v3_h($message);
    $theme = v3_theme_current();
    echo '<!doctype html><html lang="pt-BR" data-v3-theme="' . v3_h($theme['theme_mode']) . '" data-v3-preset="' . v3_h($theme['theme_preset']) . '" data-v3-layout="' . v3_theme_layout($theme) . '" data-v3-show-title="' . (int)$theme['show_title'] . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="' . v3_h($theme['background_color']) . '"><title>' . v3_h($theme['brand_name'] ?? 'FireSpot') . '</title><link rel="stylesheet" href="assets/css/portal-v3.css?v=18">' . v3_theme_css($theme) . '</head><body><main class="shell narrow"><section class="panel">' . v3_brand_mark($theme) . '<h1>Não foi possível continuar</h1><p class="muted">' . $safe . '</p><a class="button secondary" href="../portal/index.php">Voltar</a></section></main></body></html>';
    exit;
}

function v3_public_error(Throwable $error, string $fallback = 'Não foi possível continuar agora. Tente novamente.'): string
{
    if ($error instanceof PDOException) {
        error_log('[portal-v3 database] ' . $error->getMessage());
        return $fallback;
    }
    if ($error instanceof InvalidArgumentException || $error instanceof RuntimeException) {
        $message = trim($error->getMessage());
        return $message !== '' ? substr($message, 0, 300) : $fallback;
    }
    error_log('[portal-v3 unexpected] ' . get_class($error) . ': ' . $error->getMessage());
    return $fallback;
}

function v3_resolve_partner(PDO $pdo): ?array
{
    if (!fs_payment_schema_ready($pdo)) return null;
    $fastRaw = trim((string) ($_GET['hotspot'] ?? $_GET['fast_id'] ?? $_GET['id'] ?? ($_SESSION['portal_hotspot_code'] ?? $_SESSION['portal_fast_id'] ?? '')));
    $fast = preg_replace('/[^A-Za-z0-9_-]/', '', $fastRaw);
    if ($fast !== '') {
        $_SESSION['portal_hotspot_code'] = $fast;
        $_SESSION['portal_fast_id'] = $fast;
    }
    $partner = fs_guest_partner($pdo, $fast, true);
    if (!$partner) return null;
    $hotspotCode = fs_partner_hotspot_public_code($partner);
    if ($hotspotCode !== '') {
        $_SESSION['portal_hotspot_code'] = $hotspotCode;
        $_SESSION['portal_fast_id'] = $hotspotCode;
    }
    $_SESSION['portal_hotspot_id'] = fs_partner_hotspot_id($partner);
    $_SESSION['portal_partner_id'] = (int)$partner['id'];
    $_SESSION['portal_v3_active'] = true;
    unset($_SESSION['portal_v2_active']);
    return $partner;
}

function v3_hotspot_code(array $partner): string
{
    return fs_partner_hotspot_public_code($partner);
}

function v3_hotspot_query(array $partner): string
{
    return 'hotspot=' . rawurlencode(v3_hotspot_code($partner));
}

function v3_context_for_order(PDO $pdo, array $order, array $currentPartner): array
{
    if ((int)($order['partner_id'] ?? 0) !== (int)($currentPartner['id'] ?? 0)) {
        throw new RuntimeException('Pedido não pertence a este estabelecimento.');
    }
    if (!fs_partner_hotspots_schema_ready($pdo)) return $currentPartner;
    $context = fs_partner_hotspot_for_operation($pdo,(int)$order['partner_id'],isset($order['hotspot_id']) ? (int)$order['hotspot_id'] : null);
    if (!$context) throw new RuntimeException('A instalação original deste pedido não está disponível.');
    return $context;
}

function v3_context(PDO $pdo): array
{
    if (!fs_payment_schema_ready($pdo)) {
        v3_fail('O Portal V3 ainda não foi habilitado no banco de dados.');
    }
    $partner = v3_resolve_partner($pdo);
    if (!$partner) v3_fail('Estabelecimento não encontrado ou Portal V3 não ativado.', 404);
    try {
        $company = company_get();
    } catch (Throwable $e) {
        $company = [];
    }
    $GLOBALS['portal_v3_theme'] = portal_theme_get($pdo, $partner, $company);
    return $partner;
}

function v3_device_context(): array
{
    $hotspot = $_SESSION['hotspot_ctx']['data'] ?? [];
    $device = $_SESSION['hotspot_device_info'] ?? [];
    return [
        'mac' => (string) ($device['mac'] ?? $hotspot['mac'] ?? ''),
        'ip' => (string) ($device['ip'] ?? $hotspot['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
    ];
}

/**
 * Monta a mesma identidade usada na concessão real da cortesia.
 * A pré-validação e o endpoint final precisam enxergar o mesmo dispositivo,
 * conta e acessos existentes para não divergirem antes/depois do anúncio.
 */
function v3_courtesy_context(PDO $pdo, array $partner, bool $adCompleted, string $source): array
{
    $device = v3_device_context();
    $mac = fs_guest_normalize_mac((string) ($device['mac'] ?? ''));
    $ip = (string) ($device['ip'] ?? '');
    $deviceKey = $mac !== '' ? $mac : ($ip !== '' ? 'IP:' . $ip : '');
    $username = trim((string) ($_SESSION['cliente_username'] ?? ''));
    $hasPaid = $mac !== '' && fs_guest_find_credit_by_mac($pdo, (int) $partner['id'], $mac) !== null;
    $isProvider = false;

    if ($username !== '') {
        try {
            $state = fs_courtesy_shadow_radius_state($username);
            $isProvider = !empty($state['is_provider']);
            $hasPaid = $hasPaid || !empty($state['has_active_paid']);
        } catch (Throwable $e) {
            error_log('[portal-v3 courtesy context] ' . $e->getMessage());
        }
    }

    return [
        'partner_id' => (int) $partner['id'],
        'hotspot_id' => (int) (fs_partner_hotspot_id($partner) ?? 0),
        'portal' => 'v3',
        'source' => $source,
        'device_key' => $deviceKey,
        'mac' => $mac,
        'ip' => $ip,
        'account_key' => $username,
        'account_username' => $username,
        'associated_device' => $username !== '' && fs_courtesy_shadow_associated($pdo, $username, $mac),
        'ad_completed' => $adCompleted,
        'has_active_paid' => $hasPaid,
        'is_provider' => $isProvider,
    ];
}

/**
 * Avalia a cortesia antes da propaganda. `ad_completed=true` é proposital:
 * esta consulta responde se o visitante poderá receber a cortesia depois de
 * concluir o anúncio; a prova real continua obrigatória no endpoint final.
 */
function v3_courtesy_preflight(PDO $pdo, array $partner, ?array $policy = null): array
{
    try {
        $policy = $policy ?? fs_courtesy_policy_resolve($pdo,(int)$partner['id'],fs_partner_hotspot_id($partner));
        $rollout = fs_courtesy_rollout_resolve($pdo, (int) $partner['id'], 'v3', $policy);
        if (($rollout['effective_mode'] ?? 'legacy') !== 'enforce') {
            return fs_courtesy_result(false, 'COURTESY_TEMPORARILY_UNAVAILABLE', 'O acesso gratuito está temporariamente indisponível.');
        }
        return fs_courtesy_check($pdo, v3_courtesy_context($pdo, $partner, true, 'v3_preflight'));
    } catch (Throwable $e) {
        error_log('[portal-v3 courtesy preflight] ' . get_class($e) . ': ' . $e->getMessage());
        return fs_courtesy_result(false, 'COURTESY_UNAVAILABLE', 'Não foi possível consultar o acesso gratuito agora.');
    }
}

function v3_courtesy_retry_timestamp(array $decision): ?int
{
    $retryAt = fs_courtesy_time($decision['retry_at'] ?? null);
    return $retryAt !== null && $retryAt > time() ? $retryAt : null;
}

function v3_courtesy_wait_label(int $seconds): string
{
    $seconds = max(1, $seconds);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) return $days . 'd ' . str_pad((string) $hours, 2, '0', STR_PAD_LEFT) . 'h';
    if ($hours > 0) return $hours . 'h ' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) . 'min';
    if ($minutes > 0) return $minutes . 'min ' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) . 's';
    return $seconds . 's';
}

/**
 * Credenciais RADIUS só podem ser enviadas ao endpoint de login configurado
 * para a própria unidade. O valor recebido do redirect do hotspot é contexto
 * não confiável e nunca pode virar um destino arbitrário de formulário.
 */
function v3_hotspot_login_url(array $partner, string $url): ?string
{
    return fs_hotspot_login_url($partner, $url);
}

/**
 * E-mail técnico exigido pelo provedor, sem coletar dado pessoal do visitante.
 * O valor é aleatório por sessão e não é usado para cadastro ou comunicação.
 */
function v3_anonymous_payer_email(): string
{
    $saved = strtolower(trim((string) ($_SESSION['portal_v3_payer_email'] ?? '')));
    if ($saved !== '' && filter_var($saved, FILTER_VALIDATE_EMAIL)) return $saved;

    $domain = strtolower(trim((string) env('PAYMENT_ANONYMOUS_EMAIL_DOMAIN', 'firecdn.com.br')));
    if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
        $domain = 'firecdn.com.br';
    }
    $saved = 'guest.' . bin2hex(random_bytes(12)) . '@' . $domain;
    $_SESSION['portal_v3_payer_email'] = $saved;
    return $saved;
}

function v3_order_token(string $publicId): string
{
    return (string) ($_SESSION['portal_v3_orders'][$publicId] ?? '');
}

function v3_remember_pending_pix(string $publicId, array $payment): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/',$publicId)) return null;
    $pix = fs_payment_pix_display_payload($payment);
    if (!$pix) return null;
    $stored = $_SESSION['portal_v3_pending_pix'] ?? [];
    if (!is_array($stored)) $stored = [];
    unset($stored[$publicId]);
    $stored[$publicId] = $pix;
    if (count($stored) > 3) $stored = array_slice($stored,-3,null,true);
    $_SESSION['portal_v3_pending_pix'] = $stored;
    return $pix;
}

function v3_pending_pix(string $publicId): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/',$publicId)) return null;
    $pix = $_SESSION['portal_v3_pending_pix'][$publicId] ?? null;
    return is_array($pix) ? fs_payment_pix_display_payload($pix) : null;
}

function v3_forget_pending_pix(string $publicId): void
{
    unset($_SESSION['portal_v3_pending_pix'][$publicId]);
}

function v3_forget_order(string $publicId, int $partnerId): void
{
    v3_forget_pending_pix($publicId);
    unset($_SESSION['portal_v3_orders'][$publicId]);
    $cookieName = v3_recovery_cookie_name($partnerId);
    $cookieValue = strtolower((string)($_COOKIE[$cookieName] ?? ''));
    if ($publicId !== '' && str_starts_with($cookieValue, strtolower($publicId) . '.')) {
        v3_clear_recovery_cookie($partnerId);
    }
}

function v3_store_order_token(string $publicId, string $token): void
{
    if (!isset($_SESSION['portal_v3_orders']) || !is_array($_SESSION['portal_v3_orders'])) {
        $_SESSION['portal_v3_orders'] = [];
    }
    $_SESSION['portal_v3_orders'][$publicId] = $token;
    if (count($_SESSION['portal_v3_orders']) > 8) {
        $_SESSION['portal_v3_orders'] = array_slice($_SESSION['portal_v3_orders'], -8, null, true);
    }
}

function v3_recovery_cookie_name(int $partnerId): string
{
    return 'HSV3REC_' . max(0, $partnerId);
}

function v3_set_recovery_cookie(int $partnerId, string $publicId, string $token): void
{
    if ($partnerId <= 0 || !preg_match('/^[a-f0-9]{32}$/', $publicId) || !preg_match('/^[a-f0-9]{64}$/', $token)) return;
    $name = v3_recovery_cookie_name($partnerId);
    $value = $publicId . '.' . $token;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (!headers_sent()) {
        setcookie($name, $value, [
            'expires' => time() + (400 * 86400),
            'path' => '/portal-v3',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_COOKIE[$name] = $value;
}

function v3_clear_recovery_cookie(int $partnerId): void
{
    $name = v3_recovery_cookie_name($partnerId);
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (!headers_sent()) {
        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => '/portal-v3',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    unset($_COOKIE[$name]);
}

function v3_remember_order(array $order, string $token): void
{
    $publicId = strtolower((string) ($order['public_id'] ?? ''));
    $partnerId = (int) ($order['partner_id'] ?? 0);
    if ($publicId === '' || $partnerId <= 0 || $token === '') return;
    v3_store_order_token($publicId, $token);
    v3_set_recovery_cookie($partnerId, $publicId, $token);
}

function v3_recovery_candidate(PDO $pdo, array $partner, string $publicId, string $token): ?array
{
    $order = fs_guest_order_for_session($pdo, $publicId, $token);
    if (!$order || (int) $order['partner_id'] !== (int) $partner['id']) return null;
    if (empty($order['device_mac'])) {
        $device = v3_device_context();
        $mac = fs_guest_normalize_mac((string) ($device['mac'] ?? ''));
        if ($mac !== '') {
            $pdo->prepare('UPDATE guest_orders SET device_mac=?,updated_at=NOW() WHERE id=? AND device_mac IS NULL')
                ->execute([$mac, (int) $order['id']]);
            $order['device_mac'] = $mac;
        }
    }

    if ($order['status'] === 'paid') {
        try {
            $order = fs_guest_grant_access($pdo, (int) $order['id']);
        } catch (Throwable $e) {
            return null;
        }
        $credit = fs_guest_credit_balance($pdo, $order);
        if ((int) $credit['remaining_seconds'] > 0) {
            $order['_credit'] = $credit;
            $order['_recovery_kind'] = 'credit';
            v3_remember_order($order, $token);
            return $order;
        }
        return null;
    }

    if ($order['status'] === 'pending' && !empty($order['provider_payment_id'])) {
        if (!fs_guest_payment_is_expired($order)) {
            $order['_recovery_kind'] = 'pending';
            v3_remember_order($order, $token);
            return $order;
        }
    }
    return null;
}

/**
 * Recupera uma compra V3 sem cadastro pessoal.
 * Prioridade: cookie/token do pedido, sessão atual e MAC entregue pelo MikroTik.
 */
function v3_recover_access(PDO $pdo, array $partner): ?array
{
    $partnerId = (int) ($partner['id'] ?? 0);
    if ($partnerId <= 0) return null;

    $cookieName = v3_recovery_cookie_name($partnerId);
    $cookieValue = (string) ($_COOKIE[$cookieName] ?? '');
    if (preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/', strtolower($cookieValue), $match)) {
        $candidate = v3_recovery_candidate($pdo, $partner, $match[1], $match[2]);
        if ($candidate) return $candidate;
        v3_clear_recovery_cookie($partnerId);
    }

    $sessionOrders = $_SESSION['portal_v3_orders'] ?? [];
    if (is_array($sessionOrders)) {
        foreach (array_reverse($sessionOrders, true) as $publicId => $token) {
            $candidate = v3_recovery_candidate($pdo, $partner, (string) $publicId, (string) $token);
            if ($candidate) return $candidate;
        }
    }

    $device = v3_device_context();
    $mac = fs_guest_normalize_mac((string) ($device['mac'] ?? ''));
    if ($mac === '') return null;

    $order = fs_guest_find_credit_by_mac($pdo, $partnerId, $mac);
    if (!$order) $order = fs_guest_find_pending_by_mac($pdo, $partnerId, $mac);
    if (!$order) return null;

    $token = fs_guest_rotate_order_token($pdo, (int) $order['id']);
    $order['_recovery_kind'] = $order['status'] === 'paid' ? 'credit' : 'pending';
    v3_remember_order($order, $token);
    return $order;
}

/**
 * Consome uma única tentativa de continuação automática logo após o Pix.
 * A captura precisa ser recente, pertencer a uma janela Pix e ainda não
 * possuir accounting ativo. A janela pode ter sido entregue pela WebView ou
 * encerrada pelo temporizador do RouterOS; retornos normais continuam usando
 * a confirmação "Bem-vindo de volta".
 */
function v3_claim_recent_paid_handoff(array $order, ?int $now = null): bool
{
    if ((string)($order['status'] ?? '') !== 'paid'
        || (string)($order['payment_method'] ?? '') !== 'pix'
        || empty($order['payment_window_token'])
        || !empty($order['_credit']['online'])) {
        return false;
    }
    $publicId = strtolower(trim((string)($order['public_id'] ?? '')));
    if (!preg_match('/^[a-f0-9]{32}$/',$publicId)) return false;
    $paidAt = fs_guest_local_datetime_timestamp((string)($order['paid_at'] ?? ''));
    $now = $now ?? time();
    if ($paidAt <= 0 || $paidAt > $now + 30 || ($now - $paidAt) > 600) return false;

    $claims = $_SESSION['portal_v3_paid_handoff_claims'] ?? [];
    if (!is_array($claims)) $claims = [];
    foreach ($claims as $id => $claimedAt) {
        if ((int)$claimedAt < $now - 600) unset($claims[$id]);
    }
    if (isset($claims[$publicId])) {
        $_SESSION['portal_v3_paid_handoff_claims'] = $claims;
        return false;
    }
    $claims[$publicId] = $now;
    $_SESSION['portal_v3_paid_handoff_claims'] = $claims;
    return true;
}

/**
 * Resume o retorno de uma compra sem confundir saldo agregado com uma sessao
 * do aparelho atual. O accounting precisa coincidir com instalacao, NAS, MAC
 * e IP antes de considerarmos que este navegador ja esta conectado.
 */
function v3_paid_return_state(PDO $pdo, array $order): array
{
    $credit = isset($order['_credit']) && is_array($order['_credit'])
        ? $order['_credit']
        : fs_guest_credit_balance($pdo,$order);
    $device = v3_device_context();
    $currentMac = fs_guest_normalize_mac((string)($device['mac'] ?? ''));
    $orderMac = fs_guest_normalize_mac((string)($order['device_mac'] ?? ''));
    $session = null;
    try {
        $session = fs_guest_radius_active_session_for_device($pdo,$order,$device);
    } catch (Throwable $error) {
        error_log('[portal-v3 paid return session] ' . get_class($error));
    }
    $sessionActive = is_array($session);
    $radiusHandoff = (string)($order['payment_access_mode'] ?? '') === FS_GUEST_PAYMENT_ACCESS_RADIUS;
    $connected = $sessionActive
        && (!$radiusHandoff || (string)($order['radius_coa_status'] ?? '') === 'applied');
    $onlineElsewhere = !empty($credit['online']) && !$sessionActive;
    $sameDevice = $currentMac !== '' && $orderMac !== '' && hash_equals($orderMac,$currentMac);
    return [
        'credit' => $credit,
        'remaining_seconds' => max(0,(int)($credit['remaining_seconds'] ?? 0)),
        'connected' => $connected,
        'session_active' => $sessionActive,
        'handoff_pending' => $sessionActive && !$connected,
        'online_elsewhere' => $onlineElsewhere,
        'session' => $session,
        'current_mac' => $currentMac,
        'same_device' => $sameDevice,
        'connect_allowed' => !$sessionActive && !$onlineElsewhere && $sameDevice && (int)($credit['remaining_seconds'] ?? 0) > 0,
    ];
}

/** Retorna somente o destino HTTP(S) original, sem credenciais ou zona local. */
function v3_paid_return_destination(array $partner, ?PDO $pdo = null): ?string
{
    $raw = (string)($_SESSION['hotspot_ctx']['data']['link-orig-esc'] ?? '');
    if ($raw === '' || strlen($raw) > 1200 || preg_match('/[\x00-\x1F\x7F]/',$raw)) return null;
    $decoded = html_entity_decode($raw,ENT_QUOTES | ENT_HTML5,'UTF-8');
    $candidates = array_values(array_unique([$decoded,rawurldecode($decoded)]));
    $blockedHosts = array_filter([
        strtolower(rtrim(trim((string)($partner['gateway_ip'] ?? '')),'.')),
        strtolower(rtrim(trim((string)($partner['dns_name'] ?? '')),'.')),
        strtolower((string)(parse_url(v3_public_base_url($pdo),PHP_URL_HOST) ?: '')),
    ]);
    foreach ($candidates as $candidate) {
        if ($candidate === '' || strlen($candidate) > 1200 || preg_match('/[\x00-\x1F\x7F]/',$candidate)) continue;
        if (!filter_var($candidate,FILTER_VALIDATE_URL)) continue;
        $parts = parse_url($candidate);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')),['http','https'],true)) continue;
        if (isset($parts['user']) || isset($parts['pass'])) continue;
        $host = strtolower(rtrim((string)($parts['host'] ?? ''),'.'));
        if ($host === '' || in_array($host,$blockedHosts,true) || fs_hotspot_private_dns_name($host)) continue;
        return $candidate;
    }
    return null;
}

/**
 * Autoriza uma unica tentativa automatica para o desafio atual do RouterOS.
 * Um retorno do mesmo desafio cai na tela manual e, assim, nunca cria loop.
 */
function v3_claim_paid_return_reconnect(array $order, array $partner, array $state, ?int $now = null): bool
{
    if ((string)($order['status'] ?? '') !== 'paid'
        || empty($state['connect_allowed'])
        || !empty($state['connected'])) return false;
    $publicId = strtolower(trim((string)($order['public_id'] ?? '')));
    if (!preg_match('/^[a-f0-9]{32}$/',$publicId)) return false;

    $ctx = $_SESSION['hotspot_ctx'] ?? [];
    $capturedAt = (int)($ctx['ts'] ?? 0);
    $now = $now ?? time();
    if ($capturedAt <= 0 || $capturedAt < $now - 300 || $capturedAt > $now + 30) return false;
    $data = is_array($ctx['data'] ?? null) ? $ctx['data'] : [];
    $loginUrl = v3_hotspot_login_url($partner,(string)($data['link-login-only'] ?? $data['link-login'] ?? ''));
    if ($loginUrl === null) return false;
    $chapId = (string)($data['chap-id'] ?? '');
    $challenge = (string)($data['chap-challenge'] ?? '');
    if (fs_hotspot_login_password('probe',$chapId,$challenge) === null) return false;

    $fingerprint = hash('sha256',implode("\0",[
        $publicId,
        (string)($state['current_mac'] ?? ''),
        $loginUrl,
        $chapId,
        $challenge,
        (string)$capturedAt,
    ]));
    $claims = $_SESSION['portal_v3_paid_return_claims'] ?? [];
    if (!is_array($claims)) $claims = [];
    foreach ($claims as $key => $claimedAt) {
        if ((int)$claimedAt < $now - 600) unset($claims[$key]);
    }
    if (isset($claims[$fingerprint])) {
        $_SESSION['portal_v3_paid_return_claims'] = $claims;
        return false;
    }
    $claims[$fingerprint] = $now;
    $_SESSION['portal_v3_paid_return_claims'] = $claims;
    return true;
}

function v3_public_base_url(?PDO $pdo = null): string
{
    return fs_public_base_url($pdo);
}
