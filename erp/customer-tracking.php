<?php
/**
 * customer-tracking.php
 * Subhiksha Cards ERP - Public Customer Order Tracking
 *
 * Access methods:
 * 1. Secure token URL:
 *    customer-tracking.php?token=XXXXXXXX
 *
 * 2. Manual tracking:
 *    Job Card No + Mobile number
 *
 * Tables used:
 * - job_cards
 * - job_tracking
 * - workflow_steps
 * - customer_tracking_links
 * - customer_tracking_views
 * - customer_approvals
 * - approval_files
 */

require_once __DIR__ . '/includes/db.php';

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function ct_table_exists(mysqli $conn, string $table): bool
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

function ct_status_label(string $status): string
{
    $status = strtolower(trim($status));

    return match ($status) {
        'completed' => 'Completed',
        'in_progress' => 'In Progress',
        'delayed' => 'Delayed',
        'skipped' => 'Skipped',
        'cancelled' => 'Cancelled',
        default => 'Pending',
    };
}

function ct_status_class(string $status): string
{
    $status = strtolower(trim($status));

    return match ($status) {
        'completed' => 'done',
        'in_progress' => 'current',
        'delayed' => 'delay',
        'cancelled' => 'cancel',
        default => 'pending',
    };
}

function ct_find_job_by_token(mysqli $conn, string $token): array
{
    $token = trim($token);

    if ($token === '') {
        return [];
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                jc.*,
                ctl.id AS tracking_link_id,
                ctl.tracking_token AS link_token,
                ctl.expires_at
            FROM customer_tracking_links ctl
            INNER JOIN job_cards jc ON jc.id = ctl.job_card_id
            WHERE ctl.tracking_token = ?
              AND ctl.is_active = 1
              AND (ctl.expires_at IS NULL OR ctl.expires_at >= NOW())
            LIMIT 1
        ");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        if (!$job) {
            $stmt = $conn->prepare("
                SELECT *
                FROM job_cards
                WHERE tracking_token = ?
                LIMIT 1
            ");
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $job = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }

        return $job;
    } catch (Throwable $e) {
        return [];
    }
}

