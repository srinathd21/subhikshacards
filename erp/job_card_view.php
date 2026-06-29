<?php
/**
 * job_card_view.php
 * Subhiksha Cards ERP - Full Job Card Details + Role Based Tracking Update.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (function_exists('require_permission')) {
    require_permission($conn, 'can_view', 'job_cards.php');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['job_cards_csrf'])) {
    $_SESSION['job_cards_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['job_cards_csrf'];

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function jcv_table_exists(mysqli $conn, string $table): bool
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

function jcv_col(mysqli $conn, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (isset($cache[$key])) return $cache[$key];

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

function jcv_bind_type($v): string
{
    return is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
}

function jcv_update(mysqli $conn, string $table, array $data, int $id): void
{
    $filtered = [];
    foreach ($data as $key => $value) {
        if (jcv_col($conn, $table, $key)) $filtered[$key] = $value;
    }
    if (!$filtered) return;

    $sets = [];
    $types = '';
    $values = [];
    foreach ($filtered as $key => $value) {
        $sets[] = "`{$key}`=?";
        $types .= jcv_bind_type($value);
        $values[] = $value;
    }
    $types .= 'i';
    $values[] = $id;

    $stmt = $conn->prepare("UPDATE {$table} SET " . implode(',', $sets) . " WHERE id=?");
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
}

function jcv_money($value): string { return '₹' . number_format((float)$value, 2); }
function jcv_date($value): string { return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-'; }
function jcv_datetime($value): string { return !empty($value) ? date('d-m-Y h:i A', strtotime((string)$value)) : '-'; }
function jcv_yes_no($value): string { return ((int)$value === 1) ? 'Yes' : 'No'; }
function jcv_status_label($status): string { $status = trim((string)$status); return $status === '' ? 'Pending' : ucwords(str_replace('_', ' ', $status)); }

function jcv_delay_days_from_planned($plannedDate): int
{
    if (empty($plannedDate)) return 0;
    try {
        $planned = new DateTime(date('Y-m-d', strtotime((string)$plannedDate)));
        $today = new DateTime(date('Y-m-d'));
        if ($today <= $planned) return 0;
        return (int)$planned->diff($today)->days;
    } catch (Throwable $e) {
        return 0;
    }
}

function jcv_next_expected_date($plannedDate): ?string
{
    if (empty($plannedDate)) return null;
    try {
        $dt = new DateTime(date('Y-m-d', strtotime((string)$plannedDate)));
        $dt->modify('+1 day');
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function jcv_default_delay_reason_id(mysqli $conn): ?int
{
    if (!jcv_table_exists($conn, 'delay_reasons')) return null;
    try {
        if (jcv_col($conn, 'delay_reasons', 'reason_key')) {
            $key = 'other';
            $stmt = $conn->prepare("SELECT id FROM delay_reasons WHERE reason_key = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) return (int)$row['id'];
        }
        $stmt = $conn->prepare("SELECT id FROM delay_reasons WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function jcv_history_insert(mysqli $conn, int $trackingId, int $jobCardId, int $workflowStepId, string $oldStatus, string $newStatus, string $remarks): void
{
    if (!jcv_table_exists($conn, 'job_tracking_history')) return;
    try {
        $changedBy = 0;
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            INSERT INTO job_tracking_history
                (job_tracking_id, job_card_id, workflow_step_id, old_status, new_status, action_remarks, changed_by, changed_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iiisssis', $trackingId, $jobCardId, $workflowStepId, $oldStatus, $newStatus, $remarks, $changedBy, $now);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {}
}

function jcv_auto_mark_overdue_tracking(mysqli $conn, int $jobCardId): void
{
    if ($jobCardId <= 0 || !jcv_table_exists($conn, 'job_tracking')) return;

    $today = date('Y-m-d');
    $defaultReasonId = jcv_default_delay_reason_id($conn);

    try {
        $stmt = $conn->prepare("
            SELECT *
            FROM job_tracking
            WHERE job_card_id = ?
              AND status NOT IN ('completed','cancelled','skipped')
              AND planned_completion_date IS NOT NULL
              AND planned_completion_date < ?
        ");
        $stmt->bind_param('is', $jobCardId, $today);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $trackingId = (int)$row['id'];
            $plannedDate = (string)($row['planned_completion_date'] ?? '');
            $nextExpectedDate = !empty($row['revised_completion_date']) ? (string)$row['revised_completion_date'] : jcv_next_expected_date($plannedDate);
            $delayDays = jcv_delay_days_from_planned($plannedDate);
            $oldStatus = (string)($row['status'] ?? 'pending');

            $data = [
                'status' => 'delayed',
                'is_delayed' => 1,
                'delay_days' => max(1, $delayDays),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            if (empty($row['delay_started_at'])) $data['delay_started_at'] = date('Y-m-d H:i:s');
            if (empty($row['revised_completion_date'])) $data['revised_completion_date'] = $nextExpectedDate;
            if (empty($row['delay_reason_id']) && $defaultReasonId) $data['delay_reason_id'] = $defaultReasonId;
            if (trim((string)($row['delay_remarks'] ?? '')) === '') $data['delay_remarks'] = 'Auto marked delayed because planned date was missed.';

            jcv_update($conn, 'job_tracking', $data, $trackingId);
            jcv_history_insert($conn, $trackingId, (int)$row['job_card_id'], (int)$row['workflow_step_id'], $oldStatus, 'delayed', 'Auto marked delayed. Original planned date: ' . $plannedDate . '. Next expected date: ' . ($nextExpectedDate ?: '-') . '.');
        }
        $stmt->close();
    } catch (Throwable $e) {}
}

function jcv_progress_percent(array $tracking): int
{
    $total = count($tracking);
    if ($total <= 0) return 0;
    $completed = 0;
    foreach ($tracking as $row) {
        if ((string)($row['status'] ?? '') === 'completed') $completed++;
    }
    return (int)round(($completed / $total) * 100);
}

function jcv_progress_counts(array $tracking): array
{
    $counts = ['total'=>count($tracking), 'completed'=>0, 'in_progress'=>0, 'pending'=>0, 'delayed'=>0, 'other'=>0];
    foreach ($tracking as $row) {
        $status = (string)($row['status'] ?? 'pending');
        if (isset($counts[$status])) $counts[$status]++; else $counts['other']++;
        if ((int)($row['is_delayed'] ?? 0) === 1) $counts['delayed']++;
    }
    return $counts;
}

function jcv_current_role_ids(mysqli $conn): array
{
    $ids = [];
    foreach (['role_id', 'current_role_id'] as $key) {
        if (!empty($_SESSION[$key])) $ids[] = (int)$_SESSION[$key];
    }
    if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        foreach ($_SESSION['roles'] as $role) {
            if (is_array($role) && !empty($role['id'])) $ids[] = (int)$role['id'];
            elseif (is_numeric($role)) $ids[] = (int)$role;
        }
    }
    return array_values(array_unique(array_filter($ids)));
}

function jcv_current_role_keys(mysqli $conn): array
{
    $keys = [];
    foreach (['role_key', 'current_role_key'] as $key) {
        if (!empty($_SESSION[$key])) $keys[] = strtolower((string)$_SESSION[$key]);
    }
    $roleIds = jcv_current_role_ids($conn);
    if ($roleIds && jcv_table_exists($conn, 'roles')) {
        try {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $types = str_repeat('i', count($roleIds));
            $stmt = $conn->prepare("SELECT role_key FROM roles WHERE id IN ({$placeholders})");
            $stmt->bind_param($types, ...$roleIds);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $keys[] = strtolower((string)$row['role_key']);
            $stmt->close();
        } catch (Throwable $e) {}
    }
    return array_values(array_unique(array_filter($keys)));
}

function jcv_is_admin_user(mysqli $conn): bool
{
    if (function_exists('is_super_admin') && is_super_admin()) return true;
    if (!empty($_SESSION['is_super_admin'])) return true;
    foreach (jcv_current_role_keys($conn) as $key) {
        if (in_array($key, ['super_admin', 'admin', 'business_admin'], true)) return true;
    }
    return false;
}

function jcv_can_update_tracking(mysqli $conn, array $trackingRow): bool
{
    if (jcv_is_admin_user($conn)) return true;
    $responsibleRoleId = (int)($trackingRow['responsible_role_id'] ?? 0);
    return $responsibleRoleId > 0 && in_array($responsibleRoleId, jcv_current_role_ids($conn), true);
}

$id = (int)($_GET['id'] ?? 0);
$job = null;
$item = null;
$tracking = [];
$delayReasons = [];
$error = '';

if ($id <= 0) {
    $error = 'Invalid Job Card.';
} else {
    jcv_auto_mark_overdue_tracking($conn, $id);

    try {
        $stmt = $conn->prepare("
            SELECT
                jc.*,
                jcs.status_name AS job_status_name,
                ws.step_name AS current_step_name,
                pt.printing_name,
                pst.sub_type_name,
                ft.function_name,
                pb.proforma_no,
                q.quotation_no,
                e.enquiry_no,
                cb.name AS created_by_name,
                cr.role_name AS created_by_department,
                apr.role_name AS assigned_printing_department,
                au.name AS assigned_printing_user_name,
                du.name AS assigned_design_user_name,
                su.name AS assigned_sales_user_name
            FROM job_cards jc
            LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
            LEFT JOIN workflow_steps ws ON ws.id = jc.current_workflow_step_id
            LEFT JOIN printing_types pt ON pt.id = jc.printing_type_id
            LEFT JOIN printing_sub_types pst ON pst.id = jc.printing_sub_type_id
            LEFT JOIN function_types ft ON ft.id = jc.function_type_id
            LEFT JOIN proforma_bills pb ON pb.id = jc.proforma_bill_id
            LEFT JOIN quotations q ON q.id = jc.quotation_id
            LEFT JOIN enquiries e ON e.id = jc.enquiry_id
            LEFT JOIN users cb ON cb.id = jc.created_by
            LEFT JOIN roles cr ON cr.id = cb.role_id
            LEFT JOIN roles apr ON apr.id = jc.assigned_printing_role_id
            LEFT JOIN users au ON au.id = jc.assigned_printing_user_id
            LEFT JOIN users du ON du.id = jc.assigned_design_user_id
            LEFT JOIN users su ON su.id = jc.assigned_sales_user_id
            WHERE jc.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$job) $error = 'Job Card not found.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($job) {
    try {
        $stmt = $conn->prepare("
            SELECT *
            FROM job_card_items
            WHERE job_card_id = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {}

    try {
        $historyJoin = '';
        $historySelect = "NULL AS updated_by_name, NULL AS updated_by_department, NULL AS last_updated_at, NULL AS last_action_remarks";
        if (jcv_table_exists($conn, 'job_tracking_history')) {
            $historyJoin = "
                LEFT JOIN (
                    SELECT h1.*
                    FROM job_tracking_history h1
                    INNER JOIN (
                        SELECT job_tracking_id, MAX(id) AS max_id
                        FROM job_tracking_history
                        WHERE COALESCE(action_remarks, '') NOT LIKE '%job card creation%'
                          AND COALESCE(action_remarks, '') NOT LIKE 'Pending after%'
                        GROUP BY job_tracking_id
                    ) hx ON hx.max_id = h1.id
                ) jth ON jth.job_tracking_id = jt.id
                LEFT JOIN users hu ON hu.id = jth.changed_by
                LEFT JOIN roles hur ON hur.id = hu.role_id
            ";
            $historySelect = "
                CASE WHEN jth.id IS NOT NULL THEN hu.name WHEN jt.completed_by IS NOT NULL THEN cu.name ELSE NULL END AS updated_by_name,
                CASE WHEN jth.id IS NOT NULL THEN hur.role_name WHEN jt.completed_by IS NOT NULL THEN cur.role_name ELSE NULL END AS updated_by_department,
                CASE WHEN jth.id IS NOT NULL THEN jth.changed_at WHEN jt.completed_by IS NOT NULL THEN jt.actual_completed_at ELSE NULL END AS last_updated_at,
                CASE WHEN jth.id IS NOT NULL THEN jth.action_remarks ELSE NULL END AS last_action_remarks
            ";
        } else {
            $historySelect = "
                CASE WHEN jt.completed_by IS NOT NULL THEN cu.name ELSE NULL END AS updated_by_name,
                CASE WHEN jt.completed_by IS NOT NULL THEN cur.role_name ELSE NULL END AS updated_by_department,
                CASE WHEN jt.completed_by IS NOT NULL THEN jt.actual_completed_at ELSE NULL END AS last_updated_at,
                NULL AS last_action_remarks
            ";
        }

        $stmt = $conn->prepare("
            SELECT jt.*, ws.step_name, ws.step_key, ws.sort_order, r.role_name, dr.reason_name AS delay_reason_name,
                   {$historySelect}
            FROM job_tracking jt
            LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
            LEFT JOIN roles r ON r.id = jt.responsible_role_id
            LEFT JOIN delay_reasons dr ON dr.id = jt.delay_reason_id
            LEFT JOIN users cu ON cu.id = jt.completed_by
            LEFT JOIN roles cur ON cur.id = cu.role_id
            {$historyJoin}
            WHERE jt.job_card_id = ?
            ORDER BY ws.sort_order ASC, jt.id ASC
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $tracking[] = $row;
        $stmt->close();
    } catch (Throwable $e) {}

    if (jcv_table_exists($conn, 'delay_reasons')) {
        try {
            $res = $conn->query("SELECT id, reason_name FROM delay_reasons WHERE is_active = 1 ORDER BY id ASC");
            while ($row = $res->fetch_assoc()) $delayReasons[] = $row;
            $res->free();
        } catch (Throwable $e) {}
    }
}

$progressPercent = jcv_progress_percent($tracking);
$progressCounts = jcv_progress_counts($tracking);
$pageTitle = $job ? 'Job Card - ' . ($job['job_card_no'] ?? '') : 'Job Card';
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
        .job-view .page-head{padding:24px 28px;margin-bottom:18px}
        .job-view .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}
        .view-card{padding:24px;border-radius:20px;margin-bottom:18px}
        .view-section-title{font-size:17px;font-weight:900;color:var(--text-main);margin-bottom:14px}
        .info-box{border:1px solid var(--border-soft);border-radius:16px;padding:14px;background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));height:100%}
        .info-box small{display:block;font-size:11px;text-transform:uppercase;color:var(--text-muted);font-weight:900;margin-bottom:5px}
        .info-box strong{display:block;font-size:15px;color:var(--text-main);font-weight:900;word-break:break-word}
        .status-chip{display:inline-flex;align-items:center;border-radius:999px;padding:6px 12px;font-weight:900;font-size:12px;background:#dbeafe;color:#1d4ed8}
        .progress-panel{border:1px solid var(--border-soft);border-radius:20px;padding:18px;background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg))}
        .progress-main-value{font-size:38px;font-weight:900;color:var(--text-main);line-height:1}
        .progress-track{height:14px;border-radius:999px;background:color-mix(in srgb,var(--text-muted) 16%,transparent);overflow:hidden}
        .progress-track span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#2563eb,#22c55e)}
        .progress-stat{border:1px solid var(--border-soft);border-radius:16px;padding:12px;background:var(--card-bg);height:100%}
        .progress-stat small{display:block;font-size:10px;font-weight:900;text-transform:uppercase;color:var(--text-muted)}
        .progress-stat strong{display:block;font-size:22px;font-weight:900;color:var(--text-main)}
        .tracking-status-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:900;background:#e5e7eb;color:#374151}
        .tracking-status-badge.completed{background:#dcfce7;color:#166534}
        .tracking-status-badge.in_progress{background:#dbeafe;color:#1d4ed8}
        .tracking-status-badge.pending{background:#fef3c7;color:#92400e}
        .tracking-status-badge.delayed{background:#fee2e2;color:#991b1b}
        .planned-date-old{text-decoration:line-through;color:#991b1b;font-weight:900}
        .planned-date-new{color:#166534;font-weight:900;margin-left:7px}
        .delay-info{font-size:11px;font-weight:800;color:#991b1b}
        .delay-meta{font-size:11px;font-weight:800;color:var(--text-muted)}
        .delay-required-box{display:none;margin-top:8px}
        .tracking-update-form.delay-visible .delay-required-box{display:block}
        .role-lock-text{font-size:11px;font-weight:900;color:var(--text-muted)}
        .toast-ui{border:0;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.18);overflow:hidden;min-width:320px;max-width:420px}
        .toast-ui.success{background:#dcfce7;color:#14532d}.toast-ui.danger{background:#fee2e2;color:#7f1d1d}
        .toast-title{font-size:14px;font-weight:900}.toast-message{font-size:13px;font-weight:800;line-height:1.45}
        @media print{#sidebar,#mobileOverlay,#settingsOverlay,.no-print,nav,.app-shell>aside{display:none!important}main{margin:0!important}.view-card,.page-head{box-shadow:none!important;border:1px solid #ddd!important}}
    </style>
</head>
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main id="main">
        <?php include __DIR__ . '/includes/nav.php'; ?>

        <section class="page-section job-view">
            <div class="card-ui page-head">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div>
                        <h1 class="mb-1">Job Card Details</h1>
                        <p class="text-muted-custom mb-0">Complete job card, assignment, planned dates, delay status and role based progress update.</p>
                    </div>
                    <div class="d-flex gap-2 no-print">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" onclick="window.print()">Print</button>
                        <a href="job_cards.php" class="btn btn-primary rounded-pill px-4 fw-bold">Back to Job Cards</a>
                    </div>
                </div>
            </div>

            <?php if ($error !== ''): ?>
            <div class="card-ui view-card"><div class="alert alert-danger mb-0"><?= e($error) ?></div></div>
            <?php endif; ?>

            <?php if ($job): ?>
            <div class="card-ui view-card">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                    <div>
                        <div class="view-section-title mb-1"><?= e($job['job_card_no'] ?? '-') ?></div>
                        <div class="text-muted-custom">
                            Enquiry: <?= e($job['enquiry_no'] ?? '-') ?> |
                            Quotation: <?= e($job['quotation_no'] ?? '-') ?> |
                            Proforma: <?= e($job['proforma_no'] ?? '-') ?>
                        </div>
                    </div>
                    <span class="status-chip"><?= e($job['job_status_name'] ?? '-') ?></span>
                </div>

                <div class="row g-3">
                    <div class="col-md-3"><div class="info-box"><small>Customer</small><strong><?= e($job['customer_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Mobile</small><strong><?= e($job['mobile'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Function</small><strong><?= e($job['function_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Order Type</small><strong><?= e(ucfirst((string)($job['order_type'] ?? '-'))) ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Product</small><strong><?= e($job['product_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Printing Type</small><strong><?= e($job['printing_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Screen Sub Type</small><strong><?= e($job['sub_type_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Delivery Date</small><strong><?= e(jcv_date($job['delivery_date'] ?? '')) ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Advance</small><strong><?= e(jcv_money($job['advance_amount'] ?? 0)) ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Balance</small><strong><?= e(jcv_money($job['balance_amount'] ?? 0)) ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Assigned Department</small><strong><?= e($job['assigned_printing_department'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Current Stage</small><strong><?= e($job['current_step_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Created By</small><strong><?= e($job['created_by_name'] ?? '-') ?></strong><small><?= e($job['created_by_department'] ?? '') ?></small></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Created Date/Time</small><strong><?= e(jcv_datetime($job['created_at'] ?? '')) ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Delay Status</small><strong><?= ((int)($job['is_delayed'] ?? 0) === 1 || ($progressCounts['delayed'] ?? 0) > 0) ? 'Delayed' : 'On Track' ?></strong></div></div>
                </div>
            </div>

            <div class="card-ui view-card">
                <div class="view-section-title">Product / Requirement Details</div>
                <div class="row g-3">
                    <div class="col-md-3"><div class="info-box"><small>Item Name</small><strong><?= e($item['item_name'] ?? $job['product_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Qty</small><strong><?= e(number_format((float)($item['qty'] ?? 0), 2)) ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Rate</small><strong><?= e(jcv_money($item['rate'] ?? 0)) ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Amount</small><strong><?= e(jcv_money($item['amount'] ?? 0)) ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Size</small><strong><?= e($item['size_text'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>GSM Thickness</small><strong><?= e($item['gsm_thickness'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Lamination Status</small><strong><?= e(jcv_yes_no($item['lamination_required'] ?? 0)) ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Lamination Type</small><strong><?= e($item['lamination_type'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Printing Side</small><strong><?= e($item['printing_side'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Screening Type</small><strong><?= e($item['screening_type'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Finishing Details</small><strong><?= e(jcv_yes_no($item['finishing_required'] ?? 0)) ?></strong></div></div>
                    <div class="col-12"><div class="info-box"><small>Description / Notes</small><strong><?= e($item['description'] ?? $job['notes'] ?? '-') ?></strong></div></div>
                </div>
            </div>

            <div class="card-ui view-card">
                <div class="view-section-title">Job Progress Status</div>
                <div class="progress-panel mb-3">
                    <div class="row g-3 align-items-center">
                        <div class="col-lg-3">
                            <small class="text-muted-custom fw-bold text-uppercase">Overall Progress</small>
                            <div class="progress-main-value"><?= e($progressPercent) ?>%</div>
                        </div>
                        <div class="col-lg-9">
                            <div class="d-flex justify-content-between gap-2 mb-2">
                                <strong><?= e($job['current_step_name'] ?? '-') ?></strong>
                                <span class="status-chip"><?= e($job['job_status_name'] ?? '-') ?></span>
                            </div>
                            <div class="progress-track"><span style="width:<?= e($progressPercent) ?>%"></span></div>
                        </div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-6 col-md-2"><div class="progress-stat"><small>Total</small><strong><?= e($progressCounts['total']) ?></strong></div></div>
                    <div class="col-6 col-md-2"><div class="progress-stat"><small>Completed</small><strong><?= e($progressCounts['completed']) ?></strong></div></div>
                    <div class="col-6 col-md-2"><div class="progress-stat"><small>In Progress</small><strong><?= e($progressCounts['in_progress']) ?></strong></div></div>
                    <div class="col-6 col-md-2"><div class="progress-stat"><small>Pending</small><strong><?= e($progressCounts['pending']) ?></strong></div></div>
                    <div class="col-6 col-md-2"><div class="progress-stat"><small>Delayed</small><strong><?= e($progressCounts['delayed']) ?></strong></div></div>
                    <div class="col-6 col-md-2"><div class="progress-stat"><small>Other</small><strong><?= e($progressCounts['other']) ?></strong></div></div>
                </div>
            </div>

            <div class="card-ui view-card">
                <div class="view-section-title">Planned Tracking Dates / Role Based Status Update</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Stage</th>
                                <th>Status</th>
                                <th>Responsible</th>
                                <th>Updated By</th>
                                <th>Planned / Revised</th>
                                <th>Delay Details</th>
                                <th class="no-print">Update</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$tracking): ?>
                            <tr><td colspan="8" class="text-center text-muted-custom py-4">No tracking steps found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($tracking as $trk): ?>
                            <?php
                                $trkStatus = trim((string)($trk['status'] ?? 'pending'));
                                if ($trkStatus === '') $trkStatus = 'pending';
                                $delayDays = jcv_delay_days_from_planned($trk['planned_completion_date'] ?? null);
                                $isDelayed = (int)($trk['is_delayed'] ?? 0) === 1 || $delayDays > 0 || $trkStatus === 'delayed';
                                $needsDelayReason = $delayDays > 0 && empty($trk['delay_reason_id']) && trim((string)($trk['delay_remarks'] ?? '')) === '';
                                $badgeClass = $isDelayed ? 'delayed' : $trkStatus;
                            ?>
                            <tr>
                                <td><?= e($trk['sort_order'] ?? '-') ?></td>
                                <td><strong><?= e($trk['step_name'] ?? '-') ?></strong><small class="d-block text-muted-custom"><?= e($trk['step_key'] ?? '') ?></small></td>
                                <td>
                                    <span class="tracking-status-badge <?= e($badgeClass) ?>"><?= e($isDelayed ? 'Delayed' : jcv_status_label($trkStatus)) ?></span>
                                    <?php if ($isDelayed): ?><small class="d-block delay-info"><?= e($trk['delay_reason_name'] ?? 'Delay reason pending') ?></small><?php endif; ?>
                                </td>
                                <td><strong><?= e($trk['role_name'] ?? '-') ?></strong></td>
                                <td>
                                    <strong><?= e($trk['updated_by_name'] ?? '-') ?></strong>
                                    <small class="d-block text-muted-custom"><?= e($trk['updated_by_department'] ?? '-') ?></small>
                                    <small class="d-block text-muted-custom"><?= e(jcv_datetime($trk['last_updated_at'] ?? '')) ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($trk['revised_completion_date']) && !empty($trk['planned_completion_date']) && $trk['revised_completion_date'] !== $trk['planned_completion_date']): ?>
                                        Planned:
                                        <span class="planned-date-old"><?= e(jcv_date($trk['planned_completion_date'])) ?></span>
                                        <span class="planned-date-new"><?= e(jcv_date($trk['revised_completion_date'])) ?></span>
                                    <?php else: ?>
                                        <?= e(jcv_date($trk['planned_completion_date'] ?? '')) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isDelayed): ?>
                                        <strong><?= e((int)($trk['delay_days'] ?? $delayDays)) ?> day(s)</strong>
                                        <small class="d-block delay-meta">Started: <?= e(jcv_datetime($trk['delay_started_at'] ?? '')) ?></small>
                                        <small class="d-block delay-meta">Completed: <?= e(jcv_datetime($trk['actual_completed_at'] ?? '')) ?></small>
                                        <small class="d-block delay-info"><?= e($trk['delay_remarks'] ?? '-') ?></small>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="no-print">
                                    <?php if (jcv_can_update_tracking($conn, $trk)): ?>
                                    <form class="tracking-update-form js-tracking-update-form <?= $needsDelayReason ? 'delay-visible' : '' ?>" method="post" action="api/job_cards.php" data-delay-required="<?= $needsDelayReason ? '1' : '0' ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="update_tracking_status">
                                        <input type="hidden" name="tracking_id" value="<?= e($trk['id'] ?? '') ?>">
                                        <div class="d-flex gap-2 align-items-center">
                                            <select name="status" class="form-select form-select-sm js-status-select">
                                                <option value="pending" <?= $trkStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="in_progress" <?= $trkStatus === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                                <option value="completed" <?= $trkStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-primary fw-bold">Update</button>
                                        </div>
                                        <div class="delay-required-box">
                                            <select name="delay_reason_id" class="form-select form-select-sm mb-2 js-delay-reason">
                                                <option value="">Select Delay Reason</option>
                                                <?php foreach ($delayReasons as $reason): ?>
                                                <option value="<?= e($reason['id']) ?>" <?= (int)($trk['delay_reason_id'] ?? 0) === (int)$reason['id'] ? 'selected' : '' ?>><?= e($reason['reason_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <textarea name="delay_remarks" class="form-control form-control-sm js-delay-remarks" rows="2" placeholder="Enter delay remarks"><?= e($trk['delay_remarks'] ?? '') ?></textarea>
                                            <small class="delay-info">Delay reason is required before updating delayed status.</small>
                                        </div>
                                    </form>
                                    <?php else: ?>
                                    <span class="role-lock-text">Role restricted</span>
                                    <?php endif; ?>
                                </td>
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
    function showToast(message,type='success',title='Success'){
        const old=document.getElementById('jobViewToastWrap'); if(old)old.remove();
        const wrap=document.createElement('div'); wrap.id='jobViewToastWrap'; wrap.className='toast-container position-fixed top-0 end-0 p-3'; wrap.style.zIndex='12000';
        wrap.innerHTML=`<div id="jobViewToast" class="toast toast-ui ${type}" role="alert" data-bs-delay="4200"><div class="d-flex"><div class="toast-body"><div class="toast-title">${title}</div><div class="toast-message">${message}</div></div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"></button></div></div>`;
        document.body.appendChild(wrap); if(window.bootstrap)new bootstrap.Toast(document.getElementById('jobViewToast')).show(); else alert(message);
    }

    document.querySelectorAll('.js-tracking-update-form').forEach(function(form){
        const statusSelect=form.querySelector('.js-status-select');
        if(statusSelect){
            statusSelect.addEventListener('change',function(){
                const delayRequired=form.getAttribute('data-delay-required')==='1' && ['in_progress','completed'].includes(statusSelect.value);
                form.classList.toggle('delay-visible',delayRequired);
            });
        }
        form.addEventListener('submit',function(event){
            event.preventDefault();
            const statusValue=statusSelect?statusSelect.value:'';
            const delayRequired=form.getAttribute('data-delay-required')==='1' && ['in_progress','completed'].includes(statusValue);
            const delayReason=form.querySelector('.js-delay-reason');
            const delayRemarks=form.querySelector('.js-delay-remarks');
            if(delayRequired && (!delayReason || !delayReason.value || !delayRemarks || !delayRemarks.value.trim())){
                form.classList.add('delay-visible');
                showToast('Please enter delay reason and delay remarks before updating this delayed job status.','danger','Delay Reason Required');
                return;
            }
            const btn=form.querySelector('button[type="submit"]'); const oldText=btn?btn.textContent:'';
            if(btn){btn.disabled=true;btn.textContent='Updating...';}
            fetch('api/job_cards.php',{method:'POST',body:new FormData(form),credentials:'same-origin'})
            .then(r=>r.json())
            .then(data=>{
                if(data.status){showToast(data.message || 'Job tracking updated.','success','Success'); setTimeout(()=>window.location.reload(),800);}
                else{showToast(data.message || 'Update failed.','danger','Failed'); if(btn){btn.disabled=false;btn.textContent=oldText||'Update';}}
            })
            .catch(()=>{showToast('API request failed.','danger','Failed'); if(btn){btn.disabled=false;btn.textContent=oldText||'Update';}});
        });
    });
})();
</script>
</body>
</html>
