<?php

declare(strict_types=1);

require_once __DIR__.'/partner_entitlements.php';

/** @return array{q:string,status:string,plan:string,page:int,per_page:int} */
function fs_control_center_subscription_filters(array $input): array
{
    $q=preg_replace('/\s+/u',' ',trim((string)($input['q']??'')))?:'';
    $q=function_exists('mb_substr')?mb_substr($q,0,100,'UTF-8'):substr($q,0,100);
    $status=(string)($input['status']??'all');if(!in_array($status,['all','trial','active','grace','past_due','suspended','ended'],true))$status='all';
    $plan=preg_match('/^[a-z0-9_]{2,64}$/',(string)($input['plan']??''))?(string)$input['plan']:'all';
    return ['q'=>$q,'status'=>$status,'plan'=>$plan,'page'=>max(1,(int)($input['page']??1)),'per_page'=>max(10,min(100,(int)($input['per_page']??25)))];
}

function fs_control_center_subscription_from_sql(): string
{
    return " FROM partners p
      LEFT JOIN partner_subscriptions s ON s.partner_id=p.id AND s.is_current=1
      LEFT JOIN platform_plans pp ON pp.id=s.plan_id
      LEFT JOIN (SELECT partner_id,COUNT(*) used_hotspots FROM partner_hotspots WHERE management_state<>'retired' GROUP BY partner_id) hu ON hu.partner_id=p.id
      LEFT JOIN (SELECT partner_id,COUNT(*) used_nas FROM partner_nas_ownerships WHERE status<>'retired' GROUP BY partner_id) nu ON nu.partner_id=p.id
      LEFT JOIN (SELECT m.partner_id,COUNT(*) used_admins FROM partner_admin_memberships m JOIN host_users u ON u.id=m.user_id AND u.active=1 WHERE m.active=1 GROUP BY m.partner_id) au ON au.partner_id=p.id
      LEFT JOIN (SELECT partner_id,COUNT(DISTINCT hotspot_id) used_overrides FROM courtesy_hotspot_policy_overrides WHERE state IN ('draft','published') GROUP BY partner_id) cu ON cu.partner_id=p.id";
}

/** @return array<string,mixed> */
function fs_control_center_subscriptions(PDO $pdo, array $input=[]): array
{
    $filters=fs_control_center_subscription_filters($input);$where=[];$params=[];
    if($filters['q']!==''){$like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$filters['q']).'%';$where[]='(p.name LIKE ? OR p.code LIKE ?)';array_push($params,$like,$like);}
    if($filters['status']!=='all'){$where[]='s.status=?';$params[]=$filters['status'];}
    if($filters['plan']!=='all'){$where[]='pp.code=?';$params[]=$filters['plan'];}
    $whereSql=$where?' WHERE '.implode(' AND ',$where):'';$from=fs_control_center_subscription_from_sql();
    $count=$pdo->prepare('SELECT COUNT(*)'.$from.$whereSql);$count->execute($params);$total=(int)$count->fetchColumn();
    $pages=max(1,(int)ceil($total/$filters['per_page']));$filters['page']=min($filters['page'],$pages);$offset=($filters['page']-1)*$filters['per_page'];
    $sql="SELECT p.id,p.code,p.name,p.active,s.status,s.starts_at,s.grace_until,pp.code plan_code,pp.name plan_name,pp.version plan_version,
      COALESCE(pp.max_hotspots,0) max_hotspots,COALESCE(pp.max_nas,0) max_nas,COALESCE(pp.max_admin_users,0) max_admin_users,
      COALESCE(pp.max_report_range_days,0) max_report_range_days,COALESCE(pp.custom_courtesy_overrides,0) custom_courtesy_overrides,
      COALESCE(hu.used_hotspots,0) used_hotspots,COALESCE(nu.used_nas,0) used_nas,COALESCE(au.used_admins,0) used_admins,COALESCE(cu.used_overrides,0) used_overrides".$from.$whereSql.' ORDER BY p.active DESC,p.name,p.id LIMIT '.(int)$filters['per_page'].' OFFSET '.(int)$offset;
    $statement=$pdo->prepare($sql);$statement->execute($params);$rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($rows as &$row){
        foreach(['id','active','plan_version','max_hotspots','max_nas','max_admin_users','max_report_range_days','custom_courtesy_overrides','used_hotspots','used_nas','used_admins','used_overrides'] as $field)$row[$field]=(int)($row[$field]??0);
        $row['quota_alert']=($row['max_hotspots']>0&&$row['used_hotspots']*100>=$row['max_hotspots']*80)
            ||($row['max_admin_users']>0&&$row['used_admins']*100>=$row['max_admin_users']*80)
            ||($row['custom_courtesy_overrides']>0&&$row['used_overrides']*100>=$row['custom_courtesy_overrides']*80);
    }unset($row);
    return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$filters['page'],'filters'=>$filters];
}

