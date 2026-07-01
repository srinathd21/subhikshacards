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

if (empty($_SESSION['dispatch_csrf'])) {
    $_SESSION['dispatch_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['dispatch_csrf'];
$message = '';
$messageType = 'success';
$toastTitle = 'Info';

function dspTableExists(mysqli $conn, string $table): bool
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

function dspColExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

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

function dspDate($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function dspDateTime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime((string)$value)) : '-';
}

function dspMoney($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function dspPost(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function dspCsrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['dispatch_csrf']) ||
        !hash_equals($_SESSION['dispatch_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function dspStatusId(mysqli $conn, array $keys): ?int
{
    if (!dspTableExists($conn, 'job_card_statuses')) return null;

    foreach ($keys as $key) {
        try {
            $stmt = $conn->prepare("SELECT id FROM job_card_statuses WHERE status_key = ? LIMIT 1");
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) return (int)$row['id'];
        } catch (Throwable $e) {}
    }

    foreach ($keys as $key) {
        try {
            $like = '%' . str_replace('_', '%', $key) . '%';
            $stmt = $conn->prepare("SELECT id FROM job_card_statuses WHERE LOWER(status_name) LIKE LOWER(?) ORDER BY id ASC LIMIT 1");
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) return (int)$row['id'];
        } catch (Throwable $e) {}
    }

    return null;
}

function dspLog(mysqli $conn, string $actionKey, int $recordId, string $description): void
{
    try {
        if (!dspTableExists($conn, 'activity_logs')) return;

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (dspColExists($conn, 'activity_logs', 'action_key')) {
            $stmt = $conn->prepare("
                INSERT INTO activity_logs
                    (user_id, role_id, action_key, module_name, record_id, description, ip_address, user_agent, created_at)
                VALUES
                    (?, ?, ?, 'Dispatch', ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param('iisisss', $userId, $roleId, $actionKey, $recordId, $description, $ip, $ua);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {}
}

function dspUpdateColumns(mysqli $conn, string $table, array $data, int $id): void
{
    if (!$data || !dspTableExists($conn, $table)) return;

    $filtered = [];
    foreach ($data as $column => $value) {
        if (dspColExists($conn, $table, $column)) {
            $filtered[$column] = $value;
        }
    }

    if (!$filtered) return;

    $sets = [];
    $types = '';
    $values = [];

    foreach ($filtered as $column => $value) {
        $sets[] = "`{$column}` = ?";
        if (is_int($value)) $types .= 'i';
        elseif (is_float($value)) $types .= 'd';
        else $types .= 's';
        $values[] = $value;
    }

    $types .= 'i';
    $values[] = $id;

    $stmt = $conn->prepare("UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
}

function dspFindDispatchTracking(mysqli $conn, int $jobId): ?array
{
    if (!dspTableExists($conn, 'job_tracking')) return null;

    try {
        $stmt = $conn->prepare("
            SELECT jt.*, ws.step_key, ws.step_name, ws.sort_order
            FROM job_tracking jt
            LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
            WHERE jt.job_card_id = ?
              AND (
                    LOWER(COALESCE(ws.step_key, '')) IN ('dispatch','send_to_dispatch','ready_for_dispatch','delivered')
                 OR LOWER(COALESCE(ws.step_name, '')) LIKE '%dispatch%'
              )
            ORDER BY ws.sort_order ASC, jt.id ASC
            LIMIT 1
        ");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return $row;
    } catch (Throwable $e) {}

    try {
        $stmt = $conn->prepare("
            SELECT jt.*, ws.step_key, ws.step_name, ws.sort_order
            FROM job_tracking jt
            LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
            WHERE jt.job_card_id = ?
              AND jt.status NOT IN ('completed','skipped','cancelled')
            ORDER BY ws.sort_order DESC, jt.id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_dispatched') {
    dspCsrf();

    $jobId = (int)($_POST['job_card_id'] ?? 0);
    $dispatchDate = dspPost('dispatch_date', date('Y-m-d'));
    $deliveryPerson = dspPost('delivery_person');
    $deliveryMode = dspPost('delivery_mode');
    $trackingNo = dspPost('tracking_no');
    $remarks = dspPost('remarks');
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($jobId <= 0) {
        $message = 'Invalid job card.';
        $messageType = 'danger';
        $toastTitle = 'Failed';
    } else {
        try {
            $tracking = dspFindDispatchTracking($conn, $jobId);
            if ($tracking) {
                $trackingId = (int)$tracking['id'];
                $stmt = $conn->prepare("
                    UPDATE job_tracking
                    SET status = 'completed',
                        remarks = ?,
                        actual_start_at = COALESCE(actual_start_at, NOW()),
                        actual_completed_at = NOW(),
                        completed_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND job_card_id = ?
                ");
                $stmt->bind_param('siii', $remarks, $userId, $trackingId, $jobId);
                $stmt->execute();
                $stmt->close();
            }

            $dispatchedStatusId = dspStatusId($conn, ['dispatched', 'dispatch', 'delivered', 'completed']);
            $data = [
                'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s'),
                'dispatched_at' => date('Y-m-d H:i:s', strtotime($dispatchDate . ' ' . date('H:i:s'))),
                'dispatch_date' => $dispatchDate,
                'dispatch_status' => 'dispatched',
                'delivery_mode' => $deliveryMode,
                'delivery_person' => $deliveryPerson,
                'courier_name' => $deliveryMode,
                'tracking_no' => $trackingNo,
                'dispatch_remarks' => $remarks,
                'remarks' => $remarks
            ];

            if ($dispatchedStatusId) {
                $data['job_card_status_id'] = $dispatchedStatusId;
            }

            dspUpdateColumns($conn, 'job_cards', $data, $jobId);
            dspLog($conn, 'dispatch_job_card', $jobId, 'Job card marked as dispatched.');

            header('Location: dispatch.php?msg=dispatched');
            exit;
        } catch (Throwable $e) {
            $message = 'Dispatch update failed: ' . $e->getMessage();
            $messageType = 'danger';
            $toastTitle = 'Failed';
        }
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'dispatched') {
    $message = 'Job card marked as dispatched successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
}

$filter = strtolower(trim((string)($_GET['filter'] ?? 'ready')));
if (!in_array($filter, ['ready', 'dispatched', 'all'], true)) $filter = 'ready';

$rows = [];
$totalReady = 0;
$totalDispatched = 0;
$totalAll = 0;

if (dspTableExists($conn, 'job_cards')) {
    try {
        $statusSelect = dspTableExists($conn, 'job_card_statuses') ? 'jcs.status_name, jcs.status_key,' : "NULL AS status_name, NULL AS status_key,";
        $statusJoin = dspTableExists($conn, 'job_card_statuses') ? 'LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id' : '';

        $sql = "
            SELECT
                jc.*,
                {$statusSelect}
                ft.function_name,
                pt.printing_name,
                pst.sub_type_name,
                ws.step_name AS current_step_name,
                ws.step_key AS current_step_key,
                creator.username AS created_by_name,
                COALESCE(dt.dispatch_tracking_status, '') AS dispatch_tracking_status,
                COALESCE(dt.dispatch_completed_at, NULL) AS dispatch_completed_at,
                COALESCE(dt.dispatch_remarks, '') AS tracking_dispatch_remarks
            FROM job_cards jc
            {$statusJoin}
            LEFT JOIN function_types ft ON ft.id = jc.function_type_id
            LEFT JOIN printing_types pt ON pt.id = jc.printing_type_id
            LEFT JOIN printing_sub_types pst ON pst.id = jc.printing_sub_type_id
            LEFT JOIN workflow_steps ws ON ws.id = jc.current_workflow_step_id
            LEFT JOIN users creator ON creator.id = jc.created_by
            LEFT JOIN (
                SELECT
                    jt.job_card_id,
                    MAX(CASE WHEN LOWER(COALESCE(ws2.step_key, '')) IN ('dispatch','send_to_dispatch','ready_for_dispatch','delivered') OR LOWER(COALESCE(ws2.step_name, '')) LIKE '%dispatch%' THEN jt.status ELSE '' END) AS dispatch_tracking_status,
                    MAX(CASE WHEN LOWER(COALESCE(ws2.step_key, '')) IN ('dispatch','send_to_dispatch','ready_for_dispatch','delivered') OR LOWER(COALESCE(ws2.step_name, '')) LIKE '%dispatch%' THEN jt.actual_completed_at ELSE NULL END) AS dispatch_completed_at,
                    MAX(CASE WHEN LOWER(COALESCE(ws2.step_key, '')) IN ('dispatch','send_to_dispatch','ready_for_dispatch','delivered') OR LOWER(COALESCE(ws2.step_name, '')) LIKE '%dispatch%' THEN jt.remarks ELSE '' END) AS dispatch_remarks
                FROM job_tracking jt
                LEFT JOIN workflow_steps ws2 ON ws2.id = jt.workflow_step_id
                GROUP BY jt.job_card_id
            ) dt ON dt.job_card_id = jc.id
            ORDER BY jc.id DESC
            LIMIT 250
        ";

        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) {
            $statusKey = strtolower((string)($row['status_key'] ?? ''));
            $statusName = strtolower((string)($row['status_name'] ?? ''));
            $currentStepKey = strtolower((string)($row['current_step_key'] ?? ''));
            $currentStepName = strtolower((string)($row['current_step_name'] ?? ''));
            $dispatchTrackingStatus = strtolower((string)($row['dispatch_tracking_status'] ?? ''));
            $dispatchCompleted = !empty($row['dispatch_completed_at']);

            $isDispatched = $dispatchCompleted
                || in_array($statusKey, ['dispatched', 'delivered', 'completed'], true)
                || str_contains($statusName, 'dispatch')
                || !empty($row['dispatched_at'] ?? '')
                || strtolower((string)($row['dispatch_status'] ?? '')) === 'dispatched';

            $isReady = !$isDispatched && (
                in_array($currentStepKey, ['dispatch', 'send_to_dispatch', 'ready_for_dispatch'], true)
                || str_contains($currentStepName, 'dispatch')
                || in_array($statusKey, ['ready_for_dispatch', 'ready', 'in_progress'], true)
                || str_contains($statusName, 'ready')
                || $dispatchTrackingStatus === 'in_progress'
                || $dispatchTrackingStatus === 'pending'
            );

            if ($isReady) $totalReady++;
            if ($isDispatched) $totalDispatched++;
            $totalAll++;

            $row['_is_ready'] = $isReady ? 1 : 0;
            $row['_is_dispatched'] = $isDispatched ? 1 : 0;

            if ($filter === 'ready' && !$isReady) continue;
            if ($filter === 'dispatched' && !$isDispatched) continue;

            $rows[] = $row;
        }
        $res->free();
    } catch (Throwable $e) {
        $message = 'Dispatch list query error: ' . $e->getMessage();
        $messageType = 'danger';
        $toastTitle = 'Failed';
        $rows = [];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dispatch - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
        .view-info-card{border:1px solid var(--border-soft);border-radius:16px;padding:14px 16px;background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));height:100%}.view-info-card small{display:block;color:var(--text-muted);font-size:11px;font-weight:900;text-transform:uppercase;margin-bottom:4px}.view-info-card strong,.view-info-card span{display:block;color:var(--text-main);font-weight:900;word-break:break-word;white-space:pre-wrap}.toast-ui{border:0;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.18);overflow:hidden;min-width:320px;max-width:420px}.toast-ui.success{background:#dcfce7;color:#14532d}.toast-ui.danger{background:#fee2e2;color:#7f1d1d}.toast-ui.warning{background:#fef3c7;color:#78350f}.toast-ui .toast-title{font-size:14px;font-weight:900;margin-bottom:2px}.toast-ui .toast-message{font-size:13px;font-weight:800;line-height:1.45}.module-page .page-head{padding:24px 28px;margin-bottom:18px}.module-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}.module-card{padding:24px}.module-title{font-size:18px;font-weight:900;color:var(--text-main);margin:0}.stat-card{padding:18px;min-height:112px;display:flex;align-items:center;gap:14px}.stat-icon{width:52px;height:52px;border-radius:16px;display:grid;place-items:center;color:#fff;flex:0 0 auto}.stat-card span{display:block;font-size:12px;color:var(--text-muted);font-weight:900;text-transform:uppercase}.stat-card strong{font-size:24px;font-weight:900;color:var(--text-main)}.status-pill{font-size:11px;font-weight:900;border-radius:999px;padding:6px 10px;background:color-mix(in srgb,var(--info-color) 14%,transparent);color:var(--info-color);display:inline-flex;align-items:center;white-space:nowrap}.status-pill.ready{background:#fef3c7;color:#92400e}.status-pill.dispatched{background:#dcfce7;color:#166534}.status-pill.danger{background:#fee2e2;color:#991b1b}.filter-chip{border:1px solid var(--border-soft);background:var(--card-bg);color:var(--text-main);border-radius:999px;padding:8px 15px;font-weight:900;font-size:12px;text-decoration:none;display:inline-flex}.filter-chip.active{background:#2563eb;color:#fff;border-color:#2563eb}.form-control,.form-select{border-radius:14px;min-height:46px}.modal-content{border:0;border-radius:22px;background:var(--card-bg);color:var(--text-main)}.modal-header,.modal-footer{border-color:var(--border-soft)}.mobile-cards{display:none}.mobile-card{border:1px solid var(--border-soft);background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));border-radius:18px;padding:16px;margin-bottom:12px}.mobile-card-title{font-size:16px;font-weight:900;color:var(--text-main)}.mobile-card-subtitle{display:block;color:var(--text-muted);font-size:12px;font-weight:700;margin-top:4px;word-break:break-word}.mobile-card-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.btn-action-icon{width:36px!important;height:36px!important;min-width:36px!important;max-width:36px!important;padding:0!important;border-radius:50%!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;line-height:1!important}.btn-action-icon svg{width:16px!important;height:16px!important;stroke-width:2.5!important;flex:0 0 auto!important}@media(max-width:767.98px){.module-page .page-head{padding:18px;border-radius:18px}.module-page .page-head h1{font-size:24px}.module-card{padding:16px;border-radius:18px}.desktop-table{display:none!important}.mobile-cards{display:block}.mobile-card{padding:16px 16px 14px!important;border-radius:20px!important}.mobile-card>.d-flex.justify-content-between{align-items:flex-start!important;gap:12px!important}.mobile-card .status-pill{align-self:flex-start!important;max-width:130px!important;font-size:10px!important;white-space:normal!important}.mobile-card-actions .btn,.mobile-card-actions form{flex:1 1 auto}.mobile-card-actions .btn{width:100%;min-height:38px!important;border-radius:999px!important;font-size:13px!important;font-weight:900!important}.mobile-card-actions .btn-action-icon{width:42px!important;height:42px!important;min-width:42px!important;max-width:42px!important;border-radius:50%!important;justify-self:center!important;margin:0 auto!important}.mobile-card-actions .btn-action-icon svg{width:18px!important;height:18px!important}}
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
                        <h1 class="mb-1">Dispatch</h1>
                        <p class="text-muted-custom mb-0">Manage ready dispatch job cards and dispatched history.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="job_cards.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Job Cards</a>
                    </div>
                </div>
            </div>

            <?php if ($message !== ''): ?>
            <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000">
                <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4200">
                    <div class="d-flex">
                        <div class="toast-body">
                            <div class="toast-title"><?= e($toastTitle) ?></div>
                            <div class="toast-message"><?= e($message) ?></div>
                        </div>
                        <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-4"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)"><i data-lucide="package-check"></i></div><div><span>Ready Dispatch</span><strong><?= number_format($totalReady) ?></strong></div></div></div>
                <div class="col-12 col-md-4"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)"><i data-lucide="truck"></i></div><div><span>Dispatched</span><strong><?= number_format($totalDispatched) ?></strong></div></div></div>
                <div class="col-12 col-md-4"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)"><i data-lucide="clipboard-list"></i></div><div><span>Total Jobs</span><strong><?= number_format($totalAll) ?></strong></div></div></div>
            </div>

            <div class="card-ui module-card">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                    <div>
                        <h2 class="module-title">Dispatch Job Cards</h2>
                        <p class="text-muted-custom mb-0">Ready and dispatched job card details.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <a class="filter-chip <?= $filter === 'ready' ? 'active' : '' ?>" href="dispatch.php?filter=ready">Ready</a>
                        <a class="filter-chip <?= $filter === 'dispatched' ? 'active' : '' ?>" href="dispatch.php?filter=dispatched">Dispatched</a>
                        <a class="filter-chip <?= $filter === 'all' ? 'active' : '' ?>" href="dispatch.php?filter=all">All</a>
                        <input type="search" id="tableSearch" class="form-control" style="max-width:260px" placeholder="Search...">
                    </div>
                </div>

                <div class="table-responsive desktop-table">
                    <table class="table-ui" id="dataTable">
                        <thead>
                            <tr>
                                <th>Job Card</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Delivery Date</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                            <tr><td colspan="7" class="text-center text-muted-custom py-4">No dispatch records found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $row): ?>
                            <?php
                                $isDispatched = (int)($row['_is_dispatched'] ?? 0) === 1;
                                $statusText = $isDispatched ? 'Dispatched' : 'Ready for Dispatch';
                                $statusClass = $isDispatched ? 'dispatched' : 'ready';
                                $dispatchTime = !empty($row['dispatch_completed_at']) ? $row['dispatch_completed_at'] : ($row['dispatched_at'] ?? null);
                            ?>
                            <tr>
                                <td><strong><?= e($row['job_card_no'] ?? '-') ?></strong><small class="d-block text-muted-custom">Current: <?= e($row['current_step_name'] ?? '-') ?></small></td>
                                <td><?= e($row['customer_name'] ?? '-') ?><small class="d-block text-muted-custom"><?= e($row['mobile'] ?? '-') ?></small></td>
                                <td><?= e($row['product_name'] ?? '-') ?><small class="d-block text-muted-custom"><?= e($row['printing_name'] ?? '-') ?> <?= e($row['sub_type_name'] ?? '') ?></small></td>
                                <td><?= e(dspDate($row['delivery_date'] ?? null)) ?></td>
                                <td><?= e(dspMoney($row['balance_amount'] ?? 0)) ?></td>
                                <td><span class="status-pill <?= e($statusClass) ?>"><?= e($statusText) ?></span><?php if ($dispatchTime): ?><small class="d-block text-muted-custom mt-1"><?= e(dspDateTime($dispatchTime)) ?></small><?php endif; ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle fw-bold btn-action-icon js-view-record" data-bs-toggle="modal" data-bs-target="#viewModal"
                                        data-job-no="<?= e($row['job_card_no'] ?? '-') ?>"
                                        data-customer="<?= e($row['customer_name'] ?? '-') ?>"
                                        data-mobile="<?= e($row['mobile'] ?? '-') ?>"
                                        data-product="<?= e($row['product_name'] ?? '-') ?>"
                                        data-printing="<?= e(trim(($row['printing_name'] ?? '-') . ' ' . ($row['sub_type_name'] ?? ''))) ?>"
                                        data-function="<?= e($row['function_name'] ?? '-') ?>"
                                        data-delivery="<?= e(dspDate($row['delivery_date'] ?? null)) ?>"
                                        data-final="<?= e(dspMoney($row['final_amount'] ?? 0)) ?>"
                                        data-advance="<?= e(dspMoney($row['advance_amount'] ?? 0)) ?>"
                                        data-balance="<?= e(dspMoney($row['balance_amount'] ?? 0)) ?>"
                                        data-status="<?= e($statusText) ?>"
                                        data-current-step="<?= e($row['current_step_name'] ?? '-') ?>"
                                        data-dispatched-at="<?= e(dspDateTime($dispatchTime)) ?>"
                                        data-remarks="<?= e(($row['tracking_dispatch_remarks'] ?? '') ?: ($row['dispatch_remarks'] ?? ($row['remarks'] ?? '-'))) ?>">
                                        <i data-lucide="eye"></i>
                                    </button>
                                    <?php if (!$isDispatched): ?>
                                    <button type="button" class="btn btn-sm btn-success rounded-pill fw-bold px-3 ms-1 js-dispatch-record" data-bs-toggle="modal" data-bs-target="#dispatchModal" data-job-id="<?= (int)$row['id'] ?>" data-job-no="<?= e($row['job_card_no'] ?? '-') ?>" data-customer="<?= e($row['customer_name'] ?? '-') ?>">Dispatch</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mobile-cards" id="mobileCards">
                    <?php if (!$rows): ?><div class="mobile-card text-center text-muted-custom">No dispatch records found.</div><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                    <?php
                        $isDispatched = (int)($row['_is_dispatched'] ?? 0) === 1;
                        $statusText = $isDispatched ? 'Dispatched' : 'Ready for Dispatch';
                        $statusClass = $isDispatched ? 'dispatched' : 'ready';
                        $dispatchTime = !empty($row['dispatch_completed_at']) ? $row['dispatch_completed_at'] : ($row['dispatched_at'] ?? null);
                    ?>
                    <div class="mobile-card">
                        <div class="d-flex justify-content-between gap-2">
                            <div>
                                <div class="mobile-card-title"><?= e($row['job_card_no'] ?? '-') ?></div>
                                <span class="mobile-card-subtitle">Customer: <?= e($row['customer_name'] ?? '-') ?> | <?= e($row['mobile'] ?? '-') ?></span>
                                <span class="mobile-card-subtitle">Product: <?= e($row['product_name'] ?? '-') ?></span>
                                <span class="mobile-card-subtitle">Delivery: <?= e(dspDate($row['delivery_date'] ?? null)) ?></span>
                                <span class="mobile-card-subtitle">Balance: <?= e(dspMoney($row['balance_amount'] ?? 0)) ?></span>
                            </div>
                            <span class="status-pill <?= e($statusClass) ?>"><?= e($statusText) ?></span>
                        </div>
                        <div class="mobile-card-actions">
                            <?php if (!$isDispatched): ?>
                            <button type="button" class="btn btn-success rounded-pill fw-bold js-dispatch-record" data-bs-toggle="modal" data-bs-target="#dispatchModal" data-job-id="<?= (int)$row['id'] ?>" data-job-no="<?= e($row['job_card_no'] ?? '-') ?>" data-customer="<?= e($row['customer_name'] ?? '-') ?>">Dispatch</button>
                            <?php endif; ?>
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

<div class="modal fade" id="dispatchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="mark_dispatched">
            <input type="hidden" name="job_card_id" id="dispatchJobId" value="">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold">Mark as Dispatched</h5>
                    <small class="text-muted-custom" id="dispatchJobInfo">-</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-bold">Dispatch Date *</label><input type="date" name="dispatch_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required></div>
                    <div class="col-md-6"><label class="form-label fw-bold">Delivery Mode</label><input type="text" name="delivery_mode" class="form-control" placeholder="Direct / Courier / Parcel"></div>
                    <div class="col-md-6"><label class="form-label fw-bold">Delivery Person</label><input type="text" name="delivery_person" class="form-control" placeholder="Staff / Courier name"></div>
                    <div class="col-md-6"><label class="form-label fw-bold">Tracking / LR No</label><input type="text" name="tracking_no" class="form-control" placeholder="Optional tracking number"></div>
                    <div class="col-12"><label class="form-label fw-bold">Remarks</label><textarea name="remarks" rows="3" class="form-control" placeholder="Dispatch remarks"></textarea></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">Confirm Dispatch</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header"><div><h5 class="modal-title fw-bold">Dispatch Details</h5><small class="text-muted-custom" id="viewJobNo">-</small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4"><div class="view-info-card"><small>Customer</small><strong id="viewCustomer">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Mobile</small><strong id="viewMobile">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Status</small><strong id="viewStatus">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Product</small><strong id="viewProduct">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Printing</small><strong id="viewPrinting">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Function</small><strong id="viewFunction">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Delivery Date</small><strong id="viewDelivery">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Current Step</small><strong id="viewCurrentStep">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Dispatched At</small><strong id="viewDispatchedAt">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Final Amount</small><strong id="viewFinal">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Advance</small><strong id="viewAdvance">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Balance</small><strong id="viewBalance">-</strong></div></div>
                    <div class="col-12"><div class="view-info-card"><small>Remarks</small><span id="viewRemarks">-</span></div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/script.php'; ?>
<script>
(function(){
    function setText(id, value){
        const el = document.getElementById(id);
        if(!el) return;
        el.textContent = value && String(value).trim() ? value : '-';
    }

    const pageToastEl = document.getElementById('pageToast');
    if(pageToastEl && window.bootstrap && bootstrap.Toast){ bootstrap.Toast.getOrCreateInstance(pageToastEl).show(); }

    document.querySelectorAll('.js-dispatch-record').forEach(function(btn){
        btn.addEventListener('click', function(){
            const id = document.getElementById('dispatchJobId');
            if(id) id.value = btn.dataset.jobId || '';
            setText('dispatchJobInfo', (btn.dataset.jobNo || '-') + ' | ' + (btn.dataset.customer || '-'));
        });
    });

    document.querySelectorAll('.js-view-record').forEach(function(btn){
        btn.addEventListener('click', function(){
            setText('viewJobNo', btn.dataset.jobNo || '-');
            setText('viewCustomer', btn.dataset.customer || '-');
            setText('viewMobile', btn.dataset.mobile || '-');
            setText('viewProduct', btn.dataset.product || '-');
            setText('viewPrinting', btn.dataset.printing || '-');
            setText('viewFunction', btn.dataset.function || '-');
            setText('viewDelivery', btn.dataset.delivery || '-');
            setText('viewFinal', btn.dataset.final || '-');
            setText('viewAdvance', btn.dataset.advance || '-');
            setText('viewBalance', btn.dataset.balance || '-');
            setText('viewStatus', btn.dataset.status || '-');
            setText('viewCurrentStep', btn.dataset.currentStep || '-');
            setText('viewDispatchedAt', btn.dataset.dispatchedAt || '-');
            setText('viewRemarks', btn.dataset.remarks || '-');
        });
    });

    document.getElementById('tableSearch')?.addEventListener('input', function(){
        const value = this.value.toLowerCase().trim();
        document.querySelectorAll('#dataTable tbody tr').forEach(function(row){ row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none'; });
        document.querySelectorAll('#mobileCards .mobile-card').forEach(function(card){ card.style.display = card.textContent.toLowerCase().includes(value) ? '' : 'none'; });
    });

    if(window.lucide && typeof window.lucide.createIcons === 'function'){ window.lucide.createIcons(); }
})();
</script>
</body>
</html>
