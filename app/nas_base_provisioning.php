<?php

require_once __DIR__ . '/schema_guard.php';
require_once __DIR__ . '/lib/routeros.php';
require_once __DIR__ . '/nas_credentials.php';

// A prontidao CoA possui estado proprio. Manter a revisao 1 evita bloquear a
// reaplicacao de instalacoes antigas enquanto cada NAS e validado/repreparado.
const FS_NAS_BASE_REVISION = 1;
const FS_NAS_BASE_COA_PORT = 3799;
const FS_NAS_BASE_RADIUS_COMMENT = 'FireSpot Base';

class FsNasBaseProvisioningException extends RuntimeException
{
    private $errorCodeName;

    public function __construct(string $errorCodeName, string $message)
    {
        parent::__construct($message);
        $this->errorCodeName = $errorCodeName;
    }

    public function errorCodeName(): string
    {
        return $this->errorCodeName;
    }
}

function fs_nas_base_schema_require(PDO $pdo): void
{
    runtime_schema_require($pdo, 'nas_base_provisioning', [
        'nas_id','radius_server_id','status','config_revision','routeros_version',
        'coa_status','coa_port','coa_checked_at','coa_error_code',
        'attempt_count','last_attempt_at','provisioned_at','last_error_code','last_error_detail'
    ]);
}

function fs_nas_is_mikrotik(?string $type): bool
{
    $type = strtolower(trim((string)$type));
    return $type === 'mikrotik' || $type === 'routeros';
}

