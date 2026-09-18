<?php

declare(strict_types=1);

require_once __DIR__.'/../app/db.php';require_once __DIR__.'/../app/control_center_plans.php';
$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$catalog=fs_control_center_platform_plan_versions($pdo);$expect(count($catalog)>=3,'Catálogo FireSpot incompleto.');
$codes=array_unique(array_column($catalog,'code'));$expect(in_array('essential',$codes,true)&&in_array('multipoint_advanced',$codes,true),'Planos comerciais essenciais ausentes.');
foreach($catalog as $plan){$expect((int)$plan['version']>=1,'Plano sem versão válida.');$expect(is_array($plan['features']),'Recursos do plano não foram agregados.');}
$subscriptions=fs_control_center_subscriptions($pdo,['per_page'=>10]);$expect(count($subscriptions['rows'])<=10,'Paginação de assinaturas excedeu o limite.');
$totalPartners=(int)$pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn();$expect($subscriptions['total']===$totalPartners,'Visão de assinaturas não cobre os estabelecimentos.');
foreach($subscriptions['rows'] as $row){$expect((int)$row['id']>0,'Assinatura sem estabelecimento.');$expect(!array_key_exists('gateway_ip',$row)&&!array_key_exists('pool_start',$row),'Visão de contratos vazou detalhes de ponto.');$near=($row['max_hotspots']>0&&$row['used_hotspots']*100>=$row['max_hotspots']*80)||($row['max_admin_users']>0&&$row['used_admins']*100>=$row['max_admin_users']*80)||($row['custom_courtesy_overrides']>0&&$row['used_overrides']*100>=$row['custom_courtesy_overrides']*80);$expect((bool)$row['quota_alert']===$near,'Alerta de cota não segue o limiar de 80%.');}
$kpis=fs_control_center_subscription_kpis($pdo);$expect($kpis['total']===$totalPartners,'KPI de assinaturas não concilia.');
$access=fs_control_center_access_plan_kpis($pdo);$expect($access['active']+$access['inactive']===$access['total'],'Catálogo global de acesso não concilia.');
$partnerAccess=fs_control_center_partner_access_plans($pdo,['per_page'=>10]);$partnerAccessTotal=(int)$pdo->query('SELECT COUNT(*) FROM partner_payment_plans')->fetchColumn();$expect($partnerAccess['total']===$partnerAccessTotal&&count($partnerAccess['rows'])<=10,'Consolidação de planos próprios não concilia ou ignora paginação.');
foreach($partnerAccess['rows'] as $row){$expect((int)$row['partner_id']>0&&!array_key_exists('gateway_ip',$row),'Plano próprio saiu do contexto do estabelecimento ou vazou rede.');}
echo 'OK: '.$checks." verificações integradas da Central de Planos.\n";
