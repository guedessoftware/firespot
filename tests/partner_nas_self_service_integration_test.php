<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=dirname(__DIR__);
require_once $root.'/app/db.php';
require_once $root.'/app/partner_infrastructure.php';

$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$migration=(string)file_get_contents($root.'/migrations/053_partner_owned_nas_self_service.sql');
$adoption=(string)file_get_contents($root.'/app/cli/adopt_example_partner_partner_nas.php');
$domain=(string)file_get_contents($root.'/app/partner_infrastructure.php');
$actions=(string)file_get_contents($root.'/portal/host/panel_actions.php');
$page=(string)file_get_contents($root.'/portal/host/pages/infrastructure.php');
$css=(string)file_get_contents($root.'/portal/host/assets/host-admin.css');
$javascript=(string)file_get_contents($root.'/portal/host/assets/host-admin.js');
$finalizer=(string)file_get_contents('/opt/firespot-ops/firespot_partner_nas_self_service_finalize_root.sh');

$expect(str_contains($migration,"SELECT equipment.id,partner.id,'firespot_dedicated'")&&str_contains($migration,"equipment.shortname IN ('Demo-NAS-A','Demo-NAS-B')"),'Migração não inicia a adoção fechada em modo central compatível.');
$expect(str_contains($migration,"partner.code='00000001'")&&str_contains($migration,"equipment.shortname='FireSpot'")===false,'Migração não está isolada ao example_partner ou tenta incluir o NAS FireSpot.');
$expect(str_contains($migration,"'nas.manage',0")&&str_contains($migration,"'hotspots.apply',0")&&str_contains($migration,"10,25,10,366,25")&&str_contains($adoption,"feature_code IN ('nas.manage','nas.prepare','nas.retire')"),'Contrato Multipontos v3 não aguarda a propriedade atômica para liberar capacidades ou perdeu suas cotas.');
$transactionPosition=strpos($adoption,'$pdo->beginTransaction()');
$expect($transactionPosition!==false&&str_contains($adoption,"management_mode='partner_owned'")&&str_contains($adoption,"status='pending'")&&str_contains($adoption,'UPDATE nas SET mgmt_password=NULL'),'Adoção não classifica os dois NAS de forma atômica, pendente e com credenciais protegidas.');
$expect(!str_contains($adoption,'ros_exec(')&&str_contains($adoption,'remote_changes=none verification=deferred'),'Adoção de propriedade ainda depende da disponibilidade remota do NAS.');
$expect(str_contains($domain,"ELSE NULL END management_address")&&str_contains($domain,"o.management_mode='partner_owned'"),'Listagem pode expor endereço central ou operar NAS sem propriedade.');
$expect(str_contains($domain,'fs_partner_hotspot_assert_draft_nas')&&str_contains($domain,"fs_partner_has_entitlement(\$pdo,\$partnerId,'nas.manage',true)"),'Novos pontos não ficam restritos ao NAS próprio no plano v3.');
foreach(['nas_register','nas_update','nas_sync','nas_prepare','nas_retire'] as $action)$expect(str_contains($actions,"\$action==='{$action}'"),'Ação autenticada de NAS ausente: '.$action);
$expect(!str_contains($page,'name="action" value="hotspot_apply"')&&!str_contains($page,'name="action" value="hotspot_deactivate"'),'Tela permite aplicar ou retirar remotamente um ponto.');
$expect(str_contains($page,'id="host-modal-new-point" hidden')&&str_contains($page,'data-host-modal-open="host-modal-new-point"'),'Novo ponto não usa janela flutuante.');
$expect(str_contains($page,'host-cell-stack')&&str_contains($css,'.host-cell-stack > strong')&&str_contains($css,'.host-infrastructure__table td'),'Correção de elementos concatenados nas tabelas está incompleta.');
$expect(str_contains($javascript,"event.key==='Escape'")&&str_contains($javascript,'modalOpener')&&str_contains($javascript,'focusableSelector'),'Modal não preserva fechamento por teclado, foco e retorno ao acionador.');
$expect(str_contains($finalizer,'--target-active-queue')&&str_contains($finalizer,'portal_digest_before')&&str_contains($finalizer,'hotspot_digest_before')&&str_contains($finalizer,'nas_core_digest_before'),'Finalizador não bloqueia concorrência ou prova a preservação dos dados.');
$expect(str_contains($finalizer,'adopt_example_partner_partner_nas.php')&&str_contains($finalizer,'A adoção não executa comandos remotos')&&!str_contains($finalizer,'systemctl start firespot-job@partner_infrastructure'),'Finalizador não limita a adoção à classificação segura dos dois NAS.');

