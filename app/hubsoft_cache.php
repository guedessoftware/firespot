<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

$hubsoftApiPath = __DIR__ . '/hubsoft_api.php';
if (file_exists($hubsoftApiPath)) {
    require_once $hubsoftApiPath;
}

function fs_hubsoft_cache_mask_document(string $document): string
{
    $digits = preg_replace('/\D+/', '', $document) ?? '';
    return $digits === '' ? 'Documento não identificado' : 'Documento ••••' . substr($digits, -4);
}

function fs_hubsoft_cache_sanitize_error(string $message): string
{
    return (string) preg_replace_callback(
        '/(?<!\d)(\d{11}|\d{14})(?!\d)/',
        static fn(array $match): string => 'Documento ••••' . substr($match[1], -4),
        $message
    );
}

function fs_hubsoft_cache_exception_code(Throwable $error): string
{
    $httpStatus = (int)$error->getCode();
    if ($httpStatus >= 400 && $httpStatus <= 599) return 'HUBSOFT_FETCH_HTTP_' . $httpStatus;
    $message = $error->getMessage();
    if (str_contains($message,'não respondeu')) return 'HUBSOFT_FETCH_TRANSPORT';
    if (str_contains($message,'fora do formato esperado')) return 'HUBSOFT_FETCH_CONTRACT';
    if (str_contains($message,'autenticação') || str_contains($message,'sessão')) return 'HUBSOFT_FETCH_AUTH';
    return 'HUBSOFT_FETCH_FAILED';
}

function fs_hubsoft_capability_record(PDO $pdo, bool $ok, string $code): void
{
    $code = substr(preg_replace('/[^A-Z0-9_:-]+/i', '_', strtoupper(trim($code))) ?: 'UNKNOWN', 0, 80);
    $upsert = $pdo->prepare("INSERT INTO app_settings (skey,svalue) VALUES (?,?)
        ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)");
    $upsert->execute(['hubsoft_capability_last_at', date('Y-m-d H:i:s')]);
    $upsert->execute(['hubsoft_capability_last_ok', $ok ? '1' : '0']);
    $upsert->execute(['hubsoft_capability_last_code', $code]);
}

/** @return array{checked:bool,ok:bool,code:string,last_at:?string,age_seconds:?int} */
function fs_hubsoft_capability_status(PDO $pdo): array
{
    $values = [];
    $statement = $pdo->query("SELECT skey,svalue FROM app_settings WHERE skey IN
        ('hubsoft_capability_last_at','hubsoft_capability_last_ok','hubsoft_capability_last_code')");
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $values[(string)$row['skey']] = (string)$row['svalue'];
    }
    $lastAt = trim((string)($values['hubsoft_capability_last_at'] ?? ''));
    $timestamp = $lastAt !== '' ? strtotime($lastAt) : false;
    return [
        'checked'=>$timestamp !== false,
        'ok'=>($values['hubsoft_capability_last_ok'] ?? '') === '1',
        'code'=>(string)($values['hubsoft_capability_last_code'] ?? 'NOT_CHECKED'),
        'last_at'=>$lastAt !== '' ? $lastAt : null,
        'age_seconds'=>$timestamp === false ? null : max(0, time()-$timestamp),
    ];
}

/**
 * Prova OAuth e a permissão do endpoint efetivamente usado pela aplicação.
 * Nenhum documento, token ou corpo remoto é retornado ou persistido.
 *
 * @return array{ok:bool,oauth_ok:bool,oauth_code:string,code:string,result:?string}
 */
