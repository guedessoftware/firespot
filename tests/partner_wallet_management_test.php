<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__ . '/../app/partner_central.php';

$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{
    $checks++;
    if(!$condition)throw new RuntimeException($message);
};

$central=(string)file_get_contents(__DIR__.'/../app/partner_central.php');
$portal=(string)file_get_contents(__DIR__.'/../portal/host/index.php')
    .(string)file_get_contents(__DIR__.'/../portal/host/pages/billing.php')
    .(string)file_get_contents(__DIR__.'/../portal/host/panel_actions.php');
$migration=(string)file_get_contents(__DIR__.'/../migrations/049_partner_wallet_lifecycle.sql');
$provider=(string)file_get_contents(__DIR__.'/../app/payment_provider.php');

foreach([
    'partner_central_rotate_partner_wallet_token',
    'partner_central_rotate_partner_wallet_webhook',
    'partner_central_test_partner_wallet_webhook',
    'partner_central_test_partner_wallet_connection',
    'partner_central_restore_partner_wallet',
] as $function)$expect(str_contains($central,'function '.$function),'Função de ciclo da carteira ausente: '.$function);
$expect(substr_count($central,'fs_payment_validate_wallet_capabilities')>=2,'Token novo não valida identidade e consulta de pagamentos.');
$expect(substr_count($central,'SELECT independent_billing,payment_wallet_id FROM partners WHERE id=? FOR UPDATE')>=3,'Substituição e rotações não fixam a carteira ativa sob lock.');
$expect(str_contains($central,'WHERE id=? AND partner_id=? AND active=1'),'Atualização da carteira não restringe propriedade e estado ativo.');
$expect(str_contains($central,"(int)(\$wallet['active'] ?? 0) !== 1"),'A Central ainda pode vincular uma carteira histórica inativa.');
$expect(str_contains($provider,"/v1/payments/search?sort=date_created&criteria=desc&limit=1&offset=0"),'Validação de consulta de pagamentos ausente.');
$expect(str_contains($provider,'fs_payment_webhook_self_test'),'Autoteste HMAC do webhook ausente.');

foreach(['wallet_token_rotate','wallet_webhook_rotate','wallet_connection_test','wallet_replace','wallet_restore'] as $action){
    $expect(str_contains($portal,"\$action === '".$action."'")||str_contains($portal,'value="'.$action.'"'),'Ação não exposta de forma protegida: '.$action);
}
$expect(substr_count($portal,"partner_admin_require_current_password")>=7,'Ações sensíveis deixaram de exigir reautenticação.');
$expect(str_contains($portal,'wallet.token_rotated')&&str_contains($portal,'wallet.webhook_secret_rotated')&&str_contains($portal,'wallet.webhook_tested')&&str_contains($portal,'wallet.restored'),'Auditoria da carteira ficou incompleta.');
$expect(str_contains($central,'fs_payment_validate_wallet_capabilities($wallet)')&&str_contains($central,'fs_payment_webhook_self_test($wallet)'),'Rollback de carteira não revalida token e assinatura antes de restaurar.');
$expect(str_contains($central,'function partner_central_test_partner_wallet_connection')&&str_contains($central,"'ready'=>\$api==='ok'&&\$webhook==='ok'")&&str_contains($central,"\$webhook='attention'"),'Teste unificado não diferencia API e webhook usando a carteira armazenada.');
$expect(str_contains($portal,'Testar conexão da carteira')&&str_contains($portal,"\$action==='wallet_connection_test'")&&!preg_match("/wallet_connection_test'[\\s\\S]{0,350}partner_admin_require_current_password/",$portal),'Diagnóstico da carteira ainda exige preenchimento manual ou senha atual.');
$expect(str_contains($portal,'Gateway de recebimento')&&str_contains($portal,'Outros gateways · em breve'),'Seletor extensível de gateway não foi preparado na interface.');
$expect(!preg_match('/<input[^>]*name="(?:access_token|webhook_secret)"[^>]*value=/i',$portal),'Segredo da carteira foi pré-preenchido no HTML.');
$expect(str_contains($portal,'fs_partner_independence_plan_allows_wallet_activation')&&str_contains($portal,'partner_central_replace_partner_wallet($pdo,$partnerId,$_POST,$canActivateIndependent)'),'Ativação da carteira própria não está restrita ao plano máximo no servidor.');
$expect(str_contains($portal,'Pertence ao estabelecimento')&&str_contains($portal,'Todos os pontos usam o mesmo recebedor'),'A interface não esclarece a propriedade e a abrangência financeira da carteira.');
$expect(str_contains($portal,'Adicionar carteira Mercado Pago')&&str_contains($portal,'Editar ou substituir carteira')&&str_contains($portal,'salvar alterações'),'A interface não expõe claramente os fluxos de adicionar e editar a carteira.');
$expect(str_contains($migration,'access_token_validated_at')&&str_contains($migration,'webhook_secret_validated_at'),'Migração não registra as datas de validação.');
$expect(str_contains($migration,'uq_payment_wallet_active_partner'),'Migração não garante uma única carteira própria ativa por estabelecimento.');

$wallet=['webhook_secret'=>'fixture-webhook-secret'];
$result=fs_payment_webhook_self_test($wallet);
$expect($result['valid']===true&&$result['reason']==='ok','Autoteste HMAC não usa o verificador de produção.');

echo "OK: {$checks} verificações da gestão de carteira própria.\n";
