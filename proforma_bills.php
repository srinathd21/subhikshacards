<?php
/**
 * proforma_bills.php
 * Subhiksha Cards ERP - Correct Flow
 *
 * Flow:
 * Quotation -> Proforma Bill / Sales Order -> Advance Payment -> Job Card -> Job Tracking
 *
 * Uses your current DB tables:
 * - proforma_bills
 * - proforma_bill_items
 * - payments
 * - job_cards
 * - job_card_items
 * - workflow_steps
 * - job_tracking
 */

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'proforma_bills.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['proforma_csrf'])) {
    $_SESSION['proforma_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['proforma_csrf'];
$message = '';
$messageType = 'success';

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

function pb_redirect(string $query = ''): void
{
    header('Location: proforma_bills.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function pb_csrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['proforma_csrf']) ||
        !hash_equals($_SESSION['proforma_csrf'], (string)$_POST['csrf_token'])
    ) {
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

function pb_create_job_card(mysqli $conn, int $proformaId): int
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
    if (!empty($firstItem['printing_type_id'])) {
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

    if ($orderType === 'customized') {
        $stmt = $conn->prepare("SELECT id FROM roles WHERE role_key = 'multicolor_offset_printing' LIMIT 1");
        $stmt->execute();
        $roleRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($roleRow) {
            $assignedPrintingRoleId = (int)$roleRow['id'];
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
        $roleId = !empty($step['role_id']) ? (int)$step['role_id'] : null;
        $status = 'pending';
        $actualStart = null;
        $actualComplete = null;
        $completedBy = null;

        if (in_array($step['step_key'], ['enquiry', 'sales_order_proforma_invoice'], true)) {
            $status = 'completed';
            $actualStart = date('Y-m-d H:i:s');
            $actualComplete = date('Y-m-d H:i:s');
            $completedBy = $createdBy;
        } elseif ($currentStepId && (int)$step['id'] === (int)$currentStepId) {
            $status = 'in_progress';
            $actualStart = date('Y-m-d H:i:s');
        }

        $stmt = $conn->prepare("
            INSERT INTO job_tracking
                (
                    job_card_id,
                    workflow_step_id,
                    status,
                    responsible_role_id,
                    actual_start_at,
                    actual_completed_at,
                    completed_by,
                    created_at,
                    updated_at
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                responsible_role_id = VALUES(responsible_role_id),
                updated_at = NOW()
        ");
        $stmt->bind_param('iisissi', $jobCardId, $stepId, $status, $roleId, $actualStart, $actualComplete, $completedBy);
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
    $res = $conn->query("SELECT id, printing_name, printing_key FROM printing_types WHERE is_active = 1 ORDER BY sort_order ASC, printing_name ASC");
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
        SELECT q.id, q.quotation_no, q.customer_name, q.mobile, q.final_amount
        FROM quotations q
        ORDER BY q.id DESC
        LIMIT 200
    ");
    while ($row = $res->fetch_assoc()) {
        $quotations[] = $row;
    }
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pb_csrf();

    try {
        $action = pb_post('action');

        if ($action === 'save_proforma') {
            $id = pb_int($_POST['id'] ?? 0);
            $quotationId = pb_int($_POST['quotation_id'] ?? 0) ?: null;
            $functionTypeId = pb_int($_POST['function_type_id'] ?? 0) ?: null;
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
            $createJobCardNow = isset($_POST['create_job_card_now']);

            $productId = pb_int($_POST['product_id'] ?? 0) ?: null;
            $manualItemName = pb_post('item_name');
            $description = pb_post('description');
            $qty = pb_float($_POST['qty'] ?? 1);
            $rate = pb_float($_POST['rate'] ?? 0);
            $printingTypeId = pb_int($_POST['printing_type_id'] ?? 0) ?: null;
            $printingSubTypeId = pb_int($_POST['printing_sub_type_id'] ?? 0) ?: null;
            $finishingRequired = isset($_POST['finishing_required']) ? 1 : 0;
            $sizeText = pb_post('size_text');
            $gsmThickness = pb_post('gsm_thickness');
            $laminationRequired = isset($_POST['lamination_required']) ? 1 : 0;
            $laminationType = pb_post('lamination_type') ?: null;
            $printingSide = pb_post('printing_side') ?: null;
            $screeningType = pb_post('screening_type') ?: null;

            $discountAmount = pb_float($_POST['discount_amount'] ?? 0);
            $advanceAmount = pb_float($_POST['advance_amount'] ?? 0);
            $paymentMode = pb_post('payment_mode', 'cash');
            $paymentRef = pb_post('payment_reference');

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
                if ($brideName === '' || $groomName === '' || $venue === '' || !$functionDate || !$functionTime) {
                    throw new RuntimeException('Bride, groom, venue, function date and time are required for Wedding / Reception.');
                }
            } elseif ($selectedFieldGroup === 'event') {
                if ($venue === '' || !$functionDate || !$functionTime) {
                    throw new RuntimeException('Venue, function date and time are required for this function type.');
                }
            } elseif ($selectedFieldGroup === 'business_print') {
                if ($billingAddress === '') {
                    throw new RuntimeException('Address is required for Visiting Card / Bill Book / Brochure / Pamphlet.');
                }
            }

            if ($manualItemName === '' && !$productId) {
                throw new RuntimeException('Please select product or enter product name.');
            }

            if ($qty <= 0) {
                throw new RuntimeException('Quantity must be greater than zero.');
            }

            $productName = $manualItemName;
            if ($productName === '' && $productId) {
                foreach ($products as $product) {
                    if ((int)$product['id'] === $productId) {
                        $productName = $product['product_name'];
                        break;
                    }
                }
            }

            if ($productName === '') {
                $productName = 'Invitation Cards';
            }

            $amount = $qty * $rate;
            $subTotal = $amount;
            $finalAmount = max(0, $subTotal - $discountAmount);
            $balanceAmount = max(0, $finalAmount - $advanceAmount);
            $totalQty = $qty;
            $userId = (int)($_SESSION['user_id'] ?? 0);
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
                    'iiissssssssssssiddddddssii',
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
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->bind_param(
                    'siiissssssssssssiddddddssi',
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
                        sort_order,
                        created_at
                    )
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->bind_param(
                'iissdddiiississs',
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
                $screeningType
            );
            $stmt->execute();
            $stmt->close();

            if ($advanceAmount > 0 && $id <= 0) {
                $paymentNo = pb_next_no($conn, 'payments', 'payment_no', 'SC-PAY');
                $paymentType = $balanceAmount <= 0 ? 'full' : 'advance';
                $today = date('Y-m-d');

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
                        (?, ?, ?, ?, ?, ?, ?, ?, 'Advance collected from proforma bill', ?, NOW())
                ");
                $stmt->bind_param(
                    'iisssdssi',
                    $customerId,
                    $proformaId,
                    $paymentNo,
                    $paymentType,
                    $paymentMode,
                    $advanceAmount,
                    $today,
                    $paymentRef,
                    $userId
                );
                $stmt->execute();
                $stmt->close();

                pb_log($conn, 'collect_payment', 'Payments', $proformaId, 'Advance payment collected.');
            }

            $conn->commit();

            if ($createJobCardNow) {
                $conn->begin_transaction();
                $jobId = pb_create_job_card($conn, $proformaId);
                $conn->commit();

                pb_redirect('msg=job_created&job_id=' . $jobId);
            }

            pb_redirect($id > 0 ? 'msg=updated' : 'msg=created');
        }

        if ($action === 'create_job_card') {
            $proformaId = pb_int($_POST['proforma_id'] ?? 0);

            $conn->begin_transaction();
            $jobId = pb_create_job_card($conn, $proformaId);
            $conn->commit();

            pb_redirect('msg=job_created&job_id=' . $jobId);
        }
    } catch (Throwable $e) {
        try {
            if ($conn->errno === 0) {
                $conn->rollback();
            }
        } catch (Throwable $rollbackError) {
        }

        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') {
    $message = 'Proforma bill created successfully.';
} elseif ($msg === 'updated') {
    $message = 'Proforma bill updated successfully.';
} elseif ($msg === 'job_created') {
    $message = 'Job card created successfully with tracking stages.';
}

$rows = [];
try {
    $res = $conn->query("
        SELECT
            pb.*,
            ps.status_name,
            jc.job_card_no,
            ft.function_name
        FROM proforma_bills pb
        LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id
        LEFT JOIN job_cards jc ON jc.proforma_bill_id = pb.id
        LEFT JOIN function_types ft ON ft.id = pb.function_type_id
        ORDER BY pb.id DESC
        LIMIT 300
    ");
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
} catch (Throwable $e) {
    $rows = [];
}

$editData = null;
$editItem = null;
if (!empty($_GET['edit'])) {
    $editId = pb_int($_GET['edit']);
    try {
        $stmt = $conn->prepare("SELECT * FROM proforma_bills WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editData = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($editData) {
            $stmt = $conn->prepare("SELECT * FROM proforma_bill_items WHERE proforma_bill_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
            $stmt->bind_param('i', $editId);
            $stmt->execute();
            $editItem = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    } catch (Throwable $e) {
        $editData = null;
    }
}

function pb_form_value(?array $data, string $key, string $default = ''): string
{
    return e($data[$key] ?? $default);
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Proforma Bills - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
        .proforma-page .page-head {
            padding: 24px 28px;
            margin-bottom: 18px;
        }

        .proforma-page .page-head h1 {
            font-size: 30px;
            font-weight: 900;
            color: var(--text-main);
        }

        .card-pad {
            padding: 24px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 900;
            color: var(--text-main);
            margin-bottom: 12px;
        }

        .form-control,
        .form-select {
            border-radius: 14px;
            min-height: 46px;
        }

        .status-pill {
            font-size: 11px;
            font-weight: 900;
            border-radius: 999px;
            padding: 5px 9px;
            background: color-mix(in srgb, var(--info-color) 14%, transparent);
            color: var(--info-color);
        }

        .status-pill.ok {
            background: color-mix(in srgb, var(--success-color) 14%, transparent);
            color: var(--success-color);
        }

        .amount-box {
            border: 1px solid var(--border-soft);
            background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
            border-radius: 18px;
            padding: 14px;
        }

        .amount-box small {
            display: block;
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 900;
            text-transform: uppercase;
        }

        .function-specific.d-none { display: none !important; }

        .amount-box strong {
            display: block;
            margin-top: 3px;
            font-size: 18px;
            font-weight: 900;
            color: var(--text-main);
        }

        .mobile-cards {
            display: none;
        }

        .mobile-card {
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 16px;
            margin-bottom: 12px;
            background: var(--card-bg);
        }

        @media(max-width:767.98px) {
            .proforma-page .page-head {
                padding: 18px;
                border-radius: 18px;
            }

            .proforma-page .page-head h1 {
                font-size: 24px;
            }

            .card-pad {
                padding: 16px;
                border-radius: 18px;
            }

            .desktop-table {
                display: none !important;
            }

            .mobile-cards {
                display: block;
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

            <section class="page-section proforma-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Proforma Bills / Sales Orders</h1>
                            <p class="text-muted-custom mb-0">
                                Create proforma bill, collect advance and generate job card with tracking flow.
                            </p>
                        </div>

                        <?php if ($editData): ?>
                            <a href="proforma_bills.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                                Cancel Edit
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-<?= e($messageType) ?> rounded-4 fw-bold">
                        <?= e($message) ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="card-ui card-pad mb-3" id="proformaForm">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_proforma">
                    <input type="hidden" name="id" value="<?= e($editData['id'] ?? '') ?>">

                    <div class="section-title">
                        <?= $editData ? 'Edit Proforma Bill' : 'Create Proforma Bill' ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Quotation Reference</label>
                            <select name="quotation_id" class="form-select">
                                <option value="">Direct Proforma</option>
                                <?php foreach ($quotations as $quotation): ?>
                                    <option value="<?= e($quotation['id']) ?>" <?= ((int)($editData['quotation_id'] ?? 0) === (int)$quotation['id']) ? 'selected' : '' ?>>
                                        <?= e($quotation['quotation_no']) ?> - <?= e($quotation['customer_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Function / Product Type *</label>
                            <select name="function_type_id" id="function_type_id" class="form-select" required>
                                <option value="">Select Type</option>
                                <?php foreach ($functionTypes as $ft): ?>
                                    <option value="<?= e($ft['id']) ?>"
                                        data-field-group="<?= e($ft['field_group']) ?>"
                                        data-function-key="<?= e($ft['function_key']) ?>"
                                        <?= ((int)($editData['function_type_id'] ?? 0) === (int)$ft['id']) ? 'selected' : '' ?>>
                                        <?= e($ft['function_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted-custom fw-bold d-block mt-1" id="functionHelp">
                                Select type to show required inputs.
                            </small>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Order Type *</label>
                            <select name="order_type" id="order_type" class="form-select" required>
                                <option value="readymade" <?= (($editData['order_type'] ?? '') === 'readymade') ? 'selected' : '' ?>>Readymade</option>
                                <option value="customized" <?= (($editData['order_type'] ?? '') === 'customized') ? 'selected' : '' ?>>Customized</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Delivery Date</label>
                            <input type="date" name="delivery_date" class="form-control" value="<?= pb_form_value($editData, 'delivery_date') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Customer Name *</label>
                            <input name="customer_name" class="form-control" required value="<?= pb_form_value($editData, 'customer_name') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Mobile *</label>
                            <input name="mobile" class="form-control" required value="<?= pb_form_value($editData, 'mobile') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">GST Number</label>
                            <input name="gst_number" class="form-control" value="<?= pb_form_value($editData, 'gst_number') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Billing Name</label>
                            <input name="billing_name" class="form-control" value="<?= pb_form_value($editData, 'billing_name') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Billing Mobile</label>
                            <input name="billing_mobile" class="form-control" value="<?= pb_form_value($editData, 'billing_mobile') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Status</label>
                            <select name="proforma_status_id" class="form-select">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= e($status['id']) ?>" <?= ((int)($editData['proforma_status_id'] ?? 0) === (int)$status['id']) ? 'selected' : '' ?>>
                                        <?= e($status['status_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 function-specific group-business group-other">
                            <label class="form-label fw-bold">Address / Billing Address <span class="text-danger group-business-required">*</span></label>
                            <textarea name="billing_address" class="form-control" rows="2"><?= pb_form_value($editData, 'billing_address') ?></textarea>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="section-title">Function Details</div>

                    <div class="row g-3">
                        <div class="col-md-4 function-specific group-wedding">
                            <label class="form-label fw-bold">Bride Name <span class="text-danger">*</span></label>
                            <input name="bride_name" class="form-control" value="<?= pb_form_value($editData, 'bride_name') ?>">
                        </div>

                        <div class="col-md-4 function-specific group-wedding">
                            <label class="form-label fw-bold">Groom Name <span class="text-danger">*</span></label>
                            <input name="groom_name" class="form-control" value="<?= pb_form_value($editData, 'groom_name') ?>">
                        </div>

                        <div class="col-md-2 function-specific group-wedding group-event">
                            <label class="form-label fw-bold">Function Date <span class="text-danger">*</span></label>
                            <input type="date" name="function_date" class="form-control" value="<?= pb_form_value($editData, 'function_date') ?>">
                        </div>

                        <div class="col-md-2 function-specific group-wedding group-event">
                            <label class="form-label fw-bold">Function Time <span class="text-danger">*</span></label>
                            <input type="time" name="function_time" class="form-control" value="<?= pb_form_value($editData, 'function_time') ?>">
                        </div>

                        <div class="col-12 function-specific group-wedding group-event">
                            <label class="form-label fw-bold">Venue <span class="text-danger">*</span></label>
                            <textarea name="venue" class="form-control" rows="2"><?= pb_form_value($editData, 'venue') ?></textarea>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="section-title">Product / Printing Details</div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Product</label>
                            <select name="product_id" id="product_id" class="form-select">
                                <option value="">Manual Product Name</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?= e($product['id']) ?>"
                                        data-price="<?= e($product['default_price']) ?>"
                                        <?= ((int)($editItem['product_id'] ?? 0) === (int)$product['id']) ? 'selected' : '' ?>>
                                        <?= e($product['product_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Product / Item Name *</label>
                            <input name="item_name" id="item_name" class="form-control" value="<?= pb_form_value($editItem, 'item_name') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Printing Type</label>
                            <select name="printing_type_id" id="printing_type_id" class="form-select">
                                <option value="">Select</option>
                                <?php foreach ($printingTypes as $type): ?>
                                    <option value="<?= e($type['id']) ?>" <?= ((int)($editItem['printing_type_id'] ?? 0) === (int)$type['id']) ? 'selected' : '' ?>>
                                        <?= e($type['printing_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Printing Sub Type</label>
                            <select name="printing_sub_type_id" id="printing_sub_type_id" class="form-select">
                                <option value="">Select</option>
                                <?php foreach ($printingSubTypes as $sub): ?>
                                    <option value="<?= e($sub['id']) ?>" data-printing-type="<?= e($sub['printing_type_id']) ?>" <?= ((int)($editItem['printing_sub_type_id'] ?? 0) === (int)$sub['id']) ? 'selected' : '' ?>>
                                        <?= e($sub['sub_type_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">Qty *</label>
                            <input type="number" step="0.01" name="qty" id="qty" class="form-control" value="<?= pb_form_value($editItem, 'qty', '1') ?>" required>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">Rate</label>
                            <input type="number" step="0.01" name="rate" id="rate" class="form-control" value="<?= pb_form_value($editItem, 'rate', '0') ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">Discount</label>
                            <input type="number" step="0.01" name="discount_amount" id="discount_amount" class="form-control" value="<?= pb_form_value($editData, 'discount_amount', '0') ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">Advance</label>
                            <input type="number" step="0.01" name="advance_amount" id="advance_amount" class="form-control" value="<?= pb_form_value($editData, 'advance_amount', '0') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Payment Mode</label>
                            <select name="payment_mode" class="form-select">
                                <option value="cash">Cash</option>
                                <option value="upi">UPI</option>
                                <option value="bank">Bank</option>
                                <option value="cheque">Cheque</option>
                                <option value="card">Card</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Payment Reference</label>
                            <input name="payment_reference" class="form-control">
                        </div>

                        <div class="col-md-4 readymade-only">
                            <label class="form-label fw-bold">Finishing</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="finishing_required" id="finishing_required" <?= ((int)($editItem['finishing_required'] ?? 0) === 1) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="finishing_required">With Finishing</label>
                            </div>
                        </div>

                        <div class="col-md-4 customized-only">
                            <label class="form-label fw-bold">Size</label>
                            <input name="size_text" class="form-control" value="<?= pb_form_value($editItem, 'size_text') ?>">
                        </div>

                        <div class="col-md-4 customized-only">
                            <label class="form-label fw-bold">GSM Thickness</label>
                            <input name="gsm_thickness" class="form-control" value="<?= pb_form_value($editItem, 'gsm_thickness') ?>">
                        </div>

                        <div class="col-md-4 customized-only">
                            <label class="form-label fw-bold">Lamination</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="lamination_required" id="lamination_required" <?= ((int)($editItem['lamination_required'] ?? 0) === 1) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="lamination_required">Required</label>
                            </div>
                        </div>

                        <div class="col-md-4 customized-only">
                            <label class="form-label fw-bold">Lamination Type</label>
                            <select name="lamination_type" class="form-select">
                                <option value="">Select</option>
                                <option value="glossy" <?= (($editItem['lamination_type'] ?? '') === 'glossy') ? 'selected' : '' ?>>Glossy</option>
                                <option value="matte" <?= (($editItem['lamination_type'] ?? '') === 'matte') ? 'selected' : '' ?>>Matte</option>
                                <option value="special" <?= (($editItem['lamination_type'] ?? '') === 'special') ? 'selected' : '' ?>>Special</option>
                            </select>
                        </div>

                        <div class="col-md-4 customized-only">
                            <label class="form-label fw-bold">Printing Side</label>
                            <select name="printing_side" class="form-select">
                                <option value="">Select</option>
                                <option value="single" <?= (($editItem['printing_side'] ?? '') === 'single') ? 'selected' : '' ?>>Single Side</option>
                                <option value="double" <?= (($editItem['printing_side'] ?? '') === 'double') ? 'selected' : '' ?>>Double Side</option>
                            </select>
                        </div>

                        <div class="col-md-4 customized-only">
                            <label class="form-label fw-bold">Screening</label>
                            <select name="screening_type" class="form-select">
                                <option value="">Select</option>
                                <option value="regular" <?= (($editItem['screening_type'] ?? '') === 'regular') ? 'selected' : '' ?>>Regular Screening</option>
                                <option value="special" <?= (($editItem['screening_type'] ?? '') === 'special') ? 'selected' : '' ?>>Special Screening</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Item Description</label>
                            <textarea name="description" class="form-control" rows="2"><?= pb_form_value($editItem, 'description') ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2"><?= pb_form_value($editData, 'remarks') ?></textarea>
                        </div>
                    </div>

                    <div class="row g-3 my-3">
                        <div class="col-md-3">
                            <div class="amount-box">
                                <small>Sub Total</small>
                                <strong id="subTotalText">₹0.00</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="amount-box">
                                <small>Final Amount</small>
                                <strong id="finalAmountText">₹0.00</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="amount-box">
                                <small>Advance</small>
                                <strong id="advanceText">₹0.00</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="amount-box">
                                <small>Balance</small>
                                <strong id="balanceText">₹0.00</strong>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="create_job_card_now" id="create_job_card_now" <?= $editData ? '' : 'checked' ?>>
                            <label class="form-check-label fw-bold" for="create_job_card_now">
                                Create Job Card immediately after save
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold">
                            <?= $editData ? 'Update Proforma' : 'Save Proforma' ?>
                        </button>
                    </div>
                </form>

                <div class="card-ui card-pad">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <div class="section-title mb-1">Proforma Bill List</div>
                            <p class="text-muted-custom mb-0">Create job card directly from confirmed proforma bills.</p>
                        </div>

                        <input type="search" id="tableSearch" class="form-control" style="max-width:340px" placeholder="Search...">
                    </div>

                    <div class="table-responsive desktop-table">
                        <table class="table-ui" id="proformaTable">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Customer</th>
                                    <th>Function</th>
                                    <th>Order Type</th>
                                    <th>Amount</th>
                                    <th>Advance</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Job Card</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$rows): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted-custom py-4">No proforma bills found.</td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><strong><?= e($row['proforma_no']) ?></strong></td>
                                        <td><?= e($row['customer_name']) ?><small class="d-block text-muted-custom"><?= e($row['mobile']) ?></small></td>
                                        <td><?= e($row['function_name'] ?? '-') ?></td>
                                        <td><?= e(ucfirst($row['order_type'])) ?></td>
                                        <td>₹<?= number_format((float)$row['final_amount'], 2) ?></td>
                                        <td>₹<?= number_format((float)$row['advance_amount'], 2) ?></td>
                                        <td>₹<?= number_format((float)$row['balance_amount'], 2) ?></td>
                                        <td><span class="status-pill"><?= e($row['status_name'] ?? '-') ?></span></td>
                                        <td>
                                            <?php if (!empty($row['job_card_no'])): ?>
                                                <span class="status-pill ok"><?= e($row['job_card_no']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted-custom fw-bold">Not Created</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="proforma_bills.php?edit=<?= e($row['id']) ?>" class="btn btn-sm btn-outline-primary rounded-pill fw-bold">
                                                Edit
                                            </a>

                                            <?php if (empty($row['job_card_no'])): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Create job card for this proforma bill?')">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="create_job_card">
                                                    <input type="hidden" name="proforma_id" value="<?= e($row['id']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-success rounded-pill fw-bold">
                                                        Create Job Card
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <a href="job_cards.php" class="btn btn-sm btn-outline-secondary rounded-pill fw-bold">
                                                    View
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-cards" id="mobileCards">
                        <?php foreach ($rows as $row): ?>
                            <div class="mobile-card">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <strong><?= e($row['proforma_no']) ?></strong>
                                        <small class="d-block text-muted-custom"><?= e($row['customer_name']) ?> · <?= e($row['mobile']) ?></small>
                                        <small class="d-block text-muted-custom"><?= e($row['function_name'] ?? '-') ?> · <?= e(ucfirst($row['order_type'])) ?> · ₹<?= number_format((float)$row['final_amount'], 2) ?></small>
                                    </div>
                                    <span class="status-pill"><?= e($row['status_name'] ?? '-') ?></span>
                                </div>

                                <div class="mt-3 d-flex gap-2 flex-wrap">
                                    <a href="proforma_bills.php?edit=<?= e($row['id']) ?>" class="btn btn-sm btn-outline-primary rounded-pill fw-bold">Edit</a>
                                    <?php if (empty($row['job_card_no'])): ?>
                                        <form method="post" onsubmit="return confirm('Create job card?')">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="create_job_card">
                                            <input type="hidden" name="proforma_id" value="<?= e($row['id']) ?>">
                                            <button class="btn btn-sm btn-success rounded-pill fw-bold">Create Job Card</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="status-pill ok"><?= e($row['job_card_no']) ?></span>
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

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
        (function () {
            const functionType = document.getElementById('function_type_id');
            const orderType = document.getElementById('order_type');
            const product = document.getElementById('product_id');
            const itemName = document.getElementById('item_name');
            const rate = document.getElementById('rate');
            const qty = document.getElementById('qty');
            const discount = document.getElementById('discount_amount');
            const advance = document.getElementById('advance_amount');
            const printingType = document.getElementById('printing_type_id');
            const printingSubType = document.getElementById('printing_sub_type_id');

            function money(value) {
                return '₹' + Number(value || 0).toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function calculate() {
                const q = parseFloat(qty.value || 0);
                const r = parseFloat(rate.value || 0);
                const d = parseFloat(discount.value || 0);
                const a = parseFloat(advance.value || 0);

                const sub = q * r;
                const finalAmount = Math.max(0, sub - d);
                const bal = Math.max(0, finalAmount - a);

                document.getElementById('subTotalText').textContent = money(sub);
                document.getElementById('finalAmountText').textContent = money(finalAmount);
                document.getElementById('advanceText').textContent = money(a);
                document.getElementById('balanceText').textContent = money(bal);
            }

            function selectedFieldGroup() {
                const opt = functionType?.options[functionType.selectedIndex];
                return opt ? (opt.dataset.fieldGroup || 'other') : 'other';
            }

            function toggleFunctionFields() {
                const group = selectedFieldGroup();
                const help = document.getElementById('functionHelp');

                document.querySelectorAll('.function-specific').forEach(el => el.classList.add('d-none'));

                if (group === 'wedding_reception') {
                    document.querySelectorAll('.group-wedding').forEach(el => el.classList.remove('d-none'));
                    if (help) help.textContent = 'Wedding / Reception: Bride, groom, venue, date and time are required.';
                } else if (group === 'event') {
                    document.querySelectorAll('.group-event').forEach(el => el.classList.remove('d-none'));
                    if (help) help.textContent = 'Event type: Venue, function date and time are required.';
                } else if (group === 'business_print') {
                    document.querySelectorAll('.group-business').forEach(el => el.classList.remove('d-none'));
                    if (help) help.textContent = 'Business print: Address, item details and quantity are required.';
                } else {
                    document.querySelectorAll('.group-other').forEach(el => el.classList.remove('d-none'));
                    if (help) help.textContent = 'Other type: Fill customer, product and required order details.';
                }
            }

            function toggleOrderFields() {
                const customized = orderType.value === 'customized';

                document.querySelectorAll('.customized-only').forEach(el => el.style.display = customized ? '' : 'none');
                document.querySelectorAll('.readymade-only').forEach(el => el.style.display = customized ? 'none' : '');
            }

            function filterSubTypes() {
                const typeId = printingType.value;
                Array.from(printingSubType.options).forEach(function (opt) {
                    if (!opt.value) {
                        opt.hidden = false;
                        return;
                    }

                    opt.hidden = opt.dataset.printingType !== typeId;
                });

                const selected = printingSubType.options[printingSubType.selectedIndex];
                if (selected && selected.hidden) {
                    printingSubType.value = '';
                }
            }

            product?.addEventListener('change', function () {
                const opt = product.options[product.selectedIndex];
                if (opt && opt.value) {
                    if (!itemName.value) itemName.value = opt.textContent.trim();
                    if (parseFloat(rate.value || 0) <= 0) rate.value = opt.dataset.price || 0;
                    calculate();
                }
            });

            [qty, rate, discount, advance].forEach(el => el?.addEventListener('input', calculate));
            functionType?.addEventListener('change', toggleFunctionFields);
            orderType?.addEventListener('change', toggleOrderFields);
            printingType?.addEventListener('change', filterSubTypes);

            document.getElementById('tableSearch')?.addEventListener('input', function () {
                const value = this.value.toLowerCase().trim();
                document.querySelectorAll('#proformaTable tbody tr').forEach(function (row) {
                    row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
                });
                document.querySelectorAll('#mobileCards .mobile-card').forEach(function (card) {
                    card.style.display = card.textContent.toLowerCase().includes(value) ? '' : 'none';
                });
            });

            toggleFunctionFields();
            toggleOrderFields();
            filterSubTypes();
            calculate();

            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        })();
    </script>
</body>

</html>
