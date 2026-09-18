<?php

declare(strict_types=1);

require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/radius_db.php';
require_once __DIR__ . '/nas_base_provisioning.php';

const FS_GUEST_PAYMENT_ACCESS_RADIUS = 'radius_preauth';
const FS_GUEST_PAYMENT_ACCESS_LEGACY = 'legacy_binding';

function fs_guest_payment_radius_schema_ready(PDO $app): bool
{
    static $cache = [];
    $key = spl_object_id($app);
    if (array_key_exists($key,$cache)) return $cache[$key];
    try {
        $app->query('SELECT payment_access_mode,radius_phase,radius_provisional_started_at,radius_provisional_expires_at,
            radius_paid_baseline_seconds,radius_coa_status,radius_coa_attempts,radius_coa_last_attempt_at,
            radius_coa_applied_at,radius_coa_error_code,radius_coa_radacctid FROM guest_orders LIMIT 0');
        $cache[$key] = true;
    } catch (Throwable $error) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * Agenda tentativas de CoA sem transformar ausência de sessão em loop.
 * O crédito e a credencial RADIUS permanecem válidos quando o item segue para
 * revisão manual; somente o envio automático é interrompido.
 *
 * @return array{eligible:bool,terminal:bool,delay_seconds:int,next_attempt_at:?int,terminal_code:?string}
 */
function fs_guest_payment_radius_retry_policy(array $order, ?int $now = null): array
{
    $now = $now ?? time();
    $status = strtolower(trim((string)($order['radius_coa_status'] ?? 'pending')));
    if ($status === 'applied' || $status === 'manual_review') {
        return ['eligible'=>false,'terminal'=>$status === 'manual_review','delay_seconds'=>0,'next_attempt_at'=>null,'terminal_code'=>$status === 'manual_review'?(string)($order['radius_coa_error_code'] ?? 'MANUAL_REVIEW'):null];
    }

    $attempts = max(0,(int)($order['radius_coa_attempts'] ?? 0));
    $error = strtoupper(trim((string)($order['radius_coa_error_code'] ?? '')));
    if ($error === 'RADIUS_SESSION_NOT_FOUND' || $error === '') {
        $baseDelay = 30;$maxDelay = 3600;$maxAttempts = 20;$maxAge = 86400;$terminalCode = 'RETRY_LIMIT_SESSION_NOT_FOUND';
    } elseif ($error === 'RADIUS_SESSION_AMBIGUOUS') {
        $baseDelay = 300;$maxDelay = 3600;$maxAttempts = 3;$maxAge = 3600;$terminalCode = 'RETRY_LIMIT_SESSION_AMBIGUOUS';
    } elseif ($error === 'COA_NAK') {
        $baseDelay = 300;$maxDelay = 3600;$maxAttempts = 5;$maxAge = 21600;$terminalCode = 'RETRY_LIMIT_COA_NAK';
    } elseif (in_array($error,['COA_NO_ACK','COA_TRANSPORT_FAILED','COA_CONTEXT_FAILED','COA_CREDENTIAL_UPDATE_FAILED'],true)) {
        $baseDelay = 60;$maxDelay = 3600;$maxAttempts = 8;$maxAge = 21600;$terminalCode = 'RETRY_LIMIT_COA_TRANSPORT';
    } else {
        $baseDelay = 120;$maxDelay = 3600;$maxAttempts = 5;$maxAge = 21600;$terminalCode = 'RETRY_LIMIT_COA_OTHER';
    }

    $paidAt = strtotime((string)($order['paid_at'] ?? '')) ?: $now;
    $terminal = $attempts >= $maxAttempts || ($attempts > 0 && $now - $paidAt >= $maxAge);
    if ($terminal) {
        return ['eligible'=>false,'terminal'=>true,'delay_seconds'=>0,'next_attempt_at'=>null,'terminal_code'=>$terminalCode];
    }

    $delay = min($maxDelay,$baseDelay * (2 ** min(7,max(0,$attempts - 1))));
    $jitterSeed = (string)($order['id'] ?? $order['public_id'] ?? $order['radius_username'] ?? 'guest') . ':' . $error . ':' . $attempts;
    $jitterWindow = max(1,intdiv($delay,4));
    $delay = min($maxDelay,$delay + (int)(sprintf('%u',crc32($jitterSeed)) % ($jitterWindow + 1)));
    $lastAttempt = strtotime((string)($order['radius_coa_last_attempt_at'] ?? '')) ?: null;
    $nextAttempt = $lastAttempt === null ? $now : $lastAttempt + $delay;
    return ['eligible'=>$nextAttempt <= $now,'terminal'=>false,'delay_seconds'=>$delay,'next_attempt_at'=>$nextAttempt,'terminal_code'=>null];
}

function fs_guest_payment_radius_username(array $order): string
{
    $publicId = strtolower(trim((string)($order['public_id'] ?? '')));
    if (!preg_match('/^[a-f0-9]{32}$/',$publicId)) throw new InvalidArgumentException('Pedido sem identificador valido.');
    return 'gst_' . substr($publicId,0,20);
}

function fs_guest_payment_radius_expiration(int $timestamp): string
{
    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(fs_guest_local_timezone())
        ->format('d M Y H:i:s');
}

function fs_guest_payment_radius_install(
    PDO $radius,
    array $order,
    string $username,
    string $password,
    int $remainingSeconds
): void {
    $remainingSeconds = max(30,$remainingSeconds);
    $owns = !$radius->inTransaction();
    if ($owns) $radius->beginTransaction();
    try {
        $radius->prepare("DELETE FROM radcheck WHERE username=? AND attribute IN ('Cleartext-Password','Max-All-Session','Simultaneous-Use','Expiration','Calling-Station-Id')")
            ->execute([$username]);
        $check = $radius->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,?,?)");
        $check->execute([$username,'Cleartext-Password',':=',$password]);
        $check->execute([$username,'Max-All-Session',':=',(string)$remainingSeconds]);
        $check->execute([$username,'Simultaneous-Use',':=','1']);
        $check->execute([$username,'Expiration',':=',fs_guest_payment_radius_expiration(time()+$remainingSeconds)]);
        $mac = fs_guest_normalize_mac((string)($order['device_mac'] ?? ''));
        if ($mac !== '') $check->execute([$username,'Calling-Station-Id','==',$mac]);

        $radius->prepare("DELETE FROM radreply WHERE username=? AND attribute IN ('Mikrotik-Rate-Limit','Acct-Interim-Interval','Session-Timeout')")
            ->execute([$username]);
        $reply = $radius->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,?,':=',?)");
        $preDown = max(256,min(2048,(int)env('V3_PAYMENT_PREAUTH_DOWNLOAD_KBPS','1024')));
        $preUp = max(128,min(1024,(int)env('V3_PAYMENT_PREAUTH_UPLOAD_KBPS','512')));
        $reply->execute([$username,'Mikrotik-Rate-Limit',fs_mikrotik_rate_limit_value($preDown,$preUp)]);
        $reply->execute([$username,'Acct-Interim-Interval','15']);
        $reply->execute([$username,'Session-Timeout',(string)$remainingSeconds]);
        if ($owns) $radius->commit();
    } catch (Throwable $error) {
        if ($owns && $radius->inTransaction()) $radius->rollBack();
        throw $error;
    }
}

