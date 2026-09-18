<?php

declare(strict_types=1);

require_once __DIR__ . '/courtesy_policy.php';
require_once __DIR__ . '/courtesy_rollout.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/payment_wallets.php';

function partner_purpose_labels(): array
{
    return [
        'free' => 'Gratuito rápido',
        'sponsored' => 'Gratuito patrocinado',
        'paid' => 'Acesso pago',
        'hybrid' => 'Gratuito + pago',
    ];
}

function partner_purpose_label(?string $purpose): string
{
    return partner_purpose_labels()[$purpose ?? ''] ?? 'Não definida';
}

function partner_purpose_descriptions(): array
{
    return [
        'free' => 'Internet gratuita com liberação rápida, sem anúncio obrigatório e sem venda.',
        'sponsored' => 'Internet gratuita liberada somente depois que o visitante conclui um anúncio.',
        'paid' => 'Somente venda de acesso. A cortesia permanece desativada.',
        'hybrid' => 'Oferece uma cortesia limitada e também planos pagos na mesma experiência.',
    ];
}

function partner_purpose_description(?string $purpose): string
{
    return partner_purpose_descriptions()[$purpose ?? ''] ?? 'Selecione uma finalidade para consultar os requisitos.';
}

function partner_purpose_has_sales(?string $purpose): bool
{
    return in_array($purpose, ['paid', 'hybrid'], true);
}

function partner_purpose_has_courtesy(?string $purpose): bool
{
    return in_array($purpose, ['free', 'sponsored', 'hybrid'], true);
}

function partner_purpose_module_allowed(array $partner, string $module): bool
{
    $purpose = (string)($partner['access_purpose'] ?? '');
    if (in_array($module, ['plans', 'billing', 'finance'], true)) return partner_purpose_has_sales($purpose);
    if ($module === 'courtesy') return partner_purpose_has_courtesy($purpose);
    if ($module === 'ads') return (int)($partner['ads_enabled'] ?? 0) === 1;
    return in_array($module, ['summary', 'theme', 'reports', 'team', 'monetization', 'infrastructure', 'analytics', 'audit'], true);
}

