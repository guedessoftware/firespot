<?php
require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../../app/partner_ads.php';
$pdo=host_db();
if($_SERVER['REQUEST_METHOD']==='POST'&&csrf_check($_POST['_csrf']??$_POST['csrf']??'')){
  try{$context=partner_admin_require_feature_context($pdo,'ads.manage','ads','ads.manage',true);$id=(int)($_POST['id']??0);$active=partner_ads_toggle($pdo,(int)$context['partner_id'],$id);partner_admin_audit($pdo,(int)$context['partner_id'],'partner_admin',(int)$context['user_id'],$active?'ad.activated':'ad.deactivated','ad',$id);}catch(Throwable $e){host_flash('error',partner_admin_public_error($e));}
}
host_redirect('ads');
