<?php
/**
 * reports.php
 * Subhiksha Cards ERP - Reports Dashboard
 */
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'reports.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function rpt_table_exists(mysqli $conn, string $table): bool
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

function rpt_col_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function rpt_money($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function rpt_date($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function rpt_datetime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime((string)$value)) : '-';
}

function rpt_query_scalar(mysqli $conn, string $sql, string $types = '', array $params = [], $default = 0)
{
    try {
        if ($types !== '') {
            $stmt = $conn->prepare($sql);
            if (!$stmt) return $default;
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            $res = $conn->query($sql);
            if (!$res) return $default;
            $row = $res->fetch_assoc();
            $res->free();
        }
        if (!$row) return $default;
        $value = array_values($row)[0] ?? $default;
        return $value === null ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function rpt_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = [];
    try {
        if ($types !== '') {
            $stmt = $conn->prepare($sql);
            if (!$stmt) return [];
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $rows[] = $row;
            $stmt->close();
        } else {
            $res = $conn->query($sql);
            if (!$res) return [];
            while ($row = $res->fetch_assoc()) $rows[] = $row;
            $res->free();
        }
    } catch (Throwable $e) {
        return [];
    }
    return $rows;
}

function rpt_status_class(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['paid', 'completed', 'approved', 'delivered'], true)) return 'success';
    if (in_array($status, ['delayed', 'rejected', 'cancelled'], true)) return 'danger';
    if (in_array($status, ['in_progress', 'progress'], true)) return 'primary';
    return 'warning';
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$dateFrom = trim((string)($_GET['date_from'] ?? $monthStart));
$dateTo = trim((string)($_GET['date_to'] ?? $today));
$reportType = trim((string)($_GET['report'] ?? 'overview'));
$export = trim((string)($_GET['export'] ?? ''));

