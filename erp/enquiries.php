<?php

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'enquiries.php');
// Backend create/update/delete/WhatsApp processing moved to api/enquiries.php
// Toast rule: show toast only for important save/update/delete/API result messages.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['enquiries_csrf'])) {
    $_SESSION['enquiries_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['enquiries_csrf'];
$currentPage = 'enquiries.php';
$canCreate = can_create($conn, $currentPage);
$canEdit = can_edit($conn, $currentPage);
$canDelete = can_delete($conn, $currentPage);
$canUpdate = can_update($conn, $currentPage);
$canSendWhatsapp = can_send_whatsapp($conn, $currentPage);

$message = '';
$messageType = 'success';
$toastTitle = 'Info';

function enqTableExists(mysqli $conn, string $table): bool
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

function enqPost(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function enqInt($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function enqRedirect(string $query = ''): void
{
    header('Location: enquiries.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function enqCsrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['enquiries_csrf']) ||
        !hash_equals($_SESSION['enquiries_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function enqNextNo(mysqli $conn): string
{
    $prefix = 'SC-ENQ';
    $datePart = date('ymd');
    $like = $prefix . '-' . $datePart . '-%';

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM enquiries WHERE enquiry_no LIKE ?");
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $next = ((int)($row['total'] ?? 0)) + 1;
        return $prefix . '-' . $datePart . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return $prefix . '-' . $datePart . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}

function enqGetDefaultStatusId(mysqli $conn): ?int
{
    try {
        $stmt = $conn->prepare("
            SELECT id
            FROM enquiry_statuses
            WHERE status_key = 'new'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function enqGetClosedStatusId(mysqli $conn): ?int
{
    try {
        $stmt = $conn->prepare("
            SELECT id
            FROM enquiry_statuses
            WHERE status_key IN ('cancelled', 'closed')
            ORDER BY FIELD(status_key, 'cancelled', 'closed'), id ASC
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}


function enqFunctionTypeId(mysqli $conn, string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    if (ctype_digit($value)) {
        return (int)$value;
    }

    if (!enqTableExists($conn, 'function_types')) {
        throw new RuntimeException('function_types table is missing.');
    }

    $functionName = mb_substr($value, 0, 150);
    $baseKey = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $functionName), '_'));
    if ($baseKey === '') {
        $baseKey = 'custom_type';
    }

    try {
        $stmt = $conn->prepare("
            SELECT id
            FROM function_types
            WHERE LOWER(function_name) = LOWER(?)
            LIMIT 1
        ");
        $stmt->bind_param('s', $functionName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            return (int)$row['id'];
        }

        $functionKey = $baseKey;
        $i = 1;

        while (true) {
            $stmt = $conn->prepare("SELECT id FROM function_types WHERE function_key = ? LIMIT 1");
            $stmt->bind_param('s', $functionKey);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$exists) {
                break;
            }

            $i++;
            $functionKey = $baseKey . '_' . $i;
        }

        $fieldGroup = 'other';
        $sortOrder = 999;

        $stmt = $conn->prepare("
            INSERT INTO function_types
                (function_name, function_key, field_group, is_active, sort_order, created_at)
            VALUES
                (?, ?, ?, 1, ?, NOW())
        ");
        $stmt->bind_param('sssi', $functionName, $functionKey, $fieldGroup, $sortOrder);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();

        return $newId;
    } catch (Throwable $e) {
        throw new RuntimeException('Unable to create new function type: ' . $e->getMessage());
    }
}


function enqCustomerId(mysqli $conn, string $customerName, string $mobile, string $address = ''): ?int
{
    $customerName = trim($customerName);
    $mobile = trim($mobile);
    $address = trim($address);

    if ($customerName === '' || $mobile === '' || !enqTableExists($conn, 'customers')) {
        return null;
    }

    try {
        $stmt = $conn->prepare("SELECT id FROM customers WHERE mobile = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('s', $mobile);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $customerId = (int)$row['id'];
            $userId = (int)($_SESSION['user_id'] ?? 0);

            $stmt = $conn->prepare("
                UPDATE customers
                SET customer_name = ?,
                    address = IF(? = '', address, ?),
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('sssii', $customerName, $address, $address, $userId, $customerId);
            $stmt->execute();
            $stmt->close();

            return $customerId;
        }

        $createdBy = (int)($_SESSION['user_id'] ?? 0);

        $stmt = $conn->prepare("
            INSERT INTO customers
                (customer_name, mobile, address, is_active, created_by, created_at)
            VALUES
                (?, ?, ?, 1, ?, NOW())
        ");
        $stmt->bind_param('sssi', $customerName, $mobile, $address, $createdBy);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        return $id;
    } catch (Throwable $e) {
        return null;
    }
}

function enqLog(mysqli $conn, string $actionKey, int $recordId, string $description): void
{
    try {
        if (!enqTableExists($conn, 'activity_logs')) {
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $actionTypeId = null;
        if (enqTableExists($conn, 'activity_action_types')) {
            $stmt = $conn->prepare("SELECT id FROM activity_action_types WHERE action_key = ? LIMIT 1");
            $stmt->bind_param('s', $actionKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $actionTypeId = (int)$row['id'];
            }
        }

        $moduleName = 'Enquiries';
        $tableName = 'enquiries';

        $stmt = $conn->prepare("
            INSERT INTO activity_logs
                (user_id, role_id, action_type_id, action_key, module_name, table_name, record_id, description, ip_address, user_agent, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            'iiisssisss',
            $userId,
            $roleId,
            $actionTypeId,
            $actionKey,
            $moduleName,
            $tableName,
            $recordId,
            $description,
            $ip,
            $ua
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Ignore log failure; page action should not fail because of log.
    }
}


function enqSettingValue(mysqli $conn, string $key, string $default = ''): string
{
    try {
        if (!enqTableExists($conn, 'system_settings')) {
            return $default;
        }

        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? trim((string)$row['setting_value']) : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function enqWhatsappApiReady(mysqli $conn): bool
{
    $enabled = enqSettingValue($conn, 'whatsapp_enabled', '0');
    $apiUrl = enqSettingValue($conn, 'watzup_api_url', '');
    $apiToken = enqSettingValue($conn, 'watzup_api_token', '');
    $senderId = enqSettingValue($conn, 'watzup_sender_id', '');

    if ($enabled !== '1') {
        return false;
    }

    $dummyValues = [
        '',
        'https://your-whatsapp-provider-url/send-message',
        'PASTE_YOUR_SECRET_KEY_HERE',
        'PASTE_YOUR_UNIQUE_ID_HERE',
        'YOUR_REAL_API_URL',
        'YOUR_REAL_SECRET_KEY',
        'YOUR_REAL_UNIQUE_ID_OR_ACCOUNT_ID'
    ];

    if (in_array($apiUrl, $dummyValues, true)) {
        return false;
    }

    if (in_array($apiToken, $dummyValues, true)) {
        return false;
    }

    if (in_array($senderId, $dummyValues, true)) {
        return false;
    }

    return filter_var($apiUrl, FILTER_VALIDATE_URL) !== false;
}

function enqWhatsappMobile($mobile): string
{
    $mobile = preg_replace('/\D+/', '', (string)$mobile);

    if ($mobile === '') {
        return '';
    }

    if (strlen($mobile) === 10) {
        return '91' . $mobile;
    }

    return $mobile;
}

function enqWhatsappMessage(array $row): string
{
    $customerName = trim((string)($row['customer_name'] ?? 'Customer'));
    $enquiryNo = trim((string)($row['enquiry_no'] ?? '-'));
    $functionType = trim((string)($row['function_name'] ?? '-'));
    $functionDate = !empty($row['function_date']) ? date('d-m-Y', strtotime($row['function_date'])) : '-';

    return "Hi {$customerName},\n\n"
        . "Thank you for contacting Subhiksha Cards.\n"
        . "Your enquiry {$enquiryNo} has been received successfully.\n\n"
        . "Requirement: {$functionType}\n"
        . "Function Date: {$functionDate}\n\n"
        . "Our team will follow up shortly.\n\n"
        . "- Subhiksha Cards";
}

function enqWhatsappUrl(array $row): string
{
    $mobile = enqWhatsappMobile($row['mobile'] ?? '');

    if ($mobile === '') {
        return '#';
    }

    return 'https://wa.me/' . $mobile . '?text=' . rawurlencode(enqWhatsappMessage($row));
}

function enqGetByIdForWhatsapp(mysqli $conn, int $id): ?array
{
    if ($id <= 0 || !enqTableExists($conn, 'enquiries')) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                e.*,
                ft.function_name,
                es.status_name,
                es.status_key
            FROM enquiries e
            LEFT JOIN function_types ft ON ft.id = e.function_type_id
            LEFT JOIN enquiry_statuses es ON es.id = e.enquiry_status_id
            WHERE e.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}


function enqWhatsappTemplateId(mysqli $conn, string $templateKey): ?int
{
    try {
        if (!enqTableExists($conn, 'whatsapp_templates')) {
            return null;
        }

        $stmt = $conn->prepare("SELECT id FROM whatsapp_templates WHERE template_key = ? LIMIT 1");
        $stmt->bind_param('s', $templateKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function enqWhatsappLogManual(mysqli $conn, int $id): array
{
    $enquiry = enqGetByIdForWhatsapp($conn, $id);

    if (!$enquiry) {
        return [
            'success' => false,
            'message' => 'Enquiry not found.'
        ];
    }

    $mobile = enqWhatsappMobile($enquiry['mobile'] ?? '');

    if ($mobile === '') {
        return [
            'success' => false,
            'message' => 'Customer mobile number is missing.'
        ];
    }

    if (!enqTableExists($conn, 'whatsapp_logs')) {
        return [
            'success' => true,
            'message' => 'Manual WhatsApp opened. whatsapp_logs table missing, so log not saved.'
        ];
    }

    try {
        $templateId = enqWhatsappTemplateId($conn, 'enquiry_completed');
        $relatedModule = 'Enquiries';
        $relatedId = $id;
        $customerId = !empty($enquiry['customer_id']) ? (int)$enquiry['customer_id'] : null;
        $jobCardId = null;
        $messageBody = enqWhatsappMessage($enquiry);
        $status = 'sent';
        $providerResponse = json_encode([
            'mode' => 'manual',
            'status' => 'opened',
            'message' => 'Manual WhatsApp Web/App opened by user.'
        ]);
        $sentBy = (int)($_SESSION['user_id'] ?? 0);
        $sentAt = date('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            INSERT INTO whatsapp_logs
                (
                    template_id,
                    related_module,
                    related_id,
                    customer_id,
                    job_card_id,
                    mobile,
                    message_body,
                    status,
                    provider_response,
                    sent_by,
                    sent_at,
                    created_at
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->bind_param(
            'isiiissssis',
            $templateId,
            $relatedModule,
            $relatedId,
            $customerId,
            $jobCardId,
            $mobile,
            $messageBody,
            $status,
            $providerResponse,
            $sentBy,
            $sentAt
        );
        $stmt->execute();
        $logId = (int)$stmt->insert_id;
        $stmt->close();

        enqLog($conn, 'send_whatsapp_manual', $id, 'Manual WhatsApp message opened.');

        return [
            'success' => true,
            'message' => 'Manual WhatsApp logged.',
            'log_id' => $logId
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}


function enqSendWhatsappByApi(mysqli $conn, int $id): array
{
    $apiFile = __DIR__ . '/includes/whatsapp-api.php';

    if (!file_exists($apiFile)) {
        return [
            'success' => false,
            'message' => 'WhatsApp API file missing.'
        ];
    }

    require_once $apiFile;

    if (!function_exists('subhiksha_send_whatsapp')) {
        return [
            'success' => false,
            'message' => 'WhatsApp API function missing.'
        ];
    }

    $enquiry = enqGetByIdForWhatsapp($conn, $id);

    if (!$enquiry) {
        return [
            'success' => false,
            'message' => 'Enquiry not found.'
        ];
    }

    return subhiksha_send_whatsapp($conn, [
        'mobile' => (string)($enquiry['mobile'] ?? ''),
        'template_key' => 'enquiry_completed',
        'variables' => [
            'customer_name' => (string)($enquiry['customer_name'] ?? 'Customer'),
            'enquiry_no' => (string)($enquiry['enquiry_no'] ?? '-'),
            'function_type' => (string)($enquiry['function_name'] ?? '-'),
            'order_type' => '-'
        ],
        'related_module' => 'Enquiries',
        'related_id' => $id,
        'customer_id' => $enquiry['customer_id'] ?? null
    ]);
}

function enqWhatsappSvg(): string
{
    return '<svg viewBox="0 0 32 32" width="17" height="17" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16.04 3C8.85 3 3 8.73 3 15.78c0 2.26.61 4.47 1.77 6.41L3 29l7.02-1.8a13.3 13.3 0 0 0 6.02 1.43C23.23 28.63 29 22.9 29 15.85S23.23 3 16.04 3Zm0 23.45c-1.9 0-3.76-.5-5.39-1.45l-.39-.23-4.16 1.07 1.11-4.01-.26-.41a11.05 11.05 0 0 1-1.73-5.64c0-5.84 4.85-10.6 10.82-10.6 5.96 0 10.81 4.76 10.81 10.67 0 5.84-4.85 10.6-10.81 10.6Zm5.93-7.95c-.32-.16-1.9-.92-2.2-1.03-.3-.11-.52-.16-.74.16-.22.32-.85 1.03-1.04 1.24-.19.22-.38.24-.7.08-.32-.16-1.36-.49-2.59-1.55-.96-.84-1.61-1.88-1.8-2.2-.19-.32-.02-.49.14-.65.14-.14.32-.38.49-.57.16-.19.22-.32.32-.54.11-.22.05-.41-.03-.57-.08-.16-.74-1.76-1.01-2.41-.27-.65-.54-.54-.74-.55h-.63c-.22 0-.57.08-.87.41-.3.32-1.14 1.09-1.14 2.68s1.17 3.12 1.33 3.34c.16.22 2.3 3.46 5.58 4.85.78.33 1.39.53 1.86.68.78.24 1.49.21 2.05.13.63-.09 1.9-.76 2.17-1.49.27-.73.27-1.36.19-1.49-.08-.13-.3-.21-.62-.37Z"/></svg>';
}

function enqWhatsappPreviewButton(array $row): string
{
    return '
        <button type="button"
            class="btn btn-sm btn-whatsapp-icon btn-action-icon rounded-circle js-whatsapp-preview"
            title="Preview WhatsApp message"
            data-id="' . e($row['id'] ?? '') . '"
            data-customer-name="' . e($row['customer_name'] ?? '') . '"
            data-mobile="' . e($row['mobile'] ?? '') . '"
            data-wa-url="' . e(enqWhatsappUrl($row)) . '"
            data-message="' . e(enqWhatsappMessage($row)) . '">
            ' . enqWhatsappSvg() . '
        </button>
    ';
}


/* Backend processing moved to api/enquiries.php */

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') {
    $message = 'Enquiry created successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'created_whatsapp_sent') {
    $message = 'Enquiry created and WhatsApp message sent successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'created_whatsapp_manual') {
    $message = 'Enquiry created successfully. Redirecting to WhatsApp for manual sending.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'created_whatsapp_failed') {
    $message = 'Enquiry created successfully. WhatsApp API sending failed.';
    $messageType = 'warning';
    $toastTitle = 'Warning';
} elseif ($msg === 'updated') {
    $message = 'Enquiry updated successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'closed') {
    $message = 'Enquiry closed successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'whatsapp_sent') {
    $message = 'WhatsApp message sent successfully using API.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'whatsapp_failed') {
    $message = 'WhatsApp message sending failed.';
    $messageType = 'danger';
    $toastTitle = 'Failed';
} elseif ($msg === 'whatsapp_manual') {
    $message = 'WhatsApp API is not ready. Manual WhatsApp mode is active.';
    $messageType = 'warning';
    $toastTitle = 'Warning';
}

if (isset($_GET['err']) && trim((string)$_GET['err']) !== '') {
    $errText = trim((string)$_GET['err']);
    $message .= ($message !== '' ? ' ' : '') . 'Error: ' . $errText;
}

$functionTypes = [];
try {
    if (enqTableExists($conn, 'function_types')) {
        $res = $conn->query("
            SELECT id, function_name
            FROM function_types
            WHERE is_active = 1
            ORDER BY sort_order ASC, function_name ASC
        ");
        while ($row = $res->fetch_assoc()) {
            $functionTypes[] = $row;
        }
        $res->free();
    }
} catch (Throwable $e) {
}

$statuses = [];
try {
    if (enqTableExists($conn, 'enquiry_statuses')) {
        $res = $conn->query("
            SELECT id, status_name, status_key, color_code
            FROM enquiry_statuses
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        while ($row = $res->fetch_assoc()) {
            $statuses[] = $row;
        }
        $res->free();
    }
} catch (Throwable $e) {
}

$salesUsers = [];
try {
    if (enqTableExists($conn, 'users')) {
        $res = $conn->query("
            SELECT id, username AS display_name
            FROM users
            WHERE is_active = 1
            ORDER BY display_name ASC
        ");
        while ($row = $res->fetch_assoc()) {
            $salesUsers[] = $row;
        }
        $res->free();
    }
} catch (Throwable $e) {
}

$rows = [];
if (enqTableExists($conn, 'enquiries')) {
    try {
        $res = $conn->query("
            SELECT
                e.*,
                ft.function_name,
                es.status_name,
                es.status_key,
                es.color_code,
                u.username AS sales_person
            FROM enquiries e
            LEFT JOIN function_types ft ON ft.id = e.function_type_id
            LEFT JOIN enquiry_statuses es ON es.id = e.enquiry_status_id
            LEFT JOIN users u ON u.id = e.assigned_sales_user_id
            ORDER BY e.id DESC
            LIMIT 300
        ");

        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }

        $res->free();
    } catch (Throwable $e) {
        $message = 'List query error: ' . $e->getMessage();
        $messageType = 'danger';
        $toastTitle = 'Failed';
        $rows = [];
    }
}

$totalRows = count($rows);
$pendingRows = 0;
$convertedRows = 0;

foreach ($rows as $row) {
    $statusKey = strtolower((string)($row['status_key'] ?? ''));

    if (in_array($statusKey, ['new', 'follow_up_pending', 'callback_scheduled', 'interested'], true)) {
        $pendingRows++;
    }

    if ((int)($row['converted_to_quotation'] ?? 0) === 1 || (int)($row['converted_to_order'] ?? 0) === 1) {
        $convertedRows++;
    }
}

function enqDate($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime($value)) : '-';
}

function enqDateTime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime($value)) : '-';
}

$defaultStatusId = $statuses[0]['id'] ?? '';
$whatsappApiReady = enqWhatsappApiReady($conn);

$autoOpenWhatsappUrl = '';
$autoOpenWhatsappId = enqInt($_GET['open_whatsapp'] ?? 0);
if ($autoOpenWhatsappId > 0) {
    $autoOpenEnquiry = enqGetByIdForWhatsapp($conn, $autoOpenWhatsappId);
    if ($autoOpenEnquiry) {
        $autoOpenWhatsappUrl = enqWhatsappUrl($autoOpenEnquiry);
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Enquiries - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
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

    .toast-ui.warning {
        background: #fef3c7;
        color: #78350f;
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

    .status-pill {
        font-size: 11px;
        font-weight: 900;
        border-radius: 999px;
        padding: 5px 9px;
        background: color-mix(in srgb, var(--info-color) 14%, transparent);
        color: var(--info-color);
        display: inline-flex;
    }

    .status-pill.closed {
        color: var(--danger-color);
        background: color-mix(in srgb, var(--danger-color) 14%, transparent);
    }

    .form-control,
    .form-select {
        border-radius: 14px;
        min-height: 46px;
    }

    .modal-content {
        border: 0;
        border-radius: 22px;
        background: var(--card-bg);
        color: var(--text-main);
    }

    .modal-header,
    .modal-footer {
        border-color: var(--border-soft);
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
        margin-top: 12px;
    }



    .btn-whatsapp-icon {
        width: 36px;
        height: 36px;
        padding: 0;
        color: #fff !important;
        background: #22c55e;
        border-color: #22c55e;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .btn-whatsapp-icon:hover {
        color: #fff !important;
        background: #16a34a;
        border-color: #16a34a;
    }


    #whatsappPreviewModal .modal-dialog {
        max-width: 760px;
    }

    #whatsappPreviewModal .modal-body {
        padding: 22px 28px;
    }

    #whatsappPreviewModal .whatsapp-preview-box {
        min-height: 330px;
        max-height: 430px;
        white-space: pre-wrap;
        resize: vertical;
        font-weight: 700;
        line-height: 1.6;
        padding: 18px;
        font-size: 15px;
    }

    @media(max-width:767.98px) {
        #whatsappPreviewModal .modal-dialog {
            max-width: calc(100% - 24px);
            margin: 12px auto;
        }

        #whatsappPreviewModal .whatsapp-preview-box {
            min-height: 300px;
            max-height: 420px;
        }
    }


    .whatsapp-preview-box {
        min-height: 190px;
        white-space: pre-wrap;
        resize: vertical;
        font-weight: 700;
        line-height: 1.55;
    }

    .select2-container--bootstrap-5 .select2-selection {
        min-height: 46px;
        border-radius: 14px;
        border-color: var(--border-soft);
        display: flex;
        align-items: center;
    }

    .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
        font-weight: 700;
        color: var(--text-main);
        padding-left: 10px;
    }

    .select2-container--bootstrap-5 .select2-dropdown {
        border-radius: 14px;
        border-color: var(--border-soft);
        overflow: hidden;
        z-index: 9999;
    }

    .select2-container--bootstrap-5 .select2-search__field {
        border-radius: 10px !important;
        min-height: 38px;
    }

    .modal .select2-container {
        width: 100% !important;
    }

    @media(max-width:767.98px) {
        .module-page .page-head {
            padding: 18px;
            border-radius: 18px;
        }

        .module-page .page-head h1 {
            font-size: 24px;
        }

        .module-page .page-head .btn {
            width: 100%;
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

        .mobile-card-actions .btn,
        .mobile-card-actions form {
            flex: 1 1 auto;
        }

        .mobile-card-actions .btn {
            width: 100%;
        }
    }

    /* Mobile enquiry card UI fix */
    @media(max-width:767.98px) {
        .mobile-card {
            padding: 16px 16px 14px !important;
            border-radius: 20px !important;
        }

        .mobile-card > .d-flex.justify-content-between {
            align-items: flex-start !important;
            gap: 12px !important;
        }

        .mobile-card .status-pill {
            align-self: flex-start !important;
            flex: 0 0 auto !important;
            min-width: auto !important;
            height: auto !important;
            min-height: 0 !important;
            line-height: 1.2 !important;
            padding: 6px 10px !important;
            border-radius: 999px !important;
            white-space: nowrap !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 10px !important;
            max-width: 120px !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }

        .mobile-card-title {
            font-size: 16px !important;
            line-height: 1.25 !important;
            margin-bottom: 6px !important;
        }

        .mobile-card-subtitle {
            font-size: 12px !important;
            line-height: 1.45 !important;
            margin-top: 3px !important;
        }

        .mobile-card-actions {
            margin-top: 14px !important;
            gap: 8px !important;
        }

        .mobile-card-actions .btn {
            min-height: 38px !important;
            border-radius: 999px !important;
            font-size: 13px !important;
            font-weight: 900 !important;
        }

        .mobile-card-actions .btn-whatsapp-icon {
            width: 42px !important;
            height: 42px !important;
            min-width: 42px !important;
            max-width: 42px !important;
            flex: 0 0 42px !important;
            padding: 0 !important;
            border-radius: 50% !important;
            margin: 0 auto !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .mobile-card-actions .btn-whatsapp-icon svg {
            width: 18px !important;
            height: 18px !important;
        }

        .module-card .form-control#tableSearch {
            min-height: 46px !important;
            border-radius: 16px !important;
        }
    }


    /* Action icon buttons - safe common UI */
    .btn-action-icon,
    .btn-delete-icon {
        width: 36px !important;
        height: 36px !important;
        min-width: 36px !important;
        max-width: 36px !important;
        padding: 0 !important;
        border-radius: 50% !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        line-height: 1 !important;
    }

    .btn-action-icon svg,
    .btn-delete-icon svg {
        width: 16px !important;
        height: 16px !important;
        stroke-width: 2.5 !important;
        flex: 0 0 auto !important;
    }

    .btn-action-icon.btn-whatsapp-icon {
        background: #22c55e !important;
        border-color: #22c55e !important;
        color: #fff !important;
    }

    .btn-action-icon.btn-whatsapp-icon:hover {
        background: #16a34a !important;
        border-color: #16a34a !important;
        color: #fff !important;
    }

    @media(max-width:767.98px) {
        .mobile-card-actions .btn-action-icon,
        .mobile-card-actions .btn-delete-icon,
        .proforma-mobile-card .proforma-mobile-actions .btn-action-icon,
        .proforma-mobile-card .proforma-mobile-actions .btn-delete-icon {
            width: 42px !important;
            height: 42px !important;
            min-width: 42px !important;
            max-width: 42px !important;
            border-radius: 50% !important;
            justify-self: center !important;
            margin: 0 auto !important;
        }

        .mobile-card-actions .btn-action-icon svg,
        .mobile-card-actions .btn-delete-icon svg,
        .proforma-mobile-card .proforma-mobile-actions .btn-action-icon svg,
        .proforma-mobile-card .proforma-mobile-actions .btn-delete-icon svg {
            width: 18px !important;
            height: 18px !important;
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
                            <h1 class="mb-1">Enquiries</h1>
                            <p class="text-muted-custom mb-0">Register customer enquiry and callback details.</p>
                        </div>

                        <?php if ($canCreate): ?>
                        <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" id="newRecordBtn"
                            data-bs-toggle="modal" data-bs-target="#recordModal">
                            Create New
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 12000">
                    <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive"
                        aria-atomic="true" data-bs-delay="4200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title"><?= e($toastTitle) ?></div>
                                <div class="toast-message"><?= e($message) ?></div>
                            </div>
                            <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"
                                aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)">
                                <i data-lucide="phone"></i>
                            </div>
                            <div>
                                <span>Total Enquiries</span>
                                <strong><?= number_format($totalRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                <i data-lucide="clock"></i>
                            </div>
                            <div>
                                <span>Pending Follow-up</span>
                                <strong><?= number_format($pendingRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                <i data-lucide="check-circle-2"></i>
                            </div>
                            <div>
                                <span>Converted</span>
                                <strong><?= number_format($convertedRows) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Enquiries List</h2>
                            <p class="text-muted-custom mb-0">Correct flow: enquiry → follow-up → quotation.</p>
                        </div>

                        <div style="max-width:340px;width:100%">
                            <input type="search" id="tableSearch" class="form-control" placeholder="Search...">
                        </div>
                    </div>

                    <div class="table-responsive desktop-table">
                        <table class="table-ui" id="dataTable">
                            <thead>
                                <tr>
                                    <th>Enquiry No</th>
                                    <th>Customer</th>
                                    <th>Function Type</th>
                                    <th>Function Date</th>
                                    <th>Next Callback</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted-custom py-4">
                                        No enquiries found.
                                    </td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($rows as $row): ?>
                                <?php $closed = in_array(strtolower((string)($row['status_key'] ?? '')), ['cancelled', 'closed'], true); ?>
                                <tr>
                                    <td><strong><?= e($row['enquiry_no']) ?></strong></td>
                                    <td>
                                        <?= e($row['customer_name']) ?>
                                        <small class="d-block text-muted-custom"><?= e($row['mobile']) ?></small>
                                    </td>
                                    <td><?= e($row['function_name'] ?? '-') ?></td>
                                    <td><?= e(enqDate($row['function_date'] ?? null)) ?></td>
                                    <td><?= e(enqDateTime($row['next_callback_at'] ?? null)) ?></td>
                                    <td>
                                        <span class="status-pill <?= $closed ? 'closed' : '' ?>">
                                            <?= e($row['status_name'] ?? 'New') ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <button title="View" aria-label="View" type="button"
                                            class="btn btn-sm btn-outline-secondary rounded-circle fw-bold js-view-record btn-action-icon"
                                            data-bs-toggle="modal" data-bs-target="#viewModal"
                                            data-enquiry-no="<?= e($row['enquiry_no']) ?>"
                                            data-customer-name="<?= e($row['customer_name']) ?>"
                                            data-mobile="<?= e($row['mobile']) ?>"
                                            data-function-name="<?= e($row['function_name'] ?? '-') ?>"
                                            data-function-date="<?= e(enqDate($row['function_date'] ?? null)) ?>"
                                            data-next-callback="<?= e(enqDateTime($row['next_callback_at'] ?? null)) ?>"
                                            data-venue="<?= e($row['venue']) ?>"
                                            data-address="<?= e($row['address']) ?>"
                                            data-enquiry-source="<?= e($row['enquiry_source']) ?>"
                                            data-status-name="<?= e($row['status_name'] ?? 'New') ?>"
                                            data-sales-person="<?= e($row['sales_person'] ?? '-') ?>"
                                            data-remarks="<?= e($row['remarks']) ?>"><i data-lucide="eye"></i></button>

                                        <?php if ($canEdit): ?>
                                        <button title="Edit" aria-label="Edit" type="button"
                                            class="btn btn-sm btn-outline-primary rounded-circle fw-bold js-edit-record btn-action-icon"
                                            data-bs-toggle="modal" data-bs-target="#recordModal"
                                            data-id="<?= e($row['id']) ?>"
                                            data-customer-name="<?= e($row['customer_name']) ?>"
                                            data-mobile="<?= e($row['mobile']) ?>"
                                            data-function-type-id="<?= e($row['function_type_id']) ?>"
                                            data-function-date="<?= e($row['function_date']) ?>"
                                            data-venue="<?= e($row['venue']) ?>"
                                            data-address="<?= e($row['address']) ?>"
                                            data-enquiry-source="<?= e($row['enquiry_source']) ?>"
                                            data-enquiry-status-id="<?= e($row['enquiry_status_id']) ?>"
                                            data-assigned-sales-user-id="<?= e($row['assigned_sales_user_id']) ?>"
                                            data-next-callback-at="<?= !empty($row['next_callback_at']) ? e(date('Y-m-d\TH:i', strtotime($row['next_callback_at']))) : '' ?>"
                                            data-remarks="<?= e($row['remarks']) ?>"><i data-lucide="pencil"></i></button>
                                        <?php endif; ?>

                                        <?php if ($canSendWhatsapp): ?>
                                        <?php if ($canSendWhatsapp): ?>
                                <?= enqWhatsappPreviewButton($row) ?>
                                <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if (!$closed && $canDelete): ?>
                                        <form method="post" action="api/enquiries.php"
                                            class="d-inline js-api-close-form" onsubmit="return false;">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="close_record">
                                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                            <button title="Close" aria-label="Close" type="submit"
                                                class="btn btn-sm btn-outline-danger rounded-circle fw-bold btn-action-icon"><i data-lucide="x-circle"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-cards" id="mobileCards">
                        <?php if (!$rows): ?>
                        <div class="mobile-card text-center text-muted-custom">No enquiries found.</div>
                        <?php endif; ?>

                        <?php foreach ($rows as $row): ?>
                        <?php $closed = in_array(strtolower((string)($row['status_key'] ?? '')), ['cancelled', 'closed'], true); ?>
                        <div class="mobile-card">
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <div class="mobile-card-title"><?= e($row['customer_name']) ?></div>
                                    <span class="mobile-card-subtitle">No: <?= e($row['enquiry_no']) ?></span>
                                    <span class="mobile-card-subtitle">Mobile: <?= e($row['mobile']) ?></span>
                                    <span class="mobile-card-subtitle">Type:
                                        <?= e($row['function_name'] ?? '-') ?></span>
                                    <span class="mobile-card-subtitle">Callback:
                                        <?= e(enqDateTime($row['next_callback_at'] ?? null)) ?></span>
                                </div>

                                <span class="status-pill <?= $closed ? 'closed' : '' ?>">
                                    <?= e($row['status_name'] ?? 'New') ?>
                                </span>
                            </div>

                            <div class="mobile-card-actions">
                                <button title="View" aria-label="View" type="button"
                                    class="btn btn-sm btn-outline-secondary rounded-circle fw-bold js-view-record btn-action-icon"
                                    data-bs-toggle="modal" data-bs-target="#viewModal"
                                    data-enquiry-no="<?= e($row['enquiry_no']) ?>"
                                    data-customer-name="<?= e($row['customer_name']) ?>"
                                    data-mobile="<?= e($row['mobile']) ?>"
                                    data-function-name="<?= e($row['function_name'] ?? '-') ?>"
                                    data-function-date="<?= e(enqDate($row['function_date'] ?? null)) ?>"
                                    data-next-callback="<?= e(enqDateTime($row['next_callback_at'] ?? null)) ?>"
                                    data-venue="<?= e($row['venue']) ?>" data-address="<?= e($row['address']) ?>"
                                    data-enquiry-source="<?= e($row['enquiry_source']) ?>"
                                    data-status-name="<?= e($row['status_name'] ?? 'New') ?>"
                                    data-sales-person="<?= e($row['sales_person'] ?? '-') ?>"
                                    data-remarks="<?= e($row['remarks']) ?>"><i data-lucide="eye"></i></button>

                                <?php if ($canEdit): ?>
                                <button title="Edit" aria-label="Edit" type="button"
                                    class="btn btn-sm btn-outline-primary rounded-circle fw-bold js-edit-record btn-action-icon"
                                    data-bs-toggle="modal" data-bs-target="#recordModal" data-id="<?= e($row['id']) ?>"
                                    data-customer-name="<?= e($row['customer_name']) ?>"
                                    data-mobile="<?= e($row['mobile']) ?>"
                                    data-function-type-id="<?= e($row['function_type_id']) ?>"
                                    data-function-date="<?= e($row['function_date']) ?>"
                                    data-venue="<?= e($row['venue']) ?>" data-address="<?= e($row['address']) ?>"
                                    data-enquiry-source="<?= e($row['enquiry_source']) ?>"
                                    data-enquiry-status-id="<?= e($row['enquiry_status_id']) ?>"
                                    data-assigned-sales-user-id="<?= e($row['assigned_sales_user_id']) ?>"
                                    data-next-callback-at="<?= !empty($row['next_callback_at']) ? e(date('Y-m-d\TH:i', strtotime($row['next_callback_at']))) : '' ?>"
                                    data-remarks="<?= e($row['remarks']) ?>"><i data-lucide="pencil"></i></button>
                                <?php endif; ?>

                                <?= enqWhatsappPreviewButton($row) ?>

                                <?php if (!$closed && $canDelete): ?>
                                <form method="post" action="api/enquiries.php" class="d-inline js-api-close-form"
                                    onsubmit="return false;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="close_record">
                                    <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                    <button title="Close" aria-label="Close" type="submit" class="btn btn-sm btn-outline-danger rounded-circle fw-bold btn-action-icon"><i data-lucide="x-circle"></i></button>
                                </form>
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

    <div class="modal fade" id="recordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="post" action="api/enquiries.php" class="modal-content" id="enquiryForm">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save_record">
                <input type="hidden" name="id" id="id" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="recordModalTitle">Create Enquiry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Customer Name *</label>
                            <input type="text" name="customer_name" id="customer_name" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Mobile *</label>
                            <input type="text" name="mobile" id="mobile" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Function / Product Type *</label>
                            <select name="function_type_id" id="function_type_id" class="form-select select2-autotype"
                                required data-placeholder="Search function / product type" data-tags="true"
                                data-placeholder="Type or select function / product type">
                                <option value="">Select Type</option>
                                <?php foreach ($functionTypes as $type): ?>
                                <option value="<?= e($type['id']) ?>"><?= e($type['function_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Function Date</label>
                            <input type="date" name="function_date" id="function_date" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Enquiry Source</label>
                            <input type="text" name="enquiry_source" id="enquiry_source" class="form-control"
                                placeholder="Walk-in / WhatsApp / Instagram / Referral">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Next Callback</label>
                            <input type="datetime-local" name="next_callback_at" id="next_callback_at"
                                class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status *</label>
                            <select name="enquiry_status_id" id="enquiry_status_id" class="form-select select2-autotype"
                                required data-placeholder="Search status">
                                <option value="">Select Status</option>
                                <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status['id']) ?>"><?= e($status['status_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Assigned Sales Person</label>
                            <select name="assigned_sales_user_id" id="assigned_sales_user_id"
                                class="form-select select2-autotype" data-placeholder="Search sales person">
                                <option value="">Not Assigned</option>
                                <?php foreach ($salesUsers as $user): ?>
                                <option value="<?= e($user['id']) ?>"><?= e($user['display_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Venue</label>
                            <textarea name="venue" id="venue" rows="2" class="form-control"></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Address</label>
                            <textarea name="address" id="address" rows="2" class="form-control"></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Remarks</label>
                            <textarea name="remarks" id="remarks" rows="3" class="form-control"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" id="recordSubmitBtn">
                        Save
                    </button>
                </div>
            </form>
        </div>
    </div>



    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold">View Enquiry</h5>
                        <small class="text-muted-custom" id="viewEnquiryNo"></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Customer</small>
                                <strong id="viewCustomerName">-</strong>
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
                                <small>Status</small>
                                <strong id="viewStatusName">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Function / Product Type</small>
                                <strong id="viewFunctionName">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Function Date</small>
                                <strong id="viewFunctionDate">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Next Callback</small>
                                <strong id="viewNextCallback">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Enquiry Source</small>
                                <strong id="viewEnquirySource">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Assigned Sales Person</small>
                                <strong id="viewSalesPerson">-</strong>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="view-info-card">
                                <small>Venue</small>
                                <span id="viewVenue">-</span>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="view-info-card">
                                <small>Address</small>
                                <span id="viewAddress">-</span>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="view-info-card">
                                <small>Remarks</small>
                                <span id="viewRemarks">-</span>
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

    <div class="modal fade" id="whatsappPreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="post" action="api/enquiries.php" id="whatsappApiForm">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="send_whatsapp_api" id="wa_api_action">
                    <input type="hidden" name="manual_action" value="log_manual_whatsapp" id="wa_manual_action">
                    <input type="hidden" name="id" id="wa_enquiry_id" value="">

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title fw-bold">WhatsApp Preview</h5>
                            <small class="text-muted-custom" id="waCustomerInfo"></small>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <label class="form-label fw-bold">Message Preview</label>
                        <textarea class="form-control whatsapp-preview-box" id="waMessagePreview" rows="12"
                            readonly></textarea>

                        <div class="alert alert-info rounded-4 mt-3 mb-0 fw-bold" id="waModeInfo"></div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold"
                            data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="button" class="btn btn-whatsapp-icon btn-action-icon rounded-circle" id="waSendBtn"
                            title="Send WhatsApp">
                            <?= enqWhatsappSvg() ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
    (function() {
        const title = document.getElementById('recordModalTitle');
        const submit = document.getElementById('recordSubmitBtn');
        const defaultStatusId = '<?= e($defaultStatusId) ?>';


        const whatsappApiReady = <?= $whatsappApiReady ? 'true' : 'false' ?>;
        const autoOpenWhatsappUrl = '<?= e($autoOpenWhatsappUrl) ?>';
        let currentManualWhatsappUrl = '#';
        let whatsappPreviewModal = null;


        function showToast(message, type = 'success', title = '') {
            if (!message) return;

            const oldToastWrap = document.getElementById('dynamicActionToastWrap');
            if (oldToastWrap) {
                oldToastWrap.remove();
            }

            const toastTitle = title || (type === 'danger' ? 'Failed' : (type === 'warning' ? 'Warning' :
                'Success'));
            const wrap = document.createElement('div');
            wrap.id = 'dynamicActionToastWrap';
            wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
            wrap.style.zIndex = '12000';

            wrap.innerHTML = `
                <div id="dynamicActionToast" class="toast toast-ui ${type}" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4200">
                    <div class="d-flex">
                        <div class="toast-body">
                            <div class="toast-title">${toastTitle}</div>
                            <div class="toast-message">${message}</div>
                        </div>
                        <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;

            document.body.appendChild(wrap);

            const toastEl = document.getElementById('dynamicActionToast');
            if (window.bootstrap && bootstrap.Toast && toastEl) {
                bootstrap.Toast.getOrCreateInstance(toastEl).show();
            }
        }

        const pageToastEl = document.getElementById('pageToast');
        if (pageToastEl && window.bootstrap && bootstrap.Toast) {
            bootstrap.Toast.getOrCreateInstance(pageToastEl).show();
        }


        function openWhatsappPreview(btn) {
            const modalEl = document.getElementById('whatsappPreviewModal');
            if (!modalEl) return;

            set('wa_enquiry_id', btn.dataset.id || '');
            currentManualWhatsappUrl = btn.dataset.waUrl || '#';

            const messageBox = document.getElementById('waMessagePreview');
            const infoBox = document.getElementById('waModeInfo');
            const customerInfo = document.getElementById('waCustomerInfo');

            if (messageBox) {
                messageBox.value = btn.dataset.message || '';
            }

            if (customerInfo) {
                customerInfo.textContent = (btn.dataset.customerName || 'Customer') + ' | ' + (btn.dataset.mobile ||
                    '');
            }

            if (infoBox) {
                infoBox.textContent = whatsappApiReady ?
                    'API mode: message will be sent directly using WhatsApp API.' :
                    'Manual mode: WhatsApp Web/App will open and this action will be saved in WhatsApp Logs.';
            }

            whatsappPreviewModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            whatsappPreviewModal.show();
        }


        function setText(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            const clean = (value == null || String(value).trim() === '') ? '-' : String(value);
            el.textContent = clean;
        }

        document.querySelectorAll('.js-view-record').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setText('viewEnquiryNo', btn.dataset.enquiryNo || '-');
                setText('viewCustomerName', btn.dataset.customerName || '-');
                setText('viewMobile', btn.dataset.mobile || '-');
                setText('viewStatusName', btn.dataset.statusName || '-');
                setText('viewFunctionName', btn.dataset.functionName || '-');
                setText('viewFunctionDate', btn.dataset.functionDate || '-');
                setText('viewNextCallback', btn.dataset.nextCallback || '-');
                setText('viewEnquirySource', btn.dataset.enquirySource || '-');
                setText('viewSalesPerson', btn.dataset.salesPerson || '-');
                setText('viewVenue', btn.dataset.venue || '-');
                setText('viewAddress', btn.dataset.address || '-');
                setText('viewRemarks', btn.dataset.remarks || '-');
            });
        });


        document.querySelectorAll('.js-whatsapp-preview').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openWhatsappPreview(btn);
            });
        });

        document.getElementById('waSendBtn')?.addEventListener('click', function() {
            if (whatsappApiReady) {
                const form = document.getElementById('whatsappApiForm');
                const formData = new FormData(form);
                formData.set('action', 'send_whatsapp_api');

                fetch('api/enquiries.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        showToast(data.message || (data.status ? 'WhatsApp sent successfully.' :
                                'WhatsApp failed.'), data.status ? 'success' : 'danger', data
                            .status ? 'Success' : 'Failed');
                        if (data.open_whatsapp_url) {
                            window.open(data.open_whatsapp_url, '_blank', 'noopener');
                        }
                        if (whatsappPreviewModal) {
                            whatsappPreviewModal.hide();
                        }
                    })
                    .catch(() => showToast('WhatsApp API request failed.', 'danger', 'Failed'));

                return;
            }

            if (currentManualWhatsappUrl && currentManualWhatsappUrl !== '#') {
                const form = document.getElementById('whatsappApiForm');
                const formData = new FormData(form);
                formData.set('action', 'log_manual_whatsapp');

                fetch('api/enquiries.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                }).catch(function() {
                    showToast('WhatsApp log failed, but manual WhatsApp will open.', 'warning',
                        'Warning');
                }).finally(function() {
                    window.open(currentManualWhatsappUrl, '_blank', 'noopener');

                    if (whatsappPreviewModal) {
                        whatsappPreviewModal.hide();
                    }
                });
            } else {
                showToast('Customer mobile number is missing.', 'danger', 'Failed');
            }
        });




        if (autoOpenWhatsappUrl && autoOpenWhatsappUrl !== '#') {
            window.addEventListener('load', function() {
                setTimeout(function() {
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState({}, document.title,
                            'enquiries.php?msg=created_whatsapp_manual');
                    }

                    /*
                     * Open WhatsApp in the same tab.
                     * This avoids browser popup blocking and also makes the create modal close first
                     * because the page reloads cleanly before this redirect happens.
                     */
                    window.location.href = autoOpenWhatsappUrl;
                }, 500);
            });
        }


        function initSelect2AutoType(context) {
            if (!window.jQuery || !$.fn.select2) return;

            const $context = context ? $(context) : $(document);

            $context.find('select.select2-autotype').each(function() {
                const $select = $(this);

                if ($select.hasClass('select2-hidden-accessible')) {
                    $select.select2('destroy');
                }

                const $modal = $select.closest('.modal');

                $select.select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    dropdownParent: $modal.length ? $modal : $(document.body),
                    placeholder: $select.data('placeholder') || $select.find('option:first')
                        .text() || 'Search and select',
                    allowClear: false,
                    tags: String($select.data('tags')) === 'true',
                    createTag: function(params) {
                        const term = $.trim(params.term);
                        if (term === '') return null;

                        return {
                            id: term,
                            text: term,
                            newTag: true
                        };
                    }
                });
            });
        }

        function refreshSelect2Value(id) {
            if (window.jQuery && $.fn.select2) {
                $('#' + id).trigger('change.select2');
            }
        }

        function set(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = value == null ? '' : value;
        }

        document.getElementById('newRecordBtn')?.addEventListener('click', function() {
            title.textContent = 'Create Enquiry';
            submit.textContent = 'Save';

            set('id', '');
            set('customer_name', '');
            set('mobile', '');
            set('function_type_id', '');
            set('function_date', '');
            set('venue', '');
            set('address', '');
            set('enquiry_source', '');
            set('enquiry_status_id', defaultStatusId);
            set('assigned_sales_user_id', '');
            set('next_callback_at', '');
            set('remarks', '');

            refreshSelect2Value('function_type_id');
            refreshSelect2Value('enquiry_status_id');
            refreshSelect2Value('assigned_sales_user_id');
        });

        document.querySelectorAll('.js-edit-record').forEach(function(btn) {
            btn.addEventListener('click', function() {
                title.textContent = 'Edit Enquiry';
                submit.textContent = 'Update';

                set('id', btn.dataset.id || '');
                set('customer_name', btn.dataset.customerName || '');
                set('mobile', btn.dataset.mobile || '');
                set('function_type_id', btn.dataset.functionTypeId || '');
                set('function_date', btn.dataset.functionDate || '');
                set('venue', btn.dataset.venue || '');
                set('address', btn.dataset.address || '');
                set('enquiry_source', btn.dataset.enquirySource || '');
                set('enquiry_status_id', btn.dataset.enquiryStatusId || defaultStatusId);
                set('assigned_sales_user_id', btn.dataset.assignedSalesUserId || '');
                set('next_callback_at', btn.dataset.nextCallbackAt || '');
                set('remarks', btn.dataset.remarks || '');

                refreshSelect2Value('function_type_id');
                refreshSelect2Value('enquiry_status_id');
                refreshSelect2Value('assigned_sales_user_id');
            });
        });


        document.querySelector('#recordModal form')?.addEventListener('submit', function(event) {
            event.preventDefault();

            const form = this;
            const formData = new FormData(form);

            fetch('api/enquiries.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    showToast(data.message || (data.status ? 'Saved successfully.' : 'Save failed.'),
                        data.status ? 'success' : 'danger', data.status ? 'Success' : 'Failed');

                    if (data.open_whatsapp_url) {
                        setTimeout(() => window.open(data.open_whatsapp_url, '_blank', 'noopener'),
                            500);
                    }

                    if (data.status) {
                        setTimeout(() => window.location.reload(), 900);
                    }
                })
                .catch(() => showToast('API request failed.', 'danger', 'Failed'));
        });

        document.querySelectorAll('.js-api-close-form').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
            });

            form.querySelector('button[type="submit"]')?.addEventListener('click', function() {
                const ok = confirm('Close this enquiry?');
                if (!ok) return;

                const formData = new FormData(form);
                fetch('api/enquiries.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        showToast(data.message || (data.status ? 'Enquiry closed.' :
                                'Close failed.'), data.status ? 'success' : 'danger', data
                            .status ? 'Success' : 'Failed');
                        if (data.status) {
                            setTimeout(() => window.location.reload(), 800);
                        }
                    })
                    .catch(() => showToast('API request failed.', 'danger', 'Failed'));
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

        initSelect2AutoType(document);

        document.getElementById('recordModal')?.addEventListener('shown.bs.modal', function() {
            initSelect2AutoType(this);
        });

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    })();
    </script>
</body>

</html>