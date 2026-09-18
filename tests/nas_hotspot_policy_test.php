<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=dirname(__DIR__);
require_once $root.'/app/db.php';
require_once $root.'/app/partner_infrastructure.php';
require_once $root.'/app/cli/migration_framework.php';

$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$migration=(string)file_get_contents($root.'/migrations/055_nas_hotspot_allocation_policies.sql');
$domain=(string)file_get_contents($root.'/app/partner_hotspots.php').(string)file_get_contents($root.'/app/partner_infrastructure.php');
$page=(string)file_get_contents($root.'/portal/host/pages/infrastructure.php');
$actions=(string)file_get_contents($root.'/portal/host/panel_actions.php');
$central=(string)file_get_contents($root.'/dashboard/nas.php').(string)file_get_contents($root.'/app/control_center_partners.php');
$deploy=(string)file_get_contents($root.'/app/cli/nas_hotspot_policy_deploy_check.php');
$finalizer=(string)file_get_contents('/opt/firespot-ops/firespot_nas_hotspot_policy_finalize_root.sh');

$expect(str_contains($migration,'nas_hotspot_allocation_policies')&&substr_count($migration,'network_prefix_length')>=4&&str_contains($migration,'requested_dns_servers'),'Migração 055 não separa política do NAS, máscara do ponto e DNS desejado.');
$expect(!str_contains($migration,'SET gateway_ip=')&&!str_contains($migration,'SET vlan_id='),'Migração 055 tenta renumerar uma configuração efetiva existente.');
$expect(str_contains($domain,'function fs_nas_hotspot_policy_save')&&str_contains($domain,'function fs_partner_nas_hotspot_policy_save'),'Política central e política limitada ao NAS próprio não possuem serviços separados.');
$expect(str_contains($domain,'function fs_nas_hotspot_radius_host')&&str_contains($domain,"\$data['radius_ip']=fs_nas_hotspot_radius_host"),'Ponto ainda aceita um destino RADIUS independente da base do NAS.');
$expect(str_contains($domain,"'partner_admin'")&&str_contains($domain,'fs_partner_nas_assert_owned'),'Cliente pode alterar política de um NAS que não lhe pertence.');
$expect(str_contains($actions,"'nas.hotspot_policy_updated'")&&str_contains($actions,'partner_admin_require_current_password'),'Alteração da política não exige senha atual ou não gera auditoria.');
$expect(str_contains($page,"\$infrastructureView==='nas'")&&str_contains($page,"\$infrastructureView==='hotspots'")&&str_contains($page,'Pontos Hotspot')&&str_contains($page,'Política de pontos de'),'Portal não explica nem expõe corretamente a separação NAS/ponto.');
$expect(str_contains($page,'data-host-allocation-preview')&&str_contains($page,'data-next-allocation'),'Novo ponto não antecipa a VLAN e a rede calculadas pelo NAS.');
$expect(str_contains($central,'Primeira VLAN dos pontos')&&str_contains($central,'RADIUS da base FireSpot'),'Central não administra no mesmo NAS a base RADIUS e a política dos pontos.');
$expect(!preg_match('/ros_exec|ssh2_connect|fs_nas_base_provision\s*\(/',(string)substr($domain,strpos($domain,'function fs_partner_nas_hotspot_policy_save'),700)),'Salvar política local pode alcançar o RouterOS.');
$expect(str_contains($deploy,'hotspot-digest')&&str_contains($deploy,'nas-base-digest')&&str_contains($deploy,'queue-digest'),'Verificação de implantação não prova a preservação dos pontos, RADIUS/NAS e fila remota.');
$hotspotDigestSource=(string)substr($deploy,(int)strpos($deploy,'$hotspotDigest'),(int)strpos($deploy,'$baseDigest')-(int)strpos($deploy,'$hotspotDigest'));
$expect(!str_contains($hotspotDigestSource,'updated_at')&&!str_contains($hotspotDigestSource,'created_at'),'Digest de configuração efetiva ainda trata timestamps tocados pelo backfill como mudança funcional.');
$expect(str_contains($page,'host-nas-grid')&&str_contains($page,'host-nas-card')&&!str_contains($page,'host-infrastructure__nas"><thead'),'Portal ainda força os NAS em uma tabela horizontal larga.');
$expect(str_contains($finalizer,'somente a política versionada 055')&&str_contains($finalizer,'Nenhuma ação foi enviada ao RouterOS'),'Finalizador não limita o escopo à migração 055 sem operação remota.');

