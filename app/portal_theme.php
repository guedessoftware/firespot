<?php

declare(strict_types=1);

/**
 * Identidade visual exclusiva do Portal V3.
 *
 * O tema fica separado das configurações dos portais clássico e V2 para que
 * cada estabelecimento possa personalizar sua venda anônima sem regressões.
 */

function portal_theme_ensure_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) return;

    try {
        $pdo->query('SELECT theme_preset,show_title,logo_light_path,logo_dark_path,text_color,muted_text_color,hero_text_color,button_text_color,footer_text_color FROM partner_portal_themes LIMIT 0');
        $ensured = true;
        return;
    } catch (Throwable $e) {
        throw new RuntimeException('A estrutura de temas não está instalada. Aplique as migrações 005 a 009.', 0, $e);
    }
}

function portal_theme_color($value, string $fallback): string
{
    $value = strtolower(trim((string) $value));
    return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : strtolower($fallback);
}

function portal_theme_defaults(array $partner = [], array $company = []): array
{
    $brandName = trim((string) ($partner['name'] ?? ''));
    if ($brandName === '') $brandName = trim((string) ($company['name'] ?? '')) ?: 'FireSpot';

    $letterSource = $brandName !== '' ? $brandName : (string) ($company['logo_letter'] ?? 'F');
    $letter = function_exists('mb_substr') ? mb_substr($letterSource, 0, 1, 'UTF-8') : substr($letterSource, 0, 1);
    $letter = strtoupper($letter ?: 'F');

    return [
        'partner_id' => (int) ($partner['id'] ?? 0),
        'theme_preset' => 'modern',
        'show_title' => 1,
        'theme_mode' => 'light',
        'primary_color' => '#ff9f1c',
        'secondary_color' => '#ff6b00',
        'background_color' => '#071225',
        'text_color_override' => null,
        'muted_text_color_override' => null,
        'hero_text_color_override' => null,
        'button_text_color_override' => null,
        'footer_text_color_override' => null,
        'logo_path' => null,
        'logo_url' => null,
        'logo_light_path' => null,
        'logo_light_url' => null,
        'logo_dark_path' => null,
        'logo_dark_url' => null,
        'brand_name' => $brandName,
        'brand_subtitle' => trim((string) ($company['subtitle'] ?? '')) ?: 'Wi-Fi seguro e rápido',
        'logo_letter' => $letter,
        'customized' => false,
    ];
}

function portal_theme_logo_file(?string $path): ?string
{
    $path = ltrim(trim((string) $path), '/');
    if (!preg_match('#^portal-v3/uploads/branding/partner_[0-9]+_[a-f0-9]{16}\.(?:png|jpe?g|webp)$#', $path)) return null;
    return dirname(__DIR__) . '/' . $path;
}

function portal_theme_get(PDO $pdo, array $partner = [], array $company = []): array
{
    $theme = portal_theme_defaults($partner, $company);
    $partnerId = (int) ($partner['id'] ?? 0);
    if ($partnerId <= 0) return portal_theme_visual($theme);

    try {
        portal_theme_ensure_table($pdo);
        $st = $pdo->prepare('SELECT * FROM partner_portal_themes WHERE partner_id=? LIMIT 1');
        $st->execute([$partnerId]);
        $saved = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // A identidade padrão mantém o Portal V3 disponível mesmo se a
        // migração visual ainda não puder ser aplicada no banco.
        return portal_theme_visual($theme);
    }

    if ($saved) {
        $theme['theme_preset'] = in_array($saved['theme_preset'] ?? '', ['modern', 'compact_blue', 'compact_light'], true) ? $saved['theme_preset'] : 'modern';
        $theme['show_title'] = (int)($saved['show_title'] ?? 1) === 1 ? 1 : 0;
        $theme['theme_mode'] = in_array($saved['theme_mode'] ?? '', ['light', 'dark'], true) ? $saved['theme_mode'] : 'light';
        $theme['primary_color'] = portal_theme_color($saved['primary_color'] ?? '', $theme['primary_color']);
        $theme['secondary_color'] = portal_theme_color($saved['secondary_color'] ?? '', $theme['secondary_color']);
        $theme['background_color'] = portal_theme_color($saved['background_color'] ?? '', $theme['background_color']);
        foreach (['text_color','muted_text_color','hero_text_color','button_text_color','footer_text_color'] as $textColorField) {
            $savedColor = trim((string) ($saved[$textColorField] ?? ''));
            $theme[$textColorField . '_override'] = $savedColor !== ''
                ? portal_theme_color($savedColor, '#ffffff')
                : null;
        }
        foreach (['logo_path', 'logo_light_path', 'logo_dark_path'] as $logoField) {
            $logoPath = ltrim(trim((string)($saved[$logoField] ?? '')), '/');
            $logoFile = portal_theme_logo_file($logoPath);
            if ($logoFile !== null && is_file($logoFile)) {
                $theme[$logoField] = $logoPath;
                $theme[str_replace('_path', '_url', $logoField)] = '/' . $logoPath;
            }
        }
        $theme['customized'] = true;
    }

    return portal_theme_visual($theme);
}

