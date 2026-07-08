<?php
/**
 * api/create_proforma.php
 * Action-based API for Proforma Bills / Sales Orders module.
 * Backend processing moved from proforma_bills.php without changing DB schema/business flow.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_permission($conn, 'can_view', 'proforma_bills.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['proforma_csrf'])) {
    $_SESSION['proforma_csrf'] = bin2hex(random_bytes(32));
}

/* create_proforma.php uses this token; the list page uses proforma_csrf. */
if (empty($_SESSION['create_proforma_csrf'])) {
    $_SESSION['create_proforma_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['proforma_csrf'];
$message = '';
$messageType = 'success';
$toastTitle = 'Info';

function pb_table_exists(mysqli $conn, string $table): bool
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

function apiColumnExists(mysqli $conn, string $table, string $column): bool
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

function pb_post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function pb_float($value): float
{
    return (float)str_replace(',', '', (string)$value);
}

function pb_int($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function pb_date_or_null_value($value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    $time = strtotime($value);
    return $time ? date('Y-m-d', $time) : null;
}

function pb_slug_key(string $value, string $fallback = 'item'): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string)$value, '_');

    return $value !== '' ? substr($value, 0, 140) : $fallback;
}

function pb_unique_product_key(mysqli $conn, string $productName): string
{
    $baseKey = pb_slug_key($productName, 'product');
    $key = $baseKey;
    $i = 1;

    while (pb_table_exists($conn, 'products')) {
        $stmt = $conn->prepare("SELECT id FROM products WHERE product_key = ? LIMIT 1");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            return $key;
        }

        $i++;
        $suffix = '_' . $i;
        $key = substr($baseKey, 0, 140 - strlen($suffix)) . $suffix;
    }

    return $key;
}

function pb_find_or_create_product(mysqli $conn, string $rawProductValue, string $manualItemName, string $orderType, float $rate, int $userId): array
{
    $rawProductValue = trim($rawProductValue);
    $manualItemName = trim($manualItemName);
    $selectedProductId = ($rawProductValue !== '' && ctype_digit($rawProductValue)) ? (int)$rawProductValue : 0;
    $productName = $manualItemName;

    if (!pb_table_exists($conn, 'products')) {
        return [
            'id' => $selectedProductId > 0 ? $selectedProductId : null,
            'name' => $productName !== '' ? $productName : ($rawProductValue !== '' && !ctype_digit($rawProductValue) ? $rawProductValue : '')
        ];
    }

    if ($selectedProductId > 0) {
        $stmt = $conn->prepare("SELECT id, product_name FROM products WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $selectedProductId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            if ($productName === '') {
                $productName = (string)$row['product_name'];
            }

            return ['id' => (int)$row['id'], 'name' => $productName];
        }
    }

    if ($productName === '' && $rawProductValue !== '' && !ctype_digit($rawProductValue)) {
        $productName = $rawProductValue;
    }

    $productName = trim($productName);
    if ($productName === '') {
        return ['id' => null, 'name' => ''];
    }

    $stmt = $conn->prepare("SELECT id, product_name FROM products WHERE LOWER(product_name) = LOWER(?) LIMIT 1");
    $stmt->bind_param('s', $productName);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        return ['id' => (int)$existing['id'], 'name' => (string)$existing['product_name']];
    }

    $productKey = pb_unique_product_key($conn, $productName);
    $defaultOrderType = in_array($orderType, ['readymade', 'customized'], true) ? $orderType : 'both';
    $defaultPrice = max(0, $rate);

    $stmt = $conn->prepare("
        INSERT INTO products
            (category_id, product_name, product_key, default_order_type, description, default_price, is_active, created_by, created_at)
        VALUES
            (NULL, ?, ?, ?, NULL, ?, 1, ?, NOW())
    ");
    $stmt->bind_param('sssdi', $productName, $productKey, $defaultOrderType, $defaultPrice, $userId);
    $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();

    return ['id' => $newId, 'name' => $productName];
}

