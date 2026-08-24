<?php
/**
 * payments.php
 * Subhiksha Cards ERP - Unified Payments page.
 *
 * One list only:
 * - Paid Proforma bills
 * - Unpaid / Partially Paid Proforma bills
 * - Paid Quick Sales
 * - Cancelled payment entries (when Cancelled filter is selected)
 *
 * No separate Pending Payments section.
 * Pagination and all summary values work on the currently selected filters.
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
        if ($res) $res->free();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function payColExists(mysqli $conn, string $table, string $col): bool
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

function payEnsureCancelColumns(mysqli $conn): void
{
    if (!payTableExists($conn, 'payments')) return;

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

function payRedirect(array $params = []): void
{
    header('Location: payments.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

function payKeepParams(array $extra = []): array
{
    $keep = [];
    foreach (['status', 'job_card_id', 'proforma_id', 'q', 'date_from', 'date_to', 'page'] as $key) {
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

    if (!$bill) throw new RuntimeException('Related proforma bill not found.');

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

function payBindAndExecute(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
}

function payBuildUrl(array $extra = []): string
{
    $params = [];
    foreach (['status', 'job_card_id', 'proforma_id', 'q', 'date_from', 'date_to', 'page'] as $key) {
        if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $params[$key] = trim((string)$_GET[$key]);
        }
    }
    $params = array_merge($params, $extra);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') unset($params[$key]);
    }
    return 'payments.php' . ($params ? '?' . http_build_query($params) : '');
}

try {
    payEnsureCancelColumns($conn);
} catch (Throwable $e) {
    // Keep the page available even if ALTER permission is unavailable.
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

        if (
            !payColExists($conn, 'payments', 'is_cancelled') ||
            !payColExists($conn, 'payments', 'cancelled_at') ||
            !payColExists($conn, 'payments', 'cancelled_by') ||
            !payColExists($conn, 'payments', 'cancel_reason')
        ) {
            throw new RuntimeException('Payment cancel columns are missing.');
        }

        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));
        if ($paymentId <= 0) throw new RuntimeException('Invalid payment.');
        if ($cancelReason === '') throw new RuntimeException('Cancel reason is required.');

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

        if (!$payment) throw new RuntimeException('Payment not found.');
        if (empty($payment['bill_id'])) throw new RuntimeException('Related proforma bill not found.');
        if ((int)($payment['is_cancelled'] ?? 0) === 1) throw new RuntimeException('This payment is already cancelled.');

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

// -----------------------------------------------------------------------------
// Filters + pagination
// -----------------------------------------------------------------------------
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));

// Backward compatibility with older links that used ?view=cancelled.
if (!isset($_GET['status']) && strtolower((string)($_GET['view'] ?? '')) === 'cancelled') {
    $statusFilter = 'cancelled';
}

if (!in_array($statusFilter, ['all', 'paid', 'unpaid', 'cancelled'], true)) {
    $statusFilter = 'all';
}

$jobCardId = (int)($_GET['job_card_id'] ?? 0);
$proformaId = (int)($_GET['proforma_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$exportPdf = (string)($_GET['export'] ?? '') === 'pdf';

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';

$allRows = [];
$displayRows = [];
$jobContext = null;
$canCancel = payCanCancel($conn);

$hasProformaBills = payTableExists($conn, 'proforma_bills');
$hasProformaPayments = payTableExists($conn, 'payments');
$hasQuickSalePayments = payTableExists($conn, 'quick_sale_payments') && payTableExists($conn, 'quick_sales');
$hasCancel = $hasProformaPayments && payColExists($conn, 'payments', 'is_cancelled');

if (!$hasProformaBills && !$hasQuickSalePayments) {
    $error = 'No payment data is available.';
}

// -----------------------------------------------------------------------------
// ACTIVE PROFORMA BILLS: one row per Proforma.
// Paid / Partially Paid / Unpaid are calculated from active payment rows.
// -----------------------------------------------------------------------------
if ($error === '' && $hasProformaBills && $statusFilter !== 'cancelled') {
    try {
        $activePaidPart = $hasCancel
            ? 'CASE WHEN COALESCE(is_cancelled,0) = 0 THEN amount ELSE 0 END'
            : 'amount';
        $activeDatePart = $hasCancel
            ? 'CASE WHEN COALESCE(is_cancelled,0) = 0 THEN payment_date ELSE NULL END'
            : 'payment_date';
        $activeIdPart = $hasCancel
            ? 'CASE WHEN COALESCE(is_cancelled,0) = 0 THEN id ELSE NULL END'
            : 'id';

        $paymentJoin = '';
        $paidExpr = 'COALESCE(pb.advance_amount,0)';
        $lastPaymentDateExpr = 'NULL';
        $lastPaymentIdExpr = 'NULL';

        if ($hasProformaPayments) {
            $paymentJoin = "
                LEFT JOIN (
                    SELECT
                        proforma_bill_id,
                        COUNT(*) AS payment_count,
                        COALESCE(SUM({$activePaidPart}),0) AS active_paid,
                        MAX({$activeDatePart}) AS last_payment_date,
                        MAX({$activeIdPart}) AS last_payment_id
                    FROM payments
                    GROUP BY proforma_bill_id
                ) pa ON pa.proforma_bill_id = pb.id
                LEFT JOIN payments lp ON lp.id = pa.last_payment_id
            ";

            $paidExpr = "CASE
                WHEN COALESCE(pa.payment_count,0) > 0 THEN COALESCE(pa.active_paid,0)
                ELSE COALESCE(pb.advance_amount,0)
            END";
            $lastPaymentDateExpr = 'pa.last_payment_date';
            $lastPaymentIdExpr = 'pa.last_payment_id';
        }

        $balanceExpr = "GREATEST(COALESCE(pb.final_amount,0) - ({$paidExpr}), 0)";
        $where = [];
        $params = [];
        $types = '';

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

        // Unified date behavior: latest active payment date when available,
        // otherwise Proforma created date (required for completely unpaid bills).
        $effectiveDateExpr = "DATE(COALESCE({$lastPaymentDateExpr}, pb.created_at))";

        if ($dateFrom !== '') {
            $where[] = "{$effectiveDateExpr} >= ?";
            $params[] = $dateFrom;
            $types .= 's';
        }

        if ($dateTo !== '') {
            $where[] = "{$effectiveDateExpr} <= ?";
            $params[] = $dateTo;
            $types .= 's';
        }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(pb.proforma_no LIKE ? OR pb.customer_name LIKE ? OR pb.mobile LIKE ? OR COALESCE(jc.job_card_no,'') LIKE ? OR COALESCE(lp.payment_no,'') LIKE ? OR COALESCE(lp.reference_no,'') LIKE ?)";
            for ($i = 0; $i < 6; $i++) {
                $params[] = $like;
                $types .= 's';
            }
        }

        if ($statusFilter === 'paid') {
            $where[] = "{$balanceExpr} <= 0.009";
        } elseif ($statusFilter === 'unpaid') {
            // Includes both completely unpaid and partially paid bills.
            $where[] = "{$balanceExpr} > 0.009";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $jobJoin = payTableExists($conn, 'job_cards')
            ? "
                LEFT JOIN (
                    SELECT proforma_bill_id, MAX(id) AS latest_job_card_id
                    FROM job_cards
                    GROUP BY proforma_bill_id
                ) jx ON jx.proforma_bill_id = pb.id
                LEFT JOIN job_cards jc ON jc.id = jx.latest_job_card_id
                " . (payTableExists($conn, 'job_card_statuses')
                    ? 'LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id'
                    : '')
            : '';

        $jobSelect = payTableExists($conn, 'job_cards')
            ? "jc.id AS job_card_id, jc.job_card_no, " . (payTableExists($conn, 'job_card_statuses') ? 'jcs.status_name' : "'-'") . " AS job_status_name"
            : "NULL AS job_card_id, NULL AS job_card_no, '-' AS job_status_name";

        $lastPaymentSelect = $hasProformaPayments
            ? "lp.payment_no AS last_payment_no, lp.payment_mode AS last_payment_mode, lp.reference_no AS last_reference_no, lp.received_by AS last_received_by"
            : "NULL AS last_payment_no, NULL AS last_payment_mode, NULL AS last_reference_no, NULL AS last_received_by";

        $sql = "
            SELECT
                pb.id AS bill_id,
                pb.proforma_no,
                pb.customer_id,
                pb.customer_name,
                pb.mobile,
                pb.order_type,
                pb.final_amount,
                pb.delivery_date,
                pb.created_at AS proforma_created_at,
                ({$paidExpr}) AS paid_amount,
                {$balanceExpr} AS current_balance,
                {$lastPaymentDateExpr} AS last_payment_date,
                {$lastPaymentIdExpr} AS last_payment_id,
                {$lastPaymentSelect},
                {$jobSelect}
            FROM proforma_bills pb
            {$paymentJoin}
            {$jobJoin}
            {$whereSql}
            ORDER BY COALESCE({$lastPaymentDateExpr}, pb.created_at) DESC, pb.id DESC
        ";

        $stmt = $conn->prepare($sql);
        payBindAndExecute($stmt, $types, $params);
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $paid = (float)($row['paid_amount'] ?? 0);
            $balance = (float)($row['current_balance'] ?? 0);
            $final = (float)($row['final_amount'] ?? 0);

            if ($balance <= 0.009) {
                $billStatus = 'paid';
            } elseif ($paid > 0.009 && $paid < $final - 0.009) {
                $billStatus = 'partial';
            } else {
                $billStatus = 'unpaid';
            }

            $row['record_type'] = 'proforma';
            $row['bill_status'] = $billStatus;
            $row['sort_date'] = (string)($row['last_payment_date'] ?: $row['proforma_created_at']);
            $allRows[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {
        $error = 'Unable to load Proforma payment details: ' . $e->getMessage();
    }
}

// -----------------------------------------------------------------------------
// QUICK SALE: always a paid record. Added into the SAME list.
// -----------------------------------------------------------------------------
if (
    $error === '' &&
    $hasQuickSalePayments &&
    in_array($statusFilter, ['all', 'paid'], true) &&
    $jobCardId <= 0 &&
    $proformaId <= 0
) {
    try {
        $qsWhere = [];
        $qsParams = [];
        $qsTypes = '';

        if ($dateFrom !== '') {
            $qsWhere[] = 'qsp.payment_date >= ?';
            $qsParams[] = $dateFrom;
            $qsTypes .= 's';
        }
        if ($dateTo !== '') {
            $qsWhere[] = 'qsp.payment_date <= ?';
            $qsParams[] = $dateTo;
            $qsTypes .= 's';
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $qsWhere[] = "(
                qsp.payment_no LIKE ?
                OR qs.sale_no LIKE ?
                OR COALESCE(qsp.reference_no,'') LIKE ?
                OR EXISTS (
                    SELECT 1 FROM quick_sale_items qsi_search
                    WHERE qsi_search.quick_sale_id = qs.id
                      AND qsi_search.product_name LIKE ?
                )
            )";
            for ($i = 0; $i < 4; $i++) {
                $qsParams[] = $like;
                $qsTypes .= 's';
            }
        }

        $qsWhereSql = $qsWhere ? 'WHERE ' . implode(' AND ', $qsWhere) : '';
        $qsCustomerSelect = payColExists($conn, 'quick_sales', 'customer_name')
            ? "COALESCE(NULLIF(qs.customer_name,''),'Counter Sale')"
            : "'Counter Sale'";
        $qsMobileSelect = payColExists($conn, 'quick_sales', 'mobile')
            ? "COALESCE(NULLIF(qs.mobile,''),'-')"
            : "'-'";

        $qsSql = "
            SELECT
                qs.id AS quick_sale_id,
                qs.sale_no,
                {$qsCustomerSelect} AS customer_name,
                {$qsMobileSelect} AS mobile,
                qs.total_amount AS final_amount,
                COALESCE(SUM(qsp.amount),0) AS paid_amount,
                GREATEST(COALESCE(qs.total_amount,0) - COALESCE(SUM(qsp.amount),0),0) AS current_balance,
                MAX(qsp.payment_date) AS last_payment_date,
                MAX(qsp.id) AS last_payment_id,
                GROUP_CONCAT(DISTINCT UPPER(qsp.payment_mode) ORDER BY qsp.id SEPARATOR ' + ') AS last_payment_mode,
                GROUP_CONCAT(DISTINCT NULLIF(qsp.reference_no,'') ORDER BY qsp.id SEPARATOR ', ') AS last_reference_no,
                COALESCE((
                    SELECT GROUP_CONCAT(qsi.product_name ORDER BY qsi.id SEPARATOR ', ')
                    FROM quick_sale_items qsi
                    WHERE qsi.quick_sale_id = qs.id
                ), '') AS quick_sale_products,
                qs.created_at AS sale_created_at
            FROM quick_sales qs
            INNER JOIN quick_sale_payments qsp ON qsp.quick_sale_id = qs.id
            {$qsWhereSql}
            GROUP BY qs.id
            ORDER BY MAX(qsp.payment_date) DESC, qs.id DESC
        ";

        $stmt = $conn->prepare($qsSql);
        payBindAndExecute($stmt, $qsTypes, $qsParams);
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['record_type'] = 'quick_sale';
            $row['bill_status'] = 'paid';
            $row['bill_id'] = null;
            $row['proforma_no'] = $row['sale_no'] ?? '-';
            $row['order_type'] = 'quick_sale';
            $row['job_card_id'] = null;
            $row['job_card_no'] = null;
            $row['job_status_name'] = '-';
            $row['sort_date'] = (string)($row['last_payment_date'] ?: $row['sale_created_at']);
            $allRows[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {
        $error = 'Unable to load Quick Sale payments: ' . $e->getMessage();
    }
}

// -----------------------------------------------------------------------------
// CANCELLED FILTER: cancelled payment entries use the SAME table area.
// -----------------------------------------------------------------------------
if ($error === '' && $statusFilter === 'cancelled' && $hasProformaPayments && $hasCancel) {
    try {
        $where = ['COALESCE(p.is_cancelled,0) = 1'];
        $params = [];
        $types = '';

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
            $where[] = "(p.payment_no LIKE ? OR pb.proforma_no LIKE ? OR pb.customer_name LIKE ? OR pb.mobile LIKE ? OR COALESCE(jc.job_card_no,'') LIKE ? OR COALESCE(p.reference_no,'') LIKE ?)";
            for ($i = 0; $i < 6; $i++) {
                $params[] = $like;
                $types .= 's';
            }
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $hasCancelledBy = payColExists($conn, 'payments', 'cancelled_by');
        $cancelByJoin = $hasCancelledBy ? 'LEFT JOIN users cu ON cu.id = p.cancelled_by' : '';
        $cancelBySelect = $hasCancelledBy ? "COALESCE(cu.username,'-')" : "'-'";
        $jobJoin = payTableExists($conn, 'job_cards')
            ? "
                LEFT JOIN (
                    SELECT proforma_bill_id, MAX(id) AS latest_job_card_id
                    FROM job_cards GROUP BY proforma_bill_id
                ) jx ON jx.proforma_bill_id = pb.id
                LEFT JOIN job_cards jc ON jc.id = jx.latest_job_card_id
            "
            : '';
        $jobSelect = payTableExists($conn, 'job_cards')
            ? 'jc.id AS job_card_id, jc.job_card_no'
            : 'NULL AS job_card_id, NULL AS job_card_no';

        $sql = "
            SELECT
                p.id AS payment_id,
                p.payment_no,
                p.payment_mode,
                p.amount AS cancelled_amount,
                p.payment_date,
                p.reference_no,
                p.cancelled_at,
                p.cancel_reason,
                {$cancelBySelect} AS cancelled_by_name,
                pb.id AS bill_id,
                pb.proforma_no,
                pb.customer_name,
                pb.mobile,
                pb.order_type,
                pb.final_amount,
                pb.balance_amount AS current_balance,
                {$jobSelect}
            FROM payments p
            LEFT JOIN proforma_bills pb ON pb.id = p.proforma_bill_id
            {$jobJoin}
            {$cancelByJoin}
            {$whereSql}
            ORDER BY COALESCE(p.cancelled_at,p.created_at) DESC, p.id DESC
        ";

        $stmt = $conn->prepare($sql);
        payBindAndExecute($stmt, $types, $params);
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['record_type'] = 'cancelled';
            $row['bill_status'] = 'cancelled';
            $row['paid_amount'] = 0;
            $row['sort_date'] = (string)($row['cancelled_at'] ?: $row['payment_date']);
            $allRows[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {
        $error = 'Unable to load cancelled payments: ' . $e->getMessage();
    }
}

// -----------------------------------------------------------------------------
// Job-card context (preserved)
// -----------------------------------------------------------------------------
if ($jobCardId > 0 && $error === '' && payTableExists($conn, 'job_cards')) {
    try {
        $stmt = $conn->prepare('
            SELECT
                jc.*,
                pb.proforma_no,
                pb.customer_name,
                pb.mobile,
                pb.final_amount,
                pb.advance_amount,
                pb.balance_amount
            FROM job_cards jc
            LEFT JOIN proforma_bills pb ON pb.id = jc.proforma_bill_id
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

// Sort after Proforma + Quick Sale merge.
usort($allRows, static function (array $a, array $b): int {
    $aTime = strtotime((string)($a['sort_date'] ?? '')) ?: 0;
    $bTime = strtotime((string)($b['sort_date'] ?? '')) ?: 0;
    if ($aTime === $bTime) {
        $aId = (int)($a['bill_id'] ?? $a['quick_sale_id'] ?? $a['payment_id'] ?? 0);
        $bId = (int)($b['bill_id'] ?? $b['quick_sale_id'] ?? $b['payment_id'] ?? 0);
        return $bId <=> $aId;
    }
    return $bTime <=> $aTime;
});

// Filter-aware summary cards.
$filteredCount = count($allRows);
$summaryPaidAmount = 0.0;
$summaryOutstanding = 0.0;
$summaryPaidBills = 0;
$summaryNeedsPayment = 0;
$summaryCancelledAmount = 0.0;

foreach ($allRows as $row) {
    $status = (string)($row['bill_status'] ?? '');
    if ($status === 'cancelled') {
        $summaryCancelledAmount += (float)($row['cancelled_amount'] ?? 0);
        continue;
    }

    $summaryPaidAmount += (float)($row['paid_amount'] ?? 0);
    $summaryOutstanding += (float)($row['current_balance'] ?? 0);
    if ($status === 'paid') $summaryPaidBills++;
    if (in_array($status, ['unpaid', 'partial'], true)) $summaryNeedsPayment++;
}

$totalPages = max(1, (int)ceil($filteredCount / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$displayRows = $exportPdf ? $allRows : array_slice($allRows, $offset, $perPage);
$showFrom = $filteredCount > 0 ? $offset + 1 : 0;
$showTo = $filteredCount > 0 ? min($offset + count($displayRows), $filteredCount) : 0;

$pageTitle = $jobContext ? 'Payments - ' . ($jobContext['job_card_no'] ?? '') : 'Payments';
$exportParams = array_filter([
    'status' => $statusFilter !== 'all' ? $statusFilter : null,
    'job_card_id' => $jobCardId ?: null,
    'proforma_id' => $proformaId ?: null,
    'q' => $q ?: null,
    'date_from' => $dateFrom ?: null,
    'date_to' => $dateTo ?: null,
    'export' => 'pdf'
]);
$exportUrl = 'payments.php?' . http_build_query($exportParams);

/*
 * PDF / print export uses a dedicated report-only document.
 * Do not render the ERP sidebar, navigation, dashboard cards, filters or action buttons.
 * The browser print dialog can then be saved as PDF without capturing the application UI.
 */
