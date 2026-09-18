<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_page();
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/monetization.php';
require_once __DIR__ . '/../../app/ad_monetization.php';
require_once __DIR__ . '/../../app/partner_admin.php';
require_once __DIR__ . '/../../app/settings.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Método não permitido.';
    exit;
}

$section = (string)($_POST['return_section'] ?? 'commercial');
if (!in_array($section, ['commercial', 'offers', 'performance', 'settings'], true)) $section = 'commercial';
$flash = ['ok'=>false,'message'=>'Não foi possível concluir a operação de campanha.'];

try {
    if (!csrf_check($_POST['csrf'] ?? '')) throw new RuntimeException('Sessão expirada. Recarregue a página.');
    admin_require_capability('monetization.manage');
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'advertising_settings_save') {
        $minutes=(int)($_POST['ad_minutes']??5);
        $cooldown=(int)($_POST['ad_cooldown_minutes']??180);
        $dailyCap=(int)($_POST['ad_daily_cap']??0);
        $customDefault=(int)($_POST['custom_ads_default']??15);
        if($minutes<1||$minutes>120)throw new InvalidArgumentException('A cortesia global deve ficar entre 1 e 120 minutos.');
        if($cooldown<0||$cooldown>1440)throw new InvalidArgumentException('O intervalo global deve ficar entre 0 e 1.440 minutos.');
        if($dailyCap<0||$dailyCap>10)throw new InvalidArgumentException('O limite diário deve ficar entre 0 e 10 concessões.');
        if($customDefault<5||$customDefault>180)throw new InvalidArgumentException('A duração padrão do anúncio deve ficar entre 5 e 180 segundos.');
        $pdo->beginTransaction();
        try{
            settings_set('ad_minutes',(string)$minutes);
            settings_set('ad_cooldown_minutes',(string)$cooldown);
            settings_set('ad_daily_cap',(string)$dailyCap);
            settings_set('custom_ads_enabled',!empty($_POST['custom_ads_enabled'])?'1':'0');
            settings_set('custom_ads_default',(string)$customDefault);
            $pdo->commit();
        }catch(Throwable $settingsError){if($pdo->inTransaction())$pdo->rollBack();throw$settingsError;}
        $flash=['ok'=>true,'message'=>'Regras globais de publicidade e cortesia atualizadas.'];
        $section='settings';
    } elseif ($action === 'advertiser_save') {
        fs_monetization_save_advertiser($pdo, $_POST + ['active'=>!empty($_POST['active'])]);
        $flash = ['ok'=>true,'message'=>'Anunciante salvo na área de Campanhas.'];
    } elseif ($action === 'advertiser_toggle') {
        fs_monetization_set_advertiser_active($pdo, max(0,(int)($_POST['id']??0)), !empty($_POST['active']));
        $flash = ['ok'=>true,'message'=>'Situação do anunciante atualizada.'];
    } elseif ($action === 'campaign_save') {
        $id = max(0, (int)($_POST['id'] ?? 0));
        $stored = null;
        if ($id > 0) {
            $query = $pdo->prepare('SELECT * FROM ad_campaigns WHERE id=? LIMIT 1');
            $query->execute([$id]);
            $stored = $query->fetch();
            if (!$stored) throw new RuntimeException('Campanha não encontrada.');
        }
        $campaignId = fs_monetization_save_campaign($pdo, [
            'id'=>$id,
            'advertiser_id'=>$_POST['advertiser_id']??0,
            'name'=>$_POST['name']??'',
            'campaign_type'=>$_POST['campaign_type']??'commercial',
            'status'=>$stored['status']??'draft',
            'starts_at'=>$_POST['starts_at']??'',
            'ends_at'=>$_POST['ends_at']??'',
            // Valores financeiros nunca são aceitos deste formulário. Em uma
            // edição eles são preservados; em um novo rascunho começam em zero.
            'budget_cents'=>$stored['budget_cents']??0,
            'advertiser_view_cpm_cents'=>$stored['advertiser_view_cpm_cents']??0,
            'advertiser_click_cents'=>$stored['advertiser_click_cents']??0,
            'advertiser_lead_cents'=>$stored['advertiser_lead_cents']??0,
            'lead_capture_enabled'=>!empty($_POST['lead_capture_enabled']),
            'offer_message'=>$_POST['offer_message']??'',
            'offer_valid_until'=>$_POST['offer_valid_until']??'',
            'consent_version'=>'offer-v1',
            'frequency_window_hours'=>$_POST['frequency_window_hours']??24,
            'max_views_per_device'=>$_POST['max_views_per_device']??1,
        ], admin_id());
        $flash = ['ok'=>true,'message'=>'Campanha salva como composição operacional. Orçamento e cobrança permanecem no Financeiro.'];
        $section = 'commercial';
    } elseif ($action === 'campaign_status') {
        fs_monetization_set_campaign_status($pdo, max(0,(int)($_POST['id']??0)), (string)($_POST['status']??''));
        $flash = ['ok'=>true,'message'=>'Situação operacional da campanha atualizada.'];
    } elseif ($action === 'campaign_assign') {
        $campaignId = max(0,(int)($_POST['campaign_id']??0));
        $partnerId = max(0,(int)($_POST['partner_id']??0));
        $existing = $pdo->prepare('SELECT partner_view_cpm_cents,partner_click_cents,partner_lead_cents FROM ad_campaign_partners WHERE campaign_id=? AND partner_id=? LIMIT 1');
        $existing->execute([$campaignId,$partnerId]);
        $rates = $existing->fetch() ?: ['partner_view_cpm_cents'=>0,'partner_click_cents'=>0,'partner_lead_cents'=>0];
        $rates['status'] = $_POST['status'] ?? 'active';
        $rates['max_views_per_device'] = $_POST['max_views_per_device'] ?? '';
        fs_monetization_assign_campaign($pdo,$campaignId,$partnerId,$rates);
        $flash = ['ok'=>true,'message'=>'Participação do estabelecimento atualizada sem alterar valores financeiros.'];
    } elseif ($action === 'creative_link') {
        $adId=max(0,(int)($_POST['ad_id']??0));
        $campaignId=max(0,(int)($_POST['campaign_id']??0));
        $query=$pdo->prepare('SELECT campaign_type FROM ad_campaigns WHERE id=? LIMIT 1');
        $query->execute([$campaignId]);
        $campaignType=(string)($query->fetchColumn()?:'');
        if ($campaignType === '') throw new RuntimeException('Campanha não encontrada.');
        $adKind=$campaignType==='commercial'?'commercial':'institutional';
        $update=$pdo->prepare('UPDATE custom_ads SET campaign_id=?,ad_kind=?,lead_capture_enabled=?,offer_message=?,updated_at=NOW() WHERE id=? AND partner_id IS NULL');
        $update->execute([$campaignId,$adKind,!empty($_POST['lead_capture_enabled'])?1:0,substr(trim((string)($_POST['offer_message']??'')),0,500)?:null,$adId]);
        if ($update->rowCount()<1) throw new RuntimeException('Anúncio global não encontrado.');
        $flash=['ok'=>true,'message'=>'Criativo vinculado à campanha.'];
    } elseif ($action === 'offer_retry') {
        $pdo->beginTransaction();
        try {
            $retry=fs_ad_retry_offer($pdo,max(0,(int)($_POST['id']??0)));
            partner_admin_audit($pdo,(int)$retry['partner_id'],'firespot',admin_id(),'ad_offer.retry_queued','ad_lead',(int)$retry['lead_id']);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        $flash=['ok'=>true,'message'=>'Oferta recolocada na fila sem duplicar o lead nem alterar o consentimento.'];
        $section='offers';
    } else {
        throw new InvalidArgumentException('Ação de campanha inválida.');
    }
} catch (Throwable $error) {
    $flash=['ok'=>false,'message'=>admin_public_error($error,'Não foi possível concluir a operação de campanha.')];
}

$_SESSION['campaigns_flash']=$flash;
$query=['section'=>$section];
if (!empty($campaignId)) $query['campaign_id']=(int)$campaignId;
header('Location: ../campanhas.php?' . http_build_query($query),true,303);
exit;
