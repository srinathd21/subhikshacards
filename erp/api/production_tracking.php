<?php
/**
 * api/production_tracking.php
 * Action-based API for Production Tracking module.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_permission($conn, 'can_view', 'production_tracking.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function pt_api_response(bool $status, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge([
        'status' => $status,
        'success' => $status,
        'message' => $message
    ], $extra));
    exit;
}

function pt_table_exists(mysqli $conn, string $table): bool
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

function pt_col(mysqli $conn, string $table, string $col): bool
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

function pt_post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function pt_int($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function pt_bind_type($v): string
{
    return is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
}

function pt_update(mysqli $conn, string $table, array $data, int $id): void
{
    $filtered = [];
    foreach ($data as $key => $value) {
        if (pt_col($conn, $table, $key)) $filtered[$key] = $value;
    }
    if (!$filtered) return;

    $sets = [];
    $types = '';
    $values = [];
    foreach ($filtered as $key => $value) {
        $sets[] = "`{$key}`=?";
        $types .= pt_bind_type($value);
        $values[] = $value;
    }
    $types .= 'i';
    $values[] = $id;

    $stmt = $conn->prepare("UPDATE {$table} SET " . implode(',', $sets) . " WHERE id=?");
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
}

function pt_insert(mysqli $conn, string $table, array $data): int
{
    $filtered = [];
    foreach ($data as $key => $value) {
        if (pt_col($conn, $table, $key)) $filtered[$key] = $value;
    }
    if (!$filtered) return 0;

    $cols = array_keys($filtered);
    $types = '';
    $values = [];
    foreach ($filtered as $value) {
        $types .= pt_bind_type($value);
        $values[] = $value;
    }

    $stmt = $conn->prepare("INSERT INTO {$table} (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();

    return $id;
}

function pt_csrf(): void
{
    if (
        empty($_REQUEST['csrf_token']) ||
        empty($_SESSION['production_tracking_csrf']) ||
        !hash_equals($_SESSION['production_tracking_csrf'], (string)$_REQUEST['csrf_token'])
    ) {
        pt_api_response(false, 'Invalid CSRF token.');
    }
}

function pt_current_role_ids(mysqli $conn): array
{
    $ids = [];

    foreach (['role_id', 'current_role_id'] as $key) {
        if (!empty($_SESSION[$key])) $ids[] = (int)$_SESSION[$key];
    }

    foreach (['role_ids', 'user_role_ids'] as $key) {
        if (!empty($_SESSION[$key]) && is_array($_SESSION[$key])) {
            foreach ($_SESSION[$key] as $id) $ids[] = (int)$id;
        }
    }

    if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        foreach ($_SESSION['roles'] as $role) {
            if (is_array($role) && !empty($role['id'])) $ids[] = (int)$role['id'];
            elseif (is_numeric($role)) $ids[] = (int)$role;
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

function pt_current_role_keys(mysqli $conn): array
{
    $keys = [];

    foreach (['role_key', 'current_role_key'] as $key) {
        if (!empty($_SESSION[$key])) $keys[] = strtolower((string)$_SESSION[$key]);
    }

    if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        foreach ($_SESSION['roles'] as $role) {
            if (is_array($role) && !empty($role['role_key'])) $keys[] = strtolower((string)$role['role_key']);
            elseif (is_string($role)) $keys[] = strtolower($role);
        }
    }

    $roleIds = pt_current_role_ids($conn);
    if ($roleIds && pt_table_exists($conn, 'roles') && pt_col($conn, 'roles', 'role_key')) {
        try {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $types = str_repeat('i', count($roleIds));
            $stmt = $conn->prepare("SELECT role_key FROM roles WHERE id IN ({$placeholders})");
            $stmt->bind_param($types, ...$roleIds);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $keys[] = strtolower((string)$row['role_key']);
            $stmt->close();
        } catch (Throwable $e) {}
    }

    return array_values(array_unique(array_filter($keys)));
}

function pt_is_admin(mysqli $conn): bool
{
    if (function_exists('is_super_admin') && is_super_admin()) return true;
    if (!empty($_SESSION['is_super_admin'])) return true;

    foreach (pt_current_role_keys($conn) as $key) {
        if (in_array($key, ['super_admin', 'admin', 'business_admin'], true)) return true;
    }

    return false;
}

function pt_delay_days($plannedDate): int
{
    if (empty($plannedDate)) return 0;

    try {
        $planned = new DateTime(date('Y-m-d', strtotime((string)$plannedDate)));
        $today = new DateTime(date('Y-m-d'));

        if ($today <= $planned) return 0;

        return (int)$planned->diff($today)->days;
    } catch (Throwable $e) {
        return 0;
    }
}

function pt_next_expected_date($plannedDate): ?string
{
    if (empty($plannedDate)) return null;

    try {
        $dt = new DateTime(date('Y-m-d', strtotime((string)$plannedDate)));
        $dt->modify('+1 day');
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function pt_status_id_by_key(mysqli $conn, string $key): ?int
{
    if (!pt_table_exists($conn, 'job_card_statuses')) return null;

    try {
        if (pt_col($conn, 'job_card_statuses', 'status_key')) {
            $stmt = $conn->prepare("SELECT id FROM job_card_statuses WHERE status_key = ? LIMIT 1");
            $stmt->bind_param('s', $key);
        } else {
            $name = ucwords(str_replace('_', ' ', $key));
            $stmt = $conn->prepare("SELECT id FROM job_card_statuses WHERE LOWER(status_name) = LOWER(?) LIMIT 1");
            $stmt->bind_param('s', $name);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function pt_can_update_tracking(mysqli $conn, array $trackingRow): bool
{
    if (pt_is_admin($conn)) return true;

    $responsibleRoleId = (int)($trackingRow['responsible_role_id'] ?? 0);
    return $responsibleRoleId > 0 && in_array($responsibleRoleId, pt_current_role_ids($conn), true);
}

function pt_tracking_row(mysqli $conn, int $trackingId): ?array
{
    if ($trackingId <= 0 || !pt_table_exists($conn, 'job_tracking')) return null;

    try {
        $stmt = $conn->prepare("
            SELECT jt.*, ws.step_name, ws.step_key
            FROM job_tracking jt
            LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id
            WHERE jt.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $trackingId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function pt_history_insert(mysqli $conn, int $trackingId, int $jobCardId, int $workflowStepId, string $oldStatus, string $newStatus, string $remarks, int $userId): void
{
    if (!pt_table_exists($conn, 'job_tracking_history')) return;

    try {
        pt_insert($conn, 'job_tracking_history', [
            'job_tracking_id' => $trackingId,
            'job_card_id' => $jobCardId,
            'workflow_step_id' => $workflowStepId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'old_data' => json_encode(['status' => $oldStatus], JSON_UNESCAPED_UNICODE),
            'new_data' => json_encode(['status' => $newStatus], JSON_UNESCAPED_UNICODE),
            'action_remarks' => $remarks,
            'changed_by' => $userId ?: null,
            'changed_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Throwable $e) {}
}

function pt_update_job_progress(mysqli $conn, int $jobCardId): array
{
    $rows = [];
    if ($jobCardId <= 0 || !pt_table_exists($conn, 'job_tracking')) {
        return ['progress' => 0];
    }

    try {
        $join = pt_table_exists($conn, 'workflow_steps') ? 'LEFT JOIN workflow_steps ws ON ws.id = jt.workflow_step_id' : '';
        $sort = pt_table_exists($conn, 'workflow_steps') ? 'ORDER BY ws.sort_order ASC, jt.id ASC' : 'ORDER BY jt.id ASC';
        $stmt = $conn->prepare("SELECT jt.* FROM job_tracking jt {$join} WHERE jt.job_card_id = ? {$sort}");
        $stmt->bind_param('i', $jobCardId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
    } catch (Throwable $e) {}

    $total = count($rows);
    $completed = 0;
    $currentStepId = null;

    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? 'pending');
        if ($status === 'completed') $completed++;
        if ($currentStepId === null && $status !== 'completed') {
            $currentStepId = (int)($row['workflow_step_id'] ?? 0);
        }
    }

    $allCompleted = $total > 0 && $completed === $total;
    if ($allCompleted && $rows) {
        $last = end($rows);
        $currentStepId = (int)($last['workflow_step_id'] ?? 0);
    }

    $progress = $total > 0 ? (int)round(($completed / $total) * 100) : 0;
    $jobStatusKey = $allCompleted ? 'completed' : 'in_progress';
    $statusId = pt_status_id_by_key($conn, $jobStatusKey);

    $updates = [];
    if (pt_col($conn, 'job_cards', 'current_workflow_step_id')) $updates['current_workflow_step_id'] = $currentStepId ?: null;
    if ($statusId && pt_col($conn, 'job_cards', 'job_card_status_id')) $updates['job_card_status_id'] = $statusId;
    if (pt_col($conn, 'job_cards', 'is_delayed')) {
        $hasDelay = false;
        foreach ($rows as $row) {
            if ((int)($row['is_delayed'] ?? 0) === 1 || (string)($row['status'] ?? '') === 'delayed') {
                $hasDelay = true;
                break;
            }
        }
        $updates['is_delayed'] = $hasDelay ? 1 : 0;
    }
    if (pt_col($conn, 'job_cards', 'completed_at') && $allCompleted) $updates['completed_at'] = date('Y-m-d H:i:s');
    if (pt_col($conn, 'job_cards', 'updated_at')) $updates['updated_at'] = date('Y-m-d H:i:s');

    if ($updates && pt_table_exists($conn, 'job_cards')) {
        pt_update($conn, 'job_cards', $updates, $jobCardId);
    }

    return ['progress' => $progress, 'total' => $total, 'completed' => $completed, 'current_step_id' => $currentStepId, 'job_status_key' => $jobStatusKey];
}

function pt_update_tracking_status(mysqli $conn, int $trackingId, string $newStatus, string $remarks = '', ?int $delayReasonId = null, string $delayRemarks = ''): array
{
    if ($trackingId <= 0) throw new RuntimeException('Invalid tracking record.');
    if (!in_array($newStatus, ['pending', 'in_progress', 'completed'], true)) throw new RuntimeException('Invalid tracking status.');
    if (!pt_table_exists($conn, 'job_tracking')) throw new RuntimeException('job_tracking table is missing.');

    $tracking = pt_tracking_row($conn, $trackingId);
    if (!$tracking) throw new RuntimeException('Tracking step not found.');

    if (!pt_can_update_tracking($conn, $tracking)) {
        throw new RuntimeException('You do not have role access to update this production stage.');
    }

    $jobCardId = (int)($tracking['job_card_id'] ?? 0);
    $oldStatus = (string)($tracking['status'] ?? 'pending');
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $now = date('Y-m-d H:i:s');
    $delayDays = pt_delay_days($tracking['planned_completion_date'] ?? null);
    $isDelayed = $delayDays > 0 && in_array($newStatus, ['in_progress', 'completed'], true);

    if ($isDelayed && (!$delayReasonId || trim($delayRemarks) === '')) {
        throw new RuntimeException('Delay reason and delay remarks are required before updating this delayed production stage.');
    }

    $data = ['status' => $newStatus];

    if ($newStatus === 'pending') {
        if (pt_col($conn, 'job_tracking', 'actual_start_at')) $data['actual_start_at'] = null;
        if (pt_col($conn, 'job_tracking', 'actual_completed_at')) $data['actual_completed_at'] = null;
        if (pt_col($conn, 'job_tracking', 'completed_by')) $data['completed_by'] = null;
    } elseif ($newStatus === 'in_progress') {
        if (pt_col($conn, 'job_tracking', 'actual_start_at') && empty($tracking['actual_start_at'])) $data['actual_start_at'] = $now;
        if (pt_col($conn, 'job_tracking', 'actual_completed_at')) $data['actual_completed_at'] = null;
        if (pt_col($conn, 'job_tracking', 'completed_by')) $data['completed_by'] = null;
    } elseif ($newStatus === 'completed') {
        if (pt_col($conn, 'job_tracking', 'actual_start_at') && empty($tracking['actual_start_at'])) $data['actual_start_at'] = $now;
        if (pt_col($conn, 'job_tracking', 'actual_completed_at')) $data['actual_completed_at'] = $now;
        if (pt_col($conn, 'job_tracking', 'completed_by')) $data['completed_by'] = $userId ?: null;
    }

    if ($isDelayed) {
        if (pt_col($conn, 'job_tracking', 'is_delayed')) $data['is_delayed'] = 1;
        if (pt_col($conn, 'job_tracking', 'delay_started_at') && empty($tracking['delay_started_at'])) $data['delay_started_at'] = $now;
        if (pt_col($conn, 'job_tracking', 'delay_days')) $data['delay_days'] = $delayDays;
        if (pt_col($conn, 'job_tracking', 'revised_completion_date') && empty($tracking['revised_completion_date'])) $data['revised_completion_date'] = pt_next_expected_date($tracking['planned_completion_date'] ?? null);
        if (pt_col($conn, 'job_tracking', 'delay_reason_id')) $data['delay_reason_id'] = $delayReasonId;
        if (pt_col($conn, 'job_tracking', 'delay_remarks')) $data['delay_remarks'] = $delayRemarks;
    }

    if (pt_col($conn, 'job_tracking', 'updated_at')) $data['updated_at'] = $now;

    $conn->begin_transaction();

    pt_update($conn, 'job_tracking', $data, $trackingId);

    pt_history_insert(
        $conn,
        $trackingId,
        $jobCardId,
        (int)($tracking['workflow_step_id'] ?? 0),
        $oldStatus,
        $newStatus,
        ($remarks !== '' ? $remarks : 'Production tracking status updated.') . ($delayRemarks !== '' ? ' Delay: ' . $delayRemarks : ''),
        $userId
    );

    $progress = pt_update_job_progress($conn, $jobCardId);

    $conn->commit();

    return [
        'tracking_id' => $trackingId,
        'job_card_id' => $jobCardId,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'progress' => $progress
    ];
}

try {
    $action = (string)($_REQUEST['action'] ?? '');

    if ($action === '') {
        pt_api_response(false, 'Action is required.');
    }

    if (in_array($action, ['update_tracking_status'], true)) {
        pt_csrf();
    }

    if ($action === 'update_tracking_status') {
        $trackingId = pt_int($_POST['tracking_id'] ?? 0);
        $newStatus = pt_post('status');
        $delayReasonId = pt_int($_POST['delay_reason_id'] ?? 0) ?: null;
        $delayRemarks = pt_post('delay_remarks');
        $remarks = pt_post('remarks');

        $result = pt_update_tracking_status($conn, $trackingId, $newStatus, $remarks, $delayReasonId, $delayRemarks);

        pt_api_response(true, 'Production tracking status updated successfully.', $result);
    }

    pt_api_response(false, 'Invalid action.');
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
    }
    pt_api_response(false, $e->getMessage());
}