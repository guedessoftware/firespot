<?php

declare(strict_types=1);

require_once __DIR__ . '/control_center_partners.php';

/**
 * Resumo gerencial da Central. Pontos aparecem somente como agregados por
 * estabelecimento; esta consulta não forma nem expõe um inventário global.
 *
 * @return array{
 *   partners:array<string,int>,
 *   plans:list<array{name:string,total:int}>,
 *   quotas_near_limit:int,
 *   infrastructure_pending:int,
 *   infrastructure_failed:int,
 *   wallet_alerts:int
 * }
 */
function fs_control_center_management_overview(PDO $pdo): array
{
    $partners = fs_control_center_partner_kpis($pdo);

    $planRows = $pdo->query("SELECT COALESCE(pp.name,'Sem plano') name,COUNT(*) total
        FROM partners p
        LEFT JOIN partner_subscriptions s ON s.partner_id=p.id AND s.is_current=1
        LEFT JOIN platform_plans pp ON pp.id=s.plan_id
        WHERE p.active=1
        GROUP BY COALESCE(pp.name,'Sem plano')
        ORDER BY total DESC,name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $plans = [];
    foreach ($planRows as $row) {
        $plans[] = ['name'=>(string)$row['name'],'total'=>(int)$row['total']];
    }

    $quotasNearLimit = (int)$pdo->query("SELECT COUNT(*) FROM (
        SELECT p.id,
            COALESCE(pp.max_hotspots,0) max_hotspots,
            COALESCE(pp.max_admin_users,0) max_admin_users,
            COALESCE(pp.custom_courtesy_overrides,0) max_courtesy_overrides,
            COALESCE(hu.used_hotspots,0) used_hotspots,
            COALESCE(au.used_admins,0) used_admins,
            COALESCE(cu.used_overrides,0) used_overrides
        FROM partners p
        JOIN partner_subscriptions s ON s.partner_id=p.id AND s.is_current=1
        JOIN platform_plans pp ON pp.id=s.plan_id
        LEFT JOIN (
            SELECT partner_id,COUNT(*) used_hotspots
            FROM partner_hotspots
            WHERE management_state<>'retired'
            GROUP BY partner_id
        ) hu ON hu.partner_id=p.id
        LEFT JOIN (
            SELECT m.partner_id,COUNT(*) used_admins
            FROM partner_admin_memberships m
            JOIN host_users u ON u.id=m.user_id AND u.active=1
            WHERE m.active=1
            GROUP BY m.partner_id
        ) au ON au.partner_id=p.id
        LEFT JOIN (
            SELECT partner_id,COUNT(DISTINCT hotspot_id) used_overrides
            FROM courtesy_hotspot_policy_overrides
            WHERE state IN ('draft','published')
            GROUP BY partner_id
        ) cu ON cu.partner_id=p.id
        WHERE p.active=1 AND s.status IN ('trial','active','grace')
    ) quota_usage
    WHERE (max_hotspots>0 AND used_hotspots*100>=max_hotspots*80)
       OR (max_admin_users>0 AND used_admins*100>=max_admin_users*80)
       OR (max_courtesy_overrides>0 AND used_overrides*100>=max_courtesy_overrides*80)")->fetchColumn();

    $infrastructure = $pdo->query("SELECT
            SUM(CASE WHEN status IN ('queued','running','retry') THEN 1 ELSE 0 END) pending,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed
        FROM hotspot_change_requests")->fetch(PDO::FETCH_ASSOC) ?: [];

    $walletAlerts = (int)$pdo->query("SELECT COUNT(*)
        FROM payment_wallets
        WHERE active=1
          AND (access_token_validated_at IS NULL
            OR webhook_secret_encrypted IS NULL
            OR webhook_secret_encrypted=''
            OR webhook_secret_validated_at IS NULL)")->fetchColumn();

    return [
        'partners'=>$partners,
        'plans'=>$plans,
        'quotas_near_limit'=>$quotasNearLimit,
        'infrastructure_pending'=>(int)($infrastructure['pending'] ?? 0),
        'infrastructure_failed'=>(int)($infrastructure['failed'] ?? 0),
        'wallet_alerts'=>$walletAlerts,
    ];
}
