<?php

declare(strict_types=1);

if(!defined('FIRESPOT_HOST_PANEL_VIEW')){http_response_code(404);exit;}

$independentBilling=(int)($context['independent_billing']??0)===1;
$canConnectWallet=$independentBilling||$walletCanActivate;
$walletValidationReady=fs_payment_wallet_validation_schema_ready($pdo);
$activeEnvironment=(string)($activeWallet['environment']??'production');
$activeEnvironmentLabel=$activeEnvironment==='sandbox'?'Sandbox':'Produção';
$activeProvider=(string)($activeWallet['provider']??'mercadopago');
$paymentGateways=fs_payment_gateway_catalog();
?>
<section class="stack host-wallet">
  <article class="card host-wallet__hero">
    <div class="host-wallet__hero-copy">
      <span class="host-wallet__eyebrow">Financeiro · plano máximo</span>
      <h1>Carteira do estabelecimento</h1>
      <p class="subtle">Gerencie a conta que recebe as vendas de todos os pontos Hotspot de <?=host_h($context['name'])?>.</p>
      <div class="host-wallet__badges" aria-label="Escopo e elegibilidade da carteira">
        <span class="pill warning">Plano Máximo · Independência</span>
        <span class="pill">Pertence ao estabelecimento</span>
        <span class="pill">Uma carteira ativa</span>
      </div>
    </div>
    <div class="host-wallet__state <?=$activeWallet?'is-connected':'is-central'?>">
      <span>Recebimento atual</span>
      <strong><?=$activeWallet?'Carteira própria':'FireSpot'?></strong>
      <small><?=$activeWallet?'Pagamentos novos vão diretamente para a conta conectada.':'O recebimento permanece central até uma carteira ser validada.'?></small>
    </div>
  </article>

  <article class="card host-wallet__scope" aria-labelledby="wallet-scope-title">
    <div class="host-wallet__section-heading">
      <div><span class="host-wallet__eyebrow">Responsabilidade</span><h2 id="wallet-scope-title">Como a carteira funciona</h2></div>
      <span class="pill <?=$walletPlanEligible?'ok':'off'?>"><?=$walletPlanEligible?'Incluída no plano vigente':'Exclusiva do Plano Máximo'?></span>
    </div>
    <div class="host-wallet__scope-grid">
      <div><span>Proprietário</span><strong><?=host_h($context['name'])?></strong><small>A carteira nunca pertence a um NAS ou ponto isolado.</small></div>
      <div><span>Abrangência</span><strong><?=count($partnerHotspots)?> ponto(s) Hotspot</strong><small>Todos os pontos usam o mesmo recebedor do estabelecimento.</small></div>
      <div><span>Acesso administrativo</span><strong>Proprietário, Gerente e Financeiro</strong><small>Somente perfis administrativos autorizados pelo estabelecimento.</small></div>
      <div><span>Proteção</span><strong>Segredos criptografados</strong><small>Token e assinatura aparecem somente de forma mascarada.</small></div>
    </div>
  </article>

  <?php if($activeWallet):?>
  <article class="card host-wallet__active">
    <div class="host-wallet__section-heading">
      <div><span class="host-wallet__eyebrow">Carteira ativa</span><h2><?=host_h($activeWallet['name'])?></h2><p class="subtle"><?=host_h(fs_payment_gateway_label($activeProvider))?> · <?=host_h($activeEnvironmentLabel)?> · conectada ao estabelecimento.</p></div>
      <span class="host-wallet__connection"><i aria-hidden="true"></i> Carteira ativa</span>
    </div>
    <dl class="host-wallet__credentials">
      <div><dt>Access Token</dt><dd><?=host_h($activeWallet['credential_hint']?:'Mascarado')?><small>Validado em <?=host_h(host_datetime($activeWallet['access_token_validated_at']??$activeWallet['updated_at']))?></small></dd></div>
      <div><dt>Assinatura do webhook</dt><dd><?=host_h($activeWallet['webhook_secret_hint']?:'Pendente')?><small><?=!empty($activeWallet['webhook_secret_validated_at'])?'Validada em '.host_h(host_datetime($activeWallet['webhook_secret_validated_at'])):'Validação rastreável pendente'?></small></dd></div>
      <div><dt>Ambiente</dt><dd><?=host_h($activeEnvironmentLabel)?><small><?=$activeEnvironment==='sandbox'?'Sem recebimentos reais':'Preparada para cobranças reais'?></small></dd></div>
      <div><dt>Gateway</dt><dd><?=host_h(fs_payment_gateway_label($activeProvider))?><small>Todos os pontos usam este recebedor.</small></dd></div>
    </dl>
  </article>

  <?php if($walletValidationReady):?>
  <article class="card host-wallet__maintenance">
    <div class="host-wallet__section-heading host-wallet__health-heading">
      <div><span class="host-wallet__eyebrow">Diagnóstico</span><h2>Conexão e credenciais</h2><p class="subtle">Teste a carteira armazenada sem preencher token, segredo ou senha. Nenhuma cobrança será criada.</p></div>
      <form method="post"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="wallet_connection_test"><input type="hidden" name="return_page" value="billing"><button class="btn primary" type="submit">Testar conexão da carteira</button></form>
    </div>
    <div class="host-wallet__credential-actions">
      <details class="host-wallet__action">
        <summary><span><strong>Atualizar Access Token</strong><small>Trocar somente a credencial da API</small></span><b>Alterar</b></summary>
        <form method="post" class="host-wallet__compact-form">
          <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="wallet_token_rotate"><input type="hidden" name="return_page" value="billing">
          <label>Novo Access Token<input type="password" name="access_token" autocomplete="new-password" required></label>
          <label>Sua senha do portal<input type="password" name="current_password" autocomplete="current-password" required></label>
          <button class="btn primary" type="submit">Validar e atualizar token</button>
        </form>
      </details>
      <details class="host-wallet__action">
        <summary><span><strong>Atualizar Webhook</strong><small>Trocar somente a assinatura das notificações</small></span><b>Alterar</b></summary>
        <form method="post" class="host-wallet__compact-form">
          <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="wallet_webhook_rotate"><input type="hidden" name="return_page" value="billing">
          <label>Nova assinatura secreta<input type="password" name="webhook_secret" autocomplete="new-password" required></label>
          <label>Sua senha do portal<input type="password" name="current_password" autocomplete="current-password" required></label>
          <button class="btn primary" type="submit">Testar e atualizar webhook</button>
        </form>
      </details>
    </div>
  </article>
  <?php else:?><div class="notice">A manutenção separada das credenciais será liberada após a atualização do ciclo seguro de carteiras.</div><?php endif;?>
  <?php endif;?>

  <?php if($canConnectWallet):?>
  <details class="card host-wallet__connect" <?=!$activeWallet?'open':''?>>
    <summary>
      <span><span class="host-wallet__eyebrow"><?=$activeWallet?'Edição segura':'Ativação da independência'?></span><strong><?=$activeWallet?'Editar ou substituir carteira':'Adicionar carteira Mercado Pago'?></strong><small><?=$activeWallet?'Nome, ambiente e chave pública são preenchidos; confirme novamente os segredos para salvar uma revisão segura.':'Disponível exclusivamente no Plano Máximo · Independência.'?></small></span>
      <b><?=$activeWallet?'Editar':'Adicionar'?></b>
    </summary>
    <div class="host-wallet__connect-body">
      <div class="host-wallet__connect-note">
        <strong>Antes de conectar</strong>
        <p>O Mercado Pago precisa confirmar a identidade do token e permitir a consulta de pagamentos. A assinatura do webhook também é testada, sem criar cobrança.</p>
      </div>
      <form method="post" class="host-wallet__connect-form">
        <input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="wallet_replace"><input type="hidden" name="return_page" value="billing">
        <div class="host-wallet__form-grid">
          <label>Gateway de recebimento<select name="provider" required><?php foreach($paymentGateways as $providerCode=>$gateway):?><option value="<?=host_h($providerCode)?>" <?=$activeProvider===$providerCode?'selected':''?> <?=empty($gateway['available'])?'disabled':''?>><?=host_h($gateway['label'])?><?=empty($gateway['available'])?' · em breve':''?></option><?php endforeach;?><option value="" disabled>Outros gateways · em breve</option></select></label>
          <label>Nome da carteira<input name="name" maxlength="120" value="<?=host_h($activeWallet['name']??'')?>" placeholder="Ex.: Recebimentos <?=host_h($context['name'])?>" required></label>
          <label>Ambiente<select name="environment"><option value="production" <?=$activeEnvironment==='production'?'selected':''?>>Produção</option><option value="sandbox" <?=$activeEnvironment==='sandbox'?'selected':''?>>Sandbox</option></select></label>
          <label>Public key<input name="public_key" autocomplete="off" maxlength="255" value="<?=host_h($activeWallet['public_key']??'')?>" placeholder="APP_USR-…" required></label>
          <label>Access Token<input type="password" name="access_token" autocomplete="new-password" placeholder="Será criptografado" required></label>
          <label>Assinatura secreta do webhook<input type="password" name="webhook_secret" autocomplete="new-password" placeholder="Será criptografada" required></label>
          <label>Sua senha do portal<input type="password" name="current_password" autocomplete="current-password" required></label>
        </div>
        <div class="host-wallet__submit"><small>Ao confirmar, os novos pedidos passam a usar esta configuração. Pedidos anteriores preservam o recebedor original.</small><button class="btn primary" type="submit">Validar e <?=$activeWallet?'salvar alterações':'ativar recebimento próprio'?></button></div>
      </form>
    </div>
  </details>
  <?php else:?>
  <article class="card host-wallet__upgrade">
    <div><span class="host-wallet__eyebrow">Plano necessário</span><h2>Independência financeira</h2><p class="subtle">O recebimento próprio e o gerenciamento de carteiras pertencem exclusivamente ao Plano Máximo. O plano atual continua usando a carteira central da FireSpot.</p></div>
    <span class="pill warning">Plano Máximo</span>
  </article>
  <?php endif;?>

  <?php if($wallets):?>
  <article class="card host-wallet__history">
    <div class="host-wallet__section-heading"><div><span class="host-wallet__eyebrow">Auditoria</span><h2>Histórico de carteiras</h2><p class="subtle">Somente uma carteira pode ficar ativa. As anteriores permanecem vinculadas aos pedidos já criados.</p></div><span class="pill"><?=count($wallets)?> registro(s)</span></div>
    <div class="table-wrap"><table><thead><tr><th>Carteira</th><th>Credenciais</th><th>Situação</th><th>Atualização</th><th>Ação</th></tr></thead><tbody>
      <?php foreach($wallets as $wallet):?>
      <tr>
        <td><strong><?=host_h($wallet['name'])?></strong><small><?=host_h($wallet['environment']==='sandbox'?'Sandbox':'Produção')?></small></td>
        <td><span>Token <?=host_h($wallet['credential_hint']?:'mascarado')?></span><small>Webhook <?=host_h($wallet['webhook_secret_hint']?:'pendente')?></small></td>
        <td><span class="pill <?=$wallet['active']?'ok':'off'?>"><?=$wallet['active']?'Ativa':'Histórica'?></span></td>
        <td><?=host_h(host_datetime($wallet['updated_at']))?></td>
        <td><?php if(!$wallet['active']&&$walletValidationReady):?><form method="post" class="host-wallet__restore"><input type="hidden" name="csrf" value="<?=host_h(csrf_token())?>"><input type="hidden" name="action" value="wallet_restore"><input type="hidden" name="return_page" value="billing"><input type="hidden" name="wallet_id" value="<?=(int)$wallet['id']?>"><input type="password" name="current_password" autocomplete="current-password" required placeholder="Sua senha"><button class="btn" type="submit">Revalidar e restaurar</button></form><?php else:?>—<?php endif;?></td>
      </tr>
      <?php endforeach;?>
    </tbody></table></div>
  </article>
  <?php endif;?>
</section>
