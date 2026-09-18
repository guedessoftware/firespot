<?php
// app/account_deletion.php — helpers para exclusão e auditoria de contas

declare(strict_types=1);

require_once __DIR__ . '/schema_guard.php';
require_once __DIR__ . '/env.php';

/** Garante que a tabela de auditoria existe */
function ensure_deleted_accounts_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    runtime_schema_require($pdo, 'deleted_accounts_log', ['id','username','reason','payload']);
    $ensured = true;
}

function account_deletion_audit_key(): string
{
    foreach (['ACCOUNT_DELETION_AUDIT_KEY','APP_KEY','PERSONAL_DATA_KEY','PAYMENT_CREDENTIAL_KEY'] as $name) {
        $value = trim((string) env($name,''));
        if (strlen($value) >= 32) return hash('sha256',$value,true);
    }
    throw new RuntimeException('Chave de auditoria de exclusão não configurada.');
}

function account_deletion_reference(string $value,string $context='subject'): string
{
    $value = trim($value);
    if ($value === '') throw new InvalidArgumentException('Identificador de exclusão vazio.');
    return 'anon:' . substr(hash_hmac('sha256',$context . "\0" . $value,account_deletion_audit_key()),0,48);
}

/** @return array<string,int> */
function account_deletion_safe_counts(array $raw): array
{
    $counts = [];
    foreach ($raw as $name => $value) {
        $name = strtolower(trim((string)$name));
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/',$name) || !is_numeric($value)) continue;
        $counts[$name] = max(0,(int)$value);
    }
    ksort($counts);
    return $counts;
}

/** Converte payloads novos ou legados em um recibo que não contém PII. */
function account_deletion_public_receipt(array $payload,?string $deletedAt=null): array
{
    if ((int)($payload['version'] ?? 0) === 2) {
        $counts = account_deletion_safe_counts((array)($payload['counts'] ?? []));
        return [
            'version' => 2,
            'completed_at' => (string)($payload['completed_at'] ?? $deletedAt ?? ''),
            'outcome' => 'deleted',
            'counts' => $counts,
            'total' => array_sum($counts),
        ];
    }

    $counts = [];
    foreach ($payload as $name => $value) {
        $safeName = strtolower(trim((string)$name));
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/',$safeName)) continue;
        if (is_array($value)) {
            if ($safeName === 'radacct_summary') {
                $counts['radacct'] = max(0,(int)($value['total'] ?? 0));
            } else {
                $counts[$safeName] = array_is_list($value) ? count($value) : ($value ? 1 : 0);
            }
        } elseif (is_numeric($value) && str_ends_with($safeName,'_revoked')) {
            $counts[$safeName] = max(0,(int)$value);
        }
    }
    $counts = account_deletion_safe_counts($counts);
    return [
        'version' => 2,
        'completed_at' => $deletedAt ?? '',
        'outcome' => 'deleted',
        'counts' => $counts,
        'total' => array_sum($counts),
        'legacy_sanitized' => true,
    ];
}

