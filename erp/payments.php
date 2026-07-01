<?php
/**
 * payments.php
 * Paid payment history page using Subhiksha template style.
 * Default view: paid / active payments only.
 * Cancel filter: shows cancelled payment details.
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

function payTableExists(mysqli $conn, string $table): bool
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

function payColExists(mysqli $conn, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $tableEsc = $conn->real_escape_string($table);
        $colEsc = $conn->real_escape_string($col);
        $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) {
            $res->free();
        }
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function payEnsureCancelColumns(mysqli $conn): void
{
    if (!payTableExists($conn, 'payments')) {
        return;
    }

    $alters = [];
    if (!payColExists($conn, 'payments', 'is_cancelled')) {
        $alters[] = "ADD COLUMN `is_cancelled` TINYINT(1) NOT NULL DEFAULT 0";
    }
    if (!payColExists($conn, 'payments', 'cancelled_at')) {
        $alters[] = "ADD COLUMN `cancelled_at` DATETIME DEFAULT NULL";
    }
    if (!payColExists($conn, 'payments', 'cancelled_by')) {
        $alters[] = "ADD COLUMN `cancelled_by` BIGINT(20) UNSIGNED DEFAULT NULL";
    }
    if (!payColExists($conn, 'payments', 'cancel_reason')) {
        $alters[] = "ADD COLUMN `cancel_reason` TEXT DEFAULT NULL";
    }

    if ($alters) {
        $conn->query('ALTER TABLE `payments` ' . implode(', ', $alters));
    }
}

function payMoney($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function payDate($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function payDateTime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime((string)$value)) : '-';
}

function payCheckCsrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['payments_csrf']) ||
        !hash_equals($_SESSION['payments_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function payCanCancel(mysqli $conn): bool
{
    if (function_exists('is_admin_user') && is_admin_user()) {
        return true;
    }

    if (function_exists('can_update')) {
        try {
            return can_update($conn, 'proforma_bills.php');
        } catch (Throwable $e) {
            return false;
        }
    }

    return true;
}

function payRedirect(array $params = []): void
{
    header('Location: payments.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

function payKeepParams(array $extra = []): array
{
    $keep = [];
    foreach (['view', 'job_card_id', 'proforma_id', 'q', 'date_from', 'date_to'] as $key) {
        if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $keep[$key] = trim((string)$_GET[$key]);
        }
    }
    return array_merge($keep, $extra);
}

function paySetBillAndJobAmounts(mysqli $conn, int $proformaId, float $newAdvance): void
{
    $stmt = $conn->prepare('SELECT final_amount FROM proforma_bills WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $proformaId);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bill) {
        throw new RuntimeException('Related proforma bill not found.');
    }

    $finalAmount = (float)$bill['final_amount'];
    $newAdvance = max(0, min($finalAmount, $newAdvance));
    $newBalance = max(0, $finalAmount - $newAdvance);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare('UPDATE proforma_bills SET advance_amount = ?, balance_amount = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('ddii', $newAdvance, $newBalance, $userId, $proformaId);
    $stmt->execute();
    $stmt->close();

    if (payTableExists($conn, 'job_cards')) {
        $stmt = $conn->prepare('UPDATE job_cards SET advance_amount = ?, balance_amount = ?, updated_by = ?, updated_at = NOW() WHERE proforma_bill_id = ?');
        $stmt->bind_param('ddii', $newAdvance, $newBalance, $userId, $proformaId);
        $stmt->execute();
        $stmt->close();
    }
}

try {
    payEnsureCancelColumns($conn);
} catch (Throwable $e) {
    // Page will still load. Cancel action will show error if required columns are unavailable.
}

if (empty($_SESSION['payments_csrf'])) {
    $_SESSION['payments_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['payments_csrf'];

$message = '';
$messageType = 'success';
$toastTitle = 'Info';
$error = '';

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'cancelled') {
    $message = 'Payment cancelled successfully and amount reverted.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif (!empty($_GET['err'])) {
    $message = 'Error: ' . trim((string)$_GET['err']);
    $messageType = 'danger';
    $toastTitle = 'Failed';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    payCheckCsrf();

    try {
        if (!payCanCancel($conn)) {
            throw new RuntimeException('You do not have permission to cancel payment.');
        }

        $action = trim((string)($_POST['action'] ?? ''));
        if ($action !== 'cancel_payment') {
            throw new RuntimeException('Invalid action.');
        }

        if (!payColExists($conn, 'payments', 'is_cancelled') || !payColExists($conn, 'payments', 'cancelled_at') || !payColExists($conn, 'payments', 'cancelled_by') || !payColExists($conn, 'payments', 'cancel_reason')) {
            throw new RuntimeException('Payment cancel columns are missing. Please add is_cancelled, cancelled_at, cancelled_by and cancel_reason to payments table.');
        }

        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));

        if ($paymentId <= 0) {
            throw new RuntimeException('Invalid payment.');
        }
        if ($cancelReason === '') {
            throw new RuntimeException('Cancel reason is required.');
        }

        $stmt = $conn->prepare('
            SELECT p.*, pb.id AS bill_id, pb.advance_amount
            FROM payments p
            LEFT JOIN proforma_bills pb ON pb.id = p.proforma_bill_id
            WHERE p.id = ?
            LIMIT 1
        ');
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payment) {
            throw new RuntimeException('Payment not found.');
        }
        if (empty($payment['bill_id'])) {
            throw new RuntimeException('Related proforma bill not found.');
        }
        if ((int)($payment['is_cancelled'] ?? 0) === 1) {
            throw new RuntimeException('This payment is already cancelled.');
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare('UPDATE payments SET is_cancelled = 1, cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ?');
        $stmt->bind_param('isi', $userId, $cancelReason, $paymentId);
        $stmt->execute();
        $stmt->close();

        $newAdvance = (float)$payment['advance_amount'] - (float)$payment['amount'];
        paySetBillAndJobAmounts($conn, (int)$payment['bill_id'], $newAdvance);

        payRedirect(payKeepParams(['msg' => 'cancelled']));
    } catch (Throwable $e) {
        payRedirect(payKeepParams(['err' => $e->getMessage()]));
    }
}

$view = strtolower(trim((string)($_GET['view'] ?? 'paid')));
if (!in_array($view, ['paid', 'cancelled'], true)) {
    $view = 'paid';
}

$jobCardId = (int)($_GET['job_card_id'] ?? 0);
$proformaId = (int)($_GET['proforma_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$rows = [];
$paidCount = 0;
$cancelledCount = 0;
$paidAmount = 0;
$cancelledAmount = 0;
$cashAmount = 0;
$upiAmount = 0;
$bankAmount = 0;
$jobContext = null;
$canCancel = payCanCancel($conn);

if (!payTableExists($conn, 'payments')) {
    $error = 'payments table is missing.';
}

if ($error === '') {
    try {
        $hasCancel = payColExists($conn, 'payments', 'is_cancelled');
        $hasCancelledAt = payColExists($conn, 'payments', 'cancelled_at');
        $hasCancelledBy = payColExists($conn, 'payments', 'cancelled_by');
        $hasCancelReason = payColExists($conn, 'payments', 'cancel_reason');
        $hasReceivedBy = payColExists($conn, 'payments', 'received_by');

        $cancelledSelect = $hasCancel ? 'COALESCE(p.is_cancelled,0) AS is_cancelled' : '0 AS is_cancelled';
        $cancelledAtSelect = $hasCancelledAt ? 'p.cancelled_at' : 'NULL AS cancelled_at';
        $cancelledBySelect = $hasCancelledBy ? 'p.cancelled_by' : 'NULL AS cancelled_by';
        $cancelReasonSelect = $hasCancelReason ? 'p.cancel_reason' : 'NULL AS cancel_reason';
        $receivedBySelect = $hasReceivedBy ? "COALESCE(ru.username, '-') AS received_by_name" : "'-' AS received_by_name";
        $receivedByJoin = $hasReceivedBy ? 'LEFT JOIN users ru ON ru.id = p.received_by' : '';
        $cancelBySelect = $hasCancelledBy ? "COALESCE(cu.username, '-') AS cancelled_by_name" : "'-' AS cancelled_by_name";
        $cancelByJoin = $hasCancelledBy ? 'LEFT JOIN users cu ON cu.id = p.cancelled_by' : '';

        $where = [];
        $params = [];
        $types = '';

        if ($view === 'paid') {
            $where[] = $hasCancel ? 'COALESCE(p.is_cancelled,0) = 0' : '1 = 1';
        } else {
            $where[] = $hasCancel ? 'COALESCE(p.is_cancelled,0) = 1' : '1 = 0';
        }

        if ($jobCardId > 0) {
            $where[] = 'jc.id = ?';
            $params[] = $jobCardId;
            $types .= 'i';
        }

        if ($proformaId > 0) {
            $where[] = 'pb.id = ?';
            $params[] = $proformaId;
            $types .= 'i';
        }

        if ($dateFrom !== '') {
            $where[] = 'p.payment_date >= ?';
            $params[] = $dateFrom;
            $types .= 's';
        }

        if ($dateTo !== '') {
            $where[] = 'p.payment_date <= ?';
            $params[] = $dateTo;
            $types .= 's';
        }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(p.payment_no LIKE ? OR pb.proforma_no LIKE ? OR pb.customer_name LIKE ? OR pb.mobile LIKE ? OR jc.job_card_no LIKE ? OR p.reference_no LIKE ?)';
            for ($i = 0; $i < 6; $i++) {
                $params[] = $like;
                $types .= 's';
            }
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT
                p.*,
                {$cancelledSelect},
                {$cancelledAtSelect},
                {$cancelledBySelect},
                {$cancelReasonSelect},
                {$receivedBySelect},
                {$cancelBySelect},
                pb.id AS bill_id,
                pb.proforma_no,
                pb.customer_name,
                pb.mobile,
                pb.order_type,
                pb.final_amount,
                pb.advance_amount,
                pb.balance_amount,
                pb.delivery_date,
                ft.function_name,
                ps.status_name AS proforma_status_name,
                jc.id AS job_card_id,
                jc.job_card_no,
                jcs.status_name AS job_status_name
            FROM payments p
            LEFT JOIN proforma_bills pb ON pb.id = p.proforma_bill_id
            LEFT JOIN function_types ft ON ft.id = pb.function_type_id
            LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id
            LEFT JOIN (
                SELECT proforma_bill_id, MAX(id) AS latest_job_card_id
                FROM job_cards
                GROUP BY proforma_bill_id
            ) jx ON jx.proforma_bill_id = pb.id
            LEFT JOIN job_cards jc ON jc.id = jx.latest_job_card_id
            LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
            {$receivedByJoin}
            {$cancelByJoin}
            {$whereSql}
            ORDER BY p.id DESC
            LIMIT 300
        ";

        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        $summaryWhere = [];
        $summaryParams = [];
        $summaryTypes = '';
        if ($jobCardId > 0) {
            $summaryWhere[] = 'jc.id = ?';
            $summaryParams[] = $jobCardId;
            $summaryTypes .= 'i';
        }
        if ($proformaId > 0) {
            $summaryWhere[] = 'pb.id = ?';
            $summaryParams[] = $proformaId;
            $summaryTypes .= 'i';
        }
        if ($dateFrom !== '') {
            $summaryWhere[] = 'p.payment_date >= ?';
            $summaryParams[] = $dateFrom;
            $summaryTypes .= 's';
        }
        if ($dateTo !== '') {
            $summaryWhere[] = 'p.payment_date <= ?';
            $summaryParams[] = $dateTo;
            $summaryTypes .= 's';
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $summaryWhere[] = '(p.payment_no LIKE ? OR pb.proforma_no LIKE ? OR pb.customer_name LIKE ? OR pb.mobile LIKE ? OR jc.job_card_no LIKE ? OR p.reference_no LIKE ?)';
            for ($i = 0; $i < 6; $i++) {
                $summaryParams[] = $like;
                $summaryTypes .= 's';
            }
        }
        $summaryWhereSql = $summaryWhere ? 'WHERE ' . implode(' AND ', $summaryWhere) : '';

        $summarySql = "
            SELECT
                SUM(CASE WHEN " . ($hasCancel ? 'COALESCE(p.is_cancelled,0) = 0' : '1=1') . " THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN " . ($hasCancel ? 'COALESCE(p.is_cancelled,0) = 0' : '1=1') . " THEN p.amount ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN " . ($hasCancel ? 'COALESCE(p.is_cancelled,0) = 1' : '1=0') . " THEN 1 ELSE 0 END) AS cancelled_count,
                SUM(CASE WHEN " . ($hasCancel ? 'COALESCE(p.is_cancelled,0) = 1' : '1=0') . " THEN p.amount ELSE 0 END) AS cancelled_amount,
                SUM(CASE WHEN " . ($hasCancel ? 'COALESCE(p.is_cancelled,0) = 0' : '1=1') . " AND LOWER(COALESCE(p.payment_mode,'')) = 'cash' THEN p.amount ELSE 0 END) AS cash_amount,
                SUM(CASE WHEN " . ($hasCancel ? 'COALESCE(p.is_cancelled,0) = 0' : '1=1') . " AND LOWER(COALESCE(p.payment_mode,'')) = 'upi' THEN p.amount ELSE 0 END) AS upi_amount,
                SUM(CASE WHEN " . ($hasCancel ? 'COALESCE(p.is_cancelled,0) = 0' : '1=1') . " AND LOWER(COALESCE(p.payment_mode,'')) = 'bank' THEN p.amount ELSE 0 END) AS bank_amount
            FROM payments p
            LEFT JOIN proforma_bills pb ON pb.id = p.proforma_bill_id
            LEFT JOIN (
                SELECT proforma_bill_id, MAX(id) AS latest_job_card_id
                FROM job_cards
                GROUP BY proforma_bill_id
            ) jx ON jx.proforma_bill_id = pb.id
            LEFT JOIN job_cards jc ON jc.id = jx.latest_job_card_id
            {$summaryWhereSql}
        ";
        $stmt = $conn->prepare($summarySql);
        if ($summaryParams) {
            $stmt->bind_param($summaryTypes, ...$summaryParams);
        }
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $paidCount = (int)($summary['paid_count'] ?? 0);
        $cancelledCount = (int)($summary['cancelled_count'] ?? 0);
        $paidAmount = (float)($summary['paid_amount'] ?? 0);
        $cancelledAmount = (float)($summary['cancelled_amount'] ?? 0);
        $cashAmount = (float)($summary['cash_amount'] ?? 0);
        $upiAmount = (float)($summary['upi_amount'] ?? 0);
        $bankAmount = (float)($summary['bank_amount'] ?? 0);
    } catch (Throwable $e) {
        $error = 'Unable to load payments: ' . $e->getMessage();
    }
}

if ($jobCardId > 0 && $error === '') {
    try {
        $stmt = $conn->prepare('
            SELECT
                jc.*,
                pb.proforma_no,
                pb.customer_name,
                pb.mobile,
                pb.final_amount,
                pb.advance_amount,
                pb.balance_amount,
                ft.function_name,
                jcs.status_name AS job_status_name
            FROM job_cards jc
            LEFT JOIN proforma_bills pb ON pb.id = jc.proforma_bill_id
            LEFT JOIN function_types ft ON ft.id = pb.function_type_id
            LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
            WHERE jc.id = ?
            LIMIT 1
        ');
        $stmt->bind_param('i', $jobCardId);
        $stmt->execute();
        $jobContext = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        $jobContext = null;
    }
}

$pageTitle = $jobContext ? 'Payments - ' . ($jobContext['job_card_no'] ?? '') : 'Payments';
$exportPdf = (string)($_GET['export'] ?? '') === 'pdf';
$exportParams = array_filter([
    'view' => $view,
    'job_card_id' => $jobCardId ?: null,
    'proforma_id' => $proformaId ?: null,
    'q' => $q ?: null,
    'date_from' => $dateFrom ?: null,
    'date_to' => $dateTo ?: null,
    'export' => 'pdf'
]);
$exportUrl = 'payments.php?' . http_build_query($exportParams);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
    .module-page .page-head{padding:24px 28px;margin-bottom:18px}.module-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}.module-card{padding:24px}.module-title{font-size:18px;font-weight:900;color:var(--text-main);margin:0}.stat-card{padding:18px;min-height:112px;display:flex;align-items:center;gap:14px}.stat-icon{width:52px;height:52px;border-radius:16px;display:grid;place-items:center;color:#fff;flex:0 0 auto}.stat-card span{display:block;font-size:12px;color:var(--text-muted);font-weight:900;text-transform:uppercase}.stat-card strong{font-size:24px;font-weight:900;color:var(--text-main)}.status-pill{font-size:11px;font-weight:900;border-radius:999px;padding:5px 9px;background:color-mix(in srgb,var(--info-color) 14%,transparent);color:var(--info-color);display:inline-flex;align-items:center;white-space:nowrap}.status-pill.ok{color:#166534;background:#dcfce7}.status-pill.cancelled{color:#991b1b;background:#fee2e2}.status-pill.job{color:#1d4ed8;background:#dbeafe}.form-control,.form-select{border-radius:14px;min-height:46px}.payment-tabs{display:flex;gap:10px;flex-wrap:wrap}.payment-tabs .btn{border-radius:999px;font-weight:900}.filter-card{border:1px solid var(--border-soft);border-radius:18px;padding:16px;background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg))}.table-ui th{font-size:12px}.paid-amount{color:#166534;font-weight:900}.cancelled-amount{color:#991b1b;font-weight:900;text-decoration:line-through}.cancel-inline{display:grid;grid-template-columns:minmax(130px,1fr) auto;gap:6px;align-items:center}.job-context{border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:18px;padding:16px}.mobile-cards{display:none}.mobile-card{border:1px solid var(--border-soft);background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));border-radius:18px;padding:16px;margin-bottom:12px}.mobile-card-title{font-size:16px;font-weight:900;color:var(--text-main)}.mobile-card-subtitle{display:block;color:var(--text-muted);font-size:12px;font-weight:700;margin-top:4px;word-break:break-word}.mobile-card-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.toast-ui{border:0;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.18);overflow:hidden;min-width:320px;max-width:420px}.toast-ui.success{background:#dcfce7;color:#14532d}.toast-ui.danger{background:#fee2e2;color:#7f1d1d}.toast-title{font-size:14px;font-weight:900}.toast-message{font-size:13px;font-weight:800;line-height:1.45}.btn-action-icon{width:36px!important;height:36px!important;min-width:36px!important;max-width:36px!important;padding:0!important;border-radius:50%!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;line-height:1!important}.btn-action-icon svg{width:16px!important;height:16px!important;stroke-width:2.5!important}@media(max-width:767.98px){.module-page .page-head{padding:18px;border-radius:18px}.module-page .page-head h1{font-size:24px}.module-card{padding:16px;border-radius:18px}.desktop-table{display:none!important}.mobile-cards{display:block}.cancel-inline{grid-template-columns:1fr}.mobile-card-actions .btn,.mobile-card-actions form{flex:1 1 auto}.mobile-card-actions .btn{width:100%}.btn-action-icon{width:42px!important;height:42px!important;min-width:42px!important;max-width:42px!important}}
    @media print{#sidebar,#mobileOverlay,#settingsOverlay,nav,.app-shell>aside,.no-print,.filter-card,.payment-tabs,.toast-container{display:none!important}main{margin:0!important}.page-section{padding:0!important}.card-ui,.module-card,.page-head{box-shadow:none!important;border:1px solid #ddd!important}.desktop-table{display:block!important}.mobile-cards{display:none!important}body{background:#fff!important}.table-ui{width:100%!important;font-size:11px}.table-ui th,.table-ui td{padding:7px!important}}
    </style>
</head>
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main id="main">
        <?php include __DIR__ . '/includes/nav.php'; ?>

        <section class="page-section module-page">
            <div class="card-ui page-head">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div>
                        <h1 class="mb-1">Payment History</h1>
                        <p class="text-muted-custom mb-0">
                            <?= $jobContext ? 'Payment history for job card ' . e($jobContext['job_card_no'] ?? '-') : 'Paid payment history and cancelled payment details.' ?>
                        </p>
                    </div>
                    <div class="d-flex flex-column flex-sm-row gap-2 no-print">
                        <a href="proforma_bills.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Proforma List</a>
                        <a href="<?= e($exportUrl) ?>" class="btn btn-primary rounded-pill px-4 fw-bold">
                            <i data-lucide="file-down"></i> Export PDF
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($message !== ''): ?>
            <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000">
                <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4200">
                    <div class="d-flex">
                        <div class="toast-body"><div class="toast-title"><?= e($toastTitle) ?></div><div class="toast-message"><?= e($message) ?></div></div>
                        <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
            <div class="card-ui module-card"><div class="alert alert-danger rounded-4 fw-bold mb-0"><?= e($error) ?></div></div>
            <?php else: ?>

            <?php if ($jobContext): ?>
            <div class="job-context mb-3">
                <div class="row g-3 align-items-center">
                    <div class="col-md-3"><strong>Job Card</strong><br><?= e($jobContext['job_card_no'] ?? '-') ?></div>
                    <div class="col-md-3"><strong>Proforma</strong><br><?= e($jobContext['proforma_no'] ?? '-') ?></div>
                    <div class="col-md-3"><strong>Customer</strong><br><?= e($jobContext['customer_name'] ?? '-') ?> · <?= e($jobContext['mobile'] ?? '') ?></div>
                    <div class="col-md-3"><strong>Balance</strong><br><?= e(payMoney($jobContext['balance_amount'] ?? 0)) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-3"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)"><i data-lucide="indian-rupee"></i></div><div><span>Paid Amount</span><strong><?= e(payMoney($paidAmount)) ?></strong></div></div></div>
                <div class="col-12 col-md-3"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)"><i data-lucide="receipt"></i></div><div><span>Paid Entries</span><strong><?= number_format($paidCount) ?></strong></div></div></div>
                <div class="col-12 col-md-3"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#dc2626,#ef4444)"><i data-lucide="x-circle"></i></div><div><span>Cancelled</span><strong><?= number_format($cancelledCount) ?></strong></div></div></div>
                <div class="col-12 col-md-3"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#7c3aed,#9333ea)"><i data-lucide="credit-card"></i></div><div><span>UPI + Bank</span><strong><?= e(payMoney($upiAmount + $bankAmount)) ?></strong></div></div></div>
            </div>

            <div class="card-ui module-card">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                    <div>
                        <h2 class="module-title"><?= $view === 'cancelled' ? 'Cancelled Payment Details' : 'Paid Payment History' ?></h2>
                        <p class="text-muted-custom mb-0"><?= $view === 'cancelled' ? 'Cancelled entries are shown here with cancel reason and user.' : 'Only active paid payment entries are shown here.' ?></p>
                    </div>
                    <div class="payment-tabs no-print">
                        <?php $paidUrl = 'payments.php?' . http_build_query(array_filter(['view' => 'paid', 'job_card_id' => $jobCardId ?: null, 'proforma_id' => $proformaId ?: null, 'q' => $q ?: null, 'date_from' => $dateFrom ?: null, 'date_to' => $dateTo ?: null])); ?>
                        <?php $cancelUrl = 'payments.php?' . http_build_query(array_filter(['view' => 'cancelled', 'job_card_id' => $jobCardId ?: null, 'proforma_id' => $proformaId ?: null, 'q' => $q ?: null, 'date_from' => $dateFrom ?: null, 'date_to' => $dateTo ?: null])); ?>
                        <a class="btn <?= $view === 'paid' ? 'btn-success' : 'btn-outline-success' ?> px-4" href="<?= e($paidUrl) ?>">Paid</a>
                        <a class="btn <?= $view === 'cancelled' ? 'btn-danger' : 'btn-outline-danger' ?> px-4" href="<?= e($cancelUrl) ?>">Cancelled</a>
                    </div>
                </div>

                <form method="get" class="filter-card mb-3 no-print">
                    <input type="hidden" name="view" value="<?= e($view) ?>">
                    <input type="hidden" name="job_card_id" value="<?= $jobCardId > 0 ? (int)$jobCardId : '' ?>">
                    <input type="hidden" name="proforma_id" value="<?= $proformaId > 0 ? (int)$proformaId : '' ?>">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4"><label class="form-label fw-bold">Search</label><input type="search" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Payment no / customer / job card / reference"></div>
                        <div class="col-md-2"><label class="form-label fw-bold">From</label><input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>"></div>
                        <div class="col-md-2"><label class="form-label fw-bold">To</label><input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>"></div>
                        <div class="col-md-4"><button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Filter</button> <a href="payments.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Reset</a></div>
                    </div>
                </form>

                <div class="table-responsive desktop-table">
                    <table class="table-ui" id="paymentsTable">
                        <thead>
                            <tr>
                                <th>Payment No</th>
                                <th>Customer</th>
                                <th>Proforma</th>
                                <th>Job Card</th>
                                <th>Mode</th>
                                <th>Amount</th>
                                <th>Date / Ref</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="8" class="text-center text-muted-custom py-4">No <?= $view === 'cancelled' ? 'cancelled' : 'paid' ?> payment details found.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($rows as $row): ?>
                            <?php $isCancelled = (int)($row['is_cancelled'] ?? 0) === 1; ?>
                            <tr>
                                <td><strong><?= e($row['payment_no'] ?? '-') ?></strong><small class="d-block text-muted-custom">ID: <?= (int)($row['id'] ?? 0) ?></small></td>
                                <td><?= e($row['customer_name'] ?? '-') ?><small class="d-block text-muted-custom"><?= e($row['mobile'] ?? '-') ?></small></td>
                                <td><a href="proforma_bill_view.php?id=<?= (int)($row['bill_id'] ?? 0) ?>" class="fw-bold text-decoration-none"><?= e($row['proforma_no'] ?? '-') ?></a><small class="d-block text-muted-custom"><?= e(ucfirst((string)($row['order_type'] ?? '-'))) ?></small></td>
                                <td><?php if (!empty($row['job_card_id'])): ?><a href="payments.php?view=<?= e($view) ?>&job_card_id=<?= (int)$row['job_card_id'] ?>" class="status-pill job text-decoration-none"><?= e($row['job_card_no'] ?? '-') ?></a><small class="d-block text-muted-custom mt-1"><?= e($row['job_status_name'] ?? '-') ?></small><?php else: ?><span class="text-muted-custom fw-bold">Not Created</span><?php endif; ?></td>
                                <td><?= e(ucfirst((string)($row['payment_type'] ?? '-'))) ?><small class="d-block text-muted-custom"><?= e(strtoupper((string)($row['payment_mode'] ?? '-'))) ?></small></td>
                                <td><span class="<?= $isCancelled ? 'cancelled-amount' : 'paid-amount' ?>"><?= e(payMoney($row['amount'] ?? 0)) ?></span></td>
                                <td><?= e(payDate($row['payment_date'] ?? null)) ?><small class="d-block text-muted-custom">Ref: <?= e($row['reference_no'] ?? '-') ?></small></td>
                                <td>
                                    <?php if ($isCancelled): ?>
                                    <span class="status-pill cancelled">Cancelled</span>
                                    <small class="d-block text-muted-custom mt-1">At: <?= e(payDateTime($row['cancelled_at'] ?? null)) ?></small>
                                    <small class="d-block text-muted-custom">By: <?= e($row['cancelled_by_name'] ?? '-') ?></small>
                                    <small class="d-block text-danger fw-bold mt-1">Reason: <?= e($row['cancel_reason'] ?? '-') ?></small>
                                    <?php else: ?>
                                    <span class="status-pill ok">Paid</span>
                                    <small class="d-block text-muted-custom mt-1">By: <?= e($row['received_by_name'] ?? '-') ?></small>
                                    <?php endif; ?>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mobile-cards" id="mobileCards">
                    <?php if (!$rows): ?>
                    <div class="mobile-card text-center text-muted-custom">No <?= $view === 'cancelled' ? 'cancelled' : 'paid' ?> payment details found.</div>
                    <?php endif; ?>

                    <?php foreach ($rows as $row): ?>
                    <?php $isCancelled = (int)($row['is_cancelled'] ?? 0) === 1; ?>
                    <div class="mobile-card">
                        <div class="d-flex justify-content-between gap-2">
                            <div>
                                <div class="mobile-card-title"><?= e($row['payment_no'] ?? '-') ?></div>
                                <span class="mobile-card-subtitle"><?= e($row['customer_name'] ?? '-') ?> · <?= e($row['mobile'] ?? '-') ?></span>
                                <span class="mobile-card-subtitle">Proforma: <?= e($row['proforma_no'] ?? '-') ?></span>
                                <span class="mobile-card-subtitle">Job Card: <?= e($row['job_card_no'] ?? 'Not Created') ?></span>
                                <span class="mobile-card-subtitle">Amount: <?= e(payMoney($row['amount'] ?? 0)) ?> · <?= e(strtoupper((string)($row['payment_mode'] ?? '-'))) ?></span>
                                <span class="mobile-card-subtitle">Date: <?= e(payDate($row['payment_date'] ?? null)) ?></span>
                                <?php if ($isCancelled): ?>
                                <span class="mobile-card-subtitle text-danger fw-bold">Cancelled: <?= e(payDateTime($row['cancelled_at'] ?? null)) ?> · <?= e($row['cancel_reason'] ?? '-') ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="status-pill <?= $isCancelled ? 'cancelled' : 'ok' ?>"><?= $isCancelled ? 'Cancelled' : 'Paid' ?></span>
                        </div>

                    </div>
                    <?php endforeach; ?>
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
    const pageToastEl = document.getElementById('pageToast');
    if (pageToastEl && window.bootstrap && bootstrap.Toast) {
        bootstrap.Toast.getOrCreateInstance(pageToastEl).show();
    }
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
    <?php if ($exportPdf): ?>
    window.addEventListener('load', function(){
        setTimeout(function(){ window.print(); }, 400);
    });
    <?php endif; ?>
})();
</script>
</body>
</html>
