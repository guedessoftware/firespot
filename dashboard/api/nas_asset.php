<?php
declare(strict_types=1);

// Público: serve assets do hotspot com substituição de fast_id quando necessário
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/public_url.php';

$allowed = [
    'index.html' => 'text/html; charset=utf-8',
    'login.html' => 'text/html; charset=utf-8',
    'logout.html' => 'text/html; charset=utf-8',
    'status.html' => 'text/html; charset=utf-8',
    'redirect.html' => 'text/html; charset=utf-8',
    'error.html' => 'text/html; charset=utf-8',
    'img/load-connect.gif' => 'image/gif',
];

$file = isset($_GET['file']) ? trim((string)$_GET['file']) : '';
if (!isset($allowed[$file])) {
    http_response_code(404);
    exit('Arquivo não permitido');
}

$basePath = __DIR__ . '/../../assets/nas/';
$fullPath = realpath($basePath . $file);
if ($fullPath === false || strpos($fullPath, realpath($basePath)) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    exit('Arquivo não encontrado');
}

$contents = file_get_contents($fullPath);
if ($contents === false) {
    http_response_code(500);
    exit('Falha ao ler o arquivo');
}

if ($file === 'index.html' || $file === 'login.html') {
    $codeRaw = isset($_GET['code']) ? (string)$_GET['code'] : '';
    $code = preg_replace('/[^A-Za-z0-9_-]/', '', $codeRaw);
    if ($code === '') {
        $code = 'HOST';
    }
    $replacement = 'name="fast_id" value="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"';
    $contents = preg_replace('/name="fast_id" value="[^"]*"/', $replacement, $contents);
}
if (substr($file, -5) === '.html') {
    $contents = str_replace('https://firecdn.com.br', fs_public_base_url(db()), $contents);
}

header('Content-Type: ' . $allowed[$file]);
header('Cache-Control: no-store, max-age=0');

echo $contents;
