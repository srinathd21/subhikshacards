<?php
require_once __DIR__ . '/includes/auth.php';

if (function_exists('require_permission')) {
    require_permission($conn, 'can_view', 'job_cards.php');
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

function caTableExists(mysqli $conn, string $table): bool
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

function caColExists(mysqli $conn, string $table, string $col): bool
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

function caDate($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function caDateTime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime((string)$value)) : '-';
}

function caMoney($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function caApprovalLabel(?string $type): string
{
    $type = strtolower(trim((string)$type));
    if ($type === 'proof_approval') return 'Proof Approval';
    if ($type === 'design_approval') return 'Design Approval';
    if ($type === 'confirmation') return 'Confirmation';
    return $type !== '' ? ucwords(str_replace('_', ' ', $type)) : 'Customer Approval';
}

function caStatusClass(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'approved') return 'success';
    if (in_array($status, ['rejected', 'correction_requested'], true)) return 'danger';
    if ($status === 'expired') return 'muted';
    return 'warning';
}

function caApprovalTypeFromStep(string $stepKey): string
{
    $stepKey = strtolower(trim($stepKey));
    if ($stepKey === 'proofing_approval') return 'proof_approval';
    if ($stepKey === 'design_approval') return 'design_approval';
    return 'confirmation';
}

$message = '';
$messageType = 'success';
$toastTitle = 'Info';
$filter = strtolower(trim((string)($_GET['status'] ?? 'waiting')));
$allowedFilters = ['waiting', 'approved', 'rejected', 'expired', 'all'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'waiting';
}

$rows = [];
$stats = [
    'waiting' => 0,
    'approved' => 0,
    'rejected' => 0,
    'expired' => 0,
    'all' => 0,
];

