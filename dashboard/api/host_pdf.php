<?php
require_once __DIR__ . '/../../app/admin_auth.php';
if (!admin_is_authenticated()) { http_response_code(403); exit('Acesso negado'); }
try { admin_require_capability('partners.view'); }
catch (Throwable $e) { http_response_code(403); exit('Acesso negado'); }

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/public_url.php';
require_once __DIR__ . '/../../app/lib/fpdf.php';
require_once __DIR__ . '/../../app/lib/phpqrcode.php';
require_once __DIR__ . '/../../app/partner_hotspots.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$hotspotId = isset($_GET['hotspot_id']) ? (int)$_GET['hotspot_id'] : 0;
if ($id <= 0 && $hotspotId <= 0) {
  http_response_code(400);
  exit('ID inválido');
}

if (fs_partner_hotspots_schema_ready($pdo)) {
  $host = $hotspotId > 0 ? fs_partner_hotspot_by_id($pdo,$hotspotId,null,false) : fs_partner_hotspot_default($pdo,$id,false);
  if ($host) {
    $st = $pdo->prepare('SELECT nasname,shortname FROM nas WHERE id=? LIMIT 1');
    $st->execute([(int)($host['nas_id'] ?? 0)]);
    $nas = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $host += ['nasname'=>$nas['nasname'] ?? null,'shortname'=>$nas['shortname'] ?? null];
  }
} else {
  $st = $pdo->prepare('SELECT p.*, n.nasname, n.shortname FROM partners p LEFT JOIN nas n ON n.id = p.nas_id WHERE p.id=? LIMIT 1');
  $st->execute([$id]);
  $host = $st->fetch();
}
if (!$host) {
  http_response_code(404);
  exit('Host não encontrado');
}

$code = fs_partner_hotspot_public_code($host);
$name = (string)$host['name'] . (!empty($host['hotspot_name']) ? ' — ' . (string)$host['hotspot_name'] : '');
$vlan = $host['vlan_id'];
$gateway = $host['gateway_ip'];
$dnsServers = $host['dns_servers'];
$dnsName = $host['dns_name'];
$poolStart = $host['pool_start'];
$poolEnd = $host['pool_end'];
$nasLabel = trim($host['nasname'] . ' ' . ($host['shortname'] ? '(' . $host['shortname'] . ')' : ''));

$publicBase = fs_public_base_url($pdo);
$qrData = (string)($host['portal_mode'] ?? '') === 'v3'
  ? $publicBase . '/portal-v3/index.php?hotspot=' . urlencode($code)
  : $publicBase . '/portal/index.php?fast_id=' . urlencode($code);
$tmpQr = tempnam(sys_get_temp_dir(), 'qr');
QRcode::png($qrData, $tmpQr, QR_ECLEVEL_M, 6);

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetAuthor('FireSpot');
$pdf->SetTitle('Instruções Wi-Fi - ' . $code);

// Header
$pdf->SetFillColor(215,154,35);
$pdf->Rect(0,0,210,40,'F');
if (is_file(__DIR__ . '/../../assets/img/logo-fire.png')) {
  $pdf->Image(__DIR__.'/../../assets/img/logo-fire.png', 15, 10, 40, 0, 'PNG');
}
$pdf->SetY(12);
$pdf->SetTextColor(255,255,255);
$pdf->SetFont('Helvetica','B',22);
$pdf->Cell(0,10,utf8_decode('FIRENETWORK'),0,1,'R');
$pdf->SetFont('Helvetica','',13);
$pdf->Cell(0,8,utf8_decode("Guia rápido de conexão"),0,1,'R');
$pdf->SetY(48);
$pdf->SetTextColor(0,0,0);
$pdf->SetFont('Helvetica','B',21);
$pdf->Cell(0,8,utf8_decode($name),0,1,'C');
$pdf->Ln(4);

// Instructions block
$pdf->SetFont('Helvetica','B',14);
$pdf->Cell(0,8,'Como conectar',0,1,'L');
$pdf->SetFont('Helvetica','',11);
$pdf->MultiCell(0,6,utf8_decode('Ligue o Wi-Fi do seu celular ou tablet.'));
$pdf->Ln(1);
$pdf->SetFont('Helvetica','',11);
$pdf->Write(6, utf8_decode('Conecte-se à rede '));
$pdf->SetFont('Helvetica','B',12.1);
$pdf->Write(6, utf8_decode('FireSpot'));
$pdf->SetFont('Helvetica','',11);
$pdf->Write(6, utf8_decode('.'));
$pdf->Ln(7);
$pdf->MultiCell(0,6,utf8_decode('Se houver o botão, escaneie o QR Code abaixo ou faça login para liberar a internet.'));
$pdf->Ln(1);
$pdf->MultiCell(0,6,utf8_decode('Pronto! Aproveite o Wi-Fi com segurança.'));
$pdf->Ln(2);
$pdf->Ln(5);

// QR code (centralizado e maior)
$x = $pdf->GetX();
$y = $pdf->GetY();
$pdf->Image($tmpQr, 55, $y, 96, 96, 'PNG');
$pdf->SetXY($x, $y + 102);
$pdf->SetFont('Helvetica','I',10);
$publicLabel = preg_replace('~^https?://~i', '', $publicBase) . '/portal';
$pdf->Cell(0,6,'Escaneie o QR Code para abrir o portal ou acesse: ' . $publicLabel,0,1,'C');

// Footer note
$pdf->Ln(10);

unlink($tmpQr);

$filename = 'host_' . preg_replace('/[^A-Za-z0-9_-]/','', $code) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.$filename.'"');
$pdf->Output('I', $filename);
