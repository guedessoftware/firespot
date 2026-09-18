<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../app/db.php';

/**
 * FireSpot – Atualiza pico de concorrência por hora
 * Correções:
 *  - Usa o MESMO schema do seu teste: firespot.radacct
 *  - Filtro de sessão ativa inclui zero-date ('0000-00-00 00:00:00')
 *  - Timezone por offset (-04:00) para evitar #1298
 */

date_default_timezone_set('America/Manaus');

$pdo = db();
try { $pdo->query("SET time_zone = '-04:00'"); } catch (\Throwable $e) {}

$tz = new DateTimeZone('America/Manaus');
$nowDt      = new DateTime('now', $tz);
$hourStart  = (clone $nowDt)->setTime((int)$nowDt->format('H'), 0, 0);

$nowStr     = $nowDt->format('Y-m-d H:i:s');
$hourKeyStr = $hourStart->format('Y-m-d H:00:00');

/* === Ajuste AQUI os schemas se necessário === */
$dbRadius = 'firespot';   // <-- radacct está em firespot
$dbDash   = 'firespot';   // <-- rad_concurrency_hourly também

// Predicado de sessão ativa (inclui zero-date)
$activeWhere = "acctstarttime <= ? AND (acctstoptime IS NULL OR acctstoptime = '0000-00-00 00:00:00' OR acctstoptime > ?)";

/* -------- DISPOSITIVOS (MAC) -------- */
$sqlDevice = "
  SELECT COUNT(DISTINCT COALESCE(NULLIF(callingstationid,''), CONCAT('sess#',radacctid)))
  FROM {$dbRadius}.radacct
  WHERE {$activeWhere}
";
$st = $pdo->prepare($sqlDevice);
$st->execute([$nowStr, $nowStr]);
$cntDevice = (int)$st->fetchColumn();

$st = $pdo->prepare("
  INSERT INTO {$dbDash}.rad_concurrency_hourly (hour_key, mode, peak_count, sample_count, last_seen)
  VALUES (?, 'device', ?, 1, ?)
  ON DUPLICATE KEY UPDATE
    peak_count   = GREATEST(peak_count, VALUES(peak_count)),
    sample_count = sample_count + 1,
    last_seen    = VALUES(last_seen)
");
$st->execute([$hourKeyStr, $cntDevice, $nowStr]);

/* -------- USUÁRIOS (username/CPF) -------- */
$sqlUser = "
  SELECT COUNT(DISTINCT COALESCE(NULLIF(username,''), COALESCE(NULLIF(callingstationid,''), CONCAT('sess#',radacctid))))
  FROM {$dbRadius}.radacct
  WHERE {$activeWhere}
";
$st = $pdo->prepare($sqlUser);
$st->execute([$nowStr, $nowStr]);
$cntUser = (int)$st->fetchColumn();

$st = $pdo->prepare("
  INSERT INTO {$dbDash}.rad_concurrency_hourly (hour_key, mode, peak_count, sample_count, last_seen)
  VALUES (?, 'user', ?, 1, ?)
  ON DUPLICATE KEY UPDATE
    peak_count   = GREATEST(peak_count, VALUES(peak_count)),
    sample_count = sample_count + 1,
    last_seen    = VALUES(last_seen)
");
$st->execute([$hourKeyStr, $cntUser, $nowStr]);

echo "OK hour={$hourKeyStr} device={$cntDevice} user={$cntUser} now={$nowStr}\n";
