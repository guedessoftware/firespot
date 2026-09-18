<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../courtesy_policy.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$apply = in_array('--apply', $argv ?? [], true);
$lookbackHours = 168;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--lookback-hours=(\d+)$/', (string) $arg, $match)) {
        $lookbackHours = max(1, min(8760, (int) $match[1]));
    }
}

if (!fs_courtesy_schema_ready($pdo)) throw new RuntimeException('A migração 017 ainda não foi aplicada.');
$legacyTimezoneName = trim((string) env('LEGACY_DB_TIMEZONE', 'America/Manaus'));
try {
    $legacyTimezone = new DateTimeZone($legacyTimezoneName);
} catch (Throwable $e) {
    throw new RuntimeException('LEGACY_DB_TIMEZONE inválida.');
}
$utc = new DateTimeZone('UTC');
$cutoffLocal = (new DateTimeImmutable('now', $legacyTimezone))->modify('-' . $lookbackHours . ' hours')->format('Y-m-d H:i:s');

$st = $pdo->prepare("SELECT COUNT(*) FROM partner_uses pu
    WHERE pu.used_at>=? AND NOT EXISTS (
      SELECT 1 FROM courtesy_grants cg WHERE cg.legacy_source='partner_uses' AND cg.legacy_id=pu.id
    )");
$st->execute([$cutoffLocal]);
$candidates = (int) $st->fetchColumn();
$st = $pdo->prepare('SELECT COUNT(*) FROM ad_grants WHERE granted_at>=?');
$st->execute([$cutoffLocal]);
$unattributedAds = (int) $st->fetchColumn();

if (!$apply) {
    echo 'Dry-run: ' . $candidates . ' partner_uses importáveis nas últimas ' . $lookbackHours . ' horas.' . PHP_EOL;
    echo 'Aviso: ' . $unattributedAds . ' ad_grants recentes não possuem estabelecimento confiável e não serão importados.' . PHP_EOL;
    echo "Execute novamente com --apply para gravar.\n";
    exit;
}

$policies = [];
$imported = 0;
while (true) {
    $st = $pdo->prepare("SELECT pu.* FROM partner_uses pu
        WHERE pu.used_at>=? AND NOT EXISTS (
          SELECT 1 FROM courtesy_grants cg WHERE cg.legacy_source='partner_uses' AND cg.legacy_id=pu.id
        )
        ORDER BY pu.id LIMIT 500");
    $st->execute([$cutoffLocal]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) break;

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("INSERT IGNORE INTO courtesy_grants
            (public_id,partner_id,partner_code,partner_name,policy_revision,policy_snapshot,
             device_key_hash,account_key_hash,device_mac,device_ip,account_username,portal,source,
             enforcement_method,consumption_mode,grant_minutes,granted_seconds,consumed_seconds,status,
             reservation_expires_at,activated_at,expires_at,ended_at,legacy_source,legacy_id,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,'expired',?,?,?,?,?,?,?,?)");
        foreach ($rows as $row) {
            $partnerId = (int) $row['partner_id'];
            if (!isset($policies[$partnerId])) $policies[$partnerId] = fs_courtesy_policy_resolve($pdo, $partnerId);
            $policy = $policies[$partnerId];
            $localCreated = new DateTimeImmutable((string) $row['used_at'], $legacyTimezone);
            $created = $localCreated->setTimezone($utc);
            $createdSql = $created->format('Y-m-d H:i:s');
            $seconds = max(60, (int) $policy['grant_minutes'] * 60);
            $endedSql = $created->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
            $deviceKey = trim((string) ($row['mac'] ?? ''));
            if ($deviceKey === '') $deviceKey = 'legacy-missing:' . (int) $row['id'];
            $account = trim((string) ($row['username'] ?? ''));
            $snapshot = json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $insert->execute([
                substr(hash('sha256', 'partner_uses:' . (int) $row['id']), 0, 32),
                $partnerId,
                (string) $policy['partner_code'],
                (string) $policy['partner_name'],
                (string) $policy['policy_revision'],
                $snapshot,
                fs_courtesy_identity_hash($deviceKey),
                $account !== '' ? fs_courtesy_identity_hash($account) : null,
                substr((string) ($row['mac'] ?? ''), 0, 64) ?: null,
                filter_var((string) ($row['ip'] ?? ''), FILTER_VALIDATE_IP) ? (string) $row['ip'] : null,
                $account !== '' ? substr($account, 0, 64) : null,
                'legacy',
                'partner_use_import',
                'radius',
                (string) $policy['consumption_mode'],
                (int) $policy['grant_minutes'],
                $seconds,
                $createdSql,
                $createdSql,
                $endedSql,
                $endedSql,
                'partner_uses',
                (int) $row['id'],
                $createdSql,
                $createdSql,
            ]);
            $imported += $insert->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

echo 'Importação concluída: ' . $imported . ' concessões legadas incluídas.' . PHP_EOL;
echo 'ad_grants não atribuídos ignorados: ' . $unattributedAds . ".\n";
