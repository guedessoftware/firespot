<?php

declare(strict_types=1);

/**
 * Consulta financeira do painel do estabelecimento.
 *
 * Os valores representam cobranças brutas registradas em guest_orders. Eles
 * não representam saldo disponível nem uma previsão de liquidação.
 */

function partner_finance_statuses(): array
{
    return [
        'pending' => 'Pendente',
        'paid' => 'Pago',
        'payment_failed' => 'Falhou',
        'cancelled' => 'Cancelado',
        'refunded' => 'Reembolsado',
    ];
}

function partner_finance_status_label(string $status): string
{
    return partner_finance_statuses()[$status] ?? 'Desconhecido';
}

function partner_finance_status_class(string $status): string
{
    return match ($status) {
        'paid' => 'is-paid',
        'pending' => 'is-pending',
        'payment_failed' => 'is-failed',
        'cancelled' => 'is-cancelled',
        'refunded' => 'is-refunded',
        default => 'is-unknown',
    };
}

function partner_finance_payment_method_label(?string $method): string
{
    $method = trim((string)$method);
    if ($method === '') return 'Não informado';
    return [
        'pix' => 'Pix',
        'credit_card' => 'Cartão de crédito',
        'debit_card' => 'Cartão de débito',
        'account_money' => 'Saldo Mercado Pago',
    ][$method] ?? ucfirst(str_replace('_', ' ', $method));
}

function partner_finance_parse_date(string $value, DateTimeZone $timezone): DateTimeImmutable
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        || $parsed->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Informe um período válido.');
    }
    return $parsed;
}

function partner_finance_filters(array $input, ?DateTimeImmutable $today = null): array
{
    $timezone = new DateTimeZone('America/Manaus');
    $today = ($today ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone)->setTime(0, 0);
    $defaultFrom = $today->modify('-29 days');

    $fromValue = trim((string)($input['from'] ?? ''));
    $toValue = trim((string)($input['to'] ?? ''));
    $from = $fromValue === '' ? $defaultFrom : partner_finance_parse_date($fromValue, $timezone);
    $to = $toValue === '' ? $today : partner_finance_parse_date($toValue, $timezone);

    if ($from > $to) throw new InvalidArgumentException('A data inicial não pode ser posterior à data final.');
    if ((int)$from->diff($to)->format('%a') > 365) {
        throw new InvalidArgumentException('Consulte no máximo 366 dias por vez.');
    }

    $status = trim((string)($input['status'] ?? ''));
    if ($status !== '' && !array_key_exists($status, partner_finance_statuses())) {
        throw new InvalidArgumentException('Selecione uma situação de venda válida.');
    }

    $pageValue = (string)($input['p'] ?? '1');
    $page = ctype_digit($pageValue) ? max(1, (int)$pageValue) : 1;
    $hotspotValue = trim((string)($input['hotspot_id'] ?? ''));
    if ($hotspotValue !== '' && (!ctype_digit($hotspotValue) || (int)$hotspotValue <= 0)) {
        throw new InvalidArgumentException('Selecione um ponto válido.');
    }

    return [
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'date_from' => $from->format('Y-m-d') . ' 00:00:00',
        'date_until' => $to->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
        'status' => $status,
        'hotspot_id' => $hotspotValue !== '' ? (int)$hotspotValue : null,
        'page' => $page,
    ];
}