/** Registra somente recibo mínimo, nunca snapshot ou credencial. */
function log_account_deletion(PDO $pdo, string $username, array $payload, string $reason = 'self-service', ?string $initiator = null): void
{
    ensure_deleted_accounts_table($pdo);
    $reason = strtolower(trim($reason));
    if (!preg_match('/^[a-z0-9_-]{1,60}$/',$reason)) $reason = 'unspecified';
    $subjectReference = account_deletion_reference($username,'subject');
    $actorReference = $initiator === null || trim($initiator) === ''
        ? 'system'
        : account_deletion_reference($initiator,'actor');
    $receipt = [
        'version' => 2,
        'completed_at' => (string)($payload['completed_at'] ?? gmdate(DATE_ATOM)),
        'outcome' => 'deleted',
        'counts' => account_deletion_safe_counts((array)($payload['counts'] ?? [])),
    ];
    $receipt['total'] = array_sum($receipt['counts']);
    $json = json_encode($receipt,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stmt = $pdo->prepare('INSERT INTO deleted_accounts_log (username, reason, initiator, payload) VALUES (?,?,?,?)');
    $stmt->execute([$subjectReference,$reason,$actorReference,$json]);
}

/**
 * Exclui uma identidade do portal clássico e do RADIUS e grava recibo mínimo.
 * O callback opcional recebe o telefone normalizado e deve devolver a
 * quantidade de leads revogados.
 *
 * @return array<string,int>
 */
function account_deletion_execute(
    PDO $app,
    PDO $radius,
    string $username,
    string $reason,
    string $initiator,
    ?callable $revokeLeads = null
): array {
    $username = trim($username);
    if ($username === '' || strlen($username) > 64) throw new InvalidArgumentException('Identidade inválida.');

    // Valida a chave antes de remover qualquer dado.
    account_deletion_reference($username,'subject');
    ensure_deleted_accounts_table($app);

    $tableHas = static function (string $table,?string $column=null) use ($app): bool {
        $sql = 'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?';
        $params = [$table];
        if ($column !== null) {
            $sql .= ' AND COLUMN_NAME=?';
            $params[] = $column;
        }
        $statement = $app->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        return (bool)$statement->fetchColumn();
    };

    $cpf = preg_replace('/\D+/','',$username);
    $phone = '';
    if ($tableHas('clientes_info')) {
        $statement = $app->prepare('SELECT cpf,telefone FROM clientes_info WHERE cpf=? LIMIT 1');
        $statement->execute([$cpf !== '' ? $cpf : $username]);
        $customer = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($customer['cpf'])) $cpf = preg_replace('/\D+/','',(string)$customer['cpf']);
        $phone = preg_replace('/\D+/','',(string)($customer['telefone'] ?? ''));
    }

    $sameConnection = $app === $radius;
    $app->beginTransaction();
    if (!$sameConnection) $radius->beginTransaction();
    try {
        $counts = [];
        $counts['ad_leads_revoked'] = $phone !== '' && $revokeLeads !== null
            ? max(0,(int)$revokeLeads($phone))
            : 0;

        foreach ([
            'radacct' => 'DELETE FROM radacct WHERE username=?',
            'radpostauth' => 'DELETE FROM radpostauth WHERE username=?',
            'radreply' => 'DELETE FROM radreply WHERE username=?',
            'radcheck' => 'DELETE FROM radcheck WHERE username=?',
            'radusergroup' => 'DELETE FROM radusergroup WHERE username=?',
        ] as $table => $sql) {
            $statement = $radius->prepare($sql);
            $statement->execute([$username]);
            $counts[$table] = $statement->rowCount();
        }

        $appDeletes = [];
        if ($tableHas('clientes_info')) {
            $appDeletes['clientes_info'] = ['DELETE FROM clientes_info WHERE cpf=?',[$cpf !== '' ? $cpf : $username]];
        }
        if ($tableHas('clientes_dispositivos')) {
            $column = $tableHas('clientes_dispositivos','username') ? 'username' : 'cpf';
            $key = $column === 'cpf' && $cpf !== '' ? $cpf : $username;
            $appDeletes['clientes_dispositivos'] = ["DELETE FROM clientes_dispositivos WHERE {$column}=?",[$key]];
        }
        if ($tableHas('vip_orders')) {
            $appDeletes['vip_orders'] = ['DELETE FROM vip_orders WHERE username=? OR cpf=?',[$username,$cpf !== '' ? $cpf : $username]];
        }
        if ($tableHas('promo_queue') && $phone !== '') {
            $appDeletes['promo_queue'] = ['DELETE FROM promo_queue WHERE to_msisdn LIKE ? OR to_msisdn LIKE ?',["%{$phone}","+%{$phone}"]];
        }
        foreach ($appDeletes as $table => [$sql,$params]) {
            $statement = $app->prepare($sql);
            $statement->execute($params);
            $counts[$table] = $statement->rowCount();
        }

        log_account_deletion($app,$username,[
            'completed_at' => gmdate(DATE_ATOM),
            'counts' => $counts,
        ],$reason,$initiator);

        // Com bancos separados não há transação distribuída. Confirmar o
        // RADIUS primeiro permite repetir a anonimização local em caso raro de
        // falha no segundo commit, sem restaurar credenciais de acesso.
        if (!$sameConnection) $radius->commit();
        $app->commit();
        return account_deletion_safe_counts($counts);
    } catch (Throwable $error) {
        if (!$sameConnection && $radius->inTransaction()) $radius->rollBack();
        if ($app->inTransaction()) $app->rollBack();
        throw $error;
    }
}

/** Obtém registros de exclusões já decodificados */
function fetch_deleted_accounts(PDO $pdo, int $limit = 100, int $offset = 0): array
{
    ensure_deleted_accounts_table($pdo);
    $limit = max(1, min($limit, 500));
    $offset = max(0, $offset);

    $sql = sprintf(
        'SELECT id, username, deleted_at, reason, initiator, payload
         FROM deleted_accounts_log
         ORDER BY deleted_at DESC
         LIMIT %d OFFSET %d',
        $limit,
        $offset
    );

    $stmt = $pdo->query($sql);
    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $payload = json_decode((string)$row['payload'], true);
        $row['username'] = str_starts_with((string)$row['username'],'anon:')
            ? (string)$row['username']
            : 'legacy-redacted';
        $row['initiator'] = str_starts_with((string)($row['initiator'] ?? ''),'anon:')
            ? (string)$row['initiator']
            : ((string)($row['initiator'] ?? '') === 'system' ? 'system' : 'legacy-redacted');
        $row['payload_decoded'] = account_deletion_public_receipt(is_array($payload) ? $payload : [],(string)$row['deleted_at']);
        unset($row['payload']);
        $rows[] = $row;
    }
    return $rows;
}

/** Estatísticas simples das exclusões */
function deleted_accounts_stats(PDO $pdo): array
{
    ensure_deleted_accounts_table($pdo);
    $stats = ['total' => 0, 'last_30_days' => 0];
    $stats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM deleted_accounts_log')->fetchColumn();
    $stats['last_30_days'] = (int)$pdo->query("SELECT COUNT(*) FROM deleted_accounts_log WHERE deleted_at >= (NOW() - INTERVAL 30 DAY)")->fetchColumn();
    return $stats;
}
