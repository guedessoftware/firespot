<?php

declare(strict_types=1);

require_once __DIR__ . '/portal_configuration.php';
require_once __DIR__ . '/portal_theme.php';
require_once __DIR__ . '/partner_admin.php';

/**
 * Apresentação versionada do Portal V3.
 *
 * Este domínio não ativa o Portal V3, não publica jornada funcional e não
 * conhece RouterOS. Ele prepara, valida e aprova apenas aparência e conteúdo.
 */

function fs_portal_skin_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT code,version,manifest_json FROM portal_skin_catalog LIMIT 0');
        $pdo->query('SELECT id,partner_id,state,skin_code,identity_json,content_json FROM partner_portal_presentations LIMIT 0');
        $pdo->query('SELECT partner_id,status,preview_mobile_approved,preview_desktop_approved FROM partner_portal_migrations LIMIT 0');
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

/** @return array<string,array<string,mixed>> */
function fs_portal_skin_builtin_catalog(): array
{
    return [
        'balanced'=>[
            'code'=>'balanced','version'=>1,'label'=>'Equilibrado',
            'description'=>'Marca, mensagem e modalidades em equilíbrio.',
            'manifest'=>['regions'=>['brand','message','options','legal'],'components'=>['welcome','courtesy','paid','subscriber','plans'],'content'=>['headline'=>80,'message'=>240,'legal'=>300]],
        ],
        'quick-connect'=>[
            'code'=>'quick-connect','version'=>1,'label'=>'Conexão rápida',
            'description'=>'Pouco conteúdo e ação principal em destaque.',
            'manifest'=>['regions'=>['brand','primary_action','legal'],'components'=>['welcome','courtesy','paid','subscriber','plans'],'content'=>['headline'=>60,'message'=>160,'legal'=>300]],
        ],
        'sponsored-focus'=>[
            'code'=>'sponsored-focus','version'=>1,'label'=>'Patrocinado em destaque',
            'description'=>'Maior visibilidade para a peça publicitária.',
            'manifest'=>['regions'=>['brand','sponsor','options','legal'],'components'=>['welcome','courtesy','paid','subscriber','plans','sponsor'],'content'=>['headline'=>80,'message'=>220,'legal'=>300]],
        ],
        'access-catalog'=>[
            'code'=>'access-catalog','version'=>1,'label'=>'Catálogo de acesso',
            'description'=>'Prioriza comparação e compra dos planos.',
            'manifest'=>['regions'=>['brand','plans','options','legal'],'components'=>['welcome','courtesy','paid','subscriber','plans'],'content'=>['headline'=>80,'message'=>180,'legal'=>300]],
        ],
        'institutional'=>[
            'code'=>'institutional','version'=>1,'label'=>'Institucional',
            'description'=>'Maior presença de marca e conteúdo institucional.',
            'manifest'=>['regions'=>['brand','hero','message','options','legal'],'components'=>['welcome','courtesy','paid','subscriber','plans'],'content'=>['headline'=>100,'message'=>400,'legal'=>300]],
        ],
    ];
}

/** @return array<string,array<string,mixed>> */
function fs_portal_skin_catalog(PDO $pdo, bool $activeOnly = true): array
{
    $fallback = fs_portal_skin_builtin_catalog();
    if (!fs_portal_skin_schema_ready($pdo)) return $fallback;
    try {
        $sql='SELECT code,version,label,description,manifest_json,active FROM portal_skin_catalog';
        if ($activeOnly) $sql.=' WHERE active=1';
        $sql.=' ORDER BY label,version DESC';
        $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $catalog=[];
        foreach($rows as $row){
            $code=(string)$row['code'];
            if(isset($catalog[$code]))continue;
            $manifest=json_decode((string)$row['manifest_json'],true);
            if(!is_array($manifest))continue;
            $row['version']=(int)$row['version'];$row['active']=(int)$row['active'];$row['manifest']=$manifest;
            unset($row['manifest_json']);$catalog[$code]=$row;
        }
        return $catalog ?: $fallback;
    } catch (Throwable $error) {
        return $fallback;
    }
}

function fs_portal_skin_from_legacy_theme(string $preset): string
{
    return in_array($preset,['compact_blue','compact_light'],true) ? 'quick-connect' : 'balanced';
}

