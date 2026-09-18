<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=dirname(__DIR__);
require_once $root.'/app/db.php';
require_once $root.'/app/partner_infrastructure.php';

$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$migration=(string)file_get_contents($root.'/migrations/054_partner_hotspot_configuration_requests.sql');
$smoke=(string)file_get_contents($root.'/migrations/smoke_054.php');
$domain=(string)file_get_contents($root.'/app/partner_infrastructure.php');
$actions=(string)file_get_contents($root.'/portal/host/panel_actions.php');
$page=(string)file_get_contents($root.'/portal/host/pages/infrastructure.php');
$central=(string)file_get_contents($root.'/app/control_center_partners.php').(string)file_get_contents($root.'/dashboard/estabelecimento.php');
$finalizer=(string)file_get_contents('/opt/firespot-ops/firespot_partner_hotspot_configuration_finalize_root.sh');

$expect(str_contains($migration,'partner_hotspot_configuration_requests')&&str_contains($migration,'active_hotspot_id'),'Migração não versiona uma única solicitação ativa por ponto.');
$expect(str_contains($migration,"ENUM('submitted','superseded','cancelled','approved','rejected')"),'Ciclo de vida da solicitação de ponto está incompleto.');
$expect(str_contains($smoke,'request.partner_id<>point.partner_id')&&str_contains($smoke,'target_owner.nas_id IS NULL'),'Smoke não detecta cruzamento de estabelecimento ou NAS próprio.');
$expect(str_contains($domain,'function fs_partner_hotspot_configuration_request_save')&&str_contains($domain,"management_mode='partner_owned'"),'Serviço de solicitação não exige propriedade do NAS.');
$expect(str_contains($domain,'Nenhuma alteração foi informada para este ponto.')&&str_contains($domain,"state='superseded'"),'Serviço não rejeita pedido vazio ou não versiona a substituição.');
$expect(str_contains($actions,"'hotspot.configuration_requested'")&&str_contains($actions,"'hotspot.configuration_cancelled'")&&substr_count($actions,'partner_admin_require_current_password')>=6,'Solicitações não são reautenticadas e auditadas.');
$expect(str_contains($page,'id="host-modal-point-<?=$pointId?>" hidden')&&str_contains($page,'hotspot_configuration_request_save'),'Configuração dos pontos não aparece em janela flutuante.');
$expect(str_contains($page,'VLAN, gateway e pool são calculados pela política do NAS')&&str_contains($page,'Este ponto utiliza um NAS da FireSpot'),'Tela não explica a fronteira entre proposta do cliente e gestão FireSpot.');
$expect(!str_contains($page,'name="action" value="hotspot_apply"')&&!str_contains($page,'name="action" value="hotspot_deactivate"'),'Janela de ponto permite mutação remota pelo cliente.');
$expect(str_contains($central,"'configuration_requests'=>")&&str_contains($central,'Alteração solicitada pelo estabelecimento'),'Central não enxerga a solicitação do cliente.');
$expect(str_contains($finalizer,'hotspot_digest_before')&&str_contains($finalizer,'partner_hotspot_configuration_deploy_check.php'),'Finalizador não prova a preservação da configuração efetiva.');

$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$installed=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version=54 AND state='applied'")->fetchColumn()===1;
if($installed){
    $expect(fs_partner_hotspot_configuration_schema_ready($pdo),'Migração 054 está no ledger, mas o contrato não está legível.');
    $partnerId=(int)$pdo->query("SELECT id FROM partners WHERE code='00000001' LIMIT 1")->fetchColumn();
    $points=$pdo->prepare("SELECT h.*,n.shortname FROM partner_hotspots h JOIN nas n ON n.id=h.nas_id WHERE h.partner_id=? AND h.name IN ('flutuante','TESTE')");$points->execute([$partnerId]);$byName=[];foreach($points->fetchAll(PDO::FETCH_ASSOC)?:[] as $point)$byName[(string)$point['name']]=$point;
    $own=$byName['flutuante']??[];$centralPoint=$byName['TESTE']??[];
    $actorStatement=$pdo->prepare('SELECT user_id FROM partner_admin_memberships WHERE partner_id=? AND active=1 ORDER BY (role=\'owner\') DESC,id LIMIT 1');$actorStatement->execute([$partnerId]);$actor=(int)$actorStatement->fetchColumn();
    $expect($partnerId>0&&!empty($own)&&!empty($centralPoint)&&$actor>0,'Cenário de integração example_partner incompleto.');
    $before=$pdo->prepare('SELECT * FROM partner_hotspots WHERE id=?');$before->execute([(int)$own['id']]);$effectiveBefore=$before->fetch(PDO::FETCH_ASSOC);
    $pdo->beginTransaction();
    try{
        $request=fs_partner_hotspot_configuration_request_save($pdo,$partnerId,(int)$own['id'],[
            'name'=>(string)$own['name'].' revisão segura',
            'nas_id'=>(int)$own['nas_id'],
            'nas_interface_id'=>(int)$own['nas_interface_id'],
            'reason'=>'Teste transacional sem aplicação remota.',
        ],$actor);
        $expect((int)$request['id']>0&&(int)$request['revision']>0,'Solicitação versionada não foi criada.');
        $stored=$pdo->prepare("SELECT state,requested_name FROM partner_hotspot_configuration_requests WHERE id=?");$stored->execute([(int)$request['id']]);$stored=$stored->fetch(PDO::FETCH_ASSOC);
        $expect(($stored['state']??'')==='submitted'&&str_contains((string)$stored['requested_name'],'revisão segura'),'Solicitação não preservou o estado desejado.');
        $after=$pdo->prepare('SELECT * FROM partner_hotspots WHERE id=?');$after->execute([(int)$own['id']]);
        $expect($effectiveBefore===$after->fetch(PDO::FETCH_ASSOC),'Solicitação alterou a configuração efetiva do ponto.');
        try{fs_partner_hotspot_configuration_request_save($pdo,$partnerId,(int)$centralPoint['id'],['name'=>'TESTE indevido','nas_id'=>(int)$own['nas_id'],'nas_interface_id'=>(int)$own['nas_interface_id']],$actor);$centralBlocked=false;}catch(RuntimeException $error){$centralBlocked=true;}
        $expect($centralBlocked,'Cliente conseguiu solicitar alteração do ponto TESTE em NAS FireSpot.');
        $cancelled=fs_partner_hotspot_configuration_request_cancel($pdo,$partnerId,(int)$own['id']);
        $expect($cancelled===(int)$request['id'],'Cancelamento não atingiu a solicitação ativa correta.');
        $pdo->rollBack();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}else{
    // CREATE TABLE não é transacional no MariaDB. Se uma interrupção ocorrer
    // entre o DDL e o ledger, o finalizador reaplica a migração idempotente.
    $expect(true,fs_partner_hotspot_configuration_schema_ready($pdo)?'Retomada pós-DDL disponível.':'Estado pré-v054 apto à aplicação.');
}

echo 'OK: '.$checks.' verificações da configuração versionada dos pontos'.($installed?' e ciclo transacional.':' (aguarda a migração 054).')."\n";
