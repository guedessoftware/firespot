<?php
require_once __DIR__ . '/../app/admin_auth.php';
admin_require_page();

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/radius_servers.php';
require_once __DIR__ . '/../app/nas_base_provisioning.php';
require_once __DIR__ . '/../app/partner_hotspots.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("SET time_zone='-04:00'");
fs_nas_base_schema_require($pdo);

$csrf = csrf_token();
$titulo = 'NAS';
$pageId = 'nas';

$message = (string)($_SESSION['nas_flash_message'] ?? '');
$error = (string)($_SESSION['nas_flash_error'] ?? '');
unset($_SESSION['nas_flash_message'], $_SESSION['nas_flash_error']);

function sanitize_text(?string $value, int $max = 255): string
{
  $value = trim((string)$value);
  if ($value === '') {
    return '';
  }
  return mb_substr($value, 0, $max);
}

function mask_radius_secret(string $secret): string
{
  $length = strlen($secret);
  if ($length <= 4) return str_repeat('*', $length);
  return substr($secret, 0, 2) . str_repeat('*', max(2, $length - 4)) . substr($secret, -2);
}

function nas_base_status_label(?string $status): string
{
  $labels = [
    'pending' => 'Pendente',
    'applying' => 'Aplicando',
    'ready' => 'Pronta',
    'error' => 'Com erro',
  ];
  return $labels[(string)$status] ?? 'Não iniciada';
}

function nas_health_status_label(?string $status, ?string $routerosVersion = null): string
{
  if ($status === 'ok' && trim((string)$routerosVersion) !== '') return 'Sincronizado';
  if ($status === 'ok') return 'Dados incompletos';
  if ($status === 'error') return 'Falha na consulta';
  return 'Nunca sincronizado';
}

