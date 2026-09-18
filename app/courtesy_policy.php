<?php

declare(strict_types=1);

require_once __DIR__ . '/partner_hotspots.php';

/**
 * Domínio central da cortesia FireSpot.
 *
 * Este arquivo não é carregado por nenhum portal legado. A ativação ocorrerá
 * gradualmente depois do shadow mode e do provisionamento RADIUS.
 */

function fs_courtesy_policy_defaults(): array
{
    return [
        'enabled' => 1,
        'grant_minutes' => 20,
        'credit_validity_minutes' => 1440,
        'consumption_mode' => 'online',
        'auth_mode' => 'account_device',
        'requires_ad' => 0,
        'exclude_active_paid' => 1,
        'exclude_provider' => 1,
        'enforcement_method' => 'radius',
        'block_while_active' => 1,
        'device_max_grants' => 1,
        'device_period_minutes' => 1440,
        'account_max_grants' => null,
        'account_period_minutes' => null,
        'partner_max_grants' => null,
        'partner_period_minutes' => null,
        'cooldown_after_end_minutes' => 0,
        'campaign_max_grants' => null,
        'campaign_starts_at' => null,
        'campaign_ends_at' => null,
        'reservation_ttl_seconds' => 120,
        'radius_group' => null,
        'revision' => 1,
    ];
}

/** @return array<string,list<string>> */
function fs_courtesy_override_group_fields(): array
{
    return [
        'enabled'=>['enabled'],
        'grant'=>['grant_minutes','credit_validity_minutes','consumption_mode'],
        'auth'=>['auth_mode'],
        'device_limit'=>['device_max_grants','device_period_minutes'],
        'account_limit'=>['account_max_grants','account_period_minutes'],
        'cooldown'=>['cooldown_after_end_minutes'],
    ];
}

function fs_courtesy_nullable_int(array $values, string $key, ?int $default = null): ?int
{
    if (!array_key_exists($key, $values)) return $default;
    if ($values[$key] === null || $values[$key] === '') return null;
    return (int) $values[$key];
}

