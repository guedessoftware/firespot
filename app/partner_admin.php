<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/session_boot.php';
require_once __DIR__ . '/partner_purpose.php';
require_once __DIR__ . '/portal_configuration.php';
require_once __DIR__ . '/public_url.php';
require_once __DIR__ . '/partner_entitlements.php';

function partner_admin_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM partner_admin_memberships LIMIT 0');
        $pdo->query('SELECT auth_version FROM host_users LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function partner_admin_roles(): array
{
    return [
        'owner' => 'Proprietário',
        'manager' => 'Gerente',
        'finance' => 'Financeiro',
        'marketing' => 'Marketing',
        'viewer' => 'Leitura',
    ];
}

function partner_admin_capabilities(string $role): array
{
    $map = [
        'owner' => ['team.manage','theme.manage','plans.manage','hotspot_commercial.manage','wallet.manage','ads.manage','reports.view','reports.export','sales.view','finance.view','monetization.view','monetization.summary.view','ads.campaigns.view','ads.earnings.view','ads.leads.view','infrastructure.view','nas.manage','nas.prepare','nas.retire','hotspots.manage','courtesy.view','courtesy.manage','courtesy.override.manage','audit.view'],
        'manager' => ['theme.manage','plans.manage','hotspot_commercial.manage','wallet.manage','ads.manage','reports.view','sales.view','monetization.view','ads.campaigns.view','infrastructure.view','nas.manage','nas.prepare','nas.retire','hotspots.manage','courtesy.view','courtesy.manage','audit.view'],
        'finance' => ['wallet.manage','reports.view','reports.export','sales.view','finance.view','monetization.view','monetization.summary.view','ads.earnings.view','audit.view'],
        'marketing' => ['theme.manage','ads.manage','reports.view','monetization.view','ads.campaigns.view','ads.leads.view','audit.view'],
        'viewer' => ['reports.view','infrastructure.view','courtesy.view','audit.view'],
    ];
    return $map[$role] ?? [];
}

function partner_admin_role_has(string $role, string $capability): bool
{
    return in_array($capability, partner_admin_capabilities($role), true);
}

function partner_admin_normalize_email(string $email): string
{
    $email = trim($email);
    return function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);
}

function partner_admin_secret(): string
{
    $secret = trim((string)env('APP_KEY', env('PAYMENT_CREDENTIAL_KEY', '')));
    if (strlen($secret) < 32) {
        // Compatibilidade de implantação: não interrompe a conta já migrada
        // enquanto APP_KEY é configurada, mas deriva uma chave sem expor nem
        // usar diretamente a senha do banco como material HMAC.
        $material = (defined('DB_PASSWORD') ? (string)DB_PASSWORD : '') . "\0"
            . (defined('DB_DATABASE') ? (string)DB_DATABASE : '') . "\0"
            . __FILE__ . "\0" . php_uname('n');
        $secret = hash('sha256',$material);
    }
    return $secret;
}

function partner_admin_base_url(?PDO $pdo = null): string
{
    try {
        return fs_public_base_url($pdo);
    } catch (Throwable $e) {
        return '';
    }
}

function partner_admin_origin_hash(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return hash_hmac('sha256', $ip, partner_admin_secret());
}

function partner_admin_clean_metadata(array $metadata): array
{
    $clean = [];
    foreach ($metadata as $key => $value) {
        $normalized = strtolower((string)$key);
        if (preg_match('/password|secret|token|credential|access[_-]?key|authorization/', $normalized)) continue;
        if (is_array($value)) $value = partner_admin_clean_metadata($value);
        if (is_string($value)) $value = substr($value, 0, 500);
        $clean[$key] = $value;
    }
    return $clean;
}

/**
 * Mensagens de domínio podem ser apresentadas ao usuário. Erros de banco e
 * falhas inesperadas ficam somente no log para não expor estrutura interna.
 */
function partner_admin_public_error(Throwable $error, string $fallback = 'Não foi possível concluir a operação. Tente novamente.'): string
{
    if ($error instanceof PDOException) {
        error_log('partner_admin: ' . $error->getMessage());
        return $fallback;
    }
    if ($error instanceof InvalidArgumentException || $error instanceof RuntimeException) {
        $message = trim($error->getMessage());
        return $message !== '' ? substr($message, 0, 300) : $fallback;
    }
    error_log('partner_admin: ' . get_class($error) . ': ' . $error->getMessage());
    return $fallback;
}

