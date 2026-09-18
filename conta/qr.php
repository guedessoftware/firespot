<?php
require_once __DIR__.'/_boot.php';$pdo=conta_db();$account=fs_subscriber_require($pdo);$invite=(string)($_GET['invite']??'');$reveal=$_SESSION['subscriber_invite_reveal']??null;if(!is_array($reveal)||(string)($reveal['public_id']??'')!==$invite||empty($reveal['url'])){http_response_code(404);exit;}
$url=(string)$reveal['url'];unset($_SESSION['subscriber_invite_reveal']);require_once __DIR__.'/../app/lib/phpqrcode.php';header('Content-Type: image/png');header('Cache-Control: no-store');QRcode::png($url,false,QR_ECLEVEL_M,6,2);
