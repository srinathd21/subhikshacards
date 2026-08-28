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
    if (empty($value)) {
        return '-';
    }

    try {
        /*
         * MySQL approval timestamps are stored in UTC.
         * Customer-facing display must use India Standard Time.
         */
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

function cpa_random_token(): string
{
    try {
        return bin2hex(random_bytes(24));
    } catch (Throwable $e) {
        return sha1(uniqid('approval_', true) . mt_rand());
    }
}

function cpa_approval_type_for_step_key(string $stepKey): string
{
    $stepKey = strtolower(trim($stepKey));
    if ($stepKey === 'proofing_approval') return 'proof_approval';
    if ($stepKey === 'design_approval') return 'design_approval';
    return 'confirmation';
}

function cpa_refresh_job_card_progress(mysqli $conn, int $jobId): void
{
    if ($jobId <= 0 || !cpa_table_exists($conn, 'job_tracking') || !cpa_table_exists($conn, 'job_cards')) {
        return;
    }

    $summary = [
        'total_steps' => 0,
        'completed_steps' => 0,
        'open_steps' => 0,
        'progress_steps' => 0,
        'delayed_steps' => 0,
        'delay_history_steps' => 0
    ];

    $stmt = $conn->prepare("\n        SELECT\n            COUNT(*) AS total_steps,\n            SUM(CASE WHEN status IN ('completed','skipped') THEN 1 ELSE 0 END) AS completed_steps,\n            SUM(CASE WHEN status NOT IN ('completed','skipped','cancelled') THEN 1 ELSE 0 END) AS open_steps,\n            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS progress_steps,\n            SUM(CASE WHEN status = 'delayed' THEN 1 ELSE 0 END) AS delayed_steps,\n            SUM(CASE WHEN is_delayed = 1 THEN 1 ELSE 0 END) AS delay_history_steps\n        FROM job_tracking\n        WHERE job_card_id = ?\n    ");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $summary = array_merge($summary, $row);
    }

    $currentWorkflowStepId = null;
    $stmt = $conn->prepare("\n        SELECT jt.workflow_step_id\n        FROM job_tracking jt\n        LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id\n        WHERE jt.job_card_id = ?\n          AND jt.status NOT IN ('completed','skipped','cancelled')\n        ORDER BY ws.sort_order ASC, jt.id ASC\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $currentRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($currentRow) {
        $currentWorkflowStepId = (int)$currentRow['workflow_step_id'];
    }

    $jobStatusKey = 'in_progress';
    if ((int)($summary['delayed_steps'] ?? 0) > 0) {
        $jobStatusKey = 'delayed';
    } elseif ((int)($summary['open_steps'] ?? 0) === 0 && (int)($summary['total_steps'] ?? 0) > 0) {
        $jobStatusKey = 'completed';
    } elseif ((int)($summary['progress_steps'] ?? 0) > 0) {
        $jobStatusKey = 'in_progress';
    } else {
        $jobStatusKey = 'pending';
    }

    $jobStatusId = null;
    if (cpa_table_exists($conn, 'job_card_statuses')) {
        $stmt = $conn->prepare("SELECT id FROM job_card_statuses WHERE status_key = ? LIMIT 1");
        $stmt->bind_param('s', $jobStatusKey);
        $stmt->execute();
        $statusRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($statusRow) {
            $jobStatusId = (int)$statusRow['id'];
        }
    }

    $isDelayed = ((int)($summary['delay_history_steps'] ?? 0) > 0 || $jobStatusKey === 'delayed') ? 1 : 0;

    if ($jobStatusId && $currentWorkflowStepId) {
        $stmt = $conn->prepare("\n            UPDATE job_cards\n            SET current_workflow_step_id = ?,\n                job_card_status_id = ?,\n                is_delayed = ?,\n                completed_at = CASE WHEN ? = 'completed' THEN COALESCE(completed_at, NOW()) ELSE completed_at END,\n                updated_at = NOW()\n            WHERE id = ?\n        ");
        $stmt->bind_param('iiisi', $currentWorkflowStepId, $jobStatusId, $isDelayed, $jobStatusKey, $jobId);
        $stmt->execute();
        $stmt->close();
    } elseif ($jobStatusId) {
        $stmt = $conn->prepare("\n            UPDATE job_cards\n            SET job_card_status_id = ?,\n                is_delayed = ?,\n                completed_at = CASE WHEN ? = 'completed' THEN COALESCE(completed_at, NOW()) ELSE completed_at END,\n                updated_at = NOW()\n            WHERE id = ?\n        ");
        $stmt->bind_param('iisi', $jobStatusId, $isDelayed, $jobStatusKey, $jobId);
        $stmt->execute();
        $stmt->close();
    }
}

