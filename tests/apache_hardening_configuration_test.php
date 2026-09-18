<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$checks=0;
function apache_hardening_expect(bool $condition,string $message):void
{
    global $checks;$checks++;if(!$condition)throw new RuntimeException($message);
}

$root=dirname(__DIR__);$ops='/opt/firespot-ops';$http=(string)file_get_contents($ops.'/apache/firespot.conf');$https=(string)file_get_contents($ops.'/apache/firespot-ssl.conf');$defaultHttp=(string)file_get_contents($ops.'/apache/000-default.conf');$defaultHttps=(string)file_get_contents($ops.'/apache/000-default-ssl.conf');$installer=(string)file_get_contents($ops.'/firespot_security_finalize_root.sh');
apache_hardening_expect(str_contains($http,'ServerName firecdn.com.br'),'O vhost HTTP precisa identificar firecdn.com.br.');
apache_hardening_expect(str_contains($http,'https://firecdn.com.br%{REQUEST_URI}')&&preg_match('/R=30[18]/',$http)===1,'O vhost HTTP precisa redirecionar para HTTPS preservando a rota.');
apache_hardening_expect(str_contains($http,'.well-known/acme-challenge'),'O desafio ACME precisa permanecer acessível em HTTP.');
apache_hardening_expect(str_contains($https,'Options -Indexes +FollowSymLinks'),'O DocumentRoot precisa desativar listagem.');
apache_hardening_expect(!preg_match('/Options\s+Indexes(?:\s|$)/m',$https),'O vhost HTTPS não pode reativar Indexes.');
apache_hardening_expect(str_contains($https,'AllowOverride None'),'O vhost HTTPS precisa carregar a política sem depender de .htaccess.');
foreach(['app','archive','backups','docs','installers','migrations','ops','tests','phpmyadmin'] as $privatePath)apache_hardening_expect(str_contains($https,$privatePath),'A rota privada '.$privatePath.' precisa estar no bloqueio do vhost.');
apache_hardening_expect(!preg_match('/Alias\s+\/phpmyadmin/i',$https.$http),'phpMyAdmin não pode ter alias público.');
foreach(['assets/ads','portal-v3/uploads'] as $uploadPath)apache_hardening_expect(str_contains($https,$uploadPath),'A área de upload '.$uploadPath.' precisa de política própria.');
apache_hardening_expect(substr_count($https,'RemoveHandler')>=2,'Uploads precisam remover handlers executáveis.');
apache_hardening_expect(str_contains($defaultHttp,'DocumentRoot /var/www/letsencrypt')&&!str_contains($defaultHttp,'DocumentRoot /var/www/html/hotspot'),'O vhost HTTP padrão não pode servir o FireSpot.');
apache_hardening_expect(str_contains($defaultHttps,'Require all denied')&&!str_contains($defaultHttps,'DocumentRoot /var/www/html/hotspot'),'O vhost HTTPS padrão precisa negar conteúdo do FireSpot.');
apache_hardening_expect(!str_contains($http.$https.$defaultHttp.$defaultHttps,'hotspot.internal'),'O vhost não pode depender de *.hotspot.internal.');
apache_hardening_expect(strpos($installer,'apache2ctl configtest')<strpos($installer,'systemctl start apache2'),'O instalador precisa validar o Apache antes de iniciá-lo.');
apache_hardening_expect(str_contains($installer,'chmod 0755 /var/www/html/hotspot'),'O instalador precisa retirar o modo 777 da raiz auditada.');

echo "OK: {$checks} verificações do vhost endurecido.\n";
