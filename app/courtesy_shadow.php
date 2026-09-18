<?php

declare(strict_types=1);

require_once __DIR__ . '/courtesy_policy.php';
require_once __DIR__ . '/courtesy_rollout.php';
require_once __DIR__ . '/radius_db.php';

function fs_courtesy_shadow_enabled(PDO $pdo): bool
{
    static $enabled = null;
    if ($enabled !== null) return $enabled;
    try {
        $st = $pdo->prepare("SELECT svalue FROM app_settings WHERE skey='courtesy_shadow_enabled' LIMIT 1");
        $st->execute();
        $value = $st->fetchColumn();
        $enabled = $value !== false && in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    } catch (Throwable $e) {
        $enabled = false;
    }
    return $enabled;
}

function fs_courtesy_shadow_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM courtesy_shadow_events LIMIT 0');
        return fs_courtesy_schema_ready($pdo);
    } catch (Throwable $e) {
        return false;
    }
}

function fs_courtesy_shadow_partner_id(PDO $pdo, array $context): int
{
    $partnerId = (int) ($context['partner_id'] ?? 0);
    if ($partnerId > 0) return $partnerId;

    $candidates = [];
    foreach ([$context['partner_code'] ?? '', $_SESSION['portal_fast_id'] ?? ''] as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') $candidates[] = $candidate;
    }
    $serverName = trim((string) ($context['server_name'] ?? $_SESSION['hotspot_ctx']['data']['server-name'] ?? ''));
    if (stripos($serverName, 'hs_') === 0) $candidates[] = substr($serverName, 3);

    foreach (array_values(array_unique($candidates)) as $candidate) {
        $st = $pdo->prepare('SELECT id FROM partners WHERE active=1 AND code=? LIMIT 1');
        $st->execute([$candidate]);
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id > 0) return $id;
        if (ctype_digit($candidate)) {
            $st = $pdo->prepare('SELECT id FROM partners WHERE active=1 AND id=? LIMIT 1');
            $st->execute([(int) $candidate]);
            $id = (int) ($st->fetchColumn() ?: 0);
            if ($id > 0) return $id;
        }
    }

    $host = trim((string) ($context['host'] ?? $_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?: '';
    if ($host !== '') {
        $st = $pdo->prepare('SELECT id FROM partners WHERE active=1 AND dns_name=? LIMIT 1');
        $st->execute([$host]);
        return (int) ($st->fetchColumn() ?: 0);
    }
    return 0;
}

function fs_courtesy_shadow_device_key(array $context): string
{
    $candidates = [
        $context['device_key'] ?? '',
        $context['mac'] ?? '',
        $_SESSION['hotspot_device_info']['mac'] ?? '',
        $_SESSION['hotspot_ctx']['data']['mac'] ?? '',
    ];
    foreach ($candidates as $candidate) {
        $candidate = strtoupper(str_replace('-', ':', trim((string) $candidate)));
        if (preg_match('/^[0-9A-F]{12}$/', $candidate)) $candidate = implode(':', str_split($candidate, 2));
        if (preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $candidate)) return $candidate;
        if (strpos($candidate, 'DID:') === 0 && strlen($candidate) > 4) return substr($candidate, 0, 64);
    }
    $did = preg_replace('/[^A-Fa-f0-9]/', '', (string) ($_COOKIE['fs_did'] ?? '')) ?: '';
    return $did !== '' ? 'DID:' . strtoupper(substr($did, 0, 56)) : '';
}

function fs_courtesy_shadow_radius_state(string $username): array
{
    $state = ['is_provider' => false, 'has_active_paid' => false];
    if ($username === '') return $state;
    try {
        $radius = fs_radius_db();
        $st = $radius->prepare('SELECT LOWER(groupname) FROM radusergroup WHERE username=?');
        $st->execute([$username]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $group) {
            $group = strtolower((string) $group);
            if ($group === 'isp_unl' || strpos($group, 'isp_') === 0) $state['is_provider'] = true;
            if (strpos($group, 'vip') !== false || strpos($group, 'premium') !== false) $state['has_active_paid'] = true;
        }
        if (!$state['is_provider'] && !$state['has_active_paid']) {
            $st = $radius->prepare("SELECT CAST(value AS UNSIGNED) FROM radcheck WHERE username=? AND attribute='Max-All-Session' LIMIT 1");
            $st->execute([$username]);
            $allowed = (int) ($st->fetchColumn() ?: 0);
            if ($allowed > 0) {
                $st = $radius->prepare('SELECT COALESCE(SUM(COALESCE(acctsessiontime,0)),0) FROM radacct WHERE username=?');
                $st->execute([$username]);
                $state['has_active_paid'] = $allowed > (int) ($st->fetchColumn() ?: 0);
            }
        }
    } catch (Throwable $e) {
        error_log('[courtesy shadow radius state] ' . $e->getMessage());
    }
    return $state;
}

