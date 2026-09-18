<?php
// /portal/login_cpf.php — Hubsoft (CPF + Telefone), com promoção automática a ISP_UNL
require_once __DIR__ . '/../app/session_boot.php';

require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/hubsoft_api.php'; // getAccessToken(), hubsoftRequest()

// (opcional) utilidades se existirem no seu projeto
if (file_exists(__DIR__ . '/../app/auth_utils.php')) {
    require_once __DIR__ . '/../app/auth_utils.php';
}

$csrf = csrf_token();

/* =========================================================
   Normalização & validação
   ========================================================= */
function only_digits(string $s): string
{
    return preg_replace('/\D+/', '', $s) ?? '';
}

function normalize_cpf(string $s): string
{
    return only_digits($s);
}

function is_valid_cpf(string $cpf): bool
{
    $cpf = normalize_cpf($cpf);
    if (strlen($cpf) !== 11)
        return false;
    if (preg_match('/^(\\d)\\1{10}$/', $cpf))
        return false;
    $sum = 0;
    for ($i = 0, $w = 10; $i < 9; $i++, $w--)
        $sum += intval($cpf[$i]) * $w;
    $d1 = ($sum * 10) % 11;
    if ($d1 === 10)
        $d1 = 0;
    if ($d1 !== intval($cpf[9]))
        return false;
    $sum = 0;
    for ($i = 0, $w = 11; $i < 10; $i++, $w--)
        $sum += intval($cpf[$i]) * $w;
    $d2 = ($sum * 10) % 11;
    if ($d2 === 10)
        $d2 = 0;
    return $d2 === intval($cpf[10]);
}

function normalize_phone_br(string $s): string
{
    $d = only_digits($s);
    if (strpos($d, '55') === 0 && strlen($d) >= 12)
        $d = substr($d, 2); // remove +55
    if (strlen($d) > 11)
        $d = substr($d, -11); // mantém no máx 11 (DDD + 8/9)
    return $d;
}

/** compara telefones com tolerância (com/sem DDI/DDD/9º dígito) */
function phones_match(string $informed, string $fromHubsoft): bool
{
    $a = normalize_phone_br($informed);
    $b = normalize_phone_br($fromHubsoft);
    if ($a === '' || $b === '')
        return false;
    if ($a === $b)
        return true;
    foreach ([11, 10, 9, 8] as $len) {
        if (strlen($a) >= $len && strlen($b) >= $len) {
            if (substr($a, -$len) === substr($b, -$len))
                return true;
        }
    }
    return false;
}

/* =========================================================
   Fallbacks (caso não existam nas suas utils)
   ========================================================= */
if (!function_exists('upsert_radcheck_password')) {
    function upsert_radcheck_password(PDO $pdo, string $username, string $clearPassword): void
    {
        if ($username === '' || $clearPassword === '') {
            return;
        }

        $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute='Cleartext-Password'")
            ->execute([$username]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?,?,':=',?)")
            ->execute([$username, 'Cleartext-Password', $clearPassword]);
    }
}

