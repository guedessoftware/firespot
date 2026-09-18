<?php
require_once __DIR__ . '/../app/session_boot.php';

$redirectTarget = 'index.php';
if (!empty($_SESSION['portal_v2_active'])) {
	$redirectTarget = '../portal-v2/index.php';
}

$_SESSION = [];
session_destroy();

header('Location: ' . $redirectTarget);
exit;