function fs_nas_base_state(PDO $pdo, int $nasId): ?array
{
    fs_nas_base_schema_require($pdo);
    $st = $pdo->prepare('SELECT b.*,r.name radius_name,r.host radius_host,r.port radius_port
        FROM nas_base_provisioning b
        LEFT JOIN radius_servers r ON r.id=b.radius_server_id
        WHERE b.nas_id=? LIMIT 1');
    $st->execute([$nasId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fs_nas_base_radius_host(PDO $pdo, int $nasId): string
{
    $state = fs_nas_base_state($pdo,$nasId);
    $host = trim((string)($state['radius_host'] ?? ''));
    if ($host === '') {
        throw new RuntimeException('O NAS selecionado ainda não possui um servidor RADIUS na base FireSpot.');
    }
    return $host;
}

function fs_nas_base_assign(PDO $pdo, int $nasId, int $radiusServerId, bool $markPending = true): void
{
    fs_nas_base_schema_require($pdo);
    if ($nasId <= 0 || $radiusServerId <= 0) {
        throw new InvalidArgumentException('Selecione o servidor RADIUS da base FireSpot.');
    }
    $st = $pdo->prepare('SELECT host FROM radius_servers WHERE id=? LIMIT 1');
    $st->execute([$radiusServerId]);
    $radiusHost = trim((string)$st->fetchColumn());
    if ($radiusHost === '') throw new InvalidArgumentException('Servidor RADIUS não encontrado.');

    $sql = 'INSERT INTO nas_base_provisioning (nas_id,radius_server_id,status)
        VALUES (?,?,?) ON DUPLICATE KEY UPDATE radius_server_id=VALUES(radius_server_id)';
    if ($markPending) {
        $sql .= ",status='pending',last_error_code=NULL,last_error_detail=NULL";
    }
    $pdo->prepare($sql)->execute([$nasId,$radiusServerId,'pending']);

    // radius_ip permanece no legado e no histórico das instalações, mas agora
    // é uma projeção do destino canônico definido na base do NAS.
    $pdo->prepare('UPDATE partner_hotspots SET radius_ip=?,updated_at=NOW() WHERE nas_id=?')
        ->execute([$radiusHost,$nasId]);
    $pdo->prepare('UPDATE partners SET radius_ip=?,updated_at=NOW() WHERE nas_id=?')
        ->execute([$radiusHost,$nasId]);
}

function fs_routeros_quote(string $value): string
{
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
        throw new InvalidArgumentException('Valor de configuração contém caracteres de controle.');
    }
    return '"' . strtr($value, ['\\'=>'\\\\','"'=>'\\"','$'=>'\\$']) . '"';
}

function fs_nas_base_routeros_version(string $raw): array
{
    $version = trim(preg_replace('/\s+/', ' ', $raw));
    if ($version === '' || !preg_match('/^(\d+)\./', $version, $match)) {
        throw new FsNasBaseProvisioningException('ROUTEROS_VERSION_UNKNOWN', 'Não foi possível identificar a versão do RouterOS.');
    }
    $major = (int)$match[1];
    if (!in_array($major, [6,7], true)) {
        throw new FsNasBaseProvisioningException('ROUTEROS_UNSUPPORTED', 'RouterOS ' . $version . ' não é suportado pela preparação automática.');
    }
    return ['version'=>mb_substr($version,0,64),'major'=>$major];
}

function fs_nas_base_count_output($value, string $context): int
{
    $value = trim((string)$value);
    if (!preg_match('/^\d+$/', $value)) {
        throw new FsNasBaseProvisioningException('ROUTEROS_INVALID_RESPONSE', 'O RouterOS não confirmou ' . $context . '.');
    }
    return (int)$value;
}

function fs_nas_base_assert_command_result(array $result, string $context, string $operation = 'configuração-base'): array
{
    if (empty($result['ok'])) {
        throw new FsNasBaseProvisioningException('ROUTEROS_COMMAND_FAILED', 'Falha ao ' . $context . '.');
    }
    foreach (($result['out'] ?? []) as $output) {
        $output = trim((string)$output);
        if ($output !== '' && preg_match('/(?:failure:|syntax error|bad (?:parameter|command name)|expected (?:end of command|command)|invalid value|no such item|executing script failed)/i', $output)) {
            throw new FsNasBaseProvisioningException('ROUTEROS_COMMAND_FAILED', 'O RouterOS recusou a ' . $operation . ' ao ' . $context . '.');
        }
    }
    return $result['out'] ?? [];
}

function fs_nas_base_connection(array $nas, bool $allowUnpinnedHostKey = false): array
{
    $host = trim((string)($nas['nasname'] ?? ''));
    $user = trim((string)($nas['mgmt_username'] ?? ''));
    $pass = (string)($nas['mgmt_password'] ?? '');
    $port = (int)($nas['mgmt_port'] ?? 22);
    if ($port <= 0) $port = 22;
    if ($host === '' || $user === '' || $pass === '') {
        throw new FsNasBaseProvisioningException('SSH_CREDENTIALS_MISSING', 'Informe endereço, usuário e senha SSH do MikroTik.');
    }
    if ($port < 1 || $port > 65535) {
        throw new FsNasBaseProvisioningException('SSH_PORT_INVALID', 'Informe uma porta SSH válida para o MikroTik.');
    }
    $expectedHostKey=trim((string)($nas['expected_host_key_fingerprint']??''));
    if(!$allowUnpinnedHostKey&&!empty($nas['host_key_required'])&&$expectedHostKey===''){
        throw new FsNasBaseProvisioningException('SSH_HOST_KEY_NOT_VERIFIED','A chave SSH deste NAS ainda não foi verificada.');
    }
    return ['host'=>$host,'user'=>$user,'pass'=>$pass,'port'=>$port,'key'=>null,'expected_host_key_fingerprint'=>$expectedHostKey];
}

function fs_nas_base_record_failure(PDO $pdo, int $nasId, Throwable $error, ?string $routerosVersion = null): void
{
    $code = $error instanceof FsNasBaseProvisioningException ? $error->errorCodeName() : 'PROVISIONING_FAILED';
    $detail = mb_substr(trim($error->getMessage()) ?: 'Falha não identificada.', 0, 255);
    $pdo->prepare("UPDATE nas_base_provisioning SET status='error',routeros_version=COALESCE(?,routeros_version),
        coa_status='error',coa_checked_at=NOW(),coa_error_code=?,last_error_code=?,last_error_detail=? WHERE nas_id=?")
        ->execute([$routerosVersion,$code,$code,$detail,$nasId]);
}

/**
 * Aplica somente a base global do NAS. O executor opcional existe para testes
 * determinísticos e deve possuir a mesma assinatura lógica de ros_exec().
 */
function fs_nas_base_provision(PDO $pdo, int $nasId, ?callable $executor = null): array
{
    fs_nas_base_schema_require($pdo);
    $st = $pdo->prepare('SELECT n.*,b.radius_server_id,r.name radius_name,r.host radius_host,r.port radius_port
        FROM nas n
        JOIN nas_base_provisioning b ON b.nas_id=n.id
        LEFT JOIN radius_servers r ON r.id=b.radius_server_id
        WHERE n.id=? LIMIT 1');
    $st->execute([$nasId]);
    $nas = $st->fetch(PDO::FETCH_ASSOC);
    if (!$nas) throw new RuntimeException('NAS ou preparação-base não encontrado.');
    $storedRadiusSecret=(string)($nas['secret']??'');
    $nas = fs_nas_credentials_for_operation($pdo,$nas);
    if (empty($nas['radius_server_id']) || trim((string)$nas['radius_host']) === '') {
        throw new InvalidArgumentException('Selecione o servidor RADIUS da base FireSpot.');
    }
    $radiusHost = trim((string)$nas['radius_host']);
    if (!filter_var($radiusHost,FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9.-]+$/i',$radiusHost)) {
        throw new InvalidArgumentException('O host do servidor RADIUS é inválido.');
    }
    $radiusPort = (int)($nas['radius_port'] ?? 1812);
    if ($radiusPort < 1 || $radiusPort > 65534) throw new InvalidArgumentException('A porta do servidor RADIUS é inválida.');
    $radiusSecret = (string)($nas['secret'] ?? '');
    if ($radiusSecret === '') throw new InvalidArgumentException('Informe o segredo compartilhado do NAS.');

    $connection = fs_nas_base_connection($nas);
    $run = $executor ?: static function (array $commands, array $connection) {
        return ros_exec($commands,$connection);
    };
    $routerosVersion = null;
    $radiusSecretApplied = false;
    $localTransaction = false;
    $pdo->prepare("UPDATE nas_base_provisioning SET status='applying',attempt_count=attempt_count+1,last_attempt_at=NOW(),last_error_code=NULL,last_error_detail=NULL WHERE nas_id=?")
        ->execute([$nasId]);

    try {
        // `:put` devolve o valor também em sessões SSH não interativas.
        // O `get version` isolado pode finalizar sem stdout em algumas versões.
        $probe = fs_nas_base_assert_command_result($run([':put [/system resource get version]'],$connection), 'consultar a versão');
        $versionInfo = fs_nas_base_routeros_version((string)($probe[0] ?? ''));
        $routerosVersion = $versionInfo['version'];
        // Permite incorporar cadastros legados marcados como "other", mas só
        // depois que o próprio equipamento confirma ser RouterOS 6 ou 7.
        $pdo->prepare("UPDATE nas SET type='mikrotik' WHERE id=?")->execute([$nasId]);

        $comment = fs_routeros_quote(FS_NAS_BASE_RADIUS_COMMENT);
        $counts = fs_nas_base_assert_command_result($run([
            '/radius print count-only where comment=' . $comment,
            '/radius print count-only where service~"hotspot"',
        ],$connection), 'inspecionar o RADIUS existente');
        $managedCount = fs_nas_base_count_output($counts[0] ?? '', 'a entrada RADIUS gerenciada');
        $hotspotCount = fs_nas_base_count_output($counts[1] ?? '', 'as entradas RADIUS de hotspot');
        if ($managedCount > 1 || ($managedCount === 0 && $hotspotCount > 1)) {
            throw new FsNasBaseProvisioningException('RADIUS_AMBIGUOUS', 'O MikroTik possui múltiplas entradas RADIUS de hotspot. Revise-as antes de preparar a base FireSpot.');
        }

        $settings = 'service=hotspot address=' . fs_routeros_quote($radiusHost)
            . ' authentication-port=' . $radiusPort
            . ' accounting-port=' . ($radiusPort + 1)
            . ' secret=' . fs_routeros_quote($radiusSecret)
            . ' timeout=3s disabled=no comment=' . $comment;
        if ($managedCount === 1) {
            $command = '/radius set [find where comment=' . $comment . '] ' . $settings;
        } elseif ($hotspotCount === 1) {
            $command = '/radius set [find where service~"hotspot"] ' . $settings;
        } else {
            $command = '/radius add ' . $settings;
        }
        fs_nas_base_assert_command_result($run([$command],$connection), 'aplicar a entrada RADIUS');
        $radiusSecretApplied = true;
        fs_nas_base_assert_command_result($run([
            '/radius incoming set accept=yes port=' . FS_NAS_BASE_COA_PORT,
        ],$connection),'habilitar a promocao dinamica RADIUS');

        $verification = fs_nas_base_assert_command_result($run([
            '/radius print count-only where comment=' . $comment,
            '/radius print detail without-paging where comment=' . $comment,
            ':put [/radius incoming get accept]',
            ':put [/radius incoming get port]',
        ],$connection), 'validar a configuração-base');
        if (fs_nas_base_count_output($verification[0] ?? '', 'a configuração-base') !== 1) {
            throw new FsNasBaseProvisioningException('RADIUS_VERIFY_FAILED', 'A configuração RADIUS não foi confirmada no MikroTik.');
        }
        $detail = (string)($verification[1] ?? '');
        if (stripos($detail,'address=' . $radiusHost) === false || stripos($detail,'service=hotspot') === false) {
            throw new FsNasBaseProvisioningException('RADIUS_VERIFY_FAILED', 'O destino RADIUS retornado pelo MikroTik diverge da base FireSpot.');
        }
        $incomingAccept = strtolower(trim((string)($verification[2] ?? '')));
        $incomingPort = trim((string)($verification[3] ?? ''));
        if (!in_array($incomingAccept,['yes','true'],true) || $incomingPort !== (string)FS_NAS_BASE_COA_PORT) {
            throw new FsNasBaseProvisioningException('COA_VERIFY_FAILED','O MikroTik nao confirmou o recebimento de CoA na porta padrao.');
        }

        $localTransaction=!$pdo->inTransaction();if($localTransaction)$pdo->beginTransaction();
        $pdo->prepare('UPDATE nas SET secret=? WHERE id=?')->execute([$radiusSecret,$nasId]);
        $pdo->prepare("UPDATE nas_base_provisioning SET status='ready',config_revision=?,routeros_version=?,
            coa_status='ready',coa_port=?,coa_checked_at=NOW(),coa_error_code=NULL,
            provisioned_at=NOW(),last_error_code=NULL,last_error_detail=NULL WHERE nas_id=?")
            ->execute([FS_NAS_BASE_REVISION,$routerosVersion,FS_NAS_BASE_COA_PORT,$nasId]);
        if($localTransaction)$pdo->commit();
        return [
            'ok'=>true,
            'nas_id'=>$nasId,
            'status'=>'ready',
            'config_revision'=>FS_NAS_BASE_REVISION,
            'routeros_version'=>$routerosVersion,
            'radius_host'=>$radiusHost,
            'coa_status'=>'ready',
            'coa_port'=>FS_NAS_BASE_COA_PORT,
        ];
    } catch (Throwable $error) {
        if($localTransaction&&$pdo->inTransaction())$pdo->rollBack();
        if($radiusSecretApplied&&$storedRadiusSecret!==''&&!hash_equals($storedRadiusSecret,$radiusSecret)){
            try{
                fs_nas_base_assert_command_result($run([
                    '/radius set [find where comment=' . fs_routeros_quote(FS_NAS_BASE_RADIUS_COMMENT) . '] secret=' . fs_routeros_quote($storedRadiusSecret),
                ],$connection),'restaurar o shared secret anterior','rollback da configuração-base');
            }catch(Throwable $rollbackError){error_log('[nas base rollback] nas_id='.$nasId.' code=RADIUS_SECRET_ROLLBACK_FAILED');}
        }
        fs_nas_base_record_failure($pdo,$nasId,$error,$routerosVersion);
        throw $error;
    }
}

function fs_nas_base_assert_ready(PDO $pdo, int $nasId, ?string $expectedRadiusHost = null): array
{
    $state = fs_nas_base_state($pdo,$nasId);
    if (!$state || $state['status'] !== 'ready' || (int)$state['config_revision'] < FS_NAS_BASE_REVISION) {
        throw new RuntimeException('A base FireSpot deste NAS ainda não está pronta. Prepare o NAS em Infraestrutura > NAS antes de aplicar a instalação.');
    }
    if (trim((string)($state['radius_host'] ?? '')) === '') {
        throw new RuntimeException('O NAS preparado não possui um servidor RADIUS válido.');
    }
    if ($expectedRadiusHost !== null && trim($expectedRadiusHost) !== '' && strcasecmp(trim($expectedRadiusHost),(string)$state['radius_host']) !== 0) {
        throw new RuntimeException('O RADIUS registrado na instalação diverge da base do NAS. Salve a instalação para herdar o destino correto.');
    }
    return $state;
}
