<?php
/**
 * api/proforma_whatsapp_send.php
 *
 * Sends the approved Meta template `proforma_created` immediately after a
 * newly created Proforma Bill has been committed. Repeated calls are safe:
 * an existing successful WhatsApp log prevents a duplicate message.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function pcw_response(bool $status, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'status' => $status,
        'success' => $status,
        'message' => $message
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pcw_table_exists(mysqli $conn, string $table): bool
{
    try {
        $safe = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}

function pcw_permission_allowed(mysqli $conn): bool
{
    $roleKey = strtolower(trim((string)(
        $_SESSION['role_key']
        ?? $_SESSION['role']
        ?? $_SESSION['user_role']
        ?? ''
    )));

    if (in_array($roleKey, ['admin', 'super_admin', 'superadmin'], true)) {
        return true;
    }

    foreach (['can_create', 'can_update', 'can_edit', 'can_send_whatsapp'] as $functionName) {
        if (!function_exists($functionName)) {
            continue;
        }

        try {
            if ((bool)$functionName($conn, 'proforma_bills.php')) {
                return true;
            }
        } catch (ArgumentCountError $e) {
            try {
                if ((bool)$functionName('proforma_bills.php')) {
                    return true;
                }
            } catch (Throwable $inner) {
            }
        } catch (Throwable $e) {
        }
    }

    return false;
}

function pcw_setting(mysqli $conn, string $key): string
{
    if (!pcw_table_exists($conn, 'system_settings')) {
        return '';
    }

    try {
        $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return trim((string)($row['setting_value'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

function pcw_base_url(mysqli $conn): string
{
    foreach (['site_url', 'base_url', 'app_url'] as $key) {
        $configured = pcw_setting($conn, $key);
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptDirectory = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    $erpDirectory = preg_replace('#/api$#', '', $scriptDirectory) ?: '';

    return rtrim($scheme . '://' . $host . ($erpDirectory === '/' ? '' : $erpDirectory), '/');
}

function pcw_money($value): string
{
    return '₹' . number_format((float)$value, 2, '.', ',');
}

function pcw_already_sent(mysqli $conn, int $proformaId): bool
{
    if (!pcw_table_exists($conn, 'whatsapp_logs') || !pcw_table_exists($conn, 'whatsapp_templates')) {
        return false;
    }

    try {
        $stmt = $conn->prepare("
            SELECT wl.id
            FROM whatsapp_logs wl
            INNER JOIN whatsapp_templates wt ON wt.id = wl.template_id
            WHERE wl.related_module = 'Proforma Bills'
              AND wl.related_id = ?
              AND wl.status = 'sent'
              AND wt.template_key = 'proforma_created'
            ORDER BY wl.id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $proformaId);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pcw_response(false, 'Invalid request method.');
}

if (!pcw_permission_allowed($conn)) {
    http_response_code(403);
    pcw_response(false, 'You do not have permission to send the Proforma WhatsApp message.');
}

$csrfToken = trim((string)($_POST['csrf_token'] ?? ''));
$validCsrf = $csrfToken !== '' && (
    (!empty($_SESSION['create_proforma_csrf'])
        && hash_equals((string)$_SESSION['create_proforma_csrf'], $csrfToken))
    || (!empty($_SESSION['proforma_csrf'])
        && hash_equals((string)$_SESSION['proforma_csrf'], $csrfToken))
);

if (!$validCsrf) {
    http_response_code(419);
    pcw_response(false, 'Invalid CSRF token.');
}

$action = trim((string)($_POST['action'] ?? ''));
if (!in_array($action, ['send_proforma_whatsapp', 'send_whatsapp_api'], true)) {
    pcw_response(false, 'Invalid WhatsApp action.');
}

$proformaId = (int)($_POST['id'] ?? $_POST['proforma_id'] ?? 0);
if ($proformaId <= 0) {
    pcw_response(false, 'Invalid Proforma Bill.');
}

if (pcw_already_sent($conn, $proformaId)) {
    pcw_response(true, 'The proforma_created WhatsApp template was already sent.', [
        'already_sent' => true,
        'template' => 'proforma_created',
        'template_key' => 'proforma_created',
        'proforma_id' => $proformaId,
        'id' => $proformaId,
        'mode' => 'api'
    ]);
}

try {
    $stmt = $conn->prepare("
        SELECT
            pb.id,
            pb.proforma_no,
            pb.customer_id,
            pb.customer_name,
            pb.mobile,
            pb.final_amount,
            pb.advance_amount,
            pb.balance_amount,
            pb.delivery_date,
            COALESCE((
                SELECT pbi.item_name
                FROM proforma_bill_items pbi
                WHERE pbi.proforma_bill_id = pb.id
                ORDER BY pbi.sort_order ASC, pbi.id ASC
                LIMIT 1
            ), 'Invitation Cards') AS product_name
        FROM proforma_bills pb
        WHERE pb.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $proformaId);
    $stmt->execute();
    $proforma = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $e) {
    pcw_response(false, 'Unable to load the created Proforma Bill.');
}

if (empty($proforma)) {
    pcw_response(false, 'Created Proforma Bill not found.');
}

$mobile = trim((string)($proforma['mobile'] ?? ''));
if ($mobile === '') {
    pcw_response(false, 'Customer mobile number is missing.');
}

$whatsappApiFile = __DIR__ . '/../includes/whatsapp-api.php';
if (!is_file($whatsappApiFile)) {
    pcw_response(false, 'WhatsApp API file is missing.');
}

require_once $whatsappApiFile;

if (!function_exists('subhiksha_send_template_whatsapp')) {
    pcw_response(false, 'WhatsApp template API function is missing.');
}

$pdfUrl = pcw_base_url($conn)
    . '/proforma_bill_pdf.php?public=1&download=1&id=' . $proformaId;

$variables = [
    'customer_name' => trim((string)($proforma['customer_name'] ?? 'Customer')) ?: 'Customer',
    'proforma_no' => trim((string)($proforma['proforma_no'] ?? '-')) ?: '-',
    'product_name' => trim((string)($proforma['product_name'] ?? 'Invitation Cards')) ?: 'Invitation Cards',
    'final_amount' => pcw_money($proforma['final_amount'] ?? 0),
    'advance_amount' => pcw_money($proforma['advance_amount'] ?? 0),
    'balance_amount' => pcw_money($proforma['balance_amount'] ?? 0),
    'delivery_date' => !empty($proforma['delivery_date'])
        ? date('d-m-Y', strtotime((string)$proforma['delivery_date']))
        : '-',
    'proforma_pdf_link' => $pdfUrl
];

$meta = [
    'related_module' => 'Proforma Bills',
    'related_id' => $proformaId,
    'customer_id' => !empty($proforma['customer_id']) ? (int)$proforma['customer_id'] : null,
    'sent_by' => (int)($_SESSION['user_id'] ?? 0),
    'language_code' => 'en',
    'extra_payload' => [
        'type' => 'text',
        'proforma_pdf_link' => $pdfUrl
    ]
];

$result = subhiksha_send_template_whatsapp(
    $conn,
    'proforma_created',
    $mobile,
    $variables,
    $meta
);

$sent = (bool)($result['success'] ?? false);
pcw_response($sent, $sent
    ? 'Proforma created WhatsApp template sent successfully.'
    : (string)($result['message'] ?? 'WhatsApp template sending failed.'), [
        'template' => 'proforma_created',
        'template_key' => 'proforma_created',
        'proforma_id' => $proformaId,
        'id' => $proformaId,
        'proforma_pdf_link' => $pdfUrl,
        'pdf_url' => $pdfUrl,
        'whatsapp_sent' => $sent,
        'mode' => 'api',
        'log_id' => (int)($result['log_id'] ?? 0)
    ]);