if ($exportPdf) {
    $statusNames = [
        'all' => 'All',
        'paid' => 'Paid',
        'unpaid' => 'Unpaid / Partially Paid',
        'cancelled' => 'Cancelled',
    ];
    $exportStatusLabel = $statusNames[$statusFilter] ?? 'All';
    $exportDateLabel = 'All Dates';
    if ($dateFrom !== '' && $dateTo !== '') {
        $exportDateLabel = payDate($dateFrom) . ' to ' . payDate($dateTo);
    } elseif ($dateFrom !== '') {
        $exportDateLabel = 'From ' . payDate($dateFrom);
    } elseif ($dateTo !== '') {
        $exportDateLabel = 'Up to ' . payDate($dateTo);
    }
    ?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Payment Report - Subhiksha Cards</title>
    <style>
    @page {
        size: A4 landscape;
        margin: 10mm;
    }

    * {
        box-sizing: border-box;
    }

    html,
    body {
        margin: 0;
        padding: 0;
        background: #fff;
        color: #111827;
        font-family: Arial, Helvetica, sans-serif;
    }

    body {
        font-size: 11px;
    }

    .report {
        width: 100%;
    }

    .report-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 20px;
        border-bottom: 2px solid #111827;
        padding-bottom: 10px;
        margin-bottom: 12px;
    }

    .company {
        font-size: 20px;
        font-weight: 800;
        letter-spacing: .2px;
    }

    .report-title {
        font-size: 15px;
        font-weight: 700;
        margin-top: 3px;
    }

    .generated {
        text-align: right;
        line-height: 1.55;
        color: #4b5563;
    }

    .filters {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 10px;
    }

    .filter-box {
        border: 1px solid #d1d5db;
        border-radius: 6px;
        padding: 7px 9px;
        min-height: 44px;
    }

    .filter-box small {
        display: block;
        color: #6b7280;
        font-size: 9px;
        text-transform: uppercase;
        font-weight: 700;
        margin-bottom: 3px;
    }

    .filter-box strong {
        font-size: 10.5px;
        word-break: break-word;
    }

    .summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 12px;
    }

    .summary-box {
        border: 1px solid #d1d5db;
        border-radius: 6px;
        padding: 8px 9px;
    }

    .summary-box span {
        display: block;
        color: #6b7280;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        margin-bottom: 3px;
    }

    .summary-box strong {
        font-size: 13px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    thead {
        display: table-header-group;
    }

    tr {
        page-break-inside: avoid;
    }

    th,
    td {
        border: 1px solid #d1d5db;
        padding: 6px 5px;
        vertical-align: top;
        overflow-wrap: anywhere;
    }

    th {
        background: #f3f4f6;
        font-size: 9px;
        text-transform: uppercase;
        text-align: left;
    }

    td {
        font-size: 9.5px;
        line-height: 1.35;
    }

    .num {
        text-align: right;
        white-space: nowrap;
    }

    .muted {
        color: #6b7280;
        font-size: 8.5px;
        display: block;
        margin-top: 2px;
    }

    .status {
        font-weight: 700;
        white-space: nowrap;
    }

    .paid {
        color: #166534;
    }

    .partial {
        color: #c2410c;
    }

    .unpaid,
    .cancelled {
        color: #b91c1c;
    }

    .empty {
        text-align: center;
        padding: 18px;
        color: #6b7280;
    }

    .footer-note {
        margin-top: 8px;
        font-size: 9px;
        color: #6b7280;
        text-align: right;
    }

    @media screen {
        body {
            padding: 18px;
            background: #eef2f7;
        }

        .report {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            padding: 18px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .12);
        }
    }

    @media print {
        body {
            padding: 0 !important;
            background: #fff !important;
        }

        .report {
            max-width: none;
            padding: 0;
            box-shadow: none;
        }
    }
    </style>
