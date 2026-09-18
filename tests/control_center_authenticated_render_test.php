<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/db.php';

$pdo = db();
$partnerId = (int)($pdo->query('SELECT id FROM partners ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
if ($partnerId <= 0) {
    throw new RuntimeException('A auditoria autenticada precisa de ao menos um estabelecimento de referência.');
}

$routes = [
    ['Visão geral','index.php',''],
    ['Clientes','usuarios.php',''],
    ['Dispositivos','dispositivos.php',''],
    ['Privacidade','privacidade.php',''],
];
foreach (['overview','rollout','mappings','accounts','support','observability'] as $section) {
    $routes[] = ['Assinantes / ' . $section,'assinantes.php',http_build_query(['section'=>$section])];
}
$routes[] = ['Estabelecimentos','estabelecimentos.php',''];
foreach (['summary','registration','contract','portal','access-plans','courtesy','points','finance','team','audit'] as $section) {
    $routes[] = ['Estabelecimento / ' . $section,'estabelecimento.php',http_build_query(['id'=>$partnerId,'section'=>$section])];
}
foreach (['firespot','subscriptions','access','partner-access'] as $section) {
    $routes[] = ['Planos / ' . $section,'planos.php',http_build_query(['section'=>$section])];
}
foreach (['overview','delivery','settings'] as $section) {
    $routes[] = ['Financeiro / ' . $section,'financeiro.php',http_build_query(['section'=>$section])];
}
$routes[] = ['Vendas','vendas.php',''];
foreach (['wallets','receipts'] as $section) {
    $routes[] = ['Recebimentos / ' . $section,'recebimentos.php',http_build_query(['section'=>$section])];
}
$routes[] = ['Auditoria financeira','auditoria_vendas.php',''];
foreach (['overview','agreements','funding','marketplace','settlements','ledger'] as $section) {
    $routes[] = ['Monetização / ' . $section,'monetizacao.php',http_build_query(['section'=>$section])];
}
$routes[] = ['Campanhas / overview','campanhas.php',http_build_query(['section'=>'overview'])];
$routes[] = ['Anúncios','anuncios.php',''];
$routes[] = ['Promoções','promocoes.php',''];
foreach (['commercial','offers','performance','settings'] as $section) {
    $routes[] = ['Campanhas / ' . $section,'campanhas.php',http_build_query(['section'=>$section])];
}
$routes[] = ['Relatórios','relatorios.php',''];
$routes[] = ['Infraestrutura / overview','infraestrutura.php',http_build_query(['section'=>'overview'])];
$routes[] = ['Equipamentos NAS','nas.php',''];
$routes[] = ['Destinos RADIUS','nas.php',http_build_query(['section'=>'radius'])];
foreach (['freeradius','jobs','logs'] as $section) {
    $routes[] = ['Infraestrutura / ' . $section,'infraestrutura.php',http_build_query(['section'=>$section])];
}
foreach (['overview','hubsoft','payments','messaging','adsense'] as $section) {
    $routes[] = ['Integrações / ' . $section,'integracoes.php',http_build_query(['section'=>$section])];
}
foreach (['overview','general','identity','governance','security'] as $section) {
    $routes[] = ['Configurações / ' . $section,'configuracoes.php',http_build_query(['section'=>$section])];
}
$routes[] = ['Supervisão de administradores','host_acessos.php',''];

if (count($routes) !== 63) {
    throw new RuntimeException('Inventário da auditoria autenticada divergiu das 63 combinações canônicas.');
}

if (($argv[1] ?? '') === '--inventory') {
    echo json_encode(array_map(
        static fn(array $item):array=>['label'=>$item[0],'route'=>$item[1],'query'=>$item[2]],
        $routes
    ),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

$renderer = <<<'PHP'
$root = $argv[1];
$route = $argv[2];
$query = $argv[3];
chdir($root);
ini_set('session.use_cookies','0');
ini_set('session.use_strict_mode','0');
session_id('fsrender' . substr(hash('sha256',$route . "\0" . $query . "\0" . getmypid()),0,32));
$_SERVER = array_merge($_SERVER,[
    'HTTPS'=>'on',
    'REQUEST_METHOD'=>'GET',
    'PHP_SELF'=>'/dashboard/' . $route,
    'SCRIPT_NAME'=>'/dashboard/' . $route,
    'REQUEST_URI'=>'/dashboard/' . $route . ($query !== '' ? '?' . $query : ''),
    'HTTP_HOST'=>'localhost',
    'SERVER_NAME'=>'localhost',
    'SERVER_PORT'=>'443',
    'REMOTE_ADDR'=>'127.0.0.1',
]);
$_GET=[];
if ($query !== '') parse_str($query,$_GET);
$_POST=[];
require $root . '/app/session_boot.php';
$_SESSION=[
    'admin'=>true,
    'admin_name'=>'Auditoria autenticada FireSpot',
    'admin_role'=>'admin',
    'admin_id'=>0,
];
try {
    ob_start();
    include $root . '/dashboard/' . $route;
    $html=(string)ob_get_clean();
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    echo $html;
} catch (Throwable $error) {
    while (ob_get_level() > 0) ob_end_clean();
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    fwrite(STDERR,get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    exit(70);
}
PHP;

$render = static function (string $route, string $query) use ($root, $renderer): array {
    $command = [PHP_BINARY,'-d','display_errors=stderr','-d','html_errors=0','-d','log_errors=0','-d','error_reporting=-1','-r',$renderer,$root,$route,$query];
    $descriptors = [
        0=>['pipe','r'],
        1=>['pipe','w'],
        2=>['pipe','w'],
    ];
    $process = proc_open($command,$descriptors,$pipes,$root,null,['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Não foi possível iniciar o renderizador isolado.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1],false);
    stream_set_blocking($pipes[2],false);
    $stdout='';$stderr='';$status=proc_get_status($process);$started=microtime(true);
    while ($status['running']) {
        $read=[];
        if (!feof($pipes[1])) $read[]=$pipes[1];
        if (!feof($pipes[2])) $read[]=$pipes[2];
        if ($read) {
            $write=null;$except=null;
            @stream_select($read,$write,$except,1,0);
            foreach ($read as $stream) {
                $chunk=(string)stream_get_contents($stream);
                if ($stream === $pipes[1]) $stdout.=$chunk; else $stderr.=$chunk;
            }
        }
        if (microtime(true)-$started>30) {
            proc_terminate($process,9);
            $stderr.="Tempo limite de 30 segundos excedido.\n";
            break;
        }
        $status=proc_get_status($process);
    }
    $stdout.=(string)stream_get_contents($pipes[1]);
    $stderr.=(string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    $closeCode=proc_close($process);
    $exitCode=$closeCode>=0?$closeCode:(int)($status['exitcode']??-1);
    return ['exit'=>$exitCode,'stdout'=>$stdout,'stderr'=>$stderr];
};

if (($argv[1] ?? '') === '--render-advanced-partner') {
    $requestedSection=(string)($argv[2]??'portal');
    if(!in_array($requestedSection,['summary','portal','points'],true)){
        fwrite(STDERR,"Seção avançada fora do inventário visual.\n");exit(64);
    }
    $advancedPartnerId=(int)($pdo->query("SELECT p.id FROM partners p
        WHERE p.active=1 AND p.self_service_enabled=1
          AND EXISTS(SELECT 1 FROM partner_subscriptions s JOIN platform_plans plan ON plan.id=s.plan_id
            WHERE s.partner_id=p.id AND s.is_current=1 AND plan.code='multipoint_advanced')
        ORDER BY p.id LIMIT 1")->fetchColumn()?:0);
    if($advancedPartnerId<=0){fwrite(STDERR,"Estabelecimento Multipontos de referência ausente.\n");exit(69);}
    $query=http_build_query(['id'=>$advancedPartnerId,'section'=>$requestedSection]);
    $result=$render('estabelecimento.php',$query);
    if($result['exit']!==0||trim((string)$result['stderr'])!==''){
        fwrite(STDERR,(string)$result['stderr']);exit($result['exit']!==0?(int)$result['exit']:70);
    }
    echo (string)$result['stdout'];exit(0);
}

// Suporte deliberadamente restrito ao CLI para auditorias em navegador sem
// expor uma rota autenticada ou criar uma sessão administrativa no servidor
// web. Apenas combinações presentes no inventário canônico podem ser
// renderizadas.
if (($argv[1] ?? '') === '--render') {
    $requestedRoute = (string)($argv[2] ?? '');
    $requestedQuery = (string)($argv[3] ?? '');
    $allowed = false;
    foreach ($routes as [, $route, $query]) {
        if ($route === $requestedRoute && $query === $requestedQuery) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        fwrite(STDERR,"Combinação fora do inventário canônico.\n");
        exit(64);
    }
    $result = $render($requestedRoute,$requestedQuery);
    if ($result['exit'] !== 0 || trim((string)$result['stderr']) !== '') {
        fwrite(STDERR,(string)$result['stderr']);
        exit($result['exit'] !== 0 ? (int)$result['exit'] : 70);
    }
    echo (string)$result['stdout'];
    exit(0);
}

$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException($message);};
$previousLibxml=libxml_use_internal_errors(true);

foreach ($routes as [$label,$route,$query]) {
    $result=$render($route,$query);
    $diagnostic=trim((string)$result['stderr']);
    $expect($result['exit']===0,$label . ' falhou ao renderizar' . ($diagnostic!==''?': ' . $diagnostic:'.'));
    $expect($diagnostic==='',$label . ' emitiu erro ou aviso PHP: ' . $diagnostic);
    $html=(string)$result['stdout'];
    $expect(trim($html)!=='',$label . ' retornou HTML vazio.');

    $dom=new DOMDocument();
    libxml_clear_errors();
    $loaded=$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOWARNING|LIBXML_NOERROR);
    $expect($loaded===true,$label . ' não produziu um documento HTML analisável.');
    $xpath=new DOMXPath($dom);
    $expect($xpath->query('//main')->length===1,$label . ' precisa de exatamente um conteúdo principal.');
    $expect($xpath->query('//main//h1')->length===1,$label . ' precisa de exatamente um título principal.');
    $expect($xpath->query('//body//meta')->length===0,$label . ' moveu metadados do head para o body.');
    $expect($xpath->query('//img[not(@alt)]')->length===0,$label . ' contém imagem sem atributo alt.');

    $ids=[];
    foreach ($xpath->query('//*[@id]') as $node) {
        $id=trim((string)$node->getAttribute('id'));
        if ($id==='') continue;
        $expect(!isset($ids[$id]),$label . ' contém id duplicado: ' . $id . '.');
        $ids[$id]=true;
    }

    foreach ($xpath->query('//a[@href]') as $link) {
        $name=trim((string)$link->textContent);
        if ($name==='') $name=trim((string)$link->getAttribute('aria-label'));
        if ($name==='') $name=trim((string)$link->getAttribute('title'));
        $expect($name!=='',$label . ' contém link sem nome acessível.');
    }
    foreach ($xpath->query('//button') as $button) {
        $name=trim((string)$button->textContent);
        if ($name==='') $name=trim((string)$button->getAttribute('aria-label'));
        if ($name==='') $name=trim((string)$button->getAttribute('title'));
        $expect($name!=='',$label . ' contém botão sem nome acessível.');
    }
    foreach ($xpath->query('//input[not(translate(@type,"HIDEN","hiden")="hidden")]|//select|//textarea') as $control) {
        $type=strtolower((string)$control->getAttribute('type'));
        $name=trim((string)$control->getAttribute('aria-label'));
        if ($name==='') $name=trim((string)$control->getAttribute('aria-labelledby'));
        if ($name===''&&in_array($type,['submit','button','reset','image'],true)) $name=trim((string)$control->getAttribute('value'));
        if ($name==='') {
            $id=(string)$control->getAttribute('id');
            if ($id!=='') {
                $quoted='"' . str_replace('"','\\"',$id) . '"';
                $labels=$xpath->query('//label[@for=' . $quoted . ']');
                if ($labels!==false&&$labels->length>0) $name=trim((string)$labels->item(0)->textContent);
            }
        }
        if ($name==='') {
            $labels=$xpath->query('ancestor::label[1]',$control);
            if ($labels!==false&&$labels->length>0) $name=trim((string)$labels->item(0)->textContent);
        }
        $identity=(string)$control->getAttribute('id');
        if ($identity==='') $identity=(string)$control->getAttribute('name');
        if ($identity==='') $identity=strtolower($control->nodeName);
        $expect($name!=='',$label . ' contém controle sem rótulo: ' . $identity . '.');
    }
}

libxml_clear_errors();
libxml_use_internal_errors($previousLibxml);
echo 'OK: ' . count($routes) . ' páginas autenticadas e ' . $checks . " verificações de renderização e acessibilidade.\n";
