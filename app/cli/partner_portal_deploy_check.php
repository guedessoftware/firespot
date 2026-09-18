#!/usr/bin/env php
<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../db.php';
require_once __DIR__.'/../env.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/migration_framework.php';

$mode=(string)($argv[1]??'--preflight');
$allowed=['--preflight','--database-name','--backup-tables','--nas-key-length','--active-queue'];
if(!in_array($mode,$allowed,true)){
    fwrite(STDERR,"Uso: php app/cli/partner_portal_deploy_check.php [--preflight|--database-name|--backup-tables|--nas-key-length|--active-queue]\n");
    exit(64);
}

try{
    if($mode==='--database-name'){
        $database=defined('DB_DATABASE')?(string)DB_DATABASE:'';
        if(!preg_match('/^[A-Za-z0-9_]+$/',$database))throw new RuntimeException('Nome de banco inválido.');
        echo $database.PHP_EOL;
        exit(0);
    }
    if($mode==='--nas-key-length'){
        echo strlen(trim((string)env('NAS_CREDENTIAL_KEY',''))).PHP_EOL;
        exit(0);
    }

    $runtime=db();
    if($mode==='--backup-tables'){
        $wanted=[
            'partners','partner_hotspots','nas','nas_interfaces','nas_base_provisioning','courtesy_partner_policies','payment_wallets',
            'partner_admin_memberships','partner_admin_audit','schema_migrations','app_settings',
            'platform_plans','platform_plan_features','partner_subscriptions','partner_feature_overrides','partner_subscription_events',
            'partner_nas_ownerships','hotspot_change_requests','partner_network_reservations',
            'courtesy_policy_revisions','courtesy_hotspot_policy_overrides',
            'partner_portal_daily_events','partner_daily_metrics','partner_daily_metric_reasons','partner_analytics_runs',
        ];
        $placeholders=implode(',',array_fill(0,count($wanted),'?'));
        $query=$runtime->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$placeholders.')');
        $query->execute($wanted);
        $existing=array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN)?:[],true);
        foreach($wanted as $table)if(isset($existing[$table]))echo $table.PHP_EOL;
        exit(0);
    }
    if($mode==='--active-queue'){
        $queue=$runtime->prepare('SELECT COUNT(*) FROM hotspot_change_requests WHERE status IN (?,?,?)');
        $queue->execute(['queued','retry','running']);
        echo (int)$queue->fetchColumn().PHP_EOL;
        exit(0);
    }

    $inventory=fs_migration_inventory(dirname(__DIR__,2).'/migrations');
    $ledger=fs_migration_ledger($runtime);
    $expectedInventory=range(1,53);
    if(array_keys($inventory)!==$expectedInventory)throw new RuntimeException('Inventário inesperado; esperadas exatamente as migrações 001-053.');
    $errors=fs_migration_validate_ledger($inventory,$ledger);
    if($errors)throw new RuntimeException('Ledger inválido: '.implode(',',$errors));
    for($version=1;$version<=44;$version++)if(!isset($ledger[$version]))throw new RuntimeException(sprintf('Migração base %03d ausente do ledger.',$version));

    $advanced=range(45,49);
    $registered=array_values(array_filter($advanced,static fn(int $version):bool=>isset($ledger[$version])));
    if($registered!==array_slice($advanced,0,count($registered)))throw new RuntimeException('Ledger avançado não forma um prefixo contínuo entre 045 e 049.');
    $pending=array_values(array_diff($advanced,$registered));
    echo 'pending_migrations_045_049='.($pending?implode(',',array_map(static fn(int $version):string=>sprintf('%03d',$version),$pending)):'none').PHP_EOL;

    // O runner histórico usa --apply sem intervalo. Depois que 050-053 passaram
    // a existir, reexecutá-lo poderia instalar o Portal V3 visual fora do
    // finalizador dedicado. Aposenta o preflight antes de qualquer backup,
    // chave, serviço, worker ou migração.
    if($pending)throw new RuntimeException('O finalizador 045-049 foi aposentado com migrações daquele intervalo ainda ausentes; faça recuperação operacional específica.');
    $newPending=array_values(array_filter(range(50,53),static fn(int $version):bool=>!isset($ledger[$version])));
    throw new RuntimeException('O finalizador 045-049 já foi concluído e não pode ser reexecutado. Pendências atuais: '.($newPending?implode(',',array_map(static fn(int $version):string=>sprintf('%03d',$version),$newPending)):'nenhuma').'. Use firespot_portal_v3_skins_finalize_root.sh somente quando autorizado.');

    $duplicateWallets=(int)$runtime->query('SELECT COUNT(*) FROM (SELECT partner_id FROM payment_wallets WHERE partner_id IS NOT NULL AND active=1 GROUP BY partner_id HAVING COUNT(*)>1) duplicated')->fetchColumn();
    if($duplicateWallets!==0)throw new RuntimeException('Há estabelecimento com mais de uma carteira própria ativa; a migração 049 foi interrompida antes do DDL.');
    $invalidWalletBindings=(int)$runtime->query('SELECT COUNT(*) FROM partners p LEFT JOIN payment_wallets w ON w.id=p.payment_wallet_id AND w.partner_id=p.id AND w.active=1 WHERE p.independent_billing=1 AND (p.payment_wallet_id IS NULL OR w.id IS NULL)')->fetchColumn();
    if($invalidWalletBindings!==0)throw new RuntimeException('Há recebimento independente ligado a carteira ausente, inativa ou de outro estabelecimento; corrija antes da migração 049.');

    $activeWithoutNas=(int)$runtime->query('SELECT COUNT(*) FROM partner_hotspots WHERE active=1 AND nas_id IS NULL')->fetchColumn();
    $access=$runtime->prepare("SELECT COUNT(*) FROM partner_hotspots h WHERE h.active=1 AND h.nas_id IS NULL AND (
        EXISTS (SELECT 1 FROM guest_orders g WHERE g.hotspot_id=h.id AND g.partner_id=h.partner_id AND g.status=? AND g.radius_cleaned_at IS NULL)
        OR EXISTS (SELECT 1 FROM courtesy_grants c WHERE c.hotspot_id=h.id AND c.partner_id=h.partner_id AND c.status IN (?,?,?))
        OR EXISTS (SELECT 1 FROM subscriber_access_grants s WHERE s.hotspot_id=h.id AND s.partner_id=h.partner_id AND s.status=?)
    )");
    $access->execute(['paid','reserved','provisioning','active','active']);
    if((int)$access->fetchColumn()!==0)throw new RuntimeException('Há instalação ativa sem NAS ainda ligada a acesso vigente; a reconciliação automática foi bloqueada.');
    echo "active_hotspots_without_nas_to_reconcile={$activeWithoutNas}".PHP_EOL;

    $pilotGate=$runtime->prepare('SELECT svalue FROM app_settings WHERE skey=? LIMIT 1');
    $pilotGate->execute(['partner_hotspot_apply_pilot_approved']);
    if((string)($pilotGate->fetchColumn()?:'0')==='1')throw new RuntimeException('A trava global de aplicação já está aberta, mas o piloto físico permanece pendente neste rollout.');
}catch(Throwable $error){
    fwrite(STDERR,'PRECHECK_FAILED: '.$error->getMessage().PHP_EOL);
    exit(2);
}
