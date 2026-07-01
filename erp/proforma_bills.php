<?php
/**
 * proforma_bills.php
 * Fast list page with separate payment page link.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'proforma_bills.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function pb_fast_table_exists(mysqli $conn, string $table): bool
{
    try {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) {
            $res->free();
        }
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function pb_fast_money($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function pb_fast_status_class($balance): string
{
    return ((float)$balance <= 0) ? 'paid' : 'pending';
}

function pb_fast_mobile($mobile): string
{
    $mobile = preg_replace('/\D+/', '', (string)$mobile);
    if ($mobile === '') {
        return '';
    }
    return strlen($mobile) === 10 ? '91' . $mobile : $mobile;
}

function pb_fast_base_url(mysqli $conn): string
{
    $setting = '';
    try {
        if (pb_fast_table_exists($conn, 'system_settings')) {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key IN ('site_url','base_url','app_url') AND TRIM(setting_value) <> '' ORDER BY FIELD(setting_key,'site_url','base_url','app_url') LIMIT 1");
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $setting = trim((string)($row['setting_value'] ?? ''));
        }
    } catch (Throwable $e) {
        $setting = '';
    }

    if ($setting !== '') {
        return rtrim($setting, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return rtrim($scheme . '://' . $host . ($dir === '' || $dir === '/' ? '' : $dir), '/');
}

function pb_fast_tracking_url(mysqli $conn, array $row): string
{
    $token = trim((string)($row['tracking_token'] ?? ''));
    if ($token === '') {
        return '';
    }
    return pb_fast_base_url($conn) . '/customer_tracking.php?token=' . rawurlencode($token);
}

function pb_fast_whatsapp_url(mysqli $conn, array $row): string
{
    $mobile = pb_fast_mobile($row['mobile'] ?? '');
    if ($mobile === '') {
        return '#';
    }

    $customer = trim((string)($row['customer_name'] ?? 'Customer')) ?: 'Customer';
    $proformaNo = trim((string)($row['proforma_no'] ?? '-')) ?: '-';
    $jobCardNo = trim((string)($row['job_card_no'] ?? '-')) ?: '-';
    $functionName = trim((string)($row['function_name'] ?? '-')) ?: '-';
    $orderType = ucfirst((string)($row['order_type'] ?? '-'));
    $finalAmount = pb_fast_money($row['final_amount'] ?? 0);
    $advanceAmount = pb_fast_money($row['advance_amount'] ?? 0);
    $balanceAmount = pb_fast_money($row['balance_amount'] ?? 0);
    $trackingUrl = pb_fast_tracking_url($conn, $row);

    $message = "Hi {$customer},

"
        . "Greetings from Subhiksha Cards.

"
        . "Your proforma bill / sales order has been created.

"
        . "Proforma No: {$proformaNo}
"
        . "Job Card No: {$jobCardNo}
"
        . "Function/Product: {$functionName}
"
        . "Order Type: {$orderType}
"
        . "Final Amount: {$finalAmount}
"
        . "Advance Paid: {$advanceAmount}
"
        . "Balance Amount: {$balanceAmount}

";

    if ($trackingUrl !== '') {
        $message .= "Track your order here:
{$trackingUrl}

";
    } else {
        $message .= "Tracking link will be shared once job card tracking is ready.

";
    }

    $message .= "Thank you,
Subhiksha Cards";

    return 'https://wa.me/' . $mobile . '?text=' . rawurlencode($message);
}

function pb_fast_whatsapp_svg(): string
{
    return '<svg viewBox="0 0 32 32" width="17" height="17" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16.04 3C8.85 3 3 8.73 3 15.78c0 2.26.61 4.47 1.77 6.41L3 29l7.02-1.8a13.3 13.3 0 0 0 6.02 1.43C23.23 28.63 29 22.9 29 15.85S23.23 3 16.04 3Zm0 23.45c-1.9 0-3.76-.5-5.39-1.45l-.39-.23-4.16 1.07 1.11-4.01-.26-.41a11.05 11.05 0 0 1-1.73-5.64c0-5.84 4.85-10.6 10.82-10.6 5.96 0 10.81 4.76 10.81 10.67 0 5.84-4.85 10.6-10.81 10.6Zm5.93-7.95c-.32-.16-1.9-.92-2.2-1.03-.3-.11-.52-.16-.74.16-.22.32-.85 1.03-1.04 1.24-.19.22-.38.24-.7.08-.32-.16-1.36-.49-2.59-1.55-.96-.84-1.61-1.88-1.8-2.2-.19-.32-.02-.49.14-.65.14-.14.32-.38.49-.57.16-.19.22-.32.32-.54.11-.22.05-.41-.03-.57-.08-.16-.74-1.76-1.01-2.41-.27-.65-.54-.54-.74-.55h-.63c-.22 0-.57.08-.87.41-.3.32-1.14 1.09-1.14 2.68s1.17 3.12 1.33 3.34c.16.22 2.3 3.46 5.58 4.85.78.33 1.39.53 1.86.68.78.24 1.49.21 2.05.13.63-.09 1.9-.76 2.17-1.49.27-.73.27-1.36.19-1.49-.08-.13-.3-.21-.62-.37Z"/></svg>';
}

if (empty($_SESSION['proforma_csrf'])) {
    $_SESSION['proforma_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['proforma_csrf'];
$message = '';
$messageType = 'success';
$toastTitle = 'Info';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'payment_collected') {
    $message = 'Payment collected successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'payment_cancelled') {
    $message = 'Payment cancelled and balance reverted successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'job_created') {
    $message = 'Job card created successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'deleted') {
    $message = 'Proforma bill deleted successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif (!empty($_GET['err'])) {
    $message = 'Error: ' . trim((string)$_GET['err']);
    $messageType = 'danger';
    $toastTitle = 'Failed';
}

$rows = [];
try {
    $sql = "
        SELECT
            pb.id,
            pb.proforma_no,
            pb.customer_name,
            pb.mobile,
            pb.order_type,
            pb.balance_amount,
            pb.final_amount,
            pb.advance_amount,
            COALESCE(ps.status_name, '-') AS status_name,
            COALESCE(ft.function_name, '-') AS function_name,
            jc.job_card_no,
            jc.tracking_token
        FROM proforma_bills pb
        LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id
        LEFT JOIN function_types ft ON ft.id = pb.function_type_id
        LEFT JOIN (
            SELECT proforma_bill_id, MAX(job_card_no) AS job_card_no, MAX(tracking_token) AS tracking_token
            FROM job_cards
            GROUP BY proforma_bill_id
        ) jc ON jc.proforma_bill_id = pb.id
        ORDER BY pb.id DESC
        LIMIT 150
    ";
    $res = $conn->query($sql);
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();
} catch (Throwable $e) {
    $message = 'Unable to load proforma bills: ' . $e->getMessage();
    $messageType = 'danger';
    $toastTitle = 'Failed';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Proforma Bills - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
    .toast-ui{border:0;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.18);overflow:hidden;min-width:320px;max-width:420px}.toast-ui.success{background:#dcfce7;color:#14532d}.toast-ui.danger{background:#fee2e2;color:#7f1d1d}.toast-title{font-size:14px;font-weight:900}.toast-message{font-size:13px;font-weight:800;line-height:1.45}
    .proforma-page .page-head{padding:24px 28px;margin-bottom:18px}.proforma-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}.card-pad{padding:24px}.section-title{font-size:18px;font-weight:900;color:var(--text-main);margin-bottom:6px}
    .proforma-list-table{table-layout:fixed;width:100%;min-width:1180px}.proforma-list-table th,.proforma-list-table td{vertical-align:middle!important}.proforma-list-table th{font-size:12px;text-transform:uppercase;color:var(--text-muted)}.proforma-list-table th:nth-child(1),.proforma-list-table td:nth-child(1){width:15%}.proforma-list-table th:nth-child(2),.proforma-list-table td:nth-child(2){width:20%}.proforma-list-table th:nth-child(3),.proforma-list-table td:nth-child(3){width:15%}.proforma-list-table th:nth-child(4),.proforma-list-table td:nth-child(4){width:10%}.proforma-list-table th:nth-child(5),.proforma-list-table td:nth-child(5){width:11%}.proforma-list-table th:nth-child(6),.proforma-list-table td:nth-child(6){width:10%}.proforma-list-table th:nth-child(7),.proforma-list-table td:nth-child(7){width:19%}
    .status-pill{font-size:11px;font-weight:900;border-radius:999px;padding:6px 10px;background:#dbeafe;color:#1d4ed8;display:inline-flex;align-items:center;justify-content:center;line-height:1.2}.status-pill.paid{background:#dcfce7;color:#166534}.status-pill.pending{background:#fef3c7;color:#92400e}.balance-text{font-weight:900;color:#991b1b}.balance-text.paid{color:#166534}.action-buttons{display:flex;gap:6px;justify-content:flex-end;align-items:center;flex-wrap:nowrap;white-space:nowrap}.action-buttons form{display:inline-flex;margin:0}.btn-action-icon,.btn-delete-icon{width:34px!important;height:34px!important;min-width:34px!important;max-width:34px!important;padding:0!important;border-radius:50%!important;display:inline-flex!important;align-items:center!important;justify-content:center!important}.btn-action-icon svg,.btn-delete-icon svg{width:15px!important;height:15px!important;stroke-width:2.5!important}.btn-whatsapp-icon{background:#22c55e!important;border-color:#22c55e!important;color:#fff!important}.btn-whatsapp-icon:hover{background:#16a34a!important;border-color:#16a34a!important;color:#fff!important}.mobile-cards{display:none}.mobile-card{border:1px solid var(--border-soft);border-radius:18px;padding:16px;margin-bottom:12px;background:var(--card-bg)}
    @media(max-width:767.98px){.proforma-page .page-head{padding:18px;border-radius:18px}.proforma-page .page-head h1{font-size:24px}.card-pad{padding:16px;border-radius:18px}.desktop-table{display:none!important}.mobile-cards{display:block}.mobile-actions{display:grid;grid-template-columns:repeat(5,42px);gap:8px;margin-top:14px}.btn-action-icon,.btn-delete-icon{width:42px!important;height:42px!important;min-width:42px!important;max-width:42px!important}}
    </style>
</head>
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main id="main">
        <?php include __DIR__ . '/includes/nav.php'; ?>
        <section class="page-section proforma-page">
            <div class="card-ui page-head">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div>
                        <h1 class="mb-1">Proforma Bills / Sales Orders</h1>
                        <p class="text-muted-custom mb-0">Fast list view. Use payment page to collect or cancel payments.</p>
                    </div>
                    <a href="create_proforma.php" class="btn btn-primary rounded-pill px-4 fw-bold">Create Proforma Bill</a>
                </div>
            </div>

            <?php if ($message !== ''): ?>
            <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000">
                <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4200">
                    <div class="d-flex"><div class="toast-body"><div class="toast-title"><?= e($toastTitle) ?></div><div class="toast-message"><?= e($message) ?></div></div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card-ui card-pad">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                    <div>
                        <div class="section-title">Proforma Bill List</div>
                        <p class="text-muted-custom mb-0">Only required columns are shown.</p>
                    </div>
                    <input type="search" id="tableSearch" class="form-control" style="max-width:340px" placeholder="Search...">
                </div>

                <div class="table-responsive desktop-table">
                    <table class="table-ui proforma-list-table" id="proformaTable">
                        <thead><tr><th>No</th><th>Customer</th><th>Function</th><th>Order Type</th><th>Balance</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="7" class="text-center text-muted-custom py-4">No proforma bills found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <?php $balance = (float)($row['balance_amount'] ?? 0); $paidClass = $balance <= 0 ? 'paid' : 'pending'; ?>
                            <tr>
                                <td><strong><?= e($row['proforma_no'] ?? '-') ?></strong></td>
                                <td><?= e($row['customer_name'] ?? '-') ?><small class="d-block text-muted-custom"><?= e($row['mobile'] ?? '-') ?></small></td>
                                <td><?= e($row['function_name'] ?? '-') ?></td>
                                <td><?= e(ucfirst((string)($row['order_type'] ?? '-'))) ?></td>
                                <td><span class="balance-text <?= e($paidClass) ?>"><?= e(pb_fast_money($balance)) ?></span></td>
                                <td><span class="status-pill <?= e($paidClass) ?>"><?= $balance <= 0 ? 'Paid' : e($row['status_name'] ?? '-') ?></span></td>
                                <td class="text-end">
                                    <div class="action-buttons">
                                        <a title="View" href="proforma_bill_view.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-secondary btn-action-icon"><i data-lucide="eye"></i></a>
                                        <a title="Payment" href="proforma_payment.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-success btn-action-icon"><i data-lucide="indian-rupee"></i></a>
                                        <a title="Send WhatsApp" href="<?= e(pb_fast_whatsapp_url($conn, $row)) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-whatsapp-icon btn-action-icon"><?= pb_fast_whatsapp_svg() ?></a>
                                        <?php if (empty($row['job_card_no'])): ?>
                                        <form method="post" action="api/proforma_bills.php" class="js-api-job-card-form" onsubmit="return false;">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="create_job_card">
                                            <input type="hidden" name="proforma_id" value="<?= (int)$row['id'] ?>">
                                            <button title="Create Job Card" type="submit" class="btn btn-sm btn-primary btn-action-icon"><i data-lucide="briefcase-business"></i></button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="post" action="api/proforma_bills.php" class="js-api-delete-form" onsubmit="return false;">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete_record">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button title="Delete" type="submit" class="btn btn-sm btn-outline-danger btn-delete-icon"><i data-lucide="trash-2"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mobile-cards" id="mobileCards">
                    <?php foreach ($rows as $row): ?>
                    <?php $balance = (float)($row['balance_amount'] ?? 0); $paidClass = $balance <= 0 ? 'paid' : 'pending'; ?>
                    <div class="mobile-card">
                        <div class="d-flex justify-content-between gap-2">
                            <div>
                                <strong><?= e($row['proforma_no'] ?? '-') ?></strong>
                                <small class="d-block text-muted-custom"><?= e($row['customer_name'] ?? '-') ?> · <?= e($row['mobile'] ?? '-') ?></small>
                                <small class="d-block text-muted-custom"><?= e($row['function_name'] ?? '-') ?> · <?= e(ucfirst((string)($row['order_type'] ?? '-'))) ?></small>
                                <small class="d-block balance-text <?= e($paidClass) ?>">Balance: <?= e(pb_fast_money($balance)) ?></small>
                            </div>
                            <span class="status-pill <?= e($paidClass) ?>"><?= $balance <= 0 ? 'Paid' : e($row['status_name'] ?? '-') ?></span>
                        </div>
                        <div class="mobile-actions">
                            <a title="View" href="proforma_bill_view.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-secondary btn-action-icon"><i data-lucide="eye"></i></a>
                            <a title="Payment" href="proforma_payment.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-success btn-action-icon"><i data-lucide="indian-rupee"></i></a>
                            <a title="Send WhatsApp" href="<?= e(pb_fast_whatsapp_url($conn, $row)) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-whatsapp-icon btn-action-icon"><?= pb_fast_whatsapp_svg() ?></a>
                            <?php if (empty($row['job_card_no'])): ?>
                            <form method="post" action="api/proforma_bills.php" class="js-api-job-card-form" onsubmit="return false;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="create_job_card">
                                <input type="hidden" name="proforma_id" value="<?= (int)$row['id'] ?>">
                                <button title="Create Job Card" type="submit" class="btn btn-sm btn-primary btn-action-icon"><i data-lucide="briefcase-business"></i></button>
                            </form>
                            <?php endif; ?>
                            <form method="post" action="api/proforma_bills.php" class="js-api-delete-form" onsubmit="return false;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_record">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button title="Delete" type="submit" class="btn btn-sm btn-outline-danger btn-delete-icon"><i data-lucide="trash-2"></i></button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </main>
    <div id="settingsOverlay"></div>
    <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
</div>
<?php include __DIR__ . '/includes/script.php'; ?>
<script>
(function(){
    const pageToastEl=document.getElementById('pageToast');
    if(pageToastEl&&window.bootstrap&&bootstrap.Toast){bootstrap.Toast.getOrCreateInstance(pageToastEl).show();}
    if(window.lucide&&typeof window.lucide.createIcons==='function'){window.lucide.createIcons();}

    const search=document.getElementById('tableSearch');
    search?.addEventListener('input',function(){
        const value=this.value.toLowerCase().trim();
        document.querySelectorAll('#proformaTable tbody tr').forEach(function(row){
            row.style.display=row.textContent.toLowerCase().includes(value)?'':'none';
        });
        document.querySelectorAll('#mobileCards .mobile-card').forEach(function(card){
            card.style.display=card.textContent.toLowerCase().includes(value)?'':'none';
        });
    });

    function showToast(message,type='success'){
        alert(message);
    }

    document.querySelectorAll('.js-api-job-card-form').forEach(function(form){
        form.addEventListener('submit',function(e){e.preventDefault();});
        form.querySelector('button[type="submit"]')?.addEventListener('click',function(){
            if(!confirm('Create job card?')) return;
            fetch('api/proforma_bills.php',{method:'POST',body:new FormData(form),credentials:'same-origin'})
                .then(r=>r.json()).then(data=>{alert(data.message || (data.status?'Job card created.':'Job card failed.')); if(data.status){location.reload();}})
                .catch(()=>alert('API request failed.'));
        });
    });

    document.querySelectorAll('.js-api-delete-form').forEach(function(form){
        form.addEventListener('submit',function(e){e.preventDefault();});
        form.querySelector('button[type="submit"]')?.addEventListener('click',function(){
            if(!confirm('Delete this proforma bill?')) return;
            fetch('api/proforma_bills.php',{method:'POST',body:new FormData(form),credentials:'same-origin'})
                .then(r=>r.json()).then(data=>{alert(data.message || (data.status?'Deleted.':'Delete failed.')); if(data.status){location.reload();}})
                .catch(()=>alert('API request failed.'));
        });
    });
})();
</script>
</body>
</html>
