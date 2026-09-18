<?php
declare(strict_types=1);
@ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../_boot.php';
require_once __DIR__ . '/../../app/courtesy_access.php';
require_once __DIR__ . '/../../app/courtesy_shadow.php';
require_once __DIR__ . '/../../app/partner_ads.php';
require_once __DIR__ . '/../../app/ad_monetization.php';
require_once __DIR__ . '/../../app/ad_platform.php';

function v3_courtesy_response(int $status,array $payload):void{http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')v3_courtesy_response(405,['ok'=>false,'error'=>'Método não permitido.']);
if(!csrf_check($_POST['csrf']??''))v3_courtesy_response(403,['ok'=>false,'error'=>'Sessão expirada. Recarregue a página.']);
try {
    $pdo = db();
    $partner = v3_resolve_partner($pdo);
    if (!$partner) throw new RuntimeException('Estabelecimento indisponível.');

    $portalConfig=fs_portal_config_for_partner($pdo,$partner);
    if (!fs_portal_config_has_courtesy($portalConfig)) throw new RuntimeException('Esta configuração não oferece cortesia.');

    $policy = fs_courtesy_policy_resolve($pdo,(int)$partner['id'],fs_partner_hotspot_id($partner));
    $preflight = fs_courtesy_check($pdo, v3_courtesy_context($pdo, $partner, true, 'v3_grant_preflight'));
    $activeReconnect = (string)($preflight['code'] ?? '') === 'ACTIVE_GRANT';
    $context = v3_courtesy_context($pdo, $partner, false, 'v3_courtesy');
    $username = (string)$context['account_username'];

    // O visitante nunca deve concluir uma campanha que o V3 não possa recompensar.
    $rollout = fs_courtesy_rollout_resolve($pdo, (int)$partner['id'], 'v3', $policy);
    if (($rollout['effective_mode'] ?? 'legacy') !== 'enforce') {
        if (($rollout['effective_mode'] ?? 'legacy') === 'shadow') {
            fs_courtesy_shadow_capture($pdo, ['allowed' => false, 'code' => 'V3_SHADOW_ONLY'], $context);
        }
        error_log('[portal-v3 courtesy] grant blocked partner=' . (int)$partner['id']
            . ' requested=' . (string)($rollout['requested_mode'] ?? 'legacy')
            . ' effective=' . (string)($rollout['effective_mode'] ?? 'legacy')
            . ' blocked_by=' . (string)($rollout['blocked_by'] ?? 'none'));
        v3_courtesy_response(503, [
            'ok' => false,
            'error' => 'Não foi possível conectar agora. Tente novamente em alguns instantes.',
            'code' => 'COURTESY_TEMPORARILY_UNAVAILABLE',
        ]);
    }

    $proofToken = trim((string)($_POST['ad_token'] ?? ''));
    $platformProof = (string)($_POST['ad_platform'] ?? '') === '1';
    $platformDelivery = null;
    $adCompleted = empty($policy['requires_ad']);
    // Uma credencial ainda ativa pode ser reconectada sem obrigar o visitante
    // a assistir novamente. Se o saldo tiver terminado, a fachada reavalia e
    // a nova concessão continuará bloqueada sem uma prova publicitária válida.
    if (!empty($policy['requires_ad']) && !$activeReconnect) {
        if($platformProof){
            $platformDelivery=fs_ad_platform_delivery_consume($pdo,$proofToken,(int)$partner['id'],(int)(fs_partner_hotspot_id($partner)??0));
            $adCompleted=true;
        }else{
            $proof = $_SESSION['courtesy_ad_proofs'][$proofToken] ?? null;
            $eligibleIds = array_map(static fn($ad) => (int)$ad['id'], partner_ads_eligible($pdo, $partner));
            $adCompleted = is_array($proof)
                && (int)($proof['partner_id'] ?? 0) === (int)$partner['id']
                && (int)($proof['hotspot_id'] ?? 0) === (int)(fs_partner_hotspot_id($partner) ?? 0)
                && in_array((int)($proof['ad_id'] ?? 0), $eligibleIds, true)
                && (int)($proof['ready_at'] ?? PHP_INT_MAX) <= time()
                && (int)($proof['expires_at'] ?? 0) > time();
            if ($adCompleted && strlen($proofToken) === 64) {
                $delivery = fs_ad_delivery_complete($pdo, $proofToken);
                $adCompleted = (int)$delivery['partner_id'] === (int)$partner['id']
                    && (int)($delivery['hotspot_id'] ?? 0) === (int)(fs_partner_hotspot_id($partner) ?? 0)
                    && (int)$delivery['ad_id'] === (int)$proof['ad_id'];
            }
        }
        if (!$adCompleted) throw new RuntimeException('A prova do anúncio é inválida ou expirou.');
    }
    $context['ad_completed'] = $adCompleted;
    $context['idempotency_key'] = fs_courtesy_access_idempotency($context, 'v3_courtesy');
    $result = fs_courtesy_access_issue($pdo, $context);
    if (empty($result['handled']) || empty($result['allowed']) || empty($result['grant']['username']) || empty($result['grant']['password'])) {
        v3_courtesy_response(409, [
            'ok' => false,
            'error' => (string)($result['message'] ?? 'Acesso gratuito indisponível.'),
            'code' => $result['code'] ?? 'DENIED',
            'retry_at' => $result['retry_at'] ?? null,
        ]);
    }

    $_SESSION['portal_v3_courtesy_credentials'] = [
        'partner_id' => (int)$partner['id'],
        'hotspot_id' => (int)(fs_partner_hotspot_id($partner) ?? 0),
        'username' => (string)$result['grant']['username'],
        'password' => (string)$result['grant']['password'],
        'expires_at' => time() + 120,
    ];
    $_SESSION['courtesy_active_grant'] = [
        'public_id' => (string)($result['grant']['public_id'] ?? ''),
        'portal' => 'v3',
        'partner_id' => (int)$partner['id'],
        'hotspot_id' => (int)(fs_partner_hotspot_id($partner) ?? 0),
        'account_username' => $username,
    ];
    if ($proofToken !== '' && strlen($proofToken) === 64) {
        if($platformProof)fs_ad_platform_delivery_attach_access($pdo,$proofToken,(int)$partner['id'],(int)(fs_partner_hotspot_id($partner)??0),(string)$result['grant']['username']);
        else fs_ad_delivery_attach_access($pdo, $proofToken, (string)$result['grant']['username']);
    }
    if ($proofToken !== '') unset($_SESSION['courtesy_ad_proofs'][$proofToken]);
    v3_courtesy_response(200, ['ok' => true, 'connect_url' => 'courtesy_connect.php']);
} catch (Throwable $e) {
    error_log('[portal-v3 courtesy] grant error ' . get_class($e) . ': ' . $e->getMessage());
    v3_courtesy_response(409, [
        'ok' => false,
        'error' => 'Não foi possível conectar agora. Tente novamente.',
        'code' => 'COURTESY_UNAVAILABLE',
    ]);
}