function nas_status_class(?string $status, string $readyValue): string
{
  if ($status === $readyValue) return 'is-ready';
  if ($status === 'error') return 'is-error';
  return 'is-pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'CSRF inválido. Recarregue a página.';
  } elseif (!admin_has_capability('partner.network.manage')) {
    $error = 'Você não possui permissão para gerenciar a infraestrutura.';
  } else {
    $action = $_POST['action'] ?? '';
    try {
      if ($action === 'radius_add') {
        $name = sanitize_text($_POST['radius_name'] ?? '', 120);
        $host = strtolower(sanitize_text($_POST['radius_host'] ?? '', 150));
        $port = (int)($_POST['radius_port'] ?? 1812);
        $secret = sanitize_text($_POST['radius_secret'] ?? '', 180);
        if ($name === '' || $host === '' || $secret === '') {
          throw new RuntimeException('Informe nome, host e shared secret do servidor RADIUS.');
        }
        if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9.-]+$/i', $host)) {
          throw new RuntimeException('Informe um IP ou hostname válido para o servidor RADIUS.');
        }
        if ($port < 1 || $port > 65535) {
          throw new RuntimeException('Informe uma porta válida para o servidor RADIUS.');
        }
        radius_server_create($pdo, ['name' => $name, 'host' => $host, 'port' => $port, 'secret' => $secret]);
        $message = 'Servidor RADIUS cadastrado com sucesso.';
      } elseif ($action === 'radius_delete') {
        $id = (int)($_POST['radius_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('ID de servidor RADIUS inválido.');
        radius_server_delete($pdo, $id);
        $message = 'Servidor RADIUS removido.';
      } elseif ($action === 'create') {
        $nasname = sanitize_text($_POST['nasname'] ?? '', 128);
        $shortname = sanitize_text($_POST['shortname'] ?? '', 32);
        $type = sanitize_text($_POST['type'] ?? '', 30) ?: 'mikrotik';
        $ports = $_POST['ports'] === '' ? null : max(0, (int)$_POST['ports']);
        $secret = sanitize_text($_POST['secret'] ?? '', 60);
        $server = sanitize_text($_POST['server'] ?? '', 64);
        $community = sanitize_text($_POST['community'] ?? '', 50);
        $mgmtUser = sanitize_text($_POST['mgmt_username'] ?? '', 64);
        $mgmtPass = sanitize_text($_POST['mgmt_password'] ?? '', 128);
        $mgmtPort = $_POST['mgmt_port'] === '' ? 22 : (int)$_POST['mgmt_port'];
        $radiusServerId = (int)($_POST['radius_server_id'] ?? 0);
        $description = sanitize_text($_POST['description'] ?? '', 200);
        if ($nasname === '') {
          throw new RuntimeException('Informe o endereço/NAS name.');
        }
        if ($secret === '') {
          throw new RuntimeException('Informe o segredo compartilhado.');
        }
        if (fs_nas_is_mikrotik($type)) {
          if ($mgmtUser === '' || $mgmtPass === '') throw new RuntimeException('Informe o usuário e a senha SSH do MikroTik.');
          if ($mgmtPort < 1 || $mgmtPort > 65535) throw new RuntimeException('Informe uma porta SSH válida.');
          if ($radiusServerId <= 0) throw new RuntimeException('Selecione o servidor RADIUS da base FireSpot.');
        }
        $allocationPolicy=fs_nas_hotspot_policy_schema_ready($pdo)?fs_nas_hotspot_policy_validate($_POST):null;
        $st = $pdo->prepare('INSERT INTO nas (nasname, shortname, type, ports, secret, server, community, mgmt_username, mgmt_password, mgmt_port, description) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$nasname, $shortname ?: null, $type ?: null, $ports, $secret, $server ?: null, $community ?: null, $mgmtUser ?: null, $mgmtPass ?: null, $mgmtPort, $description ?: null]);
        $nasId = (int)$pdo->lastInsertId();
        if($allocationPolicy!==null)fs_nas_hotspot_policy_save($pdo,$nasId,$allocationPolicy,'firespot',null);
        if (fs_nas_is_mikrotik($type)) {
          fs_nas_base_assign($pdo,$nasId,$radiusServerId,true);
          try {
            $prepared = fs_nas_base_provision($pdo,$nasId);
            $message = 'NAS cadastrado e base FireSpot preparada no RouterOS ' . $prepared['routeros_version'] . '.';
          } catch (Throwable $provisionError) {
            $message = 'NAS cadastrado, mas a preparação-base não foi concluída.';
            $error = 'O NAS foi cadastrado e preservado, mas a preparação-base falhou: ' . $provisionError->getMessage() . ' Corrija o acesso e use “Preparar base”.';
          }
        } else {
          $message = 'NAS cadastrado. A preparação automática não se aplica a este tipo de equipamento.';
        }
      } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
          throw new RuntimeException('ID inválido.');
        }
        $current = $pdo->prepare('SELECT * FROM nas WHERE id=? LIMIT 1');
        $current->execute([$id]);
        $existing = $current->fetch();
        if (!$existing) {
          throw new RuntimeException('NAS não encontrado.');
        }
        $nasname = sanitize_text($_POST['nasname'] ?? '', 128);
        $shortname = sanitize_text($_POST['shortname'] ?? '', 32);
        $type = sanitize_text($_POST['type'] ?? '', 30) ?: 'mikrotik';
        $ports = $_POST['ports'] === '' ? null : max(0, (int)$_POST['ports']);
        $secretRaw = sanitize_text($_POST['secret'] ?? '', 60);
        $secret = $secretRaw === '' ? (string)($existing['secret'] ?? '') : $secretRaw;
        $server = sanitize_text($_POST['server'] ?? '', 64);
        $community = sanitize_text($_POST['community'] ?? '', 50);
        $mgmtUserRaw = sanitize_text($_POST['mgmt_username'] ?? '', 64);
        $mgmtPassRaw = sanitize_text($_POST['mgmt_password'] ?? '', 128);
        $mgmtUser = $mgmtUserRaw === '' ? ($existing['mgmt_username'] ?? null) : $mgmtUserRaw;
        $mgmtPass = $mgmtPassRaw === '' ? ($existing['mgmt_password'] ?? null) : $mgmtPassRaw;
        $mgmtPort = $_POST['mgmt_port'] === '' ? ((int)($existing['mgmt_port'] ?? 22) ?: 22) : (int)$_POST['mgmt_port'];
        $radiusServerId = (int)($_POST['radius_server_id'] ?? 0);
        $description = sanitize_text($_POST['description'] ?? '', 200);
        if ($nasname === '') {
          throw new RuntimeException('Informe o endereço/NAS name.');
        }
        if ($secret === '') {
          throw new RuntimeException('Informe o segredo compartilhado.');
        }
        if (fs_nas_is_mikrotik($type)) {
          if (trim((string)$mgmtUser) === '' || (string)$mgmtPass === '') throw new RuntimeException('Informe o usuário e a senha SSH do MikroTik.');
          if ($mgmtPort < 1 || $mgmtPort > 65535) throw new RuntimeException('Informe uma porta SSH válida.');
          if ($radiusServerId <= 0) throw new RuntimeException('Selecione o servidor RADIUS da base FireSpot.');
        }
        $allocationPolicy=fs_nas_hotspot_policy_schema_ready($pdo)?fs_nas_hotspot_policy_validate($_POST):null;
        $baseBefore = fs_nas_base_state($pdo,$id);
        $baseChanged = fs_nas_is_mikrotik($type) && (
          !fs_nas_is_mikrotik($existing['type'] ?? null)
          || (string)$existing['nasname'] !== $nasname
          || (string)$existing['secret'] !== $secret
          || (string)($existing['mgmt_username'] ?? '') !== (string)$mgmtUser
          || (string)($existing['mgmt_password'] ?? '') !== (string)$mgmtPass
          || (int)($existing['mgmt_port'] ?? 22) !== $mgmtPort
          || (int)($baseBefore['radius_server_id'] ?? 0) !== $radiusServerId
        );
        $st = $pdo->prepare('UPDATE nas SET nasname=?, shortname=?, type=?, ports=?, secret=?, server=?, community=?, mgmt_username=?, mgmt_password=?, mgmt_port=?, description=? WHERE id=?');
        $st->execute([$nasname, $shortname ?: null, $type ?: null, $ports, $secret, $server ?: null, $community ?: null, $mgmtUser ?: null, $mgmtPass ?: null, $mgmtPort, $description ?: null, $id]);
        if($allocationPolicy!==null)fs_nas_hotspot_policy_save($pdo,$id,$allocationPolicy,'firespot',null);
        if (fs_nas_is_mikrotik($type)) {
          fs_nas_base_assign($pdo,$id,$radiusServerId,$baseChanged);
          if ($baseChanged || !$baseBefore || ($baseBefore['status'] ?? '') !== 'ready') {
            try {
              $prepared = fs_nas_base_provision($pdo,$id);
              $message = 'NAS atualizado e base FireSpot preparada no RouterOS ' . $prepared['routeros_version'] . '.';
            } catch (Throwable $provisionError) {
              $message = 'NAS atualizado, mas a preparação-base não foi concluída.';
              $error = 'Os dados do NAS foram atualizados, mas a preparação-base falhou: ' . $provisionError->getMessage() . ' Corrija o acesso e tente novamente.';
            }
          } else {
            $message = 'NAS atualizado. A base FireSpot permanece pronta.';
          }
        } else {
          $message = 'NAS atualizado.';
        }
      } elseif ($action === 'provision') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('ID inválido.');
        $prepared = fs_nas_base_provision($pdo,$id);
        $message = 'Base FireSpot reaplicada e validada no RouterOS ' . $prepared['routeros_version'] . '.';
      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
          throw new RuntimeException('ID inválido.');
        }
        $st = $pdo->prepare('DELETE FROM nas WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $message = 'NAS removido.';
      }
    } catch (Throwable $e) {
      $error = admin_public_error($e, 'Não foi possível atualizar a infraestrutura.');
    }
  }
  $_SESSION['nas_flash_message'] = $message;
  $_SESSION['nas_flash_error'] = $error;
  $isRadiusAction = strpos((string)($_POST['action'] ?? ''), 'radius_') === 0;
  $target = $isRadiusAction ? 'nas.php?section=radius#servidores-radius' : 'nas.php#nas-cadastrados';
  header('Location: ' . $target, true, 303);
  exit;
}

