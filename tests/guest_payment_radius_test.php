<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/guest_payment_radius.php';
require_once __DIR__ . '/../app/portal_v3_payment_window.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$radius = fs_radius_db();
$radius->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$radius->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$sameRadiusConnection = $radius === $pdo;
$checks = 0;
$expect = static function (bool $condition,string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$policyNow = strtotime('2026-09-06 12:00:00');
$retryInitial = fs_guest_payment_radius_retry_policy([
    'radius_coa_status'=>'pending',
    'radius_coa_attempts'=>0,
    'paid_at'=>'2026-09-06 11:59:50',
],$policyNow);
$expect($retryInitial['eligible'] && !$retryInitial['terminal'],'Primeira tentativa de CoA nao ficou elegivel.');
$retryWaiting = fs_guest_payment_radius_retry_policy([
    'radius_coa_status'=>'waiting_session',
    'radius_coa_attempts'=>1,
    'radius_coa_error_code'=>'RADIUS_SESSION_NOT_FOUND',
    'radius_coa_last_attempt_at'=>'2026-09-06 11:59:50',
    'paid_at'=>'2026-09-06 11:59:00',
],$policyNow);
$expect(!$retryWaiting['eligible'] && !$retryWaiting['terminal'] && $retryWaiting['next_attempt_at']>$policyNow,'Backoff de sessao ausente nao foi respeitado.');
$retryReady = fs_guest_payment_radius_retry_policy([
    'radius_coa_status'=>'waiting_session',
    'radius_coa_attempts'=>1,
    'radius_coa_error_code'=>'RADIUS_SESSION_NOT_FOUND',
    'radius_coa_last_attempt_at'=>'2026-09-06 11:59:20',
    'paid_at'=>'2026-09-06 11:59:00',
],$policyNow);
$expect($retryReady['eligible'] && !$retryReady['terminal'],'Tentativa nao foi liberada depois do backoff.');
$retryLimit = fs_guest_payment_radius_retry_policy([
    'radius_coa_status'=>'waiting_session',
    'radius_coa_attempts'=>20,
    'radius_coa_error_code'=>'RADIUS_SESSION_NOT_FOUND',
    'paid_at'=>'2026-09-06 11:00:00',
],$policyNow);
$expect($retryLimit['terminal'] && $retryLimit['terminal_code']==='RETRY_LIMIT_SESSION_NOT_FOUND','Limite de sessao ausente nao enviou o pedido para revisao.');
$nakLimit = fs_guest_payment_radius_retry_policy([
    'radius_coa_status'=>'failed',
    'radius_coa_attempts'=>5,
    'radius_coa_error_code'=>'COA_NAK',
    'paid_at'=>'2026-09-06 11:00:00',
],$policyNow);
$expect($nakLimit['terminal'] && $nakLimit['terminal_code']==='RETRY_LIMIT_COA_NAK','Limite de CoA-NAK nao foi aplicado.');
$manualReview = fs_guest_payment_radius_retry_policy([
    'radius_coa_status'=>'manual_review',
    'radius_coa_attempts'=>2,
    'radius_coa_error_code'=>'MANUAL_REVIEW',
],$policyNow);
$expect(!$manualReview['eligible'] && $manualReview['terminal'],'Revisao manual voltou para a fila automatica.');

$pdo->beginTransaction();
if (!$sameRadiusConnection) $radius->beginTransaction();
try {
    $st = $pdo->query("SELECT h.*,p.code partner_code,p.active partner_active,n.nasname
        FROM partner_hotspots h JOIN partners p ON p.id=h.partner_id JOIN nas n ON n.id=h.nas_id
        WHERE h.active=1 AND p.active=1 AND n.nasname REGEXP '^[0-9]+\\\\.[0-9]+\\\\.[0-9]+\\\\.[0-9]+$'
        ORDER BY h.id LIMIT 1");
    $hotspot = $st->fetch();
    if (!$hotspot) throw new RuntimeException('Nenhuma instalacao ativa disponivel para o teste RADIUS/CoA.');
    $partner = fs_partner_hotspot_resolve($pdo,(string)$hotspot['code'],false,true);
    if (!$partner) throw new RuntimeException('Contexto de instalacao indisponivel.');
    // Desde a migração 056 a janela Pix pertence ao ponto. O teste precisa
    // declarar explicitamente essa pré-condição na própria transação, em vez
    // de depender da política comercial de uma instalação de produção.
    if(fs_partner_hotspot_commercial_schema_ready($pdo)){
        $enableWindow=$pdo->prepare('UPDATE partner_hotspot_commercial_policies SET paid_access_enabled=1,payment_window_enabled=1 WHERE hotspot_id=? AND partner_id=?');
        $enableWindow->execute([(int)$hotspot['id'],(int)$hotspot['partner_id']]);
        $windowReady=$pdo->prepare('SELECT paid_access_enabled=1 AND payment_window_enabled=1 FROM partner_hotspot_commercial_policies WHERE hotspot_id=? AND partner_id=?');
        $windowReady->execute([(int)$hotspot['id'],(int)$hotspot['partner_id']]);
        if((int)$windowReady->fetchColumn()!==1)throw new RuntimeException('A fixture não conseguiu habilitar a janela Pix no ponto selecionado.');
    }
    $plans = fs_guest_plans($pdo,$partner);
    if (!$plans) throw new RuntimeException('Nenhum plano disponivel para o teste.');
    $plan = $plans[0];
    $plan['duration_minutes'] = 7;
    $plan['download_kbps'] = 4096;
    $plan['upload_kbps'] = 1024;
    $orderRef = fs_guest_create_order($pdo,$partner,$plan,['mac'=>'02:41:52:63:74:85','ip'=>'192.0.2.77'],null);
    $orderId = (int)$orderRef['id'];
    $pdo->prepare("UPDATE guest_orders SET payment_method='pix',payment_expires_at=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=?")
        ->execute([$orderId]);

    $st = $pdo->prepare('SELECT * FROM guest_orders WHERE id=?');
    $st->execute([$orderId]);
    $pendingOrder = $st->fetch();
    $window = fs_v3_payment_window_start($pdo,$partner,$pendingOrder);
    $expect((string)($window['access_mode'] ?? '') === FS_GUEST_PAYMENT_ACCESS_RADIUS && !empty($window['connect_required']),'Janela nova nao escolheu autenticacao RADIUS.');
    $st->execute([$orderId]);
    $prepared = $st->fetch();
    $prepared['_radius_password'] = fs_guest_radius_password($radius,(string)$prepared['radius_username']);
    $expect((string)$prepared['payment_access_mode'] === FS_GUEST_PAYMENT_ACCESS_RADIUS,'Pedido nao adotou a pre-autenticacao RADIUS.');
    $expect((string)$prepared['radius_phase'] === 'provisional','Credencial provisoria nao ficou pronta.');
    $username = (string)$prepared['radius_username'];
    $expect($username !== '' && !empty($prepared['_radius_password']),'Credencial provisoria incompleta.');
    $st = $radius->prepare("SELECT attribute,op,value FROM radcheck WHERE username=? ORDER BY attribute");
    $st->execute([$username]);
    $checksByAttribute = [];
    foreach ($st->fetchAll() as $row) $checksByAttribute[$row['attribute']] = $row;
    $expect(($checksByAttribute['Calling-Station-Id']['value'] ?? '') === '02:41:52:63:74:85','Credencial provisoria nao foi vinculada ao MAC.');
    $expect((int)($checksByAttribute['Max-All-Session']['value'] ?? 0) === (int)$window['remaining_seconds'],'Limite provisorio incorreto.');
    $st = $radius->prepare("SELECT value FROM radreply WHERE username=? AND attribute='Mikrotik-Rate-Limit'");
    $st->execute([$username]);
    $expect((string)$st->fetchColumn() === '512k/1024k','Velocidade provisoria segura nao foi aplicada.');

    $nasIp = (string)$hotspot['nasname'];
    $sessionId = 'coa-' . bin2hex(random_bytes(8));
    $radius->prepare("INSERT INTO radacct
        (acctsessionid,acctuniqueid,username,nasipaddress,acctstarttime,acctupdatetime,acctsessiontime,
         calledstationid,callingstationid,acctterminatecause,framedipaddress)
        VALUES (?,?,?, ?,DATE_SUB(NOW(),INTERVAL 25 SECOND),NOW(),15, ?,?,'',?)")
        ->execute([$sessionId,md5($sessionId),$username,$nasIp,'firespot-test','02:41:52:63:74:85','192.0.2.77']);
    $activeSession = fs_guest_radius_active_session_for_device($pdo,$prepared,['mac'=>'02:41:52:63:74:85','ip'=>'192.0.2.77'],$radius);
    $expect((string)($activeSession['acctsessionid'] ?? '') === $sessionId,'A sessao exata do aparelho nao foi reconhecida no retorno.');
    $expect(fs_guest_radius_active_session_for_device($pdo,$prepared,['mac'=>'02:41:52:63:74:86','ip'=>'192.0.2.77'],$radius) === null,'Uma sessao de outro MAC foi aceita como conexao do aparelho atual.');
    $expect(fs_guest_radius_active_session_for_device($pdo,$prepared,['mac'=>'02:41:52:63:74:85','ip'=>'192.0.2.78'],$radius) === null,'Uma sessao de outro IP foi aceita como conexao do aparelho atual.');
    $pdo->prepare("UPDATE guest_orders SET status='paid',paid_at=NOW() WHERE id=?")->execute([$orderId]);

    $sent = [];
    $sender = static function (array $nas,array $session,array $attributes,array $order) use (&$sent): array {
        $sent[] = compact('nas','session','attributes','order');
        return ['ok'=>true,'code'=>'COA_ACK'];
    };
    $paid = fs_guest_finalize_paid_access($pdo,$orderId,$radius,$sender);
    $expect((string)$paid['radius_phase'] === 'paid_active' && (string)$paid['radius_coa_status'] === 'applied','CoA confirmado nao ativou o pedido.');
    $expect(count($sent) === 1,'Promocao nao enviou exatamente um CoA.');
    $payload = implode("\n",$sent[0]['attributes']);
    $expect(strpos($payload,'Acct-Session-Id := "' . $sessionId . '"') !== false,'CoA nao identificou a sessao exata.');
    $expect(strpos($payload,'Session-Timeout := 420') !== false,'CoA nao enviou o tempo integral comprado.');
    $expect(strpos($payload,'Mikrotik-Rate-Limit := "1024k/4096k"') !== false,'CoA nao enviou a velocidade final.');
    $expect((string)$sent[0]['session']['nasipaddress'] === $nasIp,'CoA foi direcionado para outro NAS.');

    $baseline = (int)$paid['radius_paid_baseline_seconds'];
    $st = $radius->prepare("SELECT CAST(value AS UNSIGNED) FROM radcheck WHERE username=? AND attribute='Max-All-Session'");
    $st->execute([$username]);
    $expect((int)$st->fetchColumn() === $baseline+420,'Tempo provisorio nao foi isolado do credito comprado.');
    $credit = fs_guest_credit_balance($radius,$paid);
    $expect((int)$credit['remaining_seconds'] === 420,'Saldo inicial pago nao foi limitado ao tempo comprado.');

    fs_guest_finalize_paid_access($pdo,$orderId,$radius,$sender);
    $expect(count($sent) === 1,'Webhook duplicado reenviou CoA depois do ACK.');

    $lateRef = fs_guest_create_order($pdo,$partner,$plan,['mac'=>'02:41:52:63:74:86','ip'=>'192.0.2.78'],null);
    $lateId = (int)$lateRef['id'];
    $pdo->prepare("UPDATE guest_orders SET payment_method='pix',payment_expires_at=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=?")
        ->execute([$lateId]);
    $latePrepared = fs_guest_prepare_provisional_access($pdo,$lateId,60,$radius);
    fs_guest_expire_provisional_access($pdo,$latePrepared,$radius);
    $expect(fs_guest_radius_password($radius,(string)$latePrepared['radius_username']) === null,'Expiracao nao removeu a credencial provisoria.');
    $pdo->prepare("UPDATE guest_orders SET status='paid',paid_at=NOW() WHERE id=?")->execute([$lateId]);
    $latePaid = fs_guest_finalize_paid_access($pdo,$lateId,$radius,$sender);
    $expect((string)$latePaid['radius_coa_status'] === 'waiting_session','Pagamento tardio sem sessao foi marcado como conectado.');
    $expect(empty($latePaid['radius_cleaned_at']) && fs_guest_radius_password($radius,(string)$latePaid['radius_username']) !== null,'Pagamento tardio nao recriou a credencial final recuperavel.');

    if (!$sameRadiusConnection && $radius->inTransaction()) $radius->rollBack();
    $pdo->rollBack();
    echo "Guest payment RADIUS/CoA OK: {$checks} verificacoes.\n";
} catch (Throwable $error) {
    if (!$sameRadiusConnection && $radius->inTransaction()) $radius->rollBack();
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
