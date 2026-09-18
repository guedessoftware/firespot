<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/courtesy_policy.php';

$tests = 0;

function courtesy_expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) throw new RuntimeException($message);
}

function courtesy_expect_code(string $expected, array $decision): void
{
    courtesy_expect(($decision['code'] ?? null) === $expected, 'Esperado ' . $expected . ', recebido ' . ($decision['code'] ?? 'sem código'));
}

function courtesy_policy(array $changes = []): array
{
    return array_merge(fs_courtesy_policy_defaults(), ['auth_mode' => 'anonymous'], $changes);
}

function courtesy_context(array $changes = []): array
{
    return array_merge([
        'partner_id' => 10,
        'device_key' => 'AA:BB:CC:DD:EE:FF',
        'mac' => 'aa-bb-cc-dd-ee-ff',
        'portal' => 'v2',
        'source' => 'quick_login',
    ], $changes);
}

$now = strtotime('2026-08-09 08:00:00 UTC');
courtesy_expect($now !== false, 'Data fixa inválida.');

$normalized = fs_courtesy_policy_normalize(fs_courtesy_policy_defaults());
courtesy_expect(fs_courtesy_policy_validate($normalized) === [], 'A política padrão precisa ser válida.');

$decision = fs_courtesy_evaluate(courtesy_policy(), courtesy_context(), [], $now);
courtesy_expect_code('ELIGIBLE', $decision);
courtesy_expect($decision['allowed'] === true && $decision['minutes'] === 20, 'A concessão padrão deveria liberar 20 minutos.');

courtesy_expect_code('POLICY_DISABLED', fs_courtesy_evaluate(courtesy_policy(['enabled' => 0]), courtesy_context(), [], $now));
courtesy_expect_code('DEVICE_REQUIRED', fs_courtesy_evaluate(courtesy_policy(), courtesy_context(['device_key' => '']), [], $now));
courtesy_expect_code('AUTH_REQUIRED', fs_courtesy_evaluate(courtesy_policy(['auth_mode' => 'account']), courtesy_context(), [], $now));
courtesy_expect_code('DEVICE_ASSOCIATION_REQUIRED', fs_courtesy_evaluate(
    courtesy_policy(['auth_mode' => 'account_device']),
    courtesy_context(['account_key' => '5511999999999']),
    [],
    $now
));
courtesy_expect_code('AD_REQUIRED', fs_courtesy_evaluate(courtesy_policy(['requires_ad' => 1]), courtesy_context(), [], $now));
courtesy_expect_code('PROVIDER_NOT_ELIGIBLE', fs_courtesy_evaluate(courtesy_policy(), courtesy_context(['is_provider' => true]), [], $now));
courtesy_expect_code('ACTIVE_PLAN_NOT_ELIGIBLE', fs_courtesy_evaluate(courtesy_policy(), courtesy_context(['has_active_paid' => true]), [], $now));
courtesy_expect_code('ACTIVE_GRANT', fs_courtesy_evaluate(courtesy_policy(), courtesy_context(), ['active_count' => 1], $now));

$lastEnd = $now - 300;
$decision = fs_courtesy_evaluate(
    courtesy_policy(['cooldown_after_end_minutes' => 10]),
    courtesy_context(),
    ['last_ended_at' => $lastEnd],
    $now
);
courtesy_expect_code('COOLDOWN', $decision);
courtesy_expect($decision['retry_at'] === gmdate(DATE_ATOM, $now + 300), 'Retry do cooldown incorreto.');

$oldest = $now - 600;
$decision = fs_courtesy_evaluate(
    courtesy_policy(['device_max_grants' => 1, 'device_period_minutes' => 60]),
    courtesy_context(),
    ['device' => ['count' => 1, 'oldest_at' => $oldest]],
    $now
);
courtesy_expect_code('DEVICE_LIMIT', $decision);
courtesy_expect($decision['retry_at'] === gmdate(DATE_ATOM, $oldest + 3600), 'Retry do dispositivo incorreto.');