function fs_portal_plain_text($value, int $maximum, string $fallback = ''): string
{
    $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',trim((string)$value)) ?? '';
    $value=trim(strip_tags($value));
    if($value==='')$value=$fallback;
    return function_exists('mb_substr') ? mb_substr($value,0,$maximum,'UTF-8') : substr($value,0,$maximum);
}

function fs_portal_presentation_media_path($value, int $partnerId): ?string
{
    $path=ltrim(trim((string)$value),'/');
    if($path==='')return null;
    $quoted=preg_quote((string)$partnerId,'#');
    if(!preg_match('#^portal-v3/uploads/(?:branding|content)/partner_'.$quoted.'_[a-f0-9]{16}\.(?:png|jpe?g|webp)$#',$path)){
        throw new InvalidArgumentException('A mídia informada não pertence ao estabelecimento.');
    }
    return $path;
}

function fs_portal_presentation_media_file(?string $path): ?string
{
    $path=ltrim(trim((string)$path),'/');
    if(!preg_match('#^portal-v3/uploads/(?:branding|content)/partner_[0-9]+_[a-f0-9]{16}\.(?:png|jpe?g|webp)$#',$path))return null;
    return dirname(__DIR__).'/'.$path;
}

function fs_portal_presentation_store_image(int $partnerId, array $upload, string $area): string
{
    if($partnerId<=0||!in_array($area,['branding','content'],true))throw new InvalidArgumentException('Destino de imagem inválido.');
    $error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);if($error===UPLOAD_ERR_NO_FILE)return '';
    if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Não foi possível receber a imagem.');
    $maximum=$area==='branding'?2*1024*1024:4*1024*1024;
    if((int)($upload['size']??0)<=0||(int)$upload['size']>$maximum)throw new RuntimeException('A imagem excede o limite permitido.');
    $temporary=(string)($upload['tmp_name']??'');if($temporary===''||!is_uploaded_file($temporary))throw new RuntimeException('Upload de imagem inválido.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($temporary);$extensions=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    if(!isset($extensions[$mime]))throw new RuntimeException('Use uma imagem PNG, JPG ou WEBP.');
    $dimensions=@getimagesize($temporary);
    if(!$dimensions||(int)$dimensions[0]<1||(int)$dimensions[1]<1||(int)$dimensions[0]>6000||(int)$dimensions[1]>6000)throw new RuntimeException('A imagem deve ter dimensões válidas de até 6000 × 6000 pixels.');
    $directory=dirname(__DIR__).'/portal-v3/uploads/'.$area;
    if(!is_dir($directory)&&!mkdir($directory,02770,true)&&!is_dir($directory))throw new RuntimeException('Não foi possível preparar a pasta de imagens.');
    if(!is_writable($directory))throw new RuntimeException('A pasta de imagens não está disponível para gravação.');
    $filename='partner_'.$partnerId.'_'.bin2hex(random_bytes(8)).'.'.$extensions[$mime];
    if(!move_uploaded_file($temporary,$directory.'/'.$filename))throw new RuntimeException('Não foi possível armazenar a imagem.');
    @chmod($directory.'/'.$filename,0644);
    return 'portal-v3/uploads/'.$area.'/'.$filename;
}

function fs_portal_presentation_delete_new_image(?string $path): void
{
    $file=fs_portal_presentation_media_file($path);
    if($file!==null&&is_file($file))@unlink($file);
}

/** @return array<string,mixed> */
function fs_portal_presentation_identity(array $values, array $base = [], int $partnerId = 0): array
{
    $color=static function($value,string $fallback):string{return portal_theme_color($value,$fallback);};
    return [
        'brand_name'=>fs_portal_plain_text($values['brand_name']??$base['brand_name']??'',100,'FireSpot'),
        'brand_subtitle'=>fs_portal_plain_text($values['brand_subtitle']??$base['brand_subtitle']??'',160,'Wi-Fi seguro e rápido'),
        'show_title'=>fs_portal_config_bool($values['show_title']??$base['show_title']??1),
        'theme_mode'=>in_array((string)($values['theme_mode']??$base['theme_mode']??'light'),['light','dark'],true)?(string)($values['theme_mode']??$base['theme_mode']??'light'):'light',
        'primary_color'=>$color($values['primary_color']??$base['primary_color']??'','#ff9f1c'),
        'secondary_color'=>$color($values['secondary_color']??$base['secondary_color']??'','#ff6b00'),
        'background_color'=>$color($values['background_color']??$base['background_color']??'','#071225'),
        'logo_light_path'=>fs_portal_presentation_media_path($values['logo_light_path']??$base['logo_light_path']??null,$partnerId),
        'logo_dark_path'=>fs_portal_presentation_media_path($values['logo_dark_path']??$base['logo_dark_path']??null,$partnerId),
    ];
}

