<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__ . '/../app/partner_courtesy_management.php';

$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$base=fs_courtesy_policy_defaults();$base['enforcement_method']='radius';$base['radius_group']='firespot-courtesy';$base['reservation_ttl_seconds']=180;$base['requires_ad']=1;$base['exclude_active_paid']=1;
$updated=fs_partner_courtesy_input([
    'enabled'=>'1','grant_minutes'=>'45','credit_validity_minutes'=>'2880','consumption_mode'=>'online','auth_mode'=>'account_device',
    'device_max_grants'=>'2','device_period_minutes'=>'1440','account_max_grants'=>'3','account_period_minutes'=>'10080','cooldown_after_end_minutes'=>'120',
],$base);
$expect($updated['grant_minutes']===45&&$updated['cooldown_after_end_minutes']===120,'Campos comerciais não foram atualizados.');
$expect($updated['device_max_grants']===2&&$updated['device_period_minutes']===1440,'Limite por dispositivo foi alterado incorretamente.');
$expect($updated['enforcement_method']==='radius','Cliente conseguiu trocar o método técnico de execução.');
$expect($updated['radius_group']==='firespot-courtesy','Cliente conseguiu trocar o grupo RADIUS.');
$expect($updated['reservation_ttl_seconds']===180,'Cliente conseguiu trocar o TTL técnico da reserva.');
$expect($updated['requires_ad']===1&&$updated['exclude_active_paid']===1,'Cliente conseguiu contornar gates técnicos/comerciais protegidos.');
$expect(fs_courtesy_policy_validate($updated)===[],'Política comercial válida foi recusada.');
foreach(fs_partner_courtesy_presets() as $code=>$preset){$normalized=fs_partner_courtesy_input(fs_partner_courtesy_preset($code),$base);$expect(fs_courtesy_policy_validate($normalized)===[],'Preset de cortesia inválido: '.$code);}
try{fs_partner_courtesy_preset('unknown');$invalidPreset=false;}catch(InvalidArgumentException $e){$invalidPreset=true;}
$expect($invalidPreset,'Preset desconhecido foi aceito.');
$partial=fs_partner_courtesy_override_input(['override_groups'=>['cooldown'],'cooldown_after_end_minutes'=>'720'],$base);
$expect($partial['groups']===['cooldown']&&$partial['values']['cooldown_after_end_minutes']===720,'Override parcial não preservou o grupo escolhido.');
$expect($partial['values']['grant_minutes']===null&&$partial['values']['device_max_grants']===null,'Override parcial materializou cópia integral da política.');
try{fs_partner_courtesy_override_input(['grant_minutes'=>'30'],$base);$missingGroups=false;}catch(InvalidArgumentException $e){$missingGroups=true;}
$expect($missingGroups,'Override sem campos explícitos foi aceito.');

try{fs_partner_courtesy_input(['enabled'=>'1','grant_minutes'=>'0'],$base);$invalid=false;}catch(InvalidArgumentException $e){$invalid=true;}
$expect($invalid,'Duração inválida foi aceita no rascunho.');

$root=dirname(__DIR__);$policy=(string)file_get_contents($root.'/app/courtesy_policy.php');$management=(string)file_get_contents($root.'/app/partner_courtesy_management.php');$portal=(string)file_get_contents($root.'/portal/host/index.php').(string)file_get_contents($root.'/portal/host/panel_actions.php');
$expect(strpos($policy,'courtesy_hotspot_policy_overrides')!==false&&strpos($policy,'h.partner_id=o.partner_id')!==false,'Resolução por ponto não valida o mesmo estabelecimento.');
$expect(substr_count($policy,"\$context['hotspot_id']")>=2,'Check e reserva não resolvem a política do ponto atual.');
$expect(strpos($management,'fs_courtesy_policy_save($pdo,$partnerId,$current,true)')!==false,'Publicação não atualiza a política efetiva pelo domínio canônico.');
$expect(strpos($management,"state='superseded'")!==false&&strpos($management,"state='published'")!==false,'Publicação não preserva histórico de revisões.');
$expect(strpos($management,'function fs_partner_courtesy_save_central')!==false&&strpos($management,'fs_partner_courtesy_record_effective_revision')!==false,'Alterações da Central não preservam o histórico comercial publicado.');
$expect(strpos($management,'fs_partner_courtesy_lock_partner($pdo,$partnerId);')!==false,'Publicações concorrentes não são serializadas pelo estabelecimento.');
$expect(strpos($management,'fs_partner_courtesy_restore_draft')!==false&&strpos($portal,"'courtesy_restore'")!==false,'Rollback de cortesia não restaura uma revisão como rascunho.');
$expect(strpos($policy,'override_mask')!==false&&strpos($management,'override_groups')!==false,'Override por ponto não preserva herança explícita da política geral.');
$expect(strpos($management,"SELECT id FROM partners WHERE id=? LIMIT 1 FOR UPDATE")!==false,'Cota de overrides não é serializada por estabelecimento.');
$expect(substr_count($management,'fs_partner_courtesy_lock_partner($pdo,$partnerId);')>=6&&strpos($management,'AND partner_id=? AND active=1 LIMIT 1 FOR UPDATE')!==false,'Salvar, publicar ou retirar override não usa ordem de locks consistente.');
$expect(strpos($portal,"'courtesy.override.manage'")!==false,'Override por ponto não exige capacidade exclusiva de proprietário.');
$expect(strpos($portal,'policy_revision')!==false,'Auditoria da publicação não registra a revisão efetiva.');

echo "OK: {$checks} verificações da gestão versionada de cortesia.\n";
