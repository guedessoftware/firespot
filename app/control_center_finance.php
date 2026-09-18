<?php

declare(strict_types=1);

/** @return array{label:string,detail:string,tone:string,group:string} */
function fs_control_center_delivery_state(array $order): array
{
    if ((string)($order['status'] ?? '') !== 'paid') return ['label'=>'Não aplicável','detail'=>'Pagamento não concluído','tone'=>'neutral','group'=>'other'];
    if ((string)($order['payment_access_mode'] ?? '') !== 'radius_preauth') return ['label'=>'Fluxo tradicional','detail'=>'Entrega sem promoção CoA','tone'=>'success','group'=>'delivered'];
    $status=(string)($order['radius_coa_status'] ?? 'pending');
    if($status==='applied')return ['label'=>'Internet ativada','detail'=>'CoA confirmado','tone'=>'success','group'=>'delivered'];
    if($status==='manual_review'){
        $code=(string)($order['radius_coa_error_code']??'');
        $detail=str_contains($code,'SESSION_NOT_FOUND')?'Sessão não localizada':(str_contains($code,'AMBIGUOUS')?'Sessão ambígua':'Revisão técnica necessária');
        return ['label'=>'Revisão manual','detail'=>$detail,'tone'=>'danger','group'=>'manual_review'];
    }
    if($status==='waiting_session')return ['label'=>'Aguardando reconexão','detail'=>'Crédito preservado','tone'=>'warning','group'=>'pending'];
    return ['label'=>'Processando ativação','detail'=>'Retentativa controlada','tone'=>'warning','group'=>'pending'];
}

/** @return array{partner_id:int,state:string,page:int,per_page:int} */
function fs_control_center_delivery_filters(array $input): array
{
    $state=(string)($input['state']??'all');
    if(!in_array($state,['all','delivered','pending','manual_review'],true))$state='all';
    return ['partner_id'=>max(0,(int)($input['partner_id']??0)),'state'=>$state,'page'=>max(1,(int)($input['page']??1)),'per_page'=>max(10,min(100,(int)($input['per_page']??50)))];
}

/** @return array<string,mixed> */
function fs_control_center_deliveries(PDO $pdo, array $input=[]): array
{
    $filters=fs_control_center_delivery_filters($input);$where=["g.status='paid'"];$params=[];
    if($filters['partner_id']>0){$where[]='g.partner_id=?';$params[]=$filters['partner_id'];}
    if($filters['state']==='delivered')$where[]="(COALESCE(g.payment_access_mode,'')<>'radius_preauth' OR g.radius_coa_status='applied')";
    elseif($filters['state']==='manual_review')$where[]="g.payment_access_mode='radius_preauth' AND g.radius_coa_status='manual_review'";
    elseif($filters['state']==='pending')$where[]="g.payment_access_mode='radius_preauth' AND COALESCE(g.radius_coa_status,'pending') NOT IN ('applied','manual_review')";
    $whereSql=' WHERE '.implode(' AND ',$where);
    $count=$pdo->prepare('SELECT COUNT(*) FROM guest_orders g'.$whereSql);$count->execute($params);$total=(int)$count->fetchColumn();
    $pages=max(1,(int)ceil($total/$filters['per_page']));$filters['page']=min($filters['page'],$pages);$offset=($filters['page']-1)*$filters['per_page'];
    $statement=$pdo->prepare('SELECT g.id,g.partner_id,g.status,g.plan_name,g.amount_cents,g.payment_method,g.payment_access_mode,g.radius_phase,g.radius_coa_status,g.radius_coa_attempts,g.radius_coa_last_attempt_at,g.radius_coa_error_code,g.paid_at,g.created_at,p.name partner_name,w.name wallet_name FROM guest_orders g LEFT JOIN partners p ON p.id=g.partner_id LEFT JOIN payment_wallets w ON w.id=g.wallet_id'.$whereSql.' ORDER BY g.id DESC LIMIT '.(int)$filters['per_page'].' OFFSET '.(int)$offset);
    $statement->execute($params);$rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($rows as &$row)$row['delivery']=fs_control_center_delivery_state($row);unset($row);
    $kpis=$pdo->query("SELECT COUNT(*) paid,
        SUM(CASE WHEN COALESCE(payment_access_mode,'')<>'radius_preauth' OR radius_coa_status='applied' THEN 1 ELSE 0 END) delivered,
        SUM(CASE WHEN payment_access_mode='radius_preauth' AND radius_coa_status='manual_review' THEN 1 ELSE 0 END) manual_review,
        SUM(CASE WHEN payment_access_mode='radius_preauth' AND COALESCE(radius_coa_status,'pending') NOT IN ('applied','manual_review') THEN 1 ELSE 0 END) pending
        FROM guest_orders WHERE status='paid'")->fetch(PDO::FETCH_ASSOC)?:[];
    foreach(['paid','delivered','manual_review','pending'] as $key)$kpis[$key]=(int)($kpis[$key]??0);
    $partners=$pdo->query('SELECT id,name FROM partners ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC)?:[];
    return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$filters['page'],'filters'=>$filters,'kpis'=>$kpis,'partners'=>$partners];
}