function portal_theme_save(PDO $pdo, int $partnerId, array $data): void
{
    if ($partnerId <= 0) throw new InvalidArgumentException('Estabelecimento inválido.');
    portal_theme_ensure_table($pdo);

    $preset = in_array($data['theme_preset'] ?? '', ['modern', 'compact_blue', 'compact_light'], true) ? (string) $data['theme_preset'] : 'modern';
    $showTitle = (int)($data['show_title'] ?? 0) === 1 ? 1 : 0;
    $mode = in_array($data['theme_mode'] ?? '', ['light', 'dark'], true) ? (string) $data['theme_mode'] : 'light';
    if ($preset === 'compact_blue') $mode = 'dark';
    if ($preset === 'compact_light') $mode = 'light';
    $primary = portal_theme_color($data['primary_color'] ?? '', '#ff9f1c');
    $secondary = portal_theme_color($data['secondary_color'] ?? '', '#ff6b00');
    $background = portal_theme_color($data['background_color'] ?? '', '#071225');
    $preview = portal_theme_visual([
        'theme_preset' => $preset,
        'theme_mode' => $mode,
        'primary_color' => $primary,
        'secondary_color' => $secondary,
        'background_color' => $background,
    ]);
    $textColor = portal_theme_color($data['text_color'] ?? '', $preview['text_color']);
    $mutedTextColor = portal_theme_color($data['muted_text_color'] ?? '', $preview['muted_color']);
    $heroTextColor = portal_theme_color($data['hero_text_color'] ?? '', $preview['hero_text']);
    $buttonTextColor = portal_theme_color($data['button_text_color'] ?? '', $preview['on_brand']);
    $footerTextColor = portal_theme_color($data['footer_text_color'] ?? '', $preview['footer_text']);
    $logoLightPath = ltrim(trim((string)($data['logo_light_path'] ?? '')), '/');
    $logoDarkPath = ltrim(trim((string)($data['logo_dark_path'] ?? '')), '/');
    foreach ([$logoLightPath, $logoDarkPath] as $logoPath) {
        if ($logoPath !== '' && portal_theme_logo_file($logoPath) === null) {
            throw new InvalidArgumentException('Caminho de logotipo inválido.');
        }
    }

    $st = $pdo->prepare("INSERT INTO partner_portal_themes
        (partner_id,theme_preset,show_title,theme_mode,primary_color,secondary_color,background_color,text_color,muted_text_color,hero_text_color,button_text_color,footer_text_color,logo_path,logo_light_path,logo_dark_path)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NULL,?,?)
        ON DUPLICATE KEY UPDATE theme_preset=VALUES(theme_preset),show_title=VALUES(show_title),theme_mode=VALUES(theme_mode),primary_color=VALUES(primary_color),
          secondary_color=VALUES(secondary_color),background_color=VALUES(background_color),
          text_color=VALUES(text_color),muted_text_color=VALUES(muted_text_color),hero_text_color=VALUES(hero_text_color),
          button_text_color=VALUES(button_text_color),footer_text_color=VALUES(footer_text_color),
          logo_path=NULL,logo_light_path=VALUES(logo_light_path),logo_dark_path=VALUES(logo_dark_path),updated_at=NOW()");
    $st->execute([$partnerId,$preset,$showTitle,$mode,$primary,$secondary,$background,$textColor,$mutedTextColor,$heroTextColor,$buttonTextColor,$footerTextColor,$logoLightPath !== '' ? $logoLightPath : null,$logoDarkPath !== '' ? $logoDarkPath : null]);
}