function fs_hubsoft_capability_probe(PDO $pdo): array
{
    try {
        $token = getAccessToken(true);
        if (trim($token) === '') throw new RuntimeException('OAUTH_INVALID');
    } catch (Throwable $error) {
        $http = (int)$error->getCode();
        $code = $http >= 400 && $http <= 599 ? 'OAUTH_HTTP_' . $http : 'OAUTH_FAILED';
        fs_hubsoft_capability_record($pdo, false, $code);
        return ['ok'=>false,'oauth_ok'=>false,'oauth_code'=>$code,'code'=>$code,'result'=>null];
    }

    $document = '';
    try {
        $document = (string)$pdo->query("SELECT ci.cpf
            FROM clientes_info ci
            INNER JOIN radusergroup rug ON rug.username=ci.cpf AND rug.groupname='ISP_UNL'
            WHERE (ci.nome IS NULL OR ci.nome='')
               OR (ci.telefone IS NULL OR ci.telefone='')
               OR (ci.email IS NULL OR ci.email='')
               OR (ci.data_nascimento IS NULL OR ci.data_nascimento='0000-00-00')
            GROUP BY ci.cpf ORDER BY ci.cpf LIMIT 1")->fetchColumn();
    } catch (Throwable $ignored) {
    }
    if (preg_replace('/\D+/', '', $document) === '') $document = '00000000000';

    try {
        $rows = hubsoftRequest('/api/v1/integracao/cliente?busca=cpf_cnpj&termo_busca=' . rawurlencode($document), 'GET', null);
        fs_hubsoft_capability_record($pdo, true, 'CLIENT_QUERY_OK');
        return ['ok'=>true,'oauth_ok'=>true,'oauth_code'=>'OAUTH_ACCEPTED','code'=>'CLIENT_QUERY_OK','result'=>$rows?'found':'not_found'];
    } catch (Throwable $error) {
        $code = str_replace('HUBSOFT_FETCH_', 'CLIENT_QUERY_', fs_hubsoft_cache_exception_code($error));
        fs_hubsoft_capability_record($pdo, false, $code);
        return ['ok'=>false,'oauth_ok'=>true,'oauth_code'=>'OAUTH_ACCEPTED','code'=>$code,'result'=>null];
    }
}

/** @return array{date:string,count:int,automatic_count:int,manual_count:int,last_run:?string,last_automatic_run:?string} */
function fs_hubsoft_cache_normalize_state(array $state, string $today, int $limitPerDay): array
{
    if (($state['date'] ?? '') !== $today) {
        return ['date' => $today, 'count' => 0, 'automatic_count' => 0, 'manual_count' => 0, 'last_run' => null, 'last_automatic_run' => null];
    }

    if (!array_key_exists('automatic_count', $state)) {
        $legacyCount = max(0, (int) ($state['count'] ?? 0));
        $automaticCount = $limitPerDay > 0 ? min($legacyCount, $limitPerDay) : 0;
        $manualCount = max(0, $legacyCount - $automaticCount);
    } else {
        $automaticCount = max(0, (int) $state['automatic_count']);
        $manualCount = max(0, (int) ($state['manual_count'] ?? 0));
    }

    $lastRun = !empty($state['last_run']) ? (string) $state['last_run'] : null;
    $lastAutomaticRun = !empty($state['last_automatic_run'])
        ? (string) $state['last_automatic_run']
        : ($automaticCount > 0 ? $lastRun : null);

    return [
        'date' => $today,
        'count' => $automaticCount,
        'automatic_count' => $automaticCount,
        'manual_count' => $manualCount,
        'last_run' => $lastRun,
        'last_automatic_run' => $lastAutomaticRun,
    ];
}

/** @return array{runs_per_day:int,runs_today:int,last_run_label:string,summary:array<string,mixed>} */
function hubsoft_cache_status(): array
{
    $tzName = getenv('APP_TZ') ?: 'America/Manaus';
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('America/Manaus');
    }

    $runsPerDay = max(0, min(24, (int) settings_get('hubsoft_cache_runs_per_day', 4)));
    $state = json_decode((string) settings_get('hubsoft_cache_run_state', ''), true);
    if (!is_array($state)) $state = [];
    $stats = json_decode((string) settings_get('hubsoft_cache_last_stats', ''), true);
    if (!is_array($stats)) $stats = [];

    $now = new DateTimeImmutable('now', $tz);
    $state = fs_hubsoft_cache_normalize_state($state, $now->format('Y-m-d'), $runsPerDay);
    $lastRunLabel = 'Nunca executado';
    if (!empty($state['last_run'])) {
        try {
            $lastRunLabel = (new DateTimeImmutable((string) $state['last_run']))->setTimezone($tz)->format('d/m/Y H:i');
        } catch (Throwable $e) {
            $lastRunLabel = 'Indisponível';
        }
    }

    $statsTime = null;
    if (!empty($stats['timestamp'])) {
        try {
            $statsTime = (new DateTimeImmutable((string) $stats['timestamp']))->setTimezone($tz)->format('d/m/Y H:i');
        } catch (Throwable $e) {
            $statsTime = null;
        }
    }

    $notFound = (int) ($stats['not_found'] ?? 0);
    $hasExplicitNotFound = array_key_exists('not_found', $stats);
    $displayErrors = [];
    foreach (array_slice((array) ($stats['errors'] ?? []), 0, 20) as $error) {
        $error = fs_hubsoft_cache_sanitize_error((string) $error);
        if (stripos($error, 'cliente não encontrado') !== false) {
            if (!$hasExplicitNotFound) $notFound++;
            continue;
        }
        $displayErrors[] = $error;
    }

    return [
        'runs_per_day' => $runsPerDay,
        'runs_today' => (int) $state['automatic_count'] + (int) $state['manual_count'],
        'automatic_runs_today' => (int) $state['automatic_count'],
        'manual_runs_today' => (int) $state['manual_count'],
        'last_run_label' => $lastRunLabel,
        'summary' => [
            'timestamp_label' => $statsTime,
            'processed' => (int) ($stats['processed'] ?? 0),
            'updated' => (int) ($stats['updated'] ?? 0),
            'skipped' => (int) ($stats['skipped'] ?? 0),
            'not_found' => $notFound,
            'errors' => $displayErrors,
        ],
    ];
}

