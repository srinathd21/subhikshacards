<?php

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'job_cards.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function jcTableExists(mysqli $conn, string $table): bool
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

function jcDate($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime($value)) : '-';
}

function jcDateTime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime($value)) : '-';
}

function jcMoney($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function jcOrderBadgeClass(string $orderType): string
{
    return strtolower($orderType) === 'customized' ? 'customized' : 'readymade';
}

function jcStatusClass(string $statusKey): string
{
    $statusKey = strtolower(trim($statusKey));

    if (in_array($statusKey, ['completed'], true)) {
        return 'completed';
    }

    if (in_array($statusKey, ['ready_for_dispatch', 'dispatched'], true)) {
        return 'ready';
    }

    if (in_array($statusKey, ['delayed', 'cancelled'], true)) {
        return 'danger';
    }

    return 'progress';
}

function jcRoleLabel(string $roleKey): string
{
    $labels = [
        'admin' => 'Admin',
        'sales' => 'Sales',
        'designing_proofing' => 'Designing / Proofing',
        'offset_printing' => 'Offset Printing',
        'screen_printing' => 'Screen Printing',
        'digital_printing' => 'Digital Printing',
        'multicolor_offset_printing' => 'Multicolor Offset Printing',
        'printing' => 'Printing'
    ];

    return $labels[$roleKey] ?? ucfirst(str_replace('_', ' ', $roleKey));
}

$roleKey = strtolower((string)($_SESSION['role_key'] ?? ''));
$roleId = (int)($_SESSION['role_id'] ?? 0);

$allAccessRoles = [
    'admin',
    'sales',
    'designing_proofing'
];

$printingRoleKeys = [
    'offset_printing',
    'screen_printing',
    'digital_printing',
    'multicolor_offset_printing'
];

$hasAllJobCardAccess = in_array($roleKey, $allAccessRoles, true);
$isSpecificPrintingRole = in_array($roleKey, $printingRoleKeys, true);
$isGeneralPrintingRole = $roleKey === 'printing';

$filterOrderType = trim((string)($_GET['order_type'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterSearch = trim((string)($_GET['search'] ?? ''));

$where = [];
$params = [];
$types = '';

if (!$hasAllJobCardAccess) {
    if ($isSpecificPrintingRole) {
        /*
         | Printing users see only job cards matching their printing type.
         | Example:
         | offset_printing role sees Offset Print jobs.
         | screen_printing role sees Screen Print jobs.
         | digital_printing role sees Digital Print jobs.
         | multicolor_offset_printing role sees Multicolor Offset Print + customized jobs.
         */
        $where[] = "(
            pt.role_key = ?
            OR rprint.role_key = ?
        )";
        $params[] = $roleKey;
        $params[] = $roleKey;
        $types .= 'ss';
    } elseif ($isGeneralPrintingRole) {
        /*
         | General printing role can see all printing department jobs.
         */
        $where[] = "(
            pt.role_key IN ('offset_printing','screen_printing','digital_printing','multicolor_offset_printing')
            OR rprint.role_key IN ('offset_printing','screen_printing','digital_printing','multicolor_offset_printing')
        )";
    } else {
        /*
         | Any other role should not see job cards unless admin gives special permission later.
         */
        $where[] = "1 = 0";
    }
}

if (in_array($filterOrderType, ['readymade', 'customized'], true)) {
    $where[] = "jc.order_type = ?";
    $params[] = $filterOrderType;
    $types .= 's';
}

if ($filterStatus !== '') {
    $where[] = "jcs.status_key = ?";
    $params[] = $filterStatus;
    $types .= 's';
}

if ($filterSearch !== '') {
    $where[] = "(
        jc.job_card_no LIKE ?
        OR jc.customer_name LIKE ?
        OR jc.mobile LIKE ?
        OR jc.product_name LIKE ?
        OR pt.printing_name LIKE ?
        OR ws.step_name LIKE ?
    )";

    $like = '%' . $filterSearch . '%';
    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$statusOptions = [];
try {
    if (jcTableExists($conn, 'job_card_statuses')) {
        $res = $conn->query("
            SELECT status_key, status_name
            FROM job_card_statuses
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        while ($row = $res->fetch_assoc()) {
            $statusOptions[] = $row;
        }
        $res->free();
    }
} catch (Throwable $e) {
    $statusOptions = [];
}

$rows = [];
$message = '';
$messageType = 'success';

if (!jcTableExists($conn, 'job_cards')) {
    $message = 'job_cards table is missing.';
    $messageType = 'danger';
} else {
    try {
        $sql = "
            SELECT
                jc.*,

                ft.function_name,

                pt.printing_name,
                pt.printing_key,
                pt.role_key AS printing_role_key,

                pst.sub_type_name,

                jcs.status_name,
                jcs.status_key,
                jcs.color_code,

                ws.step_name AS current_step_name,
                ws.step_key AS current_step_key,

                sales.username AS sales_person,
                designer.username AS designer_name,
                printer.username AS printer_name,

                rprint.role_name AS assigned_printing_role_name,
                rprint.role_key AS assigned_printing_role_key,

                COALESCE(track.total_steps, 0) AS total_steps,
                COALESCE(track.completed_steps, 0) AS completed_steps,
                COALESCE(track.delayed_steps, 0) AS delayed_steps

            FROM job_cards jc

            LEFT JOIN function_types ft
                ON ft.id = jc.function_type_id

            LEFT JOIN printing_types pt
                ON pt.id = jc.printing_type_id

            LEFT JOIN printing_sub_types pst
                ON pst.id = jc.printing_sub_type_id

            LEFT JOIN job_card_statuses jcs
                ON jcs.id = jc.job_card_status_id

            LEFT JOIN workflow_steps ws
                ON ws.id = jc.current_workflow_step_id

            LEFT JOIN users sales
                ON sales.id = jc.assigned_sales_user_id

            LEFT JOIN users designer
                ON designer.id = jc.assigned_design_user_id

            LEFT JOIN users printer
                ON printer.id = jc.assigned_printing_user_id

            LEFT JOIN roles rprint
                ON rprint.id = jc.assigned_printing_role_id

            LEFT JOIN (
                SELECT
                    job_card_id,
                    COUNT(*) AS total_steps,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_steps,
                    SUM(CASE WHEN status = 'delayed' OR is_delayed = 1 THEN 1 ELSE 0 END) AS delayed_steps
                FROM job_tracking
                GROUP BY job_card_id
            ) track
                ON track.job_card_id = jc.id

            {$whereSql}

            ORDER BY jc.id DESC
            LIMIT 500
        ";

        $stmt = $conn->prepare($sql);

        if ($params) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();
    } catch (Throwable $e) {
        $message = 'Job cards query error: ' . $e->getMessage();
        $messageType = 'danger';
        $rows = [];
    }
}

$totalRows = count($rows);
$readymadeRows = 0;
$customizedRows = 0;
$delayedRows = 0;

foreach ($rows as $row) {
    if (($row['order_type'] ?? '') === 'readymade') {
        $readymadeRows++;
    }

    if (($row['order_type'] ?? '') === 'customized') {
        $customizedRows++;
    }

    if ((int)($row['is_delayed'] ?? 0) === 1 || (int)($row['delayed_steps'] ?? 0) > 0) {
        $delayedRows++;
    }
}

$pageAccessLabel = $hasAllJobCardAccess
    ? 'Showing all job cards'
    : 'Showing job cards for ' . jcRoleLabel($roleKey);

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Job Cards - Subhiksha Cards</title>

    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
    .module-page .page-head {
        padding: 24px 28px;
        margin-bottom: 18px;
    }

    .module-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main);
    }

    .module-card {
        padding: 24px;
    }

    .module-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
        margin: 0;
    }

    .stat-card {
        padding: 18px;
        min-height: 112px;
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto;
    }

    .stat-card span {
        display: block;
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 900;
        text-transform: uppercase;
    }

    .stat-card strong {
        font-size: 24px;
        font-weight: 900;
        color: var(--text-main);
    }

    .filter-card {
        padding: 18px;
        margin-bottom: 18px;
    }

    .form-control,
    .form-select {
        border-radius: 14px;
        min-height: 46px;
    }

    .order-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 6px 11px;
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .02em;
    }

    .order-badge.readymade {
        color: #92400e;
        background: #fef3c7;
    }

    .order-badge.customized {
        color: #075985;
        background: #e0f2fe;
    }

    .status-pill {
        font-size: 11px;
        font-weight: 900;
        border-radius: 999px;
        padding: 6px 10px;
        display: inline-flex;
        align-items: center;
        white-space: nowrap;
    }

    .status-pill.progress {
        color: #1d4ed8;
        background: #dbeafe;
    }

    .status-pill.ready {
        color: #047857;
        background: #d1fae5;
    }

    .status-pill.completed {
        color: #166534;
        background: #dcfce7;
    }

    .status-pill.danger {
        color: #991b1b;
        background: #fee2e2;
    }

    .progress-mini {
        width: 120px;
        height: 8px;
        background: color-mix(in srgb, var(--border-soft) 80%, transparent);
        border-radius: 999px;
        overflow: hidden;
    }

    .progress-mini-bar {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(135deg, #2563eb, #22c55e);
    }

    .job-no {
        font-weight: 900;
        color: var(--text-main);
    }

    .muted-small {
        display: block;
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 700;
        margin-top: 3px;
    }

    .btn-action-icon {
        width: 36px !important;
        height: 36px !important;
        min-width: 36px !important;
        max-width: 36px !important;
        padding: 0 !important;
        border-radius: 50% !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .view-info-card {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 14px 16px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        height: 100%;
    }

    .view-info-card small {
        display: block;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        margin-bottom: 4px;
    }

    .view-info-card strong,
    .view-info-card span {
        display: block;
        color: var(--text-main);
        font-weight: 900;
        word-break: break-word;
        white-space: pre-wrap;
    }

    .mobile-cards {
        display: none;
    }

    .mobile-card {
        border: 1px solid var(--border-soft);
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        border-radius: 18px;
        padding: 16px;
        margin-bottom: 12px;
    }

    .mobile-card-title {
        font-size: 16px;
        font-weight: 900;
        color: var(--text-main);
        line-height: 1.25;
    }

    .mobile-card-subtitle {
        display: block;
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
        margin-top: 4px;
        word-break: break-word;
    }

    .mobile-card-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 14px;
    }

    .toast-ui {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
        overflow: hidden;
        min-width: 320px;
        max-width: 420px;
    }

    .toast-ui.success {
        background: #dcfce7;
        color: #14532d;
    }

    .toast-ui.danger {
        background: #fee2e2;
        color: #7f1d1d;
    }

    .toast-ui .toast-title {
        font-size: 14px;
        font-weight: 900;
        margin-bottom: 2px;
    }

    .toast-ui .toast-message {
        font-size: 13px;
        font-weight: 800;
        line-height: 1.45;
    }

    @media(max-width:767.98px) {
        .module-page .page-head {
            padding: 18px;
            border-radius: 18px;
        }

        .module-page .page-head h1 {
            font-size: 24px;
        }

        .module-card {
            padding: 16px;
            border-radius: 18px;
        }

        .desktop-table {
            display: none !important;
        }

        .mobile-cards {
            display: block;
        }

        .filter-card .btn {
            width: 100%;
        }

        .mobile-card-actions .btn-action-icon {
            width: 42px !important;
            height: 42px !important;
            min-width: 42px !important;
            max-width: 42px !important;
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
                            <h1 class="mb-1">Job Cards</h1>
                            <p class="text-muted-custom mb-0">
                                <?= e($pageAccessLabel) ?>
                            </p>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <span class="status-pill progress">
                                <?= e(jcRoleLabel($roleKey)) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000">
                    <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert"
                        aria-live="assertive" aria-atomic="true" data-bs-delay="5200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title"><?= $messageType === 'danger' ? 'Failed' : 'Info' ?></div>
                                <div class="toast-message"><?= e($message) ?></div>
                            </div>
                            <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"
                                aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)">
                                <i data-lucide="clipboard-list"></i>
                            </div>
                            <div>
                                <span>Total Job Cards</span>
                                <strong><?= number_format($totalRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                <i data-lucide="package-check"></i>
                            </div>
                            <div>
                                <span>Readymade</span>
                                <strong><?= number_format($readymadeRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#0284c7,#38bdf8)">
                                <i data-lucide="palette"></i>
                            </div>
                            <div>
                                <span>Customized</span>
                                <strong><?= number_format($customizedRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#dc2626,#f97316)">
                                <i data-lucide="clock-alert"></i>
                            </div>
                            <div>
                                <span>Delayed</span>
                                <strong><?= number_format($delayedRows) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui filter-card">
                    <form method="get" class="row g-3 align-items-end">
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold">Order Type</label>
                            <select name="order_type" class="form-select">
                                <option value="">All</option>
                                <option value="readymade" <?= $filterOrderType === 'readymade' ? 'selected' : '' ?>>
                                    Readymade</option>
                                <option value="customized" <?= $filterOrderType === 'customized' ? 'selected' : '' ?>>
                                    Customized</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <?php foreach ($statusOptions as $status): ?>
                                <option value="<?= e($status['status_key']) ?>"
                                    <?= $filterStatus === $status['status_key'] ? 'selected' : '' ?>>
                                    <?= e($status['status_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-bold">Search</label>
                            <input type="search" name="search" class="form-control"
                                placeholder="Job no, customer, mobile, product..." value="<?= e($filterSearch) ?>">
                        </div>

                        <div class="col-12 col-md-2">
                            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold w-100">
                                Filter
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card-ui module-card">
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Job Cards List</h2>
                            <p class="text-muted-custom mb-0">
                                Admin, Sales and Designing / Proofing can see all jobs. Printing roles see only their
                                assigned printing type.
                            </p>
                        </div>

                        <div style="max-width:340px;width:100%">
                            <input type="search" id="tableSearch" class="form-control"
                                placeholder="Search in this list...">
                        </div>
                    </div>

                    <div class="table-responsive desktop-table">
                        <table class="table-ui" id="dataTable">
                            <thead>
                                <tr>
                                    <th>Job Card</th>
                                    <th>Customer</th>
                                    <th>Order Type</th>
                                    <th>Product</th>
                                    <th>Printing</th>
                                    <th>Current Stage</th>
                                    <th>Progress</th>
                                    <th>Amount</th>
                                    <th>Delivery</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted-custom py-4">
                                        No job cards found.
                                    </td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($rows as $row): ?>
                                <?php
                                    $orderType = strtolower((string)($row['order_type'] ?? 'readymade'));
                                    $statusKey = strtolower((string)($row['status_key'] ?? 'in_progress'));
                                    $totalSteps = (int)($row['total_steps'] ?? 0);
                                    $completedSteps = (int)($row['completed_steps'] ?? 0);
                                    $progressPercent = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;
                                    $progressPercent = max(0, min(100, $progressPercent));
                                ?>
                                <tr>
                                    <td>
                                        <span class="job-no"><?= e($row['job_card_no']) ?></span>
                                        <small class="muted-small">
                                            Created: <?= e(jcDate($row['created_at'] ?? null)) ?>
                                        </small>
                                    </td>

                                    <td>
                                        <strong><?= e($row['customer_name']) ?></strong>
                                        <small class="muted-small"><?= e($row['mobile']) ?></small>
                                    </td>

                                    <td>
                                        <span class="order-badge <?= e(jcOrderBadgeClass($orderType)) ?>">
                                            <?= e($orderType === 'customized' ? 'Customized' : 'Readymade') ?>
                                        </span>
                                    </td>

                                    <td>
                                        <strong><?= e($row['product_name'] ?: '-') ?></strong>
                                        <small class="muted-small"><?= e($row['function_name'] ?? '-') ?></small>
                                    </td>

                                    <td>
                                        <strong><?= e($row['printing_name'] ?? '-') ?></strong>
                                        <?php if (!empty($row['sub_type_name'])): ?>
                                        <small class="muted-small"><?= e($row['sub_type_name']) ?></small>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span class="status-pill <?= e(jcStatusClass($statusKey)) ?>">
                                            <?= e($row['current_step_name'] ?? 'Not Started') ?>
                                        </span>
                                        <small class="muted-small"><?= e($row['status_name'] ?? '-') ?></small>
                                    </td>

                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress-mini">
                                                <div class="progress-mini-bar"
                                                    style="width:<?= (int)$progressPercent ?>%"></div>
                                            </div>
                                            <strong><?= (int)$progressPercent ?>%</strong>
                                        </div>
                                        <small class="muted-small">
                                            <?= number_format($completedSteps) ?>/<?= number_format($totalSteps) ?>
                                            stages
                                        </small>
                                    </td>

                                    <td>
                                        <strong><?= e(jcMoney($row['final_amount'] ?? 0)) ?></strong>
                                        <small class="muted-small">
                                            Bal: <?= e(jcMoney($row['balance_amount'] ?? 0)) ?>
                                        </small>
                                    </td>

                                    <td><?= e(jcDate($row['delivery_date'] ?? null)) ?></td>

                                    <td class="text-end">
    <a href="job_card_view.php?id=<?= e($row['id']) ?>"
        class="btn btn-sm btn-outline-secondary rounded-circle btn-action-icon"
        title="View Job Card"
        aria-label="View Job Card">
        <i data-lucide="eye"></i>
    </a>
</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-cards" id="mobileCards">
                        <?php if (!$rows): ?>
                        <div class="mobile-card text-center text-muted-custom">
                            No job cards found.
                        </div>
                        <?php endif; ?>

                        <?php foreach ($rows as $row): ?>
                        <?php
                            $orderType = strtolower((string)($row['order_type'] ?? 'readymade'));
                            $statusKey = strtolower((string)($row['status_key'] ?? 'in_progress'));
                            $totalSteps = (int)($row['total_steps'] ?? 0);
                            $completedSteps = (int)($row['completed_steps'] ?? 0);
                            $progressPercent = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;
                            $progressPercent = max(0, min(100, $progressPercent));
                        ?>
                        <div class="mobile-card">
                            <div class="d-flex justify-content-between gap-2 align-items-start">
                                <div>
                                    <div class="mobile-card-title"><?= e($row['job_card_no']) ?></div>
                                    <span class="mobile-card-subtitle"><?= e($row['customer_name']) ?> |
                                        <?= e($row['mobile']) ?></span>
                                    <span class="mobile-card-subtitle">Product:
                                        <?= e($row['product_name'] ?: '-') ?></span>
                                    <span class="mobile-card-subtitle">Printing:
                                        <?= e($row['printing_name'] ?? '-') ?></span>
                                    <span class="mobile-card-subtitle">Stage:
                                        <?= e($row['current_step_name'] ?? '-') ?></span>
                                    <span class="mobile-card-subtitle">Delivery:
                                        <?= e(jcDate($row['delivery_date'] ?? null)) ?></span>
                                </div>

                                <span class="order-badge <?= e(jcOrderBadgeClass($orderType)) ?>">
                                    <?= e($orderType === 'customized' ? 'Custom' : 'Ready') ?>
                                </span>
                            </div>

                            <div class="d-flex align-items-center gap-2 mt-3">
                                <div class="progress-mini" style="width:100%">
                                    <div class="progress-mini-bar" style="width:<?= (int)$progressPercent ?>%"></div>
                                </div>
                                <strong><?= (int)$progressPercent ?>%</strong>
                            </div>

                            <div class="mobile-card-actions">
                                <a href="job_card_view.php?id=<?= e($row['id']) ?>"
    class="btn btn-sm btn-outline-secondary rounded-circle btn-action-icon"
    title="View Job Card"
    aria-label="View Job Card">
    <i data-lucide="eye"></i>
</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold">View Job Card</h5>
                        <small class="text-muted-custom" id="viewJobNo"></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Customer</small>
                                <strong id="viewCustomer">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Mobile</small>
                                <strong id="viewMobile">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Order Type</small>
                                <strong id="viewOrderType">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Product</small>
                                <strong id="viewProduct">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Function Type</small>
                                <strong id="viewFunction">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Printing Type</small>
                                <strong id="viewPrinting">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Printing Sub Type</small>
                                <strong id="viewSubtype">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Status</small>
                                <strong id="viewStatus">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Current Stage</small>
                                <strong id="viewStep">-</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="view-info-card">
                                <small>Sales Person</small>
                                <strong id="viewSales">-</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="view-info-card">
                                <small>Designer</small>
                                <strong id="viewDesigner">-</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="view-info-card">
                                <small>Printer</small>
                                <strong id="viewPrinter">-</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="view-info-card">
                                <small>Printing Role</small>
                                <strong id="viewPrintRole">-</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="view-info-card">
                                <small>Final Amount</small>
                                <strong id="viewFinal">-</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="view-info-card">
                                <small>Advance</small>
                                <strong id="viewAdvance">-</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="view-info-card">
                                <small>Balance</small>
                                <strong id="viewBalance">-</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="view-info-card">
                                <small>Delivery Date</small>
                                <strong id="viewDelivery">-</strong>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="view-info-card">
                                <small>Progress</small>
                                <span id="viewProgress">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold"
                        data-bs-dismiss="modal">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
    (function() {
        function setText(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            const clean = (value == null || String(value).trim() === '') ? '-' : String(value);
            el.textContent = clean;
        }

        document.querySelectorAll('.js-view-record').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setText('viewJobNo', btn.dataset.jobNo || '-');
                setText('viewCustomer', btn.dataset.customer || '-');
                setText('viewMobile', btn.dataset.mobile || '-');
                setText('viewOrderType', btn.dataset.orderType || '-');
                setText('viewProduct', btn.dataset.product || '-');
                setText('viewFunction', btn.dataset.function || '-');
                setText('viewPrinting', btn.dataset.printing || '-');
                setText('viewSubtype', btn.dataset.subtype || '-');
                setText('viewStatus', btn.dataset.status || '-');
                setText('viewStep', btn.dataset.step || '-');
                setText('viewSales', btn.dataset.sales || '-');
                setText('viewDesigner', btn.dataset.designer || '-');
                setText('viewPrinter', btn.dataset.printer || '-');
                setText('viewPrintRole', btn.dataset.printRole || '-');
                setText('viewFinal', btn.dataset.final || '-');
                setText('viewAdvance', btn.dataset.advance || '-');
                setText('viewBalance', btn.dataset.balance || '-');
                setText('viewDelivery', btn.dataset.delivery || '-');
                setText('viewProgress', btn.dataset.progress || '-');
            });
        });

        document.getElementById('tableSearch')?.addEventListener('input', function() {
            const value = this.value.toLowerCase().trim();

            document.querySelectorAll('#dataTable tbody tr').forEach(function(row) {
                row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
            });

            document.querySelectorAll('#mobileCards .mobile-card').forEach(function(card) {
                card.style.display = card.textContent.toLowerCase().includes(value) ? '' : 'none';
            });
        });

        const pageToastEl = document.getElementById('pageToast');
        if (pageToastEl && window.bootstrap && bootstrap.Toast) {
            bootstrap.Toast.getOrCreateInstance(pageToastEl).show();
        }

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    })();
    </script>
</body>

</html>