function ct_find_job_by_manual(mysqli $conn, string $jobNo, string $mobile): array
{
    $jobNo = trim($jobNo);
    $mobile = trim($mobile);

    if ($jobNo === '' || $mobile === '') {
        return [];
    }

    try {
        $stmt = $conn->prepare("
            SELECT *
            FROM job_cards
            WHERE job_card_no = ?
              AND REPLACE(REPLACE(REPLACE(mobile, ' ', ''), '+91', ''), '-', '') = REPLACE(REPLACE(REPLACE(?, ' ', ''), '+91', ''), '-', '')
            LIMIT 1
        ");
        $stmt->bind_param('ss', $jobNo, $mobile);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return $job;
    } catch (Throwable $e) {
        return [];
    }
}

function ct_get_timeline(mysqli $conn, int $jobCardId): array
{
    if ($jobCardId <= 0) {
        return [];
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                jt.*,
                ws.step_key,
                ws.step_name,
                ws.sort_order,
                ws.is_customer_visible
            FROM job_tracking jt
            INNER JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
            WHERE jt.job_card_id = ?
              AND ws.is_customer_visible = 1
            ORDER BY ws.sort_order ASC, jt.id ASC
        ");
        $stmt->bind_param('i', $jobCardId);
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function ct_get_latest_approval(mysqli $conn, int $jobCardId): array
{
    if ($jobCardId <= 0 || !ct_table_exists($conn, 'customer_approvals')) {
        return [];
    }

    try {
        $stmt = $conn->prepare("
            SELECT *
            FROM customer_approvals
            WHERE job_card_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $jobCardId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return $row;
    } catch (Throwable $e) {
        return [];
    }
}

function ct_get_latest_file(mysqli $conn, int $jobCardId): array
{
    if ($jobCardId <= 0 || !ct_table_exists($conn, 'approval_files')) {
        return [];
    }

    try {
        $stmt = $conn->prepare("
            SELECT *
            FROM approval_files
            WHERE job_card_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $jobCardId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return $row;
    } catch (Throwable $e) {
        return [];
    }
}

function ct_log_view(mysqli $conn, array $job): void
{
    try {
        if (empty($job['tracking_link_id']) || empty($job['id'])) {
            return;
        }

        $trackingLinkId = (int)$job['tracking_link_id'];
        $jobCardId = (int)$job['id'];
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("
            INSERT INTO customer_tracking_views
                (tracking_link_id, job_card_id, ip_address, user_agent, viewed_at)
            VALUES
                (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('iiss', $trackingLinkId, $jobCardId, $ip, $ua);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE customer_tracking_links
            SET last_viewed_at = NOW(), view_count = view_count + 1
            WHERE id = ?
        ");
        $stmt->bind_param('i', $trackingLinkId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
    }
}

$job = [];
$error = '';
$searched = false;

$token = trim((string)($_GET['token'] ?? ''));

if ($token !== '') {
    $searched = true;
    $job = ct_find_job_by_token($conn, $token);

    if (!$job) {
        $error = 'Tracking link is invalid or expired.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $searched = true;
    $jobNo = trim((string)($_POST['job_card_no'] ?? ''));
    $mobile = trim((string)($_POST['mobile'] ?? ''));

    if ($jobNo === '' || $mobile === '') {
        $error = 'Please enter job card number and mobile number.';
    } else {
        $job = ct_find_job_by_manual($conn, $jobNo, $mobile);

        if (!$job) {
            $error = 'Order not found. Please check job card number and mobile number.';
        }
    }
}

if ($job) {
    ct_log_view($conn, $job);
}

$timeline = $job ? ct_get_timeline($conn, (int)$job['id']) : [];
$approval = $job ? ct_get_latest_approval($conn, (int)$job['id']) : [];
$latestFile = $job ? ct_get_latest_file($conn, (int)$job['id']) : [];

$currentStepId = (int)($job['current_workflow_step_id'] ?? 0);
$currentStepName = 'Order Received';

foreach ($timeline as $row) {
    if ((int)$row['workflow_step_id'] === $currentStepId) {
        $currentStepName = $row['step_name'];
        break;
    }
}

if ($timeline && $currentStepId <= 0) {
    foreach ($timeline as $row) {
        if (strtolower((string)$row['status']) === 'in_progress') {
            $currentStepName = $row['step_name'];
            break;
        }
    }
}

$completedCount = 0;
foreach ($timeline as $row) {
    if (strtolower((string)$row['status']) === 'completed') {
        $completedCount++;
    }
}

$totalSteps = count($timeline);
$progressPercent = $totalSteps > 0 ? round(($completedCount / $totalSteps) * 100) : 0;

if (!empty($job['completed_at'])) {
    $progressPercent = 100;
    $currentStepName = 'Completed';
}

$displayOrderType = ucfirst((string)($job['order_type'] ?? ''));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Track Order - Subhiksha Cards</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --brand-blue: #063f8f;
            --brand-orange: #f28c00;
            --ink: #0f172a;
            --muted: #64748b;
            --soft: #e2e8f0;
            --bg: #fff7df;
            --card: #ffffff;
            --success: #16a34a;
            --warning: #f59e0b;
            --danger: #dc2626;
            --info: #2563eb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background:
                radial-gradient(circle at top left, rgba(242, 140, 0, .18), transparent 34%),
                linear-gradient(135deg, #fff7df 0%, #f8fafc 58%, #eef6ff 100%);
            min-height: 100vh;
            color: var(--ink);
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .track-wrap {
            max-width: 920px;
            margin: auto;
        }

        .track-head {
            background:
                linear-gradient(135deg, rgba(6, 63, 143, .96), rgba(15, 23, 42, .96)),
                radial-gradient(circle at top right, rgba(242, 140, 0, .35), transparent 30%);
            color: #fff;
            border-radius: 28px;
            padding: 30px;
            box-shadow: 0 22px 60px rgba(15, 23, 42, .20);
            overflow: hidden;
            position: relative;
        }

        .track-head::after {
            content: "";
            position: absolute;
            width: 190px;
            height: 190px;
            border-radius: 999px;
            right: -70px;
            top: -70px;
            background: rgba(242, 140, 0, .30);
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 13px;
            position: relative;
            z-index: 1;
        }

        .brand-logo {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            object-fit: contain;
            background: #fff;
            padding: 7px;
        }

        .brand-title {
            font-size: 13px;
            letter-spacing: .8px;
            font-weight: 900;
            opacity: .86;
            text-transform: uppercase;
        }

        .track-head h1 {
            font-size: 32px;
            font-weight: 900;
            line-height: 1.15;
            position: relative;
            z-index: 1;
        }

        .track-head p {
            color: rgba(255, 255, 255, .78);
            position: relative;
            z-index: 1;
        }

        .track-card {
            background: rgba(255, 255, 255, .92);
            border: 1px solid rgba(226, 232, 240, .85);
            border-radius: 24px;
            padding: 26px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, .08);
            backdrop-filter: blur(10px);
        }

        .form-control {
            border-radius: 16px;
            border-color: var(--soft);
            min-height: 52px;
            font-weight: 700;
        }

        .btn-track {
            min-height: 52px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--brand-orange), #ffc34d);
            border: 0;
            color: #111827;
            font-weight: 900;
            box-shadow: 0 12px 30px rgba(242, 140, 0, .26);
        }

        .summary-card {
            border: 1px solid var(--soft);
            border-radius: 22px;
            padding: 20px;
            background: #fff;
        }

        .order-no {
            font-size: 23px;
            font-weight: 900;
            color: var(--brand-blue);
            margin-bottom: 4px;
        }

        .current-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fff3bf;
            color: #8a6100;
            padding: 9px 14px;
            border-radius: 999px;
            font-weight: 900;
            white-space: nowrap;
        }

        .progress {
            height: 12px;
            border-radius: 999px;
            background: #e5e7eb;
        }

        .progress-bar {
            background: linear-gradient(135deg, var(--brand-orange), #ffd36e);
            border-radius: 999px;
        }

        .timeline {
            position: relative;
            padding-left: 4px;
        }

        .step {
            display: flex;
            gap: 15px;
            position: relative;
            padding: 0 0 18px;
        }

        .step:last-child {
            padding-bottom: 0;
        }

        .step::before {
            content: "";
            position: absolute;
            left: 18px;
            top: 38px;
            bottom: 0;
            width: 2px;
            background: var(--soft);
        }

        .step:last-child::before {
            display: none;
        }

        .step-icon {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            background: #f1f5f9;
            border: 2px solid #e2e8f0;
            display: grid;
            place-items: center;
            color: var(--muted);
            font-weight: 900;
            flex: 0 0 auto;
            z-index: 1;
        }

        .step.done .step-icon {
            background: #dcfce7;
            border-color: #bbf7d0;
            color: var(--success);
        }

        .step.current .step-icon {
            background: #fff3bf;
            border-color: #facc15;
            color: #92400e;
            box-shadow: 0 0 0 5px rgba(250, 204, 21, .16);
        }

        .step.delay .step-icon {
            background: #fee2e2;
            border-color: #fecaca;
            color: var(--danger);
        }

        .step-body {
            flex: 1;
            border: 1px solid var(--soft);
            background: #fff;
            border-radius: 18px;
            padding: 14px 16px;
        }

        .step.current .step-body {
            border-color: #facc15;
            background: #fffdf0;
        }

        .step-title {
            font-size: 15px;
            font-weight: 900;
            margin-bottom: 4px;
        }

        .step-meta {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .step-badge {
            display: inline-flex;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 900;
        }

        .badge-done {
            background: #dcfce7;
            color: #166534;
        }

        .badge-current {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .badge-delay {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-pending {
            background: #f1f5f9;
            color: #475569;
        }

        .info-box {
            border-radius: 18px;
            padding: 15px;
            background: #f8fafc;
            border: 1px solid var(--soft);
            height: 100%;
        }

        .info-box small {
            color: var(--muted);
            font-weight: 800;
            text-transform: uppercase;
            font-size: 11px;
        }

        .info-box strong {
            display: block;
            margin-top: 4px;
            font-size: 15px;
            color: var(--ink);
        }

        .preview-box {
            border: 1px dashed #cbd5e1;
            background: #f8fafc;
            border-radius: 18px;
            padding: 16px;
        }

        .footer-note {
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        @media (max-width: 767.98px) {
            .track-head {
                padding: 22px;
                border-radius: 22px;
            }

            .track-head h1 {
                font-size: 25px;
            }

            .track-card {
                padding: 18px;
                border-radius: 20px;
            }

            .brand-logo {
                width: 50px;
                height: 50px;
            }

            .current-pill {
                width: 100%;
                justify-content: center;
                margin-top: 12px;
            }

            .order-no {
                font-size: 20px;
            }

            .step {
                gap: 11px;
            }

            .step-body {
                padding: 12px;
            }

            .step-title {
                font-size: 14px;
            }
        }
    </style>
</head>

<body>
    <main class="container py-4 py-lg-5">
        <div class="track-wrap">
            <section class="track-head mb-4">
                <div class="brand-row mb-4">
                    <img src="assets/img/subhiksha-logo.png" class="brand-logo" alt="Subhiksha Cards"
                        onerror="this.style.display='none'">
                    <div>
                        <div class="brand-title">Subhiksha Cards Customer Portal</div>
                        <div class="fw-bold">Order Tracking</div>
                    </div>
                </div>

                <h1 class="mb-2">Track Your Invitation Order</h1>
                <p class="mb-0">
                    Enter your job card number and mobile number, or open your secure tracking link from WhatsApp.
                </p>
            </section>

            <section class="track-card">
                <form method="post" class="row g-2 align-items-center">
                    <div class="col-12 col-md">
                        <input class="form-control form-control-lg" name="job_card_no"
                            placeholder="Job Card No: SC-JOB-1027"
                            value="<?= e($_POST['job_card_no'] ?? '') ?>" required>
                    </div>

                    <div class="col-12 col-md">
                        <input class="form-control form-control-lg" name="mobile"
                            placeholder="Registered Mobile Number"
                            value="<?= e($_POST['mobile'] ?? '') ?>" required>
                    </div>

                    <div class="col-12 col-md-auto">
                        <button class="btn btn-track btn-lg w-100 px-4" type="submit">
                            Track Order
                        </button>
                    </div>
                </form>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger rounded-4 mt-3 mb-0 fw-bold">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <?php if (!$job && !$searched): ?>
                    <div class="preview-box mt-4">
                        <strong>How to track?</strong>
                        <p class="text-muted mb-0 mt-1">
                            Use your Job Card number and registered mobile number. If you received a WhatsApp tracking
                            link, open it directly without login.
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ($job): ?>
                    <hr class="my-4">

                    <div class="summary-card mb-3">
                        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
                            <div>
                                <div class="order-no"><?= e($job['job_card_no']) ?></div>
                                <p class="text-muted mb-0 fw-semibold">
                                    <?= e($job['customer_name']) ?> ·
                                    <?= e($job['product_name'] ?? 'Invitation Cards') ?> ·
                                    <?= e($displayOrderType) ?>
                                </p>
                            </div>

                            <span class="current-pill">
                                <?= e($currentStepName) ?>
                            </span>
                        </div>

                        <div class="mt-4">
                            <div class="d-flex justify-content-between small fw-bold text-muted mb-1">
                                <span>Order Progress</span>
                                <span><?= e($progressPercent) ?>%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar" style="width: <?= e($progressPercent) ?>%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="info-box">
                                <small>Final Amount</small>
                                <strong>₹<?= number_format((float)($job['final_amount'] ?? 0), 2) ?></strong>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="info-box">
                                <small>Advance</small>
                                <strong>₹<?= number_format((float)($job['advance_amount'] ?? 0), 2) ?></strong>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="info-box">
                                <small>Balance</small>
                                <strong>₹<?= number_format((float)($job['balance_amount'] ?? 0), 2) ?></strong>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="info-box">
                                <small>Delivery Date</small>
                                <strong>
                                    <?= !empty($job['delivery_date']) ? e(date('d-m-Y', strtotime($job['delivery_date']))) : '-' ?>
                                </strong>
                            </div>
                        </div>
                    </div>

                    <?php if ($approval): ?>
                        <div class="preview-box mb-4">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <strong>Approval Status</strong>
                                    <div class="text-muted fw-semibold mt-1">
                                        <?= e($approval['approval_type'] ?? 'Approval') ?> ·
                                        <?= e($approval['approval_status'] ?? $approval['status'] ?? 'Pending') ?>
                                    </div>
                                </div>

                                <?php if (!empty($latestFile['file_path'])): ?>
                                    <a class="btn btn-sm btn-outline-primary rounded-pill fw-bold"
                                       href="<?= e($latestFile['file_path']) ?>" target="_blank">
                                        View Proof / Design
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <h2 class="h5 fw-bold mb-3">Tracking Timeline</h2>

                    <?php if (!$timeline): ?>
                        <div class="alert alert-warning rounded-4 fw-bold">
                            Tracking stages are not planned yet. Please contact Subhiksha Cards.
                        </div>
                    <?php endif; ?>

                    <?php if ($timeline): ?>
                        <div class="timeline">
                            <?php foreach ($timeline as $index => $row): ?>
                                <?php
                                $status = (string)($row['status'] ?? 'pending');
                                $class = ct_status_class($status);
                                $label = ct_status_label($status);

                                if ((int)$row['workflow_step_id'] === $currentStepId && $status !== 'completed') {
                                    $class = 'current';
                                    $label = 'Current';
                                }

                                $badgeClass = match ($class) {
                                    'done' => 'badge-done',
                                    'current' => 'badge-current',
                                    'delay' => 'badge-delay',
                                    default => 'badge-pending',
                                };
                                ?>
                                <div class="step <?= e($class) ?>">
                                    <div class="step-icon">
                                        <?php if ($class === 'done'): ?>
                                            ✓
                                        <?php elseif ($class === 'current'): ?>
                                            ●
                                        <?php else: ?>
                                            <?= e($index + 1) ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="step-body">
                                        <div class="d-flex flex-wrap justify-content-between gap-2">
                                            <div>
                                                <div class="step-title"><?= e($row['step_name']) ?></div>

                                                <div class="step-meta">
                                                    <?php if (!empty($row['planned_completion_date'])): ?>
                                                        Planned:
                                                        <?php if ((int)($row['is_delayed'] ?? 0) === 1 && !empty($row['revised_completion_date'])): ?>
                                                            <del><?= e(date('d-m-Y', strtotime($row['planned_completion_date']))) ?></del>
                                                            <?= e(date('d-m-Y', strtotime($row['revised_completion_date']))) ?>
                                                        <?php else: ?>
                                                            <?= e(date('d-m-Y', strtotime($row['planned_completion_date']))) ?>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        Planned date not set
                                                    <?php endif; ?>
                                                </div>

                                                <?php if (!empty($row['actual_completed_at'])): ?>
                                                    <div class="step-meta">
                                                        Completed:
                                                        <?= e(date('d-m-Y h:i A', strtotime($row['actual_completed_at']))) ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($row['delay_remarks'])): ?>
                                                    <div class="step-meta text-danger">
                                                        Delay: <?= e($row['delay_remarks']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <span class="step-badge <?= e($badgeClass) ?>">
                                                <?= e($label) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <div class="footer-note mt-4">
                For support, contact Subhiksha Cards team with your Job Card number.
            </div>
        </div>
    </main>
</body>

</html>
