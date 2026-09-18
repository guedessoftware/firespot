<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/partner_dashboard.php';

$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$expect(fs_partner_dashboard_server_name('00000001-flutuante',42)==='hs_00000001FLUTUANTE','Servidor Hotspot do ponto secundário foi calculado incorretamente.');
$expect(fs_partner_dashboard_server_name('---',42)==='hs_HOTSPOT42','Fallback do servidor Hotspot não é determinístico.');

$pdo=db();
$partnerId=(int)$pdo->query('SELECT partner_id FROM partner_hotspots GROUP BY partner_id ORDER BY COUNT(*) DESC,partner_id LIMIT 1')->fetchColumn();
$expect($partnerId>0,'Não existe estabelecimento com ponto para validar o resumo.');
$expected=$pdo->prepare('SELECT id,name,code FROM partner_hotspots WHERE partner_id=? ORDER BY is_default DESC,active DESC,name,id');$expected->execute([$partnerId]);$expected=$expected->fetchAll(PDO::FETCH_ASSOC)?:[];
$dashboard=fs_partner_dashboard($pdo,$partnerId,8,false);
$expect(count($dashboard['points'])===count($expected),'Resumo não preservou exatamente os pontos do estabelecimento.');
$expect($dashboard['commerce']===null,'Resumo consultou dados financeiros sem autorização explícita.');
$expectedIds=array_map(static fn(array $row):int=>(int)$row['id'],$expected);
$actualIds=array_map(static fn(array $row):int=>(int)$row['id'],$dashboard['points']);
$expect($actualIds===$expectedIds,'Ordenação ou isolamento dos pontos divergiu do cadastro do estabelecimento.');
foreach(['online_sessions','visitors_today','sessions_today','traffic_today','active_hotspots','total_hotspots'] as $metric)$expect(isset($dashboard['overview'][$metric])&&(int)$dashboard['overview'][$metric]>=0,'Indicador inválido: '.$metric);

$walkKeys=static function(array $value)use(&$walkKeys):array{
    $keys=[];foreach($value as $key=>$item){$keys[]=(string)$key;if(is_array($item))$keys=array_merge($keys,$walkKeys($item));}return $keys;
};
$keys=$walkKeys($dashboard);
foreach(['username','callingstationid','framedipaddress','device_mac','device_ip','account_username','cpf','telefone','email'] as $privateKey)$expect(!in_array($privateKey,$keys,true),'Resumo expõe identificador privado: '.$privateKey);

$withSales=fs_partner_dashboard($pdo,$partnerId,3,true);
$expect(is_array($withSales['commerce'])&&isset($withSales['commerce']['sales_today'],$withSales['commerce']['revenue_today']),'Visão financeira autorizada não retornou o total de hoje.');
$expect(count($withSales['online'])<=3,'Limite de sessões online não foi respeitado.');
echo "OK: {$checks} verificações do resumo operacional do estabelecimento.\n";