/** PROMOÇÃO COMPLETA PARA PROVEDOR (limpa limites e aplica ISP_UNL) */
function promote_to_provider(PDO $pdo, string $username): void
{
    if ($username === '')
        return;
    $pdo->beginTransaction();
    try {
        // Remover limites de visitante/VIP
        $pdo->prepare("DELETE FROM firespot.radcheck WHERE username=? AND attribute IN ('Max-All-Session','Expiration','Simultaneous-Use')")
            ->execute([$username]);
        $pdo->prepare("DELETE FROM firespot.radreply WHERE username=? AND attribute IN ('Session-Timeout','Idle-Timeout','Mikrotik-Rate-Limit','Acct-Interim-Interval')")
            ->execute([$username]);

        // Remover grupos de visitante
        $pdo->prepare("DELETE FROM firespot.radusergroup WHERE username=? AND groupname IN ('Plano_Padrao','VIP_24H','PREMIUM_DAY')")
            ->execute([$username]);

        // Garantir grupo ISP_UNL (priority 1)
        $pdo->prepare("DELETE FROM firespot.radusergroup WHERE username=? AND groupname='ISP_UNL'")
            ->execute([$username]);

        try {
            $pdo->prepare("INSERT INTO firespot.radusergroup (username, groupname, priority) VALUES (?,?,1)")
                ->execute([$username, 'ISP_UNL']);
        } catch (\PDOException $e) {
            // Em alguns schemas a coluna id é NOT NULL sem auto-inc;
            if (($e->errorInfo[1] ?? 0) == 1364) {
                $nextId = (int) $pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM firespot.radusergroup")->fetchColumn();
                $pdo->prepare("INSERT INTO firespot.radusergroup (id, username, groupname, priority) VALUES (?,?,?,1)")
                    ->execute([$nextId, $username, 'ISP_UNL']);
            } else {
                throw $e;
            }
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* =========================================================
   Hubsoft — endpoint e chaves (usa telefone_primario + telefone_secundario)
   ========================================================= */
/**
 * Consulta:
 *   /api/v1/integracao/cliente?busca=cpf_cnpj&termo_busca={CPF}
 * Retorna:
 *   ['ok'=>bool, 'cliente'=>array|null, 'ativo'=>bool, 'servico_habilitado'=>bool,
 *    'tel_primario'=>string|null, 'tel_secundario'=>string|null]
 */
function hubsoft_fetch_cliente_por_cpf(string $cpf): array
{
    $cpf = normalize_cpf($cpf);
    $endpoint = "/api/v1/integracao/cliente?busca=cpf_cnpj&termo_busca={$cpf}";
    $dados = hubsoftRequest($endpoint, 'GET', null);

    if (empty($dados) || !is_array($dados)) {
        return ['ok' => false, 'cliente' => null, 'ativo' => false, 'servico_habilitado' => false, 'tel_primario' => null, 'tel_secundario' => null];
    }

    $cliente = $dados[0];

    // ativo?
    $ativo = !empty($cliente['ativo']);

    // pelo menos um serviço habilitado?
    $servicoHabil = false;
    if (!empty($cliente['servicos']) && is_array($cliente['servicos'])) {
        foreach ($cliente['servicos'] as $srv) {
            if (isset($srv['status']) && (string) $srv['status'] === "Serviço Habilitado") {
                $servicoHabil = true;
                break;
            }
        }
    }

    // telefones preferenciais
    $telPrim = !empty($cliente['telefone_primario']) ? (string) $cliente['telefone_primario'] : null;
    $telSec = !empty($cliente['telefone_secundario']) ? (string) $cliente['telefone_secundario'] : null;

    // fallbacks adicionais
    if (!$telPrim && !empty($cliente['telefone']))
        $telPrim = (string) $cliente['telefone'];
    if (!$telSec && !empty($cliente['celular']))
        $telSec = (string) $cliente['celular'];

    return [
        'ok' => true,
        'cliente' => $cliente,
        'ativo' => $ativo,
        'servico_habilitado' => $servicoHabil,
        'tel_primario' => $telPrim,
        'tel_secundario' => $telSec,
    ];
}

/* =========================================================
   Handler
   ========================================================= */
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $err = 'Requisição inválida.';
    } else {
        $cpfRaw = trim($_POST['cpf'] ?? '');
        $telRaw = trim($_POST['phone'] ?? '');

        $cpf = normalize_cpf($cpfRaw);
        $telInput = normalize_phone_br($telRaw);

        if (!is_valid_cpf($cpf)) {
            $err = 'CPF inválido.';
        } elseif (strlen($telInput) < 10 || strlen($telInput) > 11) {
            $err = 'Telefone inválido. Informe DDD + número (10 ou 11 dígitos).';
        } else {
            try {
                $pdo = db();
                $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("SET time_zone='-04:00'");

                // 1) Hubsoft
                $r = hubsoft_fetch_cliente_por_cpf($cpf);
                if (!$r['ok'] || !$r['cliente']) {
                    $err = 'CPF não encontrado.';
                } elseif (!$r['ativo']) {
                    $err = 'CPF não consta como ATIVO no provedor.';
                } elseif (!$r['servico_habilitado']) {
                    $err = 'Acesso Negado: Serviço não habilitado. Contate o suporte.';
                } else {
                    // 2) Telefone confere com primário OU secundário?
                    $telPrim = $r['tel_primario'] ?? '';
                    $telSec = $r['tel_secundario'] ?? '';

                    $okPhone = false;
                    if ($telPrim && phones_match($telInput, $telPrim))
                        $okPhone = true;
                    if (!$okPhone && $telSec && phones_match($telInput, $telSec))
                        $okPhone = true;

                    if (!$okPhone) {
                        $err = 'Telefone não confere com o cadastro.';
                    } else {
                        // 3) Autenticação aprovada → PROMOVER a PROVEDOR
                        //    - senha temporária para auto-login de hotspot
                        //    - limpa limites e aplica ISP_UNL
                        $tempPass = bin2hex(random_bytes(6)); // 12 hex chars

                        upsert_radcheck_password($pdo, $cpf, $tempPass);
                        promote_to_provider($pdo, $cpf); // <<< chave da mudança

                        // (opcional) reconcilia grupos premium do seu sistema, se existir
                        if (function_exists('reconcile_premium_group')) {
                            reconcile_premium_group($pdo, $cpf);
                        }

                        // Registro mínimo em clientes_info (guarda telefone normalizado)
                        $pdo->prepare("
                            INSERT INTO firespot.clientes_info (cpf, telefone)
                            VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE telefone = COALESCE(VALUES(telefone), telefone)
                        ")->execute([$cpf, $telInput]);

                        // Sessões p/ auto-login no Hotspot
                        $_SESSION['cliente_username'] = $cpf;
                        $_SESSION['hotspot_auto'] = [
                            'username' => $cpf,
                            'password' => $tempPass,
                            'ts' => time()
                        ];

                        // Se já capturou contexto do Hotspot → auto-post; senão vai p/ conta
                        $hasCtx = !empty($_SESSION['hotspot_ctx']['data']['link-login-only']);
                        header("Location: " . ($hasCtx ? "hotspot_do_login.php" : "cliente.php"));
                        exit;
                    }
                }

            } catch (\Throwable $e) {
                error_log('login_cpf (hubsoft prim/seg): ' . $e->getMessage());
                $err = 'Serviço indisponível. Tente novamente.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login (Cliente do Provedor)</title>
    <link rel="stylesheet" href="assets/css/portal.css">
    <script>
        // Máscaras de CPF e Telefone
        document.addEventListener('DOMContentLoaded', () => {
            const icpf = document.querySelector('input[name="cpf"]');
            const itel = document.querySelector('input[name="phone"]');

            const maskCPF = (v) => {
                v = (v || '').replace(/\D+/g, '').slice(0, 11);
                if (v.length > 9) v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{0,2}).*/, "$1.$2.$3-$4");
                else if (v.length > 6) v = v.replace(/^(\d{3})(\d{3})(\d{0,3}).*/, "$1.$2.$3");
                else if (v.length > 3) v = v.replace(/^(\d{3})(\d{0,3}).*/, "$1.$2");
                return v;
            };
            const maskPhone = (v) => {
                v = (v || '').replace(/\D+/g, '').slice(0, 11);
                if (v.length <= 10) {
                    return v.replace(/^(\d{0,2})(\d{0,4})(\d{0,4}).*/, (m, a, b, c) =>
                        [a ? `(${a}` + (a.length === 2 ? ') ' : '') : '', b, (b && c ? '-' : ''), c].join('')
                    );
                } else {
                    return v.replace(/^(\d{0,2})(\d{0,5})(\d{0,4}).*/, (m, a, b, c) =>
                        [a ? `(${a}` + (a.length === 2 ? ') ' : '') : '', b, (b && c ? '-' : ''), c].join('')
                    );
                }
            };

            icpf?.addEventListener('input', () => {
                const p = icpf.selectionStart, before = icpf.value;
                icpf.value = maskCPF(before);
                const d = icpf.value.length - before.length;
                icpf.selectionStart = icpf.selectionEnd = (p + d >= 0) ? p + d : icpf.value.length;
            }, { passive: true });

            itel?.addEventListener('input', () => {
                const p = itel.selectionStart, before = itel.value;
                itel.value = maskPhone(before);
                const d = itel.value.length - before.length;
                itel.selectionStart = itel.selectionEnd = (p + d >= 0) ? p + d : itel.value.length;
            }, { passive: true });

            // Envia só dígitos
            const form = document.querySelector('#login_cpf_form');
            form?.addEventListener('submit', () => {
                icpf.value = (icpf.value || '').replace(/\D+/g, '').slice(0, 11);
                itel.value = (itel.value || '').replace(/\D+/g, '').slice(0, 11);
            });
        });
    </script>
</head>

<body>
    <div class="header inline"><a href="index.php">← Voltar</a><strong>Login por CPF</strong><span></span></div>
    <div class="container">
        <div class="card">
            <?php if (!empty($err)): ?>
                <div class="notice" style="color:#b91c1c;border-color:#fecaca;background:#fee2e2;">
                    <?= htmlspecialchars($err) ?></div>
            <?php endif; ?>

            <form id="login_cpf_form" method="post" novalidate>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <div class="grid">
                    <div>
                        <label>CPF</label>
                        <input type="text" name="cpf" inputmode="numeric" placeholder="000.000.000-00" maxlength="14"
                            required>
                    </div>
                    <div>
                        <label>Telefone (DDD + número)</label>
                        <input type="tel" name="phone" inputmode="numeric" placeholder="(92) 9XXXX-XXXX" maxlength="16"
                            required>
                    </div>
                </div>
                <br>
                <button class="btn primary" type="submit">Entrar</button>
            </form>

            <p class="muted" style="margin-top:8px">
                Conferimos seu CPF e telefone com o cadastro do provedor. Se os dados estiverem corretos, liberamos
                seu acesso completo automaticamente.
            </p>
        </div>
    </div>
</body>

</html>
