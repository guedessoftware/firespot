<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/public_url.php';
require_once __DIR__ . '/partner_hotspots.php';

function partner_ads_http_url(?string $url, bool $required = false): ?string
{
    $url = trim((string)$url);
    if ($url === '') {
        if ($required) throw new InvalidArgumentException('Informe uma URL válida.');
        return null;
    }
    if (strlen($url) > 500) throw new InvalidArgumentException('A URL informada é muito longa.');
    if (!filter_var($url,FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Informe uma URL válida.');
    $scheme = strtolower((string)parse_url($url,PHP_URL_SCHEME));
    if (!in_array($scheme,['http','https'],true)) throw new InvalidArgumentException('Somente links HTTP ou HTTPS são permitidos.');
    return $url;
}

function partner_ads_local_upload_file(?string $url): ?string
{
    $url = trim((string)$url);
    if (!preg_match('~^/assets/ads/((?:partner_[1-9][0-9]*|global)_[a-f0-9]{20}\.(?:jpg|png|webp|mp4))$~i',$url,$match)) return null;
    return dirname(__DIR__) . '/assets/ads/' . $match[1];
}

function partner_ads_delete_upload_if_unreferenced(PDO $pdo, ?string $url): void
{
    $file = partner_ads_local_upload_file($url);
    if ($file === null || !is_file($file)) return;
    $st = $pdo->prepare('SELECT COUNT(*) FROM custom_ads WHERE image_url=? OR media_url=? OR poster_url=?');
    $st->execute([(string)$url,(string)$url,(string)$url]);
    if ((int)$st->fetchColumn() === 0) @unlink($file);
}

function partner_ads_valid_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Informe um período de campanha válido.');
    }
    return $value;
}

function partner_ads_store_upload(int $partnerId, array $upload): ?array
{
    $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return null;
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível receber a mídia.');
    $size = (int)($upload['size'] ?? 0);
    if ($size <= 0 || $size > 30*1024*1024) throw new RuntimeException('A mídia deve ter no máximo 30 MB.');
    $temporary = (string)($upload['tmp_name'] ?? '');
    if ($temporary === '' || !is_uploaded_file($temporary)) throw new RuntimeException('Upload de mídia inválido.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $types = [
        'image/jpeg'=>['extension'=>'jpg','type'=>'image'],
        'image/png'=>['extension'=>'png','type'=>'image'],
        'image/webp'=>['extension'=>'webp','type'=>'image'],
        'video/mp4'=>['extension'=>'mp4','type'=>'video'],
    ];
    if (!isset($types[$mime])) throw new RuntimeException('Use uma imagem JPG, PNG ou WEBP, ou um vídeo MP4.');
    if ($types[$mime]['type'] === 'image') {
        if ($size > 5*1024*1024) throw new RuntimeException('A imagem deve ter no máximo 5 MB.');
        $dimensions = @getimagesize($temporary);
        if (!$dimensions || (int)$dimensions[0] < 320 || (int)$dimensions[1] < 180 || (int)$dimensions[0] > 5000 || (int)$dimensions[1] > 5000) {
            throw new RuntimeException('A imagem deve ter entre 320 × 180 e 5000 × 5000 pixels.');
        }
    }
    $directory = dirname(__DIR__) . '/assets/ads';
    if (!is_dir($directory) || !is_writable($directory)) throw new RuntimeException('A pasta de anúncios não está disponível.');
    $scope = $partnerId > 0 ? 'partner_' . $partnerId : 'global';
    $name = $scope . '_' . bin2hex(random_bytes(10)) . '.' . $types[$mime]['extension'];
    if (!move_uploaded_file($temporary,$directory . '/' . $name)) throw new RuntimeException('Não foi possível salvar a mídia.');
    @chmod($directory . '/' . $name,0644);
    return ['url'=>'/assets/ads/' . $name,'type'=>$types[$mime]['type']];
}

function partner_ads_media_type(string $type): string
{
    return $type === 'video' ? 'video' : 'image';
}

function partner_ads_fit_mode(string $fit): string
{
    return $fit === 'cover' ? 'cover' : 'contain';
}

