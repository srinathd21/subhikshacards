<?php
/**
 * api/enquiries.php
 * Action-based API for Enquiries module.
 * Generated from existing enquiries.php without changing database structure/business rules.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_permission($conn, 'can_view', 'enquiries.php');

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
    $apiFile = __DIR__ . '/../includes/whatsapp-api.php';

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
            class="btn btn-sm btn-whatsapp-icon rounded-circle js-whatsapp-preview"
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
        empty($_SESSION['enquiries_csrf']) ||
        !hash_equals($_SESSION['enquiries_csrf'], (string)$_REQUEST['csrf_token'])
    ) {
        apiResponse(false, 'Invalid CSRF token.');
    }
}

function apiEnquiryRow(mysqli $conn, int $id): ?array
{
    if ($id <= 0 || !enqTableExists($conn, 'enquiries')) {
        return null;
    }

    $stmt = $conn->prepare("
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
        WHERE e.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function apiEnquiryList(mysqli $conn): array
{
    if (!enqTableExists($conn, 'enquiries')) {
        return [];
    }

    $rows = [];
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
    return $rows;
}

try {
    $action = (string)($_REQUEST['action'] ?? '');

    if ($action === '') {
        apiResponse(false, 'Action is required.');
    }

    if (in_array($action, ['create', 'update', 'save_record', 'delete', 'close_record', 'log_manual_whatsapp', 'send_whatsapp_api'], true)) {
        apiCsrf();
    }

    if ($action === 'list') {
        apiResponse(true, 'List loaded successfully.', ['data' => apiEnquiryList($conn)]);
    }

    if ($action === 'view') {
        $id = enqInt($_REQUEST['id'] ?? 0);
        $row = apiEnquiryRow($conn, $id);

        if (!$row) {
            apiResponse(false, 'Enquiry not found.');
        }

        apiResponse(true, 'Enquiry loaded successfully.', ['data' => $row]);
    }

    if (in_array($action, ['create', 'update', 'save_record'], true)) {
        if (!enqTableExists($conn, 'enquiries')) {
            throw new RuntimeException('enquiries table is missing.');
        }

        $id = enqInt($_POST['id'] ?? 0);
        $customerName = enqPost('customer_name');
        $mobile = enqPost('mobile');
        $functionTypeRaw = enqPost('function_type_id');
        $functionTypeId = enqFunctionTypeId($conn, $functionTypeRaw);
        $functionDate = enqPost('function_date');
        $venue = enqPost('venue');
        $address = enqPost('address');
        $enquirySource = enqPost('enquiry_source');
        $enquiryStatusId = enqInt($_POST['enquiry_status_id'] ?? 0);
        $assignedSalesUserId = enqInt($_POST['assigned_sales_user_id'] ?? 0);
        $nextCallbackAt = enqPost('next_callback_at');
        $remarks = enqPost('remarks');

        if ($customerName === '' || $mobile === '') {
            throw new RuntimeException('Customer name and mobile number are required.');
        }

        if ($functionTypeId <= 0) {
            throw new RuntimeException('Function / Product type is required.');
        }

        if ($enquiryStatusId <= 0) {
            $defaultStatus = enqGetDefaultStatusId($conn);
            if (!$defaultStatus) {
                throw new RuntimeException('Enquiry status master is missing. Add enquiry_statuses first.');
            }
            $enquiryStatusId = $defaultStatus;
        }

        $functionDateValue = $functionDate !== '' ? $functionDate : null;
        $nextCallbackValue = null;

        if ($nextCallbackAt !== '') {
            $nextCallbackValue = str_replace('T', ' ', $nextCallbackAt);
            if (strlen($nextCallbackValue) === 16) {
                $nextCallbackValue .= ':00';
            }
        }

        $assignedSalesUserIdValue = $assignedSalesUserId > 0 ? $assignedSalesUserId : null;
        $functionTypeIdValue = $functionTypeId > 0 ? $functionTypeId : null;
        $enquiryStatusIdValue = $enquiryStatusId > 0 ? $enquiryStatusId : null;
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $customerId = enqCustomerId($conn, $customerName, $mobile, $address);

        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE enquiries
                SET customer_id = ?,
                    customer_name = ?,
                    mobile = ?,
                    function_type_id = ?,
                    function_date = ?,
                    venue = ?,
                    address = ?,
                    enquiry_source = ?,
                    enquiry_status_id = ?,
                    assigned_sales_user_id = ?,
                    next_callback_at = ?,
                    remarks = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->bind_param(
                'ississssiissii',
                $customerId,
                $customerName,
                $mobile,
                $functionTypeIdValue,
                $functionDateValue,
                $venue,
                $address,
                $enquirySource,
                $enquiryStatusIdValue,
                $assignedSalesUserIdValue,
                $nextCallbackValue,
                $remarks,
                $userId,
                $id
            );
            $stmt->execute();
            $stmt->close();

            enqLog($conn, 'update', $id, 'Enquiry updated.');
            apiResponse(true, 'Enquiry updated successfully.', ['id' => $id]);
        }

        $enquiryNo = enqNextNo($conn);

        $stmt = $conn->prepare("
            INSERT INTO enquiries
                (
                    enquiry_no,
                    customer_id,
                    customer_name,
                    mobile,
                    function_type_id,
                    function_date,
                    venue,
                    address,
                    enquiry_source,
                    enquiry_status_id,
                    assigned_sales_user_id,
                    next_callback_at,
                    remarks,
                    created_by,
                    created_at,
                    updated_at
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $stmt->bind_param(
            'sississssiissi',
            $enquiryNo,
            $customerId,
            $customerName,
            $mobile,
            $functionTypeIdValue,
            $functionDateValue,
            $venue,
            $address,
            $enquirySource,
            $enquiryStatusIdValue,
            $assignedSalesUserIdValue,
            $nextCallbackValue,
            $remarks,
            $userId
        );
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();

        enqLog($conn, 'create_enquiry', $newId, 'Enquiry created: ' . $enquiryNo);

        $extra = ['id' => $newId, 'enquiry_no' => $enquiryNo];

        if (enqWhatsappApiReady($conn)) {
            $waResult = enqSendWhatsappByApi($conn, $newId);

            if (!($waResult['success'] ?? false)) {
                apiResponse(true, 'Enquiry created successfully. WhatsApp API sending failed.', array_merge($extra, [
                    'whatsapp_status' => false,
                    'whatsapp_message' => (string)($waResult['response'] ?? $waResult['message'] ?? 'WhatsApp failed.')
                ]));
            }

            apiResponse(true, 'Enquiry created and WhatsApp message sent successfully.', array_merge($extra, [
                'whatsapp_status' => true
            ]));
        }

        enqWhatsappLogManual($conn, $newId);
        $row = enqGetByIdForWhatsapp($conn, $newId);
        apiResponse(true, 'Enquiry created successfully. Open WhatsApp for manual sending.', array_merge($extra, [
            'manual_whatsapp' => true,
            'open_whatsapp_url' => $row ? enqWhatsappUrl($row) : ''
        ]));
    }

    if ($action === 'log_manual_whatsapp') {
        $id = enqInt($_POST['id'] ?? 0);

        if ($id <= 0) {
            apiResponse(false, 'Invalid enquiry.');
        }

        $manualResult = enqWhatsappLogManual($conn, $id);
        apiResponse((bool)($manualResult['success'] ?? false), (string)($manualResult['message'] ?? ''), $manualResult);
    }

    if ($action === 'send_whatsapp_api') {
        $id = enqInt($_POST['id'] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException('Invalid enquiry.');
        }

        if (!enqWhatsappApiReady($conn)) {
            $row = enqGetByIdForWhatsapp($conn, $id);
            apiResponse(false, 'WhatsApp API is not ready. Manual WhatsApp mode is active.', [
                'manual_whatsapp' => true,
                'open_whatsapp_url' => $row ? enqWhatsappUrl($row) : ''
            ]);
        }

        $waResult = enqSendWhatsappByApi($conn, $id);

        if (!($waResult['success'] ?? false)) {
            apiResponse(false, (string)($waResult['response'] ?? $waResult['message'] ?? 'WhatsApp failed.'), $waResult);
        }

        enqLog($conn, 'send_whatsapp', $id, 'WhatsApp enquiry message sent using API.');
        apiResponse(true, 'WhatsApp message sent successfully using API.', $waResult);
    }

    if (in_array($action, ['delete', 'close_record'], true)) {
        $id = enqInt($_POST['id'] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException('Invalid enquiry.');
        }

        $closedStatusId = enqGetClosedStatusId($conn);
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($closedStatusId) {
            $stmt = $conn->prepare("
                UPDATE enquiries
                SET enquiry_status_id = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('iii', $closedStatusId, $userId, $id);
        } else {
            $stmt = $conn->prepare("
                UPDATE enquiries
                SET updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('ii', $userId, $id);
        }

        $stmt->execute();
        $stmt->close();

        enqLog($conn, 'delete', $id, 'Enquiry closed.');
        apiResponse(true, 'Enquiry closed successfully.', ['id' => $id]);
    }

    apiResponse(false, 'Invalid action.');
} catch (Throwable $e) {
    apiResponse(false, $e->getMessage());
}