/** @return array<string,int> */
function fs_control_center_subscription_kpis(PDO $pdo): array
{
    $row=$pdo->query("SELECT COUNT(*) total,SUM(CASE WHEN s.status IN ('trial','active') THEN 1 ELSE 0 END) healthy,SUM(CASE WHEN s.status IN ('grace','past_due') THEN 1 ELSE 0 END) attention,SUM(CASE WHEN s.status IN ('suspended','ended') OR s.id IS NULL THEN 1 ELSE 0 END) restricted,SUM(CASE WHEN pp.code='multipoint_advanced' THEN 1 ELSE 0 END) multipoint FROM partners p LEFT JOIN partner_subscriptions s ON s.partner_id=p.id AND s.is_current=1 LEFT JOIN platform_plans pp ON pp.id=s.plan_id")->fetch(PDO::FETCH_ASSOC)?:[];
    foreach(['total','healthy','attention','restricted','multipoint'] as $field)$row[$field]=(int)($row[$field]??0);return $row;
}

/** @return list<array<string,mixed>> */
function fs_control_center_platform_plan_versions(PDO $pdo): array
{
    $plans=$pdo->query('SELECT p.*,(p.version=(SELECT MAX(x.version) FROM platform_plans x WHERE x.code=p.code)) is_latest,(SELECT COUNT(*) FROM partner_subscriptions s WHERE s.plan_id=p.id AND s.is_current=1) current_subscriptions FROM platform_plans p ORDER BY p.internal_only,p.code,p.version DESC')->fetchAll(PDO::FETCH_ASSOC)?:[];
    $features=$pdo->query('SELECT plan_id,feature_code,enabled FROM platform_plan_features ORDER BY plan_id,feature_code')->fetchAll(PDO::FETCH_ASSOC)?:[];$byPlan=[];
    foreach($features as $feature)$byPlan[(int)$feature['plan_id']][]=$feature;
    foreach($plans as &$plan){$plan['features']=$byPlan[(int)$plan['id']]??[];}unset($plan);return $plans;
}

/** @return array<string,int> */
function fs_control_center_access_plan_kpis(PDO $pdo): array
{
    $row=$pdo->query('SELECT COUNT(*) total,SUM(CASE WHEN ativo=1 THEN 1 ELSE 0 END) active,SUM(CASE WHEN ativo=0 THEN 1 ELSE 0 END) inactive FROM planos')->fetch(PDO::FETCH_ASSOC)?:[];
    foreach(['total','active','inactive'] as $field)$row[$field]=(int)($row[$field]??0);return $row;
}

/** @return array{q:string,status:string,page:int,per_page:int} */
function fs_control_center_partner_access_plan_filters(array $input): array
{
    $q=preg_replace('/\s+/u',' ',trim((string)($input['q']??'')))?:'';
    $q=function_exists('mb_substr')?mb_substr($q,0,100,'UTF-8'):substr($q,0,100);
    $status=(string)($input['status']??'all');
    if(!in_array($status,['all','active','inactive'],true))$status='all';
    return ['q'=>$q,'status'=>$status,'page'=>max(1,(int)($input['page']??1)),'per_page'=>max(10,min(100,(int)($input['per_page']??25)))];
}

/** @return array<string,mixed> */
function fs_control_center_partner_access_plans(PDO $pdo, array $input=[]): array
{
    $filters=fs_control_center_partner_access_plan_filters($input);$where=[];$params=[];
    if($filters['q']!==''){$like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$filters['q']).'%';$where[]='(p.name LIKE ? OR p.code LIKE ? OR ap.name LIKE ?)';array_push($params,$like,$like,$like);}
    if($filters['status']==='active')$where[]='ap.active=1';elseif($filters['status']==='inactive')$where[]='ap.active=0';
    $whereSql=$where?' WHERE '.implode(' AND ',$where):'';
    $from=' FROM partner_payment_plans ap JOIN partners p ON p.id=ap.partner_id';
    $count=$pdo->prepare('SELECT COUNT(*)'.$from.$whereSql);$count->execute($params);$total=(int)$count->fetchColumn();
    $pages=max(1,(int)ceil($total/$filters['per_page']));$filters['page']=min($filters['page'],$pages);$offset=($filters['page']-1)*$filters['per_page'];
    $statement=$pdo->prepare('SELECT ap.id,ap.partner_id,ap.name,ap.description,ap.price_cents,ap.duration_minutes,ap.download_kbps,ap.upload_kbps,ap.active,ap.sort_order,p.code partner_code,p.name partner_name,p.active partner_active'.$from.$whereSql.' ORDER BY p.name,p.id,ap.active DESC,ap.sort_order,ap.id LIMIT '.(int)$filters['per_page'].' OFFSET '.(int)$offset);
    $statement->execute($params);
    $kpis=$pdo->query('SELECT COUNT(*) total,SUM(CASE WHEN ap.active=1 THEN 1 ELSE 0 END) active,COUNT(DISTINCT ap.partner_id) partners FROM partner_payment_plans ap')->fetch(PDO::FETCH_ASSOC)?:[];
    foreach(['total','active','partners'] as $key)$kpis[$key]=(int)($kpis[$key]??0);
    return ['rows'=>$statement->fetchAll(PDO::FETCH_ASSOC)?:[],'total'=>$total,'pages'=>$pages,'page'=>$filters['page'],'filters'=>$filters,'kpis'=>$kpis];
}
