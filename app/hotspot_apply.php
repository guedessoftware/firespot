<?php

declare(strict_types=1);

require_once __DIR__ . '/hotspot_walled_garden.php';
require_once __DIR__ . '/partner_network.php';
require_once __DIR__ . '/partner_hotspots.php';
require_once __DIR__ . '/nas_base_provisioning.php';
require_once __DIR__ . '/public_url.php';

/** @return array<string,mixed> */
function fs_hotspot_apply_nas(PDO $pdo, int $nasId): array
{
    $statement=$pdo->prepare('SELECT * FROM nas WHERE id=? LIMIT 1');$statement->execute([$nasId]);
    $nas=$statement->fetch(PDO::FETCH_ASSOC);if(!$nas)throw new RuntimeException('NAS da instalação não encontrado.');
    return fs_nas_credentials_for_operation($pdo,$nas);
}

function fs_hotspot_apply_code(string $publicCode, int $hotspotId): string
{
    $code=strtoupper((string)preg_replace('/[^A-Za-z0-9]/','',$publicCode));
    return $code!==''?substr($code,0,48):'HOTSPOT'.$hotspotId;
}

/** @return array{code:string,iface_name:string,html_dir:string,connection:array<string,mixed>,nas:array<string,mixed>} */
function fs_hotspot_apply_remote(PDO $pdo, array $partner, array $configuration): array
{
    fs_partner_hotspot_assert_operational($configuration+['active'=>1]);
    $nasId=(int)$configuration['nas_id'];
    fs_nas_base_assert_ready($pdo,$nasId,(string)$configuration['radius_ip']);
    $nas=fs_hotspot_apply_nas($pdo,$nasId);$connection=fs_nas_base_connection($nas);
    $statement=$pdo->prepare('SELECT interface_name FROM nas_interfaces WHERE id=? AND nas_id=? LIMIT 1');
    $statement->execute([(int)$configuration['nas_interface_id'],$nasId]);$baseInterface=(string)($statement->fetchColumn()?:'');
    if($baseInterface==='')throw new RuntimeException('A interface selecionada não pertence ao NAS da instalação.');

    $hotspotId=(int)($configuration['hotspot_id']??0);
    $code=fs_hotspot_apply_code((string)$configuration['code'],$hotspotId);
    $vlan=(int)$configuration['vlan_id'];$ifaceName='vlan'.$vlan.'-'.$code;$htmlDir='hs_'.$code;
    $gateway=(string)$configuration['gateway_ip'];$poolStart=(string)$configuration['pool_start'];$poolEnd=(string)$configuration['pool_end'];
    $network=fs_partner_hotspot_network_profile($configuration);
    $dnsParts=preg_split('/[\s,;]+/',trim((string)$configuration['dns_servers']),-1,PREG_SPLIT_NO_EMPTY)?:[];
    if(!$dnsParts)throw new InvalidArgumentException('Informe ao menos um servidor DNS upstream.');
    foreach($dnsParts as $dns)if(!filter_var($dns,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new InvalidArgumentException('A instalação possui DNS upstream inválido.');
    $dnsServers=implode(',',$dnsParts);
    $isV3=(string)($partner['portal_mode']??'inherit')==='v3';
    $logicalDns=partner_network_dns_name((string)($configuration['dns_name']??''),(string)$configuration['code']);
    $routerDns=$isV3?partner_network_routeros_dns_name($logicalDns):$logicalDns;
    $quotedIface=fs_routeros_quote($baseInterface);$quotedVlanIface=fs_routeros_quote($ifaceName);
    $quotedCode=fs_routeros_quote($code);$quotedName=fs_routeros_quote((string)$configuration['name']);
    $quotedProfile=fs_routeros_quote('hs_'.$code);$quotedRouterDns=fs_routeros_quote($routerDns);$quotedDefault=fs_routeros_quote('default');
    $loginBy=$isV3?'cookie,http-chap,http-pap,mac-cookie':'http-chap,http-pap';
    $cookieLifetime=$isV3?FS_HOTSPOT_SHORT_RECONNECT_TIMEOUT:'1d';

    $probe=fs_nas_base_assert_command_result(ros_exec([':put [/system resource get version]'],$connection),'consultar a versão antes da aplicação','pré-validação da instalação');
    fs_nas_base_routeros_version((string)($probe[0]??''));
    $networkAddress=explode('/',$network['cidr'],2)[0];
    $preflight=fs_nas_base_assert_command_result(ros_exec([
        ':put [/interface print count-only where name='.$quotedIface.']',
        ':put [/interface vlan print count-only where vlan-id='.$vlan.']',
        ':put [/interface vlan print count-only where name='.$quotedVlanIface.']',
        ':put [/ip address print count-only where network='.fs_routeros_quote($networkAddress).']',
        ':put [/ip address print count-only where network='.fs_routeros_quote($networkAddress).' and interface='.$quotedVlanIface.']',
        ':put [/ip hotspot profile print count-only where name='.$quotedProfile.']',
        ':put [/ip hotspot print count-only where name='.$quotedProfile.']',
    ],$connection),'validar conflitos antes da aplicação','pré-validação somente leitura');
    $baseCount=fs_nas_base_count_output($preflight[0]??'','a interface-base');
    $vlanCount=fs_nas_base_count_output($preflight[1]??'','a VLAN selecionada');
    $managedVlanCount=fs_nas_base_count_output($preflight[2]??'','a VLAN gerenciada');
    $networkCount=fs_nas_base_count_output($preflight[3]??'','a rede selecionada');
    $managedNetworkCount=fs_nas_base_count_output($preflight[4]??'','a rede gerenciada');
    $profileCount=fs_nas_base_count_output($preflight[5]??'','o perfil Hotspot gerenciado');
    $serverCount=fs_nas_base_count_output($preflight[6]??'','o servidor Hotspot gerenciado');
    if($baseCount!==1||$vlanCount!==$managedVlanCount||$vlanCount>1||$networkCount!==$managedNetworkCount||$profileCount>1||$serverCount>1){
        throw new RuntimeException('O preflight encontrou interface, VLAN, rede ou perfil conflitante no NAS. Sincronize o equipamento antes de tentar novamente.');
    }
    fs_nas_base_assert_command_result(ros_exec([
        ':do { /file make-dir '.$htmlDir.' } on-error={ }',
        ':do { /file make-dir '.$htmlDir.'/img } on-error={ }',
    ],$connection),'preparar o diretório do portal','arquivos da instalação');

    $publicHost=fs_public_host($pdo);
    $commands=[
        '/ip hotspot remove [find where name='.$quotedProfile.']',
        '/ip dhcp-server remove [find where name='.fs_routeros_quote('dhcp_'.$code).']',
        '/ip hotspot profile remove [find where name='.$quotedProfile.']',
        '/ip dhcp-server network remove [find where comment='.$quotedCode.']',
        '/ip address remove [find where interface='.$quotedVlanIface.']',
        '/ip pool remove [find where name='.fs_routeros_quote('pool_'.$code).']',
        '/interface vlan remove [find where name='.$quotedVlanIface.']',
        '/interface vlan add name='.$quotedVlanIface.' vlan-id='.$vlan.' interface='.$quotedIface.' comment='.$quotedName,
        '/ip address add address='.$gateway.'/'.$network['prefix'].' interface='.$quotedVlanIface.' comment='.$quotedCode,
        '/ip pool add name='.fs_routeros_quote('pool_'.$code).' ranges='.$poolStart.'-'.$poolEnd,
        '/ip dhcp-server add name='.fs_routeros_quote('dhcp_'.$code).' interface='.$quotedVlanIface.' address-pool='.fs_routeros_quote('pool_'.$code).' lease-time=4h disabled=no',
        '/ip dhcp-server network add address='.$network['cidr'].' gateway='.$gateway.' dns-server='.$gateway.' comment='.$quotedCode,
        '/ip dns set servers='.$dnsServers.' allow-remote-requests=yes',
    ];
    if($isV3)$commands[]='/ip hotspot user profile set [find where name='.$quotedDefault.'] add-mac-cookie=yes mac-cookie-timeout='.FS_HOTSPOT_SHORT_RECONNECT_TIMEOUT;
    $commands[]='/ip hotspot profile add name='.$quotedProfile.' hotspot-address='.$gateway.' login-by='.$loginBy.' http-cookie-lifetime='.$cookieLifetime.' use-radius=yes dns-name='.$quotedRouterDns.' html-directory='.$htmlDir;
    $commands[]='/ip hotspot add name='.$quotedProfile.' profile='.$quotedProfile.' interface='.$quotedVlanIface.' address-pool='.fs_routeros_quote('pool_'.$code).' disabled=no';
    $commands[]='/ip hotspot walled-garden ip remove [find where dst-host='.fs_routeros_quote($publicHost).']';
    $commands[]='/ip hotspot walled-garden ip add action=accept comment="FireSpot Portal" disabled=no dst-host='.fs_routeros_quote($publicHost);
    $commands=array_merge($commands,fs_hotspot_payment_walled_garden_commands(),[
        '/ip firewall nat remove [find where comment='.fs_routeros_quote('Hotspot '.$code).']',
        '/ip firewall nat add chain=srcnat src-address='.$network['cidr'].' action=masquerade comment='.fs_routeros_quote('Hotspot '.$code),
    ]);
    fs_nas_base_assert_command_result(ros_exec($commands,$connection),'aplicar a instalação','configuração da instalação');
    fs_hotspot_apply_assets($pdo,$connection,$htmlDir,(string)$configuration['code']);

    $verify=fs_nas_base_assert_command_result(ros_exec([
        ':put [/ip dns get allow-remote-requests]',
        ':put [/ip dhcp-server network get [find where comment='.$quotedCode.'] dns-server]',
        ':put [/ip hotspot profile get [find where name='.$quotedProfile.'] dns-name]',
        ':put [/ip hotspot profile get [find where name='.$quotedProfile.'] hotspot-address]',
        ':put [/ip hotspot profile get [find where name='.$quotedProfile.'] login-by]',
        ':put [/ip hotspot profile get [find where name='.$quotedProfile.'] http-cookie-lifetime]',
        ':put [/ip hotspot user profile get [find where name='.$quotedDefault.'] add-mac-cookie]',
        ':put [/ip hotspot user profile get [find where name='.$quotedDefault.'] mac-cookie-timeout]',
        '/ip hotspot print count-only where name='.$quotedProfile.' and disabled=no',
        ':put [/ip hotspot profile get [find where name='.$quotedProfile.'] use-radius]',
        '/interface vlan print count-only where name='.$quotedVlanIface,
        '/ip address print count-only where interface='.$quotedVlanIface.' and address='.fs_routeros_quote($gateway.'/'.$network['prefix']),
        '/ip pool print count-only where name='.fs_routeros_quote('pool_'.$code),
        '/ip dhcp-server print count-only where name='.fs_routeros_quote('dhcp_'.$code).' and disabled=no',
        '/ip dhcp-server network print count-only where comment='.$quotedCode,
        '/ip firewall nat print count-only where comment='.fs_routeros_quote('Hotspot '.$code),
        '/ip hotspot walled-garden ip print count-only where dst-host='.fs_routeros_quote($publicHost).' and disabled=no',
    ],$connection),'validar o gateway e a reconexão do Hotspot','validação da instalação');
    $methods=preg_split('/[\s,;]+/',strtolower(trim((string)($verify[4]??''))),-1,PREG_SPLIT_NO_EMPTY)?:[];
    $timeouts=['20m','00:20:00'];
    if(!in_array(strtolower(trim((string)($verify[0]??''))),['true','yes'],true)
        ||trim((string)($verify[1]??''))!==$gateway
        ||strtolower(trim((string)($verify[2]??'')))!==strtolower($routerDns)
        ||trim((string)($verify[3]??''))!==$gateway
        ||fs_nas_base_count_output($verify[8]??'','o servidor Hotspot ativo')!==1
        ||!in_array(strtolower(trim((string)($verify[9]??''))),['true','yes'],true)
        ||fs_nas_base_count_output($verify[10]??'','a VLAN aplicada')!==1
        ||fs_nas_base_count_output($verify[11]??'','o endereço do gateway')!==1
        ||fs_nas_base_count_output($verify[12]??'','o pool aplicado')!==1
        ||fs_nas_base_count_output($verify[13]??'','o DHCP ativo')!==1
        ||fs_nas_base_count_output($verify[14]??'','a rede DHCP')!==1
        ||fs_nas_base_count_output($verify[15]??'','a regra NAT')!==1
        ||fs_nas_base_count_output($verify[16]??'','a liberação do portal')!==1
        ||($isV3&&(!in_array('cookie',$methods,true)||!in_array('mac-cookie',$methods,true)||in_array('mac',$methods,true)))
        ||($isV3&&!in_array(strtolower(trim((string)($verify[5]??''))),$timeouts,true))
        ||($isV3&&!in_array(strtolower(trim((string)($verify[6]??''))),['true','yes'],true))
        ||($isV3&&!in_array(strtolower(trim((string)($verify[7]??''))),$timeouts,true))){
        throw new RuntimeException('O MikroTik não confirmou abertura pelo gateway, RADIUS e reconexão curta.');
    }
    return ['code'=>$code,'iface_name'=>$ifaceName,'html_dir'=>$htmlDir,'connection'=>$connection,'nas'=>$nas];
}

function fs_hotspot_apply_assets(PDO $pdo, array $connection, string $htmlDir, string $portalCode): void
{
    $files=['index.html','status.html','redirect.html','login.html','logout.html','error.html'];
    $conn=null;$sftp=null;
    if(function_exists('ssh2_connect')&&function_exists('ssh2_sftp')){
        $conn=@ssh2_connect((string)$connection['host'],(int)$connection['port']);
        if($conn){
            ros_assert_ssh2_host_key($conn,(string)($connection['expected_host_key_fingerprint']??''));
            if(@ssh2_auth_password($conn,(string)$connection['user'],(string)$connection['pass']))$sftp=@ssh2_sftp($conn);
        }
    }
    if($sftp){
        @ssh2_sftp_mkdir($sftp,'/'.$htmlDir,0775,true);@ssh2_sftp_mkdir($sftp,'/'.$htmlDir.'/img',0775,true);
        foreach($files as $file){
            $local=dirname(__DIR__).'/assets/nas/'.$file;if(!is_file($local))continue;
            $content=(string)file_get_contents($local);$content=str_replace('https://firecdn.com.br',fs_public_base_url($pdo),$content);
            $content=(string)preg_replace('/name="fast_id" value="[^"]*"/','name="fast_id" value="'.htmlspecialchars($portalCode,ENT_QUOTES,'UTF-8').'"',$content);
            $stream=@fopen('ssh2.sftp://'.$sftp.'/'.$htmlDir.'/'.$file,'w');if(!$stream)throw new RuntimeException('Não foi possível publicar os arquivos do portal no NAS.');
            fwrite($stream,$content);fclose($stream);
        }
        $gif=dirname(__DIR__).'/assets/nas/img/load-connect.gif';if(is_file($gif)){
            $stream=@fopen('ssh2.sftp://'.$sftp.'/'.$htmlDir.'/img/load-connect.gif','w');if(!$stream)throw new RuntimeException('Não foi possível publicar os ativos do portal no NAS.');
            fwrite($stream,(string)file_get_contents($gif));fclose($stream);
        }
        unset($sftp,$conn);return;
    }
    $base=fs_public_base_url($pdo).'/dashboard/api/nas_asset.php';$commands=[];
    foreach($files as $file)$commands[]='/tool fetch url='.fs_routeros_quote($base.'?file='.rawurlencode($file).'&code='.rawurlencode($portalCode)).' dst-path='.fs_routeros_quote($htmlDir.'/'.$file).' mode=https keep-result=yes';
    $commands[]='/tool fetch url='.fs_routeros_quote($base.'?file='.rawurlencode('img/load-connect.gif').'&code='.rawurlencode($portalCode)).' dst-path='.fs_routeros_quote($htmlDir.'/img/load-connect.gif').' mode=https keep-result=yes';
    fs_nas_base_assert_command_result(ros_exec($commands,$connection),'transferir os arquivos do portal','arquivos da instalação');
}

function fs_hotspot_apply_remote_remove(array $configuration, array $connection): void
{
    $code=fs_hotspot_apply_code((string)$configuration['code'],(int)($configuration['hotspot_id']??0));
    $profile=fs_routeros_quote('hs_'.$code);$iface=fs_routeros_quote('vlan'.(int)$configuration['vlan_id'].'-'.$code);$comment=fs_routeros_quote($code);
    fs_nas_base_assert_command_result(ros_exec([
        '/ip hotspot remove [find where name='.$profile.']','/ip dhcp-server remove [find where name='.fs_routeros_quote('dhcp_'.$code).']',
        '/ip hotspot profile remove [find where name='.$profile.']','/ip dhcp-server network remove [find where comment='.$comment.']',
        '/ip address remove [find where interface='.$iface.']','/ip pool remove [find where name='.fs_routeros_quote('pool_'.$code).']',
        '/interface vlan remove [find where name='.$iface.']','/ip firewall nat remove [find where comment='.fs_routeros_quote('Hotspot '.$code).']',
    ],$connection),'retirar a instalação','remoção explícita da instalação');
    $absent=fs_nas_base_assert_command_result(ros_exec([
        '/ip hotspot print count-only where name='.$profile,
        '/ip dhcp-server print count-only where name='.fs_routeros_quote('dhcp_'.$code),
        '/ip hotspot profile print count-only where name='.$profile,
        '/ip dhcp-server network print count-only where comment='.$comment,
        '/ip address print count-only where interface='.$iface,
        '/ip pool print count-only where name='.fs_routeros_quote('pool_'.$code),
        '/interface vlan print count-only where name='.$iface,
        '/ip firewall nat print count-only where comment='.fs_routeros_quote('Hotspot '.$code),
    ],$connection),'confirmar a retirada da instalação','validação da remoção');
    foreach($absent as $value)if(fs_nas_base_count_output($value,'a ausência da instalação')!==0)throw new RuntimeException('O MikroTik ainda mantém parte da instalação retirada.');
}

/** Remove apenas a interface antiga quando o perfil novo usa o mesmo NAS. */
function fs_hotspot_apply_remote_remove_stale_interface(array $configuration, array $connection): void
{
    $code=fs_hotspot_apply_code((string)$configuration['code'],(int)($configuration['hotspot_id']??0));
    $iface=fs_routeros_quote('vlan'.(int)$configuration['vlan_id'].'-'.$code);
    fs_nas_base_assert_command_result(ros_exec([
        '/ip address remove [find where interface='.$iface.']',
        '/interface vlan remove [find where name='.$iface.']',
    ],$connection),'retirar a interface antiga da instalação','limpeza da migração');
    $absent=fs_nas_base_assert_command_result(ros_exec([
        '/ip address print count-only where interface='.$iface,
        '/interface vlan print count-only where name='.$iface,
    ],$connection),'confirmar a retirada da interface antiga','validação da limpeza');
    foreach($absent as $value)if(fs_nas_base_count_output($value,'a ausência da interface antiga')!==0)throw new RuntimeException('O MikroTik ainda mantém a interface antiga da instalação.');
}

/** @return array<string,mixed> */
function fs_hotspot_apply_cleanup_change_request(PDO $pdo, array $request): array
{
    $partnerId=(int)$request['partner_id'];$hotspotId=(int)($request['hotspot_id']??0);$nasId=(int)$request['nas_id'];
    $payload=json_decode((string)($request['payload']??''),true);$old=is_array($payload)?($payload['configuration']??null):null;
    if(!is_array($old)||(int)($old['nas_id']??0)!==$nasId)throw new RuntimeException('A configuração antiga da limpeza é inválida.');
    $current=fs_partner_hotspot_by_id($pdo,$hotspotId,$partnerId,false);if(!$current)throw new RuntimeException('Instalação da limpeza não encontrada.');
    $nas=fs_hotspot_apply_nas($pdo,$nasId);$connection=fs_nas_base_connection($nas);
    if((int)$current['nas_id']===$nasId){
        $oldInterface='vlan'.(int)$old['vlan_id'].'-'.fs_hotspot_apply_code((string)$old['code'],$hotspotId);
        $currentInterface='vlan'.(int)$current['vlan_id'].'-'.fs_hotspot_apply_code((string)$current['hotspot_code'],$hotspotId);
        if($oldInterface!==$currentInterface)fs_hotspot_apply_remote_remove_stale_interface($old,$connection);
    }else fs_hotspot_apply_remote_remove($old,$connection);
    return ['hotspot_id'=>$hotspotId,'old_nas_id'=>$nasId,'cleaned'=>true];
}

/** @return array<string,mixed> */
function fs_hotspot_apply_change_request(PDO $pdo, array $request): array
{
    $partnerId=(int)$request['partner_id'];$hotspotId=(int)($request['hotspot_id']??0);$nasId=(int)$request['nas_id'];
    if((string)$request['operation']==='hotspot_cleanup')return fs_hotspot_apply_cleanup_change_request($pdo,$request);
    $hotspot=fs_partner_hotspot_by_id($pdo,$hotspotId,$partnerId,false);if(!$hotspot)throw new RuntimeException('Instalação da solicitação não encontrada.');
    $statement=$pdo->prepare('SELECT * FROM partners WHERE id=? AND active=1 LIMIT 1');$statement->execute([$partnerId]);$partner=$statement->fetch(PDO::FETCH_ASSOC);
    if(!$partner)throw new RuntimeException('Estabelecimento inativo ou ausente.');
    if((string)$request['operation']==='hotspot_deactivate'){
        if((int)$hotspot['hotspot_is_default']===1)throw new RuntimeException('A instalação principal não pode ser desativada por esta operação.');
        if((int)$hotspot['nas_id']!==$nasId)throw new RuntimeException('O NAS da solicitação não corresponde ao NAS atual da instalação.');
        $deactivatePayload=json_decode((string)($request['payload']??''),true);$configurationVersion=(int)($deactivatePayload['configuration_version']??0);
        if($configurationVersion<=0||$configurationVersion!==(int)($hotspot['desired_config_version']??0)||(int)($hotspot['last_change_request_id']??0)!==(int)$request['id'])throw new RuntimeException('A desativação foi substituída por uma versão mais recente.');
        $nas=fs_hotspot_apply_nas($pdo,(int)$hotspot['nas_id']);$connection=fs_nas_base_connection($nas);
        fs_hotspot_apply_remote_remove([
            'code'=>$hotspot['hotspot_code'],'vlan_id'=>$hotspot['vlan_id'],'hotspot_id'=>$hotspotId,
        ],$connection);
        $pdo->beginTransaction();
        try{
            $update=$pdo->prepare("UPDATE partner_hotspots SET active=0,applied_config_version=?,management_state='retired',updated_at=NOW() WHERE id=? AND partner_id=? AND desired_config_version=? AND last_change_request_id=?");
            $update->execute([$configurationVersion,$hotspotId,$partnerId,$configurationVersion,(int)$request['id']]);if($update->rowCount()!==1)throw new RuntimeException('A versão mudou durante a desativação.');
            $pdo->prepare("UPDATE partner_network_reservations SET state='released',updated_at=NOW() WHERE hotspot_id=? AND partner_id=? AND state IN ('reserved','applied')")->execute([$hotspotId,$partnerId]);$pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            try{fs_hotspot_apply_remote($pdo,$partner,[
                'code'=>$hotspot['hotspot_code'],'name'=>$hotspot['hotspot_name'],'nas_id'=>$hotspot['nas_id'],'nas_interface_id'=>$hotspot['nas_interface_id'],'vlan_id'=>$hotspot['vlan_id'],'network_prefix_length'=>$hotspot['network_prefix_length']??null,
                'gateway_ip'=>$hotspot['gateway_ip'],'pool_start'=>$hotspot['pool_start'],'pool_end'=>$hotspot['pool_end'],'dns_servers'=>$hotspot['dns_servers'],'dns_name'=>$hotspot['dns_name'],'radius_ip'=>$hotspot['radius_ip'],'active'=>1,'hotspot_id'=>$hotspotId,
            ]);}catch(Throwable $rollbackError){error_log('[hotspot_deactivate rollback] request_id='.(int)$request['id'].' code=ROLLBACK_FAILED');}
            throw $e;
        }
        return ['hotspot_id'=>$hotspotId,'active'=>false];
    }
    $payload=json_decode((string)($request['payload']??''),true);$configuration=is_array($payload)?($payload['configuration']??null):null;
    if(!is_array($configuration)||(int)($configuration['nas_id']??0)!==$nasId)throw new RuntimeException('A configuração desejada da solicitação é inválida.');
    $configurationVersion=(int)($configuration['configuration_version']??0);
    if($configurationVersion<=0||$configurationVersion!==(int)($hotspot['desired_config_version']??0)||(int)($hotspot['last_change_request_id']??0)!==(int)$request['id'])throw new RuntimeException('A solicitação foi substituída por uma versão mais recente da instalação.');
    $validated=fs_partner_hotspot_validate_payload($pdo,$configuration);$validated['configuration_version']=$configurationVersion;$validated['hotspot_id']=$hotspotId;$configuration=$validated;
    if((string)$configuration['code']!==(string)$hotspot['hotspot_code'])throw new RuntimeException('O código público permanente da instalação divergiu.');
    fs_partner_nas_assert_owned($pdo,$partnerId,$nasId,true,false);
    $networkCidr=fs_partner_hotspot_assert_network_consistent($configuration);
    $reservation=$pdo->prepare("SELECT id,network_cidr FROM partner_network_reservations WHERE partner_id=? AND nas_id=? AND hotspot_id=? AND vlan_id=? AND state IN ('reserved','applied') LIMIT 1");
    $reservation->execute([$partnerId,$nasId,$hotspotId,(int)$configuration['vlan_id']]);$reservation=$reservation->fetch(PDO::FETCH_ASSOC);
    if(!$reservation||(string)$reservation['network_cidr']!==$networkCidr)throw new RuntimeException('A reserva de VLAN/rede da solicitação não está mais válida.');
    $pdo->prepare("UPDATE partner_hotspots SET management_state='applying',updated_at=NOW() WHERE id=? AND partner_id=?")->execute([$hotspotId,$partnerId]);
    try{$remote=fs_hotspot_apply_remote($pdo,$partner,$configuration);}
    catch(Throwable $error){
        // O banco ainda contém a configuração efetiva anterior. Em ponto já
        // ativo, tenta restaurá-la no mesmo NAS; rascunhos novos são limpos.
        try{
            $desiredNas=fs_hotspot_apply_nas($pdo,$nasId);$desiredConnection=fs_nas_base_connection($desiredNas);
            if((int)$hotspot['hotspot_active']===1&&(int)$hotspot['nas_id']===$nasId){
                fs_hotspot_apply_remote($pdo,$partner,[
                    'code'=>$hotspot['hotspot_code'],'name'=>$hotspot['hotspot_name'],'nas_id'=>$hotspot['nas_id'],'nas_interface_id'=>$hotspot['nas_interface_id'],'vlan_id'=>$hotspot['vlan_id'],'network_prefix_length'=>$hotspot['network_prefix_length']??null,
                    'gateway_ip'=>$hotspot['gateway_ip'],'pool_start'=>$hotspot['pool_start'],'pool_end'=>$hotspot['pool_end'],'dns_servers'=>$hotspot['dns_servers'],'dns_name'=>$hotspot['dns_name'],'radius_ip'=>$hotspot['radius_ip'],'active'=>1,'hotspot_id'=>$hotspotId,
                ]);
                if((int)$hotspot['vlan_id']!==(int)$configuration['vlan_id'])fs_hotspot_apply_remote_remove_stale_interface($configuration,$desiredConnection);
            }else fs_hotspot_apply_remote_remove($configuration,$desiredConnection);
        }catch(Throwable $rollbackError){error_log('[hotspot_apply rollback] request_id='.(int)$request['id'].' code=ROLLBACK_FAILED');}
        throw $error;
    }
    $pdo->beginTransaction();
    try{
        if(fs_partner_hotspot_network_prefix_schema_ready($pdo)){
            $update=$pdo->prepare("UPDATE partner_hotspots SET name=?,nas_id=?,nas_interface_id=?,vlan_id=?,network_prefix_length=?,gateway_ip=?,pool_start=?,pool_end=?,dns_servers=?,dns_name=?,radius_ip=?,active=1,applied_config_version=?,management_state='ready',updated_at=NOW() WHERE id=? AND partner_id=? AND desired_config_version=? AND last_change_request_id=?");
            $update->execute([$configuration['name'],$configuration['nas_id'],$configuration['nas_interface_id'],$configuration['vlan_id'],$configuration['network_prefix_length'],$configuration['gateway_ip'],$configuration['pool_start'],$configuration['pool_end'],$configuration['dns_servers'],$configuration['dns_name'],$configuration['radius_ip'],$configurationVersion,$hotspotId,$partnerId,$configurationVersion,(int)$request['id']]);
        }else{
            $update=$pdo->prepare("UPDATE partner_hotspots SET name=?,nas_id=?,nas_interface_id=?,vlan_id=?,gateway_ip=?,pool_start=?,pool_end=?,dns_servers=?,dns_name=?,radius_ip=?,active=1,applied_config_version=?,management_state='ready',updated_at=NOW() WHERE id=? AND partner_id=? AND desired_config_version=? AND last_change_request_id=?");
            $update->execute([$configuration['name'],$configuration['nas_id'],$configuration['nas_interface_id'],$configuration['vlan_id'],$configuration['gateway_ip'],$configuration['pool_start'],$configuration['pool_end'],$configuration['dns_servers'],$configuration['dns_name'],$configuration['radius_ip'],$configurationVersion,$hotspotId,$partnerId,$configurationVersion,(int)$request['id']]);
        }
        if($update->rowCount()!==1)throw new RuntimeException('A versão desejada mudou durante a aplicação; o estado remoto será revertido.');
        $pdo->prepare("UPDATE partner_network_reservations SET state='released',updated_at=NOW() WHERE hotspot_id=? AND state IN ('reserved','applied') AND NOT (nas_id=? AND vlan_id=?)")->execute([$hotspotId,$nasId,(int)$configuration['vlan_id']]);
        $pdo->prepare("INSERT INTO partner_network_reservations (partner_id,nas_id,hotspot_id,vlan_id,network_cidr,state,expires_at) VALUES (?,?,?,?,?,'applied',NULL) ON DUPLICATE KEY UPDATE partner_id=VALUES(partner_id),hotspot_id=VALUES(hotspot_id),network_cidr=VALUES(network_cidr),state='applied',expires_at=NULL,updated_at=NOW()")
            ->execute([$partnerId,$nasId,$hotspotId,(int)$configuration['vlan_id'],fs_partner_hotspot_network_profile($configuration)['cidr']]);
        if((int)$hotspot['hotspot_is_default']===1){
            if(fs_partner_hotspot_network_prefix_schema_ready($pdo))$pdo->prepare('UPDATE partners SET nas_id=?,nas_interface_id=?,vlan_id=?,network_prefix_length=?,gateway_ip=?,pool_start=?,pool_end=?,dns_servers=?,dns_name=?,radius_ip=?,updated_at=NOW() WHERE id=?')->execute([$configuration['nas_id'],$configuration['nas_interface_id'],$configuration['vlan_id'],$configuration['network_prefix_length'],$configuration['gateway_ip'],$configuration['pool_start'],$configuration['pool_end'],$configuration['dns_servers'],$configuration['dns_name'],$configuration['radius_ip'],$partnerId]);
            else $pdo->prepare('UPDATE partners SET nas_id=?,nas_interface_id=?,vlan_id=?,gateway_ip=?,pool_start=?,pool_end=?,dns_servers=?,dns_name=?,radius_ip=?,updated_at=NOW() WHERE id=?')->execute([$configuration['nas_id'],$configuration['nas_interface_id'],$configuration['vlan_id'],$configuration['gateway_ip'],$configuration['pool_start'],$configuration['pool_end'],$configuration['dns_servers'],$configuration['dns_name'],$configuration['radius_ip'],$partnerId]);
        }
        if((int)$hotspot['hotspot_active']===1&&((int)$hotspot['nas_id']!==$nasId||(int)$hotspot['vlan_id']!==(int)$configuration['vlan_id'])){
            $oldConfiguration=[
                'hotspot_id'=>$hotspotId,'code'=>$hotspot['hotspot_code'],'name'=>$hotspot['hotspot_name'],'nas_id'=>(int)$hotspot['nas_id'],
                'nas_interface_id'=>(int)$hotspot['nas_interface_id'],'vlan_id'=>(int)$hotspot['vlan_id'],'network_prefix_length'=>$hotspot['network_prefix_length']??null,'gateway_ip'=>$hotspot['gateway_ip'],
                'pool_start'=>$hotspot['pool_start'],'pool_end'=>$hotspot['pool_end'],'dns_servers'=>$hotspot['dns_servers'],'dns_name'=>$hotspot['dns_name'],'radius_ip'=>$hotspot['radius_ip'],
            ];
            fs_partner_change_request_enqueue($pdo,$partnerId,(int)$hotspot['nas_id'],$hotspotId,'hotspot_cleanup',['configuration'=>$oldConfiguration],(int)($request['requested_by_user_id']??0),'cleanup-after-request-'.(int)$request['id']);
        }
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        try{
            if((int)$hotspot['hotspot_active']===1&&(int)$hotspot['nas_id']===$nasId){
                fs_hotspot_apply_remote($pdo,$partner,[
                    'code'=>$hotspot['hotspot_code'],'name'=>$hotspot['hotspot_name'],'nas_id'=>$hotspot['nas_id'],'nas_interface_id'=>$hotspot['nas_interface_id'],'vlan_id'=>$hotspot['vlan_id'],'network_prefix_length'=>$hotspot['network_prefix_length']??null,
                    'gateway_ip'=>$hotspot['gateway_ip'],'pool_start'=>$hotspot['pool_start'],'pool_end'=>$hotspot['pool_end'],'dns_servers'=>$hotspot['dns_servers'],'dns_name'=>$hotspot['dns_name'],'radius_ip'=>$hotspot['radius_ip'],'active'=>1,'hotspot_id'=>$hotspotId,
                ]);
                if((int)$hotspot['vlan_id']!==(int)$configuration['vlan_id'])fs_hotspot_apply_remote_remove_stale_interface($configuration,$remote['connection']);
            }else fs_hotspot_apply_remote_remove($configuration,$remote['connection']);
        }catch(Throwable $rollbackError){error_log('[hotspot_apply commit rollback] request_id='.(int)$request['id'].' code=ROLLBACK_FAILED');}
        throw $e;
    }
    return ['hotspot_id'=>$hotspotId,'nas_id'=>$nasId,'code'=>$remote['code'],'active'=>true];
}
