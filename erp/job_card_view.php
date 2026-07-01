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

function jcvTableExists(mysqli $conn, string $table): bool
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

function jcvDate($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime($value)) : '-';
}

function jcvDateTime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime($value)) : '-';
}

function jcvMoney($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function jcvRoleLabel(string $roleKey): string
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

function jcvOrderBadgeClass(string $orderType): string
{
    return strtolower($orderType) === 'customized' ? 'customized' : 'readymade';
}

function jcvStatusClass(string $status): string
{
    $status = strtolower(trim($status));

    if ($status === 'completed') {
        return 'completed';
    }

    if (in_array($status, ['in_progress', 'progress'], true)) {
        return 'progress';
    }

    if (in_array($status, ['delayed', 'cancelled'], true)) {
        return 'danger';
    }

    return 'pending';
}

function jcvCanUpdateStep(
    string $roleKey,
    ?string $stepRoleKey,
    ?string $jobPrintingRoleKey,
    ?string $jobPrintingTypeRoleKey,
    bool $canUpdateJob,
    string $stepStatus
): bool {
    $roleKey = strtolower(trim($roleKey));
    $stepRoleKey = strtolower(trim((string)$stepRoleKey));
    $jobPrintingRoleKey = strtolower(trim((string)$jobPrintingRoleKey));
    $jobPrintingTypeRoleKey = strtolower(trim((string)$jobPrintingTypeRoleKey));
    $stepStatus = strtolower(trim($stepStatus));

    if (!$canUpdateJob) {
        return false;
    }

    if ($roleKey === 'admin') {
        return true;
    }

    if (in_array($stepStatus, ['completed', 'cancelled'], true)) {
        return false;
    }

    if ($stepRoleKey === $roleKey) {
        return true;
    }

    $printingRoles = [
        'offset_printing',
        'screen_printing',
        'digital_printing',
        'multicolor_offset_printing'
    ];

    if (in_array($roleKey, $printingRoles, true)) {
        if ($stepRoleKey === 'printing') {
            return $jobPrintingRoleKey === $roleKey || $jobPrintingTypeRoleKey === $roleKey;
        }

        return $stepRoleKey === $roleKey;
    }

    if ($roleKey === 'printing') {
        return $stepRoleKey === 'printing' || in_array($stepRoleKey, $printingRoles, true);
    }

    return false;
}

function jcvIsApprovalStage(array $step): bool
{
    $stepKey = strtolower(trim((string)($step['step_key'] ?? '')));
    return (int)($step['is_approval_step'] ?? 0) === 1
        || in_array($stepKey, ['proofing_approval', 'design_approval'], true);
}

function jcvApprovalTypeForStep(array $step): string
{
    $stepKey = strtolower(trim((string)($step['step_key'] ?? '')));

    if ($stepKey === 'proofing_approval') {
        return 'proof_approval';
    }

    if ($stepKey === 'design_approval') {
        return 'design_approval';
    }

    return 'confirmation';
}

function jcvApprovalIsDone(?array $approval): bool
{
    if (!$approval) {
        return false;
    }

    return strtolower((string)($approval['status'] ?? '')) === 'approved'
        || (int)($approval['approved_by_customer'] ?? 0) === 1
        || (int)($approval['approved_by_call'] ?? 0) === 1;
}

function jcvRandomToken(): string
{
    try {
        return bin2hex(random_bytes(24));
    } catch (Throwable $e) {
        return sha1(uniqid('approval_', true) . mt_rand());
    }
}

function jcvGetCustomerApproval(mysqli $conn, int $jobId, int $workflowStepId, string $approvalType): ?array
{
    if (!jcvTableExists($conn, 'customer_approvals')) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM customer_approvals
        WHERE job_card_id = ?
          AND workflow_step_id = ?
          AND approval_type = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('iis', $jobId, $workflowStepId, $approvalType);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function jcvSaveManualCustomerApproval(
    mysqli $conn,
    int $jobId,
    int $workflowStepId,
    string $approvalType,
    string $customerName,
    string $mobile,
    int $userId,
    string $remarks
): void {
    if (!jcvTableExists($conn, 'customer_approvals')) {
        throw new RuntimeException('customer_approvals table is missing.');
    }

    $existing = jcvGetCustomerApproval($conn, $jobId, $workflowStepId, $approvalType);

    if ($existing) {
        $approvalId = (int)$existing['id'];
        $stmt = $conn->prepare("
            UPDATE customer_approvals
            SET
                status = 'approved',
                approved_by_call = 1,
                call_confirmed_by = ?,
                internal_remarks = ?,
                approved_at = COALESCE(approved_at, NOW()),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('isi', $userId, $remarks, $approvalId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $token = jcvRandomToken();
    $stmt = $conn->prepare("
        INSERT INTO customer_approvals
        (
            job_card_id,
            workflow_step_id,
            approval_type,
            approval_token,
            customer_name,
            mobile,
            status,
            approved_by_customer,
            approved_by_call,
            call_confirmed_by,
            internal_remarks,
            approved_at,
            created_at
        )
        VALUES
        (?, ?, ?, ?, ?, ?, 'approved', 0, 1, ?, ?, NOW(), NOW())
    ");
    $stmt->bind_param('iissssis', $jobId, $workflowStepId, $approvalType, $token, $customerName, $mobile, $userId, $remarks);
    $stmt->execute();
    $stmt->close();
}

$roleKey = strtolower((string)($_SESSION['role_key'] ?? ''));

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

$canUpdateJob = false;
$canManualCustomerApproval = in_array($roleKey, ['admin', 'sales'], true);

try {
    $canUpdateJob = is_admin_user() || can_update($conn, 'job_cards.php');
} catch (Throwable $e) {
    $canUpdateJob = is_admin_user();
}

$jobId = (int)($_GET['id'] ?? 0);
$message = '';
$messageType = 'danger';
$job = null;
$trackingRows = [];
$delayReasons = [];

if (($_GET['msg'] ?? '') === 'status_updated') {
    $message = 'Job status updated successfully.';
    $messageType = 'success';
}

try {
    if (jcvTableExists($conn, 'delay_reasons')) {
        $res = $conn->query("
            SELECT id, reason_name
            FROM delay_reasons
            WHERE is_active = 1
            ORDER BY id ASC, reason_name ASC
        ");

        while ($row = $res->fetch_assoc()) {
            $delayReasons[] = $row;
        }

        $res->free();
    }
} catch (Throwable $e) {
    $delayReasons = [];
}

if ($jobId <= 0) {
    $message = 'Invalid job card.';
    $messageType = 'danger';
} elseif (!jcvTableExists($conn, 'job_cards')) {
    $message = 'job_cards table is missing.';
    $messageType = 'danger';
} else {
    try {
        $where = ['jc.id = ?'];
        $params = [$jobId];
        $types = 'i';

        if (!$hasAllJobCardAccess) {
            if ($isSpecificPrintingRole) {
                $where[] = "(pt.role_key = ? OR rprint.role_key = ?)";
                $params[] = $roleKey;
                $params[] = $roleKey;
                $types .= 'ss';
            } elseif ($isGeneralPrintingRole) {
                $where[] = "(
                    pt.role_key IN ('offset_printing','screen_printing','digital_printing','multicolor_offset_printing')
                    OR rprint.role_key IN ('offset_printing','screen_printing','digital_printing','multicolor_offset_printing')
                )";
            } else {
                $where[] = "1 = 0";
            }
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

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

                creator.username AS created_by_name,

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

            LEFT JOIN users creator
                ON creator.id = jc.created_by

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

            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$job) {
            $message = 'Job card not found or you do not have permission to view this job.';
            $messageType = 'danger';
        }
    } catch (Throwable $e) {
        $message = 'Job card query error: ' . $e->getMessage();
        $messageType = 'danger';
        $job = null;
    }
}

if ($job && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_step_status') {
    $trackingId = (int)($_POST['tracking_id'] ?? 0);
    $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $delayReasonId = (int)($_POST['delay_reason_id'] ?? 0);
    $delayDays = max(0, (int)($_POST['delay_days'] ?? 0));

    $allowedStatus = [
        'pending',
        'in_progress',
        'completed',
        'delayed',
        'skipped',
        'cancelled'
    ];

    if ($trackingId <= 0 || !in_array($newStatus, $allowedStatus, true)) {
        $message = 'Invalid status update request.';
        $messageType = 'danger';
    } elseif ($newStatus === 'delayed' && $remarks === '') {
        $message = 'Delay remark is required.';
        $messageType = 'danger';
    } elseif ($newStatus === 'delayed' && $delayReasonId <= 0) {
        $message = 'Delay reason is required.';
        $messageType = 'danger';
    } else {
        try {
            $stmt = $conn->prepare("
                SELECT
                    jt.*,
                    rr.role_key AS responsible_role_key,
                    ws.default_owner_role_key,
                    ws.step_key,
                    ws.step_name,
                    ws.is_approval_step
                FROM job_tracking jt
                LEFT JOIN roles rr
                    ON rr.id = jt.responsible_role_id
                LEFT JOIN workflow_steps ws
                    ON ws.id = jt.workflow_step_id
                WHERE jt.id = ?
                  AND jt.job_card_id = ?
                LIMIT 1
            ");
            $stmt->bind_param('ii', $trackingId, $jobId);
            $stmt->execute();
            $stepRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$stepRow) {
                $message = 'Tracking stage not found.';
                $messageType = 'danger';
            } else {
                $stepRoleKey = $stepRow['responsible_role_key'] ?: $stepRow['default_owner_role_key'];
                $oldStepStatus = strtolower((string)($stepRow['status'] ?? 'pending'));

                $canUpdateThisStep = jcvCanUpdateStep(
                    $roleKey,
                    $stepRoleKey,
                    $job['assigned_printing_role_key'] ?? '',
                    $job['printing_role_key'] ?? '',
                    $canUpdateJob,
                    $oldStepStatus
                );

                if (!$canUpdateThisStep) {
                    $message = 'You do not have permission to update this stage.';
                    $messageType = 'danger';
                } else {
                    $userId = (int)($_SESSION['user_id'] ?? 0);

                    $isApprovalStage = jcvIsApprovalStage($stepRow);

                    if ($newStatus === 'completed' && $isApprovalStage) {
                        $approvalType = jcvApprovalTypeForStep($stepRow);
                        $approval = jcvGetCustomerApproval($conn, $jobId, (int)$stepRow['workflow_step_id'], $approvalType);

                        if (!jcvApprovalIsDone($approval)) {
                            $manualConfirm = isset($_POST['manual_customer_approved']) ? 1 : 0;
                            $approvalRemarks = trim((string)($_POST['approval_remarks'] ?? ''));

                            if (!$canManualCustomerApproval) {
                                throw new RuntimeException('Customer approval is required before completing this stage. Admin or Sales can manually confirm if the customer approved by call.');
                            }

                            if ($manualConfirm !== 1) {
                                throw new RuntimeException('Customer approval confirmation is required for this approval stage.');
                            }

                            if ($approvalRemarks === '') {
                                throw new RuntimeException('Customer approval remark is required.');
                            }

                            jcvSaveManualCustomerApproval(
                                $conn,
                                $jobId,
                                (int)$stepRow['workflow_step_id'],
                                $approvalType,
                                (string)($job['customer_name'] ?? ''),
                                (string)($job['mobile'] ?? ''),
                                $userId,
                                $approvalRemarks
                            );
                        }
                    }

                    if ($newStatus === 'completed') {
                        $stmt = $conn->prepare("
                            UPDATE job_tracking
                            SET
                                status = ?,
                                remarks = ?,
                                actual_start_at = COALESCE(actual_start_at, NOW()),
                                actual_completed_at = NOW(),
                                completed_by = ?,
                                updated_at = NOW()
                            WHERE id = ?
                              AND job_card_id = ?
                        ");
                        $stmt->bind_param('ssiii', $newStatus, $remarks, $userId, $trackingId, $jobId);
                        $stmt->execute();
                        $stmt->close();
                    } elseif ($newStatus === 'in_progress') {
                        $stmt = $conn->prepare("
                            UPDATE job_tracking
                            SET
                                status = ?,
                                remarks = ?,
                                actual_start_at = COALESCE(actual_start_at, NOW()),
                                updated_at = NOW()
                            WHERE id = ?
                              AND job_card_id = ?
                        ");
                        $stmt->bind_param('ssii', $newStatus, $remarks, $trackingId, $jobId);
                        $stmt->execute();
                        $stmt->close();
                    } elseif ($newStatus === 'delayed') {
                        $delayReasonValue = $delayReasonId;

                        $stmt = $conn->prepare("
                            UPDATE job_tracking
                            SET
                                status = ?,
                                remarks = ?,
                                is_delayed = 1,
                                delay_started_at = COALESCE(delay_started_at, NOW()),
                                delay_days = ?,
                                delay_reason_id = ?,
                                delay_remarks = ?,
                                updated_at = NOW()
                            WHERE id = ?
                              AND job_card_id = ?
                        ");

                        $stmt->bind_param(
                            'ssiisii',
                            $newStatus,
                            $remarks,
                            $delayDays,
                            $delayReasonValue,
                            $remarks,
                            $trackingId,
                            $jobId
                        );

                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE job_tracking
                            SET
                                status = ?,
                                remarks = ?,
                                updated_at = NOW()
                            WHERE id = ?
                              AND job_card_id = ?
                        ");
                        $stmt->bind_param('ssii', $newStatus, $remarks, $trackingId, $jobId);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $summary = [
                        'total_steps' => 0,
                        'completed_steps' => 0,
                        'open_steps' => 0,
                        'progress_steps' => 0,
                        'delayed_steps' => 0,
                        'delay_history_steps' => 0
                    ];

                    $stmt = $conn->prepare("
                        SELECT
                            COUNT(*) AS total_steps,
                            SUM(CASE WHEN status IN ('completed','skipped') THEN 1 ELSE 0 END) AS completed_steps,
                            SUM(CASE WHEN status NOT IN ('completed','skipped','cancelled') THEN 1 ELSE 0 END) AS open_steps,
                            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS progress_steps,
                            SUM(CASE WHEN status = 'delayed' THEN 1 ELSE 0 END) AS delayed_steps,
                            SUM(CASE WHEN is_delayed = 1 THEN 1 ELSE 0 END) AS delay_history_steps
                        FROM job_tracking
                        WHERE job_card_id = ?
                    ");
                    $stmt->bind_param('i', $jobId);
                    $stmt->execute();
                    $summaryRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($summaryRow) {
                        $summary = array_merge($summary, $summaryRow);
                    }

                    $currentWorkflowStepId = null;

                    $stmt = $conn->prepare("
                        SELECT jt.workflow_step_id
                        FROM job_tracking jt
                        LEFT JOIN workflow_steps ws
                            ON ws.id = jt.workflow_step_id
                        WHERE jt.job_card_id = ?
                          AND jt.status NOT IN ('completed','skipped','cancelled')
                        ORDER BY ws.sort_order ASC, jt.id ASC
                        LIMIT 1
                    ");
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

                    $stmt = $conn->prepare("
                        SELECT id
                        FROM job_card_statuses
                        WHERE status_key = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param('s', $jobStatusKey);
                    $stmt->execute();
                    $statusRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($statusRow) {
                        $jobStatusId = (int)$statusRow['id'];
                    }

                    $isDelayed = ((int)($summary['delay_history_steps'] ?? 0) > 0 || $jobStatusKey === 'delayed') ? 1 : 0;

                    if ($jobStatusId && $currentWorkflowStepId) {
                        $stmt = $conn->prepare("
                            UPDATE job_cards
                            SET
                                current_workflow_step_id = ?,
                                job_card_status_id = ?,
                                is_delayed = ?,
                                completed_at = CASE
                                    WHEN ? = 'completed' THEN COALESCE(completed_at, NOW())
                                    ELSE completed_at
                                END,
                                updated_by = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->bind_param(
                            'iiisii',
                            $currentWorkflowStepId,
                            $jobStatusId,
                            $isDelayed,
                            $jobStatusKey,
                            $userId,
                            $jobId
                        );
                        $stmt->execute();
                        $stmt->close();
                    } elseif ($jobStatusId) {
                        $stmt = $conn->prepare("
                            UPDATE job_cards
                            SET
                                job_card_status_id = ?,
                                is_delayed = ?,
                                completed_at = CASE
                                    WHEN ? = 'completed' THEN COALESCE(completed_at, NOW())
                                    ELSE completed_at
                                END,
                                updated_by = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->bind_param(
                            'iisii',
                            $jobStatusId,
                            $isDelayed,
                            $jobStatusKey,
                            $userId,
                            $jobId
                        );
                        $stmt->execute();
                        $stmt->close();
                    }

                    header('Location: job_card_view.php?id=' . $jobId . '&msg=status_updated');
                    exit;
                }
            }
        } catch (Throwable $e) {
            $message = 'Status update failed: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

if ($job) {
    try {
        if (jcvTableExists($conn, 'job_tracking')) {
            $stmt = $conn->prepare("
                SELECT
                    jt.*,
                    ws.step_name,
                    ws.step_key,
                    ws.sort_order,
                    ws.default_owner_role_key,
                    ws.is_approval_step,
                    rr.role_name AS responsible_role_name,
                    rr.role_key AS responsible_role_key,
                    ru.username AS responsible_user_name,
                    cu.username AS completed_by_name,
                    dr.reason_name AS delay_reason_name,
                    ca.id AS approval_id,
                    ca.job_card_id AS approval_job_card_id,
                    ca.workflow_step_id AS approval_workflow_step_id,
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
                FROM job_tracking jt
                LEFT JOIN workflow_steps ws
                    ON ws.id = jt.workflow_step_id
                LEFT JOIN roles rr
                    ON rr.id = jt.responsible_role_id
                LEFT JOIN users ru
                    ON ru.id = jt.responsible_user_id
                LEFT JOIN users cu
                    ON cu.id = jt.completed_by
                LEFT JOIN delay_reasons dr
                    ON dr.id = jt.delay_reason_id
                LEFT JOIN (
                    SELECT ca1.*
                    FROM customer_approvals ca1
                    INNER JOIN (
                        SELECT job_card_id, workflow_step_id, MAX(id) AS max_id
                        FROM customer_approvals
                        GROUP BY job_card_id, workflow_step_id
                    ) latest_ca
                        ON latest_ca.max_id = ca1.id
                ) ca
                    ON ca.job_card_id = jt.job_card_id
                   AND ca.workflow_step_id = jt.workflow_step_id
                LEFT JOIN users call_user
                    ON call_user.id = ca.call_confirmed_by
                LEFT JOIN users sent_user
                    ON sent_user.id = ca.link_sent_by
                WHERE jt.job_card_id = ?
                ORDER BY ws.sort_order ASC, jt.id ASC
            ");
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $trackingRows[] = $row;
            }

            $stmt->close();
        }
    } catch (Throwable $e) {
        $trackingRows = [];
    }
}

$totalSteps = $job ? (int)($job['total_steps'] ?? 0) : 0;
$completedSteps = $job ? (int)($job['completed_steps'] ?? 0) : 0;
$progressPercent = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;
$progressPercent = max(0, min(100, $progressPercent));

$orderType = $job ? strtolower((string)($job['order_type'] ?? 'readymade')) : '';
$statusKey = $job ? strtolower((string)($job['status_key'] ?? '')) : '';

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>View Job Card - Subhiksha Cards</title>

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

    .info-card {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 16px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        height: 100%;
    }

    .info-card small {
        display: block;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        margin-bottom: 5px;
    }

    .info-card strong,
    .info-card span {
        display: block;
        color: var(--text-main);
        font-weight: 900;
        word-break: break-word;
        white-space: pre-wrap;
    }

    .order-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 7px 12px;
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

    .status-pill.completed {
        color: #166534;
        background: #dcfce7;
    }

    .status-pill.progress {
        color: #1d4ed8;
        background: #dbeafe;
    }

    .status-pill.pending {
        color: #92400e;
        background: #fef3c7;
    }

    .status-pill.danger {
        color: #991b1b;
        background: #fee2e2;
    }

    .progress-wrap {
        width: 100%;
        height: 12px;
        background: color-mix(in srgb, var(--border-soft) 80%, transparent);
        border-radius: 999px;
        overflow: hidden;
    }

    .progress-bar-mini {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(135deg, #2563eb, #22c55e);
    }

    .timeline {
        display: grid;
        gap: 12px;
    }

    .timeline-item {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 12px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
    }

    .timeline-item.delayed-history {
        border-color: #fecaca;
        background: color-mix(in srgb, #fee2e2 34%, var(--card-bg));
        box-shadow: 0 10px 24px rgba(220, 38, 38, .08);
    }

    .delay-history-note {
        border: 1px solid #fca5a5;
        background: #fef2f2;
        color: #991b1b;
        border-radius: 14px;
        padding: 10px 12px;
        font-weight: 900;
        font-size: 12px;
    }

    .delay-card {
        border-color: #fca5a5 !important;
        background: #fef2f2 !important;
    }

    .delay-card small,
    .delay-card strong,
    .delay-card span {
        color: #991b1b !important;
    }

    .timeline-title {
        font-size: 15px;
        font-weight: 900;
        color: var(--text-main);
        margin: 0;
    }

    .timeline-meta {
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
        margin-top: 4px;
    }

    .amount-card {
        padding: 12px 14px;
        border-radius: 14px;
        background: linear-gradient(135deg, rgba(37, 99, 235, .10), rgba(34, 197, 94, .10));
        border: 1px solid var(--border-soft);
    }

    .amount-card small {
        display: block;
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 900;
        text-transform: uppercase;
    }

    .amount-card strong {
        display: block;
        margin-top: 3px;
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
    }

    .timeline-item .info-card {
        padding: 10px 12px;
        border-radius: 12px;
    }

    .timeline-item .info-card small {
        font-size: 10px;
        margin-bottom: 3px;
    }

    .timeline-item .info-card strong,
    .timeline-item .info-card span {
        font-size: 13px;
        line-height: 1.35;
    }

    .stage-update-form {
        border: 1px dashed var(--border-soft);
        background: color-mix(in srgb, var(--card-bg) 92%, #dbeafe);
    }

    .stage-update-form textarea {
        min-height: 46px;
    }

    .delay-field {
        display: none;
    }

    .stage-update-form.is-delay .delay-field {
        display: block;
    }

    .required-star {
        color: #dc2626;
        font-weight: 900;
    }

    .approval-field {
        display: none;
    }

    .stage-update-form.needs-approval .approval-field {
        display: block;
    }

    .approval-box {
        border: 1px solid #f59e0b;
        background: #fffbeb;
        color: #78350f;
        border-radius: 16px;
        padding: 14px 16px;
    }

    .approval-box.success {
        border-color: #22c55e;
        background: #f0fdf4;
        color: #14532d;
    }

    .approval-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
        margin-top: 10px;
    }

    .approval-mini {
        border: 1px solid rgba(120, 53, 15, .18);
        border-radius: 12px;
        padding: 8px 10px;
        background: rgba(255, 255, 255, .55);
        min-width: 0;
    }

    .approval-box.success .approval-mini {
        border-color: rgba(20, 83, 45, .16);
    }

    .approval-mini small {
        display: block;
        font-size: 9px;
        font-weight: 900;
        text-transform: uppercase;
        opacity: .75;
        margin-bottom: 2px;
    }

    .approval-mini strong,
    .approval-mini span {
        display: block;
        font-size: 12px;
        font-weight: 900;
        word-break: break-word;
    }

    @media(max-width:991.98px) {
        .approval-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
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
                            <h1 class="mb-1">View Job Card</h1>
                            <p class="text-muted-custom mb-0">
                                <?= $job ? e($job['job_card_no']) : 'Job card details' ?>
                            </p>
                        </div>

                        <a href="job_cards.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                            Back to Job Cards
                        </a>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                <div class="alert alert-<?= e($messageType === 'success' ? 'success' : 'danger') ?> rounded-4 fw-bold">
                    <?= e($message) ?>
                </div>
                <?php endif; ?>

                <?php if (!$job): ?>
                <div class="card-ui module-card">
                    <div class="alert alert-danger rounded-4 fw-bold mb-0">
                        <?= e($message ?: 'Job card not found.') ?>
                    </div>
                </div>
                <?php else: ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4">
                        <div class="amount-card">
                            <small>Final Amount</small>
                            <strong><?= e(jcvMoney($job['final_amount'] ?? 0)) ?></strong>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="amount-card">
                            <small>Advance Amount</small>
                            <strong><?= e(jcvMoney($job['advance_amount'] ?? 0)) ?></strong>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="amount-card">
                            <small>Balance Amount</small>
                            <strong><?= e(jcvMoney($job['balance_amount'] ?? 0)) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card mb-3">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                        <div>
                            <h2 class="module-title"><?= e($job['job_card_no']) ?></h2>
                            <p class="text-muted-custom mb-0">
                                Created on <?= e(jcvDateTime($job['created_at'] ?? null)) ?>
                            </p>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <span class="order-badge <?= e(jcvOrderBadgeClass($orderType)) ?>">
                                <?= e($orderType === 'customized' ? 'Customized' : 'Readymade') ?>
                            </span>

                            <span class="status-pill <?= e(jcvStatusClass($statusKey)) ?>">
                                <?= e($job['status_name'] ?? 'Status') ?>
                            </span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Customer</small>
                                <strong><?= e($job['customer_name']) ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Mobile</small>
                                <strong><?= e($job['mobile']) ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Delivery Date</small>
                                <strong><?= e(jcvDate($job['delivery_date'] ?? null)) ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Function / Product Type</small>
                                <strong><?= e($job['function_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Product Name</small>
                                <strong><?= e($job['product_name'] ?: '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Printing Type</small>
                                <strong><?= e($job['printing_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Printing Sub Type</small>
                                <strong><?= e($job['sub_type_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Current Stage</small>
                                <strong><?= e($job['current_step_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="info-card">
                                <small>Assigned Printing Role</small>
                                <strong><?= e($job['assigned_printing_role_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="info-card">
                                <small>Sales Person</small>
                                <strong><?= e($job['sales_person'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="info-card">
                                <small>Designer</small>
                                <strong><?= e($job['designer_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="info-card">
                                <small>Printer</small>
                                <strong><?= e($job['printer_name'] ?? '-') ?></strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="info-card">
                                <small>Created By</small>
                                <strong><?= e($job['created_by_name'] ?? '-') ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card mb-3">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Progress</h2>
                            <p class="text-muted-custom mb-0">
                                <?= number_format($completedSteps) ?> completed out of <?= number_format($totalSteps) ?>
                                stages.
                            </p>
                        </div>

                        <strong class="fs-4"><?= (int)$progressPercent ?>%</strong>
                    </div>

                    <div class="progress-wrap">
                        <div class="progress-bar-mini" style="width:<?= (int)$progressPercent ?>%"></div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Workflow Tracking</h2>
                            <p class="text-muted-custom mb-0">
                                Stage-wise production tracking details.
                            </p>
                        </div>
                    </div>

                    <?php if (!$trackingRows): ?>
                    <div class="alert alert-warning rounded-4 fw-bold mb-0">
                        No tracking stages found for this job card.
                    </div>
                    <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($trackingRows as $step): ?>
                        <?php
                            $stepStatus = strtolower((string)($step['status'] ?? 'pending'));
                            $statusClass = jcvStatusClass($stepStatus);
                            $stepOwnerRoleKey = $step['responsible_role_key'] ?: ($step['default_owner_role_key'] ?? '');
                            $canUpdateThisStep = jcvCanUpdateStep(
                                $roleKey,
                                $stepOwnerRoleKey,
                                $job['assigned_printing_role_key'] ?? '',
                                $job['printing_role_key'] ?? '',
                                $canUpdateJob,
                                $stepStatus
                            );
                            $stageWasDelayed = (int)($step['is_delayed'] ?? 0) === 1
                                || !empty($step['delay_started_at'])
                                || !empty($step['delay_reason_id'])
                                || trim((string)($step['delay_remarks'] ?? '')) !== '';
                            $stageDelayedCompleted = $stageWasDelayed && $stepStatus === 'completed';
                        ?>
                        <div class="timeline-item <?= $stageWasDelayed ? 'delayed-history' : '' ?>">
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                                <div>
                                    <h3 class="timeline-title"><?= e($step['step_name'] ?? '-') ?></h3>
                                    <div class="timeline-meta">
                                        Role:
                                        <?= e($step['responsible_role_name'] ?? jcvRoleLabel($stepOwnerRoleKey)) ?>
                                        |
                                        User:
                                        <?= e($step['responsible_user_name'] ?? '-') ?>
                                    </div>
                                </div>

                                <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-2">
                                    <span class="status-pill <?= e($statusClass) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $stepStatus))) ?>
                                    </span>

                                    <?php if ($stageDelayedCompleted): ?>
                                    <span class="status-pill danger">Delayed & Completed</span>
                                    <?php elseif ($stageWasDelayed && $stepStatus !== 'delayed'): ?>
                                    <span class="status-pill danger">Delayed History</span>
                                    <?php endif; ?>

                                    <?php if ($canUpdateThisStep): ?>
                                    <button type="button"
                                        class="btn btn-sm btn-primary rounded-pill px-3 fw-bold"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#updateStage<?= (int)$step['id'] ?>">
                                        Update Status
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="row g-3 mt-2">
                                <div class="col-md-3">
                                    <div class="info-card">
                                        <small>Planned Start</small>
                                        <strong><?= e(jcvDate($step['planned_start_date'] ?? null)) ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="info-card">
                                        <small>Planned Completion</small>
                                        <strong><?= e(jcvDate($step['planned_completion_date'] ?? null)) ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="info-card">
                                        <small>Actual Start</small>
                                        <strong><?= e(jcvDateTime($step['actual_start_at'] ?? null)) ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="info-card">
                                        <small>Actual Completed</small>
                                        <strong><?= e(jcvDateTime($step['actual_completed_at'] ?? null)) ?></strong>
                                    </div>
                                </div>

                                <?php if ($stageWasDelayed): ?>
                                <div class="col-12">
                                    <div class="delay-history-note">
                                        <?= $stageDelayedCompleted ? 'This stage was delayed and later completed.' : 'This stage has delay history.' ?>
                                        <?= !empty($step['delay_remarks']) ? ' Remark: ' . e($step['delay_remarks']) : '' ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($stageWasDelayed || $stepStatus === 'delayed'): ?>
                                <div class="col-md-4">
                                    <div class="info-card delay-card">
                                        <small>Delay Days</small>
                                        <strong><?= e($step['delay_days'] ?? 0) ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="info-card delay-card">
                                        <small>Delay Reason</small>
                                        <strong><?= e($step['delay_reason_name'] ?? '-') ?></strong>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="info-card delay-card">
                                        <small>Delay Remarks</small>
                                        <span><?= e($step['delay_remarks'] ?? '-') ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($step['remarks'])): ?>
                                <div class="col-12">
                                    <div class="info-card">
                                        <small>Remarks</small>
                                        <span><?= e($step['remarks']) ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php
                                    $isApprovalStage = jcvIsApprovalStage($step);
                                    $approvalRow = null;
                                    if (!empty($step['approval_id'])) {
                                        $approvalRow = [
                                            'status' => $step['approval_status'] ?? '',
                                            'approved_by_customer' => $step['approved_by_customer'] ?? 0,
                                            'approved_by_call' => $step['approved_by_call'] ?? 0
                                        ];
                                    }
                                    $approvalDone = jcvApprovalIsDone($approvalRow);
                                ?>

                                <?php if ($isApprovalStage): ?>
                                <div class="col-12">
                                    <div class="approval-box <?= $approvalDone ? 'success' : '' ?>">
                                        <strong>Customer Approval: <?= $approvalDone ? 'Approved' : 'Pending' ?></strong>
                                        <div class="small mt-1">
                                            <?php if ($approvalDone): ?>
                                                <?php if ((int)($step['approved_by_customer'] ?? 0) === 1): ?>
                                                    Approved by customer link.
                                                <?php elseif ((int)($step['approved_by_call'] ?? 0) === 1): ?>
                                                    Manually approved by call<?= !empty($step['call_confirmed_by_name']) ? ' by ' . e($step['call_confirmed_by_name']) : '' ?>.
                                                <?php endif; ?>
                                                <?= !empty($step['approved_at']) ? ' Manual/customer approved time: ' . e(jcvDateTime($step['approved_at'])) : '' ?>
                                            <?php else: ?>
                                                This approval stage cannot be completed until the customer approves it, or Admin/Sales confirms approval by call.
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($step['approval_id'])): ?>
                                        <div class="approval-grid">
                                            <div class="approval-mini"><small>ID</small><strong><?= e($step['approval_id']) ?></strong></div>
                                            <div class="approval-mini"><small>Job Card ID</small><strong><?= e($step['approval_job_card_id'] ?? '-') ?></strong></div>
                                            <div class="approval-mini"><small>Workflow Step ID</small><strong><?= e($step['approval_workflow_step_id'] ?? '-') ?></strong></div>
                                            <div class="approval-mini"><small>Approval Type</small><strong><?= e($step['approval_type'] ?? '-') ?></strong></div>
                                            <div class="approval-mini"><small>Approval Token</small><span><?= e($step['approval_token'] ?? '-') ?></span></div>
                                            <div class="approval-mini"><small>Customer Name</small><strong><?= e($step['approval_customer_name'] ?? '-') ?></strong></div>
                                            <div class="approval-mini"><small>Mobile</small><strong><?= e($step['approval_mobile'] ?? '-') ?></strong></div>
                                            <div class="approval-mini"><small>Status</small><strong><?= e($step['approval_status'] ?? '-') ?></strong></div>
                                            <div class="approval-mini"><small>Approved By Customer</small><strong><?= ((int)($step['approved_by_customer'] ?? 0) === 1) ? 'Yes' : 'No' ?></strong></div>
                                            <div class="approval-mini"><small>Approved By Call</small><strong><?= ((int)($step['approved_by_call'] ?? 0) === 1) ? 'Yes' : 'No' ?></strong></div>
                                            <div class="approval-mini"><small>Call Confirmed By</small><strong><?= e($step['call_confirmed_by_name'] ?? ($step['call_confirmed_by'] ?? '-')) ?></strong></div>
                                            <div class="approval-mini"><small>Approved At</small><strong><?= e(jcvDateTime($step['approved_at'] ?? null)) ?></strong></div>
                                            <div class="approval-mini"><small>Rejected At</small><strong><?= e(jcvDateTime($step['rejected_at'] ?? null)) ?></strong></div>
                                            <div class="approval-mini"><small>Expires At</small><strong><?= e(jcvDateTime($step['expires_at'] ?? null)) ?></strong></div>
                                            <div class="approval-mini"><small>IP Address</small><strong><?= e($step['ip_address'] ?? '-') ?></strong></div>
                                            <div class="approval-mini"><small>Link Sent At</small><strong><?= e(jcvDateTime($step['link_sent_at'] ?? null)) ?></strong></div>
                                            <div class="approval-mini"><small>Link Sent By</small><strong><?= e($step['link_sent_by_name'] ?? ($step['link_sent_by'] ?? '-')) ?></strong></div>
                                            <div class="approval-mini"><small>Created At</small><strong><?= e(jcvDateTime($step['approval_created_at'] ?? null)) ?></strong></div>
                                            <div class="approval-mini"><small>Updated At</small><strong><?= e(jcvDateTime($step['approval_updated_at'] ?? null)) ?></strong></div>
                                            <div class="approval-mini"><small>User Agent</small><span><?= e($step['user_agent'] ?? '-') ?></span></div>
                                        </div>
                                        <?php endif; ?>

                                        <?php if (!empty($step['customer_remarks'])): ?>
                                        <div class="small mt-2"><strong>Customer Remarks:</strong> <?= e($step['customer_remarks']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($step['internal_remarks'])): ?>
                                        <div class="small mt-1"><strong>Internal / Manual Approval Remarks:</strong> <?= e($step['internal_remarks']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($canUpdateThisStep): ?>
                                <div class="col-12">
                                    <div class="collapse" id="updateStage<?= (int)$step['id'] ?>">
                                        <form method="post" class="info-card mt-2 stage-update-form" data-approval-stage="<?= $isApprovalStage ? '1' : '0' ?>" data-approval-done="<?= $approvalDone ? '1' : '0' ?>" data-can-manual-approval="<?= $canManualCustomerApproval ? '1' : '0' ?>">
                                            <input type="hidden" name="action" value="update_step_status">
                                            <input type="hidden" name="tracking_id" value="<?= (int)$step['id'] ?>">

                                            <div class="row g-3 align-items-end">
                                                <div class="col-md-3">
                                                    <label class="form-label fw-bold">Update Status <span class="required-star">*</span></label>
                                                    <select name="status" class="form-select js-stage-status" required>
                                                        <option value="">Select Status</option>
                                                        <option value="pending" <?= $stepStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                        <option value="in_progress" <?= $stepStatus === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                                        <option value="completed" <?= $stepStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                                                        <option value="delayed" <?= $stepStatus === 'delayed' ? 'selected' : '' ?>>Delayed</option>
                                                        <option value="skipped" <?= $stepStatus === 'skipped' ? 'selected' : '' ?>>Skipped</option>
                                                        <option value="cancelled" <?= $stepStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-3 delay-field">
                                                    <label class="form-label fw-bold">Delay Reason <span class="required-star">*</span></label>
                                                    <select name="delay_reason_id" class="form-select">
                                                        <option value="">Select Reason</option>
                                                        <?php foreach ($delayReasons as $reason): ?>
                                                        <option value="<?= (int)$reason['id'] ?>"
                                                            <?= (int)($step['delay_reason_id'] ?? 0) === (int)$reason['id'] ? 'selected' : '' ?>>
                                                            <?= e($reason['reason_name']) ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="col-md-2 delay-field">
                                                    <label class="form-label fw-bold">Delay Days <span class="required-star">*</span></label>
                                                    <input type="number"
                                                        name="delay_days"
                                                        class="form-control"
                                                        min="0"
                                                        value="<?= e($step['delay_days'] ?? 0) ?>">
                                                </div>

                                                <div class="col-md-4">
                                                    <label class="form-label fw-bold">Remark <span class="required-star js-remark-star d-none">*</span></label>
                                                    <textarea name="remarks"
                                                        class="form-control js-remark"
                                                        rows="2"
                                                        placeholder="Enter update remark"><?= e($step['remarks'] ?? '') ?></textarea>
                                                </div>

                                                <?php if ($isApprovalStage && !$approvalDone): ?>
                                                <div class="col-12 approval-field">
                                                    <?php if ($canManualCustomerApproval): ?>
                                                    <div class="approval-box">
                                                        <div class="form-check mb-3">
                                                            <input class="form-check-input js-manual-approval" type="checkbox"
                                                                name="manual_customer_approved" value="1"
                                                                id="manualApproval<?= (int)$step['id'] ?>">
                                                            <label class="form-check-label fw-bold" for="manualApproval<?= (int)$step['id'] ?>">
                                                                Customer approved by call / direct confirmation
                                                                <span class="required-star">*</span>
                                                            </label>
                                                        </div>

                                                        <label class="form-label fw-bold">
                                                            Approval Remark <span class="required-star">*</span>
                                                        </label>
                                                        <textarea name="approval_remarks"
                                                            class="form-control js-approval-remarks"
                                                            rows="2"
                                                            placeholder="Example: Customer confirmed proof/design approval by phone call"><?= e($step['internal_remarks'] ?? '') ?></textarea>
                                                    </div>
                                                    <?php else: ?>
                                                    <div class="approval-box">
                                                        Customer approval is pending. Admin or Sales must confirm customer approval before this stage can be completed.
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>

                                                <div class="col-12 text-end">
                                                    <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">
                                                        Save Update
                                                    </button>
                                                </div>
                                            </div>

                                            <small class="text-muted-custom d-block mt-2">
                                                Delay status requires delay reason and remark. Proofing Approval / Design Approval requires customer approval before completion.
                                            </small>
                                        </form>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php endif; ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
    (function() {
        function refreshDelayFields(form) {
            const select = form.querySelector('.js-stage-status');
            const remark = form.querySelector('.js-remark');
            const remarkStar = form.querySelector('.js-remark-star');
            const delayReason = form.querySelector('select[name="delay_reason_id"]');
            const delayDays = form.querySelector('input[name="delay_days"]');
            const approvalRemarks = form.querySelector('.js-approval-remarks');
            const manualApproval = form.querySelector('.js-manual-approval');

            if (!select) return;

            if (select.value === 'delayed') {
                form.classList.add('is-delay');
                if (remark) remark.setAttribute('required', 'required');
                if (remarkStar) remarkStar.classList.remove('d-none');
                if (delayReason) delayReason.setAttribute('required', 'required');
                if (delayDays) delayDays.setAttribute('required', 'required');
            } else {
                form.classList.remove('is-delay');
                if (delayReason) delayReason.removeAttribute('required');
                if (delayDays) delayDays.removeAttribute('required');
            }

            const approvalStage = form.dataset.approvalStage === '1';
            const approvalDone = form.dataset.approvalDone === '1';
            const canManualApproval = form.dataset.canManualApproval === '1';
            const needsApproval = approvalStage && !approvalDone && select.value === 'completed';

            if (needsApproval) {
                form.classList.add('needs-approval');
                if (canManualApproval) {
                    if (manualApproval) manualApproval.setAttribute('required', 'required');
                    if (approvalRemarks) approvalRemarks.setAttribute('required', 'required');
                }
            } else {
                form.classList.remove('needs-approval');
                if (manualApproval) manualApproval.removeAttribute('required');
                if (approvalRemarks) approvalRemarks.removeAttribute('required');
            }

            if (select.value !== 'delayed' && !needsApproval) {
                if (remark) remark.removeAttribute('required');
                if (remarkStar) remarkStar.classList.add('d-none');
            }
        }

        document.querySelectorAll('.stage-update-form').forEach(function(form) {
            refreshDelayFields(form);

            const select = form.querySelector('.js-stage-status');
            if (select) {
                select.addEventListener('change', function() {
                    refreshDelayFields(form);
                });
            }
        });

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    })();
    </script>
</body>

</html>
