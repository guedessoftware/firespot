<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/payment_wallets.php';
require_once __DIR__ . '/payment_provider.php';
require_once __DIR__ . '/partner_hotspots.php';

function fs_guest_normalize_mac(string $value): string
{
    $value = strtoupper(trim(str_replace('-', ':', $value)));
    if (preg_match('/^[0-9A-F]{12}$/', $value)) $value = implode(':', str_split($value, 2));
    if (preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $value)) return $value;
    if (strpos($value, 'DID:') === 0) return substr(preg_replace('/[^A-Z0-9:]/', '', $value), 0, 64);
    return '';
}

/** RouterOS recebe rx/tx do ponto de vista do roteador: upload/download do cliente. */
function fs_mikrotik_rate_limit_value(int $downloadKbps, int $uploadKbps): ?string
{
    $downloadKbps=max(0,$downloadKbps);$uploadKbps=max(0,$uploadKbps);
    if($downloadKbps===0&&$uploadKbps===0)return null;
    return $uploadKbps.'k/'.$downloadKbps.'k';
}

function fs_guest_local_timezone(): DateTimeZone
{
    $name = trim((string)env('APP_TZ', env('RADIUS_TIMEZONE', 'America/Manaus')));
    try {
        return new DateTimeZone($name !== '' ? $name : 'America/Manaus');
    } catch (Throwable $e) {
        return new DateTimeZone('America/Manaus');
    }
}

function fs_guest_local_datetime_timestamp(string $value): int
{
    $value = trim($value);
    if ($value === '') return 0;
    try {
        return (new DateTimeImmutable($value, fs_guest_local_timezone()))->getTimestamp();
    } catch (Throwable $e) {
        return 0;
    }
}

function fs_guest_payment_expiry_timestamp(array $order): int
{
    $explicit = fs_guest_local_datetime_timestamp((string)($order['payment_expires_at'] ?? ''));
    if ($explicit > 0) return $explicit;
    $created = fs_guest_local_datetime_timestamp((string)($order['created_at'] ?? ''));
    return $created > 0 ? $created + 86400 : 0;
}

function fs_guest_payment_is_expired(array $order, ?int $now = null): bool
{
    $expiry = fs_guest_payment_expiry_timestamp($order);
    return $expiry > 0 && $expiry <= ($now ?? time());
}

