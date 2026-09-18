<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../app/db.php';
if (env('APP_ENV') !== 'local' || env('DB_DATABASE') !== 'firespot_local') exit(64);
$pdo = db();
$username = 'lab-probe-' . bin2hex(random_bytes(8));
$password = bin2hex(random_bytes(16));
$secret = tempnam(sys_get_temp_dir(), 'fs-radius-');
chmod($secret, 0600);
file_put_contents($secret, env('COURTESY_RADIUS_PROBE_SECRET') . "\n");
$host = (string)env('COURTESY_RADIUS_PROBE_HOST');
if ($host !== 'radius') throw new RuntimeException('Teste exclusivo do serviço radius local.');
$exchange = static function(string $body, string $kind = 'auth') use ($host, $secret): string {
    $port = $kind === 'acct' ? 1813 : 1812;
    $process = proc_open(['radclient','-x','-r','1','-t','3','-S',$secret,"{$host}:{$port}",$kind],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('radclient indisponível.');
    fwrite($pipes[0], $body . "Message-Authenticator = 0x00000000000000000000000000000000\n");
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]); proc_close($process);
    return $output;
};
$assert = static function(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('Falhou: ' . $label);
    echo "PASS {$label}\n";
};
try {
    $insert = $pdo->prepare('INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,?,?)');
    foreach ([['Cleartext-Password',':=',$password],['Max-All-Session',':=','300'],['Calling-Station-Id','==','02:00:00:00:00:01']] as $row) $insert->execute(array_merge([$username],$row));
    $st = $pdo->prepare("INSERT INTO radacct (acctsessionid,acctuniqueid,username,nasipaddress,acctsessiontime,acctstarttime,acctstoptime) VALUES (?,?,?,'192.0.2.254',120,NOW()-INTERVAL 1 HOUR,NOW()-INTERVAL 58 MINUTE)");
    $st->execute([$username . '-old',md5($username),$username]);
    $base = "User-Name = \"{$username}\"\nNAS-IP-Address = 192.0.2.254\nCalling-Station-Id = \"02:00:00:00:00:01\"\n";
    $reply = $exchange($base . "User-Password = \"{$password}\"\n");
    $assert(str_contains($reply,'Access-Accept') && str_contains($reply,'Session-Timeout = 180'), 'PAP: 180 segundos restantes de 300');
    $challenge = random_bytes(16);
    $chap = "\x01" . md5("\x01" . $password . $challenge, true);
    $reply = $exchange($base . 'CHAP-Challenge = 0x' . bin2hex($challenge) . "\nCHAP-Password = 0x" . bin2hex($chap) . "\n");
    $assert(str_contains($reply,'Access-Accept'), 'CHAP');
    $assert(str_contains($exchange($base . "User-Password = \"incorrect\"\n"),'Access-Reject'), 'Senha incorreta rejeitada');
    $assert(str_contains($exchange(str_replace('02:00:00:00:00:01','02:00:00:00:00:02',$base) . "User-Password = \"{$password}\"\n"),'Access-Reject'), 'Outro MAC rejeitado');
    foreach (['Start'=>0,'Interim-Update'=>7,'Stop'=>9] as $status=>$seconds) {
        $reply = $exchange($base . "NAS-Port = 1\nAcct-Session-Id = \"{$username}-live\"\nAcct-Status-Type = {$status}\nAcct-Session-Time = {$seconds}\nFramed-IP-Address = 192.0.2.253\n", 'acct');
        $assert(str_contains($reply,'Accounting-Response'), 'Accounting ' . $status);
    }
    $st = $pdo->prepare('SELECT acctsessiontime,acctstoptime FROM radacct WHERE username=? AND acctsessionid=?');
    $st->execute([$username,$username.'-live']); $row = $st->fetch(PDO::FETCH_ASSOC);
    $assert($row && (int)$row['acctsessiontime'] === 9 && $row['acctstoptime'] !== null, 'Accounting persistido no SQL');
    $st = $pdo->prepare('UPDATE radacct SET acctsessiontime=300 WHERE username=?'); $st->execute([$username]);
    $assert(str_contains($exchange($base . "User-Password = \"{$password}\"\n"),'Access-Reject'), 'Crédito esgotado rejeitado');
} finally {
    foreach (['radcheck','radreply','radacct'] as $table) { $st=$pdo->prepare("DELETE FROM {$table} WHERE username=?"); $st->execute([$username]); }
    unlink($secret);
}
