<?php
/**
 * job_delay_report.php
 * Subhiksha Cards ERP - Job Delay Report
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (function_exists('require_permission')) {
    require_permission($conn, 'can_view', 'reports.php');
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

function jdr_date($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function jdr_datetime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime((string)$value)) : '-';
}

$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'delayed'));

$where = ["(jt.is_delayed = 1 OR jt.status = 'delayed')"];
$params = [];
$types = '';

if ($from !== '') {
    $where[] = "DATE(COALESCE(jt.delay_started_at, jt.updated_at, jt.created_at)) >= ?";
    $types .= 's';
    $params[] = $from;
}

if ($to !== '') {
    $where[] = "DATE(COALESCE(jt.delay_started_at, jt.updated_at, jt.created_at)) <= ?";
    $types .= 's';
    $params[] = $to;
}

if ($status !== '' && $status !== 'all') {
    $where[] = "jt.status = ?";
    $types .= 's';
    $params[] = $status;
}

$rows = [];
try {
    $sql = "
        SELECT
            jt.*,
            jc.job_card_no,
            jc.customer_name,
            jc.mobile,
            ws.step_name,
            ws.step_key,
            rr.role_name AS responsible_department,
            ru.name AS responsible_user,
            dr.reason_name AS delay_reason_name,
            cu.name AS completed_by_name,
            cr.role_name AS completed_by_department
        FROM job_tracking jt
        LEFT JOIN job_cards jc ON jc.id = jt.job_card_id
        LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
        LEFT JOIN roles rr ON rr.id = jt.responsible_role_id
        LEFT JOIN users ru ON ru.id = jt.responsible_user_id
        LEFT JOIN delay_reasons dr ON dr.id = jt.delay_reason_id
        LEFT JOIN users cu ON cu.id = jt.completed_by
        LEFT JOIN roles cr ON cr.id = cu.role_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(jt.delay_started_at, jt.updated_at, jt.created_at) DESC, jt.id DESC
    ";

    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Job Delay Report - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
        .report-page .page-head{padding:24px 28px;margin-bottom:18px}
        .report-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}
        .report-card{padding:24px;border-radius:20px;margin-bottom:18px}
        .planned-date-old{text-decoration:line-through;color:#991b1b;font-weight:900}
        .planned-date-new{color:#166534;font-weight:900;margin-left:7px}
        .delay-badge{display:inline-flex;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:900;background:#fee2e2;color:#991b1b}
        @media print{#sidebar,#mobileOverlay,#settingsOverlay,nav,.no-print,.app-shell>aside{display:none!important}main{margin:0!important}.report-card,.page-head{box-shadow:none!important;border:1px solid #ddd!important}}
    </style>
</head>
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main id="main">
        <?php include __DIR__ . '/includes/nav.php'; ?>

        <section class="page-section report-page">
            <div class="card-ui page-head">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                    <div>
                        <h1 class="mb-1">Job Delay Report</h1>
                        <p class="text-muted-custom mb-0">Delayed stages, responsible role/user, planned vs revised date and completion details.</p>
                    </div>
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold no-print" onclick="window.print()">Print</button>
                </div>
            </div>

            <div class="card-ui report-card no-print">
                <form class="row g-3">
                    <div class="col-md-3"><label class="form-label fw-bold">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label fw-bold">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label fw-bold">Status</label><select name="status" class="form-select"><option value="all" <?= $status==='all'?'selected':'' ?>>All</option><option value="delayed" <?= $status==='delayed'?'selected':'' ?>>Delayed</option><option value="in_progress" <?= $status==='in_progress'?'selected':'' ?>>In Progress</option><option value="completed" <?= $status==='completed'?'selected':'' ?>>Completed</option></select></div>
                    <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary rounded-pill px-4 fw-bold w-100">Filter</button></div>
                </form>
            </div>

            <div class="card-ui report-card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Job</th>
                                <th>Stage</th>
                                <th>Responsible</th>
                                <th>Planned / Revised</th>
                                <th>Delay</th>
                                <th>Completed</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                            <tr><td colspan="7" class="text-center text-muted-custom py-4">No delayed records found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= e($row['job_card_no'] ?? '-') ?></strong>
                                    <small class="d-block text-muted-custom"><?= e($row['customer_name'] ?? '-') ?> · <?= e($row['mobile'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <strong><?= e($row['step_name'] ?? '-') ?></strong>
                                    <small class="d-block text-muted-custom"><?= e($row['step_key'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <strong><?= e($row['responsible_department'] ?? '-') ?></strong>
                                    <small class="d-block text-muted-custom"><?= e($row['responsible_user'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($row['revised_completion_date']) && $row['revised_completion_date'] !== $row['planned_completion_date']): ?>
                                    Planned: <span class="planned-date-old"><?= e(jdr_date($row['planned_completion_date'])) ?></span>
                                    <span class="planned-date-new"><?= e(jdr_date($row['revised_completion_date'])) ?></span>
                                    <?php else: ?>
                                    <?= e(jdr_date($row['planned_completion_date'])) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="delay-badge"><?= e((int)($row['delay_days'] ?? 0)) ?> day(s)</span>
                                    <small class="d-block text-muted-custom">Started: <?= e(jdr_datetime($row['delay_started_at'] ?? '')) ?></small>
                                </td>
                                <td>
                                    <?= e(jdr_datetime($row['actual_completed_at'] ?? '')) ?>
                                    <small class="d-block text-muted-custom"><?= e($row['completed_by_name'] ?? '-') ?> / <?= e($row['completed_by_department'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <strong><?= e($row['delay_reason_name'] ?? '-') ?></strong>
                                    <small class="d-block text-muted-custom"><?= e($row['delay_remarks'] ?? '-') ?></small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
    <div id="settingsOverlay"></div>
    <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
</div>
<?php include __DIR__ . '/includes/script.php'; ?>
</body>
</html>
