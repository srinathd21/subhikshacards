<?php
/**
 * api/proforma_whatsapp_send.php
 * Sends Proforma Bill PDF link through configured WhatsApp API using existing WhatsApp template.
 * No wa.me/manual browser fallback is used here.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/whatsapp-api.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function pws_json(bool $status, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pws_table_exists(mysqli $conn, string $table): bool
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

function pws_money($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function pws_base_url(mysqli $conn): string
{
    $setting = '';

    try {
        if (pws_table_exists($conn, 'system_settings')) {
            $stmt = $conn->prepare("
                SELECT setting_value
                FROM system_settings
                WHERE setting_key IN ('site_url','base_url','app_url')
                  AND TRIM(setting_value) <> ''
                ORDER BY FIELD(setting_key,'site_url','base_url','app_url')
                LIMIT 1
            ");
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $setting = trim((string)($row['setting_value'] ?? ''));
        }
    } catch (Throwable $e) {
        $setting = '';
    }

    if ($setting !== '') {
        return rtrim($setting, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    /*
     * Current file is /erp/api/proforma_whatsapp_send.php,
     * so go one level up to /erp.
     */
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $erpDir = rtrim(dirname($scriptDir), '/');

    if ($erpDir === '/' || $erpDir === '.') {
        $erpDir = '';
    }

    return rtrim($scheme . '://' . $host . $erpDir, '/');
}

function pws_public_pdf_url(mysqli $conn, int $id): string
{
    return pws_base_url($conn)
        . '/proforma_bill_pdf.php?id='
        . $id
        . '&public=1&download=1';
}

function pws_customer_preview_url(mysqli $conn, int $id): string
{
    return pws_base_url($conn) . '/customer_proforma_bill.php?id=' . $id;
}

