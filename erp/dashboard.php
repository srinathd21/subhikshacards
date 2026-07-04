<?php
/**
 * dashboard.php
 * Subhiksha Cards ERP - Role Based Live Dashboard
 *
 * Flow used:
 * Enquiry -> Quotation -> Proforma Bill -> Payment -> Job Card -> Tracking -> Dispatch
 *
 * Notes:
 * - Recent Activity section removed as requested.
 * - Sales team handles dispatch work in this setup.
 * - Counts are taken from actual ERP tables with safe fallbacks.
 */

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'dashboard.php');

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

function dash_money($amount): string
{
    return '₹' . number_format((float)$amount, 2);
}

function dash_short_money($amount): string
{
    $amount = (float)$amount;
    if (abs($amount) >= 10000000) return '₹' . number_format($amount / 10000000, 2) . ' Cr';
    if (abs($amount) >= 100000) return '₹' . number_format($amount / 100000, 2) . ' L';
    return dash_money($amount);
}

function dash_date($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
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
$displayName = trim((string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'User')) ?: 'User';
$today = date('Y-m-d');

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

if (dash_table_exists($conn, 'job_cards')) {
    $activeJobs = (int)dash_scalar($conn, "
        SELECT COUNT(DISTINCT jc.id)
        FROM job_cards jc
        " . dash_join_status_filter() . "
        WHERE " . dash_active_job_where() . "
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

$googleReviewLink = '';
if (dash_table_exists($conn, 'system_settings')) {
    $googleReviewLink = (string)dash_scalar($conn, "
        SELECT COALESCE(setting_value, '')
        FROM system_settings
        WHERE setting_key IN ('google_review_link','google_review_url')
        ORDER BY FIELD(setting_key, 'google_review_link', 'google_review_url')
        LIMIT 1
    ", '');
}
$reviewLinkReady = trim($googleReviewLink) !== '';
$reviewPendingJobs = 0;
if (dash_table_exists($conn, 'job_cards')) {
    $reviewPendingJobs = (int)dash_scalar($conn, "
        SELECT COUNT(DISTINCT jc.id)
        FROM job_cards jc
        " . dash_join_status_filter() . "
        LEFT JOIN review_link_logs rll ON rll.job_card_id = jc.id AND rll.sent_status = 'sent'
        WHERE " . dash_completed_job_where() . "
          AND rll.id IS NULL
    ", 0);
}

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
        'label' => 'Google Review Link',
        'value' => $reviewLinkReady ? 'Ready' : 'Pending',
        'sub' => $reviewLinkReady ? number_format($reviewPendingJobs) . ' jobs need review message' : 'Waiting for customer link',
        'url' => 'google-review-settings.php',
        'icon' => 'star',
        'class' => $reviewLinkReady ? 'success' : 'warning',
        'groups' => ['admin','sales'],
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
          AND (
                LOWER(COALESCE(cws.default_owner_role_key, '')) = 'designing_proofing'
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
// Production stage summary - current step count only
// -----------------------------------------------------------------------------
$stageSummary = [];
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

    @media(max-width:420px) {
        .dashboard-page .dashboard-hero h1 {
            font-size: 22px
        }

        .dashboard-card-title {
            font-size: 16px
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

    <?php include __DIR__ . '/includes/script.php'; ?>
    <script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
    </script>
</body>

</html>