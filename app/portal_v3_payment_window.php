<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/guest_payment_radius.php';
require_once __DIR__ . '/lib/routeros.php';
require_once __DIR__ . '/hotspot_login.php';

/**
 * Janela curta de internet para o pagador abrir o aplicativo bancário.
 *
 * O bypass é vinculado ao IP/MAC do pedido e sempre possui expiração no
 * próprio MikroTik. A tabela guest_payment_windows mantém o histórico por
 * dispositivo para que recarregar o checkout não renove o tempo gratuito.
 */

final class FsV3PaymentWindowLimit extends RuntimeException
{
    private int $retryAfter;

    public function __construct(string $message, int $retryAfter)
    {
        parent::__construct($message);
        $this->retryAfter = max(1, $retryAfter);
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}

function fs_v3_payment_window_minutes(array $partner): int
{
    $configured = $partner['payment_window_minutes'] ?? env('V3_PAYMENT_WINDOW_MINUTES', '2');
    return max(1, min(5, (int) $configured));
}

function fs_v3_payment_window_enabled(array $partner): bool
{
    return !array_key_exists('payment_window_enabled',$partner)||(int)$partner['payment_window_enabled']===1;
}

function fs_v3_payment_window_cooldown_minutes(array $partner = []): int
{
    $configured = $partner['payment_window_cooldown_minutes'] ?? env('V3_PAYMENT_WINDOW_COOLDOWN_MINUTES', '10');
    return max(5, min(60, (int)$configured));
}

function fs_v3_payment_window_daily_limit(array $partner = []): int
{
    $configured = $partner['payment_window_daily_limit'] ?? env('V3_PAYMENT_WINDOW_DAILY_LIMIT', '3');
    return max(1, min(12, (int)$configured));
}

function fs_v3_payment_window_period_minutes(array $partner = []): int
{
    $configured = $partner['payment_window_period_minutes'] ?? env('V3_PAYMENT_WINDOW_PERIOD_MINUTES', '1440');
    return max(60,min(10080,(int)$configured));
}

function fs_v3_payment_window_schema_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->query('SELECT payment_window_token,payment_window_started_at,payment_window_expires_at,payment_window_closed_at FROM guest_orders LIMIT 0');
        $pdo->query('SELECT order_id,partner_id,device_mac,device_ip,token,started_at,expires_at,closed_at,close_reason FROM guest_payment_windows LIMIT 0');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function fs_v3_payment_window_connection(PDO $pdo, array $partner): array
{
    $nasId = (int) ($partner['nas_id'] ?? 0);
    if ($nasId <= 0) throw new RuntimeException('O estabelecimento não possui um MikroTik associado.');

    $st = $pdo->prepare('SELECT nasname,server,mgmt_username,mgmt_password,mgmt_port FROM nas WHERE id=? LIMIT 1');
    $st->execute([$nasId]);
    $nas = $st->fetch(PDO::FETCH_ASSOC);
    if (!$nas) throw new RuntimeException('O MikroTik associado ao estabelecimento não foi encontrado.');

    $host = trim((string) ($nas['nasname'] ?? ''));
    $server = trim((string) ($nas['server'] ?? ''));
    if ($server !== '' && filter_var($server, FILTER_VALIDATE_IP)) $host = $server;
    $user = trim((string) ($nas['mgmt_username'] ?? ''));
    $pass = (string) ($nas['mgmt_password'] ?? '');
    $port = (int) ($nas['mgmt_port'] ?? 22);
    if ($host === '' || $user === '' || $pass === '') {
        throw new RuntimeException('Configure o acesso de gerenciamento do MikroTik deste estabelecimento.');
    }

    return ['host' => $host, 'user' => $user, 'pass' => $pass, 'port' => $port, 'nas_id' => $nasId];
}

function fs_v3_payment_window_device(array $order): array
{
    $ip = trim((string) ($order['device_ip'] ?? ''));
    $mac = fs_guest_normalize_mac((string) ($order['device_mac'] ?? ''));
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new RuntimeException('O MikroTik não informou um IPv4 válido para este dispositivo.');
    }
    if ($mac === '') throw new RuntimeException('O MikroTik não informou o MAC deste dispositivo.');
    return ['ip' => $ip, 'mac' => $mac];
}

/**
 * Seletor estrito do host que recebeu a janela temporária.
 * MAC e IPv4 já foram normalizados por fs_v3_payment_window_device().
 */
function fs_v3_payment_window_host_selector(array $device): string
{
    $ip = trim((string)($device['ip'] ?? ''));
    $mac = fs_guest_normalize_mac((string)($device['mac'] ?? ''));
    if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || $mac === '') {
        throw new InvalidArgumentException('Dispositivo inválido para reciclar o estado do Hotspot.');
    }
    return 'mac-address="' . $mac . '" and address="' . $ip . '"';
}

