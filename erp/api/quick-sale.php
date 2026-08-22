<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function qs_api_response(bool $status, string $message, array $data = []): void
{
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message
    ], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function qs_api_table_exists(mysqli $conn, string $table): bool
{
    try {
        $safe = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}



function qs_api_column_exists(mysqli $conn, string $table, string $column): bool
{
    try {
        $safeTable = $conn->real_escape_string($table);
        $safeColumn = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function qs_api_public_invoice_url(string $token): string
{
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/api/quick-sale.php'));

    // API lives in /api. Move one level up to the ERP root.
    $root = rtrim(dirname(dirname($script)), '/');
    if ($root === '.' || $root === '/') {
        $root = '';
    }

    if ($host === '') {
        return $root . '/quick_sale_invoice_pdf.php?token='
            . rawurlencode($token) . '&download=1';
    }

    return $scheme . '://' . $host . $root
        . '/quick_sale_invoice_pdf.php?token='
        . rawurlencode($token) . '&download=1';
}

function qs_api_send_invoice_whatsapp(
    mysqli $conn,
    int $quickSaleId,
    string $saleNo,
    string $customerName,
    string $mobile,
    float $total,
    string $invoiceToken,
    ?int $sentBy
): array {
    $apiFile = __DIR__ . '/../includes/whatsapp-api.php';

    if (!is_file($apiFile)) {
        return [
            'success' => false,
            'message' => 'WhatsApp API file is missing.',
            'log_id' => 0
        ];
    }

    require_once $apiFile;

    if (!function_exists('subhiksha_send_whatsapp')) {
        return [
            'success' => false,
            'message' => 'WhatsApp sending function is unavailable.',
            'log_id' => 0
        ];
    }

    $invoiceUrl = qs_api_public_invoice_url($invoiceToken);

    /*
     * Use the existing live Meta sender without changing its configuration.
     * This is a plain WhatsApp message containing the secure invoice link.
     * Meta may reject non-template messages when no 24-hour customer-service
     * conversation window is open; the Quick Sale itself is never rolled back.
     */
    $message =
        "Dear {$customerName},\n\n"
        . "Thank you for your purchase from Subhiksha Cards.\n"
        . "Quick Sale Invoice: {$saleNo}\n"
        . "Total Amount: ₹" . number_format($total, 2) . "\n\n"
        . "Download Invoice:\n{$invoiceUrl}\n\n"
        . "Thank you.";

    return subhiksha_send_whatsapp($conn, [
        'mobile' => $mobile,
        'message' => $message,
        'related_module' => 'Quick Sale',
        'related_id' => $quickSaleId,
        'customer_id' => null,
        'sent_by' => $sentBy,
    ]);
}

function qs_api_is_admin_role(mysqli $conn): bool
{
    $sessionRoleKey = strtolower(trim((string)($_SESSION['role_key'] ?? '')));
    $sessionRoleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));

    if (
        in_array($sessionRoleKey, ['admin', 'super_admin', 'business_admin'], true) ||
        $sessionRoleName === 'admin'
    ) {
        return true;
    }

    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($roleId <= 0) {
        return false;
    }

    try {
        $stmt = $conn->prepare("
            SELECT role_key, role_name
            FROM roles
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $roleKey = strtolower(trim((string)($row['role_key'] ?? '')));
        $roleName = strtolower(trim((string)($row['role_name'] ?? '')));

        return
            in_array($roleKey, ['admin', 'super_admin', 'business_admin'], true) ||
            $roleName === 'admin';
    } catch (Throwable $e) {
        return false;
    }
}




function qs_api_ensure_payment_tables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS quick_sale_payments (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            quick_sale_id BIGINT(20) UNSIGNED NOT NULL,
            payment_no VARCHAR(60) NOT NULL,
            payment_mode ENUM('cash','upi') NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            tendered_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            return_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            payment_date DATE NOT NULL,
            reference_no VARCHAR(150) DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            received_by BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_quick_sale_payment_no (payment_no),
            KEY idx_quick_sale_payments_sale (quick_sale_id),
            KEY idx_quick_sale_payments_mode (payment_mode),
            KEY idx_quick_sale_payments_date (payment_date),
            CONSTRAINT fk_quick_sale_payments_sale
                FOREIGN KEY (quick_sale_id) REFERENCES quick_sales(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS quick_sale_cash_denominations (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            quick_sale_payment_id BIGINT(20) UNSIGNED NOT NULL,
            denomination_type ENUM('note','coin') NOT NULL,
            denomination_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            denomination_count INT UNSIGNED NOT NULL DEFAULT 0,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            created_by BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_qs_cash_payment (quick_sale_payment_id),
            CONSTRAINT fk_qs_cash_payment
                FOREIGN KEY (quick_sale_payment_id) REFERENCES quick_sale_payments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function qs_api_payment_no(mysqli $conn): string
{
    $prefix = 'QS-PAY-' . date('ymd') . '-';
    $like = $prefix . '%';

    $stmt = $conn->prepare("
        SELECT payment_no
        FROM quick_sale_payments
        WHERE payment_no LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = 1;
    if (!empty($row['payment_no']) && preg_match('/-(\d+)$/', (string)$row['payment_no'], $m)) {
        $next = ((int)$m[1]) + 1;
    }

    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function qs_api_valid_date(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    [$y, $m, $d] = array_map('intval', explode('-', $date));
    return checkdate($m, $d, $y);
}

function qs_api_cash_denomination_master(): array
{
    return [
        ['field' => 'cash_note_500', 'type' => 'note', 'value' => 500],
        ['field' => 'cash_note_200', 'type' => 'note', 'value' => 200],
        ['field' => 'cash_note_100', 'type' => 'note', 'value' => 100],
        ['field' => 'cash_note_50',  'type' => 'note', 'value' => 50],
        ['field' => 'cash_note_20',  'type' => 'note', 'value' => 20],
        ['field' => 'cash_note_10',  'type' => 'note', 'value' => 10],
        ['field' => 'cash_coin_20',  'type' => 'coin', 'value' => 20],
        ['field' => 'cash_coin_10',  'type' => 'coin', 'value' => 10],
        ['field' => 'cash_coin_5',   'type' => 'coin', 'value' => 5],
        ['field' => 'cash_coin_2',   'type' => 'coin', 'value' => 2],
        ['field' => 'cash_coin_1',   'type' => 'coin', 'value' => 1],
    ];
}

function qs_api_cash_denomination_payload(): array
{
    $rows = [];
    $total = 0.0;

    foreach (qs_api_cash_denomination_master() as $meta) {
        $count = max(0, (int)($_POST[$meta['field']] ?? 0));
        $value = (float)$meta['value'];
        $amount = round($count * $value, 2);
        $total += $amount;

        $rows[] = [
            'type' => (string)$meta['type'],
            'value' => $value,
            'count' => $count,
            'amount' => $amount,
        ];
    }

    return [
        'rows' => $rows,
        'total' => round($total, 2),
    ];
}

function qs_api_payment_breakdown(
    float $saleTotal,
    bool $useCash,
    float $cashTendered,
    bool $useUpi,
    float $upiAmount
): array {
    $saleTotal = round(max(0, $saleTotal), 2);
    $cashTendered = $useCash ? round(max(0, $cashTendered), 2) : 0.0;
    $upiAmount = $useUpi ? round(max(0, $upiAmount), 2) : 0.0;

    if ($saleTotal <= 0) {
        throw new RuntimeException('Quick Sale Total must be greater than zero.');
    }

    if (!$useCash && !$useUpi) {
        throw new RuntimeException('Select Cash, UPI or both.');
    }

    if ($useCash && $cashTendered <= 0) {
        throw new RuntimeException('Cash Received must be greater than zero.');
    }

    if ($useUpi && $upiAmount <= 0) {
        throw new RuntimeException('UPI Amount must be greater than zero.');
    }

    if ($upiAmount > $saleTotal + 0.009) {
        throw new RuntimeException(
            'UPI Amount cannot exceed Quick Sale Total. Excess/Return is allowed only through Cash.'
        );
    }

    $cashRequired = round(max(0, $saleTotal - $upiAmount), 2);

    if (!$useCash) {
        if (abs($upiAmount - $saleTotal) > 0.009) {
            throw new RuntimeException('UPI-only payment must exactly match Quick Sale Total.');
        }

        return [
            'cash_tendered' => 0.0,
            'cash_applied' => 0.0,
            'upi_amount' => $saleTotal,
            'total_received' => $saleTotal,
            'total_applied' => $saleTotal,
            'return_amount' => 0.0,
        ];
    }

    if ($useUpi && $cashRequired <= 0.009) {
        throw new RuntimeException(
            'UPI already covers the Quick Sale Total. Unselect Cash or reduce UPI Amount.'
        );
    }

    if ($cashTendered + 0.009 < $cashRequired) {
        $short = round($cashRequired - $cashTendered, 2);
        throw new RuntimeException(
            'Payment is short by ₹' . number_format($short, 2) . '.'
        );
    }

    $cashApplied = $cashRequired;
    $returnAmount = round(max(0, $cashTendered - $cashApplied), 2);
    $totalReceived = round($cashTendered + $upiAmount, 2);
    $totalApplied = round($cashApplied + $upiAmount, 2);

    if (abs($totalApplied - $saleTotal) > 0.009) {
        throw new RuntimeException('Payment split does not match Quick Sale Total.');
    }

    return [
        'cash_tendered' => $cashTendered,
        'cash_applied' => $cashApplied,
        'upi_amount' => $upiAmount,
        'total_received' => $totalReceived,
        'total_applied' => $totalApplied,
        'return_amount' => $returnAmount,
    ];
}

function qs_api_save_cash_denominations(
    mysqli $conn,
    int $paymentId,
    array $payload,
    ?int $userId
): void {
    $stmt = $conn->prepare("
        INSERT INTO quick_sale_cash_denominations
            (
                quick_sale_payment_id,
                denomination_type,
                denomination_value,
                denomination_count,
                amount,
                created_by,
                created_at
            )
        VALUES
            (?, ?, ?, ?, ?, ?, NOW())
    ");

    foreach (($payload['rows'] ?? []) as $row) {
        $type = (string)($row['type'] ?? 'note');
        $value = (float)($row['value'] ?? 0);
        $count = max(0, (int)($row['count'] ?? 0));
        $amount = round($count * $value, 2);
        $stmt->bind_param('isdidi', $paymentId, $type, $value, $count, $amount, $userId);
        $stmt->execute();
    }

    $stmt->close();
}

function qs_api_insert_payment(
    mysqli $conn,
    int $quickSaleId,
    string $mode,
    float $amount,
    float $tendered,
    float $returnAmount,
    string $paymentDate,
    string $reference,
    string $remarks,
    ?int $userId
): int {
    $paymentNo = qs_api_payment_no($conn);

    $stmt = $conn->prepare("
        INSERT INTO quick_sale_payments
            (
                quick_sale_id,
                payment_no,
                payment_mode,
                amount,
                tendered_amount,
                return_amount,
                payment_date,
                reference_no,
                remarks,
                received_by,
                created_at
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->bind_param(
        'issdddsssi',
        $quickSaleId,
        $paymentNo,
        $mode,
        $amount,
        $tendered,
        $returnAmount,
        $paymentDate,
        $reference,
        $remarks,
        $userId
    );
    $stmt->execute();
    $paymentId = (int)$stmt->insert_id;
    $stmt->close();

    return $paymentId;
}


function qs_api_find_or_create_product(
    mysqli $conn,
    int $productId,
    string $productName,
    float $defaultPrice,
    ?int $userId
): array {
    $productName = trim($productName);
    $price = round(max(0, $defaultPrice), 2);
    $createdBy = $userId && $userId > 0 ? $userId : null;

    if ($productId > 0) {
        $stmt = $conn->prepare("
            SELECT
                id,
                product_name,
                default_price,
                is_active,
                COALESCE(is_removed, 0) AS is_removed
            FROM products
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('Selected Product Master was not found.');
        }

        if ((int)$row['is_removed'] === 1 || (int)$row['is_active'] !== 1) {
            throw new RuntimeException(
                $row['product_name'] . ' exists in Product Master but is inactive/removed. Restore it before Quick Sale.'
            );
        }

        $row['was_created'] = false;
        $row['was_restored'] = false;
        return $row;
    }

    if ($productName === '') {
        throw new RuntimeException('Product Name is required.');
    }

    /*
     * A typed Select2 tag is a genuine Product Master creation request.
     * First check the complete master table, including removed/inactive rows.
     */
    $stmt = $conn->prepare("
        SELECT
            id,
            product_name,
            default_price,
            is_active,
            COALESCE(is_removed, 0) AS is_removed
        FROM products
        WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))
        ORDER BY id ASC
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param('s', $productName);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        /*
         * It may be hidden from the Quick Sale dropdown because it was removed
         * or inactive. Re-activate the SAME master instead of creating a
         * duplicate product with the same name.
         */
        if ((int)$existing['is_removed'] === 1 || (int)$existing['is_active'] !== 1) {
            $stmt = $conn->prepare("
                UPDATE products
                SET
                    is_active = 1,
                    is_removed = 0,
                    removed_at = NULL,
                    removed_by = NULL,
                    removal_reason = NULL,
                    default_price = CASE
                        WHEN ? > 0 THEN ?
                        ELSE default_price
                    END,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param(
                'ddii',
                $price,
                $price,
                $createdBy,
                $existing['id']
            );
            $stmt->execute();
            $stmt->close();

            $existing['is_active'] = 1;
            $existing['is_removed'] = 0;
            $existing['default_price'] = $price > 0 ? $price : (float)$existing['default_price'];
            $existing['was_created'] = false;
            $existing['was_restored'] = true;
        } else {
            $existing['was_created'] = false;
            $existing['was_restored'] = false;
        }

        $existingId = (int)$existing['id'];

        $stmt = $conn->prepare("
            INSERT INTO product_stock
                (
                    product_id,
                    on_hand_stock,
                    reserved_stock,
                    minimum_stock,
                    low_stock_alert,
                    created_at
                )
            VALUES
                (?, 0, 0, 0, 0, NOW())
            ON DUPLICATE KEY UPDATE
                updated_at = NOW()
        ");
        $stmt->bind_param('i', $existingId);
        $stmt->execute();
        $stmt->close();

        return $existing;
    }

    /*
     * Create a minimal Product Master directly from Quick Sale.
     * Quick Sale intentionally requires only Product Name + Qty + Price.
     * Thumbnail/secondary images can be added later from Product Master.
     */
    $stmt = $conn->prepare("
        INSERT INTO products
            (
                product_name,
                default_order_type,
                default_price,
                is_active,
                is_removed,
                created_by,
                created_at
            )
        VALUES
            (?, 'readymade', ?, 1, 0, ?, NOW())
    ");
    $stmt->bind_param('sdi', $productName, $price, $createdBy);
    $stmt->execute();
    $newProductId = (int)$stmt->insert_id;
    $stmt->close();

    if ($newProductId <= 0) {
        throw new RuntimeException('Unable to create new Product Master from Quick Sale.');
    }

    $stmt = $conn->prepare("
        INSERT INTO product_stock
            (
                product_id,
                on_hand_stock,
                reserved_stock,
                minimum_stock,
                low_stock_alert,
                created_at
            )
        VALUES
            (?, 0, 0, 0, 0, NOW())
        ON DUPLICATE KEY UPDATE
            updated_at = NOW()
    ");
    $stmt->bind_param('i', $newProductId);
    $stmt->execute();
    $stmt->close();

    /*
     * Read it back. This makes the save fail immediately if Product Master
     * creation did not actually persist inside the transaction.
     */
    $stmt = $conn->prepare("
        SELECT
            id,
            product_name,
            default_price,
            is_active,
            COALESCE(is_removed, 0) AS is_removed
        FROM products
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $newProductId);
    $stmt->execute();
    $created = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$created) {
        throw new RuntimeException('New Product Master verification failed.');
    }

    $created['was_created'] = true;
    $created['was_restored'] = false;
    return $created;
}

function qs_api_next_no(mysqli $conn): string
{
    $prefix = 'QS-' . date('ymd') . '-';
    $like = $prefix . '%';

    $stmt = $conn->prepare("
        SELECT sale_no
        FROM quick_sales
        WHERE sale_no LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = 1;

    if (!empty($row['sale_no']) && preg_match('/-(\d+)$/', (string)$row['sale_no'], $match)) {
        $next = ((int)$match[1]) + 1;
    }

    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }

    if (!qs_api_is_admin_role($conn)) {
        if (function_exists('permission_allowed')) {
            if (!permission_allowed($conn, 'can_create', 'quick-sale.php')) {
                throw new RuntimeException('You do not have permission to create Quick Sale.');
            }
        } elseif (function_exists('can_create') && !can_create($conn, 'quick-sale.php')) {
            throw new RuntimeException('You do not have permission to create Quick Sale.');
        }
    }

    $csrf = (string)($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string)($_SESSION['quick_sale_csrf'] ?? '');

    if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        throw new RuntimeException('Invalid CSRF token.');
    }

    foreach (['quick_sales', 'quick_sale_items', 'products', 'product_stock', 'stock_transactions'] as $requiredTable) {
        if (!qs_api_table_exists($conn, $requiredTable)) {
            throw new RuntimeException(
                'Quick Sale database setup is incomplete. Missing table: ' . $requiredTable . '. Run quick_sale_module.sql.'
            );
        }
    }

    foreach (
        ['customer_name', 'mobile', 'address', 'invoice_token', 'whatsapp_status', 'whatsapp_log_id', 'whatsapp_sent_at']
        as $requiredColumn
    ) {
        if (!qs_api_column_exists($conn, 'quick_sales', $requiredColumn)) {
            throw new RuntimeException(
                'Quick Sale customer/WhatsApp database update is missing. Run database/quick_sale_customer_whatsapp_update.sql.'
            );
        }
    }

    try {
        qs_api_ensure_payment_tables($conn);
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Quick Sale payment database setup is missing. Run quick_sale_payment_update.sql. ' . $e->getMessage()
        );
    }

    $customerName = trim((string)($_POST['customer_name'] ?? ''));
    $customerMobile = preg_replace('/\D+/', '', (string)($_POST['customer_mobile'] ?? ''));
    $customerAddress = trim((string)($_POST['customer_address'] ?? ''));

    if ($customerName === '') {
        throw new RuntimeException('Customer Name is required.');
    }

    $customerNameLength = function_exists('mb_strlen') ? mb_strlen($customerName) : strlen($customerName);
    if ($customerNameLength > 200) {
        throw new RuntimeException('Customer Name cannot exceed 200 characters.');
    }

    if (!preg_match('/^\d{10}$/', $customerMobile)) {
        throw new RuntimeException('Please enter a valid 10 digit customer mobile number.');
    }

    $customerAddressLength = function_exists('mb_strlen') ? mb_strlen($customerAddress) : strlen($customerAddress);
    if ($customerAddressLength > 1000) {
        throw new RuntimeException('Customer Address is too long.');
    }

    $rawJson = trim((string)($_POST['items_json'] ?? ''));
    $decoded = json_decode($rawJson, true);

    if (!is_array($decoded) || !$decoded) {
        throw new RuntimeException('Please add at least one product.');
    }

    /*
     * Merge duplicate product rows defensively even though the UI blocks them.
     * The last supplied rate is used and quantities are combined.
     */
    $itemsByProduct = [];

    foreach ($decoded as $rawItem) {
        if (!is_array($rawItem)) continue;

        $productId = (int)($rawItem['product_id'] ?? 0);
        $productName = trim((string)($rawItem['product_name'] ?? ''));
        $isNewProduct = (int)($rawItem['is_new_product'] ?? 0) === 1;
        $qty = round((float)($rawItem['qty'] ?? 0), 2);
        $rate = round((float)($rawItem['rate'] ?? 0), 2);

        if ($productId <= 0 && $productName === '') {
            throw new RuntimeException('Product Name is required.');
        }

        if ($qty <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }

        if ($rate <= 0) {
            throw new RuntimeException('Price must be greater than zero.');
        }

        $groupKey = $productId > 0
            ? 'id:' . $productId
            : 'name:' . strtolower($productName);

        if (!isset($itemsByProduct[$groupKey])) {
            $itemsByProduct[$groupKey] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'is_new_product' => $isNewProduct ? 1 : 0,
                'qty' => 0.0,
                'rate' => $rate
            ];
        }

        $itemsByProduct[$groupKey]['qty'] =
            round($itemsByProduct[$groupKey]['qty'] + $qty, 2);

        $itemsByProduct[$groupKey]['rate'] = $rate;
    }

    if (!$itemsByProduct) {
        throw new RuntimeException('Please add at least one valid product.');
    }

    $requestSaleTotal = 0.0;
    foreach ($itemsByProduct as $requestedItem) {
        $requestSaleTotal += round(
            (float)$requestedItem['qty'] * (float)$requestedItem['rate'],
            2
        );
    }
    $requestSaleTotal = round($requestSaleTotal, 2);

    $useCash = (int)($_POST['use_cash'] ?? 0) === 1;
    $useUpi = (int)($_POST['use_upi'] ?? 0) === 1;
    $cashTendered = round((float)($_POST['cash_amount'] ?? 0), 2);
    $upiAmount = round((float)($_POST['upi_amount'] ?? 0), 2);
    $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
    $upiReference = trim((string)($_POST['upi_reference'] ?? ''));
    $paymentRemarks = trim((string)($_POST['payment_remarks'] ?? ''));

    if (!qs_api_valid_date($paymentDate)) {
        throw new RuntimeException('Please select a valid Payment Date.');
    }

    $paymentBreakdown = qs_api_payment_breakdown(
        $requestSaleTotal,
        $useCash,
        $cashTendered,
        $useUpi,
        $upiAmount
    );

    $cashDenominationPayload = null;
    if ($useCash) {
        $cashDenominationPayload = qs_api_cash_denomination_payload();

        if (abs((float)$cashDenominationPayload['total'] - $cashTendered) > 0.009) {
            throw new RuntimeException(
                'Cash denomination total ₹'
                . number_format((float)$cashDenominationPayload['total'], 2)
                . ' must match Cash Received ₹'
                . number_format($cashTendered, 2)
                . '.'
            );
        }
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $conn->begin_transaction();

    try {
        $saleNo = qs_api_next_no($conn);

        $invoiceToken = bin2hex(random_bytes(24));

        $stmt = $conn->prepare("
            INSERT INTO quick_sales
                (
                    sale_no,
                    customer_name,
                    mobile,
                    address,
                    invoice_token,
                    whatsapp_status,
                    total_amount,
                    created_by,
                    created_at
                )
            VALUES
                (?, ?, ?, ?, ?, 'pending', 0, ?, NOW())
        ");
        $createdBy = $userId > 0 ? $userId : null;
        $stmt->bind_param(
            'sssssi',
            $saleNo,
            $customerName,
            $customerMobile,
            $customerAddress,
            $invoiceToken,
            $createdBy
        );
        $stmt->execute();
        $quickSaleId = (int)$stmt->insert_id;
        $stmt->close();

        $grandTotal = 0.0;
        $savedItems = [];
        $createdProductNames = [];
        $restoredProductNames = [];

        foreach ($itemsByProduct as $requested) {
            $requestedProductId = (int)($requested['product_id'] ?? 0);
            $requestedProductName = trim((string)($requested['product_name'] ?? ''));
            $qty = (float)$requested['qty'];
            $rate = (float)$requested['rate'];

            $resolvedProduct = qs_api_find_or_create_product(
                $conn,
                $requestedProductId,
                $requestedProductName,
                $rate,
                $createdBy
            );

            $productId = (int)$resolvedProduct['id'];

            if (!empty($resolvedProduct['was_created'])) {
                $createdProductNames[] = (string)$resolvedProduct['product_name'];
            }

            if (!empty($resolvedProduct['was_restored'])) {
                $restoredProductNames[] = (string)$resolvedProduct['product_name'];
            }

            /*
             * Lock Product + Stock so concurrent Quick Sales serialize correctly.
             * Negative On Hand is allowed by business rule.
             */
            $stmt = $conn->prepare("
                SELECT
                    p.product_name,
                    p.is_active,
                    COALESCE(p.is_removed, 0) AS is_removed,
                    ps.id AS stock_id,
                    ps.on_hand_stock,
                    ps.reserved_stock
                FROM products p
                LEFT JOIN product_stock ps ON ps.product_id = p.id
                WHERE p.id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$product) {
                throw new RuntimeException('Product not found.');
            }

            if ((int)($product['is_active'] ?? 0) !== 1 || (int)($product['is_removed'] ?? 0) === 1) {
                throw new RuntimeException(
                    (string)($product['product_name'] ?? 'Product') . ' is inactive or removed.'
                );
            }

            if (empty($product['stock_id'])) {
                $stmt = $conn->prepare("
                    INSERT INTO product_stock
                        (product_id, on_hand_stock, reserved_stock, minimum_stock, low_stock_alert, created_at)
                    VALUES
                        (?, 0, 0, 0, 0, NOW())
                    ON DUPLICATE KEY UPDATE updated_at = NOW()
                ");
                $stmt->bind_param('i', $productId);
                $stmt->execute();
                $stmt->close();

                $product['stock_id'] = 1;
                $product['on_hand_stock'] = 0;
                $product['reserved_stock'] = 0;
            }

            $onHandBefore = round((float)($product['on_hand_stock'] ?? 0), 2);
            $reservedBefore = round((float)($product['reserved_stock'] ?? 0), 2);
            $availableBefore = round($onHandBefore - $reservedBefore, 2);

            /*
             * Quick Sale follows the same negative-stock visibility rule as
             * Create Proforma: insufficient stock never blocks the transaction.
             *
             * Physical On Hand decreases immediately and may become negative.
             * Reserved remains unchanged, therefore Available can become even
             * more negative when stock was already reserved.
             */
            $onHandAfter = round($onHandBefore - $qty, 2);
            $lineAmount = round($qty * $rate, 2);
            $grandTotal = round($grandTotal + $lineAmount, 2);
            $productName = (string)$product['product_name'];

            $stmt = $conn->prepare("
                UPDATE product_stock
                SET
                    on_hand_stock = ?,
                    updated_at = NOW()
                WHERE product_id = ?
            ");
            $stmt->bind_param('di', $onHandAfter, $productId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("
                INSERT INTO quick_sale_items
                    (
                        quick_sale_id,
                        product_id,
                        product_name,
                        qty,
                        rate,
                        amount,
                        created_at
                    )
                VALUES
                    (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param(
                'iisddd',
                $quickSaleId,
                $productId,
                $productName,
                $qty,
                $rate,
                $lineAmount
            );
            $stmt->execute();
            $stmt->close();

            /*
             * Stock ledger quantity is signed.
             * Direct sale = negative quantity.
             * Reserved stock is unchanged.
             */
            $transactionType = 'quick_sale';
            $signedQty = -1 * $qty;
            $referenceType = 'quick_sale';
            $description = 'Direct Quick Sale - ' . $saleNo;

            $stmt = $conn->prepare("
                INSERT INTO stock_transactions
                    (
                        product_id,
                        transaction_type,
                        quantity,
                        on_hand_before,
                        on_hand_after,
                        reserved_before,
                        reserved_after,
                        reference_type,
                        reference_id,
                        reference_no,
                        description,
                        created_by,
                        created_at
                    )
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param(
                'isdddddsissi',
                $productId,
                $transactionType,
                $signedQty,
                $onHandBefore,
                $onHandAfter,
                $reservedBefore,
                $reservedBefore,
                $referenceType,
                $quickSaleId,
                $saleNo,
                $description,
                $createdBy
            );
            $stmt->execute();
            $stmt->close();

            $savedItems[] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'qty' => $qty,
                'rate' => $rate,
                'amount' => $lineAmount,
                'on_hand_before' => $onHandBefore,
                'on_hand_after' => $onHandAfter,
                'reserved_stock' => $reservedBefore,
                'available_before' => $availableBefore,
                'available_after' => round($onHandAfter - $reservedBefore, 2)
            ];
        }

        $stmt = $conn->prepare("
            UPDATE quick_sales
            SET total_amount = ?
            WHERE id = ?
        ");
        $stmt->bind_param('di', $grandTotal, $quickSaleId);
        $stmt->execute();
        $stmt->close();

        if (abs($grandTotal - $requestSaleTotal) > 0.009) {
            throw new RuntimeException('Quick Sale total changed during save. Please try again.');
        }

        /*
         * Store payment inside the SAME DB transaction as stock reduction.
         * If payment/denomination save fails, the complete Quick Sale rolls back.
         */
        if ($useCash) {
            $cashPaymentId = qs_api_insert_payment(
                $conn,
                $quickSaleId,
                'cash',
                (float)$paymentBreakdown['cash_applied'],
                (float)$paymentBreakdown['cash_tendered'],
                (float)$paymentBreakdown['return_amount'],
                $paymentDate,
                '',
                $paymentRemarks,
                $createdBy
            );

            qs_api_save_cash_denominations(
                $conn,
                $cashPaymentId,
                is_array($cashDenominationPayload) ? $cashDenominationPayload : [],
                $createdBy
            );
        }

        if ($useUpi) {
            qs_api_insert_payment(
                $conn,
                $quickSaleId,
                'upi',
                (float)$paymentBreakdown['upi_amount'],
                (float)$paymentBreakdown['upi_amount'],
                0.0,
                $paymentDate,
                $upiReference,
                $paymentRemarks,
                $createdBy
            );
        }

        $conn->commit();

        /*
         * Send invoice only AFTER the database transaction is committed.
         * A WhatsApp failure must never undo the sale, payment or stock movement.
         */
        $whatsapp = [
            'success' => false,
            'message' => 'WhatsApp was not sent.',
            'log_id' => 0
        ];

        try {
            $whatsapp = qs_api_send_invoice_whatsapp(
                $conn,
                $quickSaleId,
                $saleNo,
                $customerName,
                $customerMobile,
                $grandTotal,
                $invoiceToken,
                $createdBy
            );
        } catch (Throwable $waError) {
            $whatsapp = [
                'success' => false,
                'message' => $waError->getMessage(),
                'log_id' => 0
            ];
        }

        $waStatus = !empty($whatsapp['success']) ? 'sent' : 'failed';
        $waLogId = !empty($whatsapp['log_id']) ? (int)$whatsapp['log_id'] : null;

        try {
            if ($waStatus === 'sent') {
                $stmt = $conn->prepare("
                    UPDATE quick_sales
                    SET whatsapp_status = 'sent',
                        whatsapp_log_id = ?,
                        whatsapp_sent_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('ii', $waLogId, $quickSaleId);
            } else {
                $stmt = $conn->prepare("
                    UPDATE quick_sales
                    SET whatsapp_status = 'failed',
                        whatsapp_log_id = ?,
                        whatsapp_sent_at = NULL
                    WHERE id = ?
                ");
                $stmt->bind_param('ii', $waLogId, $quickSaleId);
            }
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $ignored) {
            // Sale is already safely committed.
        }

        qs_api_response(true, 'Quick Sale, payment and stock saved successfully.', [
            'quick_sale_id' => $quickSaleId,
            'sale_no' => $saleNo,
            'customer_name' => $customerName,
            'customer_mobile' => $customerMobile,
            'invoice_url' => qs_api_public_invoice_url($invoiceToken),
            'whatsapp_sent' => !empty($whatsapp['success']),
            'whatsapp_message' => (string)($whatsapp['message'] ?? ''),
            'whatsapp_log_id' => $waLogId,
            'total_amount' => $grandTotal,
            'cash_received' => (float)$paymentBreakdown['cash_tendered'],
            'cash_applied' => (float)$paymentBreakdown['cash_applied'],
            'upi_amount' => (float)$paymentBreakdown['upi_amount'],
            'total_received' => (float)$paymentBreakdown['total_received'],
            'total_applied' => (float)$paymentBreakdown['total_applied'],
            'return_amount' => (float)$paymentBreakdown['return_amount'],
            'created_products' => array_values(array_unique($createdProductNames)),
            'restored_products' => array_values(array_unique($restoredProductNames)),
            'items' => $savedItems
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    qs_api_response(false, $e->getMessage());
}
