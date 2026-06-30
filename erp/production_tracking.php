<?php
require_once __DIR__ . '/includes/auth.php';

require_permission($conn, 'can_view', 'production_tracking.php');

$pageTitle = 'Production Tracking';

$currentPage = 'production_tracking.php';

$canCreate = can_create($conn, $currentPage);
$canUpdate = can_update($conn, $currentPage);
$canDelete = can_delete($conn, $currentPage);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['production_tracking_csrf'])) {
    $_SESSION['production_tracking_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['production_tracking_csrf'];

if (!function_exists('set_toast')) {
    function set_toast(string $type, string $message): void
    {
        $_SESSION['toast'] = [
            'type' => $type,
            'message' => $message
        ];
    }
}

$stages = [
    'design_proofing' => 'Design / Proofing',
    'approval' => 'Approval',
    'printing' => 'Printing',
    'cutting_packing' => 'Cutting & Packing',
    'quality_check' => 'Quality Check',
    'ready_dispatch' => 'Ready for Dispatch'
];

$statuses = [
    'active' => 'Active',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];

$tableExists = false;

try {
    $checkTable = $conn->query("SHOW TABLES LIKE 'production_jobs'");
    $tableExists = $checkTable && $checkTable->num_rows > 0;
} catch (Throwable $e) {
    $tableExists = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists) {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $postedToken)) {
        set_toast('error', 'Invalid request. Please refresh and try again.');
        header('Location: production_tracking.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_job') {
            if (!$canCreate) {
                set_toast('error', 'You do not have permission to create job.');
                header('Location: production_tracking.php');
                exit;
            }

            $jobNo = trim((string)($_POST['job_no'] ?? ''));
            $customerName = trim((string)($_POST['customer_name'] ?? ''));
            $mobile = trim((string)($_POST['mobile'] ?? ''));
            $itemName = trim((string)($_POST['item_name'] ?? ''));
            $qty = (int)($_POST['qty'] ?? 1);
            $currentStage = trim((string)($_POST['current_stage'] ?? 'design_proofing'));
            $deliveryDate = trim((string)($_POST['delivery_date'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $createdBy = (int)($_SESSION['user_id'] ?? 0);

            if ($jobNo === '' || $customerName === '' || $itemName === '') {
                set_toast('error', 'Job no, customer name and item name are required.');
                header('Location: production_tracking.php');
                exit;
            }

            if (!array_key_exists($currentStage, $stages)) {
                $currentStage = 'design_proofing';
            }

            if ($qty <= 0) {
                $qty = 1;
            }

            $duplicate = $conn->prepare("SELECT id FROM production_jobs WHERE job_no = ? LIMIT 1");
            $duplicate->bind_param('s', $jobNo);
            $duplicate->execute();
            $duplicateResult = $duplicate->get_result();

            if ($duplicateResult->num_rows > 0) {
                $duplicate->close();
                set_toast('error', 'This job number already exists.');
                header('Location: production_tracking.php');
                exit;
            }

            $duplicate->close();

            $deliveryDateValue = $deliveryDate !== '' ? $deliveryDate : null;

            $stmt = $conn->prepare("
                INSERT INTO production_jobs (
                    job_no,
                    customer_name,
                    mobile,
                    item_name,
                    qty,
                    current_stage,
                    delivery_date,
                    status,
                    notes,
                    created_by,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NOW())
            ");

            $stmt->bind_param(
                'ssssisssi',
                $jobNo,
                $customerName,
                $mobile,
                $itemName,
                $qty,
                $currentStage,
                $deliveryDateValue,
                $notes,
                $createdBy
            );

            $stmt->execute();
            $stmt->close();

            set_toast('success', 'Production job created successfully.');
            header('Location: production_tracking.php');
            exit;
        }

        if ($action === 'update_stage') {
            if (!$canUpdate) {
                set_toast('error', 'You do not have permission to update stage.');
                header('Location: production_tracking.php');
                exit;
            }

            $jobId = (int)($_POST['job_id'] ?? 0);
            $currentStage = trim((string)($_POST['current_stage'] ?? ''));

            if ($jobId <= 0 || !array_key_exists($currentStage, $stages)) {
                set_toast('error', 'Invalid stage update request.');
                header('Location: production_tracking.php');
                exit;
            }

            $stmt = $conn->prepare("
                UPDATE production_jobs
                SET current_stage = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->bind_param('si', $currentStage, $jobId);
            $stmt->execute();
            $stmt->close();

            set_toast('success', 'Production stage updated successfully.');
            header('Location: production_tracking.php');
            exit;
        }

        if ($action === 'update_status') {
            if (!$canUpdate) {
                set_toast('error', 'You do not have permission to update status.');
                header('Location: production_tracking.php');
                exit;
            }

            $jobId = (int)($_POST['job_id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? ''));

            if ($jobId <= 0 || !array_key_exists($status, $statuses)) {
                set_toast('error', 'Invalid status update request.');
                header('Location: production_tracking.php');
                exit;
            }

            $stmt = $conn->prepare("
                UPDATE production_jobs
                SET status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->bind_param('si', $status, $jobId);
            $stmt->execute();
            $stmt->close();

            if ($status === 'completed') {
                set_toast('success', 'Job marked as completed.');
            } elseif ($status === 'cancelled') {
                set_toast('warning', 'Job marked as cancelled.');
            } else {
                set_toast('success', 'Job marked as active.');
            }

            header('Location: production_tracking.php');
            exit;
        }
    } catch (Throwable $e) {
        set_toast('error', 'Error: ' . $e->getMessage());
        header('Location: production_tracking.php');
        exit;
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$stageFilter = trim((string)($_GET['stage'] ?? 'all'));

$totalJobs = 0;
$activeJobs = 0;
$completedJobs = 0;
$jobs = [];

if ($tableExists) {
    try {
        $statQuery = $conn->query("
            SELECT
                COUNT(*) AS total_jobs,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_jobs
            FROM production_jobs
        ");

        $stats = $statQuery->fetch_assoc();

        $totalJobs = (int)($stats['total_jobs'] ?? 0);
        $activeJobs = (int)($stats['active_jobs'] ?? 0);
        $completedJobs = (int)($stats['completed_jobs'] ?? 0);

        $where = [];
        $types = '';
        $params = [];

        if ($search !== '') {
            $where[] = "(job_no LIKE ? OR customer_name LIKE ? OR mobile LIKE ? OR item_name LIKE ?)";
            $likeSearch = '%' . $search . '%';
            $types .= 'ssss';
            $params[] = $likeSearch;
            $params[] = $likeSearch;
            $params[] = $likeSearch;
            $params[] = $likeSearch;
        }

        if ($stageFilter !== 'all' && array_key_exists($stageFilter, $stages)) {
            $where[] = "current_stage = ?";
            $types .= 's';
            $params[] = $stageFilter;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT *
            FROM production_jobs
            $whereSql
            ORDER BY id DESC
            LIMIT 300
        ";

        $stmt = $conn->prepare($sql);

        if ($params) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $jobs[] = $row;
        }

        $stmt->close();
    } catch (Throwable $e) {
        set_toast('error', 'Unable to load production jobs.');
    }
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> - Subhiksha Cards</title>

    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php if (file_exists(__DIR__ . '/includes/theme-loader.php')): ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <?php endif; ?>

    <style>
    .production-page {
        padding: 24px;
        background: #f5f8fc;
        min-height: 100vh;
    }

    .production-card {
        background: #fff;
        border: 1px solid #dce4f0;
        border-radius: 18px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.04);
    }

    .production-header {
        padding: 32px;
        display: flex;
        justify-content: space-between;
        gap: 18px;
        align-items: center;
        margin-bottom: 20px;
    }

    .production-title {
        font-size: 30px;
        font-weight: 900;
        color: #020617;
        margin: 0;
    }

    .production-subtitle {
        color: #52627a;
        margin-top: 8px;
        font-size: 16px;
    }

    .production-btn {
        background: #1677ff;
        color: #fff;
        border: 0;
        border-radius: 28px;
        padding: 12px 26px;
        font-weight: 800;
    }

    .production-btn:hover {
        background: #075ee8;
        color: #fff;
    }

    .filter-card {
        padding: 26px 28px;
        margin-bottom: 18px;
    }

    .stage-tabs {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .stage-tab {
        border: 1px solid #dce4f0;
        padding: 10px 18px;
        border-radius: 24px;
        font-weight: 800;
        font-size: 13px;
        color: #020617;
        background: #fff;
        text-decoration: none;
    }

    .stage-tab.active {
        background: linear-gradient(135deg, #0e83b7, #246bff);
        color: #fff;
        border-color: transparent;
    }

    .error-box {
        background: #fee2e2;
        border: 1px solid #fca5a5;
        color: #7f1d1d;
        padding: 18px 20px;
        border-radius: 16px;
        margin-bottom: 18px;
        font-weight: 800;
    }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 18px;
        margin-bottom: 20px;
    }

    .stat-card {
        padding: 28px 22px;
        display: flex;
        gap: 16px;
        align-items: center;
    }

    .stat-icon {
        width: 58px;
        height: 58px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        color: #fff;
        font-size: 22px;
    }

    .stat-icon.blue {
        background: #2084ed;
    }

    .stat-icon.orange {
        background: #fb8c00;
    }

    .stat-icon.green {
        background: #20b85a;
    }

    .stat-label {
        font-size: 12px;
        font-weight: 900;
        color: #58708f;
        text-transform: uppercase;
    }

    .stat-value {
        font-size: 28px;
        font-weight: 900;
        color: #020617;
    }

    .list-card {
        padding: 28px;
    }

    .list-head {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: center;
        margin-bottom: 18px;
    }

    .section-title {
        font-size: 20px;
        font-weight: 900;
        margin: 0;
        color: #071326;
    }

    .section-subtitle {
        color: #607089;
        margin-top: 4px;
    }

    .search-input {
        min-width: 310px;
        border: 1px solid #dce4f0;
        border-radius: 14px;
        padding: 13px 16px;
        outline: none;
    }

    .production-table {
        width: 100%;
        border-collapse: collapse;
    }

    .production-table thead th {
        background: #edf2f8;
        color: #30445f;
        font-size: 12px;
        text-transform: uppercase;
        padding: 15px 14px;
        border-bottom: 1px solid #dce4f0;
    }

    .production-table tbody td {
        padding: 14px;
        border-bottom: 1px solid #edf2f7;
        vertical-align: middle;
    }

    .badge-stage,
    .badge-active,
    .badge-completed,
    .badge-cancelled {
        display: inline-flex;
        padding: 7px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
    }

    .badge-stage {
        background: #eaf2ff;
        color: #075ec9;
    }

    .badge-active {
        background: #e0f2fe;
        color: #0369a1;
    }

    .badge-completed {
        background: #dcfce7;
        color: #166534;
    }

    .badge-cancelled {
        background: #fee2e2;
        color: #991b1b;
    }

    .action-row {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        flex-wrap: wrap;
    }

    .mini-btn {
        border: 1px solid #dce4f0;
        background: #fff;
        border-radius: 10px;
        padding: 8px 10px;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
    }

    .mini-btn.success {
        background: #16a34a;
        color: #fff;
        border-color: #16a34a;
    }

    .mini-btn.warning {
        background: #f59e0b;
        color: #fff;
        border-color: #f59e0b;
    }

    .stage-select {
        border: 1px solid #dce4f0;
        border-radius: 10px;
        padding: 8px 10px;
        font-size: 12px;
        font-weight: 700;
    }

    .empty-row {
        padding: 35px;
        text-align: center;
        color: #607089;
        font-size: 16px;
    }

    .toast-wrap {
        position: fixed;
        top: 22px;
        right: 22px;
        z-index: 99999;
    }

    .custom-toast {
        min-width: 310px;
        max-width: 420px;
        border-radius: 16px;
        padding: 15px 16px;
        display: flex;
        gap: 12px;
        align-items: flex-start;
        color: #fff;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.22);
        margin-bottom: 10px;
    }

    .custom-toast.success {
        background: linear-gradient(135deg, #16a34a, #22c55e);
    }

    .custom-toast.error {
        background: linear-gradient(135deg, #dc2626, #ef4444);
    }

    .custom-toast.warning {
        background: linear-gradient(135deg, #f59e0b, #f97316);
    }

    .toast-icon {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        display: grid;
        place-items: center;
    }

    .toast-title {
        font-weight: 900;
    }

    .toast-message {
        font-size: 13px;
    }

    .toast-close {
        margin-left: auto;
        background: transparent;
        border: 0;
        color: #fff;
        font-size: 18px;
        cursor: pointer;
    }

    @media (max-width: 992px) {
        .stat-grid {
            grid-template-columns: 1fr;
        }

        .production-header,
        .list-head {
            flex-direction: column;
            align-items: stretch;
        }

        .search-input {
            width: 100%;
            min-width: 100%;
        }

        .production-table {
            min-width: 900px;
        }

        .table-responsive {
            overflow-x: auto;
        }
    }
    </style>
</head>

<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
    <div id="mobileOverlay"></div>

    <div class="app-shell">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main id="main">
            <?php
            if (file_exists(__DIR__ . '/includes/nav.php')) {
                include __DIR__ . '/includes/nav.php';
            } elseif (file_exists(__DIR__ . '/includes/topbar.php')) {
                include __DIR__ . '/includes/topbar.php';
            }
            ?>

            <div class="toast-wrap" id="toastWrap"></div>

            <div class="production-page">

                <div class="production-card production-header">
                    <div>
                        <h1 class="production-title">Production Tracking</h1>
                        <div class="production-subtitle">
                            Track design, approval, printing, cutting, quality and dispatch stages.
                        </div>
                    </div>

                </div>

                <div class="production-card filter-card">
                    <div class="stage-tabs">
                        <a class="stage-tab <?= $stageFilter === 'all' ? 'active' : '' ?>"
                            href="production_tracking.php">
                            All
                        </a>

                        <?php foreach ($stages as $key => $label): ?>
                        <a class="stage-tab <?= $stageFilter === $key ? 'active' : '' ?>"
                            href="production_tracking.php?stage=<?= e($key) ?>">
                            <?= e($label) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!$tableExists): ?>
                <div class="error-box">
                    production_jobs table is missing. Run the SQL file first.
                </div>
                <?php endif; ?>

                <div class="stat-grid">
                    <div class="production-card stat-card">
                        <div class="stat-icon blue">
                            <i class="fa fa-industry"></i>
                        </div>
                        <div>
                            <div class="stat-label">Total Jobs</div>
                            <div class="stat-value"><?= e($totalJobs) ?></div>
                        </div>
                    </div>

                    <div class="production-card stat-card">
                        <div class="stat-icon orange">
                            <i class="fa fa-wave-square"></i>
                        </div>
                        <div>
                            <div class="stat-label">Active</div>
                            <div class="stat-value"><?= e($activeJobs) ?></div>
                        </div>
                    </div>

                    <div class="production-card stat-card">
                        <div class="stat-icon green">
                            <i class="fa fa-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-label">Completed</div>
                            <div class="stat-value"><?= e($completedJobs) ?></div>
                        </div>
                    </div>
                </div>

                <div class="production-card list-card">
                    <div class="list-head">
                        <div>
                            <h2 class="section-title">All Production Jobs</h2>
                            <div class="section-subtitle">Desktop table and mobile responsive view.</div>
                        </div>

                        <form method="get">
                            <?php if ($stageFilter !== 'all'): ?>
                            <input type="hidden" name="stage" value="<?= e($stageFilter) ?>">
                            <?php endif; ?>

                            <input type="text" name="search" class="search-input" placeholder="Search job..."
                                value="<?= e($search) ?>">
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="production-table">
                            <thead>
                                <tr>
                                    <th>Job</th>
                                    <th>Customer</th>
                                    <th>Item</th>
                                    <th>Stage</th>
                                    <th>Delivery</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$tableExists || empty($jobs)): ?>
                                <tr>
                                    <td colspan="7" class="empty-row">No jobs found.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($jobs as $job): ?>
                                <?php
                                $jobStage = $job['current_stage'] ?? '';
                                $jobStatus = $job['status'] ?? 'active';
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= e($job['job_no'] ?? '') ?></strong>
                                        <div class="text-muted small">Qty: <?= e($job['qty'] ?? 0) ?></div>
                                    </td>

                                    <td>
                                        <strong><?= e($job['customer_name'] ?? '') ?></strong>
                                        <div class="text-muted small"><?= e($job['mobile'] ?? '') ?></div>
                                    </td>

                                    <td><?= e($job['item_name'] ?? '') ?></td>

                                    <td>
                                        <span class="badge-stage">
                                            <?= e($stages[$jobStage] ?? $jobStage) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= !empty($job['delivery_date']) ? e(date('d-m-Y', strtotime($job['delivery_date']))) : '-' ?>
                                    </td>

                                    <td>
                                        <?php if ($jobStatus === 'completed'): ?>
                                        <span class="badge-completed">Completed</span>
                                        <?php elseif ($jobStatus === 'cancelled'): ?>
                                        <span class="badge-cancelled">Cancelled</span>
                                        <?php else: ?>
                                        <span class="badge-active">Active</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="action-row">
                                            <?php if ($canUpdate): ?>
                                            <form method="post" style="display:flex; gap:6px;">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="update_stage">
                                                <input type="hidden" name="job_id" value="<?= e($job['id'] ?? 0) ?>">

                                                <select name="current_stage" class="stage-select">
                                                    <?php foreach ($stages as $key => $label): ?>
                                                    <option value="<?= e($key) ?>"
                                                        <?= $jobStage === $key ? 'selected' : '' ?>>
                                                        <?= e($label) ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <button type="submit" class="mini-btn">Update</button>
                                            </form>

                                            <?php if ($jobStatus !== 'completed'): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="job_id" value="<?= e($job['id'] ?? 0) ?>">
                                                <input type="hidden" name="status" value="completed">
                                                <button type="submit" class="mini-btn success">Complete</button>
                                            </form>
                                            <?php endif; ?>

                                            <?php if ($jobStatus !== 'cancelled'): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="job_id" value="<?= e($job['id'] ?? 0) ?>">
                                                <input type="hidden" name="status" value="cancelled">
                                                <button type="submit" class="mini-btn warning">Cancel</button>
                                            </form>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <span class="text-muted small">No permission</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if ($tableExists && $canCreate): ?>
            <div class="modal fade" id="createJobModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <form method="post" class="modal-content">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_job">

                        <div class="modal-header">
                            <h5 class="modal-title fw-bold">Create Production Job</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Job No *</label>
                                    <input type="text" name="job_no" class="form-control" placeholder="JOB-001"
                                        required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Customer Name *</label>
                                    <input type="text" name="customer_name" class="form-control" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Mobile</label>
                                    <input type="text" name="mobile" class="form-control">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Item Name *</label>
                                    <input type="text" name="item_name" class="form-control"
                                        placeholder="Wedding Invitation Card" required>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Qty</label>
                                    <input type="number" name="qty" class="form-control" value="1" min="1">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Delivery Date</label>
                                    <input type="date" name="delivery_date" class="form-control">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Current Stage</label>
                                    <select name="current_stage" class="form-select">
                                        <?php foreach ($stages as $key => $label): ?>
                                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-12">
                                    <label class="form-label fw-bold">Notes</label>
                                    <textarea name="notes" class="form-control" rows="3"
                                        placeholder="Enter production notes..."></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-light fw-bold" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary fw-bold">Save Job</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <script>
            function showToast(type, message) {
                const wrap = document.getElementById('toastWrap');

                let title = 'Message';
                let icon = 'fa-info';

                if (type === 'success') {
                    title = 'Success';
                    icon = 'fa-check';
                }

                if (type === 'error') {
                    title = 'Error';
                    icon = 'fa-times';
                }

                if (type === 'warning') {
                    title = 'Warning';
                    icon = 'fa-exclamation';
                }

                const toast = document.createElement('div');
                toast.className = 'custom-toast ' + type;

                toast.innerHTML = `
        <div class="toast-icon">
            <i class="fa ${icon}"></i>
        </div>
        <div>
            <div class="toast-title">${title}</div>
            <div class="toast-message">${message}</div>
        </div>
        <button type="button" class="toast-close">&times;</button>
    `;

                wrap.appendChild(toast);

                toast.querySelector('.toast-close').addEventListener('click', function() {
                    toast.remove();
                });

                setTimeout(function() {
                    toast.remove();
                }, 4500);
            }

            <?php if (!empty($_SESSION['toast'])): ?>
            showToast(
                <?= json_encode((string)($_SESSION['toast']['type'] ?? 'success')) ?>,
                <?= json_encode((string)($_SESSION['toast']['message'] ?? '')) ?>
            );
            <?php unset($_SESSION['toast']); ?>
            <?php endif; ?>

            <?php if (!$tableExists): ?>
            showToast('error', 'production_jobs table is missing. Run the SQL file first.');
            <?php endif; ?>
            </script>

        </main>

        <div id="settingsOverlay"></div>
        <?php if (file_exists(__DIR__ . '/includes/rightsidebar.php')): ?>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
        <?php endif; ?>
    </div>

    <?php if (file_exists(__DIR__ . '/includes/script.php')): ?>
    <?php include __DIR__ . '/includes/script.php'; ?>
    <?php endif; ?>
</body>

</html>