function fs_courtesy_policy_normalize(array $values): array
{
    $base = fs_courtesy_policy_defaults();
    $get = static function (string $key) use ($values, $base) {
        return array_key_exists($key, $values) ? $values[$key] : $base[$key];
    };
    $bool = static function ($value): int {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false && (string) $value !== '0'
            ? ((int) $value === 1 ? 1 : 0)
            : (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
    };
    $date = static function ($value): ?string {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    };
    $group = trim((string) ($get('radius_group') ?? ''));

    return [
        'enabled' => $bool($get('enabled')),
        'grant_minutes' => (int) $get('grant_minutes'),
        'credit_validity_minutes' => (int) $get('credit_validity_minutes'),
        'consumption_mode' => strtolower(trim((string) $get('consumption_mode'))),
        'auth_mode' => strtolower(trim((string) $get('auth_mode'))),
        'requires_ad' => $bool($get('requires_ad')),
        'exclude_active_paid' => $bool($get('exclude_active_paid')),
        'exclude_provider' => $bool($get('exclude_provider')),
        'enforcement_method' => strtolower(trim((string) $get('enforcement_method'))),
        'block_while_active' => $bool($get('block_while_active')),
        'device_max_grants' => fs_courtesy_nullable_int($values, 'device_max_grants', $base['device_max_grants']),
        'device_period_minutes' => fs_courtesy_nullable_int($values, 'device_period_minutes', $base['device_period_minutes']),
        'account_max_grants' => fs_courtesy_nullable_int($values, 'account_max_grants', $base['account_max_grants']),
        'account_period_minutes' => fs_courtesy_nullable_int($values, 'account_period_minutes', $base['account_period_minutes']),
        'partner_max_grants' => fs_courtesy_nullable_int($values, 'partner_max_grants', $base['partner_max_grants']),
        'partner_period_minutes' => fs_courtesy_nullable_int($values, 'partner_period_minutes', $base['partner_period_minutes']),
        'cooldown_after_end_minutes' => (int) $get('cooldown_after_end_minutes'),
        'campaign_max_grants' => fs_courtesy_nullable_int($values, 'campaign_max_grants', $base['campaign_max_grants']),
        'campaign_starts_at' => $date($get('campaign_starts_at')),
        'campaign_ends_at' => $date($get('campaign_ends_at')),
        'reservation_ttl_seconds' => (int) $get('reservation_ttl_seconds'),
        'radius_group' => $group === '' ? null : substr($group, 0, 64),
        'revision' => max(1, (int) $get('revision')),
    ];
}

function fs_courtesy_time($value): ?int
{
    if ($value === null || $value === '') return null;
    if (is_int($value) || ctype_digit((string) $value)) return (int) $value;
    $timestamp = strtotime((string) $value . (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', (string) $value) ? '' : ' UTC'));
    return $timestamp === false ? null : $timestamp;
}

function fs_courtesy_policy_validate(array $policy): array
{
    $errors = [];
    if ((int) $policy['grant_minutes'] < 1 || (int) $policy['grant_minutes'] > 1440) {
        $errors[] = 'grant_minutes deve ficar entre 1 e 1440';
    }
    if ((int) $policy['credit_validity_minutes'] < (int) $policy['grant_minutes'] || (int) $policy['credit_validity_minutes'] > 525600) {
        $errors[] = 'credit_validity_minutes deve ser igual/maior que a concessão e no máximo 525600';
    }
    if (!in_array($policy['consumption_mode'], ['online', 'elapsed'], true)) {
        $errors[] = 'consumption_mode inválido';
    }
    if (!in_array($policy['auth_mode'], ['anonymous', 'account', 'account_device'], true)) {
        $errors[] = 'auth_mode inválido';
    }
    if (!in_array($policy['enforcement_method'], ['radius', 'mikrotik_local'], true)) {
        $errors[] = 'enforcement_method inválido';
    }
    if ($policy['radius_group'] !== null && !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', (string) $policy['radius_group'])) {
        $errors[] = 'radius_group aceita somente letras, números, ponto, sublinhado, dois-pontos e hífen';
    }

    foreach ([
        ['device_max_grants', 'device_period_minutes'],
        ['account_max_grants', 'account_period_minutes'],
        ['partner_max_grants', 'partner_period_minutes'],
    ] as [$limitField, $periodField]) {
        $limit = $policy[$limitField];
        $period = $policy[$periodField];
        if ($limit !== null && ((int) $limit < 1 || (int) $limit > 1000000)) {
            $errors[] = $limitField . ' deve ser NULL ou maior que zero';
        }
        if ($limit !== null && ($period === null || (int) $period < 1 || (int) $period > 525600)) {
            $errors[] = $periodField . ' é obrigatório e deve ficar entre 1 e 525600';
        }
        if ($limit === null && $period !== null) {
            $errors[] = $periodField . ' deve ser NULL quando o limite estiver desabilitado';
        }
    }

    if ((int) $policy['cooldown_after_end_minutes'] < 0 || (int) $policy['cooldown_after_end_minutes'] > 525600) {
        $errors[] = 'cooldown_after_end_minutes inválido';
    }
    if ((int) $policy['reservation_ttl_seconds'] < 30 || (int) $policy['reservation_ttl_seconds'] > 600) {
        $errors[] = 'reservation_ttl_seconds deve ficar entre 30 e 600';
    }

    $campaignStart = fs_courtesy_time($policy['campaign_starts_at']);
    $campaignEnd = fs_courtesy_time($policy['campaign_ends_at']);
    if ($policy['campaign_starts_at'] !== null && $campaignStart === null) $errors[] = 'campaign_starts_at inválido';
    if ($policy['campaign_ends_at'] !== null && $campaignEnd === null) $errors[] = 'campaign_ends_at inválido';
    if ($campaignStart !== null && $campaignEnd !== null && $campaignEnd <= $campaignStart) {
        $errors[] = 'campaign_ends_at deve ser posterior a campaign_starts_at';
    }
    if ($policy['campaign_max_grants'] !== null) {
        if ((int) $policy['campaign_max_grants'] < 1) $errors[] = 'campaign_max_grants deve ser NULL ou maior que zero';
        if ($campaignStart === null || $campaignEnd === null) {
            $errors[] = 'campanha com limite total exige início e fim';
        }
    }
    return array_values(array_unique($errors));
}

function fs_courtesy_policy_warnings(array $rawPolicy): array
{
    $policy = fs_courtesy_policy_normalize($rawPolicy);
    $warnings = [];
    if ($policy['device_max_grants'] !== null && $policy['device_period_minutes'] !== null
        && (int) $policy['device_period_minutes'] < (int) $policy['grant_minutes']) {
        $warnings[] = 'período por dispositivo menor que a duração da concessão';
    }
    if ($policy['device_max_grants'] !== null && (int) $policy['device_max_grants'] > 1) {
        $warnings[] = 'permite múltiplas concessões por dispositivo no período';
    }
    if ($policy['auth_mode'] === 'anonymous') {
        $warnings[] = 'acesso anônimo depende principalmente da identidade do dispositivo';
    }
    if ($policy['enforcement_method'] !== 'radius') {
        $warnings[] = 'MikroTik local ainda não está liberado para cutover';
    }
    return $warnings;
}

function fs_courtesy_context_normalize(array $context): array
{
    $device = strtolower(trim((string) ($context['device_key'] ?? '')));
    $account = strtolower(trim((string) ($context['account_key'] ?? '')));
    $mac = strtoupper(str_replace('-', ':', trim((string) ($context['mac'] ?? ''))));
    if (preg_match('/^[0-9A-F]{12}$/', $mac)) $mac = implode(':', str_split($mac, 2));
    if (!preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac)) $mac = null;
    $ip = trim((string) ($context['ip'] ?? ''));
    if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = null;
    $slug = static function ($value, string $fallback): string {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?: '';
        return substr($value !== '' ? $value : $fallback, 0, 32);
    };

    return [
        'partner_id' => max(0, (int) ($context['partner_id'] ?? 0)),
        'hotspot_id' => max(0, (int) ($context['hotspot_id'] ?? 0)),
        'device_key' => $device,
        'account_key' => $account,
        'account_username' => substr(trim((string) ($context['account_username'] ?? $context['account_key'] ?? '')), 0, 64),
        'mac' => $mac,
        'ip' => $ip,
        'portal' => $slug($context['portal'] ?? '', 'unknown'),
        'source' => $slug($context['source'] ?? '', 'unknown'),
        'associated_device' => !empty($context['associated_device']),
        'ad_completed' => !empty($context['ad_completed']),
        'has_active_paid' => !empty($context['has_active_paid']),
        'is_provider' => !empty($context['is_provider']),
        'idempotency_key' => trim((string) ($context['idempotency_key'] ?? '')),
    ];
}

function fs_courtesy_identity_hash(string $value): string
{
    return hash('sha256', strtolower(trim($value)));
}

function fs_courtesy_result(bool $allowed, string $code, string $message, array $extra = []): array
{
    return array_merge([
        'allowed' => $allowed,
        'code' => $code,
        'message' => $message,
        'retry_at' => null,
    ], $extra);
}

function fs_courtesy_retry_iso(?int $timestamp): ?string
{
    return $timestamp && $timestamp > 0 ? gmdate(DATE_ATOM, $timestamp) : null;
}

function fs_courtesy_evaluate(array $rawPolicy, array $rawContext, array $usage = [], ?int $now = null): array
{
    $policy = fs_courtesy_policy_normalize($rawPolicy);
    $errors = fs_courtesy_policy_validate($policy);
    if ($errors) {
        return fs_courtesy_result(false, 'POLICY_INVALID', 'A política de cortesia precisa ser revisada.', ['errors' => $errors]);
    }

    $context = fs_courtesy_context_normalize($rawContext);
    $now = $now ?? time();
    if (!$policy['enabled']) return fs_courtesy_result(false, 'POLICY_DISABLED', 'Este estabelecimento não oferece cortesia.');

    $campaignStart = fs_courtesy_time($policy['campaign_starts_at']);
    $campaignEnd = fs_courtesy_time($policy['campaign_ends_at']);
    if ($campaignStart !== null && $now < $campaignStart) {
        return fs_courtesy_result(false, 'CAMPAIGN_NOT_STARTED', 'A campanha de cortesia ainda não começou.', ['retry_at' => fs_courtesy_retry_iso($campaignStart)]);
    }
    if ($campaignEnd !== null && $now >= $campaignEnd) {
        return fs_courtesy_result(false, 'CAMPAIGN_ENDED', 'A campanha de cortesia terminou.');
    }
    if ($context['device_key'] === '') return fs_courtesy_result(false, 'DEVICE_REQUIRED', 'Não foi possível identificar este dispositivo.');

    if (in_array($policy['auth_mode'], ['account', 'account_device'], true) && $context['account_key'] === '') {
        return fs_courtesy_result(false, 'AUTH_REQUIRED', 'Entre com sua conta para solicitar a cortesia.');
    }
    if ($policy['auth_mode'] === 'account_device' && !$context['associated_device']) {
        return fs_courtesy_result(false, 'DEVICE_ASSOCIATION_REQUIRED', 'Associe este dispositivo à sua conta.');
    }
    if ($policy['requires_ad'] && !$context['ad_completed']) {
        return fs_courtesy_result(false, 'AD_REQUIRED', 'Conclua o anúncio para solicitar a cortesia.');
    }
    if ($policy['exclude_provider'] && $context['is_provider']) {
        return fs_courtesy_result(false, 'PROVIDER_NOT_ELIGIBLE', 'Clientes de provedor não utilizam cortesia.');
    }
    if ($policy['exclude_active_paid'] && $context['has_active_paid']) {
        return fs_courtesy_result(false, 'ACTIVE_PLAN_NOT_ELIGIBLE', 'Você já possui acesso ativo.');
    }
    if ($policy['block_while_active'] && (int) ($usage['active_count'] ?? 0) > 0) {
        return fs_courtesy_result(false, 'ACTIVE_GRANT', 'Já existe uma cortesia ativa para este dispositivo.', [
            'retry_at' => fs_courtesy_retry_iso(fs_courtesy_time($usage['active_retry_at'] ?? null)),
        ]);
    }

    $lastEndedAt = fs_courtesy_time($usage['last_ended_at'] ?? null);
    if ((int) $policy['cooldown_after_end_minutes'] > 0 && $lastEndedAt !== null) {
        $retry = $lastEndedAt + ((int) $policy['cooldown_after_end_minutes'] * 60);
        if ($retry > $now) {
            return fs_courtesy_result(false, 'COOLDOWN', 'Aguarde antes de solicitar uma nova cortesia.', ['retry_at' => fs_courtesy_retry_iso($retry)]);
        }
    }

    $quota = static function (string $scope, ?int $limit, ?int $period, array $bucket) use ($now): ?array {
        if ($limit === null) return null;
        if ((int) ($bucket['count'] ?? 0) < $limit) return null;
        $oldest = fs_courtesy_time($bucket['oldest_at'] ?? null);
        $retry = $oldest !== null && $period !== null ? $oldest + ($period * 60) : null;
        $messages = [
            'DEVICE' => 'O limite de cortesia deste dispositivo foi atingido.',
            'ACCOUNT' => 'O limite de cortesia desta conta foi atingido.',
            'PARTNER' => 'O limite temporário de cortesias do estabelecimento foi atingido.',
        ];
        return fs_courtesy_result(false, $scope . '_LIMIT', $messages[$scope], [
            'retry_at' => $retry !== null && $retry > $now ? fs_courtesy_retry_iso($retry) : null,
        ]);
    };

    $denied = $quota('DEVICE', $policy['device_max_grants'], $policy['device_period_minutes'], (array) ($usage['device'] ?? []));
    if ($denied) return $denied;
    if ($context['account_key'] !== '') {
        $denied = $quota('ACCOUNT', $policy['account_max_grants'], $policy['account_period_minutes'], (array) ($usage['account'] ?? []));
        if ($denied) return $denied;
    }
    $denied = $quota('PARTNER', $policy['partner_max_grants'], $policy['partner_period_minutes'], (array) ($usage['partner'] ?? []));
    if ($denied) return $denied;

    if ($policy['campaign_max_grants'] !== null && (int) ($usage['campaign_count'] ?? 0) >= (int) $policy['campaign_max_grants']) {
        return fs_courtesy_result(false, 'CAMPAIGN_LIMIT', 'O limite total desta campanha foi atingido.', [
            'retry_at' => fs_courtesy_retry_iso($campaignEnd),
        ]);
    }

    return fs_courtesy_result(true, 'ELIGIBLE', 'Cortesia disponível.', [
        'minutes' => (int) $policy['grant_minutes'],
        'consumption_mode' => $policy['consumption_mode'],
        'enforcement_method' => $policy['enforcement_method'],
        'policy' => $policy,
    ]);
}

function fs_courtesy_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM courtesy_policy_defaults LIMIT 0');
        $pdo->query('SELECT partner_id FROM courtesy_partner_policies LIMIT 0');
        $pdo->query('SELECT public_id FROM courtesy_grants LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fs_courtesy_hotspot_column_ready(PDO $pdo): bool
{
    try {
        $st=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='courtesy_grants' AND COLUMN_NAME='hotspot_id'");
        return (int)$st->fetchColumn()===1;
    } catch (Throwable $e) {
        return false;
    }
}

function fs_courtesy_partner(PDO $pdo, int $partnerId): ?array
{
    if ($partnerId <= 0) return null;
    $st = $pdo->prepare('SELECT id,code,name,active,require_auth,free_minutes,max_uses_total,max_uses_per_device,window_per_device_minutes FROM partners WHERE id=? LIMIT 1');
    $st->execute([$partnerId]);
    $partner = $st->fetch(PDO::FETCH_ASSOC);
    return $partner ?: null;
}

function fs_courtesy_policy_resolve(PDO $pdo, int $partnerId, ?int $hotspotId = null): array
{
    $partner = fs_courtesy_partner($pdo, $partnerId);
    if (!$partner) throw new InvalidArgumentException('Estabelecimento não encontrado.');

    $source = 'legacy';
    $warnings = [];
    if (fs_courtesy_schema_ready($pdo)) {
        $st = $pdo->prepare('SELECT * FROM courtesy_partner_policies WHERE partner_id=? LIMIT 1');
        $st->execute([$partnerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $source = 'partner';
        } else {
            $row = $pdo->query('SELECT * FROM courtesy_policy_defaults WHERE id=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            $source = 'global';
        }
        if (!$row) throw new RuntimeException('Política global de cortesia ausente.');
        $policy = fs_courtesy_policy_normalize($row);
    } else {
        $legacy = fs_courtesy_policy_defaults();
        $legacy['grant_minutes'] = max(1, min(1440, (int) $partner['free_minutes']));
        $legacy['auth_mode'] = (int) $partner['require_auth'] === 1 ? 'account_device' : 'anonymous';
        $legacy['device_max_grants'] = max(1, (int) $partner['max_uses_per_device']);
        $legacy['device_period_minutes'] = max(1, (int) $partner['window_per_device_minutes']);
        if ($partner['max_uses_total'] !== null) {
            $warnings[] = 'max_uses_total não foi convertido porque não possui vigência de campanha';
        }
        $policy = fs_courtesy_policy_normalize($legacy);
    }

    $overrideRevision=null;
    if($hotspotId!==null&&$hotspotId>0){
        try{
            $override=$pdo->prepare("SELECT o.* FROM courtesy_hotspot_policy_overrides o JOIN partner_hotspots h ON h.id=o.hotspot_id AND h.partner_id=o.partner_id WHERE o.partner_id=? AND o.hotspot_id=? AND o.state='published' AND h.active=1 LIMIT 1");
            $override->execute([$partnerId,$hotspotId]);$override=$override->fetch(PDO::FETCH_ASSOC);
            if($override){$selected=array_filter(explode(',',(string)($override['override_mask']??'')));foreach(fs_courtesy_override_group_fields() as $group=>$fields)if(in_array($group,$selected,true))foreach($fields as $field)if(array_key_exists($field,$override))$policy[$field]=$override[$field];$policy=fs_courtesy_policy_normalize($policy);$source='hotspot';$overrideRevision=(int)$override['revision'];}
        }catch(Throwable $e){}
        try{
            // A modalidade comercial do ponto é soberana sobre os padrões do
            // estabelecimento. As demais regras continuam vindo do override
            // versionado acima; nenhuma consulta alcança o equipamento.
            $commercial=$pdo->prepare("SELECT commercial.courtesy_mode FROM partner_hotspot_commercial_policies commercial JOIN partner_hotspots point ON point.id=commercial.hotspot_id AND point.partner_id=commercial.partner_id WHERE commercial.partner_id=? AND commercial.hotspot_id=? AND point.active=1 LIMIT 1");
            $commercial->execute([$partnerId,$hotspotId]);$mode=(string)($commercial->fetchColumn()?:'');
            if(in_array($mode,['disabled','direct','sponsored'],true)){$policy['enabled']=$mode==='disabled'?0:1;$policy['requires_ad']=$mode==='sponsored'?1:0;$policy=fs_courtesy_policy_normalize($policy);$source='hotspot';}
        }catch(Throwable $e){}
    }
    $policy['policy_source'] = $source;
    $policy['policy_revision'] = $source . ':' . ($overrideRevision??(int)$policy['revision']);
    $policy['partner_id'] = (int) $partner['id'];
    $policy['partner_code'] = (string) $partner['code'];
    $policy['partner_name'] = (string) $partner['name'];
    $policy['partner_active'] = (int) $partner['active'] === 1;
    $policy['migration_warnings'] = $warnings;
    return $policy;
}

/**
 * Persiste a política completa do estabelecimento e mantém somente os
 * campos legados semanticamente compatíveis alinhados durante a transição.
 */
function fs_courtesy_policy_save(PDO $pdo, int $partnerId, array $values, bool $syncLegacy = true): array
{
    if ($partnerId <= 0 || !fs_courtesy_schema_ready($pdo)) {
        throw new RuntimeException('A estrutura da cortesia unificada ainda não está disponível.');
    }
    if (!fs_courtesy_partner($pdo, $partnerId)) {
        throw new InvalidArgumentException('Estabelecimento não encontrado.');
    }

    $policy = fs_courtesy_policy_normalize($values);
    $errors = fs_courtesy_policy_validate($policy);
    if ($errors) throw new InvalidArgumentException(implode('; ', $errors));

    $columns = [
        'enabled', 'grant_minutes', 'credit_validity_minutes', 'consumption_mode', 'auth_mode',
        'requires_ad', 'exclude_active_paid', 'exclude_provider', 'enforcement_method',
        'block_while_active', 'device_max_grants', 'device_period_minutes', 'account_max_grants',
        'account_period_minutes', 'partner_max_grants', 'partner_period_minutes',
        'cooldown_after_end_minutes', 'campaign_max_grants', 'campaign_starts_at',
        'campaign_ends_at', 'reservation_ttl_seconds', 'radius_group',
    ];
    $params = [$partnerId];
    foreach ($columns as $column) $params[] = $policy[$column];
    $updates = [];
    foreach ($columns as $column) $updates[] = $column . '=VALUES(' . $column . ')';

    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) $pdo->beginTransaction();
    try {
        $sql = 'INSERT INTO courtesy_partner_policies (partner_id,' . implode(',', $columns) . ') VALUES ('
            . implode(',', array_fill(0, count($params), '?')) . ') ON DUPLICATE KEY UPDATE '
            . implode(',', $updates) . ',revision=revision+1,updated_at=CURRENT_TIMESTAMP';
        $pdo->prepare($sql)->execute($params);

        if ($syncLegacy) {
            $legacyFields = [
                'require_auth' => $policy['auth_mode'] === 'anonymous' ? 0 : 1,
                'free_minutes' => (int) $policy['grant_minutes'],
            ];
            if ($policy['device_max_grants'] !== null && $policy['device_period_minutes'] !== null) {
                $legacyFields['max_uses_per_device'] = (int) $policy['device_max_grants'];
                $legacyFields['window_per_device_minutes'] = (int) $policy['device_period_minutes'];
            }
            $assignments = [];
            $legacyParams = [];
            foreach ($legacyFields as $column => $value) {
                $assignments[] = $column . '=?';
                $legacyParams[] = $value;
            }
            $legacyParams[] = $partnerId;
            $pdo->prepare('UPDATE partners SET ' . implode(',', $assignments) . ',updated_at=NOW() WHERE id=?')
                ->execute($legacyParams);
        }

        if ($startedTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return fs_courtesy_policy_resolve($pdo, $partnerId);
}

/**
 * Ponte temporária: mantém os campos compatíveis do painel legado alinhados
 * enquanto a tela definitiva da política unificada ainda não foi ativada.
 */
function fs_courtesy_sync_legacy_partner(PDO $pdo, int $partnerId): bool
{
    if ($partnerId <= 0 || !fs_courtesy_schema_ready($pdo)) return false;
    try {
        $partner = fs_courtesy_partner($pdo, $partnerId);
        if (!$partner) return false;
        $st = $pdo->prepare("INSERT INTO courtesy_partner_policies
            (partner_id,grant_minutes,auth_mode,device_max_grants,device_period_minutes)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              grant_minutes=VALUES(grant_minutes),
              auth_mode=VALUES(auth_mode),
              device_max_grants=VALUES(device_max_grants),
              device_period_minutes=VALUES(device_period_minutes),
              revision=revision+1,
              updated_at=CURRENT_TIMESTAMP");
        $st->execute([
            $partnerId,
            max(1, min(1440, (int) $partner['free_minutes'])),
            (int) $partner['require_auth'] === 1 ? 'account_device' : 'anonymous',
            max(1, (int) $partner['max_uses_per_device']),
            max(1, (int) $partner['window_per_device_minutes']),
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('[courtesy legacy policy sync] ' . $e->getMessage());
        return false;
    }
}

function fs_courtesy_counted_condition(): string
{
    return "(status IN ('active','exhausted','expired','revoked') OR (status IN ('reserved','provisioning') AND reservation_expires_at>?))";
}

function fs_courtesy_usage_bucket(PDO $pdo, int $partnerId, ?string $hashColumn, ?string $hash, string $cutoff, string $now): array
{
    $allowedColumns = ['device_key_hash', 'account_key_hash'];
    $params = [$partnerId];
    $where = 'partner_id=?';
    if ($hashColumn !== null) {
        if (!in_array($hashColumn, $allowedColumns, true)) throw new InvalidArgumentException('Dimensão de cortesia inválida.');
        $where .= ' AND ' . $hashColumn . '=?';
        $params[] = $hash;
    }
    $where .= ' AND created_at>=? AND ' . fs_courtesy_counted_condition();
    $params[] = $cutoff;
    $params[] = $now;
    $st = $pdo->prepare('SELECT COUNT(*) AS total,MIN(created_at) AS oldest_at FROM courtesy_grants WHERE ' . $where);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['count' => (int) ($row['total'] ?? 0), 'oldest_at' => $row['oldest_at'] ?? null];
}

function fs_courtesy_usage_snapshot(PDO $pdo, array $rawPolicy, array $rawContext, ?int $now = null): array
{
    $policy = fs_courtesy_policy_normalize($rawPolicy);
    $context = fs_courtesy_context_normalize($rawContext);
    $now = $now ?? time();
    $nowSql = gmdate('Y-m-d H:i:s', $now);
    $partnerId = (int) $context['partner_id'];
    if ($partnerId <= 0 || $context['device_key'] === '') {
        return ['active_count' => 0, 'device' => [], 'account' => [], 'partner' => [], 'campaign_count' => 0, 'last_ended_at' => null];
    }
    if (!fs_courtesy_schema_ready($pdo)) {
        return ['active_count' => 0, 'device' => [], 'account' => [], 'partner' => [], 'campaign_count' => 0, 'last_ended_at' => null];
    }

    $deviceHash = fs_courtesy_identity_hash($context['device_key']);
    $st = $pdo->prepare("SELECT COUNT(*) AS total,
            MIN(CASE WHEN status IN ('reserved','provisioning') THEN reservation_expires_at ELSE expires_at END) AS retry_at
        FROM courtesy_grants
        WHERE partner_id=? AND device_key_hash=? AND
          (((status IN ('reserved','provisioning')) AND reservation_expires_at>?) OR
           (status='active' AND (consumption_mode='online' OR expires_at IS NULL OR expires_at>?)))");
    $st->execute([$partnerId, $deviceHash, $nowSql, $nowSql]);
    $active = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $st = $pdo->prepare("SELECT MAX(COALESCE(ended_at,expires_at)) FROM courtesy_grants
        WHERE partner_id=? AND device_key_hash=? AND status IN ('exhausted','expired','revoked')");
    $st->execute([$partnerId, $deviceHash]);
    $lastEnded = $st->fetchColumn();

    $cutoff = static function (?int $minutes) use ($now): string {
        return gmdate('Y-m-d H:i:s', $now - (max(1, (int) $minutes) * 60));
    };
    $device = $policy['device_max_grants'] === null ? [] : fs_courtesy_usage_bucket(
        $pdo, $partnerId, 'device_key_hash', $deviceHash, $cutoff($policy['device_period_minutes']), $nowSql
    );
    $account = [];
    if ($policy['account_max_grants'] !== null && $context['account_key'] !== '') {
        $account = fs_courtesy_usage_bucket(
            $pdo,
            $partnerId,
            'account_key_hash',
            fs_courtesy_identity_hash($context['account_key']),
            $cutoff($policy['account_period_minutes']),
            $nowSql
        );
    }
    $partner = $policy['partner_max_grants'] === null ? [] : fs_courtesy_usage_bucket(
        $pdo, $partnerId, null, null, $cutoff($policy['partner_period_minutes']), $nowSql
    );

    $campaignCount = 0;
    if ($policy['campaign_max_grants'] !== null) {
        $campaignStart = gmdate('Y-m-d H:i:s', (int) fs_courtesy_time($policy['campaign_starts_at']));
        $bucket = fs_courtesy_usage_bucket($pdo, $partnerId, null, null, $campaignStart, $nowSql);
        $campaignCount = (int) $bucket['count'];
    }
    return [
        'active_count' => (int) ($active['total'] ?? 0),
        'active_retry_at' => $active['retry_at'] ?? null,
        'last_ended_at' => $lastEnded ?: null,
        'device' => $device,
        'account' => $account,
        'partner' => $partner,
        'campaign_count' => $campaignCount,
    ];
}

function fs_courtesy_check(PDO $pdo, array $context, ?int $now = null): array
{
    $context = fs_courtesy_context_normalize($context);
    $policy = fs_courtesy_policy_resolve($pdo, (int) $context['partner_id'],(int)$context['hotspot_id']?:null);
    if (!$policy['partner_active']) return fs_courtesy_result(false, 'PARTNER_INACTIVE', 'Este estabelecimento está inativo.');
    $usage = fs_courtesy_usage_snapshot($pdo, $policy, $context, $now);
    $decision = fs_courtesy_evaluate($policy, $context, $usage, $now);
    $decision['policy_source'] = $policy['policy_source'];
    $decision['policy_revision'] = $policy['policy_revision'];
    return $decision;
}

function fs_courtesy_lock(PDO $pdo, int $partnerId): string
{
    $name = 'fscourtesy:partner:' . $partnerId;
    $st = $pdo->prepare('SELECT GET_LOCK(?,5)');
    $st->execute([$name]);
    if ((int) $st->fetchColumn() !== 1) throw new RuntimeException('Outra solicitação de cortesia está sendo processada neste estabelecimento.');
    return $name;
}

function fs_courtesy_unlock(PDO $pdo, string $name): void
{
    try {
        $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $st->execute([$name]);
    } catch (Throwable $e) {
        error_log('[courtesy unlock] ' . $e->getMessage());
    }
}

function fs_courtesy_find_idempotent(PDO $pdo, string $idempotencyHash): ?array
{
    $st = $pdo->prepare('SELECT * FROM courtesy_grants WHERE idempotency_key_hash=? LIMIT 1');
    $st->execute([$idempotencyHash]);
    $grant = $st->fetch(PDO::FETCH_ASSOC);
    return $grant ?: null;
}

function fs_courtesy_idempotency_hash(array $context): string
{
    return hash('sha256', implode('|', [
        (string) ((int) ($context['partner_id'] ?? 0)),
        fs_courtesy_identity_hash((string) ($context['device_key'] ?? '')),
        (string) ($context['idempotency_key'] ?? ''),
    ]));
}

function fs_courtesy_public_grant(array $grant): array
{
    return [
        'id' => isset($grant['id']) ? (int) $grant['id'] : null,
        'public_id' => (string) ($grant['public_id'] ?? ''),
        'status' => (string) ($grant['status'] ?? ''),
        'minutes' => (int) ($grant['grant_minutes'] ?? $grant['minutes'] ?? 0),
        'reservation_expires_at' => $grant['reservation_expires_at'] ?? null,
        'activated_at' => $grant['activated_at'] ?? null,
        'expires_at' => $grant['expires_at'] ?? null,
        'enforcement_method' => $grant['enforcement_method'] ?? null,
    ];
}

/**
 * Reserva uma concessão após reavaliar a política sob lock.
 * Não provisiona RADIUS; os portais a acessam somente pela fachada de cutover.
 */
function fs_courtesy_reserve(PDO $pdo, array $rawContext, ?int $now = null): array
{
    if (!fs_courtesy_schema_ready($pdo)) throw new RuntimeException('A migração da cortesia unificada ainda não foi aplicada.');
    $context = fs_courtesy_context_normalize($rawContext);
    if ($context['partner_id'] <= 0) throw new InvalidArgumentException('Estabelecimento obrigatório.');
    if ($context['device_key'] === '') return fs_courtesy_result(false, 'DEVICE_REQUIRED', 'Não foi possível identificar este dispositivo.');
    if ($context['idempotency_key'] === '') throw new InvalidArgumentException('Chave de idempotência obrigatória.');
    if (fs_partner_hotspots_schema_ready($pdo)) {
        $hotspot = fs_partner_hotspot_for_operation($pdo,$context['partner_id'],$context['hotspot_id'] > 0 ? $context['hotspot_id'] : null);
        if (!$hotspot || (int)($hotspot['hotspot_active'] ?? 0) !== 1) throw new RuntimeException('Instalação técnica indisponível.');
        $context['hotspot_id'] = (int)$hotspot['hotspot_id'];
    }

    $idempotencyHash = fs_courtesy_idempotency_hash($context);
    $existing = fs_courtesy_find_idempotent($pdo, $idempotencyHash);
    if ($existing) return fs_courtesy_result(true, 'RESERVATION_REUSED', 'Solicitação de cortesia já registrada.', ['reused' => true, 'grant' => fs_courtesy_public_grant($existing)]);

    $lock = fs_courtesy_lock($pdo, $context['partner_id']);
    try {
        $existing = fs_courtesy_find_idempotent($pdo, $idempotencyHash);
        if ($existing) return fs_courtesy_result(true, 'RESERVATION_REUSED', 'Solicitação de cortesia já registrada.', ['reused' => true, 'grant' => fs_courtesy_public_grant($existing)]);

        $now = $now ?? time();
        $pdo->beginTransaction();
        try {
            $policy = fs_courtesy_policy_resolve($pdo, $context['partner_id'],$context['hotspot_id']>0?$context['hotspot_id']:null);
            if (!$policy['partner_active']) {
                $pdo->rollBack();
                return fs_courtesy_result(false, 'PARTNER_INACTIVE', 'Este estabelecimento está inativo.');
            }
            $usage = fs_courtesy_usage_snapshot($pdo, $policy, $context, $now);
            $decision = fs_courtesy_evaluate($policy, $context, $usage, $now);
            if (!$decision['allowed']) {
                $pdo->rollBack();
                return $decision;
            }

            $publicId = bin2hex(random_bytes(16));
            $createdAt = gmdate('Y-m-d H:i:s', $now);
            $reservationExpires = gmdate('Y-m-d H:i:s', $now + ((int) $policy['reservation_ttl_seconds']));
            $snapshot = json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $grantValues = [
                $publicId,
                $idempotencyHash,
                (int) $policy['partner_id'],
                (string) $policy['partner_code'],
                (string) $policy['partner_name'],
                (string) $policy['policy_revision'],
                $snapshot,
                fs_courtesy_identity_hash($context['device_key']),
                $context['account_key'] !== '' ? fs_courtesy_identity_hash($context['account_key']) : null,
                $context['mac'],
                $context['ip'],
                $context['account_username'] !== '' ? $context['account_username'] : null,
                $context['portal'],
                $context['source'],
                $policy['enforcement_method'],
                $policy['consumption_mode'],
                (int) $policy['grant_minutes'],
                (int) $policy['grant_minutes'] * 60,
                $reservationExpires,
                $context['ad_completed'] ? 1 : 0,
                $createdAt,
                $createdAt,
            ];
            if (fs_courtesy_hotspot_column_ready($pdo)) {
                $st = $pdo->prepare('INSERT INTO courtesy_grants
                    (public_id,idempotency_key_hash,partner_id,hotspot_id,partner_code,partner_name,policy_revision,policy_snapshot,
                     device_key_hash,account_key_hash,device_mac,device_ip,account_username,portal,source,
                     enforcement_method,consumption_mode,grant_minutes,granted_seconds,status,reservation_expires_at,
                     ad_completed,created_at,updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'reserved\',?,?,?,?)');
                array_splice($grantValues,3,0,[$context['hotspot_id'] > 0 ? $context['hotspot_id'] : null]);
            } else {
                $st = $pdo->prepare('INSERT INTO courtesy_grants
                    (public_id,idempotency_key_hash,partner_id,partner_code,partner_name,policy_revision,policy_snapshot,
                     device_key_hash,account_key_hash,device_mac,device_ip,account_username,portal,source,
                     enforcement_method,consumption_mode,grant_minutes,granted_seconds,status,reservation_expires_at,
                     ad_completed,created_at,updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'reserved\',?,?,?,?)');
            }
            $st->execute($grantValues);
            $grantId = (int) $pdo->lastInsertId();
            $pdo->commit();
            return fs_courtesy_result(true, 'RESERVED', 'Cortesia reservada para provisionamento.', [
                'reused' => false,
                'grant' => [
                    'id' => $grantId,
                    'public_id' => $publicId,
                    'status' => 'reserved',
                    'minutes' => (int) $policy['grant_minutes'],
                    'reservation_expires_at' => fs_courtesy_retry_iso(strtotime($reservationExpires . ' UTC') ?: null),
                ],
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    } finally {
        fs_courtesy_unlock($pdo, $lock);
    }
}