/** @return array<string,mixed> */
function fs_portal_presentation_content(array $values, array $base = [], int $partnerId = 0): array
{
    $density=(string)($values['density']??$base['density']??'comfortable');
    $alignment=(string)($values['alignment']??$base['alignment']??'center');
    return [
        'headline'=>fs_portal_plain_text($values['headline']??$base['headline']??'',100,'Conecte-se ao Wi-Fi'),
        'message'=>fs_portal_plain_text($values['message']??$base['message']??'',400,'Escolha como deseja acessar.'),
        'legal'=>fs_portal_plain_text($values['legal']??$base['legal']??'',300,'Conexão protegida.'),
        'continue_label'=>fs_portal_plain_text($values['continue_label']??$base['continue_label']??'',40,'Continuar'),
        'density'=>in_array($density,['compact','comfortable','spacious'],true)?$density:'comfortable',
        'alignment'=>in_array($alignment,['left','center'],true)?$alignment:'center',
        'hero_path'=>fs_portal_presentation_media_path($values['hero_path']??$base['hero_path']??null,$partnerId),
        'sponsor_path'=>fs_portal_presentation_media_path($values['sponsor_path']??$base['sponsor_path']??null,$partnerId),
    ];
}

function fs_portal_presentation_json(array $value): string
{
    $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))throw new RuntimeException('Não foi possível serializar a apresentação.');
    return $json;
}

