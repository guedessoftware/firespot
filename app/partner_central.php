<?php

declare(strict_types=1);

require_once __DIR__ . '/payment_wallets.php';
require_once __DIR__ . '/payment_provider.php';
require_once __DIR__ . '/portal_theme.php';
require_once __DIR__ . '/company.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/portal_configuration.php';

/**
 * Regras compartilhadas pela Central do Estabelecimento.
 *
 * Esta camada mantém as mesmas proteções da tela de Recebimentos sem levar
 * credenciais de carteiras para o HTML do painel de estabelecimentos.
 */

function partner_central_bool($value): int
{
    return in_array((string) $value, ['1', 'on', 'yes', 'true'], true) ? 1 : 0;
}

function partner_central_partner(PDO $pdo, int $partnerId): array
{
    if ($partnerId <= 0) throw new RuntimeException('Estabelecimento inválido.');
    $st = $pdo->prepare('SELECT * FROM partners WHERE id=? LIMIT 1');
    $st->execute([$partnerId]);
    $partner = $st->fetch(PDO::FETCH_ASSOC);
    if (!$partner) throw new RuntimeException('Estabelecimento não encontrado.');
    return $partner;
}

function partner_central_price_cents($value): int
{
    $raw = preg_replace('/[^0-9,.-]/', '', (string) $value);
    if (strpos($raw, ',') !== false) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    }
    return (int) round(((float) $raw) * 100);
}

function partner_central_theme_color(string $value, string $label): string
{
    $value = strtolower(trim($value));
    if (!preg_match('/^#[0-9a-f]{6}$/', $value)) {
        throw new RuntimeException('Informe uma cor hexadecimal válida para ' . $label . '.');
    }
    return $value;
}

