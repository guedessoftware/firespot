<?php
require_once __DIR__ . '/_boot.php';
partner_admin_session_clear(true);
header('Location: ' . host_admin_url('login'));
exit;
