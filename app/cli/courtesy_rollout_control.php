<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../courtesy_rollout.php';

$args = $argv ?? [];
$apply = in_array('--apply', $args, true);
$json = in_array('--json', $args, true);
$options = [];
foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $match)) $options[$match[1]] = trim($match[2]);
}
$global = strtolower((string)($options['global'] ?? ''));
$portal = strtolower((string)($options['portal'] ?? ''));
$mode = strtolower((string)($options['mode'] ?? ''));
$partnerSelector = (string)($options['partner'] ?? '');
$allPartners = in_array('--all-partners', $args, true);
$allowLowSample = in_array('--allow-low-sample', $args, true);
$allowMismatch = in_array('--allow-mismatch', $args, true);
$acceptWarnings = in_array('--accept-warnings', $args, true);
$minShadow = 5;
if (isset($options['min-shadow']) && ctype_digit($options['min-shadow'])) $minShadow = max(1, min(10000, (int)$options['min-shadow']));

if ($global !== '' && !in_array($global, ['on', 'off'], true)) {
    fwrite(STDERR, "--global aceita on ou off.\n");
    exit(2);
}
if (($portal === '') xor ($mode === '')) {
    fwrite(STDERR, "--portal e --mode devem ser informados juntos.\n");
    exit(2);
}
if ($portal !== '' && !array_key_exists($portal, fs_courtesy_rollout_portals())) {
    fwrite(STDERR, "Portal inválido.\n");
    exit(2);
}
if ($mode !== '' && !in_array($mode, ['legacy', 'shadow', 'enforce'], true)) {
    fwrite(STDERR, "Modo inválido.\n");
    exit(2);
}
if ($portal !== '' && !$allPartners && $partnerSelector === '') {
    fwrite(STDERR, "Informe --partner=<id|code> ou --all-partners.\n");
    exit(2);
}
if ($global === '' && $portal === '') {
    fwrite(STDERR, "Nada a alterar. Exemplos:\n"
        . "  php app/cli/courtesy_rollout_control.php --global=on\n"
        . "  php app/cli/courtesy_rollout_control.php --partner=CODIGO --portal=v2 --mode=enforce\n"
        . "  php app/cli/courtesy_rollout_control.php --global=off --apply\n");
    exit(2);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$partners = [];
if ($portal !== '') {
    if ($allPartners) {
        $partners = $pdo->query('SELECT id,code,name,active FROM partners ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        if (ctype_digit($partnerSelector)) {
            $st = $pdo->prepare('SELECT id,code,name,active FROM partners WHERE id=? LIMIT 1');
            $st->execute([(int)$partnerSelector]);
        } else {
            $st = $pdo->prepare('SELECT id,code,name,active FROM partners WHERE code=? LIMIT 1');
            $st->execute([$partnerSelector]);
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) $partners[] = $row;
    }
    if (!$partners) {
        fwrite(STDERR, "Estabelecimento não encontrado.\n");
        exit(2);
    }
}

$currentGlobal = fs_courtesy_setting_bool($pdo, 'courtesy_cutover_enabled', false);
$desiredGlobal = $global === '' ? $currentGlobal : $global === 'on';
$radiusReady = fs_courtesy_setting_bool($pdo, 'courtesy_radius_ready', false);
$mikrotikReady = fs_courtesy_setting_bool($pdo, 'courtesy_mikrotik_ready', false);
$actions = [];
if ($global !== '') $actions[] = ['type' => 'global_gate', 'from' => $currentGlobal ? 'on' : 'off', 'to' => $global];

foreach ($partners as $partner) {
    $partnerId = (int)$partner['id'];
    $policy = fs_courtesy_policy_resolve($pdo, $partnerId);
    $current = fs_courtesy_rollout_mode($pdo, $partnerId, $portal);
    if ($mode === 'enforce' && $current !== 'enforce') {
        $errors = fs_courtesy_policy_validate($policy);
        if ((int)$partner['active'] !== 1) $errors[] = 'estabelecimento inativo';
        if (!$desiredGlobal) $errors[] = 'gate global permaneceria fechado';
        if ($policy['enforcement_method'] === 'radius' && !$radiusReady) $errors[] = 'RADIUS não está pronto';
        if ($policy['enforcement_method'] === 'mikrotik_local' && !$mikrotikReady) $errors[] = 'MikroTik local não está pronto';
        $warnings = fs_courtesy_policy_warnings($policy);
        if ($warnings && !$acceptWarnings) $errors[] = 'política possui alertas: ' . implode(', ', $warnings) . ' (use --accept-warnings após revisão)';
        $st = $pdo->prepare('SELECT COUNT(*) total,SUM(decision_match=0) mismatches FROM courtesy_shadow_events
            WHERE partner_id=? AND portal=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY');
        $st->execute([$partnerId, $portal]);
        $evidence = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $shadowTotal = (int)($evidence['total'] ?? 0);
        $shadowMismatches = (int)($evidence['mismatches'] ?? 0);
        if ($shadowTotal < $minShadow && !$allowLowSample) {
            $errors[] = 'amostra shadow insuficiente (' . $shadowTotal . '/' . $minShadow . '; use --allow-low-sample somente em canário controlado)';
        }
        if ($shadowMismatches > 0 && !$allowMismatch) {
            $errors[] = $shadowMismatches . ' divergência(s) shadow (use --allow-mismatch somente após análise explícita)';
        }
        if ($errors) {
            fwrite(STDERR, $partner['name'] . ': ' . implode('; ', array_unique($errors)) . PHP_EOL);
            exit(2);
        }
    }
    $actions[] = [
        'type' => 'rollout',
        'partner_id' => $partnerId,
        'partner_code' => (string)$partner['code'],
        'partner_name' => (string)$partner['name'],
        'portal' => $portal,
        'from' => $current,
        'to' => $mode,
    ];
}

if ($apply) {
    $pdo->beginTransaction();
    try {
        if ($global !== '') {
            $st = $pdo->prepare("INSERT INTO app_settings (skey,svalue) VALUES ('courtesy_cutover_enabled',?)
                ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)");
            $st->execute([$desiredGlobal ? '1' : '0']);
            $stampKey = $desiredGlobal ? 'courtesy_cutover_started_at' : 'courtesy_cutover_stopped_at';
            $st = $pdo->prepare("INSERT INTO app_settings (skey,svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)");
            $st->execute([$stampKey, gmdate('Y-m-d H:i:s')]);
        }
        foreach ($partners as $partner) {
            fs_courtesy_rollout_save($pdo, (int)$partner['id'], $portal, $mode, 'cli-rollout');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

$result = [
    'ok' => true,
    'applied' => $apply,
    'actions' => $actions,
    'rollback_command' => 'php app/cli/courtesy_rollout_control.php --global=off --apply',
];
if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo $apply ? "Alterações aplicadas:\n" : "Dry-run; alterações propostas:\n";
    foreach ($actions as $action) {
        if ($action['type'] === 'global_gate') {
            echo '  gate global: ' . $action['from'] . ' -> ' . $action['to'] . PHP_EOL;
        } else {
            echo '  ' . $action['partner_name'] . ' / ' . $action['portal'] . ': ' . $action['from'] . ' -> ' . $action['to'] . PHP_EOL;
        }
    }
    if (!$apply) echo "Use --apply para confirmar.\n";
    echo 'Rollback imediato: ' . $result['rollback_command'] . PHP_EOL;
}
