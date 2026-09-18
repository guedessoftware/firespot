<?php

declare(strict_types=1);

require_once __DIR__.'/../app/admin_auth.php';
admin_require_page();
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/company.php';
require_once __DIR__.'/../app/public_url.php';
require_once __DIR__.'/../app/control_center_system.php';
require_once __DIR__.'/components/status-pill.php';

$titulo='Configurações';
$pageId='configuracoes';
$section=(string)($_GET['section']??'overview');
if(!in_array($section,['overview','general','identity','governance','security'],true))$section='overview';
$flash=$_SESSION['system_settings_flash']??null;
unset($_SESSION['system_settings_flash']);
$pdo=db();
$publicBaseUrl=fs_public_base_url($pdo);
$company=company_get();
$canManage=admin_has_capability('system.settings.manage');
$csrf=csrf_token();
$governance=$section==='governance'?fs_control_center_system_governance($pdo):null;
$securityChecks=[
    ['label'=>'Sodium','ready'=>function_exists('sodium_crypto_secretbox'),'detail'=>'Proteção de credenciais e dados pessoais'],
    ['label'=>'OpenSSL','ready'=>extension_loaded('openssl'),'detail'=>'Conexões TLS e validações criptográficas'],
    ['label'=>'cURL','ready'=>function_exists('curl_init'),'detail'=>'Comunicação com provedores externos'],
    ['label'=>'PDO MySQL','ready'=>extension_loaded('pdo_mysql'),'detail'=>'Persistência da aplicação'],
];
ob_start();
?>
<?php if($flash):?><div class="notice <?=!empty($flash['ok'])?'success':'error'?>"><?=htmlspecialchars((string)$flash['message'])?></div><?php endif;?>
<section class="fs-cc-toolbar"><div><span class="fs-cc-eyebrow">Sistema</span><h2>Configurações gerais do FireSpot</h2><p>Somente identidade, origem pública e regras próprias da plataforma. Integrações, infraestrutura, finanças e campanhas têm áreas independentes.</p></div></section>

<?php if($section==='overview'):?>
<section class="fs-cc-workspace-grid"><a class="card" href="?section=general"><span>Plataforma</span><strong>Origem pública</strong><small>URL canônica usada em convites, webhooks, QR Codes e arquivos de portal.</small></a><a class="card" href="?section=identity"><span>Organização</span><strong>Identidade global</strong><small>Nome e contatos institucionais da operadora FireSpot.</small></a><a class="card" href="?section=governance"><span>Governança</span><strong>Versão, gates e retenção</strong><small>Estado somente leitura das travas globais e rotinas de privacidade.</small></a><a class="card" href="?section=security"><span>Segurança</span><strong>Prontidão do runtime</strong><small>Dependências criptográficas e princípios operacionais.</small></a><a class="card" href="integracoes.php"><span>Provedores</span><strong>Integrações</strong><small>HubSoft, Mercado Pago, mensageria e AdSense.</small></a><a class="card" href="infraestrutura.php"><span>Serviços</span><strong>Infraestrutura</strong><small>NAS, RADIUS, jobs e logs sanitizados.</small></a><a class="card" href="financeiro.php"><span>Negócio</span><strong>Configurações financeiras</strong><small>Política Pix, carteiras, recebimentos e repasses.</small></a></section>

<?php elseif($section==='general'):?>
<section class="card"><header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Origem canônica</span><h2>Endereço público do FireSpot</h2><p>Esta origem é usada em novos links, convites, webhooks, QR Codes e arquivos enviados aos roteadores.</p></div></header><?php if($canManage):?><form method="post" action="actions/system.php" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="action" value="public_url_save"><input type="hidden" name="return_section" value="general"><label><span>URL base HTTPS</span><input type="url" name="public_base_url" maxlength="300" value="<?=htmlspecialchars($publicBaseUrl)?>" required><small>Somente esquema, domínio e eventual caminho base; sem consulta ou fragmento.</small></label><label><span>Portal administrativo do estabelecimento</span><input value="<?=htmlspecialchars(rtrim($publicBaseUrl,'/').'/admin/')?>" readonly></label><div class="fs-cc-form-actions"><button class="btn primary" type="submit">Salvar URL pública</button></div></form><?php else:?><dl class="fs-cc-fact-list"><div><dt>URL pública</dt><dd><?=htmlspecialchars($publicBaseUrl)?></dd></div></dl><?php endif;?></section>

