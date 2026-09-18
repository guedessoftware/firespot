<?php

declare(strict_types=1);

require_once __DIR__.'/../../app/admin_auth.php';
admin_require_page();
require_once __DIR__.'/../../app/db.php';
require_once __DIR__.'/../../app/settings.php';
require_once __DIR__.'/../../app/company.php';
require_once __DIR__.'/../../app/public_url.php';

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
    http_response_code(405);
    header('Allow: POST');
    echo 'Método não permitido.';
    exit;
}

$section=(string)($_POST['return_section']??'general');
if(!in_array($section,['general','identity'],true))$section='general';
$flash=['ok'=>false,'message'=>'Não foi possível atualizar a configuração do sistema.'];
try{
    if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sessão expirada. Recarregue a página.');
    admin_require_capability('system.settings.manage');
    $action=(string)($_POST['action']??'');
    if($action==='public_url_save'){
        $pdo=db();
        $publicBaseUrl=fs_normalize_public_base_url((string)($_POST['public_base_url']??''));
        settings_set('public_base_url',$publicBaseUrl);
        $flash=['ok'=>true,'message'=>'URL pública do FireSpot atualizada.'];
        $section='general';
    }elseif($action==='company_save'){
        $name=trim((string)($_POST['company_name']??''));
        if($name==='')throw new InvalidArgumentException('Informe o nome da organização.');
        $logoLetter=strtoupper(substr(trim((string)($_POST['company_logo_letter']??'')),0,2));
        if($logoLetter==='')$logoLetter=strtoupper(substr($name,0,1))?:'F';
        company_save([
            'name'=>$name,
            'subtitle'=>trim((string)($_POST['company_subtitle']??'')),
            'logo_letter'=>$logoLetter,
            'support_phone'=>trim((string)($_POST['company_support_phone']??''))?:null,
            'support_whatsapp'=>trim((string)($_POST['company_support_whatsapp']??''))?:null,
            'support_email'=>trim((string)($_POST['company_support_email']??''))?:null,
            'support_site'=>trim((string)($_POST['company_support_site']??''))?:null,
        ]);
        $flash=['ok'=>true,'message'=>'Identidade global da organização atualizada.'];
        $section='identity';
    }else throw new InvalidArgumentException('Ação de sistema inválida.');
}catch(Throwable $error){
    $flash=['ok'=>false,'message'=>admin_public_error($error,'Não foi possível atualizar a configuração do sistema.')];
}

$_SESSION['system_settings_flash']=$flash;
header('Location: ../configuracoes.php?'.http_build_query(['section'=>$section]),true,303);
exit;