function hubsoft_cache_refresh(array $options = []): array
{
    $ignoreLimit = (bool)($options['ignore_limit'] ?? false);
    $source = (string)($options['source'] ?? 'system');

    if (!function_exists('hubsoftRequest')) {
        return [
            'ok' => false,
            'error' => 'HubSoft API não configurada.',
            'code' => 'missing_api'
        ];
    }

    $appTz = getenv('APP_TZ') ?: 'America/Manaus';
    try {
        $tz = new DateTimeZone($appTz);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('America/Manaus');
    }
    date_default_timezone_set($tz->getName());

    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    try {
        $pdo->exec("SET time_zone = '-04:00'");
    } catch (Throwable $e) {
        // ignore
    }

    $limitPerDay = (int)settings_get('hubsoft_cache_runs_per_day', 4);
    if ($limitPerDay < 0) {
        $limitPerDay = 0;
    }

    if ($limitPerDay === 0 && !$ignoreLimit) {
        return [
            'ok' => false,
            'error' => 'Sincronização automática desativada.',
            'code' => 'disabled'
        ];
    }

    $stateRaw = (string)settings_get('hubsoft_cache_run_state', '');
    $state = json_decode($stateRaw, true);
    if (!is_array($state)) {
        $state = [];
    }

    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    $state = fs_hubsoft_cache_normalize_state($state, $today, $limitPerDay);

    $runsToday = (int)$state['automatic_count'];
    if (!$ignoreLimit && $limitPerDay > 0 && $runsToday >= $limitPerDay) {
        return [
            'ok' => false,
            'error' => 'Limite diário atingido.',
            'code' => 'limit_reached'
        ];
    }

    if (!$ignoreLimit && !empty($state['last_automatic_run'])) {
        $lastRunRaw = (string)$state['last_automatic_run'];
        if ($lastRunRaw !== '') {
            try {
                $lastRun = new DateTimeImmutable($lastRunRaw);
                $spacing = (int)floor(1440 / max(1, $limitPerDay));
                if ($spacing > 0) {
                    $nextAllowed = $lastRun->setTimezone($tz)->modify('+' . $spacing . ' minutes');
                    if ($nextAllowed > $now) {
                        return [
                            'ok' => false,
                            'error' => 'Executado recentemente. Aguarde a próxima janela.',
                            'code' => 'spacing',
                            'next_allowed' => $nextAllowed->format(DateTimeInterface::ATOM)
                        ];
                    }
                }
            } catch (Throwable $e) {
                // ignore invalid state
            }
        }
    }

    $lockStmt = $pdo->query("SELECT GET_LOCK('hubsoft_cache_refresh', 0)");
    $gotLock = $lockStmt && (int)$lockStmt->fetchColumn() === 1;
    if (!$gotLock) {
        return [
            'ok' => false,
            'error' => 'Outra execução em andamento.',
            'code' => 'locked'
        ];
    }

    $processed = 0;
    $updated = 0;
    $skipped = 0;
    $notFound = 0;
    $errors = [];
    $statusMessage = '';

    $batchSize = (int)settings_get('hubsoft_cache_batch_size', 40);
    if ($batchSize <= 0) $batchSize = 40;
    if ($batchSize > 200) $batchSize = 200;

    try {
        $stmt = $pdo->prepare(
            "SELECT ci.cpf, ci.nome, ci.telefone, ci.email, ci.data_nascimento AS nascimento, ci.sexo
             FROM clientes_info ci
             INNER JOIN radusergroup rug ON rug.username = ci.cpf AND rug.groupname = 'ISP_UNL'
             WHERE (ci.nome IS NULL OR ci.nome = '')
                OR (ci.telefone IS NULL OR ci.telefone = '')
                OR (ci.email IS NULL OR ci.email = '')
                OR (ci.data_nascimento IS NULL OR ci.data_nascimento = '0000-00-00')
             GROUP BY ci.cpf
             ORDER BY ci.cpf
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$rows) {
            $statusMessage = 'Nenhum usuário pendente.';
        }

        foreach ($rows as $row) {
            $processed++;
            $cpf = only_digits((string)$row['cpf']);
            if ($cpf === '') {
                $skipped++;
                $errors[] = 'CPF vazio em clientes_info.';
                continue;
            }

            try {
                $hub = hubsoft_fetch_cliente_por_cpf_cache($cpf);
            } catch (Throwable $e) {
                $errors[] = fs_hubsoft_cache_mask_document($cpf) . ': ' . fs_hubsoft_cache_exception_code($e);
                $skipped++;
                continue;
            }

            if (empty($hub['ok'])) {
                $notFound++;
                $skipped++;
                continue;
            }

            if (empty($hub['ativo']) || empty($hub['servico_habilitado'])) {
                $skipped++;
                continue;
            }

            $cliente = $hub['cliente'];
            if (!is_array($cliente)) {
                $skipped++;
                continue;
            }

            $updates = [];
            if (field_empty($row['nome'] ?? null)) {
                $nome = extract_nome($cliente);
                if ($nome !== '') {
                    $updates['nome'] = $nome;
                }
            }
            if (field_empty($row['telefone'] ?? null)) {
                $telefone = extract_telefone($cliente);
                if ($telefone !== '') {
                    $updates['telefone'] = $telefone;
                }
            }
            if (field_empty($row['email'] ?? null)) {
                $email = extract_email($cliente);
                if ($email !== '') {
                    $updates['email'] = $email;
                }
            }
            $nascimentoAtual = $row['nascimento'] ?? null;
            if ($nascimentoAtual === null || $nascimentoAtual === '' || $nascimentoAtual === '0000-00-00') {
                $nascimento = extract_nascimento($cliente);
                if ($nascimento !== null) {
                    $updates['data_nascimento'] = $nascimento;
                }
            }
            if (field_empty($row['sexo'] ?? null)) {
                $sexo = extract_sexo($cliente);
                if ($sexo !== null) {
                    $updates['sexo'] = $sexo;
                }
            }

            if (!$updates) {
                $skipped++;
                continue;
            }

            $placeholders = [];
            $params = [];
            foreach ($updates as $col => $val) {
                $placeholders[] = "`{$col}` = ?";
                $params[] = $val;
            }
            $params[] = $cpf;

            try {
                $upd = $pdo->prepare('UPDATE clientes_info SET ' . implode(', ', $placeholders) . ' WHERE cpf = ? LIMIT 1');
                $upd->execute($params);
                if ($upd->rowCount() > 0) {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $errors[] = fs_hubsoft_cache_mask_document($cpf) . ': DATABASE_UPDATE_FAILED';
                $skipped++;
            }
        }
    } catch (Throwable $fatal) {
        $errors[] = 'CACHE_QUERY_FAILED';
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('hubsoft_cache_refresh')");
    }

    $isManual = $source === 'manual' || $ignoreLimit;
    if ($isManual) {
        $state['manual_count'] = (int) $state['manual_count'] + 1;
    } else {
        $state['automatic_count'] = (int) $state['automatic_count'] + 1;
        $state['count'] = (int) $state['automatic_count'];
        $state['last_automatic_run'] = $now->format(DateTimeInterface::ATOM);
    }
    $state['last_run'] = $now->format(DateTimeInterface::ATOM);
    settings_set('hubsoft_cache_run_state', json_encode($state));

    $stats = [
        'timestamp' => $now->format(DateTimeInterface::ATOM),
        'processed' => $processed,
        'updated' => $updated,
        'skipped' => $skipped,
        'not_found' => $notFound,
        'errors' => array_slice($errors, 0, 20),
        'source' => $source,
        'message' => $statusMessage,
    ];
    settings_set('hubsoft_cache_last_stats', json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return [
        'ok' => true,
        'stats' => $stats,
        'message' => $statusMessage,
        'processed' => $processed,
        'updated' => $updated,
        'skipped' => $skipped,
        'not_found' => $notFound,
        'errors' => $errors,
    ];
}

function only_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value);
}