function cpa_sync_customer_approval_from_photo(mysqli $conn, string $token, string $photoStatus, string $remarks, bool $manageTransaction = true): void
{
    if (!cpa_table_exists($conn, 'customer_approvals')) {
        throw new RuntimeException('customer_approvals table is missing.');
    }

    $stmt = $conn->prepare("\n        SELECT\n            a.*,\n            jc.order_type, jc.customer_name AS jc_customer_name, jc.mobile AS jc_mobile,\n            ws.step_key AS source_step_key, ws.step_name AS source_step_name, ws.sort_order AS source_sort_order\n        FROM job_tracking_photo_approvals a\n        LEFT JOIN job_cards jc ON jc.id = a.job_card_id\n        LEFT JOIN workflow_steps ws ON ws.id = a.workflow_step_id\n        WHERE a.approval_token = ?\n        LIMIT 1\n    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $photoApproval = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$photoApproval) {
        throw new RuntimeException('Photo approval record not found.');
    }

    $jobId = (int)($photoApproval['job_card_id'] ?? 0);
    $sourceStepId = (int)($photoApproval['workflow_step_id'] ?? 0);
    $orderType = strtolower(trim((string)($photoApproval['order_type'] ?? '')));
    $sourceSort = (int)($photoApproval['source_sort_order'] ?? 0);

    $preferredStepKey = $orderType === 'readymade' ? 'proofing_approval' : 'design_approval';
    $target = null;

    /*
     * Resolve from the actual Job Card tracking rows first. This prevents an
     * approved photo from completing only the source Proofing/Design stage when
     * the workflow master row is inactive or has changed after Job Card creation.
     */
    $stmt = $conn->prepare("\n        SELECT\n            jt.workflow_step_id AS id,\n            ws.step_key,\n            ws.step_name,\n            ws.sort_order\n        FROM job_tracking jt\n        INNER JOIN workflow_steps ws ON ws.id = jt.workflow_step_id\n        WHERE jt.job_card_id = ?\n          AND ws.step_key = ?\n        ORDER BY jt.id ASC\n        LIMIT 1\n    ");
    $stmt->bind_param('is', $jobId, $preferredStepKey);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        $stmt = $conn->prepare("\n            SELECT\n                jt.workflow_step_id AS id,\n                ws.step_key,\n                ws.step_name,\n                ws.sort_order\n            FROM job_tracking jt\n            INNER JOIN workflow_steps ws ON ws.id = jt.workflow_step_id\n            WHERE jt.job_card_id = ?\n              AND jt.workflow_step_id <> ?\n              AND (\n                    COALESCE(ws.is_approval_step, 0) = 1\n                    OR ws.step_key IN ('proofing_approval','design_approval')\n                    OR LOWER(COALESCE(ws.step_name, '')) LIKE '%approval%'\n                  )\n              AND (? <= 0 OR ws.sort_order >= ?)\n            ORDER BY ws.sort_order ASC, jt.id ASC\n            LIMIT 1\n        ");
        $stmt->bind_param('iiii', $jobId, $sourceStepId, $sourceSort, $sourceSort);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$target) {
        throw new RuntimeException('Proofing Approval / Design Approval tracking stage was not found for this Job Card.');
    }

    $approvalWorkflowStepId = (int)($target['id'] ?? 0);
    $approvalStepKey = (string)($target['step_key'] ?? '');
    $approvalType = cpa_approval_type_for_step_key($approvalStepKey);

    if ($approvalWorkflowStepId <= 0) {
        throw new RuntimeException('Invalid Proofing / Design Approval workflow stage.');
    }

    $customerName = (string)($photoApproval['customer_name'] ?: ($photoApproval['jc_customer_name'] ?? ''));
    $mobile = (string)($photoApproval['mobile'] ?: ($photoApproval['jc_mobile'] ?? ''));
    $approvalToken = cpa_random_token();
    $status = $photoStatus === 'approved' ? 'approved' : 'rejected';
    $approvedByCustomer = $status === 'approved' ? 1 : 0;
    $rejectedAtSql = $status === 'rejected' ? 'NOW()' : 'NULL';
    $approvedAtSql = $status === 'approved' ? 'NOW()' : 'NULL';

    if ($manageTransaction) {
        $conn->begin_transaction();
    }

    try {
        $stmt = $conn->prepare("\n            SELECT id\n            FROM customer_approvals\n            WHERE job_card_id = ?\n              AND workflow_step_id = ?\n              AND approval_type = ?\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $stmt->bind_param('iis', $jobId, $approvalWorkflowStepId, $approvalType);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $approvalId = (int)$existing['id'];
            $stmt = $conn->prepare("\n                UPDATE customer_approvals\n                SET status = ?,\n                    customer_name = ?,\n                    mobile = ?,\n                    approved_by_customer = ?,\n                    approved_by_call = 0,\n                    customer_remarks = ?,\n                    approved_at = {$approvedAtSql},\n                    rejected_at = {$rejectedAtSql},\n                    updated_at = NOW()\n                WHERE id = ?\n            ");
            $stmt->bind_param('sssisi', $status, $customerName, $mobile, $approvedByCustomer, $remarks, $approvalId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("\n                INSERT INTO customer_approvals\n                    (job_card_id, workflow_step_id, approval_type, approval_token, customer_name, mobile, status, approved_by_customer, approved_by_call, customer_remarks, approved_at, rejected_at, created_at, updated_at)\n                VALUES\n                    (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, {$approvedAtSql}, {$rejectedAtSql}, NOW(), NOW())\n            ");
            $stmt->bind_param('iisssssis', $jobId, $approvalWorkflowStepId, $approvalType, $approvalToken, $customerName, $mobile, $status, $approvedByCustomer, $remarks);
            $stmt->execute();
            $stmt->close();
        }

        if (cpa_table_exists($conn, 'job_tracking')) {
            if ($status === 'approved') {
                $trackingRemark = trim($remarks) !== '' ? $remarks : 'Customer approved uploaded design/proofing photos.';
                $stmt = $conn->prepare("\n                    UPDATE job_tracking\n                    SET status = 'completed',\n                        remarks = ?,\n                        actual_start_at = COALESCE(actual_start_at, NOW()),\n                        actual_completed_at = NOW(),\n                        updated_at = NOW()\n                    WHERE job_card_id = ?\n                      AND workflow_step_id = ?\n                      AND status NOT IN ('completed','skipped','cancelled')\n                    LIMIT 1\n                ");
                $stmt->bind_param('sii', $trackingRemark, $jobId, $approvalWorkflowStepId);
                $stmt->execute();
                $trackingAffected = $stmt->affected_rows;
                $stmt->close();

                /*
                 * If no row was changed, confirm that the approval workflow row
                 * actually exists and is already complete. This prevents the photo
                 * response from being saved as Approved while the approval stage is
                 * silently left Pending.
                 */
                if ($trackingAffected === 0) {
                    $stmt = $conn->prepare("
                        SELECT status
                        FROM job_tracking
                        WHERE job_card_id = ?
                          AND workflow_step_id = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param('ii', $jobId, $approvalWorkflowStepId);
                    $stmt->execute();
                    $trackingRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$trackingRow) {
                        throw new RuntimeException('Proofing / Design Approval tracking stage is missing for this Job Card.');
                    }

                    $existingTrackingStatus = strtolower(trim((string)($trackingRow['status'] ?? '')));
                    if (!in_array($existingTrackingStatus, ['completed', 'skipped'], true)) {
                        throw new RuntimeException('Unable to automatically complete the Proofing / Design Approval stage.');
                    }
                }
            } else {
                $trackingRemark = trim($remarks) !== '' ? $remarks : 'Customer rejected uploaded design/proofing photos.';
                $stmt = $conn->prepare("\n                    UPDATE job_tracking\n                    SET status = CASE WHEN status = 'completed' THEN status ELSE 'pending' END,\n                        remarks = ?,\n                        actual_completed_at = CASE WHEN status = 'completed' THEN actual_completed_at ELSE NULL END,\n                        updated_at = NOW()\n                    WHERE job_card_id = ?\n                      AND workflow_step_id = ?\n                    LIMIT 1\n                ");
                $stmt->bind_param('sii', $trackingRemark, $jobId, $approvalWorkflowStepId);
                $stmt->execute();
                $stmt->close();
            }
        }

        cpa_refresh_job_card_progress($conn, $jobId);

        if ($manageTransaction) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($manageTransaction) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                // Ignore rollback failure; original exception is more useful.
            }
        }
        throw $e;
    }
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

                /*
                 * Keep the customer photo response + customer_approvals +
                 * approval-stage tracking update atomic. If any part fails, none
                 * of the records are left in a half-synchronised state.
                 */
                $conn->begin_transaction();

                $stmt = $conn->prepare("\n                    UPDATE job_tracking_photo_approvals\n                    SET status = ?,\n                        customer_remarks = ?,\n                        responded_at = NOW(),\n                        ip_address = ?,\n                        user_agent = ?,\n                        updated_at = NOW()\n                    WHERE approval_token = ?\n                      AND status = 'pending'\n                    LIMIT 1\n                ");
                $stmt->bind_param('sssss', $status, $remarks, $ip, $ua, $token);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();

                if ($affected <= 0) {
                    $conn->rollback();
                    $message = 'This approval link is already responded or not available.';
                    $messageType = 'warning';
                } else {
                    cpa_sync_customer_approval_from_photo($conn, $token, $status, $remarks, false);
                    $conn->commit();

                    $message = $status === 'approved'
                        ? 'Thank you. Proof/design approved successfully. The Proofing Approval / Design Approval stage has been completed automatically.'
                        : 'Your rejection has been submitted. The approval stage remains open for staff action / corrected proof.';
                    $messageType = $status === 'approved' ? 'success' : 'danger';
                }
            } catch (Throwable $e) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                    // Ignore rollback failure.
                }
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
    <title>Design / Proofing Approval - Subhiksha Cards</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
    body {
        background: #f8fafc;
        color: #0f172a;
        font-family: Arial, sans-serif
    }

    .page {
        max-width: 1080px;
        margin: 0 auto;
        padding: 22px
    }

    .hero {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 24px;
        padding: 22px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .08)
    }

    .title {
        font-size: 26px;
        font-weight: 900;
        margin: 0
    }

    .muted {
        color: #64748b;
        font-weight: 700
    }

    .info {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 13px;
        background: #fff;
        height: 100%
    }

    .info small {
        display: block;
        font-size: 11px;
        text-transform: uppercase;
        font-weight: 900;
        color: #64748b;
        margin-bottom: 5px
    }

    .info strong,
    .info span {
        font-weight: 900;
        word-break: break-word
    }

    .status {
        border-radius: 999px;
        padding: 7px 13px;
        font-size: 12px;
        font-weight: 900;
        text-transform: uppercase
    }

    .status.pending {
        background: #fef3c7;
        color: #92400e
    }

    .status.approved {
        background: #dcfce7;
        color: #166534
    }

    .status.rejected {
        background: #fee2e2;
        color: #991b1b
    }

    .status.expired {
        background: #e5e7eb;
        color: #374151
    }

    .photo-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        padding: 10px
    }

    .photo-card img {
        width: 100%;
        height: 260px;
        object-fit: contain;
        background: #f1f5f9;
        border-radius: 14px
    }

    .photo-card a {
        font-weight: 900;
        text-decoration: none
    }

    .pdf-card {
        width: 100%;
        min-width: 0;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        padding: 12px;
        overflow: hidden;
    }

    .pdf-viewer-wrap {
        width: 100%;
        min-width: 0;
        overflow: hidden;
        border-radius: 14px;
        background: #f8fafc;
    }

    .pdf-preview {
        display: block;
        width: 100%;
        max-width: 100%;
        height: min(70vh, 650px);
        min-height: 520px;
        border: 1px solid #cbd5e1;
        border-radius: 14px;
        background: #f8fafc;
    }

    .pdf-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 12px;
    }

    .pdf-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 44px;
        padding: 10px 16px;
        border-radius: 999px;
        text-decoration: none;
        font-weight: 900;
        text-align: center;
    }

    .pdf-btn-open {
        background: #dc2626;
        color: #fff
    }

    .pdf-btn-download {
        background: #f1f5f9;
        color: #0f172a;
        border: 1px solid #cbd5e1
    }

    .file-name {
        margin-top: 10px;
        font-size: 12px;
        line-height: 1.45;
        color: #64748b;
        font-weight: 800;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .action-box {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 22px;
        padding: 18px;
        box-shadow: 0 14px 35px rgba(15, 23, 42, .07)
    }

    @media(max-width:767px) {

        html,
        body {
            max-width: 100%;
            overflow-x: hidden
        }

        .page {
            width: 100%;
            max-width: 100%;
            padding: 8px;
        }

        .hero {
            width: 100%;
            max-width: 100%;
            padding: 14px;
            border-radius: 16px;
        }

        .title {
            font-size: 20px;
            line-height: 1.2;
        }

        .muted {
            font-size: 13px;
            line-height: 1.45;
        }

        .row {
            --bs-gutter-x: 10px;
            --bs-gutter-y: 10px;
        }

        .info {
            padding: 11px;
            border-radius: 14px;
        }

        .photo-card {
            width: 100%;
            padding: 8px;
            border-radius: 14px;
        }

        .photo-card img {
            width: 100%;
            height: auto;
            max-height: 380px;
            object-fit: contain;
        }

        .pdf-card {
            width: 100%;
            max-width: 100%;
            padding: 8px;
            border-radius: 14px;
        }

        .pdf-viewer-wrap {
            width: 100%;
            max-width: 100%;
            border-radius: 12px;
        }

        .pdf-desktop-preview {
            display: none;
        }

        .pdf-preview {
            width: 100%;
            max-width: 100%;
            height: 62vh;
            min-height: 380px;
            max-height: 560px;
            border-radius: 12px;
        }

        .pdf-actions {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            width: 100%;
        }

        .pdf-btn {
            width: 100%;
            border-radius: 12px;
            min-height: 46px;
            font-size: 15px;
        }

        .file-name {
            font-size: 11px;
            margin-top: 8px;
        }

        .action-box {
            padding: 14px;
            border-radius: 16px;
        }

        .action-box .btn {
            width: 100%;
            min-height: 46px;
        }
    }

    @media(max-width:420px) {
        .page {
            padding: 6px
        }

        .hero {
            padding: 12px
        }

        .title {
            font-size: 19px
        }

        .pdf-preview {
            height: 58vh;
            min-height: 340px;
            max-height: 500px;
        }
    }
    </style>
</head>

<body>
    <div class="page">
        <div class="hero mb-3">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-start">
                <div>
                    <h1 class="title">Design / Proofing Approval</h1>
                    <div class="muted">Please review the uploaded proof/design file before approving. Approval will
                        update the related Proofing Approval / Design Approval stage in the job card.</div>
                </div>
                <?php if ($approval): ?>
                <span class="status <?= e(cpa_status_badge($status)) ?>"><?= e(ucwords($status)) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message !== ''): ?>
        <div
            class="alert alert-<?= e($messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'danger')) ?> rounded-4 fw-bold">
            <?= e($message) ?>
        </div>
        <?php endif; ?>

        <?php if ($approval): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="info"><small>Job Card No</small><strong><?= e($approval['job_card_no'] ?? '-') ?></strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info"><small>Customer</small><strong><?= e($approval['customer_name'] ?? '-') ?></strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info"><small>Mobile</small><strong><?= e($approval['mobile'] ?? '-') ?></strong></div>
            </div>
            <div class="col-md-3">
                <div class="info"><small>Delivery
                        Date</small><strong><?= e(cpa_date($approval['delivery_date'] ?? null)) ?></strong></div>
            </div>
            <div class="col-md-4">
                <div class="info"><small>Stage</small><strong><?= e($approval['step_name'] ?? '-') ?></strong></div>
            </div>
            <div class="col-md-4">
                <div class="info"><small>Product</small><strong><?= e($approval['product_name'] ?? '-') ?></strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info"><small>Final
                        Amount</small><strong><?= e(cpa_money($approval['final_amount'] ?? 0)) ?></strong></div>
            </div>
            <div class="col-md-6">
                <div class="info"><small>Uploaded / Link
                        Created</small><strong><?= e(cpa_datetime($approval['created_at'] ?? null)) ?></strong></div>
            </div>
            <div class="col-md-6">
                <div class="info"><small>Responded
                        At</small><strong><?= e(cpa_datetime($approval['responded_at'] ?? null)) ?></strong></div>
            </div>
        </div>

        <div class="hero mb-3 uploaded-files-section">
            <h2 class="h5 fw-black fw-bold mb-3">Uploaded Proof / Design Files</h2>
            <?php if (!$photos): ?>
            <div class="alert alert-warning rounded-4 fw-bold mb-0">No proof/design files found for this approval link.
            </div>
            <?php else: ?>
            <div class="row g-3">
                <?php foreach ($photos as $photo): ?>
                <?php
                $filePath = trim((string)($photo['file_path'] ?? ''));
                $fileMime = strtolower(trim((string)($photo['mime_type'] ?? '')));
                $fileName = trim((string)($photo['original_name'] ?? ''));

                if ($fileName === '') {
                    $fileName = basename(parse_url($filePath, PHP_URL_PATH) ?: $filePath);
                }

                $fileExt = strtolower(pathinfo($fileName !== '' ? $fileName : $filePath, PATHINFO_EXTENSION));
                $isPdf = $fileMime === 'application/pdf' || $fileExt === 'pdf';
            ?>

                <?php if ($isPdf): ?>
                <div class="col-12">
                    <div class="pdf-card">
                        <div class="pdf-viewer-wrap pdf-desktop-preview">
                            <iframe src="<?= e($filePath) ?>" class="pdf-preview" title="Proofing PDF"
                                loading="lazy"></iframe>
                        </div>

                        <div class="pdf-actions">
                            <a href="<?= e($filePath) ?>" target="_blank" rel="noopener" class="pdf-btn pdf-btn-open">
                                Open PDF
                            </a>

                            <a href="<?= e($filePath) ?>" download class="pdf-btn pdf-btn-download">
                                Download PDF
                            </a>
                        </div>

                        <div class="file-name d-md-none">Mobile view shows only Open PDF and Download PDF buttons.</div>

                        <?php if ($fileName !== ''): ?>
                        <div class="file-name"><?= e($fileName) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="col-md-4 col-sm-6">
                    <div class="photo-card">
                        <a href="<?= e($filePath ?: '#') ?>" target="_blank" rel="noopener">
                            <img src="<?= e($filePath) ?>" alt="Design / Proofing image">
                            <span class="d-block mt-2">Open Image</span>
                        </a>

                        <?php if ($fileName !== ''): ?>
                        <div class="file-name"><?= e($fileName) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="action-box">
            <?php if ($canRespond): ?>
            <form method="post">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label class="form-label fw-bold">Remarks</label>
                <textarea name="customer_remarks" class="form-control rounded-4 mb-3" rows="3"
                    placeholder="Enter remarks if any"></textarea>
                <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end">
                    <button type="submit" name="action" value="reject"
                        class="btn btn-outline-danger rounded-pill px-4 fw-bold">Reject</button>
                    <button type="submit" name="action" value="approve"
                        class="btn btn-success rounded-pill px-4 fw-bold">Approve</button>
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