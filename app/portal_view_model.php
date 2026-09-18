<?php

declare(strict_types=1);

require_once __DIR__ . '/portal_skin.php';

function fs_portal_vm_duration(int $minutes): string
{
    $minutes=max(1,$minutes);
    if($minutes%1440===0){$days=(int)($minutes/1440);return $days.($days===1?' dia':' dias');}
    if($minutes%60===0){$hours=(int)($minutes/60);return $hours.($hours===1?' hora':' horas');}
    return $minutes.' minutos';
}

function fs_portal_vm_money(int $cents): string
{
    return 'R$ '.number_format(max(0,$cents)/100,2,',','.');
}

function fs_portal_vm_speed(int $kbps): string
{
    if($kbps<=0)return 'sem limite específico';
    if($kbps>=1000)return rtrim(rtrim(number_format($kbps/1000,1,',',''),'0'),',').' Mbps';
    return $kbps.' Kbps';
}

/**
 * Garante que skins recebam apenas rotas internas do motor V3.
 */
function fs_portal_vm_route(string $url, string $method = 'GET'): string
{
    $url=trim($url);$method=strtoupper($method);
    if($url===''||str_contains($url,"\0")||str_starts_with($url,'/')||str_contains($url,'://')||str_contains($url,'..'))throw new InvalidArgumentException('Rota do Portal V3 inválida.');
    $path=(string)(parse_url($url,PHP_URL_PATH)??'');
    $allowed=$method==='POST'?['checkout.php']:['index.php','courtesy.php','subscriber.php'];
    if(!in_array($path,$allowed,true))throw new InvalidArgumentException('A skin tentou utilizar uma rota não autorizada.');
    return $url;
}

/** @return array<string,mixed> */
function fs_portal_vm_theme(array $theme, array $identity): array
{
    $merged=array_merge($theme,$identity);
    foreach(['logo_light_path','logo_dark_path'] as $field){
        $path=trim((string)($merged[$field]??''));$urlField=str_replace('_path','_url',$field);
        $file=portal_theme_logo_file($path);
        $merged[$urlField]=$path!==''&&$file!==null&&is_file($file)?'/'.ltrim($path,'/'):null;
    }
    return portal_theme_visual($merged);
}

/**
 * Contrato puro compartilhado por todas as skins. Nenhuma consulta SQL,
 * elegibilidade ou mutação ocorre nesta função.
 *
 * @return array<string,mixed>
 */
