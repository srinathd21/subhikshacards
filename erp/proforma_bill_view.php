<?php
/**
 * proforma_bill_view.php
 * Full view page for created Proforma Bill / Sales Order.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (function_exists('require_permission')) {
    require_permission($conn, 'can_view', 'proforma_bills.php');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function pbv_table_exists(mysqli $conn, string $table): bool
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

function pbv_money($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function pbv_date($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function pbv_datetime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime((string)$value)) : '-';
}

function pbv_yes_no($value): string
{
    return ((int)$value === 1) ? 'Yes' : 'No';
}


function pbv_col(mysqli $conn, string $table, string $col): bool
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

function pbv_bind_type($v): string
{
    return is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
}

function pbv_update(mysqli $conn, string $table, array $data, int $id): void
{
    $filtered = [];
    foreach ($data as $key => $value) {
        if (pbv_col($conn, $table, $key)) $filtered[$key] = $value;
    }

    if (!$filtered) return;

    $sets = [];
    $types = '';
    $values = [];
    foreach ($filtered as $key => $value) {
        $sets[] = "`{$key}`=?";
        $types .= pbv_bind_type($value);
        $values[] = $value;
    }

    $types .= 'i';
    $values[] = $id;

    $stmt = $conn->prepare("UPDATE {$table} SET " . implode(',', $sets) . " WHERE id=?");
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
}

function pbv_delay_days_from_planned($plannedDate): int
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

function pbv_next_expected_date($plannedDate): ?string
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

function pbv_default_delay_reason_id(mysqli $conn): ?int
{
    if (!pbv_table_exists($conn, 'delay_reasons')) return null;

    try {
        $stmt = $conn->prepare("SELECT id FROM delay_reasons WHERE reason_key = 'other' AND is_active = 1 LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];

        $stmt = $conn->prepare("SELECT id FROM delay_reasons WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function pbv_history_insert(mysqli $conn, int $trackingId, int $jobCardId, int $workflowStepId, string $oldStatus, string $newStatus, string $remarks): void
{
    if (!pbv_table_exists($conn, 'job_tracking_history')) return;

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

function pbv_auto_mark_overdue_tracking(mysqli $conn, int $jobCardId): void
{
    if ($jobCardId <= 0 || !pbv_table_exists($conn, 'job_tracking')) return;

    $today = date('Y-m-d');
    $defaultReasonId = pbv_default_delay_reason_id($conn);

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
            $nextExpectedDate = !empty($row['revised_completion_date']) ? (string)$row['revised_completion_date'] : pbv_next_expected_date($plannedDate);
            $delayDays = pbv_delay_days_from_planned($plannedDate);
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

            pbv_update($conn, 'job_tracking', $data, $trackingId);

            pbv_history_insert(
                $conn,
                $trackingId,
                (int)$row['job_card_id'],
                (int)$row['workflow_step_id'],
                $oldStatus,
                'delayed',
                'Auto marked delayed. Original planned date: ' . $plannedDate . '. Next expected date: ' . ($nextExpectedDate ?: '-') . '.'
            );
        }
        $stmt->close();
    } catch (Throwable $e) {}
}


function pbv_status_label($status): string
{
    $status = trim((string)$status);
    if ($status === '') return 'Pending';

    return ucwords(str_replace('_', ' ', $status));
}

function pbv_progress_percent(array $tracking): int
{
    $total = count($tracking);
    if ($total <= 0) return 0;

    $completed = 0;
    foreach ($tracking as $row) {
        if ((string)($row['status'] ?? '') === 'completed') {
            $completed++;
        }
    }

    return (int)round(($completed / $total) * 100);
}

function pbv_progress_counts(array $tracking): array
{
    $counts = [
        'total' => count($tracking),
        'completed' => 0,
        'in_progress' => 0,
        'pending' => 0,
        'delayed' => 0,
        'other' => 0
    ];

    foreach ($tracking as $row) {
        $status = (string)($row['status'] ?? 'pending');

        if (isset($counts[$status])) {
            $counts[$status]++;
        } else {
            $counts['other']++;
        }

        if ((int)($row['is_delayed'] ?? 0) === 1) {
            $counts['delayed']++;
        }
    }

    return $counts;
}

function pbv_current_role_ids(mysqli $conn): array
{
    $ids = [];

    foreach (['role_id', 'current_role_id'] as $key) {
        if (!empty($_SESSION[$key])) $ids[] = (int)$_SESSION[$key];
    }

    foreach (['role_ids', 'user_role_ids'] as $key) {
        if (!empty($_SESSION[$key]) && is_array($_SESSION[$key])) {
            foreach ($_SESSION[$key] as $id) $ids[] = (int)$id;
        }
    }

    if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        foreach ($_SESSION['roles'] as $role) {
            if (is_array($role) && !empty($role['id'])) $ids[] = (int)$role['id'];
            elseif (is_numeric($role)) $ids[] = (int)$role;
        }
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId > 0 && pbv_table_exists($conn, 'user_roles')) {
        try {
            $stmt = $conn->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $ids[] = (int)$row['role_id'];
            $stmt->close();
        } catch (Throwable $e) {}
    }

    return array_values(array_unique(array_filter($ids)));
}

function pbv_current_role_keys(mysqli $conn): array
{
    $keys = [];

    foreach (['role_key', 'current_role_key'] as $key) {
        if (!empty($_SESSION[$key])) $keys[] = strtolower((string)$_SESSION[$key]);
    }

    if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        foreach ($_SESSION['roles'] as $role) {
            if (is_array($role) && !empty($role['role_key'])) $keys[] = strtolower((string)$role['role_key']);
            elseif (is_string($role)) $keys[] = strtolower($role);
        }
    }

    $roleIds = pbv_current_role_ids($conn);
    if ($roleIds && pbv_table_exists($conn, 'roles')) {
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

function pbv_is_admin_user(mysqli $conn): bool
{
    if (function_exists('is_super_admin') && is_super_admin()) return true;
    if (!empty($_SESSION['is_super_admin'])) return true;

    foreach (pbv_current_role_keys($conn) as $key) {
        if (in_array($key, ['super_admin', 'admin', 'business_admin'], true)) return true;
    }

    return false;
}

function pbv_can_update_tracking(mysqli $conn, array $trackingRow): bool
{
    if (pbv_is_admin_user($conn)) return true;

    $responsibleRoleId = (int)($trackingRow['responsible_role_id'] ?? 0);
    if ($responsibleRoleId <= 0) return false;

    return in_array($responsibleRoleId, pbv_current_role_ids($conn), true);
}



$id = (int)($_GET['id'] ?? 0);
$bill = null;
$items = [];
$payments = [];
$jobs = [];
$tracking = [];
$delayReasons = [];
$error = '';
if (empty($_SESSION['job_cards_csrf'])) $_SESSION['job_cards_csrf'] = bin2hex(random_bytes(32));
$jobCardsCsrfToken = $_SESSION['job_cards_csrf'];

if ($id <= 0) {
    $error = 'Invalid Proforma Bill.';
} else {
    try {
        $stmt = $conn->prepare("
            SELECT
                pb.*,
                ps.status_name,
                ft.function_name,
                ft.field_group,
                q.quotation_no,
                e.enquiry_no,
                c.address AS customer_master_address,
                c.gst_number AS customer_master_gst
            FROM proforma_bills pb
            LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id
            LEFT JOIN function_types ft ON ft.id = pb.function_type_id
            LEFT JOIN quotations q ON q.id = pb.quotation_id
            LEFT JOIN enquiries e ON e.id = pb.enquiry_id
            LEFT JOIN customers c ON c.id = pb.customer_id
            WHERE pb.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $bill = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$bill) {
            $error = 'Proforma Bill not found.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($bill) {
    try {
        $stmt = $conn->prepare("
            SELECT
                pbi.*,
                p.product_name AS master_product_name,
                pt.printing_name,
                pst.sub_type_name
            FROM proforma_bill_items pbi
            LEFT JOIN products p ON p.id = pbi.product_id
            LEFT JOIN printing_types pt ON pt.id = pbi.printing_type_id
            LEFT JOIN printing_sub_types pst ON pst.id = pbi.printing_sub_type_id
            WHERE pbi.proforma_bill_id = ?
            ORDER BY pbi.sort_order ASC, pbi.id ASC
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {
    }

    if (pbv_table_exists($conn, 'payments')) {
        try {
            $stmt = $conn->prepare("
                SELECT *
                FROM payments
                WHERE proforma_bill_id = ?
                ORDER BY id ASC
            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $payments[] = $row;
            }
            $stmt->close();
        } catch (Throwable $e) {
        }
    }

    if (pbv_table_exists($conn, 'job_cards')) {
        try {
            $stmt = $conn->prepare("
                SELECT jc.*, jcs.status_name AS job_status_name, ws.step_name AS current_step_name
                FROM job_cards jc
                LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
                LEFT JOIN workflow_steps ws ON ws.id = jc.current_workflow_step_id
                WHERE jc.proforma_bill_id = ?
                ORDER BY jc.id ASC
            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $jobs[] = $row;
            }
            $stmt->close();
        } catch (Throwable $e) {
        }
    }

    if ($jobs && pbv_table_exists($conn, 'job_tracking')) {
        $jobId = (int)$jobs[0]['id'];
        pbv_auto_mark_overdue_tracking($conn, $jobId);
        try {
            $historyJoin = '';
            $historySelect = "
                NULL AS updated_by_name,
                NULL AS updated_by_department,
                NULL AS last_updated_at,
                NULL AS last_action_remarks
            ";

            if (pbv_table_exists($conn, 'job_tracking_history')) {
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
                    CASE
                        WHEN jth.id IS NOT NULL THEN hu.name
                        WHEN jt.completed_by IS NOT NULL THEN cu.name
                        ELSE NULL
                    END AS updated_by_name,
                    CASE
                        WHEN jth.id IS NOT NULL THEN hur.role_name
                        WHEN jt.completed_by IS NOT NULL THEN cur.role_name
                        ELSE NULL
                    END AS updated_by_department,
                    CASE
                        WHEN jth.id IS NOT NULL THEN jth.changed_at
                        WHEN jt.completed_by IS NOT NULL THEN jt.actual_completed_at
                        ELSE NULL
                    END AS last_updated_at,
                    CASE
                        WHEN jth.id IS NOT NULL THEN jth.action_remarks
                        ELSE NULL
                    END AS last_action_remarks
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
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $tracking[] = $row;
            }
            $stmt->close();
        } catch (Throwable $e) {
        }
    }
}

$progressPercent = pbv_progress_percent($tracking);
$progressCounts = pbv_progress_counts($tracking);
$currentJob = $jobs[0] ?? null;
$currentStepName = $currentJob['current_step_name'] ?? '-';
$currentJobStatus = $currentJob['job_status_name'] ?? '-';
$trackingStatuses = [];
foreach ($tracking as $trk) {
    $status = trim((string)($trk['status'] ?? 'pending'));
    if ($status === '') $status = 'pending';
    $trackingStatuses[$status] = pbv_status_label($status);
}

if (pbv_table_exists($conn, 'delay_reasons')) {
    try {
        $res = $conn->query("SELECT id, reason_name FROM delay_reasons WHERE is_active = 1 ORDER BY id ASC");
        while ($row = $res->fetch_assoc()) {
            $delayReasons[] = $row;
        }
        $res->free();
    } catch (Throwable $e) {}
}

$pageTitle = $bill ? 'View Proforma - ' . ($bill['proforma_no'] ?? '') : 'View Proforma';
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
        .toast-ui{border:0;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.18);overflow:hidden;min-width:320px;max-width:420px}.toast-ui.success{background:#dcfce7;color:#14532d}.toast-ui.danger{background:#fee2e2;color:#7f1d1d}.toast-title{font-size:14px;font-weight:900}.toast-message{font-size:13px;font-weight:800;line-height:1.45}
        .view-page .page-head{padding:24px 28px;margin-bottom:18px}
        .view-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}
        .view-card{padding:24px;border-radius:20px;margin-bottom:18px}
        .view-section-title{font-size:17px;font-weight:900;color:var(--text-main);margin-bottom:14px}
        .info-box{border:1px solid var(--border-soft);border-radius:16px;padding:14px;background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));height:100%}
        .info-box small{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.02em;color:var(--text-muted);font-weight:900;margin-bottom:5px}
        .info-box strong{display:block;font-size:15px;color:var(--text-main);font-weight:900;word-break:break-word}
        .status-chip{display:inline-flex;align-items:center;border-radius:999px;padding:6px 12px;font-weight:900;font-size:12px;background:#dbeafe;color:#1d4ed8}
        .table-view th{font-size:12px;text-transform:uppercase;color:var(--text-muted);white-space:nowrap}
        .table-view td{vertical-align:middle}
        .progress-panel{border:1px solid var(--border-soft);border-radius:20px;padding:18px;background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg))}
        .progress-main-value{font-size:38px;font-weight:900;color:var(--text-main);line-height:1}
        .progress-track{height:14px;border-radius:999px;background:color-mix(in srgb,var(--text-muted) 16%,transparent);overflow:hidden}
        .progress-track span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#2563eb,#22c55e)}
        .progress-stat{border:1px solid var(--border-soft);border-radius:16px;padding:12px;background:var(--card-bg);height:100%}
        .progress-stat small{display:block;font-size:10px;font-weight:900;text-transform:uppercase;color:var(--text-muted);letter-spacing:.04em}
        .progress-stat strong{display:block;font-size:22px;font-weight:900;color:var(--text-main)}
        .filter-chip{border:1px solid var(--border-soft);background:var(--card-bg);color:var(--text-main);border-radius:999px;padding:7px 14px;font-weight:900;font-size:12px}
        .filter-chip.active{background:#2563eb;color:#fff;border-color:#2563eb}
        .tracking-status-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:900;background:#e5e7eb;color:#374151}
        .tracking-status-badge.completed{background:#dcfce7;color:#166534}
        .tracking-status-badge.in_progress{background:#dbeafe;color:#1d4ed8}
        .tracking-status-badge.pending{background:#fef3c7;color:#92400e}
        .tracking-status-badge.delayed{background:#fee2e2;color:#991b1b}
        .tracking-row-hidden{display:none!important}
        .tracking-update-form{min-width:220px}.tracking-update-form .form-select{min-height:36px;border-radius:12px}.tracking-update-form .btn{min-height:36px;border-radius:12px}.role-lock-text{font-size:11px;font-weight:900;color:var(--text-muted)}.delay-required-box{display:none;margin-top:8px}.tracking-update-form.delay-visible .delay-required-box{display:block}.delay-info{font-size:11px;font-weight:800;color:#991b1b}.planned-date-old{text-decoration:line-through;color:#991b1b;font-weight:900}.planned-date-new{color:#166534;font-weight:900;margin-left:7px}.delay-meta{font-size:11px;font-weight:800;color:var(--text-muted)}
        @media print{#sidebar,#mobileOverlay,#settingsOverlay,.no-print,nav,.app-shell>aside,.tracking-filter-wrap{display:none!important}main{margin:0!important}.view-card,.page-head{box-shadow:none!important;border:1px solid #ddd!important}}
        @media(max-width:767.98px){.view-page .page-head{padding:18px}.view-page .page-head h1{font-size:23px}.view-card{padding:16px}}
    </style>
</head>
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main id="main">
        <?php include __DIR__ . '/includes/nav.php'; ?>

        <section class="page-section view-page">
            <div class="card-ui page-head">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div>
                        <h1 class="mb-1">Proforma Bill Details</h1>
                        <p class="text-muted-custom mb-0">Full details entered from Create Proforma page.</p>
                    </div>
                    <div class="d-flex gap-2 no-print">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" onclick="window.print()">Print</button>
                        <a href="proforma_bills.php" class="btn btn-primary rounded-pill px-4 fw-bold">Back to List</a>
                    </div>
                </div>
            </div>

            <?php if ($error !== ''): ?>
            <div class="card-ui view-card">
                <div class="alert alert-danger mb-0"><?= e($error) ?></div>
            </div>
            <?php endif; ?>

            <?php if ($bill): ?>
            <div class="card-ui view-card">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                    <div>
                        <div class="view-section-title mb-1"><?= e($bill['proforma_no'] ?? '-') ?></div>
                        <div class="text-muted-custom">
                            Quotation: <?= e($bill['quotation_no'] ?? '-') ?> |
                            Enquiry: <?= e($bill['enquiry_no'] ?? '-') ?>
                        </div>
                    </div>
                    <div>
                        <span class="status-chip"><?= e($bill['status_name'] ?? '-') ?></span>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-3"><div class="info-box"><small>Customer Name</small><strong><?= e($bill['customer_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Mobile</small><strong><?= e($bill['mobile'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Function Type</small><strong><?= e($bill['function_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Order Type</small><strong><?= e(ucfirst((string)($bill['order_type'] ?? '-'))) ?></strong></div></div>

                    <div class="col-md-3"><div class="info-box"><small>Bride Name</small><strong><?= e($bill['bride_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Groom Name</small><strong><?= e($bill['groom_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Function Date</small><strong><?= e(pbv_date($bill['function_date'] ?? '')) ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Function Time</small><strong><?= e($bill['function_time'] ?? '-') ?></strong></div></div>
                    <div class="col-12"><div class="info-box"><small>Venue</small><strong><?= e($bill['venue'] ?? '-') ?></strong></div></div>
                </div>
            </div>

            <div class="card-ui view-card">
                <div class="view-section-title">Billing Details</div>
                <div class="row g-3">
                    <div class="col-md-3"><div class="info-box"><small>Billing Name</small><strong><?= e($bill['billing_name'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Billing Mobile</small><strong><?= e($bill['billing_mobile'] ?? '-') ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>GST Number</small><strong><?= e(($bill['gst_number'] ?? '') ?: ($bill['customer_master_gst'] ?? '-')) ?></strong></div></div>
                    <div class="col-md-3"><div class="info-box"><small>Delivery Date</small><strong><?= e(pbv_date($bill['delivery_date'] ?? '')) ?></strong></div></div>
                    <div class="col-12"><div class="info-box"><small>Billing Address</small><strong><?= e(($bill['billing_address'] ?? '') ?: ($bill['customer_master_address'] ?? '-')) ?></strong></div></div>
                </div>
            </div>

            <div class="card-ui view-card">
                <div class="view-section-title">Amount / Payment Summary</div>
                <div class="row g-3">
                    <div class="col-md-2"><div class="info-box"><small>Total Qty</small><strong><?= e(number_format((float)($bill['total_qty'] ?? 0), 2)) ?></strong></div></div>
                    <div class="col-md-2"><div class="info-box"><small>Sub Total</small><strong><?= e(pbv_money($bill['sub_total'] ?? 0)) ?></strong></div></div>
                    <div class="col-md-2"><div class="info-box"><small>Discount</small><strong><?= e(pbv_money($bill['discount_amount'] ?? 0)) ?></strong></div></div>
                    <div class="col-md-2"><div class="info-box"><small>Final Amount</small><strong><?= e(pbv_money($bill['final_amount'] ?? 0)) ?></strong></div></div>
                    <div class="col-md-2"><div class="info-box"><small>Advance</small><strong><?= e(pbv_money($bill['advance_amount'] ?? 0)) ?></strong></div></div>
                    <div class="col-md-2"><div class="info-box"><small>Balance</small><strong><?= e(pbv_money($bill['balance_amount'] ?? 0)) ?></strong></div></div>
                    <div class="col-12"><div class="info-box"><small>Remarks</small><strong><?= e($bill['remarks'] ?? '-') ?></strong></div></div>
                </div>
            </div>

            <div class="card-ui view-card">
                <div class="view-section-title">Product / Printing Details</div>
                <div class="table-responsive">
                    <table class="table table-view">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Item</th>
                                <th>Description</th>
                                <th>Qty</th>
                                <th>Rate</th>
                                <th>Amount</th>
                                <th>Printing</th>
                                <th>Extra Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$items): ?>
                            <tr><td colspan="8" class="text-center text-muted-custom">No items found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($items as $index => $item): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><strong><?= e($item['item_name'] ?? '-') ?></strong><small class="d-block text-muted-custom"><?= e($item['master_product_name'] ?? '') ?></small></td>
                                <td><?= e($item['description'] ?? '-') ?></td>
                                <td><?= e(number_format((float)($item['qty'] ?? 0), 2)) ?></td>
                                <td><?= e(pbv_money($item['rate'] ?? 0)) ?></td>
                                <td><?= e(pbv_money($item['amount'] ?? 0)) ?></td>
                                <td>
                                    <strong><?= e($item['printing_name'] ?? '-') ?></strong>
                                    <small class="d-block text-muted-custom"><?= e($item['sub_type_name'] ?? '') ?></small>
                                </td>
                                <td>
                                    Size: <?= e($item['size_text'] ?? '-') ?><br>
                                    GSM: <?= e($item['gsm_thickness'] ?? '-') ?><br>
                                    Lamination: <?= e(pbv_yes_no($item['lamination_required'] ?? 0)) ?> <?= e($item['lamination_type'] ?? '') ?><br>
                                    Side: <?= e($item['printing_side'] ?? '-') ?><br>
                                    Screening: <?= e($item['screening_type'] ?? '-') ?><br>
                                    Finishing: <?= e(pbv_yes_no($item['finishing_required'] ?? 0)) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-ui view-card">
                <div class="view-section-title">Payments</div>
                <div class="table-responsive">
                    <table class="table table-view">
                        <thead><tr><th>Payment No</th><th>Type</th><th>Mode</th><th>Amount</th><th>Date</th><th>Reference</th><th>Remarks</th></tr></thead>
                        <tbody>
                            <?php if (!$payments): ?>
                            <tr><td colspan="7" class="text-center text-muted-custom">No payment entry found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td><?= e($pay['payment_no'] ?? '-') ?></td>
                                <td><?= e(ucfirst((string)($pay['payment_type'] ?? '-'))) ?></td>
                                <td><?= e(ucfirst((string)($pay['payment_mode'] ?? '-'))) ?></td>
                                <td><?= e(pbv_money($pay['amount'] ?? 0)) ?></td>
                                <td><?= e(pbv_date($pay['payment_date'] ?? '')) ?></td>
                                <td><?= e($pay['reference_no'] ?? '-') ?></td>
                                <td><?= e($pay['remarks'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-ui view-card">
                <div class="view-section-title">Job Progress Status</div>
                <?php if (!$jobs): ?>
                    <div class="text-muted-custom fw-bold">No job card created for this proforma bill.</div>
                <?php else: ?>
                    <div class="progress-panel mb-3">
                        <div class="row g-3 align-items-center">
                            <div class="col-lg-3">
                                <small class="text-muted-custom fw-bold text-uppercase">Overall Progress</small>
                                <div class="progress-main-value"><?= e($progressPercent) ?>%</div>
                            </div>
                            <div class="col-lg-9">
                                <div class="d-flex justify-content-between gap-2 mb-2">
                                    <strong><?= e($currentStepName) ?></strong>
                                    <span class="status-chip"><?= e($currentJobStatus) ?></span>
                                </div>
                                <div class="progress-track"><span style="width:<?= e($progressPercent) ?>%"></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-6 col-md-2"><div class="progress-stat"><small>Total Steps</small><strong><?= e($progressCounts['total']) ?></strong></div></div>
                        <div class="col-6 col-md-2"><div class="progress-stat"><small>Completed</small><strong><?= e($progressCounts['completed']) ?></strong></div></div>
                        <div class="col-6 col-md-2"><div class="progress-stat"><small>In Progress</small><strong><?= e($progressCounts['in_progress']) ?></strong></div></div>
                        <div class="col-6 col-md-2"><div class="progress-stat"><small>Pending</small><strong><?= e($progressCounts['pending']) ?></strong></div></div>
                        <div class="col-6 col-md-2"><div class="progress-stat"><small>Delayed</small><strong><?= e($progressCounts['delayed']) ?></strong></div></div>
                        <div class="col-6 col-md-2"><div class="progress-stat"><small>Other</small><strong><?= e($progressCounts['other']) ?></strong></div></div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card-ui view-card">
                <div class="view-section-title">Job Card / Tracking</div>
                <?php if (!$jobs): ?>
                    <div class="text-muted-custom fw-bold">No job card created for this proforma bill.</div>
                <?php else: ?>
                    <?php foreach ($jobs as $job): ?>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3"><div class="info-box"><small>Job Card No</small><strong><?= e($job['job_card_no'] ?? '-') ?></strong></div></div>
                        <div class="col-md-3"><div class="info-box"><small>Status</small><strong><?= e($job['job_status_name'] ?? '-') ?></strong></div></div>
                        <div class="col-md-3"><div class="info-box"><small>Current Step</small><strong><?= e($job['current_step_name'] ?? '-') ?></strong></div></div>
                        <div class="col-md-3"><div class="info-box"><small>Tracking Token</small><strong><?= e($job['tracking_token'] ?? '-') ?></strong></div></div>
                    </div>
                    <?php endforeach; ?>

                    <div class="tracking-filter-wrap no-print mb-3">
                        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 align-items-md-center">
                            <div class="d-flex gap-2 flex-wrap" id="trackingStatusFilters">
                                <button type="button" class="filter-chip active" data-filter-status="all">All</button>
                                <?php foreach ($trackingStatuses as $statusKey => $statusLabel): ?>
                                <button type="button" class="filter-chip" data-filter-status="<?= e($statusKey) ?>"><?= e($statusLabel) ?></button>
                                <?php endforeach; ?>
                                <?php if (($progressCounts['delayed'] ?? 0) > 0): ?>
                                <button type="button" class="filter-chip" data-filter-status="delayed">Delayed</button>
                                <?php endif; ?>
                            </div>
                            <select id="trackingStatusSelect" class="form-select" style="max-width:260px">
                                <option value="all">All Tracking Status</option>
                                <?php foreach ($trackingStatuses as $statusKey => $statusLabel): ?>
                                <option value="<?= e($statusKey) ?>"><?= e($statusLabel) ?></option>
                                <?php endforeach; ?>
                                <?php if (($progressCounts['delayed'] ?? 0) > 0): ?>
                                <option value="delayed">Delayed</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-view" id="trackingTable">
                            <thead><tr><th>Order</th><th>Step</th><th>Status</th><th>Assigned Dept</th><th>Updated By</th><th>Updated Dept</th><th>Planned / Revised</th><th>Delay Details</th><th class="no-print">Update</th></tr></thead>
                            <tbody>
                                <?php if (!$tracking): ?>
                                <tr><td colspan="9" class="text-center text-muted-custom">No tracking steps found.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($tracking as $trk): ?>
                                <?php
                                    $trkStatus = trim((string)($trk['status'] ?? 'pending'));
                                    if ($trkStatus === '') $trkStatus = 'pending';
                                    $delayDays = 0;
                                    if (!empty($trk['planned_completion_date'])) {
                                        try {
                                            $planned = new DateTime(date('Y-m-d', strtotime((string)$trk['planned_completion_date'])));
                                            $today = new DateTime(date('Y-m-d'));
                                            if ($today > $planned) $delayDays = (int)$planned->diff($today)->days;
                                        } catch (Throwable $e) {}
                                    }
                                    $isDelayed = (int)($trk['is_delayed'] ?? 0) === 1 || $delayDays > 0;
                                    $needsDelayReason = $delayDays > 0 && empty($trk['delay_reason_id']) && trim((string)($trk['delay_remarks'] ?? '')) === '';
                                    $badgeClass = $isDelayed ? 'delayed' : $trkStatus;
                                ?>
                                <tr class="tracking-row" data-status="<?= e($trkStatus) ?>" data-delayed="<?= $isDelayed ? '1' : '0' ?>">
                                    <td><?= e($trk['sort_order'] ?? '-') ?></td>
                                    <td>
                                        <strong><?= e($trk['step_name'] ?? '-') ?></strong>
                                        <small class="d-block text-muted-custom"><?= e($trk['step_key'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <span class="tracking-status-badge <?= e($badgeClass) ?>"><?= e($isDelayed ? 'Delayed' : pbv_status_label($trkStatus)) ?></span>
                                        <?php if ($isDelayed): ?>
                                        <small class="d-block delay-info">
                                            <?= e($trk['delay_reason_name'] ?? 'Delay reason pending') ?>
                                            <?php if (!empty($trk['delay_remarks'])): ?> - <?= e($trk['delay_remarks']) ?><?php endif; ?>
                                        </small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($trk['role_name'] ?? '-') ?></td>
                                    <td>
                                        <strong><?= e($trk['updated_by_name'] ?? '-') ?></strong>
                                        <?php if (!empty($trk['last_action_remarks'])): ?>
                                        <small class="d-block text-muted-custom"><?= e($trk['last_action_remarks']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($trk['updated_by_department'] ?? '-') ?></td>
                                    <td>
                                        <?php if (!empty($trk['revised_completion_date']) && !empty($trk['planned_completion_date']) && $trk['revised_completion_date'] !== $trk['planned_completion_date']): ?>
                                            Planned:
                                            <span class="planned-date-old"><?= e(pbv_date($trk['planned_completion_date'])) ?></span>
                                            <span class="planned-date-new"><?= e(pbv_date($trk['revised_completion_date'])) ?></span>
                                        <?php else: ?>
                                            <?= e(pbv_date($trk['planned_completion_date'] ?? '')) ?>
                                        <?php endif; ?>
                                        <small class="d-block delay-meta">Updated: <?= e(pbv_datetime($trk['last_updated_at'] ?? '')) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($isDelayed): ?>
                                            <strong><?= e((int)($trk['delay_days'] ?? $delayDays)) ?> day(s) delayed</strong>
                                            <small class="d-block delay-meta">Started: <?= e(pbv_datetime($trk['delay_started_at'] ?? '')) ?></small>
                                            <small class="d-block delay-meta">Completed: <?= e(pbv_datetime($trk['actual_completed_at'] ?? '')) ?></small>
                                            <small class="d-block delay-info">Reason: <?= e($trk['delay_reason_name'] ?? '-') ?></small>
                                            <?php if (!empty($trk['delay_remarks'])): ?><small class="d-block delay-info"><?= e($trk['delay_remarks']) ?></small><?php endif; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="no-print">
                                        <?php if (pbv_can_update_tracking($conn, $trk)): ?>
                                        <form class="tracking-update-form js-tracking-update-form <?= $needsDelayReason ? 'delay-visible' : '' ?>" method="post" action="api/job_cards.php" data-delay-required="<?= $needsDelayReason ? '1' : '0' ?>">
                                            <input type="hidden" name="csrf_token" value="<?= e($jobCardsCsrfToken) ?>">
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
                                                <small class="delay-info">Delay reason is required before updating this delayed status.</small>
                                            </div>
                                        </form>
                                        <?php else: ?>
                                            <span class="role-lock-text">Role restricted</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr id="trackingNoResultRow" class="d-none">
                                    <td colspan="9" class="text-center text-muted-custom py-3">No tracking steps found for selected status.</td>
                                </tr>
                            </tbody>
                        </table>
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
(function(){
    function applyTrackingFilter(status){
        const rows = Array.from(document.querySelectorAll('#trackingTable .tracking-row'));
        let visibleCount = 0;

        rows.forEach(function(row){
            const rowStatus = row.getAttribute('data-status') || 'pending';
            const delayed = row.getAttribute('data-delayed') === '1';
            const show = status === 'all' || rowStatus === status || (status === 'delayed' && delayed);

            row.classList.toggle('tracking-row-hidden', !show);
            if(show) visibleCount++;
        });

        document.getElementById('trackingNoResultRow')?.classList.toggle('d-none', visibleCount !== 0);

        document.querySelectorAll('[data-filter-status]').forEach(function(btn){
            btn.classList.toggle('active', (btn.getAttribute('data-filter-status') || 'all') === status);
        });

        const select = document.getElementById('trackingStatusSelect');
        if(select && select.value !== status){
            select.value = status;
        }
    }

    document.querySelectorAll('[data-filter-status]').forEach(function(btn){
        btn.addEventListener('click', function(){
            applyTrackingFilter(btn.getAttribute('data-filter-status') || 'all');
        });
    });

    document.getElementById('trackingStatusSelect')?.addEventListener('change', function(){
        applyTrackingFilter(this.value || 'all');
    });
})();
</script>


<script>
(function(){
    function showProgressToast(message,type='success',title='Success'){
        const old = document.getElementById('progressToastWrap');
        if(old) old.remove();

        const wrap = document.createElement('div');
        wrap.id = 'progressToastWrap';
        wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
        wrap.style.zIndex = '12000';
        wrap.innerHTML = `<div id="progressToast" class="toast toast-ui ${type}" role="alert" data-bs-delay="4200">
            <div class="d-flex">
                <div class="toast-body">
                    <div class="toast-title">${title}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>`;
        document.body.appendChild(wrap);

        if(window.bootstrap){
            new bootstrap.Toast(document.getElementById('progressToast')).show();
        }else{
            alert(message);
        }
    }

    document.querySelectorAll('.js-tracking-update-form').forEach(function(form){
        const statusSelect = form.querySelector('.js-status-select');
        if(statusSelect){
            statusSelect.addEventListener('change', function(){
                const delayRequired = form.getAttribute('data-delay-required') === '1' && ['in_progress','completed'].includes(statusSelect.value);
                form.classList.toggle('delay-visible', delayRequired);
            });
        }

        form.addEventListener('submit', function(event){
            event.preventDefault();

            const statusSelect = form.querySelector('.js-status-select');
            const statusValue = statusSelect ? statusSelect.value : '';
            const delayRequired = form.getAttribute('data-delay-required') === '1' && ['in_progress','completed'].includes(statusValue);
            const delayReason = form.querySelector('.js-delay-reason');
            const delayRemarks = form.querySelector('.js-delay-remarks');

            if(delayRequired && (!delayReason || !delayReason.value || !delayRemarks || !delayRemarks.value.trim())){
                form.classList.add('delay-visible');
                showProgressToast('Please enter delay reason and delay remarks before updating this delayed job status.', 'danger', 'Delay Reason Required');
                return;
            }

            const btn = form.querySelector('button[type="submit"]');
            const oldText = btn ? btn.textContent : '';
            if(btn){ btn.disabled = true; btn.textContent = 'Updating...'; }

            fetch('api/job_cards.php', {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if(data.status){
                    showProgressToast(data.message || 'Tracking status updated successfully.', 'success', 'Success');
                    setTimeout(() => window.location.reload(), 800);
                }else{
                    showProgressToast(data.message || 'Tracking status update failed.', 'danger', 'Failed');
                    if(btn){ btn.disabled = false; btn.textContent = oldText || 'Update'; }
                }
            })
            .catch(() => {
                showProgressToast('API request failed.', 'danger', 'Failed');
                if(btn){ btn.disabled = false; btn.textContent = oldText || 'Update'; }
            });
        });
    });
})();
</script>

</body>
</html>
