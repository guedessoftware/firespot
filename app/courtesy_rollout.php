<?php

declare(strict_types=1);

require_once __DIR__ . '/courtesy_policy.php';

function fs_courtesy_rollout_portals(): array
{
    return [
        'qr_ad' => 'QR / anúncio',
        'v2' => 'Portal V2',
        'classic_signup' => 'Cadastro clássico',
        'classic_login' => 'Login clássico',
        'v3' => 'Portal V3',
    ];
}

function fs_courtesy_rollout_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT partner_id,portal,mode FROM courtesy_portal_rollouts LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fs_courtesy_setting_bool(PDO $pdo, string $key, bool $default = false): bool
{
    try {
        $st = $pdo->prepare('SELECT svalue FROM app_settings WHERE skey=? LIMIT 1');
        $st->execute([$key]);
        $value = $st->fetchColumn();
        if ($value === false) return $default;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    } catch (Throwable $e) {
        return $default;
    }
}

function fs_courtesy_rollout_mode(PDO $pdo, int $partnerId, string $portal): string
{
    if ($partnerId <= 0 || !array_key_exists($portal, fs_courtesy_rollout_portals()) || !fs_courtesy_rollout_schema_ready($pdo)) {
        return 'legacy';
    }
    $st = $pdo->prepare('SELECT mode FROM courtesy_portal_rollouts WHERE partner_id=? AND portal=? LIMIT 1');
    $st->execute([$partnerId, $portal]);
    $mode = (string) ($st->fetchColumn() ?: 'legacy');
    return in_array($mode, ['legacy', 'shadow', 'enforce'], true) ? $mode : 'legacy';
}

/**
 * Resolve o modo efetivo com gates fail-closed. Um registro em `enforce`
 * isoladamente nunca libera tráfego novo.
 */
function fs_courtesy_rollout_resolve(PDO $pdo, int $partnerId, string $portal, ?array $policy = null): array
{
    $requested = fs_courtesy_rollout_mode($pdo, $partnerId, $portal);
    $result = [
        'partner_id' => $partnerId,
        'portal' => $portal,
        'requested_mode' => $requested,
        'effective_mode' => $requested,
        'blocked_by' => null,
    ];
    if ($requested !== 'enforce') return $result;

    if (!fs_courtesy_setting_bool($pdo, 'courtesy_cutover_enabled', false)) {
        $result['effective_mode'] = 'shadow';
        $result['blocked_by'] = 'GLOBAL_CUTOVER_DISABLED';
        return $result;
    }

    $policy = $policy ?? fs_courtesy_policy_resolve($pdo, $partnerId);
    $method = (string) ($policy['enforcement_method'] ?? 'radius');
    if ($method === 'radius' && !fs_courtesy_setting_bool($pdo, 'courtesy_radius_ready', false)) {
        $result['effective_mode'] = 'shadow';
        $result['blocked_by'] = 'RADIUS_NOT_READY';
    } elseif ($method === 'mikrotik_local' && !fs_courtesy_setting_bool($pdo, 'courtesy_mikrotik_ready', false)) {
        $result['effective_mode'] = 'shadow';
        $result['blocked_by'] = 'MIKROTIK_NOT_READY';
    }
    return $result;
}

function fs_courtesy_rollout_should_observe(PDO $pdo, int $partnerId, string $portal): bool
{
    $rollout = fs_courtesy_rollout_resolve($pdo, $partnerId, $portal);
    return in_array($rollout['effective_mode'], ['shadow', 'enforce'], true);
}

function fs_courtesy_rollout_save(PDO $pdo, int $partnerId, string $portal, string $mode, ?string $updatedBy = null): array
{
    if ($partnerId <= 0 || !fs_courtesy_partner($pdo, $partnerId)) {
        throw new InvalidArgumentException('Estabelecimento não encontrado.');
    }
    if (!array_key_exists($portal, fs_courtesy_rollout_portals())) {
        throw new InvalidArgumentException('Portal de rollout inválido.');
    }
    if (!in_array($mode, ['legacy', 'shadow', 'enforce'], true)) {
        throw new InvalidArgumentException('Modo de rollout inválido.');
    }
    if (!fs_courtesy_rollout_schema_ready($pdo)) {
        throw new RuntimeException('A migração de rollout ainda não foi aplicada.');
    }
    if ($mode === 'enforce') {
        $policy = fs_courtesy_policy_resolve($pdo, $partnerId);
        if (!fs_courtesy_setting_bool($pdo, 'courtesy_cutover_enabled', false)) {
            throw new RuntimeException('O gate global de cutover ainda está fechado.');
        }
        if ($policy['enforcement_method'] === 'radius' && !fs_courtesy_setting_bool($pdo, 'courtesy_radius_ready', false)) {
            throw new RuntimeException('O RADIUS ainda não foi marcado como pronto.');
        }
        if ($policy['enforcement_method'] === 'mikrotik_local' && !fs_courtesy_setting_bool($pdo, 'courtesy_mikrotik_ready', false)) {
            throw new RuntimeException('O adapter MikroTik local ainda não foi marcado como pronto.');
        }
    }
    $updatedBy = trim((string) $updatedBy);
    $updatedBy = $updatedBy === '' ? null : substr($updatedBy, 0, 64);
    $st = $pdo->prepare('INSERT INTO courtesy_portal_rollouts (partner_id,portal,mode,updated_by)
        VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode),updated_by=VALUES(updated_by),revision=revision+1,updated_at=CURRENT_TIMESTAMP');
    $st->execute([$partnerId, $portal, $mode, $updatedBy]);
    return fs_courtesy_rollout_resolve($pdo, $partnerId, $portal);
}

function fs_courtesy_rollout_list(PDO $pdo, int $partnerId): array
{
    $rows = [];
    foreach (fs_courtesy_rollout_portals() as $portal => $label) {
        $resolved = fs_courtesy_rollout_resolve($pdo, $partnerId, $portal);
        $resolved['label'] = $label;
        $rows[$portal] = $resolved;
    }
    return $rows;
}
