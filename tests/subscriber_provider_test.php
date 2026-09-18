<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
putenv('PERSONAL_DATA_KEY='.base64_encode(str_repeat('S',32)));
require_once __DIR__.'/../app/subscriber_provider.php';
require_once __DIR__.'/../app/subscriber_hubsoft.php';
require_once __DIR__.'/../app/hubsoft_cache.php';
$checks=0;function subscriber_provider_expect(bool $ok,string $message):void{global$checks;$checks++;if(!$ok)throw new RuntimeException($message);}
subscriber_provider_expect(fs_subscriber_document_valid('529.982.247-25'),'CPF válido foi recusado.');
subscriber_provider_expect(!fs_subscriber_document_valid('111.111.111-11'),'CPF repetido foi aceito.');
subscriber_provider_expect(strlen(fs_subscriber_document_hash('52998224725'))===64,'Hash de documento inválido.');
$result=fs_subscriber_hubsoft_lookup('52998224725',static fn(string $document):array=>['clientes'=>[0=>[
    'id_cliente'=>'cli-100','nome'=>'Cliente de Teste','ativo'=>true,'telefone_primario'=>'(92) 99999-1234','email'=>'teste@example.invalid',
    'servicos'=>[
        ['id_servico'=>'svc-basic','id_plano'=>'plan-basic','tipo_servico'=>'Internet fibra','status'=>'Serviço Habilitado'],
        ['id_servico'=>'svc-family','plano'=>['id'=>'plan-family','nome'=>'Banda larga família'],'pacote'=>['id'=>'pkg-family'],'login_radius'=>'cliente-familia','habilitado'=>true],
    ],
]]]);
subscriber_provider_expect(!empty($result['found'])&&$result['customer_id']==='cli-100','Cliente HubSoft não foi normalizado.');
subscriber_provider_expect($result['customer_active']===true&&$result['internet_active']===true&&$result['eligible_basic']===true,'Serviço ativo não gerou elegibilidade básica.');
subscriber_provider_expect($result['phone']==='92999991234','Telefone não foi normalizado.');
$candidateKeys=array_map(static fn(array $item):string=>$item['kind'].':'.$item['id'],$result['mapping_candidates']);
subscriber_provider_expect(in_array('service:svc-basic',$candidateKeys,true)&&in_array('plan:plan-family',$candidateKeys,true)&&in_array('package:pkg-family',$candidateKeys,true),'IDs estáveis do contrato não foram extraídos.');
$familyCandidate=array_values(array_filter($result['mapping_candidates'],static fn(array $item):bool=>$item['kind']==='service'&&$item['id']==='svc-family'))[0]??[];
subscriber_provider_expect(($familyCandidate['label']??'')==='Banda larga família'&&!empty($familyCandidate['internet_detected'])&&($familyCandidate['evidence']??'')==='service_text','Diagnóstico seguro do serviço não preservou rótulo e evidência de internet.');
$networkAuthOnly=fs_subscriber_hubsoft_lookup('52998224725',static fn(string $document):array=>[['id_cliente'=>'cli-network','ativo'=>true,'servicos'=>[['id_servico'=>'svc-network','nome_servico'=>'Plano 500 Mega','login_radius'=>'cliente-500','status'=>'Serviço Habilitado']]]]);
subscriber_provider_expect($networkAuthOnly['internet_active']===true&&($networkAuthOnly['mapping_candidates'][0]['evidence']??'')==='network_auth','Autenticação de rede do HubSoft não foi reconhecida como evidência de internet.');
$inactive=fs_subscriber_hubsoft_lookup('52998224725',static fn(string $document):array=>[['id_cliente'=>'cli-200','status'=>'suspenso','servicos'=>[]]]);
subscriber_provider_expect($inactive['customer_active']===false&&$inactive['internet_active']===false,'Contrato suspenso foi tratado como ativo.');
$additional=fs_subscriber_hubsoft_lookup('52998224725',static fn(string $document):array=>[['id_cliente'=>'cli-300','ativo'=>true,'servicos'=>[['id_servico'=>'svc-tv','nome_servico'=>'TV e streaming','status'=>'Serviço Habilitado']]]]);
subscriber_provider_expect($additional['internet_active']===false&&$additional['eligible_basic']===false,'Serviço adicional sem internet recebeu elegibilidade básica.');
subscriber_provider_expect(!array_key_exists('response',$result)&&!array_key_exists('raw',$result),'Adaptador expôs resposta bruta.');
$authRequest=[];$auth=fs_subscriber_hubsoft_authenticate('52998224725','senha-de-teste',static function(string $username,string $password)use(&$authRequest):array{$authRequest=['username'=>$username,'password_length'=>strlen($password)];return ['status'=>200,'json'=>['status'=>'success','cliente'=>['id_cliente'=>'cli-100','cpf_cnpj'=>'52998224725']]];});
subscriber_provider_expect($auth['authenticated']===true&&$auth['customer_id']==='cli-100','Senha válida da Central do Assinante não foi normalizada.');
subscriber_provider_expect($authRequest===['username'=>'52998224725','password_length'=>14],'Adaptador alterou a credencial antes da validação remota.');
$denied=fs_subscriber_hubsoft_authenticate('52998224725','senha-incorreta',static fn(string $username,string $password):array=>['status'=>401,'json'=>['status'=>'error']]);
subscriber_provider_expect($denied['authenticated']===false&&$denied['result_code']==='INVALID_CREDENTIALS','Recusa do HubSoft não foi tratada como credencial inválida.');
foreach([403=>'REMOTE_FORBIDDEN',423=>'REMOTE_LOCKED',429=>'REMOTE_RATE_LIMITED'] as $status=>$code){$blocked=fs_subscriber_hubsoft_authenticate('52998224725','senha-de-teste',static fn(string $username,string $password):array=>['status'=>$status,'json'=>['status'=>'error']]);subscriber_provider_expect($blocked['authenticated']===false&&$blocked['result_code']===$code,"HTTP {$status} do HubSoft não recebeu classificação segura.");}
$mismatch=fs_subscriber_hubsoft_authenticate('52998224725','senha-de-teste',static fn(string $username,string $password):array=>['status'=>200,'json'=>['status'=>'success','cliente'=>['id_cliente'=>'cli-999','cpf_cnpj'=>'11144477735']]]);
subscriber_provider_expect($mismatch['authenticated']===false&&$mismatch['result_code']==='IDENTITY_MISMATCH','Divergência de identidade na autenticação foi aceita.');
$empty=array_fill_keys(array_keys(fs_subscriber_feature_labels()),false);
$radiusOnly=$empty;$radiusOnly['subscriber_radius_enabled']=true;$normalized=fs_subscriber_feature_normalize($empty,$radiusOnly);
subscriber_provider_expect($normalized['subscriber_access_enabled']&&$normalized['subscriber_account_enabled']&&$normalized['subscriber_invites_enabled']&&$normalized['subscriber_radius_enabled'],'RADIUS não ativou automaticamente os pré-requisitos do rollout.');
$purchaseOnly=$empty;$purchaseOnly['subscriber_authenticated_purchase_enabled']=true;$normalized=fs_subscriber_feature_normalize($empty,$purchaseOnly);
subscriber_provider_expect($normalized['subscriber_access_enabled']&&$normalized['subscriber_account_enabled']&&$normalized['subscriber_authenticated_purchase_enabled'],'Compra vinculada não ativou resolução e Minha Conta.');
$all=array_fill_keys(array_keys(fs_subscriber_feature_labels()),true);$turnOffAccess=$all;$turnOffAccess['subscriber_access_enabled']=false;$normalized=fs_subscriber_feature_normalize($all,$turnOffAccess);
subscriber_provider_expect(count(array_filter($normalized))===0,'Desligar a resolução preservou recursos dependentes.');
$turnOffInvites=$all;$turnOffInvites['subscriber_invites_enabled']=false;$normalized=fs_subscriber_feature_normalize($all,$turnOffInvites);
subscriber_provider_expect(!$normalized['subscriber_invites_enabled']&&!$normalized['subscriber_radius_enabled']&&$normalized['subscriber_account_enabled'],'Desligar convites não desligou apenas o RADIUS dependente.');
subscriber_provider_expect(fs_hubsoft_endpoint_valid('/api/v1/integracao/cliente?busca=cpf_cnpj&termo_busca=52998224725'),'Endpoint válido da consulta HubSoft foi recusado.');
subscriber_provider_expect(fs_hubsoft_endpoint_valid('/api/v1/recurso?nome=Joao%20Silva&ativo=1'),'Endpoint HubSoft com parâmetros codificados foi recusado.');
subscriber_provider_expect(!fs_hubsoft_endpoint_valid('https://example.invalid/api/v1/cliente'),'URL absoluta foi aceita como endpoint HubSoft.');
subscriber_provider_expect(!fs_hubsoft_endpoint_valid("/api/v1/cliente\nHost:example.invalid"),'Quebra de linha foi aceita no endpoint HubSoft.');
subscriber_provider_expect(!fs_hubsoft_endpoint_valid('/api/v1/cliente#fragmento'),'Fragmento foi aceito no endpoint HubSoft.');
subscriber_provider_expect(fs_hubsoft_cache_sanitize_error('52998224725: falha')==='Documento ••••4725: falha','Documento não foi mascarado no histórico HubSoft.');
subscriber_provider_expect(fs_hubsoft_cache_exception_code(new RuntimeException('recusado',403))==='HUBSOFT_FETCH_HTTP_403','HTTP 403 não recebeu código seguro no cache HubSoft.');
subscriber_provider_expect(fs_hubsoft_cache_exception_code(new RuntimeException('recusado',429))==='HUBSOFT_FETCH_HTTP_429','HTTP 429 não recebeu código seguro no cache HubSoft.');
subscriber_provider_expect(fs_hubsoft_cache_exception_code(new RuntimeException('O HubSoft não respondeu dentro do prazo esperado.'))==='HUBSOFT_FETCH_TRANSPORT','Falha de transporte não recebeu código seguro no cache HubSoft.');
subscriber_provider_expect(fs_hubsoft_cache_exception_code(new RuntimeException('O HubSoft retornou dados fora do formato esperado.'))==='HUBSOFT_FETCH_CONTRACT','Contrato remoto inválido não recebeu código seguro no cache HubSoft.');
$legacyState=fs_hubsoft_cache_normalize_state(['date'=>'2026-08-14','count'=>6,'last_run'=>'2026-08-14T03:00:00-04:00'],'2026-08-14',2);
subscriber_provider_expect($legacyState['automatic_count']===2&&$legacyState['manual_count']===4,'Contadores legados não separaram execuções automáticas e manuais.');
$newDayState=fs_hubsoft_cache_normalize_state($legacyState,'2026-08-15',2);
subscriber_provider_expect($newDayState['automatic_count']===0&&$newDayState['manual_count']===0,'Contadores HubSoft não foram reiniciados no novo dia.');
echo "OK: {$checks} verificações do adaptador HubSoft.\n";