if ($dateFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $monthStart;
if ($dateTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = $today;
if (strtotime($dateFrom) > strtotime($dateTo)) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$allowedReports = ['overview', 'sales', 'payments', 'job_cards', 'pending', 'delays', 'delivery'];
if (!in_array($reportType, $allowedReports, true)) $reportType = 'overview';

$whereDatePb = "DATE(pb.created_at) BETWEEN ? AND ?";
$whereDatePay = "DATE(p.payment_date) BETWEEN ? AND ?";
$whereDateJc = "DATE(jc.created_at) BETWEEN ? AND ?";

$stats = [
    'total_sales' => 0,
    'total_advance' => 0,
    'total_balance' => 0,
    'proforma_count' => 0,
    'payment_collected' => 0,
    'payment_count' => 0,
    'job_count' => 0,
    'completed_jobs' => 0,
    'pending_jobs' => 0,
    'delayed_jobs' => 0,
];

if (rpt_table_exists($conn, 'proforma_bills')) {
    $stats['total_sales'] = (float)rpt_query_scalar($conn, "SELECT COALESCE(SUM(pb.final_amount),0) FROM proforma_bills pb WHERE {$whereDatePb}", 'ss', [$dateFrom, $dateTo]);
    $stats['total_advance'] = (float)rpt_query_scalar($conn, "SELECT COALESCE(SUM(pb.advance_amount),0) FROM proforma_bills pb WHERE {$whereDatePb}", 'ss', [$dateFrom, $dateTo]);
    $stats['total_balance'] = (float)rpt_query_scalar($conn, "SELECT COALESCE(SUM(pb.balance_amount),0) FROM proforma_bills pb WHERE {$whereDatePb}", 'ss', [$dateFrom, $dateTo]);
    $stats['proforma_count'] = (int)rpt_query_scalar($conn, "SELECT COUNT(*) FROM proforma_bills pb WHERE {$whereDatePb}", 'ss', [$dateFrom, $dateTo]);
}

if (rpt_table_exists($conn, 'payments')) {
    $cancelCond = rpt_col_exists($conn, 'payments', 'status') ? " AND LOWER(COALESCE(p.status,'')) NOT IN ('cancelled','canceled')" : '';
    $stats['payment_collected'] = (float)rpt_query_scalar($conn, "SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE {$whereDatePay}{$cancelCond}", 'ss', [$dateFrom, $dateTo]);
    $stats['payment_count'] = (int)rpt_query_scalar($conn, "SELECT COUNT(*) FROM payments p WHERE {$whereDatePay}{$cancelCond}", 'ss', [$dateFrom, $dateTo]);
}

if (rpt_table_exists($conn, 'job_cards')) {
    $stats['job_count'] = (int)rpt_query_scalar($conn, "SELECT COUNT(*) FROM job_cards jc WHERE {$whereDateJc}", 'ss', [$dateFrom, $dateTo]);
    $stats['completed_jobs'] = (int)rpt_query_scalar($conn, "SELECT COUNT(*) FROM job_cards jc LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id WHERE {$whereDateJc} AND (LOWER(COALESCE(jcs.status_key,'')) = 'completed' OR LOWER(COALESCE(jcs.status_name,'')) = 'completed' OR jc.completed_at IS NOT NULL)", 'ss', [$dateFrom, $dateTo]);
    $stats['delayed_jobs'] = (int)rpt_query_scalar($conn, "SELECT COUNT(*) FROM job_cards jc LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id WHERE {$whereDateJc} AND (jc.is_delayed = 1 OR LOWER(COALESCE(jcs.status_key,'')) = 'delayed' OR LOWER(COALESCE(jcs.status_name,'')) = 'delayed')", 'ss', [$dateFrom, $dateTo]);
    $stats['pending_jobs'] = max(0, $stats['job_count'] - $stats['completed_jobs']);
}

$salesRows = [];
$paymentRows = [];
$jobRows = [];
$pendingRows = [];
$delayRows = [];
$deliveryRows = [];
$dailySalesRows = [];
$functionRows = [];

if (rpt_table_exists($conn, 'proforma_bills')) {
    $dailySalesRows = rpt_fetch_all($conn, "
        SELECT DATE(pb.created_at) AS report_date, COUNT(*) AS total_orders, COALESCE(SUM(pb.final_amount),0) AS total_amount
        FROM proforma_bills pb
        WHERE {$whereDatePb}
        GROUP BY DATE(pb.created_at)
        ORDER BY report_date ASC
    ", 'ss', [$dateFrom, $dateTo]);

    $functionRows = rpt_fetch_all($conn, "
        SELECT COALESCE(ft.function_name, 'Not Set') AS function_name, COUNT(*) AS total_orders, COALESCE(SUM(pb.final_amount),0) AS total_amount
        FROM proforma_bills pb
        LEFT JOIN function_types ft ON ft.id = pb.function_type_id
        WHERE {$whereDatePb}
        GROUP BY COALESCE(ft.function_name, 'Not Set')
        ORDER BY total_amount DESC
        LIMIT 10
    ", 'ss', [$dateFrom, $dateTo]);

    $salesRows = rpt_fetch_all($conn, "
        SELECT pb.id, pb.proforma_no, pb.customer_name, pb.mobile, pb.order_type, pb.total_qty, pb.final_amount, pb.advance_amount, pb.balance_amount, pb.delivery_date, pb.created_at, COALESCE(ft.function_name,'-') AS function_name, COALESCE(ps.status_name,'-') AS status_name
        FROM proforma_bills pb
        LEFT JOIN function_types ft ON ft.id = pb.function_type_id
        LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id
        WHERE {$whereDatePb}
        ORDER BY pb.id DESC
        LIMIT 300
    ", 'ss', [$dateFrom, $dateTo]);
}

if (rpt_table_exists($conn, 'payments')) {
    $cancelSelect = rpt_col_exists($conn, 'payments', 'status') ? "COALESCE(p.status,'paid')" : "'paid'";
    $paymentRows = rpt_fetch_all($conn, "
        SELECT p.id, p.payment_no, p.payment_type, p.payment_mode, p.amount, p.payment_date, p.reference_no, p.remarks, {$cancelSelect} AS payment_status, pb.proforma_no, COALESCE(pb.customer_name, c.customer_name, '-') AS customer_name, COALESCE(pb.mobile, c.mobile, '-') AS mobile, u.username AS received_by_name
        FROM payments p
        LEFT JOIN proforma_bills pb ON pb.id = p.proforma_bill_id
        LEFT JOIN customers c ON c.id = p.customer_id
        LEFT JOIN users u ON u.id = p.received_by
        WHERE {$whereDatePay}
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT 300
    ", 'ss', [$dateFrom, $dateTo]);
}

if (rpt_table_exists($conn, 'job_cards')) {
    $jobRows = rpt_fetch_all($conn, "
        SELECT jc.id, jc.job_card_no, jc.customer_name, jc.mobile, jc.order_type, jc.product_name, jc.final_amount, jc.advance_amount, jc.balance_amount, jc.delivery_date, jc.created_at, jc.completed_at, jc.is_delayed, COALESCE(jcs.status_name,'-') AS status_name, COALESCE(ws.step_name,'-') AS current_step_name, COALESCE(ft.function_name,'-') AS function_name
        FROM job_cards jc
        LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
        LEFT JOIN workflow_steps ws ON ws.id = jc.current_workflow_step_id
        LEFT JOIN function_types ft ON ft.id = jc.function_type_id
        WHERE {$whereDateJc}
        ORDER BY jc.id DESC
        LIMIT 300
    ", 'ss', [$dateFrom, $dateTo]);

    $pendingRows = rpt_fetch_all($conn, "
        SELECT jc.id, jc.job_card_no, jc.customer_name, jc.mobile, jc.product_name, jc.delivery_date, jc.created_at, COALESCE(jcs.status_name,'Pending') AS status_name, COALESCE(ws.step_name,'-') AS current_step_name
        FROM job_cards jc
        LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
        LEFT JOIN workflow_steps ws ON ws.id = jc.current_workflow_step_id
        WHERE DATE(jc.created_at) BETWEEN ? AND ?
          AND (jc.completed_at IS NULL)
        ORDER BY jc.delivery_date ASC, jc.id DESC
        LIMIT 300
    ", 'ss', [$dateFrom, $dateTo]);

    $deliveryRows = rpt_fetch_all($conn, "
        SELECT jc.id, jc.job_card_no, jc.customer_name, jc.mobile, jc.product_name, jc.delivery_date, jc.completed_at, jc.is_delayed, COALESCE(jcs.status_name,'-') AS status_name, COALESCE(ws.step_name,'-') AS current_step_name
        FROM job_cards jc
        LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
        LEFT JOIN workflow_steps ws ON ws.id = jc.current_workflow_step_id
        WHERE jc.delivery_date BETWEEN ? AND ?
        ORDER BY jc.delivery_date ASC, jc.id DESC
        LIMIT 300
    ", 'ss', [$dateFrom, $dateTo]);
}

if (rpt_table_exists($conn, 'job_tracking')) {
    $delayRows = rpt_fetch_all($conn, "
        SELECT jt.id, jt.job_card_id, jt.status, jt.planned_completion_date, jt.revised_completion_date, jt.actual_completed_at, jt.delay_days, jt.delay_remarks, jc.job_card_no, jc.customer_name, jc.mobile, jc.product_name, ws.step_name, dr.reason_name
        FROM job_tracking jt
        LEFT JOIN job_cards jc ON jc.id = jt.job_card_id
        LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
        LEFT JOIN delay_reasons dr ON dr.id = jt.delay_reason_id
        WHERE (jt.is_delayed = 1 OR jt.status = 'delayed')
          AND DATE(COALESCE(jt.delay_started_at, jt.updated_at, jt.created_at)) BETWEEN ? AND ?
        ORDER BY COALESCE(jt.delay_started_at, jt.updated_at, jt.created_at) DESC, jt.id DESC
        LIMIT 300
    ", 'ss', [$dateFrom, $dateTo]);
}

$currentRows = [];
$exportTitle = 'Report';
if ($reportType === 'sales') { $currentRows = $salesRows; $exportTitle = 'Sales Report'; }
elseif ($reportType === 'payments') { $currentRows = $paymentRows; $exportTitle = 'Payment Report'; }
elseif ($reportType === 'job_cards') { $currentRows = $jobRows; $exportTitle = 'Job Card Report'; }
elseif ($reportType === 'pending') { $currentRows = $pendingRows; $exportTitle = 'Pending Jobs Report'; }
elseif ($reportType === 'delays') { $currentRows = $delayRows; $exportTitle = 'Delay Report'; }
elseif ($reportType === 'delivery') { $currentRows = $deliveryRows; $exportTitle = 'Delivery Report'; }

if ($export === 'csv' && $reportType !== 'overview') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . strtolower(str_replace(' ', '_', $exportTitle)) . '_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    if ($currentRows) {
        fputcsv($out, array_keys($currentRows[0]));
        foreach ($currentRows as $r) fputcsv($out, $r);
    } else {
        fputcsv($out, ['No records found']);
    }
    fclose($out);
    exit;
}

$queryBase = http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo]);
$printMode = $export === 'pdf';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reports - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
        .reports-page .page-head{padding:24px 28px;margin-bottom:18px;background:linear-gradient(135deg,rgba(37,99,235,.10),rgba(34,197,94,.10)),var(--card-bg)}
        .reports-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}
        .module-card{padding:24px}.module-title{font-size:18px;font-weight:900;color:var(--text-main);margin:0}.report-tabs{display:flex;gap:8px;flex-wrap:wrap}.report-tab{border:1px solid var(--border-soft);background:var(--card-bg);color:var(--text-main);border-radius:999px;padding:9px 14px;font-size:13px;font-weight:900;text-decoration:none}.report-tab.active{background:#2563eb;color:#fff;border-color:#2563eb}.stat-card{border:1px solid var(--border-soft);border-radius:22px;padding:18px;background:var(--card-bg);height:100%;box-shadow:0 12px 30px rgba(15,23,42,.06)}.stat-card .icon{width:42px;height:42px;border-radius:16px;display:inline-flex;align-items:center;justify-content:center;background:#eff6ff;color:#2563eb;margin-bottom:12px}.stat-card strong{display:block;font-size:24px;font-weight:950;color:var(--text-main);line-height:1.1}.stat-card span{display:block;font-size:12px;color:var(--text-muted);font-weight:900;text-transform:uppercase;margin-top:5px}.stat-card.green .icon{background:#dcfce7;color:#166534}.stat-card.orange .icon{background:#ffedd5;color:#c2410c}.stat-card.red .icon{background:#fee2e2;color:#991b1b}.stat-card.purple .icon{background:#f3e8ff;color:#7e22ce}.table-ui th{font-size:11px;text-transform:uppercase;color:var(--text-muted);white-space:nowrap}.table-ui td{vertical-align:middle!important}.status-pill{font-size:11px;font-weight:900;border-radius:999px;padding:6px 10px;display:inline-flex;align-items:center}.status-pill.success{background:#dcfce7;color:#166534}.status-pill.primary{background:#dbeafe;color:#1d4ed8}.status-pill.warning{background:#fef3c7;color:#92400e}.status-pill.danger{background:#fee2e2;color:#991b1b}.amount{font-weight:950}.amount.green{color:#166534}.amount.red{color:#991b1b}.chart-list{display:grid;gap:10px}.chart-row{border:1px solid var(--border-soft);border-radius:16px;padding:12px;background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg))}.bar-bg{height:10px;border-radius:999px;background:color-mix(in srgb,var(--border-soft) 80%,transparent);overflow:hidden;margin-top:8px}.bar-fill{height:100%;border-radius:999px;background:linear-gradient(135deg,#2563eb,#22c55e)}.filter-card{padding:18px}.mobile-report-cards{display:none}.mobile-report-card{border:1px solid var(--border-soft);border-radius:18px;padding:15px;background:var(--card-bg);margin-bottom:12px}.report-print-title{display:none}
        @media(max-width:767.98px){.reports-page .page-head{padding:18px;border-radius:18px}.reports-page .page-head h1{font-size:24px}.module-card{padding:16px;border-radius:18px}.desktop-report-table{display:none!important}.mobile-report-cards{display:block}.stat-card strong{font-size:21px}.report-tabs{overflow-x:auto;flex-wrap:nowrap;padding-bottom:4px}.report-tab{white-space:nowrap}}
        @media print{#sidebar,#mobileOverlay,#settingsOverlay,nav,.no-print,.filter-card,.report-tabs,.mobile-report-cards,.btn{display:none!important}.app-shell{display:block!important}#main{margin:0!important;width:100%!important}.page-section{padding:0!important}.card-ui,.module-card,.page-head{box-shadow:none!important;border:0!important}.desktop-report-table{display:block!important}.report-print-title{display:block!important}.table-responsive{overflow:visible!important}.table-ui{font-size:11px}.reports-page .page-head{padding:0 0 12px!important;background:#fff!important}}
    </style>
</head>
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main id="main">
        <?php include __DIR__ . '/includes/nav.php'; ?>
        <section class="page-section reports-page">
            <div class="card-ui page-head">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div>
                        <h1 class="mb-1">Reports</h1>
                        <p class="text-muted-custom mb-0">Sales, payment, job card, delivery and delay reports.</p>
                    </div>
                    <div class="d-flex gap-2 no-print">
                        <?php if ($reportType !== 'overview'): ?>
                        <a href="reports.php?<?= e($queryBase . '&report=' . urlencode($reportType) . '&export=csv') ?>" class="btn btn-outline-success rounded-pill px-4 fw-bold"><i data-lucide="file-spreadsheet" class="me-1"></i> Excel/CSV</a>
                        <a href="reports.php?<?= e($queryBase . '&report=' . urlencode($reportType) . '&export=pdf') ?>" class="btn btn-outline-danger rounded-pill px-4 fw-bold"><i data-lucide="file-text" class="me-1"></i> PDF</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card-ui filter-card mb-3 no-print">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Report</label>
                        <select name="report" class="form-select">
                            <option value="overview" <?= $reportType==='overview'?'selected':'' ?>>Overview</option>
                            <option value="sales" <?= $reportType==='sales'?'selected':'' ?>>Sales</option>
                            <option value="payments" <?= $reportType==='payments'?'selected':'' ?>>Payments</option>
                            <option value="job_cards" <?= $reportType==='job_cards'?'selected':'' ?>>Job Cards</option>
                            <option value="pending" <?= $reportType==='pending'?'selected':'' ?>>Pending Jobs</option>
                            <option value="delays" <?= $reportType==='delays'?'selected':'' ?>>Delay Report</option>
                            <option value="delivery" <?= $reportType==='delivery'?'selected':'' ?>>Delivery Report</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold flex-fill">Apply</button>
                        <a href="reports.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Reset</a>
                    </div>
                </form>
            </div>

            <div class="report-tabs mb-3 no-print">
                <?php foreach (['overview'=>'Overview','sales'=>'Sales','payments'=>'Payments','job_cards'=>'Job Cards','pending'=>'Pending','delays'=>'Delays','delivery'=>'Delivery'] as $key => $label): ?>
                <a class="report-tab <?= $reportType===$key?'active':'' ?>" href="reports.php?<?= e($queryBase . '&report=' . $key) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>

            <h2 class="report-print-title"><?= e($exportTitle) ?> (<?= e(rpt_date($dateFrom)) ?> to <?= e(rpt_date($dateTo)) ?>)</h2>

            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3"><div class="stat-card"><div class="icon"><i data-lucide="receipt-indian-rupee"></i></div><strong><?= e(rpt_money($stats['total_sales'])) ?></strong><span>Total Sales</span></div></div>
                <div class="col-6 col-lg-3"><div class="stat-card green"><div class="icon"><i data-lucide="wallet"></i></div><strong><?= e(rpt_money($stats['payment_collected'])) ?></strong><span>Payment Collected</span></div></div>
                <div class="col-6 col-lg-3"><div class="stat-card red"><div class="icon"><i data-lucide="badge-alert"></i></div><strong><?= e(rpt_money($stats['total_balance'])) ?></strong><span>Balance Pending</span></div></div>
                <div class="col-6 col-lg-3"><div class="stat-card purple"><div class="icon"><i data-lucide="briefcase-business"></i></div><strong><?= number_format($stats['job_count']) ?></strong><span>Job Cards</span></div></div>
            </div>

            <?php if ($reportType === 'overview'): ?>
            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card-ui module-card h-100">
                        <h2 class="module-title mb-3">Daily Sales</h2>
                        <?php if (!$dailySalesRows): ?>
                        <div class="alert alert-warning rounded-4 fw-bold mb-0">No sales found.</div>
                        <?php else: ?>
                        <div class="chart-list">
                            <?php $maxSales = max(1, ...array_map(function($r){ return (float)$r['total_amount']; }, $dailySalesRows)); ?>
                            <?php foreach ($dailySalesRows as $row): $pct = min(100, round(((float)$row['total_amount'] / $maxSales) * 100)); ?>
                            <div class="chart-row">
                                <div class="d-flex justify-content-between gap-2"><strong><?= e(rpt_date($row['report_date'])) ?></strong><span class="amount green"><?= e(rpt_money($row['total_amount'])) ?></span></div>
                                <small class="text-muted-custom fw-bold"><?= number_format((int)$row['total_orders']) ?> order(s)</small>
                                <div class="bar-bg"><div class="bar-fill" style="width:<?= (int)$pct ?>%"></div></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card-ui module-card h-100">
                        <h2 class="module-title mb-3">Function-wise Sales</h2>
                        <?php if (!$functionRows): ?>
                        <div class="alert alert-warning rounded-4 fw-bold mb-0">No function-wise data found.</div>
                        <?php else: ?>
                        <div class="chart-list">
                            <?php $maxFn = max(1, ...array_map(function($r){ return (float)$r['total_amount']; }, $functionRows)); ?>
                            <?php foreach ($functionRows as $row): $pct = min(100, round(((float)$row['total_amount'] / $maxFn) * 100)); ?>
                            <div class="chart-row">
                                <div class="d-flex justify-content-between gap-2"><strong><?= e($row['function_name']) ?></strong><span class="amount green"><?= e(rpt_money($row['total_amount'])) ?></span></div>
                                <small class="text-muted-custom fw-bold"><?= number_format((int)$row['total_orders']) ?> order(s)</small>
                                <div class="bar-bg"><div class="bar-fill" style="width:<?= (int)$pct ?>%"></div></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card-ui module-card">
                        <h2 class="module-title mb-3">Job Status Summary</h2>
                        <div class="row g-3">
                            <div class="col-md-4"><div class="stat-card green"><div class="icon"><i data-lucide="check-circle-2"></i></div><strong><?= number_format($stats['completed_jobs']) ?></strong><span>Completed Jobs</span></div></div>
                            <div class="col-md-4"><div class="stat-card orange"><div class="icon"><i data-lucide="loader"></i></div><strong><?= number_format($stats['pending_jobs']) ?></strong><span>Open / Pending Jobs</span></div></div>
                            <div class="col-md-4"><div class="stat-card red"><div class="icon"><i data-lucide="clock-alert"></i></div><strong><?= number_format($stats['delayed_jobs']) ?></strong><span>Delayed Jobs</span></div></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card-ui module-card">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3 no-print">
                    <div>
                        <h2 class="module-title"><?= e($exportTitle) ?></h2>
                        <p class="text-muted-custom mb-0"><?= e(rpt_date($dateFrom)) ?> to <?= e(rpt_date($dateTo)) ?></p>
                    </div>
                    <input type="search" id="reportSearch" class="form-control" style="max-width:340px" placeholder="Search report...">
                </div>

                <div class="table-responsive desktop-report-table">
                    <table class="table-ui table" id="reportTable">
                        <thead>
                        <?php if ($reportType === 'sales'): ?>
                        <tr><th>Proforma</th><th>Customer</th><th>Function</th><th>Order</th><th>Qty</th><th>Final</th><th>Advance</th><th>Balance</th><th>Delivery</th><th>Status</th></tr>
                        <?php elseif ($reportType === 'payments'): ?>
                        <tr><th>Payment No</th><th>Customer</th><th>Proforma</th><th>Type</th><th>Mode</th><th>Amount</th><th>Date</th><th>Reference</th><th>Status</th></tr>
                        <?php elseif ($reportType === 'job_cards'): ?>
                        <tr><th>Job Card</th><th>Customer</th><th>Product</th><th>Function</th><th>Order</th><th>Stage</th><th>Delivery</th><th>Amount</th><th>Status</th></tr>
                        <?php elseif ($reportType === 'pending'): ?>
                        <tr><th>Job Card</th><th>Customer</th><th>Product</th><th>Current Stage</th><th>Delivery</th><th>Created</th><th>Status</th></tr>
                        <?php elseif ($reportType === 'delays'): ?>
                        <tr><th>Job Card</th><th>Customer</th><th>Product</th><th>Stage</th><th>Planned</th><th>Delay Days</th><th>Reason</th><th>Remarks</th><th>Status</th></tr>
                        <?php elseif ($reportType === 'delivery'): ?>
                        <tr><th>Job Card</th><th>Customer</th><th>Product</th><th>Delivery Date</th><th>Current Stage</th><th>Completed At</th><th>Delay</th><th>Status</th></tr>
                        <?php endif; ?>
                        </thead>
                        <tbody>
                        <?php if (!$currentRows): ?>
                        <tr><td colspan="10" class="text-center text-muted-custom py-4 fw-bold">No records found.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($currentRows as $row): ?>
                            <?php if ($reportType === 'sales'): ?>
                            <tr>
                                <td><strong><?= e($row['proforma_no'] ?? '-') ?></strong><small class="d-block text-muted-custom"><?= e(rpt_datetime($row['created_at'] ?? null)) ?></small></td>
                                <td><?= e($row['customer_name'] ?? '-') ?><small class="d-block text-muted-custom"><?= e($row['mobile'] ?? '-') ?></small></td>
                                <td><?= e($row['function_name'] ?? '-') ?></td><td><?= e(ucfirst((string)($row['order_type'] ?? '-'))) ?></td><td><?= number_format((float)($row['total_qty'] ?? 0), 0) ?></td>
                                <td class="amount green"><?= e(rpt_money($row['final_amount'] ?? 0)) ?></td><td><?= e(rpt_money($row['advance_amount'] ?? 0)) ?></td><td class="amount red"><?= e(rpt_money($row['balance_amount'] ?? 0)) ?></td><td><?= e(rpt_date($row['delivery_date'] ?? null)) ?></td><td><span class="status-pill <?= e(rpt_status_class((string)($row['status_name'] ?? ''))) ?>"><?= e($row['status_name'] ?? '-') ?></span></td>
                            </tr>
                            <?php elseif ($reportType === 'payments'): ?>
                            <tr>
                                <td><strong><?= e($row['payment_no'] ?? '-') ?></strong></td><td><?= e($row['customer_name'] ?? '-') ?><small class="d-block text-muted-custom"><?= e($row['mobile'] ?? '-') ?></small></td><td><?= e($row['proforma_no'] ?? '-') ?></td><td><?= e(ucfirst((string)($row['payment_type'] ?? '-'))) ?></td><td><?= e(ucfirst((string)($row['payment_mode'] ?? '-'))) ?></td><td class="amount green"><?= e(rpt_money($row['amount'] ?? 0)) ?></td><td><?= e(rpt_date($row['payment_date'] ?? null)) ?></td><td><?= e($row['reference_no'] ?? '-') ?></td><td><span class="status-pill <?= e(rpt_status_class((string)($row['payment_status'] ?? 'paid'))) ?>"><?= e(ucfirst((string)($row['payment_status'] ?? 'paid'))) ?></span></td>
                            </tr>
                            <?php elseif ($reportType === 'job_cards'): ?>
                            <tr>
                                <td><strong><?= e($row['job_card_no'] ?? '-') ?></strong><small class="d-block text-muted-custom"><?= e(rpt_datetime($row['created_at'] ?? null)) ?></small></td><td><?= e($row['customer_name'] ?? '-') ?><small class="d-block text-muted-custom"><?= e($row['mobile'] ?? '-') ?></small></td><td><?= e($row['product_name'] ?? '-') ?></td><td><?= e($row['function_name'] ?? '-') ?></td><td><?= e(ucfirst((string)($row['order_type'] ?? '-'))) ?></td><td><?= e($row['current_step_name'] ?? '-') ?></td><td><?= e(rpt_date($row['delivery_date'] ?? null)) ?></td><td class="amount green"><?= e(rpt_money($row['final_amount'] ?? 0)) ?></td><td><span class="status-pill <?= e(rpt_status_class((string)($row['status_name'] ?? ''))) ?>"><?= e($row['status_name'] ?? '-') ?></span></td>
                            </tr>
                            <?php elseif ($reportType === 'pending'): ?>
                            <tr>
                                <td><strong><?= e($row['job_card_no'] ?? '-') ?></strong></td><td><?= e($row['customer_name'] ?? '-') ?><small class="d-block text-muted-custom"><?= e($row['mobile'] ?? '-') ?></small></td><td><?= e($row['product_name'] ?? '-') ?></td><td><?= e($row['current_step_name'] ?? '-') ?></td><td><?= e(rpt_date($row['delivery_date'] ?? null)) ?></td><td><?= e(rpt_datetime($row['created_at'] ?? null)) ?></td><td><span class="status-pill warning"><?= e($row['status_name'] ?? 'Pending') ?></span></td>
                            </tr>
                            <?php elseif ($reportType === 'delays'): ?>
                            <tr>
                                <td><strong><?= e($row['job_card_no'] ?? '-') ?></strong></td><td><?= e($row['customer_name'] ?? '-') ?><small class="d-block text-muted-custom"><?= e($row['mobile'] ?? '-') ?></small></td><td><?= e($row['product_name'] ?? '-') ?></td><td><?= e($row['step_name'] ?? '-') ?></td><td><?= e(rpt_date($row['planned_completion_date'] ?? null)) ?></td><td><span class="amount red"><?= number_format((int)($row['delay_days'] ?? 0)) ?></span></td><td><?= e($row['reason_name'] ?? '-') ?></td><td><?= e($row['delay_remarks'] ?? '-') ?></td><td><span class="status-pill danger"><?= e(ucfirst((string)($row['status'] ?? 'delayed'))) ?></span></td>
                            </tr>
                            <?php elseif ($reportType === 'delivery'): ?>
                            <tr>
                                <td><strong><?= e($row['job_card_no'] ?? '-') ?></strong></td><td><?= e($row['customer_name'] ?? '-') ?><small class="d-block text-muted-custom"><?= e($row['mobile'] ?? '-') ?></small></td><td><?= e($row['product_name'] ?? '-') ?></td><td><?= e(rpt_date($row['delivery_date'] ?? null)) ?></td><td><?= e($row['current_step_name'] ?? '-') ?></td><td><?= e(rpt_datetime($row['completed_at'] ?? null)) ?></td><td><?= ((int)($row['is_delayed'] ?? 0) === 1) ? '<span class="status-pill danger">Delayed</span>' : '<span class="status-pill success">On Track</span>' ?></td><td><span class="status-pill <?= e(rpt_status_class((string)($row['status_name'] ?? ''))) ?>"><?= e($row['status_name'] ?? '-') ?></span></td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mobile-report-cards" id="mobileReportCards">
                    <?php foreach ($currentRows as $row): ?>
                    <div class="mobile-report-card">
                        <?php if ($reportType === 'sales'): ?>
                        <div class="d-flex justify-content-between gap-2"><strong><?= e($row['proforma_no'] ?? '-') ?></strong><span class="status-pill <?= e(rpt_status_class((string)($row['status_name'] ?? ''))) ?>"><?= e($row['status_name'] ?? '-') ?></span></div>
                        <small class="d-block text-muted-custom fw-bold"><?= e($row['customer_name'] ?? '-') ?> · <?= e($row['mobile'] ?? '-') ?></small>
                        <div class="mt-2"><strong><?= e($row['function_name'] ?? '-') ?></strong> · <?= e(ucfirst((string)($row['order_type'] ?? '-'))) ?></div>
                        <div class="mt-2 amount green">Final: <?= e(rpt_money($row['final_amount'] ?? 0)) ?></div><div class="amount red">Balance: <?= e(rpt_money($row['balance_amount'] ?? 0)) ?></div>
                        <?php elseif ($reportType === 'payments'): ?>
                        <div class="d-flex justify-content-between gap-2"><strong><?= e($row['payment_no'] ?? '-') ?></strong><span class="status-pill <?= e(rpt_status_class((string)($row['payment_status'] ?? 'paid'))) ?>"><?= e(ucfirst((string)($row['payment_status'] ?? 'paid'))) ?></span></div>
                        <small class="d-block text-muted-custom fw-bold"><?= e($row['customer_name'] ?? '-') ?> · <?= e($row['proforma_no'] ?? '-') ?></small><div class="mt-2 amount green"><?= e(rpt_money($row['amount'] ?? 0)) ?></div><small class="text-muted-custom fw-bold"><?= e(rpt_date($row['payment_date'] ?? null)) ?> · <?= e(ucfirst((string)($row['payment_mode'] ?? '-'))) ?></small>
                        <?php else: ?>
                        <div class="d-flex justify-content-between gap-2"><strong><?= e($row['job_card_no'] ?? '-') ?></strong><span class="status-pill <?= e(rpt_status_class((string)($row['status_name'] ?? ($row['status'] ?? 'pending')))) ?>"><?= e($row['status_name'] ?? ucfirst((string)($row['status'] ?? 'pending'))) ?></span></div>
                        <small class="d-block text-muted-custom fw-bold"><?= e($row['customer_name'] ?? '-') ?> · <?= e($row['mobile'] ?? '-') ?></small><div class="mt-2"><strong><?= e($row['product_name'] ?? '-') ?></strong></div><small class="text-muted-custom fw-bold">Stage: <?= e($row['current_step_name'] ?? ($row['step_name'] ?? '-')) ?></small><small class="d-block text-muted-custom fw-bold">Delivery: <?= e(rpt_date($row['delivery_date'] ?? ($row['planned_completion_date'] ?? null))) ?></small>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$currentRows): ?><div class="alert alert-warning rounded-4 fw-bold">No records found.</div><?php endif; ?>
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
    if(window.lucide&&typeof window.lucide.createIcons==='function'){window.lucide.createIcons();}
    const search=document.getElementById('reportSearch');
    if(search){
        search.addEventListener('input',function(){
            const value=this.value.toLowerCase().trim();
            document.querySelectorAll('#reportTable tbody tr').forEach(function(row){row.style.display=row.textContent.toLowerCase().includes(value)?'':'none';});
            document.querySelectorAll('#mobileReportCards .mobile-report-card').forEach(function(card){card.style.display=card.textContent.toLowerCase().includes(value)?'':'none';});
        });
    }
    <?php if ($printMode): ?>
    window.addEventListener('load',function(){setTimeout(function(){window.print();},400);});
    <?php endif; ?>
})();
</script>
</body>
</html>
