<?php
/**
 * dashboard.php
 * Subhiksha Cards ERP - Responsive Dashboard with Quick Actions
 *
 * Features:
 * - Reference UI layout
 * - Responsive KPI cards
 * - Quick action buttons
 * - Mobile card layout
 * - Safe table/count fallbacks
 */

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'dashboard.php');

function dash_table_exists(mysqli $conn, string $table): bool
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

function dash_count(mysqli $conn, string $table, string $where = '1=1'): int
{
    try {
        if (!dash_table_exists($conn, $table)) {
            return 0;
        }

        $sql = "SELECT COUNT(*) AS total FROM `{$table}` WHERE {$where}";
        $res = $conn->query($sql);
        if (!$res) {
            return 0;
        }

        $row = $res->fetch_assoc();
        $res->free();

        return (int)($row['total'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function dash_sum(mysqli $conn, string $table, string $column, string $where = '1=1'): float
{
    try {
        if (!dash_table_exists($conn, $table)) {
            return 0;
        }

        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);

        $sql = "SELECT COALESCE(SUM(`{$column}`),0) AS total FROM `{$table}` WHERE {$where}";
        $res = $conn->query($sql);
        if (!$res) {
            return 0;
        }

        $row = $res->fetch_assoc();
        $res->free();

        return (float)($row['total'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function dash_latest_rows(mysqli $conn, string $table, array $columns, int $limit = 5): array
{
    try {
        if (!dash_table_exists($conn, $table)) {
            return [];
        }

        $safeColumns = [];
        foreach ($columns as $column) {
            $safeColumns[] = '`' . preg_replace('/[^a-zA-Z0-9_]/', '', $column) . '`';
        }

        if (!$safeColumns) {
            return [];
        }

        $orderColumn = 'id';
        $sql = "SELECT " . implode(',', $safeColumns) . " FROM `{$table}` ORDER BY `{$orderColumn}` DESC LIMIT " . (int)$limit;
        $res = $conn->query($sql);
        if (!$res) {
            return [];
        }

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

function dash_money(float $amount): string
{
    return '₹' . number_format($amount, 2);
}

$today = date('Y-m-d');

$totalUsers = dash_count($conn, 'users', 'is_active = 1');
$totalRoles = dash_count($conn, 'roles', 'is_active = 1');
$totalMenus = dash_count($conn, 'sidebar_items', 'is_active = 1');
$totalLogs = dash_count($conn, 'activity_logs');

$totalCustomers = dash_count($conn, 'customers', '1=1');
$totalOrders = dash_count($conn, 'orders', '1=1');
$totalJobCards = dash_count($conn, 'job_cards', '1=1');
$totalApprovals = dash_count($conn, 'customer_approvals', '1=1');

$todayOrders = dash_count($conn, 'orders', "DATE(created_at) = '{$today}'");
$pendingApprovals = dash_count($conn, 'customer_approvals', "status IN ('pending','Pending','PENDING')");
$productionJobs = dash_count($conn, 'job_cards', "status NOT IN ('completed','Completed','COMPLETED','cancelled','Cancelled','CANCELLED')");

$totalSalesAmount = 0;
if (dash_table_exists($conn, 'sales')) {
    $totalSalesAmount = dash_sum($conn, 'sales', 'total_amount');
} elseif (dash_table_exists($conn, 'orders')) {
    $totalSalesAmount = dash_sum($conn, 'orders', 'total_amount');
}

$recentLogs = [];
if (dash_table_exists($conn, 'activity_logs')) {
    try {
        $res = $conn->query("
            SELECT
                al.action_key,
                al.module_name,
                al.description,
                al.created_at,
                u.name AS user_name
            FROM activity_logs al
            LEFT JOIN users u ON u.id = al.user_id
            ORDER BY al.id DESC
            LIMIT 6
        ");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $recentLogs[] = $row;
            }
            $res->free();
        }
    } catch (Throwable $e) {
        $recentLogs = [];
    }
}

$quickActions = [
    [
        'title' => 'New Order',
        'subtitle' => 'Create customer order',
        'url' => 'orders-create.php',
        'icon' => 'shopping-cart',
        'color' => 'linear-gradient(135deg,#2563eb,#0ea5e9)',
        'permission' => 'orders-create.php',
        'action' => 'can_create',
    ],
    [
        'title' => 'Customer',
        'subtitle' => 'Add / view customers',
        'url' => 'customers.php',
        'icon' => 'users',
        'color' => 'linear-gradient(135deg,#16a34a,#22c55e)',
        'permission' => 'customers.php',
        'action' => 'can_view',
    ],
    [
        'title' => 'Production',
        'subtitle' => 'Track job progress',
        'url' => 'production-tracking.php',
        'icon' => 'factory',
        'color' => 'linear-gradient(135deg,#f59e0b,#f97316)',
        'permission' => 'production-tracking.php',
        'action' => 'can_view',
    ],
    [
        'title' => 'Approvals',
        'subtitle' => 'Customer proof approval',
        'url' => 'customer-approvals.php',
        'icon' => 'badge-check',
        'color' => 'linear-gradient(135deg,#8b5cf6,#a855f7)',
        'permission' => 'customer-approvals.php',
        'action' => 'can_view',
    ],
    [
        'title' => 'Designs',
        'subtitle' => 'Design proofing list',
        'url' => 'designs.php',
        'icon' => 'pen-tool',
        'color' => 'linear-gradient(135deg,#ec4899,#f43f5e)',
        'permission' => 'designs.php',
        'action' => 'can_view',
    ],
    [
        'title' => 'Printing',
        'subtitle' => 'Printing queue',
        'url' => 'production-tracking.php?stage=printing',
        'icon' => 'printer',
        'color' => 'linear-gradient(135deg,#0f766e,#14b8a6)',
        'permission' => 'production-tracking.php',
        'action' => 'can_view',
    ],
    [
        'title' => 'Users',
        'subtitle' => 'Manage login users',
        'url' => 'users.php',
        'icon' => 'user-cog',
        'color' => 'linear-gradient(135deg,#475569,#0f172a)',
        'permission' => 'users.php',
        'action' => 'can_view',
    ],
    [
        'title' => 'Website Colors',
        'subtitle' => 'Theme customization',
        'url' => 'website-colors.php',
        'icon' => 'palette',
        'color' => 'linear-gradient(135deg,#d97706,#f59e0b)',
        'permission' => 'website-colors.php',
        'action' => 'can_view',
    ],
];

function dash_can_action(mysqli $conn, string $action, string $page): bool
{
    if (function_exists($action)) {
        try {
            return (bool)$action($conn, $page);
        } catch (Throwable $e) {
            return true;
        }
    }

    return true;
}

$visibleQuickActions = [];
foreach ($quickActions as $action) {
    if (dash_can_action($conn, $action['action'], $action['permission'])) {
        $visibleQuickActions[] = $action;
    }
}

if (!$visibleQuickActions) {
    $visibleQuickActions = $quickActions;
}

$displayName = trim((string)($_SESSION['name'] ?? 'Admin')) ?: 'Admin';
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
        isolation: isolate;
    }

    .dashboard-page .dashboard-hero::after {
        content: "";
        position: absolute;
        width: 260px;
        height: 260px;
        right: -80px;
        top: -90px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--brand-1) 18%, transparent);
        z-index: -1;
    }

    .dashboard-page .dashboard-hero h1 {
        font-size: 32px;
        font-weight: 900;
        color: var(--text-main);
        margin-bottom: 6px;
        letter-spacing: -.4px;
    }

    .dashboard-page .dashboard-hero p {
        color: var(--text-muted);
        font-weight: 700;
        margin: 0;
    }

    .dashboard-date-chip {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        padding: 10px 14px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--brand-1) 10%, var(--card-bg));
        color: var(--brand-1);
        font-size: 13px;
        font-weight: 900;
        border: 1px solid color-mix(in srgb, var(--brand-1) 20%, var(--border-soft));
    }

    .dash-kpi-card {
        min-height: 128px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        position: relative;
        overflow: hidden;
    }

    .dash-kpi-card::after {
        content: "";
        position: absolute;
        width: 110px;
        height: 110px;
        right: -36px;
        bottom: -42px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--brand-1) 8%, transparent);
    }

    .dash-kpi-icon {
        width: 54px;
        height: 54px;
        border-radius: 17px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto;
        box-shadow: 0 14px 30px rgba(15, 23, 42, .12);
    }

    .dash-kpi-icon svg {
        width: 25px;
        height: 25px;
    }

    .dash-kpi-label {
        display: block;
        color: var(--text-muted);
        font-size: 12px;
        text-transform: uppercase;
        font-weight: 900;
        letter-spacing: .4px;
    }

    .dash-kpi-value {
        display: block;
        color: var(--text-main);
        font-size: 26px;
        font-weight: 900;
        line-height: 1.15;
        margin-top: 4px;
    }

    .dash-kpi-sub {
        color: var(--text-muted);
        display: block;
        margin-top: 4px;
        font-size: 12px;
        font-weight: 700;
    }

    .dashboard-card {
        padding: 24px;
    }

    .dashboard-card-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
        margin: 0;
    }

    .quick-action-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }

    .quick-action-btn {
        text-decoration: none;
        color: inherit;
        border: 1px solid var(--border-soft);
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        border-radius: 20px;
        padding: 16px;
        min-height: 120px;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 13px;
    }

    .quick-action-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 18px 40px rgba(15, 23, 42, .12);
        border-color: color-mix(in srgb, var(--brand-1) 40%, var(--border-soft));
        color: inherit;
    }

    .quick-action-icon {
        width: 46px;
        height: 46px;
        border-radius: 15px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto;
    }

    .quick-action-icon svg {
        width: 22px;
        height: 22px;
    }

    .quick-action-title {
        font-size: 15px;
        font-weight: 900;
        color: var(--text-main);
        line-height: 1.2;
        margin: 0;
    }

    .quick-action-subtitle {
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 700;
        margin-top: 4px;
        line-height: 1.35;
    }

    .process-card {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 16px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        height: 100%;
    }

    .process-step-icon {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        display: grid;
        place-items: center;
        color: #fff;
        margin-bottom: 12px;
    }

    .process-card h6 {
        font-weight: 900;
        color: var(--text-main);
        margin-bottom: 5px;
    }

    .process-card p {
        color: var(--text-muted);
        margin: 0;
        font-size: 13px;
        font-weight: 700;
    }

    .activity-item {
        display: flex;
        gap: 12px;
        padding: 13px 0;
        border-bottom: 1px solid var(--border-soft);
    }

    .activity-item:last-child {
        border-bottom: 0;
    }

    .activity-dot {
        width: 38px;
        height: 38px;
        border-radius: 13px;
        display: grid;
        place-items: center;
        background: color-mix(in srgb, var(--brand-1) 12%, transparent);
        color: var(--brand-1);
        flex: 0 0 auto;
    }

    .activity-title {
        color: var(--text-main);
        font-weight: 900;
        margin-bottom: 3px;
    }

    .activity-meta {
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
    }

    .mobile-activity-card {
        display: none;
    }

    @media (max-width: 1199.98px) {
        .quick-action-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 991.98px) {
        .quick-action-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .dashboard-card {
            padding: 18px;
        }
    }

    @media (max-width: 767.98px) {
        .dashboard-page .dashboard-hero {
            padding: 20px;
            border-radius: 18px;
        }

        .dashboard-page .dashboard-hero h1 {
            font-size: 25px;
        }

        .dashboard-date-chip {
            width: 100%;
            justify-content: center;
        }

        .dash-kpi-card {
            min-height: auto;
            padding: 16px;
            border-radius: 18px;
        }

        .dash-kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 15px;
        }

        .dash-kpi-value {
            font-size: 22px;
        }

        .quick-action-grid {
            grid-template-columns: 1fr;
        }

        .quick-action-btn {
            min-height: auto;
            flex-direction: row;
            align-items: center;
            justify-content: flex-start;
            border-radius: 18px;
        }

        .quick-action-icon {
            width: 48px;
            height: 48px;
        }

        .desktop-activity-table {
            display: none;
        }

        .mobile-activity-card {
            display: block;
        }
    }

    @media (max-width: 420px) {
        .dashboard-page .dashboard-hero h1 {
            font-size: 22px;
        }

        .dashboard-card-title {
            font-size: 16px;
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
                            <p>Subhiksha Cards ERP dashboard for orders, production, approvals and master controls.</p>
                        </div>

                        <div class="dashboard-date-chip">
                            <i data-lucide="calendar-days"></i>
                            <?= e(date('d M Y, l')) ?>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card-ui dash-kpi-card h-100">
                            <div class="dash-kpi-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)">
                                <i data-lucide="shopping-bag"></i>
                            </div>
                            <div>
                                <span class="dash-kpi-label">Today Orders</span>
                                <span class="dash-kpi-value"><?= number_format($todayOrders) ?></span>
                                <span class="dash-kpi-sub">New orders today</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card-ui dash-kpi-card h-100">
                            <div class="dash-kpi-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                <i data-lucide="indian-rupee"></i>
                            </div>
                            <div>
                                <span class="dash-kpi-label">Total Sales</span>
                                <span class="dash-kpi-value"><?= e(dash_money($totalSalesAmount)) ?></span>
                                <span class="dash-kpi-sub">Overall billing value</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card-ui dash-kpi-card h-100">
                            <div class="dash-kpi-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                <i data-lucide="factory"></i>
                            </div>
                            <div>
                                <span class="dash-kpi-label">Production Jobs</span>
                                <span class="dash-kpi-value"><?= number_format($productionJobs) ?></span>
                                <span class="dash-kpi-sub">Active production</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card-ui dash-kpi-card h-100">
                            <div class="dash-kpi-icon" style="background:linear-gradient(135deg,#8b5cf6,#a855f7)">
                                <i data-lucide="badge-check"></i>
                            </div>
                            <div>
                                <span class="dash-kpi-label">Pending Approvals</span>
                                <span class="dash-kpi-value"><?= number_format($pendingApprovals) ?></span>
                                <span class="dash-kpi-sub">Waiting for approval</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-lg-8">
                        <div class="card-ui dashboard-card h-100">
                            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                                <div>
                                    <h2 class="dashboard-card-title">Quick Actions</h2>
                                    <p class="text-muted-custom mb-0">Frequently used shortcuts.</p>
                                </div>
                                <i data-lucide="zap"></i>
                            </div>

                            <div class="quick-action-grid">
                                <?php foreach ($visibleQuickActions as $action): ?>
                                <a href="<?= e($action['url']) ?>" class="quick-action-btn">
                                    <div class="quick-action-icon" style="background:<?= e($action['color']) ?>">
                                        <i data-lucide="<?= e($action['icon']) ?>"></i>
                                    </div>
                                    <div>
                                        <h3 class="quick-action-title"><?= e($action['title']) ?></h3>
                                        <div class="quick-action-subtitle"><?= e($action['subtitle']) ?></div>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-4">
                        <div class="card-ui dashboard-card h-100">
                            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                                <div>
                                    <h2 class="dashboard-card-title">System Summary</h2>
                                    <p class="text-muted-custom mb-0">Current active records.</p>
                                </div>
                                <i data-lucide="layout-dashboard"></i>
                            </div>

                            <div class="row g-3">
                                <div class="col-6">
                                    <div class="process-card">
                                        <div class="process-step-icon"
                                            style="background:linear-gradient(135deg,#2563eb,#0ea5e9)">
                                            <i data-lucide="users"></i>
                                        </div>
                                        <h6><?= number_format($totalUsers) ?></h6>
                                        <p>Users</p>
                                    </div>
                                </div>

                                <div class="col-6">
                                    <div class="process-card">
                                        <div class="process-step-icon"
                                            style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                            <i data-lucide="shield"></i>
                                        </div>
                                        <h6><?= number_format($totalRoles) ?></h6>
                                        <p>Roles</p>
                                    </div>
                                </div>

                                <div class="col-6">
                                    <div class="process-card">
                                        <div class="process-step-icon"
                                            style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                            <i data-lucide="panel-left"></i>
                                        </div>
                                        <h6><?= number_format($totalMenus) ?></h6>
                                        <p>Menus</p>
                                    </div>
                                </div>

                                <div class="col-6">
                                    <div class="process-card">
                                        <div class="process-step-icon"
                                            style="background:linear-gradient(135deg,#8b5cf6,#a855f7)">
                                            <i data-lucide="history"></i>
                                        </div>
                                        <h6><?= number_format($totalLogs) ?></h6>
                                        <p>Logs</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-xl-7">
                        <div class="card-ui dashboard-card h-100">
                            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                                <div>
                                    <h2 class="dashboard-card-title">Production Flow</h2>
                                    <p class="text-muted-custom mb-0">Common invitation printing workflow.</p>
                                </div>
                                <a href="production-tracking.php"
                                    class="btn btn-sm btn-outline-primary rounded-pill fw-bold">
                                    View Tracking
                                </a>
                            </div>

                            <div class="row g-3">
                                <div class="col-12 col-sm-6 col-lg-4">
                                    <div class="process-card">
                                        <div class="process-step-icon"
                                            style="background:linear-gradient(135deg,#2563eb,#0ea5e9)">
                                            <i data-lucide="pen-tool"></i>
                                        </div>
                                        <h6>Design / Proofing</h6>
                                        <p>Prepare proof and share with customer.</p>
                                    </div>
                                </div>

                                <div class="col-12 col-sm-6 col-lg-4">
                                    <div class="process-card">
                                        <div class="process-step-icon"
                                            style="background:linear-gradient(135deg,#8b5cf6,#a855f7)">
                                            <i data-lucide="badge-check"></i>
                                        </div>
                                        <h6>Approval</h6>
                                        <p>Get customer confirmation before print.</p>
                                    </div>
                                </div>

                                <div class="col-12 col-sm-6 col-lg-4">
                                    <div class="process-card">
                                        <div class="process-step-icon"
                                            style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                            <i data-lucide="printer"></i>
                                        </div>
                                        <h6>Printing</h6>
                                        <p>Move approved jobs to print queue.</p>
                                    </div>
                                </div>

                                <div class="col-12 col-sm-6 col-lg-4">
                                    <div class="process-card">
                                        <div class="process-step-icon"
                                            style="background:linear-gradient(135deg,#0f766e,#14b8a6)">
                                            <i data-lucide="package-check"></i>
                                        </div>
                                        <h6>Cutting & Packing</h6>
                                        <p>Finish and pack invitation cards.</p>
                                    </div>
                                </div>

                                <div class="col-12 col-sm-6 col-lg-4">
                                    <div class="process-card">
                                        <div class="process-step-icon"
                                            style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                            <i data-lucide="shield-check"></i>
                                        </div>
                                        <h6>Quality Check</h6>
                                        <p>Verify count, design and print quality.</p>
                                    </div>
                                </div>

                                <div class="col-12 col-sm-6 col-lg-4">
                                    <div class="process-card">
                                        <div class="process-step-icon"
                                            style="background:linear-gradient(135deg,#475569,#0f172a)">
                                            <i data-lucide="truck"></i>
                                        </div>
                                        <h6>Dispatch</h6>
                                        <p>Ready for delivery or pickup.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-xl-5">
                        <div class="card-ui dashboard-card h-100">
                            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                                <div>
                                    <h2 class="dashboard-card-title">Recent Activity</h2>
                                    <p class="text-muted-custom mb-0">Latest system actions.</p>
                                </div>
                                <a href="activity_logs.php" class="btn btn-sm btn-outline-primary rounded-pill fw-bold">
                                    View All
                                </a>
                            </div>

                            <?php if (!$recentLogs): ?>
                            <div class="text-center text-muted-custom py-4">
                                No recent activity found.
                            </div>
                            <?php endif; ?>

                            <div class="desktop-activity-table">
                                <?php foreach ($recentLogs as $log): ?>
                                <div class="activity-item">
                                    <div class="activity-dot">
                                        <i data-lucide="activity"></i>
                                    </div>
                                    <div>
                                        <div class="activity-title"><?= e($log['action_key'] ?: 'Activity') ?></div>
                                        <div class="activity-meta">
                                            <?= e($log['module_name'] ?: '-') ?> ·
                                            <?= e($log['user_name'] ?: 'System') ?> ·
                                            <?= e(date('d-m-Y h:i A', strtotime($log['created_at']))) ?>
                                        </div>
                                        <?php if (!empty($log['description'])): ?>
                                        <div class="activity-meta"><?= e($log['description']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="mobile-activity-card">
                                <?php foreach ($recentLogs as $log): ?>
                                <div class="process-card mb-2">
                                    <div class="d-flex gap-3">
                                        <div class="activity-dot">
                                            <i data-lucide="activity"></i>
                                        </div>
                                        <div>
                                            <div class="activity-title"><?= e($log['action_key'] ?: 'Activity') ?></div>
                                            <div class="activity-meta">
                                                <?= e($log['module_name'] ?: '-') ?> ·
                                                <?= e($log['user_name'] ?: 'System') ?>
                                            </div>
                                            <div class="activity-meta">
                                                <?= e(date('d-m-Y h:i A', strtotime($log['created_at']))) ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
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