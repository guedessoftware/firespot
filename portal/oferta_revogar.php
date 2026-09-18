<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/session_boot.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/ad_monetization.php';
$lead=trim((string)($_GET['lead']??$_POST['lead']??''));$sig=trim((string)($_GET['sig']??$_POST['sig']??''));$done=false;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){try{if(!csrf_check($_POST['csrf']??''))throw new RuntimeException('Sessão expirada. Reabra o link recebido.');if(!fs_ad_revoke_lead_public(db(),$lead,$sig))throw new RuntimeException('Link inválido ou já indisponível.');$done=true;}catch(Throwable $e){$error=$e->getMessage();}}
function revoke_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Revogar oferta</title><style>body{font-family:system-ui;background:#071225;color:#fff;display:grid;place-items:center;min-height:100vh;margin:0}.card{width:min(88vw,440px);padding:24px;border:1px solid #29415f;border-radius:18px;background:#0f2038}.btn{border:0;border-radius:11px;padding:12px 16px;background:#ff9f1c;font-weight:800}.error{color:#fecaca}</style></head><body><main class="card"><?php if($done):?><h1>Consentimento revogado</h1><p>Seu nome e celular foram removidos desta oferta. Mensagens ainda pendentes foram canceladas.</p><?php else:?><h1>Não quero mais receber esta oferta</h1><p>Ao confirmar, seu contato será removido e qualquer mensagem ainda pendente será cancelada.</p><?php if($error):?><p class="error"><?=revoke_h($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=revoke_h(csrf_token())?>"><input type="hidden" name="lead" value="<?=revoke_h($lead)?>"><input type="hidden" name="sig" value="<?=revoke_h($sig)?>"><button class="btn" type="submit">Remover meu contato</button></form><?php endif;?></main></body></html>