function partner_admin_audit(PDO $pdo, int $partnerId, string $actorType, ?int $actorId, string $action, string $targetType, $targetId = null, array $metadata = []): void
{
    if ($partnerId <= 0) return;
    $actorType = in_array($actorType, ['firespot','partner_admin','system'], true) ? $actorType : 'system';
    $encoded = $metadata ? json_encode(partner_admin_clean_metadata($metadata), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    $st = $pdo->prepare('INSERT INTO partner_admin_audit (partner_id,actor_type,actor_id,action,target_type,target_id,metadata,origin_hash) VALUES (?,?,?,?,?,?,?,?)');
    $st->execute([$partnerId,$actorType,$actorId,substr($action,0,80),substr($targetType,0,64),$targetId === null ? null : substr((string)$targetId,0,64),$encoded,partner_admin_origin_hash()]);
}

function partner_admin_memberships_for_user(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare('SELECT m.id membership_id,m.user_id,m.partner_id,m.role,m.active membership_active,p.*
        FROM partner_admin_memberships m JOIN partners p ON p.id=m.partner_id
        WHERE m.user_id=? AND m.active=1 AND p.active=1 ORDER BY p.name,p.id');
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function partner_admin_members(PDO $pdo, int $partnerId): array
{
    $st = $pdo->prepare('SELECT m.id membership_id,m.partner_id,m.role,m.active membership_active,m.created_at membership_created_at,
            u.id user_id,u.name,u.email,u.active user_active,u.last_login_at,u.auth_version,
            (SELECT COUNT(*) FROM partner_admin_memberships x WHERE x.user_id=u.id AND x.active=1) active_partner_count
        FROM partner_admin_memberships m JOIN host_users u ON u.id=m.user_id
        WHERE m.partner_id=? ORDER BY m.active DESC,(m.role=\'owner\') DESC,u.name,u.email');
    $st->execute([$partnerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function partner_admin_pending_invitations(PDO $pdo, int $partnerId): array
{
    $st = $pdo->prepare('SELECT id,email,role,expires_at,created_at FROM partner_admin_invitations
        WHERE partner_id=? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at>NOW() ORDER BY created_at DESC');
    $st->execute([$partnerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function partner_admin_invite(PDO $pdo, int $partnerId, string $email, string $role, string $actorType, int $actorId): array
{
    $email = partner_admin_normalize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Informe um e-mail válido.');
    if (!array_key_exists($role, partner_admin_roles())) throw new InvalidArgumentException('Papel inválido.');
    $partner = $pdo->prepare('SELECT id,name FROM partners WHERE id=? AND active=1 LIMIT 1');
    $partner->execute([$partnerId]);
    $partner = $partner->fetch(PDO::FETCH_ASSOC);
    if (!$partner) throw new RuntimeException('Estabelecimento não encontrado ou inativo.');

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT active FROM partners WHERE id=? LIMIT 1 FOR UPDATE');
        $lock->execute([$partnerId]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Estabelecimento não encontrado ou inativo.');
        $existing = $pdo->prepare('SELECT m.id FROM partner_admin_memberships m
            JOIN host_users u ON u.id=m.user_id WHERE m.partner_id=? AND u.email=? LIMIT 1 FOR UPDATE');
        $existing->execute([$partnerId,$email]);
        if ($existing->fetchColumn()) throw new RuntimeException('Este e-mail já possui vínculo com o estabelecimento. Altere o vínculo existente pela equipe.');
        $pdo->prepare('UPDATE partner_admin_invitations SET revoked_at=NOW(),updated_at=NOW()
            WHERE partner_id=? AND email=? AND accepted_at IS NULL AND revoked_at IS NULL')->execute([$partnerId,$email]);
        // A mesma linha de partner serializa convites e reativações. A
        // checagem dentro da transação impede ultrapassar a cota em dois POSTs
        // concorrentes; reemitir revoga antes o convite anterior equivalente.
        if($actorType==='partner_admin')fs_partner_require_quota($pdo,$partnerId,'max_admin_users',1);
        $token = bin2hex(random_bytes(32));
        $st = $pdo->prepare('INSERT INTO partner_admin_invitations (partner_id,email,role,token_hash,expires_at,created_by_type,created_by_id)
            VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 48 HOUR),?,?)');
        $st->execute([$partnerId,$email,$role,hash('sha256',$token),$actorType,$actorId]);
        $id = (int)$pdo->lastInsertId();
        partner_admin_audit($pdo,$partnerId,$actorType,$actorId,'invitation.created','invitation',$id,['role'=>$role]);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $base = partner_admin_base_url($pdo);
    return ['id'=>$id,'token'=>$token,'url'=>$base . '/admin/convite.php?token=' . rawurlencode($token),'partner'=>$partner];
}

function partner_admin_revoke_invitation(PDO $pdo, int $partnerId, int $invitationId, string $actorType, int $actorId): void
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE partner_admin_invitations SET revoked_at=NOW(),updated_at=NOW()
            WHERE id=? AND partner_id=? AND accepted_at IS NULL AND revoked_at IS NULL');
        $st->execute([$invitationId,$partnerId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Convite pendente não encontrado.');
        partner_admin_audit($pdo,$partnerId,$actorType,$actorId,'invitation.revoked','invitation',$invitationId);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function partner_admin_invitation(PDO $pdo, string $token, bool $forUpdate = false): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) return null;
    $sql = 'SELECT i.*,p.name partner_name,p.active partner_active,u.id existing_user_id,u.active existing_user_active
        FROM partner_admin_invitations i JOIN partners p ON p.id=i.partner_id
        LEFT JOIN host_users u ON u.email=i.email
        WHERE i.token_hash=? AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at>NOW() LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $st = $pdo->prepare($sql);
    $st->execute([hash('sha256',strtolower($token))]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function partner_admin_accept_invitation(PDO $pdo, string $token, string $name, string $password): array
{
    $pdo->beginTransaction();
    try {
        $invitation = partner_admin_invitation($pdo,$token,true);
        if (!$invitation || (int)$invitation['partner_active'] !== 1) throw new RuntimeException('Convite inválido, expirado ou revogado.');
        if ((string)$invitation['created_by_type'] === 'partner_admin') {
            // O convite reserva uma vaga enquanto está pendente. Revalida o
            // contrato no aceite para impedir que uma URL antiga atravesse um
            // downgrade, suspensão ou redução posterior da cota.
            fs_partner_require_entitlement($pdo,(int)$invitation['partner_id'],'team.manage',true);
            fs_partner_require_quota($pdo,(int)$invitation['partner_id'],'max_admin_users',0);
        }
        $userId = (int)($invitation['existing_user_id'] ?? 0);
        if ($userId <= 0) {
            $name = trim($name);
            if ($name === '') throw new RuntimeException('Informe seu nome.');
            if (strlen($password) < 12) throw new RuntimeException('A senha deve ter pelo menos 12 caracteres.');
            $st = $pdo->prepare('INSERT INTO host_users (email,password_hash,partner_code,name,active,auth_version,created_at,updated_at) VALUES (?,?,NULL,?,1,1,NOW(),NOW())');
            $st->execute([$invitation['email'],password_hash($password,PASSWORD_DEFAULT),$name]);
            $userId = (int)$pdo->lastInsertId();
        } elseif ((int)$invitation['existing_user_active'] !== 1) {
            throw new RuntimeException('Esta identidade está desativada. Contate a FireSpot.');
        }
        $existingMembership = $pdo->prepare('SELECT id FROM partner_admin_memberships WHERE user_id=? AND partner_id=? LIMIT 1 FOR UPDATE');
        $existingMembership->execute([$userId,(int)$invitation['partner_id']]);
        if ($existingMembership->fetchColumn()) {
            throw new RuntimeException('Esta identidade já possui vínculo com o estabelecimento.');
        }
        $st = $pdo->prepare('INSERT INTO partner_admin_memberships (user_id,partner_id,role,active,created_by_type,created_by_id)
            VALUES (?,?,?,1,?,?)');
        $st->execute([$userId,(int)$invitation['partner_id'],$invitation['role'],$invitation['created_by_type'],(int)$invitation['created_by_id']]);
        $pdo->prepare('UPDATE partner_admin_invitations SET accepted_by_user_id=?,accepted_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$userId,(int)$invitation['id']]);
        partner_admin_audit($pdo,(int)$invitation['partner_id'],'partner_admin',$userId,'invitation.accepted','invitation',(int)$invitation['id'],['role'=>$invitation['role']]);
        $pdo->commit();
        return ['user_id'=>$userId,'partner_id'=>(int)$invitation['partner_id'],'partner_name'=>$invitation['partner_name']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function partner_admin_active_owner_count(PDO $pdo, int $partnerId, int $exceptMembershipId = 0): int
{
    $sql = "SELECT COUNT(*) FROM partner_admin_memberships m JOIN host_users u ON u.id=m.user_id WHERE m.partner_id=? AND m.role='owner' AND m.active=1 AND u.active=1";
    $params = [$partnerId];
    if ($exceptMembershipId > 0) { $sql .= ' AND m.id<>?'; $params[] = $exceptMembershipId; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/**
 * Bloqueia as linhas dos proprietários durante alterações destrutivas. Isso
 * evita que duas requisições concorrentes removam, ao mesmo tempo, os dois
 * últimos proprietários que cada uma ainda conseguia enxergar.
 */
function partner_admin_lock_active_owner_ids(PDO $pdo, int $partnerId): array
{
    $st = $pdo->prepare("SELECT m.id FROM partner_admin_memberships m JOIN host_users u ON u.id=m.user_id
        WHERE m.partner_id=? AND m.role='owner' AND m.active=1 AND u.active=1 ORDER BY m.id FOR UPDATE");
    $st->execute([$partnerId]);
    return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function partner_admin_update_membership(PDO $pdo, int $partnerId, int $membershipId, string $role, int $active, string $actorType, int $actorId): void
{
    if (!array_key_exists($role, partner_admin_roles())) throw new InvalidArgumentException('Papel inválido.');
    $pdo->beginTransaction();
    try {
        $partnerLock=$pdo->prepare('SELECT id FROM partners WHERE id=? LIMIT 1 FOR UPDATE');$partnerLock->execute([$partnerId]);
        if(!$partnerLock->fetchColumn())throw new RuntimeException('Estabelecimento não encontrado.');
        $st = $pdo->prepare('SELECT m.*,u.id user_id FROM partner_admin_memberships m JOIN host_users u ON u.id=m.user_id WHERE m.id=? AND m.partner_id=? LIMIT 1 FOR UPDATE');
        $st->execute([$membershipId,$partnerId]);
        $current = $st->fetch(PDO::FETCH_ASSOC);
        if (!$current) throw new RuntimeException('Vínculo não encontrado.');
        if($actorType==='partner_admin'&&(int)$current['active']!==1&&$active===1)fs_partner_require_quota($pdo,$partnerId,'max_admin_users',1);
        if ($current['role'] === 'owner' && (int)$current['active'] === 1 && ($role !== 'owner' || $active !== 1)) {
            $otherOwners = array_values(array_diff(partner_admin_lock_active_owner_ids($pdo,$partnerId),[$membershipId]));
            if (!$otherOwners) throw new RuntimeException('O último proprietário ativo não pode ser removido ou rebaixado.');
        }
        $pdo->prepare('UPDATE partner_admin_memberships SET role=?,active=?,updated_at=NOW() WHERE id=? AND partner_id=?')->execute([$role,$active===1?1:0,$membershipId,$partnerId]);
        if ($active !== 1) $pdo->prepare('UPDATE host_users SET auth_version=auth_version+1,updated_at=NOW() WHERE id=?')->execute([(int)$current['user_id']]);
        $action = (int)$current['active'] !== $active ? ($active ? 'membership.activated' : 'membership.deactivated') : 'membership.role_changed';
        partner_admin_audit($pdo,$partnerId,$actorType,$actorId,$action,'membership',$membershipId,['from_role'=>$current['role'],'to_role'=>$role]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function partner_admin_revoke_user_sessions(PDO $pdo, int $partnerId, int $userId, string $actorType, int $actorId): void
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT 1 FROM partner_admin_memberships WHERE partner_id=? AND user_id=? LIMIT 1 FOR UPDATE');
        $st->execute([$partnerId,$userId]);
        if (!$st->fetchColumn()) throw new RuntimeException('Usuário não pertence a este estabelecimento.');
        $pdo->prepare('UPDATE host_users SET auth_version=auth_version+1,updated_at=NOW() WHERE id=?')->execute([$userId]);
        partner_admin_audit($pdo,$partnerId,$actorType,$actorId,'sessions.revoked','user',$userId);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function partner_admin_set_user_active(PDO $pdo, int $userId, int $active, string $actorType, int $actorId, ?int $requiredPartnerId = null): void
{
    $active = $active === 1 ? 1 : 0;
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT id,active FROM host_users WHERE id=? LIMIT 1 FOR UPDATE');$st->execute([$userId]);$user=$st->fetch(PDO::FETCH_ASSOC);
        if(!$user)throw new RuntimeException('Usuário não encontrado.');
        $st=$pdo->prepare('SELECT id,partner_id,role,active FROM partner_admin_memberships WHERE user_id=? ORDER BY partner_id,id FOR UPDATE');$st->execute([$userId]);$memberships=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
        if($requiredPartnerId!==null&&!array_filter($memberships,static fn($membership)=>(int)$membership['partner_id']===$requiredPartnerId))throw new RuntimeException('Usuário não pertence a este estabelecimento.');
        if($active===0){foreach($memberships as $membership){if($membership['role']==='owner'&&(int)$membership['active']===1){$otherOwners=array_values(array_diff(partner_admin_lock_active_owner_ids($pdo,(int)$membership['partner_id']),[(int)$membership['id']]));if(!$otherOwners)throw new RuntimeException('Este usuário é o último proprietário ativo de um estabelecimento. Transfira a propriedade antes de desativá-lo.');}}}
        $pdo->prepare('UPDATE host_users SET active=?,auth_version=auth_version+1,updated_at=NOW() WHERE id=?')->execute([$active,$userId]);
        foreach($memberships as $membership)partner_admin_audit($pdo,(int)$membership['partner_id'],$actorType,$actorId,$active?'user.activated':'user.deactivated','user',$userId);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function partner_admin_attempt_scope(string $email): array
{
    return partner_admin_rate_pair('login',$email);
}

function partner_admin_rate_pair(string $namespace, string $email): array
{
    $secret = partner_admin_secret();
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $identity = partner_admin_normalize_email($email);
    return [
        hash_hmac('sha256',$namespace . "\0origin\0" . $ip,$secret),
        hash_hmac('sha256',$namespace . "\0identity\0" . $identity,$secret),
    ];
}

function partner_admin_rate_scopes(string $namespace, string $email): array
{
    [$origin,$identity] = partner_admin_rate_pair($namespace,$email);
    $secret = partner_admin_secret();
    return [
        [$origin,$identity,'pair'],
        [$origin,hash_hmac('sha256',$namespace . "\0any-identity",$secret),'origin'],
        [hash_hmac('sha256',$namespace . "\0any-origin",$secret),$identity,'identity'],
    ];
}

function partner_admin_rate_policy(string $namespace): array
{
    if ($namespace === 'password_reset') {
        return [
            'pair' => [3,60,60],
            'origin' => [10,60,60],
            'identity' => [5,60,60],
        ];
    }
    return [
        'pair' => [5,15,15],
        'origin' => [25,15,15],
        'identity' => [10,30,30],
    ];
}

function partner_admin_rate_blocked(PDO $pdo, string $namespace, string $email): bool
{
    $st = $pdo->prepare('SELECT blocked_until FROM partner_admin_login_attempts
        WHERE origin_hash=? AND identity_hash=? AND expires_at>NOW() LIMIT 1');
    foreach (partner_admin_rate_scopes($namespace,$email) as [$origin,$identity]) {
        $st->execute([$origin,$identity]);
        $blocked = $st->fetchColumn();
        $st->closeCursor();
        if ($blocked !== false && $blocked !== null && strtotime((string)$blocked) > time()) return true;
    }
    return false;
}

function partner_admin_rate_hit(PDO $pdo, string $namespace, string $email): void
{
    $policies = partner_admin_rate_policy($namespace);
    foreach (partner_admin_rate_scopes($namespace,$email) as [$origin,$identity,$scope]) {
        [$limit,$windowMinutes,$blockMinutes] = $policies[$scope];
        $limit = max(2,(int)$limit);
        $windowMinutes = max(1,(int)$windowMinutes);
        $blockMinutes = max(1,(int)$blockMinutes);
        $sql = "INSERT INTO partner_admin_login_attempts (origin_hash,identity_hash,window_started_at,attempt_count,blocked_until,expires_at)
            VALUES (?,?,NOW(),1,NULL,DATE_ADD(NOW(),INTERVAL 2 DAY))
            ON DUPLICATE KEY UPDATE
              blocked_until=IF(window_started_at<DATE_SUB(NOW(),INTERVAL {$windowMinutes} MINUTE),NULL,
                IF(attempt_count+1>={$limit},DATE_ADD(NOW(),INTERVAL {$blockMinutes} MINUTE),blocked_until)),
              attempt_count=IF(window_started_at<DATE_SUB(NOW(),INTERVAL {$windowMinutes} MINUTE),1,LEAST(65535,attempt_count+1)),
              window_started_at=IF(window_started_at<DATE_SUB(NOW(),INTERVAL {$windowMinutes} MINUTE),NOW(),window_started_at),
              expires_at=DATE_ADD(NOW(),INTERVAL 2 DAY),updated_at=NOW()";
        $pdo->prepare($sql)->execute([$origin,$identity]);
    }
}

function partner_admin_login_blocked(PDO $pdo, string $email): bool
{
    return partner_admin_rate_blocked($pdo,'login',$email);
}

function partner_admin_login_failed(PDO $pdo, string $email): void
{
    partner_admin_rate_hit($pdo,'login',$email);
}

function partner_admin_login_succeeded(PDO $pdo, string $email): void
{
    [$origin,$identity] = partner_admin_attempt_scope($email);
    // Limpa somente o par que autenticou. Os limites agregados por origem e
    // identidade continuam impedindo pulverização de tentativas.
    $pdo->prepare('DELETE FROM partner_admin_login_attempts WHERE origin_hash=? AND identity_hash=?')->execute([$origin,$identity]);
}

function partner_admin_session_clear(bool $regenerate = true): void
{
    foreach (array_keys($_SESSION) as $key) {
        if (strpos((string)$key, 'host_') === 0 || strpos((string)$key, 'partner_admin_') === 0) unset($_SESSION[$key]);
    }
    if ($regenerate && session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
}

function partner_admin_select_membership(PDO $pdo, int $userId, int $membershipId): array
{
    $st = $pdo->prepare('SELECT m.id membership_id,m.user_id,m.partner_id,m.role,m.active membership_active,
            u.email,u.name,u.active user_active,u.auth_version,p.*
        FROM partner_admin_memberships m JOIN host_users u ON u.id=m.user_id JOIN partners p ON p.id=m.partner_id
        WHERE m.id=? AND m.user_id=? AND m.active=1 AND u.active=1 AND p.active=1 LIMIT 1');
    $st->execute([$membershipId,$userId]);
    $context = $st->fetch(PDO::FETCH_ASSOC);
    if (!$context) throw new RuntimeException('Vínculo indisponível.');
    $expectedVersion = (int)($_SESSION['host_auth_version'] ?? 0);
    if ($expectedVersion <= 0 || $expectedVersion !== (int)$context['auth_version']) {
        partner_admin_session_clear();
        throw new RuntimeException('Sua autenticação expirou. Entre novamente.');
    }
    $_SESSION['host_user_id'] = $userId;
    $_SESSION['host_membership_id'] = (int)$context['membership_id'];
    $_SESSION['host_partner_id'] = (int)$context['partner_id'];
    $_SESSION['host_role'] = (string)$context['role'];
    $_SESSION['host_auth_version'] = (int)$context['auth_version'];
    unset($_SESSION['host_partner_code'],$_SESSION['partner_admin_pending_user_id']);
    return $context;
}

function partner_admin_authenticate(PDO $pdo, string $email, string $password): array
{
    $email = partner_admin_normalize_email($email);
    $generic = 'E-mail ou senha inválidos.';
    if (partner_admin_login_blocked($pdo,$email)) throw new RuntimeException('Não foi possível entrar agora. Aguarde alguns minutos e tente novamente.');
    $st = $pdo->prepare('SELECT id,email,password_hash,active,auth_version FROM host_users WHERE email=? LIMIT 1');
    $st->execute([$email]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    $valid = $user && (int)$user['active'] === 1 && password_verify($password,(string)$user['password_hash']);
    $memberships = $valid ? partner_admin_memberships_for_user($pdo,(int)$user['id']) : [];
    if (!$valid || !$memberships) {
        partner_admin_login_failed($pdo,$email);
        password_verify($password, '$2y$10$3euPcmQFCiblsZeEu5s7p.9qk7.LZIRNYo09vklDnz2qBlYuh5dvi');
        throw new RuntimeException($generic);
    }
    partner_admin_login_succeeded($pdo,$email);
    session_regenerate_id(true);
    $_SESSION['partner_admin_pending_user_id'] = (int)$user['id'];
    $_SESSION['host_auth_version'] = (int)$user['auth_version'];
    $pdo->prepare('UPDATE host_users SET last_login_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$user['id']]);
    if (count($memberships) === 1) partner_admin_select_membership($pdo,(int)$user['id'],(int)$memberships[0]['membership_id']);
    return ['user'=>$user,'memberships'=>$memberships];
}

function partner_admin_context(PDO $pdo): ?array
{
    $userId = (int)($_SESSION['host_user_id'] ?? 0);
    $membershipId = (int)($_SESSION['host_membership_id'] ?? 0);
    if ($userId <= 0 || $membershipId <= 0) return null;
    $st = $pdo->prepare('SELECT m.id membership_id,m.user_id,m.partner_id,m.role,m.active membership_active,
            u.email,u.name user_name,u.active user_active,u.auth_version,p.*
        FROM partner_admin_memberships m JOIN host_users u ON u.id=m.user_id JOIN partners p ON p.id=m.partner_id
        WHERE m.id=? AND m.user_id=? LIMIT 1');
    $st->execute([$membershipId,$userId]);
    $context = $st->fetch(PDO::FETCH_ASSOC);
    if (!$context || (int)$context['membership_active'] !== 1 || (int)$context['user_active'] !== 1 || (int)$context['active'] !== 1
        || (int)$context['auth_version'] !== (int)($_SESSION['host_auth_version'] ?? 0)) {
        partner_admin_session_clear();
        return null;
    }
    return $context;
}

function partner_admin_require_context(PDO $pdo, ?string $capability = null, ?string $module = null): array
{
    $context = partner_admin_context($pdo);
    if (!$context) {
        if (!headers_sent()) header('Location: /admin/?expired=1');
        exit;
    }
    if ((int)($context['self_service_enabled'] ?? 0) !== 1 || !fs_portal_config_schema_ready($pdo) || !fs_portal_config_get($pdo,(int)$context['partner_id'],'published')) {
        if ($module !== 'activation') throw new RuntimeException('O painel de autogestão ainda não foi ativado pela FireSpot para este estabelecimento.');
    }
    if ($capability !== null && !partner_admin_role_has((string)$context['role'],$capability)) throw new RuntimeException('Você não possui permissão para esta ação.');
    if ($module !== null && $module !== 'activation' && !fs_portal_config_module_allowed($pdo,$context,$module)) throw new RuntimeException('Este módulo não está disponível para a configuração do estabelecimento.');
    return $context;
}

function partner_admin_require_feature_context(PDO $pdo, string $capability, string $module, string $feature, bool $mutation=true): array
{
    $context=partner_admin_require_context($pdo,$capability,$module);
    fs_partner_require_entitlement($pdo,(int)$context['partner_id'],$feature,$mutation);
    return $context;
}

function partner_admin_require_current_password(PDO $pdo, array $context, string $password): void
{
    $email=partner_admin_normalize_email((string)($context['email']??''));
    if ($email===''||partner_admin_rate_blocked($pdo,'sensitive_reauth',$email)) {
        throw new RuntimeException('A confirmação de identidade está temporariamente bloqueada. Aguarde alguns minutos.');
    }
    $statement=$pdo->prepare('SELECT password_hash,active FROM host_users WHERE id=? LIMIT 1');
    $statement->execute([(int)($context['user_id']??0)]);$user=$statement->fetch(PDO::FETCH_ASSOC);
    $valid=$user&&(int)$user['active']===1&&password_verify($password,(string)$user['password_hash']);
    if(!$valid){partner_admin_rate_hit($pdo,'sensitive_reauth',$email);password_verify($password,'$2y$10$3euPcmQFCiblsZeEu5s7p.9qk7.LZIRNYo09vklDnz2qBlYuh5dvi');throw new RuntimeException('A senha atual não confere.');}
    [$origin,$identity]=partner_admin_rate_pair('sensitive_reauth',$email);
    $pdo->prepare('DELETE FROM partner_admin_login_attempts WHERE origin_hash=? AND identity_hash=?')->execute([$origin,$identity]);
    $_SESSION['host_sensitive_reauth_at']=time();
}

function partner_admin_password_reset_enabled(?PDO $pdo = null): bool
{
    $from = trim((string)env('HOST_MAIL_FROM', ''));
    return filter_var(env('HOST_PASSWORD_RESET_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
        && filter_var($from,FILTER_VALIDATE_EMAIL)
        && partner_admin_base_url($pdo) !== '';
}

function partner_admin_password_reset_request(PDO $pdo, string $email): void
{
    if (!partner_admin_password_reset_enabled($pdo)) return;
    $email = partner_admin_normalize_email($email);
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) return;
    if (partner_admin_rate_blocked($pdo,'password_reset',$email)) return;
    partner_admin_rate_hit($pdo,'password_reset',$email);
    $st = $pdo->prepare('SELECT id FROM host_users WHERE email=? AND active=1 LIMIT 1');
    $st->execute([$email]);
    $userId = (int)($st->fetchColumn() ?: 0);
    if ($userId <= 0) return;
    $pdo->prepare('UPDATE host_password_resets SET revoked_at=NOW() WHERE user_id=? AND used_at IS NULL AND revoked_at IS NULL')->execute([$userId]);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO host_password_resets (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([$userId,hash('sha256',$token)]);
    $base = partner_admin_base_url($pdo);
    if ($base === '') return;
    $link = $base . '/admin/redefinir.php?token=' . rawurlencode($token);
    $from = trim((string)env('HOST_MAIL_FROM',''));
    $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8";
    if (!@mail($email,'Redefinição de senha FireSpot',"Use o link abaixo em até 30 minutos:\n\n{$link}\n",$headers)) {
        $pdo->prepare('UPDATE host_password_resets SET revoked_at=NOW() WHERE token_hash=?')->execute([hash('sha256',$token)]);
    }
}

function partner_admin_password_reset_token(PDO $pdo, string $token, bool $forUpdate = false): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/i',$token)) return null;
    $sql = 'SELECT r.id reset_id,r.user_id,u.email FROM host_password_resets r JOIN host_users u ON u.id=r.user_id
        WHERE r.token_hash=? AND r.expires_at>NOW() AND r.used_at IS NULL AND r.revoked_at IS NULL AND u.active=1 LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $st = $pdo->prepare($sql);
    $st->execute([hash('sha256',strtolower($token))]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function partner_admin_password_reset(PDO $pdo, string $token, string $password): void
{
    if (strlen($password) < 12) throw new RuntimeException('A senha deve ter pelo menos 12 caracteres.');
    $pdo->beginTransaction();
    try {
        $reset = partner_admin_password_reset_token($pdo,$token,true);
        if (!$reset) throw new RuntimeException('Link inválido ou expirado.');
        $used = $pdo->prepare('UPDATE host_password_resets SET used_at=NOW() WHERE id=? AND used_at IS NULL AND revoked_at IS NULL');
        $used->execute([(int)$reset['reset_id']]);
        if ($used->rowCount() !== 1) throw new RuntimeException('Link inválido ou já utilizado.');
        $pdo->prepare('UPDATE host_password_resets SET revoked_at=NOW() WHERE user_id=? AND id<>? AND used_at IS NULL AND revoked_at IS NULL')->execute([(int)$reset['user_id'],(int)$reset['reset_id']]);
        $pdo->prepare('UPDATE host_users SET password_hash=?,auth_version=auth_version+1,updated_at=NOW() WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),(int)$reset['user_id']]);
        $st = $pdo->prepare('SELECT partner_id FROM partner_admin_memberships WHERE user_id=?');
        $st->execute([(int)$reset['user_id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $partnerId) partner_admin_audit($pdo,(int)$partnerId,'partner_admin',(int)$reset['user_id'],'password.reset','user',(int)$reset['user_id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