/** Prepara uma sessao curta autenticada, sem marcar o acesso comprado como entregue. */
function fs_guest_prepare_provisional_access(PDO $app, int $orderId, int $seconds, ?PDO $radius = null): array
{
    if (!fs_guest_payment_radius_schema_ready($app)) throw new RuntimeException('A atualizacao RADIUS/CoA ainda nao foi aplicada.');
    $radius = $radius ?? fs_radius_db();
    $seconds = max(30,min(300,$seconds));

    $ownsAppTransaction = !$app->inTransaction();
    if ($ownsAppTransaction) $app->beginTransaction();
    try {
        $st = $app->prepare('SELECT * FROM guest_orders WHERE id=? LIMIT 1 FOR UPDATE');
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new RuntimeException('Pedido nao encontrado.');
        if ((string)$order['status'] !== 'pending' || (string)$order['payment_method'] !== 'pix') {
            throw new RuntimeException('Este pedido nao permite pre-autenticacao.');
        }
        if (fs_guest_payment_is_expired($order)) throw new RuntimeException('O Pix deste pedido expirou.');
        $mac = fs_guest_normalize_mac((string)($order['device_mac'] ?? ''));
        if ($mac === '') throw new RuntimeException('O MikroTik nao informou o MAC deste aparelho.');

        $now = time();
        $expires = fs_guest_local_datetime_timestamp((string)($order['radius_provisional_expires_at'] ?? ''));
        $alreadyReady = (string)($order['radius_phase'] ?? '') === 'provisional' && $expires > $now + 5;
        $username = trim((string)($order['radius_username'] ?? '')) ?: fs_guest_payment_radius_username($order);
        if (!$alreadyReady) $expires = $now + $seconds;
        $app->prepare("UPDATE guest_orders SET payment_access_mode=?,radius_phase='provisioning',radius_username=?,
            radius_provisional_started_at=COALESCE(radius_provisional_started_at,NOW()),
            radius_provisional_expires_at=FROM_UNIXTIME(?),radius_coa_status=NULL,radius_coa_error_code=NULL,updated_at=NOW()
            WHERE id=?")
            ->execute([FS_GUEST_PAYMENT_ACCESS_RADIUS,$username,$expires,$orderId]);
        if ($ownsAppTransaction) $app->commit();
    } catch (Throwable $error) {
        if ($ownsAppTransaction && $app->inTransaction()) $app->rollBack();
        throw $error;
    }

    try {
        $password = fs_guest_radius_password($radius,$username);
        if (!$alreadyReady || $password === null || $password === '') {
            if ($password === null || $password === '') $password = bin2hex(random_bytes(12));
            fs_guest_payment_radius_install($radius,$order,$username,$password,max(30,$expires-time()));
        }
        $app->prepare("UPDATE guest_orders SET radius_phase='provisional',radius_coa_status='not_due',radius_coa_error_code=NULL,updated_at=NOW()
            WHERE id=? AND status='pending'")->execute([$orderId]);
    } catch (Throwable $error) {
        $app->prepare("UPDATE guest_orders SET radius_phase='failed',radius_coa_status='failed',radius_coa_error_code='PREAUTH_PROVISION_FAILED',updated_at=NOW() WHERE id=?")
            ->execute([$orderId]);
        throw $error;
    }

    $st = $app->prepare('SELECT * FROM guest_orders WHERE id=? LIMIT 1');
    $st->execute([$orderId]);
    $fresh = $st->fetch(PDO::FETCH_ASSOC) ?: $order;
    $fresh['_radius_password'] = $password;
    $fresh['_provisional_remaining_seconds'] = max(1,$expires-time());
    return $fresh;
}

function fs_guest_payment_radius_usage(PDO $radius, string $username): int
{
    $st = $radius->prepare("SELECT COALESCE(SUM(CASE
        WHEN acctstoptime IS NULL THEN GREATEST(COALESCE(acctsessiontime,0),GREATEST(0,TIMESTAMPDIFF(SECOND,acctstarttime,NOW())))
        ELSE COALESCE(acctsessiontime,0) END),0) FROM radacct WHERE username=?");
    $st->execute([$username]);
    return max(0,(int)($st->fetchColumn() ?: 0));
}

function fs_guest_payment_radius_apply_paid_credentials(PDO $radius, array $order, int $baseline): void
{
    $username = (string)$order['radius_username'];
    $paidSeconds = max(60,(int)$order['duration_minutes']*60);
    $allowed = $baseline + $paidSeconds;
    $password = fs_guest_radius_password($radius,$username);
    if ($password === null || $password === '') $password = bin2hex(random_bytes(12));
    $owns = !$radius->inTransaction();
    if ($owns) $radius->beginTransaction();
    try {
        $radius->prepare("DELETE FROM radcheck WHERE username=? AND attribute IN ('Cleartext-Password','Max-All-Session','Simultaneous-Use','Expiration','Calling-Station-Id')")->execute([$username]);
        $check = $radius->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,?,?)");
        $check->execute([$username,'Cleartext-Password',':=',$password]);
        $check->execute([$username,'Max-All-Session',':=',(string)$allowed]);
        $check->execute([$username,'Simultaneous-Use',':=','1']);
        $mac = fs_guest_normalize_mac((string)($order['device_mac'] ?? ''));
        if ($mac !== '') $check->execute([$username,'Calling-Station-Id','==',$mac]);
        $radius->prepare("DELETE FROM radreply WHERE username=? AND attribute IN ('Mikrotik-Rate-Limit','Acct-Interim-Interval','Session-Timeout')")
            ->execute([$username]);
        $reply = $radius->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,?,':=',?)");
        $rate = fs_mikrotik_rate_limit_value((int)$order['download_kbps'],(int)$order['upload_kbps']);
        if ($rate !== null) $reply->execute([$username,'Mikrotik-Rate-Limit',$rate]);
        $reply->execute([$username,'Acct-Interim-Interval','60']);
        $reply->execute([$username,'Session-Timeout',(string)$paidSeconds]);
        if ($owns) $radius->commit();
    } catch (Throwable $error) {
        if ($owns && $radius->inTransaction()) $radius->rollBack();
        throw $error;
    }
}

