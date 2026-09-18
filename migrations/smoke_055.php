<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/partner_hotspots.php';
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

$table=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nas_hotspot_allocation_policies'")->fetchColumn();
if($table!==1)throw new RuntimeException('Política de alocação por NAS ausente.');
$columns=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nas_hotspot_allocation_policies' AND COLUMN_NAME IN ('nas_id','vlan_start','vlan_end','network_template','prefix_length','gateway_offset','pool_start_offset','pool_end_reserve','default_dns_servers')")->fetchColumn();
if($columns!==9)throw new RuntimeException('Contrato da política de alocação incompleto.');
$missing=(int)$pdo->query('SELECT COUNT(*) FROM nas n LEFT JOIN nas_hotspot_allocation_policies policy ON policy.nas_id=n.id WHERE policy.nas_id IS NULL')->fetchColumn();
if($missing!==0)throw new RuntimeException('Existe NAS sem política de criação de pontos.');
$invalid=(int)$pdo->query("SELECT COUNT(*) FROM nas_hotspot_allocation_policies WHERE vlan_start<1 OR vlan_end<vlan_start OR vlan_end>255 OR network_template NOT LIKE '%.{vlan}.%.%' OR prefix_length NOT BETWEEN 16 AND 30 OR gateway_offset<1 OR pool_start_offset<1 OR pool_end_reserve<1")->fetchColumn();
if($invalid!==0)throw new RuntimeException('Existe política de VLAN ou IPv4 fora do contrato.');
$pointPrefix=(int)$pdo->query("SELECT COUNT(*) FROM partner_hotspots WHERE network_prefix_length NOT BETWEEN 16 AND 30")->fetchColumn();
if($pointPrefix!==0)throw new RuntimeException('Existe ponto sem máscara IPv4 explícita.');
$partnerPrefix=(int)$pdo->query("SELECT COUNT(*) FROM partners WHERE network_prefix_length NOT BETWEEN 16 AND 30")->fetchColumn();
if($partnerPrefix!==0)throw new RuntimeException('Existe estabelecimento legado sem máscara explícita do ponto principal.');
$defaultMismatch=(int)$pdo->query('SELECT COUNT(*) FROM partner_hotspots point JOIN partners partner ON partner.id=point.partner_id WHERE point.is_default=1 AND point.network_prefix_length<>partner.network_prefix_length')->fetchColumn();
if($defaultMismatch!==0)throw new RuntimeException('A máscara do ponto principal divergiu do espelho legado do estabelecimento.');
$requestDns=(int)$pdo->query("SELECT COUNT(*) FROM partner_hotspot_configuration_requests WHERE requested_dns_servers IS NULL OR requested_dns_servers='' ")->fetchColumn();
if($requestDns!==0)throw new RuntimeException('Existe solicitação de ponto sem DNS explícito.');

foreach($pdo->query('SELECT * FROM nas_hotspot_allocation_policies ORDER BY nas_id')->fetchAll(PDO::FETCH_ASSOC)?:[] as $policy){
    $validated=fs_nas_hotspot_policy_validate($policy);
    $network=fs_partner_hotspot_network_suggestion((int)$validated['vlan_start'],$validated);
    if((int)$network['vlan_id']!==(int)$validated['vlan_start']||!str_ends_with((string)$network['cidr'],'/'.(int)$validated['prefix_length']))throw new RuntimeException('Prévia de rede da política ficou inconsistente.');
}

$partner=(int)$pdo->query("SELECT id FROM partners WHERE code='00000001' LIMIT 1")->fetchColumn();
$bindings=$pdo->prepare("SELECT h.name,n.shortname,h.gateway_ip,h.network_prefix_length FROM partner_hotspots h JOIN nas n ON n.id=h.nas_id WHERE h.partner_id=? AND h.name IN ('Principal','flutuante','TESTE')");
$bindings->execute([$partner]);$map=[];foreach($bindings->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$map[(string)$row['name']]=$row;
if(($map['Principal']['shortname']??'')!=='Demo-NAS-A'||($map['Principal']['gateway_ip']??'')!=='10.5.5.1'||($map['flutuante']['shortname']??'')!=='Demo-NAS-B'||($map['flutuante']['gateway_ip']??'')!=='10.101.0.1'||($map['TESTE']['shortname']??'')!=='FireSpot'||($map['TESTE']['gateway_ip']??'')!=='10.115.0.1')throw new RuntimeException('A migração alterou a configuração efetiva dos pontos existentes.');

echo "Smoke 055 OK: NAS e pontos separados; política 100-200 configurável sem renumerar instalações existentes.\n";
