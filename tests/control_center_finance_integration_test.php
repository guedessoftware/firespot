<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=dirname(__DIR__);
require_once $root.'/app/db.php';
require_once $root.'/app/control_center_finance.php';

$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

$delivery=fs_control_center_deliveries($pdo,['per_page'=>10]);
$paid=(int)$pdo->query("SELECT COUNT(*) FROM guest_orders WHERE status='paid'")->fetchColumn();
$expect((int)$delivery['kpis']['paid']===$paid,'Total de pagamentos não concilia com as entregas.');
$expect((int)$delivery['kpis']['delivered']+(int)$delivery['kpis']['pending']+(int)$delivery['kpis']['manual_review']===$paid,'Estados de entrega não conciliam com pagamentos pagos.');
$expect($delivery['total']===$paid&&count($delivery['rows'])<=10,'Consulta de entrega ignora paginação ou estado financeiro.');
foreach($delivery['rows'] as $row){
    $expect((string)$row['delivery']['group']===fs_control_center_delivery_state($row)['group'],'Classificação de entrega divergiu dentro da mesma consulta.');
    $expect(!array_key_exists('radius_username',$row)&&!array_key_exists('device_mac',$row)&&!array_key_exists('device_ip',$row),'Conciliação expôs identificador técnico do visitante.');
}
foreach(['delivered','pending','manual_review'] as $state){
    $filtered=fs_control_center_deliveries($pdo,['state'=>$state,'per_page'=>100]);
    foreach($filtered['rows'] as $row)$expect($row['delivery']['group']===$state,'Filtro de entrega retornou outro estado.');
}

$navigation=(string)file_get_contents($root.'/app/control_center_navigation.php');
$finance=(string)file_get_contents($root.'/dashboard/financeiro.php');
$receipts=(string)file_get_contents($root.'/dashboard/recebimentos.php');
foreach(['Visão geral','Vendas','Carteiras','Recebimentos','Conciliação e entrega','Repasses','Auditoria','Configurações'] as $label)$expect(str_contains($navigation,$label),'Aba financeira ausente: '.$label);
$expect(str_contains($finance,"section==='delivery'")&&str_contains($finance,'Nenhuma retentativa ou CoA é disparada'),'Workspace financeiro não possui conciliação somente-leitura.');
$expect(str_contains($receipts,'fs_control_center_delivery_state($order)'),'Recebimentos duplicou a regra de classificação da entrega.');
$expect(!str_contains((string)file_get_contents($root.'/app/control_center_finance.php'),'access_token_encrypted')&&!str_contains((string)file_get_contents($root.'/app/control_center_finance.php'),'webhook_secret_encrypted'),'Conciliação consulta credenciais de carteira.');

echo "OK: {$checks} verificações da conciliação financeira.\n";
