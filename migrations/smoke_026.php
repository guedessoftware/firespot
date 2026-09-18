<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/schema_guard.php';

$pdo = db();
foreach ([
    'partner_monetization_agreements' => ['partner_id','model','access_fee_type','access_fee_value','status'],
    'marketplace_accounts' => ['partner_id','access_token_encrypted','refresh_token_encrypted','status'],
    'advertisers' => ['legal_name','active'],
    'ad_campaigns' => ['budget_cents','funded_cents','reserved_cents','spent_cents','advertiser_view_remainder_millis','lead_capture_enabled'],
    'ad_campaign_partners' => ['campaign_id','partner_id','partner_view_cpm_cents','partner_view_remainder_millis','partner_lead_cents'],
    'ad_deliveries' => ['public_id','token_hash','device_hash','access_username','qualified_view_at','qualified_click_at','qualified_lead_at'],
    'ad_leads' => ['delivery_id','phone_encrypted','phone_hash','consent_text_hash','message_status'],
    'monetization_ledger' => ['event_key','amount_cents','status','settlement_id'],
    'partner_settlements' => ['period_start','period_end','total_cents','status'],
    'monetization_orders' => ['order_type','external_ref','amount_cents','status'],
] as $table=>$columns) runtime_schema_require($pdo,$table,$columns);

runtime_schema_require($pdo,'custom_ads',['campaign_id','ad_kind','lead_capture_enabled','offer_message']);
runtime_schema_require($pdo,'promo_queue',['purpose','reference_type','reference_id','idempotency_key','max_attempts','payload_encrypted']);
runtime_schema_require($pdo,'marketplace_oauth_states',['state_hash','redirect_uri','code_verifier_encrypted','expires_at']);
runtime_schema_require($pdo,'ad_pending_offers',['delivery_id']);
runtime_schema_require($pdo,'guest_orders',['marketplace_account_id','monetization_agreement_id','provider_fee_cents','firespot_fee_cents','partner_net_cents']);

$crossCampaign = (int)$pdo->query("SELECT COUNT(*) FROM custom_ads WHERE campaign_id IS NOT NULL AND ad_kind<>'commercial'")->fetchColumn();
if ($crossCampaign !== 0) throw new RuntimeException('Existem criativos comerciais sem classificação comercial.');
$negativeCampaign = (int)$pdo->query('SELECT COUNT(*) FROM ad_campaigns WHERE reserved_cents+spent_cents>funded_cents')->fetchColumn();
if ($negativeCampaign !== 0) throw new RuntimeException('Existe campanha consumindo mais que o valor financiado.');

echo "Smoke 026 concluído. Estrutura de monetização consistente.\n";