/**
 * O temporizador só recicla o host se o binding temporário ainda existir.
 * Quando o pagamento fecha a janela antes do prazo, remover o binding também
 * cancela logicamente essa ação e evita derrubar a sessão paga posteriormente.
 */
function fs_v3_payment_window_expiry_script(int $seconds,string $token,array $device): string
{
    $seconds = max(1,$seconds);
    if (!preg_match('/^v3pay-[0-9]+-[a-f0-9]{16}$/',$token)) {
        throw new InvalidArgumentException('Token inválido para a janela temporária.');
    }
    $binding = 'comment="' . $token . '"';
    $host = fs_v3_payment_window_host_selector($device);
    return ':delay ' . $seconds . 's; '
        . ':if ([:len [/ip hotspot ip-binding find where ' . $binding . ']] > 0) do={'
        . ' /ip hotspot ip-binding remove [find where ' . $binding . '];'
        . ' /ip hotspot host remove [find where ' . $host . ']'
        . ' }';
}

function fs_v3_payment_window_lock(PDO $pdo, int $partnerId, string $mac): string
{
    $name = 'fspay:' . $partnerId . ':' . substr(hash('sha256', $mac), 0, 32);
    $st = $pdo->prepare('SELECT GET_LOCK(?,5)');
    $st->execute([$name]);
    if ((int) $st->fetchColumn() !== 1) {
        throw new FsV3PaymentWindowLimit('Já existe uma liberação sendo processada para este aparelho. Aguarde alguns segundos.', 5);
    }
    return $name;
}

function fs_v3_payment_window_unlock(PDO $pdo, string $name): void
{
    try {
        $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $st->execute([$name]);
    } catch (Throwable $e) {
        error_log('[portal-v3 payment window unlock] ' . $e->getMessage());
    }
}

function fs_v3_payment_window_result(array $window, bool $reused): array
{
    $expiry = strtotime((string) ($window['expires_at'] ?? '')) ?: 0;
    $remaining = max(1, $expiry - time());
    return [
        'active' => true,
        'reused' => $reused,
        'minutes' => max(1, (int) ceil($remaining / 60)),
        'remaining_seconds' => $remaining,
        'expires_at' => date(DATE_ATOM, $expiry),
    ];
}

/**
 * Um Pix aprovado durante a janela ainda precisa ser entregue ao login do
 * Hotspot pelo navegador. O webhook prepara o RADIUS, mas preserva o bypass
 * até a WebView chamar connect.php ou até o temporizador do RouterOS vencer.
 */
function fs_v3_payment_window_paid_handoff_pending(array $order, ?int $now = null): bool
{
    if ((string)($order['payment_access_mode'] ?? '') === FS_GUEST_PAYMENT_ACCESS_RADIUS) return false;
    if ((string)($order['status'] ?? '') !== 'paid'
        || (string)($order['payment_method'] ?? '') !== 'pix'
        || !empty($order['payment_window_closed_at'])) {
        return false;
    }
    $token = trim((string)($order['payment_window_token'] ?? ''));
    if (!preg_match('/^v3pay-[0-9]+-[a-f0-9]{16}$/',$token)) return false;
    $expiresAt = fs_guest_local_datetime_timestamp((string)($order['payment_window_expires_at'] ?? ''));
    $now = $now ?? time();
    return $expiresAt > $now;
}

/**
 * Consolida a política visível ao cliente sem expor MAC, IP ou token.
 * A função separada mantém o cálculo de cooldown/limite determinístico e
 * testável sem depender do relógio ou do banco.
 */