function field_empty($value): bool
{
    if ($value === null) return true;
    $trim = trim((string)$value);
    return $trim === '';
}

function hubsoft_fetch_cliente_por_cpf_cache(string $cpf): array
{
    $cpfDigits = only_digits($cpf);
    $endpoint = "/api/v1/integracao/cliente?busca=cpf_cnpj&termo_busca={$cpfDigits}";
    $clientes = hubsoftRequest($endpoint, 'GET', null);
    if (empty($clientes) || !is_array($clientes)) {
        return ['ok' => false];
    }
    $cliente = $clientes[0] ?? null;
    if (!$cliente || !is_array($cliente)) {
        return ['ok' => false];
    }

    $ativo = !empty($cliente['ativo']);
    $servicoHabilitado = false;
    if (!empty($cliente['servicos']) && is_array($cliente['servicos'])) {
        foreach ($cliente['servicos'] as $srv) {
            if (isset($srv['status']) && (string)$srv['status'] === 'Serviço Habilitado') {
                $servicoHabilitado = true;
                break;
            }
        }
    }

    return [
        'ok' => true,
        'cliente' => $cliente,
        'ativo' => $ativo,
        'servico_habilitado' => $servicoHabilitado,
    ];
}

function extract_nome(array $cliente): string
{
    return first_non_empty($cliente, [
        'nome',
        'nome_razaosocial',
        'nome_razao_social',
        'nome_cliente',
        'nome_fantasia',
        'razao_social',
        'contato',
        'contato_nome',
    ]);
}