function portal_theme_hex_rgb(string $hex): array
{
    $hex = ltrim(portal_theme_color($hex, '#000000'), '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function portal_theme_mix(string $first, string $second, float $secondWeight): string
{
    $a = portal_theme_hex_rgb($first);
    $b = portal_theme_hex_rgb($second);
    $weight = max(0, min(1, $secondWeight));
    $rgb = [];
    for ($i = 0; $i < 3; $i++) $rgb[$i] = (int) round($a[$i] * (1 - $weight) + $b[$i] * $weight);
    return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
}

function portal_theme_luminance(string $hex): float
{
    $channels = portal_theme_hex_rgb($hex);
    foreach ($channels as &$channel) {
        $channel /= 255;
        $channel = $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }
    unset($channel);
    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function portal_theme_contrast_text(string $background): string
{
    return portal_theme_luminance($background) > 0.179 ? '#10213b' : '#ffffff';
}

function portal_theme_contrast_ratio(string $foreground, string $background): float
{
    $light = portal_theme_luminance($foreground);
    $dark = portal_theme_luminance($background);
    if ($light < $dark) [$light, $dark] = [$dark, $light];
    return ($light + 0.05) / ($dark + 0.05);
}

/**
 * Preserva a cor de marca nos elementos gráficos e produz uma variante da
 * mesma família cromática quando ela for usada como texto sobre um painel.
 */
function portal_theme_accessible_color(string $color, string $background, float $minimum = 4.5): string
{
    $color = portal_theme_color($color, '#ff6b00');
    $background = portal_theme_color($background, '#ffffff');
    if (portal_theme_contrast_ratio($color, $background) >= $minimum) return $color;

    $darkTarget = '#10213b';
    $lightTarget = '#ffffff';
    $target = portal_theme_contrast_ratio($darkTarget, $background) >= portal_theme_contrast_ratio($lightTarget, $background)
        ? $darkTarget
        : $lightTarget;
    for ($step = 1; $step <= 20; $step++) {
        $candidate = portal_theme_mix($color, $target, $step / 20);
        if (portal_theme_contrast_ratio($candidate, $background) >= $minimum) return $candidate;
    }
    return $target;
}

function portal_theme_visual(array $theme): array
{
    $preset = in_array($theme['theme_preset'] ?? '', ['modern', 'compact_blue', 'compact_light'], true) ? (string)$theme['theme_preset'] : 'modern';
    $mode = ($theme['theme_mode'] ?? 'light') === 'dark' ? 'dark' : 'light';
    $primary = portal_theme_color($theme['primary_color'] ?? '', '#ff9f1c');
    $secondary = portal_theme_color($theme['secondary_color'] ?? '', '#ff6b00');
    $background = portal_theme_color($theme['background_color'] ?? '', '#071225');
    $textColorOverride = trim((string)($theme['text_color_override'] ?? ''));
    $mutedTextColorOverride = trim((string)($theme['muted_text_color_override'] ?? ''));
    $heroTextColorOverride = trim((string)($theme['hero_text_color_override'] ?? ''));
    $buttonTextColorOverride = trim((string)($theme['button_text_color_override'] ?? ''));
    $footerTextColorOverride = trim((string)($theme['footer_text_color_override'] ?? ''));
    if ($preset === 'compact_blue') $mode = 'dark';
    if ($preset === 'compact_light') $mode = 'light';

    $theme['theme_preset'] = $preset;
    $theme['show_title'] = (int)($theme['show_title'] ?? 1) === 1 ? 1 : 0;
    $theme['theme_mode'] = $mode;
    $theme['primary_color'] = $primary;
    $theme['secondary_color'] = $secondary;
    $theme['background_color'] = $background;
    $logoOrder = $mode === 'dark' ? ['dark', 'light'] : ['light', 'dark'];
    $theme['logo_path'] = null;
    $theme['logo_url'] = null;
    foreach ($logoOrder as $logoVariant) {
        $pathKey = 'logo_' . $logoVariant . '_path';
        $urlKey = 'logo_' . $logoVariant . '_url';
        if (!empty($theme[$urlKey])) {
            $theme['logo_path'] = $theme[$pathKey] ?? null;
            $theme['logo_url'] = $theme[$urlKey];
            break;
        }
    }
    $isFireSpotPalette = $primary === '#ff9f1c' && $secondary === '#ff6b00' && $background === '#071225';
    $theme['background_secondary'] = $isFireSpotPalette ? '#0c2445' : portal_theme_mix($background, $secondary, 0.13);
    $theme['hero_primary'] = $isFireSpotPalette ? '#0d2342' : portal_theme_mix($background, '#000000', 0.08);
    $theme['hero_secondary'] = $isFireSpotPalette ? '#13375f' : portal_theme_mix($background, $primary, 0.16);
    $theme['hero_text'] = portal_theme_contrast_text($theme['hero_secondary']);
    $theme['on_brand'] = portal_theme_contrast_text(portal_theme_mix($primary, $secondary, 0.5));
    $theme['footer_text'] = portal_theme_contrast_text($background);
    $theme['primary_rgb'] = implode(',', portal_theme_hex_rgb($primary));
    $theme['secondary_rgb'] = implode(',', portal_theme_hex_rgb($secondary));

    if ($mode === 'dark') {
        $theme = array_merge($theme, [
            'panel_color' => '#0f2038',
            'text_color' => '#f4f7fb',
            'muted_color' => '#bdc9d9',
            'line_color' => '#29415f',
            'soft_color' => '#172b46',
            'notice_color' => '#35270f',
            'notice_text' => '#ffd79a',
            'popular_color' => '#432900',
            'popular_text' => '#ffd18d',
        ]);
    } else {
        $theme = array_merge($theme, [
            'panel_color' => '#ffffff',
            'text_color' => '#10213b',
            'muted_color' => '#61708a',
            'line_color' => '#dce3ed',
            'soft_color' => '#f3f7fb',
            'notice_color' => '#fff7eb',
            'notice_text' => '#74400a',
            'popular_color' => '#fff1dc',
            'popular_text' => '#9d4d00',
        ]);
    }
    $theme['text_color'] = portal_theme_color($textColorOverride, $theme['text_color']);
    $theme['muted_color'] = portal_theme_color($mutedTextColorOverride, $theme['muted_color']);
    $theme['hero_text'] = portal_theme_color($heroTextColorOverride, $theme['hero_text']);
    $theme['on_brand'] = portal_theme_color($buttonTextColorOverride, $theme['on_brand']);
    $theme['footer_text'] = portal_theme_color($footerTextColorOverride, $theme['footer_text']);
    $theme['accent_text'] = portal_theme_accessible_color($secondary, $theme['panel_color']);
    return $theme;
}
