<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/portal_configuration.php';
$checks=0;function portal_config_expect(bool $ok,string $message):void{global$checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$presets=fs_portal_config_presets();
portal_config_expect(array_keys($presets)===['free_quick','free_sponsored','paid','hybrid'],'Catálogo de presets inesperado.');
foreach($presets as $code=>$preset){portal_config_expect(($preset['code']??'')===$code&&(int)$preset['version']===1,"Preset {$code} sem versão estável.");portal_config_expect(fs_portal_config_matches_preset($preset)['code']===$code,"Preset {$code} não reconhecido.");}
$custom=fs_portal_config_normalize(['subscriber_access_mode'=>'allow','courtesy_mode'=>'direct','paid_access_enabled'=>'1','promotional_ads_enabled'=>true,'allow_global_ads'=>false,'lead_capture_enabled'=>false,'ignored'=>'unsafe']);
portal_config_expect(!array_key_exists('ignored',$custom),'Campo arbitrário entrou na configuração funcional.');
portal_config_expect($custom['subscriber_access_mode']==='allow'&&$custom['courtesy_mode']==='direct'&&$custom['paid_access_enabled']===1,'Normalização tipada falhou.');
portal_config_expect(fs_portal_config_matches_preset($custom)===null,'Configuração modificada ainda foi identificada como preset.');
$diff=fs_portal_config_diff($presets['paid'],$custom);$fields=array_column($diff,'field');
portal_config_expect(in_array('subscriber_access_mode',$fields,true)&&in_array('courtesy_mode',$fields,true),'Diff omitiu capacidades alteradas.');
$paidNavigation=fs_portal_config_normalize(array_merge($presets['paid'],['welcome_screen_enabled'=>0,'single_option_direct_enabled'=>1]));
portal_config_expect(fs_portal_config_matches_preset($paidNavigation)['code']==='paid','Preferências de navegação alteraram indevidamente o preset comercial.');
$navigationDiff=fs_portal_config_diff($presets['paid'],$paidNavigation);$navigationFields=array_column($navigationDiff,'field');
portal_config_expect(in_array('welcome_screen_enabled',$navigationFields,true)&&in_array('single_option_direct_enabled',$navigationFields,true),'Diff omitiu preferências de navegação.');
portal_config_expect(fs_portal_config_single_visible_mode(['paid'=>true,'courtesy'=>false,'subscriber'=>false])==='paid','Modalidade única não foi reconhecida.');
portal_config_expect(fs_portal_config_single_visible_mode(['paid'=>true,'courtesy'=>true,'subscriber'=>false])===null,'Navegação direta aceitou mais de uma modalidade.');
$failed=false;try{fs_portal_config_normalize(['courtesy_mode'=>'codigo_livre']);}catch(InvalidArgumentException $e){$failed=true;}portal_config_expect($failed,'Valor funcional fora da allowlist foi aceito.');
$source=(string)file_get_contents(__DIR__.'/../app/portal_configuration.php');
portal_config_expect(strpos($source,'fs_partner_courtesy_save_central($pdo,$partnerId,$policy,true)')!==false,'Publicação funcional não registra a alteração comercial da cortesia no histórico versionado.');
echo "OK: {$checks} verificações das configurações funcionais.\n";
