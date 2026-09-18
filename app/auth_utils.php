<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** Ensure default plan exists on user (no removal) */
/** garante que o usuário tenha o Plano_Padrao (não remove outros) */
function ensure_default_plan(PDO $pdo, string $username, string $group='Plano_Padrao', int $priority=10): void {
    if ($username==='') return;
    $st=$pdo->prepare("SELECT 1 FROM firespot.radusergroup WHERE username=? AND groupname=? LIMIT 1");
    $st->execute([$username,$group]);
    if ($st->fetchColumn()) return;

    insert_radusergroup_safe($pdo, $username, $group, $priority);
}


function user_primary_group(PDO $pdo, string $username): string {
    if ($username==='') return '';
    $st=$pdo->prepare("SELECT groupname FROM firespot.radusergroup WHERE username=? ORDER BY COALESCE(priority,999999) ASC, groupname ASC LIMIT 1");
    $st->execute([$username]);
    $g=$st->fetchColumn();
    return $g ? (string)$g : '';
}
function has_session_started_today(PDO $pdo, string $username, DateTimeZone $tz): bool {
    if ($username==='') return false;
    $s=new DateTime('today',$tz); $e=(clone $s)->setTime(23,59,59);
    $st=$pdo->prepare("SELECT 1 FROM firespot.radacct WHERE username=? AND acctstarttime BETWEEN ? AND ? LIMIT 1");
    $st->execute([$username, $s->format('Y-m-d H:i:s'), $e->format('Y-m-d H:i:s')]);
    return (bool)$st->fetchColumn();
}
function enforce_daily_single_login_if_default(PDO $pdo, string $username, DateTimeZone $tz, string $defaultGroup='Plano_Padrao'): array {
    $primary=user_primary_group($pdo,$username);
    if ($primary!==$defaultGroup) return ['ok'=>true,'primary'=>$primary];
    if (has_session_started_today($pdo,$username,$tz)){
        $retry=(new DateTime('tomorrow',$tz))->setTime(0,0,1)->format('Y-m-d H:i:s');
        return ['ok'=>false,'message'=>'Você já utilizou seu acesso diário do Plano Padrão. Tente novamente amanhã.','retry_at'=>$retry,'primary'=>$primary];
    }
    return ['ok'=>true,'primary'=>$primary];
}

function get_active_premium(PDO $pdo, string $username): ?array {
    $st=$pdo->prepare("SELECT id,groupname,expires_at FROM firespot.premium_access WHERE username=? AND status='active' AND expires_at>NOW() ORDER BY expires_at DESC LIMIT 1");
    $st->execute([$username]); $row=$st->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}
function reconcile_premium_group(PDO $pdo, string $username): void {
    $active=get_active_premium($pdo,$username);
    if($active){
        $pdo->prepare("DELETE FROM firespot.radusergroup WHERE username=? AND groupname=?")->execute([$username,$active['groupname']]);
        $pdo->prepare("INSERT INTO firespot.radusergroup (username,groupname,priority) VALUES (?,?,?)")->execute([$username,$active['groupname'],2]);
    }else{
        $pdo->prepare("DELETE FROM firespot.radusergroup WHERE username=? AND groupname IN ('PREMIUM_DAY')")->execute([$username]);
    }
}
function grant_premium_day(PDO $pdo, string $username, ?string $paymentRef=null, string $group='PREMIUM_DAY'): void {
    if($username==='') return;
    $pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE firespot.premium_access SET status='expired' WHERE username=? AND status='active' AND expires_at<=NOW()")->execute([$username]);
        $pdo->prepare("INSERT INTO firespot.premium_access (username,groupname,expires_at,status,payment_ref) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 1 DAY),'active',?)")->execute([$username,$group,$paymentRef]);
        $pdo->commit();
    }catch(\Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

// ... (demais funções permanecem iguais)

/** tenta inserir na radusergroup; se id não tiver default, usa MAX(id)+1 */
function insert_radusergroup_safe(PDO $pdo, string $username, string $group, int $priority): void {
    // 1) tenta o caminho padrão
    try {
        $stmt = $pdo->prepare("
            INSERT INTO firespot.radusergroup (username, groupname, priority)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$username, $group, $priority]);
        return;
    } catch (\PDOException $e) {
        // 1364 = Field 'id' doesn't have a default value
        if ($e->errorInfo[1] !== 1364) {
            throw $e; // outro erro → propaga
        }
    }

    // 2) sem AUTO_INCREMENT: calcula próximo id
    $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM firespot.radusergroup")->fetchColumn();

    $stmt = $pdo->prepare("
        INSERT INTO firespot.radusergroup (id, username, groupname, priority)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$nextId, $username, $group, $priority]);
}

// ... mantenha o que você já tem neste arquivo e APENAS adicione o trecho abaixo

/** Upsert de senha em radcheck (Cleartext-Password) para o usuário (CPF) */
function upsert_radcheck_password(PDO $pdo, string $username, string $clearPassword): void {
    if ($username === '' || $clearPassword === '') return;
    $pdo->prepare("DELETE FROM firespot.radcheck WHERE username=? AND attribute='Cleartext-Password'")
        ->execute([$username]);
    $pdo->prepare("INSERT INTO firespot.radcheck (username, attribute, op, value) VALUES (?,?,':=',?)")
        ->execute([$username, 'Cleartext-Password', $clearPassword]);
}

/** Garante grupo ilimitado (ISP_UNL) quando for cliente ativo */
function ensure_isp_unlimited(PDO $pdo, string $username, bool $isHubsoftActive): void {
    if (!$isHubsoftActive || $username === '') return;

    // Remove duplicados
    $pdo->prepare("DELETE FROM firespot.radusergroup WHERE username=? AND groupname='ISP_UNL'")
        ->execute([$username]);

    // Insere com prioridade 1 (primário)
    try {
        $pdo->prepare("INSERT INTO firespot.radusergroup (username, groupname, priority) VALUES (?,?,?)")
            ->execute([$username, 'ISP_UNL', 1]);
    } catch (\PDOException $e) {
        // Caso 'id' não seja AUTO_INCREMENT, tenta inserir com MAX(id)+1 (fallback)
        if (($e->errorInfo[1] ?? 0) == 1364) {
            $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM firespot.radusergroup")->fetchColumn();
            $pdo->prepare("INSERT INTO firespot.radusergroup (id, username, groupname, priority) VALUES (?,?,?,?)")
                ->execute([$nextId, $username, 'ISP_UNL', 1]);
        } else {
            throw $e;
        }
    }
}

