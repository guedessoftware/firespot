<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__ . '/../app/partner_infrastructure.php';
require_once __DIR__ . '/../app/cli/migration_framework.php';

$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};

$expect(fs_partner_ipv4_in_cidr('10.20.30.40','10.20.0.0/16'),'CIDR autorizado recusou endereço interno válido.');
$expect(!fs_partner_ipv4_in_cidr('10.21.30.40','10.20.0.0/16'),'CIDR aceitou endereço de outra rede.');
$expect(fs_partner_ipv4_in_cidr('203.0.113.8','0.0.0.0/0'),'Prefixo zero não cobre todo IPv4.');
$expect(!fs_partner_ipv4_in_cidr('127.0.0.1','invalid'),'CIDR inválido foi aceito.');
$expect(fs_partner_nas_validate_port(22)===22,'Porta SSH padrão foi recusada.');
foreach([0,65536] as $port){try{fs_partner_nas_validate_port($port);$blocked=false;}catch(InvalidArgumentException $e){$blocked=true;}$expect($blocked,'Porta SSH inválida foi aceita.');}
$expect(fs_partner_nas_validate_address('8.8.8.8')==='8.8.8.8','IPv4 público literal foi recusado.');
try{fs_partner_nas_validate_address('localhost');$hostnameBlocked=false;}catch(InvalidArgumentException $e){$hostnameBlocked=true;}
$expect($hostnameBlocked,'Hostname sujeito a DNS rebinding foi aceito como destino de NAS.');
$expect(ros_normalize_host_key_fingerprint('SHA256:AA:bb:01')==='aabb01','Fingerprint SSH não foi normalizado de forma estável.');

$network=['gateway_ip'=>'10.101.0.1','pool_start'=>'10.101.0.2','pool_end'=>'10.101.255.254'];
$expect(fs_partner_hotspot_assert_network_consistent($network)==='10.101.0.0/16','A rede automática /16 válida foi recusada.');
$network22=['network_prefix_length'=>22,'gateway_ip'=>'10.101.0.1','pool_start'=>'10.101.0.2','pool_end'=>'10.101.3.254'];
$expect(fs_partner_hotspot_assert_network_consistent($network22)==='10.101.0.0/22','A rede configurável /22 válida foi recusada.');
foreach([
    ['gateway_ip'=>'10.101.0.1','pool_start'=>'10.102.0.2','pool_end'=>'10.102.0.50'],
    ['gateway_ip'=>'10.101.0.2','pool_start'=>'10.101.0.2','pool_end'=>'10.101.0.50'],
    ['gateway_ip'=>'10.101.0.1','pool_start'=>'10.101.0.50','pool_end'=>'10.101.0.2'],
    ['gateway_ip'=>'10.101.0.0','pool_start'=>'10.101.0.2','pool_end'=>'10.101.0.50'],
] as $invalidNetwork){try{fs_partner_hotspot_assert_network_consistent($invalidNetwork);$networkBlocked=false;}catch(InvalidArgumentException $error){$networkBlocked=true;}$expect($networkBlocked,'Uma rede inconsistente foi aceita para aplicação.');}

$previous=getenv('NAS_CREDENTIAL_KEY');putenv('NAS_CREDENTIAL_KEY='.base64_encode(str_repeat('k',32)));
$encoded=fs_nas_credentials_encrypt('firespot-operator','ssh-secret','radius-secret');
$expect(str_starts_with($encoded,FS_NAS_CREDENTIAL_PREFIX),'Envelope do NAS não possui prefixo próprio.');
$expect(strpos($encoded,'ssh-secret')===false&&strpos($encoded,'radius-secret')===false,'Envelope expõe segredo em texto claro.');
$decoded=fs_nas_credentials_decrypt($encoded);
$expect($decoded['username']==='firespot-operator'&&$decoded['password']==='ssh-secret'&&$decoded['radius_secret']==='radius-secret','Envelope do NAS não fez round-trip.');
$credentialsDb=new PDO('sqlite::memory:');$credentialsDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$credentialsDb->exec('CREATE TABLE partner_nas_ownerships (nas_id INTEGER,management_mode TEXT,status TEXT,credentials_ciphertext TEXT,host_key_fingerprint TEXT)');
$credentialsDb->prepare('INSERT INTO partner_nas_ownerships VALUES (?,?,?,?,?)')->execute([9,'partner_owned','verified',$encoded,'SHA256:AABBCC']);
$securedNas=fs_nas_credentials_for_operation($credentialsDb,['id'=>9,'nasname'=>'192.0.2.9','mgmt_port'=>22]);$securedConnection=fs_nas_base_connection($securedNas);
$expect($securedConnection['expected_host_key_fingerprint']==='SHA256:AABBCC'&&$securedConnection['user']==='firespot-operator','Conexão do NAS próprio não recebeu credencial e chave SSH protegidas.');
$credentialsDb->exec('UPDATE partner_nas_ownerships SET host_key_fingerprint=NULL WHERE nas_id=9');
try{fs_nas_base_connection(fs_nas_credentials_for_operation($credentialsDb,['id'=>9,'nasname'=>'192.0.2.9','mgmt_port'=>22]));$unpinnedBlocked=false;}catch(FsNasBaseProvisioningException $error){$unpinnedBlocked=$error->errorCodeName()==='SSH_HOST_KEY_NOT_VERIFIED';}
$expect($unpinnedBlocked,'Operação normal aceitou NAS próprio sem chave SSH previamente fixada.');
if($previous===false)putenv('NAS_CREDENTIAL_KEY');else putenv('NAS_CREDENTIAL_KEY='.$previous);

