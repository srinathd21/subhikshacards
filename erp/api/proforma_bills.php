<?php
/**
 * api/proforma_bills.php
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

$csrfToken = $_SESSION['proforma_csrf'];
$message = '';
$messageType = 'success';
$toastTitle = 'Info';

function pb_table_exists(mysqli $conn, string $table): bool
{
    
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
    $apiFile = __DIR__ . '/../includes/whatsapp-api.php';

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
    if (
        empty($_REQUEST['csrf_token']) ||
        empty($_SESSION['proforma_csrf']) ||
        !hash_equals($_SESSION['proforma_csrf'], (string)$_REQUEST['csrf_token'])
    ) {
        apiResponse(false, 'Invalid CSRF token.');
    }
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

    if (in_array($action, ['save_proforma', 'create', 'update', 'delete', 'delete_record', 'create_job_card', 'log_manual_whatsapp', 'send_whatsapp_api'], true)) {
        apiCsrf();
    }

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

        if (in_array($action, ['save_proforma', 'create', 'update'], true)) {
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
                $offsetPrintingTypeId = pb_offset_printing_type_id($conn);
                if (!$offsetPrintingTypeId) {
                    throw new RuntimeException('Offset Print type is missing. Please add it in Printing Types master.');
                }

                $printingTypeId = $offsetPrintingTypeId;
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

                if (!pb_printing_type_allowed_for_readymade($conn, $printingTypeId)) {
                    throw new RuntimeException('Readymade order allows only Offset Print, Screen Print, or Digital Print.');
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

                apiResponse(true, 'Proforma bill saved and job card created successfully.', [
                    'id' => $proformaId,
                    'job_id' => $jobId,
                    'created_job_card' => true
                ]);
            }

            apiResponse(true, $id > 0 ? 'Proforma bill updated successfully.' : 'Proforma bill created successfully.', [
                'id' => $proformaId,
                'updated' => $id > 0
            ]);
        }


        if ($action === 'log_manual_whatsapp') {
            $id = pb_int($_POST['id'] ?? 0);

            if ($id <= 0) {
                apiResponse(false, 'Invalid proforma bill.');
            }

            $manualResult = pb_whatsapp_log_manual($conn, $id);
            apiResponse((bool)($manualResult['success'] ?? false), (string)($manualResult['message'] ?? ''), $manualResult);
        }

        if ($action === 'send_whatsapp_api') {
            $id = pb_int($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Invalid proforma bill.');
            }

            if (!pb_whatsapp_api_ready($conn)) {
                $row = pb_get_whatsapp_row($conn, $id);
                apiResponse(false, 'WhatsApp API is not ready. Manual WhatsApp mode is active.', [
                    'manual_whatsapp' => true,
                    'open_whatsapp_url' => $row ? pb_whatsapp_url($row) : ''
                ]);
            }

            $waResult = pb_send_whatsapp_by_api($conn, $id);

            if (!($waResult['success'] ?? false)) {
                apiResponse(false, (string)($waResult['response'] ?? $waResult['message'] ?? 'WhatsApp failed.'), $waResult);
            }

            apiResponse(true, 'WhatsApp message sent successfully using API.', $waResult);
        }


        if (in_array($action, ['delete', 'delete_record'], true)) {
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
            $proformaId = pb_int($_POST['proforma_id'] ?? 0);

            if ($proformaId <= 0) {
                throw new RuntimeException('Invalid proforma bill.');
            }

            $conn->begin_transaction();
            $jobId = pb_create_job_card($conn, $proformaId);
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