function fs_portal_view_model(array $input): array
{
    $stage=(string)($input['stage']??'welcome');
    if(!in_array($stage,['welcome','options','plans'],true))throw new InvalidArgumentException('Etapa visual inválida.');
    $partnerId=max(0,(int)($input['partner_id']??0));
    $presentation=is_array($input['presentation']??null)?$input['presentation']:[];
    $skinCode=(string)($presentation['skin_code']??'balanced');
    $catalog=fs_portal_skin_builtin_catalog();if(!isset($catalog[$skinCode]))$skinCode='balanced';
    $identity=fs_portal_presentation_identity((array)($presentation['identity']??[]),(array)($input['theme']??[]),$partnerId);
    $content=fs_portal_presentation_content((array)($presentation['content']??[]),[],$partnerId);
    foreach(['hero_path','sponsor_path'] as $field){$file=fs_portal_presentation_media_file($content[$field]??null);$content[str_replace('_path','_url',$field)]=$file!==null&&is_file($file)?'/'.ltrim((string)$content[$field],'/'):null;}
    $theme=fs_portal_vm_theme((array)($input['theme']??portal_theme_defaults()),$identity);
    $preview=!empty($input['preview']);$options=[];

    if(!empty($input['has_courtesy'])){
        $sponsored=!empty($input['is_sponsored']);$allowed=!empty($input['courtesy_can_start']);
        $options[]=[
            'type'=>'courtesy','title'=>$sponsored?'Internet patrocinada':'Usar cortesia',
            'meta'=>fs_portal_vm_duration((int)($input['courtesy_minutes']??1)).' grátis'.($sponsored?' após o anúncio':''),
            'icon'=>$sponsored?'▶':'✓','method'=>'GET','url'=>fs_portal_vm_route((string)($input['courtesy_url']??'courtesy.php'),'GET'),
            'label'=>$allowed?($sponsored?'Assistir e conectar':'Usar cortesia'):fs_portal_plain_text($input['courtesy_blocked_label']??'',100,'Acesso gratuito indisponível'),
            'disabled'=>!$allowed,'retry_at'=>max(0,(int)($input['courtesy_retry_at']??0)),
            'ready_label'=>$sponsored?'Assistir e conectar':'Usar cortesia',
        ];
    }
    if(!empty($input['has_sales'])){
        $options[]=['type'=>'paid','title'=>'Comprar acesso','meta'=>'Pix ou cartão','icon'=>'★','method'=>'GET','url'=>fs_portal_vm_route((string)($input['plans_url']??'index.php?step=plans'),'GET'),'label'=>'Ver planos','disabled'=>false];
    }
    if(!empty($input['subscriber_enabled'])){
        $options[]=['type'=>'subscriber','title'=>'Benefício FIRENETWORK','meta'=>'Conta ou convite','icon'=>'F','method'=>'GET','url'=>fs_portal_vm_route((string)($input['subscriber_url']??'subscriber.php'),'GET'),'label'=>!empty($input['subscriber_account'])?'Autorizar aparelho':'Acessar benefício','disabled'=>false];
    }

    $plans=[];
    foreach((array)($input['plans']??[]) as $plan){
        $id=max(0,(int)($plan['id']??0));$source=(string)($plan['source']??'global');
        if($id<=0||!in_array($source,['global','partner'],true))continue;
        $minutes=max(1,(int)($plan['duration_minutes']??1));$price=max(0,(int)($plan['price_cents']??0));
        $plans[]=[
            'id'=>$id,'source'=>$source,'name'=>fs_portal_plain_text($plan['name']??'',100,'Plano de acesso'),
            'price'=>fs_portal_vm_money($price),'price_cents'=>$price,'duration'=>fs_portal_vm_duration($minutes),'duration_minutes'=>$minutes,
            'download'=>fs_portal_vm_speed((int)($plan['download_kbps']??0)),'upload'=>fs_portal_vm_speed((int)($plan['upload_kbps']??0)),
            'method'=>'POST','url'=>fs_portal_vm_route((string)($input['checkout_url']??'checkout.php'),'POST'),
        ];
    }
    $notices=[];
    foreach((array)($input['notices']??[]) as $notice){$text=fs_portal_plain_text($notice,240);if($text!=='')$notices[]=$text;}
    $backUrl=trim((string)($input['back_url']??''));if($backUrl!=='')$backUrl=fs_portal_vm_route($backUrl,'GET');
    $previewAssetBase='../portal-v3/';
    if($preview&&in_array((string)($input['preview_asset_base']??''),['../portal-v3/','../../portal-v3/'],true))$previewAssetBase=(string)$input['preview_asset_base'];
    return [
        'schema_version'=>1,'stage'=>$stage,'preview'=>$preview,'preview_asset_base'=>$preview?$previewAssetBase:'','preview_viewport'=>in_array((string)($input['preview_viewport']??''),['mobile','desktop'],true)?(string)$input['preview_viewport']:'desktop',
        'skin'=>['code'=>$skinCode,'version'=>(int)($presentation['skin_version']??1),'label'=>$catalog[$skinCode]['label']],
        'brand'=>['name'=>$identity['brand_name'],'subtitle'=>$identity['brand_subtitle'],'show_title'=>$identity['show_title']],
        'identity'=>$identity,'content'=>$content,'theme'=>$theme,'notices'=>$notices,'options'=>$options,'plans'=>$plans,
        'sales_error'=>fs_portal_plain_text($input['sales_error']??'',300),
        'csrf'=>$preview?'':(string)($input['csrf']??''),'back_url'=>$backUrl,
        'hotspot_query'=>fs_portal_plain_text($input['hotspot_query']??'',200),
    ];
}

function fs_portal_skin_view_file(string $skinCode): string
{
    if(!isset(fs_portal_skin_builtin_catalog()[$skinCode]))$skinCode='balanced';
    return dirname(__DIR__).'/portal-v3/views/skins/'.$skinCode.'/view.php';
}
