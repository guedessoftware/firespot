<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/partner_entitlements.php';

$checks=0;
$assert=function(bool $condition,string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

foreach (['trial','active'] as $status) {
    $assert(fs_partner_subscription_status_allows($status,false),$status . ' deve permitir leitura.');
    $assert(fs_partner_subscription_status_allows($status,true),$status . ' deve permitir mutação.');
}
foreach (['grace','past_due','suspended'] as $status) {
    $assert(fs_partner_subscription_status_allows($status,false),$status . ' deve preservar leitura.');
    $assert(!fs_partner_subscription_status_allows($status,true),$status . ' deve bloquear mutação.');
}
$assert(!fs_partner_subscription_status_allows('ended',false),'Plano encerrado não deve manter entitlement avançado.');
$assert(!fs_partner_subscription_status_allows('ended',true),'Plano encerrado não deve permitir mutação.');
$pilotDb=new PDO('sqlite::memory:');$pilotDb->exec('CREATE TABLE app_settings (skey TEXT PRIMARY KEY,svalue TEXT NOT NULL)');
$assert(!fs_partner_hotspot_apply_pilot_approved($pilotDb),'Piloto remoto nasceu aprovado sem evidência.');
$pilotDb->exec("INSERT INTO app_settings VALUES ('partner_hotspot_apply_pilot_approved','1')");
$assert(fs_partner_hotspot_apply_pilot_approved($pilotDb),'A trava global não reconhece uma aprovação explícita do piloto.');

$features=fs_partner_feature_labels();
foreach (['nas.manage','nas.retire','hotspots.apply','wallet.manage','courtesy.manage','reports.advanced'] as $feature) {
    $assert(isset($features[$feature]),'Função obrigatória não catalogada: ' . $feature);
}
$modules=fs_partner_module_features();
$assert(($modules['infrastructure']??null)==='hotspots.view','Infraestrutura deve exigir visualização de instalações.');
$assert(($modules['analytics']??null)==='reports.advanced','Analytics deve exigir relatórios avançados.');
$assert(($modules['billing']??null)==='wallet.manage','Carteiras precisam permanecer protegidas pelo entitlement financeiro do plano máximo.');
foreach (['nas.manage','hotspots.apply','courtesy.manage','reports.advanced'] as $feature) {
    $assert(!in_array($feature,fs_partner_legacy_fallback_features(),true),'Fallback legado liberou função avançada: ' . $feature);
}
$overrides=fs_partner_override_catalog();
$assert(isset($overrides['quota.max_nas'],$overrides['quota.max_hotspots'],$overrides['quota.max_report_range_days']),'Catálogo de exceções não inclui as cotas comerciais tipadas.');
$assert(fs_partner_nas_self_service_allowed(),'Política não reconhece o autosserviço contratual de NAS próprio.');
foreach(['nas.manage','nas.prepare','nas.retire'] as $feature)$assert(!fs_partner_feature_is_central_only($feature),'NAS próprio permaneceu bloqueado antes da decisão por plano: '.$feature);
$serviceSource=(string)file_get_contents(__DIR__.'/../app/partner_entitlements.php');
$assert(str_contains($serviceSource,"plan_code']??'')!=='multipoint_advanced'")&&str_contains($serviceSource,"fs_partner_has_entitlement(\$pdo,\$partnerId,'wallet.manage',\$mutation)"),'A ativação da independência financeira não está vinculada ao plano máximo e ao entitlement de carteira.');
$dashboardSource=(string)file_get_contents(__DIR__.'/../dashboard/estabelecimento.php');
$dashboardActionSource=(string)file_get_contents(__DIR__.'/../dashboard/actions/partner.php');
$partnerAdminSource=(string)file_get_contents(__DIR__.'/../app/partner_admin.php');
$assert(str_contains($serviceSource,"ORDER BY id DESC LIMIT 1")&&!str_contains($serviceSource,"AND (valid_until IS NULL OR valid_until>NOW())\n        ORDER BY id DESC LIMIT 1"),'Override expirado pode reativar silenciosamente uma exceção antiga.');
$assert(str_contains($dashboardSource,'feature_override_save')&&str_contains($dashboardActionSource,"admin_require_capability('partner.subscriptions.manage')"),'Central não protege a administração de exceções contratuais.');
$assert(str_contains($partnerAdminSource,"if(\$actorType==='partner_admin')fs_partner_require_quota(\$pdo,\$partnerId,'max_admin_users',1)")&&str_contains($partnerAdminSource,"(int)\$current['active']!==1&&\$active===1"),'Convite ou reativação concorrente pode ultrapassar a cota de equipe.');
$migrationSource=(string)file_get_contents(__DIR__.'/../migrations/045_platform_entitlements.sql');
$assert(str_contains($migrationSource,"SELECT id,'hotspots.apply',0")&&str_contains($migrationSource,'ON DUPLICATE KEY UPDATE enabled=0')&&str_contains($migrationSource,"partner_hotspot_apply_pilot_approved','0"),'Aplicação remota não nasce fechada nos gates global e por estabelecimento.');
$pilotCli=(string)file_get_contents(dirname(__DIR__).'/app/cli/partner_hotspot_pilot_gate.php');
$assert(str_contains($pilotCli,'posix_geteuid()!==0')&&str_contains($pilotCli,'PHYSICAL-PILOT-APPROVED'),'A abertura da trava global não exige root e confirmação explícita do piloto físico.');
$assert(str_contains($pilotCli,'ledger 49/49 íntegro')&&str_contains($pilotCli,"status IN ('queued','retry','running')")&&str_contains($pilotCli,'NAS_CREDENTIAL_KEY'),'A abertura da trava global não revalida release, fila e chave de NAS.');
$assert(str_contains($pilotCli,'partner_hotspot_apply_pilot_evidence')&&str_contains($pilotCli,'beginTransaction()')&&str_contains($pilotCli,'evidence_sha256'),'A mudança da trava global não preserva evidência ou não é transacional.');
$smoke045=(string)file_get_contents(__DIR__.'/../migrations/smoke_045.php');
$assert(str_contains($smoke045,"'portal.basic','branding.manage'")&&!str_contains($smoke045,'array_keys(fs_partner_feature_labels())')&&!str_contains($smoke045,"'portal.presentation.manage'")&&str_contains($smoke045,"(int)\$applyGate!==0")&&str_contains($smoke045,"f.feature_code IN ('ads.manage','monetization.view')"),'Smoke 045 não está isolado ao seu contrato histórico, contradiz o gate de piloto ou não protege os add-ons separados.');
$assert(str_contains($smoke045,'$latestVersion=max(array_keys($multipoint))')&&str_contains($smoke045,'$activeVersions!==[$latestVersion]')&&str_contains($smoke045,"\$multipoint[1]!==0"),'Smoke 045 não aceita ou valida a sucessão versionada do plano Multipontos.');
$assert(str_contains($smoke045,"code='multipoint_advanced' AND version=1 AND (max_nas<1"),'Smoke 045 mistura as cotas históricas da v1 com o NAS centralizado da v2.');
$migration053=(string)file_get_contents(__DIR__.'/../migrations/053_partner_owned_nas_self_service.sql');
$adoption053=(string)file_get_contents(__DIR__.'/../app/cli/adopt_example_partner_partner_nas.php');
$assert(str_contains($migration053,"'multipoint_advanced',3")&&str_contains($migration053,"'nas.manage',0")&&str_contains($migration053,"'hotspots.apply',0")&&str_contains($adoption053,"feature_code IN ('nas.manage','nas.prepare','nas.retire')"),'Plano Multipontos v3 não mantém as mutações fechadas até concluir a adoção atômica.');
$assert(str_contains($migration053,'10,25,10,366,25'),'Plano Multipontos v3 perdeu a cota de 10 NAS próprios ou 25 pontos.');

echo "OK: {$checks} verificações de entitlements do portal.\n";
