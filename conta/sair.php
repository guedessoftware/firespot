<?php
require_once __DIR__.'/_boot.php';$pdo=conta_db();fs_subscriber_logout($pdo,false);conta_redirect('/conta/');