function extract_telefone(array $cliente): string
{
    $telefone = first_non_empty($cliente, [
        'telefone_primario',
        'telefone',
        'celular',
        'telefone_secundario',
    ]);
    $digits = normalize_phone_br($telefone);
    if ($digits === '' || strlen($digits) < 10) {
        return '';
    }
    return $digits;
}

function extract_email(array $cliente): string
{
    $email = first_non_empty($cliente, [
        'email',
        'email_principal',
        'email_financeiro',
        'email_boleto',
        'email_cadastro',
    ]);
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return strtolower($email);
    }
    return '';
}

function extract_nascimento(array $cliente): ?string
{
    $valor = first_non_empty($cliente, [
        'data_nascimento',
        'nascimento',
        'data_nasc',
    ]);
    if ($valor === '') {
        return null;
    }
    $normalizado = normalize_date($valor);
    return $normalizado;
}

function extract_sexo(array $cliente): ?string
{
    $valor = first_non_empty($cliente, ['sexo', 'genero']);
    if ($valor === '') {
        return null;
    }
    $valor = strtoupper((string)$valor);
    if (in_array($valor, ['M', 'F', 'O'], true)) {
        return $valor;
    }
    $map = [
        'MASCULINO' => 'M',
        'FEMININO' => 'F',
        'OUTRO' => 'O',
        'NAO INFORMADO' => null,
        'NAO_INFORMADO' => null,
    ];
    return $map[$valor] ?? null;
}

function first_non_empty(array $data, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        $value = trim((string)$data[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function normalize_phone_br(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value);
    if ($digits === null) {
        return '';
    }
    if (strpos($digits, '55') === 0 && strlen($digits) > 11) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) > 11) {
        $digits = substr($digits, -11);
    }
    return $digits;
}

function normalize_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) {
        return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
    }
    try {
        $dt = new DateTimeImmutable($value);
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}
