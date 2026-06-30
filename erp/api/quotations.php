<?php
/**
 * api/quotations.php
 * Action-based API for Quotations module.
 * Backend processing moved from quotations.php without changing DB schema/business flow.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_permission($conn, 'can_view', 'quotations.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['quotations_csrf'])) {
    $_SESSION['quotations_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['quotations_csrf'];
$message = '';
$messageType = 'success';

function qtTableExists(mysqli $conn, string $table): bool
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

function qtPost(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function qtInt($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function qtFloat($value): float
{
    return (float)str_replace(',', '', (string)$value);
}

function qtRedirect(string $query = ''): void
{
    header('Location: quotations.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function qtCsrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['quotations_csrf']) ||
        !hash_equals($_SESSION['quotations_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function qtNextNo(mysqli $conn): string
{
    $prefix = 'SC-QTN';
    $datePart = date('ymd');
    $like = $prefix . '-' . $datePart . '-%';

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM quotations WHERE quotation_no LIKE ?");
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

function qtDefaultStatusId(mysqli $conn): ?int
{
    try {
        if (!qtTableExists($conn, 'quotation_statuses')) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM quotation_statuses
            WHERE status_key IN ('draft', 'sent')
            ORDER BY FIELD(status_key, 'draft', 'sent'), id ASC
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

function qtCancelledStatusId(mysqli $conn): ?int
{
    try {
        if (!qtTableExists($conn, 'quotation_statuses')) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM quotation_statuses
            WHERE status_key IN ('cancelled', 'rejected')
            ORDER BY FIELD(status_key, 'cancelled', 'rejected'), id ASC
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


function qtFunctionTypeId(mysqli $conn, string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    if (ctype_digit($value)) {
        return (int)$value;
    }

    if (!qtTableExists($conn, 'function_types')) {
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


function qtCustomerId(mysqli $conn, string $customerName, string $mobile, string $address = ''): ?int
{
    $customerName = trim($customerName);
    $mobile = trim($mobile);
    $address = trim($address);

    if ($customerName === '' || $mobile === '' || !qtTableExists($conn, 'customers')) {
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

function qtLog(mysqli $conn, string $actionKey, int $recordId, string $description): void
{
    try {
        if (!qtTableExists($conn, 'activity_logs')) {
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $moduleName = 'Quotations';
        $tableName = 'quotations';
        $actionTypeId = null;

        if (qtTableExists($conn, 'activity_action_types')) {
            $stmt = $conn->prepare("SELECT id FROM activity_action_types WHERE action_key = ? LIMIT 1");
            $stmt->bind_param('s', $actionKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $actionTypeId = (int)$row['id'];
            }
        }

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
    }
}


function qtColumnExists(mysqli $conn, string $table, string $column): bool
{
    try {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) {
            $res->free();
        }
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function qtFunctionTypeName(mysqli $conn, ?int $functionTypeId): string
{
    if (!$functionTypeId || !qtTableExists($conn, 'function_types')) {
        return '';
    }

    try {
        $stmt = $conn->prepare("SELECT function_name FROM function_types WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $functionTypeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? trim((string)$row['function_name']) : '';
    } catch (Throwable $e) {
        return '';
    }
}

function qtFunctionCategory(string $functionName): string
{
    $name = strtolower(trim($functionName));

    if (in_array($name, ['wedding', 'reception'], true)) {
        return 'wedding_reception';
    }

    if (in_array($name, ['baby shower', 'ear piercing', 'bridal shower', 'opening ceremony'], true)) {
        return 'event';
    }

    if (in_array($name, ['visiting card', 'bill book', 'brochure', 'pamphlet'], true)) {
        return 'business_print';
    }

    return 'other';
}

function qtSettingValue(mysqli $conn, string $key, string $default = ''): string
{
    try {
        if (!qtTableExists($conn, 'system_settings')) {
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

function qtWhatsappApiReady(mysqli $conn): bool
{
    $enabled = qtSettingValue($conn, 'whatsapp_enabled', '0');
    $apiUrl = qtSettingValue($conn, 'watzup_api_url', '');
    $apiToken = qtSettingValue($conn, 'watzup_api_token', '');
    $senderId = qtSettingValue($conn, 'watzup_sender_id', '');

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

function qtWhatsappMobile($mobile): string
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

function qtWhatsappMessage(array $row): string
{
    $customerName = trim((string)($row['customer_name'] ?? 'Customer'));
    $quotationNo = trim((string)($row['quotation_no'] ?? '-'));
    $functionType = trim((string)($row['function_name'] ?? '-'));
    $qty = number_format((float)($row['total_qty'] ?? 0), 0);
    $itemDetails = trim((string)($row['item_details'] ?? ''));
    $price = '₹' . number_format((float)($row['sub_total'] ?? 0), 2);
    $finalAmount = '₹' . number_format((float)($row['final_amount'] ?? 0), 2);

    $lines = [
        "Hi {$customerName},",
        "",
        "Greetings from Subhiksha Cards.",
        "",
        "Your quotation has been created successfully.",
        "",
        "Quotation No: {$quotationNo}",
        "Function/Product Type: {$functionType}",
        "Number of Items: {$qty}",
    ];

    if ($itemDetails !== '') {
        $lines[] = "Item Details: {$itemDetails}";
    }

    $lines[] = "Sub Total: {$price}";
    $lines[] = "Final Price: {$finalAmount}";
    $lines[] = "";
    $lines[] = "Please review and confirm to proceed with the next process.";
    $lines[] = "";
    $lines[] = "Thank you,";
    $lines[] = "Subhiksha Cards Team";

    return implode("\n", $lines);
}

function qtWhatsappUrl(array $row): string
{
    $mobile = qtWhatsappMobile($row['mobile'] ?? '');

    if ($mobile === '') {
        return '#';
    }

    return 'https://wa.me/' . $mobile . '?text=' . rawurlencode(qtWhatsappMessage($row));
}

function qtGetByIdForWhatsapp(mysqli $conn, int $id): ?array
{
    if ($id <= 0 || !qtTableExists($conn, 'quotations')) {
        return null;
    }

    try {
        $itemSelect = qtColumnExists($conn, 'quotations', 'item_details') ? 'q.item_details,' : "'' AS item_details,";
        $stmt = $conn->prepare("
            SELECT
                q.*,
                {$itemSelect}
                ft.function_name,
                qs.status_name,
                qs.status_key
            FROM quotations q
            LEFT JOIN function_types ft ON ft.id = q.function_type_id
            LEFT JOIN quotation_statuses qs ON qs.id = q.quotation_status_id
            WHERE q.id = ?
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

function qtWhatsappTemplateId(mysqli $conn, string $templateKey): ?int
{
    try {
        if (!qtTableExists($conn, 'whatsapp_templates')) {
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

function qtWhatsappLogManual(mysqli $conn, int $id): array
{
    $quotation = qtGetByIdForWhatsapp($conn, $id);

    if (!$quotation) {
        return ['success' => false, 'message' => 'Quotation not found.'];
    }

    $mobile = qtWhatsappMobile($quotation['mobile'] ?? '');

    if ($mobile === '') {
        return ['success' => false, 'message' => 'Customer mobile number is missing.'];
    }

    if (!qtTableExists($conn, 'whatsapp_logs')) {
        return ['success' => true, 'message' => 'Manual WhatsApp opened. whatsapp_logs table missing, so log not saved.'];
    }

    try {
        $templateId = qtWhatsappTemplateId($conn, 'quotation_created');
        $relatedModule = 'Quotations';
        $relatedId = $id;
        $customerId = !empty($quotation['customer_id']) ? (int)$quotation['customer_id'] : null;
        $jobCardId = null;
        $messageBody = qtWhatsappMessage($quotation);
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

        return ['success' => true, 'message' => 'Manual WhatsApp logged.', 'log_id' => $logId];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function qtSendWhatsappByApi(mysqli $conn, int $id): array
{
    $apiFile = __DIR__ . '/../includes/whatsapp-api.php';

    if (!file_exists($apiFile)) {
        return ['success' => false, 'message' => 'WhatsApp API file missing.'];
    }

    require_once $apiFile;

    if (!function_exists('subhiksha_send_whatsapp')) {
        return ['success' => false, 'message' => 'WhatsApp API function missing.'];
    }

    $quotation = qtGetByIdForWhatsapp($conn, $id);

    if (!$quotation) {
        return ['success' => false, 'message' => 'Quotation not found.'];
    }

    return subhiksha_send_whatsapp($conn, [
        'mobile' => (string)($quotation['mobile'] ?? ''),
        'template_key' => 'quotation_created',
        'variables' => [
            'customer_name' => (string)($quotation['customer_name'] ?? 'Customer'),
            'quotation_no' => (string)($quotation['quotation_no'] ?? '-'),
            'function_type' => (string)($quotation['function_name'] ?? '-'),
            'number_of_items' => number_format((float)($quotation['total_qty'] ?? 0), 0),
            'item_details' => (string)($quotation['item_details'] ?? ''),
            'price' => '₹' . number_format((float)($quotation['sub_total'] ?? 0), 2),
            'final_price' => '₹' . number_format((float)($quotation['final_amount'] ?? 0), 2)
        ],
        'related_module' => 'Quotations',
        'related_id' => $id,
        'customer_id' => $quotation['customer_id'] ?? null
    ]);
}

function qtWhatsappSvg(): string
{
    return '<svg viewBox="0 0 32 32" width="17" height="17" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16.04 3C8.85 3 3 8.73 3 15.78c0 2.26.61 4.47 1.77 6.41L3 29l7.02-1.8a13.3 13.3 0 0 0 6.02 1.43C23.23 28.63 29 22.9 29 15.85S23.23 3 16.04 3Zm0 23.45c-1.9 0-3.76-.5-5.39-1.45l-.39-.23-4.16 1.07 1.11-4.01-.26-.41a11.05 11.05 0 0 1-1.73-5.64c0-5.84 4.85-10.6 10.82-10.6 5.96 0 10.81 4.76 10.81 10.67 0 5.84-4.85 10.6-10.81 10.6Zm5.93-7.95c-.32-.16-1.9-.92-2.2-1.03-.3-.11-.52-.16-.74.16-.22.32-.85 1.03-1.04 1.24-.19.22-.38.24-.7.08-.32-.16-1.36-.49-2.59-1.55-.96-.84-1.61-1.88-1.8-2.2-.19-.32-.02-.49.14-.65.14-.14.32-.38.49-.57.16-.19.22-.32.32-.54.11-.22.05-.41-.03-.57-.08-.16-.74-1.76-1.01-2.41-.27-.65-.54-.54-.74-.55h-.63c-.22 0-.57.08-.87.41-.3.32-1.14 1.09-1.14 2.68s1.17 3.12 1.33 3.34c.16.22 2.3 3.46 5.58 4.85.78.33 1.39.53 1.86.68.78.24 1.49.21 2.05.13.63-.09 1.9-.76 2.17-1.49.27-.73.27-1.36.19-1.49-.08-.13-.3-.21-.62-.37Z"/></svg>';
}

function qtWhatsappPreviewButton(array $row): string
{
    return '
        <button type="button"
            class="btn btn-sm btn-whatsapp-icon rounded-circle js-whatsapp-preview"
            title="Preview WhatsApp message"
            data-id="' . e($row['id'] ?? '') . '"
            data-customer-name="' . e($row['customer_name'] ?? '') . '"
            data-mobile="' . e($row['mobile'] ?? '') . '"
            data-wa-url="' . e(qtWhatsappUrl($row)) . '"
            data-message="' . e(qtWhatsappMessage($row)) . '">
            ' . qtWhatsappSvg() . '
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
        empty($_SESSION['quotations_csrf']) ||
        !hash_equals($_SESSION['quotations_csrf'], (string)$_REQUEST['csrf_token'])
    ) {
        apiResponse(false, 'Invalid CSRF token.');
    }
}

function apiQuotationRow(mysqli $conn, int $id): ?array
{
    if ($id <= 0 || !qtTableExists($conn, 'quotations')) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            q.*,
            ft.function_name,
            ft.field_group,
            qs.status_name,
            qs.status_key,
            qs.color_code,
            e.enquiry_no
        FROM quotations q
        LEFT JOIN function_types ft ON ft.id = q.function_type_id
        LEFT JOIN quotation_statuses qs ON qs.id = q.quotation_status_id
        LEFT JOIN enquiries e ON e.id = q.enquiry_id
        WHERE q.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function apiQuotationList(mysqli $conn): array
{
    if (!qtTableExists($conn, 'quotations')) {
        return [];
    }

    $rows = [];
    $res = $conn->query("
        SELECT
            q.*,
            ft.function_name,
            ft.field_group,
            qs.status_name,
            qs.status_key,
            qs.color_code,
            e.enquiry_no
        FROM quotations q
        LEFT JOIN function_types ft ON ft.id = q.function_type_id
        LEFT JOIN quotation_statuses qs ON qs.id = q.quotation_status_id
        LEFT JOIN enquiries e ON e.id = q.enquiry_id
        ORDER BY q.id DESC
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

    $permissionMap = [
        'list' => 'can_view',
        'view' => 'can_view',
        'create' => 'can_create',
        'update' => 'can_edit',
        'save_record' => ((int)($_POST['id'] ?? 0) > 0 ? 'can_edit' : 'can_create'),
        'delete' => 'can_delete',
        'cancel_record' => 'can_delete',
        'log_manual_whatsapp' => 'can_send_whatsapp',
        'send_whatsapp_api' => 'can_send_whatsapp',
    ];

    if (isset($permissionMap[$action]) && !permission_allowed($conn, $permissionMap[$action], 'quotations.php')) {
        apiResponse(false, 'Permission denied.');
    }

    if ($action === '') {
        apiResponse(false, 'Action is required.');
    }

    if (in_array($action, ['create', 'update', 'save_record', 'delete', 'cancel_record', 'log_manual_whatsapp', 'send_whatsapp_api'], true)) {
        apiCsrf();
    }

    if ($action === 'list') {
        apiResponse(true, 'Quotations loaded successfully.', ['data' => apiQuotationList($conn)]);
    }

    if ($action === 'view') {
        $id = qtInt($_REQUEST['id'] ?? 0);
        $row = apiQuotationRow($conn, $id);

        if (!$row) {
            apiResponse(false, 'Quotation not found.');
        }

        apiResponse(true, 'Quotation loaded successfully.', ['data' => $row]);
    }

    if (in_array($action, ['create', 'update', 'save_record'], true)) {
        if (!qtTableExists($conn, 'quotations')) {
            throw new RuntimeException('quotations table is missing.');
        }

        $id = qtInt($_POST['id'] ?? 0);
        $enquiryId = qtInt($_POST['enquiry_id'] ?? 0);
        $functionTypeRaw = qtPost('function_type_id');
        $functionTypeId = qtFunctionTypeId($conn, $functionTypeRaw);
        $customerName = qtPost('customer_name');
        $mobile = qtPost('mobile');
        $address = qtPost('address');
        $brideName = qtPost('bride_name');
        $groomName = qtPost('groom_name');
        $venue = qtPost('venue');
        $functionDate = qtPost('function_date');
        $functionTime = qtPost('function_time');
        $quotationStatusId = qtInt($_POST['quotation_status_id'] ?? 0);
        $totalQty = qtFloat($_POST['total_qty'] ?? 0);
        $itemDetails = qtPost('item_details');
        $unitPrice = qtFloat($_POST['unit_price'] ?? 0);
        $subTotal = round($totalQty * $unitPrice, 2);
        $discountAmount = qtFloat($_POST['discount_amount'] ?? 0);
        $finalAmount = max(0, round($subTotal - $discountAmount, 2));
        $remarks = qtPost('remarks');

        if ($functionTypeId <= 0) {
            throw new RuntimeException('Function / Product type is required.');
        }

        $functionTypeName = qtFunctionTypeName($conn, $functionTypeId);
        $functionCategory = qtFunctionCategory($functionTypeName);

        if ($functionCategory === 'wedding_reception') {
            if ($brideName === '' || $groomName === '') {
                throw new RuntimeException('Bride name and groom name are required.');
            }

            if ($customerName === '') {
                $customerName = trim($brideName . ' & ' . $groomName);
            }
        }

        if (in_array($functionCategory, ['event', 'business_print'], true) && $customerName === '') {
            throw new RuntimeException('Customer name is required.');
        }

        if (in_array($functionCategory, ['wedding_reception', 'event', 'business_print'], true) && $mobile === '') {
            throw new RuntimeException('Mobile number is required.');
        }

        if (in_array($functionCategory, ['wedding_reception', 'event'], true)) {
            if ($venue === '') {
                throw new RuntimeException('Venue is required.');
            }

            if ($functionDate === '') {
                throw new RuntimeException('Function date is required.');
            }

            if ($functionTime === '') {
                throw new RuntimeException('Function time is required.');
            }
        }

        if ($functionCategory === 'business_print' && $address === '') {
            throw new RuntimeException('Address is required.');
        }

        if ($functionCategory === 'other') {
            $customerName = $customerName !== '' ? $customerName : 'Direct Customer';
            $mobile = $mobile !== '' ? $mobile : '-';
        }

        if ($totalQty <= 0) {
            throw new RuntimeException('Number of items must be greater than zero.');
        }

        if ($itemDetails === '') {
            throw new RuntimeException('Item details is required.');
        }

        if ($unitPrice <= 0) {
            throw new RuntimeException('Each card price must be greater than zero.');
        }

        if ($subTotal <= 0) {
            throw new RuntimeException('Sub total must be greater than zero.');
        }

        if ($discountAmount < 0) {
            throw new RuntimeException('Discount amount cannot be negative.');
        }

        if ($discountAmount > $subTotal) {
            throw new RuntimeException('Discount amount cannot be greater than sub total.');
        }

        if ($finalAmount <= 0) {
            throw new RuntimeException('Final price must be greater than zero.');
        }

        if ($quotationStatusId <= 0) {
            $defaultStatusId = qtDefaultStatusId($conn);
            if (!$defaultStatusId) {
                throw new RuntimeException('Quotation status master is missing.');
            }
            $quotationStatusId = $defaultStatusId;
        }

        $enquiryIdValue = $enquiryId > 0 ? $enquiryId : null;
        $functionTypeIdValue = $functionTypeId > 0 ? $functionTypeId : null;
        $quotationStatusIdValue = $quotationStatusId > 0 ? $quotationStatusId : null;
        $functionDateValue = $functionDate !== '' ? $functionDate : null;
        $functionTimeValue = $functionTime !== '' ? $functionTime : null;
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $customerId = qtCustomerId($conn, $customerName, $mobile, $address);

        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE quotations
                SET enquiry_id = ?,
                    customer_id = ?,
                    function_type_id = ?,
                    customer_name = ?,
                    mobile = ?,
                    address = ?,
                    bride_name = ?,
                    groom_name = ?,
                    venue = ?,
                    function_date = ?,
                    function_time = ?,
                    quotation_status_id = ?,
                    total_qty = ?,
                    item_details = ?,
                    sub_total = ?,
                    discount_amount = ?,
                    final_amount = ?,
                    remarks = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param(
                'iiissssssssidsdddsii',
                $enquiryIdValue,
                $customerId,
                $functionTypeIdValue,
                $customerName,
                $mobile,
                $address,
                $brideName,
                $groomName,
                $venue,
                $functionDateValue,
                $functionTimeValue,
                $quotationStatusIdValue,
                $totalQty,
                $itemDetails,
                $subTotal,
                $discountAmount,
                $finalAmount,
                $remarks,
                $userId,
                $id
            );
            $stmt->execute();
            $stmt->close();

            if ($enquiryIdValue) {
                $stmt = $conn->prepare("UPDATE enquiries SET converted_to_quotation = 1, updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('ii', $userId, $enquiryIdValue);
                $stmt->execute();
                $stmt->close();
            }

            qtLog($conn, 'update', $id, 'Quotation updated.');
            apiResponse(true, 'Quotation updated successfully.', ['id' => $id]);
        }

        $quotationNo = qtNextNo($conn);

        $stmt = $conn->prepare("
            INSERT INTO quotations
                (
                    quotation_no,
                    enquiry_id,
                    customer_id,
                    function_type_id,
                    customer_name,
                    mobile,
                    address,
                    bride_name,
                    groom_name,
                    venue,
                    function_date,
                    function_time,
                    quotation_status_id,
                    total_qty,
                    item_details,
                    sub_total,
                    discount_amount,
                    final_amount,
                    remarks,
                    created_by,
                    created_at,
                    updated_at
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->bind_param(
            'siiissssssssidsdddsi',
            $quotationNo,
            $enquiryIdValue,
            $customerId,
            $functionTypeIdValue,
            $customerName,
            $mobile,
            $address,
            $brideName,
            $groomName,
            $venue,
            $functionDateValue,
            $functionTimeValue,
            $quotationStatusIdValue,
            $totalQty,
            $itemDetails,
            $subTotal,
            $discountAmount,
            $finalAmount,
            $remarks,
            $userId
        );
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();

        if ($enquiryIdValue) {
            $stmt = $conn->prepare("UPDATE enquiries SET converted_to_quotation = 1, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $userId, $enquiryIdValue);
            $stmt->execute();
            $stmt->close();
        }

        qtLog($conn, 'create_quotation', $newId, 'Quotation created: ' . $quotationNo);

        $extra = ['id' => $newId, 'quotation_no' => $quotationNo];

        if (qtWhatsappApiReady($conn)) {
            $waResult = qtSendWhatsappByApi($conn, $newId);

            if (!($waResult['success'] ?? false)) {
                apiResponse(true, 'Quotation created successfully. WhatsApp API sending failed.', array_merge($extra, [
                    'whatsapp_status' => false,
                    'whatsapp_message' => (string)($waResult['response'] ?? $waResult['message'] ?? 'WhatsApp failed.')
                ]));
            }

            apiResponse(true, 'Quotation created and WhatsApp message sent successfully.', array_merge($extra, [
                'whatsapp_status' => true
            ]));
        }

        qtWhatsappLogManual($conn, $newId);
        $row = qtGetByIdForWhatsapp($conn, $newId);
        apiResponse(true, 'Quotation created successfully. Open WhatsApp for manual sending.', array_merge($extra, [
            'manual_whatsapp' => true,
            'open_whatsapp_url' => $row ? qtWhatsappUrl($row) : ''
        ]));
    }

    if ($action === 'log_manual_whatsapp') {
        $id = qtInt($_POST['id'] ?? 0);

        if ($id <= 0) {
            apiResponse(false, 'Invalid quotation.');
        }

        $manualResult = qtWhatsappLogManual($conn, $id);
        apiResponse((bool)($manualResult['success'] ?? false), (string)($manualResult['message'] ?? ''), $manualResult);
    }

    if ($action === 'send_whatsapp_api') {
        $id = qtInt($_POST['id'] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException('Invalid quotation.');
        }

        if (!qtWhatsappApiReady($conn)) {
            $row = qtGetByIdForWhatsapp($conn, $id);
            apiResponse(false, 'WhatsApp API is not ready. Manual WhatsApp mode is active.', [
                'manual_whatsapp' => true,
                'open_whatsapp_url' => $row ? qtWhatsappUrl($row) : ''
            ]);
        }

        $waResult = qtSendWhatsappByApi($conn, $id);

        if (!($waResult['success'] ?? false)) {
            apiResponse(false, (string)($waResult['response'] ?? $waResult['message'] ?? 'WhatsApp failed.'), $waResult);
        }

        qtLog($conn, 'send_whatsapp', $id, 'Quotation WhatsApp message sent using API.');
        apiResponse(true, 'WhatsApp message sent successfully using API.', $waResult);
    }

    if (in_array($action, ['delete', 'cancel_record'], true)) {
        $id = qtInt($_POST['id'] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException('Invalid quotation.');
        }

        $cancelStatusId = qtCancelledStatusId($conn);
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($cancelStatusId) {
            $stmt = $conn->prepare("
                UPDATE quotations
                SET quotation_status_id = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('iii', $cancelStatusId, $userId, $id);
            $stmt->execute();
            $stmt->close();
        }

        qtLog($conn, 'delete', $id, 'Quotation cancelled.');
        apiResponse(true, 'Quotation cancelled successfully.', ['id' => $id]);
    }

    apiResponse(false, 'Invalid action.');
} catch (Throwable $e) {
    apiResponse(false, $e->getMessage());
}
