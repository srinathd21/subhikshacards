<?php

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'dashboard.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function dash_table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) return $cache[$key];

    try {
        $tableEsc = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$tableEsc}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function dash_col_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return $cache[$key];

    if (!dash_table_exists($conn, $table)) return $cache[$key] = false;

    try {
        $tableEsc = $conn->real_escape_string($table);
        $colEsc = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function dash_scalar(mysqli $conn, string $sql, $default = 0)
{
    try {
        $res = $conn->query($sql);
        if (!$res) return $default;
        $row = $res->fetch_row();
        $res->free();
        return $row ? ($row[0] ?? $default) : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function dash_fetch_all(mysqli $conn, string $sql): array
{
    try {
        $res = $conn->query($sql);
        if (!$res) return [];
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function dash_count(mysqli $conn, string $table, string $where = '1=1'): int
{
    if (!dash_table_exists($conn, $table)) return 0;
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    return (int)dash_scalar($conn, "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", 0);
}

function dash_sum(mysqli $conn, string $table, string $column, string $where = '1=1'): float
{
    if (!dash_table_exists($conn, $table) || !dash_col_exists($conn, $table, $column)) return 0.0;
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    return (float)dash_scalar($conn, "SELECT COALESCE(SUM(`{$column}`),0) FROM `{$table}` WHERE {$where}", 0);
}

function dash_indian_number($value, int $decimals = 2): string
{
    $number = (float)$value;
    $negative = $number < 0;
    $number = abs($number);

    $fixed = number_format($number, $decimals, '.', '');
    $parts = explode('.', $fixed, 2);
    $integer = $parts[0];
    $decimal = $parts[1] ?? '';

    if (strlen($integer) > 3) {
        $lastThree = substr($integer, -3);
        $remaining = substr($integer, 0, -3);
        $groups = [];

        while (strlen($remaining) > 2) {
            array_unshift($groups, substr($remaining, -2));
            $remaining = substr($remaining, 0, -2);
        }

        if ($remaining !== '') {
            array_unshift($groups, $remaining);
        }

        $integer = implode(',', $groups) . ',' . $lastThree;
    }

    $formatted = $integer;

    if ($decimals > 0) {
        $formatted .= '.' . str_pad($decimal, $decimals, '0');
    }

    return ($negative ? '-' : '') . $formatted;
}

function dash_money($amount): string
{
    return '₹' . dash_indian_number($amount, 2);
}

function dash_short_money($amount): string
{
    $amount = (float)$amount;
    if (abs($amount) >= 10000000) return '₹' . dash_indian_number($amount / 10000000, 2) . ' Cr';
    if (abs($amount) >= 100000) return '₹' . dash_indian_number($amount / 100000, 2) . ' L';
    return dash_money($amount);
}

function dash_date($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function dash_datetime_ist($value): string
{
    if (empty($value)) {
        return '-';
    }

    try {
        $utc = new DateTimeZone('UTC');
        $ist = new DateTimeZone('Asia/Kolkata');

        $dt = DateTime::createFromFormat('Y-m-d H:i:s', (string)$value, $utc);
        if (!$dt) {
            $dt = new DateTime((string)$value, $utc);
        }

        $dt->setTimezone($ist);
        return $dt->format('d-m-Y h:i A');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function dash_user_role(mysqli $conn): array
{
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $roleName = trim((string)($_SESSION['role_name'] ?? ''));
    $roleKey = trim((string)($_SESSION['role_key'] ?? ''));

    if ($roleKey === '' && $roleId > 0 && dash_table_exists($conn, 'roles')) {
        $roleIdEsc = (int)$roleId;
        $rows = dash_fetch_all($conn, "SELECT role_name, role_key FROM roles WHERE id = {$roleIdEsc} LIMIT 1");
        if ($rows) {
            $roleName = $roleName ?: (string)$rows[0]['role_name'];
            $roleKey = $roleKey ?: (string)$rows[0]['role_key'];
        }
    }

    if ($roleKey === '' && $userId > 0 && dash_table_exists($conn, 'users') && dash_table_exists($conn, 'roles')) {
        $userIdEsc = (int)$userId;
        $rows = dash_fetch_all($conn, "
            SELECT r.role_name, r.role_key
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.id = {$userIdEsc}
            LIMIT 1
        ");
        if ($rows) {
            $roleName = $roleName ?: (string)$rows[0]['role_name'];
            $roleKey = $roleKey ?: (string)$rows[0]['role_key'];
        }
    }

    $roleKey = strtolower(trim($roleKey ?: 'admin'));
    $roleName = trim($roleName ?: ucfirst(str_replace('_', ' ', $roleKey)));

    return [$roleKey, $roleName];
}

function dash_role_group(string $roleKey): string
{
    $roleKey = strtolower($roleKey);
    if (in_array($roleKey, ['admin', 'super_admin', 'administrator'], true)) return 'admin';
    if (in_array($roleKey, ['sales', 'dispatch'], true)) return 'sales';
    if (strpos($roleKey, 'design') !== false || strpos($roleKey, 'proof') !== false) return 'design';
    if (strpos($roleKey, 'printing') !== false || $roleKey === 'printing' || strpos($roleKey, 'offset') !== false || strpos($roleKey, 'screen') !== false || strpos($roleKey, 'digital') !== false) return 'printing';
    return 'general';
}

function dash_can_page(mysqli $conn, string $permission, string $page): bool
{
    if ($page === '' || $page === '#') return true;

    if (function_exists($permission)) {
        try {
            return (bool)$permission($conn, $page);
        } catch (Throwable $e) {
            return true;
        }
    }

    return true;
}

function dash_join_status_filter(): string
{
    return "
        LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
        LEFT JOIN workflow_steps cws ON cws.id = jc.current_workflow_step_id
    ";
}

function dash_active_job_where(string $alias = 'jc'): string
{
    return "
        (
            {$alias}.completed_at IS NULL
            AND LOWER(COALESCE(jcs.status_key, '')) NOT IN ('completed','cancelled')
        )
    ";
}

function dash_ready_dispatch_where(): string
{
    return "
        (
            LOWER(COALESCE(cws.step_key, '')) = 'ready_for_dispatch'
            OR LOWER(COALESCE(jcs.status_key, '')) = 'ready_for_dispatch'
        )
        AND LOWER(COALESCE(cws.step_key, '')) NOT IN ('dispatched','completed','google_review_sent')
        AND LOWER(COALESCE(jcs.status_key, '')) NOT IN ('dispatched','completed','cancelled')
        AND jc.completed_at IS NULL
    ";
}

function dash_completed_job_where(): string
{
    return "(
        LOWER(COALESCE(jcs.status_key, '')) = 'completed'
        OR jc.completed_at IS NOT NULL
        OR LOWER(COALESCE(cws.step_key, '')) IN ('completed','google_review_sent')
    )";
}

[$roleKey, $roleName] = dash_user_role($conn);
$roleGroup = dash_role_group($roleKey);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$displayName = trim((string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'User')) ?: 'User';
$today = date('Y-m-d');

// -----------------------------------------------------------------------------
// Customer Approved - Continue Job Card reminder
//
// Purpose:
// - Customer has approved the Proofing / Design Approval.
// - Approval workflow stage is already completed automatically.
// - The NEXT workflow stage is still Pending.
// - Admin + Designing / Proofing team get a popup so they continue the Job Card.
// - As soon as the next stage becomes In Progress / Completed, this reminder
//   disappears automatically because it no longer matches the query.
// -----------------------------------------------------------------------------
$customerApprovalReminderRows = [];
$customerApprovalReminderTotal = 0;
$showCustomerApprovalReminder = false;
$customerApprovalReminderCanView = dash_can_page($conn, 'can_view', 'job_cards.php');
$customerApprovalQueueCanView = dash_can_page($conn, 'can_view', 'customer_approvals.php');

if (
    $customerApprovalReminderCanView &&
    in_array($roleGroup, ['admin', 'design'], true) &&
    dash_table_exists($conn, 'job_cards') &&
    dash_table_exists($conn, 'job_tracking') &&
    dash_table_exists($conn, 'workflow_steps') &&
    dash_table_exists($conn, 'customer_approvals')
) {
    try {
        $approvalReminderSql = "
            SELECT
                ca.id AS approval_id,
                ca.status AS approval_status,
                ca.approved_by_customer,
                ca.approved_by_call,
                ca.customer_remarks,
                ca.internal_remarks,
                ca.approved_at,
                ca.updated_at AS approval_updated_at,

                jc.id AS job_card_id,
                jc.job_card_no,
                jc.customer_name,
                jc.mobile,
                jc.order_type,
                jc.delivery_date,

                jt.id AS approval_tracking_id,
                jt.status AS approval_tracking_status,

                ws.id AS approval_workflow_step_id,
                ws.step_name AS approval_step_name,
                ws.step_key AS approval_step_key,
                ws.sort_order AS approval_sort_order,

                next_ws.id AS next_workflow_step_id,
                next_ws.step_name AS next_step_name,
                next_ws.step_key AS next_step_key,

                next_jt.id AS next_tracking_id,
                next_jt.status AS next_tracking_status,

                COALESCE(NULLIF(designer.username, ''), NULLIF(designer.name, ''), '-') AS designer_name

            FROM customer_approvals ca

            INNER JOIN job_cards jc
                ON jc.id = ca.job_card_id

            INNER JOIN job_tracking jt
                ON jt.job_card_id = ca.job_card_id
               AND jt.workflow_step_id = ca.workflow_step_id

            INNER JOIN workflow_steps ws
                ON ws.id = ca.workflow_step_id

            LEFT JOIN workflow_steps next_ws
                ON next_ws.id = (
                    SELECT ws2.id
                    FROM workflow_steps ws2
                    WHERE ws2.order_type = ws.order_type
                      AND ws2.is_active = 1
                      AND ws2.sort_order > ws.sort_order
                    ORDER BY ws2.sort_order ASC, ws2.id ASC
                    LIMIT 1
                )

            LEFT JOIN job_tracking next_jt
                ON next_jt.job_card_id = jc.id
               AND next_jt.workflow_step_id = next_ws.id

            LEFT JOIN users designer
                ON designer.id = jc.assigned_design_user_id

            WHERE ca.id = (
                    SELECT MAX(ca2.id)
                    FROM customer_approvals ca2
                    WHERE ca2.job_card_id = ca.job_card_id
                      AND ca2.workflow_step_id = ca.workflow_step_id
                )

              AND (
                    COALESCE(ws.is_approval_step, 0) = 1
                    OR LOWER(COALESCE(ws.step_key, '')) IN ('proofing_approval','design_approval')
                    OR LOWER(COALESCE(ws.step_name, '')) LIKE '%approval%'
                  )

              AND (
                    LOWER(COALESCE(ca.status, '')) = 'approved'
                    OR COALESCE(ca.approved_by_customer, 0) = 1
                    OR COALESCE(ca.approved_by_call, 0) = 1
                  )

              AND LOWER(COALESCE(jt.status, '')) IN ('completed','skipped')

              AND next_ws.id IS NOT NULL
              AND next_jt.id IS NOT NULL
              AND LOWER(COALESCE(next_jt.status, 'pending')) = 'pending'

              AND jc.completed_at IS NULL

            ORDER BY
                COALESCE(ca.approved_at, ca.updated_at) DESC,
                jc.id DESC

            LIMIT 25
        ";

        $customerApprovalReminderRows = dash_fetch_all($conn, $approvalReminderSql);
        $customerApprovalReminderTotal = count($customerApprovalReminderRows);

        /*
         * Show once for the current set of newly-approved items in this login session.
         * If another customer approves later, the approval IDs change and the popup
         * can appear again for the new approval.
         */
        $approvalReminderIds = [];
        foreach ($customerApprovalReminderRows as $approvalReminderRow) {
            $approvalReminderIds[] = (int)($approvalReminderRow['approval_id'] ?? 0);
        }

        sort($approvalReminderIds);
        $approvalReminderFingerprint = md5(implode(',', $approvalReminderIds));
        $approvalReminderSessionKey = 'customer_approved_continue_reminder_' . $approvalReminderFingerprint;

        if (
            $customerApprovalReminderTotal > 0 &&
            empty($_SESSION[$approvalReminderSessionKey])
        ) {
            $showCustomerApprovalReminder = true;
            $_SESSION[$approvalReminderSessionKey] = 1;
        }
    } catch (Throwable $e) {
        error_log('Dashboard customer approved reminder error: ' . $e->getMessage());
        $customerApprovalReminderRows = [];
        $customerApprovalReminderTotal = 0;
        $showCustomerApprovalReminder = false;
    }
}

// -----------------------------------------------------------------------------
// Follow-up login reminder
// Shows once per login/session and includes today's + overdue pending follow-ups.
// Effective reminder time = next_callback_at when scheduled, otherwise followup_at.
// -----------------------------------------------------------------------------
$followupReminderRows = [];
$followupReminderTotal = 0;
$followupReminderOverdue = 0;
$showFollowupReminder = false;
$followupReminderCanView = dash_can_page($conn, 'can_view', 'followups.php');
$followupReminderCanModify = dash_can_page($conn, 'can_update', 'followups.php') || dash_can_page($conn, 'can_edit', 'followups.php');

if (empty($_SESSION['followups_csrf'])) {
    $_SESSION['followups_csrf'] = bin2hex(random_bytes(32));
}
$followupReminderCsrf = (string)$_SESSION['followups_csrf'];

if ($followupReminderCanView && dash_table_exists($conn, 'enquiry_followups') && dash_table_exists($conn, 'enquiries')) {
    try {
        $activeEnquiryWhere = dash_col_exists($conn, 'enquiries', 'converted_to_order')
            ? 'AND COALESCE(e.converted_to_order, 0) = 0'
            : '';

        $reminderSql = "
            SELECT
                ef.id,
                ef.enquiry_id,
                ef.followup_at,
                ef.next_callback_at,
                ef.call_remarks,
                ef.followup_status,
                e.enquiry_no,
                e.customer_name,
                e.mobile,
                COALESCE(ef.next_callback_at, ef.followup_at) AS reminder_at
            FROM enquiry_followups ef
            INNER JOIN enquiries e ON e.id = ef.enquiry_id
            WHERE COALESCE(ef.next_callback_at, ef.followup_at) IS NOT NULL
              AND DATE(COALESCE(ef.next_callback_at, ef.followup_at)) <= CURDATE()
              AND LOWER(COALESCE(ef.followup_status, 'followup_pending')) NOT IN
                  ('completed','closed','converted_to_quotation','not_interested')
              {$activeEnquiryWhere}
            ORDER BY
                CASE WHEN DATE(COALESCE(ef.next_callback_at, ef.followup_at)) < CURDATE() THEN 0 ELSE 1 END ASC,
                COALESCE(ef.next_callback_at, ef.followup_at) ASC,
                ef.id ASC
            LIMIT 25
        ";

        $followupReminderRows = dash_fetch_all($conn, $reminderSql);
        $followupReminderTotal = count($followupReminderRows);
        foreach ($followupReminderRows as $reminderRow) {
            $reminderDate = !empty($reminderRow['reminder_at'])
                ? date('Y-m-d', strtotime((string)$reminderRow['reminder_at']))
                : '';
            if ($reminderDate !== '' && $reminderDate < $today) {
                $followupReminderOverdue++;
            }
        }

        $reminderSessionKey = 'followup_login_reminder_shown_' . $today;
        if ($followupReminderTotal > 0 && empty($_SESSION[$reminderSessionKey])) {
            $showFollowupReminder = true;
            $_SESSION[$reminderSessionKey] = 1;
        }
    } catch (Throwable $e) {
        $followupReminderRows = [];
        $followupReminderTotal = 0;
        $followupReminderOverdue = 0;
        $showFollowupReminder = false;
    }
}

// -----------------------------------------------------------------------------
// Follow-up dashboard summary cards
// -----------------------------------------------------------------------------
$todayFollowupCount = 0;
$overdueFollowupCount = 0;
$upcomingFollowupCount = 0;

if ($followupReminderCanView && dash_table_exists($conn, 'enquiry_followups')) {
    $activeFollowupWhere = "
        LOWER(COALESCE(followup_status, 'followup_pending')) NOT IN
            ('completed','closed','converted_to_quotation','not_interested')
    ";

    $todayFollowupCount = (int)dash_scalar($conn, "
        SELECT COUNT(*)
        FROM enquiry_followups
        WHERE {$activeFollowupWhere}
          AND COALESCE(next_callback_at, followup_at) IS NOT NULL
          AND DATE(COALESCE(next_callback_at, followup_at)) = CURDATE()
    ", 0);

    $overdueFollowupCount = (int)dash_scalar($conn, "
        SELECT COUNT(*)
        FROM enquiry_followups
        WHERE {$activeFollowupWhere}
          AND COALESCE(next_callback_at, followup_at) IS NOT NULL
          AND DATE(COALESCE(next_callback_at, followup_at)) < CURDATE()
    ", 0);

    $upcomingFollowupCount = (int)dash_scalar($conn, "
        SELECT COUNT(*)
        FROM enquiry_followups
        WHERE {$activeFollowupWhere}
          AND COALESCE(next_callback_at, followup_at) IS NOT NULL
          AND DATE(COALESCE(next_callback_at, followup_at)) > CURDATE()
    ", 0);
}

$dueFollowupNotificationCount = $todayFollowupCount + $overdueFollowupCount;

// -----------------------------------------------------------------------------
// Live ERP counts
// -----------------------------------------------------------------------------
$todayEnquiries = dash_count($conn, 'enquiries', "DATE(created_at) = '{$today}'");
$totalEnquiries = dash_count($conn, 'enquiries');
$totalCustomers = dash_count($conn, 'customers', dash_col_exists($conn, 'customers', 'is_active') ? 'is_active = 1' : '1=1');

$pendingQuotations = 0;
if (dash_table_exists($conn, 'quotations')) {
    if (dash_table_exists($conn, 'proforma_bills')) {
        $pendingQuotations = (int)dash_scalar($conn, "
            SELECT COUNT(DISTINCT q.id)
            FROM quotations q
            LEFT JOIN proforma_bills pb ON pb.quotation_id = q.id
            WHERE pb.id IS NULL
        ", 0);
    } else {
        $pendingQuotations = dash_count($conn, 'quotations');
    }
}

$todayQuotations = dash_count($conn, 'quotations', "DATE(created_at) = '{$today}'");
$totalQuotations = dash_count($conn, 'quotations');
$todayProforma = dash_count($conn, 'proforma_bills', "DATE(created_at) = '{$today}'");
$totalProforma = dash_count($conn, 'proforma_bills');
$totalBusinessValue = dash_sum($conn, 'proforma_bills', 'final_amount');
$pendingBalance = dash_sum($conn, 'proforma_bills', 'balance_amount', 'balance_amount > 0');
$todayCollection = dash_sum($conn, 'payments', 'amount', "payment_date = '{$today}' AND is_cancelled = 0");
$monthCollection = dash_sum($conn, 'payments', 'amount', "DATE_FORMAT(payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND is_cancelled = 0");

$activeJobs = 0;
$delayedJobs = 0;
$readyDispatch = 0;
$dispatchedToday = 0;
$completedJobs = 0;
$dueTodayJobs = 0;

if (dash_table_exists($conn, 'job_cards')) {
    $activeJobs = (int)dash_scalar($conn, "
        SELECT COUNT(DISTINCT jc.id)
        FROM job_cards jc
        " . dash_join_status_filter() . "
        WHERE " . dash_active_job_where() . "
    ", 0);

    $dueTodayJobs = (int)dash_scalar($conn, "
        SELECT COUNT(DISTINCT jc.id)
        FROM job_cards jc
        " . dash_join_status_filter() . "
        WHERE " . dash_active_job_where() . "
          AND jc.delivery_date IS NOT NULL
          AND DATE(jc.delivery_date) = '{$today}'
    ", 0);

    $delayedJobs = (int)dash_scalar($conn, "
        SELECT COUNT(DISTINCT jc.id)
        FROM job_cards jc
        " . dash_join_status_filter() . "
        LEFT JOIN job_tracking jt ON jt.job_card_id = jc.id AND (jt.status = 'delayed' OR jt.is_delayed = 1)
        WHERE (
            jc.is_delayed = 1
            OR LOWER(COALESCE(jcs.status_key, '')) = 'delayed'
            OR jt.id IS NOT NULL
            OR (jc.delivery_date IS NOT NULL AND jc.delivery_date < CURDATE() AND " . dash_active_job_where() . ")
        )
    ", 0);

    $readyDispatch = (int)dash_scalar($conn, "
        SELECT COUNT(DISTINCT jc.id)
        FROM job_cards jc
        " . dash_join_status_filter() . "
        WHERE " . dash_ready_dispatch_where() . "
    ", 0);

    $completedJobs = (int)dash_scalar($conn, "
        SELECT COUNT(DISTINCT jc.id)
        FROM job_cards jc
        " . dash_join_status_filter() . "
        WHERE " . dash_completed_job_where() . "
    ", 0);

    $dispatchedToday = (int)dash_scalar($conn, "
        SELECT COUNT(DISTINCT jc.id)
        FROM job_cards jc
        " . dash_join_status_filter() . "
        LEFT JOIN job_tracking jt ON jt.job_card_id = jc.id
        LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
        WHERE DATE(COALESCE(jt.actual_completed_at, jc.updated_at, jc.completed_at, jc.created_at)) = '{$today}'
          AND (
                LOWER(COALESCE(jcs.status_key, '')) = 'dispatched'
             OR LOWER(COALESCE(cws.step_key, '')) = 'dispatched'
             OR (LOWER(COALESCE(ws.step_key, '')) = 'dispatched' AND jt.status = 'completed')
          )
    ", 0);

    if ($dispatchedToday === 0 && dash_table_exists($conn, 'dispatches')) {
        $dispatchedToday = dash_count($conn, 'dispatches', "dispatch_date = '{$today}' AND LOWER(COALESCE(status,'')) NOT IN ('cancelled','deleted')");
    }
}

$pendingApprovals = dash_count($conn, 'customer_approvals', "status = 'pending'");
$approvalRework = dash_count($conn, 'customer_approvals', "status IN ('rejected','correction_requested')");
if (dash_table_exists($conn, 'job_tracking_photo_approvals')) {
    $pendingApprovals += dash_count($conn, 'job_tracking_photo_approvals', "status = 'pending'");
    $approvalRework += dash_count($conn, 'job_tracking_photo_approvals', "status = 'rejected'");
}

$whatsappFailed = dash_count($conn, 'whatsapp_logs', "status = 'failed'");
$whatsappSentToday = dash_count($conn, 'whatsapp_logs', "DATE(COALESCE(sent_at, created_at)) = '{$today}' AND status IN ('sent','delivered','read')");

// -----------------------------------------------------------------------------
// Role based KPI cards
// -----------------------------------------------------------------------------
$allKpis = [
    'today_enquiries' => [
        'label' => 'Today Enquiries',
        'value' => number_format($todayEnquiries),
        'sub' => 'New enquiries today',
        'icon' => 'phone-call',
        'color' => 'linear-gradient(135deg,#2563eb,#0ea5e9)',
        'groups' => ['admin','sales','general'],
    ],
    'pending_quotations' => [
        'label' => 'Pending Quotations',
        'value' => number_format($pendingQuotations),
        'sub' => 'Not converted to proforma',
        'icon' => 'file-text',
        'color' => 'linear-gradient(135deg,#f59e0b,#f97316)',
        'groups' => ['admin','sales','general'],
    ],
    'today_collection' => [
        'label' => 'Today Collection',
        'value' => dash_short_money($todayCollection),
        'sub' => 'Payment received today',
        'icon' => 'indian-rupee',
        'color' => 'linear-gradient(135deg,#16a34a,#22c55e)',
        'groups' => ['admin','sales'],
    ],
    'pending_balance' => [
        'label' => 'Pending Balance',
        'value' => dash_short_money($pendingBalance),
        'sub' => 'Balance to collect',
        'icon' => 'wallet-cards',
        'color' => 'linear-gradient(135deg,#dc2626,#f97316)',
        'groups' => ['admin','sales'],
    ],
    'active_jobs' => [
        'label' => 'Active Job Cards',
        'value' => number_format($activeJobs),
        'sub' => 'Not completed / cancelled',
        'icon' => 'briefcase-business',
        'color' => 'linear-gradient(135deg,#8b5cf6,#a855f7)',
        'groups' => ['admin','sales','design','printing','general'],
    ],
    'pending_approval' => [
        'label' => 'Pending Approval',
        'value' => number_format($pendingApprovals),
        'sub' => 'Customer approval waiting',
        'icon' => 'badge-check',
        'color' => 'linear-gradient(135deg,#7c3aed,#ec4899)',
        'groups' => ['admin','sales','design','general'],
    ],
    'ready_dispatch' => [
        'label' => 'Ready for Dispatch',
        'value' => number_format($readyDispatch),
        'sub' => 'Sales can dispatch now',
        'icon' => 'package-check',
        'color' => 'linear-gradient(135deg,#0f766e,#14b8a6)',
        'groups' => ['admin','sales'],
    ],
    'dispatched_today' => [
        'label' => 'Dispatched Today',
        'value' => number_format($dispatchedToday),
        'sub' => 'Today dispatch count',
        'icon' => 'truck',
        'color' => 'linear-gradient(135deg,#475569,#0f172a)',
        'groups' => ['admin','sales'],
    ],
    'delayed_jobs' => [
        'label' => 'Delayed Jobs',
        'value' => number_format($delayedJobs),
        'sub' => 'Needs immediate action',
        'icon' => 'clock-alert',
        'color' => 'linear-gradient(135deg,#ef4444,#f97316)',
        'groups' => ['admin','sales','design','printing','general'],
    ],
    'completed_jobs' => [
        'label' => 'Completed Jobs',
        'value' => number_format($completedJobs),
        'sub' => 'All completed jobs',
        'icon' => 'circle-check-big',
        'color' => 'linear-gradient(135deg,#15803d,#22c55e)',
        'groups' => ['admin','sales'],
    ],
];

$kpiCards = [];
foreach ($allKpis as $kpi) {
    if ($roleGroup === 'admin' || in_array($roleGroup, $kpi['groups'], true)) {
        $kpiCards[] = $kpi;
    }
}
$kpiCards = array_slice($kpiCards, 0, 8);

// -----------------------------------------------------------------------------
// Quick actions - filtered by role group and page permission
// -----------------------------------------------------------------------------
$quickActions = [
    [
        'title' => 'New Enquiry',
        'subtitle' => 'Add customer enquiry',
        'url' => 'enquiries.php',
        'icon' => 'phone-call',
        'color' => 'linear-gradient(135deg,#2563eb,#0ea5e9)',
        'permission' => 'enquiries.php',
        'action' => 'can_create',
        'groups' => ['admin','sales'],
    ],
    [
        'title' => 'Quotation',
        'subtitle' => 'Create / view quotations',
        'url' => 'quotations.php',
        'icon' => 'file-text',
        'color' => 'linear-gradient(135deg,#f59e0b,#f97316)',
        'permission' => 'quotations.php',
        'action' => 'can_view',
        'groups' => ['admin','sales'],
    ],
    [
        'title' => 'Proforma Bill',
        'subtitle' => 'Create proforma bill',
        'url' => 'create_proforma.php',
        'icon' => 'receipt',
        'color' => 'linear-gradient(135deg,#16a34a,#22c55e)',
        'permission' => 'proforma_bills.php',
        'action' => 'can_create',
        'groups' => ['admin','sales'],
    ],
    [
        'title' => 'Payments',
        'subtitle' => 'Collection entries',
        'url' => 'payments.php',
        'icon' => 'indian-rupee',
        'color' => 'linear-gradient(135deg,#0f766e,#14b8a6)',
        'permission' => 'payments.php',
        'action' => 'can_view',
        'groups' => ['admin','sales'],
    ],
    [
        'title' => 'Dispatch',
        'subtitle' => 'Sales dispatch work',
        'url' => 'dispatch.php',
        'icon' => 'truck',
        'color' => 'linear-gradient(135deg,#475569,#0f172a)',
        'permission' => 'dispatch.php',
        'action' => 'can_view',
        'groups' => ['admin','sales'],
    ],
    [
        'title' => 'Approvals',
        'subtitle' => 'Customer proof approval',
        'url' => 'customer_approvals.php',
        'icon' => 'badge-check',
        'color' => 'linear-gradient(135deg,#8b5cf6,#a855f7)',
        'permission' => 'customer_approvals.php',
        'action' => 'can_view',
        'groups' => ['admin','sales','design'],
    ],
    [
        'title' => 'Job Cards / Tracking',
        'subtitle' => 'View job stage tracking',
        'url' => 'job_cards.php',
        'icon' => 'briefcase-business',
        'color' => 'linear-gradient(135deg,#ec4899,#f43f5e)',
        'permission' => 'job_cards.php',
        'action' => 'can_view',
        'groups' => ['admin','sales','design','printing','general'],
    ],
];

$visibleQuickActions = [];
foreach ($quickActions as $action) {
    if ($roleGroup !== 'admin' && !in_array($roleGroup, $action['groups'], true)) continue;
    if (!dash_can_page($conn, $action['action'], $action['permission'])) continue;
    $visibleQuickActions[] = $action;
}

// -----------------------------------------------------------------------------
// Attention cards
// -----------------------------------------------------------------------------
$attentionCards = [
    [
        'label' => 'Ready Dispatch',
        'value' => number_format($readyDispatch),
        'sub' => 'Sales team to dispatch',
        'url' => 'dispatch.php?filter=ready',
        'icon' => 'package-check',
        'class' => 'success',
        'groups' => ['admin','sales'],
    ],
    [
        'label' => 'Pending Approval',
        'value' => number_format($pendingApprovals),
        'sub' => 'Customer proof waiting',
        'url' => 'customer_approvals.php',
        'icon' => 'badge-check',
        'class' => 'warning',
        'groups' => ['admin','sales','design'],
    ],
    [
        'label' => 'Rework / Rejected',
        'value' => number_format($approvalRework),
        'sub' => 'Proof correction needed',
        'url' => 'customer_approvals.php',
        'icon' => 'rotate-ccw',
        'class' => 'danger',
        'groups' => ['admin','sales','design'],
    ],
    [
        'label' => 'Delayed Jobs',
        'value' => number_format($delayedJobs),
        'sub' => 'Crossed timeline',
        'url' => 'job_cards.php',
        'icon' => 'clock-alert',
        'class' => 'danger',
        'groups' => ['admin','sales','design','printing','general'],
    ],
    [
        'label' => 'Payment Balance',
        'value' => dash_short_money($pendingBalance),
        'sub' => 'Amount pending',
        'url' => 'proforma_bills.php',
        'icon' => 'wallet-cards',
        'class' => 'warning',
        'groups' => ['admin','sales'],
    ],
    [
        'label' => 'Due Today Jobs',
        'value' => number_format($dueTodayJobs),
        'sub' => 'Delivery scheduled today',
        'url' => 'job_cards.php',
        'icon' => 'calendar-clock',
        'class' => $dueTodayJobs > 0 ? 'warning' : 'success',
        'groups' => ['admin','sales','design','printing','general'],
    ],
    [
        'label' => 'WhatsApp Failed',
        'value' => number_format($whatsappFailed),
        'sub' => number_format($whatsappSentToday) . ' sent today',
        'url' => 'whatsapp-logs.php',
        'icon' => 'message-circle-warning',
        'class' => $whatsappFailed > 0 ? 'danger' : 'success',
        'groups' => ['admin','sales'],
    ],
];

$visibleAttentionCards = [];
foreach ($attentionCards as $card) {
    if ($roleGroup === 'admin' || in_array($roleGroup, $card['groups'], true)) {
        $visibleAttentionCards[] = $card;
    }
}
$visibleAttentionCards = array_slice($visibleAttentionCards, 0, 6);

// -----------------------------------------------------------------------------
// Role work queue
// -----------------------------------------------------------------------------
$workQueueTitle = 'My Work Queue';
$workQueueSubtitle = 'Priority jobs based on your role.';
$workQueue = [];

if ($roleGroup === 'sales' || $roleGroup === 'admin') {
    $workQueueTitle = 'Sales Work Queue';
    $workQueueSubtitle = 'Ready dispatch, pending approval and payment follow-up.';
    $workQueue = dash_fetch_all($conn, "
        SELECT
            'Ready Dispatch' AS queue_type,
            jc.job_card_no AS ref_no,
            jc.customer_name,
            jc.mobile,
            jc.product_name AS details,
            cws.step_name AS current_step,
            jc.delivery_date,
            jc.balance_amount,
            'dispatch.php?filter=ready' AS url
        FROM job_cards jc
        " . dash_join_status_filter() . "
        WHERE " . dash_ready_dispatch_where() . "
        ORDER BY COALESCE(jc.delivery_date, DATE(jc.created_at)) ASC, jc.id DESC
        LIMIT 8
    ");

    if (count($workQueue) < 8 && dash_table_exists($conn, 'customer_approvals')) {
        $more = dash_fetch_all($conn, "
            SELECT
                'Approval Pending' AS queue_type,
                jc.job_card_no AS ref_no,
                ca.customer_name,
                ca.mobile,
                ca.approval_type AS details,
                COALESCE(ws.step_name, 'Customer Approval') AS current_step,
                jc.delivery_date,
                jc.balance_amount,
                'customer_approvals.php' AS url
            FROM customer_approvals ca
            LEFT JOIN job_cards jc ON jc.id = ca.job_card_id
            LEFT JOIN workflow_steps ws ON ws.id = ca.workflow_step_id
            WHERE ca.status = 'pending'
            ORDER BY ca.id DESC
            LIMIT " . (8 - count($workQueue)) . "
        ");
        $workQueue = array_merge($workQueue, $more);
    }
} elseif ($roleGroup === 'design') {
    $workQueueTitle = 'Design / Proofing Queue';
    $workQueueSubtitle = 'Design and proof approval related jobs.';
    $workQueue = dash_fetch_all($conn, "
        SELECT
            'Design Work' AS queue_type,
            jc.job_card_no AS ref_no,
            jc.customer_name,
            jc.mobile,
            jc.product_name AS details,
            cws.step_name AS current_step,
            jc.delivery_date,
            jc.balance_amount,
            'job_cards.php' AS url
        FROM job_cards jc
        " . dash_join_status_filter() . "
        WHERE " . dash_active_job_where() . "
          AND jc.assigned_design_user_id = " . (int)$currentUserId . "
          AND (
                LOWER(COALESCE(cws.default_owner_role_key, '')) IN ('designing_proofing','design_proofing','designing','proofing','designer','designing_team')
             OR LOWER(COALESCE(cws.step_key, '')) IN ('proofing','proofing_approval','designing','design_approval','master_copy')
          )
        ORDER BY COALESCE(jc.delivery_date, DATE(jc.created_at)) ASC, jc.id DESC
        LIMIT 8
    ");
} elseif ($roleGroup === 'printing') {
    $workQueueTitle = 'Printing / Production Queue';
    $workQueueSubtitle = 'Jobs currently owned by printing and finishing stages.';
    $roleKeyEsc = $conn->real_escape_string($roleKey);
    $workQueue = dash_fetch_all($conn, "
        SELECT
            'Production Work' AS queue_type,
            jc.job_card_no AS ref_no,
            jc.customer_name,
            jc.mobile,
            jc.product_name AS details,
            cws.step_name AS current_step,
            jc.delivery_date,
            jc.balance_amount,
            'job_cards.php' AS url
        FROM job_cards jc
        " . dash_join_status_filter() . "
        WHERE " . dash_active_job_where() . "
          AND jc.assigned_printing_user_id = " . (int)$currentUserId . "
          AND (
                LOWER(COALESCE(cws.default_owner_role_key, '')) = '{$roleKeyEsc}'
             OR LOWER(COALESCE(cws.default_owner_role_key, '')) LIKE '%printing%'
             OR LOWER(COALESCE(cws.step_key, '')) IN ('master_copy_received','printing','print','plating','paper_board_selection','laminate','drying','cutting','packing','send_to_dispatch')
          )
        ORDER BY COALESCE(jc.delivery_date, DATE(jc.created_at)) ASC, jc.id DESC
        LIMIT 8
    ");
} else {
    $workQueue = dash_fetch_all($conn, "
        SELECT
            'Active Job' AS queue_type,
            jc.job_card_no AS ref_no,
            jc.customer_name,
            jc.mobile,
            jc.product_name AS details,
            cws.step_name AS current_step,
            jc.delivery_date,
            jc.balance_amount,
            'job_cards.php' AS url
        FROM job_cards jc
        " . dash_join_status_filter() . "
        WHERE " . dash_active_job_where() . "
        ORDER BY COALESCE(jc.delivery_date, DATE(jc.created_at)) ASC, jc.id DESC
        LIMIT 8
    ");
}

// -----------------------------------------------------------------------------
// Admin Design + Printing assignment monitor
// Personnel identity is exposed only inside this Admin-only dashboard block.
// Full record monitoring and filters remain available in job_cards.php.
// -----------------------------------------------------------------------------
$adminAssignments = [];
$adminAssignedDesignJobs = 0;
$adminUnassignedDesignJobs = 0;
$adminAssignedPrintingJobs = 0;
$adminUnassignedPrintingJobs = 0;

if ($roleGroup === 'admin' && dash_table_exists($conn, 'job_cards')) {
    $adminAssignedDesignJobs = dash_count($conn, 'job_cards', 'assigned_design_user_id IS NOT NULL');
    $adminUnassignedDesignJobs = dash_count($conn, 'job_cards', 'assigned_design_user_id IS NULL');
    $adminAssignedPrintingJobs = dash_count($conn, 'job_cards', 'assigned_printing_user_id IS NOT NULL');
    $adminUnassignedPrintingJobs = dash_count($conn, 'job_cards', 'printing_type_id IS NOT NULL AND assigned_printing_user_id IS NULL');

    $adminAssignments = dash_fetch_all($conn, "
        SELECT
            jc.id,
            jc.job_card_no,
            jc.customer_name,
            jc.product_name,
            jc.delivery_date,
            jc.is_delayed,
            pt.printing_name,
            COALESCE(NULLIF(du.name, ''), du.username, 'Unassigned') AS designer_name,
            du.username AS designer_username,
            COALESCE(NULLIF(pu.name, ''), pu.username, 'Unassigned') AS printer_name,
            pu.username AS printer_username,
            pr.role_name AS printer_role,
            cws.step_name AS current_step,
            jcs.status_name AS status_name
        FROM job_cards jc
        LEFT JOIN printing_types pt ON pt.id = jc.printing_type_id
        LEFT JOIN users du ON du.id = jc.assigned_design_user_id
        LEFT JOIN users pu ON pu.id = jc.assigned_printing_user_id
        LEFT JOIN roles pr ON pr.id = pu.role_id
        " . dash_join_status_filter() . "
        ORDER BY
            CASE WHEN jc.assigned_design_user_id IS NULL OR (jc.printing_type_id IS NOT NULL AND jc.assigned_printing_user_id IS NULL) THEN 0 ELSE 1 END ASC,
            jc.is_delayed DESC,
            jc.id DESC
        LIMIT 15
    ");
}

// -----------------------------------------------------------------------------
// Production stage summary - current step count only
// -----------------------------------------------------------------------------
$stageSummary = [];
$stageAssignmentWhere = '';
if ($roleGroup === 'printing') {
    $stageAssignmentWhere = ' AND jc.assigned_printing_user_id = ' . (int)$currentUserId;
} elseif ($roleGroup === 'design') {
    $stageAssignmentWhere = ' AND jc.assigned_design_user_id = ' . (int)$currentUserId;
}
if (dash_table_exists($conn, 'job_cards') && dash_table_exists($conn, 'workflow_steps')) {
    $stageSummary = dash_fetch_all($conn, "
        SELECT
            cws.step_key,
            cws.step_name,
            COALESCE(cws.default_owner_role_key, '') AS owner_role,
            COUNT(DISTINCT jc.id) AS total_jobs
        FROM job_cards jc
        " . dash_join_status_filter() . "
        WHERE " . dash_active_job_where() . "
        {$stageAssignmentWhere}
        GROUP BY cws.id, cws.step_key, cws.step_name, cws.default_owner_role_key, cws.sort_order
        ORDER BY cws.sort_order ASC, cws.step_name ASC
        LIMIT 12
    ");
}

$roleIntro = 'Overall company summary';
if ($roleGroup === 'sales') $roleIntro = 'Sales, payment, approval and dispatch summary';
elseif ($roleGroup === 'design') $roleIntro = 'Design, proofing and customer approval summary';
elseif ($roleGroup === 'printing') $roleIntro = 'Printing, finishing and production queue summary';
elseif ($roleGroup === 'general') $roleIntro = 'Your available ERP work summary';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dashboard - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
    .dashboard-page .dashboard-hero {
        padding: 28px;
        margin-bottom: 18px;
        overflow: hidden;
        position: relative;
        isolation: isolate
    }

    .dashboard-page .dashboard-hero:after {
        content: "";
        position: absolute;
        width: 280px;
        height: 280px;
        right: -90px;
        top: -100px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--brand-1) 18%, transparent);
        z-index: -1
    }

    .dashboard-page .dashboard-hero h1 {
        font-size: 32px;
        font-weight: 900;
        color: var(--text-main);
        margin-bottom: 6px;
        letter-spacing: -.4px
    }

    .dashboard-page .dashboard-hero p {
        color: var(--text-muted);
        font-weight: 700;
        margin: 0
    }

    .dashboard-date-chip,
    .dashboard-role-chip {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        padding: 10px 14px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--brand-1) 10%, var(--card-bg));
        color: var(--brand-1);
        font-size: 13px;
        font-weight: 900;
        border: 1px solid color-mix(in srgb, var(--brand-1) 20%, var(--border-soft))
    }

    .dashboard-role-chip {
        background: color-mix(in srgb, #16a34a 10%, var(--card-bg));
        color: #15803d;
        border-color: color-mix(in srgb, #16a34a 22%, var(--border-soft))
    }

    .dash-kpi-card {
        min-height: 128px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        position: relative;
        overflow: hidden
    }

    .dash-kpi-card:after {
        content: "";
        position: absolute;
        width: 110px;
        height: 110px;
        right: -36px;
        bottom: -42px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--brand-1) 8%, transparent)
    }

    .dash-kpi-icon {
        width: 54px;
        height: 54px;
        border-radius: 17px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto;
        box-shadow: 0 14px 30px rgba(15, 23, 42, .12)
    }

    .dash-kpi-icon svg {
        width: 25px;
        height: 25px
    }

    .dash-kpi-label {
        display: block;
        color: var(--text-muted);
        font-size: 12px;
        text-transform: uppercase;
        font-weight: 900;
        letter-spacing: .4px
    }

    .dash-kpi-value {
        display: block;
        color: var(--text-main);
        font-size: 25px;
        font-weight: 900;
        line-height: 1.15;
        margin-top: 4px
    }

    .dash-kpi-sub {
        color: var(--text-muted);
        display: block;
        margin-top: 4px;
        font-size: 12px;
        font-weight: 700
    }

    .dashboard-card {
        padding: 24px
    }

    .dashboard-card-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
        margin: 0
    }

    .quick-action-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
        gap: 14px
    }

    .quick-action-row .quick-action-btn {
        min-height: auto;
        flex-direction: row;
        align-items: center;
        justify-content: flex-start
    }

    .quick-action-row .quick-action-icon {
        width: 44px;
        height: 44px
    }

    .quick-action-btn {
        text-decoration: none;
        color: inherit;
        border: 1px solid var(--border-soft);
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        border-radius: 20px;
        padding: 16px;
        min-height: 118px;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 13px
    }

    .quick-action-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 18px 40px rgba(15, 23, 42, .12);
        border-color: color-mix(in srgb, var(--brand-1) 40%, var(--border-soft));
        color: inherit
    }

    .quick-action-icon {
        width: 46px;
        height: 46px;
        border-radius: 15px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto
    }

    .quick-action-title {
        font-size: 15px;
        font-weight: 900;
        color: var(--text-main);
        line-height: 1.2;
        margin: 0
    }

    .quick-action-subtitle {
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 700;
        margin-top: 4px;
        line-height: 1.35
    }

    .attention-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px
    }

    .attention-card {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 16px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        text-decoration: none;
        color: inherit;
        display: flex;
        gap: 13px;
        align-items: flex-start;
        transition: .18s
    }

    .attention-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 14px 35px rgba(15, 23, 42, .10);
        color: inherit
    }

    .attention-icon {
        width: 44px;
        height: 44px;
        border-radius: 15px;
        display: grid;
        place-items: center;
        flex: 0 0 auto
    }

    .attention-card.success .attention-icon {
        background: #dcfce7;
        color: #166534
    }

    .attention-card.warning .attention-icon {
        background: #fef3c7;
        color: #92400e
    }

    .attention-card.danger .attention-icon {
        background: #fee2e2;
        color: #991b1b
    }

    .attention-card strong {
        font-size: 22px;
        line-height: 1;
        font-weight: 900;
        color: var(--text-main)
    }

    .attention-card span {
        display: block;
        font-size: 12px;
        font-weight: 900;
        color: var(--text-muted);
        text-transform: uppercase
    }

    .attention-card small {
        display: block;
        margin-top: 3px;
        color: var(--text-muted);
        font-weight: 700
    }

    .queue-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 10px
    }

    .queue-table tr {
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg))
    }

    .queue-table td {
        padding: 14px 12px;
        border-top: 1px solid var(--border-soft);
        border-bottom: 1px solid var(--border-soft);
        vertical-align: middle
    }

    .queue-table td:first-child {
        border-left: 1px solid var(--border-soft);
        border-radius: 16px 0 0 16px
    }

    .queue-table td:last-child {
        border-right: 1px solid var(--border-soft);
        border-radius: 0 16px 16px 0
    }

    .queue-ref {
        font-weight: 900;
        color: var(--text-main)
    }

    .queue-meta {
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 700
    }

    .status-pill {
        font-size: 11px;
        font-weight: 900;
        border-radius: 999px;
        padding: 6px 10px;
        background: color-mix(in srgb, var(--brand-1) 12%, transparent);
        color: var(--brand-1);
        display: inline-flex;
        align-items: center;
        white-space: nowrap
    }

    .stage-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px
    }

    .stage-card {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 16px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        height: 100%
    }

    .stage-card h6 {
        font-weight: 900;
        color: var(--text-main);
        margin: 0 0 5px;
        font-size: 14px
    }

    .stage-card strong {
        font-size: 24px;
        font-weight: 900;
        color: var(--text-main)
    }

    .stage-card p {
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
        margin: 4px 0 0
    }

    .empty-box {
        border: 1px dashed var(--border-soft);
        border-radius: 18px;
        padding: 28px;
        text-align: center;
        color: var(--text-muted);
        font-weight: 800;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg))
    }

    .followup-reminder-modal .modal-dialog {
        max-width: 780px;
        width: calc(100% - 32px);
    }

    .followup-reminder-modal .modal-content {
        border: 0;
        border-radius: 24px;
        background: var(--card-bg);
        color: var(--text-main);
        box-shadow: 0 28px 80px rgba(15, 23, 42, .24);
        overflow: hidden;
    }

    .followup-reminder-modal .modal-header {
        border-bottom: 1px solid var(--border-soft);
        padding: 20px 22px 16px;
    }

    .followup-reminder-head-icon {
        width: 46px;
        height: 46px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        background: color-mix(in srgb, var(--brand-1) 12%, var(--card-bg));
        color: var(--brand-1);
        flex: 0 0 auto;
    }

    .followup-reminder-list {
        max-height: min(62vh, 560px);
        overflow-y: auto;
        padding: 14px 18px 4px;
    }

    .followup-reminder-item {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 15px;
        margin-bottom: 12px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
    }

    .followup-reminder-item.is-overdue {
        border-color: color-mix(in srgb, #dc2626 32%, var(--border-soft));
        background: color-mix(in srgb, #fee2e2 34%, var(--card-bg));
    }

    .followup-reminder-name {
        font-size: 15px;
        font-weight: 900;
        color: var(--text-main);
    }

    .followup-reminder-meta,
    .followup-reminder-remarks {
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
        line-height: 1.45;
    }

    .followup-reminder-remarks {
        color: var(--text-main);
        margin-top: 8px;
    }

    .followup-reminder-status {
        display: inline-flex;
        align-items: center;
        padding: 5px 9px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 900;
        white-space: nowrap;
        background: #dbeafe;
        color: #1d4ed8;
    }

    .followup-reminder-status.overdue {
        background: #fee2e2;
        color: #b91c1c;
    }

    .followup-schedule-box {
        display: none;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px dashed var(--border-soft);
    }

    .followup-schedule-box.is-open {
        display: block;
    }

    .followup-reminder-modal .modal-footer {
        border-top: 1px solid var(--border-soft);
        padding: 14px 18px 18px;
    }

    .followup-reminder-toast {
        position: fixed;
        top: 16px;
        right: 16px;
        z-index: 13000;
        max-width: 360px;
    }

    @media(max-width:1199.98px) {

        .quick-action-grid,
        .stage-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr))
        }

        .attention-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr))
        }
    }

    @media(max-width:991.98px) {

        .quick-action-grid,
        .stage-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr))
        }

        .dashboard-card {
            padding: 18px
        }
    }

    @media(max-width:767.98px) {
        .dashboard-page .dashboard-hero {
            padding: 20px;
            border-radius: 18px
        }

        .dashboard-page .dashboard-hero h1 {
            font-size: 25px
        }

        .dashboard-date-chip,
        .dashboard-role-chip {
            width: 100%;
            justify-content: center
        }

        .dash-kpi-card {
            min-height: auto;
            padding: 16px;
            border-radius: 18px
        }

        .dash-kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 15px
        }

        .dash-kpi-value {
            font-size: 22px
        }

        .quick-action-grid,
        .stage-grid,
        .attention-grid {
            grid-template-columns: 1fr
        }

        .quick-action-btn {
            min-height: auto;
            flex-direction: row;
            align-items: center;
            justify-content: flex-start;
            border-radius: 18px
        }

        .quick-action-icon {
            width: 48px;
            height: 48px
        }

        .queue-table,
        .queue-table tbody,
        .queue-table tr,
        .queue-table td {
            display: block;
            width: 100%
        }

        .queue-table tr {
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 13px;
            margin-bottom: 12px
        }

        .queue-table td {
            border: 0 !important;
            border-radius: 0 !important;
            padding: 5px 0
        }

        .queue-table td.text-end {
            text-align: left !important;
            margin-top: 8px
        }
    }

    @media(max-width:767.98px) {
        .followup-reminder-modal .modal-dialog {
            margin: 10px;
        }

        .followup-reminder-modal .modal-header {
            padding: 16px;
        }

        .followup-reminder-list {
            padding: 12px;
            max-height: 66vh;
        }

        .followup-reminder-item {
            padding: 13px;
            border-radius: 16px;
        }

        .followup-reminder-actions .btn {
            flex: 1 1 auto;
        }
    }

    @media(max-width:420px) {
        .dashboard-page .dashboard-hero h1 {
            font-size: 22px
        }

        .dashboard-card-title {
            font-size: 16px
        }
    }


    /* Dashboard compact sizing for comfortable 100% browser zoom */
    .dashboard-page .dashboard-hero {
        padding: 20px 22px;
        margin-bottom: 14px;
    }

    .dashboard-page .dashboard-hero:after {
        width: 220px;
        height: 220px;
        right: -72px;
        top: -78px;
    }

    .dashboard-page .dashboard-hero h1 {
        font-size: 26px;
        font-weight: 800;
        margin-bottom: 4px;
        letter-spacing: -.2px;
    }

    .dashboard-page .dashboard-hero p {
        font-size: 13px;
        font-weight: 600;
    }

    .dashboard-page .dashboard-date-chip,
    .dashboard-page .dashboard-role-chip {
        gap: 7px;
        padding: 8px 11px;
        font-size: 11.5px;
        font-weight: 700;
    }

    .dashboard-page .dash-kpi-card {
        min-height: 102px;
        padding: 14px 15px;
        gap: 11px;
    }

    .dashboard-page .dash-kpi-card:after {
        width: 88px;
        height: 88px;
        right: -30px;
        bottom: -34px;
    }

    .dashboard-page .dash-kpi-icon {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        box-shadow: 0 8px 18px rgba(15, 23, 42, .10);
    }

    .dashboard-page .dash-kpi-icon svg {
        width: 20px;
        height: 20px;
    }

    .dashboard-page .dash-kpi-label {
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: .25px;
    }

    .dashboard-page .dash-kpi-value {
        font-size: 20px;
        font-weight: 800;
        margin-top: 2px;
    }

    .dashboard-page .dash-kpi-sub {
        margin-top: 3px;
        font-size: 10.5px;
        font-weight: 600;
        line-height: 1.3;
    }

    .dashboard-page .dashboard-card {
        padding: 17px 18px;
    }

    .dashboard-page .dashboard-card-title {
        font-size: 15.5px;
        font-weight: 800;
    }

    .dashboard-page .dashboard-card .text-muted-custom {
        font-size: 11.5px;
        font-weight: 500;
    }

    .dashboard-page .quick-action-grid {
        grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
        gap: 10px;
    }

    .dashboard-page .quick-action-btn {
        border-radius: 15px;
        padding: 11px 12px;
        min-height: 86px;
        gap: 9px;
    }

    .dashboard-page .quick-action-row .quick-action-icon,
    .dashboard-page .quick-action-icon {
        width: 38px;
        height: 38px;
        border-radius: 12px;
    }

    .dashboard-page .quick-action-icon svg {
        width: 18px;
        height: 18px;
    }

    .dashboard-page .quick-action-title {
        font-size: 12.5px;
        font-weight: 700;
    }

    .dashboard-page .quick-action-subtitle {
        font-size: 10.5px;
        font-weight: 500;
        margin-top: 2px;
        line-height: 1.3;
    }

    .dashboard-page .attention-grid {
        gap: 10px;
    }

    .dashboard-page .attention-card {
        border-radius: 14px;
        padding: 11px 12px;
        gap: 9px;
    }

    .dashboard-page .attention-icon {
        width: 36px;
        height: 36px;
        border-radius: 12px;
    }

    .dashboard-page .attention-icon svg {
        width: 18px;
        height: 18px;
    }

    .dashboard-page .attention-card strong {
        font-size: 18px;
        font-weight: 800;
    }

    .dashboard-page .attention-card span {
        font-size: 10px;
        font-weight: 700;
    }

    .dashboard-page .attention-card small {
        margin-top: 2px;
        font-size: 10.5px;
        font-weight: 500;
        line-height: 1.25;
    }

    .dashboard-page .stage-grid {
        gap: 10px;
    }

    .dashboard-page .stage-card {
        border-radius: 14px;
        padding: 11px 12px;
    }

    .dashboard-page .stage-card h6 {
        font-size: 11.5px;
        font-weight: 700;
        margin-bottom: 3px;
    }

    .dashboard-page .stage-card strong {
        font-size: 18px;
        font-weight: 800;
    }

    .dashboard-page .stage-card p {
        margin-top: 2px;
        font-size: 10.5px;
        font-weight: 500;
    }

    .dashboard-page .queue-table {
        border-spacing: 0 7px;
    }

    .dashboard-page .queue-table td {
        padding: 9px 10px;
    }

    .dashboard-page .queue-table td:first-child {
        border-radius: 12px 0 0 12px;
    }

    .dashboard-page .queue-table td:last-child {
        border-radius: 0 12px 12px 0;
    }

    .dashboard-page .queue-ref {
        font-size: 12.5px;
        font-weight: 700;
    }

    .dashboard-page .queue-meta {
        font-size: 10.5px;
        font-weight: 500;
    }

    .dashboard-page .status-pill {
        font-size: 9.5px;
        font-weight: 700;
        padding: 4px 8px;
    }

    .dashboard-page .empty-box {
        border-radius: 14px;
        padding: 18px;
        font-size: 12px;
        font-weight: 600;
    }

    .dashboard-page .btn {
        font-size: 11.5px;
        font-weight: 700 !important;
    }

    .dashboard-page .btn.rounded-pill {
        padding-top: 6px;
        padding-bottom: 6px;
    }

    .dashboard-page>.row.g-3 {
        --bs-gutter-x: .8rem;
        --bs-gutter-y: .8rem;
    }

    @media(max-width:767.98px) {
        .dashboard-page .dashboard-hero {
            padding: 16px;
            margin-bottom: 12px;
        }

        .dashboard-page .dashboard-hero h1 {
            font-size: 22px;
        }

        .dashboard-page .dash-kpi-card {
            min-height: 92px;
            padding: 12px;
        }

        .dashboard-page .dash-kpi-icon {
            width: 40px;
            height: 40px;
        }

        .dashboard-page .dash-kpi-value {
            font-size: 18px;
        }

        .dashboard-page .dashboard-card {
            padding: 14px;
        }

        .dashboard-page .quick-action-btn {
            padding: 10px 11px;
            border-radius: 14px;
        }
    }
    </style>

    <style>
    /* Customer approved - continue Job Card reminder popup */
    .customer-approval-reminder-modal .modal-dialog {
        max-width: 780px;
    }

    .customer-approval-reminder-modal .modal-content {
        border: 0;
        border-radius: 22px;
        overflow: hidden;
        box-shadow: 0 28px 80px rgba(15, 23, 42, .20);
    }

    .customer-approval-reminder-modal .modal-header {
        padding: 18px 20px;
        border-bottom: 1px solid var(--border-soft);
        background: color-mix(in srgb, #22c55e 8%, var(--card-bg));
    }

    .customer-approval-reminder-head-icon {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 42px;
        color: #15803d;
        background: #dcfce7;
    }

    .customer-approval-reminder-head-icon svg {
        width: 20px;
        height: 20px;
    }

    .customer-approval-reminder-list {
        max-height: min(62vh, 540px);
        overflow-y: auto;
        padding: 12px;
        background: color-mix(in srgb, var(--body-bg) 54%, var(--card-bg));
    }

    .customer-approval-reminder-item {
        border: 1px solid #bbf7d0;
        border-radius: 16px;
        padding: 13px 14px;
        margin-bottom: 9px;
        background: color-mix(in srgb, #dcfce7 18%, var(--card-bg));
    }

    .customer-approval-reminder-item:last-child {
        margin-bottom: 0;
    }

    .customer-approval-reminder-name {
        font-size: 13px;
        font-weight: 800;
        color: var(--text-main);
    }

    .customer-approval-reminder-meta {
        margin-top: 2px;
        font-size: 11px;
        font-weight: 600;
        color: var(--text-muted);
    }

    .customer-approval-reminder-status {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        border-radius: 999px;
        padding: 4px 8px;
        font-size: 10px;
        font-weight: 800;
        white-space: nowrap;
        color: #166534;
        background: #dcfce7;
        border: 1px solid #86efac;
    }

    .customer-approval-reminder-next {
        margin-top: 9px;
        padding: 9px 11px;
        border-radius: 11px;
        border: 1px solid #bfdbfe;
        background: color-mix(in srgb, #dbeafe 40%, var(--card-bg));
        font-size: 11px;
        color: var(--text-main);
    }

    .customer-approval-reminder-next strong {
        color: #1d4ed8;
    }

    .customer-approval-reminder-remark {
        margin-top: 8px;
        padding: 8px 10px;
        border-radius: 10px;
        font-size: 11px;
        color: var(--text-main);
        background: color-mix(in srgb, var(--body-bg) 72%, var(--card-bg));
    }

    .customer-approval-reminder-modal .modal-footer {
        padding: 13px 18px;
        border-top: 1px solid var(--border-soft);
    }

    @media (max-width: 767.98px) {
        .customer-approval-reminder-modal .modal-dialog {
            margin: .75rem;
        }

        .customer-approval-reminder-modal .modal-header {
            padding: 15px;
        }

        .customer-approval-reminder-list {
            padding: 9px;
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

            <section class="page-section dashboard-page">
                <div class="card-ui dashboard-hero">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                        <div>
                            <h1>Welcome, <?= e($displayName) ?></h1>
                            <p><?= e($roleIntro) ?> for Subhiksha Cards ERP.</p>
                        </div>
                        <div class="d-flex flex-column flex-sm-row gap-2">
                            <div class="dashboard-role-chip"><i data-lucide="shield-check"></i><?= e($roleName) ?></div>
                            <div class="dashboard-date-chip"><i
                                    data-lucide="calendar-days"></i><?= e(date('d M Y, l')) ?></div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <?php foreach ($kpiCards as $card): ?>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card-ui dash-kpi-card h-100">
                            <div class="dash-kpi-icon" style="background:<?= e($card['color']) ?>"><i
                                    data-lucide="<?= e($card['icon']) ?>"></i></div>
                            <div>
                                <span class="dash-kpi-label"><?= e($card['label']) ?></span>
                                <span class="dash-kpi-value"><?= e($card['value']) ?></span>
                                <span class="dash-kpi-sub"><?= e($card['sub']) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($followupReminderCanView): ?>
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <div class="card-ui dashboard-card">
                            <div
                                class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
                                <div>
                                    <h2 class="dashboard-card-title">Follow-up Notifications</h2>
                                    <p class="text-muted-custom mb-0">
                                        Today's, overdue and upcoming customer follow-ups.
                                    </p>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php if ($followupReminderTotal > 0): ?>
                                    <button type="button" class="btn btn-outline-primary rounded-pill fw-bold px-4"
                                        data-bs-toggle="modal" data-bs-target="#todayFollowupReminderModal">
                                        <i data-lucide="bell-ring" style="width:16px;height:16px"></i>
                                        Open Reminder
                                        <span
                                            class="badge text-bg-danger ms-1"><?= number_format($dueFollowupNotificationCount) ?></span>
                                    </button>
                                    <?php endif; ?>
                                    <a href="followups.php" class="btn btn-primary rounded-pill fw-bold px-4">
                                        View Follow-ups
                                    </a>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <a href="followups.php?from_date=<?= e($today) ?>&to_date=<?= e($today) ?>"
                                        class="text-decoration-none">
                                        <div class="dash-kpi-card h-100">
                                            <div class="dash-kpi-icon"
                                                style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                                <i data-lucide="phone-call"></i>
                                            </div>
                                            <div>
                                                <span class="dash-kpi-label">Today Follow-ups</span>
                                                <span
                                                    class="dash-kpi-value"><?= number_format($todayFollowupCount) ?></span>
                                                <span class="dash-kpi-sub">Calls / callbacks due today</span>
                                            </div>
                                        </div>
                                    </a>
                                </div>

                                <div class="col-12 col-md-4">
                                    <a href="followups.php?to_date=<?= e(date('Y-m-d', strtotime('-1 day'))) ?>"
                                        class="text-decoration-none">
                                        <div class="dash-kpi-card h-100">
                                            <div class="dash-kpi-icon"
                                                style="background:linear-gradient(135deg,#dc2626,#f97316)">
                                                <i data-lucide="clock-alert"></i>
                                            </div>
                                            <div>
                                                <span class="dash-kpi-label">Overdue Follow-ups</span>
                                                <span
                                                    class="dash-kpi-value"><?= number_format($overdueFollowupCount) ?></span>
                                                <span class="dash-kpi-sub">Pending follow-ups from earlier dates</span>
                                            </div>
                                        </div>
                                    </a>
                                </div>

                                <div class="col-12 col-md-4">
                                    <a href="followups.php?from_date=<?= e(date('Y-m-d', strtotime('+1 day'))) ?>"
                                        class="text-decoration-none">
                                        <div class="dash-kpi-card h-100">
                                            <div class="dash-kpi-icon"
                                                style="background:linear-gradient(135deg,#2563eb,#7c3aed)">
                                                <i data-lucide="calendar-clock"></i>
                                            </div>
                                            <div>
                                                <span class="dash-kpi-label">Upcoming Callbacks</span>
                                                <span
                                                    class="dash-kpi-value"><?= number_format($upcomingFollowupCount) ?></span>
                                                <span class="dash-kpi-sub">Scheduled after today</span>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>


                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <div class="card-ui dashboard-card">
                            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                                <div>
                                    <h2 class="dashboard-card-title">Quick Actions</h2>
                                    <p class="text-muted-custom mb-0">Shortcuts allowed for your role.</p>
                                </div>
                                <i data-lucide="zap"></i>
                            </div>

                            <?php if (!$visibleQuickActions): ?>
                            <div class="empty-box">No quick actions available for this role.</div>
                            <?php else: ?>
                            <div class="quick-action-grid quick-action-row">
                                <?php foreach ($visibleQuickActions as $action): ?>
                                <a href="<?= e($action['url']) ?>" class="quick-action-btn">
                                    <div class="quick-action-icon" style="background:<?= e($action['color']) ?>"><i
                                            data-lucide="<?= e($action['icon']) ?>"></i></div>
                                    <div>
                                        <h3 class="quick-action-title"><?= e($action['title']) ?></h3>
                                        <div class="quick-action-subtitle"><?= e($action['subtitle']) ?></div>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($roleGroup === 'admin'): ?>
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <div class="card-ui dashboard-card">
                            <div
                                class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
                                <div>
                                    <h2 class="dashboard-card-title">Design &amp; Printing Assignment Monitor</h2>
                                    <p class="text-muted-custom mb-0">Admin-only overview of assigned designer, printing
                                        person, current stage and delivery.</p>
                                </div>
                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <span class="status-pill">Design Assigned:
                                        <?= number_format($adminAssignedDesignJobs) ?></span>
                                    <span
                                        class="status-pill <?= $adminUnassignedDesignJobs > 0 ? 'text-danger' : '' ?>">Design
                                        Unassigned:
                                        <?= number_format($adminUnassignedDesignJobs) ?></span>
                                    <span class="status-pill">Print Assigned:
                                        <?= number_format($adminAssignedPrintingJobs) ?></span>
                                    <span
                                        class="status-pill <?= $adminUnassignedPrintingJobs > 0 ? 'text-danger' : '' ?>">Print
                                        Unassigned:
                                        <?= number_format($adminUnassignedPrintingJobs) ?></span>
                                    <a href="job_cards.php"
                                        class="btn btn-sm btn-primary rounded-pill fw-bold px-3">View All Job Cards</a>
                                </div>
                            </div>

                            <?php if (!$adminAssignments): ?>
                            <div class="empty-box">No Job Card assignments found.</div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="queue-table">
                                    <thead>
                                        <tr>
                                            <th>Job Card</th>
                                            <th>Designer</th>
                                            <th>Printing / Person</th>
                                            <th>Current Stage</th>
                                            <th>Delivery</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($adminAssignments as $assignment): ?>
                                        <tr>
                                            <td>
                                                <div class="queue-ref"><?= e($assignment['job_card_no'] ?? '-') ?></div>
                                                <div class="queue-meta"><?= e($assignment['customer_name'] ?? '-') ?> |
                                                    <?= e($assignment['product_name'] ?? '-') ?></div>
                                            </td>
                                            <td>
                                                <?php if (!empty($assignment['designer_username'])): ?>
                                                <div class="queue-ref"><?= e($assignment['designer_name'] ?? '-') ?>
                                                </div>
                                                <?php else: ?>
                                                <span class="badge text-bg-warning">Unassigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="queue-ref"><?= e($assignment['printing_name'] ?? '-') ?>
                                                </div>
                                                <?php if (!empty($assignment['printer_username'])): ?>
                                                <div class="queue-meta">
                                                    <?= e($assignment['printer_name'] ?? '-') ?><?= !empty($assignment['printer_role']) ? ' — ' . e($assignment['printer_role']) : '' ?>
                                                </div>
                                                <?php elseif (!empty($assignment['printing_name'])): ?>
                                                <span class="badge text-bg-warning">Unassigned</span>
                                                <?php else: ?>
                                                <span class="queue-meta">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span
                                                    class="status-pill"><?= e($assignment['current_step'] ?? '-') ?></span>
                                                <div class="queue-meta mt-1">
                                                    <?= e($assignment['status_name'] ?? '-') ?><?= !empty($assignment['is_delayed']) ? ' | Delayed' : '' ?>
                                                </div>
                                            </td>
                                            <td><?= e(dash_date($assignment['delivery_date'] ?? null)) ?></td>
                                            <td class="text-end">
                                                <a href="job_card_view.php?id=<?= (int)$assignment['id'] ?>"
                                                    class="btn btn-sm btn-outline-primary rounded-pill fw-bold px-3">Open</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-xl-8">
                        <div class="card-ui dashboard-card h-100">
                            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                                <div>
                                    <h2 class="dashboard-card-title">Attention Required</h2>
                                    <p class="text-muted-custom mb-0">Important pending work for today.</p>
                                </div>
                                <i data-lucide="bell-ring"></i>
                            </div>

                            <?php if (!$visibleAttentionCards): ?>
                            <div class="empty-box">No important pending work found.</div>
                            <?php else: ?>
                            <div class="attention-grid">
                                <?php foreach ($visibleAttentionCards as $card): ?>
                                <a href="<?= e($card['url']) ?>" class="attention-card <?= e($card['class']) ?>">
                                    <div class="attention-icon"><i data-lucide="<?= e($card['icon']) ?>"></i></div>
                                    <div>
                                        <span><?= e($card['label']) ?></span>
                                        <strong><?= e($card['value']) ?></strong>
                                        <small><?= e($card['sub']) ?></small>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12 col-xl-4">
                        <div class="card-ui dashboard-card h-100">
                            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                                <div>
                                    <h2 class="dashboard-card-title">Today Summary</h2>
                                    <p class="text-muted-custom mb-0">Business activity snapshot.</p>
                                </div>
                                <i data-lucide="layout-dashboard"></i>
                            </div>
                            <div class="row g-3">
                                <div class="col-6">
                                    <div class="stage-card">
                                        <h6>Quotations</h6><strong><?= number_format($todayQuotations) ?></strong>
                                        <p>Created today</p>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stage-card">
                                        <h6>Proforma</h6><strong><?= number_format($todayProforma) ?></strong>
                                        <p>Created today</p>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stage-card">
                                        <h6>Customers</h6><strong><?= number_format($totalCustomers) ?></strong>
                                        <p>Active records</p>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stage-card">
                                        <h6>Month Collection</h6>
                                        <strong><?= e(dash_short_money($monthCollection)) ?></strong>
                                        <p>This month</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-xl-8">
                        <div class="card-ui dashboard-card h-100">
                            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                                <div>
                                    <h2 class="dashboard-card-title"><?= e($workQueueTitle) ?></h2>
                                    <p class="text-muted-custom mb-0"><?= e($workQueueSubtitle) ?></p>
                                </div>
                                <i data-lucide="list-checks"></i>
                            </div>

                            <?php if (!$workQueue): ?>
                            <div class="empty-box">No priority work found for your role.</div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="queue-table">
                                    <tbody>
                                        <?php foreach ($workQueue as $row): ?>
                                        <tr>
                                            <td>
                                                <div class="queue-ref"><?= e($row['ref_no'] ?: '-') ?></div>
                                                <div class="queue-meta"><?= e($row['customer_name'] ?: '-') ?> |
                                                    <?= e($row['mobile'] ?: '-') ?></div>
                                            </td>
                                            <td>
                                                <span class="status-pill"><?= e($row['queue_type'] ?: '-') ?></span>
                                                <div class="queue-meta mt-1"><?= e($row['current_step'] ?: '-') ?></div>
                                            </td>
                                            <td>
                                                <div class="queue-ref"><?= e($row['details'] ?: '-') ?></div>
                                                <div class="queue-meta">Delivery:
                                                    <?= e(dash_date($row['delivery_date'] ?? null)) ?> | Balance:
                                                    <?= e(dash_money($row['balance_amount'] ?? 0)) ?></div>
                                            </td>
                                            <td class="text-end"><a href="<?= e($row['url'] ?: 'job_cards.php') ?>"
                                                    class="btn btn-sm btn-outline-primary rounded-pill fw-bold px-3">Open</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
    </div>
    </section>
    </main>
    <div id="settingsOverlay"></div>
    <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <?php if ($customerApprovalReminderTotal > 0): ?>
    <div class="modal fade customer-approval-reminder-modal" id="customerApprovalReminderModal" tabindex="-1"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header align-items-start">
                    <div class="d-flex gap-3 align-items-start">
                        <div class="customer-approval-reminder-head-icon">
                            <i data-lucide="circle-check-big"></i>
                        </div>

                        <div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <h5 class="modal-title mb-0" style="font-weight:900">
                                    Customer Approved - Continue Job Card
                                </h5>

                                <span class="badge rounded-pill text-bg-success">
                                    <?= number_format($customerApprovalReminderTotal) ?>
                                </span>
                            </div>

                            <small class="text-muted-custom">
                                Customer approval is completed. Please continue the next Job Card stage.
                            </small>
                        </div>
                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="customer-approval-reminder-list">
                    <?php foreach ($customerApprovalReminderRows as $approvalReminder): ?>
                    <?php
                        $jobCardReminderId = (int)($approvalReminder['job_card_id'] ?? 0);
                        $approvedAt = $approvalReminder['approved_at']
                            ?? $approvalReminder['approval_updated_at']
                            ?? null;

                        $approvedMethod = (int)($approvalReminder['approved_by_customer'] ?? 0) === 1
                            ? 'Customer Approved Online'
                            : ((int)($approvalReminder['approved_by_call'] ?? 0) === 1
                                ? 'Approval Confirmed by Call'
                                : 'Approved');
                    ?>

                    <div class="customer-approval-reminder-item">
                        <div class="d-flex justify-content-between gap-3 align-items-start">
                            <div>
                                <div class="customer-approval-reminder-name">
                                    <?= e($approvalReminder['job_card_no'] ?? '-') ?> ·
                                    <?= e($approvalReminder['customer_name'] ?? '-') ?>
                                </div>

                                <div class="customer-approval-reminder-meta">
                                    <?= e($approvalReminder['mobile'] ?? '-') ?> ·
                                    <?= e($approvalReminder['approval_step_name'] ?? 'Customer Approval') ?>
                                </div>

                                <div class="customer-approval-reminder-meta">
                                    Order: <?= e(ucwords((string)($approvalReminder['order_type'] ?? '-'))) ?> ·
                                    Designer: <?= e($approvalReminder['designer_name'] ?? '-') ?> ·
                                    Delivery: <?= e(dash_date($approvalReminder['delivery_date'] ?? null)) ?>
                                </div>

                                <div class="customer-approval-reminder-meta">
                                    Approved: <?= e(dash_datetime_ist($approvedAt)) ?>
                                </div>
                            </div>

                            <span class="customer-approval-reminder-status">
                                <i data-lucide="check" style="width:12px;height:12px"></i>
                                <?= e($approvedMethod) ?>
                            </span>
                        </div>

                        <div class="customer-approval-reminder-next">
                            <strong>Next Stage:</strong>
                            <?= e($approvalReminder['next_step_name'] ?? '-') ?>
                            <span class="text-muted-custom">
                                · Waiting for Designing / Proofing team to continue.
                            </span>
                        </div>

                        <?php
                            $approvalRemark = trim((string)($approvalReminder['customer_remarks'] ?? ''));
                            if ($approvalRemark === '') {
                                $approvalRemark = trim((string)($approvalReminder['internal_remarks'] ?? ''));
                            }
                        ?>

                        <?php if ($approvalRemark !== ''): ?>
                        <div class="customer-approval-reminder-remark">
                            <strong>Customer Remark:</strong> <?= e($approvalRemark) ?>
                        </div>
                        <?php endif; ?>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <a href="job_card_view.php?id=<?= $jobCardReminderId ?>"
                                class="btn btn-sm btn-success rounded-pill fw-bold px-3">
                                <i data-lucide="arrow-right-circle" style="width:15px;height:15px"></i>
                                Open & Continue Job Card
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="modal-footer justify-content-between">
                    <small class="text-muted-custom">
                        This reminder automatically disappears after the next workflow stage is started.
                    </small>

                    <?php if ($customerApprovalQueueCanView): ?>
                    <a href="customer_approvals.php" class="btn btn-outline-success rounded-pill fw-bold px-4">
                        Approval History
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($followupReminderTotal > 0): ?>
    <div class="modal fade followup-reminder-modal" id="todayFollowupReminderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header align-items-start">
                    <div class="d-flex gap-3 align-items-start">
                        <div class="followup-reminder-head-icon"><i data-lucide="bell-ring"></i></div>
                        <div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <h5 class="modal-title fw-black mb-0" style="font-weight:900">Today's Follow-up Reminder
                                </h5>
                                <span class="badge rounded-pill text-bg-warning"
                                    id="followupReminderCount"><?= number_format($followupReminderTotal) ?></span>
                            </div>
                            <small class="text-muted-custom">
                                <?= $followupReminderOverdue > 0 ? number_format($followupReminderOverdue) . ' overdue · ' : '' ?>
                                Complete the call or schedule the next callback.
                            </small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="followup-reminder-list" id="followupReminderList">
                    <?php foreach ($followupReminderRows as $reminder): ?>
                    <?php
                        $reminderAt = (string)($reminder['reminder_at'] ?? '');
                        $isOverdue = $reminderAt !== '' && date('Y-m-d', strtotime($reminderAt)) < $today;
                        $followupId = (int)($reminder['id'] ?? 0);
                    ?>
                    <div class="followup-reminder-item <?= $isOverdue ? 'is-overdue' : '' ?>"
                        data-followup-id="<?= $followupId ?>">
                        <div class="d-flex justify-content-between gap-2 align-items-start">
                            <div>
                                <div class="followup-reminder-name"><?= e($reminder['customer_name'] ?? '-') ?></div>
                                <div class="followup-reminder-meta">
                                    <?= e($reminder['mobile'] ?? '-') ?> ·
                                    <?= e($reminderAt !== '' ? date('d-m-Y h:i A', strtotime($reminderAt)) : '-') ?>
                                </div>
                                <div class="followup-reminder-meta">Enquiry: <?= e($reminder['enquiry_no'] ?? '-') ?>
                                </div>
                            </div>
                            <span class="followup-reminder-status <?= $isOverdue ? 'overdue' : '' ?>">
                                <?= $isOverdue ? 'Overdue' : 'Due Today' ?>
                            </span>
                        </div>

                        <div class="followup-reminder-remarks">
                            <strong>Remarks:</strong> <?= e($reminder['call_remarks'] ?? '-') ?>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3 followup-reminder-actions">
                            <a href="followups.php?focus=<?= $followupId ?>"
                                class="btn btn-sm btn-outline-primary rounded-pill fw-bold px-3">View</a>
                            <?php if ($followupReminderCanModify): ?>
                            <button type="button"
                                class="btn btn-sm btn-outline-secondary rounded-pill fw-bold px-3 js-open-followup-schedule"
                                data-id="<?= $followupId ?>">
                                Schedule Next
                            </button>
                            <button type="button"
                                class="btn btn-sm btn-success rounded-pill fw-bold px-3 js-complete-dashboard-followup"
                                data-id="<?= $followupId ?>">
                                <i data-lucide="check" style="width:14px;height:14px"></i> Complete
                            </button>
                            <?php endif; ?>
                        </div>

                        <?php if ($followupReminderCanModify): ?>
                        <div class="followup-schedule-box" id="followupScheduleBox<?= $followupId ?>">
                            <label class="form-label fw-bold small">Next Callback Date & Time</label>
                            <div class="d-flex flex-column flex-sm-row gap-2">
                                <input type="datetime-local" class="form-control form-control-sm js-followup-next-time"
                                    data-id="<?= $followupId ?>" min="<?= e(date('Y-m-d\TH:i')) ?>">
                                <button type="button"
                                    class="btn btn-sm btn-primary rounded-pill fw-bold px-3 js-save-followup-schedule"
                                    data-id="<?= $followupId ?>">Save</button>
                                <button type="button"
                                    class="btn btn-sm btn-outline-secondary rounded-pill fw-bold px-3 js-cancel-followup-schedule"
                                    data-id="<?= $followupId ?>">Cancel</button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="modal-footer justify-content-between">
                    <small class="text-muted-custom">Closing this popup only dismisses the reminder. It does not
                        complete the follow-up.</small>
                    <a href="followups.php" class="btn btn-primary rounded-pill fw-bold px-4">View All Follow-ups</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/includes/script.php'; ?>
    <script>
    (function() {
        function refreshIcons() {
            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        }

        refreshIcons();

        const approvalReminderModalEl = document.getElementById('customerApprovalReminderModal');
        const reminderModalEl = document.getElementById('todayFollowupReminderModal');
        const reminderList = document.getElementById('followupReminderList');
        const reminderCount = document.getElementById('followupReminderCount');
        const csrfToken = <?= json_encode($followupReminderCsrf, JSON_UNESCAPED_SLASHES) ?>;

        function showReminderToast(message, type) {
            const old = document.getElementById('followupReminderToast');
            if (old) old.remove();

            const wrap = document.createElement('div');
            wrap.id = 'followupReminderToast';
            wrap.className = 'alert ' + (type === 'danger' ? 'alert-danger' : 'alert-success') +
                ' followup-reminder-toast shadow';
            wrap.setAttribute('role', 'alert');
            wrap.innerHTML = '<div class="d-flex justify-content-between gap-3 align-items-start"><strong>' +
                String(message || '') +
                '</strong><button type="button" class="btn-close" aria-label="Close"></button></div>';
            wrap.querySelector('.btn-close')?.addEventListener('click', () => wrap.remove());
            document.body.appendChild(wrap);
            setTimeout(() => wrap.remove(), 3800);
        }

        function apiAction(payload) {
            const formData = new FormData();
            Object.entries(payload).forEach(([key, value]) => formData.append(key, value == null ? '' : String(
                value)));
            formData.append('csrf_token', csrfToken);

            return fetch('api/followups.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            }).then(async response => {
                const data = await response.json();
                if (!data.status) throw new Error(data.message || 'Follow-up update failed.');
                return data;
            });
        }

        function removeReminder(id) {
            reminderList?.querySelector('[data-followup-id="' + id + '"]')?.remove();
            const remaining = reminderList ? reminderList.querySelectorAll('[data-followup-id]').length : 0;
            if (reminderCount) reminderCount.textContent = String(remaining);
            if (remaining === 0 && reminderModalEl && window.bootstrap && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(reminderModalEl).hide();
            }
        }

        document.querySelectorAll('.js-complete-dashboard-followup').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.dataset.id || '0';
                if (!confirm('Mark this follow-up as completed?')) return;
                this.disabled = true;
                apiAction({
                        action: 'complete_reminder',
                        id
                    })
                    .then(data => {
                        removeReminder(id);
                        showReminderToast(data.message || 'Follow-up completed.', 'success');
                    })
                    .catch(error => {
                        this.disabled = false;
                        showReminderToast(error.message || 'Unable to complete follow-up.',
                            'danger');
                    });
            });
        });

        document.querySelectorAll('.js-open-followup-schedule').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.dataset.id || '0';
                document.getElementById('followupScheduleBox' + id)?.classList.add('is-open');
            });
        });

        document.querySelectorAll('.js-cancel-followup-schedule').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.dataset.id || '0';
                document.getElementById('followupScheduleBox' + id)?.classList.remove('is-open');
            });
        });

        document.querySelectorAll('.js-save-followup-schedule').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.dataset.id || '0';
                const input = document.querySelector('.js-followup-next-time[data-id="' + id +
                    '"]');
                const nextTime = input?.value || '';
                if (!nextTime) {
                    showReminderToast('Select the next callback date and time.', 'danger');
                    input?.focus();
                    return;
                }

                this.disabled = true;
                apiAction({
                        action: 'schedule_next',
                        id,
                        next_callback_at: nextTime
                    })
                    .then(data => {
                        removeReminder(id);
                        showReminderToast(data.message || 'Next callback scheduled.',
                            'success');
                    })
                    .catch(error => {
                        this.disabled = false;
                        showReminderToast(error.message || 'Unable to schedule callback.',
                            'danger');
                    });
            });
        });

        const autoShowApprovalReminder = <?= $showCustomerApprovalReminder ? 'true' : 'false' ?>;
        const autoShowReminder = <?= $showFollowupReminder ? 'true' : 'false' ?>;

        function showFollowupReminderModal() {
            if (!autoShowReminder || !reminderModalEl || !window.bootstrap || !bootstrap.Modal) {
                return;
            }

            bootstrap.Modal.getOrCreateInstance(reminderModalEl, {
                backdrop: true,
                keyboard: true
            }).show();
            refreshIcons();
        }

        if (
            (autoShowApprovalReminder && approvalReminderModalEl) ||
            (autoShowReminder && reminderModalEl)
        ) {
            window.addEventListener('load', function() {
                setTimeout(function() {
                    if (
                        autoShowApprovalReminder &&
                        approvalReminderModalEl &&
                        window.bootstrap &&
                        bootstrap.Modal
                    ) {
                        const approvalModal = bootstrap.Modal.getOrCreateInstance(
                            approvalReminderModalEl, {
                                backdrop: true,
                                keyboard: true
                            });

                        if (autoShowReminder && reminderModalEl) {
                            approvalReminderModalEl.addEventListener('hidden.bs.modal', function() {
                                setTimeout(showFollowupReminderModal, 180);
                            }, {
                                once: true
                            });
                        }

                        approvalModal.show();
                        refreshIcons();
                    } else {
                        showFollowupReminderModal();
                    }
                }, 280);
            });
        }
    })();
    </script>
</body>

</html>