function partner_finance_overview(PDO $pdo, int $partnerId, array $filters): array
{
    if ($partnerId <= 0) throw new InvalidArgumentException('Estabelecimento inválido.');
    $sql = "SELECT
            SUM(CASE WHEN o.status='paid' THEN 1 ELSE 0 END) paid_count,
            COALESCE(SUM(CASE WHEN o.status='paid' THEN o.amount_cents ELSE 0 END),0) paid_amount,
            SUM(CASE WHEN o.status='pending' THEN 1 ELSE 0 END) pending_count,
            COALESCE(SUM(CASE WHEN o.status='pending' THEN o.amount_cents ELSE 0 END),0) pending_amount,
            SUM(CASE WHEN o.status IN ('payment_failed','cancelled') THEN 1 ELSE 0 END) failed_count,
            SUM(CASE WHEN o.status='refunded' THEN 1 ELSE 0 END) refunded_count
        FROM guest_orders o
        WHERE o.partner_id=:partner_id
          AND COALESCE(o.paid_at,o.created_at)>=:date_from
          AND COALESCE(o.paid_at,o.created_at)<:date_until";
    $params = [
        ':partner_id' => $partnerId,
        ':date_from' => (string)$filters['date_from'],
        ':date_until' => (string)$filters['date_until'],
    ];
    if ((string)($filters['status'] ?? '') !== '') {
        $sql .= ' AND o.status=:status';
        $params[':status'] = (string)$filters['status'];
    }
    if (!empty($filters['hotspot_id'])) {
        $sql .= ' AND o.hotspot_id=:hotspot_id';
        $params[':hotspot_id'] = (int)$filters['hotspot_id'];
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $paidCount = (int)($row['paid_count'] ?? 0);
    $paidAmount = (int)($row['paid_amount'] ?? 0);
    return [
        'paid_count' => $paidCount,
        'paid_amount' => $paidAmount,
        'average_ticket' => $paidCount > 0 ? (int)round($paidAmount / $paidCount) : 0,
        'pending_count' => (int)($row['pending_count'] ?? 0),
        'pending_amount' => (int)($row['pending_amount'] ?? 0),
        'failed_count' => (int)($row['failed_count'] ?? 0),
        'refunded_count' => (int)($row['refunded_count'] ?? 0),
    ];
}

function partner_finance_sales(PDO $pdo, int $partnerId, array $filters, int $pageSize = 25): array
{
    if ($partnerId <= 0) throw new InvalidArgumentException('Estabelecimento inválido.');
    $pageSize = max(10, min(100, $pageSize));
    $where = 'o.partner_id=:partner_id
        AND COALESCE(o.paid_at,o.created_at)>=:date_from
        AND COALESCE(o.paid_at,o.created_at)<:date_until';
    $params = [
        ':partner_id' => $partnerId,
        ':date_from' => (string)$filters['date_from'],
        ':date_until' => (string)$filters['date_until'],
    ];
    if ((string)($filters['status'] ?? '') !== '') {
        $where .= ' AND o.status=:status';
        $params[':status'] = (string)$filters['status'];
    }
    if (!empty($filters['hotspot_id'])) {
        $where .= ' AND o.hotspot_id=:hotspot_id';
        $params[':hotspot_id'] = (int)$filters['hotspot_id'];
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM guest_orders o WHERE {$where}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = min(max(1, (int)($filters['page'] ?? 1)), $pages);
    $offset = ($page - 1) * $pageSize;

    $sql = "SELECT o.id,o.external_ref,o.status,o.plan_name,o.amount_cents,o.duration_minutes,
            o.payment_method,o.payment_status_detail,o.provider,o.created_at,o.paid_at,
            h.name hotspot_name,h.code hotspot_code,
            CASE
              WHEN w.id IS NULL THEN 'Não identificado'
              WHEN w.partner_id=o.partner_id THEN w.name
              WHEN w.partner_id IS NULL THEN 'Carteira global'
              ELSE 'Não identificado'
            END receiver_name
        FROM guest_orders o
        LEFT JOIN payment_wallets w ON w.id=o.wallet_id
        LEFT JOIN partner_hotspots h ON h.id=o.hotspot_id AND h.partner_id=o.partner_id
        WHERE {$where}
        ORDER BY COALESCE(o.paid_at,o.created_at) DESC,o.id DESC
        LIMIT {$pageSize} OFFSET {$offset}";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return [
        'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'page_size' => $pageSize,
    ];
}
