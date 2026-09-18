<?php

declare(strict_types=1);

require_once __DIR__ . '/courtesy_policy.php';
require_once __DIR__ . '/radius_db.php';

function fs_courtesy_radius_grant(PDO $pdo, string $publicId, bool $forUpdate = false): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) return null;
    $sql = 'SELECT * FROM courtesy_grants WHERE public_id=? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $st = $pdo->prepare($sql);
    $st->execute([$publicId]);
    $grant = $st->fetch(PDO::FETCH_ASSOC);
    return $grant ?: null;
}

function fs_courtesy_radius_policy(array $grant): array
{
    $snapshot = json_decode((string) ($grant['policy_snapshot'] ?? ''), true);
    return fs_courtesy_policy_normalize(is_array($snapshot) ? $snapshot : []);
}

function fs_courtesy_radius_username(array $grant): string
{
    $publicId = strtolower((string) ($grant['public_id'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) throw new InvalidArgumentException('Concessão sem identificador válido.');
    return 'cty_' . substr($publicId, 0, 24);
}

function fs_courtesy_radius_expiration_value(int $timestamp): string
{
    $timezoneName = trim((string) env('RADIUS_TIMEZONE', 'America/Manaus'));
    try {
        $timezone = new DateTimeZone($timezoneName !== '' ? $timezoneName : 'America/Manaus');
    } catch (Throwable $e) {
        throw new RuntimeException('RADIUS_TIMEZONE inválida.');
    }
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('d M Y H:i:s');
}

function fs_courtesy_radius_expiry(array $grant, array $policy, int $activatedAt): int
{
    if ($policy['consumption_mode'] === 'elapsed') {
        $expiry = $activatedAt + ((int) $grant['granted_seconds']);
    } else {
        $expiry = $activatedAt + ((int) $policy['credit_validity_minutes'] * 60);
    }
    $campaignEnd = fs_courtesy_time($policy['campaign_ends_at']);
    if ($campaignEnd !== null) $expiry = min($expiry, $campaignEnd);
    return $expiry;
}

function fs_courtesy_radius_delete_credentials(PDO $radius, string $username): void
{
    if ($username === '') return;
    $ownsTransaction = !$radius->inTransaction();
    if ($ownsTransaction) $radius->beginTransaction();
    try {
        $radius->prepare('DELETE FROM radcheck WHERE username=?')->execute([$username]);
        $radius->prepare('DELETE FROM radreply WHERE username=?')->execute([$username]);
        $radius->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$username]);
        if ($ownsTransaction) $radius->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $radius->inTransaction()) $radius->rollBack();
        throw $e;
    }
}

function fs_courtesy_radius_install_credentials(PDO $radius, array $grant, array $policy, string $username, string $password, int $expiry): void
{
    $ownsTransaction = !$radius->inTransaction();
    if ($ownsTransaction) $radius->beginTransaction();
    try {
        fs_courtesy_radius_delete_credentials($radius, $username);
        $check = $radius->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,':=',?)");
        $check->execute([$username, 'Cleartext-Password', $password]);
        $check->execute([$username, 'Max-All-Session', (string) max(60, (int) $grant['granted_seconds'])]);
        $check->execute([$username, 'Simultaneous-Use', '1']);
        $check->execute([$username, 'Expiration', fs_courtesy_radius_expiration_value($expiry)]);

        $mac = strtoupper(trim((string) ($grant['device_mac'] ?? '')));
        if (preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac)) {
            $station = $radius->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,'==',?)");
            $station->execute([$username, 'Calling-Station-Id', $mac]);
        }

        $reply = $radius->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,?,':=',?)");
        $reply->execute([$username, 'Session-Timeout', (string) max(60, (int) $grant['granted_seconds'])]);
        $reply->execute([$username, 'Acct-Interim-Interval', '60']);

        $group = trim((string) ($policy['radius_group'] ?? ''));
        if ($group !== '') {
            $st = $radius->prepare('INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)');
            $st->execute([$username, $group]);
        }
        if ($ownsTransaction) $radius->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $radius->inTransaction()) $radius->rollBack();
        throw $e;
    }
}

/**
 * Provisiona no RADIUS uma reserva já aprovada pelo motor central.
 * Retorna a senha somente ao chamador imediato; ela não é gravada no ledger.
 */
