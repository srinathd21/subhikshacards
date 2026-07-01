<?php
/**
 * proforma_payment.php
 * Separate balance payment collection page with cancel/revert option.
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

function pp_table_exists(mysqli $conn, string $table): bool
{
    try {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function pp_col_exists(mysqli $conn, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $tableEsc = $conn->real_escape_string($table);
        $colEsc = $conn->real_escape_string($col);
        $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function pp_ensure_payment_cancel_columns(mysqli $conn): void
{
    if (!pp_table_exists($conn, 'payments')) return;
    $alters = [];
    if (!pp_col_exists($conn, 'payments', 'is_cancelled')) $alters[] = "ADD COLUMN `is_cancelled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `received_by`";
    if (!pp_col_exists($conn, 'payments', 'cancelled_at')) $alters[] = "ADD COLUMN `cancelled_at` DATETIME DEFAULT NULL AFTER `is_cancelled`";
    if (!pp_col_exists($conn, 'payments', 'cancelled_by')) $alters[] = "ADD COLUMN `cancelled_by` BIGINT(20) UNSIGNED DEFAULT NULL AFTER `cancelled_at`";
    if (!pp_col_exists($conn, 'payments', 'cancel_reason')) $alters[] = "ADD COLUMN `cancel_reason` TEXT DEFAULT NULL AFTER `cancelled_by`";
    if ($alters) {
        $conn->query("ALTER TABLE `payments` " . implode(', ', $alters));
    }
}

function pp_money($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function pp_date($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function pp_datetime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime((string)$value)) : '-';
}

function pp_next_no(mysqli $conn): string
{
    $prefix = 'PAY-' . date('ymd') . '-';
    try {
        $like = $prefix . '%';
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM payments WHERE payment_no LIKE ?");
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $prefix . str_pad((string)(((int)($row['total'] ?? 0)) + 1), 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return $prefix . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}

function pp_check_csrf(): void
{
    if (empty($_POST['csrf_token']) || empty($_SESSION['payment_csrf']) || !hash_equals($_SESSION['payment_csrf'], (string)$_POST['csrf_token'])) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function pp_redirect(int $id, string $msg = '', string $err = ''): void
{
    $q = ['id' => $id];
    if ($msg !== '') $q['msg'] = $msg;
    if ($err !== '') $q['err'] = $err;
    header('Location: proforma_payment.php?' . http_build_query($q));
    exit;
}

function pp_can_update_payment(mysqli $conn): bool
{
    if (function_exists('is_admin_user') && is_admin_user()) return true;
    if (function_exists('can_update')) {
        try {
            return can_update($conn, 'proforma_bills.php');
        } catch (Throwable $e) {
            return false;
        }
    }
    return true;
}

function pp_update_bill_and_job_amounts(mysqli $conn, int $proformaId, float $newAdvance): void
{
    $stmt = $conn->prepare("SELECT final_amount FROM proforma_bills WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $proformaId);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bill) {
        throw new RuntimeException('Proforma bill not found.');
    }

    $finalAmount = (float)$bill['final_amount'];
    if ($newAdvance < 0) $newAdvance = 0;
    if ($newAdvance > $finalAmount) $newAdvance = $finalAmount;
    $newBalance = max(0, $finalAmount - $newAdvance);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare("UPDATE proforma_bills SET advance_amount = ?, balance_amount = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ddii', $newAdvance, $newBalance, $userId, $proformaId);
    $stmt->execute();
    $stmt->close();

    if (pp_table_exists($conn, 'job_cards')) {
        $stmt = $conn->prepare("UPDATE job_cards SET advance_amount = ?, balance_amount = ?, updated_by = ?, updated_at = NOW() WHERE proforma_bill_id = ?");
        $stmt->bind_param('ddii', $newAdvance, $newBalance, $userId, $proformaId);
        $stmt->execute();
        $stmt->close();
    }
}

try {
    pp_ensure_payment_cancel_columns($conn);
} catch (Throwable $e) {
    // Page will still load; cancellation will fail with a clear error if ALTER permission is unavailable.
}

if (empty($_SESSION['payment_csrf'])) {
    $_SESSION['payment_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['payment_csrf'];

$id = (int)($_GET['id'] ?? 0);
$error = '';
$message = '';
$messageType = 'success';

if (($_GET['msg'] ?? '') === 'payment_collected') {
    $message = 'Payment collected successfully.';
} elseif (($_GET['msg'] ?? '') === 'payment_cancelled') {
    $message = 'Payment cancelled and amount reverted successfully.';
} elseif (!empty($_GET['err'])) {
    $message = 'Error: ' . trim((string)$_GET['err']);
    $messageType = 'danger';
}

if ($id <= 0) {
    $error = 'Invalid proforma bill.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) {
    pp_check_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if (!pp_can_update_payment($conn)) {
            throw new RuntimeException('You do not have permission to update payment.');
        }

        if ($action === 'collect_payment') {
            $amount = (float)str_replace(',', '', (string)($_POST['amount'] ?? '0'));
            $paymentMode = trim((string)($_POST['payment_mode'] ?? 'cash'));
            $paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
            $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
            $remarks = trim((string)($_POST['remarks'] ?? ''));

            $allowedModes = ['cash', 'upi', 'bank', 'cheque', 'card', 'other'];
            if (!in_array($paymentMode, $allowedModes, true)) $paymentMode = 'cash';
            if ($amount <= 0) throw new RuntimeException('Payment amount must be greater than zero.');
            if ($paymentDate === '') $paymentDate = date('Y-m-d');

            $stmt = $conn->prepare("SELECT id, customer_id, final_amount, advance_amount, balance_amount FROM proforma_bills WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $bill = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$bill) throw new RuntimeException('Proforma bill not found.');

            $balance = (float)$bill['balance_amount'];
            if ($balance <= 0) throw new RuntimeException('This proforma bill is already fully paid.');
            if ($amount > $balance) throw new RuntimeException('Payment amount cannot be greater than balance amount.');

            $paymentNo = pp_next_no($conn);
            $paymentType = ($amount >= $balance) ? 'balance' : 'balance';
            $customerId = !empty($bill['customer_id']) ? (int)$bill['customer_id'] : null;
            $userId = (int)($_SESSION['user_id'] ?? 0);

            $stmt = $conn->prepare("INSERT INTO payments (customer_id, proforma_bill_id, payment_no, payment_type, payment_mode, amount, payment_date, reference_no, remarks, received_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param('iisssdsssi', $customerId, $id, $paymentNo, $paymentType, $paymentMode, $amount, $paymentDate, $referenceNo, $remarks, $userId);
            $stmt->execute();
            $stmt->close();

            $newAdvance = (float)$bill['advance_amount'] + $amount;
            pp_update_bill_and_job_amounts($conn, $id, $newAdvance);
            pp_redirect($id, 'payment_collected');
        }

        if ($action === 'cancel_payment') {
            if (!pp_col_exists($conn, 'payments', 'is_cancelled')) {
                throw new RuntimeException('Payment cancellation columns are missing. Please allow ALTER TABLE permission or add cancellation columns.');
            }

            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));
            if ($paymentId <= 0) throw new RuntimeException('Invalid payment.');
            if ($cancelReason === '') throw new RuntimeException('Cancel reason is required.');

            $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ? AND proforma_bill_id = ? LIMIT 1");
            $stmt->bind_param('ii', $paymentId, $id);
            $stmt->execute();
            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$payment) throw new RuntimeException('Payment not found.');
            if ((int)($payment['is_cancelled'] ?? 0) === 1) throw new RuntimeException('This payment is already cancelled.');

            $stmt = $conn->prepare("SELECT advance_amount FROM proforma_bills WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $bill = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$bill) throw new RuntimeException('Proforma bill not found.');

            $userId = (int)($_SESSION['user_id'] ?? 0);
            $stmt = $conn->prepare("UPDATE payments SET is_cancelled = 1, cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ? AND proforma_bill_id = ?");
            $stmt->bind_param('isii', $userId, $cancelReason, $paymentId, $id);
            $stmt->execute();
            $stmt->close();

            $newAdvance = (float)$bill['advance_amount'] - (float)$payment['amount'];
            pp_update_bill_and_job_amounts($conn, $id, $newAdvance);
            pp_redirect($id, 'payment_cancelled');
        }

        throw new RuntimeException('Invalid request.');
    } catch (Throwable $e) {
        pp_redirect($id, '', $e->getMessage());
    }
}

$bill = null;
$activePayments = [];
$cancelledPayments = [];

if ($id > 0 && $error === '') {
    try {
        $stmt = $conn->prepare("SELECT pb.*, ps.status_name, ft.function_name FROM proforma_bills pb LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id LEFT JOIN function_types ft ON ft.id = pb.function_type_id WHERE pb.id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $bill = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$bill) $error = 'Proforma bill not found.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($bill && pp_table_exists($conn, 'payments')) {
    try {
        $cancelSelect = pp_col_exists($conn, 'payments', 'is_cancelled') ? 'COALESCE(p.is_cancelled,0) AS is_cancelled, p.cancelled_at, p.cancelled_by, p.cancel_reason, cu.username AS cancelled_by_name' : '0 AS is_cancelled, NULL AS cancelled_at, NULL AS cancelled_by, NULL AS cancel_reason, NULL AS cancelled_by_name';
        $cancelJoin = pp_col_exists($conn, 'payments', 'cancelled_by') ? 'LEFT JOIN users cu ON cu.id = p.cancelled_by' : '';
        $sql = "SELECT p.*, ru.username AS received_by_name, {$cancelSelect} FROM payments p LEFT JOIN users ru ON ru.id = p.received_by {$cancelJoin} WHERE p.proforma_bill_id = ? ORDER BY p.id DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if ((int)($row['is_cancelled'] ?? 0) === 1) $cancelledPayments[] = $row;
            else $activePayments[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {
        $message = 'Unable to load payment history: ' . $e->getMessage();
        $messageType = 'danger';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Collect Payment - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
    .payment-page .page-head{padding:24px 28px;margin-bottom:18px}.payment-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}.module-card{padding:24px;border-radius:20px;margin-bottom:18px}.section-title{font-size:18px;font-weight:900;color:var(--text-main);margin-bottom:12px}.info-box{border:1px solid var(--border-soft);border-radius:16px;padding:14px;background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));height:100%}.info-box small{display:block;font-size:11px;text-transform:uppercase;color:var(--text-muted);font-weight:900;margin-bottom:5px}.info-box strong{display:block;font-size:18px;color:var(--text-main);font-weight:900;word-break:break-word}.balance-due strong{color:#991b1b}.paid-box strong{color:#166534}.payment-form{border:1px solid var(--border-soft);border-radius:18px;padding:18px;background:color-mix(in srgb,var(--success-color,#16a34a) 6%,var(--card-bg))}.table-view th{font-size:12px;text-transform:uppercase;color:var(--text-muted);white-space:nowrap}.table-view td{vertical-align:middle}.cancelled-row{background:#fef2f2!important;color:#991b1b}.status-badge{display:inline-flex;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:900;background:#dcfce7;color:#166534}.status-badge.cancelled{background:#fee2e2;color:#991b1b}.toast-ui{border:0;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.18);overflow:hidden;min-width:320px;max-width:420px}.toast-ui.success{background:#dcfce7;color:#14532d}.toast-ui.danger{background:#fee2e2;color:#7f1d1d}.toast-title{font-size:14px;font-weight:900}.toast-message{font-size:13px;font-weight:800;line-height:1.45}@media(max-width:767.98px){.payment-page .page-head{padding:18px;border-radius:18px}.payment-page .page-head h1{font-size:24px}.module-card{padding:16px;border-radius:18px}.table-view{font-size:13px}}
    </style>
</head>
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main id="main">
        <?php include __DIR__ . '/includes/nav.php'; ?>
        <section class="page-section payment-page">
            <div class="card-ui page-head">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div><h1 class="mb-1">Collect Payment</h1><p class="text-muted-custom mb-0"><?= $bill ? e($bill['proforma_no'] ?? '-') : 'Payment details' ?></p></div>
                    <div class="d-flex gap-2 flex-wrap"><a href="proforma_bills.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Back to List</a><?php if ($bill): ?><a href="proforma_bill_view.php?id=<?= (int)$id ?>" class="btn btn-primary rounded-pill px-4 fw-bold">View Bill</a><?php endif; ?></div>
                </div>
            </div>

            <?php if ($message !== ''): ?>
            <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000"><div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4200"><div class="d-flex"><div class="toast-body"><div class="toast-title"><?= $messageType === 'danger' ? 'Failed' : 'Success' ?></div><div class="toast-message"><?= e($message) ?></div></div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
            <div class="card-ui module-card"><div class="alert alert-danger rounded-4 fw-bold mb-0"><?= e($error) ?></div></div>
            <?php elseif ($bill): ?>
            <div class="card-ui module-card">
                <div class="section-title">Bill Summary</div>
                <div class="row g-3">
                    <div class="col-md-3"><div class="info-box"><small>Proforma No</small><strong><?= e($bill['proforma_no'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Customer</small><strong><?= e($bill['customer_name'] ?? '-') ?></strong><small><?= e($bill['mobile'] ?? '') ?></small></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Function</small><strong><?= e($bill['function_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Status</small><strong><?= e($bill['status_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-4"><div class="info-box"><small>Final Amount</small><strong><?= e(pp_money($bill['final_amount'] ?? 0)) ?></strong></div></div>
                    <div class="col-md-4"><div class="info-box paid-box"><small>Paid Amount</small><strong><?= e(pp_money($bill['advance_amount'] ?? 0)) ?></strong></div></div>
                    <div class="col-md-4"><div class="info-box balance-due"><small>Balance Amount</small><strong><?= e(pp_money($bill['balance_amount'] ?? 0)) ?></strong></div></div>
                </div>
            </div>

            <div class="card-ui module-card">
                <div class="section-title">Make Payment</div>
                <?php if ((float)($bill['balance_amount'] ?? 0) <= 0): ?>
                <div class="alert alert-success rounded-4 fw-bold mb-0">This proforma bill is fully paid.</div>
                <?php else: ?>
                <form method="post" class="payment-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="collect_payment">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3"><label class="form-label fw-bold">Amount</label><input type="number" step="0.01" min="0.01" max="<?= e($bill['balance_amount']) ?>" name="amount" class="form-control" value="<?= e(number_format((float)$bill['balance_amount'], 2, '.', '')) ?>" required></div>
                        <div class="col-md-3"><label class="form-label fw-bold">Payment Mode</label><select name="payment_mode" class="form-select" required><option value="cash">Cash</option><option value="upi">UPI</option><option value="bank">Bank</option><option value="cheque">Cheque</option><option value="card">Card</option><option value="other">Other</option></select></div>
                        <div class="col-md-3"><label class="form-label fw-bold">Payment Date</label><input type="date" name="payment_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required></div>
                        <div class="col-md-3"><label class="form-label fw-bold">Reference No</label><input type="text" name="reference_no" class="form-control" placeholder="UPI / Bank ref"></div>
                        <div class="col-12"><label class="form-label fw-bold">Remarks</label><textarea name="remarks" class="form-control" rows="2" placeholder="Payment remarks"></textarea></div>
                        <div class="col-12 text-end"><button type="submit" class="btn btn-success rounded-pill px-4 fw-bold" onclick="return confirm('Collect this payment?')">Save Payment</button></div>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <div class="card-ui module-card">
                <div class="section-title">Recent Payment History</div>
                <div class="table-responsive">
                    <table class="table table-view">
                        <thead><tr><th>No</th><th>Type</th><th>Mode</th><th>Amount</th><th>Date</th><th>Reference</th><th>Received By</th><th>Remarks</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                            <?php if (!$activePayments): ?><tr><td colspan="9" class="text-center text-muted-custom py-3">No active payment found.</td></tr><?php endif; ?>
                            <?php foreach ($activePayments as $pay): ?>
                            <tr>
                                <td><strong><?= e($pay['payment_no'] ?? '-') ?></strong></td>
                                <td><?= e(ucfirst((string)($pay['payment_type'] ?? '-'))) ?></td>
                                <td><?= e(strtoupper((string)($pay['payment_mode'] ?? '-'))) ?></td>
                                <td><strong><?= e(pp_money($pay['amount'] ?? 0)) ?></strong></td>
                                <td><?= e(pp_date($pay['payment_date'] ?? null)) ?></td>
                                <td><?= e($pay['reference_no'] ?? '-') ?></td>
                                <td><?= e($pay['received_by_name'] ?? '-') ?></td>
                                <td><?= e($pay['remarks'] ?? '-') ?></td>
                                <td class="text-end">
                                    <form method="post" class="d-flex gap-2 justify-content-end" onsubmit="return confirm('Cancel this payment and revert balance?')">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="cancel_payment">
                                        <input type="hidden" name="payment_id" value="<?= (int)$pay['id'] ?>">
                                        <input type="text" name="cancel_reason" class="form-control form-control-sm" style="max-width:180px" placeholder="Cancel reason" required>
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill fw-bold">Cancel</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-ui module-card">
                <div class="section-title">Cancelled Payment List</div>
                <div class="table-responsive">
                    <table class="table table-view">
                        <thead><tr><th>No</th><th>Mode</th><th>Amount</th><th>Payment Date</th><th>Cancelled At</th><th>Cancelled By</th><th>Reason</th></tr></thead>
                        <tbody>
                            <?php if (!$cancelledPayments): ?><tr><td colspan="7" class="text-center text-muted-custom py-3">No cancelled payment found.</td></tr><?php endif; ?>
                            <?php foreach ($cancelledPayments as $pay): ?>
                            <tr class="cancelled-row">
                                <td><strong><?= e($pay['payment_no'] ?? '-') ?></strong></td>
                                <td><?= e(strtoupper((string)($pay['payment_mode'] ?? '-'))) ?></td>
                                <td><strong><?= e(pp_money($pay['amount'] ?? 0)) ?></strong></td>
                                <td><?= e(pp_date($pay['payment_date'] ?? null)) ?></td>
                                <td><?= e(pp_datetime($pay['cancelled_at'] ?? null)) ?></td>
                                <td><?= e($pay['cancelled_by_name'] ?? '-') ?></td>
                                <td><?= e($pay['cancel_reason'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
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
})();
</script>
</body>
</html>