$root=dirname(__DIR__);$domain=(string)file_get_contents($root.'/app/partner_infrastructure.php');$worker=(string)file_get_contents($root.'/app/partner_infrastructure_worker.php');$apply=(string)file_get_contents($root.'/app/hotspot_apply.php');$routeros=(string)file_get_contents($root.'/app/lib/routeros.php');$credentials=(string)file_get_contents($root.'/app/nas_credentials.php');$base=(string)file_get_contents($root.'/app/nas_base_provisioning.php');$hotspots=(string)file_get_contents($root.'/app/partner_hotspots.php');$central=(string)file_get_contents($root.'/dashboard/estabelecimento.php').(string)file_get_contents($root.'/app/control_center_partners.php').(string)file_get_contents($root.'/dashboard/actions/partner.php').(string)file_get_contents($root.'/dashboard/actions/point.php');$entry=(string)file_get_contents($root.'/portal/host/index.php');$actions=(string)file_get_contents($root.'/portal/host/panel_actions.php');$portal=$entry.$actions;$page=(string)file_get_contents($root.'/portal/host/pages/infrastructure.php');$finalizer=(string)file_get_contents('/opt/firespot-ops/firespot_partner_portal_finalize_root.sh');$deployCheck=(string)file_get_contents($root.'/app/cli/partner_portal_deploy_check.php');$deployment=$finalizer.$deployCheck;
$expect(strpos($domain,"management_mode='partner_owned'")!==false&&strpos($domain,'o.partner_id=?')!==false,'Operações não exigem propriedade exclusiva do NAS.');
$expect(strpos($domain,"status='retired'")!==false&&strpos($domain,'partner_hotspots WHERE partner_id=? AND nas_id=? AND active=1')!==false,'Aposentadoria não é soft-delete ou ignora pontos ativos.');
$expect(strpos($domain,'fs_partner_require_quota')!==false,'Cadastro não aplica cotas do plano.');
$expect(strpos($domain,'fs_partner_nas_owned_assignment_ensure')!==false&&strpos($domain,"assignment_source='partner_owned'")!==false,'NAS cadastrado pelo estabelecimento não recebe atribuição própria e utilizável.');
$expect(strpos($domain,"CASE WHEN o.management_mode='partner_owned'")!==false&&strpos($domain,"ELSE NULL END management_address")!==false,'Listagem não separa dados técnicos do NAS próprio e do NAS FireSpot.');
$expect(strpos($worker,'ssh2_fingerprint')!==false&&strpos($worker,'hash_equals')!==false,'Worker não fixa a chave SSH do NAS.');
$expect(strpos($worker,'fixa a chave antes de qualquer nova conexão')!==false&&strpos($worker,'host_key_fingerprint=?')!==false,'Worker não persiste a chave antes da conexão operacional seguinte.');
$expect(strpos($routeros,'ros_assert_ssh2_host_key($conn, $expectedHostKey)')!==false&&strpos($routeros,'antes da autenticação')!==false,'Comandos RouterOS não validam a chave em cada conexão antes da autenticação.');
$expect(strpos($apply,'ros_assert_ssh2_host_key($conn')!==false&&strpos($credentials,"['host_key_required']=true")!==false&&strpos($base,'SSH_HOST_KEY_NOT_VERIFIED')!==false,'SFTP ou operações de NAS próprio permitem conexão sem chave fixada.');
$expect(strpos($worker,"status='running'")!==false&&strpos($worker,"status='succeeded'")!==false&&strpos($worker,"'retry'")!==false,'Fila não possui transições e retentativas.');
$expect(strpos($apply,'partner_network_routeros_dns_name')!==false,'Aplicador não remove a zona privada automática do RouterOS.');
$expect(strpos($apply,"'cookie,http-chap,http-pap,mac-cookie'")!==false&&strpos($apply,"'cookie,http-chap,http-pap,mac'")===false,'Aplicador perdeu a reconexão curta ou habilitou login por MAC puro.');
$expect(strpos($apply,'FS_HOTSPOT_SHORT_RECONNECT_TIMEOUT')!==false,'Aplicador não valida o prazo comum de 20 minutos.');
$expect(strpos($apply,"'hotspot_cleanup'")!==false&&strpos($apply,'cleanup-after-request-')!==false,'Troca de NAS/VLAN não agenda limpeza idempotente da configuração anterior.');
$expect(substr_count($apply,'fs_hotspot_apply_remote_remove_stale_interface($configuration')>=2,'Rollback de troca de VLAN pode deixar a interface desejada órfã no mesmo NAS.');
$expect(strpos($worker,"(string)\$request['operation']!=='hotspot_cleanup'")!==false,'Falha de limpeza antiga degrada indevidamente a instalação nova já aplicada.');
$expect(strpos($domain,'active_nas_network')===false&&strpos((string)file_get_contents($root.'/migrations/046_partner_infrastructure.sql'),'active_nas_network')!==false,'A migração não impede duas redes iguais no mesmo NAS.');
$migration046=(string)file_get_contents($root.'/migrations/046_partner_infrastructure.sql');
$expect(strpos($migration046,'chk_partner_hotspots_active_nas')!==false&&strpos($migration046,'hotspot.active_without_nas_reconciled')!==false,'Schema não garante que todo ponto ativo possua NAS nem audita o legado reconciliado.');
$smoke046=(string)file_get_contents($root.'/migrations/smoke_046.php');
$expect(strpos($smoke046,'Fila de infraestrutura cruza NAS')!==false&&strpos($smoke046,'Reserva de rede cruza NAS')!==false,'Smoke não detecta cruzamento de escopo em fila ou reserva de infraestrutura.');
$readiness=(string)file_get_contents($root.'/app/cli/operations_readiness.php');
$expect(strpos($smoke046,"r.operation='hotspot_deactivate'")!==false&&strpos($smoke046,"r.state='applied'")!==false&&strpos($readiness,"['verification']=1")!==false,'Auditoria contínua confunde histórico de migração com cruzamento real ou aceita falha de verificação como íntegra.');
$expect(strpos($hotspots,'Todo estabelecimento ativo precisa estar associado a um NAS.')!==false&&strpos($central,'fs_partner_hotspot_sync_default_from_partner')!==false,'Fluxos da Central ou serviço de domínio permitem ativar o ponto principal sem NAS.');
$expect(strpos($central,'function fs_control_center_partner_save_registration')!==false&&strpos($central,'if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack()')!==false,'Edição geral pode persistir parcialmente antes de sincronizar o ponto principal.');
$expect(strpos($domain,"Já existe uma alteração de rede pendente")!==false&&strpos($domain,'configuration_version')!==false,'Fila aceita versões concorrentes do mesmo ponto.');
$expect(strpos($domain,'function fs_partner_nas_assert_no_competing_request')!==false&&substr_count($domain,'fs_partner_nas_assert_no_competing_request')>=3,'Rotação e fila do NAS não são serializadas contra operações concorrentes.');
$expect(strpos($domain,"UPDATE nas SET nasname=?,mgmt_username=?")!==false&&strpos($domain,"UPDATE nas SET nasname=?,secret=?")===false&&strpos($domain,"UPDATE nas_base_provisioning SET status='pending'")!==false,'Rotação promove o shared secret local antes da confirmação remota.');
$expect(strpos($domain,'$pendingRadiusSecret=\'pending-\'.bin2hex(random_bytes(24))')!==false&&strpos($domain,'$insert->execute([$address,$shortname,$radiusSecret')===false,'Cadastro torna o shared secret do cliente ativo antes de o RouterOS ser preparado e validado.');
$expect(strpos($domain,'Migre ou desative os pontos ativos antes de alterar o endereço do NAS.')!==false,'Endereço RADIUS/NAS pode mudar enquanto ainda possui pontos ativos.');
$expect(strpos($domain,'function fs_partner_hotspot_draft_update')!==false&&strpos($domain,'function fs_partner_hotspot_draft_discard')!==false,'Rascunho não pode ser editado e descartado sem mutação remota.');
$expect(strpos($domain,'function fs_partner_hotspot_queue_deactivate')!==false&&strpos($apply,"operation']==='hotspot_deactivate'")!==false,'Ponto secundário aplicado não possui desativação assíncrona controlada.');
$expect(strpos($apply,"hotspot['nas_id']!==\$nasId")!==false&&strpos($apply,'O NAS da solicitação não corresponde ao NAS atual da instalação.')!==false,'Executor não rejeita desativação enfileirada para um NAS diferente do NAS atual do ponto.');
$expect(strpos($domain,'A migração inicial para NAS próprio precisa ser acompanhada pela Central')!==false,'Portal permite limpar ou migrar diretamente um NAS compartilhado da FireSpot.');
$expect(strpos($apply,'pré-validação somente leitura')!==false&&strpos($apply,'vlanCount!==$managedVlanCount')!==false,'Aplicador não confere conflitos remotos antes da mutação.');
$expect(strpos($apply,"'a VLAN aplicada'")!==false&&strpos($apply,"'a regra NAT'")!==false&&strpos($apply,"'a liberação do portal'")!==false,'Pós-validação não confirma todos os componentes essenciais da instalação.');
$expect(strpos($apply,"'confirmar a retirada da instalação'")!==false&&strpos($apply,'ainda mantém parte da instalação retirada')!==false,'Desativação aceita sucesso sem confirmar a ausência remota.');
$expect(strpos($worker,'GET_LOCK(?,0)')!==false&&strpos($worker,'fs_partner_infrastructure_recover_stale')!==false,'Worker não possui lock por NAS ou recuperação de claim interrompido.');
$expect(strpos($worker,"o.status=IF(b.status='ready','ready','verified')")!==false,'Sincronização recuperada não restaura o estado verificável do NAS.');
$expect(strpos($worker,'fs_partner_infrastructure_safe_error_detail')!==false&&strpos($worker,'$error->getMessage()')===false,'Fila persiste detalhe bruto de exceção no portal.');
$expect(strpos($worker,"return 'SSH_HOST_KEY_CHANGED'")!==false&&strpos($worker,"str_contains(\$code,'HOST_KEY')")!==false,'Mudança de chave SSH não possui diagnóstico público seguro e específico.');
$expect(strpos($worker,"'hotspot_apply'=>'hotspots.apply'")!==false,'Worker não revalida o entitlement imediatamente antes da execução remota.');
$expect(strpos($apply,'desired_config_version=?')!==false&&strpos($apply,'last_change_request_id=?')!==false,'Commit remoto não fixa a versão da solicitação aplicada.');
$expect(strpos($base,"UPDATE nas SET secret=? WHERE id=?")!==false&&strpos($base,'RADIUS_SECRET_ROLLBACK_FAILED')!==false,'Preparação não promove o novo shared secret atomicamente ou não tenta restaurá-lo em falha.');
$expect(strpos($portal,'partner_admin_require_current_password')!==false&&strpos($page,'current_password')!==false,'Ações sensíveis não exigem reautenticação.');
$expect(strpos($portal,"'hotspot.draft_updated'")!==false&&strpos($portal,"'hotspot.draft_discarded'")!==false&&strpos($page,'hotspot_draft_update')!==false&&strpos($page,'hotspot_draft_discard')!==false,'CRUD seguro do rascunho não está exposto e auditado no portal.');
$expect(strpos($actions,"'hotspot_apply','hotspot_deactivate'")!==false&&strpos($actions,'permanece sob controle da Central FireSpot')!==false&&strpos($page,'name="action" value="hotspot_deactivate"')===false,'Painel do estabelecimento ainda executa aplicação ou desativação remota.');
$expect(strpos($actions,'fs_partner_nas_register(')!==false&&strpos($actions,'fs_partner_nas_update_credentials(')!==false&&strpos($actions,'fs_partner_nas_retire(')!==false,'CRUD de NAS próprio não está ligado ao controlador autenticado.');
$expect(substr_count($actions,'partner_admin_require_current_password')>=4,'Cadastro, credenciais, preparação e aposentadoria de NAS não exigem reautenticação.');
$expect(strpos($page,'data-host-modal-open="host-modal-new-point"')!==false&&strpos($page,'id="host-modal-new-point" hidden')!==false,'Novo ponto não foi movido para uma janela flutuante segura.');
$expect(strpos($page,'host-cell-stack')!==false,'Tabelas de infraestrutura não isolam visualmente título, detalhe e estado.');
$expect(strpos($page,'mgmt_password')===false&&strpos($page,'radius_secret\']')===false,'Tela tenta reexibir credenciais persistidas.');
$expect(strpos($page,'host_key_fingerprint')===false&&strpos($page,'reset_host_key')===false&&strpos($actions,'host_key_reset_requested')===false,'Painel do estabelecimento ainda expõe ou redefine chave SSH do NAS.');
$expect(strpos($portal,"\$_POST['partner_id']")===false,'Portal aceita partner_id enviado pelo cliente como fronteira de autorização.');
$expect(strpos($entry,"require_once __DIR__ . '/panel_actions.php'")!==false&&strpos($actions,'function host_panel_handle_post')!==false&&strpos($actions,'FIRESPOT_HOST_PANEL_ENTRY')!==false,'Controlador POST não foi isolado ou pode ser executado fora da entrada autenticada.');
$expect(strpos($entry,"define('FIRESPOT_HOST_PANEL_VIEW',true)")!==false&&strpos($page,"defined('FIRESPOT_HOST_PANEL_VIEW')")!==false,'Views extraídas podem ser executadas diretamente fora do painel autenticado.');
$expect(strpos((string)file_get_contents($root.'/app/cli/job_runner.php'),"'partner_infrastructure'")!==false,'Worker não está na allowlist de jobs.');
$expect(strpos($deployment,'Ledger avançado não forma um prefixo contínuo')!==false&&strpos($deployment,'pending_migrations_045_049=')!==false&&strpos($deployment,"'none'")!==false,'Finalizador não pode detectar com segurança migração parcial ou já concluída.');
$expect(strpos($finalizer,'php -r')===false&&strpos($finalizer,'partner_portal_deploy_check.php')!==false&&strpos($deployCheck,"execute(['paid','reserved','provisioning','active','active'])")!==false,'Finalizador ainda depende de PHP inline sujeito a perda de aspas SQL pelo shell.');
$expect(strpos($deployCheck,'O finalizador 045-049 já foi concluído e não pode ser reexecutado')!==false&&strpos($deployCheck,'range(1,53)')!==false,'Finalizador histórico pode avançar acidentalmente para migrações posteriores.');
$expect(strpos($finalizer,'active_queue=')!==false&&strpos($finalizer,'A implantação não as executará automaticamente')!==false&&strpos($finalizer,'systemctl enable --now')===false,'Finalizador pode disparar solicitações remotas pendentes durante a implantação.');
$expect(strpos($finalizer,'mktemp /etc/firespot/.firespot.env.')!==false&&strpos($finalizer,'mv "${env_temp}" "${env_file}"')!==false&&strpos($finalizer,'nas_key_length')!==false,'Instalação da chave de NAS não é atômica ou aceita valor privado incompleto.');
$expect(strpos($finalizer,'/run/lock/firespot-partner-portal.lock')!==false&&strpos($finalizer,'/usr/bin/flock -n 9')!==false,'Duas execuções do finalizador podem disputar chave, backup ou timers.');

$inventory=fs_migration_inventory($root.'/migrations');
$expect(isset($inventory[45],$inventory[46],$inventory[53])&&!empty($inventory[45]['idempotent'])&&!empty($inventory[46]['idempotent'])&&!empty($inventory[53]['idempotent']),'Migrações novas não estão inventariadas como idempotentes.');
$expect(count(fs_migration_sql_statements((string)file_get_contents($root.'/migrations/046_partner_infrastructure.sql')))>3,'Migração de infraestrutura não passa pelo parser SQL controlado.');
$expect(strpos((string)file_get_contents($root.'/migrations/046_partner_infrastructure.sql'),"'hotspot_cleanup'")!==false,'Fila não aceita a limpeza rastreada após migração de ponto.');

echo "OK: {$checks} verificações de segurança da infraestrutura do parceiro.\n";
