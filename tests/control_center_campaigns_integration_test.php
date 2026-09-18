<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/monetization.php';

$pdo=db();
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{
    $checks++;
    if(!$condition)throw new RuntimeException($message);
};

$pdo->beginTransaction();
try{
    $advertiserId=fs_monetization_save_advertiser($pdo,[
        'legal_name'=>'Auditoria transacional '.bin2hex(random_bytes(4)),
        'trade_name'=>'Auditoria FireSpot',
        'active'=>1,
    ]);
    $expect($advertiserId>0,'Anunciante de auditoria não foi criado.');
    $campaignId=fs_monetization_save_campaign($pdo,[
        'advertiser_id'=>$advertiserId,
        'name'=>'Rascunho sem orçamento '.bin2hex(random_bytes(3)),
        'campaign_type'=>'commercial',
        'status'=>'draft',
        'starts_at'=>date('Y-m-d H:i:s',strtotime('+1 day')),
        'ends_at'=>date('Y-m-d H:i:s',strtotime('+8 days')),
        'budget_cents'=>0,
        'frequency_window_hours'=>24,
        'max_views_per_device'=>1,
    ],null);
    $expect($campaignId>0,'Rascunho comercial não foi criado.');
    $query=$pdo->prepare('SELECT status,budget_cents,funded_cents,spent_cents FROM ad_campaigns WHERE id=?');
    $query->execute([$campaignId]);
    $stored=$query->fetch();
    $expect((string)$stored['status']==='draft','Rascunho foi ativado implicitamente.');
    $expect((int)$stored['budget_cents']===0&&(int)$stored['funded_cents']===0&&(int)$stored['spent_cents']===0,'Preparação criativa movimentou valores financeiros.');
    $blocked=false;
    try{
        fs_monetization_save_campaign($pdo,[
            'id'=>$campaignId,'advertiser_id'=>$advertiserId,'name'=>'Inválida',
            'campaign_type'=>'commercial','status'=>'active',
            'starts_at'=>date('Y-m-d H:i:s',strtotime('+1 day')),
            'ends_at'=>date('Y-m-d H:i:s',strtotime('+8 days')),'budget_cents'=>0,
        ],null);
    }catch(InvalidArgumentException $error){$blocked=true;}
    $expect($blocked,'Campanha comercial sem contrato financeiro saiu do rascunho.');
    $pdo->rollBack();
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    throw $error;
}

echo "OK: {$checks} verificações integradas de Campanhas sem movimentação financeira.\n";
