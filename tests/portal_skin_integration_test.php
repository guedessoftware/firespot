<?php

declare(strict_types=1);

require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/portal_skin.php';

$pdo=db();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
if(!fs_portal_skin_schema_ready($pdo)){echo "SKIP: migração 050 ainda não aplicada.\n";exit(0);}
$partner=$pdo->query("SELECT p.* FROM partners p WHERE NOT EXISTS (SELECT 1 FROM partner_portal_presentations x WHERE x.partner_id=p.id AND x.state IN ('draft','candidate')) ORDER BY p.id LIMIT 1")->fetch();
if(!$partner){echo "SKIP: nenhum estabelecimento disponível para teste transacional.\n";exit(0);}
$checks=0;
function portal_skin_db_expect(bool $condition,string $message):void{global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
$partnerId=(int)$partner['id'];$modeBefore=(string)$partner['portal_mode'];
$pdo->beginTransaction();
try{
    $draft=fs_portal_presentation_prepare($pdo,$partnerId,0);
    portal_skin_db_expect((string)$draft['state']==='draft','Preparação não criou rascunho.');
    portal_skin_db_expect(in_array((string)$draft['skin_code'],array_keys(fs_portal_skin_builtin_catalog()),true),'Mapeamento legado gerou skin inválida.');
    $saved=fs_portal_presentation_save($pdo,$partnerId,['skin_code'=>'institutional','brand_name'=>'Marca <b>segura</b>','headline'=>'Boas-vindas','message'=>'Conteúdo institucional','legal'=>'Uso responsável','theme_mode'=>'dark','primary_color'=>'#112233','secondary_color'=>'#445566','background_color'=>'#071225'],0);
    portal_skin_db_expect((string)$saved['skin_code']==='institutional','Skin não foi atualizada.');
    portal_skin_db_expect((string)$saved['identity']['brand_name']==='Marca segura','Identidade não foi sanitizada.');
    $state=fs_portal_migration_state($pdo,$partnerId);
    portal_skin_db_expect(empty($state['preview_mobile_approved'])&&empty($state['preview_desktop_approved']),'Gravação não zerou aprovações.');
    $mode=(string)$pdo->query('SELECT portal_mode FROM partners WHERE id='.$partnerId)->fetchColumn();
    portal_skin_db_expect($mode===$modeBefore,'Preparação alterou o portal ativo.');
    $published=(int)$pdo->query("SELECT COUNT(*) FROM partner_portal_presentations WHERE partner_id={$partnerId} AND state='published'")->fetchColumn();
    portal_skin_db_expect($published===0,'Preparação publicou apresentação.');
    fs_portal_presentation_discard($pdo,$partnerId,0);
    portal_skin_db_expect(fs_portal_presentation_get($pdo,$partnerId,'draft')===null,'Descarte não retirou rascunho atual.');
    $pdo->rollBack();
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
echo 'OK: '.$checks." verificações transacionais do Portal V3 sem ativação.\n";

