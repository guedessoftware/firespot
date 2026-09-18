<?php

declare(strict_types=1);

require_once __DIR__ . '/courtesy_policy.php';
require_once __DIR__ . '/courtesy_radius.php';
require_once __DIR__ . '/courtesy_rollout.php';

function fs_courtesy_access_idempotency(array $rawContext, string $intent, int $ttlSeconds = 180): string
{
    $context = fs_courtesy_context_normalize($rawContext);
    $intent = substr(preg_replace('/[^a-z0-9_-]+/i', '_', trim($intent)) ?: 'grant', 0, 32);
    $scope = hash('sha256', implode('|', [
        (string) $context['partner_id'],
        $context['portal'],
        $context['device_key'],
        $context['account_key'],
        $intent,
    ]));
    $now = time();
    if (!isset($_SESSION['courtesy_idempotency']) || !is_array($_SESSION['courtesy_idempotency'])) {
        $_SESSION['courtesy_idempotency'] = [];
    }
    $saved = $_SESSION['courtesy_idempotency'][$scope] ?? null;
    if (is_array($saved) && !empty($saved['key']) && (int) ($saved['created_at'] ?? 0) + max(30, $ttlSeconds) > $now) {
        return (string) $saved['key'];
    }
    $key = $intent . '_' . bin2hex(random_bytes(16));
    $_SESSION['courtesy_idempotency'][$scope] = ['key' => $key, 'created_at' => $now];
    if (count($_SESSION['courtesy_idempotency']) > 32) {
        uasort($_SESSION['courtesy_idempotency'], static fn(array $a, array $b): int => (int) ($a['created_at'] ?? 0) <=> (int) ($b['created_at'] ?? 0));
        $_SESSION['courtesy_idempotency'] = array_slice($_SESSION['courtesy_idempotency'], -32, null, true);
    }
    return $key;
}

/**
 * Fachada única para os portais durante o cutover.
 *
 * `handled=false` ordena ao portal manter exatamente o fluxo legado.
 * `handled=true` torna a decisão central soberana e nunca faz fallback para
 * um bypass local quando o provisionamento falha.
 */
function fs_courtesy_access_issue(PDO $app, array $rawContext, ?PDO $radius = null, ?int $now = null): array
{
    $context = fs_courtesy_context_normalize($rawContext);
    $partnerId = (int) $context['partner_id'];
    $portal = (string) $context['portal'];
    if ($partnerId <= 0) {
        return ['handled' => false, 'mode' => 'legacy', 'reason' => 'PARTNER_UNRESOLVED'];
    }
    if (!array_key_exists($portal, fs_courtesy_rollout_portals())) {
        return ['handled' => false, 'mode' => 'legacy', 'reason' => 'PORTAL_UNSUPPORTED'];
    }

    $policy = fs_courtesy_policy_resolve($app,$partnerId,(int)$context['hotspot_id']?:null);
    $rollout = fs_courtesy_rollout_resolve($app, $partnerId, $portal, $policy);
    if ($rollout['effective_mode'] !== 'enforce') {
        return [
            'handled' => false,
            'mode' => $rollout['effective_mode'],
            'requested_mode' => $rollout['requested_mode'],
            'reason' => $rollout['blocked_by'],
        ];
    }

    if ($policy['enforcement_method'] !== 'radius') {
        return array_merge(
            ['handled' => true],
            fs_courtesy_result(false, 'ENFORCEMENT_UNAVAILABLE', 'O método de cortesia deste estabelecimento ainda não está operacional.')
        );
    }

    try {
        // Reconexão não é uma nova concessão: localiza a credencial ativa do
        // mesmo estabelecimento/dispositivo e recalcula somente o saldo.
        // Exclusões de cliente pago/provedor permanecem soberanas no portal,
        // que deve usar a credencial própria nesses dois casos.
        $excludedExistingAccess = ($policy['exclude_provider'] && $context['is_provider'])
            || ($policy['exclude_active_paid'] && $context['has_active_paid']);
        if (!$excludedExistingAccess && $context['device_key'] !== '') {
            $st = $app->prepare("SELECT public_id FROM courtesy_grants
                WHERE partner_id=? AND device_key_hash=? AND status='active'
                ORDER BY id DESC LIMIT 1");
            $st->execute([$partnerId, fs_courtesy_identity_hash($context['device_key'])]);
            $activePublicId = (string)($st->fetchColumn() ?: '');
            if ($activePublicId !== '') {
                $reconnect = fs_courtesy_radius_prepare_reconnect($app, $activePublicId, $radius, $now);
                if (!empty($reconnect['allowed'])) {
                    return array_merge(['handled' => true, 'mode' => 'enforce', 'reconnected' => true], $reconnect);
                }
            }
        }

        $reservation = fs_courtesy_reserve($app, $context, $now);
        if (empty($reservation['allowed'])) return array_merge(['handled' => true], $reservation);
        $publicId = (string) ($reservation['grant']['public_id'] ?? '');
        if ($publicId === '') throw new RuntimeException('A reserva não retornou um identificador de concessão.');

        $provisioned = fs_courtesy_radius_provision($app, $publicId, $radius, $now);
        return array_merge(['handled' => true, 'mode' => 'enforce'], $provisioned);
    } catch (Throwable $e) {
        error_log('[courtesy access enforcement] ' . $e->getMessage());
        return array_merge(
            ['handled' => true, 'mode' => 'enforce'],
            fs_courtesy_result(false, 'ENFORCEMENT_FAILED', 'Não foi possível ativar a cortesia agora. Tente novamente em instantes.')
        );
    }
}