if (!caTableExists($conn, 'job_tracking') || !caTableExists($conn, 'workflow_steps') || !caTableExists($conn, 'job_cards')) {
    $message = 'Required tables are missing: job_tracking, workflow_steps, or job_cards.';
    $messageType = 'danger';
    $toastTitle = 'Failed';
} else {
    try {
        $hasApprovalTable = caTableExists($conn, 'customer_approvals');

        $approvalSelect = $hasApprovalTable ? "
            ca.id AS approval_id,
            ca.approval_type,
            ca.approval_token,
            ca.customer_name AS approval_customer_name,
            ca.mobile AS approval_mobile,
            ca.status AS approval_status,
            ca.approved_by_customer,
            ca.approved_by_call,
            ca.call_confirmed_by,
            ca.customer_remarks,
            ca.internal_remarks,
            ca.approved_at,
            ca.rejected_at,
            ca.expires_at,
            ca.ip_address,
            ca.user_agent,
            ca.link_sent_at,
            ca.link_sent_by,
            ca.created_at AS approval_created_at,
            ca.updated_at AS approval_updated_at,
            call_user.username AS call_confirmed_by_name,
            sent_user.username AS link_sent_by_name
        " : "
            NULL AS approval_id,
            NULL AS approval_type,
            NULL AS approval_token,
            NULL AS approval_customer_name,
            NULL AS approval_mobile,
            NULL AS approval_status,
            NULL AS approved_by_customer,
            NULL AS approved_by_call,
            NULL AS call_confirmed_by,
            NULL AS customer_remarks,
            NULL AS internal_remarks,
            NULL AS approved_at,
            NULL AS rejected_at,
            NULL AS expires_at,
            NULL AS ip_address,
            NULL AS user_agent,
            NULL AS link_sent_at,
            NULL AS link_sent_by,
            NULL AS approval_created_at,
            NULL AS approval_updated_at,
            NULL AS call_confirmed_by_name,
            NULL AS link_sent_by_name
        ";

        $approvalJoin = '';
        if ($hasApprovalTable) {
            $approvalJoin = "
                LEFT JOIN (
                    SELECT ca1.*
                    FROM customer_approvals ca1
                    INNER JOIN (
                        SELECT job_card_id, workflow_step_id, MAX(id) AS max_id
                        FROM customer_approvals
                        GROUP BY job_card_id, workflow_step_id
                    ) latest_ca ON latest_ca.max_id = ca1.id
                ) ca ON ca.job_card_id = jt.job_card_id AND ca.workflow_step_id = jt.workflow_step_id
                LEFT JOIN users call_user ON call_user.id = ca.call_confirmed_by
                LEFT JOIN users sent_user ON sent_user.id = ca.link_sent_by
            ";
        }

        $sql = "
            SELECT
                jt.id AS tracking_id,
                jt.status AS tracking_status,
                jt.planned_start_date,
                jt.planned_completion_date,
                jt.revised_completion_date,
                jt.actual_start_at,
                jt.actual_completed_at,
                jt.remarks AS tracking_remarks,
                jt.delay_remarks,
                jt.delay_days,
                jt.delay_started_at,
                ws.id AS workflow_step_id,
                ws.step_key,
                ws.step_name,
                ws.sort_order,
                ws.is_approval_step,
                rr.role_name AS responsible_role_name,
                ru.username AS responsible_user_name,
                cu.username AS completed_by_name,
                dr.reason_name AS delay_reason_name,
                jc.id AS job_card_id,
                jc.job_card_no,
                jc.tracking_token,
                jc.order_type,
                jc.customer_name,
                jc.mobile,
                jc.product_name,
                jc.final_amount,
                jc.advance_amount,
                jc.balance_amount,
                jc.delivery_date,
                jc.created_at AS job_created_at,
                jcs.status_name AS job_status_name,
                ft.function_name,
                pt.printing_name,
                pst.sub_type_name,
                {$approvalSelect}
            FROM job_tracking jt
            INNER JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
            INNER JOIN job_cards jc ON jc.id = jt.job_card_id
            LEFT JOIN roles rr ON rr.id = jt.responsible_role_id
            LEFT JOIN users ru ON ru.id = jt.responsible_user_id
            LEFT JOIN users cu ON cu.id = jt.completed_by
            LEFT JOIN delay_reasons dr ON dr.id = jt.delay_reason_id
            LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
            LEFT JOIN function_types ft ON ft.id = jc.function_type_id
            LEFT JOIN printing_types pt ON pt.id = jc.printing_type_id
            LEFT JOIN printing_sub_types pst ON pst.id = jc.printing_sub_type_id
            {$approvalJoin}
            WHERE (
                COALESCE(ws.is_approval_step, 0) = 1
                OR ws.step_key IN ('proofing_approval', 'design_approval')
                OR LOWER(ws.step_name) LIKE '%approval%'
            )
            ORDER BY
                CASE
                    WHEN COALESCE(ca.status, 'pending') = 'pending' THEN 1
                    WHEN COALESCE(ca.status, 'pending') = 'approved' THEN 2
                    WHEN COALESCE(ca.status, 'pending') = 'rejected' THEN 3
                    WHEN COALESCE(ca.status, 'pending') = 'expired' THEN 4
                    ELSE 5
                END,
                jc.id DESC,
                ws.sort_order ASC,
                jt.id ASC
        ";

        if (!$hasApprovalTable) {
            $sql = str_replace("COALESCE(ca.status, 'pending')", "'pending'", $sql);
        }

        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) {
            $status = strtolower(trim((string)($row['approval_status'] ?? '')));
            if ($status === '') $status = 'pending';

            $approvedByCustomer = (int)($row['approved_by_customer'] ?? 0) === 1;
            $approvedByCall = (int)($row['approved_by_call'] ?? 0) === 1;
            if ($status === 'pending' && ($approvedByCustomer || $approvedByCall)) {
                $status = 'approved';
            }

            $bucket = 'waiting';
            if ($status === 'approved') $bucket = 'approved';
            elseif (in_array($status, ['rejected', 'correction_requested'], true)) $bucket = 'rejected';
            elseif ($status === 'expired') $bucket = 'expired';

            $row['computed_status'] = $status;
            $row['computed_bucket'] = $bucket;
            $row['computed_approval_type'] = $row['approval_type'] ?: caApprovalTypeFromStep((string)($row['step_key'] ?? ''));

            $stats['all']++;
            $stats[$bucket]++;

            if ($filter === 'all' || $filter === $bucket) {
                $rows[] = $row;
            }
        }
        if ($res) $res->free();
    } catch (Throwable $e) {
        $message = 'Approval list query error: ' . $e->getMessage();
        $messageType = 'danger';
        $toastTitle = 'Failed';
        $rows = [];
    }
}

