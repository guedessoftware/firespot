<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/payment_wallets.php';
require_once __DIR__ . '/../app/payment_provider.php';
require_once __DIR__ . '/../app/public_url.php';
require_once __DIR__ . '/../app/control_center_navigation.php';
require_once __DIR__ . '/../app/control_center_finance.php';
require_once __DIR__ . '/components/status-pill.php';

$titulo = 'Carteiras e recebimentos';
$pageId = 'recebimentos';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$schemaReady = fs_payment_schema_ready($pdo);
$section = (string)($_GET['section'] ?? 'wallets');
if (!in_array($section, ['wallets', 'receipts'], true)) $section = 'wallets';
$message = (string)($_SESSION['receipts_flash_message'] ?? '');
unset($_SESSION['receipts_flash_message']);
$error = '';

function receipts_bool(mixed $value): int
{
    return in_array((string)$value, ['1', 'on', 'yes', 'true'], true) ? 1 : 0;
}

/** @return array{label:string,detail:string,tone:string} */
function receipts_access_delivery(array $order): array
{
    return fs_control_center_delivery_state($order);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $schemaReady) {
    try {
        if (!csrf_check($_POST['csrf'] ?? '')) throw new RuntimeException('Sessão expirada. Recarregue a página.');
        admin_require_capability('partner.billing.manage');
        if ((string)($_POST['action'] ?? '') !== 'global_wallet_save') throw new InvalidArgumentException('Ação financeira inválida.');

        $id = max(0, (int)($_POST['id'] ?? 0));
        $name = trim((string)($_POST['name'] ?? ''));
        $environment = (string)($_POST['environment'] ?? 'production');
        $publicKey = trim((string)($_POST['public_key'] ?? ''));
        $accessToken = trim((string)($_POST['access_token'] ?? ''));
        $webhookSecret = trim((string)($_POST['webhook_secret'] ?? ''));
        $active = receipts_bool($_POST['active'] ?? null);
        if ($name === '' || $publicKey === '') throw new InvalidArgumentException('Informe nome e Public Key da carteira.');
        if (!in_array($environment, ['sandbox', 'production'], true)) throw new InvalidArgumentException('Ambiente de pagamento inválido.');
        if ($webhookSecret !== '') fs_payment_webhook_secret_assert($webhookSecret);
        if ($accessToken !== '') {
            fs_payment_validate_access_token([
                'provider'=>'mercadopago', 'environment'=>$environment,
                'public_key'=>$publicKey, 'access_token'=>$accessToken, 'active'=>1,
            ]);
        }

        if ($id > 0) {
            $query = $pdo->prepare('SELECT * FROM payment_wallets WHERE id=? AND partner_id IS NULL LIMIT 1');
            $query->execute([$id]);
            $current = $query->fetch();
            if (!$current) throw new RuntimeException('Carteira global não encontrada.');
            $encrypted = $accessToken !== '' ? fs_encrypt_credential($accessToken) : (string)$current['access_token_encrypted'];
            $hint = $accessToken !== '' ? fs_credential_hint($accessToken) : (string)$current['credential_hint'];
            $webhookEncrypted = $webhookSecret !== '' ? fs_encrypt_credential($webhookSecret) : ($current['webhook_secret_encrypted'] ?? null);
            $webhookHint = $webhookSecret !== '' ? fs_credential_hint($webhookSecret) : ($current['webhook_secret_hint'] ?? null);
            $webhookConfiguredAt = $current['webhook_secret_configured_at'] ?? null;
            if ($webhookSecret !== '' && empty($webhookConfiguredAt)) $webhookConfiguredAt = (string)$pdo->query('SELECT NOW()')->fetchColumn();
            $update = $pdo->prepare('UPDATE payment_wallets SET provider=?,name=?,environment=?,public_key=?,access_token_encrypted=?,credential_hint=?,webhook_secret_encrypted=?,webhook_secret_hint=?,webhook_secret_configured_at=?,active=?,updated_at=NOW() WHERE id=? AND partner_id IS NULL');
            $update->execute(['mercadopago',$name,$environment,$publicKey,$encrypted,$hint,$webhookEncrypted,$webhookHint,$webhookConfiguredAt,$active,$id]);
            $message = 'Carteira global atualizada.';
        } else {
            if ($accessToken === '') throw new InvalidArgumentException('Informe o Access Token da nova carteira global.');
            if ($webhookSecret === '') throw new InvalidArgumentException('Informe a assinatura secreta do webhook.');
            $insert = $pdo->prepare('INSERT INTO payment_wallets (partner_id,provider,name,environment,public_key,access_token_encrypted,credential_hint,webhook_secret_encrypted,webhook_secret_hint,webhook_secret_configured_at,active) VALUES (NULL,?,?,?,?,?,?,?,?,NOW(),?)');
            $insert->execute(['mercadopago',$name,$environment,$publicKey,fs_encrypt_credential($accessToken),fs_credential_hint($accessToken),fs_encrypt_credential($webhookSecret),fs_credential_hint($webhookSecret),$active]);
            $message = 'Carteira global cadastrada com segurança.';
        }
    } catch (Throwable $exception) {
        $error = admin_public_error($exception, 'Não foi possível salvar a carteira global.');
    }

    if ($error === '' && $message !== '') {
        $_SESSION['receipts_flash_message'] = $message;
        header('Location: recebimentos.php?section=wallets', true, 303);
        exit;
    }
}

