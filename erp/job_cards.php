<?php

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'job_cards.php');

$whatsappApiPath = __DIR__ . '/includes/whatsapp-api.php';
if (file_exists($whatsappApiPath)) {
    require_once $whatsappApiPath;
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

function jcColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) {
            $res->free();
        }
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function jcStatusIdByKey(mysqli $conn, string $statusKey): ?int
{
    if (!jcTableExists($conn, 'job_card_statuses')) {
        return null;
    }

    try {
        $stmt = $conn->prepare("SELECT id FROM job_card_statuses WHERE status_key = ? LIMIT 1");
        $stmt->bind_param('s', $statusKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function jcCurrentFilterQuery(array $extra = []): string
{
    $keep = [];
    foreach (['order_type', 'status', 'search', 'from_date', 'to_date', 'page'] as $key) {
        if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $keep[$key] = trim((string)$_GET[$key]);
        }
    }

    foreach ($extra as $key => $value) {
        if ($value === null) {
            unset($keep[$key]);
        } else {
            $keep[$key] = $value;
        }
    }

    return http_build_query($keep);
}

function jcRedirectBack(array $extra = []): void
{
    $query = jcCurrentFilterQuery($extra);
    header('Location: job_cards.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function jcLoadReadymadeTracking(mysqli $conn, int $jobId): array
{
    if ($jobId <= 0 || !jcTableExists($conn, 'job_tracking') || !jcTableExists($conn, 'workflow_steps')) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT
            jt.*,
            ws.step_key,
            ws.step_name,
            ws.sort_order
        FROM job_tracking jt
        INNER JOIN workflow_steps ws
            ON ws.id = jt.workflow_step_id
        WHERE jt.job_card_id = ?
          AND ws.order_type = 'readymade'
        ORDER BY ws.sort_order ASC, jt.id ASC
    ");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function jcFindTrackingByStepKey(array $trackingRows, array $keys): ?array
{
    $keys = array_map('strtolower', $keys);
    foreach ($trackingRows as $row) {
        $stepKey = strtolower(trim((string)($row['step_key'] ?? '')));
        if (in_array($stepKey, $keys, true)) {
            return $row;
        }
    }
    return null;
}

function jcIsReadymadeScreenJob(array $job): bool
{
    $orderType = strtolower(trim((string)($job['order_type'] ?? '')));
    if ($orderType !== 'readymade') {
        return false;
    }

    $printingRole = strtolower(trim((string)($job['printing_role_key'] ?? '')));
    $assignedRole = strtolower(trim((string)($job['assigned_printing_role_key'] ?? '')));
    $printingName = strtolower(trim((string)($job['printing_name'] ?? '')));
    $printingKey = strtolower(trim((string)($job['printing_key'] ?? '')));

    return $printingRole === 'screen_printing'
        || $assignedRole === 'screen_printing'
        || strpos($printingName, 'screen') !== false
        || strpos($printingKey, 'screen') !== false;
}

function jcReadymadeScreenShortcutState(mysqli $conn, int $jobId): array
{
    $state = [
        'eligible' => false,
        'started' => false,
        'completed' => false,
        'start_enabled' => false,
        'complete_enabled' => false,
        'start_label' => 'Not Started',
        'complete_label' => 'Disabled',
    ];

    $trackingRows = jcLoadReadymadeTracking($conn, $jobId);
    if (!$trackingRows) {
        return $state;
    }

    $printing = jcFindTrackingByStepKey($trackingRows, ['printing', 'print']);
    $sendToDispatch = jcFindTrackingByStepKey($trackingRows, ['send_to_dispatch', 'send_for_dispatch', 'sent_to_dispatch']);

    if (!$printing || !$sendToDispatch) {
        return $state;
    }

    $state['eligible'] = true;
    $printingStatus = strtolower(trim((string)($printing['status'] ?? 'pending')));
    $sendStatus = strtolower(trim((string)($sendToDispatch['status'] ?? 'pending')));

    $state['started'] = in_array($printingStatus, ['in_progress', 'completed', 'skipped'], true)
        || !empty($printing['actual_start_at']);
    $state['completed'] = in_array($sendStatus, ['completed', 'skipped'], true);

    if (!$state['started']) {
        $state['start_enabled'] = true;
        $state['complete_enabled'] = false;
        $state['start_label'] = 'Not Started';
        $state['complete_label'] = 'Disabled';
    } elseif (!$state['completed']) {
        $state['start_enabled'] = false;
        $state['complete_enabled'] = true;
        $state['start_label'] = 'Already Started';
        $state['complete_label'] = 'Click to finish';
    } else {
        $state['start_enabled'] = false;
        $state['complete_enabled'] = false;
        $state['start_label'] = 'Already Started';
        $state['complete_label'] = 'Completed';
    }

    return $state;
}

function jcInsertTrackingHistory(mysqli $conn, int $trackingId, int $jobId, int $workflowStepId, string $oldStatus, string $newStatus, string $remarks, int $userId): void
{
    if (!jcTableExists($conn, 'job_tracking_history')) {
        return;
    }

    try {
        $cols = [
            'job_tracking_id' => $trackingId,
            'job_card_id' => $jobId,
            'workflow_step_id' => $workflowStepId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'action_remarks' => $remarks,
            'changed_by' => $userId > 0 ? $userId : null,
            'changed_at' => date('Y-m-d H:i:s')
        ];

        $data = [];
        foreach ($cols as $col => $value) {
            if (jcColumnExists($conn, 'job_tracking_history', $col)) {
                $data[$col] = $value;
            }
        }

        if (jcColumnExists($conn, 'job_tracking_history', 'old_data')) {
            $data['old_data'] = json_encode(['status' => $oldStatus], JSON_UNESCAPED_UNICODE);
        }
        if (jcColumnExists($conn, 'job_tracking_history', 'new_data')) {
            $data['new_data'] = json_encode(['status' => $newStatus], JSON_UNESCAPED_UNICODE);
        }

        if (!$data) return;

        $fields = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $types = '';
        $values = [];
        foreach ($data as $value) {
            if (is_int($value) || $value === null) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
            $values[] = $value;
        }

        $sql = "INSERT INTO job_tracking_history (`" . implode('`,`', $fields) . "`) VALUES ({$placeholders})";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // History failure should not block the shortcut action.
    }
}

function jcUpdateTrackingStatus(mysqli $conn, array $row, string $newStatus, int $userId, string $remarks, bool $resetToPending = false): void
{
    $trackingId = (int)($row['id'] ?? 0);
    $jobId = (int)($row['job_card_id'] ?? 0);
    $workflowStepId = (int)($row['workflow_step_id'] ?? 0);
    $oldStatus = strtolower(trim((string)($row['status'] ?? 'pending')));

    if ($trackingId <= 0 || $jobId <= 0 || $workflowStepId <= 0 || $oldStatus === $newStatus) {
        return;
    }

    if ($newStatus === 'completed') {
        $stmt = $conn->prepare("
            UPDATE job_tracking
            SET status = 'completed',
                actual_start_at = COALESCE(actual_start_at, NOW()),
                actual_completed_at = COALESCE(actual_completed_at, NOW()),
                completed_by = COALESCE(completed_by, ?),
                remarks = CASE WHEN TRIM(COALESCE(remarks, '')) = '' THEN ? ELSE remarks END,
                updated_at = NOW()
            WHERE id = ?
              AND job_card_id = ?
        ");
        $stmt->bind_param('isii', $userId, $remarks, $trackingId, $jobId);
    } elseif ($newStatus === 'in_progress') {
        $stmt = $conn->prepare("
            UPDATE job_tracking
            SET status = 'in_progress',
                actual_start_at = COALESCE(actual_start_at, NOW()),
                actual_completed_at = NULL,
                completed_by = NULL,
                remarks = CASE WHEN TRIM(COALESCE(remarks, '')) = '' THEN ? ELSE remarks END,
                updated_at = NOW()
            WHERE id = ?
              AND job_card_id = ?
        ");
        $stmt->bind_param('sii', $remarks, $trackingId, $jobId);
    } elseif ($newStatus === 'pending' && $resetToPending) {
        $stmt = $conn->prepare("
            UPDATE job_tracking
            SET status = 'pending',
                actual_start_at = NULL,
                actual_completed_at = NULL,
                completed_by = NULL,
                updated_at = NOW()
            WHERE id = ?
              AND job_card_id = ?
        ");
        $stmt->bind_param('ii', $trackingId, $jobId);
    } else {
        return;
    }

    $stmt->execute();
    $stmt->close();

    jcInsertTrackingHistory($conn, $trackingId, $jobId, $workflowStepId, $oldStatus, $newStatus, $remarks, $userId);
}

function jcRefreshJobCardFromTracking(mysqli $conn, int $jobId, ?int $forcedCurrentStepId = null): void
{
    if ($jobId <= 0 || !jcTableExists($conn, 'job_tracking')) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT
            jt.workflow_step_id,
            jt.status,
            ws.sort_order
        FROM job_tracking jt
        LEFT JOIN workflow_steps ws
            ON ws.id = jt.workflow_step_id
        WHERE jt.job_card_id = ?
        ORDER BY COALESCE(ws.sort_order, jt.id) ASC, jt.id ASC
    ");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $res = $stmt->get_result();

    $total = 0;
    $completed = 0;
    $delayed = 0;
    $currentStepId = $forcedCurrentStepId;

    while ($row = $res->fetch_assoc()) {
        $total++;
        $status = strtolower(trim((string)($row['status'] ?? 'pending')));
        if (in_array($status, ['completed', 'skipped'], true)) {
            $completed++;
        }
        if ($status === 'delayed') {
            $delayed++;
        }
        if ($currentStepId === null && !in_array($status, ['completed', 'skipped', 'cancelled'], true)) {
            $currentStepId = (int)($row['workflow_step_id'] ?? 0);
        }
    }
    $stmt->close();

    $statusKey = 'in_progress';
    if ($delayed > 0) {
        $statusKey = 'delayed';
    } elseif ($total > 0 && $completed >= $total) {
        $statusKey = 'completed';
    }

    $statusId = jcStatusIdByKey($conn, $statusKey);
    $updatedBy = (int)($_SESSION['user_id'] ?? 0);

    if ($statusId && $currentStepId) {
        $stmt = $conn->prepare("
            UPDATE job_cards
            SET current_workflow_step_id = ?,
                job_card_status_id = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('iiii', $currentStepId, $statusId, $updatedBy, $jobId);
    } elseif ($statusId) {
        $stmt = $conn->prepare("
            UPDATE job_cards
            SET job_card_status_id = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('iii', $statusId, $updatedBy, $jobId);
    } else {
        return;
    }

    $stmt->execute();
    $stmt->close();
}

function jcBuildCustomerTrackingLink(array $job): string
{
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return '';
    }

    $scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    $baseUrl = $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir : '');

    $token = trim((string)($job['tracking_token'] ?? ''));
    if ($token !== '') {
        return $baseUrl . '/customer_tracking.php?token=' . rawurlencode($token);
    }

    $jobNo = trim((string)($job['job_card_no'] ?? ($job['job_no'] ?? '')));
    if ($jobNo !== '') {
        return $baseUrl . '/customer_tracking.php?job_card_no=' . rawurlencode($jobNo);
    }

    return $baseUrl . '/customer_tracking.php';
}

function jcSendReadymadeScreenStartWhatsapp(mysqli $conn, array $job): array
{
    if (!function_exists('subhiksha_send_template_whatsapp') && !function_exists('subhiksha_send_whatsapp')) {
        return [
            'success' => false,
            'message' => 'WhatsApp API helper is not available. Place whatsapp-api.php inside includes/ folder.',
            'log_id' => 0,
            'response' => ''
        ];
    }

    $mobile = trim((string)($job['mobile'] ?? ''));
    if ($mobile === '') {
        return [
            'success' => false,
            'message' => 'Customer mobile number is missing.',
            'log_id' => 0,
            'response' => ''
        ];
    }

    $customerName = trim((string)($job['customer_name'] ?? 'Customer')) ?: 'Customer';
    $jobNo = trim((string)($job['job_card_no'] ?? ($job['job_no'] ?? '')));
    $productName = trim((string)($job['product_name'] ?? 'Cards')) ?: 'Cards';
    $trackingLink = jcBuildCustomerTrackingLink($job);
    $deliveryDate = !empty($job['delivery_date'])
        ? date('d-m-Y', strtotime((string)$job['delivery_date']))
        : '-';

    // Meta-approved job_card_status BODY: 6 parameters.
    $variables = [
        'customer_name' => $customerName,
        'job_card_no' => $jobNo !== '' ? $jobNo : '-',
        'stage_name' => 'Printing',
        'status_name' => 'In Progress',
        'product_name' => $productName,
        'delivery_date' => $deliveryDate,
        'tracking_link' => $trackingLink,
    ];

    $meta = [
        'related_module' => 'Job Tracking',
        'related_id' => (int)($job['id'] ?? 0),
        'customer_id' => !empty($job['customer_id']) ? (int)$job['customer_id'] : null,
        'job_card_id' => (int)($job['id'] ?? 0),
        'sent_by' => (int)($_SESSION['user_id'] ?? 0),
    ];

    try {
        if (function_exists('subhiksha_send_template_whatsapp')) {
            return subhiksha_send_template_whatsapp(
                $conn,
                'job_card_status',
                $mobile,
                $variables,
                $meta
            );
        }

        $meta['mobile'] = $mobile;
        $meta['template_key'] = 'job_card_status';
        $meta['variables'] = $variables;
        return subhiksha_send_whatsapp($conn, $meta);
    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => 'WhatsApp sending failed.',
            'log_id' => 0,
            'response' => $e->getMessage()
        ];
    }
}


function jcSendReadymadeScreenCompleteWhatsapp(mysqli $conn, array $job): array
{
    if (!function_exists('subhiksha_send_template_whatsapp') && !function_exists('subhiksha_send_whatsapp')) {
        return [
            'success' => false,
            'message' => 'WhatsApp API helper is not available. Place whatsapp-api.php inside includes/ folder.',
            'log_id' => 0,
            'response' => ''
        ];
    }

    $mobile = trim((string)($job['mobile'] ?? ''));
    if ($mobile === '') {
        return [
            'success' => false,
            'message' => 'Customer mobile number is missing.',
            'log_id' => 0,
            'response' => ''
        ];
    }

    $customerName = trim((string)($job['customer_name'] ?? 'Customer')) ?: 'Customer';
    $jobNo = trim((string)($job['job_card_no'] ?? ($job['job_no'] ?? '')));
    $trackingLink = jcBuildCustomerTrackingLink($job);
    $deliveryDate = !empty($job['delivery_date'])
        ? date('d-m-Y', strtotime((string)$job['delivery_date']))
        : '-';

    // Meta-approved job_stage_completed BODY: 4 parameters.
    $variables = [
        'customer_name' => $customerName,
        'job_card_no' => $jobNo !== '' ? $jobNo : '-',
        'stage_name' => 'Printing',
        'delivery_date' => $deliveryDate,
        'tracking_link' => $trackingLink,
    ];

    $meta = [
        'related_module' => 'Job Tracking',
        'related_id' => (int)($job['id'] ?? 0),
        'customer_id' => !empty($job['customer_id']) ? (int)$job['customer_id'] : null,
        'job_card_id' => (int)($job['id'] ?? 0),
        'sent_by' => (int)($_SESSION['user_id'] ?? 0),
    ];

    try {
        if (function_exists('subhiksha_send_template_whatsapp')) {
            return subhiksha_send_template_whatsapp(
                $conn,
                'job_stage_completed',
                $mobile,
                $variables,
                $meta
            );
        }

        $meta['mobile'] = $mobile;
        $meta['template_key'] = 'job_stage_completed';
        $meta['variables'] = $variables;
        return subhiksha_send_whatsapp($conn, $meta);
    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => 'WhatsApp sending failed.',
            'log_id' => 0,
            'response' => $e->getMessage()
        ];
    }
}

function jcRunReadymadeScreenShortcut(mysqli $conn, int $jobId, string $shortcutAction, string $roleKey): array
{
    if ($roleKey !== 'screen_printing') {
        throw new RuntimeException('Only Screen Printing team can use this shortcut.');
    }

    if ($jobId <= 0) {
        throw new RuntimeException('Invalid job card.');
    }

    $stmt = $conn->prepare("
        SELECT
            jc.*,
            pt.printing_name,
            pt.printing_key,
            pt.role_key AS printing_role_key,
            rprint.role_key AS assigned_printing_role_key
        FROM job_cards jc
        LEFT JOIN printing_types pt
            ON pt.id = jc.printing_type_id
        LEFT JOIN roles rprint
            ON rprint.id = jc.assigned_printing_role_id
        WHERE jc.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$job) {
        throw new RuntimeException('Job card not found.');
    }

    if (!jcIsReadymadeScreenJob($job)) {
        throw new RuntimeException('This shortcut is allowed only for Readymade Screen Print job cards.');
    }

    $trackingRows = jcLoadReadymadeTracking($conn, $jobId);
    if (!$trackingRows) {
        throw new RuntimeException('Job tracking stages are missing for this job card.');
    }

    $masterCopyReceived = jcFindTrackingByStepKey($trackingRows, ['master_copy_received', 'master_copy_recieved']);
    $printing = jcFindTrackingByStepKey($trackingRows, ['printing', 'print']);
    $sendToDispatch = jcFindTrackingByStepKey($trackingRows, ['send_to_dispatch', 'send_for_dispatch', 'sent_to_dispatch']);

    if (!$masterCopyReceived || !$printing || !$sendToDispatch) {
        throw new RuntimeException('Required workflow stages are missing: Master Copy Received, Printing or Send to Dispatch.');
    }

    $masterSort = (int)$masterCopyReceived['sort_order'];
    $printingSort = (int)$printing['sort_order'];
    $sendSort = (int)$sendToDispatch['sort_order'];
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $conn->begin_transaction();

    try {
        if ($shortcutAction === 'readymade_screen_start') {
            foreach ($trackingRows as $row) {
                $sort = (int)($row['sort_order'] ?? 0);
                if ($sort <= $masterSort) {
                    jcUpdateTrackingStatus($conn, $row, 'completed', $userId, 'Screen Printing shortcut: Started job. Completed up to Master Copy Received.');
                } elseif ($sort === $printingSort) {
                    jcUpdateTrackingStatus($conn, $row, 'in_progress', $userId, 'Screen Printing shortcut: Printing started.');
                } elseif ($sort > $printingSort) {
                    jcUpdateTrackingStatus($conn, $row, 'pending', $userId, '', true);
                }
            }
            jcRefreshJobCardFromTracking($conn, $jobId, (int)$printing['workflow_step_id']);
        } elseif ($shortcutAction === 'readymade_screen_complete') {
            foreach ($trackingRows as $row) {
                $sort = (int)($row['sort_order'] ?? 0);
                if ($sort >= $printingSort && $sort <= $sendSort) {
                    jcUpdateTrackingStatus($conn, $row, 'completed', $userId, 'Screen Printing shortcut: Completed Printing to Send to Dispatch.');
                }
            }

            $nextStepId = null;
            foreach ($trackingRows as $row) {
                $sort = (int)($row['sort_order'] ?? 0);
                if ($sort > $sendSort) {
                    $nextStepId = (int)$row['workflow_step_id'];
                    jcUpdateTrackingStatus($conn, $row, 'in_progress', $userId, 'Screen Printing shortcut: Dispatch stage opened.');
                    break;
                }
            }
            jcRefreshJobCardFromTracking($conn, $jobId, $nextStepId ?: null);
        } else {
            throw new RuntimeException('Invalid shortcut action.');
        }

        $conn->commit();

        if ($shortcutAction === 'readymade_screen_start') {
            return jcSendReadymadeScreenStartWhatsapp($conn, $job);
        }

        return jcSendReadymadeScreenCompleteWhatsapp($conn, $job);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

$roleKey = strtolower((string)($_SESSION['role_key'] ?? ''));
$roleId = (int)($_SESSION['role_id'] ?? 0);

if (empty($_SESSION['job_cards_shortcut_csrf'])) {
    $_SESSION['job_cards_shortcut_csrf'] = bin2hex(random_bytes(32));
}
$shortcutCsrfToken = $_SESSION['job_cards_shortcut_csrf'];

$message = '';
$messageType = 'success';
if (!empty($_GET['shortcut_msg'])) {
    $shortcutWaStatus = strtolower(trim((string)($_GET['wa'] ?? '')));
    if ($_GET['shortcut_msg'] === 'started') {
        $message = $shortcutWaStatus === 'failed'
            ? 'Readymade Screen Print job started, but customer WhatsApp failed. Check WhatsApp logs.'
            : 'Readymade Screen Print job started and customer WhatsApp sent. Printing opened.';
    } elseif ($_GET['shortcut_msg'] === 'completed') {
        $message = $shortcutWaStatus === 'failed'
            ? 'Readymade Screen Print job completed, but customer WhatsApp failed. Check WhatsApp logs.'
            : 'Readymade Screen Print job completed up to Send to Dispatch and customer WhatsApp sent.';
    }

    if ($shortcutWaStatus === 'failed') {
        $messageType = 'danger';
    }
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['readymade_screen_start', 'readymade_screen_complete'], true)) {
    try {
        if (empty($_POST['csrf_token']) || !hash_equals($shortcutCsrfToken, (string)$_POST['csrf_token'])) {
            throw new RuntimeException('Invalid request token. Please refresh and try again.');
        }

        $jobId = (int)($_POST['job_card_id'] ?? 0);
        $action = (string)$_POST['action'];
        $waResult = jcRunReadymadeScreenShortcut($conn, $jobId, $action, $roleKey);

        jcRedirectBack([
            'shortcut_msg' => $action === 'readymade_screen_start' ? 'started' : 'completed',
            'wa' => !empty($waResult['success']) ? 'sent' : 'failed'
        ]);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$filterOrderType = trim((string)($_GET['order_type'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterSearch = trim((string)($_GET['search'] ?? ''));
$filterFromDate = trim((string)($_GET['from_date'] ?? ''));
$filterToDate = trim((string)($_GET['to_date'] ?? ''));

// Server-side pagination. Keep the page compact while preserving all filters.
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$totalRows = 0;
$totalPages = 1;
$offset = 0;

// Only accept YYYY-MM-DD values from the date inputs.
if ($filterFromDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFromDate)) {
    $filterFromDate = '';
}
if ($filterToDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterToDate)) {
    $filterToDate = '';
}

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

if ($filterFromDate !== '') {
    $where[] = "DATE(jc.created_at) >= ?";
    $params[] = $filterFromDate;
    $types .= 's';
}

if ($filterToDate !== '') {
    $where[] = "DATE(jc.created_at) <= ?";
    $params[] = $filterToDate;
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
$readymadeRows = 0;
$customizedRows = 0;
$delayedRows = 0;

if (!jcTableExists($conn, 'job_cards')) {
    $message = 'job_cards table is missing.';
    $messageType = 'danger';
} else {
    try {
        /*
         | Count and summary query uses the SAME role/search/status/date filters
         | as the job-card list. This keeps summary cards correct even when
         | only one pagination page is displayed.
         */
        $summarySql = "
            SELECT
                COUNT(*) AS total_rows,
                COALESCE(SUM(CASE WHEN jc.order_type = 'readymade' THEN 1 ELSE 0 END), 0) AS readymade_rows,
                COALESCE(SUM(CASE WHEN jc.order_type = 'customized' THEN 1 ELSE 0 END), 0) AS customized_rows,
                COALESCE(SUM(CASE
                    WHEN COALESCE(jc.is_delayed, 0) = 1
                         OR EXISTS (
                            SELECT 1
                            FROM job_tracking jt_delay
                            WHERE jt_delay.job_card_id = jc.id
                              AND (jt_delay.status = 'delayed' OR COALESCE(jt_delay.is_delayed, 0) = 1)
                         )
                    THEN 1 ELSE 0
                END), 0) AS delayed_rows
            FROM job_cards jc
            LEFT JOIN printing_types pt
                ON pt.id = jc.printing_type_id
            LEFT JOIN roles rprint
                ON rprint.id = jc.assigned_printing_role_id
            LEFT JOIN job_card_statuses jcs
                ON jcs.id = jc.job_card_status_id
            LEFT JOIN workflow_steps ws
                ON ws.id = jc.current_workflow_step_id
            {$whereSql}
        ";

        $summaryStmt = $conn->prepare($summarySql);
        if ($params) {
            $summaryStmt->bind_param($types, ...$params);
        }
        $summaryStmt->execute();
        $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
        $summaryStmt->close();

        $totalRows = (int)($summary['total_rows'] ?? 0);
        $readymadeRows = (int)($summary['readymade_rows'] ?? 0);
        $customizedRows = (int)($summary['customized_rows'] ?? 0);
        $delayedRows = (int)($summary['delayed_rows'] ?? 0);

        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

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
            LIMIT ? OFFSET ?
        ";

        $stmt = $conn->prepare($sql);
        $listParams = $params;
        $listParams[] = $perPage;
        $listParams[] = $offset;
        $listTypes = $types . 'ii';
        $stmt->bind_param($listTypes, ...$listParams);

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
        $totalRows = 0;
        $readymadeRows = 0;
        $customizedRows = 0;
        $delayedRows = 0;
        $totalPages = 1;
        $page = 1;
        $offset = 0;
    }
}

$showingFrom = $totalRows > 0 ? $offset + 1 : 0;
$showingTo = $totalRows > 0 ? min($offset + count($rows), $totalRows) : 0;

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

    .shortcut-actions {
        display: flex;
        align-items: flex-start;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: nowrap;
    }

    .shortcut-form {
        margin: 0;
    }

    .shortcut-action-box {
        display: inline-flex;
        flex-direction: column;
        align-items: center;
        gap: 3px;
        min-width: 86px;
    }

    .shortcut-btn {
        min-width: 82px;
        min-height: 34px;
        border-radius: 9px;
        font-size: 12px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        box-shadow: none !important;
    }

    .shortcut-btn.start {
        color: #16a34a;
        border-color: #22c55e;
        background: #ffffff;
    }

    .shortcut-btn.start:not(:disabled):hover {
        color: #ffffff;
        background: #16a34a;
        border-color: #16a34a;
    }

    .shortcut-btn.complete {
        color: #1d4ed8;
        border-color: #3b82f6;
        background: #ffffff;
    }

    .shortcut-btn.complete:not(:disabled):hover {
        color: #ffffff;
        background: #2563eb;
        border-color: #2563eb;
    }

    .shortcut-btn:disabled {
        color: #9ca3af !important;
        border-color: #d1d5db !important;
        background: #f8fafc !important;
        cursor: not-allowed;
        opacity: .85;
    }

    .shortcut-note {
        display: block;
        font-size: 10px;
        line-height: 1.1;
        font-weight: 800;
        color: var(--text-muted);
        white-space: nowrap;
    }

    .shortcut-note.green {
        color: #16a34a;
    }

    .shortcut-note.blue {
        color: #2563eb;
    }

    .shortcut-note.done {
        color: #047857;
    }

    .shortcut-help-bar {
        border: 1px solid #bfdbfe;
        background: #eff6ff;
        color: #0f172a;
        border-radius: 14px;
        padding: 10px 14px;
        font-size: 13px;
        font-weight: 800;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .shortcut-help-bar span {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    @media (max-width: 767px) {
        .shortcut-actions {
            justify-content: flex-start;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .shortcut-action-box {
            min-width: 96px;
        }

        .shortcut-btn {
            min-width: 92px;
        }
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
                    <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive"
                        aria-atomic="true" data-bs-delay="5200">
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
                        <div class="col-12 col-md-6 col-lg-2">
                            <label class="form-label fw-bold">Order Type</label>
                            <select name="order_type" class="form-select">
                                <option value="">All</option>
                                <option value="readymade" <?= $filterOrderType === 'readymade' ? 'selected' : '' ?>>
                                    Readymade</option>
                                <option value="customized" <?= $filterOrderType === 'customized' ? 'selected' : '' ?>>
                                    Customized</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-6 col-lg-2">
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

                        <div class="col-12 col-md-6 col-lg-2">
                            <label class="form-label fw-bold">From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?= e($filterFromDate) ?>">
                        </div>

                        <div class="col-12 col-md-6 col-lg-2">
                            <label class="form-label fw-bold">To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?= e($filterToDate) ?>">
                        </div>

                        <div class="col-12 col-lg-3">
                            <label class="form-label fw-bold">Search</label>
                            <input type="search" name="search" class="form-control"
                                placeholder="Job no, customer, mobile, product..." value="<?= e($filterSearch) ?>">
                        </div>

                        <div class="col-12 col-lg-1">
                            <button type="submit" class="btn btn-primary rounded-pill px-3 fw-bold w-100">
                                Filter
                            </button>
                        </div>

                        <?php if ($filterOrderType !== '' || $filterStatus !== '' || $filterSearch !== '' || $filterFromDate !== '' || $filterToDate !== ''): ?>
                        <div class="col-12 text-end">
                            <a href="job_cards.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-bold">
                                Clear Filters
                            </a>
                        </div>
                        <?php endif; ?>
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
                            <small class="text-muted-custom fw-bold d-block mt-1">
                                Showing <?= number_format($showingFrom) ?>-<?= number_format($showingTo) ?> of
                                <?= number_format($totalRows) ?> filtered job card(s)
                            </small>
                        </div>

                        <div style="max-width:340px;width:100%">
                            <input type="search" id="tableSearch" class="form-control"
                                placeholder="Search in this list...">
                        </div>
                    </div>

                    <?php if ($roleKey === 'screen_printing'): ?>
                    <div class="shortcut-help-bar">
                        <span><i data-lucide="info"></i> Start: Complete Enquiry → Master Copy Received and open
                            Printing.</span>
                        <span>Complete: Complete Printing → Send to Dispatch.</span>
                    </div>
                    <?php endif; ?>

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

                                    <?php
                                        $showScreenShortcut = $roleKey === 'screen_printing' && jcIsReadymadeScreenJob($row);
                                        $shortcutState = $showScreenShortcut ? jcReadymadeScreenShortcutState($conn, (int)$row['id']) : [];
                                        $showScreenShortcut = $showScreenShortcut && !empty($shortcutState['eligible']);
                                    ?>
                                    <td class="text-end">
                                        <div class="shortcut-actions">
                                            <?php if ($showScreenShortcut): ?>
                                            <form method="post" class="shortcut-form">
                                                <input type="hidden" name="csrf_token"
                                                    value="<?= e($shortcutCsrfToken) ?>">
                                                <input type="hidden" name="job_card_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="action" value="readymade_screen_start">
                                                <div class="shortcut-action-box">
                                                    <button type="submit" class="btn btn-sm shortcut-btn start"
                                                        <?= !empty($shortcutState['start_enabled']) ? '' : 'disabled' ?>
                                                        onclick="return confirm('Start this readymade screen printing job? This will complete stages up to Master Copy Received and open Printing.');">
                                                        <i data-lucide="play"></i> Start
                                                    </button>
                                                    <small
                                                        class="shortcut-note <?= !empty($shortcutState['started']) ? 'green' : '' ?>">
                                                        <?= e($shortcutState['start_label'] ?? 'Not Started') ?>
                                                    </small>
                                                </div>
                                            </form>

                                            <form method="post" class="shortcut-form">
                                                <input type="hidden" name="csrf_token"
                                                    value="<?= e($shortcutCsrfToken) ?>">
                                                <input type="hidden" name="job_card_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="action" value="readymade_screen_complete">
                                                <div class="shortcut-action-box">
                                                    <button type="submit" class="btn btn-sm shortcut-btn complete"
                                                        <?= !empty($shortcutState['complete_enabled']) ? '' : 'disabled' ?>
                                                        onclick="return confirm('Complete this readymade screen printing job? This will complete stages from Printing to Send to Dispatch.');">
                                                        <i data-lucide="check"></i> Complete
                                                    </button>
                                                    <small
                                                        class="shortcut-note <?= !empty($shortcutState['completed']) ? 'done' : (!empty($shortcutState['complete_enabled']) ? 'blue' : '') ?>">
                                                        <?= e($shortcutState['complete_label'] ?? 'Disabled') ?>
                                                    </small>
                                                </div>
                                            </form>
                                            <?php endif; ?>

                                            <a href="job_card_view.php?id=<?= e($row['id']) ?>"
                                                class="btn btn-sm btn-outline-secondary rounded-circle btn-action-icon"
                                                title="View Job Card" aria-label="View Job Card">
                                                <i data-lucide="eye"></i>
                                            </a>
                                        </div>
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

                            <?php
                                $showScreenShortcut = $roleKey === 'screen_printing' && jcIsReadymadeScreenJob($row);
                                $shortcutState = $showScreenShortcut ? jcReadymadeScreenShortcutState($conn, (int)$row['id']) : [];
                                $showScreenShortcut = $showScreenShortcut && !empty($shortcutState['eligible']);
                            ?>
                            <div class="mobile-card-actions shortcut-actions">
                                <?php if ($showScreenShortcut): ?>
                                <form method="post" class="shortcut-form">
                                    <input type="hidden" name="csrf_token" value="<?= e($shortcutCsrfToken) ?>">
                                    <input type="hidden" name="job_card_id" value="<?= (int)$row['id'] ?>">
                                    <input type="hidden" name="action" value="readymade_screen_start">
                                    <div class="shortcut-action-box">
                                        <button type="submit" class="btn btn-sm shortcut-btn start"
                                            <?= !empty($shortcutState['start_enabled']) ? '' : 'disabled' ?>
                                            onclick="return confirm('Start this readymade screen printing job?');">
                                            <i data-lucide="play"></i> Start
                                        </button>
                                        <small
                                            class="shortcut-note <?= !empty($shortcutState['started']) ? 'green' : '' ?>">
                                            <?= e($shortcutState['start_label'] ?? 'Not Started') ?>
                                        </small>
                                    </div>
                                </form>

                                <form method="post" class="shortcut-form">
                                    <input type="hidden" name="csrf_token" value="<?= e($shortcutCsrfToken) ?>">
                                    <input type="hidden" name="job_card_id" value="<?= (int)$row['id'] ?>">
                                    <input type="hidden" name="action" value="readymade_screen_complete">
                                    <div class="shortcut-action-box">
                                        <button type="submit" class="btn btn-sm shortcut-btn complete"
                                            <?= !empty($shortcutState['complete_enabled']) ? '' : 'disabled' ?>
                                            onclick="return confirm('Complete this readymade screen printing job?');">
                                            <i data-lucide="check"></i> Complete
                                        </button>
                                        <small
                                            class="shortcut-note <?= !empty($shortcutState['completed']) ? 'done' : (!empty($shortcutState['complete_enabled']) ? 'blue' : '') ?>">
                                            <?= e($shortcutState['complete_label'] ?? 'Disabled') ?>
                                        </small>
                                    </div>
                                </form>
                                <?php endif; ?>

                                <a href="job_card_view.php?id=<?= e($row['id']) ?>"
                                    class="btn btn-sm btn-outline-secondary rounded-circle btn-action-icon"
                                    title="View Job Card" aria-label="View Job Card">
                                    <i data-lucide="eye"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <nav class="mt-4 no-print" aria-label="Job Cards pagination">
                        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                            <small class="text-muted-custom fw-bold">
                                Page <?= number_format($page) ?> of <?= number_format($totalPages) ?>
                            </small>

                            <ul class="pagination pagination-sm mb-0 flex-wrap">
                                <?php
                                    $prevQuery = jcCurrentFilterQuery(['page' => max(1, $page - 1)]);
                                    $nextQuery = jcCurrentFilterQuery(['page' => min($totalPages, $page + 1)]);
                                ?>
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="job_cards.php?<?= e($prevQuery) ?>">Previous</a>
                                </li>

                                <?php
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($totalPages, $page + 2);
                                    if ($startPage > 1):
                                ?>
                                <li class="page-item">
                                    <a class="page-link"
                                        href="job_cards.php?<?= e(jcCurrentFilterQuery(['page' => 1])) ?>">1</a>
                                </li>
                                <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link"
                                        href="job_cards.php?<?= e(jcCurrentFilterQuery(['page' => $p])) ?>">
                                        <?= number_format($p) ?>
                                    </a>
                                </li>
                                <?php endfor; ?>

                                <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link"
                                        href="job_cards.php?<?= e(jcCurrentFilterQuery(['page' => $totalPages])) ?>">
                                        <?= number_format($totalPages) ?>
                                    </a>
                                </li>
                                <?php endif; ?>

                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="job_cards.php?<?= e($nextQuery) ?>">Next</a>
                                </li>
                            </ul>
                        </div>
                    </nav>
                    <?php endif; ?>
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