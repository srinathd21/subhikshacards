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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pb_csrf();

    try {
        $action = pb_post('action');

        if ($action === 'save_proforma') {
            $id = pb_int($_POST['id'] ?? 0);
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
            $createJobCardNow = isset($_POST['create_job_card_now']);

            $productId = pb_int($_POST['product_id'] ?? 0) ?: null;
            $manualItemName = pb_post('item_name');
            $description = pb_post('description');
            $qty = pb_float($_POST['qty'] ?? 1);
            $rate = pb_float($_POST['rate'] ?? 0);
            $printingTypeId = pb_int($_POST['printing_type_id'] ?? 0) ?: null;
            $printingSubTypeId = pb_int($_POST['printing_sub_type_id'] ?? 0) ?: null;

            if ($orderType === 'customized') {
                $multiColorPrintingTypeId = pb_multicolor_printing_type_id($conn);
                if (!$multiColorPrintingTypeId) {
                    throw new RuntimeException('Multicolor Offset Printing type is missing. Please add it in Printing Types master.');
                }

                $printingTypeId = $multiColorPrintingTypeId;
                $printingSubTypeId = null;
            }
            $finishingRequired = pb_int($_POST['finishing_required'] ?? 0) === 1 ? 1 : 0;
            $sizeText = pb_post('size_text');
            $gsmThickness = pb_post('gsm_thickness');
            $laminationRequired = pb_int($_POST['lamination_required'] ?? 0) === 1 ? 1 : 0;
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

            if ($orderType === 'readymade') {
                if (!$printingTypeId) {
                    throw new RuntimeException('Please select printing type for readymade order.');
                }

                if (pb_is_screen_printing_type($conn, $printingTypeId) && !$printingSubTypeId) {
                    throw new RuntimeException('Please select Screen Print sub-type: UV Products or Foil Products.');
                }

                $sizeText = '';
                $gsmThickness = '';
                $laminationRequired = 0;
                $laminationType = null;
                $printingSide = null;
                $screeningType = null;
            }

            if ($orderType === 'customized') {
                if ($sizeText === '') {
                    throw new RuntimeException('Size is required for customized order.');
                }

                if ($gsmThickness === '') {
                    throw new RuntimeException('GSM Thickness is required for customized order.');
                }

                if (!$printingSide) {
                    throw new RuntimeException('Please select Single Side or Double Side.');
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

            if ($rate <= 0) {
                throw new RuntimeException('Price / rate must be greater than zero.');
            }

            if ($discountAmount < 0) {
                throw new RuntimeException('Discount cannot be negative.');
            }

            if ($advanceAmount < 0) {
                throw new RuntimeException('Advance amount cannot be negative.');
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

            $amount = round($qty * $rate, 2);
            $subTotal = $amount;

            if ($discountAmount > $subTotal) {
                throw new RuntimeException('Discount cannot be greater than sub total.');
            }

            $finalAmount = round(max(0, $subTotal - $discountAmount), 2);

            if ($advanceAmount > $finalAmount) {
                throw new RuntimeException('Advance cannot be greater than final amount.');
            }

            $balanceAmount = round(max(0, $finalAmount - $advanceAmount), 2);
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

    .function-specific.d-none {
        display: none !important;
    }

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


    .locked-select-note {
        color: var(--success-color, #16a34a) !important;
    }

    .printing-locked .select2-selection,
    select.printing-locked {
        background: color-mix(in srgb, var(--success-color, #16a34a) 8%, var(--card-bg)) !important;
        border-color: color-mix(in srgb, var(--success-color, #16a34a) 45%, var(--border-soft)) !important;
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
                                Create proforma bill as sales order reference, collect advance, balance, printing
                                details, delivery date and job card.
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
                        <?= $editData ? 'Edit Proforma Bill / Sales Order' : 'Create Proforma Bill / Sales Order' ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Quotation Reference</label>
                            <select name="quotation_id" class="form-select select2-autotype"
                                data-placeholder="Search quotation reference">
                                <option value="">Direct Proforma</option>
                                <?php foreach ($quotations as $quotation): ?>
                                <option value="<?= e($quotation['id']) ?>"
                                    data-customer-name="<?= e($quotation['customer_name']) ?>"
                                    data-mobile="<?= e($quotation['mobile']) ?>"
                                    data-billing-address="<?= e($quotation['address'] ?? '') ?>"
                                    data-function-type-id="<?= e($quotation['function_type_id'] ?? '') ?>"
                                    data-bride-name="<?= e($quotation['bride_name'] ?? '') ?>"
                                    data-groom-name="<?= e($quotation['groom_name'] ?? '') ?>"
                                    data-venue="<?= e($quotation['venue'] ?? '') ?>"
                                    data-function-date="<?= e($quotation['function_date'] ?? '') ?>"
                                    data-function-time="<?= e($quotation['function_time'] ?? '') ?>"
                                    data-total-qty="<?= e($quotation['total_qty'] ?? '') ?>"
                                    data-sub-total="<?= e($quotation['sub_total'] ?? '') ?>"
                                    data-discount-amount="<?= e($quotation['discount_amount'] ?? '') ?>"
                                    data-final-amount="<?= e($quotation['final_amount'] ?? '') ?>"
                                    <?= ((int)($editData['quotation_id'] ?? 0) === (int)$quotation['id']) ? 'selected' : '' ?>>
                                    <?= e($quotation['quotation_no']) ?> - <?= e($quotation['customer_name']) ?> -
                                    ₹<?= number_format((float)$quotation['final_amount'], 2) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Function / Product Type *</label>
                            <select name="function_type_id" id="function_type_id" class="form-select select2-autotype"
                                required data-placeholder="Type or select function / product type" data-tags="true">
                                <option value="">Select Type</option>
                                <?php foreach ($functionTypes as $ft): ?>
                                <option value="<?= e($ft['id']) ?>" data-field-group="<?= e($ft['field_group']) ?>"
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
                            <select name="order_type" id="order_type" class="form-select select2-autotype" required
                                data-placeholder="Search order type">
                                <option value="readymade"
                                    <?= (($editData['order_type'] ?? '') === 'readymade') ? 'selected' : '' ?>>Readymade
                                </option>
                                <option value="customized"
                                    <?= (($editData['order_type'] ?? '') === 'customized') ? 'selected' : '' ?>>
                                    Customized</option>
                            </select>
                        </div>


                        <div class="col-12">
                            <div class="order-type-info readymade-info d-none">
                                <strong>Readymade Order Required Info:</strong>
                                Customer + Billing details, Product, Printing Type, Screen Print sub-type if applicable,
                                With/Without finishing, Advance, Final amount, Balance, Delivery date and Remarks.
                            </div>
                            <div class="order-type-info customized-info d-none">
                                <strong>Customized Order Required Info:</strong>
                                Customer + Billing details, Product, Size, GSM, Lamination required/not,
                                Lamination type if selected, Single/Double side, Regular/Special screening,
                                Advance, Final amount, Balance, Delivery date and Remarks.
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Delivery Date</label>
                            <input type="date" name="delivery_date" class="form-control"
                                value="<?= pb_form_value($editData, 'delivery_date') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Customer Name *</label>
                            <input name="customer_name" class="form-control" required
                                value="<?= pb_form_value($editData, 'customer_name') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Mobile *</label>
                            <input name="mobile" class="form-control" required
                                value="<?= pb_form_value($editData, 'mobile') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">GST Number</label>
                            <input name="gst_number" class="form-control"
                                value="<?= pb_form_value($editData, 'gst_number') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Billing Name</label>
                            <input name="billing_name" class="form-control"
                                value="<?= pb_form_value($editData, 'billing_name') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Billing Mobile</label>
                            <input name="billing_mobile" class="form-control"
                                value="<?= pb_form_value($editData, 'billing_mobile') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Status</label>
                            <select name="proforma_status_id" class="form-select select2-autotype"
                                data-placeholder="Search status">
                                <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status['id']) ?>"
                                    <?= ((int)($editData['proforma_status_id'] ?? 0) === (int)$status['id']) ? 'selected' : '' ?>>
                                    <?= e($status['status_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 function-specific group-business group-other">
                            <label class="form-label fw-bold">Address / Billing Address <span
                                    class="text-danger group-business-required">*</span></label>
                            <textarea name="billing_address" class="form-control"
                                rows="2"><?= pb_form_value($editData, 'billing_address') ?></textarea>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="section-title">Function Details</div>

                    <div class="row g-3">
                        <div class="col-md-4 function-specific group-wedding">
                            <label class="form-label fw-bold">Bride Name <span class="text-danger">*</span></label>
                            <input name="bride_name" class="form-control"
                                value="<?= pb_form_value($editData, 'bride_name') ?>">
                        </div>

                        <div class="col-md-4 function-specific group-wedding">
                            <label class="form-label fw-bold">Groom Name <span class="text-danger">*</span></label>
                            <input name="groom_name" class="form-control"
                                value="<?= pb_form_value($editData, 'groom_name') ?>">
                        </div>

                        <div class="col-md-2 function-specific group-wedding group-event">
                            <label class="form-label fw-bold">Function Date <span class="text-danger">*</span></label>
                            <input type="date" name="function_date" class="form-control"
                                value="<?= pb_form_value($editData, 'function_date') ?>">
                        </div>

                        <div class="col-md-2 function-specific group-wedding group-event">
                            <label class="form-label fw-bold">Function Time <span class="text-danger">*</span></label>
                            <input type="time" name="function_time" class="form-control"
                                value="<?= pb_form_value($editData, 'function_time') ?>">
                        </div>

                        <div class="col-12 function-specific group-wedding group-event">
                            <label class="form-label fw-bold">Venue <span class="text-danger">*</span></label>
                            <textarea name="venue" class="form-control"
                                rows="2"><?= pb_form_value($editData, 'venue') ?></textarea>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="section-title">Product, Price & Printing Details</div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Product</label>
                            <select name="product_id" id="product_id" class="form-select select2-autotype"
                                data-placeholder="Search product or type manual product name" data-tags="true">
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
                            <input name="item_name" id="item_name" class="form-control"
                                value="<?= pb_form_value($editItem, 'item_name') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Printing Type <span
                                    class="text-danger readymade-required">*</span></label>
                            <select name="printing_type_id" id="printing_type_id" class="form-select select2-autotype"
                                data-placeholder="Search printing type">
                                <option value="">Select</option>
                                <?php foreach ($printingTypes as $type): ?>
                                <option value="<?= e($type['id']) ?>"
                                    data-printing-key="<?= e($type['printing_key'] ?? '') ?>"
                                    data-normalized-key="<?= e($type['normalized_key'] ?? '') ?>"
                                    data-printing-name="<?= e($type['printing_name'] ?? '') ?>"
                                    <?= ((int)($editItem['printing_type_id'] ?? 0) === (int)$type['id']) ? 'selected' : '' ?>>
                                    <?= e($type['printing_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted-custom fw-bold d-block mt-1 d-none" id="customizedPrintingHelp">
                                Customized order automatically uses Multicolor Offset Printing.
                            </small>
                        </div>

                        <div class="col-md-4" id="screenSubTypeWrap">
                            <label class="form-label fw-bold">Screen Print Sub-Type <span
                                    class="text-danger screen-subtype-required d-none">*</span></label>
                            <select name="printing_sub_type_id" id="printing_sub_type_id"
                                class="form-select select2-autotype" data-placeholder="Search printing sub type">
                                <option value="">Select</option>
                                <?php foreach ($printingSubTypes as $sub): ?>
                                <option value="<?= e($sub['id']) ?>"
                                    data-printing-type="<?= e($sub['printing_type_id']) ?>"
                                    <?= ((int)($editItem['printing_sub_type_id'] ?? 0) === (int)$sub['id']) ? 'selected' : '' ?>>
                                    <?= e($sub['sub_type_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted-custom fw-bold d-none" id="screenSubTypeHelp">
                                Screen Print selected: choose UV Products or Foil Products.
                            </small>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">Qty *</label>
                            <input type="number" step="0.01" min="0.01" name="qty" id="qty" class="form-control"
                                value="<?= pb_form_value($editItem, 'qty', '1') ?>" required>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">Rate</label>
                            <input type="number" step="0.01" min="0" name="rate" id="rate" class="form-control"
                                value="<?= pb_form_value($editItem, 'rate', '0') ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">Discount</label>
                            <input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount"
                                class="form-control" value="<?= pb_form_value($editData, 'discount_amount', '0') ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">Advance</label>
                            <input type="number" step="0.01" min="0" name="advance_amount" id="advance_amount"
                                class="form-control" value="<?= pb_form_value($editData, 'advance_amount', '0') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Payment Mode</label>
                            <select name="payment_mode" class="form-select select2-autotype"
                                data-placeholder="Search payment mode">
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
                            <label class="form-label fw-bold">Finishing Option *</label>
                            <select name="finishing_required" id="finishing_required"
                                class="form-select select2-autotype" data-placeholder="Select finishing option">
                                <option value="1"
                                    <?= ((int)($editItem['finishing_required'] ?? 0) === 1) ? 'selected' : '' ?>>With
                                    Finishing</option>
                                <option value="0"
                                    <?= ((int)($editItem['finishing_required'] ?? 0) === 0) ? 'selected' : '' ?>>Without
                                    Finishing</option>
                            </select>
                        </div>


                        <div class="col-12 customized-only" id="customizedOptionsTitle">
                            <div class="customized-options-box">
                                <strong>Customized Order Options</strong>
                                <span>Fill Size, GSM, Lamination, Printing Side and Screening details.</span>
                            </div>
                        </div>

                        <div class="col-md-4 customized-only">
                            <label class="form-label fw-bold">Size <span class="text-danger">*</span></label>
                            <input name="size_text" class="form-control"
                                value="<?= pb_form_value($editItem, 'size_text') ?>">
                        </div>

                        <div class="col-md-4 customized-only">
                            <label class="form-label fw-bold">GSM Thickness <span class="text-danger">*</span></label>
                            <input name="gsm_thickness" class="form-control"
                                value="<?= pb_form_value($editItem, 'gsm_thickness') ?>">
                        </div>

                        <div class="col-md-4 customized-only">
                            <label class="form-label fw-bold">Lamination Required?</label>
                            <select name="lamination_required" id="lamination_required"
                                class="form-select select2-autotype" data-placeholder="Select lamination option">
                                <option value="0"
                                    <?= ((int)($editItem['lamination_required'] ?? 0) === 0) ? 'selected' : '' ?>>No
                                    Lamination</option>
                                <option value="1"
                                    <?= ((int)($editItem['lamination_required'] ?? 0) === 1) ? 'selected' : '' ?>>
                                    Lamination Required</option>
                            </select>
                        </div>

                        <div class="col-md-4 customized-only">
                            <label class="form-label fw-bold">Lamination Type</label>
                            <select name="lamination_type" class="form-select select2-autotype"
                                data-placeholder="Search lamination type">
                                <option value="">Select</option>
                                <option value="glossy"
                                    <?= (($editItem['lamination_type'] ?? '') === 'glossy') ? 'selected' : '' ?>>Glossy
                                </option>
                                <option value="matte"
                                    <?= (($editItem['lamination_type'] ?? '') === 'matte') ? 'selected' : '' ?>>Matte
                                </option>
                                <option value="special"
                                    <?= (($editItem['lamination_type'] ?? '') === 'special') ? 'selected' : '' ?>>
                                    Special</option>
                            </select>
                        </div>

                        <div class="col-md-4 customized-only">
                            <label class="form-label fw-bold">Printing Side <span class="text-danger">*</span></label>
                            <select name="printing_side" class="form-select select2-autotype"
                                data-placeholder="Search printing side">
                                <option value="">Select</option>
                                <option value="single"
                                    <?= (($editItem['printing_side'] ?? '') === 'single') ? 'selected' : '' ?>>Single
                                    Side</option>
                                <option value="double"
                                    <?= (($editItem['printing_side'] ?? '') === 'double') ? 'selected' : '' ?>>Double
                                    Side</option>
                            </select>
                        </div>

                        <div class="col-md-4 customized-only">
                            <label class="form-label fw-bold">Screening <span class="text-danger">*</span></label>
                            <select name="screening_type" class="form-select select2-autotype"
                                data-placeholder="Search screening">
                                <option value="">Select</option>
                                <option value="regular"
                                    <?= (($editItem['screening_type'] ?? '') === 'regular') ? 'selected' : '' ?>>Regular
                                    Screening</option>
                                <option value="special"
                                    <?= (($editItem['screening_type'] ?? '') === 'special') ? 'selected' : '' ?>>Special
                                    Screening</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Item Description</label>
                            <textarea name="description" class="form-control"
                                rows="2"><?= pb_form_value($editItem, 'description') ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Remarks</label>
                            <textarea name="remarks" class="form-control"
                                rows="2"><?= pb_form_value($editData, 'remarks') ?></textarea>
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


                    <div class="sales-order-note mb-3">
                        <div class="fw-black mb-1">Job Card Creation Details</div>
                        <div>
                            Proforma No is treated as the Sales Order Reference.
                            If job card is created immediately, tracking stages are created based on order type.
                            Customized jobs go to Designing / Proofing first and only move to Multicolor Offset Printing
                            after Design Approval.
                        </div>
                    </div>

                    <div class="d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="create_job_card_now"
                                id="create_job_card_now" <?= $editData ? '' : 'checked' ?>>
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
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <div class="section-title mb-1">Proforma Bill List</div>
                            <p class="text-muted-custom mb-0">Create job card directly from confirmed proforma bills.
                            </p>
                        </div>

                        <input type="search" id="tableSearch" class="form-control" style="max-width:340px"
                            placeholder="Search...">
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
                                    <td colspan="10" class="text-center text-muted-custom py-4">No proforma bills found.
                                    </td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><strong><?= e($row['proforma_no']) ?></strong></td>
                                    <td><?= e($row['customer_name']) ?><small
                                            class="d-block text-muted-custom"><?= e($row['mobile']) ?></small></td>
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
                                        <a href="proforma_bills.php?edit=<?= e($row['id']) ?>"
                                            class="btn btn-sm btn-outline-primary rounded-pill fw-bold">
                                            Edit
                                        </a>

                                        <?php if (empty($row['job_card_no'])): ?>
                                        <form method="post" class="d-inline"
                                            onsubmit="return confirm('Create job card for this proforma bill?')">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="create_job_card">
                                            <input type="hidden" name="proforma_id" value="<?= e($row['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-success rounded-pill fw-bold">
                                                Create Job Card
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <a href="job_cards.php"
                                            class="btn btn-sm btn-outline-secondary rounded-pill fw-bold">
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
                                    <small class="d-block text-muted-custom"><?= e($row['customer_name']) ?> ·
                                        <?= e($row['mobile']) ?></small>
                                    <small class="d-block text-muted-custom"><?= e($row['function_name'] ?? '-') ?> ·
                                        <?= e(ucfirst($row['order_type'])) ?> ·
                                        ₹<?= number_format((float)$row['final_amount'], 2) ?></small>
                                </div>
                                <span class="status-pill"><?= e($row['status_name'] ?? '-') ?></span>
                            </div>

                            <div class="mt-3 d-flex gap-2 flex-wrap">
                                <a href="proforma_bills.php?edit=<?= e($row['id']) ?>"
                                    class="btn btn-sm btn-outline-primary rounded-pill fw-bold">Edit</a>
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
    (function() {
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
        const laminationRequired = document.getElementById('lamination_required');
        const laminationTypeWrap = document.getElementById('laminationTypeWrap');
        const laminationType = document.getElementById('lamination_type');
        const customizedPrintingHelp = document.getElementById('customizedPrintingHelp');
        const readymadeInfo = document.querySelector('.readymade-info');
        const customizedInfo = document.querySelector('.customized-info');
        const screenSubtypeRequired = document.querySelector('.screen-subtype-required');


        function initPageSelect2(context) {
            if (window.initSelect2AutoType) {
                window.initSelect2AutoType(context || document);
                return;
            }

            if (!window.jQuery || !$.fn.select2) {
                return;
            }

            const $context = context ? $(context) : $(document);

            $context.find('select.select2-autotype').each(function() {
                const $select = $(this);

                if ($select.hasClass('select2-hidden-accessible')) {
                    $select.select2('destroy');
                }

                const enableTags = String($select.data('tags')) === 'true';

                $select.select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    placeholder: $select.data('placeholder') || $select.find('option:first')
                        .text() || 'Search and select',
                    allowClear: false,
                    tags: enableTags,
                    createTag: function(params) {
                        const term = $.trim(params.term);

                        if (!enableTags || term === '') {
                            return null;
                        }

                        return {
                            id: term,
                            text: term,
                            newTag: true
                        };
                    }
                });
            });
        }

        function refreshSelect2(idOrName) {
            if (!window.jQuery || !$.fn.select2) return;

            const byId = $('#' + idOrName);
            if (byId.length) {
                byId.trigger('change.select2');
                return;
            }

            $('[name="' + idOrName + '"]').trigger('change.select2');
        }

        function setFieldByName(name, value) {
            const el = document.querySelector('[name="' + name + '"]');
            if (el) el.value = value == null ? '' : value;
        }


        function money(value) {
            return '₹' + Number(value || 0).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function calculate() {
            let q = parseFloat(qty.value || 0);
            let r = parseFloat(rate.value || 0);
            let d = parseFloat(discount.value || 0);
            let a = parseFloat(advance.value || 0);

            if (isNaN(q) || q < 0) q = 0;
            if (isNaN(r) || r < 0) r = 0;
            if (isNaN(d) || d < 0) d = 0;
            if (isNaN(a) || a < 0) a = 0;

            const sub = q * r;

            if (d > sub) {
                d = sub;
                discount.value = d.toFixed(2);
            }

            const finalAmount = Math.max(0, sub - d);

            if (a > finalAmount) {
                a = finalAmount;
                advance.value = a.toFixed(2);
            }

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
                if (help) help.textContent =
                    'Wedding / Reception: Bride, groom, venue, date and time are required.';
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


        function normalizePrintingText(value) {
            return String(value || '')
                .toLowerCase()
                .replace(/&/g, 'and')
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '');
        }

        function optionTextData(opt) {
            if (!opt) return '';

            return [
                opt.dataset.printingKey || '',
                opt.dataset.normalizedKey || '',
                opt.dataset.printingName || '',
                opt.textContent || ''
            ].join(' ');
        }

        function isScreenPrintingOption(opt) {
            const text = normalizePrintingText(optionTextData(opt));
            return text.includes('screen');
        }

        function isMulticolorPrintingOption(opt) {
            const text = normalizePrintingText(optionTextData(opt));
            return (
                (text.includes('multicolor') || text.includes('multi_color') || text.includes('multicolour')) &&
                text.includes('offset')
            );
        }

        function findMulticolorPrintingValue() {
            if (!printingType) return '';

            const options = Array.from(printingType.options);
            const match = options.find(isMulticolorPrintingOption);

            return match ? match.value : '';
        }

        function isScreenPrintingSelected() {
            if (!printingType) return false;
            return isScreenPrintingOption(printingType.options[printingType.selectedIndex]);
        }

        function setSelect2Disabled(selectEl, disabled) {
            if (!selectEl) return;

            selectEl.disabled = disabled;

            if (window.jQuery && $.fn.select2) {
                $(selectEl).prop('disabled', disabled).trigger('change.select2');
            }
        }

        function showScreenSubType(show) {
            const wrap = document.getElementById('screenSubTypeWrap') || printingSubType?.closest('.col-md-4');
            const help = document.getElementById('screenSubTypeHelp');

            if (wrap) {
                wrap.style.display = show ? '' : 'none';
            }

            help?.classList.toggle('d-none', !show);

            if (!show && printingSubType) {
                printingSubType.value = '';
                refreshSelect2('printing_sub_type_id');
            }
        }

        function filterSubTypes() {
            if (!printingSubType || !printingType) return;

            if (orderType && orderType.value === 'customized') {
                printingSubType.value = '';
                showScreenSubType(false);
                refreshSelect2('printing_sub_type_id');
                return;
            }

            const show = orderType.value === 'readymade' && isScreenPrintingSelected();
            showScreenSubType(show);

            if (!show) {
                return;
            }

            const typeId = printingType.value;

            Array.from(printingSubType.options).forEach(function(opt) {
                if (!opt.value) {
                    opt.hidden = false;
                    opt.disabled = false;
                    return;
                }

                const match = String(opt.dataset.printingType || '') === String(typeId);
                opt.hidden = !match;
                opt.disabled = !match;
            });

            const selected = printingSubType.options[printingSubType.selectedIndex];
            if (selected && (selected.hidden || selected.disabled)) {
                printingSubType.value = '';
            }

            refreshSelect2('printing_sub_type_id');
        }

        function applyCustomizedPrintingRule() {
            if (!orderType || !printingType) return;

            const customized = orderType.value === 'customized';
            const multiColorValue = findMulticolorPrintingValue();

            if (customized) {
                if (multiColorValue) {
                    printingType.value = multiColorValue;
                    refreshSelect2('printing_type_id');
                }

                printingType.classList.add('printing-locked');
                customizedPrintingHelp?.classList.remove('d-none');
                customizedPrintingHelp?.classList.add('locked-select-note');

                setSelect2Disabled(printingType, false);

                if (printingSubType) {
                    printingSubType.value = '';
                    refreshSelect2('printing_sub_type_id');
                }
            } else {
                printingType.classList.remove('printing-locked');
                customizedPrintingHelp?.classList.add('d-none');
                customizedPrintingHelp?.classList.remove('locked-select-note');

                setSelect2Disabled(printingType, false);
            }
        }

        function toggleLaminationOptions() {
            if (!laminationRequired || !laminationTypeWrap) return;

            const customized = orderType.value === 'customized';
            const showType = customized && String(laminationRequired.value) === '1';

            laminationTypeWrap.style.display = showType ? '' : 'none';

            if (!showType && laminationType) {
                laminationType.value = '';
                refreshSelect2('lamination_type');
            }
        }

        function toggleOrderInfoCards() {
            const customized = orderType.value === 'customized';
            readymadeInfo?.classList.toggle('d-none', customized);
            customizedInfo?.classList.toggle('d-none', !customized);
        }

        function toggleOrderFields() {
            const customized = orderType.value === 'customized';

            document.querySelectorAll('.customized-only').forEach(el => el.style.display = customized ? '' :
                'none');
            document.querySelectorAll('.readymade-only').forEach(el => el.style.display = customized ? 'none' : '');

            toggleOrderInfoCards();
            applyCustomizedPrintingRule();
            toggleLaminationOptions();
            filterSubTypes();
        }

        function filterSubTypes() {
            if (orderType && orderType.value === 'customized') {
                if (printingSubType) {
                    printingSubType.value = '';
                    refreshSelect2('printing_sub_type_id');
                }
                return;
            }

            const typeId = printingType.value;
            Array.from(printingSubType.options).forEach(function(opt) {
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

            refreshSelect2('printing_sub_type_id');
        }


        document.querySelector('[name="quotation_id"]')?.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (!opt || !opt.value) return;

            setFieldByName('customer_name', opt.dataset.customerName || '');
            setFieldByName('mobile', opt.dataset.mobile || '');
            setFieldByName('billing_name', opt.dataset.customerName || '');
            setFieldByName('billing_mobile', opt.dataset.mobile || '');
            setFieldByName('billing_address', opt.dataset.billingAddress || '');
            setFieldByName('function_type_id', opt.dataset.functionTypeId || '');
            setFieldByName('bride_name', opt.dataset.brideName || '');
            setFieldByName('groom_name', opt.dataset.groomName || '');
            setFieldByName('venue', opt.dataset.venue || '');
            setFieldByName('function_date', opt.dataset.functionDate || '');
            setFieldByName('function_time', opt.dataset.functionTime || '');

            if (opt.dataset.totalQty) qty.value = opt.dataset.totalQty;
            if (opt.dataset.subTotal && parseFloat(rate.value || 0) <= 0 && parseFloat(qty.value || 0) >
                0) {
                rate.value = (parseFloat(opt.dataset.subTotal) / parseFloat(qty.value || 1)).toFixed(2);
            }
            if (opt.dataset.discountAmount) discount.value = opt.dataset.discountAmount;

            refreshSelect2('function_type_id');
            toggleFunctionFields();
            calculate();
        });


        product?.addEventListener('change', function() {
            const opt = product.options[product.selectedIndex];
            if (opt && opt.value) {
                const selectedText = opt.textContent.trim();

                if (isNaN(parseInt(opt.value, 10))) {
                    itemName.value = selectedText;
                    product.value = '';
                    refreshSelect2('product_id');
                } else {
                    if (!itemName.value) itemName.value = selectedText;
                    if (parseFloat(rate.value || 0) <= 0) rate.value = opt.dataset.price || 0;
                }

                calculate();
            }
        });

        [qty, rate, discount, advance].forEach(el => el?.addEventListener('input', calculate));
        functionType?.addEventListener('change', toggleFunctionFields);
        orderType?.addEventListener('change', toggleOrderFields);
        printingType?.addEventListener('change', function() {
            if (orderType.value === 'customized') {
                applyCustomizedPrintingRule();
                return;
            }

            filterSubTypes();
        });

        laminationRequired?.addEventListener('change', toggleLaminationOptions);


        function forceCorrectOrderTypeFields() {
            if (!orderType) return;

            const customized = orderType.value === 'customized';

            document.querySelectorAll('.customized-only').forEach(function(el) {
                el.style.display = customized ? '' : 'none';
            });

            document.querySelectorAll('.readymade-only').forEach(function(el) {
                el.style.display = customized ? 'none' : '';
            });

            const customTitle = document.getElementById('customizedOptionsTitle');
            if (customTitle) {
                customTitle.style.display = customized ? '' : 'none';
            }

            if (customized) {
                applyCustomizedPrintingRule();
            }

            toggleLaminationOptions();
        }

        orderType?.addEventListener('change', forceCorrectOrderTypeFields);
        laminationRequired?.addEventListener('change', toggleLaminationOptions);


        document.getElementById('tableSearch')?.addEventListener('input', function() {
            const value = this.value.toLowerCase().trim();
            document.querySelectorAll('#proformaTable tbody tr').forEach(function(row) {
                row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
            });
            document.querySelectorAll('#mobileCards .mobile-card').forEach(function(card) {
                card.style.display = card.textContent.toLowerCase().includes(value) ? '' : 'none';
            });
        });

        initPageSelect2(document);

        toggleFunctionFields();
        toggleOrderFields();
        forceCorrectOrderTypeFields();
        calculate();

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    })();
    </script>

    <script>
    /* =========================================================
   FINAL ORDER TYPE UI FORCE FIX
   This runs after the page script and force-shows required fields.
   ========================================================= */
    (function() {
        function byId(id) {
            return document.getElementById(id);
        }

        function show(el) {
            if (!el) return;
            el.style.setProperty('display', '', 'important');
        }

        function hide(el) {
            if (!el) return;
            el.style.setProperty('display', 'none', 'important');
        }

        function refreshSelect(el) {
            if (!el) return;
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                window.jQuery(el).trigger('change.select2');
            }
        }

        function findMulticolorValue(printingType) {
            if (!printingType) return '';

            var options = Array.prototype.slice.call(printingType.options || []);

            for (var i = 0; i < options.length; i++) {
                var opt = options[i];
                var text = (
                    (opt.getAttribute('data-printing-key') || '') + ' ' +
                    (opt.getAttribute('data-normalized-key') || '') + ' ' +
                    (opt.getAttribute('data-printing-name') || '') + ' ' +
                    (opt.textContent || '')
                ).toLowerCase();

                var isMulti = text.indexOf('multicolor') !== -1 ||
                    text.indexOf('multi color') !== -1 ||
                    text.indexOf('multi_color') !== -1 ||
                    text.indexOf('multicolour') !== -1;

                if (isMulti && text.indexOf('offset') !== -1) {
                    return opt.value;
                }
            }

            return '';
        }

        function isScreenPrint(printingType) {
            if (!printingType) return false;

            var opt = printingType.options[printingType.selectedIndex];
            if (!opt) return false;

            var text = (
                (opt.getAttribute('data-printing-key') || '') + ' ' +
                (opt.getAttribute('data-normalized-key') || '') + ' ' +
                (opt.getAttribute('data-printing-name') || '') + ' ' +
                (opt.textContent || '')
            ).toLowerCase();

            return text.indexOf('screen') !== -1;
        }

        function applyOrderTypeUI() {
            var orderType = byId('order_type');
            var printingType = byId('printing_type_id');
            var printingSubType = byId('printing_sub_type_id');
            var screenSubTypeWrap = byId('screenSubTypeWrap') || (printingSubType ? printingSubType.closest(
                '.col-md-4') : null);
            var laminationRequired = byId('lamination_required');
            var laminationTypeWrap = byId('laminationTypeWrap');
            var customizedPrintingHelp = byId('customizedPrintingHelp');
            var customTitle = byId('customizedOptionsTitle');

            if (!orderType) return;

            var isCustomized = orderType.value === 'customized';

            document.querySelectorAll('.customized-only').forEach(function(el) {
                if (isCustomized) {
                    show(el);
                } else {
                    hide(el);
                }
            });

            document.querySelectorAll('.readymade-only').forEach(function(el) {
                if (isCustomized) {
                    hide(el);
                } else {
                    show(el);
                }
            });

            if (customTitle) {
                isCustomized ? show(customTitle) : hide(customTitle);
            }

            if (isCustomized) {
                var multiValue = findMulticolorValue(printingType);

                if (printingType && multiValue) {
                    printingType.value = multiValue;
                    refreshSelect(printingType);
                }

                if (customizedPrintingHelp) {
                    show(customizedPrintingHelp);
                }

                if (printingSubType) {
                    printingSubType.value = '';
                    refreshSelect(printingSubType);
                }

                hide(screenSubTypeWrap);

                if (laminationTypeWrap) {
                    if (laminationRequired && laminationRequired.value === '1') {
                        show(laminationTypeWrap);
                    } else {
                        hide(laminationTypeWrap);
                    }
                }
            } else {
                if (customizedPrintingHelp) {
                    hide(customizedPrintingHelp);
                }

                if (screenSubTypeWrap) {
                    isScreenPrint(printingType) ? show(screenSubTypeWrap) : hide(screenSubTypeWrap);
                }

                if (laminationTypeWrap) {
                    hide(laminationTypeWrap);
                }
            }
        }

        function bind() {
            var orderType = byId('order_type');
            var printingType = byId('printing_type_id');
            var laminationRequired = byId('lamination_required');

            if (orderType) {
                orderType.addEventListener('change', applyOrderTypeUI);
                if (window.jQuery) {
                    window.jQuery(orderType).on('change.select2 change', applyOrderTypeUI);
                }
            }

            if (printingType) {
                printingType.addEventListener('change', applyOrderTypeUI);
                if (window.jQuery) {
                    window.jQuery(printingType).on('change.select2 change', applyOrderTypeUI);
                }
            }

            if (laminationRequired) {
                laminationRequired.addEventListener('change', applyOrderTypeUI);
                if (window.jQuery) {
                    window.jQuery(laminationRequired).on('change.select2 change', applyOrderTypeUI);
                }
            }

            applyOrderTypeUI();

            setTimeout(applyOrderTypeUI, 150);
            setTimeout(applyOrderTypeUI, 500);
            setTimeout(applyOrderTypeUI, 1000);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bind);
        } else {
            bind();
        }
    })();
    </script>


    <script>
    /* =========================================================
   CUSTOMIZED ORDER - AUTO MULTICOLOR OFFSET PRINTING
   Final force script. Runs after Select2 and page scripts.
   ========================================================= */
    (function() {
        function byId(id) {
            return document.getElementById(id);
        }

        function norm(value) {
            return String(value || '')
                .toLowerCase()
                .replace(/&/g, 'and')
                .replace(/[^a-z0-9]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function optionText(opt) {
            if (!opt) return '';
            return norm(
                (opt.getAttribute('data-printing-key') || '') + ' ' +
                (opt.getAttribute('data-normalized-key') || '') + ' ' +
                (opt.getAttribute('data-printing-name') || '') + ' ' +
                (opt.textContent || '')
            );
        }

        function findMulticolorOffsetOption(select) {
            if (!select) return null;

            var options = Array.prototype.slice.call(select.options || []);

            return options.find(function(opt) {
                var text = optionText(opt);

                var hasOffset = text.indexOf('offset') !== -1;
                var hasMulti =
                    text.indexOf('multicolor') !== -1 ||
                    text.indexOf('multi color') !== -1 ||
                    text.indexOf('multi colour') !== -1 ||
                    text.indexOf('multicolour') !== -1 ||
                    text.indexOf('multi') !== -1 && text.indexOf('color') !== -1;

                return opt.value && hasOffset && hasMulti;
            }) || null;
        }

        function refreshSelect2(select) {
            if (!select) return;
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                window.jQuery(select).trigger('change.select2');
            }
        }

        function setCustomizedPrinting() {
            var orderType = byId('order_type');
            var printingType = byId('printing_type_id');
            var printingSubType = byId('printing_sub_type_id');
            var help = byId('customizedPrintingHelp');

            if (!orderType || !printingType) return;

            if (orderType.value !== 'customized') {
                printingType.classList.remove('printing-locked');
                if (help) help.classList.add('d-none');
                return;
            }

            var match = findMulticolorOffsetOption(printingType);

            if (match) {
                printingType.value = match.value;
                printingType.classList.add('printing-locked');
                refreshSelect2(printingType);

                if (help) {
                    help.textContent = 'Customized order automatically uses Multicolor Offset Printing.';
                    help.classList.remove('d-none');
                }
            } else if (help) {
                help.textContent = 'Multicolor Offset Printing is missing in Printing Types master.';
                help.classList.remove('d-none');
            }

            if (printingSubType) {
                printingSubType.value = '';
                refreshSelect2(printingSubType);
                var wrap = byId('screenSubTypeWrap') || printingSubType.closest('.col-md-4');
                if (wrap) wrap.style.setProperty('display', 'none', 'important');
            }
        }

        function bindCustomizedPrinting() {
            var orderType = byId('order_type');
            var printingType = byId('printing_type_id');

            if (orderType) {
                orderType.addEventListener('change', setCustomizedPrinting);
                if (window.jQuery) {
                    window.jQuery(orderType).on('change change.select2', setCustomizedPrinting);
                }
            }

            if (printingType) {
                printingType.addEventListener('change', function() {
                    if (orderType && orderType.value === 'customized') {
                        setTimeout(setCustomizedPrinting, 10);
                    }
                });
            }

            setCustomizedPrinting();
            setTimeout(setCustomizedPrinting, 100);
            setTimeout(setCustomizedPrinting, 500);
            setTimeout(setCustomizedPrinting, 1200);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindCustomizedPrinting);
        } else {
            bindCustomizedPrinting();
        }
    })();
    </script>

</body>

</html>