function fs_guest_provider_expiration(array $payment): ?string
{
    $raw = trim((string)($payment['date_of_expiration'] ?? ''));
    if ($raw === '') return null;
    try {
        $date = new DateTimeImmutable($raw);
        $zone = fs_guest_local_timezone();
        return $date->setTimezone($zone)->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function fs_guest_partner(PDO $pdo, string $code, bool $requireV3 = true): ?array
{
    $code = trim($code);
    if ($code === '') return null;
    if (fs_partner_hotspots_schema_ready($pdo)) {
        return fs_partner_hotspot_resolve($pdo,$code,$requireV3,true);
    }
    $sql = 'SELECT * FROM partners WHERE active=1 AND (code=?';
    $params = [$code];
    if (ctype_digit($code)) {
        $sql .= ' OR id=?';
        $params[] = (int) $code;
    }
    $sql .= ') LIMIT 1';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $partner = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (!$partner) return null;
    if ($requireV3 && ($partner['portal_mode'] ?? 'inherit') !== 'v3') return null;
    return $partner;
}

function fs_guest_plans(PDO $pdo, array $partner): array
{
    $st = $pdo->prepare('SELECT id, name, description, price_cents, duration_minutes, download_kbps, upload_kbps FROM partner_payment_plans WHERE partner_id=? AND active=1 ORDER BY sort_order,id');
    $st->execute([(int) $partner['id']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) $row['source'] = 'partner';
    unset($row);
    if ($rows) return $rows;
    // Compatibilidade de rollout: unidades ainda sem plano próprio continuam
    // enxergando o catálogo global até o primeiro plano local ser ativado.
    $rows = $pdo->query('SELECT id, nome AS name, descricao AS description, preco_centavos AS price_cents, duracao_min AS duration_minutes, down_kbps AS download_kbps, up_kbps AS upload_kbps FROM planos WHERE ativo=1 ORDER BY ordem,id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) $row['source'] = 'global';
    unset($row);
    return $rows;
}

function fs_guest_plan(PDO $pdo, array $partner, string $source, int $id): ?array
{
    if ($id <= 0) return null;
    if ($source === 'partner') {
        $st = $pdo->prepare('SELECT id, name, description, price_cents, duration_minutes, download_kbps, upload_kbps FROM partner_payment_plans WHERE id=? AND partner_id=? AND active=1 LIMIT 1');
        $st->execute([$id, (int) $partner['id']]);
    } elseif ($source === 'global') {
        $local = $pdo->prepare('SELECT COUNT(*) FROM partner_payment_plans WHERE partner_id=? AND active=1');
        $local->execute([(int)$partner['id']]);
        if ((int)$local->fetchColumn() > 0) return null;
        $st = $pdo->prepare('SELECT id, nome AS name, descricao AS description, preco_centavos AS price_cents, duracao_min AS duration_minutes, down_kbps AS download_kbps, up_kbps AS upload_kbps FROM planos WHERE id=? AND ativo=1 LIMIT 1');
        $st->execute([$id]);
    } else return null;
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['source'] = $source;
    return $row;
}

function fs_guest_create_order(PDO $pdo, array $partner, array $plan, array $device, ?int $walletId, array $monetization = []): array
{
    $publicId = bin2hex(random_bytes(16));
    $orderToken = bin2hex(random_bytes(32));
    $externalRef = 'GST-' . date('YmdHis') . '-' . bin2hex(random_bytes(5));
    $mac = fs_guest_normalize_mac((string) ($device['mac'] ?? ''));
    $ip = filter_var((string) ($device['ip'] ?? ''), FILTER_VALIDATE_IP) ? (string) $device['ip'] : null;
    $marketplaceAccountId=max(0,(int)($monetization['marketplace_account_id']??0))?:null;
    $agreementId=max(0,(int)($monetization['monetization_agreement_id']??0))?:null;
    $subscriberAccountId=max(0,(int)($monetization['subscriber_account_id']??0))?:null;
    $subscriberDeviceId=max(0,(int)($monetization['subscriber_device_id']??0))?:null;
    $firespotFee=max(0,min((int)$plan['price_cents'],(int)($monetization['firespot_fee_cents']??0)));
    $snapshot=$agreementId?json_encode(['agreement_id'=>$agreementId,'fee_type'=>$monetization['fee_type']??'none','fee_value'=>(int)($monetization['fee_value']??0),'firespot_fee_cents'=>$firespotFee],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
    $hotspotId = fs_partner_hotspot_id($partner);
    if (fs_partner_hotspots_schema_ready($pdo) && $hotspotId === null) {
        $defaultHotspot = fs_partner_hotspot_default($pdo,(int)$partner['id'],true);
        $hotspotId = $defaultHotspot ? fs_partner_hotspot_id($defaultHotspot) : null;
    }
    $hotspotSchemaReady = fs_partner_hotspots_schema_ready($pdo);
    if ($hotspotSchemaReady && $hotspotId === null) throw new RuntimeException('Instalação técnica não encontrada.');
    $columns = 'public_id,order_token_hash,external_ref,partner_id,'
        . ($hotspotSchemaReady ? 'hotspot_id,' : '')
        . 'subscriber_account_id,subscriber_device_id,wallet_id,marketplace_account_id,monetization_agreement_id,provider,status,plan_source,plan_id,plan_name,amount_cents,firespot_fee_cents,fee_snapshot,duration_minutes,download_kbps,upload_kbps,device_mac,device_ip,created_at,updated_at';
    $values = '?,?,?,?,' . ($hotspotSchemaReady ? '?,' : '')
        . '?,?,?,?,?,\'mercadopago\',\'pending\',?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()';
    $params = [
        $publicId,
        hash('sha256', $orderToken),
        $externalRef,
        (int) $partner['id'],
    ];
    if ($hotspotSchemaReady) $params[] = $hotspotId;
    array_push($params,
        $subscriberAccountId,
        $subscriberDeviceId,
        $walletId,
        $marketplaceAccountId,
        $agreementId,
        (string) $plan['source'],
        (int) $plan['id'],
        (string) $plan['name'],
        (int) $plan['price_cents'],
        $firespotFee,
        $snapshot,
        (int) $plan['duration_minutes'],
        (int) $plan['download_kbps'],
        (int) $plan['upload_kbps'],
        $mac !== '' ? $mac : null,
        $ip,
    );
    $st = $pdo->prepare("INSERT INTO guest_orders ({$columns}) VALUES ({$values})");
    $st->execute($params);
    return [
        'id' => (int) $pdo->lastInsertId(),
        'public_id' => $publicId,
        'order_token' => $orderToken,
        'external_ref' => $externalRef,
        'partner_id' => (int) $partner['id'],
        'hotspot_id' => $hotspotId,
        'wallet_id' => $walletId,
        'subscriber_account_id' => $subscriberAccountId,
        'subscriber_device_id' => $subscriberDeviceId,
        'marketplace_account_id' => $marketplaceAccountId,
        'monetization_agreement_id' => $agreementId,
        'firespot_fee_cents' => $firespotFee,
        'plan_name' => (string) $plan['name'],
        'amount_cents' => (int) $plan['price_cents'],
    ];
}

function fs_guest_order_for_session(PDO $pdo, string $publicId, string $rawToken, bool $forUpdate = false): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $publicId) || $rawToken === '') return null;
    $sql = 'SELECT * FROM guest_orders WHERE public_id=? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $st = $pdo->prepare($sql);
    $st->execute([$publicId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order || !hash_equals((string) $order['order_token_hash'], hash('sha256', $rawToken))) return null;
    return $order;
}

function fs_guest_wallet_for_order(PDO $pdo, array $order): array
{
    $marketplaceAccountId=(int)($order['marketplace_account_id']??0);
    if($marketplaceAccountId>0)return fs_marketplace_wallet_for_order($pdo,$marketplaceAccountId,true);
    $walletId = (int) ($order['wallet_id'] ?? 0);
    if ($walletId > 0) {
        $wallet = fs_wallet_by_id($pdo, $walletId, true);
        // O status ativo controla somente novas vendas. Pedidos históricos
        // continuam consultando a carteira registrada no próprio wallet_id,
        // inclusive depois de uma substituição segura da credencial atual.
        if (!$wallet) throw new RuntimeException('Carteira histórica do pedido não encontrada.');
        fs_wallet_assert_usable($wallet);
        return $wallet;
    }
    return fs_global_wallet();
}

function fs_guest_grant_access(PDO $pdo, int $orderId): array
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM guest_orders WHERE id=? LIMIT 1 FOR UPDATE');
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new RuntimeException('Pedido não encontrado.');
        if ($order['status'] !== 'paid') throw new RuntimeException('Pagamento ainda não confirmado.');
        if (!empty($order['access_granted_at']) && !empty($order['radius_username'])) {
            if (!empty($order['radius_cleaned_at'])) throw new RuntimeException('O crédito deste acesso terminou.');
            $username = (string) $order['radius_username'];
            $seconds = max(60, (int) $order['duration_minutes'] * 60);
            $password = fs_guest_radius_password($pdo, $username);
            if ($password === null || $password === '') {
                $password = bin2hex(random_bytes(10));
                $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute='Cleartext-Password'")->execute([$username]);
                $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,'Cleartext-Password',':=',?)")
                    ->execute([$username, $password]);
                $order['_radius_password'] = $password;
            }
            $max = $pdo->prepare("SELECT value FROM radcheck WHERE username=? AND attribute='Max-All-Session' LIMIT 1");
            $max->execute([$username]);
            if ($max->fetchColumn() === false) {
                $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,'Max-All-Session',':=',?)")
                    ->execute([$username, (string) $seconds]);
            }
            $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute='Simultaneous-Use'")->execute([$username]);
            $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,'Simultaneous-Use',':=','1')")->execute([$username]);
            $pdo->prepare("DELETE FROM radreply WHERE username=? AND attribute IN ('Acct-Interim-Interval','Mikrotik-Rate-Limit')")->execute([$username]);
            $pdo->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,'Acct-Interim-Interval',':=','60')")->execute([$username]);
            $rateLimit=fs_mikrotik_rate_limit_value((int)$order['download_kbps'],(int)$order['upload_kbps']);if($rateLimit!==null)$pdo->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,'Mikrotik-Rate-Limit',':=',?)")->execute([$username,$rateLimit]);
            if ($ownsTransaction) $pdo->commit();
            return $order;
        }

        $username = 'gst_' . substr((string) $order['public_id'], 0, 20);
        $password = bin2hex(random_bytes(10));
        $seconds = max(60, (int) $order['duration_minutes'] * 60);

        $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute IN ('Cleartext-Password','Max-All-Session','Simultaneous-Use')")->execute([$username]);
        $insCheck = $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,':=',?)");
        $insCheck->execute([$username, 'Cleartext-Password', $password]);
        $insCheck->execute([$username, 'Max-All-Session', (string) $seconds]);
        $insCheck->execute([$username, 'Simultaneous-Use', '1']);

        $pdo->prepare("DELETE FROM radreply WHERE username=? AND attribute IN ('Mikrotik-Rate-Limit','Acct-Interim-Interval','Session-Timeout')")->execute([$username]);
        $insReply = $pdo->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,?,':=',?)");
        $down = (int) $order['download_kbps'];
        $up = (int) $order['upload_kbps'];
        $rateLimit=fs_mikrotik_rate_limit_value($down,$up);
        if ($rateLimit !== null) $insReply->execute([$username, 'Mikrotik-Rate-Limit', $rateLimit]);
        $insReply->execute([$username, 'Acct-Interim-Interval', '60']);
        $insReply->execute([$username, 'Session-Timeout', (string) $seconds]);

        $pdo->prepare('UPDATE guest_orders SET radius_username=?,access_granted_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$username, $orderId]);
        if ($ownsTransaction) $pdo->commit();
        $order['radius_username'] = $username;
        $order['access_granted_at'] = date('Y-m-d H:i:s');
        $order['_radius_password'] = $password;
        return $order;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function fs_guest_radius_password(PDO $pdo, string $username): ?string
{
    $st = $pdo->prepare("SELECT value FROM radcheck WHERE username=? AND attribute='Cleartext-Password' ORDER BY id DESC LIMIT 1");
    $st->execute([$username]);
    $value = $st->fetchColumn();
    return $value === false ? null : (string) $value;
}

/**
 * Retorna o crédito RADIUS acumulado da compra.
 * Max-All-Session é o total comprado e radacct contém o tempo efetivamente usado.
 */
function fs_guest_credit_balance(PDO $pdo, array $order): array
{
    $username = trim((string) ($order['radius_username'] ?? ''));
    if ($username === '' || !empty($order['radius_cleaned_at'])) {
        return ['allowed_seconds' => 0, 'used_seconds' => 0, 'remaining_seconds' => 0, 'online' => false];
    }

    $st = $pdo->prepare("SELECT CAST(value AS UNSIGNED) FROM radcheck WHERE username=? AND attribute='Max-All-Session' ORDER BY id DESC LIMIT 1");
    $st->execute([$username]);
    $allowed = (int) ($st->fetchColumn() ?: 0);

    $st = $pdo->prepare('SELECT COALESCE(SUM(COALESCE(acctsessiontime,0)),0) FROM radacct WHERE username=?');
    $st->execute([$username]);
    $used = max(0, (int) ($st->fetchColumn() ?: 0));

    $st = $pdo->prepare("SELECT COUNT(*) FROM radacct WHERE username=? AND (acctstoptime IS NULL OR acctstoptime='0000-00-00 00:00:00')");
    $st->execute([$username]);

    $remaining = max(0, $allowed - $used);
    // Na promocao Pix, Max-All-Session inclui a linha de base consumida antes
    // do pagamento. O cliente sempre recebe exatamente o tempo comprado,
    // inclusive enquanto o primeiro interim accounting ainda esta atrasado.
    if ((string)($order['payment_access_mode'] ?? '') === 'radius_preauth'
        && $order['radius_paid_baseline_seconds'] !== null) {
        $remaining = min($remaining,max(60,(int)($order['duration_minutes'] ?? 0)*60));
    }
    return [
        'allowed_seconds' => $allowed,
        'used_seconds' => $used,
        'remaining_seconds' => $remaining,
        'online' => (int) $st->fetchColumn() > 0,
    ];
}

function fs_guest_find_credit_by_mac(PDO $pdo, int $partnerId, string $deviceMac): ?array
{
    $mac = fs_guest_normalize_mac($deviceMac);
    if ($partnerId <= 0 || $mac === '') return null;

    $st = $pdo->prepare("SELECT * FROM guest_orders
        WHERE partner_id=? AND device_mac=? AND status='paid'
          AND radius_username IS NOT NULL AND radius_cleaned_at IS NULL
        ORDER BY paid_at DESC,id DESC LIMIT 20");
    $st->execute([$partnerId, $mac]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $order) {
        $order = fs_guest_grant_access($pdo, (int) $order['id']);
        $credit = fs_guest_credit_balance($pdo, $order);
        if ((int) $credit['remaining_seconds'] > 0) {
            $order['_credit'] = $credit;
            return $order;
        }
    }
    return null;
}

function fs_guest_find_pending_by_mac(PDO $pdo, int $partnerId, string $deviceMac): ?array
{
    $mac = fs_guest_normalize_mac($deviceMac);
    if ($partnerId <= 0 || $mac === '') return null;

    $st = $pdo->prepare("SELECT * FROM guest_orders
        WHERE partner_id=? AND device_mac=? AND status='pending'
          AND provider_payment_id IS NOT NULL
          AND COALESCE(payment_expires_at,DATE_ADD(created_at,INTERVAL 24 HOUR))>NOW()
        ORDER BY id DESC LIMIT 1");
    $st->execute([$partnerId, $mac]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    return $order ?: null;
}

function fs_guest_rotate_order_token(PDO $pdo, int $orderId): string
{
    if ($orderId <= 0) throw new InvalidArgumentException('Pedido inválido para recuperação.');
    $token = bin2hex(random_bytes(32));
    $st = $pdo->prepare('UPDATE guest_orders SET order_token_hash=?,updated_at=NOW() WHERE id=?');
    $st->execute([hash('sha256', $token), $orderId]);
    if ($st->rowCount() < 1) throw new RuntimeException('Não foi possível recuperar o acesso.');
    return $token;
}

/**
 * Recalcula o limite da próxima sessão antes de reenviar as credenciais ao HotSpot.
 */
function fs_guest_prepare_reconnect(PDO $pdo, int $orderId): array
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM guest_orders WHERE id=? AND status='paid' LIMIT 1 FOR UPDATE");
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order || empty($order['radius_username']) || !empty($order['radius_cleaned_at'])) {
            throw new RuntimeException('Este acesso não possui crédito disponível.');
        }

        $credit = fs_guest_credit_balance($pdo, $order);
        $remaining = (int) $credit['remaining_seconds'];
        if ($remaining <= 0) throw new RuntimeException('O crédito deste acesso terminou.');

        $username = (string) $order['radius_username'];
        $mac = fs_guest_normalize_mac((string)($order['device_mac'] ?? ''));
        if ($mac === '') throw new RuntimeException('Este acesso não possui aparelho associado.');
        $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute IN ('Simultaneous-Use','Calling-Station-Id')")->execute([$username]);
        $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,'Simultaneous-Use',':=','1')")->execute([$username]);
        $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,'Calling-Station-Id','==',?)")->execute([$username,$mac]);
        $pdo->prepare("DELETE FROM radreply WHERE username=? AND attribute='Session-Timeout'")->execute([$username]);
        $pdo->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,'Session-Timeout',':=',?)")
            ->execute([$username, (string) $remaining]);

        if ($ownsTransaction) $pdo->commit();
        $order['_credit'] = $credit;
        return $order;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function fs_guest_mark_from_provider(PDO $pdo, array $order, array $payment): array
{
    $providerRef = (string) ($payment['external_reference'] ?? '');
    $paymentId = (string) ($payment['id'] ?? '');
    $amount = isset($payment['transaction_amount']) ? (int) round(((float) $payment['transaction_amount']) * 100) : 0;
    if (!preg_match('/^[0-9]{1,32}$/D',$paymentId)) throw new RuntimeException('Identificador do pagamento inválido.');
    if ($providerRef !== (string) $order['external_ref']) throw new RuntimeException('Referência do pagamento divergente.');
    if ($amount !== (int) $order['amount_cents']) throw new RuntimeException('Valor do pagamento divergente.');
    $normalized = fs_payment_normalize_status((string) ($payment['status'] ?? ''));
    $detail = substr((string) ($payment['status_detail'] ?? ''), 0, 96);
    $method = substr((string) ($payment['payment_method_id'] ?? ''), 0, 32);
    $paymentExpiresAt = fs_guest_provider_expiration($payment);
    $providerFee=0;$feeDetails=[];
    foreach(($payment['fee_details']??[]) as $fee){
        if(!is_array($fee))continue;$type=strtolower((string)($fee['type']??'unknown'));$cents=(int)round(abs((float)($fee['amount']??0))*100);
        $feeDetails[]=['type'=>substr($type,0,48),'amount_cents'=>$cents];
        if(!in_array($type,['application_fee','marketplace_fee'],true))$providerFee+=$cents;
    }
    $firespotFee=(int)($order['firespot_fee_cents']??0);
    $netRaw=$payment['transaction_details']['net_received_amount']??null;
    $partnerNet=$netRaw!==null?(int)round((float)$netRaw*100):max(0,$amount-$providerFee-$firespotFee);
    $feeSnapshot=json_encode(['provider_fee_cents'=>$providerFee,'firespot_fee_cents'=>$firespotFee,'partner_net_cents'=>$partnerNet,'fee_details'=>$feeDetails],JSON_UNESCAPED_SLASHES);
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare('SELECT * FROM guest_orders WHERE id=? LIMIT 1 FOR UPDATE');
        $st->execute([(int)$order['id']]);
        $stored=$st->fetch(PDO::FETCH_ASSOC);
        if(!$stored)throw new RuntimeException('Pedido inexistente.');
        if($providerRef!==(string)$stored['external_ref']||$amount!==(int)$stored['amount_cents'])throw new RuntimeException('Pagamento não pertence ao pedido.');
        $storedPaymentId=trim((string)($stored['provider_payment_id']??''));
        if($storedPaymentId!==''&&!hash_equals($storedPaymentId,$paymentId))throw new RuntimeException('Pagamento diferente já vinculado ao pedido.');
        $collision=$pdo->prepare('SELECT id FROM guest_orders WHERE provider=? AND provider_payment_id=? AND id<>? LIMIT 1');
        $collision->execute([(string)($stored['provider']??'mercadopago'),$paymentId,(int)$stored['id']]);
        if($collision->fetchColumn())throw new RuntimeException('Pagamento já vinculado a outro pedido.');

        $oldStatus=(string)$stored['status'];
        if($oldStatus==='refunded')$targetStatus='refunded';
        elseif($normalized==='refunded')$targetStatus='refunded';
        elseif($normalized==='paid')$targetStatus='paid';
        elseif($oldStatus==='paid')$targetStatus='paid';
        elseif($oldStatus==='pending'&&$normalized!=='pending')$targetStatus=$normalized;
        else $targetStatus=$oldStatus;

        $pdo->prepare("UPDATE guest_orders SET provider_payment_id=?,payment_method=?,payment_status_detail=?,payment_expires_at=COALESCE(?,payment_expires_at),provider_fee_cents=?,partner_net_cents=?,fee_snapshot=?,status=?,paid_at=IF(?='paid',COALESCE(paid_at,NOW()),paid_at),updated_at=NOW() WHERE id=?")
            ->execute([$paymentId,$method,$detail,$paymentExpiresAt,$providerFee,$partnerNet,$feeSnapshot,$targetStatus,$targetStatus,(int)$stored['id']]);
        if($ownsTransaction)$pdo->commit();
        $stored['provider_payment_id']=$paymentId;
        $stored['payment_method']=$method;
        $stored['payment_status_detail']=$detail;
        $stored['status']=$targetStatus;
        $stored['provider_fee_cents']=$providerFee;
        $stored['partner_net_cents']=$partnerNet;
        $stored['fee_snapshot']=$feeSnapshot;
        if($paymentExpiresAt!==null)$stored['payment_expires_at']=$paymentExpiresAt;
        return $stored;
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function fs_guest_webhook_signature(string $publicId): string
{
    return hash_hmac('sha256', $publicId, fs_credential_key());
}
