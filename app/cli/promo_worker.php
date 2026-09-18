#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

/**
 * Worker de promoções - envia 1 mensagem a cada 10s
 * Caminho: hotspot/app/cli/promo_worker.php
 */
@set_time_limit(0);
@ignore_user_abort(true);

$APP = realpath(__DIR__ . '/..');     // .../app
$ROOT = realpath($APP . '/..');       // .../hotspot

// ===== Bootstrap DB (robusto) =====
$pdo = null;
$db_boot_errors = [];

try {
  // 1) Tenta carregar app/db.php
  if (is_file($APP . '/db.php')) {
    require_once $APP . '/db.php';
  }

  // 2) Se $pdo não veio setado, tenta funções comuns
  if (!($pdo instanceof PDO)) {
    if (function_exists('getPDO')) {
      $pdo = getPDO();
    } elseif (function_exists('db')) {
      $pdo = db();
    } elseif (function_exists('pdo')) {
      $pdo = pdo();
    }
  }

  // 3) Último fallback: monta PDO via ENV e/ou app/config.php
  if (!($pdo instanceof PDO)) {
    $db_host = getenv('DB_HOST') ?: null;
    $db_name = getenv('DB_NAME') ?: null;
    $db_user = getenv('DB_USER') ?: null;
    $db_pass = getenv('DB_PASS') ?: null;

    if (is_file($APP . '/config.php')) {
      // muitos projetos populam $db_host/$db_name/... aqui
      include $APP . '/config.php';
    }

    if (!$db_host || !$db_name) {
      throw new RuntimeException('Configuração de banco ausente (DB_HOST/DB_NAME).');
    }

    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
} catch (Throwable $e) {
  $db_boot_errors[] = $e->getMessage();
}

if (!($pdo instanceof PDO)) {
  file_put_contents('php://stderr', "[promo_worker] Falha ao inicializar PDO: " . implode(' | ', $db_boot_errors) . PHP_EOL);
  exit(1);
}

// ===== Utils API =====
require_once $APP . '/lib/promo_api.php'; // define promo_api_send()
require_once $APP . '/personal_data_crypto.php';
$runOnce = in_array('--once', $argv ?? [], true);
$workerBootedAt=time();
$workerSourceFiles=[__FILE__,$APP.'/lib/promo_api.php',$APP.'/personal_data_crypto.php'];
$workerSourceFingerprint=hash('sha256',implode('|',array_map(static fn(string $file):string=>$file.':'.(string)(@filemtime($file)?:0).':'.(string)(@filesize($file)?:0),$workerSourceFiles)));

// Recupera somente falhas causadas pela versão antiga que tentou enviar o
// marcador ENCRYPTED como destino. OTP expirado e oferta vencida não voltam.
try{
  $pdo->exec("UPDATE promo_queue q JOIN subscriber_login_challenges c ON c.id=q.reference_id AND q.reference_type='login_challenge' SET q.status='pending',q.attempts=0,q.last_error='WORKER_UPGRADE_RETRY',q.scheduled_at=NOW(),q.failed_at=NULL,q.updated_at=NOW() WHERE q.purpose='subscriber_otp' AND q.status='error' AND q.last_error='INVALID_DEST' AND q.payload_encrypted IS NOT NULL AND q.attempts<q.max_attempts AND c.status='pending' AND c.expires_at>NOW()");
  $pdo->exec("UPDATE promo_queue q JOIN ad_leads l ON l.id=q.reference_id AND q.reference_type='ad_lead' SET q.status='pending',q.attempts=0,q.last_error='WORKER_UPGRADE_RETRY',q.scheduled_at=NOW(),q.failed_at=NULL,q.updated_at=NOW() WHERE q.purpose='ad_offer' AND q.status='error' AND q.last_error='INVALID_DEST' AND q.payload_encrypted IS NOT NULL AND q.attempts<q.max_attempts AND l.status<>'anonymized' AND l.expires_at>NOW()");
}catch(Throwable $recoveryError){file_put_contents('php://stderr',"[promo_worker] recovery_error=".get_class($recoveryError).PHP_EOL);}

/**
 * Pega 1 item 'pending' com trava transacional.
 * Em MySQL 8 você pode trocar o SELECT por:
 *   ... FOR UPDATE SKIP LOCKED
 */
function claim_one(PDO $pdo) {
  $pdo->beginTransaction();
  try {
    $pdo->exec("UPDATE promo_queue SET status='pending',scheduled_at=NOW(),updated_at=NOW(),last_error='STALE_CLAIM_RECOVERED' WHERE status='sending' AND updated_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE) AND attempts<max_attempts");
    $stmt = $pdo->query(
      "SELECT id
         FROM promo_queue
        WHERE status='pending' AND scheduled_at <= NOW() AND attempts<max_attempts
        ORDER BY scheduled_at, id
        LIMIT 1
        FOR UPDATE"
    );
    $row = $stmt->fetch();
    if (!$row) { $pdo->commit(); return null; }

    $id = (int)$row['id'];
    $upd = $pdo->prepare("UPDATE promo_queue SET status='sending', updated_at=NOW() WHERE id=:id AND status='pending'");
    $upd->execute([':id'=>$id]);
    $pdo->commit();

    $s = $pdo->prepare("SELECT * FROM promo_queue WHERE id=:id");
    $s->execute([':id'=>$id]);
    return $s->fetch();
  } catch (Throwable $e) {
    $pdo->rollBack();
    // log leve em STDERR e segue:
    file_put_contents('php://stderr', "[promo_worker] claim_one erro: ".$e->getMessage().PHP_EOL);
    return null;
  }
}

// ===== Loop principal =====
while (true) {
  if(!$runOnce){
    $currentFingerprint=hash('sha256',implode('|',array_map(static fn(string $file):string=>$file.':'.(string)(@filemtime($file)?:0).':'.(string)(@filesize($file)?:0),$workerSourceFiles)));
    if(time()-$workerBootedAt>=3600||!hash_equals($workerSourceFingerprint,$currentFingerprint)){
      file_put_contents('php://stderr',"[promo_worker] reload_requested\n");
      exit(0);
    }
  }
  // Se o mensageiro estiver desabilitado via ENV, apenas aguarda e tenta novamente
  if (function_exists('promo_is_enabled') && !promo_is_enabled()) {
    if ($runOnce) break;
    sleep(5);
    continue;
  }

  $job = claim_one($pdo);

  if (!$job) {
    if ($runOnce) break;
    // nada pendente; descansa 3s e tenta de novo
    sleep(3);
    continue;
  }

  $to  = (string)$job['to_msisdn'];
  $msg = (string)$job['msg'];
  $encryptedPurpose=in_array(($job['purpose']??''),['ad_offer','subscriber_otp'],true);
  if($encryptedPurpose){
    try{$payload=json_decode(fs_personal_decrypt((string)($job['payload_encrypted']??'')),true);if(!is_array($payload)||empty($payload['to'])||empty($payload['message']))throw new RuntimeException('INVALID_ENCRYPTED_PAYLOAD');$to=(string)$payload['to'];$msg=(string)$payload['message'];}
    catch(Throwable $e){$pdo->prepare("UPDATE promo_queue SET status='error',attempts=max_attempts,last_error='INVALID_ENCRYPTED_PAYLOAD',failed_at=NOW(),payload_encrypted=NULL,to_msisdn='PURGED',msg='PURGED',updated_at=NOW() WHERE id=?")->execute([(int)$job['id']]);if(($job['purpose']??'')==='ad_offer')$pdo->prepare("UPDATE ad_leads SET message_status='failed',updated_at=NOW() WHERE message_queue_id=?")->execute([(int)$job['id']]);if($runOnce)break;continue;}
  }

  $r = promo_api_send($to, $msg);

  if (!empty($r['ok']) && empty($r['skipped'])) {
    $st = $pdo->prepare("UPDATE promo_queue
                            SET status='sent', sent_at=NOW(), accepted_at=NOW(), last_error=NULL,
                                payload_encrypted=IF(purpose IN ('ad_offer','subscriber_otp'),NULL,payload_encrypted),
                                to_msisdn=IF(purpose IN ('ad_offer','subscriber_otp'),'PURGED',to_msisdn),msg=IF(purpose IN ('ad_offer','subscriber_otp'),'PURGED',msg),updated_at=NOW()
                          WHERE id=:id AND status='sending'");
    $st->execute([':id'=>$job['id']]);
    if($st->rowCount()>0&&($job['reference_type']??'')==='ad_lead'){$st=$pdo->prepare("UPDATE ad_leads SET message_status='accepted',updated_at=NOW() WHERE id=? AND message_queue_id=? AND message_status='queued'");$st->execute([(int)$job['reference_id'],(int)$job['id']]);}
    file_put_contents('php://stderr', "[promo_worker] accepted job=".(int)$job['id']."\n");
  } elseif (!empty($r['skipped'])) {
    // Não envia nem consome a fila quando estiver desabilitado
    $st = $pdo->prepare("UPDATE promo_queue SET status='pending', updated_at=NOW() WHERE id=:id");
    $st->execute([':id'=>$job['id']]);
    file_put_contents('php://stderr', "[promo_worker] skipped job=".(int)$job['id']."\n");
  } else {
    $errTxt = substr((string)($r['err'] ?: ('HTTP '.(int)$r['code'])),0,500);
    $attempt=(int)$job['attempts']+1;$max=max(1,(int)($job['max_attempts']??5));$final=$attempt>=$max;
    $delayMinutes=min(60,(int)pow(2,min(5,$attempt)));
    $st = $pdo->prepare("UPDATE promo_queue SET status=:status,attempts=:attempts,last_error=:e,failed_at=".($final?'NOW()':'NULL').",scheduled_at=".($final?'scheduled_at':'DATE_ADD(NOW(),INTERVAL :delay MINUTE)').",".($final?"payload_encrypted=IF(purpose IN ('ad_offer','subscriber_otp'),NULL,payload_encrypted),to_msisdn=IF(purpose IN ('ad_offer','subscriber_otp'),'PURGED',to_msisdn),msg=IF(purpose IN ('ad_offer','subscriber_otp'),'PURGED',msg),":"")."updated_at=NOW() WHERE id=:id AND status='sending'");
    $params=[':status'=>$final?'error':'pending',':attempts'=>$attempt,':e'=>$errTxt,':id'=>$job['id']];if(!$final)$params[':delay']=$delayMinutes;$st->execute($params);
    if($final&&$st->rowCount()>0&&($job['reference_type']??'')==='ad_lead'){$st=$pdo->prepare("UPDATE ad_leads SET message_status='failed',updated_at=NOW() WHERE id=? AND message_queue_id=? AND message_status='queued'");$st->execute([(int)$job['reference_id'],(int)$job['id']]);}
    file_put_contents('php://stderr', "[promo_worker] retry=".($final?'no':'yes')." job=".(int)$job['id']." error=".$errTxt."\n");
  }

  if ($runOnce) break;

  // intervalo de 10s entre cada disparo
  sleep(10);
}
