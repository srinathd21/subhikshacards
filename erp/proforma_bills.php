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
// Backend create/update/job-card/WhatsApp processing moved to api/proforma_bills.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['proforma_csrf'])) {
    $_SESSION['proforma_csrf'] = bin2hex(random_bytes(32));
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
    $customerName = trim((string)($row['customer_name'] ?? 'Customer'));
    $proformaNo = trim((string)($row['proforma_no'] ?? '-'));
    $orderType = ucfirst((string)($row['order_type'] ?? '-'));
    $productName = trim((string)($row['item_name'] ?? '-'));
    $qty = number_format((float)($row['total_qty'] ?? 0), 0);
    $finalAmount = '₹' . number_format((float)($row['final_amount'] ?? 0), 2);
    $advance = '₹' . number_format((float)($row['advance_amount'] ?? 0), 2);
    $balance = '₹' . number_format((float)($row['balance_amount'] ?? 0), 2);
    $delivery = !empty($row['delivery_date']) ? date('d-m-Y', strtotime($row['delivery_date'])) : '-';

    return "Hi {$customerName},\n\n"
        . "Greetings from Subhiksha Cards.\n\n"
        . "Your proforma bill / sales order has been created successfully.\n\n"
        . "Proforma No: {$proformaNo}\n"
        . "Order Type: {$orderType}\n"
        . "Product: {$productName}\n"
        . "Quantity: {$qty}\n"
        . "Final Amount: {$finalAmount}\n"
        . "Advance Paid: {$advance}\n"
        . "Balance Amount: {$balance}\n"
        . "Delivery Date: {$delivery}\n\n"
        . "Our team will proceed with the next process and keep you updated.\n\n"
        . "Thank you,\n"
        . "Subhiksha Cards Team";
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
    try {
        if (!pb_table_exists($conn, 'whatsapp_templates')) {
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
        $relatedModule = 'Proforma Bills';
        $relatedId = $id;
        $customerId = !empty($row['customer_id']) ? (int)$row['customer_id'] : null;
        $jobCardId = null;
        $messageBody = pb_whatsapp_message($row);
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
    $apiFile = __DIR__ . '/includes/whatsapp-api.php';

    if (!file_exists($apiFile)) {
        return ['success' => false, 'message' => 'WhatsApp API file missing.'];
    }

    require_once $apiFile;

    if (!function_exists('subhiksha_send_whatsapp')) {
        return ['success' => false, 'message' => 'WhatsApp API function missing.'];
    }

    $row = pb_get_whatsapp_row($conn, $id);

    if (!$row) {
        return ['success' => false, 'message' => 'Proforma bill not found.'];
    }

    return subhiksha_send_whatsapp($conn, [
        'mobile' => (string)($row['mobile'] ?? ''),
        'template_key' => 'proforma_created',
        'variables' => [
            'customer_name' => (string)($row['customer_name'] ?? 'Customer'),
            'proforma_no' => (string)($row['proforma_no'] ?? '-'),
            'order_type' => ucfirst((string)($row['order_type'] ?? '-')),
            'product_name' => (string)($row['item_name'] ?? '-'),
            'quantity' => number_format((float)($row['total_qty'] ?? 0), 0),
            'final_amount' => '₹' . number_format((float)($row['final_amount'] ?? 0), 2),
            'advance_amount' => '₹' . number_format((float)($row['advance_amount'] ?? 0), 2),
            'balance_amount' => '₹' . number_format((float)($row['balance_amount'] ?? 0), 2),
            'delivery_date' => !empty($row['delivery_date']) ? date('d-m-Y', strtotime($row['delivery_date'])) : '-'
        ],
        'related_module' => 'Proforma Bills',
        'related_id' => $id,
        'customer_id' => $row['customer_id'] ?? null
    ]);
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
            class="btn btn-sm btn-whatsapp-icon btn-action-icon rounded-circle js-whatsapp-preview"
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

/* Backend processing moved to api/proforma_bills.php */

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') {
    $createdNo = trim((string)($_GET['proforma_no'] ?? ''));
    $createdJobNo = trim((string)($_GET['job_card_no'] ?? ''));
    $message = 'Proforma bill created successfully and listed below.';
    if ($createdNo !== '') {
        $message .= ' Proforma: ' . $createdNo . '.';
    }
    if ($createdJobNo !== '') {
        $message .= ' Job Card: ' . $createdJobNo . '.';
    }
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'updated') {
    $message = 'Proforma bill updated successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'job_created') {
    $message = 'Job card created successfully with tracking stages.';
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

$createdProformaNoFilter = trim((string)($_GET['proforma_no'] ?? ''));

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
        LEFT JOIN (SELECT proforma_bill_id, MAX(job_card_no) AS job_card_no FROM job_cards GROUP BY proforma_bill_id) jc ON jc.proforma_bill_id = pb.id
        LEFT JOIN function_types ft ON ft.id = pb.function_type_id
        ORDER BY pb.id DESC
        LIMIT 300
    ");
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
} catch (Throwable $e) {
    $rows = [];
    if ($message === '') {
        $message = 'Unable to load proforma bills list: ' . $e->getMessage();
        $messageType = 'danger';
        $toastTitle = 'Failed';
    }
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

$whatsappApiReady = pb_whatsapp_api_ready($conn);
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

    .order-type-info {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 14px 16px;
        background: color-mix(in srgb, var(--info-color) 7%, var(--card-bg));
        color: var(--text-main);
        font-weight: 700;
    }

    .customized-options-box {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 14px 16px;
        background: color-mix(in srgb, var(--success-color) 7%, var(--card-bg));
    }

    .customized-options-box strong,
    .customized-options-box span {
        display: block;
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




    .lamination-type-only {
        display: none;
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

    /* Mobile proforma card UI fix */
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

        .mobile-card-title,
        .mobile-card strong:first-child {
            font-size: 16px !important;
            line-height: 1.25 !important;
            margin-bottom: 6px !important;
            display: block !important;
        }

        .mobile-card-subtitle,
        .mobile-card .text-muted-custom {
            font-size: 12px !important;
            line-height: 1.45 !important;
            margin-top: 3px !important;
        }

        .mobile-card-actions,
        .mobile-card .mt-3.d-flex.gap-2.flex-wrap {
            margin-top: 14px !important;
            gap: 8px !important;
            display: flex !important;
            flex-wrap: wrap !important;
        }

        .mobile-card-actions .btn,
        .mobile-card .mt-3.d-flex.gap-2.flex-wrap .btn {
            min-height: 38px !important;
            border-radius: 999px !important;
            font-size: 13px !important;
            font-weight: 900 !important;
        }

        .mobile-card-actions .btn-whatsapp-icon,
        .mobile-card .mt-3.d-flex.gap-2.flex-wrap .btn-whatsapp-icon {
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

        .mobile-card-actions .btn-whatsapp-icon svg,
        .mobile-card .mt-3.d-flex.gap-2.flex-wrap .btn-whatsapp-icon svg {
            width: 18px !important;
            height: 18px !important;
        }

        .card-pad,
        .module-card {
            border-radius: 18px !important;
        }

        #tableSearch {
            min-height: 46px !important;
            border-radius: 16px !important;
        }
    }


    /* Proforma Bill List alignment - job card separate + action buttons */
    .proforma-list-table {
        table-layout: fixed;
        width: 100%;
        min-width: 1280px;
    }

    .proforma-list-table th,
    .proforma-list-table td {
        vertical-align: middle !important;
        white-space: normal;
        overflow: hidden;
    }

    .proforma-list-table th {
        font-size: 12px;
        letter-spacing: .01em;
    }

    .proforma-list-table th:nth-child(1),
    .proforma-list-table td:nth-child(1) { width: 11%; }

    .proforma-list-table th:nth-child(2),
    .proforma-list-table td:nth-child(2) { width: 12%; }

    .proforma-list-table th:nth-child(3),
    .proforma-list-table td:nth-child(3) { width: 9%; }

    .proforma-list-table th:nth-child(4),
    .proforma-list-table td:nth-child(4) { width: 9%; }

    .proforma-list-table th:nth-child(5),
    .proforma-list-table td:nth-child(5),
    .proforma-list-table th:nth-child(6),
    .proforma-list-table td:nth-child(6),
    .proforma-list-table th:nth-child(7),
    .proforma-list-table td:nth-child(7) {
        width: 8%;
        white-space: nowrap;
    }

    .proforma-list-table th:nth-child(8),
    .proforma-list-table td:nth-child(8) { width: 9%; }

    .proforma-list-table th:nth-child(9),
    .proforma-list-table td:nth-child(9) { width: 12%; }

    .proforma-list-table th:nth-child(10),
    .proforma-list-table td:nth-child(10) { width: 14%; }

    .proforma-list-table .status-pill {
        max-width: 100%;
        line-height: 1.25;
        white-space: normal;
        text-align: center;
        justify-content: center;
        padding: 7px 10px;
        word-break: break-word;
    }

    .proforma-list-table .action-buttons {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
        flex-wrap: wrap;
    }

    .proforma-list-table .action-buttons form {
        margin: 0;
        display: inline-flex;
    }

    .proforma-list-table .action-buttons .btn {
        min-height: 34px;
    }

    .proforma-list-table .action-buttons .btn-whatsapp-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
    }

    .desktop-table {
        overflow-x: auto;
    }

    @media(max-width:767.98px) {
        .mobile-card.proforma-mobile-card {
            padding: 16px 16px 14px !important;
            border-radius: 20px !important;
        }

        .proforma-mobile-card .proforma-mobile-head {
            align-items: flex-start !important;
            gap: 12px !important;
        }

        .proforma-mobile-card .mobile-card-title,
        .proforma-mobile-card strong:first-child {
            display: block !important;
            font-size: 16px !important;
            line-height: 1.25 !important;
            margin-bottom: 6px !important;
            word-break: break-word;
        }

        .proforma-mobile-card .mobile-card-subtitle,
        .proforma-mobile-card .text-muted-custom {
            font-size: 12px !important;
            line-height: 1.45 !important;
            margin-top: 3px !important;
        }

        .proforma-mobile-card .status-pill {
            align-self: flex-start !important;
            flex: 0 0 auto !important;
            min-width: auto !important;
            max-width: 125px !important;
            height: auto !important;
            min-height: 0 !important;
            line-height: 1.2 !important;
            padding: 6px 10px !important;
            border-radius: 999px !important;
            white-space: normal !important;
            text-align: center !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 10px !important;
        }

        .proforma-mobile-card .proforma-mobile-actions {
            margin-top: 14px !important;
            gap: 8px !important;
            display: grid !important;
            grid-template-columns: 1fr 1fr 42px;
            align-items: center;
        }

        .proforma-mobile-card .proforma-mobile-actions .btn:not(.btn-whatsapp-icon) {
            min-height: 38px !important;
            border-radius: 999px !important;
            font-size: 13px !important;
            font-weight: 900 !important;
            width: 100% !important;
        }

        .proforma-mobile-card .proforma-mobile-actions form {
            grid-column: 1 / -1;
            margin: 0;
        }

        .proforma-mobile-card .proforma-mobile-actions form .btn {
            width: 100% !important;
            min-height: 38px !important;
        }

        .proforma-mobile-card .proforma-mobile-actions .status-pill.ok {
            grid-column: 1 / -1;
            width: 100%;
            max-width: 100% !important;
        }

        .proforma-mobile-card .proforma-mobile-actions .btn-whatsapp-icon {
            width: 42px !important;
            height: 42px !important;
            min-width: 42px !important;
            max-width: 42px !important;
            flex: 0 0 42px !important;
            padding: 0 !important;
            border-radius: 50% !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .proforma-mobile-card .proforma-mobile-actions .btn-whatsapp-icon svg {
            width: 18px !important;
            height: 18px !important;
        }

        #tableSearch {
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


    .recent-created-row {
        outline: 2px solid color-mix(in srgb, var(--success-color, #16a34a) 55%, transparent);
        background: color-mix(in srgb, var(--success-color, #16a34a) 8%, var(--card-bg)) !important;
    }

    
    .list-only-info{
        border:1px dashed var(--border-soft);
        border-radius:18px;
        padding:14px 18px;
        background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));
        font-weight:800;
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
                                Only created proforma bills are listed here. Use Create Proforma Bill page for new entries.
                            </p>
                        </div>

                        <div class="d-flex flex-column flex-sm-row gap-2">
                            <a href="create_proforma.php" class="btn btn-primary rounded-pill px-4 fw-bold">
                                Create Proforma Bill
                            </a>
                        </div>
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

                <!-- Create/Edit Proforma form removed from this page. Use create_proforma.php for creation. -->

                

                <div class="card-ui card-pad">
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <div class="section-title mb-1">Proforma Bill List</div>
                            <p class="text-muted-custom mb-0">Created proforma bills are listed here. View opens full details in a new tab.</p>
                        </div>

                        <input type="search" id="tableSearch" class="form-control" style="max-width:340px"
                            placeholder="Search...">
                    </div>

                    <div class="table-responsive desktop-table">
                        <table class="table-ui proforma-list-table" id="proformaTable">
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
                                <?php $isCreatedRow = ($createdProformaNoFilter !== '' && (string)($row['proforma_no'] ?? '') === $createdProformaNoFilter); ?>
                                <tr class="<?= $isCreatedRow ? 'recent-created-row' : '' ?>">
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
                                        <div class="action-buttons">
                                            <a title="View" aria-label="View" href="proforma_bill_view.php?id=<?= e($row['id']) ?>" target="_blank"
                                                class="btn btn-sm btn-outline-secondary rounded-circle fw-bold btn-action-icon"><i data-lucide="eye"></i></a>
<?= pb_whatsapp_button($row) ?>

                                            <?php if (empty($row['job_card_no'])): ?>
                                            <form method="post" action="api/proforma_bills.php" class="js-api-job-card-form" onsubmit="return false;">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="create_job_card">
                                                <input type="hidden" name="proforma_id" value="<?= e($row['id']) ?>">
                                                <button title="Create Job Card" aria-label="Create Job Card" type="submit" class="btn btn-sm btn-success rounded-circle fw-bold btn-action-icon"><i data-lucide="briefcase-business"></i></button>
                                            </form>
                                            <?php endif; ?>

                                            <form method="post" action="api/proforma_bills.php" class="js-api-delete-form" onsubmit="return false;">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete_record">
                                                <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                                <button title="Delete" aria-label="Delete" type="submit" class="btn btn-sm btn-outline-danger rounded-circle fw-bold btn-delete-icon btn-action-icon"><i data-lucide="trash-2"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-cards" id="mobileCards">
                        <?php foreach ($rows as $row): ?>
                        <div class="mobile-card proforma-mobile-card">
                            <div class="d-flex justify-content-between gap-2 proforma-mobile-head">
                                <div>
                                    <strong><?= e($row['proforma_no']) ?></strong>
                                    <small class="d-block text-muted-custom"><?= e($row['customer_name']) ?> ·
                                        <?= e($row['mobile']) ?></small>
                                    <small class="d-block text-muted-custom"><?= e($row['function_name'] ?? '-') ?> ·
                                        <?= e(ucfirst($row['order_type'])) ?> ·
                                        ₹<?= number_format((float)$row['final_amount'], 2) ?></small>
                                    <?php if (!empty($row['job_card_no'])): ?>
                                    <small class="d-block text-muted-custom">Job Card: <?= e($row['job_card_no']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <span class="status-pill"><?= e($row['status_name'] ?? '-') ?></span>
                            </div>

                            <div class="mobile-card-actions proforma-mobile-actions mt-3 d-flex gap-2 flex-wrap">
                                <a title="View" aria-label="View" href="proforma_bill_view.php?id=<?= e($row['id']) ?>" target="_blank"
                                                class="btn btn-sm btn-outline-secondary rounded-circle fw-bold btn-action-icon"><i data-lucide="eye"></i></a>
<?= pb_whatsapp_button($row) ?>
                                <?php if (empty($row['job_card_no'])): ?>
                                <form method="post" action="api/proforma_bills.php" class="js-api-job-card-form" onsubmit="return false;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="create_job_card">
                                    <input type="hidden" name="proforma_id" value="<?= e($row['id']) ?>">
                                    <button title="Create Job Card" aria-label="Create Job Card" class="btn btn-sm btn-success rounded-circle fw-bold btn-action-icon"><i data-lucide="briefcase-business"></i></button>
                                </form>
                                <?php endif; ?>

                                <form method="post" action="api/proforma_bills.php" class="js-api-delete-form" onsubmit="return false;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_record">
                                    <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                    <button title="Delete" aria-label="Delete" type="submit" class="btn btn-sm btn-outline-danger rounded-circle fw-bold btn-delete-icon btn-action-icon"><i data-lucide="trash-2"></i></button>
                                </form>
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

    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold">View Proforma Bill / Sales Order</h5>
                        <small class="text-muted-custom" id="viewProformaNo">-</small>
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
                                <small>Order Type</small>
                                <strong id="viewOrderType">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Total Qty</small>
                                <strong id="viewTotalQty">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Sub Total</small>
                                <strong id="viewSubTotal">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Discount</small>
                                <strong id="viewDiscountAmount">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Final Amount</small>
                                <strong id="viewFinalAmount">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Advance Paid</small>
                                <strong id="viewAdvanceAmount">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Balance Amount</small>
                                <strong id="viewBalanceAmount">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Delivery Date</small>
                                <strong id="viewDeliveryDate">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Job Card</small>
                                <strong id="viewJobCardNo">-</strong>
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
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>


    <div class="modal fade" id="whatsappPreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="post" action="api/proforma_bills.php" id="whatsappApiForm">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="send_whatsapp_api">
                    <input type="hidden" name="id" id="wa_proforma_id" value="">

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
                            <?= pb_whatsapp_svg() ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
    (function() {

        const whatsappApiReady = <?= $whatsappApiReady ? 'true' : 'false' ?>;
        let currentManualWhatsappUrl = '#';
        let whatsappPreviewModal = null;

        function showToast(message, type = 'success', titleText = '') {
            if (!message) return;

            const oldToastWrap = document.getElementById('dynamicActionToastWrap');
            if (oldToastWrap) {
                oldToastWrap.remove();
            }

            const toastTitle = titleText || (type === 'danger' ? 'Failed' : (type === 'warning' ? 'Warning' :
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

            if (showType) {
                laminationTypeWrap.classList.remove('lamination-type-only');
                laminationTypeWrap.style.setProperty('display', 'block', 'important');
            } else {
                laminationTypeWrap.classList.add('lamination-type-only');
                laminationTypeWrap.style.setProperty('display', 'none', 'important');
            }

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


        function applyQuotationReference() {
            const quotationSelect = document.querySelector('[name="quotation_id"]');
            const opt = quotationSelect?.options[quotationSelect.selectedIndex];

            if (!opt || !opt.value) {
                return;
            }

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

            if (opt.dataset.deliveryDate) {
                setFieldByName('delivery_date', opt.dataset.deliveryDate || '');
            }

            if (opt.dataset.remarks) {
                setFieldByName('remarks', opt.dataset.remarks || '');
            }

            if (qty && opt.dataset.totalQty) {
                qty.value = opt.dataset.totalQty;
            }

            if (rate) {
                if (opt.dataset.unitRate && parseFloat(opt.dataset.unitRate || 0) > 0) {
                    rate.value = parseFloat(opt.dataset.unitRate || 0).toFixed(2);
                } else if (opt.dataset.subTotal && parseFloat(qty?.value || 0) > 0) {
                    rate.value = (parseFloat(opt.dataset.subTotal || 0) / parseFloat(qty.value || 1)).toFixed(2);
                }
            }

            if (discount && opt.dataset.discountAmount) {
                discount.value = parseFloat(opt.dataset.discountAmount || 0).toFixed(2);
            }

            if (advance) {
                advance.value = '0.00';
            }

            refreshSelect2('function_type_id');
            refreshSelect2('quotation_id');

            toggleFunctionFields();
            toggleOrderFields();
            if (typeof forceCorrectOrderTypeFields === 'function') {
                forceCorrectOrderTypeFields();
            }
            if (window.subhikshaApplyStrictOrderTypeRequirements) {
                window.subhikshaApplyStrictOrderTypeRequirements();
            }
            if (window.subhikshaToggleLaminationType) {
                window.subhikshaToggleLaminationType();
            }

            calculate();
        }

        document.querySelector('[name="quotation_id"]')?.addEventListener('change', applyQuotationReference);

        if (window.jQuery) {
            window.jQuery('[name="quotation_id"]').on('select2:select change', function() {
                setTimeout(applyQuotationReference, 50);
            });
        }

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



        function openWhatsappPreview(btn) {
            const modalEl = document.getElementById('whatsappPreviewModal');
            if (!modalEl) return;

            setFieldByName('id', btn.dataset.id || '');
            const hiddenId = document.getElementById('wa_proforma_id');
            if (hiddenId) {
                hiddenId.value = btn.dataset.id || '';
            }

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


        function setViewText(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            const clean = (value == null || String(value).trim() === '') ? '-' : String(value);
            el.textContent = clean;
        }

        document.querySelectorAll('.js-view-proforma').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setViewText('viewProformaNo', btn.dataset.proformaNo || '-');
                setViewText('viewCustomerName', btn.dataset.customerName || '-');
                setViewText('viewMobile', btn.dataset.mobile || '-');
                setViewText('viewStatusName', btn.dataset.statusName || '-');
                setViewText('viewFunctionName', btn.dataset.functionName || '-');
                setViewText('viewOrderType', btn.dataset.orderType || '-');
                setViewText('viewTotalQty', btn.dataset.totalQty || '-');
                setViewText('viewSubTotal', btn.dataset.subTotal || '-');
                setViewText('viewDiscountAmount', btn.dataset.discountAmount || '-');
                setViewText('viewFinalAmount', btn.dataset.finalAmount || '-');
                setViewText('viewAdvanceAmount', btn.dataset.advanceAmount || '-');
                setViewText('viewBalanceAmount', btn.dataset.balanceAmount || '-');
                setViewText('viewDeliveryDate', btn.dataset.deliveryDate || '-');
                setViewText('viewJobCardNo', btn.dataset.jobCardNo || '-');
                setViewText('viewRemarks', btn.dataset.remarks || '-');
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

                fetch('api/proforma_bills.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    showToast(data.message || (data.status ? 'WhatsApp sent successfully.' : 'WhatsApp failed.'), data.status ? 'success' : 'danger', data.status ? 'Success' : 'Failed');

                    if (data.open_whatsapp_url) {
                        window.location.href = data.open_whatsapp_url;
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

                fetch('api/proforma_bills.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                }).catch(function() {
                    showToast('WhatsApp log failed, but manual WhatsApp will open.', 'warning',
                        'Warning');
                }).finally(function() {
                    window.location.href = currentManualWhatsappUrl;

                    if (whatsappPreviewModal) {
                        whatsappPreviewModal.hide();
                    }
                });
            } else {
                showToast('Customer mobile number is missing.', 'danger', 'Failed');
            }
        });

        document.querySelectorAll('.js-api-job-card-form').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
            });

            form.querySelector('button[type="submit"], button:not([type])')?.addEventListener('click', function() {
                const ok = confirm('Create job card?');
                if (!ok) return;

                const formData = new FormData(form);

                fetch('api/proforma_bills.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    showToast(data.message || (data.status ? 'Job card created.' : 'Job card creation failed.'), data.status ? 'success' : 'danger', data.status ? 'Success' : 'Failed');

                    if (data.status) {
                        setTimeout(() => window.location.reload(), 900);
                    }
                })
                .catch(() => showToast('API request failed.', 'danger', 'Failed'));
            });
        });



        document.querySelectorAll('.js-api-delete-form').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
            });

            form.querySelector('button[type="submit"]')?.addEventListener('click', function() {
                const ok = confirm('Delete this proforma bill? Related job card, job tracking, items and payments will also be removed.');
                if (!ok) return;

                const formData = new FormData(form);

                fetch('api/proforma_bills.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    showToast(data.message || (data.status ? 'Proforma bill deleted.' : 'Delete failed.'), data.status ? 'success' : 'danger', data.status ? 'Success' : 'Failed');

                    if (data.status) {
                        setTimeout(() => window.location.reload(), 900);
                    }
                })
                .catch(() => showToast('API request failed.', 'danger', 'Failed'));
            });
        });


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
                        laminationTypeWrap.classList.remove('lamination-type-only');
                        laminationTypeWrap.style.setProperty('display', 'block', 'important');
                    } else {
                        laminationTypeWrap.classList.add('lamination-type-only');
                        laminationTypeWrap.style.setProperty('display', 'none', 'important');
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





    <script>
    (function() {
        function getEl(id) {
            return document.getElementById(id);
        }

        function getVal(id) {
            var el = getEl(id);
            return el ? String(el.value || '') : '';
        }

        function clearSelect2Value(el) {
            if (!el) return;
            el.value = '';
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                window.jQuery(el).val('').trigger('change.select2');
            }
        }

        window.subhikshaToggleLaminationType = function() {
            var orderType = getVal('order_type');
            var laminationRequired = getVal('lamination_required');
            var wrap = getEl('laminationTypeWrap');
            var typeSelect = getEl('lamination_type');

            if (!wrap) return;

            if (orderType === 'customized' && laminationRequired === '1') {
                wrap.style.cssText = 'display:block !important;';
                wrap.classList.remove('d-none');
            } else {
                wrap.style.cssText = 'display:none !important;';
                wrap.classList.add('d-none');
                clearSelect2Value(typeSelect);
            }
        };

        function bindLamination() {
            ['order_type', 'lamination_required'].forEach(function(id) {
                var el = getEl(id);
                if (!el) return;

                el.addEventListener('change', function() {
                    setTimeout(window.subhikshaToggleLaminationType, 10);
                });

                if (window.jQuery) {
                    window.jQuery(el).on('change select2:select', function() {
                        setTimeout(window.subhikshaToggleLaminationType, 10);
                    });
                }
            });

            window.subhikshaToggleLaminationType();
            setTimeout(window.subhikshaToggleLaminationType, 200);
            setTimeout(window.subhikshaToggleLaminationType, 800);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindLamination);
        } else {
            bindLamination();
        }
    })();
    </script>


    <script>
    (function() {
        function byId(id) {
            return document.getElementById(id);
        }

        function refreshSelect(el) {
            if (!el) return;
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                window.jQuery(el).trigger('change.select2');
            }
        }

        function optionText(opt) {
            if (!opt) return '';
            return (
                (opt.getAttribute('data-printing-key') || '') + ' ' +
                (opt.getAttribute('data-normalized-key') || '') + ' ' +
                (opt.getAttribute('data-printing-name') || '') + ' ' +
                (opt.textContent || '')
            ).toLowerCase();
        }

        function isAllowedReadymadePrinting(opt) {
            const text = optionText(opt);
            return text.includes('offset') || text.includes('screen') || text.includes('digital') || !opt.value;
        }

        function isOffsetPrint(opt) {
            return optionText(opt).includes('offset');
        }

        function isScreenPrint(opt) {
            return optionText(opt).includes('screen');
        }

        function filterReadymadePrintingOptions() {
            const orderType = byId('order_type');
            const printingType = byId('printing_type_id');
            if (!orderType || !printingType) return;

            const customized = orderType.value === 'customized';

            Array.from(printingType.options).forEach(function(opt) {
                if (!opt.value) {
                    opt.hidden = false;
                    opt.disabled = false;
                    return;
                }

                const allowed = customized ? isOffsetPrint(opt) : isAllowedReadymadePrinting(opt);
                opt.hidden = !allowed;
                opt.disabled = !allowed;
            });

            const selected = printingType.options[printingType.selectedIndex];
            if (selected && (selected.hidden || selected.disabled || (customized && !isOffsetPrint(selected)))) {
                const replacement = Array.from(printingType.options).find(function(opt) {
                    return opt.value && !opt.disabled && !opt.hidden && (customized ? isOffsetPrint(opt) :
                        isAllowedReadymadePrinting(opt));
                });

                printingType.value = replacement ? replacement.value : '';
                refreshSelect(printingType);
            }
        }

        function applyStrictOrderTypeRequirements() {
            const orderType = byId('order_type');
            const printingType = byId('printing_type_id');
            const printingSubType = byId('printing_sub_type_id');
            const screenSubTypeWrap = byId('screenSubTypeWrap') || (printingSubType ? printingSubType.closest(
                '.col-md-4') : null);
            const laminationRequired = byId('lamination_required');
            const laminationTypeWrap = byId('laminationTypeWrap');
            const customizedPrintingHelp = byId('customizedPrintingHelp');
            const customized = orderType && orderType.value === 'customized';

            document.querySelectorAll('.customized-only').forEach(function(el) {
                el.style.setProperty('display', customized ? '' : 'none', 'important');
            });

            document.querySelectorAll('.readymade-only').forEach(function(el) {
                el.style.setProperty('display', customized ? 'none' : '', 'important');
            });

            filterReadymadePrintingOptions();

            if (customized) {
                if (customizedPrintingHelp) {
                    customizedPrintingHelp.classList.remove('d-none');
                    customizedPrintingHelp.textContent = 'Customized order automatically uses Offset Print.';
                }

                if (screenSubTypeWrap) {
                    screenSubTypeWrap.style.setProperty('display', 'none', 'important');
                }

                if (printingSubType) {
                    printingSubType.value = '';
                    refreshSelect(printingSubType);
                }

                if (laminationTypeWrap) {
                    const showLamination = laminationRequired && laminationRequired.value === '1';
                    if (showLamination) {
                        laminationTypeWrap.classList.remove('lamination-type-only');
                        laminationTypeWrap.style.setProperty('display', 'block', 'important');
                    } else {
                        laminationTypeWrap.classList.add('lamination-type-only');
                        laminationTypeWrap.style.setProperty('display', 'none', 'important');
                    }
                }
            } else {
                if (customizedPrintingHelp) {
                    customizedPrintingHelp.classList.add('d-none');
                }

                const selectedPrint = printingType ? printingType.options[printingType.selectedIndex] : null;
                const showScreenSubType = isScreenPrint(selectedPrint);

                if (screenSubTypeWrap) {
                    screenSubTypeWrap.style.setProperty('display', showScreenSubType ? '' : 'none', 'important');
                }

                if (laminationTypeWrap) {
                    laminationTypeWrap.style.setProperty('display', 'none', 'important');
                }
            }

            if (laminationTypeWrap && (!laminationRequired || laminationRequired.value !== '1')) {
                const laminationType = byId('lamination_type');
                if (laminationType) {
                    laminationType.value = '';
                    refreshSelect(laminationType);
                }
            }
        }

        ['order_type', 'printing_type_id', 'lamination_required'].forEach(function(id) {
            const el = byId(id);
            if (!el) return;

            el.addEventListener('change', applyStrictOrderTypeRequirements);

            if (window.jQuery) {
                window.jQuery(el).on('change select2:select select2:clear',
                    applyStrictOrderTypeRequirements);
            }
        });

        window.subhikshaApplyStrictOrderTypeRequirements = applyStrictOrderTypeRequirements;

        window.addEventListener('load', function() {
            setTimeout(applyStrictOrderTypeRequirements, 250);
        });

        applyStrictOrderTypeRequirements();
    })();
    </script>


    <script>
    (function() {
        function refreshSelect(el) {
            if (!el) return;
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                window.jQuery(el).trigger('change.select2');
            }
        }

        function fixLaminationTypeVisibility() {
            var orderType = document.getElementById('order_type');
            var laminationRequired = document.getElementById('lamination_required');
            var laminationTypeWrap = document.getElementById('laminationTypeWrap');
            var laminationType = document.getElementById('lamination_type');

            if (!orderType || !laminationRequired || !laminationTypeWrap) return;

            var shouldShow = orderType.value === 'customized' && String(laminationRequired.value) === '1';

            if (shouldShow) {
                laminationTypeWrap.classList.remove('lamination-type-only', 'd-none');
                laminationTypeWrap.style.setProperty('display', 'block', 'important');
            } else {
                laminationTypeWrap.classList.add('lamination-type-only');
                laminationTypeWrap.style.setProperty('display', 'none', 'important');

                if (laminationType) {
                    laminationType.value = '';
                    refreshSelect(laminationType);
                }
            }
        }

        ['order_type', 'lamination_required'].forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;

            el.addEventListener('change', fixLaminationTypeVisibility);

            if (window.jQuery) {
                window.jQuery(el).on('change select2:select select2:clear', fixLaminationTypeVisibility);
            }
        });

        window.subhikshaToggleLaminationType = fixLaminationTypeVisibility;

        window.addEventListener('load', function() {
            setTimeout(fixLaminationTypeVisibility, 250);
        });

        fixLaminationTypeVisibility();
    })();
    </script>

</body>

</html>