function partner_purpose_active_ads(PDO $pdo, array $partner): int
{
    $partnerId = (int)($partner['id'] ?? 0);
    if ($partnerId <= 0 || (int)($partner['ads_enabled'] ?? 0) !== 1) return 0;
    $sql = "SELECT COUNT(*) FROM custom_ads
        WHERE active=1 AND (start_date IS NULL OR start_date<=CURRENT_DATE)
          AND (end_date IS NULL OR end_date>=CURRENT_DATE)
          AND (partner_id=?";
    $params = [$partnerId];
    if ((int)($partner['allow_global_ads'] ?? 0) === 1) $sql .= ' OR partner_id IS NULL';
    $sql .= ')';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function partner_purpose_requirement(
    string $code,
    string $label,
    bool $ready,
    string $readyMessage,
    string $errorMessage,
    ?string $tab = null
): array
{
    return [
        'code' => $code,
        'label' => $label,
        'ready' => $ready,
        'message' => $ready ? $readyMessage : $errorMessage,
        'tab' => $tab,
    ];
}

function partner_purpose_payment_requirements(PDO $pdo, array $partner): array
{
    $requirements = [];
    try {
        $plans = fs_guest_plans($pdo, $partner);
        $validPlans = array_filter($plans, static fn(array $plan): bool => (int)$plan['price_cents'] > 0 && (int)$plan['duration_minutes'] > 0);
        $requirements[] = partner_purpose_requirement(
            'payment_plan',
            'Plano pago ativo',
            !empty($validPlans),
            count($validPlans) . ' plano(s) válido(s) disponível(is).',
            'Planos: cadastre ao menos um plano ativo com valor e duração válidos.',
            'plans'
        );
    } catch (Throwable $e) {
        $requirements[] = partner_purpose_requirement(
            'payment_plan',
            'Plano pago ativo',
            false,
            '',
            'Planos: não foi possível consultar os planos pagos.',
            'plans'
        );
    }

    try {
        $wallet = fs_wallet_for_partner($pdo, $partner, true);
        if ((int)($partner['independent_billing'] ?? 0) === 1 && (int)($wallet['partner_id'] ?? 0) !== (int)$partner['id']) {
            throw new RuntimeException('A carteira selecionada não pertence a este estabelecimento.');
        } else {
            fs_wallet_assert_usable($wallet);
        }
        $requirements[] = partner_purpose_requirement(
            'payment_wallet',
            'Recebimento utilizável',
            true,
            'A carteira de recebimento está ativa e pode abrir pagamentos.',
            '',
            'billing'
        );
    } catch (InvalidArgumentException | RuntimeException $e) {
        $reason = trim($e->getMessage());
        if (strpos($reason, 'PAYMENT_CREDENTIAL_KEY') !== false) {
            $reason = 'a chave de proteção das carteiras (PAYMENT_CREDENTIAL_KEY) não está configurada no servidor.';
        } elseif (strpos($reason, 'descriptografar') !== false || strpos($reason, 'credencial corrompida') !== false || strpos($reason, 'Formato de credencial') !== false) {
            $reason = 'o Access Token salvo não pode ser aberto com a chave atual; salve novamente a carteira com a chave correta configurada.';
        }
        $requirements[] = partner_purpose_requirement(
            'payment_wallet',
            'Recebimento utilizável',
            false,
            '',
            'Recebimento: ' . ($reason !== '' ? $reason : 'configure uma carteira utilizável.'),
            'billing'
        );
    } catch (Throwable $e) {
        $requirements[] = partner_purpose_requirement(
            'payment_wallet',
            'Recebimento utilizável',
            false,
            '',
            'Recebimento: não foi possível validar a carteira configurada.',
            'billing'
        );
    }
    return $requirements;
}

function partner_purpose_payment_ready(PDO $pdo, array $partner): array
{
    $requirements = partner_purpose_payment_requirements($pdo, $partner);
    return array_values(array_map(
        static fn(array $item): string => (string)$item['message'],
        array_filter($requirements, static fn(array $item): bool => empty($item['ready']))
    ));
}

function partner_purpose_readiness(PDO $pdo, array $partner, ?string $purpose = null): array
{
    $purpose = $purpose ?? (string)($partner['access_purpose'] ?? '');
    $requirements = [];
    if (!array_key_exists($purpose, partner_purpose_labels())) {
        return [
            'ready' => false,
            'purpose' => $purpose,
            'label' => partner_purpose_label($purpose),
            'description' => partner_purpose_description($purpose),
            'errors' => ['Finalidade: selecione uma opção válida.'],
            'requirements' => [],
        ];
    }
    $requirements[] = partner_purpose_requirement(
        'partner_active',
        'Estabelecimento ativo',
        (int)($partner['active'] ?? 0) === 1,
        'O estabelecimento está ativo.',
        'Cortesia: marque “Estabelecimento ativo” antes de colocar a finalidade em operação.',
        'access'
    );

    $policy = fs_courtesy_policy_resolve($pdo, (int)$partner['id']);
    if (partner_purpose_has_courtesy($purpose)) {
        $requirements[] = partner_purpose_requirement(
            'courtesy_enabled',
            'Cortesia habilitada',
            (int)($policy['enabled'] ?? 0) === 1,
            'A política unificada de cortesia está habilitada.',
            'Cortesia: ative a opção “Cortesia habilitada”.',
            'access'
        );
        $requirements[] = partner_purpose_requirement(
            'courtesy_radius',
            'Execução pelo FreeRADIUS',
            ($policy['enforcement_method'] ?? '') === 'radius',
            'A concessão será controlada pelo FreeRADIUS.',
            'Cortesia: selecione “FreeRADIUS” em Método de execução.',
            'access'
        );
        $requirements[] = partner_purpose_requirement(
            'courtesy_radius_ready',
            'RADIUS liberado globalmente',
            fs_courtesy_setting_bool($pdo, 'courtesy_radius_ready', false),
            'O gate global do RADIUS está pronto.',
            'Cortesia: o gate global “RADIUS pronto” está bloqueado.',
            'access'
        );
        if (($partner['portal_mode'] ?? '') === 'v3') {
            $v3Rollout = fs_courtesy_rollout_resolve($pdo, (int)$partner['id'], 'v3', $policy);
            $v3RolloutReady = ($v3Rollout['effective_mode'] ?? 'legacy') === 'enforce';
            $v3RolloutError = 'Cortesia: em “Rollout por portal”, altere Portal V3 para “Enforce unificado” e salve.';
            if (($v3Rollout['requested_mode'] ?? '') === 'enforce' && !$v3RolloutReady) {
                $v3RolloutError = 'Cortesia: o Portal V3 está em Enforce, mas um gate operacional ainda está bloqueando a concessão.';
            }
            $requirements[] = partner_purpose_requirement(
                'courtesy_v3_rollout',
                'Cortesia ativa no Portal V3',
                $v3RolloutReady,
                'O Portal V3 está autorizado a conceder a cortesia.',
                $v3RolloutError,
                'access'
            );
        }
    }

    if ($purpose === 'free') {
        $requirements[] = partner_purpose_requirement(
            'courtesy_ad_disabled',
            'Anúncio não obrigatório',
            (int)($policy['requires_ad'] ?? 0) === 0,
            'A cortesia será liberada sem exigir anúncio.',
            'Cortesia: desative “Exigir anúncio concluído” para usar Gratuito rápido.',
            'access'
        );
    }
    if ($purpose === 'sponsored') {
        $requirements[] = partner_purpose_requirement(
            'courtesy_ad_required',
            'Anúncio obrigatório',
            (int)($policy['requires_ad'] ?? 0) === 1,
            'A política exige a conclusão do anúncio.',
            'Cortesia: ative “Exigir anúncio concluído”.',
            'access'
        );
        $requirements[] = partner_purpose_requirement(
            'ads_enabled',
            'Publicidade permitida',
            (int)($partner['ads_enabled'] ?? 0) === 1,
            'A publicidade está permitida neste estabelecimento.',
            'Portal: ative “Permitir anúncios neste estabelecimento”.',
            'portal'
        );
        try {
            $activeAds = partner_purpose_active_ads($pdo, $partner);
            $requirements[] = partner_purpose_requirement(
                'eligible_ad',
                'Anúncio ativo elegível',
                $activeAds > 0,
                $activeAds . ' anúncio(s) elegível(is) encontrado(s).',
                'Anúncios: cadastre um anúncio próprio ativo ou autorize uma campanha global elegível.',
                'portal'
            );
        } catch (Throwable $e) {
            $requirements[] = partner_purpose_requirement(
                'eligible_ad',
                'Anúncio ativo elegível',
                false,
                '',
                'Anúncios: não foi possível consultar as campanhas elegíveis.',
                'portal'
            );
        }
    }
    if ($purpose === 'paid') {
        $requirements[] = partner_purpose_requirement(
            'courtesy_disabled',
            'Cortesia desativada',
            (int)($policy['enabled'] ?? 0) === 0,
            'O estabelecimento opera somente com acesso pago.',
            'Cortesia: desative “Cortesia habilitada” para operar somente com acesso pago.',
            'access'
        );
        $requirements[] = partner_purpose_requirement(
            'portal_v3',
            'Portal V3 ativo',
            ($partner['portal_mode'] ?? '') === 'v3',
            'O Portal V3 está ativo e oferece a jornada de compra.',
            'Portal: selecione Portal V3 para vender acessos.',
            'portal'
        );
        $requirements = array_merge($requirements, partner_purpose_payment_requirements($pdo, $partner));
    }
    if ($purpose === 'hybrid') {
        $limited = !empty($policy['device_max_grants']) || !empty($policy['account_max_grants'])
            || !empty($policy['partner_max_grants']) || (int)($policy['cooldown_after_end_minutes'] ?? 0) > 0;
        $requirements[] = partner_purpose_requirement(
            'courtesy_limited',
            'Cortesia limitada',
            $limited,
            'A cortesia possui limite ou intervalo configurado.',
            'Cortesia: defina ao menos um limite ou intervalo para o modelo híbrido.',
            'access'
        );
        $requirements[] = partner_purpose_requirement(
            'portal_v3',
            'Portal V3 ativo',
            ($partner['portal_mode'] ?? '') === 'v3',
            'O Portal V3 está ativo e pode combinar cortesia e compra.',
            'Portal: selecione Portal V3 para oferecer o modelo híbrido.',
            'portal'
        );
        if ((int)($policy['requires_ad'] ?? 0) === 1) {
            $requirements[] = partner_purpose_requirement(
                'ads_enabled',
                'Publicidade permitida',
                (int)($partner['ads_enabled'] ?? 0) === 1,
                'A publicidade exigida pela cortesia está permitida.',
                'Portal: ative “Permitir anúncios neste estabelecimento”.',
                'portal'
            );
            try {
                $activeAds = partner_purpose_active_ads($pdo, $partner);
                $requirements[] = partner_purpose_requirement(
                    'eligible_ad',
                    'Anúncio ativo elegível',
                    $activeAds > 0,
                    $activeAds . ' anúncio(s) elegível(is) encontrado(s).',
                    'Anúncios: não há anúncio elegível para a cortesia híbrida.',
                    'portal'
                );
            } catch (Throwable $e) {
                $requirements[] = partner_purpose_requirement(
                    'eligible_ad',
                    'Anúncio ativo elegível',
                    false,
                    '',
                    'Anúncios: não foi possível consultar as campanhas elegíveis.',
                    'portal'
                );
            }
        }
        $requirements = array_merge($requirements, partner_purpose_payment_requirements($pdo, $partner));
    }

    $errors = array_values(array_unique(array_map(
        static fn(array $item): string => (string)$item['message'],
        array_filter($requirements, static fn(array $item): bool => empty($item['ready']))
    )));
    return [
        'ready' => !$errors,
        'purpose' => $purpose,
        'label' => partner_purpose_label($purpose),
        'description' => partner_purpose_description($purpose),
        'errors' => $errors,
        'requirements' => $requirements,
        'policy' => $policy,
    ];
}

function partner_purpose_change(PDO $pdo, int $partnerId, string $purpose): array
{
    if (!array_key_exists($purpose, partner_purpose_labels())) throw new InvalidArgumentException('Finalidade inválida.');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM partners WHERE id=? LIMIT 1 FOR UPDATE');
        $st->execute([$partnerId]);
        $partner = $st->fetch(PDO::FETCH_ASSOC);
        if (!$partner) throw new RuntimeException('Estabelecimento não encontrado.');
        $readiness = partner_purpose_readiness($pdo, $partner, $purpose);
        if (empty($readiness['ready'])) {
            throw new RuntimeException('Não foi possível ativar “' . partner_purpose_label($purpose) . '”. ' . implode(' ', $readiness['errors']));
        }
        $pdo->prepare('UPDATE partners SET access_purpose=?,updated_at=NOW() WHERE id=?')->execute([$purpose, $partnerId]);
        if ($ownsTransaction) $pdo->commit();
        return $readiness;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