function partner_ads_button_text($value, string $default): string
{
    $text = trim((string)$value);
    if ($text === '') $text = $default;
    $normalized = preg_replace('/\s+/u',' ',$text);
    if (!is_string($normalized)) throw new InvalidArgumentException('O texto do botão contém caracteres inválidos.');
    $length = function_exists('mb_strlen') ? mb_strlen($normalized,'UTF-8') : strlen($normalized);
    if ($length < 2 || $length > 60) throw new InvalidArgumentException('O texto do botão deve ter entre 2 e 60 caracteres.');
    return $normalized;
}

function partner_ads_media_url(array $ad): string
{
    return trim((string)($ad['media_url'] ?? $ad['image_url'] ?? ''));
}

function partner_ads_owned(PDO $pdo, int $partnerId, int $adId): ?array
{
    $st = $pdo->prepare('SELECT * FROM custom_ads WHERE id=? AND partner_id=? LIMIT 1');
    $st->execute([$adId,$partnerId]);
    $ad = $st->fetch(PDO::FETCH_ASSOC);
    return $ad ?: null;
}

function partner_ads_for_partner(PDO $pdo, int $partnerId): array
{
    $st = $pdo->prepare('SELECT a.*,
          SUM(e.event=\'impression\') impressions,SUM(e.event=\'interest_yes\') interactions,
          SUM(e.event=\'destination_open\') destination_opens,
          MAX(e.created_at) last_event_at
        FROM custom_ads a LEFT JOIN custom_ads_events e ON e.ad_id=a.id AND e.partner_id=?
        WHERE a.partner_id=? GROUP BY a.id ORDER BY a.id DESC');
    $st->execute([$partnerId,$partnerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function partner_ads_monetization_ready(PDO $pdo): bool
{
    try {
        return (bool)$pdo->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='custom_ads' AND COLUMN_NAME='ad_kind'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function partner_ads_save(PDO $pdo, int $partnerId, array $data, array $files): array
{
    $id = (int)($data['id'] ?? 0);
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') throw new InvalidArgumentException('Informe o título do anúncio.');
    if ((function_exists('mb_strlen') ? mb_strlen($title,'UTF-8') : strlen($title)) > 200) throw new InvalidArgumentException('O título deve ter no máximo 200 caracteres.');
    $current = $id > 0 ? partner_ads_owned($pdo,$partnerId,$id) : null;
    if ($id > 0 && !$current) throw new RuntimeException('Anúncio não encontrado.');
    $link = partner_ads_http_url($data['link_url'] ?? null,false);
    $remoteMedia = partner_ads_http_url($data['media_url'] ?? $data['image_url'] ?? null,false);
    $poster = partner_ads_http_url($data['poster_url'] ?? null,false);
    $requestedType = partner_ads_media_type((string)($data['media_type'] ?? 'image'));
    $fitMode = partner_ads_fit_mode((string)($data['fit_mode'] ?? 'contain'));
    $interestButtonText = partner_ads_button_text($data['interest_button_text'] ?? ($current['interest_button_text'] ?? null),'Tenho interesse');
    $skipButtonText = partner_ads_button_text($data['skip_button_text'] ?? ($current['skip_button_text'] ?? null),'Pular e conectar');
    $leadCapture = !empty($data['lead_capture_enabled']) ? 1 : 0;
    $offerMessage = trim((string)($data['offer_message'] ?? ($current['offer_message'] ?? '')));
    if ((function_exists('mb_strlen') ? mb_strlen($offerMessage,'UTF-8') : strlen($offerMessage)) > 500) throw new InvalidArgumentException('A mensagem da oferta deve ter no máximo 500 caracteres.');
    if ($leadCapture && $offerMessage === '') throw new InvalidArgumentException('Informe a mensagem que será enviada ao interessado.');
    $start = partner_ads_valid_date($data['start_date'] ?? null);
    $end = partner_ads_valid_date($data['end_date'] ?? null);
    if ($start !== null && $end !== null && $end < $start) throw new InvalidArgumentException('A data final deve ser posterior à inicial.');
    $active = !empty($data['active']) ? 1 : 0;
    $durationInput = array_key_exists('duration_sec',$data) ? (int)$data['duration_sec'] : (int)settings_get('custom_ads_default',env('CUSTOM_ADS_DEFAULT',15));
    $duration = max(5,min(180,$durationInput));
    $uploaded = partner_ads_store_upload($partnerId,$files['media_file'] ?? $files['image_file'] ?? []);
    $media = (string)($uploaded['url'] ?? $remoteMedia ?? ($current['media_url'] ?? $current['image_url'] ?? ''));
    $mediaType = (string)($uploaded['type'] ?? ($remoteMedia !== null ? $requestedType : ($current['media_type'] ?? $requestedType)));
    $mediaType = partner_ads_media_type($mediaType);
    if ($media === '') throw new InvalidArgumentException('Envie uma imagem/vídeo ou informe a URL da mídia.');
    if ($mediaType === 'video' && !preg_match('~\.mp4(?:$|[?#])~i',$media) && $uploaded === null) {
        throw new InvalidArgumentException('Para vídeo remoto, informe uma URL de arquivo MP4.');
    }
    $compatImage = $poster ?: $media;

    try {
        $monetizationReady=partner_ads_monetization_ready($pdo);
        if ($id > 0 && $monetizationReady) {
            $st = $pdo->prepare('UPDATE custom_ads SET title=?,media_type=?,media_url=?,poster_url=?,fit_mode=?,interest_button_text=?,skip_button_text=?,lead_capture_enabled=?,offer_message=?,image_url=?,link_url=?,duration_sec=?,active=?,start_date=?,end_date=?,updated_at=NOW() WHERE id=? AND partner_id=?');
            $st->execute([$title,$mediaType,$media,$poster,$fitMode,$interestButtonText,$skipButtonText,$leadCapture,$offerMessage?:null,$compatImage,$link,$duration,$active,$start,$end,$id,$partnerId]);
        } elseif($id > 0) {
            $st = $pdo->prepare('UPDATE custom_ads SET title=?,media_type=?,media_url=?,poster_url=?,fit_mode=?,interest_button_text=?,skip_button_text=?,image_url=?,link_url=?,duration_sec=?,active=?,start_date=?,end_date=?,updated_at=NOW() WHERE id=? AND partner_id=?');
            $st->execute([$title,$mediaType,$media,$poster,$fitMode,$interestButtonText,$skipButtonText,$compatImage,$link,$duration,$active,$start,$end,$id,$partnerId]);
        } elseif($monetizationReady) {
            $st = $pdo->prepare("INSERT INTO custom_ads (title,media_type,media_url,poster_url,fit_mode,interest_button_text,skip_button_text,lead_capture_enabled,offer_message,image_url,link_url,duration_sec,weight,active,partner_code,partner_id,ad_kind,start_date,end_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,NULL,?,'owned',?,?)");
            $st->execute([$title,$mediaType,$media,$poster,$fitMode,$interestButtonText,$skipButtonText,$leadCapture,$offerMessage?:null,$compatImage,$link,$duration,$active,$partnerId,$start,$end]);
            $id = (int)$pdo->lastInsertId();
        } else {
            $st = $pdo->prepare('INSERT INTO custom_ads (title,media_type,media_url,poster_url,fit_mode,interest_button_text,skip_button_text,image_url,link_url,duration_sec,weight,active,partner_code,partner_id,start_date,end_date) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,NULL,?,?,?)');
            $st->execute([$title,$mediaType,$media,$poster,$fitMode,$interestButtonText,$skipButtonText,$compatImage,$link,$duration,$active,$partnerId,$start,$end]);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        if ($uploaded !== null) partner_ads_delete_upload_if_unreferenced($pdo,(string)$uploaded['url']);
        throw $e;
    }
    $oldMedia = (string)($current['media_url'] ?? $current['image_url'] ?? '');
    if ($oldMedia !== '' && $oldMedia !== $media) partner_ads_delete_upload_if_unreferenced($pdo,$oldMedia);
    return ['id'=>$id,'created'=>$current === null,'active'=>$active];
}

function partner_ads_toggle(PDO $pdo, int $partnerId, int $adId): int
{
    $ad = partner_ads_owned($pdo,$partnerId,$adId);
    if (!$ad) throw new RuntimeException('Anúncio não encontrado.');
    $active = (int)$ad['active'] === 1 ? 0 : 1;
    $pdo->prepare('UPDATE custom_ads SET active=?,updated_at=NOW() WHERE id=? AND partner_id=?')->execute([$active,$adId,$partnerId]);
    return $active;
}

function partner_ads_eligible(PDO $pdo, array $partner): array
{
    if ((int)($partner['ads_enabled'] ?? 0) !== 1) return [];
    $base = "active=1 AND (start_date IS NULL OR start_date<=CURRENT_DATE) AND (end_date IS NULL OR end_date>=CURRENT_DATE)";
    $extra = partner_ads_monetization_ready($pdo) ? ',campaign_id,ad_kind,lead_capture_enabled,offer_message' : ",NULL campaign_id,'owned' ad_kind,0 lead_capture_enabled,NULL offer_message";
    $commercial = partner_ads_monetization_ready($pdo) ? " AND (campaign_id IS NULL OR EXISTS (SELECT 1 FROM ad_campaigns c JOIN ad_campaign_partners cp ON cp.campaign_id=c.id WHERE c.id=custom_ads.campaign_id AND cp.partner_id=? AND cp.status='active' AND c.status='active' AND c.starts_at<=NOW() AND c.ends_at>NOW() AND (c.campaign_type='institutional' OR (c.campaign_type='commercial' AND c.funded_cents>c.spent_cents AND EXISTS (SELECT 1 FROM partner_monetization_agreements ma WHERE ma.partner_id=cp.partner_id AND ma.status='active' AND ma.advertising_enabled=1 AND ma.starts_at<=NOW() AND (ma.ends_at IS NULL OR ma.ends_at>NOW()))))))" : '';
    $st = $pdo->prepare("SELECT id,partner_id,title,media_type,media_url,poster_url,fit_mode,interest_button_text,skip_button_text,image_url,link_url,duration_sec,weight{$extra} FROM custom_ads WHERE {$base} AND partner_id=?{$commercial} ORDER BY id DESC");
    $params=[(int)$partner['id']]; if($commercial!=='')$params[]=(int)$partner['id']; $st->execute($params);
    $ads = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ((int)($partner['allow_global_ads'] ?? 0) === 1) {
        if($commercial!==''){
            $st=$pdo->prepare("SELECT id,partner_id,title,media_type,media_url,poster_url,fit_mode,interest_button_text,skip_button_text,image_url,link_url,duration_sec,weight{$extra} FROM custom_ads WHERE {$base} AND partner_id IS NULL{$commercial} ORDER BY id DESC");
            $st->execute([(int)$partner['id']]);$globalAds=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
        }else{
            $globalAds = $pdo->query("SELECT id,partner_id,title,media_type,media_url,poster_url,fit_mode,interest_button_text,skip_button_text,image_url,link_url,duration_sec,weight{$extra} FROM custom_ads WHERE {$base} AND partner_id IS NULL ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $ads=array_merge($ads,$globalAds);
    }
    $ads = array_values(array_filter($ads,static function(array $ad): bool {
        $link = trim((string)($ad['link_url'] ?? ''));
        if ($link === '') return true;
        if (!filter_var($link,FILTER_VALIDATE_URL)) return false;
        return in_array(strtolower((string)parse_url($link,PHP_URL_SCHEME)),['http','https'],true);
    }));
    return $ads;
}

function partner_ads_pick(PDO $pdo, array $partner): ?array
{
    $ads = partner_ads_eligible($pdo,$partner);
    if (!$ads) return null;
    $total = array_sum(array_map(static fn(array $ad): int => max(1,(int)$ad['weight']),$ads));
    $draw = random_int(1,max(1,$total));
    foreach ($ads as $ad) {
        $draw -= max(1,(int)$ad['weight']);
        if ($draw <= 0) return $ad;
    }
    return $ads[0];
}

function partner_ads_track(PDO $pdo, int $partnerId, int $adId, string $event, ?string $username, ?string $mac, ?int $hotspotId = null): void
{
    if (!in_array($event,['impression','interest_yes','interest_no','view_complete','skipped','destination_open'],true)) throw new InvalidArgumentException('Evento inválido.');
    $st = $pdo->prepare("SELECT 1 FROM custom_ads a JOIN partners p ON p.id=?
        WHERE a.id=? AND a.active=1 AND p.ads_enabled=1
          AND (a.partner_id=p.id OR (a.partner_id IS NULL AND p.allow_global_ads=1))
          AND (a.start_date IS NULL OR a.start_date<=CURRENT_DATE)
          AND (a.end_date IS NULL OR a.end_date>=CURRENT_DATE) LIMIT 1");
    $st->execute([$partnerId,$adId]);
    if (!$st->fetchColumn()) throw new RuntimeException('Anúncio não elegível para este estabelecimento.');
    if (fs_partner_hotspots_schema_ready($pdo)) {
        $hotspot = fs_partner_hotspot_for_operation($pdo,$partnerId,$hotspotId);
        if (!$hotspot) throw new RuntimeException('Ponto da publicidade não encontrado.');
        $hotspotId = (int)$hotspot['hotspot_id'];
    }
    if (fs_partner_hotspots_schema_ready($pdo)) {
        $pdo->prepare('INSERT INTO custom_ads_events (ad_id,partner_id,hotspot_id,username,mac,event) VALUES (?,?,?,?,?,?)')
            ->execute([$adId,$partnerId,$hotspotId,$username ?: null,$mac ?: null,$event]);
    } else {
        $pdo->prepare('INSERT INTO custom_ads_events (ad_id,partner_id,username,mac,event) VALUES (?,?,?,?,?)')
            ->execute([$adId,$partnerId,$username ?: null,$mac ?: null,$event]);
    }
}

function partner_ads_offer_prune(): void
{
    if (!isset($_SESSION['pending_ad_offers']) || !is_array($_SESSION['pending_ad_offers'])) $_SESSION['pending_ad_offers'] = [];
    $now = time();
    foreach ($_SESSION['pending_ad_offers'] as $token => $offer) {
        if (!is_array($offer) || (int)($offer['expires_at'] ?? 0) <= $now) unset($_SESSION['pending_ad_offers'][$token]);
    }
    if (count($_SESSION['pending_ad_offers']) > 8) $_SESSION['pending_ad_offers'] = array_slice($_SESSION['pending_ad_offers'],-8,null,true);
}

function partner_ads_queue_offer(PDO $pdo, int $partnerId, int $adId, ?int $deliveryId = null): ?string
{
    $st = $pdo->prepare("SELECT a.id,a.title,a.link_url FROM custom_ads a JOIN partners p ON p.id=?
        WHERE a.id=? AND a.active=1 AND p.ads_enabled=1
          AND (a.partner_id=p.id OR (a.partner_id IS NULL AND p.allow_global_ads=1))
          AND (a.start_date IS NULL OR a.start_date<=CURRENT_DATE)
          AND (a.end_date IS NULL OR a.end_date>=CURRENT_DATE) LIMIT 1");
    $st->execute([$partnerId,$adId]);
    $ad = $st->fetch(PDO::FETCH_ASSOC);
    try { $link = $ad ? partner_ads_http_url($ad['link_url'] ?? null,false) : null; }
    catch (InvalidArgumentException $e) { $link = null; }
    if (!$ad || $link === null) return null;
    partner_ads_offer_prune();
    $token = bin2hex(random_bytes(24));
    if(partner_ads_monetization_ready($pdo))$pdo->prepare('INSERT INTO ad_pending_offers (token_hash,partner_id,ad_id,delivery_id,expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([hash('sha256',$token),$partnerId,$adId,$deliveryId]);
    else $pdo->prepare('INSERT INTO ad_pending_offers (token_hash,partner_id,ad_id,expires_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([hash('sha256',$token),$partnerId,$adId]);
    try { $pdo->exec("DELETE FROM ad_pending_offers WHERE expires_at<DATE_SUB(NOW(),INTERVAL 7 DAY)"); }
    catch (Throwable $e) { error_log('[ad pending cleanup] ' . $e->getMessage()); }
    $_SESSION['pending_ad_offers'][$token] = [
        'partner_id'=>$partnerId,
        'ad_id'=>$adId,
        'created_at'=>time(),
        'expires_at'=>time()+1800,
    ];
    $_SESSION['pending_ad_offer_token'] = $token;
    return $token;
}

function partner_ads_pending_offer_token(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{48}$/',$token)) return null;
    $deliveryReady=partner_ads_monetization_ready($pdo);
    $hotspotReady=$deliveryReady&&fs_partner_hotspots_schema_ready($pdo);
    $deliverySelect=$deliveryReady?',o.delivery_id':',NULL delivery_id';
    $deliverySelect.=$hotspotReady?',d.hotspot_id':',NULL hotspot_id';
    $deliveryJoin=$hotspotReady?' LEFT JOIN ad_deliveries d ON d.id=o.delivery_id':'';
    $st = $pdo->prepare("SELECT o.id,o.partner_id,o.ad_id,o.expires_at{$deliverySelect},a.title,a.link_url
        FROM ad_pending_offers o
        JOIN custom_ads a ON a.id=o.ad_id
        JOIN partners p ON p.id=o.partner_id
        {$deliveryJoin}
        WHERE o.token_hash=? AND o.opened_at IS NULL AND o.dismissed_at IS NULL AND o.expires_at>NOW()
          AND p.active=1 AND a.active=1 AND p.ads_enabled=1
          AND (a.partner_id=p.id OR (a.partner_id IS NULL AND p.allow_global_ads=1))
          AND (a.start_date IS NULL OR a.start_date<=CURRENT_DATE)
          AND (a.end_date IS NULL OR a.end_date>=CURRENT_DATE) LIMIT 1");
    $st->execute([hash('sha256',$token)]);
    $offer = $st->fetch(PDO::FETCH_ASSOC);
    if (!$offer) return null;
    try { $link = partner_ads_http_url($offer['link_url'] ?? null,false); }
    catch (InvalidArgumentException $e) { return null; }
    if ($link === null) return null;
    $offer['link_url'] = $link;
    $offer['token'] = $token;
    return $offer;
}

function partner_ads_pending_offer(PDO $pdo, int $partnerId, ?string $token = null): ?array
{
    partner_ads_offer_prune();
    $token = trim((string)($token ?? ($_SESSION['pending_ad_offer_token'] ?? '')));
    $offer = partner_ads_pending_offer_token($pdo,$token);
    return $offer && (int)$offer['partner_id'] === $partnerId ? $offer : null;
}

function partner_ads_pending_offer_url(PDO $pdo, int $partnerId): ?string
{
    $offer = partner_ads_pending_offer($pdo,$partnerId);
    if (!$offer) return null;
    return fs_public_base_url($pdo) . '/portal/oferta.php?token=' . rawurlencode((string)$offer['token']);
}

function partner_ads_session_offer_url(PDO $pdo): ?string
{
    partner_ads_offer_prune();
    $token = trim((string)($_SESSION['pending_ad_offer_token'] ?? ''));
    $offer = $_SESSION['pending_ad_offers'][$token] ?? null;
    $partnerId = is_array($offer) ? (int)($offer['partner_id'] ?? 0) : 0;
    return $partnerId > 0 ? partner_ads_pending_offer_url($pdo,$partnerId) : null;
}

function partner_ads_forget_offer(string $token): void
{
    unset($_SESSION['pending_ad_offers'][$token]);
    if (hash_equals((string)($_SESSION['pending_ad_offer_token'] ?? ''),$token)) unset($_SESSION['pending_ad_offer_token']);
}

function partner_ads_consume_offer(PDO $pdo, string $token, string $action): ?array
{
    if (!in_array($action,['open','dismiss'],true)) throw new InvalidArgumentException('Ação de oferta inválida.');
    $offer = partner_ads_pending_offer_token($pdo,$token);
    if (!$offer) return null;
    $column = $action === 'open' ? 'opened_at' : 'dismissed_at';
    $st = $pdo->prepare("UPDATE ad_pending_offers SET {$column}=NOW() WHERE id=? AND opened_at IS NULL AND dismissed_at IS NULL AND expires_at>NOW()");
    $st->execute([(int)$offer['id']]);
    if ($st->rowCount() !== 1) return null;
    partner_ads_forget_offer($token);
    return $offer;
}