function fs_v3_payment_window_policy_summary(
    array $windows,
    int $currentOrderId,
    int $now,
    int $windowMinutes,
    int $cooldownMinutes,
    int $dailyLimit,
    int $periodMinutes = 1440
): array {
    $now = max(1,$now);
    $windowMinutes = max(1,min(5,$windowMinutes));
    $cooldownMinutes = max(5,min(60,$cooldownMinutes));
    $dailyLimit = max(1,min(12,$dailyLimit));
    $periodMinutes = max(60,min(10080,$periodMinutes));
    $eligible = [];
    foreach ($windows as $window) {
        $startedAt = (int)($window['started_ts'] ?? 0);
        if ($startedAt <= 0 || $startedAt <= $now - ($periodMinutes*60) || $startedAt > $now + 60) continue;
        if (in_array((string)($window['close_reason'] ?? ''), ['paid','order_expired','provision_failed'], true)
            || (string)($window['order_status'] ?? '') === 'paid') continue;
        $window['started_ts'] = $startedAt;
        $window['expires_ts'] = max($startedAt,(int)($window['expires_ts'] ?? 0));
        $window['closed_ts'] = (int)($window['closed_ts'] ?? 0);
        $eligible[] = $window;
    }
    usort($eligible,static fn(array $a,array $b): int => (int)$b['started_ts'] <=> (int)$a['started_ts']);

    $attemptsUsed = count($eligible);
    $attemptsRemaining = max(0,$dailyLimit-$attemptsUsed);
    $latest = $eligible[0] ?? null;
    $current = null;
    foreach ($eligible as $window) {
        if ((int)($window['order_id'] ?? 0) === $currentOrderId) {
            $current = $window;
            break;
        }
    }

    $active = $current !== null
        && (int)$current['closed_ts'] <= 0
        && (int)$current['expires_ts'] > $now;
    $cooldownAt = $latest ? (int)$latest['started_ts'] + ($cooldownMinutes*60) : $now;
    $dailyReleaseAt = $attemptsRemaining === 0 && $eligible
        ? min(array_map(static fn(array $window): int => (int)$window['started_ts'],$eligible))+($periodMinutes*60)
        : $now;
    $nextAt = max($now,$cooldownAt,$dailyReleaseAt,$active ? (int)$current['expires_ts'] : $now);
    $retryAfter = max(0,$nextAt-$now);

    if ($active) {
        $state = 'active';
    } elseif ($attemptsRemaining === 0) {
        $state = 'daily_limit';
    } elseif ($retryAfter > 0) {
        $state = 'cooling_down';
    } elseif ($current !== null || $attemptsUsed > 0) {
        $state = 'available';
    } else {
        $state = 'unused';
    }

    return [
        'enabled'=>true,
        'state'=>$state,
        'active'=>$active,
        'window_used'=>$current !== null,
        'window_minutes'=>$windowMinutes,
        'current_remaining_seconds'=>$active ? max(0,(int)$current['expires_ts']-$now) : 0,
        'current_expires_at'=>$active ? date(DATE_ATOM,(int)$current['expires_ts']) : null,
        'attempts_used'=>$attemptsUsed,
        'attempts_remaining'=>$attemptsRemaining,
        'daily_limit'=>$dailyLimit,
        'cooldown_minutes'=>$cooldownMinutes,
        'period_minutes'=>$periodMinutes,
        'retry_after'=>$retryAfter,
        'next_available_at'=>$retryAfter > 0 ? date(DATE_ATOM,$nextAt) : null,
        'can_start'=>!$active && $attemptsRemaining > 0 && $retryAfter === 0,
    ];
}