function partner_central_active_plan_count(PDO $pdo, int $partnerId, int $exceptPlanId = 0): int
{
    $sql = 'SELECT COUNT(*) FROM partner_payment_plans WHERE partner_id=? AND active=1 AND price_cents>0 AND duration_minutes>0';
    $params = [$partnerId];
    if ($exceptPlanId > 0) {
        $sql .= ' AND id<>?';
        $params[] = $exceptPlanId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

function partner_central_lock_plans(PDO $pdo, int $partnerId): void
{
    $st = $pdo->prepare('SELECT id FROM partner_payment_plans WHERE partner_id=? ORDER BY id FOR UPDATE');
    $st->execute([$partnerId]);
    $st->fetchAll(PDO::FETCH_COLUMN);
}

function partner_central_assert_plan_deactivation_allowed(PDO $pdo, int $partnerId, int $planId): void
{
    $partner = partner_central_partner($pdo, $partnerId);
    $purpose = (string)($partner['access_purpose'] ?? '');
    $salesActive = in_array($purpose, ['paid','hybrid'], true)
        || ($purpose === '' && (string)($partner['portal_mode'] ?? '') === 'v3');
    if (!$salesActive || partner_central_active_plan_count($pdo, $partnerId, $planId) > 0) return;

    // Ao desativar o último plano local, o catálogo global volta a ser a
    // fonte efetiva. Só bloqueamos se a finalidade paga ficaria sem nenhuma
    // opção válida depois da alteração.
    $globalPlans = (int)$pdo->query('SELECT COUNT(*) FROM planos WHERE ativo=1 AND preco_centavos>0 AND duracao_min>0')->fetchColumn();
    if ($globalPlans < 1) {
        throw new RuntimeException('Esta finalidade paga precisa manter ao menos um plano válido. Ative outro plano local ou disponibilize um plano global antes de desativar este.');
    }
}

function partner_central_assert_v3_ready(PDO $pdo, int $partnerId, int $independent, ?int $walletId): void
{
    fs_credential_key();
    $partner = partner_central_partner($pdo,$partnerId);
    $partner['independent_billing'] = $independent;
    $partner['payment_wallet_id'] = $walletId;
    if ($independent === 1) {
        if (($walletId ?? 0) <= 0) throw new RuntimeException('Vincule uma carteira ao estabelecimento independente.');
        $wallet = fs_wallet_by_id($pdo, (int) $walletId, true);
        if (!$wallet || (int) ($wallet['active'] ?? 0) !== 1) throw new RuntimeException('Selecione uma carteira ativa.');
        if ((int)($wallet['partner_id'] ?? 0) !== $partnerId) throw new RuntimeException('A carteira selecionada não pertence a este estabelecimento.');
        fs_wallet_assert_usable($wallet);
    } else {
        $global = fs_global_wallet();
        if (empty($global['public_key']) || empty($global['access_token'])) {
            throw new RuntimeException('A carteira global ainda não está configurada.');
        }
    }
    if (!fs_guest_plans($pdo,$partner)) throw new RuntimeException('Cadastre ao menos uma opção de acesso ativa antes de habilitar o Portal V3.');
}

function partner_central_save_portal(PDO $pdo, int $partnerId, string $portalMode): void
{
    if (!in_array($portalMode, ['inherit', 'classic', 'v2', 'v3'], true)) $portalMode = 'inherit';
    $partner = partner_central_partner($pdo, $partnerId);
    if ($portalMode === 'v3') {
        $purpose = (string)($partner['access_purpose'] ?? '');
        if (in_array($purpose,['free','sponsored'],true)) {
            $readiness = partner_purpose_readiness($pdo,$partner,$purpose);
            if (empty($readiness['ready'])) throw new RuntimeException(implode(' ',$readiness['errors']));
        } else {
            partner_central_assert_v3_ready(
                $pdo,
                $partnerId,
                (int) ($partner['independent_billing'] ?? 0),
                !empty($partner['payment_wallet_id']) ? (int) $partner['payment_wallet_id'] : null
            );
        }
    }
    $pdo->prepare('UPDATE partners SET portal_mode=?,updated_at=NOW() WHERE id=?')->execute([$portalMode, $partnerId]);
}

function partner_central_save_billing(PDO $pdo, int $partnerId, int $independent, ?int $walletId): void
{
    $partner = partner_central_partner($pdo, $partnerId);
    $independent = $independent === 1 ? 1 : 0;
    if ($independent === 1) {
        if (($walletId ?? 0) <= 0) throw new RuntimeException('Selecione a carteira que receberá os pagamentos.');
        $wallet = fs_wallet_by_id($pdo, (int) $walletId, false);
        if (!$wallet) throw new RuntimeException('Carteira não encontrada.');
        if (!array_key_exists('partner_id', $wallet) || (int)($wallet['partner_id'] ?? 0) !== $partnerId) {
            throw new RuntimeException('A carteira selecionada não pertence a este estabelecimento.');
        }
        if ((int)($wallet['active'] ?? 0) !== 1) {
            throw new RuntimeException('A carteira selecionada está inativa. Revalide e restaure a credencial antes de vinculá-la.');
        }
    } else {
        $walletId = null;
    }
    if ((string) ($partner['portal_mode'] ?? '') === 'v3' && !in_array((string)($partner['access_purpose'] ?? ''),['free','sponsored'],true)) {
        partner_central_assert_v3_ready($pdo, $partnerId, $independent, $walletId);
    }
    $pdo->prepare('UPDATE partners SET independent_billing=?,payment_wallet_id=?,updated_at=NOW() WHERE id=?')
        ->execute([$independent, $walletId, $partnerId]);
}

/** @return array{window_minutes:int,daily_limit:int,cooldown_minutes:int,period_minutes:int} */
function partner_central_save_payment_window(PDO $pdo, int $partnerId, array $input): array
{
    $partner=partner_central_partner($pdo,$partnerId);
    $config=fs_portal_config_get($pdo,$partnerId,'draft')?:fs_portal_config_for_partner($pdo,$partner);
    if(!fs_portal_config_has_sales($config))throw new RuntimeException('A internet temporária para pagamento exige venda de acesso na jornada.');
    $window=(int)($input['payment_window_minutes']??0);$limit=(int)($input['payment_window_daily_limit']??0);
    $cooldown=(int)($input['payment_window_cooldown_minutes']??0);$periodHours=(int)($input['payment_window_period_hours']??0);
    if($window<1||$window>5)throw new InvalidArgumentException('A duração da janela Pix deve ficar entre 1 e 5 minutos.');
    if($limit<1||$limit>12)throw new InvalidArgumentException('O limite deve ficar entre 1 e 12 tentativas por período.');
    if($cooldown<5||$cooldown>60)throw new InvalidArgumentException('O intervalo deve ficar entre 5 e 60 minutos.');
    if($periodHours<1||$periodHours>168)throw new InvalidArgumentException('A renovação deve ficar entre 1 e 168 horas.');
    $period=$periodHours*60;
    $pdo->prepare('UPDATE partners SET payment_window_minutes=?,payment_window_daily_limit=?,payment_window_cooldown_minutes=?,payment_window_period_minutes=?,updated_at=NOW() WHERE id=?')
        ->execute([$window,$limit,$cooldown,$period,$partnerId]);
    return ['window_minutes'=>$window,'daily_limit'=>$limit,'cooldown_minutes'=>$cooldown,'period_minutes'=>$period];
}

function partner_central_replace_partner_wallet(PDO $pdo, int $partnerId, array $data, bool $allowEnableIndependent=false): int
{
    $partner = partner_central_partner($pdo,$partnerId);
    if ((int)($partner['independent_billing'] ?? 0) !== 1 && !$allowEnableIndependent) {
        throw new RuntimeException('O recebimento deste estabelecimento é administrado pela FireSpot.');
    }
    $name = trim((string)($data['name'] ?? ''));
    $provider=trim((string)($data['provider']??'mercadopago'))?:'mercadopago';
    $gateway=fs_payment_gateway_catalog()[$provider]??null;
    if(!$gateway||empty($gateway['available']))throw new InvalidArgumentException('Selecione um gateway de recebimento disponível.');
    $environment = in_array($data['environment'] ?? '', ['sandbox','production'], true) ? (string)$data['environment'] : 'production';
    $publicKey = trim((string)($data['public_key'] ?? ''));
    $accessToken = trim((string)($data['access_token'] ?? ''));
    $webhookSecret = trim((string)($data['webhook_secret'] ?? ''));
    if ($name === '' || $publicKey === '' || $accessToken === '' || $webhookSecret === '') throw new InvalidArgumentException('Informe nome, chave pública, Access Token e assinatura secreta do webhook.');
    fs_payment_webhook_secret_assert($webhookSecret);
    $candidate = [
        'provider' => $provider,
        'environment' => $environment,
        'public_key' => $publicKey,
        'access_token' => $accessToken,
        'active' => 1,
    ];
    fs_wallet_assert_usable($candidate);
    // Consulta autenticada e somente leitura. A carteira só é persistida se o
    // provedor confirmar que a credencial é utilizável.
    fs_payment_validate_wallet_capabilities($candidate);
    fs_payment_webhook_self_test($candidate+['webhook_secret'=>$webhookSecret]);
    $encrypted = fs_encrypt_credential($accessToken);
    $hint = fs_credential_hint($accessToken);

    $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT independent_billing,payment_wallet_id FROM partners WHERE id=? FOR UPDATE');
        $lock->execute([$partnerId]);$current=$lock->fetch(PDO::FETCH_ASSOC);
        if(!$current||((int)$current['independent_billing']!==1&&!$allowEnableIndependent))throw new RuntimeException('O modo de recebimento mudou durante a validação. Recarregue a página.');
        $pdo->prepare('UPDATE payment_wallets SET active=0,updated_at=NOW() WHERE partner_id=?')->execute([$partnerId]);
        $st=fs_payment_wallet_validation_schema_ready($pdo)
            ?$pdo->prepare('INSERT INTO payment_wallets (partner_id,provider,name,environment,public_key,access_token_encrypted,credential_hint,access_token_validated_at,webhook_secret_encrypted,webhook_secret_hint,webhook_secret_configured_at,webhook_secret_validated_at,active) VALUES (?,?,?,?,?,?,?,NOW(),?,?,NOW(),NOW(),1)')
            :$pdo->prepare('INSERT INTO payment_wallets (partner_id,provider,name,environment,public_key,access_token_encrypted,credential_hint,webhook_secret_encrypted,webhook_secret_hint,webhook_secret_configured_at,active) VALUES (?,?,?,?,?,?,?,?,?,NOW(),1)');
        $st->execute([$partnerId,$provider,$name,$environment,$publicKey,$encrypted,$hint,fs_encrypt_credential($webhookSecret),fs_credential_hint($webhookSecret)]);
        $walletId = (int)$pdo->lastInsertId();
        if((string)($partner['portal_mode']??'')==='v3'&&!in_array((string)($partner['access_purpose']??''),['free','sponsored'],true))partner_central_assert_v3_ready($pdo,$partnerId,1,$walletId);
        $pdo->prepare('UPDATE partners SET independent_billing=1,payment_wallet_id=?,updated_at=NOW() WHERE id=?')->execute([$walletId,$partnerId]);
        $pdo->commit();
        return $walletId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** @return array<string,mixed> */
function partner_central_active_partner_wallet(PDO $pdo, int $partnerId, bool $withSecrets=true): array
{
    $partner=partner_central_partner($pdo,$partnerId);
    if ((int)($partner['independent_billing']??0)!==1) {
        throw new RuntimeException('O recebimento deste estabelecimento é administrado pela FireSpot.');
    }
    $walletId=(int)($partner['payment_wallet_id']??0);
    $wallet=fs_wallet_by_id($pdo,$walletId,$withSecrets);
    if (!$wallet || (int)($wallet['active']??0)!==1 || (int)($wallet['partner_id']??0)!==$partnerId) {
        throw new RuntimeException('A carteira ativa deste estabelecimento não foi encontrada.');
    }
    return $wallet;
}

function partner_central_rotate_partner_wallet_token(PDO $pdo, int $partnerId, string $accessToken): int
{
    if(!fs_payment_wallet_validation_schema_ready($pdo))throw new RuntimeException('A atualização separada de credenciais aguarda a migração 049.');
    $accessToken=trim($accessToken);
    if ($accessToken==='') throw new InvalidArgumentException('Informe o novo Access Token.');
    $wallet=partner_central_active_partner_wallet($pdo,$partnerId,false);
    $candidate=$wallet+['access_token'=>$accessToken];
    fs_wallet_assert_usable($candidate);
    fs_payment_validate_wallet_capabilities($candidate);
    $encrypted=fs_encrypt_credential($accessToken);
    $hint=fs_credential_hint($accessToken);

    $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT independent_billing,payment_wallet_id FROM partners WHERE id=? FOR UPDATE');
        $lock->execute([$partnerId]);$current=$lock->fetch(PDO::FETCH_ASSOC);
        if (!$current || (int)$current['independent_billing']!==1 || (int)$current['payment_wallet_id']!==(int)$wallet['id']) {
            throw new RuntimeException('A carteira ativa mudou durante a validação. Recarregue a página e tente novamente.');
        }
        $update=$pdo->prepare('UPDATE payment_wallets SET access_token_encrypted=?,credential_hint=?,access_token_validated_at=NOW(),updated_at=NOW() WHERE id=? AND partner_id=? AND active=1');
        $update->execute([$encrypted,$hint,(int)$wallet['id'],$partnerId]);
        if ($update->rowCount()!==1) throw new RuntimeException('A carteira ativa não pôde ser atualizada.');
        $pdo->commit();
        return (int)$wallet['id'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function partner_central_rotate_partner_wallet_webhook(PDO $pdo, int $partnerId, string $webhookSecret): int
{
    if(!fs_payment_wallet_validation_schema_ready($pdo))throw new RuntimeException('A atualização separada de credenciais aguarda a migração 049.');
    $webhookSecret=trim($webhookSecret);
    fs_payment_webhook_secret_assert($webhookSecret);
    $wallet=partner_central_active_partner_wallet($pdo,$partnerId,false);
    fs_payment_webhook_self_test($wallet+['webhook_secret'=>$webhookSecret]);
    $encrypted=fs_encrypt_credential($webhookSecret);
    $hint=fs_credential_hint($webhookSecret);

    $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT independent_billing,payment_wallet_id FROM partners WHERE id=? FOR UPDATE');
        $lock->execute([$partnerId]);$current=$lock->fetch(PDO::FETCH_ASSOC);
        if (!$current || (int)$current['independent_billing']!==1 || (int)$current['payment_wallet_id']!==(int)$wallet['id']) {
            throw new RuntimeException('A carteira ativa mudou durante a validação. Recarregue a página e tente novamente.');
        }
        $update=$pdo->prepare('UPDATE payment_wallets SET webhook_secret_encrypted=?,webhook_secret_hint=?,webhook_secret_configured_at=NOW(),webhook_secret_validated_at=NOW(),updated_at=NOW() WHERE id=? AND partner_id=? AND active=1');
        $update->execute([$encrypted,$hint,(int)$wallet['id'],$partnerId]);
        if ($update->rowCount()!==1) throw new RuntimeException('A assinatura do webhook não pôde ser atualizada.');
        $pdo->commit();
        return (int)$wallet['id'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function partner_central_test_partner_wallet_webhook(PDO $pdo, int $partnerId): int
{
    $wallet=partner_central_active_partner_wallet($pdo,$partnerId,true);
    fs_payment_webhook_self_test($wallet);
    return (int)$wallet['id'];
}

/**
 * Diagnóstico somente leitura da carteira já armazenada. Confirma o acesso à
 * API do gateway e o verificador local do webhook sem criar cobrança nem
 * solicitar novamente qualquer segredo ao operador.
 *
 * @return array{wallet_id:int,provider:string,api:string,webhook:string,ready:bool}
 */
function partner_central_test_partner_wallet_connection(PDO $pdo, int $partnerId): array
{
    $wallet=partner_central_active_partner_wallet($pdo,$partnerId,true);
    $api='ok';$webhook='ok';
    try{fs_wallet_assert_usable($wallet);fs_payment_validate_wallet_capabilities($wallet);}
    catch(Throwable $error){$api='attention';}
    try{fs_payment_webhook_self_test($wallet);}
    catch(Throwable $error){$webhook='attention';}
    return [
        'wallet_id'=>(int)$wallet['id'],
        'provider'=>(string)$wallet['provider'],
        'api'=>$api,
        'webhook'=>$webhook,
        'ready'=>$api==='ok'&&$webhook==='ok',
    ];
}

function partner_central_restore_partner_wallet(PDO $pdo, int $partnerId, int $walletId): int
{
    if(!fs_payment_wallet_validation_schema_ready($pdo))throw new RuntimeException('A restauração segura de carteira aguarda a migração 049.');
    $partner=partner_central_partner($pdo,$partnerId);
    if((int)($partner['independent_billing']??0)!==1)throw new RuntimeException('O recebimento deste estabelecimento é administrado pela FireSpot.');
    $wallet=fs_wallet_by_id($pdo,$walletId,true);
    if(!$wallet||(int)($wallet['partner_id']??0)!==$partnerId)throw new RuntimeException('Carteira histórica não encontrada.');
    if((int)($wallet['active']??0)===1)throw new RuntimeException('Esta carteira já está ativa.');
    fs_wallet_assert_usable($wallet);
    fs_payment_validate_wallet_capabilities($wallet);
    fs_payment_webhook_self_test($wallet);

    $pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT independent_billing FROM partners WHERE id=? FOR UPDATE');$lock->execute([$partnerId]);$current=$lock->fetch(PDO::FETCH_ASSOC);
        if(!$current||(int)$current['independent_billing']!==1)throw new RuntimeException('O modo de recebimento mudou durante a validação.');
        $target=$pdo->prepare('SELECT id,active FROM payment_wallets WHERE id=? AND partner_id=? FOR UPDATE');$target->execute([$walletId,$partnerId]);$target=$target->fetch(PDO::FETCH_ASSOC);
        if(!$target||(int)$target['active']===1)throw new RuntimeException('A situação da carteira histórica mudou. Recarregue a página.');
        $pdo->prepare('UPDATE payment_wallets SET active=0,updated_at=NOW() WHERE partner_id=? AND active=1')->execute([$partnerId]);
        $update=$pdo->prepare('UPDATE payment_wallets SET active=1,access_token_validated_at=NOW(),webhook_secret_validated_at=NOW(),updated_at=NOW() WHERE id=? AND partner_id=?');
        $update->execute([$walletId,$partnerId]);if($update->rowCount()!==1)throw new RuntimeException('A carteira histórica não pôde ser restaurada.');
        $pdo->prepare('UPDATE partners SET payment_wallet_id=?,updated_at=NOW() WHERE id=?')->execute([$walletId,$partnerId]);
        $pdo->commit();return $walletId;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function partner_central_save_plan(PDO $pdo, int $partnerId, array $data): string
{
    $id = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $priceCents = partner_central_price_cents($data['price'] ?? '0');
    $duration = (int) ($data['duration_minutes'] ?? 0);
    $down = max(0, (int) ($data['download_kbps'] ?? 0));
    $up = max(0, (int) ($data['upload_kbps'] ?? 0));
    $sort = max(0, min(65535, (int) ($data['sort_order'] ?? 100)));
    $active = partner_central_bool($data['active'] ?? null);
    if ($name === '' || $priceCents <= 0 || $duration <= 0) {
        throw new RuntimeException('Informe nome, valor e duração válidos para o plano.');
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        partner_central_partner($pdo, $partnerId);
        partner_central_lock_plans($pdo,$partnerId);
        if ($id > 0) {
            $current = $pdo->prepare('SELECT active FROM partner_payment_plans WHERE id=? AND partner_id=? LIMIT 1');
            $current->execute([$id, $partnerId]);
            $current = $current->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new RuntimeException('Opção de acesso não encontrada.');
            if ((int) $current['active'] === 1 && $active === 0) {
                partner_central_assert_plan_deactivation_allowed($pdo, $partnerId, $id);
            }
            $st = $pdo->prepare('UPDATE partner_payment_plans SET name=?,description=?,price_cents=?,duration_minutes=?,download_kbps=?,upload_kbps=?,sort_order=?,active=?,updated_at=NOW() WHERE id=? AND partner_id=?');
            $st->execute([$name, $description !== '' ? $description : null, $priceCents, $duration, $down, $up, $sort, $active, $id, $partnerId]);
            $message = 'Plano atualizado com sucesso.';
        } else {
            $st = $pdo->prepare('INSERT INTO partner_payment_plans (partner_id,name,description,price_cents,duration_minutes,download_kbps,upload_kbps,sort_order,active) VALUES (?,?,?,?,?,?,?,?,?)');
            $st->execute([$partnerId, $name, $description !== '' ? $description : null, $priceCents, $duration, $down, $up, $sort, $active]);
            $message = 'Plano adicionado com sucesso.';
        }
        if ($ownsTransaction) $pdo->commit();
        return $message;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function partner_central_toggle_plan(PDO $pdo, int $partnerId, int $planId): string
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        partner_central_partner($pdo,$partnerId);
        partner_central_lock_plans($pdo,$partnerId);
        $st = $pdo->prepare('SELECT active,price_cents,duration_minutes FROM partner_payment_plans WHERE id=? AND partner_id=? LIMIT 1');
        $st->execute([$planId, $partnerId]);
        $plan = $st->fetch(PDO::FETCH_ASSOC);
        if (!$plan) throw new RuntimeException('Opção de acesso não encontrada.');
        $newStatus = (int) $plan['active'] === 1 ? 0 : 1;
        if ($newStatus === 1 && ((int) $plan['price_cents'] <= 0 || (int) $plan['duration_minutes'] <= 0)) {
            throw new RuntimeException('Edite o plano e informe valor e duração válidos antes de ativá-lo.');
        }
        if ($newStatus === 0) partner_central_assert_plan_deactivation_allowed($pdo, $partnerId, $planId);
        $pdo->prepare('UPDATE partner_payment_plans SET active=?,updated_at=NOW() WHERE id=? AND partner_id=?')
            ->execute([$newStatus, $planId, $partnerId]);
        if ($ownsTransaction) $pdo->commit();
        return $newStatus === 1 ? 'Plano ativado.' : 'Plano desativado.';
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function partner_central_store_theme_logo(int $partnerId, array $upload): string
{
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return '';
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível receber o arquivo do logotipo.');
    if ((int) ($upload['size'] ?? 0) <= 0 || (int) $upload['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('O logotipo deve ter no máximo 2 MB.');
    }
    $temporary = (string) ($upload['tmp_name'] ?? '');
    if ($temporary === '' || !is_uploaded_file($temporary)) throw new RuntimeException('Upload de logotipo inválido.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) throw new RuntimeException('Use um logotipo PNG, JPG ou WEBP.');
    $dimensions = @getimagesize($temporary);
    if (!$dimensions || (int) $dimensions[0] < 1 || (int) $dimensions[1] < 1 || (int) $dimensions[0] > 4000 || (int) $dimensions[1] > 4000) {
        throw new RuntimeException('O logotipo precisa ser uma imagem válida de até 4000 × 4000 pixels.');
    }
    $directory = __DIR__ . '/../portal-v3/uploads/branding';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível preparar a pasta de logotipos.');
    }
    $filename = 'partner_' . $partnerId . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($temporary, $directory . '/' . $filename)) throw new RuntimeException('Não foi possível salvar o logotipo.');
    @chmod($directory . '/' . $filename, 0644);
    return 'portal-v3/uploads/branding/' . $filename;
}

function partner_central_delete_theme_logo(?string $path): void
{
    $file = portal_theme_logo_file($path);
    if ($file !== null && is_file($file)) @unlink($file);
}

function partner_central_save_theme(PDO $pdo, int $partnerId, array $data, array $files): array
{
    $partner = partner_central_partner($pdo, $partnerId);
    $preset = in_array($data['theme_preset'] ?? '', ['modern', 'compact_blue', 'compact_light'], true) ? (string) $data['theme_preset'] : 'modern';
    $mode = in_array($data['theme_mode'] ?? '', ['light', 'dark'], true) ? (string) $data['theme_mode'] : 'light';
    if ($preset === 'compact_blue') $mode = 'dark';
    if ($preset === 'compact_light') $mode = 'light';
    $current = portal_theme_get($pdo, $partner, company_get());
    $oldLight = (string) ($current['logo_light_path'] ?? '');
    $oldDark = (string) ($current['logo_dark_path'] ?? '');
    $newLight = $newDark = '';
    try {
        $newLight = partner_central_store_theme_logo($partnerId, $files['logo_light'] ?? []);
        $newDark = partner_central_store_theme_logo($partnerId, $files['logo_dark'] ?? []);
    } catch (Throwable $e) {
        if ($newLight !== '') partner_central_delete_theme_logo($newLight);
        throw $e;
    }
    $light = $newLight !== '' ? $newLight : (partner_central_bool($data['remove_logo_light'] ?? null) ? '' : $oldLight);
    $dark = $newDark !== '' ? $newDark : (partner_central_bool($data['remove_logo_dark'] ?? null) ? '' : $oldDark);
    try {
        portal_theme_save($pdo, $partnerId, [
            'theme_preset' => $preset,
            'show_title' => partner_central_bool($data['show_title'] ?? null),
            'theme_mode' => $mode,
            'primary_color' => partner_central_theme_color((string) ($data['primary_color'] ?? ''), 'a cor principal'),
            'secondary_color' => partner_central_theme_color((string) ($data['secondary_color'] ?? ''), 'a cor secundária'),
            'background_color' => partner_central_theme_color((string) ($data['background_color'] ?? ''), 'a cor de fundo'),
            'text_color' => partner_central_theme_color((string) ($data['text_color'] ?? ''), 'o texto principal'),
            'muted_text_color' => partner_central_theme_color((string) ($data['muted_text_color'] ?? ''), 'o texto secundário'),
            'hero_text_color' => partner_central_theme_color((string) ($data['hero_text_color'] ?? ''), 'o texto de destaque'),
            'button_text_color' => partner_central_theme_color((string) ($data['button_text_color'] ?? ''), 'o texto dos botões'),
            'footer_text_color' => partner_central_theme_color((string) ($data['footer_text_color'] ?? ''), 'o texto do rodapé'),
            'logo_light_path' => $light,
            'logo_dark_path' => $dark,
        ]);
    } catch (Throwable $e) {
        foreach (array_unique(array_filter([$newLight, $newDark])) as $path) partner_central_delete_theme_logo($path);
        throw $e;
    }
    $kept = array_unique(array_filter([$light, $dark]));
    foreach (array_unique(array_filter([$oldLight, $oldDark])) as $path) {
        if (!in_array($path, $kept, true)) partner_central_delete_theme_logo($path);
    }
    return ['logo_light_path' => $light, 'logo_dark_path' => $dark];
}
