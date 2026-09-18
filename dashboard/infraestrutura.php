<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/infrastructure_status.php';
require_once __DIR__ . '/components/status-pill.php';

$section=(string)($_GET['section']??'overview');
if(!in_array($section,['overview','freeradius','jobs','logs'],true))$section='overview';
$pdo=db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$summary=fs_infrastructure_summary($pdo);
$jobs=fs_infrastructure_jobs($pdo);
$safeEvents=$section==='logs'?fs_infrastructure_safe_events($pdo):[];
$readyJobs=count(array_filter($jobs,static fn(array $job):bool=>$job['state']==='ready'));
$dateLabel=static function(mixed $value):string{$timestamp=strtotime((string)$value);return$timestamp?date('d/m/Y H:i:s',$timestamp):'Não registrado';};
$ageLabel=static function(?int $seconds):string{if($seconds===null)return'ausente';if($seconds<60)return$seconds.' s';if($seconds<3600)return(int)floor($seconds/60).' min';return(int)floor($seconds/3600).' h';};
$serviceLabel=['active'=>'Ativo','inactive'=>'Inativo','failed'=>'Com falha','activating'=>'Iniciando','deactivating'=>'Encerrando','unknown'=>'Não confirmado'];
$serviceTone=$summary['freeradius_service']==='active'?'success':($summary['freeradius_service']==='unknown'?'neutral':'danger');
$titulo='Infraestrutura';
$pageId='infraestrutura';
ob_start();
?>
<section class="fs-cc-toolbar"><div><span class="fs-cc-eyebrow">Sistema</span><h2>Infraestrutura FireSpot</h2><p>NAS, RADIUS e processamento operacional. Pontos não formam um inventário global: cada ponto continua exclusivamente dentro do seu estabelecimento.</p></div><a class="btn primary" href="nas.php">Administrar NAS</a></section>
<section class="fs-cc-kpis" aria-label="Indicadores de infraestrutura"><article class="card"><span>NAS cadastrados</span><strong><?=(int)$summary['nas_total']?></strong><small><?=(int)$summary['nas_healthy']?> com saúde OK</small></article><article class="card"><span>Destinos RADIUS</span><strong><?=(int)$summary['radius_destinations']?></strong><small>segredos nunca exibidos</small></article><article class="card"><span>Sessões RADIUS</span><strong><?=(int)$summary['radius_active_sessions']?></strong><small>ativas no accounting</small></article><article class="card"><span>Jobs recentes</span><strong><?=$readyJobs?>/<?=count($jobs)?></strong><small>por heartbeat</small></article></section>

<?php if($section==='overview'):?>
<section class="fs-cc-workspace-grid"><a class="card" href="nas.php"><span>Equipamentos</span><strong>NAS</strong><small>Cadastro central FireSpot, interfaces, propriedade e saúde.</small></a><a class="card" href="nas.php?section=radius#servidores-radius"><span>Autenticação</span><strong>Destinos RADIUS</strong><small>Servidores cadastrados e associados aos equipamentos.</small></a><a class="card" href="?section=freeradius"><span>Serviço</span><strong>FreeRADIUS</strong><small>Estado local e atividade do accounting, sem controles web do daemon.</small></a><a class="card" href="?section=jobs"><span>Processamento</span><strong>Jobs e filas</strong><small>Heartbeats, duração e falhas operacionais.</small></a><a class="card" href="?section=logs"><span>Diagnóstico</span><strong>Eventos sanitizados</strong><small>Códigos técnicos sem credenciais, payloads ou dados pessoais.</small></a></section>
<section class="card"><header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Filas</span><h2>Resumo operacional</h2><p>Nenhuma ação remota é executada ao abrir esta página.</p></div></header><dl class="fs-cc-fact-list"><div><dt>Solicitações de infraestrutura</dt><dd><?=(int)$summary['infrastructure_pending']?> pendente(s) · <?=(int)$summary['infrastructure_failed']?> falha(s)</dd></div><div><dt>Mensageria</dt><dd><?=(int)$summary['messages_pending']?> pendente(s) · <?=(int)$summary['messages_failed']?> falha(s)</dd></div><div><dt>Bases NAS preparadas</dt><dd><?=(int)$summary['nas_prepared']?> de <?=(int)$summary['nas_total']?></dd></div></dl></section>

