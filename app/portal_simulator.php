<?php

declare(strict_types=1);

require_once __DIR__ . '/company.php';
require_once __DIR__ . '/courtesy_policy.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/partner_ads.php';
require_once __DIR__ . '/partner_purpose.php';
require_once __DIR__ . '/portal_configuration.php';
require_once __DIR__ . '/partner_hotspot_commercial.php';
require_once __DIR__ . '/portal_theme.php';
require_once __DIR__ . '/public_url.php';

/**
 * Monta uma fotografia somente leitura da experiência pública da unidade.
 * O simulador nunca chama APIs de pagamento, RADIUS, MikroTik ou métricas.
 */
function fs_portal_simulator_snapshot(PDO $pdo, array $partner, ?int $portalConfigId = null): array
{
    $partnerId = (int)($partner['id'] ?? 0);
    if ($partnerId <= 0) throw new InvalidArgumentException('Estabelecimento inválido para simulação.');

    $portalConfig = fs_portal_config_for_partner($pdo,$partner,$portalConfigId);
    $purpose = fs_portal_config_legacy_purpose($portalConfig);
    $hasSales = fs_portal_config_has_sales($portalConfig);
    $hasCourtesy = fs_portal_config_has_courtesy($portalConfig);
    $subscriberRuntime = fs_portal_config_subscriber_enabled($pdo,$portalConfig);
    $subscriber = (string)($portalConfig['subscriber_access_mode']??'inherit')==='allow' || $subscriberRuntime;
    $warnings = [];
    $plans = [];
    $ads = [];

    if ($hasSales) {
        try {
            $plans = fs_guest_plans($pdo,$partner);
        } catch (Throwable $e) {
            $warnings[] = 'O catálogo pago não pôde ser carregado.';
        }
    }

    try {
        $policy = fs_courtesy_policy_resolve($pdo,$partnerId,fs_partner_hotspot_id($partner));
    } catch (Throwable $e) {
        $policy = fs_courtesy_policy_normalize([
            'enabled' => 0,
            'grant_minutes' => max(1,(int)($partner['free_minutes'] ?? 20)),
            'auth_mode' => (int)($partner['require_auth'] ?? 1) === 1 ? 'account_device' : 'anonymous',
            'requires_ad' => fs_portal_config_is_sponsored($portalConfig) ? 1 : 0,
            'device_max_grants' => $partner['max_uses_per_device'] ?? 1,
            'device_period_minutes' => $partner['window_per_device_minutes'] ?? 1440,
        ]);
        $warnings[] = 'A política unificada de cortesia não pôde ser carregada.';
    }

    if ((int)($portalConfig['promotional_ads_enabled'] ?? 0) === 1) {
        try {
            $ads = partner_ads_eligible($pdo,$partner);
        } catch (Throwable $e) {
            $warnings[] = 'As campanhas elegíveis não puderam ser carregadas.';
        }
    }

    $theme = portal_theme_get($pdo,$partner,company_get());
    $publicHost = fs_public_host($pdo);
    $authRequired = in_array((string)($policy['auth_mode'] ?? 'anonymous'),['account','account_device'],true);
    $requiresAd = !empty($policy['requires_ad']);

    if ($hasSales && !$plans) $warnings[] = 'A finalidade oferece venda, mas não existe plano ativo disponível.';
    if ($hasCourtesy && empty($policy['enabled'])) $warnings[] = 'A finalidade oferece cortesia, mas a política está desativada.';
    if ($hasCourtesy && $requiresAd && !$ads) $warnings[] = 'A cortesia exige anúncio, mas não existe campanha elegível.';
    if (!$hasCourtesy && $ads) $warnings[] = 'Existem campanhas ativas fora da jornada de cortesia; serão usadas somente nos pontos promocionais permitidos.';
    if ($subscriber && !$subscriberRuntime) $warnings[] = 'O benefício FIRENETWORK está no rascunho, mas os gates de rollout ainda impedem a jornada real.';
    if (isset($partner['hotspot_active']) && (int)$partner['hotspot_active'] !== 1) $warnings[] = 'A instalação selecionada está inativa e não aceita novas jornadas reais.';

    return [
        'partner' => [
            'id' => $partnerId,
            'code' => (string)($partner['code'] ?? ''),
            'name' => (string)($partner['name'] ?? 'Estabelecimento'),
            'active' => (int)($partner['active'] ?? 0) === 1,
            'purpose' => $purpose,
            'purpose_label' => fs_portal_config_label($portalConfig),
            'portal_config_revision' => (int)($portalConfig['revision'] ?? 0),
            'portal_config_state' => (string)($portalConfig['state'] ?? 'compatibility'),
            'portal_mode' => (string)($partner['portal_mode'] ?? 'inherit'),
            'hotspot_id' => (int)($partner['hotspot_id'] ?? 0),
            'hotspot_code' => (string)($partner['hotspot_code'] ?? $partner['code'] ?? ''),
            'hotspot_name' => (string)($partner['hotspot_name'] ?? 'Principal'),
            'ads_enabled' => (int)($portalConfig['promotional_ads_enabled'] ?? 0) === 1,
            'allow_global_ads' => (int)($portalConfig['allow_global_ads'] ?? 0) === 1,
        ],
        'capabilities' => [
            'sales' => $hasSales,
            'courtesy' => $hasCourtesy,
            'courtesy_enabled' => $hasCourtesy && !empty($policy['enabled']),
            'auth_required' => $authRequired,
            'requires_ad' => $hasCourtesy && $requiresAd,
            'subscriber' => $subscriber,
            'lead_capture' => (int)($portalConfig['lead_capture_enabled'] ?? 0) === 1,
        ],
        'navigation' => [
            'welcome_screen_enabled' => fs_portal_config_welcome_enabled($portalConfig),
            'single_option_direct_enabled' => fs_portal_config_single_option_direct_enabled($portalConfig),
        ],
        'policy' => [
            'grant_minutes' => (int)($policy['grant_minutes'] ?? 0),
            'credit_validity_minutes' => (int)($policy['credit_validity_minutes'] ?? 0),
            'consumption_mode' => (string)($policy['consumption_mode'] ?? 'online'),
            'auth_mode' => (string)($policy['auth_mode'] ?? 'anonymous'),
            'enforcement_method' => (string)($policy['enforcement_method'] ?? 'radius'),
            'device_max_grants' => $policy['device_max_grants'] ?? null,
            'device_period_minutes' => $policy['device_period_minutes'] ?? null,
        ],
        'plans' => array_map(static fn(array $plan): array => [
            'id' => (int)$plan['id'],
            'source' => (string)$plan['source'],
            'name' => (string)$plan['name'],
            'description' => (string)($plan['description'] ?? ''),
            'price_cents' => (int)$plan['price_cents'],
            'duration_minutes' => (int)$plan['duration_minutes'],
            'download_kbps' => (int)$plan['download_kbps'],
            'upload_kbps' => (int)$plan['upload_kbps'],
        ],$plans),
        'ads' => array_map(static fn(array $ad): array => [
            'id' => (int)$ad['id'],
            'title' => (string)$ad['title'],
            'media_type' => partner_ads_media_type((string)($ad['media_type'] ?? 'image')),
            'media_url' => partner_ads_media_url($ad),
            'poster_url' => (string)($ad['poster_url'] ?? ''),
            'fit_mode' => partner_ads_fit_mode((string)($ad['fit_mode'] ?? 'contain')),
            'has_offer' => trim((string)($ad['link_url'] ?? '')) !== '',
            'interest_button_text' => partner_ads_button_text($ad['interest_button_text'] ?? null,'Tenho interesse'),
            'skip_button_text' => partner_ads_button_text($ad['skip_button_text'] ?? null,'Pular e conectar'),
            'duration_sec' => max(5,min(180,(int)($ad['duration_sec'] ?? 15))),
            'scope' => empty($ad['partner_id']) ? 'global' : 'partner',
        ],$ads),
        'theme' => [
            'preset' => (string)$theme['theme_preset'],
            'mode' => (string)$theme['theme_mode'],
            'layout' => strncmp((string)$theme['theme_preset'],'compact_',8) === 0 ? 'compact' : 'modern',
            'show_title' => (int)$theme['show_title'] === 1,
            'brand_name' => (string)$theme['brand_name'],
            'logo_url' => (string)($theme['logo_url'] ?? ''),
            'logo_letter' => (string)($theme['logo_letter'] ?? 'F'),
            'background_color' => (string)$theme['background_color'],
            'background_secondary' => (string)$theme['background_secondary'],
            'panel_color' => (string)$theme['panel_color'],
            'text_color' => (string)$theme['text_color'],
            'muted_color' => (string)$theme['muted_color'],
            'primary_color' => (string)$theme['primary_color'],
            'secondary_color' => (string)$theme['secondary_color'],
            'primary_rgb' => (string)$theme['primary_rgb'],
            'line_color' => (string)$theme['line_color'],
            'soft_color' => (string)$theme['soft_color'],
            'hero_primary' => (string)$theme['hero_primary'],
            'hero_secondary' => (string)$theme['hero_secondary'],
            'hero_text' => (string)$theme['hero_text'],
            'on_brand' => (string)$theme['on_brand'],
            'footer_text' => (string)$theme['footer_text'],
            'popular_color' => (string)$theme['popular_color'],
            'popular_text' => (string)$theme['popular_text'],
        ],
        'public_host' => $publicHost,
        'warnings' => array_values(array_unique($warnings)),
    ];
}
