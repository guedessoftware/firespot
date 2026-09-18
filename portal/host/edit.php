<?php
require_once __DIR__ . '/_boot.php';
$id=(int)($_GET['id']??0);
$query=['page'=>'ads'];
if($id>0)$query['edit']=$id;
header('Location: ' . host_admin_url('panel',$query));
exit;