<?php elseif($section==='freeradius'):?>
<section class="card"><header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Autenticação central</span><h2>Serviço FreeRADIUS</h2><p>Diagnóstico local somente de leitura. Inicialização, parada, recarga e alteração de segredos permanecem fora do processo web.</p></div><?=fs_cc_status_pill($serviceLabel[$summary['freeradius_service']]??'Não confirmado',$serviceTone)?></header><dl class="fs-cc-fact-list"><div><dt>Estado informado pelo systemd</dt><dd><?=htmlspecialchars($serviceLabel[$summary['freeradius_service']]??'Não confirmado')?></dd></div><div><dt>Sessões em accounting</dt><dd><?=(int)$summary['radius_active_sessions']?></dd></div><div><dt>Última atualização accounting</dt><dd><?=htmlspecialchars($dateLabel($summary['radius_last_accounting']))?></dd></div><div><dt>Destinos cadastrados</dt><dd><?=(int)$summary['radius_destinations']?></dd></div></dl><div class="notice">Para mudanças no daemon, use o procedimento operacional privilegiado no servidor. Esta tela não executa <code>sudo</code>, não mostra shared secrets e não altera equipamentos.</div><div class="fs-cc-form-actions"><a class="btn" href="nas.php?section=radius#servidores-radius">Administrar destinos RADIUS</a><a class="btn" href="?section=logs">Ver eventos sanitizados</a></div></section>

<?php elseif($section==='jobs'):?>
<section class="card"><header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Processamento</span><h2>Heartbeats dos jobs</h2><p>A situação abaixo vem do último resultado persistido. Timers e unidades systemd continuam validados pelo auditor operacional privilegiado.</p></div><?=fs_cc_status_pill($readyJobs===count($jobs)?'Todos recentes':$readyJobs.'/'.count($jobs).' recentes',$readyJobs===count($jobs)?'success':'warning')?></header><table class="simple-table"><thead><tr><th>Job</th><th>Heartbeat</th><th>Idade</th><th>Duração</th><th>Resultado</th></tr></thead><tbody><?php foreach($jobs as $job):$tone=$job['state']==='ready'?'success':($job['state']==='stale'?'warning':'danger');?><tr><td><strong><?=htmlspecialchars((string)$job['label'])?></strong></td><td><?=htmlspecialchars($dateLabel($job['heartbeat_at']))?></td><td><?=htmlspecialchars($ageLabel($job['age_seconds']))?></td><td><?=$job['duration_ms']===null?'—':number_format((int)$job['duration_ms'],0,',','.').' ms'?></td><td><?=fs_cc_status_pill($job['state']==='ready'?'OK':($job['state']==='stale'?'Atrasado':'Falhou'),$tone)?><br><small><?=htmlspecialchars((string)$job['runner_state'])?><?=$job['exit_code']!==null?' · código '.(int)$job['exit_code']:''?></small></td></tr><?php endforeach;?></tbody></table></section>

<?php else:?>
<section class="card"><header class="fs-cc-section-heading"><div><span class="fs-cc-eyebrow">Diagnóstico</span><h2>Eventos operacionais sanitizados</h2><p>Somente domínio, ação, código seguro, estabelecimento e horário. Payloads, credenciais, documentos, contatos, IPs e identificadores de aparelhos não são consultados.</p></div></header><table class="simple-table"><thead><tr><th>Horário</th><th>Domínio</th><th>Contexto</th><th>Evento</th><th>Código / estado</th></tr></thead><tbody><?php if(!$safeEvents):?><tr><td colspan="5">Nenhum evento recente disponível.</td></tr><?php endif;?><?php foreach($safeEvents as $event):?><tr><td><?=htmlspecialchars($dateLabel($event['at']))?></td><td><?=htmlspecialchars((string)$event['domain'])?></td><td><?=htmlspecialchars((string)$event['context'])?></td><td><code><?=htmlspecialchars((string)$event['event'])?></code></td><td><?=htmlspecialchars((string)$event['code'])?> · <?=htmlspecialchars((string)$event['state'])?><?=$event['attempts']!==null?' · tentativa '.(int)$event['attempts']:''?></td></tr><?php endforeach;?></tbody></table></section>
<?php endif;?>
<?php
$conteudo=(string)ob_get_clean();
require __DIR__.'/layout.php';
