<?php
// SEC-005: webhook VIP legado sem assinatura retirado. O fluxo canônico de
// novas vendas é portal-v3/api/webhook.php, assinado por pedido.
http_response_code(410);
exit;

// Webhook público configurado no painel do MP (MP_NOTIFICATION_URL)
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/mp_client.php';

// + Novo: helpers para Mikrotik e WhatsApp
// Mantido WhatsApp; removemos bypass Mikrotik e passamos a aplicar VIP via RADIUS
require_once __DIR__ . '/../app/lib/promo_api.php';  // promo_api_send($to,$msg)  -> meujames
require_once __DIR__ . '/api/radius_vip.php';        // vip_apply_radius($ref)

http_response_code(200); // responde 200 rápido (o MP só precisa disso)

try {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  // DEBUG opcional:
  // error_log('[MP webhook] RAW: '.$raw);

  if (!is_array($j))
    exit;

  $payment_id = '';
  if (isset($j['data']['id']))
    $payment_id = (string) $j['data']['id'];
  // fallback antigo (mantido)
  if ($payment_id === '' && isset($_GET['data_id']))
    $payment_id = (string) $_GET['data_id'];

  if ($payment_id === '')
    exit;

  // Consulta o pagamento na API (mantido)
  $pay = mp_get_payment($payment_id);
  if (!isset($pay['status']))
    exit;

  // Precisamos do token de sessão (external_reference)
  if (!isset($pay['external_reference']))
    exit;
  $ref = (string) $pay['external_reference'];

  // Só atuamos quando aprovado
  if ($pay['status'] !== 'approved')
    exit;

  // ======================
  // Atualiza pedido (mantido)
  // ======================
  $pdo = db();
  $st = $pdo->prepare(
  'UPDATE vip_orders
      SET status="paid",
          paid_at=NOW(),
          updated_at=NOW(),
          mp_payment_id=?
    WHERE external_ref=?'
);

  $st->execute([$payment_id, $ref]);

  // =======================================================
  // BUSCA DADOS para autenticar no MikroTik e avisar no zap
  // - Tenta vip_orders primeiro (telefone, valor_centavos, duracao_min)
  // - Para ip/mac/phone ou minutos ausentes, tenta payments_session (token = external_reference)
  // =======================================================
  $ip = $mac = '';
  $phone = '';
  $minutes = 1440; // padrão: 24h
  $valorCentavos = null;

  // 1) tenta puxar da vip_orders (colunas locais)
  try {
    $q = $pdo->prepare("SELECT telefone, valor_centavos, duracao_min FROM vip_orders WHERE external_ref=? LIMIT 1");
    $q->execute([$ref]);
    if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
      if (!empty($row['telefone']))
        $phone = preg_replace('/\\D+/', '', (string) $row['telefone']);
      if (!empty($row['valor_centavos']))
        $valorCentavos = (int)$row['valor_centavos'];
      if (!empty($row['duracao_min']))
        $minutes = max(1, (int)$row['duracao_min']);
    }
  } catch (Throwable $e) {
    // ok, tenta a tabela de sessão
  }

  // 2) fallback: tabela de sessão (se você estiver usando)
  if ($ip === '' || $mac === '' || $phone === '') {
    try {
      $q2 = $pdo->prepare("SELECT ip, mac, minutes, phone, amount_centavos FROM payments_session WHERE token=? LIMIT 1");
      $q2->execute([$ref]);
      if ($row2 = $q2->fetch(PDO::FETCH_ASSOC)) {
        if ($ip === '' && !empty($row2['ip']))
          $ip = trim($row2['ip']);
        if ($mac === '' && !empty($row2['mac']))
          $mac = strtoupper(trim($row2['mac']));
        if (!empty($row2['minutes']))
          $minutes = max(1, (int) $row2['minutes']);
        if ($phone === '' && !empty($row2['phone']))
          $phone = preg_replace('/\\D+/', '', (string) $row2['phone']);
        if ($valorCentavos === null && isset($row2['amount_centavos']))
          $valorCentavos = (int)$row2['amount_centavos'];
      }
    } catch (Throwable $e) {
      // silencioso
    }
  }

  // ======================
  // APLICA VIP VIA RADIUS (sem bypass)
  // ======================
  try { vip_apply_radius($ref, []); } catch (Throwable $e) { /* best effort */ }

  // ======================
  // AVISO NO WHATSAPP (opcional)
  // ======================
  if ($phone !== '') {
    // normaliza para +55 se faltar DDI
    try {
      // Usa helper do mensageiro quando disponível
      if (function_exists('promo_clean_number')) {
        $p0 = promo_clean_number($phone);
      } else {
        $p0 = preg_replace('/[^+0-9]/','', (string)$phone);
      }
      // Se já vier com +, usa como está
      if (isset($p0[0]) && $p0[0] === '+') {
        $phone = $p0;
      } else {
        // Se já começa com 55 e tiver comprimento de DDI (ex.: 55 + 11 dígitos), só prefixa '+'
        if (strpos($p0, '55') === 0 && strlen($p0) >= 12) {
          $phone = '+' . $p0;
        } else {
          // Caso geral: assume Brasil
          $phone = '+55' . $p0;
        }
      }
    } catch (Throwable $e) {
      // fallback simples
      if ($phone[0] !== '+') $phone = '+55' . $phone;
    }

    // Monta valor em BRL, se disponível
    $valorTxt = '';
    if (is_int($valorCentavos)) {
      $valorTxt = 'R$ ' . number_format($valorCentavos / 100, 2, ',', '.');
    }

    $parts = [];
    $parts[] = '🎉 Pagamento aprovado!';
    if ($valorTxt !== '') $parts[] = 'Valor: ' . $valorTxt;
    $parts[] = 'Acesso VIP: ' . $minutes . ' minuto(s).';
    $parts[] = 'Seu acesso foi ativado. Boa navegação!';
    $msg = implode("\n", $parts);

    try {
      // Usa o mesmo helper do meujames (promo_api.php)
      promo_api_send($phone, $msg);
    } catch (Throwable $e) {
      // error_log('[MP webhook][WHATS] '.$e->getMessage());
    }
  }

  // (Opcional) marque também a sessão como approved (se usar payments_session)
  try {
    $u = $pdo->prepare("UPDATE payments_session SET status='approved', approved_at=NOW(), minutes=IFNULL(minutes, ?)
                        WHERE token=?");
    $u->execute([$minutes, $ref]);
  } catch (Throwable $e) {
    // silencioso
  }

} catch (Throwable $e) {
  // loga se quiser:
  // error_log('[MP webhook] '.$e->getMessage());
}
