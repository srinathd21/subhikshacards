<?php
/**
 * api/create_proforma.php
 * Action-based API for Create Proforma Bill.
 */
header('Content-Type: application/json; charset=utf-8');
/**
 * create_proforma.php
 * Subiksha Card ERP - Create Proforma Bill / Sales Order
 *
 * Uses the current u966043993_subhiksha schema:
 * quotations -> proforma_bills -> payments -> job_cards -> job_tracking -> customer_tracking_links
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (function_exists('require_permission')) {
    require_permission($conn, 'can_create', 'proforma_bills.php');
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

function cpTableExists(mysqli $conn, string $table): bool
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


function cpColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    try {
        $tableSafe = $conn->real_escape_string($table);
        $columnSafe = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function cpPost(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function cpInt($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function cpFloat($value): float
{
    return (float)str_replace(',', '', (string)$value);
}

function cpDateOrNull(string $value): ?string
{
    return $value !== '' ? $value : null;
}

function cpTimeOrNull(string $value): ?string
{
    return $value !== '' ? $value : null;
}

function cpNextNo(mysqli $conn, string $table, string $column, string $prefix): string
{
    $datePart = date('ymd');
    $like = $prefix . '-' . $datePart . '-%';

    try {
        if (!cpTableExists($conn, $table) || !cpColumnExists($conn, $table, $column)) {
            return $prefix . '-' . $datePart . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        }

        $tableSafe = $conn->real_escape_string($table);
        $columnSafe = $conn->real_escape_string($column);
        $stmt = $conn->prepare("SELECT `{$columnSafe}` AS last_no FROM `{$tableSafe}` WHERE `{$columnSafe}` LIKE ? ORDER BY `{$columnSafe}` DESC LIMIT 1");
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $lastNo = (string)($row['last_no'] ?? '');
        $next = 1;
        if ($lastNo !== '' && preg_match('/-(\d+)$/', $lastNo, $match)) {
            $next = ((int)$match[1]) + 1;
        }

        return $prefix . '-' . $datePart . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return $prefix . '-' . $datePart . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}

function cpStatusId(mysqli $conn, string $table, array $keys): ?int
{
    if (!cpTableExists($conn, $table) || !$keys) return null;
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $types = str_repeat('s', count($keys));
    try {
        $stmt = $conn->prepare("SELECT id FROM `{$table}` WHERE status_key IN ({$placeholders}) ORDER BY sort_order ASC, id ASC LIMIT 1");
        $stmt->bind_param($types, ...$keys);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function cpRoleId(mysqli $conn, ?string $roleKey): ?int
{
    if (!$roleKey || !cpTableExists($conn, 'roles')) return null;
    try {
        $stmt = $conn->prepare("SELECT id FROM roles WHERE role_key = ? LIMIT 1");
        $stmt->bind_param('s', $roleKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function cpPrintingRoleKey(mysqli $conn, ?int $printingTypeId): ?string
{
    if (!$printingTypeId || !cpTableExists($conn, 'printing_types')) return null;
    try {
        $stmt = $conn->prepare("SELECT role_key FROM printing_types WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $printingTypeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (string)$row['role_key'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function cpCustomerId(mysqli $conn, string $customerName, string $mobile, string $address, string $gstNumber): ?int
{
    if ($customerName === '' || $mobile === '' || !cpTableExists($conn, 'customers')) return null;
    $userId = (int)($_SESSION['user_id'] ?? 0);

    try {
        $stmt = $conn->prepare("SELECT id FROM customers WHERE mobile = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('s', $mobile);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $id = (int)$row['id'];
            $stmt = $conn->prepare("UPDATE customers SET customer_name = ?, address = IF(? = '', address, ?), gst_number = IF(? = '', gst_number, ?), updated_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('sssssii', $customerName, $address, $address, $gstNumber, $gstNumber, $userId, $id);
            $stmt->execute();
            $stmt->close();
            return $id;
        }

        $stmt = $conn->prepare("INSERT INTO customers (customer_name, mobile, address, gst_number, is_active, created_by, created_at) VALUES (?, ?, ?, ?, 1, ?, NOW())");
        $stmt->bind_param('ssssi', $customerName, $mobile, $address, $gstNumber, $userId);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    } catch (Throwable $e) {
        return null;
    }
}

function cpLog(mysqli $conn, string $actionKey, string $module, string $table, int $recordId, string $description, array $newValues = []): void
{
    if (!cpTableExists($conn, 'activity_logs')) return;
    try {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        $actionTypeId = null;
        if (cpTableExists($conn, 'activity_action_types')) {
            $stmt = $conn->prepare("SELECT id FROM activity_action_types WHERE action_key = ? LIMIT 1");
            $stmt->bind_param('s', $actionKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) $actionTypeId = (int)$row['id'];
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $oldJson = null;
        $newJson = $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, role_id, action_type_id, action_key, module_name, table_name, record_id, old_values, new_values, description, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param('iiisssisssss', $userId, $roleId, $actionTypeId, $actionKey, $module, $table, $recordId, $oldJson, $newJson, $description, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Do not block business flow for logging failures.
    }
}

function cpFetchAll(mysqli $conn, string $sql): array
{
    $rows = [];
    try {
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) $rows[] = $row;
            $res->free();
        }
    } catch (Throwable $e) {
    }
    return $rows;
}

if (empty($_SESSION['create_proforma_csrf'])) {
    $_SESSION['create_proforma_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['create_proforma_csrf'];

$message = '';
$messageType = 'success';
$createdProformaNo = '';
$createdJobCardNo = '';


function cpApiResponse(bool $status, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge([
        'status' => $status,
        'success' => $status,
        'message' => $message
    ], $extra));
    exit;
}

function cpSettingValue(mysqli $conn, string $key, string $default = ''): string
{
    try {
        if (!cpTableExists($conn, 'system_settings')) return $default;

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

function cpWhatsappMobile($mobile): string
{
    $mobile = preg_replace('/\D+/', '', (string)$mobile);
    if ($mobile === '') return '';
    return strlen($mobile) === 10 ? '91' . $mobile : $mobile;
}

function cpWhatsappApiReady(mysqli $conn): bool
{
    $enabled = cpSettingValue($conn, 'whatsapp_enabled', '0');
    $apiUrl = cpSettingValue($conn, 'watzup_api_url', '');
    $apiToken = cpSettingValue($conn, 'watzup_api_token', '');
    $senderId = cpSettingValue($conn, 'watzup_sender_id', '');

    if ($enabled !== '1') return false;

    $dummyValues = [
        '',
        'https://your-whatsapp-provider-url/send-message',
        'PASTE_YOUR_SECRET_KEY_HERE',
        'PASTE_YOUR_UNIQUE_ID_HERE',
        'YOUR_REAL_API_URL',
        'YOUR_REAL_SECRET_KEY',
        'YOUR_REAL_UNIQUE_ID_OR_ACCOUNT_ID'
    ];

    if (in_array($apiUrl, $dummyValues, true) || in_array($apiToken, $dummyValues, true) || in_array($senderId, $dummyValues, true)) {
        return false;
    }

    return filter_var($apiUrl, FILTER_VALIDATE_URL) !== false;
}

function cpGetProformaWhatsappRow(mysqli $conn, int $id): ?array
{
    if ($id <= 0 || !cpTableExists($conn, 'proforma_bills')) return null;

    try {
        $stmt = $conn->prepare("
            SELECT
                pb.*,
                ft.function_name,
                ps.status_name,
                pbi.item_name,
                pbi.description,
                pbi.qty,
                pbi.rate,
                pbi.amount,
                pbi.printing_type_id,
                pbi.printing_sub_type_id,
                pbi.finishing_required,
                pbi.size_text,
                pbi.gsm_thickness,
                pbi.lamination_required,
                pbi.lamination_type,
                pbi.printing_side,
                pbi.screening_type,
                pt.printing_name,
                pst.sub_type_name,
                jc.id AS job_card_id,
                jc.job_card_no,
                jc.tracking_token
            FROM proforma_bills pb
            LEFT JOIN function_types ft ON ft.id = pb.function_type_id
            LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id
            LEFT JOIN proforma_bill_items pbi ON pbi.proforma_bill_id = pb.id
            LEFT JOIN printing_types pt ON pt.id = pbi.printing_type_id
            LEFT JOIN printing_sub_types pst ON pst.id = pbi.printing_sub_type_id
            LEFT JOIN job_cards jc ON jc.proforma_bill_id = pb.id
            WHERE pb.id = ?
            ORDER BY pbi.sort_order ASC, pbi.id ASC
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


function cpBaseUrl(mysqli $conn): string
{
    $setting = '';
    try {
        if (cpTableExists($conn, 'system_settings')) {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key IN ('site_url','base_url','app_url') AND TRIM(setting_value) <> '' ORDER BY FIELD(setting_key,'site_url','base_url','app_url') LIMIT 1");
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $setting = trim((string)($row['setting_value'] ?? ''));
        }
    } catch (Throwable $e) {}

    if ($setting !== '') return rtrim($setting, '/');

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim($dir, '/');
    if (substr($dir, -4) === '/api') {
        $dir = substr($dir, 0, -4);
    }
    if ($dir === '/' || $dir === '.') $dir = '';

    return rtrim($scheme . '://' . $host . $dir, '/');
}

function cpCustomerTrackingUrl(mysqli $conn, array $row): string
{
    $token = trim((string)($row['tracking_token'] ?? ''));
    if ($token === '') return '';
    return cpBaseUrl($conn) . '/customer_tracking.php?token=' . rawurlencode($token);
}

function cpEnsureCustomerTrackingLinksTable(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS customer_tracking_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_card_id BIGINT UNSIGNED NOT NULL,
            tracking_token VARCHAR(120) NOT NULL,
            mobile VARCHAR(30) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            expires_at DATETIME DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_tracking_token (tracking_token),
            KEY idx_job_card_id (job_card_id),
            KEY idx_mobile (mobile),
            KEY idx_active_expiry (is_active, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function cpWhatsappMessage(array $row): string
{
    global $conn;

    $customerName = trim((string)($row['customer_name'] ?? 'Customer'));
    $proformaNo = trim((string)($row['proforma_no'] ?? '-'));
    $jobCardNo = trim((string)($row['job_card_no'] ?? '-'));
    $orderType = ucfirst((string)($row['order_type'] ?? '-'));
    $productName = trim((string)($row['item_name'] ?? '-'));
    $qty = number_format((float)($row['total_qty'] ?? 0), 0);
    $finalAmount = '₹' . number_format((float)($row['final_amount'] ?? 0), 2);
    $advance = '₹' . number_format((float)($row['advance_amount'] ?? 0), 2);
    $balance = '₹' . number_format((float)($row['balance_amount'] ?? 0), 2);
    $delivery = !empty($row['delivery_date']) ? date('d-m-Y', strtotime($row['delivery_date'])) : '-';
    $trackingLink = ($conn instanceof mysqli) ? cpCustomerTrackingUrl($conn, $row) : '';

    return "Hi {$customerName},\n\n"
        . "Greetings from Subhiksha Cards.\n\n"
        . "Your proforma bill / sales order has been created successfully.\n\n"
        . "Proforma No: {$proformaNo}\n"
        . "Job Card No: {$jobCardNo}\n"
        . "Order Type: {$orderType}\n"
        . "Product: {$productName}\n"
        . "Quantity: {$qty}\n"
        . "Final Amount: {$finalAmount}\n"
        . "Advance Paid: {$advance}\n"
        . "Balance Amount: {$balance}\n"
        . "Expected Delivery: {$delivery}\n\n"
        . "Track your order live here:\n"
        . ($trackingLink !== '' ? $trackingLink : 'Tracking link will be shared shortly.') . "\n\n"
        . "The tracking page shows each production stage one by one like shipment tracking.\n\n"
        . "Thank you,\n"
        . "Subhiksha Cards Team";
}

function cpWhatsappUrl(array $row): string
{
    $mobile = cpWhatsappMobile($row['mobile'] ?? '');
    if ($mobile === '') return '';
    return 'https://wa.me/' . $mobile . '?text=' . rawurlencode(cpWhatsappMessage($row));
}

function cpWhatsappTemplateId(mysqli $conn, string $templateKey): ?int
{
    try {
        if (!cpTableExists($conn, 'whatsapp_templates')) return null;

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

function cpWhatsappLogManual(mysqli $conn, int $id): array
{
    $row = cpGetProformaWhatsappRow($conn, $id);

    if (!$row) return ['success' => false, 'message' => 'Proforma bill not found.'];

    $mobile = cpWhatsappMobile($row['mobile'] ?? '');
    if ($mobile === '') return ['success' => false, 'message' => 'Customer mobile number is missing.'];

    if (!cpTableExists($conn, 'whatsapp_logs')) {
        return ['success' => true, 'message' => 'Manual WhatsApp opened. whatsapp_logs table missing, so log not saved.'];
    }

    try {
        $templateId = cpWhatsappTemplateId($conn, 'proforma_created');
        $relatedModule = 'Proforma Bills';
        $relatedId = $id;
        $customerId = !empty($row['customer_id']) ? (int)$row['customer_id'] : null;
        $jobCardId = !empty($row['job_card_id']) ? (int)$row['job_card_id'] : null;
        $messageBody = cpWhatsappMessage($row);
        $status = 'sent';
        $providerResponse = json_encode([
            'mode' => 'manual',
            'status' => 'opened',
            'message' => 'Manual WhatsApp Web/App opened automatically after proforma creation.'
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

function cpSendWhatsappByApi(mysqli $conn, int $id): array
{
    $apiFile = __DIR__ . '/../includes/whatsapp-api.php';

    if (!file_exists($apiFile)) {
        return ['success' => false, 'message' => 'WhatsApp API file missing.'];
    }

    require_once $apiFile;

    if (!function_exists('subhiksha_send_whatsapp')) {
        return ['success' => false, 'message' => 'WhatsApp API function missing.'];
    }

    $row = cpGetProformaWhatsappRow($conn, $id);
    if (!$row) return ['success' => false, 'message' => 'Proforma bill not found.'];

    return subhiksha_send_whatsapp($conn, [
        'mobile' => (string)($row['mobile'] ?? ''),
        'message' => cpWhatsappMessage($row),
        'related_module' => 'Proforma Bills',
        'related_id' => $id,
        'customer_id' => $row['customer_id'] ?? null,
        'job_card_id' => $row['job_card_id'] ?? null
    ]);
}

function cpAutoWhatsappAfterCreate(mysqli $conn, int $proformaId): array
{
    $row = cpGetProformaWhatsappRow($conn, $proformaId);

    if (!$row) {
        return [
            'mode' => 'none',
            'success' => false,
            'message' => 'Proforma created, but WhatsApp row was not found.',
            'open_whatsapp_url' => ''
        ];
    }

    $manualUrl = cpWhatsappUrl($row);

    if (cpWhatsappApiReady($conn)) {
        $apiResult = cpSendWhatsappByApi($conn, $proformaId);

        if (($apiResult['success'] ?? false) === true) {
            return [
                'mode' => 'api',
                'success' => true,
                'message' => 'WhatsApp message sent automatically using API.',
                'open_whatsapp_url' => '',
                'provider_response' => $apiResult
            ];
        }

        return [
            'mode' => 'manual',
            'success' => false,
            'message' => 'API WhatsApp failed. Manual WhatsApp will open.',
            'open_whatsapp_url' => $manualUrl,
            'provider_response' => $apiResult
        ];
    }

    $manualResult = cpWhatsappLogManual($conn, $proformaId);

    return [
        'mode' => 'manual',
        'success' => (bool)($manualResult['success'] ?? false),
        'message' => 'Manual WhatsApp will open automatically.',
        'open_whatsapp_url' => $manualUrl,
        'provider_response' => $manualResult
    ];
}


try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        cpApiResponse(false, 'Invalid request method.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['create_proforma_csrf'], (string)$_POST['csrf_token'])) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        foreach (['proforma_bills', 'proforma_bill_items'] as $requiredTable) {
            if (!cpTableExists($conn, $requiredTable)) {
                throw new RuntimeException($requiredTable . ' table is missing.');
            }
        }

        $quotationId = cpInt($_POST['quotation_id'] ?? 0);
        $orderType = cpPost('order_type');
        $customerName = cpPost('customer_name');
        $mobile = cpPost('mobile');
        $billingName = cpPost('billing_name');
        $billingMobile = cpPost('billing_mobile');
        $billingAddress = cpPost('billing_address');
        $gstNumber = cpPost('gst_number');
        $functionTypeId = cpInt($_POST['function_type_id'] ?? 0);
        $brideName = cpPost('bride_name');
        $groomName = cpPost('groom_name');
        $venue = cpPost('venue');
        $functionDate = cpDateOrNull(cpPost('function_date'));
        $functionTime = cpTimeOrNull(cpPost('function_time'));
        $productId = cpInt($_POST['product_id'] ?? 0);
        $productName = cpPost('product_name');
        $description = cpPost('description');
        $qty = cpFloat($_POST['qty'] ?? 0);
        $rate = cpFloat($_POST['rate'] ?? 0);
        $discountAmount = cpFloat($_POST['discount_amount'] ?? 0);
        $advanceAmount = cpFloat($_POST['advance_amount'] ?? 0);
        $paymentMode = cpPost('payment_mode', 'cash');
        $paymentReference = cpPost('payment_reference');
        $deliveryDate = cpDateOrNull(cpPost('delivery_date'));
        $remarks = cpPost('remarks');
        $printingTypeId = cpInt($_POST['printing_type_id'] ?? 0);
        $printingSubTypeId = cpInt($_POST['printing_sub_type_id'] ?? 0);
        $finishingRequired = isset($_POST['finishing_required']) ? 1 : 0;
        $sizeText = cpPost('size_text');
        $gsmThickness = cpPost('gsm_thickness');
        $laminationRequired = isset($_POST['lamination_required']) ? 1 : 0;
        $laminationType = cpPost('lamination_type');
        $printingSide = cpPost('printing_side');
        $screeningType = cpPost('screening_type');
        $autoCreateJobCard = 1; // Job card must always be created after saving proforma bill.
        $createTrackingLink = 1; // Always create customer tracking link after proforma/job card creation.

        if ($autoCreateJobCard) {
            foreach (['job_cards', 'job_card_items', 'job_tracking'] as $requiredTable) {
                if (!cpTableExists($conn, $requiredTable)) {
                    throw new RuntimeException($requiredTable . ' table is missing.');
                }
            }
        }

        if (!in_array($orderType, ['readymade', 'customized'], true)) throw new RuntimeException('Order type is required.');
        if ($customerName === '') throw new RuntimeException('Customer name is required.');
        if ($mobile === '') throw new RuntimeException('Mobile number is required.');
        if ($productName === '') throw new RuntimeException('Product / item name is required.');
        if ($qty <= 0) throw new RuntimeException('Quantity must be greater than zero.');
        if ($rate <= 0) throw new RuntimeException('Rate must be greater than zero.');
        if ($printingTypeId <= 0) throw new RuntimeException('Printing type is required.');
        if ($orderType === 'readymade' && $deliveryDate === null) throw new RuntimeException('Delivery date is required.');
        if ($orderType === 'customized') {
            if ($sizeText === '') throw new RuntimeException('Size is required for customized order.');
            if ($gsmThickness === '') throw new RuntimeException('GSM thickness is required for customized order.');
            if (!in_array($printingSide, ['single', 'double'], true)) throw new RuntimeException('Printing side is required for customized order.');
            if (!in_array($screeningType, ['regular', 'special'], true)) throw new RuntimeException('Screening type is required for customized order.');
            $printingSubTypeId = 0;
            $finishingRequired = 0;
        } else {
            $sizeText = '';
            $gsmThickness = '';
            $laminationRequired = 0;
            $laminationType = '';
            $printingSide = '';
            $screeningType = '';
        }

        if ($laminationRequired && !in_array($laminationType, ['glossy', 'matte', 'special'], true)) {
            throw new RuntimeException('Lamination type is required.');
        }
        if (!$laminationRequired) $laminationType = '';

        $subTotal = round($qty * $rate, 2);
        if ($discountAmount < 0) throw new RuntimeException('Discount cannot be negative.');
        if ($discountAmount > $subTotal) throw new RuntimeException('Discount cannot be greater than subtotal.');
        $finalAmount = round($subTotal - $discountAmount, 2);
        if ($advanceAmount < 0) throw new RuntimeException('Advance amount cannot be negative.');
        if ($advanceAmount > $finalAmount) throw new RuntimeException('Advance amount cannot be greater than final amount.');
        $balanceAmount = round($finalAmount - $advanceAmount, 2);

        $quotation = null;
        $enquiryIdValue = null;
        if ($quotationId > 0) {
            $stmt = $conn->prepare("SELECT * FROM quotations WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $quotationId);
            $stmt->execute();
            $quotation = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$quotation) throw new RuntimeException('Selected quotation not found.');
            $enquiryIdValue = !empty($quotation['enquiry_id']) ? (int)$quotation['enquiry_id'] : null;
        }

        $billingName = $billingName !== '' ? $billingName : $customerName;
        $billingMobile = $billingMobile !== '' ? $billingMobile : $mobile;
        $customerId = cpCustomerId($conn, $customerName, $mobile, $billingAddress, $gstNumber);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $proformaStatusId = cpStatusId($conn, 'proforma_statuses', ['job_card_created', 'confirmed', 'draft']);
        $jobStatusId = cpStatusId($conn, 'job_card_statuses', ['in_progress', 'pending']);
        $quotationConvertedStatusId = cpStatusId($conn, 'quotation_statuses', ['converted_to_proforma_bill']);
        $proformaNo = cpNextNo($conn, 'proforma_bills', 'proforma_no', 'SC-PRO');
        $jobCardNo = cpNextNo($conn, 'job_cards', 'job_card_no', 'SC-JOB');
        $paymentNo = cpNextNo($conn, 'payments', 'payment_no', 'SC-PAY');
        $trackingToken = bin2hex(random_bytes(24));
        $productIdValue = $productId > 0 ? $productId : null;
        $printingSubTypeValue = $printingSubTypeId > 0 ? $printingSubTypeId : null;
        $functionTypeValue = $functionTypeId > 0 ? $functionTypeId : null;
        $quotationIdValue = $quotationId > 0 ? $quotationId : null;
        $customerIdValue = $customerId ?: null;
        $laminationTypeValue = $laminationType !== '' ? $laminationType : null;
        $printingSideValue = $printingSide !== '' ? $printingSide : null;
        $screeningTypeValue = $screeningType !== '' ? $screeningType : null;

        $conn->begin_transaction();

        $stmt = $conn->prepare("INSERT INTO proforma_bills (proforma_no, quotation_id, enquiry_id, customer_id, function_type_id, order_type, customer_name, mobile, billing_name, billing_mobile, billing_address, gst_number, bride_name, groom_name, venue, function_date, function_time, proforma_status_id, total_qty, sub_total, discount_amount, final_amount, advance_amount, balance_amount, delivery_date, remarks, job_card_created, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param('siiiissssssssssssiddddddssii', $proformaNo, $quotationIdValue, $enquiryIdValue, $customerIdValue, $functionTypeValue, $orderType, $customerName, $mobile, $billingName, $billingMobile, $billingAddress, $gstNumber, $brideName, $groomName, $venue, $functionDate, $functionTime, $proformaStatusId, $qty, $subTotal, $discountAmount, $finalAmount, $advanceAmount, $balanceAmount, $deliveryDate, $remarks, $autoCreateJobCard, $userId);
        $stmt->execute();
        $proformaId = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO proforma_bill_items (proforma_bill_id, product_id, item_name, description, qty, rate, amount, printing_type_id, printing_sub_type_id, finishing_required, size_text, gsm_thickness, lamination_required, lamination_type, printing_side, screening_type, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
        $stmt->bind_param('iissdddiiississs', $proformaId, $productIdValue, $productName, $description, $qty, $rate, $subTotal, $printingTypeId, $printingSubTypeValue, $finishingRequired, $sizeText, $gsmThickness, $laminationRequired, $laminationTypeValue, $printingSideValue, $screeningTypeValue);
        $stmt->execute();
        $stmt->close();

        if ($advanceAmount > 0 && cpTableExists($conn, 'payments')) {
            $paymentDate = date('Y-m-d');
            $payRemarks = 'Advance collected from proforma bill';
            $paymentType = $advanceAmount >= $finalAmount ? 'full' : 'advance';
            $stmt = $conn->prepare("INSERT INTO payments (customer_id, proforma_bill_id, payment_no, payment_type, payment_mode, amount, payment_date, reference_no, remarks, received_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param('iisssdsssi', $customerIdValue, $proformaId, $paymentNo, $paymentType, $paymentMode, $advanceAmount, $paymentDate, $paymentReference, $payRemarks, $userId);
            $stmt->execute();
            $paymentId = (int)$stmt->insert_id;
            $stmt->close();
            cpLog($conn, 'collect_payment', 'Payments', 'payments', $paymentId, 'Advance payment collected.', ['proforma_no' => $proformaNo, 'amount' => $advanceAmount]);
        }

        $jobCardId = 0;
        if ($autoCreateJobCard) {
            $steps = cpFetchAll($conn, "SELECT id, step_key, step_name, default_owner_role_key, sort_order FROM workflow_steps WHERE order_type = '" . $conn->real_escape_string($orderType) . "' AND is_active = 1 ORDER BY sort_order ASC, id ASC");
            if (!$steps) throw new RuntimeException('Workflow steps are missing for ' . $orderType . ' order.');

            $currentStepId = null;
            foreach ($steps as $step) {
                if ((int)$step['sort_order'] === 3) {
                    $currentStepId = (int)$step['id'];
                    break;
                }
            }
            if (!$currentStepId) $currentStepId = (int)$steps[0]['id'];

            $printingRoleKey = cpPrintingRoleKey($conn, $printingTypeId);
            $assignedPrintingRoleId = cpRoleId($conn, $orderType === 'customized' ? 'multicolor_offset_printing' : $printingRoleKey);

            $stmt = $conn->prepare("INSERT INTO job_cards (job_card_no, tracking_token, enquiry_id, quotation_id, proforma_bill_id, customer_id, order_type, customer_name, mobile, function_type_id, product_id, product_name, printing_type_id, printing_sub_type_id, assigned_sales_user_id, assigned_design_user_id, assigned_printing_role_id, assigned_printing_user_id, job_card_status_id, current_workflow_step_id, final_amount, advance_amount, balance_amount, delivery_date, is_delayed, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())");
            $stmt->bind_param('ssiiiisssiisiiiiiddddsi', $jobCardNo, $trackingToken, $enquiryIdValue, $quotationIdValue, $proformaId, $customerIdValue, $orderType, $customerName, $mobile, $functionTypeValue, $productIdValue, $productName, $printingTypeId, $printingSubTypeValue, $userId, $assignedPrintingRoleId, $jobStatusId, $currentStepId, $finalAmount, $advanceAmount, $balanceAmount, $deliveryDate, $userId);
            $stmt->execute();
            $jobCardId = (int)$stmt->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO job_card_items (job_card_id, product_id, item_name, description, qty, rate, amount, size_text, gsm_thickness, lamination_required, lamination_type, printing_side, screening_type, finishing_required, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param('iissdddssisssi', $jobCardId, $productIdValue, $productName, $description, $qty, $rate, $subTotal, $sizeText, $gsmThickness, $laminationRequired, $laminationTypeValue, $printingSideValue, $screeningTypeValue, $finishingRequired);
            $stmt->execute();
            $stmt->close();

            foreach ($steps as $step) {
                $sort = (int)$step['sort_order'];
                $status = 'pending';
                $actualStart = null;
                $actualCompleted = null;
                $completedBy = null;
                if ($sort <= 2) {
                    $status = 'completed';
                    $actualStart = date('Y-m-d H:i:s');
                    $actualCompleted = date('Y-m-d H:i:s');
                    $completedBy = $userId;
                } elseif ($sort === 3) {
                    $status = 'in_progress';
                    $actualStart = date('Y-m-d H:i:s');
                }

                $ownerKey = (string)($step['default_owner_role_key'] ?? '');
                if ($ownerKey === 'printing') $ownerKey = $printingRoleKey ?: 'printing';
                $responsibleRoleId = cpRoleId($conn, $ownerKey);
                $stepId = (int)$step['id'];

                $plannedStart = null;
                $plannedCompletion = null;
                if (!empty($_POST['planned_step'][$stepId]['start'])) {
                    $plannedStart = $_POST['planned_step'][$stepId]['start'];
                }
                if (!empty($_POST['planned_step'][$stepId]['completion'])) {
                    $plannedCompletion = $_POST['planned_step'][$stepId]['completion'];
                }

                $stmt = $conn->prepare("INSERT INTO job_tracking (job_card_id, workflow_step_id, planned_start_date, planned_completion_date, actual_start_at, actual_completed_at, status, responsible_role_id, responsible_user_id, is_delayed, delay_days, completed_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 0, 0, ?, NOW(), NOW())");
                $stmt->bind_param('iisssssii', $jobCardId, $stepId, $plannedStart, $plannedCompletion, $actualStart, $actualCompleted, $status, $responsibleRoleId, $completedBy);
                $stmt->execute();
                $trackingId = (int)$stmt->insert_id;
                $stmt->close();

                if (cpTableExists($conn, 'job_tracking_history')) {
                    $historyRemarks = $sort <= 2 ? 'Auto-completed during proforma/job card creation.' : ($sort === 3 ? 'Started after job card creation.' : 'Pending after job card creation.');
                    $stmt = $conn->prepare("INSERT INTO job_tracking_history (job_tracking_id, job_card_id, workflow_step_id, old_status, new_status, action_remarks, changed_by, changed_at) VALUES (?, ?, ?, NULL, ?, ?, ?, NOW())");
                    $stmt->bind_param('iiissi', $trackingId, $jobCardId, $stepId, $status, $historyRemarks, $userId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if ($createTrackingLink) {
                cpEnsureCustomerTrackingLinksTable($conn);
                $expiresAt = $deliveryDate ? date('Y-m-d 23:59:59', strtotime($deliveryDate . ' +15 days')) : null;
                $trackingHasUpdatedAt = cpColumnExists($conn, 'customer_tracking_links', 'updated_at');

                if ($trackingHasUpdatedAt) {
                    $stmt = $conn->prepare("
                        INSERT INTO customer_tracking_links
                            (job_card_id, tracking_token, mobile, is_active, expires_at, created_by, created_at)
                        VALUES
                            (?, ?, ?, 1, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                            job_card_id = VALUES(job_card_id),
                            mobile = VALUES(mobile),
                            is_active = 1,
                            expires_at = VALUES(expires_at),
                            updated_at = NOW()
                    ");
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO customer_tracking_links
                            (job_card_id, tracking_token, mobile, is_active, expires_at, created_by, created_at)
                        VALUES
                            (?, ?, ?, 1, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                            job_card_id = VALUES(job_card_id),
                            mobile = VALUES(mobile),
                            is_active = 1,
                            expires_at = VALUES(expires_at)
                    ");
                }

                $stmt->bind_param('isssi', $jobCardId, $trackingToken, $mobile, $expiresAt, $userId);
                $stmt->execute();
                $stmt->close();
            }

            cpLog($conn, 'create_job_card', 'Proforma Bills', 'job_cards', $jobCardId, 'Job card created from proforma bill: ' . $jobCardNo, ['proforma_no' => $proformaNo, 'job_card_no' => $jobCardNo]);
        }

        if ($quotationConvertedStatusId && $quotationIdValue) {
            $stmt = $conn->prepare("UPDATE quotations SET quotation_status_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('iii', $quotationConvertedStatusId, $userId, $quotationIdValue);
            $stmt->execute();
            $stmt->close();
        }

        if ($enquiryIdValue) {
            $stmt = $conn->prepare("UPDATE enquiries SET converted_to_order = 1, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $userId, $enquiryIdValue);
            $stmt->execute();
            $stmt->close();
        }

        cpLog($conn, 'create_proforma_bill', 'Proforma Bills', 'proforma_bills', $proformaId, 'Proforma bill created.', ['proforma_no' => $proformaNo, 'quotation_id' => $quotationIdValue, 'final_amount' => $finalAmount]);

        $conn->commit();
        $whatsappResult = cpAutoWhatsappAfterCreate($conn, $proformaId);

        $_SESSION['create_proforma_csrf'] = bin2hex(random_bytes(32));

        $redirectUrl = 'proforma_bills.php?msg=created&proforma_no=' . urlencode($proformaNo) . ($autoCreateJobCard ? '&job_card_no=' . urlencode($jobCardNo) : '');

        cpApiResponse(true, 'Proforma bill created successfully.' . ($autoCreateJobCard ? ' Job card and tracking steps were also created.' : ''), [
            'proforma_id' => $proformaId,
            'proforma_no' => $proformaNo,
            'job_card_no' => $autoCreateJobCard ? $jobCardNo : '',
            'redirect_url' => $redirectUrl,
            'whatsapp_mode' => $whatsappResult['mode'] ?? 'none',
            'whatsapp_success' => $whatsappResult['success'] ?? false,
            'whatsapp_message' => $whatsappResult['message'] ?? '',
            'open_whatsapp_url' => $whatsappResult['open_whatsapp_url'] ?? ''
        ]);
    }
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
    }

    cpApiResponse(false, $e->getMessage());
}

cpApiResponse(false, 'Unable to process proforma bill.');
