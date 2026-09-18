#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../partner_entitlements.php';
require_once __DIR__ . '/migration_framework.php';

date_default_timezone_set('America/Manaus');

$option = static function (string $name) use ($argv): ?string {
    $prefix='--'.$name.'=';
    foreach ($argv as $argument) if (str_starts_with((string)$argument,$prefix)) return substr((string)$argument,strlen($prefix));
    return null;
};
$approve=in_array('--approve',$argv,true);
$close=in_array('--close',$argv,true);
$status=in_array('--status',$argv,true)||(!$approve&&!$close);
if(($approve&&$close)||($status&&($approve||$close))){
    fwrite(STDERR,"Use somente --status, --approve ou --close.\n");
    exit(64);
}

$pdo=db();
$readSetting=static function(string $key)use($pdo):string{
    $statement=$pdo->prepare('SELECT svalue FROM app_settings WHERE skey=? LIMIT 1');
    $statement->execute([$key]);
    return (string)($statement->fetchColumn()?:'');
};

if($status){
    $approved=$readSetting('partner_hotspot_apply_pilot_approved')==='1';
    $changedAt=$readSetting('partner_hotspot_apply_pilot_changed_at');
    $evidence=$readSetting('partner_hotspot_apply_pilot_evidence');
    printf("pilot_gate=%s changed_at=%s evidence_sha256=%s\n",$approved?'open':'closed',$changedAt!==''?$changedAt:'never',$evidence!==''?hash('sha256',$evidence):'none');
    exit(0);
}

if(!function_exists('posix_geteuid')||posix_geteuid()!==0){
    fwrite(STDERR,"A alteração da trava global exige root. Use sudo.\n");
    exit(77);
}

$evidence=trim((string)($option('evidence')??''));
if(strlen($evidence)<12||strlen($evidence)>500||preg_match('/[\x00-\x1F\x7F]/',$evidence)){
    fwrite(STDERR,"Informe --evidence com 12 a 500 caracteres imprimíveis e uma referência auditável.\n");
    exit(64);
}
$expectedConfirmation=$approve?'PHYSICAL-PILOT-APPROVED':'CLOSE-REMOTE-APPLY';
if(!hash_equals($expectedConfirmation,(string)($option('confirm')??''))){
    fwrite(STDERR,"Confirmação inválida. Use --confirm={$expectedConfirmation}.\n");
    exit(64);
}

if(!fs_partner_entitlements_schema_ready($pdo))throw new RuntimeException('A migração de planos ainda não foi aplicada.');
$inventory=fs_migration_inventory(dirname(__DIR__,2).'/migrations');
$ledger=fs_migration_ledger($pdo);
$ledgerErrors=fs_migration_validate_ledger($inventory,$ledger);
if(count($inventory)!==49||count($ledger)!==49||$ledgerErrors)throw new RuntimeException('A trava só pode mudar com ledger 49/49 íntegro.');

if($approve){
    if(strlen(trim((string)env('NAS_CREDENTIAL_KEY','')))<32)throw new RuntimeException('A chave exclusiva das credenciais de NAS não está pronta.');
    $activeWithoutNas=(int)$pdo->query('SELECT COUNT(*) FROM partner_hotspots WHERE active=1 AND nas_id IS NULL')->fetchColumn();
    if($activeWithoutNas!==0)throw new RuntimeException('Ainda existe instalação ativa sem NAS.');
    $activeQueue=(int)$pdo->query("SELECT COUNT(*) FROM hotspot_change_requests WHERE status IN ('queued','retry','running')")->fetchColumn();
    if($activeQueue!==0)throw new RuntimeException('A fila de infraestrutura precisa estar vazia antes de abrir o piloto.');
    $baseGate=$pdo->query("SELECT f.enabled FROM platform_plan_features f JOIN platform_plans p ON p.id=f.plan_id WHERE p.code='multipoint_advanced' AND p.version=1 AND f.feature_code='hotspots.apply' LIMIT 1")->fetchColumn();
    if($baseGate===false||(int)$baseGate!==0)throw new RuntimeException('O entitlement base de aplicação precisa permanecer fechado; use somente override por estabelecimento.');
}

$pdo->beginTransaction();
try{
    $lock=$pdo->prepare("SELECT svalue FROM app_settings WHERE skey='partner_hotspot_apply_pilot_approved' LIMIT 1 FOR UPDATE");
    $lock->execute();
    $write=$pdo->prepare('INSERT INTO app_settings (skey,svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
    $write->execute(['partner_hotspot_apply_pilot_approved',$approve?'1':'0']);
    $write->execute(['partner_hotspot_apply_pilot_evidence',$evidence]);
    $write->execute(['partner_hotspot_apply_pilot_changed_at',date('Y-m-d H:i:s')]);
    $actor=trim((string)getenv('SUDO_USER'));
    $write->execute(['partner_hotspot_apply_pilot_changed_by',$actor!==''?substr($actor,0,100):'root']);
    $pdo->commit();
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    throw $error;
}

printf("OK: trava global de aplicação remota %s; evidence_sha256=%s\n",$approve?'aberta':'fechada',hash('sha256',$evidence));
