<?php

declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root . '/app/db.php';
require_once $root . '/app/control_center_partners.php';

$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$pdo=db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

$all=fs_control_center_partners($pdo,['per_page'=>10]);
$expect($all['total']>=count($all['rows']),'Total global menor que a página retornada.');
$expect(count($all['rows'])<=10,'Paginação retornou mais linhas que o limite.');
$expect($all['page']===1&&$all['pages']>=1,'Metadados de paginação inválidos.');
$kpis=fs_control_center_partner_kpis($pdo);
$expect($kpis['total']===$all['total'],'KPI total não concilia com a lista global.');
$expect($kpis['active']+$kpis['inactive']===$kpis['total'],'Situações não conciliam com o total de estabelecimentos.');

$multipoint=fs_control_center_partners($pdo,['points'=>'multiple','per_page'=>100]);
foreach($multipoint['rows'] as $row)$expect((int)$row['points_total']>1,'Filtro multiponto retornou conta com menos de dois pontos.');
$independent=fs_control_center_partners($pdo,['billing'=>'independent','per_page'=>100]);
foreach($independent['rows'] as $row)$expect((int)$row['independent_billing']===1,'Filtro financeiro retornou conta global.');

if($all['rows']){
    $id=(int)$all['rows'][0]['id'];
    $context=fs_control_center_partner_context($pdo,$id);
    $expect((int)$context['partner']['id']===$id,'Contexto abriu outro estabelecimento.');
    foreach($context['hotspots'] as $hotspot){
        $expect((int)$hotspot['partner_id']===$id,'Contexto misturou ponto de outro estabelecimento.');
        if((int)$hotspot['active']===1)$expect((int)($hotspot['nas_id']??0)>0,'Ponto ativo sem NAS apareceu no contexto canônico.');
    }
    $expect(isset($context['limits'],$context['usage'],$context['apply_gate']),'Contexto não trouxe cotas e gate operacional.');

    $catalog=fs_control_center_nas_catalog($pdo);
    $candidateNas=null;$candidateInterface=null;
    foreach($catalog['nas'] as $nas){
        $nasId=(int)$nas['id'];
        if((string)($nas['base_status']??'')==='ready'&&!empty($catalog['interfaces'][$nasId])){
            $candidateNas=$nas;$candidateInterface=$catalog['interfaces'][$nasId][0];break;
        }
    }
    $expect(is_array($candidateNas)&&is_array($candidateInterface),'Não há NAS preparado com interface para testar rascunhos.');
    $beforeHotspots=(int)$pdo->query('SELECT COUNT(*) FROM partner_hotspots')->fetchColumn();
    $beforeReservations=(int)$pdo->query('SELECT COUNT(*) FROM partner_network_reservations')->fetchColumn();
    $pdo->beginTransaction();
    try{
        $usage=fs_partner_quota_usage($pdo,$id,'max_hotspots');
        fs_partner_create_feature_override($pdo,$id,'quota.max_hotspots',true,$usage+1,2,'Teste transacional da Central',0);
        $draftId=fs_control_center_point_draft_create($pdo,$id,[
            'name'=>'Rascunho Central '.bin2hex(random_bytes(3)),
            'nas_id'=>(int)$candidateNas['id'],
            'nas_interface_id'=>(int)$candidateInterface['id'],
            'dns_servers'=>'1.1.1.1,8.8.8.8',
        ]);
        $draft=fs_partner_hotspot_by_id($pdo,$draftId,$id,false);
        $expect(is_array($draft)&&(int)$draft['hotspot_active']===0&&(string)$draft['management_state']==='draft','Criação não produziu um rascunho local inativo.');
        $expect(str_ends_with((string)$draft['dns_name'],'.hotspot.internal'),'Rascunho perdeu o identificador lógico da zona privada.');
        $reservation=$pdo->prepare("SELECT state FROM partner_network_reservations WHERE hotspot_id=? AND partner_id=? ORDER BY id DESC LIMIT 1");
        $reservation->execute([$draftId,$id]);
        $expect((string)$reservation->fetchColumn()==='reserved','A rede do rascunho não foi reservada atomicamente.');
        fs_control_center_point_draft_update($pdo,$id,$draftId,[
            'name'=>'Rascunho Central atualizado',
            'nas_id'=>(int)$candidateNas['id'],
            'nas_interface_id'=>(int)$candidateInterface['id'],
            'dns_servers'=>'9.9.9.9,1.1.1.1',
        ]);
        $updated=fs_partner_hotspot_by_id($pdo,$draftId,$id,false);
        $expect((string)$updated['hotspot_name']==='Rascunho Central atualizado'&&(string)$updated['dns_servers']==='9.9.9.9,1.1.1.1','Edição do rascunho não preservou os dados validados.');
        fs_control_center_point_draft_discard($pdo,$id,$draftId);
        $discarded=fs_partner_hotspot_by_id($pdo,$draftId,$id,false);
        $reservation->execute([$draftId,$id]);
        $expect((string)$discarded['management_state']==='retired'&&(string)$reservation->fetchColumn()==='released','Descarte não aposentou o rascunho e liberou sua rede.');
        $pdo->rollBack();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    $expect((int)$pdo->query('SELECT COUNT(*) FROM partner_hotspots')->fetchColumn()===$beforeHotspots,'O teste deixou um ponto persistido.');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM partner_network_reservations')->fetchColumn()===$beforeReservations,'O teste deixou uma reserva persistida.');
}

$beforePartners=(int)$pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn();
$pdo->beginTransaction();
try{
    $draftPartnerId=fs_control_center_partner_create_draft($pdo,[
        'name'=>'Estabelecimento transacional da Central',
        'platform_plan_code'=>'multipoint_advanced',
        'subscription_status'=>'trial',
        'subscription_reason'=>'Validação automatizada da criação',
        'self_service_enabled'=>'1',
    ],0);
    $draftContext=fs_control_center_partner_context($pdo,$draftPartnerId);
    $expect((int)$draftContext['partner']['active']===0&&(int)$draftContext['partner']['self_service_enabled']===1,'Novo estabelecimento não nasceu como rascunho inativo com a opção de painel preservada.');
    $expect((string)$draftContext['subscription']['plan_code']==='multipoint_advanced'&&(int)$draftContext['limits']['max_hotspots']===25,'Plano FireSpot inicial não foi atribuído com suas cotas.');
    $expect(count($draftContext['hotspots'])===1&&(int)$draftContext['hotspots'][0]['is_default']===1&&(int)$draftContext['hotspots'][0]['active']===0,'Ponto principal inicial não foi criado como contexto inativo.');
    $onboarding=fs_control_center_partner_onboarding($pdo,$draftPartnerId,$draftContext);
    $expect(count($onboarding['steps'])===7&&array_column($onboarding['steps'],'number')===[1,2,3,4,5,6,7],'Fluxo guiado não possui as sete etapas ordenadas.');
    $expect($onboarding['steps'][0]['complete']===true&&$onboarding['steps'][2]['complete']===false&&$onboarding['steps'][6]['complete']===false,'Prontidão do novo rascunho foi calculada incorretamente.');
    $expect($onboarding['steps'][6]['label']==='Revisão e candidato','Fluxo guiado voltou a prometer ativação do Portal V3.');
    fs_control_center_partner_save_registration($pdo,$draftPartnerId,['name'=>'Estabelecimento transacional atualizado'],0);
    $updatedPartner=partner_central_partner($pdo,$draftPartnerId);
    $expect((string)$updatedPartner['name']==='Estabelecimento transacional atualizado'&&(int)$updatedPartner['active']===0,'Cadastro contextual não foi atualizado sem ativação implícita.');
    fs_portal_config_apply_preset($pdo,$draftPartnerId,'paid','firespot',0);
    $configuredContext=fs_control_center_partner_context($pdo,$draftPartnerId);
    $configuredOnboarding=fs_control_center_partner_onboarding($pdo,$draftPartnerId,$configuredContext);
    $expect($configuredOnboarding['steps'][1]['complete']===true,'Preset funcional não concluiu a etapa de experiência.');
    $window=partner_central_save_payment_window($pdo,$draftPartnerId,['payment_window_minutes'=>3,'payment_window_daily_limit'=>4,'payment_window_cooldown_minutes'=>15,'payment_window_period_hours'=>48]);
    $expect($window===['window_minutes'=>3,'daily_limit'=>4,'cooldown_minutes'=>15,'period_minutes'=>2880],'Política canônica da janela Pix não foi normalizada corretamente.');
    $storedWindow=partner_central_partner($pdo,$draftPartnerId);
    $expect((int)$storedWindow['payment_window_minutes']===3&&(int)$storedWindow['payment_window_period_minutes']===2880,'Política da janela Pix não foi persistida dentro do contexto correto.');
    try{partner_central_save_payment_window($pdo,$draftPartnerId,['payment_window_minutes'=>9,'payment_window_daily_limit'=>4,'payment_window_cooldown_minutes'=>15,'payment_window_period_hours'=>48]);$expect(false,'Janela Pix fora do limite foi aceita.');}catch(InvalidArgumentException $error){$expect(true,'Janela Pix inválida bloqueada.');}
    $pdo->rollBack();
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
$expect((int)$pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn()===$beforePartners,'O teste deixou um estabelecimento persistido.');

echo "OK: {$checks} verificações integradas da Central de estabelecimentos.\n";
