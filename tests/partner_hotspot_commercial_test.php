<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../app/partner_hotspot_commercial.php';

$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};

$defaults=fs_partner_hotspot_commercial_defaults([
    'payment_window_minutes'=>4,
    'payment_window_daily_limit'=>6,
    'payment_window_cooldown_minutes'=>20,
    'payment_window_period_minutes'=>2880,
],[
    'paid_access_enabled'=>1,
    'courtesy_mode'=>'direct',
]);
$expect($defaults['paid_access_enabled']===1&&$defaults['courtesy_mode']==='direct'&&$defaults['payment_window_enabled']===1,'O padrão do ponto não preserva as modalidades publicadas.');
$expect($defaults['payment_window_minutes']===4&&$defaults['payment_window_daily_limit']===6&&$defaults['payment_window_cooldown_minutes']===20&&$defaults['payment_window_period_minutes']===2880,'O padrão do ponto não preserva a política Pix existente.');

$policy=fs_partner_hotspot_commercial_validate([
    'paid_access_enabled'=>'1','courtesy_mode'=>'direct','payment_window_enabled'=>'1',
    'payment_window_minutes'=>'3','payment_window_daily_limit'=>'5',
    'payment_window_cooldown_minutes'=>'15','payment_window_period_hours'=>'48',
]);
$expect($policy['paid_access_enabled']===1&&$policy['courtesy_mode']==='direct'&&$policy['payment_window_enabled']===1,'As três modalidades do ponto não são validadas em conjunto.');
$expect($policy['payment_window_period_minutes']===2880,'A renovação Pix em horas não foi persistida em minutos.');

$disabled=fs_partner_hotspot_commercial_validate([
    'courtesy_mode'=>'disabled','payment_window_minutes'=>'2','payment_window_daily_limit'=>'3',
    'payment_window_cooldown_minutes'=>'10','payment_window_period_hours'=>'24',
]);
$expect($disabled['paid_access_enabled']===0&&$disabled['courtesy_mode']==='disabled'&&$disabled['payment_window_enabled']===0,'O ponto não permite desativar cobrança, cortesia e janela Pix.');

$invalid=false;try{fs_partner_hotspot_commercial_validate([
    'courtesy_mode'=>'disabled','payment_window_enabled'=>'1','payment_window_minutes'=>'2',
    'payment_window_daily_limit'=>'3','payment_window_cooldown_minutes'=>'10','payment_window_period_hours'=>'24',
]);}catch(InvalidArgumentException $error){$invalid=true;}
$expect($invalid,'A janela Pix foi aceita sem cobrança ativa no ponto.');

$context=fs_partner_hotspot_commercial_apply_context([
    'hotspot_commercial_loaded'=>1,'hotspot_payment_window_enabled'=>0,
    'hotspot_payment_window_minutes'=>5,'hotspot_payment_window_daily_limit'=>8,
    'hotspot_payment_window_cooldown_minutes'=>30,'hotspot_payment_window_period_minutes'=>4320,
]);
$expect($context['payment_window_enabled']===0&&$context['payment_window_minutes']===5&&$context['payment_window_period_minutes']===4320,'O contexto do ponto não leva sua própria janela Pix ao runtime.');

$portal=fs_partner_hotspot_commercial_apply_portal_config([
    'paid_access_enabled'=>1,'courtesy_mode'=>'sponsored',
],[
    'hotspot_commercial_loaded'=>1,'hotspot_paid_access_enabled'=>0,'hotspot_courtesy_mode'=>'direct',
]);
$expect($portal['paid_access_enabled']===0&&$portal['courtesy_mode']==='direct','O Portal V3 não recebe as modalidades do ponto resolvido.');

$root=dirname(__DIR__);
$action=(string)file_get_contents($root.'/portal/host/panel_actions.php');
$page=(string)file_get_contents($root.'/portal/host/pages/infrastructure.php');
$runtime=(string)file_get_contents($root.'/app/portal_v3_payment_window.php');
$contextSource=(string)file_get_contents($root.'/app/partner_hotspots.php');
$simulatorSource=(string)file_get_contents($root.'/app/portal_simulator.php');
$deployCheck=(string)file_get_contents($root.'/app/cli/hotspot_commercial_policy_deploy_check.php');
$finalizer=(string)file_get_contents('/opt/firespot-ops/firespot_hotspot_commercial_policy_finalize_root.sh');
$expect(str_contains($action,'hotspot_commercial_save')&&str_contains($action,'fs_partner_courtesy_override_publish')&&str_contains($action,'$pdo->beginTransaction()'),'Cobrança e cortesia do ponto não são publicadas atomicamente.');
$expect(!preg_match('/ros_exec|RouterOSAPI|hotspot_apply\s*\(/',$action),'Salvar regras comerciais pode alcançar operação remota.');
$expect(str_contains($page,'Cobrança por acesso')&&str_contains($page,'name="courtesy_mode"')&&str_contains($page,'name="payment_window_enabled"'),'O modal do ponto não expõe as ativações independentes.');
$expect(str_contains($page,'name="grant_minutes"')&&str_contains($page,'name="payment_window_minutes"')&&str_contains($page,'name="payment_window_period_hours"'),'O modal do ponto não expõe os tempos de cortesia e Pix.');
$expect(str_contains($runtime,'fs_v3_payment_window_enabled')&&substr_count($runtime,'hotspot_id=?')>=3,'O runtime não bloqueia a janela desativada ou não isola o histórico por ponto.');
$expect(str_contains($contextSource,'hotspot_commercial_loaded')&&str_contains($contextSource,'commercial.hotspot_id=h.id'),'A resolução do portal não incorpora a política comercial da instalação.');
$expect(str_contains($simulatorSource,"require_once __DIR__ . '/partner_hotspot_commercial.php'")&&str_contains($simulatorSource,'fs_partner_hotspot_id($partner)'),'A simulação por instalação não representa suas modalidades e regras próprias.');
$expect(str_contains($deployCheck,"'behavior-digest'")&&str_contains($deployCheck,"isset(\$inventory[56])")&&str_contains($finalizer,'behavior_digest_before'),'A implantação não prova que o backfill preserva o comportamento publicado.');
$expect(str_contains($finalizer,'hotspot_digest_before')&&str_contains($finalizer,'nas_base_digest_before')&&str_contains($finalizer,'queue_digest_before')&&str_contains($finalizer,'Nenhuma ação foi enviada ao RouterOS'),'A implantação não protege configuração técnica, RADIUS e fila remota.');

echo "OK: {$checks} verificações das regras comerciais por ponto Hotspot.\n";
