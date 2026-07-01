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
            jc.job_card_no
        FROM proforma_bills pb
        LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id
        LEFT JOIN function_types ft ON ft.id = pb.function_type_id
        LEFT JOIN (
            SELECT proforma_bill_id, MAX(job_card_no) AS job_card_no
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
    .proforma-list-table{table-layout:fixed;width:100%;min-width:1040px}.proforma-list-table th,.proforma-list-table td{vertical-align:middle!important}.proforma-list-table th{font-size:12px;text-transform:uppercase;color:var(--text-muted)}.proforma-list-table th:nth-child(1),.proforma-list-table td:nth-child(1){width:15%}.proforma-list-table th:nth-child(2),.proforma-list-table td:nth-child(2){width:20%}.proforma-list-table th:nth-child(3),.proforma-list-table td:nth-child(3){width:16%}.proforma-list-table th:nth-child(4),.proforma-list-table td:nth-child(4){width:10%}.proforma-list-table th:nth-child(5),.proforma-list-table td:nth-child(5){width:12%}.proforma-list-table th:nth-child(6),.proforma-list-table td:nth-child(6){width:12%}.proforma-list-table th:nth-child(7),.proforma-list-table td:nth-child(7){width:15%}
    .status-pill{font-size:11px;font-weight:900;border-radius:999px;padding:6px 10px;background:#dbeafe;color:#1d4ed8;display:inline-flex;align-items:center;justify-content:center;line-height:1.2}.status-pill.paid{background:#dcfce7;color:#166534}.status-pill.pending{background:#fef3c7;color:#92400e}.balance-text{font-weight:900;color:#991b1b}.balance-text.paid{color:#166534}.action-buttons{display:flex;gap:6px;justify-content:flex-end;align-items:center;flex-wrap:wrap}.btn-action-icon,.btn-delete-icon{width:36px!important;height:36px!important;min-width:36px!important;max-width:36px!important;padding:0!important;border-radius:50%!important;display:inline-flex!important;align-items:center!important;justify-content:center!important}.btn-action-icon svg,.btn-delete-icon svg{width:16px!important;height:16px!important;stroke-width:2.5!important}.mobile-cards{display:none}.mobile-card{border:1px solid var(--border-soft);border-radius:18px;padding:16px;margin-bottom:12px;background:var(--card-bg)}
    @media(max-width:767.98px){.proforma-page .page-head{padding:18px;border-radius:18px}.proforma-page .page-head h1{font-size:24px}.card-pad{padding:16px;border-radius:18px}.desktop-table{display:none!important}.mobile-cards{display:block}.mobile-actions{display:grid;grid-template-columns:repeat(4,42px);gap:8px;margin-top:14px}.btn-action-icon,.btn-delete-icon{width:42px!important;height:42px!important;min-width:42px!important;max-width:42px!important}}
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
