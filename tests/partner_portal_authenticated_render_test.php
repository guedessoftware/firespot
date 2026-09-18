<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root=dirname(__DIR__);
require_once $root.'/app/db.php';

$pages=['summary','portal','plans','billing','finance','monetization','ads','team','nas','hotspots','courtesy','analytics','audit'];
$pdo=db();
$context=$pdo->query("SELECT m.id membership_id,u.id user_id,u.auth_version,p.id partner_id
    FROM partner_admin_memberships m
    JOIN host_users u ON u.id=m.user_id
    JOIN partners p ON p.id=m.partner_id
    WHERE m.active=1 AND u.active=1 AND p.active=1 AND p.self_service_enabled=1
      AND EXISTS(SELECT 1 FROM partner_portal_configurations c WHERE c.partner_id=p.id AND c.state='published')
      AND EXISTS(SELECT 1 FROM partner_subscriptions s JOIN platform_plans pl ON pl.id=s.plan_id
        WHERE s.partner_id=p.id AND s.status IN ('trial','active') AND pl.code='multipoint_advanced')
    ORDER BY (m.role='owner') DESC,m.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!$context)throw new RuntimeException('A auditoria autenticada precisa de uma conta Multipontos ativa com responsável.');

if(($argv[1]??'')==='--inventory'){
    echo json_encode(array_map(static fn(string $page):array=>['label'=>'Painel do estabelecimento / '.$page,'page'=>$page],$pages),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

$renderer=<<<'PHP'
$root=$argv[1];$page=$argv[2];$userId=(int)$argv[3];$membershipId=(int)$argv[4];$authVersion=(int)$argv[5];$partnerId=(int)$argv[6];
chdir($root);
ini_set('session.use_cookies','0');ini_set('session.use_strict_mode','0');
session_id('fsportalrender'.substr(hash('sha256',$page."\0".getmypid()),0,24));
$_SERVER=array_merge($_SERVER,[
  'HTTPS'=>'on','REQUEST_METHOD'=>'GET','PHP_SELF'=>'/portal/host/index.php','SCRIPT_NAME'=>'/portal/host/index.php',
  'REQUEST_URI'=>'/portal/host/index.php?page='.rawurlencode($page),'HTTP_HOST'=>'localhost','SERVER_NAME'=>'localhost',
  'SERVER_PORT'=>'443','REMOTE_ADDR'=>'127.0.0.1',
]);
$_GET=['page'=>$page];$_POST=[];
require $root.'/app/db.php';require $root.'/app/session_boot.php';require $root.'/app/cli/migration_framework.php';
$_SESSION=['host_user_id'=>$userId,'host_membership_id'=>$membershipId,'host_partner_id'=>$partnerId,'host_auth_version'=>$authVersion];
$pdo=db();$pdo->beginTransaction();
try{
  // Permite revisar a interface final antes da implantação privilegiada da
  // migração DML; depois da implantação, as mesmas instruções são no-op.
  $migration=(string)file_get_contents($root.'/migrations/052_multipoint_subscription_v2.sql');
  foreach(fs_migration_sql_statements($migration) as $statement)$pdo->exec($statement);
  // Exercita também páginas condicionais. A configuração sintética existe
  // somente nesta transação e nunca publica uma revisão nem muda o portal.
  $pdo->prepare("UPDATE partner_portal_configurations SET courtesy_mode='direct',paid_access_enabled=1,promotional_ads_enabled=1 WHERE partner_id=? AND state='published'")->execute([$partnerId]);
  ob_start();include $root.'/portal/host/index.php';$html=(string)ob_get_clean();
  if($pdo->inTransaction())$pdo->rollBack();
  if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
  echo $html;
}catch(Throwable $error){
  while(ob_get_level()>0)ob_end_clean();
  if($pdo->inTransaction())$pdo->rollBack();
  if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
  fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(70);
}
PHP;

$render=static function(string $page)use($root,$renderer,$context):array{
    $command=[PHP_BINARY,'-d','display_errors=stderr','-d','html_errors=0','-d','log_errors=0','-d','error_reporting=-1','-r',$renderer,$root,$page,(string)$context['user_id'],(string)$context['membership_id'],(string)$context['auth_version'],(string)$context['partner_id']];
    $descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $process=proc_open($command,$descriptors,$pipes,$root,null,['bypass_shell'=>true]);
    if(!is_resource($process))throw new RuntimeException('Não foi possível iniciar o renderizador isolado do estabelecimento.');
    fclose($pipes[0]);$stdout=(string)stream_get_contents($pipes[1]);$stderr=(string)stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($process);
    return ['exit'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr];
};

if(($argv[1]??'')==='--render'){
    $page=(string)($argv[2]??'');
    if(!in_array($page,$pages,true)){fwrite(STDERR,"Página fora do inventário canônico.\n");exit(64);}
    $result=$render($page);
    if($result['exit']!==0||trim($result['stderr'])!==''){fwrite(STDERR,$result['stderr']);exit($result['exit']!==0?$result['exit']:70);}
    echo $result['stdout'];exit(0);
}

$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$previousLibxml=libxml_use_internal_errors(true);
foreach($pages as $page){
    $result=$render($page);$diagnostic=trim($result['stderr']);
    $expect($result['exit']===0,$page.' falhou ao renderizar'.($diagnostic!==''?': '.$diagnostic:'.'));
    $expect($diagnostic==='',$page.' emitiu erro ou aviso PHP: '.$diagnostic);
    $html=$result['stdout'];$expect(trim($html)!=='',$page.' retornou HTML vazio.');
    $dom=new DOMDocument();libxml_clear_errors();
    $expect($dom->loadHTML($html,LIBXML_NONET|LIBXML_NOWARNING|LIBXML_NOERROR)===true,$page.' não produziu HTML analisável.');
    $xpath=new DOMXPath($dom);
    $expect($xpath->query('//body[@data-page="'.$page.'"]')->length===1,$page.' não preservou o contexto canônico.');
    $expect($xpath->query('//main')->length>=1,$page.' não possui conteúdo principal.');
    $expect($xpath->query('//main//h1')->length>=1,$page.' não possui título principal.');
    if($page==='summary'){
        foreach(['Online agora','Pessoas hoje','Conexões hoje','Movimento por ponto','Quem está online','Ver limites e estado do portal'] as $label)$expect(str_contains($html,$label),'Resumo operacional não renderizou: '.$label.'.');
        $expect(!str_contains($html,'<h2>Uso do plano</h2>')&&!str_contains($html,'<h2>Estado do portal</h2>'),'Resumo ainda mantém os antigos blocos extensos de configuração.');
    }elseif($page==='nas'){
        $expect($xpath->query('//main//*[@id="nas-associados"]')->length===1,'Página NAS não contém o catálogo de equipamentos.');
        $expect($xpath->query('//main//*[@id="pontos-do-estabelecimento"]')->length===0,'Página NAS ainda mistura a listagem dos pontos Hotspot.');
    }elseif($page==='hotspots'){
        $expect($xpath->query('//main//*[@id="pontos-do-estabelecimento"]')->length===1,'Página Pontos Hotspot não contém as instalações.');
        $expect($xpath->query('//main//*[@id="nas-associados"]')->length===0,'Página Pontos Hotspot ainda mistura o catálogo de NAS.');
    }elseif($page==='analytics'){
        foreach(['Relatórios integrados','Detalhes do período','Situação das vendas','Planos mais escolhidos','Últimos acessos administrativos'] as $label)$expect(str_contains($html,$label),'Métricas não incorporou o relatório: '.$label.'.');
        $expect(!str_contains($html,'href="?page=reports"'),'Métricas ainda exibe a antiga página de Relatórios no menu.');
    }elseif($page==='finance'){
        foreach(['Por página','Aplicar filtros','Detalhamento','Projeção de recebíveis'] as $label)$expect(str_contains($html,$label),'Financeiro não renderizou o refinamento: '.$label.'.');
        $expect($xpath->query('//table[contains(@class,"host-finance__table")]/thead/tr/th')->length===6,'Tabela financeira não preservou a composição compacta de seis colunas.');
    }elseif($page==='billing'){
        foreach(['Carteira do estabelecimento','Plano Máximo · Independência','Pertence ao estabelecimento','Todos os pontos usam o mesmo recebedor','Testar conexão da carteira','Gateway de recebimento'] as $label)$expect(str_contains($html,$label),'Gestão de carteiras não esclarece escopo, elegibilidade ou diagnóstico: '.$label.'.');
        $expect($xpath->query('//main//form[input[@name="action" and @value="wallet_connection_test"]]//input[not(@type="hidden")]')->length===0,'Teste de conexão ainda solicita preenchimento ao cliente.');
        $expect($xpath->query('//main//input[@name="access_token" and @value]')->length===0,'Carteira reapresentou o Access Token no HTML.');
        $expect($xpath->query('//main//input[@name="webhook_secret" and @value]')->length===0,'Carteira reapresentou a assinatura secreta no HTML.');
    }
    $expect($xpath->query('//img[not(@alt)]')->length===0,$page.' contém imagem sem alt.');
    $ids=[];foreach($xpath->query('//*[@id]') as $node){$id=trim($node->getAttribute('id'));if($id==='')continue;$expect(!isset($ids[$id]),$page.' contém id duplicado: '.$id.'.');$ids[$id]=true;}
    foreach($xpath->query('//a[@href]|//button') as $control){$name=trim($control->textContent);if($name==='')$name=trim($control->getAttribute('aria-label'));if($name==='')$name=trim($control->getAttribute('title'));$expect($name!=='',$page.' contém link ou botão sem nome acessível.');}
}
libxml_clear_errors();libxml_use_internal_errors($previousLibxml);
echo 'OK: '.count($pages).' páginas do estabelecimento e '.$checks." verificações autenticadas de renderização e acessibilidade.\n";
