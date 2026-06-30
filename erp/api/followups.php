<?php
/**
 * api/followups.php
 * Action-based API for Follow-ups module.
 * Backend processing moved from followups.php without changing DB schema/business flow.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_permission($conn, 'can_view', 'followups.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['followups_csrf'])) {
    $_SESSION['followups_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['followups_csrf'];
$message = '';
$messageType = 'success';
$toastTitle = 'Info';

function fuTableExists(mysqli $conn, string $table): bool
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

function fuPost(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function fuInt($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function fuRedirect(string $query = ''): void
{
    header('Location: followups.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function fuCsrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['followups_csrf']) ||
        !hash_equals($_SESSION['followups_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function fuDateTimeValue(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);
    if (strlen($value) === 16) {
        $value .= ':00';
    }

    return $value;
}

function fuStatusIdByKey(mysqli $conn, string $statusKey): ?int
{
    $statusKey = trim($statusKey);

    if ($statusKey === '' || !fuTableExists($conn, 'enquiry_statuses')) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT id
            FROM enquiry_statuses
            WHERE status_key = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $statusKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function apiResponse(bool $status, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge([
        'status' => $status,
        'success' => $status,
        'message' => $message
    ], $extra));
    exit;
}

function apiCsrf(): void
{
    if (
        empty($_REQUEST['csrf_token']) ||
        empty($_SESSION['followups_csrf']) ||
        !hash_equals($_SESSION['followups_csrf'], (string)$_REQUEST['csrf_token'])
    ) {
        apiResponse(false, 'Invalid CSRF token.');
    }
}


function apiRequireRolePermission(mysqli $conn, string $permission, string $message): void
{
    if (!permission_allowed($conn, $permission, 'followups.php')) {
        apiResponse(false, $message);
    }
}

function apiFollowupRow(mysqli $conn, int $id): ?array
{
    if ($id <= 0 || !fuTableExists($conn, 'enquiry_followups')) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            ef.*,
            e.enquiry_no,
            e.customer_name,
            e.mobile,
            e.function_date,
            ft.function_name,
            es.status_name AS enquiry_status_name,
            u.username AS created_by_name
        FROM enquiry_followups ef
        INNER JOIN enquiries e ON e.id = ef.enquiry_id
        LEFT JOIN function_types ft ON ft.id = e.function_type_id
        LEFT JOIN enquiry_statuses es ON es.id = e.enquiry_status_id
        LEFT JOIN users u ON u.id = ef.created_by
        WHERE ef.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function apiFollowupList(mysqli $conn): array
{
    if (!fuTableExists($conn, 'enquiry_followups')) {
        return [];
    }

    $rows = [];
    $res = $conn->query("
        SELECT
            ef.*,
            e.enquiry_no,
            e.customer_name,
            e.mobile,
            e.function_date,
            ft.function_name,
            es.status_name AS enquiry_status_name,
            u.username AS created_by_name
        FROM enquiry_followups ef
        INNER JOIN enquiries e ON e.id = ef.enquiry_id
        LEFT JOIN function_types ft ON ft.id = e.function_type_id
        LEFT JOIN enquiry_statuses es ON es.id = e.enquiry_status_id
        LEFT JOIN users u ON u.id = ef.created_by
        ORDER BY ef.followup_at DESC, ef.id DESC
        LIMIT 500
    ");

    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    $res->free();
    return $rows;
}

try {
    $action = (string)($_REQUEST['action'] ?? '');

    if ($action === '') {
        apiResponse(false, 'Action is required.');
    }

    if (in_array($action, ['create', 'update', 'save_record', 'delete', 'delete_record'], true)) {
        apiCsrf();
    }

    if ($action === 'list') {
        apiRequireRolePermission($conn, 'can_view', 'You do not have permission to view follow-ups.');
        apiResponse(true, 'Follow-ups loaded successfully.', ['data' => apiFollowupList($conn)]);
    }

    if ($action === 'view') {
        apiRequireRolePermission($conn, 'can_view', 'You do not have permission to view follow-ups.');
        $id = fuInt($_REQUEST['id'] ?? 0);
        $row = apiFollowupRow($conn, $id);

        if (!$row) {
            apiResponse(false, 'Follow-up not found.');
        }

        apiResponse(true, 'Follow-up loaded successfully.', ['data' => $row]);
    }

    if (in_array($action, ['create', 'update', 'save_record'], true)) {
        if (!fuTableExists($conn, 'enquiry_followups')) {
            throw new RuntimeException('enquiry_followups table is missing. Run the support SQL first.');
        }

        if (!fuTableExists($conn, 'enquiries')) {
            throw new RuntimeException('enquiries table is missing.');
        }

        $id = fuInt($_POST['id'] ?? 0);

        if ($id > 0) {
            apiRequireRolePermission($conn, 'can_edit', 'You do not have permission to edit follow-ups.');
        } else {
            apiRequireRolePermission($conn, 'can_create', 'You do not have permission to create follow-ups.');
        }

        $enquiryId = fuInt($_POST['enquiry_id'] ?? 0);
        $followupAt = fuDateTimeValue(fuPost('followup_at'));
        $callRemarks = fuPost('call_remarks');
        $customerResponse = fuPost('customer_response');
        $nextCallbackAt = fuDateTimeValue(fuPost('next_callback_at'));
        $followupStatus = fuPost('followup_status', 'followup_pending');
        $createdBy = (int)($_SESSION['user_id'] ?? 0);

        if ($enquiryId <= 0) {
            throw new RuntimeException('Please select enquiry.');
        }

        if (!$followupAt) {
            throw new RuntimeException('Follow-up date and time is required.');
        }

        if ($callRemarks === '') {
            throw new RuntimeException('Call remarks is required.');
        }

        $stmt = $conn->prepare("SELECT id FROM enquiries WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $enquiryId);
        $stmt->execute();
        $enquiryExists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$enquiryExists) {
            throw new RuntimeException('Selected enquiry not found.');
        }

        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE enquiry_followups
                SET enquiry_id = ?,
                    followup_at = ?,
                    call_remarks = ?,
                    customer_response = ?,
                    next_callback_at = ?,
                    followup_status = ?
                WHERE id = ?
            ");
            $stmt->bind_param(
                'isssssi',
                $enquiryId,
                $followupAt,
                $callRemarks,
                $customerResponse,
                $nextCallbackAt,
                $followupStatus,
                $id
            );
            $stmt->execute();
            $stmt->close();

            $statusId = fuStatusIdByKey($conn, $followupStatus);
            if ($statusId || $nextCallbackAt) {
                if ($statusId) {
                    $stmt = $conn->prepare("
                        UPDATE enquiries
                        SET enquiry_status_id = ?,
                            next_callback_at = ?,
                            updated_by = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->bind_param('isii', $statusId, $nextCallbackAt, $createdBy, $enquiryId);
                } else {
                    $stmt = $conn->prepare("
                        UPDATE enquiries
                        SET next_callback_at = ?,
                            updated_by = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->bind_param('sii', $nextCallbackAt, $createdBy, $enquiryId);
                }

                $stmt->execute();
                $stmt->close();
            }

            apiResponse(true, 'Follow-up updated successfully.', ['id' => $id]);
        }

        $stmt = $conn->prepare("
            INSERT INTO enquiry_followups
                (
                    enquiry_id,
                    followup_at,
                    call_remarks,
                    customer_response,
                    next_callback_at,
                    followup_status,
                    created_by,
                    created_at
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            'isssssi',
            $enquiryId,
            $followupAt,
            $callRemarks,
            $customerResponse,
            $nextCallbackAt,
            $followupStatus,
            $createdBy
        );
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();

        $statusId = fuStatusIdByKey($conn, $followupStatus);
        if ($statusId) {
            $stmt = $conn->prepare("
                UPDATE enquiries
                SET enquiry_status_id = ?,
                    next_callback_at = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('isii', $statusId, $nextCallbackAt, $createdBy, $enquiryId);
        } else {
            $stmt = $conn->prepare("
                UPDATE enquiries
                SET next_callback_at = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('sii', $nextCallbackAt, $createdBy, $enquiryId);
        }

        $stmt->execute();
        $stmt->close();

        apiResponse(true, 'Follow-up added successfully.', ['id' => $newId]);
    }

    if (in_array($action, ['delete', 'delete_record'], true)) {
        apiRequireRolePermission($conn, 'can_delete', 'You do not have permission to delete follow-ups.');
        $id = fuInt($_POST['id'] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException('Invalid follow-up.');
        }

        $stmt = $conn->prepare("DELETE FROM enquiry_followups WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        apiResponse(true, 'Follow-up deleted successfully.', ['id' => $id]);
    }

    apiResponse(false, 'Invalid action.');
} catch (Throwable $e) {
    apiResponse(false, $e->getMessage());
}
