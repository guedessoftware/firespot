<?php
require_once __DIR__ . '/../../app/admin_auth.php';
admin_require_json();
if (!admin_has_capability('partner.network.manage')) {
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}
admin_require_csrf($_POST, true);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/lib/routeros.php';
require_once __DIR__ . '/../../app/env.php';
require_once __DIR__ . '/../../app/partner_network.php';
require_once __DIR__ . '/../../app/public_url.php';
require_once __DIR__ . '/../../app/hotspot_walled_garden.php';
require_once __DIR__ . '/../../app/partner_hotspots.php';
require_once __DIR__ . '/../../app/partner_admin.php';
require_once __DIR__ . '/../../app/nas_base_provisioning.php';

function normalize_dns_name(string $value, string $code): string
{
  return partner_network_dns_name($value, $code);
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $hotspotId = isset($_POST['hotspot_id']) ? (int)$_POST['hotspot_id'] : 0;
  if ($id <= 0 && $hotspotId <= 0) {
    throw new RuntimeException('ID inválido.');
  }
  if (fs_partner_hotspots_schema_ready($pdo)) {
    $host = $hotspotId > 0 ? fs_partner_hotspot_by_id($pdo,$hotspotId,null,true) : fs_partner_hotspot_default($pdo,$id,true);
  } else {
    $st = $pdo->prepare('SELECT * FROM partners WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $host = $st->fetch();
  }
  if (!$host) {
    throw new RuntimeException('Ponto não encontrado ou inativo.');
  }

  $requiredHostFields = ['vlan_id','gateway_ip','pool_start','pool_end','dns_servers','radius_ip','nas_id','nas_interface_id'];
  foreach ($requiredHostFields as $required) {
    if (!array_key_exists($required, $host) || $host[$required] === null || $host[$required] === '') {
      throw new RuntimeException("Campo obrigatório ausente: {$required}");
    }
  }

  $st = $pdo->prepare('SELECT * FROM nas WHERE id=? LIMIT 1');
  $st->execute([(int)$host['nas_id']]);
  $nas = $st->fetch();
  if (!$nas) {
    throw new RuntimeException('NAS vinculado não encontrado.');
  }
  $nas = fs_nas_credentials_for_operation($pdo,$nas);
  $mgmtUser = trim((string)($nas['mgmt_username'] ?? ''));
  $mgmtPassword = (string)($nas['mgmt_password'] ?? '');
  if ($mgmtUser === '') {
    throw new RuntimeException('Usuário de gerenciamento não configurado no NAS.');
  }
  if ($mgmtPassword === '') {
    throw new RuntimeException('Senha SSH de gerenciamento não configurada no NAS.');
  }
  fs_nas_base_assert_ready($pdo,(int)$nas['id'],(string)($host['radius_ip'] ?? ''));
  $port = (int)($nas['mgmt_port'] ?? 22);
  if ($port <= 0) $port = 22;

  $st = $pdo->prepare('SELECT interface_name FROM nas_interfaces WHERE id=? LIMIT 1');
  $st->execute([(int)$host['nas_interface_id']]);
  $iface = $st->fetchColumn();
  if (!$iface) {
    throw new RuntimeException('Interface do NAS não encontrada.');
  }

  $portalCode = fs_partner_hotspot_public_code($host);
  $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $portalCode));
  if ($code === '') {
    $code = 'HOST' . (int)$host['id'];
  }
  $ifaceName = sprintf('vlan%s-%s', (int)$host['vlan_id'], $code);
  $gateway = trim($host['gateway_ip']);
  $poolStart = trim($host['pool_start']);
  $poolEnd = trim($host['pool_end']);
  foreach ([$gateway, $poolStart, $poolEnd] as $networkIp) {
    if (!filter_var($networkIp,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) {
      throw new RuntimeException('O ponto contém um endereço IPv4 inválido. Salve a rede novamente antes de aplicar.');
    }
  }
  $dnsParts = preg_split('/[\s,;]+/',trim((string)$host['dns_servers']),-1,PREG_SPLIT_NO_EMPTY) ?: [];
  if (!$dnsParts) throw new RuntimeException('Informe ao menos um servidor DNS upstream.');
  foreach ($dnsParts as $dnsServer) {
    if (!filter_var($dnsServer,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) {
      throw new RuntimeException('O ponto contém um servidor DNS upstream inválido.');
    }
  }
  $dnsServers = implode(',',$dnsParts);
  $isPortalV3 = (string)($host['portal_mode'] ?? 'inherit') === 'v3';
  $dnsName = normalize_dns_name($host['dns_name'] ?? '', $code);
  $routerDnsName = $isPortalV3 ? partner_network_routeros_dns_name($dnsName) : $dnsName;
  $dnsNameArg = fs_routeros_quote($routerDnsName);
  $networkCommentArg = fs_routeros_quote($code);
  $hotspotProfileArg = fs_routeros_quote('hs_' . $code);
  $defaultUserProfileArg = fs_routeros_quote('default');
  $networkProfile = fs_partner_hotspot_network_profile([
    'network_prefix_length'=>$host['network_prefix_length']??null,
    'gateway_ip'=>$gateway,'pool_start'=>$poolStart,'pool_end'=>$poolEnd,
  ]);
  $networkCidr = (string)$networkProfile['cidr'];
  $networkPrefix = (int)$networkProfile['prefix'];

  $prevHost = getenv('ROS_HOST');
  $prevUser = getenv('ROS_USER');
  $prevPass = getenv('ROS_PASS');
  $prevPort = getenv('ROS_PORT');

  $safeIface = fs_routeros_quote((string)$iface);
  $safeVlanIface = fs_routeros_quote($ifaceName);
  $hotspotNameArg = fs_routeros_quote((string)$host['name']);
  $htmlDirName = 'hs_' . $code;
  $htmlDirPath = '/' . $htmlDirName;
  $loginBy = $isPortalV3 ? 'cookie,http-chap,http-pap,mac-cookie' : 'http-chap,http-pap';
  $httpCookieLifetime = $isPortalV3 ? FS_HOTSPOT_SHORT_RECONNECT_TIMEOUT : '1d';

  $portsToTry = [$port];
  if ($port !== 22) {
    $portsToTry[] = 22;
  }
  $portsToTry = array_values(array_unique($portsToTry));

  $authPasswordUsed = null;
  $authUserUsed = null;
  $conn = null;
  $portUsed = $port;
  $candidatePasswords = [$mgmtPassword];
  $candidateUsers = [$mgmtUser];
  $connectedAtLeastOnce = false;
  foreach ($portsToTry as $portCandidate) {
    $connCandidate = @ssh2_connect($nas['nasname'], $portCandidate);
    if (!$connCandidate) {
      continue;
    }
    $connectedAtLeastOnce = true;
    foreach ($candidateUsers as $candidateUser) {
      foreach ($candidatePasswords as $candidatePassword) {
        if (@ssh2_auth_password($connCandidate, $candidateUser, $candidatePassword)) {
          $conn = $connCandidate;
          $portUsed = $portCandidate;
          $authPasswordUsed = $candidatePassword;
          $authUserUsed = $candidateUser;
          break 3;
        }
      }
    }
  }
  if (!$conn) {
    if (!$connectedAtLeastOnce) {
      throw new RuntimeException('SSH connect falhou ('.$nas['nasname'].':'.$port.')');
    }
    throw new RuntimeException('Falha de autenticação SSH. Verifique usuário, senha ou segredo do NAS.');
  }
  $mgmtUser = $authUserUsed ?? $mgmtUser;
  $rosPass = $authPasswordUsed ?? $mgmtPassword;
  putenv('ROS_HOST='.$nas['nasname']);
  putenv('ROS_USER='.$mgmtUser);
  if ($rosPass !== '') {
    putenv('ROS_PASS='.$rosPass);
  } else {
    putenv('ROS_PASS');
  }
  putenv('ROS_PORT='.$portUsed);

  // O cliente consulta somente o gateway, que encaminha consultas externas.
  // No V3 a zona privada não é publicada pelo perfil Hotspot: a primeira
  // abertura do portal também precisa usar diretamente o gateway.
  fs_nas_base_assert_command_result(ros_exec([
    "/ip dns set servers={$dnsServers} allow-remote-requests=yes",
  ]),'configurar o resolvedor DNS do NAS','configuração DNS global do NAS');

  $prepareDirs = ros_exec([
    ":do { /file make-dir {$htmlDirName} } on-error={ }",
    ":do { /file make-dir {$htmlDirName}/img } on-error={ }"
  ]);
  if (empty($prepareDirs['ok'])) {
    throw new RuntimeException('Falha ao preparar diretório do hotspot no NAS.');
  }

  $sftp = @ssh2_sftp($conn);
  $useScpFallback = !$sftp;
  if ($useScpFallback && !function_exists('ssh2_scp_send')) {
    throw new RuntimeException('SFTP indisponível no NAS e SCP não suportado no servidor PHP.');
  }

  $publicHost = fs_public_host($pdo);
  $commands = [
    "/ip hotspot remove [find name=hs_{$code}]",
    "/ip dhcp-server remove [find name=dhcp_{$code}]",
    "/ip hotspot profile remove [find name=hs_{$code}]",
    "/ip dhcp-server network remove [find comment=\"{$code}\"]",
    "/ip address remove [find interface={$safeVlanIface}]",
    "/ip pool remove [find name=pool_{$code}]",
    "/interface vlan remove [find name={$safeVlanIface}]",
    "/interface vlan add name={$safeVlanIface} vlan-id={$host['vlan_id']} interface={$safeIface} comment={$hotspotNameArg}",
    "/ip address add address={$gateway}/{$networkPrefix} interface={$safeVlanIface} comment=\"{$code}\"",
    "/ip pool add name=pool_{$code} ranges={$poolStart}-{$poolEnd}",
    "/ip dhcp-server add name=dhcp_{$code} interface={$safeVlanIface} address-pool=pool_{$code} lease-time=4h disabled=no",
    "/ip dhcp-server network add address={$networkCidr} gateway={$gateway} dns-server={$gateway} comment=\"{$code}\"",
  ];
  if ($isPortalV3) {
    $commands[] = "/ip hotspot user profile set [find where name={$defaultUserProfileArg}] add-mac-cookie=yes mac-cookie-timeout=" . FS_HOTSPOT_SHORT_RECONNECT_TIMEOUT;
  }
  $commands = array_merge($commands, [
    "/ip hotspot profile add name=hs_{$code} hotspot-address={$gateway} login-by={$loginBy} http-cookie-lifetime={$httpCookieLifetime} use-radius=yes dns-name={$dnsNameArg} html-directory={$htmlDirName}",
    "/ip hotspot add name=hs_{$code} profile=hs_{$code} interface={$safeVlanIface} address-pool=pool_{$code} disabled=no",
    "/ip hotspot walled-garden ip remove [find dst-host=firespot.firenetwork.com.br]",
    "/ip hotspot walled-garden ip remove [find dst-host={$publicHost}]",
    "/ip hotspot walled-garden ip add action=accept comment=\"FireSpot Portal\" disabled=no dst-host={$publicHost}",
  ]);
  $commands = array_merge($commands, fs_hotspot_payment_walled_garden_commands(), [
    "/ip firewall nat remove [find comment=\"Hotspot {$code}\"]",
    "/ip firewall nat add chain=srcnat src-address={$networkCidr} action=masquerade comment=\"Hotspot {$code}\""
  ]);

  $result = ros_exec($commands);
  fs_nas_base_assert_command_result($result,'aplicar o ponto','configuração do ponto');

  $verificationCommands = [
    ':put [/ip dns get allow-remote-requests]',
    ':put [/ip dns get servers]',
    ":put [/ip dhcp-server network get [find where comment={$networkCommentArg}] dns-server]",
    ":put [/ip hotspot profile get [find where name={$hotspotProfileArg}] dns-name]",
    ":put [/ip hotspot profile get [find where name={$hotspotProfileArg}] hotspot-address]",
    ":put [/ip hotspot profile get [find where name={$hotspotProfileArg}] login-by]",
    ":put [/ip hotspot profile get [find where name={$hotspotProfileArg}] http-cookie-lifetime]",
    ":put [/ip hotspot user profile get [find where name={$defaultUserProfileArg}] add-mac-cookie]",
    ":put [/ip hotspot user profile get [find where name={$defaultUserProfileArg}] mac-cookie-timeout]",
  ];
  if ($routerDnsName !== '') {
    $verificationCommands[] = "/ip dns static print count-only where name={$dnsNameArg} and dynamic=yes";
    $verificationCommands[] = ":put [/ip dns static get [find where name={$dnsNameArg} and dynamic=yes] address]";
    $verificationCommands[] = ":put [:resolve domain-name={$dnsNameArg} server={$gateway} type=ipv4]";
  }
  $dnsVerification = fs_nas_base_assert_command_result(
    ros_exec($verificationCommands),
    'validar o DNS e a reconexão do Hotspot',
    'configuração do ponto'
  );
  $dnsRemoteEnabled = strtolower(trim((string)($dnsVerification[0] ?? '')));
  $dnsReturnedParts = preg_split('/[\s,;]+/',trim((string)($dnsVerification[1] ?? '')),-1,PREG_SPLIT_NO_EMPTY) ?: [];
  $loginMethods = preg_split('/[\s,;]+/',strtolower(trim((string)($dnsVerification[5] ?? ''))),-1,PREG_SPLIT_NO_EMPTY) ?: [];
  $shortTimeoutValues = ['20m','00:20:00'];
  if (!in_array($dnsRemoteEnabled,['true','yes'],true)
      || $dnsReturnedParts !== $dnsParts
      || trim((string)($dnsVerification[2] ?? '')) !== $gateway
      || strtolower(trim((string)($dnsVerification[3] ?? ''))) !== $routerDnsName
      || trim((string)($dnsVerification[4] ?? '')) !== $gateway
      || ($isPortalV3 && (!in_array('cookie',$loginMethods,true) || !in_array('mac-cookie',$loginMethods,true)))
      || ($isPortalV3 && !in_array(strtolower(trim((string)($dnsVerification[6] ?? ''))),$shortTimeoutValues,true))
      || ($isPortalV3 && !in_array(strtolower(trim((string)($dnsVerification[7] ?? ''))),['true','yes'],true))
      || ($isPortalV3 && !in_array(strtolower(trim((string)($dnsVerification[8] ?? ''))),$shortTimeoutValues,true))) {
    throw new RuntimeException('O MikroTik não confirmou o acesso inicial pelo gateway e a reconexão curta. A aplicação foi interrompida para evitar um portal inacessível.');
  }
  if ($routerDnsName !== ''
      && (fs_nas_base_count_output($dnsVerification[9] ?? '', 'o registro DNS do perfil Hotspot') !== 1
        || trim((string)($dnsVerification[10] ?? '')) !== $gateway
        || trim((string)($dnsVerification[11] ?? '')) !== $gateway)) {
    throw new RuntimeException('O MikroTik não confirmou o domínio público personalizado do Hotspot.');
  }

  if ($useScpFallback) {
    $assetBase = fs_public_base_url($pdo) . '/dashboard/api/nas_asset.php';
    $fetchCmds = [];
    $assetFiles = [
      'index.html',
      'status.html',
      'redirect.html',
      'login.html',
      'logout.html',
      'error.html'
    ];
    foreach ($assetFiles as $fileName) {
      $url = $assetBase . '?file=' . rawurlencode($fileName) . '&code=' . rawurlencode($portalCode);
      $dst = $htmlDirName . '/' . $fileName;
      $fetchCmds[] = '/tool fetch url="' . $url . '" dst-path="' . $dst . '" mode=https keep-result=yes';
    }
    $imgUrl = $assetBase . '?file=' . rawurlencode('img/load-connect.gif') . '&code=' . rawurlencode($portalCode);
    $fetchCmds[] = '/tool fetch url="' . $imgUrl . '" dst-path="' . $htmlDirName . '/img/load-connect.gif" mode=https keep-result=yes';
    $fetchResult = ros_exec($fetchCmds);
    fs_nas_base_assert_command_result($fetchResult,'transferir os arquivos do portal','transferência do portal');
  } else {
    $mkdir = function($path) use ($sftp) {
      @ssh2_sftp_mkdir($sftp, $path, 0775, true);
    };
    $mkdir($htmlDirPath);
    $mkdir($htmlDirPath . '/img');

    $writeFile = function($path, $source) use ($sftp) {
      $stream = @fopen('ssh2.sftp://' . $sftp . $path, 'w');
      if (!$stream) {
        throw new RuntimeException('Não foi possível escrever o arquivo ' . $path);
      }
      fwrite($stream, $source);
      fclose($stream);
    };

    $filesToCopy = [
      'index.html',
      'status.html',
      'redirect.html',
      'login.html',
      'logout.html',
      'error.html'
    ];
    foreach ($filesToCopy as $fileName) {
      $fullPath = __DIR__ . '/../../assets/nas/' . $fileName;
      if (!is_file($fullPath)) continue;
      $content = file_get_contents($fullPath);
      $content = str_replace('https://firecdn.com.br', fs_public_base_url($pdo), $content);
      if (strpos($content, 'name="fast_id"') !== false) {
        $content = preg_replace('/name="fast_id" value="[^"]*"/','name="fast_id" value="'.htmlspecialchars($portalCode, ENT_QUOTES, 'UTF-8').'"',$content);
      }
      $writeFile($htmlDirPath . '/' . $fileName, $content);
    }
    $gifLocal = __DIR__ . '/../../assets/nas/img/load-connect.gif';
    if (is_file($gifLocal)) {
      $gifContent = file_get_contents($gifLocal);
      $writeFile($htmlDirPath . '/img/load-connect.gif', $gifContent);
    }
  }

  unset($sftp);
  unset($conn);

  if ($prevHost !== false) putenv('ROS_HOST='.$prevHost); else putenv('ROS_HOST');
  if ($prevUser !== false) putenv('ROS_USER='.$prevUser); else putenv('ROS_USER');
  if ($prevPass !== false) putenv('ROS_PASS='.$prevPass); else putenv('ROS_PASS');
  if ($prevPort !== false) putenv('ROS_PORT='.$prevPort); else putenv('ROS_PORT');

  partner_admin_audit($pdo,(int)$host['id'],'firespot',admin_id(),'hotspot.applied','partner_hotspot',fs_partner_hotspot_id($host),[
    'nas_id'=>(int)$host['nas_id'],'vlan_id'=>(int)$host['vlan_id'],'routeros_transport'=>$useScpFallback?'fetch':'sftp'
  ]);

  echo json_encode([
    'ok' => true,
    'message' => 'Configuração aplicada com sucesso.',
    'hotspot_id' => fs_partner_hotspot_id($host),
  ]);
} catch (Throwable $e) {
  if (isset($prevHost)) { if ($prevHost !== false) putenv('ROS_HOST='.$prevHost); else putenv('ROS_HOST'); }
  if (isset($prevUser)) { if ($prevUser !== false) putenv('ROS_USER='.$prevUser); else putenv('ROS_USER'); }
  if (isset($prevPass)) { if ($prevPass !== false) putenv('ROS_PASS='.$prevPass); else putenv('ROS_PASS'); }
  if (isset($prevPort)) { if ($prevPort !== false) putenv('ROS_PORT='.$prevPort); else putenv('ROS_PORT'); }
  if(isset($pdo,$host)&&is_array($host)&&!empty($host['id'])){try{partner_admin_audit($pdo,(int)$host['id'],'firespot',admin_id(),'hotspot.apply_failed','partner_hotspot',fs_partner_hotspot_id($host),['error_type'=>get_class($e)]);}catch(Throwable $auditError){}}
  http_response_code(400);
  $publicError=$e instanceof InvalidArgumentException||$e instanceof RuntimeException?substr($e->getMessage(),0,300):'Não foi possível aplicar a configuração no NAS.';
  echo json_encode(['ok' => false, 'error' => $publicError]);
}