$default=fs_nas_hotspot_policy_validate(fs_nas_hotspot_policy_default());
$network24=fs_partner_hotspot_network_suggestion(101,$default);
$expect($network24['cidr']==='10.101.0.0/24'&&$network24['gateway_ip']==='10.101.0.1'&&$network24['pool_end']==='10.101.0.254','Exemplo VLAN 101 /24 divergiu do contrato informado.');
$policy22=$default;$policy22['prefix_length']=22;
$network22=fs_partner_hotspot_network_suggestion(101,$policy22);
$expect($network22['cidr']==='10.101.0.0/22'&&$network22['gateway_ip']==='10.101.0.1'&&$network22['pool_end']==='10.101.3.254','Exemplo /22 não calculou a expansão correta do terceiro octeto.');
foreach([
    array_replace($default,['vlan_start'=>0]),
    array_replace($default,['network_template'=>'192.168.{vlan}.0']),
    array_replace($default,['prefix_length'=>31]),
    array_replace($default,['gateway_offset'=>2,'pool_start_offset'=>2]),
] as $invalid){try{fs_nas_hotspot_policy_validate($invalid);$blocked=false;}catch(InvalidArgumentException $error){$blocked=true;}$expect($blocked,'Política inválida foi aceita.');}

$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$historyPartnerId=(int)$pdo->query('SELECT id FROM partners ORDER BY id LIMIT 1')->fetchColumn();
if($historyPartnerId>0){
    $nasHistory=fs_partner_change_requests($pdo,$historyPartnerId,100,'nas');
    $hotspotHistory=fs_partner_change_requests($pdo,$historyPartnerId,100,'hotspots');
    $expect(count(array_filter($nasHistory,static fn(array $row):bool=>!str_starts_with((string)$row['operation'],'nas_')))===0,'Histórico da página NAS recebeu operação de ponto.');
    $expect(count(array_filter($hotspotHistory,static fn(array $row):bool=>!str_starts_with((string)$row['operation'],'hotspot_')))===0,'Histórico da página Pontos Hotspot recebeu operação de NAS.');
}
$installed=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version=55 AND state='applied'")->fetchColumn()===1;
if($installed){
    $expect(fs_nas_hotspot_policy_schema_ready($pdo)&&fs_partner_hotspot_network_prefix_schema_ready($pdo),'Ledger 055 existe, mas o contrato não está legível.');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM nas n LEFT JOIN nas_hotspot_allocation_policies policy ON policy.nas_id=n.id WHERE policy.nas_id IS NULL')->fetchColumn()===0,'Existe NAS sem política de novos pontos.');
    $before=$pdo->query("SELECT h.name,n.shortname,h.vlan_id,h.gateway_ip,h.pool_start,h.pool_end FROM partner_hotspots h JOIN partners p ON p.id=h.partner_id JOIN nas n ON n.id=h.nas_id WHERE p.code='00000001' AND h.name IN ('Principal','flutuante','TESTE') ORDER BY h.id")->fetchAll(PDO::FETCH_ASSOC);
    $nasId=(int)$pdo->query('SELECT nas_id FROM nas_hotspot_allocation_policies ORDER BY nas_id LIMIT 1')->fetchColumn();
    $original=fs_nas_hotspot_policy($pdo,$nasId);
    $pdo->beginTransaction();
    try{$changed=$original;$changed['prefix_length']=$original['prefix_length']===24?22:24;fs_nas_hotspot_policy_save($pdo,$nasId,$changed,'system',null);$expect((int)fs_nas_hotspot_policy($pdo,$nasId,true)['prefix_length']===(int)$changed['prefix_length'],'Política não persistiu sob transação.');$pdo->rollBack();}
    catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    $after=$pdo->query("SELECT h.name,n.shortname,h.vlan_id,h.gateway_ip,h.pool_start,h.pool_end FROM partner_hotspots h JOIN partners p ON p.id=h.partner_id JOIN nas n ON n.id=h.nas_id WHERE p.code='00000001' AND h.name IN ('Principal','flutuante','TESTE') ORDER BY h.id")->fetchAll(PDO::FETCH_ASSOC);
    $expect($before===$after,'Alterar a política do NAS renumerou pontos existentes.');
}else{
    $expect(true,fs_nas_hotspot_policy_schema_ready($pdo)?'Retomada pós-DDL disponível.':'Estado pré-v055 apto à aplicação.');
}

echo 'OK: '.$checks.' verificações da separação entre NAS e pontos'.($installed?' e política transacional.':' (aguarda a migração 055).')."\n";