function pb_ensure_optional_create_proforma_columns(mysqli $conn): void
{
    if (pb_table_exists($conn, 'proforma_bills')) {
        if (!apiColumnExists($conn, 'proforma_bills', 'card_extra_charge')) {
            $conn->query("ALTER TABLE proforma_bills ADD COLUMN card_extra_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_amount");
        }
        if (!apiColumnExists($conn, 'proforma_bills', 'packing_charge')) {
            $conn->query("ALTER TABLE proforma_bills ADD COLUMN packing_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER card_extra_charge");
        }
        if (!apiColumnExists($conn, 'proforma_bills', 'printing_charge')) {
            $conn->query("ALTER TABLE proforma_bills ADD COLUMN printing_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER packing_charge");
        }
        if (!apiColumnExists($conn, 'proforma_bills', 'gst_percent')) {
            $conn->query("ALTER TABLE proforma_bills ADD COLUMN gst_percent DECIMAL(5,2) NOT NULL DEFAULT 18.00 AFTER printing_charge");
        }
        if (!apiColumnExists($conn, 'proforma_bills', 'taxable_value')) {
            $conn->query("ALTER TABLE proforma_bills ADD COLUMN taxable_value DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER gst_percent");
        }
        if (!apiColumnExists($conn, 'proforma_bills', 'gst_amount')) {
            $conn->query("ALTER TABLE proforma_bills ADD COLUMN gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER taxable_value");
        }
    }

    if (pb_table_exists($conn, 'proforma_bill_items')) {
        if (!apiColumnExists($conn, 'proforma_bill_items', 'printing_price_master_id')) {
            $conn->query("ALTER TABLE proforma_bill_items ADD COLUMN printing_price_master_id BIGINT UNSIGNED DEFAULT NULL AFTER screening_type");
        }
        if (!apiColumnExists($conn, 'proforma_bill_items', 'price_slab_text')) {
            $conn->query("ALTER TABLE proforma_bill_items ADD COLUMN price_slab_text VARCHAR(100) DEFAULT NULL AFTER printing_price_master_id");
        }
        if (!apiColumnExists($conn, 'proforma_bill_items', 'plate_charge')) {
            $conn->query("ALTER TABLE proforma_bill_items ADD COLUMN plate_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER price_slab_text");
        }
        if (!apiColumnExists($conn, 'proforma_bill_items', 'item_printing_charge')) {
            $conn->query("ALTER TABLE proforma_bill_items ADD COLUMN item_printing_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER plate_charge");
        }
        if (!apiColumnExists($conn, 'proforma_bill_items', 'item_package_charge')) {
            $conn->query("ALTER TABLE proforma_bill_items ADD COLUMN item_package_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER item_printing_charge");
        }
        if (!apiColumnExists($conn, 'proforma_bill_items', 'item_additional_charge')) {
            $conn->query("ALTER TABLE proforma_bill_items ADD COLUMN item_additional_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER item_package_charge");
        }
        if (!apiColumnExists($conn, 'proforma_bill_items', 'is_gst_inclusive')) {
            $conn->query("ALTER TABLE proforma_bill_items ADD COLUMN is_gst_inclusive TINYINT(1) NOT NULL DEFAULT 1 AFTER item_additional_charge");
        }
    }

    /*
     * Cash denomination details are stored row-wise in a separate table.
     * Example for ₹1001 cash payment:
     * payment_cash_denominations -> ₹500 x 2 and ₹1 coin x 1.
     */
    if (pb_table_exists($conn, 'payments')) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS payment_cash_denominations (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                payment_id BIGINT(20) UNSIGNED NOT NULL,
                denomination_type ENUM('note','coin') NOT NULL DEFAULT 'note',
                denomination_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                denomination_count INT UNSIGNED NOT NULL DEFAULT 0,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_by BIGINT(20) UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_payment_cash_denominations_payment_id (payment_id),
                KEY idx_payment_cash_denominations_value (denomination_type, denomination_value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        try {
            $conn->query("
                ALTER TABLE payment_cash_denominations
                ADD CONSTRAINT fk_payment_cash_denominations_payment
                FOREIGN KEY (payment_id) REFERENCES payments(id)
                ON DELETE CASCADE
            ");
        } catch (Throwable $e) {
            /* Foreign key may already exist or the live table may not allow it; inserts still work. */
        }
    }
}

function pb_cash_denomination_payload(): array
{
    $map = [
        'cash_note_500' => ['type' => 'note', 'value' => 500],
        'cash_note_200' => ['type' => 'note', 'value' => 200],
        'cash_note_100' => ['type' => 'note', 'value' => 100],
        'cash_note_50' => ['type' => 'note', 'value' => 50],
        'cash_note_20' => ['type' => 'note', 'value' => 20],
        'cash_note_10' => ['type' => 'note', 'value' => 10],
        'cash_coin_20' => ['type' => 'coin', 'value' => 20],
        'cash_coin_10' => ['type' => 'coin', 'value' => 10],
        'cash_coin_5' => ['type' => 'coin', 'value' => 5],
        'cash_coin_2' => ['type' => 'coin', 'value' => 2],
        'cash_coin_1' => ['type' => 'coin', 'value' => 1],
    ];

    $rows = [];
    $summary = [];
    $total = 0.0;

    foreach ($map as $field => $meta) {
        $count = max(0, (int)($_POST[$field] ?? 0));
        $value = (float)$meta['value'];
        $amount = round($count * $value, 2);
        $total += $amount;

        /*
         * Store every note/coin denomination row, even when the count is 0.
         * This makes the cash breakup complete for auditing and avoids saving
         * only the denominations that were used, such as ₹500 and ₹1.
         */
        $row = [
            'field' => $field,
            'type' => $meta['type'],
            'value' => $value,
            'count' => $count,
            'amount' => $amount,
            'display' => $count . ' x ₹' . number_format($value, 0) . ' = ₹' . number_format($amount, 2, '.', '')
        ];

        $rows[] = $row;

        if ($count > 0) {
            $summary[] = $row['display'];
        }
    }

    return [
        'rows' => $rows,
        'summary' => $summary,
        'summary_text' => implode('; ', $summary),
        'total' => round($total, 2)
    ];
}


function pb_save_cash_denominations(mysqli $conn, int $paymentId, array $cashDenomination, int $userId): void
{
    if ($paymentId <= 0 || !pb_table_exists($conn, 'payment_cash_denominations')) {
        return;
    }

    $rows = $cashDenomination['rows'] ?? [];
    if (!is_array($rows) || !$rows) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO payment_cash_denominations
            (payment_id, denomination_type, denomination_value, denomination_count, amount, created_by, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, NOW())
    ");

    foreach ($rows as $row) {
        $type = strtolower((string)($row['type'] ?? 'note')) === 'coin' ? 'coin' : 'note';
        $value = (float)($row['value'] ?? 0);
        $count = max(0, (int)($row['count'] ?? 0));
        $amount = (float)($row['amount'] ?? ($value * $count));

        if ($value <= 0) {
            continue;
        }

        /* Save all denominations, including zero-count rows. */
        $stmt->bind_param('isdidi', $paymentId, $type, $value, $count, $amount, $userId);
        $stmt->execute();
    }

    $stmt->close();
}

function pb_posted_planned_dates(): array
{
    $out = [];
    $posted = $_POST['planned_step'] ?? [];

    if (!is_array($posted)) {
        return $out;
    }

    foreach ($posted as $stepId => $row) {
        $id = (int)$stepId;
        if ($id <= 0 || !is_array($row)) {
            continue;
        }

        $out[$id] = [
            'start' => pb_date_or_null_value($row['start'] ?? ''),
            'completion' => pb_date_or_null_value($row['completion'] ?? '')
        ];
    }

    return $out;
}

function pb_enquiry_completed_date(mysqli $conn, array $bill): string
{
    $today = date('Y-m-d');

    try {
        $enquiryId = !empty($bill['enquiry_id']) ? (int)$bill['enquiry_id'] : 0;

        if ($enquiryId <= 0 && !empty($bill['quotation_id']) && pb_table_exists($conn, 'quotations')) {
            $quotationId = (int)$bill['quotation_id'];
            $stmt = $conn->prepare("SELECT enquiry_id FROM quotations WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $quotationId);
            $stmt->execute();
            $q = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $enquiryId = !empty($q['enquiry_id']) ? (int)$q['enquiry_id'] : 0;
        }

        if ($enquiryId > 0 && pb_table_exists($conn, 'enquiries')) {
            $stmt = $conn->prepare("SELECT updated_at, created_at FROM enquiries WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $enquiryId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $date = !empty($row['updated_at']) ? $row['updated_at'] : ($row['created_at'] ?? '');
                if ($date !== '') {
                    return substr((string)$date, 0, 10);
                }
            }
        }
    } catch (Throwable $e) {
        return $today;
    }

    return $today;
}

function pb_is_final_review_step(array $step): bool
{
    $key = strtolower((string)($step['step_key'] ?? ''));
    $name = strtolower((string)($step['step_name'] ?? ''));

    return (int)($step['is_final_step'] ?? 0) === 1
        || str_contains($key, 'google')
        || str_contains($key, 'review')
        || str_contains($key, 'whatsapp')
        || str_contains($name, 'google')
        || str_contains($name, 'review')
        || str_contains($name, 'whatsapp');
}

function pb_default_planned_dates(mysqli $conn, array $bill, array $step, array $postedDates): array
{
    $stepId = (int)($step['id'] ?? 0);
    $key = strtolower((string)($step['step_key'] ?? ''));
    $name = strtolower((string)($step['step_name'] ?? ''));
    $today = date('Y-m-d');

    $start = $postedDates[$stepId]['start'] ?? null;
    $completion = $postedDates[$stepId]['completion'] ?? null;

    if (!$start || !$completion) {
        if ($key === 'enquiry' || str_contains($name, 'enquiry')) {
            $date = pb_enquiry_completed_date($conn, $bill);
        } elseif (pb_is_final_review_step($step)) {
            $date = !empty($bill['delivery_date']) ? substr((string)$bill['delivery_date'], 0, 10) : $today;
        } else {
            $date = $today;
        }

        if (!$start) {
            $start = $date;
        }

        if (!$completion) {
            $completion = $date;
        }
    }

    return [$start, $completion];
}

function pb_redirect(string $query = ''): void
{
    header('Location: proforma_bills.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function pb_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    $valid = $token !== '' && (
        (!empty($_SESSION['proforma_csrf']) && hash_equals($_SESSION['proforma_csrf'], $token)) ||
        (!empty($_SESSION['create_proforma_csrf']) && hash_equals($_SESSION['create_proforma_csrf'], $token))
    );

    if (!$valid) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function pb_next_no(mysqli $conn, string $table, string $column, string $prefix): string
{
    $datePart = date('ymd');
    $like = $prefix . '-' . $datePart . '-%';

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM {$table} WHERE {$column} LIKE ?");
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

function pb_status_id(mysqli $conn, string $table, string $keyColumn, string $key): ?int
{
    try {
        if (!pb_table_exists($conn, $table)) {
            return null;
        }

        $stmt = $conn->prepare("SELECT id FROM {$table} WHERE {$keyColumn} = ? LIMIT 1");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}


function pb_role_id_by_keys(mysqli $conn, array $roleKeys): ?int
{
    try {
        if (!pb_table_exists($conn, 'roles') || !$roleKeys) {
            return null;
        }

        foreach ($roleKeys as $key) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }

            $stmt = $conn->prepare("SELECT id FROM roles WHERE role_key = ? LIMIT 1");
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                return (int)$row['id'];
            }
        }

        return null;
    } catch (Throwable $e) {
        return null;
    }
}

function pb_designing_role_id(mysqli $conn): ?int
{
    return pb_role_id_by_keys($conn, [
        'designing_proofing',
        'design_proofing',
        'designing',
        'proofing',
        'designer',
        'designing_team'
    ]);
}


function pb_multicolor_printing_type_id(mysqli $conn): ?int
{
    try {
        if (!pb_table_exists($conn, 'printing_types')) {
            return null;
        }

        $keys = [
            'multicolor_offset_printing',
            'multi_color_offset_printing',
            'multicolour_offset_printing',
            'multicolor_offset',
            'multi_color_offset'
        ];

        foreach ($keys as $key) {
            $stmt = $conn->prepare("SELECT id FROM printing_types WHERE printing_key = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                return (int)$row['id'];
            }
        }

        $likeOffset = '%offset%';
        $likeMultiColor1 = '%multicolor%';
        $likeMultiColor2 = '%multi color%';
        $likeMultiColor3 = '%multi-color%';
        $likeMultiColour = '%multicolour%';

        $stmt = $conn->prepare("
            SELECT id
            FROM printing_types
            WHERE is_active = 1
              AND LOWER(printing_name) LIKE ?
              AND (
                    LOWER(printing_name) LIKE ?
                 OR LOWER(printing_name) LIKE ?
                 OR LOWER(printing_name) LIKE ?
                 OR LOWER(printing_name) LIKE ?
                 OR LOWER(printing_key) LIKE ?
                 OR LOWER(printing_key) LIKE ?
                 OR LOWER(printing_key) LIKE ?
                 OR LOWER(printing_key) LIKE ?
              )
            ORDER BY sort_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->bind_param(
            'sssssssss',
            $likeOffset,
            $likeMultiColor1,
            $likeMultiColor2,
            $likeMultiColor3,
            $likeMultiColour,
            $likeMultiColor1,
            $likeMultiColor2,
            $likeMultiColor3,
            $likeMultiColour
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}



function pb_is_screen_printing_type(mysqli $conn, ?int $printingTypeId): bool
{
    if (!$printingTypeId || !pb_table_exists($conn, 'printing_types')) {
        return false;
    }

    try {
        $stmt = $conn->prepare("
            SELECT printing_name, printing_key
            FROM printing_types
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $printingTypeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return false;
        }

        $name = strtolower((string)($row['printing_name'] ?? ''));
        $key = strtolower((string)($row['printing_key'] ?? ''));

        return str_contains($name, 'screen') || str_contains($key, 'screen');
    } catch (Throwable $e) {
        return false;
    }
}


function pb_multicolor_role_id(mysqli $conn): ?int
{
    return pb_role_id_by_keys($conn, [
        'multicolor_offset_printing',
        'multi_color_offset_printing',
        'multicolour_offset_printing',
        'printing_multicolor',
        'printing'
    ]);
}

function pb_sales_role_id(mysqli $conn): ?int
{
    return pb_role_id_by_keys($conn, [
        'sales',
        'sales_team',
        'sales_executive'
    ]);
}

function pb_customized_sales_completed_steps(): array
{
    return [
        'enquiry',
        'quotation',
        'proforma_bill',
        'sales_order_proforma_invoice',
        'sales_order',
        'job_card',
        'job_card_created'
    ];
}

function pb_customized_design_steps(): array
{
    return [
        'designing',
        'proofing',
        'design_approval',
        'customer_design_approval',
        'approval'
    ];
}

function pb_customized_printing_steps(): array
{
    return [
        'design_received',
        'plate_preparation',
        'plating',
        'paper_board_selection',
        'paper_selection',
        'board_selection',
        'ctp',
        'multicolor_offset_printing',
        'printing',
        'print',
        'production',
        'lamination',
        'laminate',
        'drying',
        'cutting',
        'packing',
        'quality_check',
        'send_to_dispatch',
        'ready_for_dispatch',
        'dispatch'
    ];
}

function pb_readymade_sales_completed_steps(): array
{
    return [
        'enquiry',
        'quotation',
        'proforma_bill',
        'sales_order_proforma_invoice',
        'sales_order',
        'job_card',
        'job_card_created'
    ];
}

function pb_readymade_design_steps(): array
{
    return [
        'proofing',
        'proofing_approval',
        'proof_approval',
        'master_copy'
    ];
}

function pb_readymade_printing_steps(): array
{
    return [
        'master_copy_received',
        'printing',
        'drying',
        'packing',
        'send_to_dispatch'
    ];
}

function pb_function_type_id(mysqli $conn, string $value): ?int
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (ctype_digit($value)) {
        return (int)$value;
    }

    if (!pb_table_exists($conn, 'function_types')) {
        throw new RuntimeException('function_types table is missing.');
    }

    $functionName = function_exists('mb_substr') ? mb_substr($value, 0, 150) : substr($value, 0, 150);
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

function pb_log(mysqli $conn, string $action, string $module, int $recordId, string $description): void
{
    try {
        if (!pb_table_exists($conn, 'activity_logs')) {
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("
            INSERT INTO activity_logs
                (user_id, role_id, action_key, module_name, record_id, description, ip_address, user_agent, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('iississs', $userId, $roleId, $action, $module, $recordId, $description, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
    }
}

function pb_customer_id(mysqli $conn, string $customerName, string $mobile, string $address = '', string $gst = ''): ?int
{
    $customerName = trim($customerName);
    $mobile = trim($mobile);

    if ($customerName === '' || $mobile === '') {
        return null;
    }

    try {
        $stmt = $conn->prepare("SELECT id FROM customers WHERE mobile = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('s', $mobile);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            return (int)$row['id'];
        }

        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("
            INSERT INTO customers
                (customer_name, mobile, address, gst_number, is_active, created_by, created_at)
            VALUES
                (?, ?, ?, ?, 1, ?, NOW())
        ");
        $stmt->bind_param('ssssi', $customerName, $mobile, $address, $gst, $createdBy);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        return $id;
    } catch (Throwable $e) {
        return null;
    }
}

function pb_first_workflow_step(mysqli $conn, string $orderType): ?int
{
    try {
        $preferred = $orderType === 'customized' ? 'designing' : 'proofing';

        $stmt = $conn->prepare("
            SELECT id
            FROM workflow_steps
            WHERE order_type = ?
              AND step_key = ?
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->bind_param('ss', $orderType, $preferred);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            return (int)$row['id'];
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM workflow_steps
            WHERE order_type = ?
              AND is_active = 1
            ORDER BY sort_order ASC
            LIMIT 1
        ");
        $stmt->bind_param('s', $orderType);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function pb_create_job_card(mysqli $conn, int $proformaId, array $plannedDates = []): int
{
    if ($proformaId <= 0) {
        throw new RuntimeException('Invalid proforma bill.');
    }

    $stmt = $conn->prepare("SELECT * FROM proforma_bills WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $proformaId);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bill) {
        throw new RuntimeException('Proforma bill not found.');
    }

    if ((int)($bill['job_card_created'] ?? 0) === 1) {
        $stmt = $conn->prepare("SELECT id FROM job_cards WHERE proforma_bill_id = ? LIMIT 1");
        $stmt->bind_param('i', $proformaId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            return (int)$existing['id'];
        }
    }

    $stmt = $conn->prepare("SELECT * FROM proforma_bill_items WHERE proforma_bill_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param('i', $proformaId);
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    if (!$items) {
        throw new RuntimeException('Please add at least one proforma item before creating job card.');
    }

    $firstItem = $items[0];
    $orderType = (string)$bill['order_type'];
    $jobNo = pb_next_no($conn, 'job_cards', 'job_card_no', 'SC-JOB');
    $trackingToken = bin2hex(random_bytes(24));
    $currentStepId = pb_first_workflow_step($conn, $orderType);
    $jobStatusId = pb_status_id($conn, 'job_card_statuses', 'status_key', 'in_progress');
    $createdBy = (int)($_SESSION['user_id'] ?? 0);

    $assignedPrintingRoleId = null;
    $salesRoleId = pb_sales_role_id($conn);
    $designingRoleId = pb_designing_role_id($conn);
    $multicolorRoleId = pb_multicolor_role_id($conn);

    /*
     * Requirement-based assignment:
     * - Readymade: printing department depends on selected printing type.
     * - Customized: not directly visible to printing at creation; starts with Designing / Proofing.
     *   It becomes visible to Multicolor Offset Printing only after Design Approval.
     */
    if ($orderType !== 'customized' && !empty($firstItem['printing_type_id'])) {
        try {
            $stmt = $conn->prepare("
                SELECT r.id
                FROM printing_types pt
                LEFT JOIN roles r ON r.role_key = pt.role_key
                WHERE pt.id = ?
                LIMIT 1
            ");
            $ptId = (int)$firstItem['printing_type_id'];
            $stmt->bind_param('i', $ptId);
            $stmt->execute();
            $roleRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($roleRow && !empty($roleRow['id'])) {
                $assignedPrintingRoleId = (int)$roleRow['id'];
            }
        } catch (Throwable $e) {
            $assignedPrintingRoleId = null;
        }
    }

    $productName = (string)($firstItem['item_name'] ?? 'Invitation Cards');
    $productId = !empty($firstItem['product_id']) ? (int)$firstItem['product_id'] : null;
    $printingTypeId = !empty($firstItem['printing_type_id']) ? (int)$firstItem['printing_type_id'] : null;
    $printingSubTypeId = !empty($firstItem['printing_sub_type_id']) ? (int)$firstItem['printing_sub_type_id'] : null;

    $stmt = $conn->prepare("
        INSERT INTO job_cards
            (
                job_card_no,
                tracking_token,
                enquiry_id,
                quotation_id,
                proforma_bill_id,
                customer_id,
                order_type,
                customer_name,
                mobile,
                function_type_id,
                product_id,
                product_name,
                printing_type_id,
                printing_sub_type_id,
                assigned_sales_user_id,
                assigned_printing_role_id,
                job_card_status_id,
                current_workflow_step_id,
                final_amount,
                advance_amount,
                balance_amount,
                delivery_date,
                created_by,
                created_at,
                updated_at
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $enquiryId = !empty($bill['enquiry_id']) ? (int)$bill['enquiry_id'] : null;
    $quotationId = !empty($bill['quotation_id']) ? (int)$bill['quotation_id'] : null;
    $customerId = !empty($bill['customer_id']) ? (int)$bill['customer_id'] : null;
    $functionTypeId = !empty($bill['function_type_id']) ? (int)$bill['function_type_id'] : null;
    $salesUserId = $createdBy > 0 ? $createdBy : null;

    $finalAmount = (float)$bill['final_amount'];
    $advanceAmount = (float)$bill['advance_amount'];
    $balanceAmount = (float)$bill['balance_amount'];
    $deliveryDate = !empty($bill['delivery_date']) ? $bill['delivery_date'] : null;

    $stmt->bind_param(
        'ssiiiisssiiisiiiiidddsi',
        $jobNo,
        $trackingToken,
        $enquiryId,
        $quotationId,
        $proformaId,
        $customerId,
        $orderType,
        $bill['customer_name'],
        $bill['mobile'],
        $functionTypeId,
        $productId,
        $productName,
        $printingTypeId,
        $printingSubTypeId,
        $salesUserId,
        $assignedPrintingRoleId,
        $jobStatusId,
        $currentStepId,
        $finalAmount,
        $advanceAmount,
        $balanceAmount,
        $deliveryDate,
        $createdBy
    );

    $stmt->execute();
    $jobCardId = (int)$stmt->insert_id;
    $stmt->close();

    foreach ($items as $item) {
        $stmt = $conn->prepare("
            INSERT INTO job_card_items
                (
                    job_card_id,
                    product_id,
                    item_name,
                    description,
                    qty,
                    rate,
                    amount,
                    size_text,
                    gsm_thickness,
                    lamination_required,
                    lamination_type,
                    printing_side,
                    screening_type,
                    finishing_required,
                    created_at
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $itemProductId = !empty($item['product_id']) ? (int)$item['product_id'] : null;
        $itemName = (string)$item['item_name'];
        $description = (string)($item['description'] ?? '');
        $qty = (float)$item['qty'];
        $rate = (float)$item['rate'];
        $amount = (float)$item['amount'];
        $sizeText = (string)($item['size_text'] ?? '');
        $gsm = (string)($item['gsm_thickness'] ?? '');
        $laminationRequired = isset($item['lamination_required']) ? (int)$item['lamination_required'] : null;
        $laminationType = $item['lamination_type'] ?? null;
        $printingSide = $item['printing_side'] ?? null;
        $screeningType = $item['screening_type'] ?? null;
        $finishingRequired = isset($item['finishing_required']) ? (int)$item['finishing_required'] : null;

        $stmt->bind_param(
            'iissdddssisssi',
            $jobCardId,
            $itemProductId,
            $itemName,
            $description,
            $qty,
            $rate,
            $amount,
            $sizeText,
            $gsm,
            $laminationRequired,
            $laminationType,
            $printingSide,
            $screeningType,
            $finishingRequired
        );
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("
        SELECT ws.*, r.id AS role_id
        FROM workflow_steps ws
        LEFT JOIN roles r ON r.role_key = ws.default_owner_role_key
        WHERE ws.order_type = ?
          AND ws.is_active = 1
        ORDER BY ws.sort_order ASC
    ");
    $stmt->bind_param('s', $orderType);
    $stmt->execute();
    $res = $stmt->get_result();

    $steps = [];
    while ($row = $res->fetch_assoc()) {
        $steps[] = $row;
    }
    $stmt->close();

    foreach ($steps as $step) {
        $stepId = (int)$step['id'];
        $stepKey = (string)($step['step_key'] ?? '');
        $roleId = !empty($step['role_id']) ? (int)$step['role_id'] : null;
        $status = 'pending';
        $actualStart = null;
        $actualComplete = null;
        $completedBy = null;

        if ($orderType === 'customized') {
            if (in_array($stepKey, pb_customized_sales_completed_steps(), true)) {
                $roleId = $salesRoleId ?: $roleId;
                $status = 'completed';
                $actualStart = date('Y-m-d H:i:s');
                $actualComplete = date('Y-m-d H:i:s');
                $completedBy = $createdBy;
            } elseif (in_array($stepKey, pb_customized_design_steps(), true)) {
                $roleId = $designingRoleId ?: $roleId;

                if ($stepKey === 'designing' || (int)$stepId === (int)$currentStepId) {
                    $status = 'in_progress';
                    $actualStart = date('Y-m-d H:i:s');
                }
            } elseif (in_array($stepKey, pb_customized_printing_steps(), true)) {
                $roleId = $multicolorRoleId ?: $roleId;
                $status = 'pending';
            }
        } else {
            if (in_array($stepKey, pb_readymade_sales_completed_steps(), true)) {
                $roleId = $salesRoleId ?: $roleId;
                $status = 'completed';
                $actualStart = date('Y-m-d H:i:s');
                $actualComplete = date('Y-m-d H:i:s');
                $completedBy = $createdBy;
            } elseif (in_array($stepKey, pb_readymade_design_steps(), true)) {
                $roleId = $designingRoleId ?: $roleId;
                if ($stepKey === 'proofing' || (int)$stepId === (int)$currentStepId) {
                    $status = 'in_progress';
                    $actualStart = date('Y-m-d H:i:s');
                }
            } elseif (in_array($stepKey, pb_readymade_printing_steps(), true)) {
                $roleId = $assignedPrintingRoleId ?: $roleId;
                $status = 'pending';
            } elseif ($currentStepId && (int)$stepId === (int)$currentStepId) {
                $status = 'in_progress';
                $actualStart = date('Y-m-d H:i:s');
            }
        }

        [$plannedStartDate, $plannedCompletionDate] = pb_default_planned_dates($conn, $bill, $step, $plannedDates);

        $stmt = $conn->prepare("
            INSERT INTO job_tracking
                (
                    job_card_id,
                    workflow_step_id,
                    planned_start_date,
                    planned_completion_date,
                    status,
                    responsible_role_id,
                    actual_start_at,
                    actual_completed_at,
                    completed_by,
                    created_at,
                    updated_at
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                planned_start_date = VALUES(planned_start_date),
                planned_completion_date = VALUES(planned_completion_date),
                status = VALUES(status),
                responsible_role_id = VALUES(responsible_role_id),
                updated_at = NOW()
        ");
        $stmt->bind_param('iisssissi', $jobCardId, $stepId, $plannedStartDate, $plannedCompletionDate, $status, $roleId, $actualStart, $actualComplete, $completedBy);
        $stmt->execute();
        $stmt->close();
    }

    $jobCardStatusPb = pb_status_id($conn, 'proforma_statuses', 'status_key', 'job_card_created');

    if ($jobCardStatusPb) {
        $stmt = $conn->prepare("
            UPDATE proforma_bills
            SET job_card_created = 1,
                proforma_status_id = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('iii', $jobCardStatusPb, $createdBy, $proformaId);
    } else {
        $stmt = $conn->prepare("
            UPDATE proforma_bills
            SET job_card_created = 1,
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('ii', $createdBy, $proformaId);
    }

    $stmt->execute();
    $stmt->close();

    pb_log($conn, 'create_job_card', 'Proforma Bills', $proformaId, 'Job card created from proforma bill: ' . $jobNo);

    return $jobCardId;
}

function pb_offset_printing_type_id(mysqli $conn): ?int
{
    try {
        if (!pb_table_exists($conn, 'printing_types')) {
            return null;
        }

        $likeOffset = '%offset%';
        $stmt = $conn->prepare("
            SELECT id
            FROM printing_types
            WHERE is_active = 1
              AND (
                    LOWER(printing_name) LIKE ?
                 OR LOWER(printing_key) LIKE ?
              )
            ORDER BY sort_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->bind_param('ss', $likeOffset, $likeOffset);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function pb_printing_type_allowed_for_readymade(mysqli $conn, ?int $printingTypeId): bool
{
    if (!$printingTypeId || !pb_table_exists($conn, 'printing_types')) {
        return false;
    }

    try {
        $stmt = $conn->prepare("
            SELECT printing_name, printing_key
            FROM printing_types
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->bind_param('i', $printingTypeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return false;
        }

        $text = strtolower((string)($row['printing_name'] ?? '') . ' ' . (string)($row['printing_key'] ?? ''));

        return str_contains($text, 'offset')
            || str_contains($text, 'screen')
            || str_contains($text, 'digital');
    } catch (Throwable $e) {
        return false;
    }
}

function pb_setting_value(mysqli $conn, string $key, string $default = ''): string
{
    try {
        if (!pb_table_exists($conn, 'system_settings')) {
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

function pb_whatsapp_api_ready(mysqli $conn): bool
{
    $enabled = pb_setting_value($conn, 'whatsapp_enabled', '0');
    $apiUrl = pb_setting_value($conn, 'watzup_api_url', '');
    $apiToken = pb_setting_value($conn, 'watzup_api_token', '');
    $senderId = pb_setting_value($conn, 'watzup_sender_id', '');

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

    if (in_array($apiUrl, $dummyValues, true) || in_array($apiToken, $dummyValues, true) || in_array($senderId, $dummyValues, true)) {
        return false;
    }

    return filter_var($apiUrl, FILTER_VALIDATE_URL) !== false;
}

function pb_whatsapp_mobile($mobile): string
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

function pb_whatsapp_template_row(mysqli $conn, string $templateKey): ?array
{
    try {
        if (!pb_table_exists($conn, 'whatsapp_templates')) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT id, template_key, template_name, message_body
            FROM whatsapp_templates
            WHERE template_key = ?
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->bind_param('s', $templateKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function pb_whatsapp_render_template(string $message, array $variables): string
{
    foreach ($variables as $key => $value) {
        $key = trim((string)$key);
        $value = (string)$value;
        $message = str_replace('{{' . $key . '}}', $value, $message);
        $message = str_replace('{' . $key . '}', $value, $message);
    }

    return $message;
}

function pb_whatsapp_base_url(mysqli $conn): string
{
    $setting = pb_setting_value($conn, 'site_url', '');
    if ($setting === '') $setting = pb_setting_value($conn, 'base_url', '');
    if ($setting === '') $setting = pb_setting_value($conn, 'app_url', '');
    if ($setting !== '') return rtrim($setting, '/');

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $dir = preg_replace('#/api$#', '', $dir);

    return rtrim($scheme . '://' . $host . ($dir === '' || $dir === '/' ? '' : $dir), '/');
}

function pb_whatsapp_tracking_url(mysqli $conn, array $row): string
{
    $token = trim((string)($row['tracking_token'] ?? ''));
    if ($token === '') return '';

    return pb_whatsapp_base_url($conn) . '/customer_tracking.php?token=' . rawurlencode($token);
}

function pb_whatsapp_variables(mysqli $conn, array $row): array
{
    return [
        'customer_name' => (string)($row['customer_name'] ?? 'Customer'),
        'proforma_no' => (string)($row['proforma_no'] ?? '-'),
        'job_card_no' => (string)($row['job_card_no'] ?? '-'),
        'function_type' => (string)($row['function_name'] ?? '-'),
        'product_name' => (string)($row['item_name'] ?? '-'),
        'order_type' => ucfirst((string)($row['order_type'] ?? '-')),
        'quantity' => number_format((float)($row['total_qty'] ?? 0), 0),
        'final_amount' => '₹' . number_format((float)($row['final_amount'] ?? 0), 2),
        'advance_amount' => '₹' . number_format((float)($row['advance_amount'] ?? 0), 2),
        'balance_amount' => '₹' . number_format((float)($row['balance_amount'] ?? 0), 2),
        'delivery_date' => !empty($row['delivery_date']) ? date('d-m-Y', strtotime((string)$row['delivery_date'])) : '-',
        'tracking_link' => pb_whatsapp_tracking_url($conn, $row),
        'mobile' => (string)($row['mobile'] ?? '')
    ];
}

function pb_whatsapp_template_message(mysqli $conn, string $templateKey, array $row): string
{
    $template = pb_whatsapp_template_row($conn, $templateKey);
    if (!$template) {
        return '';
    }

    return pb_whatsapp_render_template((string)$template['message_body'], pb_whatsapp_variables($conn, $row));
}

function pb_get_whatsapp_row(mysqli $conn, int $id): ?array
{
    if ($id <= 0 || !pb_table_exists($conn, 'proforma_bills')) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                pb.*,
                ft.function_name,
                ps.status_name,
                jc.job_card_no,
                jc.tracking_token,
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
                pst.sub_type_name
            FROM proforma_bills pb
            LEFT JOIN function_types ft ON ft.id = pb.function_type_id
            LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id
            LEFT JOIN (
                SELECT proforma_bill_id, MAX(job_card_no) AS job_card_no, MAX(tracking_token) AS tracking_token
                FROM job_cards
                GROUP BY proforma_bill_id
            ) jc ON jc.proforma_bill_id = pb.id
            LEFT JOIN proforma_bill_items pbi ON pbi.proforma_bill_id = pb.id
            LEFT JOIN printing_types pt ON pt.id = pbi.printing_type_id
            LEFT JOIN printing_sub_types pst ON pst.id = pbi.printing_sub_type_id
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

function pb_whatsapp_message(array $row): string
{
    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return '';
    }

    return pb_whatsapp_template_message($conn, 'proforma_created', $row);
}

function pb_whatsapp_url(array $row): string
{
    $mobile = pb_whatsapp_mobile($row['mobile'] ?? '');

    if ($mobile === '') {
        return '#';
    }

    return 'https://wa.me/' . $mobile . '?text=' . rawurlencode(pb_whatsapp_message($row));
}

function pb_whatsapp_template_id(mysqli $conn, string $templateKey): ?int
{
    $template = pb_whatsapp_template_row($conn, $templateKey);
    return $template ? (int)$template['id'] : null;
}

function pb_whatsapp_log_manual(mysqli $conn, int $id): array
{
    $row = pb_get_whatsapp_row($conn, $id);

    if (!$row) {
        return ['success' => false, 'message' => 'Proforma bill not found.'];
    }

    $mobile = pb_whatsapp_mobile($row['mobile'] ?? '');

    if ($mobile === '') {
        return ['success' => false, 'message' => 'Customer mobile number is missing.'];
    }

    if (!pb_table_exists($conn, 'whatsapp_logs')) {
        return ['success' => true, 'message' => 'Manual WhatsApp opened. whatsapp_logs table missing, so log not saved.'];
    }

    try {
        $templateId = pb_whatsapp_template_id($conn, 'proforma_created');
        $messageBody = pb_whatsapp_template_message($conn, 'proforma_created', $row);

        if (!$templateId || trim($messageBody) === '') {
            return ['success' => false, 'message' => 'Active WhatsApp template not found for template_key: proforma_created.'];
        }

        $relatedModule = 'Proforma Bills';
        $relatedId = $id;
        $customerId = !empty($row['customer_id']) ? (int)$row['customer_id'] : null;
        $jobCardId = null;
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

function pb_send_whatsapp_by_api(mysqli $conn, int $id): array
{
    $apiFile = __DIR__ . '/../includes/whatsapp-api.php';

    if (!file_exists($apiFile)) {
        return ['success' => false, 'message' => 'WhatsApp API file missing.'];
    }

    require_once $apiFile;

    if (!function_exists('subhiksha_send_template_whatsapp') && !function_exists('subhiksha_send_whatsapp')) {
        return ['success' => false, 'message' => 'WhatsApp API function missing.'];
    }

    $row = pb_get_whatsapp_row($conn, $id);

    if (!$row) {
        return ['success' => false, 'message' => 'Proforma bill not found.'];
    }

    $variables = pb_whatsapp_variables($conn, $row);
    $meta = [
        'related_module' => 'Proforma Bills',
        'related_id' => $id,
        'customer_id' => $row['customer_id'] ?? null
    ];

    if (function_exists('subhiksha_send_template_whatsapp')) {
        return subhiksha_send_template_whatsapp(
            $conn,
            'proforma_created',
            (string)($row['mobile'] ?? ''),
            $variables,
            $meta
        );
    }

    return subhiksha_send_whatsapp($conn, array_merge($meta, [
        'mobile' => (string)($row['mobile'] ?? ''),
        'template_key' => 'proforma_created',
        'variables' => $variables
    ]));
}

function pb_send_whatsapp_preferred(mysqli $conn, int $id): array
{
    $row = pb_get_whatsapp_row($conn, $id);

    if (!$row) {
        return ['success' => false, 'message' => 'Proforma bill not found.'];
    }

    $manualUrl = pb_whatsapp_url($row);

    if (pb_whatsapp_api_ready($conn)) {
        $apiResult = pb_send_whatsapp_by_api($conn, $id);
        $apiResult['mode'] = 'api';
        $apiResult['manual_whatsapp'] = false;

        if (!($apiResult['success'] ?? false)) {
            $apiResult['message'] = 'WhatsApp API sending failed: ' . (string)($apiResult['response'] ?? $apiResult['message'] ?? 'Unknown error.');
        }

        return $apiResult;
    }

    $manualResult = pb_whatsapp_log_manual($conn, $id);
    $manualResult['mode'] = 'manual';
    $manualResult['manual_whatsapp'] = true;
    $manualResult['open_whatsapp_url'] = $manualUrl;

    if ($manualResult['success'] ?? false) {
        $manualResult['message'] = 'WhatsApp API is not enabled. Manual WhatsApp mode opened.';
    } else {
        $manualResult['message'] = 'Manual WhatsApp failed: ' . (string)($manualResult['message'] ?? 'Unknown error.');
    }

    return $manualResult;
}

function pb_whatsapp_svg(): string
{
    return '<svg viewBox="0 0 32 32" width="17" height="17" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16.04 3C8.85 3 3 8.73 3 15.78c0 2.26.61 4.47 1.77 6.41L3 29l7.02-1.8a13.3 13.3 0 0 0 6.02 1.43C23.23 28.63 29 22.9 29 15.85S23.23 3 16.04 3Zm0 23.45c-1.9 0-3.76-.5-5.39-1.45l-.39-.23-4.16 1.07 1.11-4.01-.26-.41a11.05 11.05 0 0 1-1.73-5.64c0-5.84 4.85-10.6 10.82-10.6 5.96 0 10.81 4.76 10.81 10.67 0 5.84-4.85 10.6-10.81 10.6Zm5.93-7.95c-.32-.16-1.9-.92-2.2-1.03-.3-.11-.52-.16-.74.16-.22.32-.85 1.03-1.04 1.24-.19.22-.38.24-.7.08-.32-.16-1.36-.49-2.59-1.55-.96-.84-1.61-1.88-1.8-2.2-.19-.32-.02-.49.14-.65.14-.14.32-.38.49-.57.16-.19.22-.32.32-.54.11-.22.05-.41-.03-.57-.08-.16-.74-1.76-1.01-2.41-.27-.65-.54-.54-.74-.55h-.63c-.22 0-.57.08-.87.41-.3.32-1.14 1.09-1.14 2.68s1.17 3.12 1.33 3.34c.16.22 2.3 3.46 5.58 4.85.78.33 1.39.53 1.86.68.78.24 1.49.21 2.05.13.63-.09 1.9-.76 2.17-1.49.27-.73.27-1.36.19-1.49-.08-.13-.3-.21-.62-.37Z"/></svg>';
}

function pb_whatsapp_button(array $row): string
{
    $waRow = pb_get_whatsapp_row($GLOBALS['conn'], (int)($row['id'] ?? 0));

    if (!$waRow) {
        $waRow = $row;
    }

    return '
        <button type="button"
            class="btn btn-sm btn-whatsapp-icon rounded-circle js-whatsapp-preview"
            title="Preview WhatsApp message"
            data-id="' . e($row['id'] ?? '') . '"
            data-customer-name="' . e($row['customer_name'] ?? '') . '"
            data-mobile="' . e($row['mobile'] ?? '') . '"
            data-wa-url="' . e(pb_whatsapp_url($waRow)) . '"
            data-message="' . e(pb_whatsapp_message($waRow)) . '">
            ' . pb_whatsapp_svg() . '
        </button>
    ';
}


$products = [];
$printingTypes = [];
$printingSubTypes = [];
$statuses = [];
$functionTypes = [];
$quotations = [];

try {
    $res = $conn->query("SELECT id, product_name, default_price, default_order_type FROM products WHERE is_active = 1 ORDER BY product_name ASC");
    while ($row = $res->fetch_assoc()) {
        $products[] = $row;
    }
} catch (Throwable $e) {
}

try {
    $res = $conn->query("
        SELECT
            id,
            printing_name,
            COALESCE(printing_key, '') AS printing_key,
            LOWER(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(COALESCE(printing_key, printing_name), ' ', '_'),
                        '-', '_'),
                    '/', '_'),
                '__', '_')
            ) AS normalized_key
        FROM printing_types
        WHERE is_active = 1
        ORDER BY sort_order ASC, printing_name ASC
    ");
    while ($row = $res->fetch_assoc()) {
        $printingTypes[] = $row;
    }
} catch (Throwable $e) {
}

try {
    $res = $conn->query("SELECT id, printing_type_id, sub_type_name FROM printing_sub_types WHERE is_active = 1 ORDER BY sort_order ASC, sub_type_name ASC");
    while ($row = $res->fetch_assoc()) {
        $printingSubTypes[] = $row;
    }
} catch (Throwable $e) {
}

try {
    $res = $conn->query("SELECT id, function_name, function_key, field_group FROM function_types WHERE is_active = 1 ORDER BY sort_order ASC, function_name ASC");
    while ($row = $res->fetch_assoc()) {
        $functionTypes[] = $row;
    }
} catch (Throwable $e) {
}

try {
    $res = $conn->query("SELECT id, status_name, status_key FROM proforma_statuses WHERE is_active = 1 ORDER BY sort_order ASC");
    while ($row = $res->fetch_assoc()) {
        $statuses[] = $row;
    }
} catch (Throwable $e) {
}

try {
    $res = $conn->query("
        SELECT
            q.id,
            q.quotation_no,
            q.customer_name,
            q.mobile,
            q.address,
            q.function_type_id,
            q.bride_name,
            q.groom_name,
            q.venue,
            q.function_date,
            q.function_time,
            q.total_qty,
            q.sub_total,
            q.discount_amount,
            q.final_amount
        FROM quotations q
        ORDER BY q.id DESC
        LIMIT 200
    ");
    while ($row = $res->fetch_assoc()) {
        $quotations[] = $row;
    }
} catch (Throwable $e) {
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
    $token = (string)($_REQUEST['csrf_token'] ?? '');
    $valid = $token !== '' && (
        (!empty($_SESSION['proforma_csrf']) && hash_equals($_SESSION['proforma_csrf'], $token)) ||
        (!empty($_SESSION['create_proforma_csrf']) && hash_equals($_SESSION['create_proforma_csrf'], $token))
    );

    if (!$valid) {
        apiResponse(false, 'Invalid CSRF token.');
    }
}

function apiPermissionAllowed(mysqli $conn, string $permission): bool
{
    $currentPage = 'proforma_bills.php';

    if ($permission === 'can_create') {
        return function_exists('can_create') ? (bool)can_create($conn, $currentPage) : false;
    }

    if ($permission === 'can_edit') {
        return function_exists('can_edit') ? (bool)can_edit($conn, $currentPage) : false;
    }

    if ($permission === 'can_update') {
        return function_exists('can_update') ? (bool)can_update($conn, $currentPage) : false;
    }

    if ($permission === 'can_delete') {
        return function_exists('can_delete') ? (bool)can_delete($conn, $currentPage) : false;
    }

    if ($permission === 'can_send_whatsapp') {
        return function_exists('can_send_whatsapp') ? (bool)can_send_whatsapp($conn, $currentPage) : false;
    }

    return false;
}

function apiRequirePermission(mysqli $conn, string $permission, string $message): void
{
    if (!apiPermissionAllowed($conn, $permission)) {
        apiResponse(false, $message);
    }
}

function apiRequireAnyPermission(mysqli $conn, array $permissions, string $message): void
{
    foreach ($permissions as $permission) {
        if (apiPermissionAllowed($conn, (string)$permission)) {
            return;
        }
    }

    apiResponse(false, $message);
}

function apiProformaRow(mysqli $conn, int $id): ?array
{
    if ($id <= 0 || !pb_table_exists($conn, 'proforma_bills')) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            pb.*,
            ps.status_name,
            jc.job_card_no,
            ft.function_name,
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
            pbi.screening_type
        FROM proforma_bills pb
        LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id
        LEFT JOIN (SELECT proforma_bill_id, MAX(job_card_no) AS job_card_no FROM job_cards GROUP BY proforma_bill_id) jc ON jc.proforma_bill_id = pb.id
        LEFT JOIN function_types ft ON ft.id = pb.function_type_id
        LEFT JOIN proforma_bill_items pbi ON pbi.proforma_bill_id = pb.id
        WHERE pb.id = ?
        ORDER BY pbi.sort_order ASC, pbi.id ASC
        LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function apiProformaList(mysqli $conn): array
{
    if (!pb_table_exists($conn, 'proforma_bills')) {
        return [];
    }

    $rows = [];
    $res = $conn->query("
        SELECT
            pb.*,
            ps.status_name,
            jc.job_card_no,
            ft.function_name
        FROM proforma_bills pb
        LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id
        LEFT JOIN (SELECT proforma_bill_id, MAX(job_card_no) AS job_card_no FROM job_cards GROUP BY proforma_bill_id) jc ON jc.proforma_bill_id = pb.id
        LEFT JOIN function_types ft ON ft.id = pb.function_type_id
        ORDER BY pb.id DESC
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

    if (in_array($action, ['save_proforma', 'create', 'update', 'create_proforma', 'update_proforma', 'delete', 'delete_record', 'create_job_card', 'log_manual_whatsapp', 'send_whatsapp_api'], true)) {
        apiCsrf();
    }

    pb_ensure_optional_create_proforma_columns($conn);

    if ($action === 'list') {
        apiResponse(true, 'Proforma bills loaded successfully.', ['data' => apiProformaList($conn)]);
    }

    if ($action === 'view') {
        $id = pb_int($_REQUEST['id'] ?? 0);
        $row = apiProformaRow($conn, $id);

        if (!$row) {
            apiResponse(false, 'Proforma bill not found.');
        }

        apiResponse(true, 'Proforma bill loaded successfully.', ['data' => $row]);
    }

        $action = pb_post('action');

        if (in_array($action, ['save_proforma', 'create', 'update', 'create_proforma', 'update_proforma'], true)) {
            $id = pb_int($_POST['id'] ?? 0);

            if ($id > 0 || in_array($action, ['update', 'update_proforma'], true)) {
                apiRequireAnyPermission($conn, ['can_edit', 'can_update'], 'You do not have permission to edit proforma bills.');
            } else {
                apiRequirePermission($conn, 'can_create', 'You do not have permission to create proforma bills.');
            }

            $quotationId = pb_int($_POST['quotation_id'] ?? 0) ?: null;
            $functionTypeId = pb_function_type_id($conn, pb_post('function_type_id'));
            $orderType = pb_post('order_type', 'readymade');
            $customerName = pb_post('customer_name');
            $mobile = pb_post('mobile');
            $billingName = pb_post('billing_name');
            $billingMobile = pb_post('billing_mobile');
            $billingAddress = pb_post('billing_address');
            $gstNumber = pb_post('gst_number');
            $brideName = pb_post('bride_name');
            $groomName = pb_post('groom_name');
            $venue = pb_post('venue');
            $functionDate = pb_post('function_date') ?: null;
            $functionTime = pb_post('function_time') ?: null;
            $statusId = pb_int($_POST['proforma_status_id'] ?? 0) ?: null;
            $deliveryDate = pb_post('delivery_date') ?: null;
            $remarks = pb_post('remarks');
            $createJobCardNow = isset($_POST['create_job_card_now']) || (isset($_POST['auto_create_job_card']) && (string)$_POST['auto_create_job_card'] === '1');
            $plannedDates = pb_posted_planned_dates();

            $rawProductValue = pb_post('product_id');
            $productId = ($rawProductValue !== '' && ctype_digit($rawProductValue)) ? (int)$rawProductValue : null;
            $manualItemName = pb_post('item_name');
            if ($manualItemName === '') {
                $manualItemName = pb_post('product_name');
            }
            $description = pb_post('description');
            $qty = pb_float($_POST['qty'] ?? 1);
            $rate = pb_float($_POST['rate'] ?? 0);
            $printingPriceMasterId = pb_int($_POST['printing_price_master_id'] ?? 0) ?: null;
            $priceSlabText = pb_post('price_slab_text');
            $pricingPlateCharge = pb_float($_POST['pricing_plate_charge'] ?? 0);
            $pricingPrintingCharge = pb_float($_POST['pricing_printing_charge'] ?? 0);
            $pricingPackageCharge = pb_float($_POST['pricing_package_charge'] ?? 0);
            $pricingAdditionalCharge = pb_float($_POST['pricing_additional_charge'] ?? 0);
            $pricingIsGstInclusive = pb_int($_POST['pricing_is_gst_inclusive'] ?? 1) === 1 ? 1 : 0;
            $printingTypeId = pb_int($_POST['printing_type_id'] ?? 0) ?: null;
            $printingSubTypeId = pb_int($_POST['printing_sub_type_id'] ?? 0) ?: null;

            if ($orderType === 'customized') {
                $multiColorPrintingTypeId = pb_multicolor_printing_type_id($conn);
                if (!$multiColorPrintingTypeId) {
                    throw new RuntimeException('Multicolor Offset Print type is missing. Please add it in Printing Types master.');
                }

                $printingTypeId = $multiColorPrintingTypeId;
                $printingSubTypeId = null;
            }
            $finishingRequired = pb_int($_POST['finishing_required'] ?? 0) === 1 ? 1 : 0;
            $sizeText = pb_post('size_text');
            $gsmThickness = pb_post('gsm_thickness');
            $laminationRequired = pb_int($_POST['lamination_required'] ?? 0) === 1 ? 1 : 0;
            $laminationType = pb_post('lamination_type') ?: null;
            if ($laminationRequired !== 1) {
                $laminationType = null;
            }
            $printingSide = pb_post('printing_side') ?: null;
            $screeningType = pb_post('screening_type') ?: null;

            $discountAmount = pb_float($_POST['discount_amount'] ?? 0);
            /* Extra card charge is applicable for both readymade and customized orders. */
            $extraCardCharge = pb_float($_POST['extra_card_charge'] ?? 0);
            /* Package charge is common and printing charge is optional for all order types. */
            $packingCharge = pb_float($_POST['packing_charge'] ?? 0);
            $printingCharge = pb_float($_POST['printing_charge'] ?? 0);

            /*
             * Pricing master only pre-fills values in the form.
             * User can edit Rate / Plate-Additional / Package / Printing Charge.
             * Save the actual edited values, not only the original hidden master values.
             */
            if ($printingPriceMasterId) {
                $pricingPlateCharge = round(max(0, $extraCardCharge), 2);
                $pricingPrintingCharge = round(max(0, $printingCharge), 2);
                $pricingPackageCharge = round(max(0, $packingCharge), 2);
                $pricingAdditionalCharge = 0.00;
            }
            $gstPercent = pb_float($_POST['gst_percent'] ?? 18);
            if ($gstPercent < 0) $gstPercent = 0.0;

            /* Split payment: cash and UPI can be received together. */
            $cashAmount = pb_float($_POST['cash_amount'] ?? 0);
            $upiAmount = pb_float($_POST['upi_amount'] ?? 0);
            if ($cashAmount < 0 || $upiAmount < 0) {
                throw new RuntimeException('Cash and UPI amounts cannot be negative.');
            }
            $advanceAmount = round($cashAmount + $upiAmount, 2);
            $paymentMode = ($cashAmount > 0 && $upiAmount > 0) ? 'split' : (($upiAmount > 0) ? 'upi' : 'cash');
            $paymentRef = pb_post('payment_reference');
            $cashRef = pb_post('cash_reference');
            $upiRef = pb_post('upi_reference');

            if (!in_array($orderType, ['readymade', 'customized'], true)) {
                throw new RuntimeException('Invalid order type.');
            }

            if ($customerName === '' || $mobile === '') {
                throw new RuntimeException('Customer name and mobile number are required.');
            }

            $selectedFieldGroup = 'other';
            foreach ($functionTypes as $ft) {
                if ((int)$ft['id'] === (int)$functionTypeId) {
                    $selectedFieldGroup = (string)$ft['field_group'];
                    break;
                }
            }

            if (!$functionTypeId) {
                throw new RuntimeException('Please select function / product type.');
            }

            if ($selectedFieldGroup === 'wedding_reception') {
                if ($brideName === '' || $groomName === '' || $venue === '' || !$functionDate) {
                    throw new RuntimeException('Bride, groom, venue and function date are required for Wedding / Reception. Function time is optional.');
                }
            } elseif ($selectedFieldGroup === 'event') {
                if ($venue === '' || !$functionDate) {
                    throw new RuntimeException('Venue and function date are required for this function type. Function time is optional.');
                }
            } elseif ($selectedFieldGroup === 'business_print') {
                if ($billingAddress === '') {
                    throw new RuntimeException('Address is required for Visiting Card / Bill Book / Brochure / Pamphlet.');
                }
            }

            if ($manualItemName === '' && !$productId) {
                throw new RuntimeException('Please select product or enter product name.');
            }

            if ($orderType === 'readymade') {
                if (!$printingTypeId) {
                    throw new RuntimeException('Please select printing type for readymade order.');
                }

                if (!pb_printing_type_allowed_for_readymade($conn, $printingTypeId)) {
                    throw new RuntimeException('Readymade order allows only Offset Print, Screen Print, or Digital Print.');
                }

                if (pb_is_screen_printing_type($conn, $printingTypeId) && !$printingSubTypeId) {
                    throw new RuntimeException('Please select Screen Print sub-type: UV Products or Foil Products.');
                }

                if (in_array(strtolower((string)$laminationType), ['none', 'not_applicable'], true)) {
                    $laminationRequired = 0;
                    $laminationType = null;
                }
                $screeningType = null;
            }

            if ($orderType === 'customized') {
                if ($sizeText === '') {
                    $sizeText = '22x8.5';
                }

                if ($gsmThickness === '') {
                    $gsmThickness = '300';
                }

                if (!$printingSide) {
                    throw new RuntimeException('Please select Single Side Scoring or Double Side Scoring.');
                }

                if (!$screeningType) {
                    throw new RuntimeException('Please select Regular Screening or Special Screening.');
                }

                if ($laminationRequired === 1 && !$laminationType) {
                    throw new RuntimeException('Please select lamination type.');
                }

                $finishingRequired = 0;
            }

            if ($qty <= 0) {
                throw new RuntimeException('Quantity must be greater than zero.');
            }

            /*
             * Item rate is optional.
             * When left blank/0, amount is calculated from Printing Charge + Plate/Additional + Package.
             * If user manually enters rate, item amount = qty * rate.
             */
            if ($rate < 0) {
                throw new RuntimeException('Price / rate cannot be negative.');
            }

            if ($discountAmount < 0) {
                throw new RuntimeException('Discount cannot be negative.');
            }

            if ($extraCardCharge < 0) {
                throw new RuntimeException('Extra card charge cannot be negative.');
            }

            if ($packingCharge < 0) {
                throw new RuntimeException('Packing charge cannot be negative.');
            }

            if ($printingCharge < 0) {
                throw new RuntimeException('Printing charge cannot be negative.');
            }

            if ($advanceAmount < 0) {
                throw new RuntimeException('Advance amount cannot be negative.');
            }

            $userId = (int)($_SESSION['user_id'] ?? 0);
            $productInfo = pb_find_or_create_product($conn, $rawProductValue, $manualItemName, $orderType, $rate, $userId);
            $productId = $productInfo['id'];
            $productName = trim((string)$productInfo['name']);

            if ($productName === '') {
                $productName = 'Invitation Cards';
            }

            $amount = round($qty * $rate, 2);
            $subTotal = $amount;

            $grossBeforeDiscount = round($subTotal + $extraCardCharge + $packingCharge + $printingCharge, 2);
            if ($discountAmount > $grossBeforeDiscount) {
                throw new RuntimeException('Discount cannot be greater than total amount.');
            }

            $finalAmount = round(max(0, $grossBeforeDiscount - $discountAmount), 2);
            /* Inclusive GST breakup: final amount is inclusive of GST. */
            $taxableValue = $gstPercent > 0 ? round($finalAmount / (1 + ($gstPercent / 100)), 2) : $finalAmount;
            $gstAmount = round(max(0, $finalAmount - $taxableValue), 2);

            if ($advanceAmount > $finalAmount) {
                throw new RuntimeException('Advance cannot be greater than final amount.');
            }

            $balanceAmount = round(max(0, $finalAmount - $advanceAmount), 2);

            $cashDenomination = ['rows' => [], 'summary' => [], 'summary_text' => '', 'total' => 0.0];
            if ($cashAmount > 0) {
                $cashDenomination = pb_cash_denomination_payload();
                if (abs((float)$cashDenomination['total'] - $cashAmount) > 0.009) {
                    throw new RuntimeException('Cash denomination total must match the cash amount.');
                }
            }

            $totalQty = $qty;
            $customerId = pb_customer_id($conn, $customerName, $mobile, $billingAddress, $gstNumber);

            $conn->begin_transaction();

            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE proforma_bills
                    SET quotation_id = ?,
                        customer_id = ?,
                        function_type_id = ?,
                        order_type = ?,
                        customer_name = ?,
                        mobile = ?,
                        billing_name = ?,
                        billing_mobile = ?,
                        billing_address = ?,
                        gst_number = ?,
                        bride_name = ?,
                        groom_name = ?,
                        venue = ?,
                        function_date = ?,
                        function_time = ?,
                        proforma_status_id = ?,
                        total_qty = ?,
                        sub_total = ?,
                        discount_amount = ?,
                        card_extra_charge = ?,
                        packing_charge = ?,
                        printing_charge = ?,
                        gst_percent = ?,
                        taxable_value = ?,
                        gst_amount = ?,
                        final_amount = ?,
                        advance_amount = ?,
                        balance_amount = ?,
                        delivery_date = ?,
                        remarks = ?,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param(
                    'iiissssssssssssiddddddddddddssii',
                    $quotationId,
                    $customerId,
                    $functionTypeId,
                    $orderType,
                    $customerName,
                    $mobile,
                    $billingName,
                    $billingMobile,
                    $billingAddress,
                    $gstNumber,
                    $brideName,
                    $groomName,
                    $venue,
                    $functionDate,
                    $functionTime,
                    $statusId,
                    $totalQty,
                    $subTotal,
                    $discountAmount,
                    $extraCardCharge,
                    $packingCharge,
                    $printingCharge,
                    $gstPercent,
                    $taxableValue,
                    $gstAmount,
                    $finalAmount,
                    $advanceAmount,
                    $balanceAmount,
                    $deliveryDate,
                    $remarks,
                    $userId,
                    $id
                );
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM proforma_bill_items WHERE proforma_bill_id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                $proformaId = $id;
                pb_log($conn, 'update', 'Proforma Bills', $proformaId, 'Proforma bill updated.');
            } else {
                $proformaNo = pb_next_no($conn, 'proforma_bills', 'proforma_no', 'SC-PRO');

                if (!$statusId) {
                    $statusId = pb_status_id($conn, 'proforma_statuses', 'status_key', 'confirmed');
                }

                $stmt = $conn->prepare("
                    INSERT INTO proforma_bills
                        (
                            proforma_no,
                            quotation_id,
                            customer_id,
                            function_type_id,
                            order_type,
                            customer_name,
                            mobile,
                            billing_name,
                            billing_mobile,
                            billing_address,
                            gst_number,
                            bride_name,
                            groom_name,
                            venue,
                            function_date,
                            function_time,
                            proforma_status_id,
                            total_qty,
                            sub_total,
                            discount_amount,
                            card_extra_charge,
                            packing_charge,
                            printing_charge,
                            gst_percent,
                            taxable_value,
                            gst_amount,
                            final_amount,
                            advance_amount,
                            balance_amount,
                            delivery_date,
                            remarks,
                            created_by,
                            created_at,
                            updated_at
                        )
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->bind_param(
                    'siiissssssssssssiddddddddddddssi',
                    $proformaNo,
                    $quotationId,
                    $customerId,
                    $functionTypeId,
                    $orderType,
                    $customerName,
                    $mobile,
                    $billingName,
                    $billingMobile,
                    $billingAddress,
                    $gstNumber,
                    $brideName,
                    $groomName,
                    $venue,
                    $functionDate,
                    $functionTime,
                    $statusId,
                    $totalQty,
                    $subTotal,
                    $discountAmount,
                    $extraCardCharge,
                    $packingCharge,
                    $printingCharge,
                    $gstPercent,
                    $taxableValue,
                    $gstAmount,
                    $finalAmount,
                    $advanceAmount,
                    $balanceAmount,
                    $deliveryDate,
                    $remarks,
                    $userId
                );
                $stmt->execute();
                $proformaId = (int)$stmt->insert_id;
                $stmt->close();

                pb_log($conn, 'create_proforma_bill', 'Proforma Bills', $proformaId, 'Proforma bill created.');
            }

            $stmt = $conn->prepare("
                INSERT INTO proforma_bill_items
                    (
                        proforma_bill_id,
                        product_id,
                        item_name,
                        description,
                        qty,
                        rate,
                        amount,
                        printing_type_id,
                        printing_sub_type_id,
                        finishing_required,
                        size_text,
                        gsm_thickness,
                        lamination_required,
                        lamination_type,
                        printing_side,
                        screening_type,
                        printing_price_master_id,
                        price_slab_text,
                        plate_charge,
                        item_printing_charge,
                        item_package_charge,
                        item_additional_charge,
                        is_gst_inclusive,
                        sort_order,
                        created_at
                    )
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->bind_param(
                'iissdddiiississsisddddi',
                $proformaId,
                $productId,
                $productName,
                $description,
                $qty,
                $rate,
                $amount,
                $printingTypeId,
                $printingSubTypeId,
                $finishingRequired,
                $sizeText,
                $gsmThickness,
                $laminationRequired,
                $laminationType,
                $printingSide,
                $screeningType,
                $printingPriceMasterId,
                $priceSlabText,
                $pricingPlateCharge,
                $pricingPrintingCharge,
                $pricingPackageCharge,
                $pricingAdditionalCharge,
                $pricingIsGstInclusive
            );
            $stmt->execute();
            $stmt->close();

            if ($advanceAmount > 0 && $id <= 0) {
                $paymentType = $balanceAmount <= 0 ? 'full' : 'advance';
                $today = date('Y-m-d');

                $saveAdvancePayment = function (string $mode, float $amountValue, string $referenceNo, string $remarksText) use ($conn, $customerId, $proformaId, $paymentType, $today, $userId): int {
                    if ($amountValue <= 0) {
                        return 0;
                    }

                    $paymentNo = pb_next_no($conn, 'payments', 'payment_no', 'SC-PAY');
                    $stmt = $conn->prepare("
                        INSERT INTO payments
                            (
                                customer_id,
                                proforma_bill_id,
                                payment_no,
                                payment_type,
                                payment_mode,
                                amount,
                                payment_date,
                                reference_no,
                                remarks,
                                received_by,
                                created_at
                            )
                        VALUES
                            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    "
                    );
                    $stmt->bind_param(
                        'iisssdsssi',
                        $customerId,
                        $proformaId,
                        $paymentNo,
                        $paymentType,
                        $mode,
                        $amountValue,
                        $today,
                        $referenceNo,
                        $remarksText,
                        $userId
                    );
                    $stmt->execute();
                    $paymentId = (int)$conn->insert_id;
                    $stmt->close();

                    return $paymentId;
                };

                $cashPaymentId = $saveAdvancePayment('cash', $cashAmount, $cashRef ?: $paymentRef, 'Advance cash collected from proforma bill');
                if ($cashPaymentId > 0) {
                    pb_save_cash_denominations($conn, $cashPaymentId, $cashDenomination, $userId);
                }

                $saveAdvancePayment('upi', $upiAmount, $upiRef ?: $paymentRef, 'Advance UPI collected from proforma bill');

                pb_log($conn, 'collect_payment', 'Payments', $proformaId, 'Advance split payment collected. Cash: ' . number_format($cashAmount, 2, '.', '') . ', UPI: ' . number_format($upiAmount, 2, '.', ''));
            }


            $conn->commit();

            $isNewProforma = $id <= 0;
            $jobId = 0;
            $createdJobCard = false;

            if ($createJobCardNow) {
                $conn->begin_transaction();
                $jobId = pb_create_job_card($conn, $proformaId, $plannedDates);
                $conn->commit();
                $createdJobCard = true;
            }

            $createdJobCardNo = '';
            if ($createdJobCard && $jobId > 0 && pb_table_exists($conn, 'job_cards')) {
                $stmt = $conn->prepare("SELECT job_card_no FROM job_cards WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $jobId);
                $stmt->execute();
                $jobRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $createdJobCardNo = (string)($jobRow['job_card_no'] ?? '');
            }

            if (empty($proformaNo)) {
                $stmt = $conn->prepare("SELECT proforma_no FROM proforma_bills WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $proformaId);
                $stmt->execute();
                $pbRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $proformaNo = (string)($pbRow['proforma_no'] ?? '');
            }

            $responseData = [
                'id' => $proformaId,
                'proforma_id' => $proformaId,
                'proforma_no' => $proformaNo,
                'redirect_url' => 'proforma_bills.php',
                'updated' => !$isNewProforma
            ];

            if ($createdJobCard) {
                $responseData['job_id'] = $jobId;
                $responseData['job_card_no'] = $createdJobCardNo;
                $responseData['created_job_card'] = true;
            }

            $responseMessage = $createdJobCard
                ? 'Proforma bill saved and job card created successfully.'
                : ($isNewProforma ? 'Proforma bill created successfully.' : 'Proforma bill updated successfully.');

            if ($isNewProforma) {
                $whatsappResult = pb_send_whatsapp_preferred($conn, $proformaId);
                $responseData['whatsapp'] = $whatsappResult;
                $responseData['whatsapp_sent'] = (bool)($whatsappResult['success'] ?? false);
                $responseData['whatsapp_mode'] = (string)($whatsappResult['mode'] ?? '');

                if (!empty($whatsappResult['manual_whatsapp'])) {
                    $responseData['manual_whatsapp'] = true;
                    $responseData['open_whatsapp_url'] = (string)($whatsappResult['open_whatsapp_url'] ?? '');
                }

                if ($whatsappResult['success'] ?? false) {
                    $responseMessage .= ((string)($whatsappResult['mode'] ?? '') === 'manual')
                        ? ' Manual WhatsApp mode opened.'
                        : ' WhatsApp message sent successfully.';
                } else {
                    $responseMessage .= ' WhatsApp failed: ' . (string)($whatsappResult['message'] ?? 'Unknown error.');
                }
            }

            apiResponse(true, $responseMessage, $responseData);
        }


        if ($action === 'log_manual_whatsapp') {
            apiRequirePermission($conn, 'can_send_whatsapp', 'You do not have permission to send WhatsApp messages.');
            $id = pb_int($_POST['id'] ?? 0);

            if ($id <= 0) {
                apiResponse(false, 'Invalid proforma bill.');
            }

            $manualResult = pb_whatsapp_log_manual($conn, $id);
            apiResponse((bool)($manualResult['success'] ?? false), (string)($manualResult['message'] ?? ''), $manualResult);
        }

        if ($action === 'send_whatsapp_api') {
            apiRequirePermission($conn, 'can_send_whatsapp', 'You do not have permission to send WhatsApp messages.');
            $id = pb_int($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Invalid proforma bill.');
            }

            $waResult = pb_send_whatsapp_preferred($conn, $id);

            if (!($waResult['success'] ?? false)) {
                apiResponse(false, (string)($waResult['message'] ?? $waResult['response'] ?? 'WhatsApp failed.'), $waResult);
            }

            apiResponse(true, (string)($waResult['message'] ?? 'WhatsApp message sent successfully.'), $waResult);
        }


        if (in_array($action, ['delete', 'delete_record'], true)) {
            apiRequirePermission($conn, 'can_delete', 'You do not have permission to delete proforma bills.');
            $id = pb_int($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Invalid proforma bill.');
            }

            if (!pb_table_exists($conn, 'proforma_bills')) {
                throw new RuntimeException('proforma_bills table is missing.');
            }

            $stmt = $conn->prepare("SELECT id, proforma_no FROM proforma_bills WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $bill = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$bill) {
                throw new RuntimeException('Proforma bill not found.');
            }

            $conn->begin_transaction();

            $jobIds = [];
            if (pb_table_exists($conn, 'job_cards')) {
                $stmt = $conn->prepare("SELECT id FROM job_cards WHERE proforma_bill_id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($job = $res->fetch_assoc()) {
                    $jobIds[] = (int)$job['id'];
                }
                $stmt->close();
            }

            foreach ($jobIds as $jobId) {
                if (pb_table_exists($conn, 'job_tracking')) {
                    $stmt = $conn->prepare("DELETE FROM job_tracking WHERE job_card_id = ?");
                    $stmt->bind_param('i', $jobId);
                    $stmt->execute();
                    $stmt->close();
                }

                if (pb_table_exists($conn, 'job_card_items')) {
                    $stmt = $conn->prepare("DELETE FROM job_card_items WHERE job_card_id = ?");
                    $stmt->bind_param('i', $jobId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if (pb_table_exists($conn, 'job_cards')) {
                $stmt = $conn->prepare("DELETE FROM job_cards WHERE proforma_bill_id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }

            if (pb_table_exists($conn, 'payment_cash_denominations') && pb_table_exists($conn, 'payments') && apiColumnExists($conn, 'payments', 'proforma_bill_id')) {
                $stmt = $conn->prepare("
                    DELETE pcd
                    FROM payment_cash_denominations pcd
                    INNER JOIN payments p ON p.id = pcd.payment_id
                    WHERE p.proforma_bill_id = ?
                ");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }

            if (pb_table_exists($conn, 'payments') && apiColumnExists($conn, 'payments', 'proforma_bill_id')) {
                $stmt = $conn->prepare("DELETE FROM payments WHERE proforma_bill_id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }

            if (pb_table_exists($conn, 'proforma_bill_items')) {
                $stmt = $conn->prepare("DELETE FROM proforma_bill_items WHERE proforma_bill_id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("DELETE FROM proforma_bills WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            pb_log($conn, 'delete', 'Proforma Bills', $id, 'Proforma bill deleted: ' . ($bill['proforma_no'] ?? $id));

            apiResponse(true, 'Proforma bill deleted successfully.', ['id' => $id]);
        }

        if ($action === 'create_job_card') {
            apiRequireAnyPermission($conn, ['can_create', 'can_update'], 'You do not have permission to create job cards from proforma bills.');
            $proformaId = pb_int($_POST['proforma_id'] ?? 0);

            if ($proformaId <= 0) {
                throw new RuntimeException('Invalid proforma bill.');
            }

            $plannedDates = pb_posted_planned_dates();
            $conn->begin_transaction();
            $jobId = pb_create_job_card($conn, $proformaId, $plannedDates);
            $conn->commit();

            apiResponse(true, 'Job card created successfully with tracking stages.', [
                'proforma_id' => $proformaId,
                'job_id' => $jobId
            ]);
        }


    apiResponse(false, 'Invalid action.');
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
    }

    apiResponse(false, $e->getMessage());
}