function pws_fetch_proforma(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("
        SELECT
            pb.id,
            pb.proforma_no,
            pb.customer_id,
            pb.customer_name,
            pb.mobile,
            pb.order_type,
            pb.final_amount,
            pb.advance_amount,
            pb.balance_amount,
            pb.delivery_date,
            ft.function_name,
            pbi.item_name
        FROM proforma_bills pb
        LEFT JOIN function_types ft ON ft.id = pb.function_type_id
        LEFT JOIN (
            SELECT proforma_bill_id, MIN(id) AS first_item_id
            FROM proforma_bill_items
            GROUP BY proforma_bill_id
        ) first_pbi ON first_pbi.proforma_bill_id = pb.id
        LEFT JOIN proforma_bill_items pbi ON pbi.id = first_pbi.first_item_id
        WHERE pb.id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pws_json(false, 'Invalid request method.');
    }

    if (function_exists('require_permission')) {
        require_permission($conn, 'can_view', 'proforma_bills.php');
    }

    if (
        function_exists('can_send_whatsapp')
        && !can_send_whatsapp($conn, 'proforma_bills.php')
    ) {
        pws_json(false, 'You do not have permission to send WhatsApp messages.');
    }

    $token = (string)($_POST['csrf_token'] ?? '');

    $validCsrf = $token !== '' && (
        (
            !empty($_SESSION['proforma_csrf'])
            && hash_equals((string)$_SESSION['proforma_csrf'], $token)
        )
        ||
        (
            !empty($_SESSION['create_proforma_csrf'])
            && hash_equals((string)$_SESSION['create_proforma_csrf'], $token)
        )
    );

    if (!$validCsrf) {
        pws_json(false, 'Invalid CSRF token.');
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if (
        $action !== 'send_proforma_whatsapp'
        && $action !== 'send_whatsapp_api'
    ) {
        pws_json(false, 'Invalid WhatsApp action.');
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        pws_json(false, 'Invalid proforma bill id.');
    }

    $row = pws_fetch_proforma($conn, $id);

    if (!$row) {
        pws_json(false, 'Proforma bill not found.');
    }

    $mobile = trim((string)($row['mobile'] ?? ''));

    if ($mobile === '') {
        pws_json(false, 'Customer mobile number is missing.');
    }

    $pdfUrl = pws_public_pdf_url($conn, $id);
    $customerPreviewUrl = pws_customer_preview_url($conn, $id);

    $deliveryDate = '-';

    if (!empty($row['delivery_date']) && $row['delivery_date'] !== '0000-00-00') {
        $deliveryTimestamp = strtotime((string)$row['delivery_date']);
        if ($deliveryTimestamp !== false) {
            $deliveryDate = date('d-m-Y', $deliveryTimestamp);
        }
    }

    /*
     * Exact values required by Meta template: proforma_created
     *
     * {{1}} customer_name
     * {{2}} proforma_no
     * {{3}} product_name
     * {{4}} final_amount
     * {{5}} advance_amount
     * {{6}} balance_amount
     * {{7}} delivery_date
     * {{8}} proforma_pdf_link
     *
     * Additional aliases are kept for compatibility with existing ERP code.
     */
    $variables = [
        'customer_name' => trim((string)($row['customer_name'] ?? 'Customer')) ?: 'Customer',
        'proforma_no' => trim((string)($row['proforma_no'] ?? '-')) ?: '-',
        'product_name' => trim(
            (string)(($row['item_name'] ?? '') ?: ($row['function_name'] ?? '-'))
        ) ?: '-',

        'final_amount' => pws_money($row['final_amount'] ?? 0),
        'advance_amount' => pws_money($row['advance_amount'] ?? 0),
        'balance_amount' => pws_money($row['balance_amount'] ?? 0),
        'delivery_date' => $deliveryDate,
        'proforma_pdf_link' => $pdfUrl,

        /* Compatibility aliases */
        'function_type' => trim((string)($row['function_name'] ?? '-')) ?: '-',
        'order_type' => ucfirst((string)($row['order_type'] ?? '-')),
        'invoice_link' => $pdfUrl,
        'proforma_download_link' => $pdfUrl,
        'customer_proforma_link' => $customerPreviewUrl,
        'proforma_view_link' => $customerPreviewUrl,
    ];

    /*
     * Send the exact Meta-approved template: proforma_created.
     * Do NOT set message_type = text because this is a Meta template message.
     */
    if (function_exists('subhiksha_send_template_whatsapp')) {
        $wa = subhiksha_send_template_whatsapp(
            $conn,
            'proforma_created',
            $mobile,
            $variables,
            [
                'related_module' => 'Proforma Bills',
                'related_id' => $id,
                'customer_id' => !empty($row['customer_id'])
                    ? (int)$row['customer_id']
                    : null,
                'sent_by' => (int)($_SESSION['user_id'] ?? 0),
            ]
        );
    } else {
        $wa = subhiksha_send_whatsapp(
            $conn,
            [
                'mobile' => $mobile,
                'template_key' => 'proforma_created',
                'variables' => $variables,
                'related_module' => 'Proforma Bills',
                'related_id' => $id,
                'customer_id' => !empty($row['customer_id'])
                    ? (int)$row['customer_id']
                    : null,
                'sent_by' => (int)($_SESSION['user_id'] ?? 0),
            ]
        );
    }

    if (!empty($wa['success'])) {
        pws_json(
            true,
            'Proforma created WhatsApp message sent successfully.',
            [
                'template' => 'proforma_created',
                'log_id' => $wa['log_id'] ?? 0,
                'pdf_url' => $pdfUrl,
                'customer_url' => $customerPreviewUrl,
            ]
        );
    }

    pws_json(
        false,
        $wa['message'] ?? 'WhatsApp API template sending failed.',
        [
            'template' => 'proforma_created',
            'log_id' => $wa['log_id'] ?? 0,
            'pdf_url' => $pdfUrl,
            'customer_url' => $customerPreviewUrl,
            'response' => $wa['response'] ?? '',
        ]
    );

} catch (Throwable $e) {
    pws_json(false, 'WhatsApp API error: ' . $e->getMessage());
}