$pageTitle = 'Customer Approvals';
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
    .view-info-card{border:1px solid var(--border-soft);border-radius:16px;padding:14px 16px;background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));height:100%}
    .view-info-card small{display:block;color:var(--text-muted);font-size:11px;font-weight:900;text-transform:uppercase;margin-bottom:4px}
    .view-info-card strong,.view-info-card span{display:block;color:var(--text-main);font-weight:900;word-break:break-word;white-space:pre-wrap}
    .toast-ui{border:0;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.18);overflow:hidden;min-width:320px;max-width:420px}.toast-ui.success{background:#dcfce7;color:#14532d}.toast-ui.danger{background:#fee2e2;color:#7f1d1d}.toast-ui.warning{background:#fef3c7;color:#78350f}.toast-title{font-size:14px;font-weight:900;margin-bottom:2px}.toast-message{font-size:13px;font-weight:800;line-height:1.45}
    .module-page .page-head{padding:24px 28px;margin-bottom:18px}.module-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}.module-card{padding:24px}.module-title{font-size:18px;font-weight:900;color:var(--text-main);margin:0}
    .stat-card{padding:18px;min-height:112px;display:flex;align-items:center;gap:14px}.stat-icon{width:52px;height:52px;border-radius:16px;display:grid;place-items:center;color:#fff;flex:0 0 auto}.stat-card span{display:block;font-size:12px;color:var(--text-muted);font-weight:900;text-transform:uppercase}.stat-card strong{font-size:24px;font-weight:900;color:var(--text-main)}
    .filter-tabs{display:flex;gap:8px;flex-wrap:wrap}.filter-tab{border:1px solid var(--border-soft);background:var(--card-bg);color:var(--text-main);border-radius:999px;padding:8px 14px;font-size:12px;font-weight:900;text-decoration:none}.filter-tab.active{background:#2563eb;border-color:#2563eb;color:#fff}.filter-tab.danger.active{background:#dc2626;border-color:#dc2626}.filter-tab.success.active{background:#16a34a;border-color:#16a34a}
    .status-pill{font-size:11px;font-weight:900;border-radius:999px;padding:5px 9px;background:color-mix(in srgb,var(--info-color) 14%,transparent);color:var(--info-color);display:inline-flex}.status-pill.success{color:#166534;background:#dcfce7}.status-pill.warning{color:#92400e;background:#fef3c7}.status-pill.danger{color:#991b1b;background:#fee2e2}.status-pill.muted{color:#475569;background:#e2e8f0}
    .amount-text{font-weight:900;color:var(--text-main);white-space:nowrap}.btn-action-icon{width:36px!important;height:36px!important;min-width:36px!important;max-width:36px!important;padding:0!important;border-radius:50%!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;line-height:1!important}.btn-action-icon svg{width:16px!important;height:16px!important;stroke-width:2.5!important}
    .desktop-table{overflow-x:auto}.table-ui th{white-space:nowrap}.table-ui td{vertical-align:middle}.mobile-cards{display:none}.mobile-card{border:1px solid var(--border-soft);background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));border-radius:18px;padding:16px;margin-bottom:12px}.mobile-card-title{font-size:16px;font-weight:900;color:var(--text-main)}.mobile-card-subtitle{display:block;color:var(--text-muted);font-size:12px;font-weight:700;margin-top:4px;word-break:break-word}.mobile-card-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
    .approval-card-danger{border-color:#fecaca!important;background:color-mix(in srgb,#fee2e2 38%,var(--card-bg))!important}.modal-content{border:0;border-radius:22px;background:var(--card-bg);color:var(--text-main)}.modal-header,.modal-footer{border-color:var(--border-soft)}
    @media(max-width:767.98px){.module-page .page-head{padding:18px;border-radius:18px}.module-page .page-head h1{font-size:24px}.module-page .page-head .btn{width:100%}.module-card{padding:16px;border-radius:18px}.desktop-table{display:none!important}.mobile-cards{display:block}.mobile-card-actions .btn{width:100%}.mobile-card .status-pill{font-size:10px;max-width:130px;white-space:normal;text-align:center}.filter-tabs{display:grid;grid-template-columns:1fr 1fr}.filter-tab{text-align:center}.btn-action-icon{width:42px!important;height:42px!important}}
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
                        <h1 class="mb-1">Customer Approvals</h1>
                        <p class="text-muted-custom mb-0">Waiting customer approvals with related job card details.</p>
                    </div>
                    <a href="job_cards.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Back to Job Cards</a>
                </div>
            </div>

            <?php if ($message !== ''): ?>
            <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000">
                <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4200">
                    <div class="d-flex">
                        <div class="toast-body"><div class="toast-title"><?= e($toastTitle) ?></div><div class="toast-message"><?= e($message) ?></div></div>
                        <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)"><i data-lucide="clock"></i></div><div><span>Waiting</span><strong><?= number_format($stats['waiting']) ?></strong></div></div></div>
                <div class="col-6 col-md-3"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)"><i data-lucide="check-circle-2"></i></div><div><span>Approved</span><strong><?= number_format($stats['approved']) ?></strong></div></div></div>
                <div class="col-6 col-md-3"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#dc2626,#f43f5e)"><i data-lucide="x-circle"></i></div><div><span>Rejected</span><strong><?= number_format($stats['rejected']) ?></strong></div></div></div>
                <div class="col-6 col-md-3"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)"><i data-lucide="list-checks"></i></div><div><span>Total</span><strong><?= number_format($stats['all']) ?></strong></div></div></div>
            </div>

            <div class="card-ui module-card">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                    <div>
                        <h2 class="module-title">Approval List</h2>
                        <p class="text-muted-custom mb-0">Default view shows approvals waiting from customers.</p>
                    </div>
                    <input type="search" id="tableSearch" class="form-control" style="max-width:340px" placeholder="Search approval / job card...">
                </div>

                <div class="filter-tabs mb-3">
                    <a class="filter-tab <?= $filter === 'waiting' ? 'active' : '' ?>" href="customer_approvals.php?status=waiting">Waiting (<?= number_format($stats['waiting']) ?>)</a>
                    <a class="filter-tab success <?= $filter === 'approved' ? 'active' : '' ?>" href="customer_approvals.php?status=approved">Approved (<?= number_format($stats['approved']) ?>)</a>
                    <a class="filter-tab danger <?= $filter === 'rejected' ? 'active' : '' ?>" href="customer_approvals.php?status=rejected">Rejected (<?= number_format($stats['rejected']) ?>)</a>
                    <a class="filter-tab <?= $filter === 'expired' ? 'active' : '' ?>" href="customer_approvals.php?status=expired">Expired (<?= number_format($stats['expired']) ?>)</a>
                    <a class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>" href="customer_approvals.php?status=all">All (<?= number_format($stats['all']) ?>)</a>
                </div>

                <div class="table-responsive desktop-table">
                    <table class="table-ui" id="dataTable">
                        <thead>
                            <tr>
                                <th>Job Card</th>
                                <th>Customer</th>
                                <th>Product / Printing</th>
                                <th>Approval Stage</th>
                                <th>Amount</th>
                                <th>Delivery</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                            <tr><td colspan="8" class="text-center text-muted-custom py-4">No approvals found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $row): ?>
                            <?php
                                $bucket = (string)($row['computed_bucket'] ?? 'waiting');
                                $status = (string)($row['computed_status'] ?? 'pending');
                                $approvalType = (string)($row['computed_approval_type'] ?? 'confirmation');
                                $statusClass = caStatusClass($status);
                            ?>
                            <tr>
                                <td><strong><?= e($row['job_card_no'] ?? '-') ?></strong><small class="d-block text-muted-custom">#<?= e($row['job_card_id'] ?? '-') ?></small></td>
                                <td><?= e($row['customer_name'] ?? '-') ?><small class="d-block text-muted-custom"><?= e($row['mobile'] ?? '-') ?></small></td>
                                <td><?= e($row['product_name'] ?? '-') ?><small class="d-block text-muted-custom"><?= e($row['printing_name'] ?? '-') ?> <?= e($row['sub_type_name'] ?? '') ?></small></td>
                                <td><strong><?= e($row['step_name'] ?? '-') ?></strong><small class="d-block text-muted-custom"><?= e(caApprovalLabel($approvalType)) ?></small></td>
                                <td><span class="amount-text"><?= e(caMoney($row['final_amount'] ?? 0)) ?></span><small class="d-block text-muted-custom">Bal: <?= e(caMoney($row['balance_amount'] ?? 0)) ?></small></td>
                                <td><?= e(caDate($row['delivery_date'] ?? null)) ?></td>
                                <td><span class="status-pill <?= e($statusClass) ?>"><?= e($bucket === 'waiting' ? 'Waiting' : ucwords(str_replace('_', ' ', $status))) ?></span></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle fw-bold js-view-record btn-action-icon"
                                        data-bs-toggle="modal" data-bs-target="#viewModal"
                                        <?php foreach ($row as $key => $value): ?>
                                            data-<?= e(str_replace('_', '-', $key)) ?>="<?= e($value) ?>"
                                        <?php endforeach; ?>
                                        data-approval-label="<?= e(caApprovalLabel($approvalType)) ?>"
                                        data-display-status="<?= e($bucket === 'waiting' ? 'Waiting' : ucwords(str_replace('_', ' ', $status))) ?>"
                                    ><i data-lucide="eye"></i></button>
                                    <a href="job_card_view.php?id=<?= e($row['job_card_id'] ?? 0) ?>" class="btn btn-sm btn-outline-primary rounded-circle fw-bold btn-action-icon" title="Open Job Card"><i data-lucide="briefcase-business"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mobile-cards" id="mobileCards">
                    <?php if (!$rows): ?><div class="mobile-card text-center text-muted-custom">No approvals found.</div><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                    <?php
                        $bucket = (string)($row['computed_bucket'] ?? 'waiting');
                        $status = (string)($row['computed_status'] ?? 'pending');
                        $approvalType = (string)($row['computed_approval_type'] ?? 'confirmation');
                        $statusClass = caStatusClass($status);
                    ?>
                    <div class="mobile-card">
                        <div class="d-flex justify-content-between gap-2">
                            <div>
                                <div class="mobile-card-title"><?= e($row['job_card_no'] ?? '-') ?></div>
                                <span class="mobile-card-subtitle">Customer: <?= e($row['customer_name'] ?? '-') ?> | <?= e($row['mobile'] ?? '-') ?></span>
                                <span class="mobile-card-subtitle">Stage: <?= e($row['step_name'] ?? '-') ?></span>
                                <span class="mobile-card-subtitle">Approval: <?= e(caApprovalLabel($approvalType)) ?></span>
                                <span class="mobile-card-subtitle">Delivery: <?= e(caDate($row['delivery_date'] ?? null)) ?></span>
                            </div>
                            <span class="status-pill <?= e($statusClass) ?>"><?= e($bucket === 'waiting' ? 'Waiting' : ucwords(str_replace('_', ' ', $status))) ?></span>
                        </div>
                        <div class="mobile-card-actions">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle fw-bold js-view-record btn-action-icon" data-bs-toggle="modal" data-bs-target="#viewModal"
                                <?php foreach ($row as $key => $value): ?>
                                    data-<?= e(str_replace('_', '-', $key)) ?>="<?= e($value) ?>"
                                <?php endforeach; ?>
                                data-approval-label="<?= e(caApprovalLabel($approvalType)) ?>"
                                data-display-status="<?= e($bucket === 'waiting' ? 'Waiting' : ucwords(str_replace('_', ' ', $status))) ?>"
                            ><i data-lucide="eye"></i></button>
                            <a href="job_card_view.php?id=<?= e($row['job_card_id'] ?? 0) ?>" class="btn btn-sm btn-outline-primary rounded-circle fw-bold btn-action-icon"><i data-lucide="briefcase-business"></i></a>
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
                    <h5 class="modal-title fw-bold">Customer Approval Details</h5>
                    <small class="text-muted-custom" id="viewJobCardNo">-</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-3"><div class="view-info-card"><small>Job Card</small><strong id="m_job_card_no">-</strong></div></div>
                    <div class="col-md-3"><div class="view-info-card"><small>Job Status</small><strong id="m_job_status_name">-</strong></div></div>
                    <div class="col-md-3"><div class="view-info-card"><small>Customer</small><strong id="m_customer_name">-</strong></div></div>
                    <div class="col-md-3"><div class="view-info-card"><small>Mobile</small><strong id="m_mobile">-</strong></div></div>
                    <div class="col-md-3"><div class="view-info-card"><small>Product</small><strong id="m_product_name">-</strong></div></div>
                    <div class="col-md-3"><div class="view-info-card"><small>Printing</small><strong id="m_printing_name">-</strong></div></div>
                    <div class="col-md-3"><div class="view-info-card"><small>Delivery Date</small><strong id="m_delivery_date">-</strong></div></div>
                    <div class="col-md-3"><div class="view-info-card"><small>Balance</small><strong id="m_balance_amount">-</strong></div></div>
                    <div class="col-md-3"><div class="view-info-card"><small>Stage</small><strong id="m_step_name">-</strong></div></div>
                    <div class="col-md-3"><div class="view-info-card"><small>Approval Type</small><strong id="m_approval_label">-</strong></div></div>
                    <div class="col-md-3"><div class="view-info-card"><small>Status</small><strong id="m_display_status">-</strong></div></div>
                    <div class="col-md-3"><div class="view-info-card"><small>Approval ID</small><strong id="m_approval_id">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Approved By Customer</small><strong id="m_approved_by_customer">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Approved By Call</small><strong id="m_approved_by_call">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Call Confirmed By</small><strong id="m_call_confirmed_by_name">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Approved At</small><strong id="m_approved_at">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Rejected At</small><strong id="m_rejected_at">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Expires At</small><strong id="m_expires_at">-</strong></div></div>
                    <div class="col-12"><div class="view-info-card"><small>Approval Token</small><span id="m_approval_token">-</span></div></div>
                    <div class="col-md-6"><div class="view-info-card"><small>Customer Remarks</small><span id="m_customer_remarks">-</span></div></div>
                    <div class="col-md-6"><div class="view-info-card"><small>Internal Remarks</small><span id="m_internal_remarks">-</span></div></div>
                    <div class="col-md-6"><div class="view-info-card"><small>Tracking Remarks</small><span id="m_tracking_remarks">-</span></div></div>
                    <div class="col-md-6"><div class="view-info-card"><small>Delay Remarks</small><span id="m_delay_remarks">-</span></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Planned Completion</small><strong id="m_planned_completion_date">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>Actual Completed</small><strong id="m_actual_completed_at">-</strong></div></div>
                    <div class="col-md-4"><div class="view-info-card"><small>IP Address</small><strong id="m_ip_address">-</strong></div></div>
                    <div class="col-12"><div class="view-info-card"><small>User Agent</small><span id="m_user_agent">-</span></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" id="modalJobLink" class="btn btn-primary rounded-pill px-4 fw-bold">Open Job Card</a>
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/script.php'; ?>
<script>
(function(){
    const pageToastEl = document.getElementById('pageToast');
    if (pageToastEl && window.bootstrap && bootstrap.Toast) bootstrap.Toast.getOrCreateInstance(pageToastEl).show();

    function setText(id, value){
        const el = document.getElementById(id);
        if(!el) return;
        const clean = (value == null || String(value).trim() === '') ? '-' : String(value);
        el.textContent = clean;
    }

    function fmtMoney(value){
        const n = Number(value || 0);
        return '₹' + n.toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
    }

    function fmtDate(value, withTime){
        if(!value || value === '-') return '-';
        const d = new Date(String(value).replace(' ', 'T'));
        if(isNaN(d.getTime())) return value;
        const date = d.toLocaleDateString('en-GB').replace(/\//g, '-');
        if(!withTime) return date;
        return date + ' ' + d.toLocaleTimeString('en-IN', {hour:'2-digit', minute:'2-digit'});
    }

    document.querySelectorAll('.js-view-record').forEach(function(btn){
        btn.addEventListener('click', function(){
            setText('viewJobCardNo', btn.dataset.jobCardNo || '-');
            setText('m_job_card_no', btn.dataset.jobCardNo || '-');
            setText('m_job_status_name', btn.dataset.jobStatusName || '-');
            setText('m_customer_name', btn.dataset.customerName || '-');
            setText('m_mobile', btn.dataset.mobile || '-');
            setText('m_product_name', btn.dataset.productName || '-');
            setText('m_printing_name', [btn.dataset.printingName || '-', btn.dataset.subTypeName || ''].join(' ').trim());
            setText('m_delivery_date', fmtDate(btn.dataset.deliveryDate || '', false));
            setText('m_balance_amount', fmtMoney(btn.dataset.balanceAmount || 0));
            setText('m_step_name', btn.dataset.stepName || '-');
            setText('m_approval_label', btn.dataset.approvalLabel || '-');
            setText('m_display_status', btn.dataset.displayStatus || '-');
            setText('m_approval_id', btn.dataset.approvalId || '-');
            setText('m_approved_by_customer', Number(btn.dataset.approvedByCustomer || 0) === 1 ? 'Yes' : 'No');
            setText('m_approved_by_call', Number(btn.dataset.approvedByCall || 0) === 1 ? 'Yes' : 'No');
            setText('m_call_confirmed_by_name', btn.dataset.callConfirmedByName || '-');
            setText('m_approved_at', fmtDate(btn.dataset.approvedAt || '', true));
            setText('m_rejected_at', fmtDate(btn.dataset.rejectedAt || '', true));
            setText('m_expires_at', fmtDate(btn.dataset.expiresAt || '', true));
            setText('m_approval_token', btn.dataset.approvalToken || '-');
            setText('m_customer_remarks', btn.dataset.customerRemarks || '-');
            setText('m_internal_remarks', btn.dataset.internalRemarks || '-');
            setText('m_tracking_remarks', btn.dataset.trackingRemarks || '-');
            setText('m_delay_remarks', btn.dataset.delayRemarks || '-');
            setText('m_planned_completion_date', fmtDate(btn.dataset.plannedCompletionDate || '', false));
            setText('m_actual_completed_at', fmtDate(btn.dataset.actualCompletedAt || '', true));
            setText('m_ip_address', btn.dataset.ipAddress || '-');
            setText('m_user_agent', btn.dataset.userAgent || '-');
            const link = document.getElementById('modalJobLink');
            if(link) link.href = 'job_card_view.php?id=' + encodeURIComponent(btn.dataset.jobCardId || '0');
        });
    });

    document.getElementById('tableSearch')?.addEventListener('input', function(){
        const value = this.value.toLowerCase().trim();
        document.querySelectorAll('#dataTable tbody tr').forEach(function(row){
            row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
        });
        document.querySelectorAll('#mobileCards .mobile-card').forEach(function(card){
            card.style.display = card.textContent.toLowerCase().includes(value) ? '' : 'none';
        });
    });

    if(window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
})();
</script>
</body>
</html>