function fs_guest_payment_radius_context(PDO $app, PDO $radius, array $order): array
{
    $partnerId = (int)($order['partner_id'] ?? 0);
    $hotspotId = isset($order['hotspot_id']) ? (int)$order['hotspot_id'] : null;
    $context = fs_partner_hotspot_for_operation($app,$partnerId,$hotspotId);
    if (!$context) throw new RuntimeException('A instalacao original do pedido nao foi encontrada.');
    $nasId = (int)($context['nas_id'] ?? 0);
    if ($nasId <= 0) throw new RuntimeException('A instalacao nao possui NAS associado.');
    $st = $app->prepare('SELECT n.*,b.coa_status,b.coa_port FROM nas n LEFT JOIN nas_base_provisioning b ON b.nas_id=n.id WHERE n.id=? LIMIT 1');
    $st->execute([$nasId]);
    $nas = $st->fetch(PDO::FETCH_ASSOC);
    if (!$nas || trim((string)$nas['secret']) === '') throw new RuntimeException('NAS ou segredo RADIUS nao encontrado.');
    $nasIp = trim((string)$nas['nasname']);
    if (!filter_var($nasIp,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) throw new RuntimeException('O endereco RADIUS do NAS e invalido.');

    $username = trim((string)$order['radius_username']);
    $mac = fs_guest_normalize_mac((string)($order['device_mac'] ?? ''));
    $ip = trim((string)($order['device_ip'] ?? ''));
    $params = [$username,$nasIp];
    $where = ["username=?","nasipaddress=?","(acctstoptime IS NULL OR acctstoptime='0000-00-00 00:00:00')"];
    if ($mac !== '') {
        $where[] = "REPLACE(REPLACE(UPPER(callingstationid),'-',''),':','')=?";
        $params[] = str_replace(':','',$mac);
    }
    if (filter_var($ip,FILTER_VALIDATE_IP)) {
        $where[] = 'framedipaddress=?';
        $params[] = $ip;
    }
    $st = $radius->prepare('SELECT radacctid,acctsessionid,username,nasipaddress,callingstationid,framedipaddress,acctstarttime,acctsessiontime
        FROM radacct WHERE ' . implode(' AND ',$where) . ' ORDER BY acctstarttime DESC LIMIT 2');
    $st->execute($params);
    $sessions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$sessions) throw new RuntimeException('RADIUS_SESSION_NOT_FOUND');
    if (count($sessions) > 1) throw new RuntimeException('RADIUS_SESSION_AMBIGUOUS');
    return ['nas'=>$nas,'session'=>$sessions[0],'context'=>$context];
}

/**
 * Confirma que a credencial do pedido esta online neste aparelho e na
 * instalacao que originou a compra. Esta leitura e propositalmente mais
 * restrita que o indicador agregado de saldo: uma sessao do mesmo usuario em
 * outro NAS, MAC ou IP nao pode fechar o portal do aparelho atual.
 */
function fs_guest_radius_active_session_for_device(
    PDO $app,
    array $order,
    array $device,
    ?PDO $radius = null
): ?array {
    $username = trim((string)($order['radius_username'] ?? ''));
    $mac = fs_guest_normalize_mac((string)($device['mac'] ?? ''));
    $ip = trim((string)($device['ip'] ?? ''));
    if ($username === '' || $mac === '' || !filter_var($ip,FILTER_VALIDATE_IP)) return null;

    $partnerId = (int)($order['partner_id'] ?? 0);
    $hotspotId = isset($order['hotspot_id']) ? (int)$order['hotspot_id'] : null;
    $context = fs_partner_hotspot_for_operation($app,$partnerId,$hotspotId);
    if (!$context) return null;
    $nasId = (int)($context['nas_id'] ?? 0);
    if ($nasId <= 0) return null;

    $st = $app->prepare('SELECT nasname FROM nas WHERE id=? LIMIT 1');
    $st->execute([$nasId]);
    $nasIp = trim((string)($st->fetchColumn() ?: ''));
    if ($nasIp === '') return null;

    $radius = $radius ?? fs_radius_db();
    $st = $radius->prepare("SELECT radacctid,acctsessionid,username,nasipaddress,callingstationid,framedipaddress,acctstarttime,acctupdatetime,acctsessiontime
        FROM radacct
        WHERE username=? AND nasipaddress=?
          AND REPLACE(REPLACE(UPPER(callingstationid),'-',''),':','')=?
          AND framedipaddress=?
          AND (acctstoptime IS NULL OR acctstoptime='0000-00-00 00:00:00')
        ORDER BY acctstarttime DESC LIMIT 2");
    $st->execute([$username,$nasIp,str_replace(':','',$mac),$ip]);
    $sessions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return count($sessions) === 1 ? $sessions[0] : null;
}

function fs_guest_radius_attribute_string(string $value): string
{
    if (preg_match('/[\x00-\x1F\x7F]/',$value)) throw new InvalidArgumentException('Atributo RADIUS invalido.');
    return '"' . strtr($value,['\\'=>'\\\\','"'=>'\\"']) . '"';
}

/** @return array{ok:bool,code:string} */
function fs_guest_payment_radius_send_coa(array $nas, array $session, array $order, ?callable $sender = null): array
{
    $paidSeconds = max(60,(int)$order['duration_minutes']*60);
    $rate = fs_mikrotik_rate_limit_value((int)$order['download_kbps'],(int)$order['upload_kbps']);
    $attributes = [
        'User-Name := ' . fs_guest_radius_attribute_string((string)$session['username']),
        'Acct-Session-Id := ' . fs_guest_radius_attribute_string((string)$session['acctsessionid']),
        'NAS-IP-Address := ' . (string)$session['nasipaddress'],
        'Framed-IP-Address := ' . (string)$session['framedipaddress'],
        'Calling-Station-Id := ' . fs_guest_radius_attribute_string((string)$session['callingstationid']),
        'Session-Timeout := ' . $paidSeconds,
    ];
    if ($rate !== null) $attributes[] = 'Mikrotik-Rate-Limit := ' . fs_guest_radius_attribute_string($rate);
    if ($sender !== null) return $sender($nas,$session,$attributes,$order);

    $target = trim((string)$session['nasipaddress']);
    if (!filter_var($target,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) return ['ok'=>false,'code'=>'COA_TARGET_INVALID'];
    $port = max(1,min(65535,(int)($nas['coa_port'] ?? 3799)));
    $secret = (string)($nas['secret'] ?? '');
    if ($secret === '') return ['ok'=>false,'code'=>'COA_SECRET_MISSING'];
    if (!function_exists('proc_open')) return ['ok'=>false,'code'=>'COA_TRANSPORT_UNAVAILABLE'];

    $secretFile = tempnam(sys_get_temp_dir(),'firespot-coa-');
    if ($secretFile === false) return ['ok'=>false,'code'=>'COA_SECRET_FILE_FAILED'];
    try {
        if (file_put_contents($secretFile,$secret,LOCK_EX) === false || !chmod($secretFile,0600)) {
            return ['ok'=>false,'code'=>'COA_SECRET_FILE_FAILED'];
        }
        $pipes = [];
        $radclient = is_executable('/usr/bin/radclient') ? '/usr/bin/radclient' : 'radclient';
        $process = proc_open(
            [$radclient,'-r','1','-t','1.5','-S',$secretFile,$target . ':' . $port,'coa'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
            $pipes
        );
        if (!is_resource($process)) return ['ok'=>false,'code'=>'COA_PROCESS_FAILED'];
        fwrite($pipes[0],implode("\n",$attributes) . "\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        $response = (string)$stdout . "\n" . (string)$stderr;
        if ($exit === 0 && stripos($response,'CoA-ACK') !== false) return ['ok'=>true,'code'=>'COA_ACK'];
        if (stripos($response,'CoA-NAK') !== false) return ['ok'=>false,'code'=>'COA_NAK'];
        return ['ok'=>false,'code'=>$exit === 0 ? 'COA_NO_ACK' : 'COA_TRANSPORT_FAILED'];
    } finally {
        @unlink($secretFile);
    }
}

function fs_guest_promote_provisional_access(PDO $app, int $orderId, ?PDO $radius = null, ?callable $sender = null): array
{
    $radius = $radius ?? fs_radius_db();
    $ownsAppTransaction = !$app->inTransaction();
    if ($ownsAppTransaction) $app->beginTransaction();
    try {
        $st = $app->prepare('SELECT * FROM guest_orders WHERE id=? LIMIT 1 FOR UPDATE');
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order || (string)$order['status'] !== 'paid') throw new RuntimeException('Pagamento ainda nao confirmado.');
        if ((string)($order['payment_access_mode'] ?? '') !== FS_GUEST_PAYMENT_ACCESS_RADIUS || empty($order['radius_username'])) {
            if ($ownsAppTransaction) $app->commit();
            return fs_guest_grant_access($app,$orderId);
        }
        $baseline = $order['radius_paid_baseline_seconds'] === null
            ? fs_guest_payment_radius_usage($radius,(string)$order['radius_username'])
            : max(0,(int)$order['radius_paid_baseline_seconds']);
        $app->prepare("UPDATE guest_orders SET access_granted_at=COALESCE(access_granted_at,NOW()),radius_cleaned_at=NULL,radius_phase='paid_pending_coa',
            radius_paid_baseline_seconds=COALESCE(radius_paid_baseline_seconds,?),radius_coa_status=IF(radius_coa_status='applied','applied','pending'),updated_at=NOW()
            WHERE id=?")
            ->execute([$baseline,$orderId]);
        if ($ownsAppTransaction) $app->commit();
    } catch (Throwable $error) {
        if ($ownsAppTransaction && $app->inTransaction()) $app->rollBack();
        throw $error;
    }

    $st = $app->prepare('SELECT * FROM guest_orders WHERE id=? LIMIT 1');
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC) ?: $order;
    if ((string)($order['radius_coa_status'] ?? '') === 'applied') return $order;
    $policy = fs_guest_payment_radius_retry_policy($order);
    if ($policy['terminal']) {
        $app->prepare("UPDATE guest_orders SET radius_coa_status='manual_review',radius_coa_error_code=?,updated_at=NOW() WHERE id=? AND radius_coa_status<>'applied'")
            ->execute([substr((string)$policy['terminal_code'],0,64),$orderId]);
        $st->execute([$orderId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: $order;
    }
    if (!$policy['eligible']) return $order;

    $attempts = max(0,(int)($order['radius_coa_attempts'] ?? 0));
    $claim = $app->prepare("UPDATE guest_orders SET radius_coa_attempts=radius_coa_attempts+1,radius_coa_last_attempt_at=NOW(),radius_coa_status='sending',updated_at=NOW() WHERE id=? AND radius_coa_attempts=? AND radius_coa_status NOT IN ('applied','manual_review')");
    $claim->execute([$orderId,$attempts]);
    if ($claim->rowCount() !== 1) {
        $st->execute([$orderId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: $order;
    }
    $attemptNumber = $attempts + 1;
    try {
        $password = fs_guest_radius_password($radius,(string)$order['radius_username']);
        if ($attempts === 0 || $password === null || $password === '') {
            fs_guest_payment_radius_apply_paid_credentials($radius,$order,$baseline);
        }
        $resolved = fs_guest_payment_radius_context($app,$radius,$order);
        $result = fs_guest_payment_radius_send_coa($resolved['nas'],$resolved['session'],$order,$sender);
        if (!empty($result['ok'])) {
            $app->prepare("UPDATE guest_orders SET radius_phase='paid_active',radius_coa_status='applied',radius_coa_applied_at=NOW(),radius_coa_error_code=NULL,radius_coa_radacctid=?,updated_at=NOW() WHERE id=?")
                ->execute([(int)$resolved['session']['radacctid'],$orderId]);
        } else {
            $failureCode = substr((string)($result['code'] ?? 'COA_FAILED'),0,64);
            $postPolicy = fs_guest_payment_radius_retry_policy(array_merge($order,['radius_coa_attempts'=>$attemptNumber,'radius_coa_error_code'=>$failureCode,'radius_coa_last_attempt_at'=>date('Y-m-d H:i:s')]));
            $app->prepare("UPDATE guest_orders SET radius_coa_status=?,radius_coa_error_code=?,radius_coa_radacctid=?,updated_at=NOW() WHERE id=?")
                ->execute([$postPolicy['terminal']?'manual_review':'failed',substr((string)($postPolicy['terminal_code'] ?? $failureCode),0,64),(int)$resolved['session']['radacctid'],$orderId]);
        }
    } catch (Throwable $error) {
        $code = in_array($error->getMessage(),['RADIUS_SESSION_NOT_FOUND','RADIUS_SESSION_AMBIGUOUS'],true)
            ? $error->getMessage()
            : (str_contains($error->getMessage(),'credencial')?'COA_CREDENTIAL_UPDATE_FAILED':'COA_CONTEXT_FAILED');
        $postPolicy = fs_guest_payment_radius_retry_policy(array_merge($order,['radius_coa_attempts'=>$attemptNumber,'radius_coa_error_code'=>$code,'radius_coa_last_attempt_at'=>date('Y-m-d H:i:s')]));
        $status = $postPolicy['terminal'] ? 'manual_review' : ($code === 'RADIUS_SESSION_NOT_FOUND' ? 'waiting_session' : 'failed');
        $app->prepare('UPDATE guest_orders SET radius_coa_status=?,radius_coa_error_code=?,updated_at=NOW() WHERE id=?')
            ->execute([$status,substr((string)($postPolicy['terminal_code'] ?? $code),0,64),$orderId]);
    }
    $st->execute([$orderId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: $order;
}

/** Escolhe promocao da sessao ativa ou concessao tradicional, de forma idempotente. */
function fs_guest_finalize_paid_access(PDO $app, int $orderId, ?PDO $radius = null, ?callable $sender = null): array
{
    $st = $app->prepare('SELECT * FROM guest_orders WHERE id=? LIMIT 1');
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new RuntimeException('Pedido nao encontrado.');
    if ((string)$order['status'] !== 'paid') throw new RuntimeException('Pagamento ainda nao confirmado.');
    if (fs_guest_payment_radius_schema_ready($app)
        && (string)($order['payment_access_mode'] ?? '') === FS_GUEST_PAYMENT_ACCESS_RADIUS) {
        return fs_guest_promote_provisional_access($app,$orderId,$radius,$sender);
    }
    return fs_guest_grant_access($app,$orderId);
}

function fs_guest_expire_provisional_access(PDO $app, array $order, ?PDO $radius = null): void
{
    if ((string)($order['payment_access_mode'] ?? '') !== FS_GUEST_PAYMENT_ACCESS_RADIUS
        || (string)($order['status'] ?? '') === 'paid') return;
    $radius = $radius ?? fs_radius_db();
    $username = trim((string)($order['radius_username'] ?? ''));
    if ($username !== '') {
        $owns = !$radius->inTransaction();
        if ($owns) $radius->beginTransaction();
        try {
            $radius->prepare('DELETE FROM radcheck WHERE username=?')->execute([$username]);
            $radius->prepare('DELETE FROM radreply WHERE username=?')->execute([$username]);
            $radius->prepare('DELETE FROM radusergroup WHERE username=?')->execute([$username]);
            if ($owns) $radius->commit();
        } catch (Throwable $error) {
            if ($owns && $radius->inTransaction()) $radius->rollBack();
            throw $error;
        }
    }
    $app->prepare("UPDATE guest_orders SET radius_phase='expired',radius_cleaned_at=COALESCE(radius_cleaned_at,NOW()),updated_at=NOW() WHERE id=? AND status<>'paid'")
        ->execute([(int)$order['id']]);
}
