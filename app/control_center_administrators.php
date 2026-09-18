<?php

declare(strict_types=1);

function fs_control_center_administrator_filters(array $input): array
{
    $q = trim((string)($input['q'] ?? ''));
    $q = function_exists('mb_substr') ? mb_substr($q, 0, 100, 'UTF-8') : substr($q, 0, 100);
    return [
        'q'=>$q,
        'page'=>max(1, (int)($input['page'] ?? 1)),
        'per_page'=>50,
    ];
}

/** @return array{filters:array<string,mixed>,total:int,page:int,pages:int,rows:list<array<string,mixed>>} */
function fs_control_center_administrator_supervision(PDO $pdo, array $input = []): array
{
    $filters = fs_control_center_administrator_filters($input);
    $where = '';
    $params = [];
    if ($filters['q'] !== '') {
        $where = 'WHERE u.email LIKE ? OR u.name LIKE ? OR p.name LIKE ? OR p.code LIKE ?';
        $params = array_fill(0, 4, '%' . $filters['q'] . '%');
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM partner_admin_memberships m JOIN host_users u ON u.id=m.user_id JOIN partners p ON p.id=m.partner_id {$where}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $filters['per_page']));
    $filters['page'] = min($filters['page'], $pages);
    $offset = ($filters['page'] - 1) * $filters['per_page'];

    $statement = $pdo->prepare("SELECT m.partner_id,m.role,m.active membership_active,u.email,u.name,u.active user_active,u.last_login_at,p.name partner_name,p.code partner_code,(SELECT COUNT(*) FROM partner_admin_memberships x WHERE x.user_id=u.id AND x.active=1) partner_count FROM partner_admin_memberships m JOIN host_users u ON u.id=m.user_id JOIN partners p ON p.id=m.partner_id {$where} ORDER BY p.name,u.name,u.email LIMIT {$filters['per_page']} OFFSET {$offset}");
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        foreach (['partner_id','membership_active','user_active','partner_count'] as $field) $row[$field] = (int)($row[$field] ?? 0);
    }
    unset($row);

    return ['filters'=>$filters,'total'=>$total,'page'=>$filters['page'],'pages'=>$pages,'rows'=>$rows];
}

/** @return list<array<string,mixed>> */
function fs_control_center_pending_administrator_invitations(PDO $pdo, int $limit = 100): array
{
    $limit = max(1, min(100, $limit));
    $rows = $pdo->query("SELECT i.partner_id,i.email,i.role,i.expires_at,p.name partner_name,p.code partner_code FROM partner_admin_invitations i JOIN partners p ON p.id=i.partner_id WHERE i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at>NOW() ORDER BY i.created_at DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) $row['partner_id'] = (int)($row['partner_id'] ?? 0);
    unset($row);
    return $rows;
}
