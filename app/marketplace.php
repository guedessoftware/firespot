<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/credential_crypto.php';
require_once __DIR__ . '/public_url.php';

function fs_marketplace_schema_ready(PDO $pdo): bool
{
    try {
        return (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketplace_accounts'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function fs_monetization_current_agreement(PDO $pdo, int $partnerId, bool $forUpdate = false): ?array
{
    if ($partnerId <= 0 || !fs_marketplace_schema_ready($pdo)) return null;
    $sql = "SELECT * FROM partner_monetization_agreements
        WHERE partner_id=? AND status='active' AND starts_at<=NOW()
          AND (ends_at IS NULL OR ends_at>NOW())
        ORDER BY starts_at DESC,version DESC,id DESC LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $st = $pdo->prepare($sql);
    $st->execute([$partnerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fs_monetization_access_fee(array $agreement, int $amountCents): int
{
    if ($amountCents <= 0 || !in_array((string)($agreement['model'] ?? ''), ['revenue_share','hybrid'], true)) return 0;
    $type = (string)($agreement['access_fee_type'] ?? 'none');
    $value = max(0, (int)($agreement['access_fee_value'] ?? 0));
    if ($type === 'percentage') {
        if ($value > 10000) throw new RuntimeException('Percentual de comissão inválido no contrato.');
        $fee = (int)round($amountCents * $value / 10000, 0, PHP_ROUND_HALF_UP);
    } elseif ($type === 'fixed') {
        $fee = $value;
    } else {
        $fee = 0;
    }
    return min($amountCents, max(0, $fee));
}

function fs_marketplace_account(PDO $pdo, int $partnerId): ?array
{
    if ($partnerId <= 0 || !fs_marketplace_schema_ready($pdo)) return null;
    $st = $pdo->prepare("SELECT id,partner_id,provider,seller_user_id,public_key,credential_hint,token_expires_at,status,last_error,authorized_at,created_at,updated_at FROM marketplace_accounts WHERE partner_id=? AND provider='mercadopago' LIMIT 1");
    $st->execute([$partnerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fs_marketplace_app_config(PDO $pdo): array
{
    $clientId = trim((string)env('MERCADOPAGO_CLIENT_ID', ''));
    $clientSecret = trim((string)env('MERCADOPAGO_CLIENT_SECRET', ''));
    $publicKey = trim((string)env('MERCADOPAGO_PUBLIC_KEY', env('MP_PUBLIC_KEY', '')));
    if ($clientId === '' || $clientSecret === '' || $publicKey === '') {
        throw new RuntimeException('Configure MERCADOPAGO_CLIENT_ID, MERCADOPAGO_CLIENT_SECRET e MERCADOPAGO_PUBLIC_KEY.');
    }
    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'public_key' => $publicKey,
        'redirect_uri' => rtrim(fs_public_base_url($pdo), '/') . '/admin/mercadopago.php',
    ];
}

function fs_marketplace_oauth_start(PDO $pdo, int $partnerId, string $actorType, int $actorId): string
{
    $config = fs_marketplace_app_config($pdo);
    if (!in_array($actorType, ['firespot','partner_admin'], true) || $actorId <= 0) throw new InvalidArgumentException('Ator OAuth inválido.');
    $st = $pdo->prepare('SELECT id FROM partners WHERE id=? AND active=1 LIMIT 1');
    $st->execute([$partnerId]);
    if (!$st->fetchColumn()) throw new RuntimeException('Estabelecimento não encontrado ou inativo.');

    $state = bin2hex(random_bytes(32));
    $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
    $challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $pdo->prepare('DELETE FROM marketplace_oauth_states WHERE expires_at<NOW() OR used_at IS NOT NULL')->execute();
    $st = $pdo->prepare('INSERT INTO marketplace_oauth_states (partner_id,state_hash,redirect_uri,code_verifier_encrypted,expires_at,created_by_type,created_by_id) VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE),?,?)');
    $st->execute([$partnerId,hash('sha256',$state),$config['redirect_uri'],fs_encrypt_credential($verifier),$actorType,$actorId]);
    return 'https://auth.mercadopago.com/authorization?' . http_build_query([
        'client_id' => $config['client_id'],
        'response_type' => 'code',
        'platform_id' => 'mp',
        'state' => $state,
        'redirect_uri' => $config['redirect_uri'],
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986);
}

function fs_marketplace_oauth_request(array $body): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('A extensão cURL não está habilitada.');
    $ch = curl_init(rtrim((string)env('MERCADOPAGO_API_BASE', 'https://api.mercadopago.com'), '/') . '/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) { $error = curl_error($ch); curl_close($ch); throw new RuntimeException('Falha no OAuth do Mercado Pago: ' . $error); }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$raw, true);
    if ($code >= 300 || !is_array($json)) {
        $message = is_array($json) ? (string)($json['message'] ?? $json['error'] ?? 'OAuth recusado.') : 'Resposta OAuth inválida.';
        throw new RuntimeException('Mercado Pago OAuth HTTP ' . $code . ': ' . substr($message,0,180));
    }
    return $json;
}

function fs_marketplace_oauth_state(PDO $pdo,string $state):?array
{
    if(!preg_match('/^[a-f0-9]{64}$/i',$state))return null;
    $st=$pdo->prepare('SELECT partner_id,created_by_type,created_by_id FROM marketplace_oauth_states WHERE state_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1');
    $st->execute([hash('sha256',strtolower($state))]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function fs_marketplace_oauth_finish(PDO $pdo, string $state, string $code,string $expectedActorType,int $expectedActorId): array
{
    if (!preg_match('/^[a-f0-9]{64}$/i', $state) || trim($code) === '') throw new InvalidArgumentException('Retorno OAuth inválido.');
    if(!in_array($expectedActorType,['firespot','partner_admin'],true)||$expectedActorId<=0)throw new RuntimeException('A sessão que iniciou a autorização não está mais ativa.');
    $config = fs_marketplace_app_config($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM marketplace_oauth_states WHERE state_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1 FOR UPDATE');
        $st->execute([hash('sha256',strtolower($state))]);
        $oauth = $st->fetch(PDO::FETCH_ASSOC);
        if (!$oauth || !hash_equals((string)$oauth['redirect_uri'], $config['redirect_uri'])) throw new RuntimeException('Autorização OAuth expirada ou já utilizada.');
        if(!hash_equals((string)$oauth['created_by_type'],$expectedActorType)||(int)$oauth['created_by_id']!==$expectedActorId)throw new RuntimeException('A autorização pertence a outra sessão administrativa.');
        $pdo->prepare('UPDATE marketplace_oauth_states SET used_at=NOW() WHERE id=?')->execute([(int)$oauth['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $token = fs_marketplace_oauth_request([
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'grant_type' => 'authorization_code',
        'code' => trim($code),
        'redirect_uri' => $config['redirect_uri'],
        'code_verifier' => fs_decrypt_credential((string)$oauth['code_verifier_encrypted']),
    ]);
    $access = trim((string)($token['access_token'] ?? ''));
    $refresh = trim((string)($token['refresh_token'] ?? ''));
    if ($access === '' || $refresh === '') throw new RuntimeException('O Mercado Pago não retornou as credenciais esperadas.');
    $expires = max(60, (int)($token['expires_in'] ?? 15552000));
    $st = $pdo->prepare("INSERT INTO marketplace_accounts
        (partner_id,provider,seller_user_id,public_key,access_token_encrypted,refresh_token_encrypted,credential_hint,token_expires_at,status,last_error,authorized_at)
        VALUES (?,'mercadopago',?,?,?,?,?,DATE_ADD(NOW(),INTERVAL ? SECOND),'active',NULL,NOW())
        ON DUPLICATE KEY UPDATE seller_user_id=VALUES(seller_user_id),public_key=VALUES(public_key),access_token_encrypted=VALUES(access_token_encrypted),refresh_token_encrypted=VALUES(refresh_token_encrypted),credential_hint=VALUES(credential_hint),token_expires_at=VALUES(token_expires_at),status='active',last_error=NULL,authorized_at=NOW()");
    $st->execute([(int)$oauth['partner_id'],substr((string)($token['user_id'] ?? ''),0,64),substr((string)($token['public_key'] ?? ''),0,255),fs_encrypt_credential($access),fs_encrypt_credential($refresh),fs_credential_hint($access),$expires]);
    $result=fs_marketplace_account($pdo, (int)$oauth['partner_id']) ?? [];
    $result['_oauth_actor_type']=(string)$oauth['created_by_type'];
    $result['_oauth_actor_id']=(int)$oauth['created_by_id'];
    return $result;
}

function fs_marketplace_refresh(PDO $pdo, array $account): array
{
    $config = fs_marketplace_app_config($pdo);
    $st = $pdo->prepare('SELECT * FROM marketplace_accounts WHERE id=? LIMIT 1');
    $st->execute([(int)$account['id']]);
    $stored = $st->fetch(PDO::FETCH_ASSOC);
    if (!$stored) throw new RuntimeException('Conta Marketplace não encontrada.');
    $token = fs_marketplace_oauth_request([
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'grant_type' => 'refresh_token',
        'refresh_token' => fs_decrypt_credential((string)$stored['refresh_token_encrypted']),
    ]);
    $access = trim((string)($token['access_token'] ?? ''));
    $refresh = trim((string)($token['refresh_token'] ?? ''));
    if ($access === '') throw new RuntimeException('O Mercado Pago não renovou o Access Token.');
    if ($refresh === '') $refresh = fs_decrypt_credential((string)$stored['refresh_token_encrypted']);
    $expires = max(60, (int)($token['expires_in'] ?? 15552000));
    $pdo->prepare("UPDATE marketplace_accounts SET access_token_encrypted=?,refresh_token_encrypted=?,credential_hint=?,token_expires_at=DATE_ADD(NOW(),INTERVAL ? SECOND),status='active',last_error=NULL,updated_at=NOW() WHERE id=?")
        ->execute([fs_encrypt_credential($access),fs_encrypt_credential($refresh),fs_credential_hint($access),$expires,(int)$stored['id']]);
    return fs_marketplace_account($pdo, (int)$stored['partner_id']) ?? [];
}

function fs_marketplace_wallet_for_partner(PDO $pdo, array $partner, bool $withSecret = false): ?array
{
    $agreement = fs_monetization_current_agreement($pdo, (int)($partner['id'] ?? 0));
    if (!$agreement || !in_array((string)$agreement['model'], ['revenue_share','hybrid'], true) || ($agreement['access_fee_type'] ?? 'none') === 'none') return null;
    $st = $pdo->prepare("SELECT *,token_expires_at IS NULL OR token_expires_at<=DATE_ADD(NOW(),INTERVAL 1 DAY) needs_refresh FROM marketplace_accounts WHERE partner_id=? AND provider='mercadopago' LIMIT 1");
    $st->execute([(int)$partner['id']]);
    $account = $st->fetch(PDO::FETCH_ASSOC);
    if (!$account || ($account['status'] ?? '') !== 'active') throw new RuntimeException('Autorize a conta Mercado Pago do estabelecimento para ativar a participação nas vendas.');
    if ($withSecret && !empty($account['needs_refresh'])) {
        try{fs_marketplace_refresh($pdo,$account);$st->execute([(int)$partner['id']]);$account=$st->fetch(PDO::FETCH_ASSOC);}catch(Throwable $e){$pdo->prepare("UPDATE marketplace_accounts SET status=IF(token_expires_at<=NOW(),'expired','error'),last_error='TOKEN_REFRESH_FAILED',updated_at=NOW() WHERE id=?")->execute([(int)$account['id']]);throw new RuntimeException('A autorização Mercado Pago precisa ser renovada.');}
    }
    $wallet = [
        'id' => null,
        'provider' => 'mercadopago',
        'name' => 'Marketplace Mercado Pago',
        'environment' => (string)env('MP_ENV','production'),
        'public_key' => (string)env('MERCADOPAGO_PUBLIC_KEY', env('MP_PUBLIC_KEY','')),
        'active' => 1,
        'source' => 'marketplace',
        'marketplace_account_id' => (int)$account['id'],
        'monetization_agreement_id' => (int)$agreement['id'],
        'webhook_secret' => trim((string)env('MERCADOPAGO_MARKETPLACE_WEBHOOK_SECRET',env('MERCADOPAGO_WEBHOOK_SECRET',''))),
        'webhook_secret_configured_at' => trim((string)env('MERCADOPAGO_MARKETPLACE_WEBHOOK_SIGNATURE_REQUIRED_AFTER',env('MERCADOPAGO_WEBHOOK_SIGNATURE_REQUIRED_AFTER',''))),
    ];
    if ($withSecret) $wallet['access_token'] = fs_decrypt_credential((string)$account['access_token_encrypted']);
    return $wallet;
}

function fs_marketplace_disconnect(PDO $pdo, int $partnerId): void
{
    $agreement=fs_monetization_current_agreement($pdo,$partnerId);
    if($agreement&&in_array((string)$agreement['model'],['revenue_share','hybrid'],true))throw new RuntimeException('Troque primeiro o contrato para mensalidade antes de revogar a conta Marketplace.');
    $pdo->prepare("UPDATE marketplace_accounts SET access_token_encrypted=NULL,refresh_token_encrypted=NULL,status='revoked',token_expires_at=NULL,updated_at=NOW() WHERE partner_id=? AND provider='mercadopago'")->execute([$partnerId]);
}

function fs_marketplace_wallet_for_order(PDO $pdo, int $accountId, bool $withSecret = true): array
{
    $st=$pdo->prepare('SELECT m.*,m.token_expires_at IS NULL OR m.token_expires_at<=DATE_ADD(NOW(),INTERVAL 1 DAY) needs_refresh,p.id resolved_partner_id FROM marketplace_accounts m JOIN partners p ON p.id=m.partner_id WHERE m.id=? LIMIT 1');
    $st->execute([$accountId]);$account=$st->fetch(PDO::FETCH_ASSOC);
    if(!$account)throw new RuntimeException('Conta Marketplace histórica do pedido não encontrada.');
    if(($account['status']??'')!=='active')throw new RuntimeException('A autorização Mercado Pago do recebedor está indisponível.');
    if($withSecret&&!empty($account['needs_refresh'])){try{fs_marketplace_refresh($pdo,$account);$st->execute([$accountId]);$account=$st->fetch(PDO::FETCH_ASSOC);}catch(Throwable $e){$pdo->prepare("UPDATE marketplace_accounts SET status=IF(token_expires_at<=NOW(),'expired','error'),last_error='TOKEN_REFRESH_FAILED',updated_at=NOW() WHERE id=?")->execute([(int)$account['id']]);throw new RuntimeException('A autorização Mercado Pago precisa ser renovada.');}}
    $wallet=['id'=>null,'provider'=>'mercadopago','name'=>'Marketplace Mercado Pago','environment'=>(string)env('MP_ENV','production'),'public_key'=>(string)env('MERCADOPAGO_PUBLIC_KEY',env('MP_PUBLIC_KEY','')),'active'=>1,'source'=>'marketplace','marketplace_account_id'=>(int)$account['id'],'webhook_secret'=>trim((string)env('MERCADOPAGO_MARKETPLACE_WEBHOOK_SECRET',env('MERCADOPAGO_WEBHOOK_SECRET',''))),'webhook_secret_configured_at'=>trim((string)env('MERCADOPAGO_MARKETPLACE_WEBHOOK_SIGNATURE_REQUIRED_AFTER',env('MERCADOPAGO_WEBHOOK_SIGNATURE_REQUIRED_AFTER','')))];
    if($withSecret)$wallet['access_token']=fs_decrypt_credential((string)$account['access_token_encrypted']);
    return $wallet;
}
