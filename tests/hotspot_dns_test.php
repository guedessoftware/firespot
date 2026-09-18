<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/partner_network.php';

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$expect(partner_network_dns_name('', '00000001-TESTE') === '00000001-teste.hotspot.internal','O nome padrão do Hotspot está incorreto.');
$expect(partner_network_dns_name('00000001-teste.local','fallback') === '00000001-teste.hotspot.internal','Uma instalação .local não foi promovida.');
$expect(partner_network_dns_name('portal.exemplo.com','fallback') === 'portal.exemplo.com','Um domínio DNS válido foi alterado sem necessidade.');
$expect(partner_network_dns_name('Portal_Com_Espaco','fallback') === 'portal-com-espaco','A higienização do nome DNS divergiu.');
$expect(partner_network_routeros_dns_name('00000001-teste.hotspot.internal') === '','A zona automática ainda seria publicada no navegador pelo RouterOS.');
$expect(partner_network_routeros_dns_name('wifi.exemplo.com') === 'wifi.exemplo.com','Um domínio público personalizado foi removido do RouterOS.');
$expect(FS_HOTSPOT_SHORT_RECONNECT_TIMEOUT === '20m','A janela curta de reconexão divergiu do padrão aprovado.');

$applySource = (string)file_get_contents(__DIR__ . '/../dashboard/api/host_apply.php');
$guideSource = (string)file_get_contents(__DIR__ . '/../docs/MIKROTIK_HOTSPOT_CONNECTION_SAFETY.md');
$expect(str_contains($applySource,'/ip dns set servers={$dnsServers} allow-remote-requests=yes'),'A aplicação não configura o resolvedor DNS upstream do MikroTik.');
$expect(str_contains($applySource,"':put [/ip dns get allow-remote-requests]'") && str_contains($applySource,"':put [/ip dns get servers]'"),'A aplicação não valida a configuração DNS global retornada pelo NAS.');
$expect(str_contains($applySource,'dns-server={$gateway}'),'O DHCP não entrega o gateway como DNS aos clientes.');
$expect(!str_contains($applySource,'dns-server={$dnsServers}'),'O DHCP ainda entrega diretamente os DNS públicos.');
$expect(!str_contains($applySource,'/ip dns static add'),'A aplicação tenta criar uma entrada paralela àquela gerenciada pelo perfil Hotspot.');
$expect(str_contains($applySource,'partner_network_routeros_dns_name($dnsName)'),'A aplicação não remove a dependência da zona privada na abertura inicial.');
$expect(str_contains($applySource,'if ($routerDnsName !== \'\')'),'Domínios personalizados deixaram de possuir validação própria.');
$expect(str_contains($applySource,'/ip dns static print count-only where name={$dnsNameArg} and dynamic=yes'),'A aplicação não valida o registro de um domínio personalizado.');
$expect(str_contains($applySource,':put [:resolve domain-name={$dnsNameArg} server={$gateway} type=ipv4]'),'A aplicação não testa a resolução de um domínio personalizado.');
$expect(str_contains($applySource,'add-mac-cookie=yes mac-cookie-timeout=') && str_contains($applySource,'FS_HOTSPOT_SHORT_RECONNECT_TIMEOUT'),'A aplicação não prepara a reconexão curta por MAC cookie.');
$expect(str_contains($applySource,"in_array('mac-cookie',\$loginMethods,true)"),'A aplicação não confirma que o MAC cookie ficou ativo.');
$expect(!str_contains($applySource,'login-by=mac,'),'A aplicação reintroduziu autenticação pura por MAC.');
$expect(str_contains($applySource,'http-cookie-lifetime={$httpCookieLifetime}'),'A aplicação não limita o cookie HTTP à mesma janela curta.');
$expect(str_contains($applySource,"preg_split('/[\\s,;]+/'"),'A validação não normaliza o separador de DNS retornado pelo RouterOS.');
$expect(str_contains($applySource,'O MikroTik não confirmou o acesso inicial pelo gateway e a reconexão curta'),'A aplicação não valida o acesso inicial e a reconexão retornados pelo NAS.');
$expect(str_contains($guideSource,'gateway como DNS') && str_contains($guideSource,'DNS upstream'),'O guia MikroTik diverge da aplicação automática.');
$expect(str_contains($guideSource,'`dns-name=""`'),'O guia ainda publica a zona privada no RouterOS.');
$expect(str_contains($guideSource,'`add-mac-cookie=yes`')&&str_contains($guideSource,'`mac-cookie-timeout=20m`'),'O guia não documenta a reconexão curta do Portal V3.');

echo "OK: {$checks} verificações de DNS do Hotspot.\n";