/** @return array<string,mixed>|null */
function fs_portal_presentation_get(PDO $pdo, int $partnerId, string $state, bool $forUpdate = false): ?array
{
    if($partnerId<=0||!in_array($state,['draft','candidate','published'],true)||!fs_portal_skin_schema_ready($pdo))return null;
    $statement=$pdo->prepare('SELECT * FROM partner_portal_presentations WHERE partner_id=? AND state=? ORDER BY revision DESC LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $statement->execute([$partnerId,$state]);$row=$statement->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $row['identity']=json_decode((string)$row['identity_json'],true)?:[];
    $row['content']=json_decode((string)$row['content_json'],true)?:[];
    $row['validation']=json_decode((string)($row['validation_snapshot']??''),true)?:null;
    return $row;
}

/** @return array<string,mixed> */
function fs_portal_migration_state(PDO $pdo, int $partnerId): array
{
    if(!fs_portal_skin_schema_ready($pdo))return ['installed'=>false,'status'=>'schema_pending','preview_mobile_approved'=>0,'preview_desktop_approved'=>0];
    $statement=$pdo->prepare('SELECT * FROM partner_portal_migrations WHERE partner_id=? LIMIT 1');$statement->execute([$partnerId]);
    $row=$statement->fetch(PDO::FETCH_ASSOC);
    if(!$row)return ['installed'=>true,'status'=>'legacy_active','preview_mobile_approved'=>0,'preview_desktop_approved'=>0];
    $row['installed']=true;$row['inventory']=json_decode((string)$row['inventory_json'],true)?:[];$row['checklist']=json_decode((string)($row['checklist_json']??''),true)?:null;
    return $row;
}

function fs_portal_presentation_next_revision(PDO $pdo, int $partnerId): int
{
    $statement=$pdo->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM partner_portal_presentations WHERE partner_id=?');$statement->execute([$partnerId]);
    return max(1,(int)$statement->fetchColumn());
}

function fs_portal_presentation_actor_type(string $actorType): string
{
    if(!in_array($actorType,['firespot','partner_admin','system'],true))throw new InvalidArgumentException('Autor da apresentação inválido.');
    return $actorType;
}

/** @return array<string,mixed> */
function fs_portal_presentation_prepare(PDO $pdo, int $partnerId, ?int $actorId, string $actorType = 'firespot'): array
{
    if(!fs_portal_skin_schema_ready($pdo))throw new RuntimeException('A migração 050 ainda não foi aplicada.');
    $actorType=fs_portal_presentation_actor_type($actorType);
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $statement=$pdo->prepare('SELECT * FROM partners WHERE id=? LIMIT 1 FOR UPDATE');$statement->execute([$partnerId]);$partner=$statement->fetch(PDO::FETCH_ASSOC);
        if(!$partner)throw new InvalidArgumentException('Estabelecimento não encontrado.');
        $existing=fs_portal_presentation_get($pdo,$partnerId,'draft',true);
        if($existing){if($ownsTransaction)$pdo->commit();return $existing;}
        if(fs_portal_presentation_get($pdo,$partnerId,'candidate',true))throw new RuntimeException('Já existe uma apresentação pronta aguardando migração manual.');

        $config=fs_portal_config_get($pdo,$partnerId,'draft',true)?:fs_portal_config_get($pdo,$partnerId,'published',true)?:fs_portal_config_for_partner($pdo,$partner);
        $configId=(int)($config['id']??0)?:null;
        $theme=portal_theme_get($pdo,$partner,[]);
        $skin=fs_portal_skin_from_legacy_theme((string)($theme['theme_preset']??'modern'));
        $identity=fs_portal_presentation_identity($theme,[],$partnerId);
        $content=fs_portal_presentation_content([],[],$partnerId);
        $revision=fs_portal_presentation_next_revision($pdo,$partnerId);
        $insert=$pdo->prepare("INSERT INTO partner_portal_presentations (partner_id,revision,state,source,skin_code,skin_version,portal_configuration_id,identity_json,content_json,created_by_type,created_by_id) VALUES (?,?,'draft','legacy_theme',?,?,?,?,?,?,?)");
        $insert->execute([$partnerId,$revision,$skin,1,$configId,fs_portal_presentation_json($identity),fs_portal_presentation_json($content),$actorType,$actorId]);
        $presentationId=(int)$pdo->lastInsertId();
        $hotspotCount=$pdo->prepare('SELECT COUNT(*) FROM partner_hotspots WHERE partner_id=?');$hotspotCount->execute([$partnerId]);
        $inventory=['portal_mode'=>(string)$partner['portal_mode'],'legacy_theme'=>(string)($theme['theme_preset']??'modern'),'functional_configuration_id'=>$configId,'points'=>(int)$hotspotCount->fetchColumn(),'prepared'=>true];
        $status=(string)$partner['portal_mode']==='v3'?'v3_active':'draft';
        $migration=$pdo->prepare("INSERT INTO partner_portal_migrations (partner_id,status,source_portal_mode,draft_presentation_id,inventory_json,prepared_by_id,prepared_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE status=IF(status IN ('v3_active','rollback_available'),status,'draft'),source_portal_mode=VALUES(source_portal_mode),draft_presentation_id=VALUES(draft_presentation_id),candidate_presentation_id=NULL,inventory_json=VALUES(inventory_json),checklist_json=NULL,checklist_hash=NULL,preview_mobile_approved=0,preview_desktop_approved=0,preview_approved_by_id=NULL,preview_approved_at=NULL,prepared_by_id=VALUES(prepared_by_id),prepared_at=VALUES(prepared_at)");
        $migration->execute([$partnerId,$status,(string)$partner['portal_mode'],$presentationId,fs_portal_presentation_json($inventory),$actorId]);
        partner_admin_audit($pdo,$partnerId,$actorType,$actorId,'portal.presentation_prepared','portal_presentation',$presentationId,['revision'=>$revision,'skin_code'=>$skin,'portal_mode_unchanged'=>(string)$partner['portal_mode']]);
        if($ownsTransaction)$pdo->commit();
        return fs_portal_presentation_get($pdo,$partnerId,'draft')?:[];
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

/** @return array<string,mixed> */
function fs_portal_presentation_save(PDO $pdo, int $partnerId, array $input, ?int $actorId, string $actorType = 'firespot'): array
{
    $actorType=fs_portal_presentation_actor_type($actorType);
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $draft=fs_portal_presentation_get($pdo,$partnerId,'draft',true);if(!$draft)throw new RuntimeException('Prepare primeiro o rascunho visual do Portal V3.');
        $skinCode=(string)($input['skin_code']??$draft['skin_code']);$catalog=fs_portal_skin_catalog($pdo,true);
        if(!isset($catalog[$skinCode]))throw new InvalidArgumentException('Modelo visual inválido ou inativo.');
        $identity=fs_portal_presentation_identity($input,$draft['identity'],$partnerId);
        $content=fs_portal_presentation_content($input,$draft['content'],$partnerId);
        $statement=$pdo->prepare("UPDATE partner_portal_presentations SET source='manual',skin_code=?,skin_version=?,identity_json=?,content_json=?,validation_snapshot=NULL,validation_hash=NULL,validated_at=NULL,updated_at=NOW() WHERE id=? AND partner_id=? AND state='draft'");
        $statement->execute([$skinCode,(int)$catalog[$skinCode]['version'],fs_portal_presentation_json($identity),fs_portal_presentation_json($content),(int)$draft['id'],$partnerId]);
        if($statement->rowCount()!==1)throw new RuntimeException('O rascunho visual mudou durante a gravação.');
        $pdo->prepare("UPDATE partner_portal_migrations SET status=IF(status IN ('v3_active','rollback_available'),status,'draft'),candidate_presentation_id=NULL,checklist_json=NULL,checklist_hash=NULL,preview_mobile_approved=0,preview_desktop_approved=0,preview_approved_by_id=NULL,preview_approved_at=NULL,updated_at=NOW() WHERE partner_id=?")->execute([$partnerId]);
        partner_admin_audit($pdo,$partnerId,$actorType,$actorId,'portal.presentation_saved','portal_presentation',(int)$draft['id'],['skin_code'=>$skinCode,'revision'=>(int)$draft['revision']]);
        if($ownsTransaction)$pdo->commit();return fs_portal_presentation_get($pdo,$partnerId,'draft')?:$draft;
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

/** @return array<string,mixed> */
function fs_portal_presentation_save_uploads(PDO $pdo, int $partnerId, array $input, array $files, ?int $actorId, string $actorType = 'firespot'): array
{
    $draft=fs_portal_presentation_get($pdo,$partnerId,'draft');if(!$draft)throw new RuntimeException('Prepare primeiro o rascunho visual do Portal V3.');
    $paths=[
        'logo_light_path'=>(string)($draft['identity']['logo_light_path']??''),
        'logo_dark_path'=>(string)($draft['identity']['logo_dark_path']??''),
        'hero_path'=>(string)($draft['content']['hero_path']??''),
        'sponsor_path'=>(string)($draft['content']['sponsor_path']??''),
    ];
    $uploads=['logo_light'=>'branding','logo_dark'=>'branding','hero'=>'content','sponsor'=>'content'];$created=[];
    try{
        foreach($uploads as $field=>$area){
            $pathField=$field.'_path';$new=fs_portal_presentation_store_image($partnerId,$files[$field]??[],$area);
            if($new!==''){$paths[$pathField]=$new;$created[]=$new;}
            elseif(isset($input['remove_'.$field]))$paths[$pathField]='';
        }
        return fs_portal_presentation_save($pdo,$partnerId,array_merge($input,$paths),$actorId,$actorType);
    }catch(Throwable $error){foreach($created as $path)fs_portal_presentation_delete_new_image($path);throw$error;}
}

/** @return array<string,mixed> */
function fs_portal_presentation_validate(PDO $pdo, int $partnerId, ?array $presentation = null, bool $persist = true): array
{
    $presentation=$presentation?:fs_portal_presentation_get($pdo,$partnerId,'draft');
    if(!$presentation||!in_array((string)$presentation['state'],['draft','candidate'],true))throw new RuntimeException('Não existe apresentação para validar.');
    $statement=$pdo->prepare('SELECT * FROM partners WHERE id=? LIMIT 1');$statement->execute([$partnerId]);$partner=$statement->fetch(PDO::FETCH_ASSOC);
    if(!$partner)throw new InvalidArgumentException('Estabelecimento não encontrado.');
    $configId=(int)($presentation['portal_configuration_id']??0);
    $config=$configId>0?fs_portal_config_by_id($pdo,$partnerId,$configId):null;
    $config=$config?:fs_portal_config_get($pdo,$partnerId,'draft')?:fs_portal_config_for_partner($pdo,$partner);
    $prospectivePartner=$partner;$prospectivePartner['portal_mode']='v3';
    $functional=fs_portal_config_validate($pdo,$prospectivePartner,$config);
    $items=$functional['items'];$catalog=fs_portal_skin_catalog($pdo,true);$skin=$catalog[(string)$presentation['skin_code']]??null;
    $items[]=fs_portal_config_validation_item('skin','Modelo visual',$skin?'ready':'block',$skin?'Modelo visual versionado e ativo.':'Modelo visual ausente ou inativo.','presentation');
    $identity=$presentation['identity'];$content=$presentation['content'];
    $items[]=fs_portal_config_validation_item('brand_name','Identidade',trim((string)($identity['brand_name']??''))!==''?'ready':'block','Nome de marca definido.','presentation');
    if($skin){
        foreach(['headline','message','legal'] as $field){
            $limit=(int)($skin['manifest']['content'][$field]??0);$value=(string)($content[$field]??'');
            $length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
            $items[]=fs_portal_config_validation_item('content_'.$field,ucfirst($field),$limit>0&&$length<=$limit?'ready':'block',$limit>0&&$length<=$limit?'Conteúdo dentro do limite.':'Reduza o conteúdo para até '.$limit.' caracteres.','presentation');
        }
    }
    if((string)$presentation['skin_code']==='sponsored-focus'&&!fs_portal_config_is_sponsored($config))$items[]=fs_portal_config_validation_item('skin_recommendation','Compatibilidade','warning','Este modelo é recomendado para jornada patrocinada, mas não altera a modalidade.','presentation');
    $blocks=array_values(array_filter($items,static fn(array $item):bool=>(string)$item['level']==='block'));
    $warnings=array_values(array_filter($items,static fn(array $item):bool=>(string)$item['level']==='warning'));
    $validation=['ready'=>!$blocks,'items'=>$items,'blocks'=>$blocks,'warnings'=>$warnings,'functional_configuration_id'=>(int)($config['id']??0),'skin_code'=>(string)$presentation['skin_code'],'skin_version'=>(int)$presentation['skin_version'],'validated_at'=>gmdate(DATE_ATOM),'activation_performed'=>false];
    if($persist){
        $snapshot=fs_portal_presentation_json($validation);$hash=hash('sha256',$snapshot);
        $pdo->prepare('UPDATE partner_portal_presentations SET validation_snapshot=?,validation_hash=?,validated_at=NOW(),updated_at=NOW() WHERE id=? AND partner_id=?')->execute([$snapshot,$hash,(int)$presentation['id'],$partnerId]);
        $pdo->prepare('UPDATE partner_portal_migrations SET checklist_json=?,checklist_hash=?,updated_at=NOW() WHERE partner_id=?')->execute([$snapshot,$hash,$partnerId]);
    }
    return $validation;
}

/** @return array<string,mixed> */
function fs_portal_presentation_approve_preview(PDO $pdo, int $partnerId, string $viewport, ?int $actorId): array
{
    if(!in_array($viewport,['mobile','desktop'],true))throw new InvalidArgumentException('Formato de prévia inválido.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $draft=fs_portal_presentation_get($pdo,$partnerId,'draft',true);if(!$draft)throw new RuntimeException('Nenhum rascunho visual disponível.');
        $validation=fs_portal_presentation_validate($pdo,$partnerId,$draft,true);
        if(empty($validation['ready']))throw new RuntimeException('Corrija os bloqueios do checklist antes de aprovar a prévia.');
        $column=$viewport==='mobile'?'preview_mobile_approved':'preview_desktop_approved';
        $update=$pdo->prepare("UPDATE partner_portal_migrations SET {$column}=1,preview_approved_by_id=?,preview_approved_at=NOW(),updated_at=NOW() WHERE partner_id=? AND draft_presentation_id=?");$update->execute([$actorId,$partnerId,(int)$draft['id']]);
        if($update->rowCount()!==1)throw new RuntimeException('O estado da migração mudou durante a aprovação.');
        partner_admin_audit($pdo,$partnerId,'firespot',$actorId,'portal.preview_approved','portal_presentation',(int)$draft['id'],['viewport'=>$viewport]);
        if($ownsTransaction)$pdo->commit();return fs_portal_migration_state($pdo,$partnerId);
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

/** @return array<string,mixed> */
function fs_portal_presentation_mark_ready(PDO $pdo, int $partnerId, ?int $actorId): array
{
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $draft=fs_portal_presentation_get($pdo,$partnerId,'draft',true);if(!$draft)throw new RuntimeException('Nenhum rascunho visual disponível.');
        $migration=fs_portal_migration_state($pdo,$partnerId);
        if(empty($migration['preview_mobile_approved'])||empty($migration['preview_desktop_approved']))throw new RuntimeException('Aprove as prévias móvel e desktop antes de marcar como pronta.');
        $validation=fs_portal_presentation_validate($pdo,$partnerId,$draft,true);
        if(empty($validation['ready']))throw new RuntimeException('O checklist do Portal V3 possui bloqueios.');
        $pdo->prepare("UPDATE partner_portal_presentations SET state='candidate',updated_at=NOW() WHERE id=? AND partner_id=? AND state='draft'")->execute([(int)$draft['id'],$partnerId]);
        $pdo->prepare("UPDATE partner_portal_migrations SET status='ready',draft_presentation_id=NULL,candidate_presentation_id=?,ready_by_id=?,ready_at=NOW(),updated_at=NOW() WHERE partner_id=?")->execute([(int)$draft['id'],$actorId,$partnerId]);
        partner_admin_audit($pdo,$partnerId,'firespot',$actorId,'portal.migration_ready','portal_presentation',(int)$draft['id'],['activation_performed'=>false,'checklist_hash'=>hash('sha256',fs_portal_presentation_json($validation))]);
        if($ownsTransaction)$pdo->commit();return fs_portal_presentation_get($pdo,$partnerId,'candidate')?:$draft;
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

function fs_portal_presentation_discard(PDO $pdo, int $partnerId, ?int $actorId, string $actorType = 'firespot'): void
{
    $actorType=fs_portal_presentation_actor_type($actorType);
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $presentation=fs_portal_presentation_get($pdo,$partnerId,'draft',true)?:fs_portal_presentation_get($pdo,$partnerId,'candidate',true);if(!$presentation){if($ownsTransaction)$pdo->commit();return;}
        $update=$pdo->prepare("UPDATE partner_portal_presentations SET state='discarded',updated_at=NOW() WHERE id=? AND partner_id=? AND state IN ('draft','candidate')");$update->execute([(int)$presentation['id'],$partnerId]);
        if($update->rowCount()!==1)throw new RuntimeException('A apresentação mudou durante o descarte.');
        $pdo->prepare("UPDATE partner_portal_migrations SET status=IF(source_portal_mode='v3','v3_active','legacy_active'),draft_presentation_id=NULL,candidate_presentation_id=NULL,checklist_json=NULL,checklist_hash=NULL,preview_mobile_approved=0,preview_desktop_approved=0,preview_approved_by_id=NULL,preview_approved_at=NULL,updated_at=NOW() WHERE partner_id=?")->execute([$partnerId]);
        partner_admin_audit($pdo,$partnerId,$actorType,$actorId,'portal.presentation_discarded','portal_presentation',(int)$presentation['id'],['revision'=>(int)$presentation['revision'],'previous_state'=>(string)$presentation['state']]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}

/** @return array<string,mixed> */
function fs_portal_presentation_reopen(PDO $pdo, int $partnerId, ?int $actorId): array
{
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $candidate=fs_portal_presentation_get($pdo,$partnerId,'candidate',true);if(!$candidate)throw new RuntimeException('Não existe candidata para reabrir.');
        $update=$pdo->prepare("UPDATE partner_portal_presentations SET state='draft',validation_snapshot=NULL,validation_hash=NULL,validated_at=NULL,updated_at=NOW() WHERE id=? AND partner_id=? AND state='candidate'");$update->execute([(int)$candidate['id'],$partnerId]);
        if($update->rowCount()!==1)throw new RuntimeException('A candidata mudou durante a reabertura.');
        $pdo->prepare("UPDATE partner_portal_migrations SET status='draft',draft_presentation_id=?,candidate_presentation_id=NULL,checklist_json=NULL,checklist_hash=NULL,preview_mobile_approved=0,preview_desktop_approved=0,preview_approved_by_id=NULL,preview_approved_at=NULL,ready_by_id=NULL,ready_at=NULL,updated_at=NOW() WHERE partner_id=?")->execute([(int)$candidate['id'],$partnerId]);
        partner_admin_audit($pdo,$partnerId,'firespot',$actorId,'portal.presentation_reopened','portal_presentation',(int)$candidate['id'],['activation_performed'=>false]);
        if($ownsTransaction)$pdo->commit();return fs_portal_presentation_get($pdo,$partnerId,'draft')?:$candidate;
    }catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$error;}
}