$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$installed=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version=53 AND state='applied'")->fetchColumn()===1;
$cycleComplete=false;
if($installed){
    $partnerId=(int)$pdo->query("SELECT id FROM partners WHERE code='00000001' LIMIT 1")->fetchColumn();
    $expect($partnerId>0,'Estabelecimento example_partner ausente.');
    $staged=$pdo->prepare("SELECT COUNT(*) FROM partner_nas_ownerships o JOIN nas n ON n.id=o.nas_id WHERE o.partner_id=? AND o.management_mode='firespot_dedicated' AND n.shortname IN ('Demo-NAS-A','Demo-NAS-B')");$staged->execute([$partnerId]);$stagedCount=(int)$staged->fetchColumn();
    if($stagedCount===2){
        $enabled=$pdo->query("SELECT COUNT(*) FROM platform_plan_features f JOIN platform_plans p ON p.id=f.plan_id WHERE p.code='multipoint_advanced' AND p.version=3 AND f.feature_code IN ('nas.manage','nas.prepare','nas.retire') AND f.enabled=1")->fetchColumn();
        $expect((int)$enabled===0,'Capacidades foram liberadas antes da validação atômica dos dois NAS.');
        $legacy=$pdo->prepare("SELECT COUNT(*) FROM nas n JOIN partner_nas_ownerships o ON o.nas_id=n.id WHERE o.partner_id=? AND o.management_mode='firespot_dedicated' AND n.shortname IN ('Demo-NAS-A','Demo-NAS-B') AND n.mgmt_password IS NOT NULL AND n.mgmt_password<>''");$legacy->execute([$partnerId]);
        $expect((int)$legacy->fetchColumn()===2,'Estado de retomada perdeu credencial legada antes da promoção.');
        // Simula a retomada inteira dentro de uma transação e reverte tudo,
        // permitindo validar o estado pós-adoção antes do comando root.
        $targets=$pdo->prepare("SELECT n.id,n.shortname,n.mgmt_username,n.mgmt_password,n.secret FROM nas n JOIN partner_nas_ownerships o ON o.nas_id=n.id WHERE o.partner_id=? AND o.management_mode='firespot_dedicated' AND n.shortname IN ('Demo-NAS-A','Demo-NAS-B') ORDER BY n.id");$targets->execute([$partnerId]);$targets=$targets->fetchAll(PDO::FETCH_ASSOC)?:[];
        $pdo->beginTransaction();
        try{
            foreach($targets as $target){
                $ciphertext=fs_nas_credentials_encrypt((string)$target['mgmt_username'],(string)$target['mgmt_password'],(string)$target['secret']);
                $pdo->prepare("UPDATE partner_nas_ownerships SET management_mode='partner_owned',status='pending',credentials_ciphertext=? WHERE partner_id=? AND nas_id=?")->execute([$ciphertext,$partnerId,(int)$target['id']]);
                $pdo->prepare("UPDATE partner_nas_assignments SET assignment_source='partner_owned',status='ready' WHERE partner_id=? AND nas_id=?")->execute([$partnerId,(int)$target['id']]);
            }
            $pdo->prepare("UPDATE platform_plan_features f JOIN platform_plans p ON p.id=f.plan_id SET f.enabled=1 WHERE p.code='multipoint_advanced' AND p.version=3 AND f.feature_code IN ('nas.manage','nas.prepare','nas.retire')")->execute();
            $simulated=fs_partner_nas_list($pdo,$partnerId);$simulatedByName=[];foreach($simulated as $row)$simulatedByName[(string)$row['shortname']]=$row;
            $expect(($simulatedByName['Demo-NAS-A']['management_mode']??'')==='partner_owned'&&($simulatedByName['Demo-NAS-B']['management_mode']??'')==='partner_owned','Retomada simulada não expõe os dois NAS como propriedade do example_partner.');
            $expect(($simulatedByName['FireSpot']['management_mode']??'')==='firespot_managed'&&($simulatedByName['FireSpot']['management_address']??null)===null,'Retomada simulada transfere ou expõe o NAS FireSpot.');
            try{fs_partner_hotspot_assert_draft_nas($pdo,$partnerId,(int)$simulatedByName['FireSpot']['id'],false);$simulatedCentralBlocked=false;}catch(RuntimeException $error){$simulatedCentralBlocked=true;}
            $expect($simulatedCentralBlocked,'Retomada simulada permite rascunho forjado no NAS FireSpot.');
            $expect((int)fs_partner_hotspot_assert_draft_nas($pdo,$partnerId,(int)$simulatedByName['Demo-NAS-A']['id'],false)['nas_id']===(int)$simulatedByName['Demo-NAS-A']['id'],'Retomada simulada não libera rascunho no NAS próprio com base preparada.');
            $pdo->rollBack();$cycleComplete=true;
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }else{
    $expect($stagedCount===0,'Adoção parcial promoveu somente um dos NAS.');
    $rows=fs_partner_nas_list($pdo,$partnerId);$byName=[];foreach($rows as $row)$byName[(string)$row['shortname']]=$row;
    foreach(['Demo-NAS-A','Demo-NAS-B'] as $name){
        $expect(($byName[$name]['management_mode']??'')==='partner_owned',$name.' não aparece como NAS do estabelecimento.');
        $expect(!empty($byName[$name]['management_address'])&&(int)($byName[$name]['credentials_configured']??0)===1,$name.' não possui acesso protegido utilizável.');
    }
    $expect(($byName['FireSpot']['management_mode']??'')==='firespot_managed'&&($byName['FireSpot']['management_address']??null)===null,'NAS FireSpot não permaneceu central e sem endereço exposto.');
    $fireSpotId=(int)($byName['FireSpot']['id']??0);$ownId=(int)($byName['Demo-NAS-A']['id']??0);
    try{fs_partner_hotspot_assert_draft_nas($pdo,$partnerId,$fireSpotId,false);$centralBlocked=false;}catch(RuntimeException $error){$centralBlocked=true;}
    $expect($centralBlocked,'POST forjado pode criar ponto novo no NAS FireSpot.');
    $expect((int)fs_partner_hotspot_assert_draft_nas($pdo,$partnerId,$ownId,false)['nas_id']===$ownId,'NAS próprio com base preparada foi recusado para um novo rascunho sem mutação remota.');

    $before=$pdo->prepare('SELECT credentials_ciphertext,status FROM partner_nas_ownerships WHERE partner_id=? AND nas_id=?');$before->execute([$partnerId,$ownId]);$before=$before->fetch(PDO::FETCH_ASSOC);
    $actor=(int)$pdo->query('SELECT user_id FROM partner_admin_memberships WHERE partner_id='.$partnerId.' AND active=1 ORDER BY (role=\'owner\') DESC,id LIMIT 1')->fetchColumn();
    $pdo->beginTransaction();
    try{
        $requestId=fs_partner_nas_update_credentials($pdo,$partnerId,$ownId,[
            'name'=>'Demo-NAS-A','address'=>(string)$byName['Demo-NAS-A']['management_address'],'port'=>(int)$byName['Demo-NAS-A']['mgmt_port'],
            'username'=>'integration-user','password'=>'integration-password','radius_secret'=>'integration-radius-secret',
        ],$actor,'integration-'.bin2hex(random_bytes(8)));
        $expect($requestId>0,'Atualização protegida não gerou solicitação idempotente.');
        $inside=$pdo->prepare('SELECT credentials_ciphertext,status FROM partner_nas_ownerships WHERE partner_id=? AND nas_id=?');$inside->execute([$partnerId,$ownId]);$inside=$inside->fetch(PDO::FETCH_ASSOC);
        $expect(str_starts_with((string)$inside['credentials_ciphertext'],FS_NAS_CREDENTIAL_PREFIX)&&!str_contains((string)$inside['credentials_ciphertext'],'integration-password'),'Credencial atualizada não foi criptografada.');
        $pdo->rollBack();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    $after=$pdo->prepare('SELECT credentials_ciphertext,status FROM partner_nas_ownerships WHERE partner_id=? AND nas_id=?');$after->execute([$partnerId,$ownId]);$after=$after->fetch(PDO::FETCH_ASSOC);
    $expect($before===$after,'Rollback do ensaio não restaurou as credenciais do NAS.');
    $cycleComplete=true;
    }
}else{
    // DDL do MariaDB não é transacional. Uma retomada pode encontrar a
    // coluna já criada antes do ledger; a migração 053 é idempotente e o
    // finalizador reaplica o conjunto completo sem promover propriedade.
    $expect(true,'Estado pré-v053 apto à retomada idempotente.');
}

echo 'OK: '.$checks.' verificações da autogestão de NAS próprio'.($cycleComplete?' e ciclo transacional.':($installed?' (adoção remota pendente e capacidades fechadas).':' (ciclo transacional aguarda a migração 053).'))."\n";