$radiusServers = radius_servers_all($pdo);
$nasList = $pdo->query("SELECT n.*,b.radius_server_id,b.status base_status,b.config_revision,b.routeros_version base_routeros_version,
    b.coa_status,b.coa_port,b.coa_checked_at,b.coa_error_code,
    b.attempt_count,b.last_attempt_at,b.provisioned_at,b.last_error_code,b.last_error_detail,
    r.name base_radius_name,r.host base_radius_host,r.port base_radius_port,
    h.status health_status,h.checked_at health_checked_at,h.latency_ms,h.message health_message,h.uptime,
    h.routeros_version health_routeros_version,h.board_model,h.cpu_load,h.memory_free,h.memory_total,
    h.interface_count,h.hotspot_host_count,h.ppp_active_count,h.hotspot_server_count,h.vlan_count,
    h.radius_hotspot_count,h.firespot_radius_count,h.temperature,h.voltage,
    h.last_success_at,h.last_error_at,h.error_detail health_error_detail,
    (SELECT COUNT(*) FROM partner_hotspots ph WHERE ph.nas_id=n.id AND ph.active=1) installation_count
  FROM nas n
  LEFT JOIN nas_base_provisioning b ON b.nas_id=n.id
  LEFT JOIN radius_servers r ON r.id=b.radius_server_id
  LEFT JOIN nas_health h ON h.nas_id=n.id
  ORDER BY n.nasname")->fetchAll();
foreach($nasList as &$nasRow){
  $policy=fs_nas_hotspot_policy($pdo,(int)$nasRow['id']);$nasRow=array_merge($nasRow,$policy);
  $nextVlan=fs_partner_hotspot_next_vlan($pdo,(int)$nasRow['id'],false,$policy);$nasRow['next_vlan']=$nextVlan;
  $nasRow['next_network']=$nextVlan!==null?fs_partner_hotspot_network_suggestion($nextVlan,$policy):null;
}
unset($nasRow);

$statsRaw = $pdo->query(
  "SELECT nasipaddress,
          COUNT(*) AS total_sessions,
          SUM(CASE WHEN acctstoptime IS NULL THEN 1 ELSE 0 END) AS active_sessions,
          COUNT(DISTINCT CASE WHEN nasportid IS NOT NULL AND nasportid <> '' THEN nasportid END) AS total_ports,
          COUNT(DISTINCT CASE WHEN callingstationid IS NOT NULL AND callingstationid <> '' THEN callingstationid END) AS distinct_devices,
          MAX(acctstarttime) AS last_start,
          MAX(acctstoptime) AS last_stop
     FROM radacct
 GROUP BY nasipaddress"
)->fetchAll();

$nasStats = [];
foreach ($statsRaw as $row) {
  $nasStats[$row['nasipaddress']] = $row;
}

$interfacesRaw = $pdo->query('SELECT id, nas_id, interface_name, interface_type, vlan_id, ip_address, mac_address, description FROM nas_interfaces ORDER BY interface_name')->fetchAll();
$interfacesByNas = [];
foreach ($interfacesRaw as $iface) {
  $nasId = (int)$iface['nas_id'];
  $interfacesByNas[$nasId][] = [
    'id' => (int)$iface['id'],
    'name' => $iface['interface_name'],
    'type' => $iface['interface_type'],
    'vlan_id' => $iface['vlan_id'] !== null ? (int)$iface['vlan_id'] : null,
    'ip_address' => $iface['ip_address'],
    'mac_address' => $iface['mac_address'],
    'description' => $iface['description'],
  ];
}

$pppSessionsRaw = $pdo->query('SELECT nas_id,username,service,caller_id,address,uptime,synced_at FROM nas_ppp_active_sessions ORDER BY nas_id,service,username')->fetchAll();
$pppSessionsByNas = [];
foreach ($pppSessionsRaw as $session) {
  $pppSessionsByNas[(int)$session['nas_id']][] = [
    'username' => $session['username'],
    'service' => $session['service'],
    'caller_id' => $session['caller_id'],
    'address' => $session['address'],
    'uptime' => $session['uptime'],
    'synced_at' => $session['synced_at'],
  ];
}

ob_start();
?>

<?php
  $activeNasSessions = 0;
  foreach ($nasStats as $nasStat) $activeNasSessions += (int)($nasStat['active_sessions'] ?? 0);
?>
<section class="fs-workspace-overview" aria-label="Resumo da infraestrutura">
  <div class="fs-workspace-overview__copy">
    <span>Infraestrutura</span>
    <strong>Equipamentos, interfaces e autenticação em uma única área</strong>
    <small>Consulte o estado dos NAS e siga diretamente para os pontos de configuração do RADIUS.</small>
  </div>
  <div class="fs-workspace-stats">
    <div><strong><?= count($nasList) ?></strong><span>NAS</span></div>
    <div><strong><?= count($interfacesRaw) ?></strong><span>Interfaces</span></div>
    <div><strong><?= count($radiusServers) ?></strong><span>RADIUS</span></div>
    <div><strong><?= $activeNasSessions ?></strong><span>Sessões</span></div>
  </div>
  <nav class="fs-workspace-links" aria-label="Atalhos da infraestrutura">
    <a href="#nas-cadastrados">Equipamentos</a>
    <a href="#novo-nas">Adicionar NAS</a>
    <a href="nas.php?section=radius#servidores-radius">Destinos RADIUS</a>
    <a href="infraestrutura.php?section=freeradius">Serviço RADIUS</a>
  </nav>
</section>

<div class="card card--form" id="servidores-radius">
  <div class="card-header card-header--compact">
    <div>
      <h3 class="card-title">Servidores RADIUS</h3>
      <small class="muted">Destinos de autenticação reutilizados pelos estabelecimentos e equipamentos.</small>
    </div>
  </div>
  <form method="post" class="content-grid partner-form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="radius_add">
    <div class="form-field">
      <label for="radius-name">Nome / identificação</label>
      <input id="radius-name" name="radius_name" required maxlength="120" placeholder="ex.: RADIUS Principal">
    </div>
    <div class="form-field">
      <label for="radius-host">Host ou IP</label>
      <input id="radius-host" name="radius_host" required maxlength="150" placeholder="ex.: 192.0.2.254">
    </div>
    <div class="form-field">
      <label for="radius-port">Porta</label>
      <input id="radius-port" name="radius_port" type="number" min="1" max="65535" value="1812" required>
    </div>
    <div class="form-field">
      <label for="radius-secret">Shared secret</label>
      <input id="radius-secret" name="radius_secret" type="password" required maxlength="180" autocomplete="new-password" placeholder="Informe o segredo">
    </div>
    <div class="form-field form-field--actions">
      <button class="theme-btn" type="submit">Adicionar servidor</button>
    </div>
  </form>
  <div class="table-responsive">
    <table class="tabela">
      <thead><tr><th>Nome</th><th>Host</th><th>Porta</th><th>Secret</th><th>Ações</th></tr></thead>
      <tbody>
        <?php if (!$radiusServers): ?>
          <tr><td colspan="5" class="muted">Nenhum servidor RADIUS cadastrado.</td></tr>
        <?php else: foreach ($radiusServers as $server): ?>
          <tr>
            <td><?= htmlspecialchars($server['name']) ?></td>
            <td><?= htmlspecialchars($server['host']) ?></td>
            <td><?= (int)$server['port'] ?></td>
            <td><code><?= htmlspecialchars(mask_radius_secret((string)$server['secret'])) ?></code></td>
            <td>
              <form method="post" data-confirm="Remover este servidor RADIUS?">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="radius_delete">
                <input type="hidden" name="radius_id" value="<?= (int)$server['id'] ?>">
                <button class="theme-btn" type="submit">Excluir</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card card--form" id="novo-nas">
  <div class="card-header card-header--compact">
    <h3 class="card-title">Adicionar NAS</h3>
  </div>
  <form method="post" class="content-grid partner-form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="create">
    <div class="form-field">
      <label for="nas-create-name">Endereço (nasname)</label>
      <input id="nas-create-name" name="nasname" required maxlength="128" placeholder="ex.: 10.5.50.1">
    </div>
    <div class="form-field">
      <label for="nas-create-shortname">Apelido</label>
      <input id="nas-create-shortname" name="shortname" maxlength="32" placeholder="Apelido interno">
    </div>
    <div class="form-field">
      <label for="nas-create-type">Tipo</label>
      <select id="nas-create-type" name="type" required>
        <option value="mikrotik" selected>MikroTik / RouterOS</option>
        <option value="other">Outro equipamento</option>
      </select>
    </div>
    <div class="form-field">
      <label for="nas-create-ports">Portas</label>
      <input id="nas-create-ports" name="ports" type="number" min="0" placeholder="Opcional">
    </div>
    <div class="form-field">
      <label for="nas-create-secret">Shared secret RADIUS do NAS</label>
      <input id="nas-create-secret" name="secret" required maxlength="60" placeholder="Segredo usado pelo equipamento no RADIUS">
    </div>
    <div class="form-field">
      <label for="nas-create-server">Servidor virtual FreeRADIUS</label>
      <input id="nas-create-server" name="server" maxlength="64" placeholder="Opcional">
    </div>
    <div class="form-field">
      <label for="nas-create-community">Community</label>
      <input id="nas-create-community" name="community" maxlength="50" placeholder="Opcional">
    </div>
    <div class="form-field">
      <label for="nas-create-mgmt-user">Usuário SSH</label>
      <input id="nas-create-mgmt-user" name="mgmt_username" maxlength="64" autocomplete="username" placeholder="Usuário do RouterOS" required>
    </div>
    <div class="form-field">
      <label for="nas-create-mgmt-pass">Senha SSH</label>
      <input id="nas-create-mgmt-pass" name="mgmt_password" type="password" maxlength="128" autocomplete="new-password" placeholder="Senha do RouterOS" required>
    </div>
    <div class="form-field">
      <label for="nas-create-mgmt-port">Porta SSH</label>
      <input id="nas-create-mgmt-port" name="mgmt_port" type="number" min="1" max="65535" value="22" required>
    </div>
    <div class="form-field">
      <label for="nas-create-radius-server">RADIUS da base FireSpot</label>
      <select id="nas-create-radius-server" name="radius_server_id" required>
        <option value="">— Selecione —</option>
        <?php foreach ($radiusServers as $radiusServer): ?>
          <option value="<?= (int)$radiusServer['id'] ?>"><?= htmlspecialchars((string)$radiusServer['name']) ?> — <?= htmlspecialchars((string)$radiusServer['host']) ?>:<?= (int)$radiusServer['port'] ?></option>
        <?php endforeach; ?>
      </select>
      <small class="muted">Aplicado no cadastro; VLAN e Hotspot serão configurados somente no ponto.</small>
    </div>
    <?php if(fs_nas_hotspot_policy_schema_ready($pdo)):?>
    <div class="form-field"><label for="nas-create-vlan-start">Primeira VLAN dos pontos</label><input id="nas-create-vlan-start" name="vlan_start" type="number" min="1" max="255" value="100" required></div>
    <div class="form-field"><label for="nas-create-vlan-end">Última VLAN dos pontos</label><input id="nas-create-vlan-end" name="vlan_end" type="number" min="1" max="255" value="200" required></div>
    <div class="form-field"><label for="nas-create-network-template">Modelo de rede</label><input id="nas-create-network-template" name="network_template" value="10.{vlan}.0.0" pattern="10\.\{vlan\}\.0\.0" required><small class="muted">A VLAN ocupa o segundo octeto.</small></div>
    <div class="form-field"><label for="nas-create-prefix">Máscara por ponto</label><select id="nas-create-prefix" name="prefix_length" required><?php foreach(range(16,30) as $prefix):?><option value="<?=$prefix?>" <?=$prefix===24?'selected':''?>>/<?=$prefix?></option><?php endforeach;?></select></div>
    <div class="form-field"><label for="nas-create-gateway-offset">Offset do gateway</label><input id="nas-create-gateway-offset" name="gateway_offset" type="number" min="1" value="1" required></div>
    <div class="form-field"><label for="nas-create-pool-start">Offset inicial do pool</label><input id="nas-create-pool-start" name="pool_start_offset" type="number" min="1" value="2" required></div>
    <div class="form-field"><label for="nas-create-pool-reserve">Reserva final</label><input id="nas-create-pool-reserve" name="pool_end_reserve" type="number" min="1" value="1" required></div>
    <div class="form-field"><label for="nas-create-dns">DNS padrão dos novos pontos</label><input id="nas-create-dns" name="default_dns_servers" value="1.1.1.1,8.8.8.8" required></div>
    <?php endif;?>
    <div class="form-field form-field--full">
      <label for="nas-create-description">Descrição</label>
      <textarea id="nas-create-description" name="description" rows="2" placeholder="Notas sobre o equipamento"></textarea>
    </div>
    <div class="form-field form-field--actions">
      <button class="theme-btn" type="submit">Salvar NAS</button>
    </div>
  </form>
</div>

<?php if ($error): ?>
  <div class="card nas-notice nas-notice--error">
    <?= htmlspecialchars($error) ?>
  </div>
<?php elseif ($message): ?>
  <div class="card nas-notice nas-notice--success">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<div class="card" id="nas-cadastrados">
  <div class="card-header card-header--compact">
    <div><h3 class="card-title">NAS cadastrados</h3><small class="muted">Sincronize para importar versão, hardware, interfaces e estado operacional sem alterar a configuração do MikroTik.</small></div>
    <small class="muted">Total: <?= count($nasList) ?></small>
  </div>
  <div class="nas-operation-flow" aria-label="Fluxo de configuração do NAS">
    <div><span>1</span><p><strong>Sincronizar</strong><small>Lê o equipamento e atualiza o inventário do FireSpot.</small></p></div>
    <div><span>2</span><p><strong>Preparar base</strong><small>Grava somente o RADIUS global no MikroTik.</small></p></div>
    <div><span>3</span><p><strong>Aplicar pontos</strong><small>VLAN, DHCP, Hotspot e portal ficam no estabelecimento.</small></p></div>
  </div>
  <div id="nas-sync-feedback" class="nas-sync-feedback" role="status" aria-live="polite" hidden></div>
  <div class="table-responsive table-responsive--nas">
    <table class="tabela tabela--nas">
      <thead>
        <tr>
          <th>Equipamento</th>
          <th>Estado do MikroTik</th>
          <th>Inventário</th>
          <th>Base FireSpot</th>
          <th>Política de novos pontos</th>
          <th>Acesso de gerência</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$nasList): ?>
          <tr><td colspan="7" class="muted">Nenhum NAS cadastrado.</td></tr>
        <?php else: ?>
          <?php foreach ($nasList as $nas):
            $ip = $nas['nasname'];
            $stats = $nasStats[$ip] ?? null;
            $routerosVersion = (string)($nas['health_routeros_version'] ?: $nas['base_routeros_version'] ?: '');
            $canPrepare = trim((string)($nas['mgmt_username'] ?? '')) !== ''
              && trim((string)($nas['mgmt_password'] ?? '')) !== ''
              && (int)($nas['radius_server_id'] ?? 0) > 0;
            $detail = [
            'id' => (int)$nas['id'],
            'nasname' => $nas['nasname'],
            'shortname' => $nas['shortname'],
            'type' => $nas['type'],
            'ports' => $nas['ports'],
            'secret_set' => trim((string)($nas['secret'] ?? '')) !== '',
            'server' => $nas['server'],
            'community' => $nas['community'],
            'mgmt_username' => $nas['mgmt_username'],
            'mgmt_password_set' => trim((string)($nas['mgmt_password'] ?? '')) !== '',
            'mgmt_port' => $nas['mgmt_port'],
            'description' => $nas['description'],
            'radius_server_id' => (int)($nas['radius_server_id'] ?? 0),
            'vlan_start'=>(int)($nas['vlan_start']??100),'vlan_end'=>(int)($nas['vlan_end']??200),
            'network_template'=>$nas['network_template']??'10.{vlan}.0.0','prefix_length'=>(int)($nas['prefix_length']??24),
            'gateway_offset'=>(int)($nas['gateway_offset']??1),'pool_start_offset'=>(int)($nas['pool_start_offset']??2),
            'pool_end_reserve'=>(int)($nas['pool_end_reserve']??1),'default_dns_servers'=>$nas['default_dns_servers']??'1.1.1.1,8.8.8.8',
            'base_status' => $nas['base_status'] ?? null,
            'base_routeros_version' => $nas['base_routeros_version'] ?? null,
            'coa_status' => $nas['coa_status'] ?? 'unknown',
            'coa_port' => $nas['coa_port'] ?? 3799,
            'coa_checked_at' => $nas['coa_checked_at'] ?? null,
            'coa_error_code' => $nas['coa_error_code'] ?? null,
            'base_radius_name' => $nas['base_radius_name'] ?? null,
            'base_radius_host' => $nas['base_radius_host'] ?? null,
            'base_provisioned_at' => $nas['provisioned_at'] ?? null,
            'base_error' => $nas['last_error_detail'] ?? null,
            'health_status' => $nas['health_status'] ?? null,
            'health_checked_at' => $nas['health_checked_at'] ?? null,
            'health_error' => $nas['health_error_detail'] ?? null,
            'latency_ms' => $nas['latency_ms'] ?? null,
            'uptime' => $nas['uptime'] ?? null,
            'health_routeros_version' => $nas['health_routeros_version'] ?? null,
            'board_model' => $nas['board_model'] ?? null,
            'cpu_load' => $nas['cpu_load'] ?? null,
            'memory_free' => $nas['memory_free'] ?? null,
            'memory_total' => $nas['memory_total'] ?? null,
            'interface_count' => $nas['interface_count'] ?? null,
            'hotspot_host_count' => $nas['hotspot_host_count'] ?? null,
            'ppp_active_count' => $nas['ppp_active_count'] ?? null,
            'hotspot_server_count' => $nas['hotspot_server_count'] ?? null,
            'vlan_count' => $nas['vlan_count'] ?? null,
            'radius_hotspot_count' => $nas['radius_hotspot_count'] ?? null,
            'firespot_radius_count' => $nas['firespot_radius_count'] ?? null,
            'installation_count' => (int)($nas['installation_count'] ?? 0),
            'temperature' => $nas['temperature'] ?? null,
            'voltage' => $nas['voltage'] ?? null,
            'stats' => $stats,
            'interfaces' => $interfacesByNas[(int)$nas['id']] ?? [],
            'ppp_sessions' => $pppSessionsByNas[(int)$nas['id']] ?? [],
          ];
            ?>
            <tr data-nas='<?= json_encode($detail, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' class="js-nas-row">
              <td data-label="Equipamento">
                <div class="nas-device"><strong><?= htmlspecialchars((string)($nas['shortname'] ?: $nas['nasname'])) ?></strong><code><?= htmlspecialchars((string)$nas['nasname']) ?></code><small>#<?= (int)$nas['id'] ?> · <?= $routerosVersion !== '' ? 'MikroTik' : htmlspecialchars((string)($nas['type'] ?: 'não identificado')) ?></small></div>
              </td>
              <td data-label="Estado do MikroTik">
                <span class="nas-status <?= ($nas['health_status'] ?? null)==='ok'&&$routerosVersion!==''?'is-ready':nas_status_class($nas['health_status'] ?? null,'__never__') ?>"><?= htmlspecialchars(nas_health_status_label($nas['health_status'] ?? null,$routerosVersion)) ?></span>
                <?php if ($routerosVersion !== ''): ?><small class="nas-meta">RouterOS <?= htmlspecialchars($routerosVersion) ?><?= !empty($nas['board_model']) ? ' · ' . htmlspecialchars((string)$nas['board_model']) : '' ?></small><?php endif; ?>
                <small class="nas-meta"><?= !empty($nas['health_checked_at']) ? 'Última consulta: ' . htmlspecialchars((string)$nas['health_checked_at']) : 'Use “Sincronizar” para consultar o equipamento.' ?></small>
                <?php if (($nas['health_status'] ?? '') === 'error'): ?><small class="nas-meta is-error"><?= htmlspecialchars((string)($nas['health_error_detail'] ?? 'Falha não identificada.')) ?></small><?php endif; ?>
              </td>
              <td data-label="Inventário">
                <strong><?= $nas['interface_count'] !== null ? (int)$nas['interface_count'] : count($interfacesByNas[(int)$nas['id']] ?? []) ?> interfaces estruturais</strong>
                <?php if ($nas['hotspot_server_count'] !== null || $nas['vlan_count'] !== null): ?>
                  <small class="nas-meta">No MikroTik: <?= $nas['hotspot_server_count'] !== null ? (int)$nas['hotspot_server_count'] : '—' ?> Hotspots · <?= $nas['vlan_count'] !== null ? (int)$nas['vlan_count'] : '—' ?> VLANs</small>
                <?php else: ?>
                  <small class="nas-meta">Topologia remota ainda não sincronizada</small>
                <?php endif; ?>
                <small class="nas-meta">No FireSpot: <?= (int)($nas['installation_count'] ?? 0) ?> pontos · <?= (int)($stats['active_sessions'] ?? 0) ?> sessões ativas</small>
                <small class="nas-meta">Clientes Hotspot: <?= $nas['hotspot_host_count'] !== null ? (int)$nas['hotspot_host_count'] : '—' ?></small>
                <small class="nas-meta">Serviços externos: <?= $nas['ppp_active_count'] !== null ? (int)$nas['ppp_active_count'] : count($pppSessionsByNas[(int)$nas['id']] ?? []) ?> sessões PPP</small>
                <?php if ($nas['latency_ms'] !== null): ?><small class="nas-meta">Latência: <?= (int)$nas['latency_ms'] ?> ms</small><?php endif; ?>
              </td>
              <td data-label="Base FireSpot">
                <span class="nas-status <?= nas_status_class($nas['base_status'] ?? null,'ready') ?>"><?= htmlspecialchars(nas_base_status_label($nas['base_status'] ?? null)) ?></span>
                <small class="nas-meta"><?= !empty($nas['base_radius_host']) ? htmlspecialchars((string)($nas['base_radius_name'] ?: 'RADIUS')) . ' · ' . htmlspecialchars((string)$nas['base_radius_host']) : 'RADIUS ainda não definido' ?></small>
                <?php if ($nas['firespot_radius_count'] === null): ?>
                  <small class="nas-meta is-pending">No MikroTik: ainda não conferida</small>
                <?php elseif ((int)$nas['firespot_radius_count'] === 1): ?>
                  <small class="nas-meta is-success">No MikroTik: entrada FireSpot confirmada</small>
                <?php elseif ((int)$nas['firespot_radius_count'] === 0): ?>
                  <small class="nas-meta is-error">No MikroTik: entrada FireSpot ausente</small>
                <?php else: ?>
                  <small class="nas-meta is-error">No MikroTik: <?= (int)$nas['firespot_radius_count'] ?> entradas FireSpot encontradas</small>
                <?php endif; ?>
                <?php if ($nas['radius_hotspot_count'] !== null): ?><small class="nas-meta">Total RADIUS Hotspot: <?= (int)$nas['radius_hotspot_count'] ?></small><?php endif; ?>
                <small class="nas-meta <?= ($nas['coa_status'] ?? '') === 'ready' ? 'is-success' : (($nas['coa_status'] ?? '') === 'error' ? 'is-error' : 'is-pending') ?>">CoA: <?= ($nas['coa_status'] ?? '') === 'ready' ? 'pronto na porta ' . (int)$nas['coa_port'] : (($nas['coa_status'] ?? '') === 'error' ? 'indisponível' : 'ainda não conferido') ?></small>
                <?php if (($nas['base_status'] ?? '') === 'error' && !empty($nas['last_error_detail'])): ?><small class="nas-meta is-error"><?= htmlspecialchars((string)$nas['last_error_detail']) ?></small><?php endif; ?>
              </td>
              <td data-label="Política de novos pontos">
                <strong>VLAN <?=(int)$nas['vlan_start']?>–<?=(int)$nas['vlan_end']?> · /<?=(int)$nas['prefix_length']?></strong>
                <small class="nas-meta"><?=htmlspecialchars((string)$nas['network_template'])?> · DNS <?=htmlspecialchars((string)$nas['default_dns_servers'])?></small>
                <?php if(is_array($nas['next_network']??null)):?><small class="nas-meta is-success">Próxima: VLAN <?=(int)$nas['next_vlan']?> · <?=htmlspecialchars((string)$nas['next_network']['cidr'])?> · gateway <?=htmlspecialchars((string)$nas['next_network']['gateway_ip'])?></small><?php else:?><small class="nas-meta is-error">Faixa automática esgotada</small><?php endif;?>
              </td>
              <td data-label="Acesso de gerência"><strong><?= $nas['mgmt_username'] ? htmlspecialchars((string)$nas['mgmt_username']) : 'Usuário ausente' ?></strong><small class="nas-meta">SSH <?= (int)($nas['mgmt_port'] ?: 22) ?> · senha <?= $nas['mgmt_password'] ? 'informada' : 'ausente' ?></small><small class="nas-meta">Shared secret <?= $nas['secret'] ? 'informado' : 'ausente' ?></small></td>
              <td data-label="Ações" class="table-actions">
                <button class="theme-btn js-info" type="button">Detalhes</button>
                <button class="theme-btn apply-btn js-sync" type="button" data-nas-id="<?= (int)$nas['id'] ?>">Sincronizar</button>
                <button class="theme-btn js-edit" type="button">Editar</button>
                <?php if ($canPrepare): ?>
                <form method="post" class="nas-action-form" data-confirm="Esta ação grava ou atualiza somente a base RADIUS global no MikroTik. Deseja continuar?">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="provision">
                  <input type="hidden" name="id" value="<?= (int)$nas['id'] ?>">
                  <button class="theme-btn" type="submit"><?= ($nas['base_status'] ?? '') === 'ready' ? 'Reaplicar base' : 'Preparar base' ?></button>
                </form>
                <?php else: ?><small class="nas-action-hint">Edite e informe SSH e RADIUS para preparar a base.</small>
                <?php endif; ?>
                <form method="post" class="nas-action-form is-delete" data-confirm="Confirma remover este NAS?">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$nas['id'] ?>">
                  <button class="theme-btn" type="submit">Excluir</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="modal-edit" class="modal-backdrop" aria-hidden="true">
  <div class="card card--modal card--modal-wide">
    <div class="card-header card-header--compact">
      <h3 class="card-title">Editar NAS</h3>
      <button type="button" class="theme-btn" id="modal-close">✕</button>
    </div>
    <form method="post" id="form-edit" class="content-grid partner-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="edit-id">
      <div class="form-field">
        <label for="edit-nasname">Endereço (nasname)</label>
        <input name="nasname" id="edit-nasname" required maxlength="128">
      </div>
      <div class="form-field">
        <label for="edit-shortname">Apelido</label>
        <input name="shortname" id="edit-shortname" maxlength="32">
      </div>
      <div class="form-field">
        <label for="edit-type">Tipo</label>
        <select name="type" id="edit-type" required>
          <option value="mikrotik">MikroTik / RouterOS</option>
          <option value="other">Outro equipamento</option>
        </select>
      </div>
      <div class="form-field">
        <label for="edit-ports">Portas</label>
        <input name="ports" id="edit-ports" type="number" min="0">
      </div>
      <div class="form-field">
        <label for="edit-secret">Shared secret RADIUS do NAS</label>
        <input name="secret" id="edit-secret" maxlength="60" type="password" autocomplete="new-password" placeholder="Deixe vazio para manter">
      </div>
      <div class="form-field">
        <label for="edit-server">Servidor</label>
        <input name="server" id="edit-server" maxlength="64">
      </div>
      <div class="form-field">
        <label for="edit-community">Community</label>
        <input name="community" id="edit-community" maxlength="50">
      </div>
      <div class="form-field">
        <label for="edit-mgmt-user">Usuário SSH</label>
        <input name="mgmt_username" id="edit-mgmt-user" maxlength="64">
      </div>
      <div class="form-field">
        <label for="edit-mgmt-pass">Senha SSH</label>
        <input name="mgmt_password" id="edit-mgmt-pass" maxlength="128" type="password">
      </div>
      <div class="form-field">
        <label for="edit-mgmt-port">Porta SSH</label>
        <input name="mgmt_port" id="edit-mgmt-port" type="number" min="1" max="65535">
      </div>
      <div class="form-field">
        <label for="edit-radius-server">RADIUS da base FireSpot</label>
        <select name="radius_server_id" id="edit-radius-server" required>
          <option value="">— Selecione —</option>
          <?php foreach ($radiusServers as $radiusServer): ?>
            <option value="<?= (int)$radiusServer['id'] ?>"><?= htmlspecialchars((string)$radiusServer['name']) ?> — <?= htmlspecialchars((string)$radiusServer['host']) ?>:<?= (int)$radiusServer['port'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if(fs_nas_hotspot_policy_schema_ready($pdo)):?>
      <div class="form-field"><label for="edit-vlan-start">Primeira VLAN dos pontos</label><input name="vlan_start" id="edit-vlan-start" type="number" min="1" max="255" required></div>
      <div class="form-field"><label for="edit-vlan-end">Última VLAN dos pontos</label><input name="vlan_end" id="edit-vlan-end" type="number" min="1" max="255" required></div>
      <div class="form-field"><label for="edit-network-template">Modelo de rede</label><input name="network_template" id="edit-network-template" pattern="10\.\{vlan\}\.0\.0" required><small class="muted">Formato atual: 10.{vlan}.0.0</small></div>
      <div class="form-field"><label for="edit-prefix-length">Máscara por ponto</label><select name="prefix_length" id="edit-prefix-length" required><?php foreach(range(16,30) as $prefix):?><option value="<?=$prefix?>">/<?=$prefix?></option><?php endforeach;?></select></div>
      <div class="form-field"><label for="edit-gateway-offset">Offset do gateway</label><input name="gateway_offset" id="edit-gateway-offset" type="number" min="1" required></div>
      <div class="form-field"><label for="edit-pool-start-offset">Offset inicial do pool</label><input name="pool_start_offset" id="edit-pool-start-offset" type="number" min="1" required></div>
      <div class="form-field"><label for="edit-pool-end-reserve">Reserva final</label><input name="pool_end_reserve" id="edit-pool-end-reserve" type="number" min="1" required></div>
      <div class="form-field"><label for="edit-default-dns">DNS padrão dos novos pontos</label><input name="default_dns_servers" id="edit-default-dns" required></div>
      <?php endif;?>
      <div class="form-field form-field--full">
        <label for="edit-description">Descrição</label>
        <textarea name="description" id="edit-description" rows="2"></textarea>
      </div>
      <div class="form-field form-field--actions">
        <button class="theme-btn" type="submit">Atualizar</button>
      </div>
    </form>
  </div>
</div>

<div id="modal-info" class="modal-backdrop" aria-hidden="true">
  <div class="card card--modal nas-info-modal" role="dialog" aria-modal="true" aria-labelledby="nas-info-title">
    <div class="card-header card-header--compact nas-info-header">
      <div class="nas-info-heading">
        <span class="nas-info-eyebrow">Infraestrutura MikroTik</span>
        <h3 class="card-title" id="nas-info-title">Detalhes do NAS</h3>
        <small id="info-heading-name">Equipamento</small>
      </div>
      <div class="table-actions"><button type="button" class="theme-btn apply-btn" id="info-sync">Sincronizar agora</button><button type="button" class="theme-btn" id="info-close">✕</button></div>
    </div>
    <nav class="nas-info-tabs" role="tablist" aria-label="Detalhes do NAS">
      <button type="button" class="nas-info-tab is-active" id="nas-tab-overview" data-nas-tab="overview" role="tab" aria-selected="true" aria-controls="nas-panel-overview">Visão geral</button>
      <button type="button" class="nas-info-tab" id="nas-tab-interfaces" data-nas-tab="interfaces" role="tab" aria-selected="false" aria-controls="nas-panel-interfaces">Interfaces <span id="info-interface-count">0</span></button>
      <button type="button" class="nas-info-tab" id="nas-tab-ppp" data-nas-tab="ppp" role="tab" aria-selected="false" aria-controls="nas-panel-ppp">Sessões PPP <span id="info-ppp-count">0</span></button>
    </nav>
    <div class="info-body">
      <section class="nas-info-panel" id="nas-panel-overview" data-nas-panel="overview" role="tabpanel" aria-labelledby="nas-tab-overview">
        <div class="nas-info-identity">
          <div><strong id="info-shortname"></strong><span id="info-nasname"></span></div>
          <div class="nas-info-identity__status"><span class="nas-status" id="info-health-status">Sem dados</span><small id="info-sync-at"></small></div>
        </div>

        <div class="nas-info-kpis">
          <article><span>Interfaces</span><strong id="info-ports">0</strong><small>estruturais</small></article>
          <article><span>Hotspot</span><strong id="info-hotspot-hosts">0</strong><small>clientes ativos</small></article>
          <article><span>Serviços externos</span><strong id="info-ppp-active">0</strong><small>sessões PPP</small></article>
          <article><span>FireSpot</span><strong id="info-installations">0</strong><small>pontos</small></article>
        </div>

        <div class="nas-info-groups">
          <article class="nas-info-group">
            <header><span class="nas-info-group__icon">F</span><div><strong>Operação FireSpot</strong><small>Autenticação e uso</small></div></header>
            <dl>
              <div><dt>Base</dt><dd id="info-base-status"></dd></div>
              <div><dt>RADIUS</dt><dd id="info-radius"></dd></div>
              <div><dt>Sessões ativas</dt><dd id="info-active"></dd></div>
              <div><dt>Histórico</dt><dd id="info-total"></dd></div>
              <div><dt>Dispositivos</dt><dd id="info-distinct"></dd></div>
              <div><dt>Último início</dt><dd id="info-last-start"></dd></div>
              <div><dt>Último término</dt><dd id="info-last-stop"></dd></div>
            </dl>
          </article>

          <article class="nas-info-group">
            <header><span class="nas-info-group__icon">M</span><div><strong>Equipamento</strong><small>Recursos do RouterOS</small></div></header>
            <dl>
              <div><dt>RouterOS</dt><dd id="info-routeros"></dd></div>
              <div><dt>Modelo</dt><dd id="info-board"></dd></div>
              <div><dt>Uptime</dt><dd id="info-uptime"></dd></div>
              <div><dt>CPU</dt><dd id="info-cpu"></dd></div>
              <div><dt>Memória livre</dt><dd id="info-memory"></dd></div>
              <div><dt>Latência</dt><dd id="info-latency"></dd></div>
              <div><dt>Temperatura / tensão</dt><dd id="info-hardware-health"></dd></div>
            </dl>
          </article>

          <article class="nas-info-group">
            <header><span class="nas-info-group__icon">R</span><div><strong>Rede e gerência</strong><small>Topologia e acesso técnico</small></div></header>
            <dl>
              <div><dt>Tipo</dt><dd id="info-type"></dd></div>
              <div><dt>Hotspots</dt><dd id="info-hotspot-servers"></dd></div>
              <div><dt>VLANs</dt><dd id="info-vlans"></dd></div>
              <div><dt>RADIUS Hotspot</dt><dd id="info-radius-hotspot"></dd></div>
              <div><dt>Base no NAS</dt><dd id="info-firespot-radius"></dd></div>
              <div><dt>Promoção CoA</dt><dd id="info-coa"></dd></div>
              <div><dt>SSH</dt><dd><span id="info-mgmt-user"></span>:<span id="info-mgmt-port"></span></dd></div>
            </dl>
          </article>
        </div>
      </section>

      <section class="nas-info-panel" id="nas-panel-interfaces" data-nas-panel="interfaces" role="tabpanel" aria-labelledby="nas-tab-interfaces" hidden>
        <div class="nas-info-panel-heading"><div><h4>Interfaces de infraestrutura</h4><p>Interfaces estáticas identificadas pelo tipo retornado pelo RouterOS.</p></div><span class="nas-safe-badge">Disponíveis para análise técnica</span></div>
        <div id="info-interfaces" class="interfaces-list nas-info-table"></div>
      </section>

      <section class="nas-info-panel" id="nas-panel-ppp" data-nas-panel="ppp" role="tabpanel" aria-labelledby="nas-tab-ppp" hidden>
        <div class="nas-info-panel-heading"><div><h4>Sessões PPP ativas</h4><p>Snapshot de <code>/ppp active</code>; não são interfaces dos pontos FireSpot.</p></div><span class="nas-external-badge">Serviço externo ao FireSpot</span></div>
        <div id="info-ppp-sessions" class="interfaces-list nas-info-table"></div>
      </section>
    </div>
  </div>
</div>


<?php
$conteudo = ob_get_clean();
include 'layout.php';