function fs_v3_payment_window_status(PDO $pdo, array $partner, array $order): array
{
    $disabled = [
        'enabled'=>false,'state'=>'unavailable','active'=>false,'window_used'=>false,
        'window_minutes'=>fs_v3_payment_window_minutes($partner),'current_remaining_seconds'=>0,
        'current_expires_at'=>null,'attempts_used'=>0,'attempts_remaining'=>0,
        'daily_limit'=>fs_v3_payment_window_daily_limit($partner),'cooldown_minutes'=>fs_v3_payment_window_cooldown_minutes($partner),
        'period_minutes'=>fs_v3_payment_window_period_minutes($partner),
        'retry_after'=>0,'next_available_at'=>null,'can_start'=>false,
    ];
    if (!fs_v3_payment_window_schema_ready($pdo)||!fs_v3_payment_window_enabled($partner)||(string)($order['payment_method'] ?? '') !== 'pix') return $disabled;
    $partnerId = (int)($partner['id'] ?? 0);
    $orderId = (int)($order['id'] ?? 0);
    if ($partnerId <= 0 || $orderId <= 0 || (int)($order['partner_id'] ?? 0) !== $partnerId) return $disabled;
    $mac = fs_guest_normalize_mac((string)($order['device_mac'] ?? ''));
    if ($mac === '') return $disabled;

    fs_v3_payment_window_expire_due_orders($pdo,$partnerId,$mac);

    $now = (int)$pdo->query('SELECT UNIX_TIMESTAMP(NOW())')->fetchColumn();
    if ($now <= 0) $now = time();
    $periodMinutes = fs_v3_payment_window_period_minutes($partner);
    $hotspotId=(int)(fs_partner_hotspot_id($partner)??($order['hotspot_id']??0));
    $hotspotClause=$hotspotId>0?' AND o.hotspot_id=?':'';
    $st = $pdo->prepare("SELECT w.order_id,w.close_reason,o.status order_status,
          UNIX_TIMESTAMP(w.started_at) started_ts,UNIX_TIMESTAMP(w.expires_at) expires_ts,
          COALESCE(UNIX_TIMESTAMP(w.closed_at),0) closed_ts
        FROM guest_payment_windows w
        LEFT JOIN guest_orders o ON o.id=w.order_id
        WHERE w.partner_id=? AND w.device_mac=?
          AND w.started_at>DATE_SUB(NOW(),INTERVAL {$periodMinutes} MINUTE){$hotspotClause}
        ORDER BY w.started_at DESC,w.id DESC LIMIT 20");
    $params=[$partnerId,$mac];if($hotspotId>0)$params[]=$hotspotId;$st->execute($params);
    return fs_v3_payment_window_policy_summary(
        $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
        $orderId,
        $now,
        fs_v3_payment_window_minutes($partner),
        fs_v3_payment_window_cooldown_minutes($partner),
        fs_v3_payment_window_daily_limit($partner),
        $periodMinutes
    );
}

function fs_v3_payment_window_reauth_url(array $partner): ?string
{
    return fs_hotspot_reauth_url($partner);
}

function fs_v3_payment_window_start(PDO $pdo, array $partner, array $order): array
{
    if (!fs_v3_payment_window_schema_ready($pdo)) {
        throw new RuntimeException('A atualização da política de internet temporária ainda não foi aplicada.');
    }
    $partnerId = (int) ($partner['id'] ?? 0);
    $orderId = (int) ($order['id'] ?? 0);
    if ((int) ($order['partner_id'] ?? 0) !== $partnerId || $partnerId <= 0 || $orderId <= 0) {
        throw new RuntimeException('Pedido e estabelecimento divergentes.');
    }

    $device = fs_v3_payment_window_device($order);
    fs_v3_payment_window_expire_due_orders($pdo,$partnerId,$device['mac']);
    $lockName = fs_v3_payment_window_lock($pdo, $partnerId, $device['mac']);
    try {
        $st = $pdo->prepare('SELECT * FROM guest_orders WHERE id=? LIMIT 1');
        $st->execute([$orderId]);
        $freshOrder = $st->fetch(PDO::FETCH_ASSOC);
        if (!$freshOrder || (int) $freshOrder['partner_id'] !== $partnerId) {
            throw new RuntimeException('Pedido não encontrado.');
        }
        $operationContext = fs_partner_hotspot_for_operation($pdo,$partnerId,isset($freshOrder['hotspot_id']) ? (int)$freshOrder['hotspot_id'] : null);
        if (fs_partner_hotspots_schema_ready($pdo) && !$operationContext) {
            throw new RuntimeException('A instalação original do pedido não foi encontrada.');
        }
        if ($operationContext) $partner = $operationContext;
        if(!fs_v3_payment_window_enabled($partner))throw new RuntimeException('A janela temporária Pix está desativada neste ponto.');
        if ((string) ($freshOrder['status'] ?? '') !== 'pending') {
            throw new RuntimeException('Este pedido não permite abrir uma janela temporária.');
        }
        $useRadius = (string)($freshOrder['payment_access_mode'] ?? '') !== FS_GUEST_PAYMENT_ACCESS_LEGACY;

        // Mantém o histórico coerente mesmo quando a remoção ocorreu pelo
        // temporizador autônomo do RouterOS.
        $pdo->prepare("UPDATE guest_payment_windows
            SET closed_at=COALESCE(closed_at,NOW()),close_reason=COALESCE(close_reason,'expired')
            WHERE partner_id=? AND device_mac=? AND closed_at IS NULL AND expires_at<=NOW()")
            ->execute([$partnerId, $device['mac']]);
        $pdo->prepare('UPDATE guest_orders
            SET payment_window_closed_at=COALESCE(payment_window_closed_at,NOW()),updated_at=NOW()
            WHERE partner_id=? AND device_mac=? AND payment_window_token IS NOT NULL
              AND payment_window_closed_at IS NULL AND payment_window_expires_at<=NOW()')
            ->execute([$partnerId, $device['mac']]);

        $hotspotId = isset($freshOrder['hotspot_id']) ? (int)$freshOrder['hotspot_id'] : 0;
        if(fs_partner_hotspots_schema_ready($pdo)){
            $st = $pdo->prepare('SELECT w.*,source_order.payment_access_mode source_access_mode FROM guest_payment_windows w
                JOIN guest_orders source_order ON source_order.id=w.order_id
                WHERE w.partner_id=? AND w.device_mac=? AND w.closed_at IS NULL
                  AND (source_order.hotspot_id=? OR (?=0 AND source_order.hotspot_id IS NULL))
                  AND expires_at>DATE_ADD(NOW(),INTERVAL 15 SECOND)
                ORDER BY w.started_at DESC,w.id DESC LIMIT 1');
            $st->execute([$partnerId, $device['mac'],$hotspotId,$hotspotId]);
        }else{
            $st = $pdo->prepare('SELECT w.* FROM guest_payment_windows w
                WHERE w.partner_id=? AND w.device_mac=? AND w.closed_at IS NULL
                  AND expires_at>DATE_ADD(NOW(),INTERVAL 15 SECOND)
                ORDER BY w.started_at DESC,w.id DESC LIMIT 1');
            $st->execute([$partnerId, $device['mac']]);
        }
        $activeWindow = $st->fetch(PDO::FETCH_ASSOC);
        if ($activeWindow) {
            if (fs_guest_payment_radius_schema_ready($pdo) && (int)$activeWindow['order_id'] !== $orderId) {
                $remaining = max(1,(strtotime((string)$activeWindow['expires_at']) ?: time()+5)-time());
                throw new FsV3PaymentWindowLimit('Ja existe uma janela temporaria ativa neste aparelho. Retome o Pix anterior ou aguarde o encerramento.', $remaining);
            }
            // Recarregar o mesmo pedido reaproveita a janela sem renovar tempo.
            // Uma cobranca diferente nunca herda uma sessao RADIUS autenticada.
            $ownsReuseTransaction = !$pdo->inTransaction();
            if ($ownsReuseTransaction) $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE guest_payment_windows SET order_id=?,device_ip=? WHERE id=?')
                    ->execute([$orderId, $device['ip'], (int) $activeWindow['id']]);
                $pdo->prepare('UPDATE guest_orders
                    SET payment_window_token=?,payment_window_started_at=?,payment_window_expires_at=?,payment_window_closed_at=NULL,updated_at=NOW()
                    WHERE id=?')
                    ->execute([(string) $activeWindow['token'], (string) $activeWindow['started_at'], (string) $activeWindow['expires_at'], $orderId]);
                if ($ownsReuseTransaction) $pdo->commit();
            } catch (Throwable $e) {
                if ($ownsReuseTransaction && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $result = fs_v3_payment_window_result($activeWindow, true);
            if ($useRadius && fs_guest_payment_radius_schema_ready($pdo)) {
                $prepared = fs_guest_prepare_provisional_access($pdo,$orderId,$result['remaining_seconds']);
                $result['access_mode'] = FS_GUEST_PAYMENT_ACCESS_RADIUS;
                $result['connect_required'] = true;
                $result['radius_phase'] = (string)($prepared['radius_phase'] ?? 'provisional');
            }
            return $result;
        }

        $cooldown = fs_v3_payment_window_cooldown_minutes($partner);
        $historyHotspotClause=$hotspotId>0?' AND o.hotspot_id=?':'';
        $st = $pdo->prepare("SELECT w.started_at,
                GREATEST(1,TIMESTAMPDIFF(SECOND,NOW(),DATE_ADD(w.started_at,INTERVAL {$cooldown} MINUTE))) AS retry_after
            FROM guest_payment_windows w
            LEFT JOIN guest_orders o ON o.id=w.order_id
            WHERE w.partner_id=? AND w.device_mac=? AND COALESCE(w.close_reason,'') NOT IN ('paid','order_expired','provision_failed')
              AND (o.id IS NULL OR o.status<>'paid'){$historyHotspotClause}
            ORDER BY w.started_at DESC,w.id DESC LIMIT 1");
        $historyParams=[$partnerId,$device['mac']];if($hotspotId>0)$historyParams[]=$hotspotId;$st->execute($historyParams);
        $latestWindow = $st->fetch(PDO::FETCH_ASSOC);
        if ($latestWindow) {
            $nextAt = (strtotime((string) $latestWindow['started_at']) ?: 0) + ($cooldown * 60);
            if ($nextAt > time()) {
                throw new FsV3PaymentWindowLimit(
                    'A internet temporária deste aparelho já foi utilizada. Aguarde antes de solicitar uma nova liberação.',
                    max(1, (int) ($latestWindow['retry_after'] ?? ($nextAt - time())))
                );
            }
        }

        $dailyLimit = fs_v3_payment_window_daily_limit($partner);
        $periodMinutes = fs_v3_payment_window_period_minutes($partner);
        $st = $pdo->prepare("SELECT COUNT(*) FROM guest_payment_windows w
            LEFT JOIN guest_orders o ON o.id=w.order_id
            WHERE w.partner_id=? AND w.device_mac=?
              AND w.started_at>DATE_SUB(NOW(),INTERVAL {$periodMinutes} MINUTE)
              AND COALESCE(w.close_reason,'') NOT IN ('paid','order_expired','provision_failed')
              AND (o.id IS NULL OR o.status<>'paid'){$historyHotspotClause}");
        $st->execute($historyParams);
        if ((int) $st->fetchColumn() >= $dailyLimit) {
        $st = $pdo->prepare("SELECT GREATEST(1,TIMESTAMPDIFF(SECOND,NOW(),DATE_ADD(MIN(w.started_at),INTERVAL {$periodMinutes} MINUTE)))
                FROM guest_payment_windows w
                LEFT JOIN guest_orders o ON o.id=w.order_id
                WHERE w.partner_id=? AND w.device_mac=?
                  AND w.started_at>DATE_SUB(NOW(),INTERVAL {$periodMinutes} MINUTE)
                  AND COALESCE(w.close_reason,'') NOT IN ('paid','order_expired','provision_failed')
                  AND (o.id IS NULL OR o.status<>'paid'){$historyHotspotClause}");
            $st->execute($historyParams);
            $release = (int)$st->fetchColumn();
            throw new FsV3PaymentWindowLimit(
                'O limite de internet temporária deste aparelho foi atingido neste período. O Pix continua válido para pagamento.',
                max(1,$release)
            );
        }

        $minutes = fs_v3_payment_window_minutes($partner);
        $seconds = $minutes * 60;
        $token = 'v3pay-' . $partnerId . '-' . substr(bin2hex(random_bytes(8)), 0, 16);

        if ($useRadius && fs_guest_payment_radius_schema_ready($pdo)) {
            $ownsRadiusWindowTransaction = !$pdo->inTransaction();
            if ($ownsRadiusWindowTransaction) $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO guest_payment_windows
                    (order_id,partner_id,device_mac,device_ip,token,started_at,expires_at)
                    VALUES (?,?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL ? MINUTE))')
                    ->execute([$orderId,$partnerId,$device['mac'],$device['ip'],$token,$minutes]);
                $pdo->prepare('UPDATE guest_orders
                    SET payment_window_token=?,payment_window_started_at=NOW(),payment_window_expires_at=DATE_ADD(NOW(),INTERVAL ? MINUTE),
                        payment_window_closed_at=NULL,payment_access_mode=?,updated_at=NOW()
                    WHERE id=?')
                    ->execute([$token,$minutes,FS_GUEST_PAYMENT_ACCESS_RADIUS,$orderId]);
                if ($ownsRadiusWindowTransaction) $pdo->commit();
            } catch (Throwable $error) {
                if ($ownsRadiusWindowTransaction && $pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
            try {
                $prepared = fs_guest_prepare_provisional_access($pdo,$orderId,$seconds);
            } catch (Throwable $error) {
                $pdo->prepare("UPDATE guest_payment_windows SET closed_at=COALESCE(closed_at,NOW()),close_reason='provision_failed' WHERE token=?")
                    ->execute([$token]);
                $pdo->prepare('UPDATE guest_orders SET payment_window_closed_at=COALESCE(payment_window_closed_at,NOW()),updated_at=NOW() WHERE id=?')
                    ->execute([$orderId]);
                throw $error;
            }
            return [
                'active'=>true,'reused'=>false,'minutes'=>$minutes,'remaining_seconds'=>$seconds,
                'expires_at'=>date(DATE_ATOM,time()+$seconds),'access_mode'=>FS_GUEST_PAYMENT_ACCESS_RADIUS,
                'connect_required'=>true,'radius_phase'=>(string)($prepared['radius_phase'] ?? 'provisional'),
            ];
        }

        $connection = fs_v3_payment_window_connection($pdo, $partner);

        // :execute mantém a expiração no RouterOS mesmo se a WebView do portal
        // for suspensa pelo Android enquanto o aplicativo bancário está aberto.
        $cleanup = fs_v3_payment_window_expiry_script($seconds,$token,$device);
        $commands = [
            '/ip hotspot ip-binding add address=' . $device['ip'] . ' mac-address=' . $device['mac'] . ' type=bypassed comment="' . $token . '"',
            ':execute {' . $cleanup . '}',
            ':put [/ip hotspot ip-binding print count-only where comment="' . $token . '"]',
        ];
        $result = ros_exec($commands, $connection);
        if (empty($result['ok'])) throw new RuntimeException((string) ($result['err'] ?? 'O MikroTik recusou a liberação temporária.'));
        $verification = trim((string) end($result['out']));
        if (!preg_match('/(?:^|\D)[1-9][0-9]*(?:\D|$)/', $verification)) {
            throw new RuntimeException('O MikroTik não confirmou a janela temporária.');
        }

        $ownsLegacyWindowTransaction = !$pdo->inTransaction();
        if ($ownsLegacyWindowTransaction) $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO guest_payment_windows
                (order_id,partner_id,device_mac,device_ip,token,started_at,expires_at)
                VALUES (?,?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL ? MINUTE))')
                ->execute([$orderId, $partnerId, $device['mac'], $device['ip'], $token, $minutes]);
            $pdo->prepare('UPDATE guest_orders
                SET payment_window_token=?,payment_window_started_at=NOW(),payment_window_expires_at=DATE_ADD(NOW(),INTERVAL ? MINUTE),
                    payment_window_closed_at=NULL,payment_access_mode=?,updated_at=NOW()
                WHERE id=?')
                ->execute([$token,$minutes,FS_GUEST_PAYMENT_ACCESS_LEGACY,$orderId]);
            if ($ownsLegacyWindowTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownsLegacyWindowTransaction && $pdo->inTransaction()) $pdo->rollBack();
            try {
                ros_exec(['/ip hotspot ip-binding remove [find where comment="' . $token . '"]'], $connection);
            } catch (Throwable $cleanupError) {
                error_log('[portal-v3 payment window rollback] ' . $cleanupError->getMessage());
            }
            throw $e;
        }

        return [
            'active' => true,
            'reused' => false,
            'minutes' => $minutes,
            'remaining_seconds' => $seconds,
            'expires_at' => date(DATE_ATOM, time() + $seconds),
        ];
    } finally {
        fs_v3_payment_window_unlock($pdo, $lockName);
    }
}

function fs_v3_payment_window_close(PDO $pdo, array $partner, array $order, bool $recycleHost = true): bool
{
    if (!fs_v3_payment_window_schema_ready($pdo)) return true;
    $partnerId = (int) ($partner['id'] ?? 0);
    if ((int) ($order['partner_id'] ?? 0) !== $partnerId) return false;
    $token = trim((string) ($order['payment_window_token'] ?? ''));
    if ($token === '' || !empty($order['payment_window_closed_at'])) return true;
    if (!preg_match('/^v3pay-[0-9]+-[a-f0-9]{16}$/', $token)) return false;

    $lockName = '';
    try {
        $device = fs_v3_payment_window_device($order);
        $lockName = fs_v3_payment_window_lock($pdo, $partnerId, $device['mac']);
        // Webhooks v1 e v2 podem chegar quase juntos. Releia o pedido dentro
        // do lock para que somente o primeiro fechamento toque no RouterOS.
        $stFresh = $pdo->prepare('SELECT * FROM guest_orders WHERE id=? AND partner_id=? AND payment_window_token=? LIMIT 1');
        $stFresh->execute([(int)($order['id'] ?? 0),$partnerId,$token]);
        $freshOrder = $stFresh->fetch(PDO::FETCH_ASSOC);
        if (!$freshOrder) throw new RuntimeException('A janela não pertence mais ao pedido informado.');
        if (!empty($freshOrder['payment_window_closed_at'])) return true;
        $order = array_replace($order,$freshOrder);
        if ((string)($order['payment_access_mode'] ?? '') === FS_GUEST_PAYMENT_ACCESS_RADIUS) {
            $reason = (string)($order['status'] ?? '') === 'paid'
                ? 'paid'
                : ((fs_guest_local_datetime_timestamp((string)($order['payment_window_expires_at'] ?? '')) ?: PHP_INT_MAX) <= time() ? 'expired' : 'closed');
            $ownsRadiusCloseTransaction = !$pdo->inTransaction();
            if ($ownsRadiusCloseTransaction) $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE guest_payment_windows SET closed_at=COALESCE(closed_at,NOW()),close_reason=COALESCE(close_reason,?) WHERE token=?')
                    ->execute([$reason,$token]);
                $pdo->prepare('UPDATE guest_orders SET payment_window_closed_at=COALESCE(payment_window_closed_at,NOW()),updated_at=NOW() WHERE id=?')
                    ->execute([(int)$order['id']]);
                if ($ownsRadiusCloseTransaction) $pdo->commit();
            } catch (Throwable $error) {
                if ($ownsRadiusCloseTransaction && $pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
            if ((string)($order['status'] ?? '') !== 'paid') fs_guest_expire_provisional_access($pdo,$order);
            return true;
        }
        $device = fs_v3_payment_window_device($order);
        $operationContext = fs_partner_hotspot_for_operation($pdo,$partnerId,isset($order['hotspot_id']) ? (int)$order['hotspot_id'] : null);
        if (fs_partner_hotspots_schema_ready($pdo) && !$operationContext) throw new RuntimeException('Instalação original do pedido não encontrada.');
        if ($operationContext) $partner = $operationContext;
        $connection = fs_v3_payment_window_connection($pdo, $partner);
        $hostSelector = fs_v3_payment_window_host_selector($device);
        $commands = ['/ip hotspot ip-binding remove [find where comment="' . $token . '"]'];
        if ($recycleHost) {
            // Fechamentos remotos precisam forçar nova captura. No handoff
            // iniciado pela própria WebView, preservar o host mantém válido o
            // desafio HTTP-CHAP que será enviado logo depois por connect.php.
            $commands[] = '/ip hotspot host remove [find where ' . $hostSelector . ']';
        }
        $commands[] = ':put [/ip hotspot ip-binding print count-only where comment="' . $token . '"]';
        $result = ros_exec($commands, $connection);
        if (empty($result['ok'])) throw new RuntimeException((string) ($result['err'] ?? 'falha no MikroTik'));
        $verification = trim((string) end($result['out']));
        if (!preg_match('/^(?:0\s*)+$/', $verification)) throw new RuntimeException('O MikroTik ainda mantém o bypass ativo.');

        $reason = (string) ($order['status'] ?? '') === 'paid'
            ? 'paid'
            : ((fs_guest_local_datetime_timestamp((string)($order['payment_window_expires_at'] ?? '')) ?: PHP_INT_MAX) <= time() ? 'expired' : 'closed');
        $ownsLegacyCloseTransaction = !$pdo->inTransaction();
        if ($ownsLegacyCloseTransaction) $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE guest_payment_windows SET closed_at=COALESCE(closed_at,NOW()),close_reason=COALESCE(close_reason,?) WHERE token=?')
                ->execute([$reason, $token]);
            $pdo->prepare('UPDATE guest_orders SET payment_window_closed_at=COALESCE(payment_window_closed_at,NOW()),updated_at=NOW()
                WHERE partner_id=? AND payment_window_token=?')
                ->execute([$partnerId, $token]);
            if ($ownsLegacyCloseTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownsLegacyCloseTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return true;
    } catch (Throwable $e) {
        error_log('[portal-v3 payment window close] order=' . (int) ($order['id'] ?? 0) . ' ' . $e->getMessage());
        return false;
    } finally {
        if ($lockName !== '') fs_v3_payment_window_unlock($pdo, $lockName);
    }
}

/**
 * Encerra o histórico gratuito pertencente a um Pix vencido. Essas janelas
 * deixam de consumir o limite do aparelho, permitindo que uma nova cobrança
 * comece com a política completa do estabelecimento.
 */
function fs_v3_payment_window_release_expired_order(PDO $pdo, int $orderId): int
{
    if ($orderId <= 0 || !fs_v3_payment_window_schema_ready($pdo)) return 0;
    $st = $pdo->prepare("UPDATE guest_payment_windows
        SET closed_at=COALESCE(closed_at,NOW()),close_reason='order_expired'
        WHERE order_id=? AND COALESCE(close_reason,'')<>'paid'");
    $st->execute([$orderId]);
    $released = $st->rowCount();
    $pdo->prepare('UPDATE guest_orders
        SET payment_window_closed_at=COALESCE(payment_window_closed_at,NOW()),updated_at=NOW()
        WHERE id=?')->execute([$orderId]);
    return $released;
}

function fs_v3_payment_window_expire_due_orders(PDO $pdo, int $partnerId, string $deviceMac): int
{
    if ($partnerId <= 0 || $deviceMac === '' || !fs_v3_payment_window_schema_ready($pdo)) return 0;
    $st = $pdo->prepare("SELECT * FROM guest_orders
        WHERE partner_id=? AND device_mac=? AND status='pending'
          AND COALESCE(payment_expires_at,DATE_ADD(created_at,INTERVAL 24 HOUR))<=NOW()
        ORDER BY id LIMIT 20");
    $st->execute([$partnerId,$deviceMac]);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$orders) return 0;

    $expire = $pdo->prepare("UPDATE guest_orders
        SET status='cancelled',payment_status_detail='expired',updated_at=NOW()
        WHERE id=? AND status='pending'");
    $released = 0;
    foreach ($orders as $order) {
        $id = (int)$order['id'];
        fs_v3_payment_window_close($pdo,['id'=>$partnerId],$order);
        $expire->execute([$id]);
        if ($expire->rowCount() === 1) {
            $released += fs_v3_payment_window_release_expired_order($pdo,$id);
        }
    }
    return $released;
}