function fs_courtesy_shadow_associated(PDO $pdo, string $username, string $deviceKey): bool
{
    if ($username === '' || $deviceKey === '') return false;
    try {
        $st = $pdo->prepare('SELECT 1 FROM clientes_dispositivos WHERE username=? AND UPPER(mac)=? AND is_active=1 LIMIT 1');
        $st->execute([$username, strtoupper($deviceKey)]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function fs_courtesy_shadow_sql_time($value): ?string
{
    $timestamp = fs_courtesy_time($value);
    return $timestamp === null ? null : gmdate('Y-m-d H:i:s', $timestamp);
}

/**
 * Compara a decisão legada com a política nova. Nunca altera a resposta do
 * portal e nunca lança erro para o fluxo chamador.
 */
function fs_courtesy_shadow_capture(PDO $pdo, array $legacy, array $rawContext = []): ?array
{
    try {
        if (!fs_courtesy_shadow_enabled($pdo) || !fs_courtesy_shadow_schema_ready($pdo)) return null;
        $partnerId = fs_courtesy_shadow_partner_id($pdo, $rawContext);
        if ($partnerId <= 0) return null;
        $portal = fs_courtesy_context_normalize([
            'portal' => $rawContext['portal'] ?? 'unknown',
            'source' => $rawContext['source'] ?? 'unknown',
        ])['portal'];
        if (fs_courtesy_rollout_schema_ready($pdo) && !fs_courtesy_rollout_should_observe($pdo, $partnerId, $portal)) return null;

        $deviceKey = fs_courtesy_shadow_device_key($rawContext);
        $username = trim((string) ($rawContext['account_key'] ?? $rawContext['username'] ?? $_SESSION['cliente_username'] ?? ''));
        $radiusState = fs_courtesy_shadow_radius_state($username);
        $associated = array_key_exists('associated_device', $rawContext)
            ? !empty($rawContext['associated_device'])
            : fs_courtesy_shadow_associated($pdo, $username, $deviceKey);
        $context = [
            'partner_id' => $partnerId,
            'device_key' => $deviceKey,
            'mac' => $deviceKey,
            'ip' => $rawContext['ip'] ?? $_SESSION['hotspot_device_info']['ip'] ?? $_SESSION['hotspot_ctx']['data']['ip'] ?? '',
            'account_key' => $username,
            'account_username' => $username,
            'portal' => $rawContext['portal'] ?? 'unknown',
            'source' => $rawContext['source'] ?? 'unknown',
            'associated_device' => $associated,
            'ad_completed' => !empty($rawContext['ad_completed']),
            'has_active_paid' => array_key_exists('has_active_paid', $rawContext) ? !empty($rawContext['has_active_paid']) : $radiusState['has_active_paid'],
            'is_provider' => array_key_exists('is_provider', $rawContext) ? !empty($rawContext['is_provider']) : $radiusState['is_provider'],
        ];

        $decision = fs_courtesy_check($pdo, $context);
        $legacyAllowed = !empty($legacy['allowed']);
        $policyAllowed = !empty($decision['allowed']);
        $legacyMinutes = isset($legacy['minutes']) ? (int) $legacy['minutes'] : null;
        $policyMinutes = isset($decision['minutes']) ? (int) $decision['minutes'] : null;
        $match = $legacyAllowed === $policyAllowed;
        if ($match && $legacyAllowed) $match = $legacyMinutes === $policyMinutes;

        $policy = fs_courtesy_policy_resolve($pdo, $partnerId);
        $st = $pdo->prepare('INSERT INTO courtesy_shadow_events
            (partner_id,partner_code,portal,source,device_key_hash,account_key_hash,
             legacy_allowed,legacy_code,legacy_minutes,legacy_retry_at,
             policy_allowed,policy_code,policy_minutes,policy_retry_at,policy_revision,decision_match,
             auth_present,device_associated,ad_completed,active_paid,provider,error_code,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $partnerId,
            (string) $policy['partner_code'],
            fs_courtesy_context_normalize($context)['portal'],
            fs_courtesy_context_normalize($context)['source'],
            $deviceKey !== '' ? fs_courtesy_identity_hash($deviceKey) : null,
            $username !== '' ? fs_courtesy_identity_hash($username) : null,
            $legacyAllowed ? 1 : 0,
            substr((string) ($legacy['code'] ?? 'UNKNOWN'), 0, 64),
            $legacyMinutes,
            fs_courtesy_shadow_sql_time($legacy['retry_at'] ?? null),
            $policyAllowed ? 1 : 0,
            substr((string) ($decision['code'] ?? 'UNKNOWN'), 0, 64),
            $policyMinutes,
            fs_courtesy_shadow_sql_time($decision['retry_at'] ?? null),
            substr((string) ($decision['policy_revision'] ?? $policy['policy_revision']), 0, 32),
            $match ? 1 : 0,
            $username !== '' ? 1 : 0,
            $associated ? 1 : 0,
            $context['ad_completed'] ? 1 : 0,
            $context['has_active_paid'] ? 1 : 0,
            $context['is_provider'] ? 1 : 0,
            null,
            gmdate('Y-m-d H:i:s'),
        ]);
        return ['legacy' => $legacy, 'policy' => $decision, 'match' => $match];
    } catch (Throwable $e) {
        error_log('[courtesy shadow] ' . $e->getMessage());
        return null;
    }
}
