<?php
require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function cpa_table_exists(mysqli $conn, string $table): bool
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

function cpa_datetime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime((string)$value)) : '-';
}

function cpa_date($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function cpa_money($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function cpa_status_badge(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'approved') return 'approved';
    if ($status === 'rejected') return 'rejected';
    if ($status === 'expired') return 'expired';
    return 'pending';
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$message = '';
$messageType = 'danger';
$approval = null;
$photos = [];

if ($token === '') {
    $message = 'Invalid approval link.';
} elseif (!cpa_table_exists($conn, 'job_tracking_photo_approvals')) {
    $message = 'Approval table is missing.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $remarks = trim((string)($_POST['customer_remarks'] ?? ''));

        if (!in_array($action, ['approve', 'reject'], true)) {
            $message = 'Invalid action.';
        } else {
            try {
                $status = $action === 'approve' ? 'approved' : 'rejected';
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

                $stmt = $conn->prepare("\n                    UPDATE job_tracking_photo_approvals\n                    SET status = ?,\n                        customer_remarks = ?,\n                        responded_at = NOW(),\n                        ip_address = ?,\n                        user_agent = ?,\n                        updated_at = NOW()\n                    WHERE approval_token = ?\n                      AND status = 'pending'\n                    LIMIT 1\n                ");
                $stmt->bind_param('sssss', $status, $remarks, $ip, $ua, $token);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();

                if ($affected > 0) {
                    $message = $status === 'approved' ? 'Thank you. Photos approved successfully.' : 'Your rejection has been submitted.';
                    $messageType = $status === 'approved' ? 'success' : 'danger';
                } else {
                    $message = 'This approval link is already responded or not available.';
                    $messageType = 'warning';
                }
            } catch (Throwable $e) {
                $message = 'Unable to save response: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    try {
        $stmt = $conn->prepare("\n            SELECT\n                a.*,\n                jc.job_card_no, jc.customer_name, jc.mobile, jc.product_name, jc.delivery_date, jc.final_amount,\n                jt.status AS tracking_status, jt.remarks AS tracking_remarks, jt.actual_start_at, jt.actual_completed_at,\n                ws.step_name, ws.step_key\n            FROM job_tracking_photo_approvals a\n            LEFT JOIN job_cards jc ON jc.id = a.job_card_id\n            LEFT JOIN job_tracking jt ON jt.id = a.job_tracking_id\n            LEFT JOIN workflow_steps ws ON ws.id = a.workflow_step_id\n            WHERE a.approval_token = ?\n            LIMIT 1\n        ");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $approval = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$approval) {
            $message = $message ?: 'Approval link not found.';
        } elseif (cpa_table_exists($conn, 'job_tracking_photos')) {
            $stmt = $conn->prepare("\n                SELECT *\n                FROM job_tracking_photos\n                WHERE job_card_id = ?\n                  AND job_tracking_id = ?\n                ORDER BY id ASC\n            ");
            $jobId = (int)$approval['job_card_id'];
            $trackingId = (int)$approval['job_tracking_id'];
            $stmt->bind_param('ii', $jobId, $trackingId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $photos[] = $row;
            $stmt->close();
        }
    } catch (Throwable $e) {
        $message = $message ?: 'Unable to load approval details: ' . $e->getMessage();
    }
}

$status = strtolower((string)($approval['status'] ?? 'pending'));
$canRespond = $approval && $status === 'pending';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Design Photo Approval - Subhiksha Cards</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{background:#f8fafc;color:#0f172a;font-family:Arial,sans-serif}
        .page{max-width:1080px;margin:0 auto;padding:22px}
        .hero{background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:22px;box-shadow:0 18px 45px rgba(15,23,42,.08)}
        .title{font-size:26px;font-weight:900;margin:0}.muted{color:#64748b;font-weight:700}
        .info{border:1px solid #e2e8f0;border-radius:16px;padding:13px;background:#fff;height:100%}.info small{display:block;font-size:11px;text-transform:uppercase;font-weight:900;color:#64748b;margin-bottom:5px}.info strong,.info span{font-weight:900;word-break:break-word}
        .status{border-radius:999px;padding:7px 13px;font-size:12px;font-weight:900;text-transform:uppercase}.status.pending{background:#fef3c7;color:#92400e}.status.approved{background:#dcfce7;color:#166534}.status.rejected{background:#fee2e2;color:#991b1b}.status.expired{background:#e5e7eb;color:#374151}
        .photo-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:10px}.photo-card img{width:100%;height:260px;object-fit:contain;background:#f1f5f9;border-radius:14px}.photo-card a{font-weight:900;text-decoration:none}
        .action-box{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:18px;box-shadow:0 14px 35px rgba(15,23,42,.07)}
        @media(max-width:767px){.page{padding:12px}.hero{padding:16px;border-radius:18px}.title{font-size:22px}.photo-card img{height:210px}}
    </style>
</head>
<body>
<div class="page">
    <div class="hero mb-3">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-start">
            <div>
                <h1 class="title">Design / Proofing Photo Approval</h1>
                <div class="muted">Please review the uploaded photos and approve or reject.</div>
            </div>
            <?php if ($approval): ?>
            <span class="status <?= e(cpa_status_badge($status)) ?>"><?= e(ucwords($status)) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message !== ''): ?>
    <div class="alert alert-<?= e($messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'danger')) ?> rounded-4 fw-bold">
        <?= e($message) ?>
    </div>
    <?php endif; ?>

    <?php if ($approval): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="info"><small>Job Card No</small><strong><?= e($approval['job_card_no'] ?? '-') ?></strong></div></div>
        <div class="col-md-3"><div class="info"><small>Customer</small><strong><?= e($approval['customer_name'] ?? '-') ?></strong></div></div>
        <div class="col-md-3"><div class="info"><small>Mobile</small><strong><?= e($approval['mobile'] ?? '-') ?></strong></div></div>
        <div class="col-md-3"><div class="info"><small>Delivery Date</small><strong><?= e(cpa_date($approval['delivery_date'] ?? null)) ?></strong></div></div>
        <div class="col-md-4"><div class="info"><small>Stage</small><strong><?= e($approval['step_name'] ?? '-') ?></strong></div></div>
        <div class="col-md-4"><div class="info"><small>Product</small><strong><?= e($approval['product_name'] ?? '-') ?></strong></div></div>
        <div class="col-md-4"><div class="info"><small>Final Amount</small><strong><?= e(cpa_money($approval['final_amount'] ?? 0)) ?></strong></div></div>
        <div class="col-md-6"><div class="info"><small>Uploaded / Link Created</small><strong><?= e(cpa_datetime($approval['created_at'] ?? null)) ?></strong></div></div>
        <div class="col-md-6"><div class="info"><small>Responded At</small><strong><?= e(cpa_datetime($approval['responded_at'] ?? null)) ?></strong></div></div>
    </div>

    <div class="hero mb-3">
        <h2 class="h5 fw-black fw-bold mb-3">Uploaded Photos</h2>
        <?php if (!$photos): ?>
        <div class="alert alert-warning rounded-4 fw-bold mb-0">No photos found for this approval link.</div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($photos as $photo): ?>
            <div class="col-md-4 col-sm-6">
                <div class="photo-card">
                    <a href="<?= e($photo['file_path'] ?? '#') ?>" target="_blank" rel="noopener">
                        <img src="<?= e($photo['file_path'] ?? '') ?>" alt="Design photo">
                        <span class="d-block mt-2">Open Image</span>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="action-box">
        <?php if ($canRespond): ?>
        <form method="post">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label class="form-label fw-bold">Remarks</label>
            <textarea name="customer_remarks" class="form-control rounded-4 mb-3" rows="3" placeholder="Enter remarks if any"></textarea>
            <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end">
                <button type="submit" name="action" value="reject" class="btn btn-outline-danger rounded-pill px-4 fw-bold">Reject</button>
                <button type="submit" name="action" value="approve" class="btn btn-success rounded-pill px-4 fw-bold">Approve</button>
            </div>
        </form>
        <?php else: ?>
        <div class="fw-bold">This approval request is already <?= e(ucwords($status)) ?>.</div>
        <?php if (!empty($approval['customer_remarks'])): ?>
        <div class="mt-2 muted">Remarks: <?= e($approval['customer_remarks']) ?></div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