<?php elseif($section==='identity'):?>
<section class="card"><header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Organização</span><h2>Identidade global da FireSpot</h2><p>Identidade da operadora. Marcas de estabelecimentos pertencem ao Portal do próprio estabelecimento.</p></div></header><?php if($canManage):?><form method="post" action="actions/system.php" class="fs-cc-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="action" value="company_save"><input type="hidden" name="return_section" value="identity"><div class="fs-cc-form-grid"><label><span>Nome da organização</span><input name="company_name" maxlength="120" value="<?=htmlspecialchars((string)($company['name']??'FireSpot'))?>" required></label><label><span>Slogan</span><input name="company_subtitle" maxlength="160" value="<?=htmlspecialchars((string)($company['subtitle']??''))?>"></label><label><span>Iniciais</span><input name="company_logo_letter" maxlength="2" value="<?=htmlspecialchars((string)($company['logo_letter']??'F'))?>"></label><label><span>Telefone comercial</span><input name="company_support_phone" maxlength="32" value="<?=htmlspecialchars((string)($company['support_phone']??''))?>"></label><label><span>WhatsApp de suporte</span><input name="company_support_whatsapp" maxlength="32" value="<?=htmlspecialchars((string)($company['support_whatsapp']??''))?>"></label><label><span>E-mail de contato</span><input type="email" name="company_support_email" maxlength="120" value="<?=htmlspecialchars((string)($company['support_email']??''))?>"></label><label class="fs-cc-span-two"><span>Site institucional</span><input type="url" name="company_support_site" maxlength="255" value="<?=htmlspecialchars((string)($company['support_site']??''))?>"></label></div><div class="fs-cc-form-actions"><button class="btn primary" type="submit">Salvar identidade global</button></div></form><?php else:?><dl class="fs-cc-fact-list"><div><dt>Organização</dt><dd><?=htmlspecialchars((string)($company['name']??'FireSpot'))?></dd></div><div><dt>Suporte</dt><dd><?=htmlspecialchars((string)($company['support_email']??'Não informado'))?></dd></div></dl><?php endif;?></section>

<?php elseif($section==='governance'&&is_array($governance)):?>
<section class="fs-cc-kpis" aria-label="Versão e gates globais"><article class="card"><span>Schema instalado</span><strong>v<?=str_pad((string)(int)$governance['schema_version'],3,'0',STR_PAD_LEFT)?></strong><small><?=(int)$governance['schema_registered']?> de <?=(int)$governance['schema_target']?> migrações</small></article><article class="card"><span>Aplicação remota</span><strong><?=!empty($governance['hotspot_apply_pilot_approved'])?'Gate aberto':'Gate fechado'?></strong><small>alteração somente por procedimento privilegiado</small></article><article class="card"><span>Ativação do Portal V3</span><strong>Congelada</strong><small>rascunho e prévia não publicam</small></article></section>
<section class="card"><header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Governança operacional</span><h2>Versão e travas globais</h2><p>Consulta sem ação remota, DDL ou mudança de gate. Operações privilegiadas permanecem nos procedimentos auditados.</p></div></header><dl class="fs-cc-fact-list"><div><dt>Migrações pendentes</dt><dd><?=$governance['schema_pending']?htmlspecialchars(implode(', ',array_map(static fn(int $version):string=>str_pad((string)$version,3,'0',STR_PAD_LEFT),$governance['schema_pending']))):'Nenhuma'?></dd></div><div><dt>Piloto de aplicação de pontos</dt><dd><?=!empty($governance['hotspot_apply_pilot_approved'])?'Aprovado — revisar escopo':'Não aprovado; hotspots.apply permanece fechado'?></dd></div><div><dt>Migração visual do Portal V3</dt><dd>Preparação manual; nenhuma ação de ativação disponível nesta entrega</dd></div></dl></section>
<section class="card fs-cc-separated-card"><header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Dados e privacidade</span><h2>Rotinas de retenção</h2><p>Os detalhes operacionais ficam em Infraestrutura e os recibos anônimos em Clientes → Privacidade.</p></div></header><div class="fs-cc-integration-grid"><?php foreach(['privacy_job'=>'Limpeza de privacidade','subscriber_retention_job'=>'Retenção de assinantes'] as $key=>$label):$job=$governance[$key]??null;?><article class="card"><header><div><span>Job</span><h2><?=$label?></h2></div><?=fs_cc_status_pill(($job['state']??'failed')==='ready'?'Recente':(($job['state']??'failed')==='stale'?'Atrasado':'Revisar'),($job['state']??'failed')==='ready'?'success':(($job['state']??'failed')==='stale'?'warning':'danger'))?></header><p>Último heartbeat: <?=htmlspecialchars((string)($job['heartbeat_at']??'não registrado'))?></p></article><?php endforeach;?></div><div class="fs-cc-form-actions"><a class="btn" href="infraestrutura.php?section=jobs">Abrir jobs</a><a class="btn" href="privacidade.php">Abrir Privacidade</a></div></section>

<?php elseif($section==='security'):?>
<section class="card"><header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Prontidão local</span><h2>Dependências de segurança</h2><p>Este painel não exibe chaves, segredos, senhas nem conteúdo bruto de logs.</p></div></header><div class="fs-cc-integration-grid"><?php foreach($securityChecks as $check):?><article class="card"><header><div><span>Runtime</span><h2><?=htmlspecialchars($check['label'])?></h2></div><?=fs_cc_status_pill($check['ready']?'Disponível':'Ausente',$check['ready']?'success':'danger')?></header><p><?=htmlspecialchars($check['detail'])?></p></article><?php endforeach;?></div><div class="notice-info"><strong>Operações privilegiadas:</strong> serviços do sistema e arquivos protegidos são tratados por rotinas operacionais restritas. A página web não executa <code>sudo</code> nem mostra instruções para ampliar permissões do Apache.</div></section>
<?php endif;?>
<?php
$conteudo=(string)ob_get_clean();
require __DIR__.'/layout.php';