courtesy_expect_code('ACCOUNT_LIMIT', fs_courtesy_evaluate(
    courtesy_policy(['account_max_grants' => 2, 'account_period_minutes' => 1440]),
    courtesy_context(['account_key' => 'cliente-1']),
    ['account' => ['count' => 2, 'oldest_at' => $now - 60]],
    $now
));

courtesy_expect_code('PARTNER_LIMIT', fs_courtesy_evaluate(
    courtesy_policy(['partner_max_grants' => 100, 'partner_period_minutes' => 60]),
    courtesy_context(),
    ['partner' => ['count' => 100, 'oldest_at' => $now - 60]],
    $now
));

$campaign = [
    'campaign_max_grants' => 500,
    'campaign_starts_at' => gmdate('Y-m-d H:i:s', $now - 3600),
    'campaign_ends_at' => gmdate('Y-m-d H:i:s', $now + 3600),
];
courtesy_expect_code('CAMPAIGN_LIMIT', fs_courtesy_evaluate(courtesy_policy($campaign), courtesy_context(), ['campaign_count' => 500], $now));
courtesy_expect_code('CAMPAIGN_NOT_STARTED', fs_courtesy_evaluate(courtesy_policy(array_merge($campaign, [
    'campaign_starts_at' => gmdate('Y-m-d H:i:s', $now + 60),
    'campaign_ends_at' => gmdate('Y-m-d H:i:s', $now + 3600),
])), courtesy_context(), [], $now));
courtesy_expect_code('CAMPAIGN_ENDED', fs_courtesy_evaluate(courtesy_policy(array_merge($campaign, [
    'campaign_starts_at' => gmdate('Y-m-d H:i:s', $now - 3600),
    'campaign_ends_at' => gmdate('Y-m-d H:i:s', $now - 60),
])), courtesy_context(), [], $now));

courtesy_expect_code('POLICY_INVALID', fs_courtesy_evaluate(courtesy_policy([
    'device_max_grants' => null,
    'device_period_minutes' => 1440,
]), courtesy_context(), [], $now));
courtesy_expect_code('POLICY_INVALID', fs_courtesy_evaluate(courtesy_policy([
    'grant_minutes' => 30,
    'credit_validity_minutes' => 20,
]), courtesy_context(), [], $now));
courtesy_expect_code('POLICY_INVALID', fs_courtesy_evaluate(courtesy_policy([
    'radius_group' => 'grupo com espaço',
]), courtesy_context(), [], $now));
$unlimitedWarnings = fs_courtesy_policy_warnings(courtesy_policy([
    'device_max_grants' => null,
    'device_period_minutes' => null,
]));
courtesy_expect(!in_array('período por dispositivo menor que a duração da concessão', $unlimitedWarnings, true), 'Limite ilimitado não deve gerar alerta de período.');

$context = fs_courtesy_context_normalize(courtesy_context());
courtesy_expect($context['mac'] === 'AA:BB:CC:DD:EE:FF', 'Normalização de MAC incorreta.');
courtesy_expect(strlen(fs_courtesy_identity_hash($context['device_key'])) === 64, 'Hash de identidade incorreto.');

$idempotentA = fs_courtesy_idempotency_hash(array_merge($context, ['partner_id' => 1, 'idempotency_key' => 'same-request']));
$idempotentB = fs_courtesy_idempotency_hash(array_merge($context, ['partner_id' => 2, 'idempotency_key' => 'same-request']));
courtesy_expect($idempotentA !== $idempotentB, 'A idempotência precisa ser isolada por estabelecimento.');
$publicGrant = fs_courtesy_public_grant(['id' => '7', 'public_id' => 'abc', 'status' => 'active', 'grant_minutes' => '20', 'policy_snapshot' => 'private']);
courtesy_expect(!array_key_exists('policy_snapshot', $publicGrant), 'O retorno público não deve expor o snapshot interno.');

echo 'Testes da política de cortesia concluídos: ' . $tests . " verificações.\n";