function fs_courtesy_radius_provision(PDO $app, string $publicId, ?PDO $radius = null, ?int $now = null): array
{
    $radius = $radius ?? fs_radius_db();
    $now = $now ?? time();
    $nowSql = gmdate('Y-m-d H:i:s', $now);

    $app->beginTransaction();
    try {
        $grant = fs_courtesy_radius_grant($app, $publicId, true);
        if (!$grant) throw new InvalidArgumentException('Concessão não encontrada.');
        if ((string) $grant['enforcement_method'] !== 'radius') throw new RuntimeException('Esta concessão não utiliza enforcement RADIUS.');
        if ((string) $grant['status'] === 'active') {
            $app->commit();
            return fs_courtesy_radius_prepare_reconnect($app, $publicId, $radius, $now);
        }
        if ((string) $grant['status'] !== 'reserved') throw new RuntimeException('Concessão não está disponível para provisionamento.');
        if ((fs_courtesy_time($grant['reservation_expires_at']) ?? 0) <= $now) {
            $app->prepare("UPDATE courtesy_grants SET status='expired',ended_at=?,updated_at=? WHERE id=?")
                ->execute([$nowSql, $nowSql, (int) $grant['id']]);
            $app->commit();
            return fs_courtesy_result(false, 'RESERVATION_EXPIRED', 'A reserva de cortesia expirou.');
        }
        $app->prepare("UPDATE courtesy_grants
            SET status='provisioning',provision_attempts=provision_attempts+1,failure_code=NULL,failure_detail=NULL,updated_at=?
            WHERE id=?")
            ->execute([$nowSql, (int) $grant['id']]);
        $app->commit();
    } catch (Throwable $e) {
        if ($app->inTransaction()) $app->rollBack();
        throw $e;
    }

    $policy = fs_courtesy_radius_policy($grant);
    $username = fs_courtesy_radius_username($grant);
    $password = bin2hex(random_bytes(12));
    $expiry = fs_courtesy_radius_expiry($grant, $policy, $now);

    try {
        fs_courtesy_radius_install_credentials($radius, $grant, $policy, $username, $password, $expiry);
        $updated = $app->prepare("UPDATE courtesy_grants
            SET status='active',radius_username=?,enforcement_ref=?,activated_at=?,expires_at=?,updated_at=?
            WHERE id=? AND status='provisioning'");
        $updated->execute([$username, $username, $nowSql, gmdate('Y-m-d H:i:s', $expiry), $nowSql, (int) $grant['id']]);
        if ($updated->rowCount() !== 1) throw new RuntimeException('O ledger recusou a ativação da credencial RADIUS.');
    } catch (Throwable $e) {
        try {
            fs_courtesy_radius_delete_credentials($radius, $username);
        } catch (Throwable $cleanupError) {
            error_log('[courtesy radius cleanup after failure] ' . $cleanupError->getMessage());
        }
        $detail = substr($e->getMessage(), 0, 255);
        $app->prepare("UPDATE courtesy_grants
            SET status='failed',failure_code='RADIUS_PROVISION_FAILED',failure_detail=?,ended_at=?,updated_at=?
            WHERE id=? AND status='provisioning'")
            ->execute([$detail, $nowSql, $nowSql, (int) $grant['id']]);
        throw $e;
    }

    return fs_courtesy_result(true, 'ACTIVE', 'Credencial de cortesia ativa.', [
        'grant' => [
            'public_id' => $publicId,
            'status' => 'active',
            'username' => $username,
            'password' => $password,
            'minutes' => (int) $grant['grant_minutes'],
            'remaining_seconds' => (int) $grant['granted_seconds'],
            'expires_at' => fs_courtesy_retry_iso($expiry),
        ],
    ]);
}

function fs_courtesy_radius_balance(PDO $radius, array $grant, ?int $now = null): array
{
    $now = $now ?? time();
    $username = trim((string) ($grant['radius_username'] ?? ''));
    if ($username === '') return ['allowed_seconds' => 0, 'used_seconds' => 0, 'remaining_seconds' => 0, 'online' => false, 'last_accounting_at' => null];

    $st = $radius->prepare("SELECT CAST(value AS UNSIGNED) FROM radcheck WHERE username=? AND attribute='Max-All-Session' LIMIT 1");
    $st->execute([$username]);
    $allowed = max(0, (int) ($st->fetchColumn() ?: 0));

    $st = $radius->prepare('SELECT COALESCE(SUM(COALESCE(acctsessiontime,0)),0) AS used,
            SUM(CASE WHEN acctstoptime IS NULL THEN 1 ELSE 0 END) AS online,
            MAX(COALESCE(acctupdatetime,acctstoptime,acctstarttime)) AS last_accounting_at
        FROM radacct WHERE username=?');
    $st->execute([$username]);
    $accounting = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $used = max(0, (int) ($accounting['used'] ?? 0));
    $remainingCredit = max(0, $allowed - $used);
    $expiry = fs_courtesy_time($grant['expires_at'] ?? null);
    $remainingValidity = $expiry === null ? $remainingCredit : max(0, $expiry - $now);
    $remaining = min($remainingCredit, $remainingValidity);

    return [
        'allowed_seconds' => $allowed,
        'used_seconds' => $used,
        'remaining_credit_seconds' => $remainingCredit,
        'remaining_validity_seconds' => $remainingValidity,
        'remaining_seconds' => $remaining,
        'online' => (int) ($accounting['online'] ?? 0) > 0,
        'last_accounting_at' => $accounting['last_accounting_at'] ?? null,
    ];
}

function fs_courtesy_radius_password(PDO $radius, string $username): ?string
{
    $st = $radius->prepare("SELECT value FROM radcheck WHERE username=? AND attribute='Cleartext-Password' LIMIT 1");
    $st->execute([$username]);
    $password = $st->fetchColumn();
    return $password === false ? null : (string) $password;
}

function fs_courtesy_radius_prepare_reconnect(PDO $app, string $publicId, ?PDO $radius = null, ?int $now = null): array
{
    $radius = $radius ?? fs_radius_db();
    $now = $now ?? time();
    $grant = fs_courtesy_radius_grant($app, $publicId);
    if (!$grant || (string) $grant['status'] !== 'active') return fs_courtesy_result(false, 'GRANT_NOT_ACTIVE', 'Esta cortesia não está ativa.');

    $balance = fs_courtesy_radius_balance($radius, $grant, $now);
    if ((int) $balance['remaining_seconds'] <= 0) {
        $expiredByTime = (fs_courtesy_time($grant['expires_at']) ?? PHP_INT_MAX) <= $now;
        $status = $expiredByTime ? 'expired' : 'exhausted';
        $nowSql = gmdate('Y-m-d H:i:s', $now);
        $app->prepare("UPDATE courtesy_grants SET status=?,consumed_seconds=?,last_accounting_at=?,ended_at=?,updated_at=? WHERE id=? AND status='active'")
            ->execute([$status, (int) $balance['used_seconds'], $balance['last_accounting_at'], $nowSql, $nowSql, (int) $grant['id']]);
        if (!$balance['online']) {
            fs_courtesy_radius_delete_credentials($radius, (string) $grant['radius_username']);
            $app->prepare('UPDATE courtesy_grants SET radius_cleaned_at=?,updated_at=? WHERE id=?')
                ->execute([$nowSql, $nowSql, (int) $grant['id']]);
        }
        return fs_courtesy_result(false, strtoupper($status), 'O saldo desta cortesia terminou.');
    }

    $username = (string) $grant['radius_username'];
    $timeout = max(60, (int) $balance['remaining_seconds']);
    $radius->beginTransaction();
    try {
        $radius->prepare("DELETE FROM radreply WHERE username=? AND attribute='Session-Timeout'")->execute([$username]);
        $radius->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,'Session-Timeout',':=',?)")
            ->execute([$username, (string) $timeout]);
        $radius->commit();
    } catch (Throwable $e) {
        if ($radius->inTransaction()) $radius->rollBack();
        throw $e;
    }
    $password = fs_courtesy_radius_password($radius, $username);
    if ($password === null || $password === '') throw new RuntimeException('Senha da cortesia não encontrada no RADIUS.');

    $nowSql = gmdate('Y-m-d H:i:s', $now);
    $app->prepare('UPDATE courtesy_grants SET consumed_seconds=?,last_accounting_at=?,updated_at=? WHERE id=?')
        ->execute([(int) $balance['used_seconds'], $balance['last_accounting_at'], $nowSql, (int) $grant['id']]);
    return fs_courtesy_result(true, 'ACTIVE', 'Cortesia pronta para reconexão.', [
        'grant' => [
            'public_id' => $publicId,
            'status' => 'active',
            'username' => $username,
            'password' => $password,
            'remaining_seconds' => (int) $balance['remaining_seconds'],
            'expires_at' => $grant['expires_at'],
        ],
    ]);
}

function fs_courtesy_radius_reconcile(PDO $app, string $publicId, ?PDO $radius = null, ?int $now = null): array
{
    $radius = $radius ?? fs_radius_db();
    $now = $now ?? time();
    $grant = fs_courtesy_radius_grant($app, $publicId);
    if (!$grant) throw new InvalidArgumentException('Concessão não encontrada.');
    if ((string) $grant['status'] !== 'active') return ['changed' => false, 'status' => $grant['status']];

    $balance = fs_courtesy_radius_balance($radius, $grant, $now);
    $status = 'active';
    if ((int) $balance['remaining_seconds'] <= 0) {
        $status = (fs_courtesy_time($grant['expires_at']) ?? PHP_INT_MAX) <= $now ? 'expired' : 'exhausted';
    }
    $nowSql = gmdate('Y-m-d H:i:s', $now);
    $endedAt = $status === 'active' ? null : $nowSql;
    $app->prepare('UPDATE courtesy_grants
        SET status=?,consumed_seconds=?,last_accounting_at=?,ended_at=COALESCE(ended_at,?),updated_at=? WHERE id=?')
        ->execute([$status, (int) $balance['used_seconds'], $balance['last_accounting_at'], $endedAt, $nowSql, (int) $grant['id']]);

    $cleaned = false;
    if ($status !== 'active' && !$balance['online'] && empty($grant['radius_cleaned_at'])) {
        fs_courtesy_radius_delete_credentials($radius, (string) $grant['radius_username']);
        $app->prepare('UPDATE courtesy_grants SET radius_cleaned_at=?,updated_at=? WHERE id=?')
            ->execute([$nowSql, $nowSql, (int) $grant['id']]);
        $cleaned = true;
    }
    return ['changed' => $status !== 'active' || $cleaned, 'status' => $status, 'cleaned' => $cleaned, 'balance' => $balance];
}
