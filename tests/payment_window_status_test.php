<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/portal_v3_payment_window.php';
require_once __DIR__ . '/../portal-v3/_boot.php';

$checks = 0;
$expect = static function (bool $condition,string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$now = 1700000000;

$active = fs_v3_payment_window_policy_summary([[
    'order_id'=>91,'started_ts'=>$now-60,'expires_ts'=>$now+60,'closed_ts'=>0,
    'close_reason'=>null,'order_status'=>'pending',
]],91,$now,2,10,3);
$expect($active['state']==='active' && $active['current_remaining_seconds']===60,'A janela ativa não preservou seu contador.');
$expect($active['attempts_used']===1 && $active['attempts_remaining']===2 && $active['daily_limit']===3,'O saldo de tentativas da janela ativa está incorreto.');
$expect($active['retry_after']===540 && $active['can_start']===false,'A próxima liberação não respeitou o cooldown contado desde o início.');

$cooldown = fs_v3_payment_window_policy_summary([[
    'order_id'=>91,'started_ts'=>$now-180,'expires_ts'=>$now-60,'closed_ts'=>$now-60,
    'close_reason'=>'expired','order_status'=>'pending',
]],91,$now,2,10,3);
$expect($cooldown['state']==='cooling_down' && $cooldown['retry_after']===420,'A janela expirada não informou corretamente a próxima liberação.');
$expect($cooldown['window_used']===true && $cooldown['attempts_remaining']===2,'A janela usada não foi contabilizada para o cliente.');

$available = fs_v3_payment_window_policy_summary([[
    'order_id'=>91,'started_ts'=>$now-700,'expires_ts'=>$now-580,'closed_ts'=>$now-580,
    'close_reason'=>'expired','order_status'=>'pending',
]],91,$now,2,10,3);
$expect($available['state']==='available' && $available['can_start']===true && $available['retry_after']===0,'Uma nova janela elegível continuou bloqueada.');

$limited = fs_v3_payment_window_policy_summary([
    ['order_id'=>91,'started_ts'=>$now-700,'expires_ts'=>$now-580,'closed_ts'=>$now-580,'close_reason'=>'expired','order_status'=>'pending'],
    ['order_id'=>80,'started_ts'=>$now-3600,'expires_ts'=>$now-3480,'closed_ts'=>$now-3480,'close_reason'=>'expired','order_status'=>'pending'],
    ['order_id'=>70,'started_ts'=>$now-7200,'expires_ts'=>$now-7080,'closed_ts'=>$now-7080,'close_reason'=>'expired','order_status'=>'pending'],
],91,$now,2,10,3);
$expect($limited['state']==='daily_limit' && $limited['attempts_remaining']===0,'O limite de três janelas em 24 horas não foi apresentado.');
$expect($limited['retry_after']===79200 && $limited['can_start']===false,'O horário de renovação do limite diário está incorreto.');

$paidIgnored = fs_v3_payment_window_policy_summary([[
    'order_id'=>91,'started_ts'=>$now-60,'expires_ts'=>$now+60,'closed_ts'=>$now,
    'close_reason'=>'paid','order_status'=>'paid',
]],91,$now,2,10,3);
$expect($paidIgnored['state']==='unused' && $paidIgnored['attempts_used']===0,'Uma janela paga consumiu o limite de tentativas não pagas.');

$expiredOrderIgnored = fs_v3_payment_window_policy_summary([[
    'order_id'=>91,'started_ts'=>$now-60,'expires_ts'=>$now,'closed_ts'=>$now,
    'close_reason'=>'order_expired','order_status'=>'payment_failed',
]],91,$now,2,10,3);
$expect($expiredOrderIgnored['state']==='unused' && $expiredOrderIgnored['attempts_used']===0 && $expiredOrderIgnored['can_start']===true,'As tentativas do Pix vencido não foram liberadas para uma nova compra.');

$expiryOrder = ['created_at'=>'2026-08-12 11:59:53','payment_expires_at'=>'2026-08-13 11:59:53'];
$expiryTs = (new DateTimeImmutable('2026-08-13 11:59:53',new DateTimeZone('America/Manaus')))->getTimestamp();
$expect(fs_guest_payment_expiry_timestamp($expiryOrder)===$expiryTs,'O vencimento explícito do Pix foi interpretado no fuso incorreto.');
$expect(!fs_guest_payment_is_expired($expiryOrder,$expiryTs-1) && fs_guest_payment_is_expired($expiryOrder,$expiryTs),'A compra não vence exatamente no horário configurado.');
$expect(fs_guest_provider_expiration(['date_of_expiration'=>'2026-08-13T12:59:53-03:00'])==='2026-08-13 11:59:53','O vencimento do provedor não foi convertido para o horário local do banco.');
$providerExpiration=new DateTimeImmutable('2026-08-16 08:13:00',new DateTimeZone('America/Manaus'));
$expect(fs_payment_expiration_iso($providerExpiration)==='2026-08-16T08:13:00.000-04:00','O vencimento enviado ao Mercado Pago não contém milissegundos e fuso no formato aceito.');
$expect(fs_payment_normalize_status('expired')==='cancelled' && fs_payment_normalize_status('rejected')==='payment_failed','O Pix vencido não foi diferenciado de um pagamento recusado.');

$individualPartner = [
    'payment_window_minutes'=>4,
    'payment_window_daily_limit'=>7,
    'payment_window_cooldown_minutes'=>25,
    'payment_window_period_minutes'=>2880,
];
$expect(fs_v3_payment_window_minutes($individualPartner)===4,'A duração individual da janela Pix não foi aplicada.');
$expect(fs_v3_payment_window_daily_limit($individualPartner)===7,'O limite individual de tentativas Pix não foi aplicado.');
$expect(fs_v3_payment_window_cooldown_minutes($individualPartner)===25,'O intervalo individual entre janelas Pix não foi aplicado.');
$expect(fs_v3_payment_window_period_minutes($individualPartner)===2880,'O período individual de renovação Pix não foi aplicado.');
$expect(fs_v3_payment_window_enabled($individualPartner)===true&&fs_v3_payment_window_enabled(['payment_window_enabled'=>0])===false,'A ativação individual da janela Pix não foi respeitada.');
$shortPeriod = fs_v3_payment_window_policy_summary([[
    'order_id'=>91,'started_ts'=>$now-3700,'expires_ts'=>$now-3580,'closed_ts'=>$now-3580,
    'close_reason'=>'expired','order_status'=>'pending',
]],91,$now,2,10,3,60);
$expect($shortPeriod['state']==='unused' && $shortPeriod['attempts_used']===0 && $shortPeriod['period_minutes']===60,'Uma tentativa fora do período individual continuou consumindo o limite.');
$boundaryPeriod = fs_v3_payment_window_policy_summary([[
    'order_id'=>91,'started_ts'=>$now-3600,'expires_ts'=>$now-3480,'closed_ts'=>$now-3480,
    'close_reason'=>'expired','order_status'=>'pending',
]],91,$now,2,10,3,60);
$expect($boundaryPeriod['state']==='unused' && $boundaryPeriod['attempts_used']===0,'Uma tentativa continuou bloqueando o aparelho depois de completar exatamente o período.');

$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
$pixPayload = fs_payment_pix_display_payload(['point_of_interaction'=>['transaction_data'=>['qr_code'=>'000201-pix-teste','qr_code_base64'=>$png]]]);
$expect(($pixPayload['qr_code']??'')==='000201-pix-teste' && ($pixPayload['qr_code_base64']??'')==='data:image/png;base64,'.$png,'O QR Code Pix válido não foi preparado para exibição offline.');
$flatPixPayload = fs_payment_pix_display_payload(['qr_code'=>'000201-pix-sessao','qr_code_base64'=>'data:image/png;base64,'.$png]);
$expect(($flatPixPayload['qr_code']??'')==='000201-pix-sessao' && ($flatPixPayload['qr_code_base64']??'')==='data:image/png;base64,'.$png,'O QR Code Pix guardado na sessão não foi recuperado.');
$expect(fs_payment_pix_display_payload(['point_of_interaction'=>['transaction_data'=>['qr_code_base64'=>base64_encode('<svg></svg>')]]])===null,'Uma imagem que não é PNG foi aceita como QR Code Pix.');

$expect(
    fs_v3_payment_window_reauth_url(['gateway_ip'=>'10.115.0.1','dns_name'=>'00000001-teste.hotspot.internal']) === 'http://10.115.0.1/login',
    'O retorno da janela Pix não substituiu o DNS privado pelo gateway da instalação.'
);
$expect(fs_v3_payment_window_reauth_url(['gateway_ip'=>'10.116.0.1','dns_name'=>'outro.hotspot.internal'])==='http://10.116.0.1/login','Outra instalação reutilizou o gateway incorreto no retorno da janela Pix.');
$expect(fs_v3_payment_window_reauth_url(['gateway_ip'=>'','dns_name'=>'00000001-teste.hotspot.internal'])===null,'O retorno aceitou DNS privado sem gateway válido.');
$expect(fs_v3_payment_window_reauth_url(['gateway_ip'=>'10.115.0.1','dns_name'=>'wifi.example.com'])==='http://wifi.example.com/login','Um domínio personalizado válido foi reescrito indevidamente.');
$expect(fs_v3_payment_window_reauth_url(['gateway_ip'=>'10.115.0.1','dns_name'=>'00000001-teste.hotspot.internal/redirect'])==='http://10.115.0.1/login','O fallback para gateway falhou diante de um DNS name inválido.');
$expect(fs_v3_payment_window_reauth_url(['dns_name'=>'hotspot'])===null,'Um nome local sem domínio e sem gateway foi aceito no redirecionamento.');
$handoffDevice=['ip'=>'192.0.2.77','mac'=>'02:00:00:00:00:01'];
$expect(fs_v3_payment_window_host_selector($handoffDevice)==='mac-address="02:00:00:00:00:01" and address="192.0.2.77"','O encerramento da janela não seleciona exatamente MAC e IP do aparelho.');
$expiryScript=fs_v3_payment_window_expiry_script(120,'v3pay-1-0000000000000001',$handoffDevice);
$expect(str_contains($expiryScript,':if ([:len [/ip hotspot ip-binding find where comment="v3pay-1-0000000000000001"]] > 0)') && str_contains($expiryScript,'/ip hotspot host remove [find where mac-address="02:00:00:00:00:01" and address="192.0.2.77"]'),'O temporizador autônomo não recicla o host mantendo a proteção contra uma sessão paga posterior.');
$invalidHandoff=false;try{fs_v3_payment_window_host_selector(['ip'=>'192.0.2.77; remove','mac'=>'02:00:00:00:00:01']);}catch(InvalidArgumentException $e){$invalidHandoff=true;}
$expect($invalidHandoff,'O seletor de encerramento aceitou um IP não confiável.');
$handoffPending=['status'=>'paid','payment_method'=>'pix','payment_window_token'=>'v3pay-1-0000000000000001','payment_window_expires_at'=>'2026-08-20 23:46:08','payment_window_closed_at'=>null];
$handoffPendingNow=fs_guest_local_datetime_timestamp('2026-08-20 23:45:00');
$expect(fs_v3_payment_window_paid_handoff_pending($handoffPending,$handoffPendingNow)===true,'O webhook encerraria uma janela Pix ainda necessária para o handoff do navegador.');
$expect(fs_v3_payment_window_paid_handoff_pending($handoffPending,$handoffPendingNow+69)===false,'Uma janela Pix vencida continuou protegida contra o fechamento remoto.');
$closedHandoff=$handoffPending;$closedHandoff['payment_window_closed_at']='2026-08-20 23:45:01';
$expect(fs_v3_payment_window_paid_handoff_pending($closedHandoff,$handoffPendingNow)===false,'Uma janela já encerrada foi tratada como handoff pendente.');
$_SESSION['portal_v3_paid_handoff_claims']=[];
$recentPaid=['public_id'=>'00000000000000000000000000000001','status'=>'paid','payment_method'=>'pix','payment_window_token'=>'v3pay-1-0000000000000001','payment_window_closed_at'=>null,'paid_at'=>'2026-08-20 23:44:36','_credit'=>['online'=>false]];
$handoffNow=fs_guest_local_datetime_timestamp('2026-08-20 23:45:00');
$expect(v3_claim_recent_paid_handoff($recentPaid,$handoffNow)===true,'A captura recente do Pix com fechamento remoto pendente não reivindicou a continuação automática.');
$expect(v3_claim_recent_paid_handoff($recentPaid,$handoffNow+1)===false,'A continuação automática do Pix pôde ser repetida e criar um loop.');
$oldPaid=$recentPaid;$oldPaid['public_id']='a63cf14a4e66cec2d745a3a2fcf60dfa';$oldPaid['paid_at']='2026-08-20 23:30:00';
$expect(v3_claim_recent_paid_handoff($oldPaid,$handoffNow)===false,'Uma compra antiga ignorou a confirmação de retorno.');
$onlinePaid=$recentPaid;$onlinePaid['public_id']='00000000000000000000000000000003';$onlinePaid['_credit']['online']=true;
$expect(v3_claim_recent_paid_handoff($onlinePaid,$handoffNow)===false,'Uma sessão já online tentou autenticar novamente.');
$_SESSION['portal_v3_paid_return_claims']=[];
$_SESSION['hotspot_ctx']=['ts'=>$handoffNow,'data'=>[
    'link-login-only'=>'http://10.115.0.1/login','chap-id'=>'','chap-challenge'=>'',
]];
$returnPartner=['gateway_ip'=>'10.115.0.1','dns_name'=>'00000001-teste.hotspot.internal'];
$returnState=['connect_allowed'=>true,'connected'=>false,'current_mac'=>'02:00:00:00:00:01'];
$expect(v3_claim_paid_return_reconnect($oldPaid,$returnPartner,$returnState,$handoffNow)===true,'O retorno pago elegível não tentou a reconexão automática.');
$expect(v3_claim_paid_return_reconnect($oldPaid,$returnPartner,$returnState,$handoffNow+1)===false,'O mesmo desafio pôde repetir a reconexão automática e criar um loop.');
$blockedReturn=$returnState;$blockedReturn['connect_allowed']=false;
$expect(v3_claim_paid_return_reconnect($oldPaid,$returnPartner,$blockedReturn,$handoffNow+1)===false,'Um aparelho não associado recebeu tentativa automática de reconexão.');

$successSource = (string)file_get_contents(__DIR__ . '/../portal-v3/sucesso.php');
$statusSource = (string)file_get_contents(__DIR__ . '/../portal-v3/api/payment_status.php');
$webhookSource = (string)file_get_contents(__DIR__ . '/../portal-v3/api/webhook.php');
$connectSource = (string)file_get_contents(__DIR__ . '/../portal-v3/connect.php');
$checkoutSource = (string)file_get_contents(__DIR__ . '/../portal-v3/api/checkout_create.php');
$checkoutPageSource = (string)file_get_contents(__DIR__ . '/../portal-v3/checkout.php');
$checkoutJsSource = (string)file_get_contents(__DIR__ . '/../portal-v3/assets/js/checkout.js');
$indexSource = (string)file_get_contents(__DIR__ . '/../portal-v3/index.php');
$bootSource = (string)file_get_contents(__DIR__ . '/../portal-v3/_boot.php');
$cssSource = (string)file_get_contents(__DIR__ . '/../portal-v3/assets/css/portal-v3.css');
$centralSource = (string)file_get_contents(__DIR__ . '/../dashboard/estabelecimento.php');
$centralActionSource = (string)file_get_contents(__DIR__ . '/../dashboard/actions/commerce.php');
$migrationSource = (string)file_get_contents(__DIR__ . '/../migrations/032_partner_payment_window_policy.sql');
$expirationMigrationSource = (string)file_get_contents(__DIR__ . '/../migrations/033_guest_pix_expiration.sql');
$providerSource = (string)file_get_contents(__DIR__ . '/../app/payment_provider.php');
$cleanupSource = (string)file_get_contents(__DIR__ . '/../app/cli/cleanup_guest_access.php');
$windowApiSource = (string)file_get_contents(__DIR__ . '/../portal-v3/api/payment_window.php');
$preauthConnectSource = (string)file_get_contents(__DIR__ . '/../portal-v3/payment-connect.php');
$windowCoreSource = (string)file_get_contents(__DIR__ . '/../app/portal_v3_payment_window.php');
$guestRadiusSource = (string)file_get_contents(__DIR__ . '/../app/guest_payment_radius.php');
$expect(str_contains($statusSource,"'payment_window' => \$paymentWindow"),'A API de status não entrega a política da janela.');
$expect(str_contains($statusSource,"'reauth_url' => \$reauthUrl"),'A API de status não entrega a URL de retorno ao Hotspot.');
$expect(str_contains($bootSource,'function v3_claim_recent_paid_handoff') && str_contains($indexSource,"connect.php?order=") && str_contains($indexSource,"&handoff=1"),'A nova captura logo após o Pix não continua automaticamente para a credencial paga.');
$expect(str_contains($bootSource,'function v3_claim_paid_return_reconnect') && str_contains($indexSource,'&return=auto'),'O retorno pago não possui uma tentativa automática única antes da confirmação manual.');
$expect(str_contains($guestRadiusSource,'function fs_guest_radius_active_session_for_device') && str_contains($guestRadiusSource,'nasipaddress=?') && str_contains($guestRadiusSource,'callingstationid') && str_contains($guestRadiusSource,'framedipaddress=?'),'A detecção de conexão ativa não está vinculada a NAS, MAC e IP.');
$expect(!str_contains($statusSource,'fs_v3_payment_window_paid_handoff_pending') && str_contains($webhookSource,'fs_v3_payment_window_paid_handoff_pending'),'A WebView ou o webhook ainda encerra imediatamente a janela Pix aprovada.');
$expect(str_contains($connectSource,'fs_v3_payment_window_close($pdo, $partner, $order, false)'),'O handoff iniciado pelo navegador ainda recicla o host e invalida o desafio atual.');
$expect(str_contains($windowApiSource,"'connect_url'")&&str_contains($windowApiSource,'payment-connect.php'),'A janela Pix nova nao encaminha para a pre-autenticacao RADIUS.');
$expect(str_contains($preauthConnectSource,'v3_hotspot_login_url')&&str_contains($preauthConnectSource,'fs_hotspot_login_password'),'A pre-autenticacao Pix nao usa o destino seguro e o HTTP-CHAP centralizados.');
$expect(str_contains($preauthConnectSource,'name="dst"')&&str_contains($preauthConnectSource,'v3_public_base_url'),'O login provisorio nao retorna ao dominio publico do Portal V3.');
$expect(str_contains($windowCoreSource,'FS_GUEST_PAYMENT_ACCESS_RADIUS')&&str_contains($windowCoreSource,'FS_GUEST_PAYMENT_ACCESS_LEGACY'),'A janela Pix nao separa explicitamente o transporte RADIUS do legado.');
$expect(str_contains($windowCoreSource,"A janela temporária Pix está desativada neste ponto")&&substr_count($windowCoreSource,'hotspot_id=?')>=3,'A janela Pix não é bloqueada ou contabilizada no escopo do ponto original.');
$expect(str_contains((string)file_get_contents(__DIR__ . '/../app/portal_v3_payment_window.php'),'SELECT * FROM guest_orders WHERE id=? AND partner_id=? AND payment_window_token=? LIMIT 1'),'O fechamento não relê a janela dentro do lock e permite remoções duplicadas por webhooks concorrentes.');
$expect(str_contains($successSource,"document.addEventListener('visibilitychange'") && str_contains($successSource,"window.addEventListener('pageshow'"),'A WebView retomada depois do aplicativo bancário não força uma nova consulta do Pix.');
$expect(str_contains($statusSource,"'pix' => \$order['status'] === 'pending' ? \$pix : null"),'A API de status não entrega o QR Code enquanto o Pix está pendente.');
$expect(str_contains($checkoutSource,'v3_remember_pending_pix') && str_contains($bootSource,'function v3_pending_pix'),'O QR Code Pix não é preservado na sessão do pedido.');
$expect(str_contains($checkoutSource,'payment_expires_at=DATE_ADD(NOW(),INTERVAL 24 HOUR)') && str_contains($providerSource,"\$payload['date_of_expiration']"),'A cobrança Pix não recebe um vencimento explícito de 24 horas.');
$expect(str_contains($checkoutPageSource,'id="pay-with-pix"') && str_contains($checkoutPageSource,'id="pay-with-card"'),'O checkout compacto não separa Pix imediato e cartão sob demanda.');
$expect(!str_contains($checkoutPageSource,'Você não está criando uma conta') && !str_contains($checkoutPageSource,'Opcional e desmarcado por padrão'),'O checkout ainda contém explicações longas e redundantes.');
$expect(!str_contains($checkoutPageSource,'sdk.mercadopago.com') && str_contains($checkoutJsSource,"payment_method_id: 'pix'") && str_contains($checkoutJsSource,"script.src = 'https://sdk.mercadopago.com/js/v2'"),'O Pix ainda depende do carregamento antecipado do formulário externo.');
$expect(str_contains($checkoutJsSource,'if (linkAccount) linkAccount.checked = false;'),'A associação opcional da compra não inicia explicitamente desmarcada.');
$expect(str_contains($checkoutJsSource,"paymentMethods: { creditCard: 'all', maxInstallments: 12 }"),'O formulário sob demanda não está limitado ao cartão.');
$expect(str_contains($cssSource,'body.checkout-page{') && str_contains($cssSource,'height:100svh;') && str_contains($cssSource,'overflow:hidden;'),'O checkout inicial não foi limitado à altura útil do aparelho.');
$expect(str_contains($cleanupSource,"payment_status_detail='expired'") && str_contains($cleanupSource,'fs_v3_payment_window_release_expired_order'),'A rotina não cancela e libera as tentativas do pedido vencido.');
$expect(str_contains($statusSource,"'restart_url' => \$restartUrl") && str_contains($successSource,"titleEl.textContent=data.payment_expired?'Pix vencido'") && str_contains($successSource,'window.location.replace(data.restart_url||cfg.restartUrl)'),'A tela vencida não retorna automaticamente para uma nova compra.');
$expect(str_contains($bootSource,'function v3_forget_order') && str_contains($bootSource,'fs_guest_payment_is_expired'),'O pedido vencido ainda pode ser recuperado pela sessão ou pelo cookie.');
$expect(str_contains($statusSource,"payment_window_expires_at") && str_contains($statusSource,'fs_v3_payment_window_close'),'A API não encerra a janela expirada antes do retorno ao Hotspot.');
$expect(str_contains($successSource,'<span>Tentativas</span>') && str_contains($successSource,'attemptsEl.textContent=String(policy.attempts_used)') && str_contains($successSource,'limitEl.textContent=String(policy.daily_limit)'),'A tela final não apresenta a progressão de tentativas usadas até o limite diário.');
$expect(str_contains($successSource,'payment-window-countdown'),'A tela final não apresenta a contagem regressiva.');
$expect(str_contains($successSource,'Liberar internet') && str_contains($successSource,'Verificar Pix'),'A tela final não oferece as ações compactas esperadas.');
$expect(str_contains($successSource,'Abrir portal') && str_contains($successSource,'window.location.replace'),'A tela final não oferece retorno HTTP automático e manual ao Hotspot.');
$expect(str_contains($successSource,'payment-pix-card') && str_contains($successSource,'Copiar código Pix') && str_contains($successSource,'renderPix(data.pix)'),'A tela final não exibe o QR Code e a cópia do Pix pendente.');
$expect(str_contains($successSource,'renderPix(cfg.pix)'),'A tela final aguarda a rede mesmo quando o QR Code já está guardado na sessão.');
$expect(str_contains($successSource,'return-credit-summary') && str_contains($successSource,'Tempo restante') && str_contains($successSource,'Liberar acesso'),'O retorno pago não apresenta somente saldo e a ação de liberação.');
$expect(str_contains($successSource,"\$order['status'] === 'pending'") && str_contains($successSource,'if (!$confirmReturn)'),'A tela de retorno ainda pode executar ou renderizar o fluxo da janela Pix.');
$expect(str_contains($successSource,'<div class="payment-window-lower">') && strpos($successSource,'payment-window-duration') < strpos($successSource,'payment-pix-card'),'A duração e o Pix não foram organizados na área inferior dividida do cartão.');
$expect(str_contains($successSource,"navigator.clipboard") && str_contains($successSource,"document.execCommand('copy')"),'A cópia do Pix não possui compatibilidade alternativa para a WebView.');
$expect(str_contains($successSource,"previousState==='active'") && str_contains($successSource,'schedulePortalReturn();'),'O retorno automático não está limitado à transição de janela ativa para encerrada.');
$expect(!str_contains($successSource,'Nova janela disponível') && !str_contains($successSource,'Internet temporária disponível'),'A tela final ainda usa títulos longos ou redundantes.');
$expect(!str_contains($successSource,'Limite diário atingido') && str_contains($successSource,'Limite de tentativas atingido'),'A tela final ainda chama um período configurável de limite diário.');
$expect(!str_contains($successSource,'if(attempts<150)'),'A consulta automática ainda para depois de dez minutos.');
$expect(str_contains($cssSource,'body.success-page{height:100vh;height:100svh') && str_contains($cssSource,'overflow:hidden'),'A tela compacta não respeita a altura útil do dispositivo.');
$expect(str_contains($cssSource,'body.success-page .success-actions{width:min(430px,100%);display:grid;grid-template-columns:1fr 1fr'),'As ações não foram compactadas lado a lado.');
$expect(str_contains($cssSource,'@media(max-height:620px)'),'Não há redução progressiva para telas baixas.');
$expect(str_contains($cssSource,'.payment-window-card.has-pix .payment-window-lower{grid-template-columns:minmax(0,1fr) minmax(0,1fr)'),'A área inferior não foi dividida igualmente entre indicadores e Pix.');
$expect(str_contains($cssSource,'body.success-page .payment-pix-card img{width:min(100%,clamp(104px,17svh,142px))') && str_contains($cssSource,'body.success-page .payment-pix-card img{width:min(100%,82px)}'),'O QR Code não possui dimensões maiores e adaptação para telas baixas.');
$expect(str_contains($centralActionSource,'payment_window_save') && str_contains($centralActionSource,'partner_central_save_payment_window') && str_contains($centralSource,'Salvar política de pagamento'),'O painel FireSpot não salva a política individual de pagamento.');
$expect(str_contains($centralSource,'name="payment_window_daily_limit"') && str_contains($centralSource,'name="payment_window_cooldown_minutes"') && str_contains($centralSource,'name="payment_window_period_hours"'),'O painel FireSpot não apresenta todos os controles da janela Pix.');
$expect(str_contains($migrationSource,'payment_window_daily_limit') && str_contains($migrationSource,'payment_window_cooldown_minutes') && str_contains($migrationSource,'payment_window_period_minutes'),'A migração da política individual está incompleta.');
$expect(str_contains($expirationMigrationSource,'payment_expires_at') && str_contains($expirationMigrationSource,"close_reason='order_expired'"),'A migração do vencimento dos pedidos Pix está incompleta.');

echo "OK: {$checks} verificações do estado da janela de pagamento.\n";