$wallets = $partners = $recentOrders = [];
$totals = ['wallets'=>0,'global_wallets'=>0,'partner_wallets'=>0,'independent'=>0,'receipts'=>0,'paid_cents'=>0,'manual_review'=>0];
$partnerFilter = max(0, (int)($_GET['partner_id'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$totalRows = 0;
if ($schemaReady) {
    $wallets = $pdo->query('SELECT w.id,w.partner_id,w.provider,w.name,w.environment,w.public_key,w.credential_hint,w.webhook_secret_hint,w.webhook_secret_configured_at,w.active,w.updated_at,p.name partner_name FROM payment_wallets w LEFT JOIN partners p ON p.id=w.partner_id ORDER BY w.partner_id IS NULL DESC,w.active DESC,w.name,w.id')->fetchAll() ?: [];
    $partners = $pdo->query('SELECT id,code,name,active,independent_billing,payment_wallet_id FROM partners ORDER BY name,id')->fetchAll() ?: [];
    foreach ($wallets as $wallet) {
        $totals['wallets']++;
        $totals[(int)($wallet['partner_id'] ?? 0) > 0 ? 'partner_wallets' : 'global_wallets']++;
    }
    foreach ($partners as $partner) if ((int)$partner['independent_billing'] === 1) $totals['independent']++;

    $where = [];
    $params = [];
    if ($partnerFilter > 0) { $where[] = 'g.partner_id=?'; $params[] = $partnerFilter; }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM guest_orders g' . $whereSql);
    $count->execute($params);
    $totalRows = (int)$count->fetchColumn();
    $pageCount = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $pageCount) $page = $pageCount;
    $offset = ($page - 1) * $perPage;
    $ordersSql = 'SELECT g.*,p.name partner_name,w.name wallet_name FROM guest_orders g LEFT JOIN partners p ON p.id=g.partner_id LEFT JOIN payment_wallets w ON w.id=g.wallet_id' . $whereSql . ' ORDER BY g.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $orders = $pdo->prepare($ordersSql);
    $orders->execute($params);
    $recentOrders = $orders->fetchAll() ?: [];

    $summary = $pdo->prepare("SELECT COUNT(*) receipts,SUM(CASE WHEN g.status='paid' THEN g.amount_cents ELSE 0 END) paid_cents,SUM(CASE WHEN g.status='paid' AND g.payment_access_mode='radius_preauth' AND g.radius_coa_status='manual_review' THEN 1 ELSE 0 END) manual_review FROM guest_orders g" . $whereSql);
    $summary->execute($params);
    $orderTotals = $summary->fetch() ?: [];
    foreach (['receipts','paid_cents','manual_review'] as $key) $totals[$key] = (int)($orderTotals[$key] ?? 0);
} else {
    $pageCount = 1;
}

$csrf = csrf_token();
$canonicalWebhookUrl = fs_public_base_url($pdo) . '/portal-v3/api/webhook.php';
ob_start();
?>
<?php if (!$schemaReady): ?>
  <div class="notice-error"><strong>Migração financeira pendente.</strong> Aplique a migração versionada correspondente antes de administrar carteiras.</div>
<?php else: ?>
  <?php if ($message !== ''): ?><div class="notice-ok"><?=htmlspecialchars($message)?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="notice-error"><?=htmlspecialchars($error)?></div><?php endif; ?>

  <section class="fs-workspace-overview" aria-label="Resumo financeiro">
    <div class="fs-workspace-overview__copy"><span>Financeiro</span><strong>Carteiras e recebimentos em uma fronteira financeira única</strong><small>Portal, modelos visuais e planos de acesso são configurados exclusivamente dentro do estabelecimento.</small></div>
    <div class="fs-workspace-stats"><div><strong><?=$totals['wallets']?></strong><span>Carteiras</span></div><div><strong><?=$totals['independent']?></strong><span>Independentes</span></div><div><strong><?=$totals['receipts']?></strong><span>Pedidos</span></div></div>
    <nav class="fs-workspace-links" aria-label="Atalhos desta página"><a href="?section=wallets">Carteiras</a><a href="?section=receipts">Recebimentos</a><a href="integracoes.php?section=payments">Integração Mercado Pago</a></nav>
  </section>

  <?php if ($section === 'wallets'): ?>
    <div class="notice-info"><strong>Webhook Mercado Pago:</strong> URL canônica <code><?=htmlspecialchars($canonicalWebhookUrl)?></code>. A configuração e o diagnóstico da integração ficam em <a href="integracoes.php?section=payments">Sistema &gt; Integrações</a>.</div>
    <section class="fs-cc-toolbar"><div><span class="fs-cc-eyebrow">Carteiras</span><h2>Destinos financeiros</h2><p>Esta visão administra a carteira global e inventaria as carteiras próprias. Credenciais de estabelecimento são alteradas no contexto do proprietário.</p></div></section>
    <div class="receipts-grid" id="carteiras">
      <?php if (admin_has_capability('partner.billing.manage')): ?>
      <section class="card">
        <h2>Nova carteira global</h2><p class="muted">Credenciais são validadas, criptografadas e nunca voltam a ser exibidas.</p>
        <form method="post" class="form-stack" autocomplete="off">
          <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="action" value="global_wallet_save">
          <label>Nome<input name="name" required maxlength="120" placeholder="Ex.: Mercado Pago FireSpot"></label>
          <label>Ambiente<select name="environment"><option value="production">Produção</option><option value="sandbox">Testes</option></select></label>
          <label>Public Key<input name="public_key" required autocomplete="off"></label>
          <label>Access Token<input type="password" name="access_token" required autocomplete="new-password"></label>
          <label>Assinatura secreta do webhook<input type="password" name="webhook_secret" required autocomplete="new-password"></label>
          <label class="inline-check"><input type="checkbox" name="active" value="1" checked> Carteira ativa</label>
          <button class="btn primary" type="submit">Validar e salvar carteira global</button>
        </form>
      </section>
      <?php endif; ?>

      <section class="card">
        <h2>Inventário de carteiras</h2>
        <table class="simple-table"><thead><tr><th>Carteira</th><th>Proprietário</th><th>Status</th></tr></thead><tbody>
        <?php if (!$wallets): ?><tr><td colspan="3">Nenhuma carteira cadastrada.</td></tr><?php endif; ?>
        <?php foreach ($wallets as $wallet): $walletPartnerId=(int)($wallet['partner_id']??0); ?>
          <tr><td><strong><?=htmlspecialchars($wallet['name'])?></strong><br><small><?=htmlspecialchars($wallet['environment'])?> · token <?=htmlspecialchars($wallet['credential_hint']?:'protegido')?> · webhook <?=htmlspecialchars($wallet['webhook_secret_hint']?:'pendente')?></small></td><td><?=$walletPartnerId>0?htmlspecialchars($wallet['partner_name']?:'Estabelecimento removido'):'Global FireSpot'?></td><td><?=fs_cc_status_pill((int)$wallet['active']===1?'Ativa':'Histórica',(int)$wallet['active']===1?'success':'neutral')?><?php if($walletPartnerId>0):?><br><a href="<?=htmlspecialchars(fs_control_center_partner_url($walletPartnerId,'finance'))?>">Abrir estabelecimento</a><?php elseif(admin_has_capability('partner.billing.manage')):?><details class="fs-cc-editor"><summary>Atualizar carteira global</summary><form method="post" class="form-stack" autocomplete="off"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="action" value="global_wallet_save"><input type="hidden" name="id" value="<?=(int)$wallet['id']?>"><label>Nome<input name="name" value="<?=htmlspecialchars($wallet['name'])?>" required></label><label>Ambiente<select name="environment"><option value="production"<?=$wallet['environment']==='production'?' selected':''?>>Produção</option><option value="sandbox"<?=$wallet['environment']==='sandbox'?' selected':''?>>Testes</option></select></label><label>Public Key<input name="public_key" value="<?=htmlspecialchars($wallet['public_key'])?>" required></label><label>Novo Access Token <small>vazio mantém o atual</small><input type="password" name="access_token" autocomplete="new-password"></label><label>Nova assinatura do webhook <small>vazio mantém a atual</small><input type="password" name="webhook_secret" autocomplete="new-password"></label><label class="inline-check"><input type="checkbox" name="active" value="1"<?=(int)$wallet['active']===1?' checked':''?>> Carteira ativa</label><button class="btn" type="submit">Atualizar carteira global</button></form></details><?php endif;?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </section>
    </div>
  <?php else: ?>
    <section class="fs-cc-toolbar"><div><span class="fs-cc-eyebrow">Recebimentos</span><h2>Operação financeira por estabelecimento</h2><p>A visão global consolida pagamentos sem transformar os pontos do estabelecimento em uma área global.</p></div><a class="btn primary" href="vendas.php">Análise completa de vendas</a></section>
    <section class="card">
      <form method="get" class="inline-form"><input type="hidden" name="section" value="receipts"><label>Estabelecimento<select name="partner_id"><option value="0">Todos</option><?php foreach($partners as $partner):?><option value="<?=(int)$partner['id']?>"<?=$partnerFilter===(int)$partner['id']?' selected':''?>><?=htmlspecialchars($partner['name'])?></option><?php endforeach;?></select></label><button class="btn" type="submit">Filtrar</button></form>
      <div class="fs-workspace-stats" aria-label="Totais filtrados"><div><strong><?=$totals['receipts']?></strong><span>Pedidos</span></div><div><strong>R$ <?=number_format($totals['paid_cents']/100,2,',','.')?></strong><span>Pagamentos concluídos</span></div><div><strong><?=$totals['manual_review']?></strong><span>Revisões manuais</span></div></div>
    </section>
    <section class="card subsection" id="recebimentos">
      <h2>Recebimentos recentes</h2>
      <table class="simple-table"><thead><tr><th>Data</th><th>Estabelecimento</th><th>Plano de acesso</th><th>Recebedor</th><th>Pagamento</th><th>Status</th><th>Entrega</th></tr></thead><tbody>
      <?php if(!$recentOrders):?><tr><td colspan="7">Nenhum recebimento encontrado.</td></tr><?php endif;?>
      <?php foreach($recentOrders as $order):$delivery=receipts_access_delivery($order);?><tr><td><?=htmlspecialchars((string)$order['created_at'])?></td><td><a href="<?=htmlspecialchars(fs_control_center_partner_url((int)$order['partner_id'],'finance'))?>"><?=htmlspecialchars($order['partner_name']?:'Não identificado')?></a></td><td><?=htmlspecialchars((string)$order['plan_name'])?><br><small><?=(int)$order['duration_minutes']?> min</small></td><td><?=htmlspecialchars($order['wallet_name']?:'Carteira global')?></td><td>R$ <?=number_format((int)$order['amount_cents']/100,2,',','.')?><br><small><?=htmlspecialchars($order['payment_method']?:'-')?></small></td><td><?=fs_cc_status_pill((string)$order['status'],(string)$order['status']==='paid'?'success':((string)$order['status']==='pending'?'warning':'danger'))?></td><td><?=fs_cc_status_pill($delivery['label'],$delivery['tone'])?><br><small>Pedido #<?=(int)$order['id']?> · <?=htmlspecialchars($delivery['detail'])?></small></td></tr><?php endforeach;?>
      </tbody></table>
      <?php if($pageCount>1):?><nav class="fs-cc-pagination" aria-label="Paginação"><span>Página <?=$page?> de <?=$pageCount?></span><?php if($page>1):?><a class="btn" href="?<?=http_build_query(['section'=>'receipts','partner_id'=>$partnerFilter,'page'=>$page-1])?>">Anterior</a><?php endif;?><?php if($page<$pageCount):?><a class="btn" href="?<?=http_build_query(['section'=>'receipts','partner_id'=>$partnerFilter,'page'=>$page+1])?>">Próxima</a><?php endif;?></nav><?php endif;?>
    </section>
  <?php endif; ?>
<?php endif; ?>
<?php
$conteudo = (string)ob_get_clean();
require __DIR__ . '/layout.php';