</head>

<body>
    <div class="report">
        <div class="report-head">
            <div>
                <div class="company">SUBHIKSHA CARDS</div>
                <div class="report-title">Payment Report</div>
            </div>
            <div class="generated">
                <strong>Generated:</strong> <?= e(date('d-m-Y h:i A')) ?><br>
                <strong>Records:</strong> <?= number_format($filteredCount) ?>
            </div>
        </div>

        <div class="filters">
            <div class="filter-box"><small>Payment Status</small><strong><?= e($exportStatusLabel) ?></strong></div>
            <div class="filter-box"><small>Date Range</small><strong><?= e($exportDateLabel) ?></strong></div>
            <div class="filter-box"><small>Search</small><strong><?= e($q !== '' ? $q : 'All') ?></strong></div>
            <div class="filter-box">
                <small>Context</small><strong><?= e($jobContext ? ('Job Card ' . ($jobContext['job_card_no'] ?? '-')) : ($proformaId > 0 ? ('Proforma ID ' . $proformaId) : 'All Bills')) ?></strong>
            </div>
        </div>

        <div class="summary">
            <div class="summary-box"><span>Filtered Records</span><strong><?= number_format($filteredCount) ?></strong>
            </div>
            <div class="summary-box"><span>Paid Amount</span><strong><?= e(payMoney($summaryPaidAmount)) ?></strong>
            </div>
            <div class="summary-box"><span>Outstanding</span><strong><?= e(payMoney($summaryOutstanding)) ?></strong>
            </div>
            <div class="summary-box"><span>Needs
                    Payment</span><strong><?= number_format($summaryNeedsPayment) ?></strong></div>
        </div>

        <table>
            <colgroup>
                <col style="width:13%">
                <col style="width:18%">
                <col style="width:12%">
                <col style="width:11%">
                <col style="width:11%">
                <col style="width:11%">
                <col style="width:13%">
                <col style="width:11%">
            </colgroup>
            <thead>
                <tr>
                    <th>Bill / Payment</th>
                    <th>Customer</th>
                    <th>Job Card</th>
                    <th class="num">Total</th>
                    <th class="num">Paid</th>
                    <th class="num">Balance</th>
                    <th>Date / Mode</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$displayRows): ?>
                <tr>
                    <td colspan="8" class="empty">No payment records found for the selected filters.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($displayRows as $row): ?>
                <?php
                    $recordType = (string)($row['record_type'] ?? 'proforma');
                    $billStatus = (string)($row['bill_status'] ?? 'unpaid');
                    $isQuickSale = $recordType === 'quick_sale';
                    $isCancelled = $recordType === 'cancelled';
                    $paid = (float)($row['paid_amount'] ?? 0);
                    $balance = (float)($row['current_balance'] ?? 0);
                    $statusLabel = $billStatus === 'partial' ? 'Partially Paid' : ucfirst($billStatus);
                    $dateValue = $isCancelled
                        ? ($row['payment_date'] ?? null)
                        : ($row['last_payment_date'] ?? ($row['sort_date'] ?? null));
                    $modeValue = $isCancelled
                        ? 'Cancelled'
                        : (string)($row['last_payment_mode'] ?? ($paid > 0 ? '-' : 'No payment'));
                ?>
                <tr>
                    <td>
                        <strong><?= e($row['proforma_no'] ?? '-') ?></strong>
                        <?php if ($isQuickSale): ?><span class="muted">Quick
                            Sale</span><?php elseif ($isCancelled && !empty($row['payment_no'])): ?><span
                            class="muted"><?= e($row['payment_no']) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <strong><?= e($row['customer_name'] ?? '-') ?></strong>
                        <span class="muted"><?= e($row['mobile'] ?? '-') ?></span>
                        <?php if ($isQuickSale && !empty($row['quick_sale_products'])): ?><span
                            class="muted"><?= e($row['quick_sale_products']) ?></span><?php endif; ?>
                    </td>
                    <td><?= e($isQuickSale ? 'N/A' : ($row['job_card_no'] ?? 'Not Created')) ?></td>
                    <td class="num"><strong><?= e(payMoney($row['final_amount'] ?? 0)) ?></strong></td>
                    <td class="num"><?= e(payMoney($isCancelled ? ($row['cancelled_amount'] ?? 0) : $paid)) ?></td>
                    <td class="num"><?= e(payMoney($balance)) ?></td>
                    <td><?= e(payDate($dateValue)) ?><span
                            class="muted"><?= e($modeValue !== '' ? $modeValue : '-') ?></span></td>
                    <td><span class="status <?= e($billStatus) ?>"><?= e($statusLabel) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="footer-note">Subhiksha Cards ERP · Payment Report</div>
    </div>
    <script>
    window.addEventListener('load', function() {
        setTimeout(function() {
            window.print();
        }, 250);
    });
    </script>
