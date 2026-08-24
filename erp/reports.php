<?php
/**
 * reports.php
 * Subhiksha Cards ERP - Professional Reports & Analytics
 *
 * Changes in this version:
 * - Professional compact report UI.
 * - Real server-side PDF download (FPDF), no browser UI print.
 * - Quick Sale wise report.
 * - Existing Sales / Payment / Job Card / Pending / Delay / Delivery logic retained.
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
    if (in_array($status, ['paid', 'completed', 'approved', 'delivered', 'dispatched'], true)) return 'success';
    if (in_array($status, ['delayed', 'rejected', 'cancelled', 'canceled'], true)) return 'danger';
    if (in_array($status, ['in_progress', 'progress', 'processing'], true)) return 'primary';
    return 'warning';
}

function rpt_pdf_text($value): string
{
    $text = trim((string)$value);
    $text = str_replace(['₹', '–', '—', '’', '“', '”'], ['Rs. ', '-', '-', "'", '"', '"'], $text);
    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
    return $converted !== false ? $converted : $text;
}

function rpt_pdf_money($value): string
{
    return 'Rs. ' . number_format((float)$value, 2);
}

function rpt_load_fpdf(): void
{
    if (class_exists('FPDF')) return;

    $candidates = [
        __DIR__ . '/assets/libs/fpdf/fpdf.php',
        __DIR__ . '/assets/libs/fpdf/FPDF.php',
        __DIR__ . '/assets/libs/fpdf/fpdf186/fpdf.php',
        __DIR__ . '/assets/libs/fpdf/fpdf184/fpdf.php',
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/libs/fpdf.php',
        __DIR__ . '/libs/fpdf/fpdf.php',
        __DIR__ . '/fpdf.php',
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            if (class_exists('FPDF')) return;
        }
    }

    throw new RuntimeException('FPDF library not found. Expected assets/libs/fpdf/fpdf.php');
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

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

$allowedReports = ['overview', 'sales', 'quick_sales', 'payments', 'job_cards', 'pending', 'delays', 'delivery'];
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

$quickStats = [
    'sale_count' => 0,
    'sale_amount' => 0.0,
    'total_qty' => 0.0,
    'cash_amount' => 0.0,
    'upi_amount' => 0.0,
    'return_amount' => 0.0,
];

if (rpt_table_exists($conn, 'proforma_bills')) {
    $stats['total_sales'] = (float)rpt_query_scalar($conn, "SELECT COALESCE(SUM(pb.final_amount),0) FROM proforma_bills pb WHERE {$whereDatePb}", 'ss', [$dateFrom, $dateTo]);
    $stats['total_advance'] = (float)rpt_query_scalar($conn, "SELECT COALESCE(SUM(pb.advance_amount),0) FROM proforma_bills pb WHERE {$whereDatePb}", 'ss', [$dateFrom, $dateTo]);
    $stats['total_balance'] = (float)rpt_query_scalar($conn, "SELECT COALESCE(SUM(pb.balance_amount),0) FROM proforma_bills pb WHERE {$whereDatePb}", 'ss', [$dateFrom, $dateTo]);
    $stats['proforma_count'] = (int)rpt_query_scalar($conn, "SELECT COUNT(*) FROM proforma_bills pb WHERE {$whereDatePb}", 'ss', [$dateFrom, $dateTo]);
}

if (rpt_table_exists($conn, 'payments')) {
    $cancelCond = rpt_col_exists($conn, 'payments', 'status')
        ? " AND LOWER(COALESCE(p.status,'')) NOT IN ('cancelled','canceled')"
        : (rpt_col_exists($conn, 'payments', 'is_cancelled') ? " AND COALESCE(p.is_cancelled,0)=0" : '');

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
$quickSaleRows = [];
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
        SELECT pb.id, pb.proforma_no, pb.customer_name, pb.mobile, pb.order_type, pb.total_qty, pb.final_amount,
               pb.advance_amount, pb.balance_amount, pb.delivery_date, pb.created_at,
               COALESCE(ft.function_name,'-') AS function_name, COALESCE(ps.status_name,'-') AS status_name
        FROM proforma_bills pb
        LEFT JOIN function_types ft ON ft.id = pb.function_type_id
        LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id
        WHERE {$whereDatePb}
        ORDER BY pb.id DESC
        LIMIT 1000
    ", 'ss', [$dateFrom, $dateTo]);
}

/* Quick Sale report */
if (rpt_table_exists($conn, 'quick_sales')) {
    $hasQuickItems = rpt_table_exists($conn, 'quick_sale_items');
    $hasQuickPayments = rpt_table_exists($conn, 'quick_sale_payments');
    $hasUsers = rpt_table_exists($conn, 'users');

    $customerNameExpr = rpt_col_exists($conn, 'quick_sales', 'customer_name') ? "COALESCE(NULLIF(qs.customer_name,''), 'Walk-in Customer')" : "'Walk-in Customer'";
    $mobileExpr = rpt_col_exists($conn, 'quick_sales', 'mobile') ? "COALESCE(qs.mobile,'')" : "''";

    $itemJoin = $hasQuickItems ? "
        LEFT JOIN (
            SELECT quick_sale_id,
                   COUNT(*) AS item_count,
                   COALESCE(SUM(qty),0) AS total_qty,
                   GROUP_CONCAT(CONCAT(product_name, ' x ', FORMAT(qty, 0)) ORDER BY id SEPARATOR ', ') AS product_names
            FROM quick_sale_items
            GROUP BY quick_sale_id
        ) qsi ON qsi.quick_sale_id = qs.id
    " : '';

    $paymentJoin = $hasQuickPayments ? "
        LEFT JOIN (
            SELECT quick_sale_id,
                   COALESCE(SUM(CASE WHEN payment_mode='cash' THEN amount ELSE 0 END),0) AS cash_amount,
                   COALESCE(SUM(CASE WHEN payment_mode='upi' THEN amount ELSE 0 END),0) AS upi_amount,
                   COALESCE(SUM(return_amount),0) AS return_amount,
                   GROUP_CONCAT(DISTINCT CASE WHEN payment_mode='upi' AND COALESCE(reference_no,'')<>'' THEN reference_no END ORDER BY id SEPARATOR ', ') AS upi_reference
            FROM quick_sale_payments
            GROUP BY quick_sale_id
        ) qsp ON qsp.quick_sale_id = qs.id
    " : '';

    $userJoin = ($hasUsers && rpt_col_exists($conn, 'quick_sales', 'created_by'))
        ? "LEFT JOIN users qu ON qu.id = qs.created_by"
        : '';

    $itemCountExpr = $hasQuickItems ? 'COALESCE(qsi.item_count,0)' : '0';
    $qtyExpr = $hasQuickItems ? 'COALESCE(qsi.total_qty,0)' : '0';
    $productsExpr = $hasQuickItems ? "COALESCE(qsi.product_names,'')" : "''";
    $cashExpr = $hasQuickPayments ? 'COALESCE(qsp.cash_amount,0)' : '0';
    $upiExpr = $hasQuickPayments ? 'COALESCE(qsp.upi_amount,0)' : '0';
    $returnExpr = $hasQuickPayments ? 'COALESCE(qsp.return_amount,0)' : '0';
    $upiRefExpr = $hasQuickPayments ? "COALESCE(qsp.upi_reference,'')" : "''";
    $saleByExpr = ($hasUsers && rpt_col_exists($conn, 'quick_sales', 'created_by'))
        ? "COALESCE(NULLIF(qu.name,''), NULLIF(qu.username,''), 'System')"
        : "'System'";

    $quickSaleRows = rpt_fetch_all($conn, "
        SELECT qs.id, qs.sale_no, {$customerNameExpr} AS customer_name, {$mobileExpr} AS mobile,
               qs.total_amount, qs.created_at,
               {$itemCountExpr} AS item_count, {$qtyExpr} AS total_qty, {$productsExpr} AS product_names,
               {$cashExpr} AS cash_amount, {$upiExpr} AS upi_amount, {$returnExpr} AS return_amount,
               {$upiRefExpr} AS upi_reference, {$saleByExpr} AS sale_by,
               CASE
                   WHEN {$cashExpr} > 0 AND {$upiExpr} > 0 THEN 'Split'
                   WHEN {$cashExpr} > 0 THEN 'Cash'
                   WHEN {$upiExpr} > 0 THEN 'UPI'
                   ELSE 'Unpaid'
               END AS payment_mode_label
        FROM quick_sales qs
        {$itemJoin}
        {$paymentJoin}
        {$userJoin}
        WHERE DATE(qs.created_at) BETWEEN ? AND ?
        ORDER BY qs.id DESC
        LIMIT 1000
    ", 'ss', [$dateFrom, $dateTo]);

    foreach ($quickSaleRows as $qr) {
        $quickStats['sale_count']++;
        $quickStats['sale_amount'] += (float)($qr['total_amount'] ?? 0);
        $quickStats['total_qty'] += (float)($qr['total_qty'] ?? 0);
        $quickStats['cash_amount'] += (float)($qr['cash_amount'] ?? 0);
        $quickStats['upi_amount'] += (float)($qr['upi_amount'] ?? 0);
        $quickStats['return_amount'] += (float)($qr['return_amount'] ?? 0);
    }
}

if (rpt_table_exists($conn, 'payments')) {
    if (rpt_col_exists($conn, 'payments', 'status')) {
        $cancelSelect = "COALESCE(p.status,'paid')";
    } elseif (rpt_col_exists($conn, 'payments', 'is_cancelled')) {
        $cancelSelect = "CASE WHEN COALESCE(p.is_cancelled,0)=1 THEN 'cancelled' ELSE 'paid' END";
    } else {
        $cancelSelect = "'paid'";
    }

    $paymentRows = rpt_fetch_all($conn, "
        SELECT p.id, p.payment_no, p.payment_type, p.payment_mode, p.amount, p.payment_date,
               p.reference_no, p.remarks, {$cancelSelect} AS payment_status, pb.proforma_no,
               COALESCE(pb.customer_name, c.customer_name, '-') AS customer_name,
               COALESCE(pb.mobile, c.mobile, '-') AS mobile,
               COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), '-') AS received_by_name
        FROM payments p
        LEFT JOIN proforma_bills pb ON pb.id = p.proforma_bill_id
        LEFT JOIN customers c ON c.id = p.customer_id
        LEFT JOIN users u ON u.id = p.received_by
        WHERE {$whereDatePay}
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT 1000
    ", 'ss', [$dateFrom, $dateTo]);
}

