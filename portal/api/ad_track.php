<?php
declare(strict_types=1);
@ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/session_boot.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/partner_ads.php';
require_once __DIR__ . '/../../app/ad_monetization.php';

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);exit;}
if(!csrf_check($_POST['csrf']??'')){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'invalid_csrf']);exit;}
$action=(string)($_POST['action']??'');$adId=(int)($_POST['ad_id']??0);$event=null;
if($action==='impression')$event='impression';
if($action==='interest'&&($_POST['interest']??'')==='yes')$event='interest_yes';
if($action==='interest'&&($_POST['interest']??'')==='no')$event='interest_no';
if($action==='view_complete')$event='view_complete';
if($action==='skipped')$event='skipped';
if(!$event||$adId<=0){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'bad_request']);exit;}
try{
  $pdo=db();$code=trim((string)($_SESSION['portal_fast_id']??''));
  $hotspotContext=fs_partner_hotspot_resolve($pdo,$code,false,true);$partnerId=(int)($hotspotContext['id']??0);$hotspotId=$hotspotContext?fs_partner_hotspot_id($hotspotContext):null;
  if($partnerId<=0){$st=$pdo->prepare('SELECT id FROM partners WHERE active=1 AND (code=? OR id=?) LIMIT 1');$st->execute([$code,ctype_digit($code)?(int)$code:0]);$partnerId=(int)($st->fetchColumn()?:0);}
  if($partnerId<=0)throw new RuntimeException('partner_unresolved');
  $deliveryToken=trim((string)($_POST['delivery_token']??''));
  $deliveryId=null;if($deliveryToken!==''){$delivery=fs_ad_delivery_by_token($pdo,$deliveryToken,false);if($delivery){fs_ad_delivery_assert_session($delivery);if((int)$delivery['partner_id']!==$partnerId||(int)$delivery['ad_id']!==$adId)throw new RuntimeException('delivery_scope');$deliveryId=(int)$delivery['id'];$hotspotId=isset($delivery['hotspot_id'])?(int)$delivery['hotspot_id']:$hotspotId;}}
  partner_ads_track($pdo,$partnerId,$adId,$event,$_SESSION['cliente_username']??null,$_SESSION['hotspot_device_info']['mac']??null,$hotspotId);
  if($event==='view_complete'&&$deliveryToken!=='' )fs_ad_delivery_complete($pdo,$deliveryToken);
  $st=$pdo->prepare('SELECT lead_capture_enabled FROM custom_ads WHERE id=? LIMIT 1');$st->execute([$adId]);$leadCapture=(int)($st->fetchColumn()?:0)===1;
  $offerToken=$event==='interest_yes'&&!$leadCapture?partner_ads_queue_offer($pdo,$partnerId,$adId,$deliveryId):null;
  echo json_encode(['ok'=>true,'offer_queued'=>$offerToken!==null,'lead_capture'=>$leadCapture]);
}catch(Throwable $e){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'ad_not_eligible']);}