</body>

</html>
<?php
    exit;
}
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
    .module-page .page-head {
        padding: 24px 28px;
        margin-bottom: 18px
    }

    .module-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main)
    }

    .module-card {
        padding: 24px
    }

    .module-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
        margin: 0
    }

    .stat-card {
        padding: 18px;
        min-height: 112px;
        display: flex;
        align-items: center;
        gap: 14px
    }

    .stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto
    }

    .stat-card span {
        display: block;
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 900;
        text-transform: uppercase
    }

    .stat-card strong {
        font-size: 24px;
        font-weight: 900;
        color: var(--text-main)
    }

    .status-pill {
        font-size: 11px;
        font-weight: 900;
        border-radius: 999px;
        padding: 5px 9px;
        display: inline-flex;
        align-items: center;
        white-space: nowrap
    }

    .status-pill.paid {
        color: #166534;
        background: #dcfce7
    }

    .status-pill.partial {
        color: #c2410c;
        background: #fff7ed;
        border: 1px solid #fed7aa
    }

    .status-pill.unpaid {
        color: #b91c1c;
        background: #fef2f2;
        border: 1px solid #fecaca
    }

    .status-pill.cancelled {
        color: #991b1b;
        background: #fee2e2
    }

    .status-pill.job {
        color: #1d4ed8;
        background: #dbeafe
    }

    .form-control,
    .form-select {
        border-radius: 14px;
        min-height: 46px
    }

    .filter-card {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 16px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg))
    }

    .table-ui th {
        font-size: 12px
    }

    .paid-amount {
        color: #166534;
        font-weight: 900
    }

    .balance-amount {
        color: #b91c1c;
        font-weight: 900
    }

    .job-context {
        border: 1px solid #bfdbfe;
        background: #eff6ff;
        color: #1e3a8a;
        border-radius: 18px;
        padding: 16px
    }

    .mobile-cards {
        display: none
    }

    .mobile-card {
        border: 1px solid var(--border-soft);
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        border-radius: 18px;
        padding: 16px;
        margin-bottom: 12px
    }

    .mobile-card-title {
        font-size: 16px;
        font-weight: 900;
        color: var(--text-main)
    }

    .mobile-card-subtitle {
        display: block;
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
        margin-top: 4px;
        word-break: break-word
    }

    .mobile-card-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px
    }

    .toast-ui {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
        overflow: hidden;
        min-width: 320px;
        max-width: 420px
    }

    .toast-ui.success {
        background: #dcfce7;
        color: #14532d
    }

    .toast-ui.danger {
        background: #fee2e2;
        color: #7f1d1d
    }

    .toast-title {
        font-size: 14px;
        font-weight: 900
    }

    .toast-message {
        font-size: 13px;
        font-weight: 800;
        line-height: 1.45
    }

    .pagination .page-link {
        font-weight: 800;
        border-radius: 10px;
        margin: 0 2px;
        color: var(--text-main);
        background: var(--card-bg);
        border-color: var(--border-soft)
    }

    .pagination .page-item.active .page-link {
        background: var(--brand-1, #2563eb);
        border-color: var(--brand-1, #2563eb);
        color: #fff
    }

    .pagination .page-item.disabled .page-link {
        opacity: .5
    }

    @media(max-width:767.98px) {
        .module-page .page-head {
            padding: 18px;
            border-radius: 18px
        }

        .module-page .page-head h1 {
            font-size: 24px
        }

        .module-card {
            padding: 16px;
            border-radius: 18px
        }

        .desktop-table {
            display: none !important
        }

        .mobile-cards {
            display: block
        }

        .mobile-card-actions .btn {
            width: 100%
        }

        .filter-card .btn {
            width: 100%
        }
    }

    @media print {

        #sidebar,
        #mobileOverlay,
        #settingsOverlay,
        nav,
        .app-shell>aside,
        .no-print,
        .filter-card,
        .toast-container,
        .pagination-wrap {
            display: none !important
        }

        main {
            margin: 0 !important
        }

        .page-section {
            padding: 0 !important
        }

        .card-ui,
        .module-card,
        .page-head {
            box-shadow: none !important;
            border: 1px solid #ddd !important
        }

        .desktop-table {
            display: block !important
        }

        .mobile-cards {
            display: none !important
        }

        body {
            background: #fff !important
        }

        .table-ui {
            width: 100% !important;
            font-size: 11px
        }

        .table-ui th,
        .table-ui td {
            padding: 7px !important
        }
    }
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
                            <h1 class="mb-1">Payments</h1>
                            <p class="text-muted-custom mb-0">
                                <?= $jobContext ? 'Payment details for job card ' . e($jobContext['job_card_no'] ?? '-') : 'Paid, unpaid and partially paid bills in one list with filter-wise pagination.' ?>
                            </p>
                        </div>
                        <div class="d-flex flex-column flex-sm-row gap-2 no-print">
                            <a href="proforma_bills.php"
                                class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Proforma List</a>
                            <a href="<?= e($exportUrl) ?>" class="btn btn-primary rounded-pill px-4 fw-bold"><i
                                    data-lucide="file-down"></i> Export PDF</a>
                        </div>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000">
                    <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive"
                        aria-atomic="true" data-bs-delay="4200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title"><?= e($toastTitle) ?></div>
                                <div class="toast-message"><?= e($message) ?></div>
                            </div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"
                                aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                <div class="card-ui module-card">
                    <div class="alert alert-danger rounded-4 fw-bold mb-0"><?= e($error) ?></div>
                </div>
                <?php else: ?>

                <?php if ($jobContext): ?>
                <div class="job-context mb-3">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-3"><strong>Job Card</strong><br><?= e($jobContext['job_card_no'] ?? '-') ?>
                        </div>
                        <div class="col-md-3"><strong>Proforma</strong><br><?= e($jobContext['proforma_no'] ?? '-') ?>
                        </div>
                        <div class="col-md-3"><strong>Customer</strong><br><?= e($jobContext['customer_name'] ?? '-') ?>
                            · <?= e($jobContext['mobile'] ?? '') ?></div>
                        <div class="col-md-3">
                            <strong>Balance</strong><br><?= e(payMoney($jobContext['balance_amount'] ?? 0)) ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)"><i
                                    data-lucide="list-filter"></i></div>
                            <div><span>Filtered Records</span><strong><?= number_format($filteredCount) ?></strong>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)"><i
                                    data-lucide="indian-rupee"></i></div>
                            <div><span>Paid Amount</span><strong><?= e(payMoney($summaryPaidAmount)) ?></strong></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#dc2626,#ef4444)"><i
                                    data-lucide="wallet-cards"></i></div>
                            <div><span>Outstanding</span><strong><?= e(payMoney($summaryOutstanding)) ?></strong></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)"><i
                                    data-lucide="clock-alert"></i></div>
                            <div><span>Need Payment</span><strong><?= number_format($summaryNeedsPayment) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Payment List</h2>
                            <p class="text-muted-custom mb-0">No separate pending section. Use Payment Status to switch
                                between Paid and Unpaid / Partially Paid bills.</p>
                        </div>
                        <?php if (!$exportPdf): ?>
                        <div class="small text-muted-custom fw-bold">Showing
                            <?= number_format($showFrom) ?>-<?= number_format($showTo) ?> of
                            <?= number_format($filteredCount) ?></div>
                        <?php endif; ?>
                    </div>

                    <form method="get" class="filter-card mb-3 no-print" id="paymentFilterForm">
                        <?php if ($jobCardId > 0): ?><input type="hidden" name="job_card_id"
                            value="<?= (int)$jobCardId ?>"><?php endif; ?>
                        <?php if ($proformaId > 0): ?><input type="hidden" name="proforma_id"
                            value="<?= (int)$proformaId ?>"><?php endif; ?>
                        <div class="row g-3 align-items-end">
                            <div class="col-12 col-lg-3">
                                <label class="form-label fw-bold">Payment Status</label>
                                <select name="status" id="paymentStatusFilter" class="form-select">
                                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                                    <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid /
                                        Partially Paid</option>
                                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>
                                        Cancelled</option>
                                </select>
                            </div>
                            <div class="col-12 col-lg-3">
                                <label class="form-label fw-bold">Search</label>
                                <input type="search" name="q" class="form-control" value="<?= e($q) ?>"
                                    placeholder="Proforma / customer / mobile / job card">
                            </div>
                            <div class="col-6 col-lg-2"><label class="form-label fw-bold">From</label><input type="date"
                                    name="date_from" class="form-control" value="<?= e($dateFrom) ?>"></div>
                            <div class="col-6 col-lg-2"><label class="form-label fw-bold">To</label><input type="date"
                                    name="date_to" class="form-control" value="<?= e($dateTo) ?>"></div>
                            <div class="col-12 col-lg-2 d-flex gap-2"><button type="submit"
                                    class="btn btn-primary rounded-pill fw-bold flex-fill">Filter</button><a
                                    href="payments.php"
                                    class="btn btn-outline-secondary rounded-pill fw-bold flex-fill">Reset</a></div>
                        </div>
                    </form>

                    <div class="table-responsive desktop-table">
                        <table class="table-ui" id="paymentsTable">
                            <thead>
                                <tr>
                                    <th>Bill / Sale</th>
                                    <th>Customer</th>
                                    <th>Job Card</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Last Payment</th>
                                    <th>Status</th>
                                    <th class="no-print">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$displayRows): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted-custom py-4">No payment details found
                                        for the selected filters.</td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($displayRows as $row): ?>
                                <?php
                            $recordType = (string)($row['record_type'] ?? 'proforma');
                            $billStatus = (string)($row['bill_status'] ?? 'unpaid');
                            $isQuickSale = $recordType === 'quick_sale';
                            $isCancelled = $recordType === 'cancelled';
                            $paid = (float)($row['paid_amount'] ?? 0);
                            $balance = (float)($row['current_balance'] ?? 0);
                            $statusLabel = $billStatus === 'partial' ? 'Partially Paid' : ucfirst($billStatus);
                        ?>
                                <tr>
                                    <td>
                                        <?php if ($isQuickSale): ?>
                                        <a href="quick-sales.php?q=<?= urlencode((string)($row['proforma_no'] ?? '')) ?>"
                                            class="fw-bold text-decoration-none"><?= e($row['proforma_no'] ?? '-') ?></a>
                                        <small class="d-block text-muted-custom">Quick Sale</small>
                                        <?php else: ?>
                                        <a href="proforma_bill_view.php?id=<?= (int)($row['bill_id'] ?? 0) ?>"
                                            class="fw-bold text-decoration-none"><?= e($row['proforma_no'] ?? '-') ?></a>
                                        <?php if ($isCancelled): ?><small class="d-block text-muted-custom">Payment:
                                            <?= e($row['payment_no'] ?? '-') ?></small><?php else: ?><small
                                            class="d-block text-muted-custom"><?= e(ucfirst((string)($row['order_type'] ?? '-'))) ?></small><?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= e($row['customer_name'] ?? '-') ?></strong><small
                                            class="d-block text-muted-custom"><?= e($row['mobile'] ?? '-') ?></small><?php if ($isQuickSale && !empty($row['quick_sale_products'])): ?><small
                                            class="d-block text-muted-custom"><?= e($row['quick_sale_products']) ?></small><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isQuickSale): ?><span class="text-muted-custom fw-bold">N/A</span>
                                        <?php elseif (!empty($row['job_card_id'])): ?><span
                                            class="status-pill job"><?= e($row['job_card_no'] ?? '-') ?></span><?php if (!empty($row['job_status_name'])): ?><small
                                            class="d-block text-muted-custom mt-1"><?= e($row['job_status_name']) ?></small><?php endif; ?>
                                        <?php else: ?><span class="text-muted-custom fw-bold">Not
                                            Created</span><?php endif; ?>
                                    </td>
                                    <td><strong><?= e(payMoney($row['final_amount'] ?? 0)) ?></strong></td>
                                    <td>
                                        <?php if ($isCancelled): ?><span class="text-danger fw-bold">Cancelled
                                            <?= e(payMoney($row['cancelled_amount'] ?? 0)) ?></span>
                                        <?php else: ?><span
                                            class="paid-amount"><?= e(payMoney($paid)) ?></span><?php endif; ?>
                                    </td>
                                    <td><?php if ($isCancelled): ?><?= e(payMoney($balance)) ?><?php elseif ($balance > 0.009): ?><span
                                            class="balance-amount"><?= e(payMoney($balance)) ?></span><?php else: ?><span
                                            class="paid-amount">₹0.00</span><?php endif; ?></td>
                                    <td>
                                        <?php if ($isCancelled): ?><?= e(payDate($row['payment_date'] ?? null)) ?><small
                                            class="d-block text-danger">Cancelled:
                                            <?= e(payDateTime($row['cancelled_at'] ?? null)) ?></small><small
                                            class="d-block text-muted-custom"><?= e($row['cancel_reason'] ?? '-') ?></small>
                                        <?php else: ?><?= e(payDate($row['last_payment_date'] ?? null)) ?><small
                                            class="d-block text-muted-custom"><?= e($row['last_payment_mode'] ?? ($paid > 0 ? '-' : 'No payment')) ?></small><?php if (!empty($row['last_reference_no'])): ?><small
                                            class="d-block text-muted-custom">Ref:
                                            <?= e($row['last_reference_no']) ?></small><?php endif; ?><?php endif; ?>
                                    </td>
                                    <td><span class="status-pill <?= e($billStatus) ?>"><?= e($statusLabel) ?></span>
                                    </td>
                                    <td class="no-print">
                                        <?php if (!$isQuickSale && !$isCancelled && $balance > 0.009): ?>
                                        <a href="proforma_payment.php?id=<?= (int)$row['bill_id'] ?>"
                                            class="btn btn-success btn-sm rounded-pill px-3 fw-bold"><i
                                                data-lucide="indian-rupee"></i> Pay</a>
                                        <?php elseif (!$isQuickSale && !$isCancelled): ?>
                                        <a href="proforma_payment.php?id=<?= (int)$row['bill_id'] ?>"
                                            class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold">View
                                            Payments</a>
                                        <?php elseif ($isQuickSale): ?>
                                        <a href="quick-sales.php?q=<?= urlencode((string)($row['proforma_no'] ?? '')) ?>"
                                            class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold">View</a>
                                        <?php else: ?>
                                        <a href="proforma_payment.php?id=<?= (int)($row['bill_id'] ?? 0) ?>"
                                            class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold">View</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-cards">
                        <?php if (!$displayRows): ?><div class="mobile-card text-center text-muted-custom">No payment
                            details found.</div><?php endif; ?>
                        <?php foreach ($displayRows as $row): ?>
                        <?php
                        $recordType = (string)($row['record_type'] ?? 'proforma');
                        $billStatus = (string)($row['bill_status'] ?? 'unpaid');
                        $isQuickSale = $recordType === 'quick_sale';
                        $isCancelled = $recordType === 'cancelled';
                        $paid = (float)($row['paid_amount'] ?? 0);
                        $balance = (float)($row['current_balance'] ?? 0);
                        $statusLabel = $billStatus === 'partial' ? 'Partially Paid' : ucfirst($billStatus);
                    ?>
                        <div class="mobile-card">
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <div class="mobile-card-title"><?= e($row['proforma_no'] ?? '-') ?></div>
                                    <span class="mobile-card-subtitle"><?= e($row['customer_name'] ?? '-') ?> ·
                                        <?= e($row['mobile'] ?? '-') ?></span>
                                    <?php if (!$isQuickSale): ?><span class="mobile-card-subtitle">Job Card:
                                        <?= e($row['job_card_no'] ?? 'Not Created') ?></span><?php endif; ?>
                                    <span class="mobile-card-subtitle">Total:
                                        <?= e(payMoney($row['final_amount'] ?? 0)) ?></span>
                                    <?php if ($isCancelled): ?><span
                                        class="mobile-card-subtitle text-danger fw-bold">Cancelled Amount:
                                        <?= e(payMoney($row['cancelled_amount'] ?? 0)) ?></span><span
                                        class="mobile-card-subtitle">Reason:
                                        <?= e($row['cancel_reason'] ?? '-') ?></span>
                                    <?php else: ?><span class="mobile-card-subtitle">Paid:
                                        <?= e(payMoney($paid)) ?></span><span
                                        class="mobile-card-subtitle <?= $balance > 0.009 ? 'text-danger fw-bold' : '' ?>">Balance:
                                        <?= e(payMoney($balance)) ?></span><?php endif; ?>
                                </div>
                                <span class="status-pill <?= e($billStatus) ?>"><?= e($statusLabel) ?></span>
                            </div>
                            <div class="mobile-card-actions no-print">
                                <?php if (!$isQuickSale && !$isCancelled && $balance > 0.009): ?><a
                                    href="proforma_payment.php?id=<?= (int)$row['bill_id'] ?>"
                                    class="btn btn-success rounded-pill fw-bold">Make Payment</a>
                                <?php elseif (!$isQuickSale): ?><a
                                    href="proforma_payment.php?id=<?= (int)($row['bill_id'] ?? 0) ?>"
                                    class="btn btn-outline-primary rounded-pill fw-bold">View Payments</a>
                                <?php else: ?><a
                                    href="quick-sales.php?q=<?= urlencode((string)($row['proforma_no'] ?? '')) ?>"
                                    class="btn btn-outline-primary rounded-pill fw-bold">View Quick
                                    Sale</a><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$exportPdf && $filteredCount > $perPage): ?>
                    <div
                        class="pagination-wrap d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mt-4 no-print">
                        <div class="text-muted-custom fw-bold small">Page <?= number_format($page) ?> of
                            <?= number_format($totalPages) ?></div>
                        <nav aria-label="Payment pagination">
                            <ul class="pagination mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link"
                                        href="<?= e(payBuildUrl(['page' => max(1,$page-1)])) ?>">Previous</a></li>
                                <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            if ($startPage > 1):
                        ?>
                                <li class="page-item"><a class="page-link"
                                        href="<?= e(payBuildUrl(['page'=>1])) ?>">1</a></li>
                                <?php if ($startPage > 2): ?><li class="page-item disabled"><span
                                        class="page-link">…</span></li><?php endif; ?>
                                <?php endif; ?>
                                <?php for ($p=$startPage; $p<=$endPage; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link"
                                        href="<?= e(payBuildUrl(['page'=>$p])) ?>"><?= $p ?></a></li>
                                <?php endfor; ?>
                                <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span
                                        class="page-link">…</span></li><?php endif; ?>
                                <li class="page-item"><a class="page-link"
                                        href="<?= e(payBuildUrl(['page'=>$totalPages])) ?>"><?= $totalPages ?></a></li>
                                <?php endif; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link"
                                        href="<?= e(payBuildUrl(['page' => min($totalPages,$page+1)])) ?>">Next</a></li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>
        </main>
        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>
    <?php include __DIR__ . '/includes/script.php'; ?>
    <script>
    (function() {
        const pageToastEl = document.getElementById('pageToast');
        if (pageToastEl && window.bootstrap && bootstrap.Toast) {
            bootstrap.Toast.getOrCreateInstance(pageToastEl).show();
        }
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }

        const statusFilter = document.getElementById('paymentStatusFilter');
        const filterForm = document.getElementById('paymentFilterForm');
        statusFilter?.addEventListener('change', function() {
            // Status changes immediately and resets pagination to page 1.
            const oldPage = filterForm?.querySelector('input[name="page"]');
            if (oldPage) oldPage.remove();
            filterForm?.submit();
        });

        <?php if ($exportPdf): ?>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 400);
        });
        <?php endif; ?>
    })();
    </script>
</body>

</html>