if (rpt_table_exists($conn, 'job_cards')) {
    $jobRows = rpt_fetch_all($conn, "
        SELECT jc.id, jc.job_card_no, jc.customer_name, jc.mobile, jc.order_type, jc.product_name,
               jc.final_amount, jc.advance_amount, jc.balance_amount, jc.delivery_date, jc.created_at,
               jc.completed_at, jc.is_delayed, COALESCE(jcs.status_name,'-') AS status_name,
               COALESCE(ws.step_name,'-') AS current_step_name, COALESCE(ft.function_name,'-') AS function_name
        FROM job_cards jc
        LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
        LEFT JOIN workflow_steps ws ON ws.id = jc.current_workflow_step_id
        LEFT JOIN function_types ft ON ft.id = jc.function_type_id
        WHERE {$whereDateJc}
        ORDER BY jc.id DESC
        LIMIT 1000
    ", 'ss', [$dateFrom, $dateTo]);

    $pendingRows = rpt_fetch_all($conn, "
        SELECT jc.id, jc.job_card_no, jc.customer_name, jc.mobile, jc.product_name, jc.delivery_date,
               jc.created_at, COALESCE(jcs.status_name,'Pending') AS status_name,
               COALESCE(ws.step_name,'-') AS current_step_name
        FROM job_cards jc
        LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
        LEFT JOIN workflow_steps ws ON ws.id = jc.current_workflow_step_id
        WHERE DATE(jc.created_at) BETWEEN ? AND ?
          AND jc.completed_at IS NULL
        ORDER BY jc.delivery_date ASC, jc.id DESC
        LIMIT 1000
    ", 'ss', [$dateFrom, $dateTo]);

    $deliveryRows = rpt_fetch_all($conn, "
        SELECT jc.id, jc.job_card_no, jc.customer_name, jc.mobile, jc.product_name, jc.delivery_date,
               jc.completed_at, jc.is_delayed, COALESCE(jcs.status_name,'-') AS status_name,
               COALESCE(ws.step_name,'-') AS current_step_name
        FROM job_cards jc
        LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
        LEFT JOIN workflow_steps ws ON ws.id = jc.current_workflow_step_id
        WHERE jc.delivery_date BETWEEN ? AND ?
        ORDER BY jc.delivery_date ASC, jc.id DESC
        LIMIT 1000
    ", 'ss', [$dateFrom, $dateTo]);
}

if (rpt_table_exists($conn, 'job_tracking')) {
    $delayRows = rpt_fetch_all($conn, "
        SELECT jt.id, jt.job_card_id, jt.status, jt.planned_completion_date, jt.revised_completion_date,
               jt.actual_completed_at, jt.delay_days, jt.delay_remarks, jc.job_card_no, jc.customer_name,
               jc.mobile, jc.product_name, ws.step_name, dr.reason_name
        FROM job_tracking jt
        LEFT JOIN job_cards jc ON jc.id = jt.job_card_id
        LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
        LEFT JOIN delay_reasons dr ON dr.id = jt.delay_reason_id
        WHERE (jt.is_delayed = 1 OR jt.status = 'delayed')
          AND DATE(COALESCE(jt.delay_started_at, jt.updated_at, jt.created_at)) BETWEEN ? AND ?
        ORDER BY COALESCE(jt.delay_started_at, jt.updated_at, jt.created_at) DESC, jt.id DESC
        LIMIT 1000
    ", 'ss', [$dateFrom, $dateTo]);
}

$currentRows = [];
$exportTitle = 'Report';
if ($reportType === 'sales') { $currentRows = $salesRows; $exportTitle = 'Sales Report'; }
elseif ($reportType === 'quick_sales') { $currentRows = $quickSaleRows; $exportTitle = 'Quick Sale Report'; }
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

if ($export === 'pdf' && $reportType !== 'overview') {
    try {
        rpt_load_fpdf();

        class SubhikshaReportsPDF extends FPDF
        {
            public string $reportTitle = 'Report';
            public string $periodText = '';

            public function Header()
            {
                $this->SetFillColor(15, 23, 42);
                $this->Rect(0, 0, $this->GetPageWidth(), 22, 'F');
                $this->SetTextColor(255, 255, 255);
                $this->SetFont('Arial', 'B', 14);
                $this->SetXY(10, 6);
                $this->Cell(145, 6, 'SUBHIKSHA CARDS', 0, 0, 'L');
                $this->SetFont('Arial', 'B', 12);
                $this->Cell(0, 6, rpt_pdf_text($this->reportTitle), 0, 1, 'R');
                $this->SetFont('Arial', '', 8);
                $this->SetXY(10, 14);
                $this->Cell(0, 4, rpt_pdf_text($this->periodText), 0, 0, 'R');
                $this->SetY(28);
            }

            public function Footer()
            {
                $this->SetY(-10);
                $this->SetDrawColor(226, 232, 240);
                $this->Line(10, $this->GetY() - 2, $this->GetPageWidth() - 10, $this->GetY() - 2);
                $this->SetFont('Arial', '', 7);
                $this->SetTextColor(100, 116, 139);
                $this->Cell(0, 4, 'Generated from Subhiksha Cards ERP  |  Page ' . $this->PageNo(), 0, 0, 'C');
            }
        }

        $pdf = new SubhikshaReportsPDF('L', 'mm', 'A4');
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->reportTitle = $exportTitle;
        $pdf->periodText = rpt_date($dateFrom) . ' to ' . rpt_date($dateTo);
        $pdf->AddPage();

        // Summary strip
        $summary = [];
        if ($reportType === 'quick_sales') {
            $summary = [
                ['Quick Sales', number_format($quickStats['sale_count'])],
                ['Sale Amount', rpt_pdf_money($quickStats['sale_amount'])],
                ['Cash', rpt_pdf_money($quickStats['cash_amount'])],
                ['UPI', rpt_pdf_money($quickStats['upi_amount'])],
            ];
        } elseif ($reportType === 'payments') {
            $summary = [
                ['Transactions', number_format(count($paymentRows))],
                ['Collected', rpt_pdf_money($stats['payment_collected'])],
                ['Pending Balance', rpt_pdf_money($stats['total_balance'])],
                ['Proformas', number_format($stats['proforma_count'])],
            ];
        } elseif (in_array($reportType, ['job_cards','pending','delays','delivery'], true)) {
            $summary = [
                ['Job Cards', number_format($stats['job_count'])],
                ['Completed', number_format($stats['completed_jobs'])],
                ['Pending', number_format($stats['pending_jobs'])],
                ['Delayed', number_format($stats['delayed_jobs'])],
            ];
        } else {
            $summary = [
                ['Proformas', number_format($stats['proforma_count'])],
                ['Sales', rpt_pdf_money($stats['total_sales'])],
                ['Collected', rpt_pdf_money($stats['payment_collected'])],
                ['Balance', rpt_pdf_money($stats['total_balance'])],
            ];
        }

        $boxW = 66.5;
        foreach ($summary as $i => [$label, $value]) {
            $x = 10 + ($i * 69);
            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->Rect($x, 29, $boxW, 17, 'DF');
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetFont('Arial', 'B', 6.5);
            $pdf->SetXY($x + 3, 32);
            $pdf->Cell($boxW - 6, 3.5, strtoupper(rpt_pdf_text($label)), 0, 1, 'L');
            $pdf->SetTextColor(15, 23, 42);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->SetX($x + 3);
            $pdf->Cell($boxW - 6, 6, rpt_pdf_text($value), 0, 0, 'L');
        }
        $pdf->SetY(52);

        $columns = [];
        $pdfRows = [];

        if ($reportType === 'sales') {
            $columns = [
                ['Proforma', 30], ['Customer', 37], ['Function', 28], ['Order', 20], ['Qty', 14],
                ['Final', 29], ['Advance', 29], ['Balance', 29], ['Delivery', 23], ['Status', 25]
            ];
            foreach ($salesRows as $r) {
                $pdfRows[] = [
                    $r['proforma_no'] ?? '-', $r['customer_name'] ?? '-', $r['function_name'] ?? '-', ucfirst((string)($r['order_type'] ?? '-')),
                    number_format((float)($r['total_qty'] ?? 0), 0), rpt_pdf_money($r['final_amount'] ?? 0), rpt_pdf_money($r['advance_amount'] ?? 0),
                    rpt_pdf_money($r['balance_amount'] ?? 0), rpt_date($r['delivery_date'] ?? null), $r['status_name'] ?? '-'
                ];
            }
        } elseif ($reportType === 'quick_sales') {
            $columns = [
                ['Quick Sale', 31], ['Customer', 38], ['Products', 70], ['Qty', 15], ['Total', 28],
                ['Payment', 20], ['Cash', 27], ['UPI', 27], ['Sale By', 25], ['Date', 24]
            ];
            foreach ($quickSaleRows as $r) {
                $pdfRows[] = [
                    $r['sale_no'] ?? '-', $r['customer_name'] ?? '-', $r['product_names'] ?? '-',
                    number_format((float)($r['total_qty'] ?? 0), 0), rpt_pdf_money($r['total_amount'] ?? 0),
                    $r['payment_mode_label'] ?? '-', rpt_pdf_money($r['cash_amount'] ?? 0), rpt_pdf_money($r['upi_amount'] ?? 0),
                    $r['sale_by'] ?? '-', rpt_date($r['created_at'] ?? null)
                ];
            }
        } elseif ($reportType === 'payments') {
            $columns = [
                ['Payment No', 34], ['Customer', 38], ['Proforma', 34], ['Type', 23], ['Mode', 20],
                ['Amount', 30], ['Date', 24], ['Reference', 35], ['Received By', 28], ['Status', 23]
            ];
            foreach ($paymentRows as $r) {
                $pdfRows[] = [
                    $r['payment_no'] ?? '-', $r['customer_name'] ?? '-', $r['proforma_no'] ?? '-', ucfirst((string)($r['payment_type'] ?? '-')),
                    ucfirst((string)($r['payment_mode'] ?? '-')), rpt_pdf_money($r['amount'] ?? 0), rpt_date($r['payment_date'] ?? null),
                    $r['reference_no'] ?? '-', $r['received_by_name'] ?? '-', ucfirst((string)($r['payment_status'] ?? 'paid'))
                ];
            }
        } elseif ($reportType === 'job_cards') {
            $columns = [
                ['Job Card', 34], ['Customer', 36], ['Product', 42], ['Function', 28], ['Order', 22],
                ['Stage', 42], ['Delivery', 24], ['Amount', 29], ['Status', 28]
            ];
            foreach ($jobRows as $r) {
                $pdfRows[] = [
                    $r['job_card_no'] ?? '-', $r['customer_name'] ?? '-', $r['product_name'] ?? '-', $r['function_name'] ?? '-',
                    ucfirst((string)($r['order_type'] ?? '-')), $r['current_step_name'] ?? '-', rpt_date($r['delivery_date'] ?? null),
                    rpt_pdf_money($r['final_amount'] ?? 0), $r['status_name'] ?? '-'
                ];
            }
        } elseif ($reportType === 'pending') {
            $columns = [
                ['Job Card', 38], ['Customer', 45], ['Product', 55], ['Current Stage', 55], ['Delivery', 30], ['Created', 35], ['Status', 30]
            ];
            foreach ($pendingRows as $r) {
                $pdfRows[] = [
                    $r['job_card_no'] ?? '-', $r['customer_name'] ?? '-', $r['product_name'] ?? '-', $r['current_step_name'] ?? '-',
                    rpt_date($r['delivery_date'] ?? null), rpt_date($r['created_at'] ?? null), $r['status_name'] ?? 'Pending'
                ];
            }
        } elseif ($reportType === 'delays') {
            $columns = [
                ['Job Card', 35], ['Customer', 37], ['Product', 43], ['Stage', 43], ['Planned', 25], ['Days', 16], ['Reason', 35], ['Remarks', 45], ['Status', 22]
            ];
            foreach ($delayRows as $r) {
                $pdfRows[] = [
                    $r['job_card_no'] ?? '-', $r['customer_name'] ?? '-', $r['product_name'] ?? '-', $r['step_name'] ?? '-',
                    rpt_date($r['planned_completion_date'] ?? null), number_format((int)($r['delay_days'] ?? 0)), $r['reason_name'] ?? '-',
                    $r['delay_remarks'] ?? '-', ucfirst((string)($r['status'] ?? 'delayed'))
                ];
            }
        } elseif ($reportType === 'delivery') {
            $columns = [
                ['Job Card', 38], ['Customer', 43], ['Product', 52], ['Delivery', 28], ['Current Stage', 55], ['Completed', 30], ['Delay', 24], ['Status', 30]
            ];
            foreach ($deliveryRows as $r) {
                $pdfRows[] = [
                    $r['job_card_no'] ?? '-', $r['customer_name'] ?? '-', $r['product_name'] ?? '-', rpt_date($r['delivery_date'] ?? null),
                    $r['current_step_name'] ?? '-', rpt_date($r['completed_at'] ?? null), ((int)($r['is_delayed'] ?? 0) === 1 ? 'Delayed' : 'On Track'),
                    $r['status_name'] ?? '-'
                ];
            }
        }

        $usable = $pdf->GetPageWidth() - 20;
        $totalBaseWidth = array_sum(array_column($columns, 1));
        $scale = $totalBaseWidth > 0 ? ($usable / $totalBaseWidth) : 1;
        $widths = array_map(static function ($c) use ($scale) { return $c[1] * $scale; }, $columns);

        $drawHeader = static function () use ($pdf, $columns, $widths) {
            $pdf->SetFillColor(30, 41, 59);
            $pdf->SetDrawColor(71, 85, 105);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 6.8);
            foreach ($columns as $i => $c) {
                $pdf->Cell($widths[$i], 7, rpt_pdf_text($c[0]), 1, 0, 'C', true);
            }
            $pdf->Ln();
        };

        $drawHeader();
        $pdf->SetFont('Arial', '', 6.6);

        if (!$pdfRows) {
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell($usable, 10, 'No records found for the selected period.', 1, 1, 'C');
        } else {
            foreach ($pdfRows as $rowIndex => $r) {
                if ($pdf->GetY() > 190) {
                    $pdf->AddPage();
                    $drawHeader();
                    $pdf->SetFont('Arial', '', 6.6);
                }

                $pdf->SetFillColor($rowIndex % 2 === 0 ? 255 : 248, $rowIndex % 2 === 0 ? 255 : 250, $rowIndex % 2 === 0 ? 255 : 252);
                $pdf->SetDrawColor(226, 232, 240);
                $pdf->SetTextColor(31, 41, 55);

                foreach ($r as $i => $value) {
                    $text = rpt_pdf_text($value);
                    $maxChars = max(7, (int)floor($widths[$i] * 2.1));
                    if (strlen($text) > $maxChars) {
                        $text = substr($text, 0, $maxChars - 3) . '...';
                    }
                    $align = (stripos($columns[$i][0], 'Amount') !== false || in_array($columns[$i][0], ['Final','Advance','Balance','Total','Cash','UPI','Qty','Days'], true)) ? 'R' : 'L';
                    $pdf->Cell($widths[$i], 6.5, $text, 1, 0, $align, true);
                }
                $pdf->Ln();
            }
        }

        $filename = strtolower(str_replace(' ', '_', $exportTitle)) . '_' . date('Ymd_His') . '.pdf';
        $pdf->Output('D', $filename);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unable to generate PDF: ' . $e->getMessage();
        exit;
    }
}

$queryBase = http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo]);

$reportMeta = [
    'overview'    => ['label' => 'Overview',     'icon' => 'layout-dashboard'],
    'sales'       => ['label' => 'Sales',        'icon' => 'receipt-indian-rupee'],
    'quick_sales' => ['label' => 'Quick Sales',  'icon' => 'shopping-cart'],
    'payments'    => ['label' => 'Payments',     'icon' => 'wallet-cards'],
    'job_cards'   => ['label' => 'Job Cards',    'icon' => 'briefcase-business'],
    'pending'     => ['label' => 'Pending Jobs', 'icon' => 'hourglass'],
    'delays'      => ['label' => 'Delays',       'icon' => 'clock-alert'],
    'delivery'    => ['label' => 'Delivery',     'icon' => 'truck'],
];

if ($reportType === 'quick_sales') {
    $metricCards = [
        ['label' => 'Quick Sale Amount', 'value' => rpt_money($quickStats['sale_amount']), 'sub' => number_format($quickStats['sale_count']) . ' invoice(s)', 'icon' => 'shopping-bag', 'tone' => 'blue'],
        ['label' => 'Total Quantity', 'value' => number_format($quickStats['total_qty'], 0), 'sub' => 'Items sold', 'icon' => 'boxes', 'tone' => 'purple'],
        ['label' => 'Cash Collected', 'value' => rpt_money($quickStats['cash_amount']), 'sub' => 'Quick Sale cash', 'icon' => 'banknote', 'tone' => 'green'],
        ['label' => 'UPI Collected', 'value' => rpt_money($quickStats['upi_amount']), 'sub' => 'Quick Sale UPI', 'icon' => 'scan-line', 'tone' => 'orange'],
    ];
} elseif ($reportType === 'payments') {
    $metricCards = [
        ['label' => 'Payment Collected', 'value' => rpt_money($stats['payment_collected']), 'sub' => number_format($stats['payment_count']) . ' transaction(s)', 'icon' => 'wallet', 'tone' => 'green'],
        ['label' => 'Pending Balance', 'value' => rpt_money($stats['total_balance']), 'sub' => 'Outstanding amount', 'icon' => 'badge-alert', 'tone' => 'red'],
        ['label' => 'Proforma Sales', 'value' => rpt_money($stats['total_sales']), 'sub' => number_format($stats['proforma_count']) . ' proforma(s)', 'icon' => 'receipt-text', 'tone' => 'blue'],
        ['label' => 'Average Payment', 'value' => rpt_money($stats['payment_count'] > 0 ? $stats['payment_collected'] / $stats['payment_count'] : 0), 'sub' => 'Per transaction', 'icon' => 'calculator', 'tone' => 'purple'],
    ];
} elseif (in_array($reportType, ['job_cards','pending','delays','delivery'], true)) {
    $metricCards = [
        ['label' => 'Job Cards', 'value' => number_format($stats['job_count']), 'sub' => 'Created in period', 'icon' => 'briefcase-business', 'tone' => 'blue'],
        ['label' => 'Completed', 'value' => number_format($stats['completed_jobs']), 'sub' => 'Completed jobs', 'icon' => 'circle-check-big', 'tone' => 'green'],
        ['label' => 'Pending', 'value' => number_format($stats['pending_jobs']), 'sub' => 'Open jobs', 'icon' => 'loader-circle', 'tone' => 'orange'],
        ['label' => 'Delayed', 'value' => number_format($stats['delayed_jobs']), 'sub' => 'Needs attention', 'icon' => 'clock-alert', 'tone' => 'red'],
    ];
} else {
    $metricCards = [
        ['label' => 'Proforma Sales', 'value' => rpt_money($stats['total_sales']), 'sub' => number_format($stats['proforma_count']) . ' proforma(s)', 'icon' => 'receipt-indian-rupee', 'tone' => 'blue'],
        ['label' => 'Payment Collected', 'value' => rpt_money($stats['payment_collected']), 'sub' => number_format($stats['payment_count']) . ' payment(s)', 'icon' => 'wallet', 'tone' => 'green'],
        ['label' => 'Balance Pending', 'value' => rpt_money($stats['total_balance']), 'sub' => 'Amount to collect', 'icon' => 'badge-alert', 'tone' => 'red'],
        ['label' => 'Quick Sale', 'value' => rpt_money($quickStats['sale_amount']), 'sub' => number_format($quickStats['sale_count']) . ' quick sale(s)', 'icon' => 'shopping-cart', 'tone' => 'purple'],
    ];
}
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
    .reports-page{--rpt-blue:#2563eb;--rpt-green:#16a34a;--rpt-orange:#ea580c;--rpt-red:#dc2626;--rpt-purple:#7c3aed}
    .reports-page .report-hero{padding:18px 20px;margin-bottom:12px;border:1px solid var(--border-soft);border-radius:16px;background:var(--card-bg);display:flex;justify-content:space-between;align-items:center;gap:14px}
    .report-hero-left{display:flex;align-items:center;gap:12px;min-width:0}.report-hero-icon{width:40px;height:40px;border-radius:12px;background:color-mix(in srgb,var(--brand-1) 12%,var(--card-bg));color:var(--brand-1);display:grid;place-items:center;flex:0 0 auto}.report-hero-icon svg{width:20px;height:20px}.report-hero h1{font-size:22px;line-height:1.15;font-weight:800;color:var(--text-main);margin:0}.report-hero p{font-size:11.5px;font-weight:500;color:var(--text-muted);margin:3px 0 0}.report-period{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:var(--text-muted);white-space:nowrap}.report-period svg{width:14px;height:14px}
    .report-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.report-actions .btn{font-size:11px;font-weight:700;padding:7px 12px;border-radius:10px}
    .report-filter{padding:13px 14px;margin-bottom:10px;border-radius:14px}.report-filter .form-label{font-size:10.5px;font-weight:700;margin-bottom:4px;color:var(--text-muted)}.report-filter .form-control,.report-filter .form-select{min-height:36px;font-size:12px;border-radius:9px;padding:6px 9px}.report-filter .btn{min-height:36px;font-size:11px;font-weight:700;border-radius:9px;padding:6px 12px}
    .report-nav{display:flex;gap:6px;overflow-x:auto;padding:2px 1px 8px;margin-bottom:6px;scrollbar-width:thin}.report-nav-link{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border:1px solid var(--border-soft);border-radius:10px;background:var(--card-bg);color:var(--text-muted);font-size:10.5px;font-weight:700;text-decoration:none;white-space:nowrap;transition:.15s}.report-nav-link svg{width:14px;height:14px}.report-nav-link:hover{color:var(--text-main);border-color:color-mix(in srgb,var(--brand-1) 30%,var(--border-soft));transform:translateY(-1px)}.report-nav-link.active{background:var(--text-main);border-color:var(--text-main);color:var(--card-bg)}
    .metric-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}.metric-card{border:1px solid var(--border-soft);border-radius:14px;padding:12px 13px;background:var(--card-bg);display:flex;align-items:center;gap:10px;min-height:78px}.metric-icon{width:36px;height:36px;border-radius:11px;display:grid;place-items:center;flex:0 0 auto}.metric-icon svg{width:17px;height:17px}.metric-icon.blue{background:#dbeafe;color:#1d4ed8}.metric-icon.green{background:#dcfce7;color:#15803d}.metric-icon.red{background:#fee2e2;color:#b91c1c}.metric-icon.orange{background:#ffedd5;color:#c2410c}.metric-icon.purple{background:#ede9fe;color:#6d28d9}.metric-label{display:block;font-size:9.5px;line-height:1.2;font-weight:700;text-transform:uppercase;letter-spacing:.035em;color:var(--text-muted)}.metric-value{display:block;font-size:17px;line-height:1.15;font-weight:800;color:var(--text-main);margin-top:2px}.metric-sub{display:block;font-size:9.5px;font-weight:500;color:var(--text-muted);margin-top:2px}
    .overview-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,.8fr);gap:10px}.report-panel{padding:15px;border-radius:14px}.report-panel-title{font-size:13px;font-weight:800;color:var(--text-main);margin:0}.report-panel-sub{font-size:10.5px;color:var(--text-muted);margin-top:2px}.panel-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px}.panel-head-icon{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;background:color-mix(in srgb,var(--brand-1) 10%,var(--card-bg));color:var(--brand-1)}.panel-head-icon svg{width:15px;height:15px}
    .trend-list{display:grid;gap:7px}.trend-row{display:grid;grid-template-columns:78px 1fr 92px;gap:9px;align-items:center;font-size:10.5px}.trend-date{font-weight:700;color:var(--text-main)}.trend-track{height:7px;border-radius:99px;background:color-mix(in srgb,var(--border-soft) 70%,transparent);overflow:hidden}.trend-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#2563eb,#14b8a6)}.trend-value{text-align:right;font-weight:800;color:var(--text-main)}
    .mix-list{display:grid;gap:8px}.mix-row{padding:9px 10px;border:1px solid var(--border-soft);border-radius:10px;background:color-mix(in srgb,var(--card-bg) 97%,var(--body-bg))}.mix-row-top{display:flex;justify-content:space-between;gap:10px;align-items:center}.mix-name{font-size:10.5px;font-weight:700;color:var(--text-main)}.mix-value{font-size:10.5px;font-weight:800;color:var(--text-main)}.mix-meta{font-size:9.5px;color:var(--text-muted);margin-top:2px}.mix-track{height:4px;border-radius:99px;background:var(--border-soft);overflow:hidden;margin-top:6px}.mix-fill{height:100%;background:var(--brand-1);border-radius:99px}
    .ops-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.ops-card{border:1px solid var(--border-soft);border-radius:11px;padding:11px;background:color-mix(in srgb,var(--card-bg) 97%,var(--body-bg))}.ops-card span{display:block;font-size:9px;text-transform:uppercase;font-weight:700;color:var(--text-muted)}.ops-card strong{display:block;font-size:18px;font-weight:800;color:var(--text-main);margin-top:3px}.ops-card small{font-size:9.5px;color:var(--text-muted)}
    .table-panel{padding:0;border-radius:14px;overflow:hidden}.table-panel-head{padding:13px 14px;border-bottom:1px solid var(--border-soft);display:flex;justify-content:space-between;align-items:center;gap:12px}.table-panel-head h2{font-size:13px;font-weight:800;color:var(--text-main);margin:0}.table-panel-head p{font-size:10px;color:var(--text-muted);margin:2px 0 0}.report-record-badge{display:inline-flex;align-items:center;padding:4px 7px;border-radius:999px;background:color-mix(in srgb,var(--brand-1) 9%,var(--card-bg));color:var(--brand-1);font-size:9.5px;font-weight:700}.report-search{max-width:270px;min-height:34px!important;font-size:11px!important;border-radius:9px!important}
    .report-table-wrap{overflow:auto;max-height:62vh}.report-table{width:100%;border-collapse:separate;border-spacing:0}.report-table th{position:sticky;top:0;z-index:2;background:var(--table-header-bg);color:var(--table-header-text);font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.035em;white-space:nowrap;padding:8px 9px;border-bottom:1px solid var(--border-soft)}.report-table td{font-size:10.5px;font-weight:500;color:var(--text-main);padding:8px 9px;border-bottom:1px solid color-mix(in srgb,var(--border-soft) 75%,transparent);vertical-align:middle}.report-table tr:hover td{background:var(--table-row-hover)}.report-table strong{font-weight:750}.cell-sub{display:block;font-size:9px;color:var(--text-muted);font-weight:500;margin-top:2px}.money-positive{font-weight:800;color:#15803d}.money-negative{font-weight:800;color:#b91c1c}.status-pill{display:inline-flex;align-items:center;padding:4px 7px;border-radius:99px;font-size:9px;font-weight:700;white-space:nowrap}.status-pill.success{background:#dcfce7;color:#166534}.status-pill.primary{background:#dbeafe;color:#1d4ed8}.status-pill.warning{background:#fef3c7;color:#92400e}.status-pill.danger{background:#fee2e2;color:#991b1b}.empty-report{padding:32px;text-align:center;color:var(--text-muted);font-size:11px;font-weight:600}
    .mobile-report-list{display:none}.mobile-report-item{border-bottom:1px solid var(--border-soft);padding:12px 14px}.mobile-report-item:last-child{border-bottom:0}.mobile-row-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.mobile-title{font-size:11.5px;font-weight:800;color:var(--text-main)}.mobile-meta{font-size:9.5px;color:var(--text-muted);margin-top:3px}.mobile-details{display:grid;grid-template-columns:1fr 1fr;gap:6px 10px;margin-top:9px;font-size:10px}.mobile-details span{color:var(--text-muted)}.mobile-details strong{display:block;color:var(--text-main);font-weight:700;margin-top:1px}
    @media(max-width:1199.98px){.metric-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.overview-grid{grid-template-columns:1fr}}
    @media(max-width:767.98px){.reports-page .report-hero{padding:14px;align-items:flex-start;flex-direction:column}.report-hero h1{font-size:19px}.report-actions{width:100%;justify-content:flex-start}.report-filter{padding:11px}.metric-grid{grid-template-columns:1fr 1fr;gap:8px}.metric-card{padding:10px;min-height:70px}.metric-value{font-size:15px}.metric-icon{width:32px;height:32px}.report-panel{padding:12px}.trend-row{grid-template-columns:68px 1fr 78px}.ops-grid{grid-template-columns:1fr}.table-panel-head{align-items:flex-start;flex-direction:column}.report-search{max-width:none;width:100%}.report-table-wrap{display:none}.mobile-report-list{display:block}.mobile-details{grid-template-columns:1fr}.report-period{white-space:normal}.report-actions .btn{flex:1 1 auto}}
    @media(max-width:420px){.metric-grid{grid-template-columns:1fr}.report-nav-link{padding:6px 9px}}
    </style>
</head>
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main id="main">
        <?php include __DIR__ . '/includes/nav.php'; ?>
        <section class="page-section reports-page">
            <div class="report-hero">
                <div class="report-hero-left">
                    <div class="report-hero-icon"><i data-lucide="chart-no-axes-combined"></i></div>
                    <div>
                        <h1>Reports & Analytics</h1>
                        <p>Sales, Quick Sales, collections, production and delivery performance.</p>
                    </div>
                </div>
                <div class="d-flex flex-column align-items-lg-end gap-2">
                    <div class="report-period"><i data-lucide="calendar-range"></i><?= e(rpt_date($dateFrom)) ?> — <?= e(rpt_date($dateTo)) ?></div>
                    <?php if ($reportType !== 'overview'): ?>
                    <div class="report-actions">
                        <a href="reports.php?<?= e($queryBase . '&report=' . urlencode($reportType) . '&export=csv') ?>" class="btn btn-outline-success"><i data-lucide="sheet" class="me-1"></i>CSV / Excel</a>
                        <a href="reports.php?<?= e($queryBase . '&report=' . urlencode($reportType) . '&export=pdf') ?>" class="btn btn-danger"><i data-lucide="file-down" class="me-1"></i>Download PDF</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-ui report-filter">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-6 col-lg-2">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
                    </div>
                    <div class="col-12 col-lg-3">
                        <label class="form-label">Report Type</label>
                        <select name="report" class="form-select">
                            <?php foreach ($reportMeta as $key => $meta): ?>
                            <option value="<?= e($key) ?>" <?= $reportType === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2 d-grid"><button type="submit" class="btn btn-primary"><i data-lucide="filter" class="me-1"></i>Apply Filters</button></div>
                    <div class="col-6 col-lg-2 d-grid"><a href="reports.php" class="btn btn-outline-secondary"><i data-lucide="rotate-ccw" class="me-1"></i>Reset</a></div>
                </form>
            </div>

            <div class="report-nav">
                <?php foreach ($reportMeta as $key => $meta): ?>
                <a href="reports.php?<?= e($queryBase . '&report=' . $key) ?>" class="report-nav-link <?= $reportType === $key ? 'active' : '' ?>">
                    <i data-lucide="<?= e($meta['icon']) ?>"></i><?= e($meta['label']) ?>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="metric-grid">
                <?php foreach ($metricCards as $metric): ?>
                <div class="metric-card">
                    <div class="metric-icon <?= e($metric['tone']) ?>"><i data-lucide="<?= e($metric['icon']) ?>"></i></div>
                    <div class="min-w-0">
                        <span class="metric-label"><?= e($metric['label']) ?></span>
                        <span class="metric-value"><?= e($metric['value']) ?></span>
                        <span class="metric-sub"><?= e($metric['sub']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($reportType === 'overview'): ?>
            <div class="overview-grid">
                <div class="card-ui report-panel">
                    <div class="panel-head">
                        <div><h2 class="report-panel-title">Sales Trend</h2><div class="report-panel-sub">Daily Proforma sales for the selected period.</div></div>
                        <div class="panel-head-icon"><i data-lucide="trending-up"></i></div>
                    </div>
                    <?php if (!$dailySalesRows): ?>
                        <div class="empty-report">No Proforma sales found for the selected dates.</div>
                    <?php else: ?>
                        <div class="trend-list">
                            <?php $maxSales = max(1, ...array_map(static function($r){ return (float)$r['total_amount']; }, $dailySalesRows)); ?>
                            <?php foreach ($dailySalesRows as $row): $pct = min(100, round(((float)$row['total_amount'] / $maxSales) * 100)); ?>
                            <div class="trend-row">
                                <span class="trend-date"><?= e(rpt_date($row['report_date'])) ?></span>
                                <div class="trend-track"><div class="trend-fill" style="width:<?= (int)$pct ?>%"></div></div>
                                <span class="trend-value"><?= e(rpt_money($row['total_amount'])) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card-ui report-panel">
                    <div class="panel-head">
                        <div><h2 class="report-panel-title">Function-wise Sales</h2><div class="report-panel-sub">Top business categories by Proforma value.</div></div>
                        <div class="panel-head-icon"><i data-lucide="chart-bar-big"></i></div>
                    </div>
                    <?php if (!$functionRows): ?>
                        <div class="empty-report">No function-wise sales data found.</div>
                    <?php else: ?>
                        <div class="mix-list">
                            <?php $maxFn = max(1, ...array_map(static function($r){ return (float)$r['total_amount']; }, $functionRows)); ?>
                            <?php foreach ($functionRows as $row): $pct = min(100, round(((float)$row['total_amount'] / $maxFn) * 100)); ?>
                            <div class="mix-row">
                                <div class="mix-row-top"><span class="mix-name"><?= e($row['function_name']) ?></span><span class="mix-value"><?= e(rpt_money($row['total_amount'])) ?></span></div>
                                <div class="mix-meta"><?= number_format((int)$row['total_orders']) ?> order(s)</div>
                                <div class="mix-track"><div class="mix-fill" style="width:<?= (int)$pct ?>%"></div></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card-ui report-panel" style="grid-column:1/-1">
                    <div class="panel-head">
                        <div><h2 class="report-panel-title">Operations Snapshot</h2><div class="report-panel-sub">Job Card status for the selected creation period.</div></div>
                        <div class="panel-head-icon"><i data-lucide="activity"></i></div>
                    </div>
                    <div class="ops-grid">
                        <div class="ops-card"><span>Completed Jobs</span><strong><?= number_format($stats['completed_jobs']) ?></strong><small>Closed production work</small></div>
                        <div class="ops-card"><span>Open / Pending</span><strong><?= number_format($stats['pending_jobs']) ?></strong><small>Still in workflow</small></div>
                        <div class="ops-card"><span>Delayed Jobs</span><strong><?= number_format($stats['delayed_jobs']) ?></strong><small>Requires attention</small></div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card-ui table-panel">
                <div class="table-panel-head">
                    <div>
                        <div class="d-flex align-items-center gap-2"><h2><?= e($exportTitle) ?></h2><span class="report-record-badge"><?= number_format(count($currentRows)) ?> records</span></div>
                        <p><?= e(rpt_date($dateFrom)) ?> to <?= e(rpt_date($dateTo)) ?></p>
                    </div>
                    <input type="search" id="reportSearch" class="form-control report-search" placeholder="Search this report...">
                </div>

                <div class="report-table-wrap">
                    <table class="report-table" id="reportTable">
                        <thead>
                        <?php if ($reportType === 'sales'): ?>
                        <tr><th>Proforma</th><th>Customer</th><th>Function</th><th>Order</th><th>Qty</th><th>Final</th><th>Advance</th><th>Balance</th><th>Delivery</th><th>Status</th></tr>
                        <?php elseif ($reportType === 'quick_sales'): ?>
                        <tr><th>Quick Sale</th><th>Customer</th><th>Products</th><th>Qty</th><th>Total</th><th>Payment</th><th>Cash</th><th>UPI</th><th>Sale By</th><th>Date</th></tr>
                        <?php elseif ($reportType === 'payments'): ?>
                        <tr><th>Payment No</th><th>Customer</th><th>Proforma</th><th>Type</th><th>Mode</th><th>Amount</th><th>Date</th><th>Reference</th><th>Received By</th><th>Status</th></tr>
                        <?php elseif ($reportType === 'job_cards'): ?>
                        <tr><th>Job Card</th><th>Customer</th><th>Product</th><th>Function</th><th>Order</th><th>Stage</th><th>Delivery</th><th>Amount</th><th>Status</th></tr>
                        <?php elseif ($reportType === 'pending'): ?>
                        <tr><th>Job Card</th><th>Customer</th><th>Product</th><th>Current Stage</th><th>Delivery</th><th>Created</th><th>Status</th></tr>
                        <?php elseif ($reportType === 'delays'): ?>
                        <tr><th>Job Card</th><th>Customer</th><th>Product</th><th>Stage</th><th>Planned</th><th>Days</th><th>Reason</th><th>Remarks</th><th>Status</th></tr>
                        <?php elseif ($reportType === 'delivery'): ?>
                        <tr><th>Job Card</th><th>Customer</th><th>Product</th><th>Delivery</th><th>Current Stage</th><th>Completed</th><th>Delay</th><th>Status</th></tr>
                        <?php endif; ?>
                        </thead>
                        <tbody>
                        <?php if (!$currentRows): ?>
                        <tr><td colspan="10" class="empty-report">No records found for the selected filters.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($currentRows as $row): ?>
                            <?php if ($reportType === 'sales'): ?>
                            <tr>
                                <td><strong><?= e($row['proforma_no'] ?? '-') ?></strong><span class="cell-sub"><?= e(rpt_datetime($row['created_at'] ?? null)) ?></span></td>
                                <td><?= e($row['customer_name'] ?? '-') ?><span class="cell-sub"><?= e($row['mobile'] ?? '-') ?></span></td>
                                <td><?= e($row['function_name'] ?? '-') ?></td><td><?= e(ucfirst((string)($row['order_type'] ?? '-'))) ?></td><td><?= number_format((float)($row['total_qty'] ?? 0),0) ?></td>
                                <td class="money-positive"><?= e(rpt_money($row['final_amount'] ?? 0)) ?></td><td><?= e(rpt_money($row['advance_amount'] ?? 0)) ?></td><td class="money-negative"><?= e(rpt_money($row['balance_amount'] ?? 0)) ?></td><td><?= e(rpt_date($row['delivery_date'] ?? null)) ?></td><td><span class="status-pill <?= e(rpt_status_class((string)($row['status_name'] ?? ''))) ?>"><?= e($row['status_name'] ?? '-') ?></span></td>
                            </tr>
                            <?php elseif ($reportType === 'quick_sales'): ?>
                            <tr>
                                <td><strong><?= e($row['sale_no'] ?? '-') ?></strong><span class="cell-sub">ID: <?= (int)($row['id'] ?? 0) ?></span></td>
                                <td><?= e($row['customer_name'] ?? 'Walk-in Customer') ?><span class="cell-sub"><?= e($row['mobile'] ?? '-') ?></span></td>
                                <td><?= e($row['product_names'] ?: '-') ?><span class="cell-sub"><?= number_format((int)($row['item_count'] ?? 0)) ?> line item(s)</span></td>
                                <td><?= number_format((float)($row['total_qty'] ?? 0),0) ?></td><td class="money-positive"><?= e(rpt_money($row['total_amount'] ?? 0)) ?></td><td><span class="status-pill <?= strtolower((string)($row['payment_mode_label'] ?? '')) === 'unpaid' ? 'warning' : 'primary' ?>"><?= e($row['payment_mode_label'] ?? '-') ?></span></td>
                                <td><?= e(rpt_money($row['cash_amount'] ?? 0)) ?></td><td><?= e(rpt_money($row['upi_amount'] ?? 0)) ?><span class="cell-sub"><?= e($row['upi_reference'] ?? '') ?></span></td><td><?= e($row['sale_by'] ?? 'System') ?></td><td><?= e(rpt_datetime($row['created_at'] ?? null)) ?></td>
                            </tr>
                            <?php elseif ($reportType === 'payments'): ?>
                            <tr>
                                <td><strong><?= e($row['payment_no'] ?? '-') ?></strong></td><td><?= e($row['customer_name'] ?? '-') ?><span class="cell-sub"><?= e($row['mobile'] ?? '-') ?></span></td><td><?= e($row['proforma_no'] ?? '-') ?></td><td><?= e(ucfirst((string)($row['payment_type'] ?? '-'))) ?></td><td><?= e(ucfirst((string)($row['payment_mode'] ?? '-'))) ?></td><td class="money-positive"><?= e(rpt_money($row['amount'] ?? 0)) ?></td><td><?= e(rpt_date($row['payment_date'] ?? null)) ?></td><td><?= e($row['reference_no'] ?? '-') ?></td><td><?= e($row['received_by_name'] ?? '-') ?></td><td><span class="status-pill <?= e(rpt_status_class((string)($row['payment_status'] ?? 'paid'))) ?>"><?= e(ucfirst((string)($row['payment_status'] ?? 'paid'))) ?></span></td>
                            </tr>
                            <?php elseif ($reportType === 'job_cards'): ?>
                            <tr>
                                <td><strong><?= e($row['job_card_no'] ?? '-') ?></strong><span class="cell-sub"><?= e(rpt_datetime($row['created_at'] ?? null)) ?></span></td><td><?= e($row['customer_name'] ?? '-') ?><span class="cell-sub"><?= e($row['mobile'] ?? '-') ?></span></td><td><?= e($row['product_name'] ?? '-') ?></td><td><?= e($row['function_name'] ?? '-') ?></td><td><?= e(ucfirst((string)($row['order_type'] ?? '-'))) ?></td><td><?= e($row['current_step_name'] ?? '-') ?></td><td><?= e(rpt_date($row['delivery_date'] ?? null)) ?></td><td class="money-positive"><?= e(rpt_money($row['final_amount'] ?? 0)) ?></td><td><span class="status-pill <?= e(rpt_status_class((string)($row['status_name'] ?? ''))) ?>"><?= e($row['status_name'] ?? '-') ?></span></td>
                            </tr>
                            <?php elseif ($reportType === 'pending'): ?>
                            <tr>
                                <td><strong><?= e($row['job_card_no'] ?? '-') ?></strong></td><td><?= e($row['customer_name'] ?? '-') ?><span class="cell-sub"><?= e($row['mobile'] ?? '-') ?></span></td><td><?= e($row['product_name'] ?? '-') ?></td><td><?= e($row['current_step_name'] ?? '-') ?></td><td><?= e(rpt_date($row['delivery_date'] ?? null)) ?></td><td><?= e(rpt_datetime($row['created_at'] ?? null)) ?></td><td><span class="status-pill warning"><?= e($row['status_name'] ?? 'Pending') ?></span></td>
                            </tr>
                            <?php elseif ($reportType === 'delays'): ?>
                            <tr>
                                <td><strong><?= e($row['job_card_no'] ?? '-') ?></strong></td><td><?= e($row['customer_name'] ?? '-') ?><span class="cell-sub"><?= e($row['mobile'] ?? '-') ?></span></td><td><?= e($row['product_name'] ?? '-') ?></td><td><?= e($row['step_name'] ?? '-') ?></td><td><?= e(rpt_date($row['planned_completion_date'] ?? null)) ?></td><td class="money-negative"><?= number_format((int)($row['delay_days'] ?? 0)) ?></td><td><?= e($row['reason_name'] ?? '-') ?></td><td><?= e($row['delay_remarks'] ?? '-') ?></td><td><span class="status-pill danger"><?= e(ucfirst((string)($row['status'] ?? 'delayed'))) ?></span></td>
                            </tr>
                            <?php elseif ($reportType === 'delivery'): ?>
                            <tr>
                                <td><strong><?= e($row['job_card_no'] ?? '-') ?></strong></td><td><?= e($row['customer_name'] ?? '-') ?><span class="cell-sub"><?= e($row['mobile'] ?? '-') ?></span></td><td><?= e($row['product_name'] ?? '-') ?></td><td><?= e(rpt_date($row['delivery_date'] ?? null)) ?></td><td><?= e($row['current_step_name'] ?? '-') ?></td><td><?= e(rpt_datetime($row['completed_at'] ?? null)) ?></td><td><?= ((int)($row['is_delayed'] ?? 0) === 1) ? '<span class="status-pill danger">Delayed</span>' : '<span class="status-pill success">On Track</span>' ?></td><td><span class="status-pill <?= e(rpt_status_class((string)($row['status_name'] ?? ''))) ?>"><?= e($row['status_name'] ?? '-') ?></span></td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mobile-report-list" id="mobileReportCards">
                    <?php foreach ($currentRows as $row): ?>
                    <div class="mobile-report-item">
                        <?php if ($reportType === 'sales'): ?>
                            <div class="mobile-row-head"><div><div class="mobile-title"><?= e($row['proforma_no'] ?? '-') ?></div><div class="mobile-meta"><?= e($row['customer_name'] ?? '-') ?> · <?= e($row['mobile'] ?? '-') ?></div></div><span class="status-pill <?= e(rpt_status_class((string)($row['status_name'] ?? ''))) ?>"><?= e($row['status_name'] ?? '-') ?></span></div>
                            <div class="mobile-details"><div><span>Final</span><strong><?= e(rpt_money($row['final_amount'] ?? 0)) ?></strong></div><div><span>Balance</span><strong><?= e(rpt_money($row['balance_amount'] ?? 0)) ?></strong></div><div><span>Function</span><strong><?= e($row['function_name'] ?? '-') ?></strong></div><div><span>Delivery</span><strong><?= e(rpt_date($row['delivery_date'] ?? null)) ?></strong></div></div>
                        <?php elseif ($reportType === 'quick_sales'): ?>
                            <div class="mobile-row-head"><div><div class="mobile-title"><?= e($row['sale_no'] ?? '-') ?></div><div class="mobile-meta"><?= e($row['customer_name'] ?? '-') ?> · <?= e($row['mobile'] ?? '-') ?></div></div><span class="status-pill primary"><?= e($row['payment_mode_label'] ?? '-') ?></span></div>
                            <div class="mobile-details"><div><span>Products</span><strong><?= e($row['product_names'] ?: '-') ?></strong></div><div><span>Total</span><strong><?= e(rpt_money($row['total_amount'] ?? 0)) ?></strong></div><div><span>Cash / UPI</span><strong><?= e(rpt_money($row['cash_amount'] ?? 0)) ?> / <?= e(rpt_money($row['upi_amount'] ?? 0)) ?></strong></div><div><span>Sale By</span><strong><?= e($row['sale_by'] ?? 'System') ?></strong></div></div>
                        <?php elseif ($reportType === 'payments'): ?>
                            <div class="mobile-row-head"><div><div class="mobile-title"><?= e($row['payment_no'] ?? '-') ?></div><div class="mobile-meta"><?= e($row['customer_name'] ?? '-') ?> · <?= e($row['proforma_no'] ?? '-') ?></div></div><span class="status-pill <?= e(rpt_status_class((string)($row['payment_status'] ?? 'paid'))) ?>"><?= e(ucfirst((string)($row['payment_status'] ?? 'paid'))) ?></span></div>
                            <div class="mobile-details"><div><span>Amount</span><strong><?= e(rpt_money($row['amount'] ?? 0)) ?></strong></div><div><span>Mode</span><strong><?= e(ucfirst((string)($row['payment_mode'] ?? '-'))) ?></strong></div><div><span>Date</span><strong><?= e(rpt_date($row['payment_date'] ?? null)) ?></strong></div><div><span>Received By</span><strong><?= e($row['received_by_name'] ?? '-') ?></strong></div></div>
                        <?php else: ?>
                            <div class="mobile-row-head"><div><div class="mobile-title"><?= e($row['job_card_no'] ?? '-') ?></div><div class="mobile-meta"><?= e($row['customer_name'] ?? '-') ?> · <?= e($row['mobile'] ?? '-') ?></div></div><span class="status-pill <?= e(rpt_status_class((string)($row['status_name'] ?? ($row['status'] ?? 'pending')))) ?>"><?= e($row['status_name'] ?? ucfirst((string)($row['status'] ?? 'pending'))) ?></span></div>
                            <div class="mobile-details"><div><span>Product</span><strong><?= e($row['product_name'] ?? '-') ?></strong></div><div><span>Stage</span><strong><?= e($row['current_step_name'] ?? ($row['step_name'] ?? '-')) ?></strong></div><div><span>Delivery / Planned</span><strong><?= e(rpt_date($row['delivery_date'] ?? ($row['planned_completion_date'] ?? null))) ?></strong></div></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$currentRows): ?><div class="empty-report">No records found for the selected filters.</div><?php endif; ?>
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
    if(window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    const search = document.getElementById('reportSearch');
    if(search){
        search.addEventListener('input', function(){
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('#reportTable tbody tr').forEach(function(row){
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
            document.querySelectorAll('#mobileReportCards .mobile-report-item').forEach(function(card){
                card.style.display = card.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }
})();
</script>
</body>
</html>
