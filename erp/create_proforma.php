<?php

/*
 * This UI file belongs in /erp/create_proforma.php.
 * Its includes folder is /erp/includes.
 * Do not replace this file with /erp/api/create_proforma.php.
 */
$dbFile = __DIR__ . '/includes/db.php';
$authFile = __DIR__ . '/includes/auth.php';

if (!is_file($dbFile)) {
    throw new RuntimeException('Database include not found: ' . $dbFile);
}
if (!is_file($authFile)) {
    throw new RuntimeException('Auth include not found: ' . $authFile);
}

require_once $dbFile;
require_once $authFile;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentPage = 'proforma_bills.php';
$editId = (int)filter_var($_GET['id'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
$isEditMode = $editId > 0 && (isset($_GET['mode']) && (string)$_GET['mode'] === 'edit');

if (!function_exists('cpCheckPagePermission')) {
    function cpCheckPagePermission(mysqli $conn, string $page, array $permissionFunctions): bool
    {
        foreach ($permissionFunctions as $functionName) {
            if (!function_exists($functionName)) {
                continue;
            }

            try {
                if ((bool)$functionName($conn, $page)) {
                    return true;
                }
            } catch (ArgumentCountError $e) {
                try {
                    if ((bool)$functionName($page)) {
                        return true;
                    }
                } catch (Throwable $inner) {
                    continue;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return false;
    }
}

$roleKeyForCreatePage = strtolower(trim((string)(
    $_SESSION['role_key']
    ?? $_SESSION['role']
    ?? $_SESSION['user_role']
    ?? ''
)));

$createProformaAllowed = in_array($roleKeyForCreatePage, ['admin', 'super_admin', 'superadmin'], true);

if (!$createProformaAllowed && function_exists('is_super_admin')) {
    try {
        $createProformaAllowed = (bool)is_super_admin();
    } catch (Throwable $e) {
        $createProformaAllowed = false;
    }
}

if (!$createProformaAllowed) {
    $permissionFunctions = $isEditMode
        ? ['can_edit', 'can_update', 'can_create', 'can_view']
        : ['can_create', 'can_view', 'can_update', 'can_edit'];

    $createProformaAllowed = cpCheckPagePermission($conn, $currentPage, $permissionFunctions);
}

if (!$createProformaAllowed && function_exists('require_permission')) {
    /* Final fallback: ask for view permission instead of create-only permission. */
    require_permission($conn, 'can_view', $currentPage);
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

function cpFetchProformaForEdit(mysqli $conn, int $id): ?array
{
    if ($id <= 0 || !cpTableExists($conn, 'proforma_bills')) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                pb.*,
                pbi.id AS item_id,
                pbi.product_id AS item_product_id,
                pbi.item_name,
                pbi.description AS item_description,
                pbi.qty AS item_qty,
                pbi.rate AS item_rate,
                pbi.amount AS item_amount,
                pbi.printing_type_id AS item_printing_type_id,
                pbi.printing_sub_type_id AS item_printing_sub_type_id,
                pbi.finishing_required AS item_finishing_required,
                pbi.size_text AS item_size_text,
                pbi.gsm_thickness AS item_gsm_thickness,
                pbi.lamination_required AS item_lamination_required,
                pbi.lamination_type AS item_lamination_type,
                pbi.printing_side AS item_printing_side,
                pbi.screening_type AS item_screening_type
            FROM proforma_bills pb
            LEFT JOIN proforma_bill_items pbi
                ON pbi.proforma_bill_id = pb.id
            WHERE pb.id = ?
            ORDER BY pbi.sort_order ASC, pbi.id ASC
            LIMIT 1
        " );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}


function cpFetchProformaItemsForEdit(mysqli $conn, int $proformaId): array
{
    if ($proformaId <= 0 || !cpTableExists($conn, 'proforma_bill_items')) {
        return [];
    }

    $rows = [];

    try {
        $hasProductStock = cpTableExists($conn, 'product_stock');
        $hasReservations = cpTableExists($conn, 'product_stock_reservations');
        $hasItemPlannedDates = cpColumnExists($conn, 'proforma_bill_items', 'planned_dates_json');

        $plannedDatesSelect = $hasItemPlannedDates
            ? "pbi.planned_dates_json"
            : "NULL AS planned_dates_json";

        $stockSelect = $hasProductStock
            ? "
                COALESCE(ps.on_hand_stock, 0) AS on_hand_stock,
                COALESCE(ps.reserved_stock, 0) AS reserved_stock,
                (COALESCE(ps.on_hand_stock, 0) - COALESCE(ps.reserved_stock, 0)) AS current_available_stock
              "
            : "
                0 AS on_hand_stock,
                0 AS reserved_stock,
                0 AS current_available_stock
              ";

        $reservationSelect = $hasReservations
            ? "COALESCE(CASE WHEN psr.status = 'active' THEN psr.quantity ELSE 0 END, 0) AS current_proforma_reserved"
            : "0 AS current_proforma_reserved";

        $stockJoin = $hasProductStock
            ? "LEFT JOIN product_stock ps ON ps.product_id = pbi.product_id"
            : "";

        $reservationJoin = $hasReservations
            ? "
                LEFT JOIN product_stock_reservations psr
                    ON psr.product_id = pbi.product_id
                   AND psr.reference_type = 'proforma'
                   AND psr.reference_id = pbi.proforma_bill_id
              "
            : "";

        $sql = "
            SELECT
                pbi.id,
                pbi.product_id,
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
                pbi.printing_price_master_id,
                pbi.price_slab_text,
                pbi.plate_charge,
                pbi.item_printing_charge,
                pbi.item_package_charge,
                pbi.item_additional_charge,
                pbi.is_gst_inclusive,
                pbi.sort_order,
                {$plannedDatesSelect},
                {$stockSelect},
                {$reservationSelect}
            FROM proforma_bill_items pbi
            {$stockJoin}
            {$reservationJoin}
            WHERE pbi.proforma_bill_id = ?
            ORDER BY pbi.sort_order ASC, pbi.id ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $proformaId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $currentAvailable = (float)($row['current_available_stock'] ?? 0);
            $ownReservation = (float)($row['current_proforma_reserved'] ?? 0);

            /*
             * While editing, the existing reservation belonging to THIS Proforma
             * must be temporarily added back. Otherwise the UI would subtract the
             * same quantity twice when displaying "Available After".
             */
            $availableForEdit = $currentAvailable + $ownReservation;
            $qty = (float)($row['qty'] ?? 0);

            $storedPlannedDates = [];
            if (!empty($row['planned_dates_json'])) {
                $decodedPlannedDates = json_decode((string)$row['planned_dates_json'], true);
                if (is_array($decodedPlannedDates)) {
                    $storedPlannedDates = $decodedPlannedDates;
                }
            }

            $rows[] = [
                'proforma_item_id' => (int)($row['id'] ?? 0),
                'product_id' => !empty($row['product_id']) ? (string)(int)$row['product_id'] : '',
                'product_name' => (string)($row['item_name'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'qty' => $qty,
                'rate' => (float)($row['rate'] ?? 0),
                'amount' => (float)($row['amount'] ?? 0),
                'printing_type_id' => !empty($row['printing_type_id']) ? (string)(int)$row['printing_type_id'] : '',
                'printing_sub_type_id' => !empty($row['printing_sub_type_id']) ? (string)(int)$row['printing_sub_type_id'] : '',
                'finishing_required' => (int)($row['finishing_required'] ?? 0),
                'size_text' => (string)($row['size_text'] ?? ''),
                'gsm_thickness' => (string)($row['gsm_thickness'] ?? ''),
                'lamination_required' => (int)($row['lamination_required'] ?? 0),
                'lamination_type' => (string)($row['lamination_type'] ?? ''),
                'printing_side' => (string)($row['printing_side'] ?? ''),
                'screening_type' => (string)($row['screening_type'] ?? ''),
                'printing_price_master_id' => !empty($row['printing_price_master_id']) ? (string)(int)$row['printing_price_master_id'] : '',
                'price_slab_text' => (string)($row['price_slab_text'] ?? ''),
                'plate_charge' => (float)($row['plate_charge'] ?? 0),
                'item_printing_charge' => (float)($row['item_printing_charge'] ?? 0),
                'item_package_charge' => (float)($row['item_package_charge'] ?? 0),
                'item_additional_charge' => (float)($row['item_additional_charge'] ?? 0),
                'is_gst_inclusive' => (int)($row['is_gst_inclusive'] ?? 1),
                'on_hand_stock' => (float)($row['on_hand_stock'] ?? 0),
                'reserved_stock' => max(0, (float)($row['reserved_stock'] ?? 0) - $ownReservation),
                'available_stock' => $availableForEdit,
                'projected_available_stock' => $availableForEdit - $qty,
                'current_proforma_reserved' => $ownReservation,
                'planned_dates' => $storedPlannedDates,
            ];
        }

        $stmt->close();

        /*
         * Existing Customized Job Cards have independent job_tracking.
         * During Edit Proforma, read each Job Card's own planned dates.
         * This does not modify workflow/status logic.
         */
        if (
            $rows &&
            cpTableExists($conn, 'job_cards') &&
            cpColumnExists($conn, 'job_cards', 'proforma_bill_item_id') &&
            cpTableExists($conn, 'job_tracking')
        ) {
            $trackingByItem = [];

            $stmt = $conn->prepare("
                SELECT
                    jc.proforma_bill_item_id,
                    jt.workflow_step_id,
                    jt.planned_start_date,
                    jt.planned_completion_date
                FROM job_cards jc
                INNER JOIN job_tracking jt ON jt.job_card_id = jc.id
                WHERE jc.proforma_bill_id = ?
                  AND jc.proforma_bill_item_id IS NOT NULL
                ORDER BY jc.id ASC, jt.workflow_step_id ASC
            ");
            $stmt->bind_param('i', $proformaId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($trackingRow = $res->fetch_assoc()) {
                $itemId = (int)($trackingRow['proforma_bill_item_id'] ?? 0);
                $stepId = (int)($trackingRow['workflow_step_id'] ?? 0);

                if ($itemId <= 0 || $stepId <= 0) {
                    continue;
                }

                if (!isset($trackingByItem[$itemId])) {
                    $trackingByItem[$itemId] = [];
                }

                $trackingByItem[$itemId][(string)$stepId] = [
                    'start' => !empty($trackingRow['planned_start_date'])
                        ? substr((string)$trackingRow['planned_start_date'], 0, 10)
                        : '',
                    'completion' => !empty($trackingRow['planned_completion_date'])
                        ? substr((string)$trackingRow['planned_completion_date'], 0, 10)
                        : '',
                ];
            }

            $stmt->close();

            foreach ($rows as &$editItemRow) {
                $itemId = (int)($editItemRow['proforma_item_id'] ?? 0);
                if ($itemId > 0 && !empty($trackingByItem[$itemId])) {
                    $editItemRow['planned_dates'] = $trackingByItem[$itemId];
                }
            }
            unset($editItemRow);
        }
    } catch (Throwable $e) {
        return [];
    }

    return $rows;
}


function cpFetchPlannedDatesForEdit(mysqli $conn, int $proformaId): array
{
    $plannedDates = [];

    if (
        $proformaId <= 0 ||
        !cpTableExists($conn, 'job_cards') ||
        !cpTableExists($conn, 'job_tracking')
    ) {
        return $plannedDates;
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                jt.workflow_step_id,
                jt.planned_start_date,
                jt.planned_completion_date
            FROM job_tracking jt
            INNER JOIN (
                SELECT id
                FROM job_cards
                WHERE proforma_bill_id = ?
                ORDER BY id DESC
                LIMIT 1
            ) jc ON jc.id = jt.job_card_id
            ORDER BY jt.workflow_step_id ASC
        ");
        $stmt->bind_param('i', $proformaId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $stepId = (string)(int)($row['workflow_step_id'] ?? 0);
            if ($stepId === '0') {
                continue;
            }

            $plannedDates[$stepId] = [
                'start' => !empty($row['planned_start_date']) ? substr((string)$row['planned_start_date'], 0, 10) : '',
                'completion' => !empty($row['planned_completion_date']) ? substr((string)$row['planned_completion_date'], 0, 10) : ''
            ];
        }

        $stmt->close();
    } catch (Throwable $e) {
        return [];
    }

    return $plannedDates;
}

if (empty($_SESSION['create_proforma_csrf'])) {
    $_SESSION['create_proforma_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['create_proforma_csrf'];

$message = '';
$messageType = 'success';
$createdProformaNo = '';
$createdJobCardNo = '';

if (!empty($_SESSION['create_proforma_flash']) && is_array($_SESSION['create_proforma_flash'])) {
    $flash = $_SESSION['create_proforma_flash'];
    $message = (string)($flash['message'] ?? '');
    $messageType = (string)($flash['message_type'] ?? 'success');
    $createdProformaNo = (string)($flash['proforma_no'] ?? '');
    $createdJobCardNo = (string)($flash['job_card_no'] ?? '');
    unset($_SESSION['create_proforma_flash']);
}

/* Create / Edit Proforma backend moved to api/create_proforma.php */

$editData = null;
if ($isEditMode) {
    $editData = cpFetchProformaForEdit($conn, $editId);
    if (!$editData) {
        $message = 'Proforma bill not found for editing.';
        $messageType = 'danger';
        $isEditMode = false;
        $editId = 0;
    }
}

$plannedDates = ($isEditMode && $editId > 0) ? cpFetchPlannedDatesForEdit($conn, $editId) : [];
$editItems = ($isEditMode && $editId > 0) ? cpFetchProformaItemsForEdit($conn, $editId) : [];

$editReservationMap = [];
foreach ($editItems as $editItemRow) {
    $pid = (string)($editItemRow['product_id'] ?? '');
    if ($pid !== '') {
        $editReservationMap[$pid] = (float)($editItemRow['current_proforma_reserved'] ?? 0);
    }
}

$editQuotationId = $editData && !empty($editData['quotation_id']) ? (int)$editData['quotation_id'] : 0;
$quotationWhere = 'pb.id IS NULL';
if ($editQuotationId > 0) {
    $quotationWhere = '(pb.id IS NULL OR q.id = ' . $editQuotationId . ')';
}

$quotations = cpFetchAll($conn, "SELECT q.*, e.enquiry_no, e.created_at AS enquiry_created_at, e.updated_at AS enquiry_completed_at, ft.function_name, ft.field_group, c.gst_number, c.address AS customer_address FROM quotations q LEFT JOIN enquiries e ON e.id = q.enquiry_id LEFT JOIN function_types ft ON ft.id = q.function_type_id LEFT JOIN customers c ON c.id = q.customer_id LEFT JOIN proforma_bills pb ON pb.quotation_id = q.id WHERE {$quotationWhere} ORDER BY q.id DESC LIMIT 500");
$functionTypes = cpFetchAll($conn, "SELECT id, function_name, field_group FROM function_types WHERE is_active = 1 ORDER BY sort_order ASC, function_name ASC");
$proformaStatuses = cpFetchAll($conn, "SELECT id, status_name, status_key, color_code FROM proforma_statuses WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
$defaultProformaStatusId = 0;
foreach ($proformaStatuses as $statusRow) {
    if (strtolower((string)($statusRow['status_key'] ?? '')) === 'confirmed') {
        $defaultProformaStatusId = (int)$statusRow['id'];
        break;
    }
}
if ($defaultProformaStatusId <= 0 && $proformaStatuses) {
    $defaultProformaStatusId = (int)($proformaStatuses[0]['id'] ?? 0);
}
$selectedProformaStatusId = ($isEditMode && $editData && !empty($editData['proforma_status_id'])) ? (int)$editData['proforma_status_id'] : $defaultProformaStatusId;
/*
 * Product Master + Stock for Proforma product selector.
 * Available = On Hand - Reserved.
 * Stock is informational here: Proforma is still allowed to reserve more than
 * available stock, so this page does NOT block quantity based on stock.
 */
$productRemovedWhere = cpColumnExists($conn, 'products', 'is_removed')
    ? " AND COALESCE(p.is_removed, 0) = 0"
    : "";

if (cpTableExists($conn, 'product_stock')) {
    $products = cpFetchAll($conn, "
        SELECT
            p.id,
            p.product_name,
            p.default_order_type,
            p.default_price,
            COALESCE(ps.on_hand_stock, 0) AS on_hand_stock,
            COALESCE(ps.reserved_stock, 0) AS reserved_stock,
            (COALESCE(ps.on_hand_stock, 0) - COALESCE(ps.reserved_stock, 0)) AS available_stock
        FROM products p
        LEFT JOIN product_stock ps ON ps.product_id = p.id
        WHERE p.is_active = 1{$productRemovedWhere}
        ORDER BY
            CASE
                WHEN (COALESCE(ps.on_hand_stock, 0) - COALESCE(ps.reserved_stock, 0)) > 0 THEN 0
                ELSE 1
            END ASC,
            (COALESCE(ps.on_hand_stock, 0) - COALESCE(ps.reserved_stock, 0)) DESC,
            p.product_name ASC
    ");
} else {
    $products = cpFetchAll($conn, "
        SELECT
            p.id,
            p.product_name,
            p.default_order_type,
            p.default_price,
            0 AS on_hand_stock,
            0 AS reserved_stock,
            0 AS available_stock
        FROM products p
        WHERE p.is_active = 1{$productRemovedWhere}
        ORDER BY p.product_name ASC
    ");
}

/*
 * Edit mode must keep every existing Proforma item selectable.
 * If an old product is inactive/removed/missing from the normal active Product
 * Master query, append a temporary option for editing only.
 */
if ($isEditMode && $editItems) {
    foreach ($editItems as $editItemRow) {
        $editPid = (int)($editItemRow['product_id'] ?? 0);
        $editName = trim((string)($editItemRow['product_name'] ?? ''));

        if ($editPid <= 0 && $editName === '') {
            continue;
        }

        $exists = false;
        foreach ($products as $productRow) {
            if ($editPid > 0 && (int)($productRow['id'] ?? 0) === $editPid) {
                $exists = true;
                break;
            }
            if ($editPid <= 0 && $editName !== '' &&
                strcasecmp(trim((string)($productRow['product_name'] ?? '')), $editName) === 0) {
                $exists = true;
                break;
            }
        }

        if (!$exists && $editPid > 0) {
            $products[] = [
                'id' => $editPid,
                'product_name' => $editName !== '' ? $editName : ('Product #' . $editPid),
                'default_order_type' => (string)($editData['order_type'] ?? 'readymade'),
                'default_price' => (float)($editItemRow['rate'] ?? 0),
                'on_hand_stock' => (float)($editItemRow['on_hand_stock'] ?? 0),
                /*
                 * Store RAW reserved/current-available values in the option.
                 * JavaScript adds this Proforma's own reservation back in edit mode.
                 */
                'reserved_stock' => (float)($editItemRow['reserved_stock'] ?? 0)
                    + (float)($editItemRow['current_proforma_reserved'] ?? 0),
                'available_stock' => (float)($editItemRow['available_stock'] ?? 0)
                    - (float)($editItemRow['current_proforma_reserved'] ?? 0),
            ];
        }
    }
}

/*
 | Edit Mode Product Fix
 | Old proforma records may have item_name saved but product_id empty,
 | or the saved product may now be inactive/missing from products master.
 | Product Master is required in UI, so add/select the saved item as a valid option.
 */
$editProductSelectValue = '';
$editProductSelectName = '';
$editProductExistsInProducts = false;
if ($isEditMode && $editData) {
    $editItemProductId = (int)($editData['item_product_id'] ?? 0);
    $editItemName = trim((string)($editData['item_name'] ?? ''));
    $editProductSelectValue = $editItemProductId > 0 ? (string)$editItemProductId : $editItemName;
    $editProductSelectName = $editItemName !== '' ? $editItemName : 'Saved Product';

    foreach ($products as $p) {
        $pid = (int)($p['id'] ?? 0);
        $pname = trim((string)($p['product_name'] ?? ''));
        if ($editItemProductId > 0 && $pid === $editItemProductId) {
            $editProductExistsInProducts = true;
            $editProductSelectName = $pname !== '' ? $pname : $editProductSelectName;
            break;
        }
        if ($editItemProductId <= 0 && $editItemName !== '' && strcasecmp($pname, $editItemName) === 0) {
            $editProductExistsInProducts = true;
            $editProductSelectValue = (string)$pid;
            $editProductSelectName = $pname;
            break;
        }
    }
}

$printingTypes = cpFetchAll($conn, "SELECT id, printing_name, printing_key, role_key, is_for_readymade, is_for_customized FROM printing_types WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
$printingSubTypes = cpFetchAll($conn, "SELECT id, printing_type_id, sub_type_name FROM printing_sub_types WHERE is_active = 1 ORDER BY printing_type_id ASC, sort_order ASC, id ASC");
$readymadeSteps = cpFetchAll($conn, "SELECT id, step_name, step_key, sort_order, is_final_step FROM workflow_steps WHERE order_type = 'readymade' AND is_active = 1 ORDER BY sort_order ASC");
$customizedSteps = cpFetchAll($conn, "SELECT id, step_name, step_key, sort_order, is_final_step FROM workflow_steps WHERE order_type = 'customized' AND is_active = 1 ORDER BY sort_order ASC");

$quotationJson = json_encode($quotations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$subTypeJson = json_encode($printingSubTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$printingTypeJson = json_encode($printingTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$stepsJson = json_encode(['readymade' => $readymadeSteps, 'printing_only' => $readymadeSteps, 'customized' => $customizedSteps], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$plannedDatesJson = json_encode($plannedDates ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$editJson = json_encode($editData ?: null, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$editItemsJson = json_encode($editItems ?: [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$editReservationJson = json_encode($editReservationMap ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $isEditMode ? 'Edit Proforma Bill' : 'Create Proforma Bill' ?> - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
    .module-page .page-head {
        padding: 24px 28px;
        margin-bottom: 18px
    }

    .module-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main)
    }

    .module-card {
        padding: 24px
    }

    .section-title {
        font-size: 16px;
        font-weight: 900;
        color: var(--text-main);
        margin-bottom: 12px
    }

    .form-control,
    .form-select {
        border-radius: 14px;
        min-height: 46px
    }

    .form-label {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .02em
    }

    .soft-panel {
        border: 1px solid var(--border-soft);
        border-radius: 20px;
        padding: 18px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg))
    }

    .amount-box {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 14px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg))
    }

    .amount-box small {
        display: block;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase
    }

    .amount-box strong {
        display: block;
        margin-top: 4px;
        color: var(--text-main);
        font-size: 20px;
        font-weight: 900
    }

    .amount-summary-panel {
        border: 1px solid var(--border-soft);
        border-radius: 22px;
        padding: 16px;
        background: color-mix(in srgb, var(--card-bg) 98%, var(--body-bg));
        overflow: hidden;
    }

    .amount-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .amount-summary-item {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 12px 14px;
        min-height: 74px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
    }

    .amount-summary-item small {
        display: block;
        color: var(--text-muted);
        font-size: 10.5px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .02em;
        margin-bottom: 6px;
    }

    .amount-summary-item strong {
        display: block;
        color: var(--text-main);
        font-size: 16px;
        font-weight: 500;
        line-height: 1.15;
        white-space: nowrap;
    }

    .amount-summary-item.final {
        border-color: color-mix(in srgb, var(--primary-color, #2563eb) 45%, var(--border-soft));
        background: color-mix(in srgb, var(--primary-color, #2563eb) 7%, var(--card-bg));
    }

    .amount-summary-item.final strong {
        font-size: 22px;
        font-weight: 950;
        color: var(--text-main);
    }

    .amount-summary-item.balance strong {
        color: #991b1b;
    }

    .amount-summary-item.return-payment {
        border-color: color-mix(in srgb, var(--warning-color, #f59e0b) 48%, var(--border-soft));
        background: color-mix(in srgb, var(--warning-color, #f59e0b) 8%, var(--card-bg));
    }

    .amount-summary-item.return-payment strong {
        color: #9a3412;
        font-weight: 900;
    }

    .return-payment-notice {
        border: 1px solid color-mix(in srgb, var(--warning-color, #f59e0b) 50%, var(--border-soft));
        border-radius: 16px;
        padding: 12px 14px;
        background: color-mix(in srgb, var(--warning-color, #f59e0b) 10%, var(--card-bg));
        color: var(--text-main);
        font-size: 13px;
        font-weight: 800;
        line-height: 1.45;
    }

    .return-amount-field .input-group-text,
    .return-amount-field .form-control {
        border-color: color-mix(in srgb, var(--warning-color, #f59e0b) 55%, var(--border-soft));
        background: color-mix(in srgb, var(--warning-color, #f59e0b) 8%, var(--card-bg));
    }

    .return-amount-field .form-control {
        color: #9a3412;
        font-size: 18px;
        font-weight: 900;
    }

    .amount-summary-note {
        margin-top: 10px;
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 800;
    }

    .charge-input-row {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        align-items: end;
        width: 100%;
    }

    .charge-input-card {
        min-width: 0;
    }

    .charge-input-card .form-control {
        width: 100%;
    }

    .quotation-select-wrap {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        align-items: stretch;
    }

    .quotation-clear-btn {
        min-height: 46px;
        border-radius: 14px;
        padding-inline: 18px;
        white-space: nowrap;
        font-weight: 900;
    }



    .product-select-wrap {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 46px;
        gap: 10px;
        align-items: stretch;
    }

    .product-clear-btn {
        min-height: 46px;
        border-radius: 14px;
        font-size: 20px;
        font-weight: 950;
        line-height: 1;
    }

    .product-add-line-wrap {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 10px;
    }

    .product-select2-result {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
    }

    .product-select2-result-name {
        min-width: 0;
        flex: 1 1 auto;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-weight: 800;
    }

    .product-select2-result-stock {
        flex: 0 0 auto;
        white-space: nowrap;
        font-size: 11px;
        font-weight: 900;
        color: var(--text-muted);
        border: 1px solid var(--border-soft);
        border-radius: 999px;
        padding: 3px 8px;
        background: color-mix(in srgb, var(--card-bg) 94%, var(--body-bg));
    }

    .product-select2-result-stock.negative {
        color: #b91c1c;
        border-color: #fca5a5;
        background: #fee2e2;
        font-weight: 950;
    }

    .product-add-line-btn {
        min-height: 46px;
        border-radius: 14px;
        padding: 0 22px;
        font-weight: 900;
    }

    .proforma-items-panel {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        overflow: hidden;
        background: var(--card-bg);
    }

    .proforma-items-head {
        padding: 13px 15px;
        border-bottom: 1px solid var(--border-soft);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        background: color-mix(in srgb, var(--card-bg) 95%, var(--body-bg));
    }

    .proforma-items-table-wrap {
        overflow-x: auto;
    }

    .proforma-items-table {
        width: 100%;
        min-width: 900px;
        border-collapse: collapse;
    }

    .proforma-items-table th,
    .proforma-items-table td {
        padding: 11px 12px;
        border-bottom: 1px solid var(--border-soft);
        vertical-align: middle;
        font-size: 12px;
    }

    .proforma-items-table th {
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: .02em;
        color: var(--text-muted);
        font-weight: 900;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
    }

    .proforma-items-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .product-stock-badge {
        display: inline-flex;
        align-items: center;
        border: 1px solid var(--border-soft);
        border-radius: 999px;
        padding: 3px 8px;
        font-size: 10px;
        font-weight: 900;
        color: var(--text-muted);
        margin-top: 4px;
    }

    .product-stock-badge.negative {
        color: #b91c1c;
        border-color: #fca5a5;
        background: #fee2e2;
        font-weight: 950;
    }

    .proforma-item-actions {
        display: flex;
        gap: 6px;
        justify-content: flex-end;
    }

    .proforma-item-actions .btn {
        width: 34px;
        height: 34px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
    }

    .proforma-items-empty {
        padding: 18px;
        text-align: center;
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 800;
    }

    .custom-only-field.hide-field,
    .screen-subtype-field.hide-field,
    .colour-type-field.hide-field,
    .lamination-type-wrap.hide-field {
        display: none !important;
    }

    .select2-container--bootstrap-5 .select2-selection--single .select2-selection__clear {
        margin-right: 8px;
        font-size: 20px;
        line-height: 1;
        color: #991b1b;
        font-weight: 900;
    }

    .toast-ui {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
        overflow: hidden;
        min-width: 320px;
        max-width: 460px
    }

    .toast-ui.success {
        background: #dcfce7;
        color: #14532d
    }

    .toast-ui.danger {
        background: #fee2e2;
        color: #7f1d1d
    }

    .toast-title {
        font-size: 14px;
        font-weight: 900
    }

    .toast-message {
        font-size: 13px;
        font-weight: 800;
        line-height: 1.45
    }

    .hide-field {
        display: none !important
    }

    .workflow-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        width: 100%;
    }

    .workflow-step {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 14px;
        background: var(--card-bg);
        min-width: 0;
    }

    .workflow-step strong {
        display: block;
        font-size: 13px;
        line-height: 1.35;
        min-height: 36px;
        word-break: break-word;
    }

    .workflow-date-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-top: 10px;
    }

    .workflow-date-field small {
        display: block;
        font-size: 11px;
        font-weight: 800;
        color: var(--text-muted);
        margin-bottom: 5px;
    }

    .workflow-date-field input[type="date"] {
        width: 100%;
        min-width: 0;
        min-height: 42px;
        padding-inline: 10px;
        font-size: 13px;
    }

    .select2-container {
        width: 100% !important
    }

    .page-loading-overlay {
        position: fixed;
        inset: 0;
        z-index: 14000;
        background: rgba(255, 255, 255, .82);
        backdrop-filter: blur(6px);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .page-loading-overlay.show {
        display: flex;
    }

    .loading-card {
        width: min(420px, 100%);
        border-radius: 24px;
        padding: 24px;
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        box-shadow: 0 22px 60px rgba(15, 23, 42, .18);
        text-align: center;
    }

    .loading-card .spinner-border {
        width: 3rem;
        height: 3rem;
    }

    .denom-table td,
    .denom-table th {
        vertical-align: middle;
        font-size: 13px;
    }

    .denom-table input {
        min-height: 38px;
        border-radius: 10px;
    }

    .denom-total-box {
        border-radius: 14px;
        background: color-mix(in srgb, var(--card-bg) 94%, var(--body-bg));
        border: 1px solid var(--border-soft);
        padding: 10px 12px;
        font-weight: 900;
    }


    .payment-mode-checks {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .payment-check-card {
        position: relative;
        cursor: pointer;
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 14px 14px 14px 44px;
        min-height: 76px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        transition: all .18s ease;
        display: flex;
        flex-direction: column;
        justify-content: center;
        user-select: none;
    }

    .payment-check-card input {
        position: absolute;
        left: 14px;
        top: 18px;
        width: 20px;
        height: 20px;
        accent-color: var(--primary-color, #2563eb);
    }

    .payment-check-card strong {
        font-size: 15px;
        color: var(--text-main);
        font-weight: 900;
        line-height: 1.1;
    }

    .payment-check-card small {
        margin-top: 5px;
        color: var(--text-muted);
        font-weight: 800;
        line-height: 1.25;
    }

    .payment-check-card.active {
        border-color: var(--primary-color, #2563eb);
        box-shadow: 0 12px 28px rgba(37, 99, 235, .14);
        background: color-mix(in srgb, var(--primary-color, #2563eb) 8%, var(--card-bg));
    }

    .cash-denom-example {
        border-radius: 14px;
        border: 1px dashed color-mix(in srgb, var(--warning-color, #f59e0b) 50%, var(--border-soft));
        background: color-mix(in srgb, var(--warning-color, #f59e0b) 8%, var(--card-bg));
        padding: 10px 12px;
        font-size: 12px;
        font-weight: 800;
        color: var(--text-main);
    }

    .denom-format-box {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 14px;
        background: color-mix(in srgb, var(--card-bg) 97%, var(--body-bg));
    }

    .denom-section-heading {
        font-size: 14px;
        font-weight: 900;
        color: var(--text-main);
        margin-bottom: 8px;
    }

    .denom-format-row {
        display: grid;
        grid-template-columns: minmax(92px, 120px) auto 18px minmax(120px, 1fr);
        gap: 8px;
        align-items: center;
        margin-bottom: 8px;
    }

    .denom-count-input,
    .cash-denom-amount-input {
        min-height: 40px;
        border-radius: 12px;
        font-weight: 800;
    }

    .cash-denom-amount-input[readonly] {
        background: color-mix(in srgb, var(--card-bg) 88%, var(--body-bg));
        color: var(--text-main);
    }

    .denom-symbol,
    .denom-equals {
        font-weight: 900;
        color: var(--text-main);
        white-space: nowrap;
    }


    .cash-denom-dialog {
        max-width: 540px;
    }

    .cash-denom-modal-content {
        border-radius: 28px;
        overflow: hidden;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .22);
    }

    .cash-denom-modal-content .modal-header {
        padding: 22px 20px 14px;
    }

    .cash-denom-modal-content .modal-title {
        font-size: 25px;
        line-height: 1.1;
        color: #0f172a;
    }

    .cash-denom-modal-content .modal-body {
        max-height: min(68vh, 620px);
        overflow-y: auto;
        padding: 18px 20px 12px;
    }

    .cash-denom-summary-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        border: 1px solid #86efac;
        background: #dcfce7;
        color: #0f172a;
        border-radius: 16px;
        padding: 12px 14px;
        font-weight: 950;
        margin-bottom: 14px;
    }

    .cash-denom-summary-bar span {
        font-weight: 950;
        white-space: nowrap;
    }

    .cash-denom-modal-content .denom-format-box {
        border: 0;
        padding: 0;
        background: transparent;
    }

    .cash-denom-modal-content .denom-section-heading {
        font-size: 15px;
        margin: 12px 0 8px;
    }

    .cash-denom-modal-content .denom-format-row {
        grid-template-columns: 108px 92px minmax(150px, 1fr);
        gap: 10px;
        margin-bottom: 10px;
    }

    .cash-denom-modal-content .denom-equals {
        display: none;
    }

    .cash-denom-modal-content .denom-count-input,
    .cash-denom-modal-content .cash-denom-amount-input {
        min-height: 46px;
        border-radius: 13px;
        font-size: 16px;
        font-weight: 950;
    }

    .cash-denom-modal-content .denom-count-input {
        text-align: center;
    }

    .cash-denom-modal-content .cash-denom-amount-input {
        text-align: right;
    }

    .cash-denom-modal-content .modal-footer {
        padding: 16px 20px 20px;
        background: #fff;
        box-shadow: 0 -8px 20px rgba(15, 23, 42, .04);
    }

    @media(max-width:575.98px) {
        .cash-denom-modal-content .denom-format-row {
            grid-template-columns: 90px 76px minmax(120px, 1fr);
        }

        .cash-denom-summary-bar {
            align-items: flex-start;
            flex-direction: column;
        }
    }

    @media(max-width:1199.98px) {

        .workflow-grid,
        .amount-summary-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media(max-width:991.98px) {

        .workflow-grid,
        .amount-summary-grid,
        .charge-input-row {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media(max-width:575.98px) {
        .quotation-select-wrap {
            grid-template-columns: 1fr;
        }

        .quotation-clear-btn {
            width: 100%;
        }

        .workflow-grid,
        .amount-summary-grid,
        .charge-input-row {
            grid-template-columns: 1fr;
        }

        .workflow-date-grid {
            grid-template-columns: 1fr;
        }

        .amount-summary-item strong {
            white-space: normal;
        }

        .payment-mode-checks {
            grid-template-columns: 1fr;
        }

        .denom-format-row {
            grid-template-columns: 88px auto 14px minmax(100px, 1fr);
            gap: 6px;
        }
    }


    body.modal-open-fallback {
        overflow: hidden;
    }

    .modal-backdrop-fallback {
        position: fixed;
        inset: 0;
        z-index: 1040;
        background: rgba(15, 23, 42, .5);
    }

    .modal.show.modal-fallback {
        display: block;
        z-index: 1055;
        background: transparent;
    }

    @media(max-width:767.98px) {
        .module-page .page-head {
            padding: 18px;
            border-radius: 18px
        }

        .module-page .page-head h1 {
            font-size: 24px
        }

        .module-card {
            padding: 16px;
            border-radius: 18px
        }
    }

    .pricing-requirement-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 14px;
        border-radius: 16px;
        background: color-mix(in srgb, var(--primary-color, #2563eb) 7%, var(--card-bg));
        border: 1px dashed color-mix(in srgb, var(--primary-color, #2563eb) 35%, var(--border-soft));
        color: var(--text-main);
        font-weight: 950;
        margin-bottom: 4px;
    }

    .pricing-summary-card {
        border: 1px solid #86efac;
        border-radius: 20px;
        padding: 16px;
        background: linear-gradient(135deg, rgba(240, 253, 244, .92), rgba(255, 255, 255, .98));
        box-shadow: 0 14px 32px rgba(22, 163, 74, .08);
    }

    .pricing-summary-card.no-price {
        border-color: #fecaca;
        background: linear-gradient(135deg, rgba(254, 242, 242, .96), rgba(255, 255, 255, .98));
        box-shadow: 0 14px 32px rgba(220, 38, 38, .07);
    }

    .pricing-summary-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 12px;
    }

    .pricing-summary-head strong {
        font-size: 15px;
        font-weight: 950;
        color: var(--text-main);
        text-transform: uppercase;
    }

    .pricing-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 11px;
        font-weight: 950;
        color: #166534;
        background: #dcfce7;
        border: 1px solid #86efac;
        white-space: nowrap;
    }

    .pricing-status-badge.warn {
        color: #991b1b;
        background: #fee2e2;
        border-color: #fecaca;
    }

    .pricing-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .pricing-cell {
        border: 1px solid rgba(148, 163, 184, .35);
        border-radius: 14px;
        padding: 10px 12px;
        background: rgba(255, 255, 255, .76);
        min-height: 68px;
    }

    .pricing-cell small {
        display: block;
        font-size: 10.5px;
        font-weight: 900;
        color: var(--text-muted);
        text-transform: uppercase;
        margin-bottom: 5px;
        letter-spacing: .02em;
    }

    .pricing-cell strong {
        display: block;
        font-size: 15px;
        font-weight: 950;
        color: var(--text-main);
        line-height: 1.15;
    }

    .pricing-cell.final strong {
        color: #15803d;
        font-size: 20px;
    }

    .pricing-note {
        border-radius: 14px;
        padding: 10px 12px;
        font-size: 12px;
        font-weight: 850;
        margin-top: 10px;
    }

    .pricing-note.info {
        color: #1d4ed8;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
    }

    .pricing-note.warn {
        color: #92400e;
        background: #fffbeb;
        border: 1px solid #fde68a;
    }

    .rate-auto-lock {
        background: color-mix(in srgb, var(--border-soft) 22%, var(--card-bg)) !important;
        font-weight: 900;
    }

    @media(max-width:991.98px) {
        .pricing-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media(max-width:575.98px) {
        .pricing-grid {
            grid-template-columns: 1fr;
        }
    }
    
    /* Professional compact Create Proforma UI */
    .module-page{
        width:100%;
        max-width:1320px;
        margin:0 auto;
    }
    .module-page .page-head{
        padding:15px 18px;
        margin-bottom:12px;
        border-radius:16px;
    }
    .module-page .page-head h1{
        font-size:23px;
        line-height:1.15;
        font-weight:800;
        letter-spacing:-.25px;
    }
    .module-page .page-head p{
        font-size:12px;
        font-weight:500;
    }
    .module-card{
        padding:18px !important;
        border-radius:16px !important;
    }
    .module-card > .row.g-3{
        --bs-gutter-y:.75rem;
        --bs-gutter-x:.85rem;
    }
    .section-title{
        font-size:15px !important;
        font-weight:800 !important;
        margin-bottom:2px !important;
        color:var(--text-main);
    }
    .form-label{
        font-size:10.5px !important;
        font-weight:750 !important;
        margin-bottom:5px !important;
        letter-spacing:.01em;
    }
    .form-control,
    .form-select,
    .select2-container .select2-selection--single{
        min-height:39px !important;
        font-size:12.5px !important;
        border-radius:11px !important;
    }
    textarea.form-control{
        min-height:58px !important;
    }
    .input-group-text{
        min-height:39px;
        font-size:12px;
        padding:6px 10px;
    }
    .soft-panel{
        padding:14px !important;
        border-radius:15px !important;
    }
    .quotation-clear-btn,
    .product-clear-btn{
        min-height:39px !important;
        font-size:11px !important;
        font-weight:700 !important;
        border-radius:11px !important;
    }
    .pricing-requirement-title{
        padding:10px 12px !important;
        border-radius:12px !important;
        font-size:12px !important;
    }
    .pricing-requirement-title span{
        font-size:13px !important;
        font-weight:800 !important;
    }
    .pricing-requirement-title small{
        font-size:10.5px !important;
        font-weight:600 !important;
    }
    .proforma-items-panel,
    .pricing-summary-card,
    .amount-summary-panel,
    .advance-payment-panel{
        border-radius:14px !important;
    }
    .proforma-items-head{
        padding:10px 12px !important;
    }
    .proforma-items-head strong{
        font-size:13px !important;
        font-weight:800 !important;
    }
    .proforma-items-table th,
    .proforma-items-table td{
        padding:7px 9px !important;
        font-size:10.5px !important;
    }
    .charge-input-row{
        gap:9px !important;
    }
    .charge-input-card{
        padding:10px !important;
        border-radius:12px !important;
    }
    .amount-summary-item{
        padding:9px 10px !important;
        border-radius:11px !important;
    }
    .amount-summary-item small{
        font-size:9.5px !important;
        font-weight:700 !important;
    }
    .amount-summary-item strong{
        font-size:14px !important;
        font-weight:800 !important;
    }
    .workflow-step{
        padding:9px 10px !important;
        border-radius:11px !important;
    }
    .workflow-step strong{
        font-size:11.5px !important;
        font-weight:750 !important;
    }
    .workflow-date-field small{
        font-size:9px !important;
    }
    .printing-only-product-field{
        transition:.15s ease;
    }
    .printing-only-product-field.printing-disabled{
        opacity:.58;
    }
    .printing-only-info{
        display:flex;
        align-items:flex-start;
        gap:10px;
        padding:10px 12px;
        border:1px solid rgba(14,116,144,.22);
        border-radius:12px;
        background:rgba(14,116,144,.06);
        color:var(--text-main);
    }
    .printing-only-info svg{
        width:18px;
        height:18px;
        flex:0 0 18px;
        margin-top:1px;
        color:#0e7490;
    }
    .printing-only-info strong{
        display:block;
        font-size:12px;
        font-weight:800;
        margin-bottom:2px;
    }
    .printing-only-info span{
        display:block;
        font-size:10.5px;
        font-weight:600;
        color:var(--text-muted);
        line-height:1.4;
    }
    @media(max-width:767.98px){
        .module-card{padding:13px !important;}
        .module-page .page-head{padding:13px 14px;}
        .module-page .page-head h1{font-size:20px;}
    }

</style>
</head>

<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
    <div id="mobileOverlay"></div>
    <div class="app-shell">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <main id="main">
            <?php include __DIR__ . '/includes/nav.php'; ?>
            <section class="page-section module-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1"><?= $isEditMode ? 'Edit Proforma Bill' : 'Create Proforma Bill' ?></h1>
                            <p class="text-muted-custom mb-0">
                                <?= $isEditMode ? 'Update existing proforma bill details without changing the order flow.' : 'Convert quotation to sales order, collect advance, create job card and initialize tracking.' ?>
                            </p>
                        </div>
                        <a href="proforma_bills.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Back to
                            Proforma Bills</a>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000">
                    <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" data-bs-delay="5200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title"><?= e($messageType === 'success' ? 'Success' : 'Failed') ?>
                                </div>
                                <div class="toast-message">
                                    <?= e($message) ?><?php if ($createdProformaNo): ?><br>Proforma:
                                    <?= e($createdProformaNo) ?><?php endif; ?><?php if ($createdJobCardNo): ?><br>Job
                                    Card: <?= e($createdJobCardNo) ?><?php endif; ?></div>
                            </div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <form method="post" action="api/create_proforma.php" class="card-ui module-card" id="proformaForm">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action"
                        value="<?= $isEditMode ? 'update_proforma' : 'create_proforma' ?>">
                    <input type="hidden" name="id" value="<?= (int)$editId ?>">
                    <input type="hidden" name="printing_price_master_id" id="printing_price_master_id" value="">
                    <input type="hidden" name="price_slab_text" id="price_slab_text_input" value="">
                    <input type="hidden" name="pricing_plate_charge" id="pricing_plate_charge" value="0">
                    <input type="hidden" name="pricing_printing_charge" id="pricing_printing_charge" value="0">
                    <input type="hidden" name="pricing_package_charge" id="pricing_package_charge" value="0">
                    <input type="hidden" name="pricing_additional_charge" id="pricing_additional_charge" value="0">
                    <input type="hidden" name="pricing_is_gst_inclusive" id="pricing_is_gst_inclusive" value="1">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="section-title">1. Quotation / Customer Details</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Quotation Reference</label>
                            <div class="quotation-select-wrap">
                                <select name="quotation_id" id="quotation_id" class="form-select select2-autotype"
                                    data-placeholder="Search quotation / customer / mobile">
                                    <option value="">Direct Proforma Bill</option>
                                    <?php foreach ($quotations as $q): ?>
                                    <option value="<?= e($q['id']) ?>"
                                        <?= ($isEditMode && $editData && (int)($editData['quotation_id'] ?? 0) === (int)$q['id']) ? 'selected' : '' ?>>
                                        <?= e($q['quotation_no']) ?> - <?= e($q['customer_name']) ?> -
                                        <?= e($q['mobile']) ?> - <?= e($q['function_name'] ?? '-') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" id="clearQuotationBtn"
                                    class="btn btn-outline-danger quotation-clear-btn">
                                    Cancel
                                </button>
                            </div>
                            <small class="text-muted-custom d-block mt-2">Use Cancel / × to clear the selected quotation
                                and continue as direct proforma.</small>
                        </div>
                        <div class="col-md-4"><label class="form-label fw-bold">Customer Name *</label><input
                                type="text" name="customer_name" id="customer_name" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Mobile *</label><input type="text"
                                name="mobile" id="mobile" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Function / Product Type</label><select
                                name="function_type_id" id="function_type_id" class="form-select select2-autotype">
                                <option value="">Select Type</option><?php foreach ($functionTypes as $type): ?><option
                                    value="<?= e($type['id']) ?>" data-field-group="<?= e($type['field_group']) ?>">
                                    <?= e($type['function_name']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-4 wedding-field"><label class="form-label fw-bold">Groom Name</label><input
                                type="text" name="groom_name" id="groom_name" class="form-control" placeholder="Enter groom name"></div>
                        <div class="col-md-4 wedding-field"><label class="form-label fw-bold">Bride Name</label><input
                                type="text" name="bride_name" id="bride_name" class="form-control" placeholder="Enter bride name"></div>
                        <div class="col-md-4 event-field"><label class="form-label fw-bold">Function Date</label><input
                                type="date" name="function_date" id="function_date" class="form-control"></div>
                        <div class="col-md-4 event-field"><label class="form-label fw-bold">Function Time <span
                                    class="text-muted-custom">(Optional)</span></label><input type="time"
                                name="function_time" id="function_time" class="form-control"></div>
                        <div class="col-md-8 event-field"><label class="form-label fw-bold">Venue</label><input
                                type="text" name="venue" id="venue" class="form-control"></div>

                        <div class="col-12 mt-3">
                            <div class="section-title">2. Billing Details</div>
                        </div>
                        <div class="col-md-4"><label class="form-label fw-bold">Billing Name</label><input type="text"
                                name="billing_name" id="billing_name" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Billing Mobile</label><input type="text"
                                name="billing_mobile" id="billing_mobile" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label fw-bold">GST Number</label><input type="text"
                                name="gst_number" id="gst_number" class="form-control"></div>
                        <div class="col-12"><label class="form-label fw-bold">Billing Address</label><textarea
                                name="billing_address" id="billing_address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="section-title">3. Order / Production Details</div>
                        </div>

                        <div class="col-12">
                            <div class="soft-panel">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold">Order Type *</label>
                                        <select name="order_type" id="order_type" class="form-select" required>
                                            <option value="readymade">Readymade</option>
                                            <option value="customized">Customized</option>
                                            <option value="printing_only">Printing Only</option>
                                        </select>
                                    </div>
                                    <div class="col-md-9 printing-only-product-field">
                                        <label class="form-label fw-bold">Product Master *</label>
                                        <div class="product-select-wrap">
                                            <select name="product_id" id="product_id"
                                                class="form-select select2-autotype product-master-select"
                                                data-placeholder="Search Product Master" data-tags="false">
                                                <option value="">Search Product Master</option>
                                                <?php if ($isEditMode && $editProductSelectValue !== '' && !$editProductExistsInProducts): ?>
                                                <option value="<?= e($editProductSelectValue) ?>"
                                                    data-name="<?= e($editProductSelectName) ?>"
                                                    data-price="<?= e($editData['item_rate'] ?? 0) ?>"
                                                    data-order-type="<?= e($editData['order_type'] ?? 'readymade') ?>"
                                                    selected><?= e($editProductSelectName) ?>
                                                </option><?php endif; ?><?php foreach ($products as $p):
                                                    $availableStock = (float)($p['available_stock'] ?? 0);
                                                    $onHandStock = (float)($p['on_hand_stock'] ?? 0);
                                                    $reservedStock = (float)($p['reserved_stock'] ?? 0);
                                                    $availableLabel = rtrim(rtrim(number_format($availableStock, 2, '.', ''), '0'), '.');
                                                ?><option value="<?= e($p['id']) ?>"
                                                    data-name="<?= e($p['product_name']) ?>"
                                                    data-price="<?= e($p['default_price']) ?>"
                                                    data-order-type="<?= e($p['default_order_type']) ?>"
                                                    data-available="<?= e($availableStock) ?>"
                                                    data-on-hand="<?= e($onHandStock) ?>"
                                                    data-reserved="<?= e($reservedStock) ?>"
                                                    <?= ($isEditMode && $editProductSelectValue !== '' && (string)$editProductSelectValue === (string)$p['id']) ? 'selected' : '' ?>>
                                                    <?= e($p['product_name']) ?>
                                                </option><?php endforeach; ?>
                                            </select>
                                            <button type="button" id="clearProductBtn"
                                                class="btn btn-outline-danger product-clear-btn"
                                                title="Clear selected product"
                                                aria-label="Clear selected product">&times;</button>
                                        </div>
                                        <input type="hidden" name="product_name" id="product_name" value="">
                                        <input type="hidden" name="items_json" id="items_json" value="[]">
                                        <div id="selectedProductStockInfo" class="mt-2 small fw-bold text-muted-custom">
                                            Select a product to view On Hand, Reserved and Available stock.</div>
                                    </div>

                                    <div class="col-md-4 printing-only-product-field">
                                        <label class="form-label fw-bold">Quantity *</label>
                                        <input type="number" step="1" min="1" name="qty" id="qty" class="form-control"
                                            value="1" required>
                                    </div>
                                    <div class="col-md-4 printing-only-product-field">
                                        <label class="form-label fw-bold">Rate / Unit</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" step="0.01" min="0" name="rate" id="rate"
                                                class="form-control" value="0">
                                            <span class="input-group-text">/ Unit</span>
                                        </div>
                                        <small class="text-muted-custom fw-bold">Editable rate for this selected
                                            product.</small>
                                    </div>
                                    <div class="col-md-4 printing-only-product-field">
                                        <label class="form-label fw-bold">Product Total</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="text" id="product_total_display" class="form-control fw-bold"
                                                value="0.00" readonly>
                                        </div>
                                        <small class="text-muted-custom fw-bold">Quantity × Rate for this
                                            product.</small>
                                    </div>

                                    <div class="col-12 customized-field hide-field mt-2">
                                        <div class="pricing-requirement-title">
                                            <span><i data-lucide="settings" class="me-2"></i>Customized Product
                                                Production
                                                Details</span>
                                            <small class="text-muted-custom fw-bold">
                                                These details belong only to this product. Each added Customized product
                                                creates its own Job Card and its own workflow/tracking.
                                            </small>
                                        </div>
                                    </div>

                                    <div class="col-md-3 custom-only-field">
                                        <label class="form-label fw-bold">Card Size</label>
                                        <input type="text" name="size_text" id="size_text" class="form-control"
                                            placeholder="Eg: 14 x 9.5">
                                    </div>
                                    <div class="col-md-3 custom-only-field">
                                        <label class="form-label fw-bold">GSM / Thickness</label>
                                        <input type="text" name="gsm_thickness" id="gsm_thickness" class="form-control"
                                            placeholder="Eg: 300 GSM">
                                    </div>
                                    <div
                                        class="col-md-3 custom-only-field lamination-required-wrap d-flex align-items-end">
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" name="lamination_required"
                                                id="lamination_required" value="1">
                                            <label class="form-check-label fw-bold" for="lamination_required">Lamination
                                                Required</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3 custom-only-field lamination-type-wrap hide-field">
                                        <label class="form-label fw-bold">Lamination Type</label>
                                        <select name="lamination_type" id="lamination_type" class="form-select">
                                            <option value="none">None</option>
                                            <option value="glossy">Glossy</option>
                                            <option value="matte">Matte</option>
                                            <option value="special">Special</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3 custom-only-field">
                                        <label class="form-label fw-bold">Printing Side</label>
                                        <select name="printing_side" id="printing_side" class="form-select">
                                            <option value="">Select</option>
                                            <option value="single">Single Side</option>
                                            <option value="double">Double Side</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3 custom-only-field customized-field">
                                        <label class="form-label fw-bold">Scoring Type</label>
                                        <select name="screening_type" id="screening_type" class="form-select">
                                            <option value="">Select</option>
                                            <option value="regular">Regular Scoring</option>
                                            <option value="special">Special Scoring</option>
                                        </select>
                                    </div>


                                    <div class="col-12 customized-field hide-field">
                                        <label class="form-label fw-bold">Product Requirement / Description</label>
                                        <textarea id="custom_item_description" class="form-control" rows="2"
                                            placeholder="Enter this product's customized production requirement"></textarea>
                                    </div>

                                    <div class="col-md-3 customized-field hide-field">
                                        <label class="form-label fw-bold">Plate / Additional Charge</label>
                                        <input type="number" step="0.01" min="0" id="custom_item_plate_charge"
                                            class="form-control" value="0" placeholder="This product only">
                                    </div>
                                    <div class="col-md-3 customized-field hide-field">
                                        <label class="form-label fw-bold">Printing Charge</label>
                                        <input type="number" step="0.01" min="0" id="custom_item_printing_charge"
                                            class="form-control" value="0" placeholder="This product only">
                                    </div>
                                    <div class="col-md-3 customized-field hide-field">
                                        <label class="form-label fw-bold">Package Charge</label>
                                        <input type="number" step="0.01" min="0" id="custom_item_package_charge"
                                            class="form-control" value="0" placeholder="This product only">
                                    </div>
                                    <div class="col-md-3 customized-field hide-field">
                                        <label class="form-label fw-bold">Customized Line Total</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="text" id="custom_item_line_total_display"
                                                class="form-control fw-bold" value="0.00" readonly>
                                        </div>
                                    </div>

                                    <div class="col-12 customized-field hide-field">
                                        <div class="soft-panel">
                                            <div
                                                class="d-flex justify-content-between align-items-center gap-2 mb-2 flex-wrap">
                                                <div>
                                                    <strong>Tracking Planned Dates for This Product</strong>
                                                    <div class="small text-muted-custom fw-bold">
                                                        These dates belong only to this product's separate Job Card.
                                                    </div>
                                                </div>
                                                <span class="badge text-bg-light">Independent Tracking</span>
                                            </div>
                                            <div id="customWorkflowSteps" class="workflow-grid"></div>
                                        </div>
                                    </div>

                                    <div class="col-12 printing-only-note hide-field" id="printingOnlyNote">
                                        <div class="printing-only-info">
                                            <i data-lucide="printer"></i>
                                            <div>
                                                <strong>Printing Only Order</strong>
                                                <span>Customer supplies the material/design. Product Master, Quantity, Rate and Product Total are not used. Select Printing Type and enter the amount in Printing Charge.</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 product-add-line-wrap printing-only-hide">
                                        <button type="button" id="addProductBtn"
                                            class="btn btn-primary product-add-line-btn">
                                            <i data-lucide="plus" class="me-1"></i> Add Product
                                        </button>
                                    </div>

                                    <div class="col-12 printing-only-hide">
                                        <div class="proforma-items-panel">
                                            <div class="proforma-items-head">
                                                <div>
                                                    <strong>Added Products</strong>
                                                    <div class="small text-muted-custom fw-bold">Add one product, then
                                                        select the next product and repeat.</div>
                                                </div>
                                                <span class="badge text-bg-light" id="proformaItemsCount">0
                                                    Products</span>
                                            </div>
                                            <div id="proformaItemsEmpty" class="proforma-items-empty">No products added
                                                yet.</div>
                                            <div id="proformaItemsTableWrap" class="proforma-items-table-wrap d-none">
                                                <table class="proforma-items-table">
                                                    <thead>
                                                        <tr>
                                                            <th>#</th>
                                                            <th>Product</th>
                                                            <th class="text-end">Qty</th>
                                                            <th class="text-end">Rate / Unit</th>
                                                            <th class="text-end">Product Total</th>
                                                            <th class="text-end customized-column hide-field">Item
                                                                Charges</th>
                                                            <th class="text-end customized-column hide-field">Line Total
                                                            </th>
                                                            <th class="customized-column hide-field">Production /
                                                                Workflow</th>
                                                            <th class="text-end">Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="proformaItemsBody"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>


                                    <div class="col-12 mt-2 readymade-field">
                                        <div class="pricing-requirement-title">
                                            <span><i data-lucide="printer" class="me-2"></i>Common Printing
                                                Details</span>
                                            <small class="text-muted-custom fw-bold">
                                                Applies to all Readymade products added in this Proforma.
                                            </small>
                                        </div>
                                    </div>

                                    <div class="col-md-4 readymade-field">
                                        <label class="form-label fw-bold">Printing Type <span
                                                class="text-danger">*</span></label>
                                        <select name="printing_type_id" id="printing_type_id" class="form-select">
                                            <option value="">Select Printing Type</option>
                                            <?php foreach ($printingTypes as $pt): ?>
                                            <option value="<?= e($pt['id']) ?>"
                                                data-readymade="<?= e($pt['is_for_readymade']) ?>"
                                                data-customized="<?= e($pt['is_for_customized']) ?>"
                                                data-role-key="<?= e($pt['role_key']) ?>"><?= e($pt['printing_name']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 screen-subtype-field hide-field" id="screenSubTypeWrap">
                                        <label class="form-label fw-bold" id="printingSubTypeLabel">Screen Print
                                            Sub-Type</label>
                                        <select name="printing_sub_type_id" id="printing_sub_type_id"
                                            class="form-select">
                                            <option value="">Not Applicable</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 readymade-field d-flex align-items-end">
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" name="finishing_required"
                                                id="finishing_required" value="1">
                                            <label class="form-check-label fw-bold" for="finishing_required">With
                                                Finishing</label>
                                        </div>
                                    </div>


                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Delivery Date *</label>
                                        <input type="date" name="delivery_date" id="delivery_date" class="form-control">
                                    </div>

                                    <div class="col-12 readymade-field printing-only-hide-pricing">
                                        <div class="pricing-requirement-title">
                                            <span><i data-lucide="sliders-horizontal" class="me-2"></i>Requirement
                                                Details & Predefined
                                                Printing Charge</span>
                                            <small class="text-muted-custom fw-bold">Printing charge is auto-filled from
                                                predefined quantity slabs and remains editable.</small>
                                        </div>
                                    </div>

                                    <div class="col-12 readymade-field">
                                        <label class="form-label fw-bold">Common Item Description</label>
                                        <textarea name="description" id="description" class="form-control"
                                            rows="2"></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Discount</label>
                                        <input type="number" step="0.01" min="0" name="discount_amount"
                                            id="discount_amount" class="form-control" value="0">
                                    </div>
                                    <?php if ($proformaStatuses): ?>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Proforma Status</label>
                                        <select name="proforma_status_id" id="proforma_status_id" class="form-select">
                                            <?php foreach ($proformaStatuses as $statusRow): ?>
                                            <option value="<?= (int)$statusRow['id'] ?>"
                                                <?= (int)$selectedProformaStatusId === (int)$statusRow['id'] ? 'selected' : '' ?>>
                                                <?= e($statusRow['status_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>


                                    <div class="col-12 readymade-field printing-only-hide-pricing">
                                        <div id="pricingSummaryCard" class="pricing-summary-card no-price">
                                            <div class="pricing-summary-head">
                                                <strong>Pricing Summary</strong>
                                                <span id="priceStatusBadge" class="pricing-status-badge warn">No
                                                    Pricing</span>
                                            </div>

                                            <div id="priceMatchedBox" class="pricing-grid d-none">
                                                <div class="pricing-cell"><small>Applied Quantity Slab</small><strong
                                                        id="priceSlabText">-</strong></div>
                                                <div class="pricing-cell"><small>Pricing Mode</small><strong
                                                        id="priceModeText">-</strong></div>
                                                <div class="pricing-cell"><small>Entered Quantity</small><strong
                                                        id="priceQtyText">0 Nos</strong></div>
                                                <div class="pricing-cell"><small>Item Rate</small><strong
                                                        id="priceRateText">₹0.00</strong></div>
                                                <div class="pricing-cell"><small>Auto Printing Charge</small><strong
                                                        id="pricePrintingText">₹0.00</strong></div>
                                                <div class="pricing-cell"><small>Plate / Additional</small><strong
                                                        id="pricePlateText">₹0.00</strong></div>
                                                <div class="pricing-cell"><small>Package Charge</small><strong
                                                        id="pricePackageText">₹0.00</strong></div>
                                                <div class="pricing-cell final"><small>Final Amount GST
                                                        Inclusive</small><strong id="priceFinalText">₹0.00</strong>
                                                </div>
                                            </div>

                                            <div id="priceNoMatchBox" class="pricing-note warn">
                                                No predefined printing charge found for this quantity/selection. Change
                                                quantity to a saved slab or edit Rate / Printing Charge manually.
                                            </div>
                                            <div id="pricingMessage" class="pricing-note info d-none">
                                                Predefined printing charge applied. Rate, Printing Charge,
                                                Plate/Additional and Package Charge are editable.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>


                        <div class="col-12 mt-3">
                            <div class="section-title">4. Amount / Payment</div>
                            <div id="chargeModeHelp" class="small text-muted-custom fw-bold mb-2">
                                Readymade charges are common once for the full Proforma.
                            </div>
                            <div class="col-12">
                                <div class="charge-input-row">
                                    <div class="charge-input-card">
                                        <label class="form-label fw-bold">Plate / Additional Charge</label>
                                        <input type="number" step="0.01" min="0" name="extra_card_charge"
                                            id="extra_card_charge" class="form-control" value="0"
                                            placeholder="Auto / optional">
                                    </div>
                                    <div class="charge-input-card">
                                        <label class="form-label fw-bold">Package Charge <span
                                                class="text-muted-custom">(Optional)</span></label>
                                        <input type="number" step="0.01" min="0" name="packing_charge"
                                            id="packing_charge" class="form-control" value="0" placeholder="Optional">
                                    </div>
                                    <div class="charge-input-card">
                                        <label class="form-label fw-bold">Printing Charge <span
                                                class="text-muted-custom">(Optional)</span></label>
                                        <input type="number" step="0.01" min="0" name="printing_charge"
                                            id="printing_charge" class="form-control" value="0"
                                            placeholder="Auto from slab / optional">
                                    </div>
                                    <div class="charge-input-card">
                                        <label class="form-label fw-bold">GST % Inclusive</label>
                                        <input type="number" step="0.01" min="0" name="gst_percent" id="gst_percent"
                                            class="form-control" value="18">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="amount-summary-panel">
                                <div class="amount-summary-grid">
                                    <div class="amount-summary-item"><small>Sub Total</small><strong
                                            id="subTotalText">₹0.00</strong></div>
                                    <div class="amount-summary-item"><small>Charges</small><strong
                                            id="chargeTotalText">₹0.00</strong></div>
                                    <div class="amount-summary-item"><small>Discount</small><strong
                                            id="discountText">₹0.00</strong></div>
                                    <div class="amount-summary-item"><small>Taxable Value</small><strong
                                            id="taxableValueText">₹0.00</strong></div>
                                    <div class="amount-summary-item"><small>GST Amount</small><strong
                                            id="gstAmountText">₹0.00</strong></div>
                                    <div class="amount-summary-item"><small>Customer Paid</small><strong
                                            id="tenderedAmountText">₹0.00</strong></div>
                                    <div class="amount-summary-item"><small>Applied to Bill</small><strong
                                            id="advanceAmountText">₹0.00</strong></div>
                                    <div class="amount-summary-item return-payment"><small>Return Amount</small><strong
                                            id="returnAmountText">₹0.00</strong></div>
                                    <div class="amount-summary-item balance"><small>Balance</small><strong
                                            id="balanceAmountText">₹0.00</strong></div>
                                    <div class="amount-summary-item final"><small>Final Amount</small><strong
                                            id="finalAmountText">₹0.00</strong></div>
                                </div>
                                <div class="amount-summary-note">GST Inclusive: taxable value and GST are shown
                                    separately, but both are already included inside the final amount. Package charge
                                    and printing charge are optional.</div>
                            </div>
                        </div>



                        <input type="hidden" name="advance_amount" id="advance_amount" value="0">
                        <input type="hidden" name="tendered_amount" id="tendered_amount" value="0">
                        <input type="hidden" name="return_amount" id="return_amount" value="0">
                        <input type="hidden" name="payment_mode" id="payment_mode" value="">
                        <input type="hidden" name="payment_reference" id="payment_reference" value="">

                        <div class="col-12">
                            <label class="form-label fw-bold">Advance Payment Method <span
                                    class="text-danger">*</span></label>
                            <div class="small text-muted-custom fw-bold mb-2">
                                Advance payment is required to create a new Proforma Bill. Select Cash, UPI, or both.
                            </div>
                            <div class="payment-mode-checks" id="advancePaymentMethodSection">
                                <label class="payment-check-card" for="pay_cash" data-payment-card="cash">
                                    <input type="checkbox" id="pay_cash" class="payment-mode-check" data-mode="cash">
                                    <strong>Cash</strong>
                                    <small>Cash amount must match denomination total</small>
                                </label>
                                <label class="payment-check-card" for="pay_upi" data-payment-card="upi">
                                    <input type="checkbox" id="pay_upi" class="payment-mode-check" data-mode="upi">
                                    <strong>UPI</strong>
                                    <small>Can be used together with cash</small>
                                </label>
                            </div>
                        </div>

                        <div class="col-md-4 payment-cash-wrap hide-field">
                            <label class="form-label fw-bold">Cash Received</label>
                            <input type="number" step="0.01" min="0" name="cash_amount" id="cash_amount"
                                class="form-control" value="0" placeholder="Amount given by customer">
                        </div>
                        <div class="col-md-4 payment-cash-wrap hide-field">
                            <label class="form-label fw-bold">Cash Remarks</label>
                            <input type="text" name="cash_reference" id="cash_reference" class="form-control"
                                placeholder="Optional cash remarks">
                        </div>
                        <div class="col-md-4 payment-cash-wrap d-flex align-items-end hide-field">
                            <button type="button" class="btn btn-outline-primary rounded-pill fw-bold w-100"
                                id="openCashDenominationBtn">Cash Denomination</button>
                        </div>

                        <div class="col-md-6 payment-upi-wrap hide-field">
                            <label class="form-label fw-bold">UPI Received</label>
                            <input type="number" step="0.01" min="0" name="upi_amount" id="upi_amount"
                                class="form-control" value="0" placeholder="UPI collected">
                        </div>
                        <div class="col-md-6 payment-upi-wrap hide-field">
                            <label class="form-label fw-bold">UPI Reference / Transaction ID</label>
                            <input type="text" name="upi_reference" id="upi_reference" class="form-control"
                                placeholder="Enter UPI transaction ID">
                        </div>

                        <div class="col-md-4 return-amount-field">
                            <label class="form-label fw-bold">Return Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="text" id="return_amount_display" class="form-control" value="0.00" readonly
                                    aria-label="Amount to return to customer">
                            </div>
                            <small class="text-muted-custom fw-bold">Give this amount back to the customer.</small>
                        </div>

                        <div class="col-12">
                            <div id="returnPaymentNotice" class="return-payment-notice d-none" role="status"
                                aria-live="polite"></div>
                        </div>

                        <div class="col-12"><label class="form-label fw-bold">Internal Remarks</label><textarea
                                name="remarks" id="remarks" class="form-control" rows="2"></textarea></div>

                        <div class="col-12 mt-3">
                            <div class="section-title">5. Job Card and Tracking</div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch"><input type="hidden" name="auto_create_job_card"
                                    value="<?= $isEditMode ? '0' : '1' ?>"><input class="form-check-input"
                                    type="checkbox" id="auto_create_job_card" value="1"
                                    <?= $isEditMode ? 'disabled' : 'checked disabled' ?>><label
                                    id="autoCreateJobCardLabel" class="form-check-label fw-bold"
                                    for="auto_create_job_card">Create Job Card
                                    Automatically</label></div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox"
                                    name="create_tracking_link" id="create_tracking_link" value="1"
                                    <?= $isEditMode ? 'disabled' : 'checked' ?>><label class="form-check-label fw-bold"
                                    for="create_tracking_link">Create Customer Tracking Link</label></div>
                        </div>
                        <div class="col-12 readymade-field">
                            <div class="soft-panel">
                                <div class="d-flex justify-content-between align-items-center mb-2"><strong>Common
                                        Planned
                                        Dates for Readymade Job Card</strong><small id="trackingDatesHelp"
                                        class="text-muted-custom">Optional,
                                        Sales can fill now or later</small></div>
                                <div id="workflowSteps" class="workflow-grid"></div>
                            </div>
                        </div>

                        <div class="col-12 d-flex flex-column flex-md-row justify-content-end gap-2 mt-4">
                            <button type="reset"
                                class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Reset</button>
                            <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold"
                                id="createProformaBtn"><?= $isEditMode ? 'Update Proforma Bill' : 'Create Proforma Bill' ?></button>
                        </div>
                    </div>
                </form>

                <div class="modal fade" id="advancePaymentModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered cash-denom-dialog">
                        <div class="modal-content border-0 cash-denom-modal-content">
                            <div class="modal-header border-0 pb-0">
                                <div>
                                    <h5 class="modal-title fw-black fw-bold">Cash Denomination</h5>
                                    <p class="text-muted-custom mb-0 fw-bold">Enter count for every note / coin</p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="cash-denom-summary-bar">
                                    <span>Cash Received: <b id="cashModalAmount">₹0.00</b></span>
                                    <span>Total: <b id="cashDenomTotal">₹0.00</b></span>
                                    <span>Return: <b id="cashModalReturn">₹0.00</b></span>
                                </div>

                                <div id="cashDenominationSection">
                                    <div class="denom-format-box mb-3">
                                        <div class="denom-section-heading">Notes:</div>
                                        <div class="denom-format-row">
                                            <input type="number" min="0" step="1" name="cash_note_500"
                                                form="proformaForm"
                                                class="form-control cash-denom-count denom-count-input" data-value="500"
                                                placeholder="Count">
                                            <span class="denom-symbol">x ₹500</span>
                                            <span class="denom-equals">=</span>
                                            <input type="text" class="form-control cash-denom-amount-input"
                                                value="₹0.00" readonly>
                                        </div>
                                        <div class="denom-format-row">
                                            <input type="number" min="0" step="1" name="cash_note_200"
                                                form="proformaForm"
                                                class="form-control cash-denom-count denom-count-input" data-value="200"
                                                placeholder="Count">
                                            <span class="denom-symbol">x ₹200</span>
                                            <span class="denom-equals">=</span>
                                            <input type="text" class="form-control cash-denom-amount-input"
                                                value="₹0.00" readonly>
                                        </div>
                                        <div class="denom-format-row">
                                            <input type="number" min="0" step="1" name="cash_note_100"
                                                form="proformaForm"
                                                class="form-control cash-denom-count denom-count-input" data-value="100"
                                                placeholder="Count">
                                            <span class="denom-symbol">x ₹100</span>
                                            <span class="denom-equals">=</span>
                                            <input type="text" class="form-control cash-denom-amount-input"
                                                value="₹0.00" readonly>
                                        </div>
                                        <div class="denom-format-row">
                                            <input type="number" min="0" step="1" name="cash_note_50"
                                                form="proformaForm"
                                                class="form-control cash-denom-count denom-count-input" data-value="50"
                                                placeholder="Count">
                                            <span class="denom-symbol">x ₹50</span>
                                            <span class="denom-equals">=</span>
                                            <input type="text" class="form-control cash-denom-amount-input"
                                                value="₹0.00" readonly>
                                        </div>
                                        <div class="denom-format-row">
                                            <input type="number" min="0" step="1" name="cash_note_20"
                                                form="proformaForm"
                                                class="form-control cash-denom-count denom-count-input" data-value="20"
                                                placeholder="Count">
                                            <span class="denom-symbol">x ₹20</span>
                                            <span class="denom-equals">=</span>
                                            <input type="text" class="form-control cash-denom-amount-input"
                                                value="₹0.00" readonly>
                                        </div>
                                        <div class="denom-format-row">
                                            <input type="number" min="0" step="1" name="cash_note_10"
                                                form="proformaForm"
                                                class="form-control cash-denom-count denom-count-input" data-value="10"
                                                placeholder="Count">
                                            <span class="denom-symbol">x ₹10</span>
                                            <span class="denom-equals">=</span>
                                            <input type="text" class="form-control cash-denom-amount-input"
                                                value="₹0.00" readonly>
                                        </div>

                                        <div class="denom-section-heading mt-3">Coins:</div>
                                        <div class="denom-format-row">
                                            <input type="number" min="0" step="1" name="cash_coin_20"
                                                form="proformaForm"
                                                class="form-control cash-denom-count denom-count-input" data-value="20"
                                                placeholder="Count">
                                            <span class="denom-symbol">x ₹20</span>
                                            <span class="denom-equals">=</span>
                                            <input type="text" class="form-control cash-denom-amount-input"
                                                value="₹0.00" readonly>
                                        </div>
                                        <div class="denom-format-row">
                                            <input type="number" min="0" step="1" name="cash_coin_10"
                                                form="proformaForm"
                                                class="form-control cash-denom-count denom-count-input" data-value="10"
                                                placeholder="Count">
                                            <span class="denom-symbol">x ₹10</span>
                                            <span class="denom-equals">=</span>
                                            <input type="text" class="form-control cash-denom-amount-input"
                                                value="₹0.00" readonly>
                                        </div>
                                        <div class="denom-format-row">
                                            <input type="number" min="0" step="1" name="cash_coin_5" form="proformaForm"
                                                class="form-control cash-denom-count denom-count-input" data-value="5"
                                                placeholder="Count">
                                            <span class="denom-symbol">x ₹5</span>
                                            <span class="denom-equals">=</span>
                                            <input type="text" class="form-control cash-denom-amount-input"
                                                value="₹0.00" readonly>
                                        </div>
                                        <div class="denom-format-row">
                                            <input type="number" min="0" step="1" name="cash_coin_2" form="proformaForm"
                                                class="form-control cash-denom-count denom-count-input" data-value="2"
                                                placeholder="Count">
                                            <span class="denom-symbol">x ₹2</span>
                                            <span class="denom-equals">=</span>
                                            <input type="text" class="form-control cash-denom-amount-input"
                                                value="₹0.00" readonly>
                                        </div>
                                        <div class="denom-format-row">
                                            <input type="number" min="0" step="1" name="cash_coin_1" form="proformaForm"
                                                class="form-control cash-denom-count denom-count-input" data-value="1"
                                                placeholder="Count">
                                            <span class="denom-symbol">x ₹1</span>
                                            <span class="denom-equals">=</span>
                                            <input type="text" class="form-control cash-denom-amount-input"
                                                value="₹0.00" readonly>
                                        </div>
                                    </div>
                                </div>

                            </div>
                            <div class="modal-footer border-0 pt-0">
                                <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold"
                                    data-bs-dismiss="modal">Close</button>
                                <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold"
                                    id="advancePaymentContinueBtn">Save Denomination</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="page-loading-overlay" id="pageLoadingOverlay">
                    <div class="loading-card">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <h5 class="fw-bold mb-1" id="pageLoadingTitle">Processing...</h5>
                        <p class="text-muted-custom mb-3" id="pageLoadingMessage">Please wait. Do not refresh or close
                            this page.</p>
                        <div class="progress rounded-pill" style="height:10px">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%">
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>
    <?php include __DIR__ . '/includes/script.php'; ?>
    <script>
    const quotations = <?= $quotationJson ?: '[]' ?>;
    const printingSubTypes = <?= $subTypeJson ?: '[]' ?>;
    const printingTypes = <?= $printingTypeJson ?: '[]' ?>;
    const workflowData = <?= $stepsJson ?: '{"readymade":[],"customized":[]}' ?>;
    const plannedStepData = <?= $plannedDatesJson ?: '{}' ?>;
    const editData = <?= $editJson ?: 'null' ?>;
    const editItems = <?= $editItemsJson ?: '[]' ?>;
    const editReservationByProduct = <?= $editReservationJson ?: '{}' ?>;

    function refreshProformaIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    function rupee(value) {
        return '₹' + Number(value || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function setValue(id, value) {
        const el = document.getElementById(id);
        if (el) el.value = value ?? '';
    }

    function getValue(id) {
        return document.getElementById(id)?.value || '';
    }

    const isEditPage = !!editData;
    const draftKey = 'subhiksha_create_proforma_draft_v4';

    function collectFormDraft() {
        const form = document.getElementById('proformaForm');
        if (!form) return {};
        const data = {};
        new FormData(form).forEach((value, key) => {
            if (key === 'csrf_token' || key === 'action' || key === 'id') return;
            if (data[key] !== undefined) {
                if (!Array.isArray(data[key])) data[key] = [data[key]];
                data[key].push(value);
            } else {
                data[key] = value;
            }
        });
        return data;
    }

    function saveFormDraft() {
        if (isEditPage || submittingToApi) return;
        try {
            localStorage.setItem(draftKey, JSON.stringify(collectFormDraft()));
        } catch (e) {}
    }

    function clearFormDraft() {
        try {
            localStorage.removeItem(draftKey);
        } catch (e) {}
    }

    function restoreFormDraft() {
        if (isEditPage) return;
        let raw = '';
        try {
            raw = localStorage.getItem(draftKey) || '';
        } catch (e) {}
        if (!raw) return;

        let data = {};
        try {
            data = JSON.parse(raw) || {};
        } catch (e) {
            return;
        }

        Object.keys(data).forEach(key => {
            const els = document.querySelectorAll('[name="' + CSS.escape(key) + '"]');
            if (!els.length) return;
            const value = data[key];

            els.forEach(el => {
                if (el.type === 'checkbox' || el.type === 'radio') {
                    el.checked = Array.isArray(value) ? value.includes(el.value) : String(value) ===
                        String(el.value);
                } else {
                    el.value = Array.isArray(value) ? (value[0] || '') : value;
                }
            });
        });

        ['quotation_id', 'function_type_id', 'proforma_status_id', 'product_id', 'order_type', 'printing_type_id',
            'printing_sub_type_id', 'lamination_type', 'printing_side', 'screening_type', 'payment_mode'
        ].forEach(refreshSelect);

        toggleFunctionFields();
        toggleOrderType(false);
        toggleLamination();
        calculate();
        renderWorkflowSteps();
    }

    function showToastOnLoad() {
        const toastEl = document.getElementById('pageToast');
        if (toastEl && window.bootstrap) {
            new bootstrap.Toast(toastEl).show();
        }
    }

    function showActionToast(message, type = 'success', titleText = '') {
        if (!message) return;
        const old = document.getElementById('dynamicActionToastWrap');
        if (old) old.remove();
        const title = titleText || (type === 'danger' ? 'Failed' : 'Success');
        const wrap = document.createElement('div');
        wrap.id = 'dynamicActionToastWrap';
        wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
        wrap.style.zIndex = '12000';
        wrap.innerHTML =
            `<div id="dynamicActionToast" class="toast toast-ui ${type}" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4200"><div class="d-flex"><div class="toast-body"><div class="toast-title">${title}</div><div class="toast-message">${message}</div></div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>`;
        document.body.appendChild(wrap);
        const toastEl = document.getElementById('dynamicActionToast');
        if (window.bootstrap && toastEl) new bootstrap.Toast(toastEl).show();
    }

    function initSelect2(context) {
        if (window.initSelect2AutoType) {
            window.initSelect2AutoType(context || document);
            return;
        }
        if (window.jQuery && $.fn.select2) {
            $(context || document).find('select.select2-autotype').each(function() {
                const $s = $(this);
                if ($s.hasClass('select2-hidden-accessible')) $s.select2('destroy');
                $s.select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    allowClear: true,
                    closeOnSelect: true,
                    tags: String($s.data('tags') || '').toLowerCase() === 'true',
                    placeholder: $s.data('placeholder') || 'Search and select',
                    createTag: function(params) {
                        const term = $.trim(params.term);
                        if (term === '') return null;
                        return {
                            id: term,
                            text: term,
                            newTag: true
                        };
                    }
                });
            });
        }
    }

    function initProductMasterSelect() {
        if (!(window.jQuery && $.fn.select2)) return;
        const $product = $('#product_id');
        if (!$product.length) return;
        if ($product.hasClass('select2-hidden-accessible')) {
            $product.select2('destroy');
        }
        $product.select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: true,
            closeOnSelect: true,
            tags: false,
            placeholder: $product.data('placeholder') || 'Search Product Master',
            templateResult: function(data) {
                if (!data.id) return data.text;

                const option = data.element;
                const productName = String(option?.dataset?.name || data.text || '').trim();
                const rawAvailable = parseFloat(option?.dataset?.available || 0) || 0;
                const ownReservation = isEditPage ?
                    (parseFloat(editReservationByProduct[String(data.id)] || 0) || 0) :
                    0;
                const available = rawAvailable + ownReservation;

                const $row = $('<div class="product-select2-result"></div>');
                $('<span class="product-select2-result-name"></span>').text(productName).appendTo($row);

                const $stock = $('<span class="product-select2-result-stock"></span>')
                    .text('Available: ' + available.toLocaleString('en-IN', {
                        maximumFractionDigits: 2
                    }))
                    .appendTo($row);

                if (available < 0) $stock.addClass('negative');
                return $row;
            },
            templateSelection: function(data) {
                if (!data.id) return data.text;
                const option = data.element;
                return String(option?.dataset?.name || data.text || '').trim();
            }
        });
    }

    function refreshSelect(id) {
        if (window.jQuery && $.fn.select2) {
            $('#' + id).trigger('change.select2');
        }
    }

    function calculate() {
        const qty = parseFloat(getValue('qty')) || 0;
        const rate = parseFloat(getValue('rate')) || 0;
        const productTotal = Math.max(0, qty * rate);
        const productTotalDisplay = document.getElementById('product_total_display');
        if (productTotalDisplay) {
            productTotalDisplay.value = productTotal.toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        const discount = parseFloat(getValue('discount_amount')) || 0;
        const orderType = getValue('order_type') || 'readymade';
        const isCustomizedOrder = orderType === 'customized';
        let extraCardCharge = parseFloat(getValue('extra_card_charge')) || 0;
        let packingCharge = parseFloat(getValue('packing_charge')) || 0;
        let printingCharge = parseFloat(getValue('printing_charge')) || 0;
        const gstPercent = Math.max(0, parseFloat(getValue('gst_percent')) || 0);
        const cashChecked = document.getElementById('pay_cash')?.checked === true;
        const upiChecked = document.getElementById('pay_upi')?.checked === true;
        const cashAmount = cashChecked ? (parseFloat(getValue('cash_amount')) || 0) : 0;
        const upiAmount = upiChecked ? (parseFloat(getValue('upi_amount')) || 0) : 0;

        const currentProductAmount = Math.max(0, qty * rate);
        const hasAddedProducts = (typeof proformaItems !== 'undefined' && Array.isArray(proformaItems) && proformaItems
            .length > 0);
        const addedProductsAmount = hasAddedProducts ?
            proformaItems.reduce((sum, item) => sum + (parseFloat(item.amount || 0) || 0), 0) :
            0;

        const sub = orderType === 'printing_only' ? 0 : Math.max(0, hasAddedProducts ? addedProductsAmount : currentProductAmount);

        let chargeTotal = 0;

        if (isCustomizedOrder) {
            if (hasAddedProducts) {
                extraCardCharge = proformaItems.reduce((sum, item) =>
                    sum + (parseFloat(item.plate_charge || 0) || 0) +
                    (parseFloat(item.item_additional_charge || 0) || 0), 0);
                printingCharge = proformaItems.reduce((sum, item) =>
                    sum + (parseFloat(item.item_printing_charge || 0) || 0), 0);
                packingCharge = proformaItems.reduce((sum, item) =>
                    sum + (parseFloat(item.item_package_charge || 0) || 0), 0);
            } else {
                extraCardCharge = parseFloat(getValue('custom_item_plate_charge')) || 0;
                printingCharge = parseFloat(getValue('custom_item_printing_charge')) || 0;
                packingCharge = parseFloat(getValue('custom_item_package_charge')) || 0;
            }

            setValue('extra_card_charge', extraCardCharge.toFixed(2));
            setValue('printing_charge', printingCharge.toFixed(2));
            setValue('packing_charge', packingCharge.toFixed(2));

            chargeTotal = Math.max(0, extraCardCharge + packingCharge + printingCharge);
        } else {
            // Readymade common charges apply exactly once for the complete Proforma.
            chargeTotal = Math.max(0, extraCardCharge + packingCharge + printingCharge);
        }

        const customLineTotalDisplay = document.getElementById('custom_item_line_total_display');
        if (customLineTotalDisplay) {
            const currentCustomCharges =
                (parseFloat(getValue('custom_item_plate_charge')) || 0) +
                (parseFloat(getValue('custom_item_printing_charge')) || 0) +
                (parseFloat(getValue('custom_item_package_charge')) || 0);
            customLineTotalDisplay.value = (currentProductAmount + currentCustomCharges).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        const final = Math.round(Math.max(0, sub - discount + chargeTotal) * 100) / 100;
        const taxable = gstPercent > 0 ? (final / (1 + (gstPercent / 100))) : final;
        const gstAmount = Math.max(0, final - taxable);
        const tenderedAmount = Math.round(Math.max(0, cashAmount + upiAmount) * 100) / 100;

        /*
         * The amount applied to an invoice must never exceed its final amount.
         * Any extra cash/UPI entered is retained as the tendered amount and shown
         * separately as money that must be returned to the customer.
         */
        const advance = Math.min(tenderedAmount, final);
        const returnAmount = Math.round(Math.max(0, tenderedAmount - final) * 100) / 100;
        const balance = Math.max(0, final - advance);

        setValue('advance_amount', advance.toFixed(2));
        setValue('tendered_amount', tenderedAmount.toFixed(2));
        setValue('return_amount', returnAmount.toFixed(2));

        let paymentMode = '';
        if (cashChecked && upiChecked) paymentMode = 'split';
        else if (upiChecked) paymentMode = 'upi';
        else if (cashChecked) paymentMode = 'cash';
        setValue('payment_mode', paymentMode);

        const paymentReferenceParts = [getValue('cash_reference'), getValue('upi_reference')].filter(Boolean);
        if (returnAmount > 0.009) {
            paymentReferenceParts.push(
                'Customer paid Rs. ' + tenderedAmount.toFixed(2) + '; Return Rs. ' + returnAmount.toFixed(2)
            );
        }
        setValue('payment_reference', paymentReferenceParts.join(' | '));

        document.getElementById('subTotalText').textContent = rupee(sub);
        document.getElementById('chargeTotalText') && (document.getElementById('chargeTotalText').textContent = rupee(
            chargeTotal));
        document.getElementById('discountText') && (document.getElementById('discountText').textContent = rupee(
            discount));
        document.getElementById('taxableValueText') && (document.getElementById('taxableValueText').textContent = rupee(
            taxable));
        document.getElementById('gstAmountText') && (document.getElementById('gstAmountText').textContent = rupee(
            gstAmount));
        document.getElementById('tenderedAmountText') && (document.getElementById('tenderedAmountText').textContent =
            rupee(tenderedAmount));
        document.getElementById('advanceAmountText') && (document.getElementById('advanceAmountText').textContent =
            rupee(advance));
        document.getElementById('returnAmountText') && (document.getElementById('returnAmountText').textContent =
            rupee(returnAmount));
        const returnAmountDisplay = document.getElementById('return_amount_display');
        if (returnAmountDisplay) returnAmountDisplay.value = returnAmount.toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        document.getElementById('finalAmountText').textContent = rupee(final);
        document.getElementById('balanceAmountText').textContent = rupee(balance);

        const returnNotice = document.getElementById('returnPaymentNotice');
        if (returnNotice) {
            if (returnAmount > 0.009) {
                returnNotice.textContent = 'Customer paid ' + rupee(tenderedAmount) + '. Apply ' + rupee(advance) +
                    ' to this bill and return ' + rupee(returnAmount) + ' to the customer.';
                returnNotice.classList.remove('d-none');
            } else {
                returnNotice.textContent = '';
                returnNotice.classList.add('d-none');
            }
        }

        const cashModalReturn = document.getElementById('cashModalReturn');
        if (cashModalReturn) cashModalReturn.textContent = rupee(returnAmount);
        updatePricingSummaryValues({
            sub,
            chargeTotal,
            final,
            taxable,
            gstAmount,
            advance,
            tenderedAmount,
            returnAmount,
            balance,
            cashAmount,
            upiAmount
        });
        return {
            sub,
            chargeTotal,
            final,
            taxable,
            gstAmount,
            advance,
            tenderedAmount,
            returnAmount,
            balance,
            cashAmount,
            upiAmount
        };
    }

    function functionGroup() {
        const opt = document.getElementById('function_type_id')?.selectedOptions[0];
        return opt?.dataset.fieldGroup || 'other';
    }

    function toggleFunctionFields() {
        const g = functionGroup();
        document.querySelectorAll('.wedding-field').forEach(el => el.classList.toggle('hide-field', g !==
            'wedding_reception'));
        document.querySelectorAll('.event-field').forEach(el => el.classList.toggle('hide-field', !(g ===
            'wedding_reception' || g === 'event')));
    }

    function syncBillingDetails() {
        const customerName = String(getValue('customer_name') || '').trim();
        const customerMobile = String(getValue('mobile') || '').trim();
        const group = functionGroup();

        if (customerMobile !== '') {
            setValue('billing_mobile', customerMobile);
        }

        if (group === 'wedding_reception') {
            const groom = String(getValue('groom_name') || '').trim();
            const bride = String(getValue('bride_name') || '').trim();
            const coupleName = [groom, bride].filter(Boolean).join('&');
            setValue('billing_name', coupleName || customerName);
        } else {
            setValue('billing_name', customerName);
        }
    }

    function isMulticolorOffsetOption(opt) {
        if (!opt || !opt.value) return false;
        const label = (opt.textContent || '').toLowerCase();
        const roleKey = (opt.dataset.roleKey || '').toLowerCase();
        return ((label.includes('multicolor') || label.includes('multi color') || label.includes('multicolour')) &&
            label.includes('offset')) || roleKey.includes('multicolor_offset') || roleKey.includes(
            'multi_color_offset');
    }

    function findMulticolorOffsetPrintingTypeId() {
        const select = document.getElementById('printing_type_id');
        if (!select) return '';
        const options = Array.from(select.options);
        const match = options.find(opt => isMulticolorOffsetOption(opt));
        return match ? match.value : '';
    }

    function applyCustomizedDefaults(forceDefaults = false) {
        if (getValue('order_type') !== 'customized') return;

        const size = document.getElementById('size_text');
        const gsm = document.getElementById('gsm_thickness');
        if (size && (forceDefaults || !size.value)) size.value = '22x8.5';
        if (gsm && (forceDefaults || !gsm.value)) gsm.value = '300';

        const multiId = findMulticolorOffsetPrintingTypeId();
        const printingType = document.getElementById('printing_type_id');
        if (multiId && printingType && (forceDefaults || !printingType.value)) {
            printingType.value = multiId;
            refreshSelect('printing_type_id');
            updateSubTypes();
        }
    }

    function toggleOrderType(forceDefaults = false) {
        const type = getValue('order_type') || 'readymade';
        const isCustomized = type === 'customized';
        const isPrintingOnly = type === 'printing_only';
        const usesCommonPrinting = type === 'readymade' || isPrintingOnly;

        document.querySelectorAll('.readymade-field').forEach(el => el.classList.toggle('hide-field', !usesCommonPrinting));
        document.querySelectorAll('.customized-field').forEach(el => el.classList.toggle('hide-field', !isCustomized));
        document.querySelectorAll('.custom-only-field').forEach(el => el.classList.toggle('hide-field', !isCustomized));
        document.querySelectorAll('.customized-column').forEach(el => el.classList.toggle('hide-field', !isCustomized));
        document.querySelectorAll('.printing-only-hide').forEach(el => el.classList.toggle('hide-field', isPrintingOnly));
        document.querySelectorAll('.printing-only-hide-pricing').forEach(el => el.classList.toggle('hide-field', isPrintingOnly));
        document.getElementById('printingOnlyNote')?.classList.toggle('hide-field', !isPrintingOnly);

        const product = document.getElementById('product_id');
        const qty = document.getElementById('qty');
        const rate = document.getElementById('rate');
        const total = document.getElementById('product_total_display');
        const clearProduct = document.getElementById('clearProductBtn');

        [product, qty, rate, total, clearProduct].forEach(el => {
            if (el) el.disabled = isPrintingOnly;
        });
        document.querySelectorAll('.printing-only-product-field').forEach(el => {
            el.classList.toggle('printing-disabled', isPrintingOnly);
        });

        if (window.jQuery && $.fn.select2 && product) {
            $('#product_id').prop('disabled', isPrintingOnly).trigger('change.select2');
        }

        if (isPrintingOnly) {
            if (Array.isArray(proformaItems) && proformaItems.length) {
                proformaItems = [];
                editingProductIndex = -1;
                syncItemsJson();
            }
            setValue('product_id', '');
            setValue('product_name', '');
            setValue('qty', '1');
            setValue('rate', '0');
            clearPricingSelection('Printing Only uses manual Printing Charge. Product pricing is not required.');
        }

        document.getElementById('delivery_date').required = true;
        syncCurrentProductRequiredState();
        updatePrintingTypeOptions();
        applyCustomizedDefaults(forceDefaults);
        updateScreenSubTypeVisibility();
        toggleLamination();

        ['extra_card_charge', 'packing_charge', 'printing_charge'].forEach(id => {
            const field = document.getElementById(id);
            if (field) {
                field.readOnly = isCustomized;
                field.title = isCustomized ?
                    'Automatically calculated from all Customized product rows.' :
                    '';
            }
        });

        const chargeHelp = document.getElementById('chargeModeHelp');
        if (chargeHelp) {
            chargeHelp.textContent = isCustomized ?
                'Customized charges are added product-wise. These values are automatic totals from all added Customized products.' :
                (isPrintingOnly ?
                    'Printing Only: enter the customer printing amount in Printing Charge. No Product Master or stock reservation is used.' :
                    'Readymade charges are common once for the full Proforma.');
        }

        const autoLabel = document.getElementById('autoCreateJobCardLabel');
        if (autoLabel) {
            autoLabel.textContent = isCustomized ?
                'Create Separate Job Card for Every Customized Product Automatically' :
                (isPrintingOnly ? 'Create Printing Job Card Automatically' : 'Create Job Card Automatically');
        }

        const trackingHelp = document.getElementById('trackingDatesHelp');
        if (trackingHelp) {
            trackingHelp.textContent = isPrintingOnly ?
                'Uses the same tracking stages as a Readymade Job Card.' :
                'Optional. These dates belong to the single Readymade Job Card.';
        }

        if (isCustomized) {
            renderCustomizedProductWorkflow(null, true);
        }

        renderWorkflowSteps();
        renderProformaItems();
        calculate();
        refreshProformaIcons();
    }

    function updatePrintingTypeOptions() {
        const type = getValue('order_type') || 'readymade';
        const select = document.getElementById('printing_type_id');
        Array.from(select.options).forEach(opt => {
            if (!opt.value) return;
            const ok = type === 'printing_only'
                ? true
                : (type === 'readymade' ? opt.dataset.readymade === '1' : opt.dataset.customized === '1');
            opt.hidden = !ok;
            opt.disabled = !ok;
        });
        if (select.selectedOptions[0]?.disabled) {
            select.value = '';
        }
        updateSubTypes();
    }

    function isScreenPrintingSelected() {
        const opt = document.getElementById('printing_type_id')?.selectedOptions[0];
        if (!opt || !opt.value) return false;
        const text = (opt.textContent || '').toLowerCase();
        const roleKey = (opt.dataset.roleKey || '').toLowerCase();
        return text.includes('screen') || roleKey.includes('screen');
    }

    function updateScreenSubTypeVisibility() {
        const wrap = document.getElementById('screenSubTypeWrap') || document.getElementById('colourTypeWrap');
        const label = document.getElementById('printingSubTypeLabel');
        const sub = document.getElementById('printing_sub_type_id');
        const show = isScreenPrintingSelected();

        if (wrap) wrap.classList.toggle('hide-field', !show);
        if (label) label.textContent = 'Screen Print Sub-Type';
        if (sub) sub.required = show;
        if (!show && sub) {
            sub.value = '';
            refreshSelect('printing_sub_type_id');
        }
    }

    function updateSubTypes() {
        const pt = parseInt(getValue('printing_type_id') || 0, 10);
        const current = getValue('printing_sub_type_id');
        const sub = document.getElementById('printing_sub_type_id');
        sub.innerHTML = '<option value="">Not Applicable</option>';
        printingSubTypes.filter(s => parseInt(s.printing_type_id, 10) === pt).forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.sub_type_name;
            sub.appendChild(opt);
        });
        if (current) sub.value = current;
        updateScreenSubTypeVisibility();
        refreshSelect('printing_sub_type_id');
    }

    function toggleLamination() {
        const isCustomized = (getValue('order_type') || 'readymade') === 'customized';
        const laminationCheckbox = document.getElementById('lamination_required');

        document.querySelectorAll('.lamination-required-wrap').forEach(el => {
            el.classList.toggle('hide-field', !isCustomized);
        });

        if (laminationCheckbox) {
            laminationCheckbox.disabled = !isCustomized;
            if (!isCustomized) {
                laminationCheckbox.checked = false;
            }
        }

        const required = isCustomized && laminationCheckbox?.checked === true;
        document.querySelectorAll('.lamination-type-wrap').forEach(el => el.classList.toggle('hide-field', !required));
        if (!required) {
            setValue('lamination_type', 'none');
            refreshSelect('lamination_type');
        }
        syncCurrentProductRequiredState();
        syncProductNameFromMaster();
        schedulePriceLookup();
    }

    function normalizeDateValue(value) {
        if (!value) return '';
        const text = String(value);
        return text.length >= 10 ? text.substring(0, 10) : text;
    }

    function todayIso() {
        const d = new Date();
        const tz = new Date(d.getTime() - (d.getTimezoneOffset() * 60000));
        return tz.toISOString().slice(0, 10);
    }

    function selectedQuotationRow() {
        const id = parseInt(getValue('quotation_id') || 0, 10);
        return quotations.find(row => parseInt(row.id || 0, 10) === id) || null;
    }

    function stepKeyText(step) {
        return String((step && (step.step_key || step.step_name)) || '').toLowerCase();
    }

    function isEnquiryStep(step) {
        const key = stepKeyText(step);
        return key === 'enquiry' || key.includes('enquiry');
    }

    function isFinalReviewStep(step) {
        const key = stepKeyText(step);
        const name = String((step && step.step_name) || '').toLowerCase();
        return String(step && step.is_final_step || '0') === '1' ||
            key.includes('google') || key.includes('review') || key.includes('whatsapp') ||
            name.includes('google') || name.includes('review') || name.includes('whatsapp');
    }

    function enquiryDefaultDate() {
        const q = selectedQuotationRow();
        return normalizeDateValue((q && (q.enquiry_completed_at || q.enquiry_created_at)) || todayIso());
    }

    function defaultPlannedDateForStep(step, kind) {
        if (isEnquiryStep(step)) {
            return enquiryDefaultDate();
        }

        if (isFinalReviewStep(step)) {
            return normalizeDateValue(getValue('delivery_date') || todayIso());
        }

        return todayIso();
    }

    function syncFinalTrackingDate(force = false) {
        const delivery = normalizeDateValue(getValue('delivery_date') || '');
        if (!delivery) return;
        document.querySelectorAll('[data-planned-final="1"]').forEach(input => {
            if (force || !input.value) input.value = delivery;
        });
    }

    function fillEmptyPlannedDatesWithToday() {
        const today = todayIso();
        document.querySelectorAll('[data-planned-step-id]').forEach(input => {
            if (!input.value) input.value = today;
        });
        syncFinalTrackingDate(false);
    }

    function collectPlannedDatesFromBox(boxId, stepAttribute = 'plannedStepId') {
        const out = {};
        const box = document.getElementById(boxId);
        if (!box) return out;

        box.querySelectorAll('[data-planned-kind]').forEach(input => {
            const stepId = String(input.dataset[stepAttribute] || '');
            const kind = String(input.dataset.plannedKind || '');

            if (!stepId || !kind) return;
            if (!out[stepId]) out[stepId] = {};

            out[stepId][kind] = normalizeDateValue(input.value || '');
        });

        return out;
    }

    function renderCustomizedProductWorkflow(savedDates = null, preserveCurrent = true) {
        const box = document.getElementById('customWorkflowSteps');
        if (!box) return;

        let values = {};

        if (preserveCurrent) {
            values = collectPlannedDatesFromBox(
                'customWorkflowSteps',
                'customPlannedStepId'
            );
        }

        if (savedDates && typeof savedDates === 'object') {
            values = savedDates;
        }

        box.innerHTML = '';

        (workflowData.customized || []).forEach(step => {
            const stepId = String(step.id || '');
            const saved = values[stepId] || {};

            const startValue =
                normalizeDateValue(saved.start || saved.planned_start_date || '') ||
                defaultPlannedDateForStep(step, 'start');

            const completionValue =
                normalizeDateValue(saved.completion || saved.planned_completion_date || '') ||
                defaultPlannedDateForStep(step, 'completion');

            const div = document.createElement('div');
            div.className = 'workflow-step';

            const title = document.createElement('strong');
            title.textContent = (step.sort_order || '') + '. ' + (step.step_name || 'Step');
            div.appendChild(title);

            const row = document.createElement('div');
            row.className = 'workflow-date-grid';

            const startCol = document.createElement('div');
            startCol.className = 'workflow-date-field';
            startCol.innerHTML = '<small>Start</small>';

            const startInput = document.createElement('input');
            startInput.type = 'date';
            startInput.className = 'form-control';
            startInput.value = startValue;
            startInput.dataset.customPlannedStepId = stepId;
            startInput.dataset.plannedKind = 'start';
            startInput.dataset.plannedStepKey = String(step.step_key || '').toLowerCase();
            if (isFinalReviewStep(step)) startInput.dataset.plannedFinal = '1';
            startCol.appendChild(startInput);

            const completionCol = document.createElement('div');
            completionCol.className = 'workflow-date-field';
            completionCol.innerHTML = '<small>Complete</small>';

            const completionInput = document.createElement('input');
            completionInput.type = 'date';
            completionInput.className = 'form-control';
            completionInput.value = completionValue;
            completionInput.dataset.customPlannedStepId = stepId;
            completionInput.dataset.plannedKind = 'completion';
            completionInput.dataset.plannedStepKey = String(step.step_key || '').toLowerCase();
            if (isFinalReviewStep(step)) completionInput.dataset.plannedFinal = '1';
            completionCol.appendChild(completionInput);

            row.appendChild(startCol);
            row.appendChild(completionCol);
            div.appendChild(row);
            box.appendChild(div);
        });
    }

    function renderWorkflowSteps() {
        const type = getValue('order_type') || 'readymade';
        const box = document.getElementById('workflowSteps');
        if (!box) return;

        const previousValues = {};
        box.querySelectorAll('[data-planned-step-id]').forEach(input => {
            const stepId = String(input.dataset.plannedStepId || '');
            const kind = input.dataset.plannedKind || '';
            if (!stepId || !kind) return;
            if (!previousValues[stepId]) previousValues[stepId] = {};
            previousValues[stepId][kind] = input.value || '';
        });

        box.innerHTML = '';

        (workflowData[type] || []).forEach(step => {
            const stepId = String(step.id || '');
            const saved = plannedStepData[stepId] || {};
            const defaultStart = defaultPlannedDateForStep(step, 'start');
            const defaultCompletion = defaultPlannedDateForStep(step, 'completion');
            const startValue = (previousValues[stepId] && previousValues[stepId].start) ?
                previousValues[stepId].start :
                (normalizeDateValue(saved.start || saved.planned_start_date || '') || defaultStart);
            const completionValue = (previousValues[stepId] && previousValues[stepId].completion) ?
                previousValues[stepId].completion :
                (normalizeDateValue(saved.completion || saved.planned_completion_date || '') ||
                    defaultCompletion);

            const div = document.createElement('div');
            div.className = 'workflow-step';

            const title = document.createElement('strong');
            title.textContent = (step.sort_order || '') + '. ' + (step.step_name || 'Step');
            div.appendChild(title);

            const row = document.createElement('div');
            row.className = 'workflow-date-grid';

            const startCol = document.createElement('div');
            startCol.className = 'workflow-date-field';
            startCol.innerHTML = '<small>Start</small>';
            const startInput = document.createElement('input');
            startInput.type = 'date';
            startInput.className = 'form-control';
            startInput.name = 'planned_step[' + stepId + '][start]';
            startInput.value = startValue;
            startInput.dataset.plannedStepId = stepId;
            startInput.dataset.plannedKind = 'start';
            startInput.dataset.plannedStepKey = String(step.step_key || '').toLowerCase();
            if (isFinalReviewStep(step)) startInput.dataset.plannedFinal = '1';
            startCol.appendChild(startInput);

            const completionCol = document.createElement('div');
            completionCol.className = 'workflow-date-field';
            completionCol.innerHTML = '<small>Complete</small>';
            const completionInput = document.createElement('input');
            completionInput.type = 'date';
            completionInput.className = 'form-control';
            completionInput.name = 'planned_step[' + stepId + '][completion]';
            completionInput.value = completionValue;
            completionInput.dataset.plannedStepId = stepId;
            completionInput.dataset.plannedKind = 'completion';
            completionInput.dataset.plannedStepKey = String(step.step_key || '').toLowerCase();
            if (isFinalReviewStep(step)) completionInput.dataset.plannedFinal = '1';
            completionCol.appendChild(completionInput);

            row.appendChild(startCol);
            row.appendChild(completionCol);
            div.appendChild(row);
            box.appendChild(div);
        });

        fillEmptyPlannedDatesWithToday();
    }

    function applyEditData() {
        if (!editData) return;
        setValue('quotation_id', editData.quotation_id || '');
        setValue('order_type', editData.order_type || 'readymade');
        setValue('customer_name', editData.customer_name || '');
        setValue('mobile', editData.mobile || '');
        setValue('billing_name', editData.billing_name || '');
        setValue('billing_mobile', editData.billing_mobile || '');
        setValue('billing_address', editData.billing_address || '');
        setValue('gst_number', editData.gst_number || '');
        setValue('function_type_id', editData.function_type_id || '');
        setValue('proforma_status_id', editData.proforma_status_id || '');
        setValue('bride_name', editData.bride_name || '');
        setValue('groom_name', editData.groom_name || '');
        setValue('venue', editData.venue || '');
        setValue('function_date', editData.function_date || '');
        setValue('function_time', editData.function_time || '');
        /*
         * Existing items are loaded into the Added Products table.
         * Keep the current Product entry blank so Update Proforma does not
         * accidentally duplicate the first saved item.
         */
        if (Array.isArray(editItems) && editItems.length) {
            setValue('product_id', '');
            setValue('product_name', '');
            setValue('qty', '1');
            setValue('rate', '0');
        } else {
            // Backward-compatible fallback for very old single-item records.
            setValue('product_id', editData.item_product_id || editData.item_name || '');
            setValue('product_name', editData.item_name || '');
            setValue('qty', editData.item_qty || editData.total_qty || '');
            setValue('rate', editData.item_rate || '');
        }
        setValue('description', editData.item_description || '');
        setValue('discount_amount', editData.discount_amount || 0);
        setValue('extra_card_charge', editData.card_extra_charge || 0);
        setValue('packing_charge', editData.packing_charge || 0);
        setValue('printing_charge', editData.printing_charge || 0);
        setValue('gst_percent', editData.gst_percent || 18);
        setValue('cash_amount', editData.advance_amount || 0);
        setValue('upi_amount', 0);
        setValue('advance_amount', editData.advance_amount || 0);
        setValue('delivery_date', editData.delivery_date || '');
        setValue('remarks', editData.remarks || '');
        setValue('printing_type_id', editData.item_printing_type_id || '');
        updatePrintingTypeOptions();
        setValue('printing_sub_type_id', editData.item_printing_sub_type_id || '');
        setValue('size_text', editData.item_size_text || '');
        setValue('gsm_thickness', editData.item_gsm_thickness || '');
        setValue('lamination_type', editData.item_lamination_type || '');
        setValue('printing_side', editData.item_printing_side || '');
        setValue('screening_type', editData.item_screening_type || '');
        const finishing = document.getElementById('finishing_required');
        if (finishing) finishing.checked = parseInt(editData.item_finishing_required || 0, 10) === 1;
        const lamination = document.getElementById('lamination_required');
        if (lamination) lamination.checked = parseInt(editData.item_lamination_required || 0, 10) === 1;
        ['quotation_id', 'function_type_id', 'proforma_status_id', 'product_id', 'order_type', 'printing_type_id',
            'printing_sub_type_id', 'lamination_type', 'printing_side', 'screening_type', 'payment_mode'
        ].forEach(refreshSelect);
    }

    function clearQuotationSelection(showMessage = false) {
        setValue('quotation_id', '');
        if (window.jQuery && $.fn.select2) {
            $('#quotation_id').val('').trigger('change.select2');
            $('#quotation_id').select2('close');
        }
        renderWorkflowSteps();
        calculate();
        saveFormDraft();
        if (showMessage) {
            showActionToast('Quotation selection cancelled. You can continue as Direct Proforma Bill.', 'success',
                'Quotation Cleared');
        }
    }

    function loadQuotation() {
        const id = parseInt(getValue('quotation_id') || 0, 10);
        const q = quotations.find(row => parseInt(row.id, 10) === id);
        if (!q) {
            return;
        }
        setValue('customer_name', q.customer_name || '');
        setValue('mobile', q.mobile || '');
        setValue('billing_name', q.billing_name || q.customer_name || '');
        setValue('billing_mobile', q.billing_mobile || q.mobile || '');
        setValue('billing_address', q.billing_address || q.address || q.customer_address || '');
        setValue('gst_number', q.gst_number || '');
        setValue('function_type_id', q.function_type_id || '');
        setValue('bride_name', q.bride_name || '');
        setValue('groom_name', q.groom_name || '');
        setValue('venue', q.venue || '');
        setValue('function_date', q.function_date || '');
        setValue('function_time', q.function_time || '');
        syncBillingDetails();
        setValue('qty', q.total_qty || 1);
        setValue('description', q.item_details || q.description || '');
        setValue('product_name', q.product_name || q.item_name || q.item_details || '');
        setValue('rate', ((parseFloat(q.total_qty || 0) > 0) ? (parseFloat(q.sub_total || 0) / parseFloat(q.total_qty ||
            1)) : parseFloat(q.sub_total || 0)).toFixed(2));
        setValue('discount_amount', q.discount_amount || 0);
        refreshSelect('function_type_id');
        toggleFunctionFields();
        renderWorkflowSteps();
        calculate();
    }


    function clearPricingSelection(message =
        'No predefined printing charge found for this quantity/selection. Change quantity to a saved slab or edit Rate / Printing Charge manually.'
    ) {
        setValue('printing_price_master_id', '');
        setValue('price_slab_text_input', '');
        setValue('pricing_plate_charge', '0');
        setValue('pricing_printing_charge', '0');
        setValue('pricing_package_charge', '0');
        setValue('pricing_additional_charge', '0');
        setValue('pricing_is_gst_inclusive', '1');

        if ((getValue('order_type') || 'readymade') === 'customized') {
            setValue('custom_item_plate_charge', '0');
            setValue('custom_item_printing_charge', '0');
            setValue('custom_item_package_charge', '0');
        }

        const card = document.getElementById('pricingSummaryCard');
        const badge = document.getElementById('priceStatusBadge');
        const matched = document.getElementById('priceMatchedBox');
        const noMatch = document.getElementById('priceNoMatchBox');
        const info = document.getElementById('pricingMessage');
        card?.classList.add('no-price');
        badge?.classList.add('warn');
        if (badge) badge.textContent = 'Manual Pricing';
        matched?.classList.add('d-none');
        noMatch?.classList.remove('d-none');
        if (noMatch) noMatch.textContent = message;
        info?.classList.add('d-none');
        calculate();
    }

    function syncPricingHiddenFromEditableFields() {
        // Store the actual edited values with the current item for audit.
        const customized = (getValue('order_type') || 'readymade') === 'customized';

        setValue('pricing_plate_charge', (
            parseFloat(getValue(customized ? 'custom_item_plate_charge' : 'extra_card_charge')) || 0
        ).toFixed(2));
        setValue('pricing_printing_charge', (
            parseFloat(getValue(customized ? 'custom_item_printing_charge' : 'printing_charge')) || 0
        ).toFixed(2));
        setValue('pricing_package_charge', (
            parseFloat(getValue(customized ? 'custom_item_package_charge' : 'packing_charge')) || 0
        ).toFixed(2));
        setValue('pricing_additional_charge', '0.00');
    }

    function updatePricingSummaryValues(amounts = null) {
        syncPricingHiddenFromEditableFields();
        const currentRate = parseFloat(getValue('rate')) || 0;
        const hasAddedProducts = Array.isArray(proformaItems) && proformaItems.length > 0;
        const qty = hasAddedProducts ?
            proformaItems.reduce((sum, item) => sum + (parseFloat(item.qty || 0) || 0), 0) :
            (parseFloat(getValue('qty')) || 0);
        const plate = parseFloat(getValue('extra_card_charge')) || 0;
        const printing = parseFloat(getValue('printing_charge')) || 0;
        const packing = parseFloat(getValue('packing_charge')) || 0;
        const itemAmount = hasAddedProducts ?
            proformaItems.reduce((sum, item) => sum + (parseFloat(item.amount || 0) || 0), 0) :
            qty * currentRate;
        const final = amounts && typeof amounts.final === 'number' ?
            amounts.final :
            (itemAmount + plate + printing + packing - (parseFloat(getValue('discount_amount')) || 0));
        const setText = (id, text) => {
            const el = document.getElementById(id);
            if (el) el.textContent = text;
        };
        setText('priceRateText', hasAddedProducts && proformaItems.length > 1 ? 'Multiple Rates' : rupee(currentRate));
        setText('priceQtyText', (qty || 0).toLocaleString('en-IN') + ' Nos');
        setText('pricePlateText', rupee(plate));
        setText('pricePrintingText', rupee(printing));
        setText('pricePackageText', rupee(packing));
        setText('priceItemAmountText', rupee(itemAmount));
        setText('priceFinalText', rupee(Math.max(0, final || 0)));
    }

    function applyPricingResult(row) {
        if (!row || !row.id) {
            clearPricingSelection();
            return;
        }

        const masterRate = parseFloat(row.rate || 0) || 0;
        const ratePerCard = parseFloat(row.rate_per_card || 0) || 0;
        const plate = parseFloat(row.plate_charge || 0) || 0;
        const additional = parseFloat(row.additional_charge || 0) || 0;
        const printing = parseFloat(row.printing_charge || 0) || 0;
        const packing = parseFloat(row.package_charge || 0) || 0;
        const slab = String(row.slab_text || ((row.min_qty || '-') + ' - ' + (row.max_qty || '-')));
        const pricingMode = String(row.pricing_mode || 'total_charge');
        const autoTarget = String(row.auto_fill_target || 'printing_charge');

        setValue('printing_price_master_id', row.id);
        setValue('price_slab_text_input', slab);
        setValue('pricing_is_gst_inclusive', String(row.is_gst_inclusive ?? 1));

        // Your sheet pricing should mostly auto-fill Printing Charge.
        // Rate remains editable. It is changed only when a pricing row is explicitly configured to auto-fill rate.
        if ((autoTarget === 'rate' || autoTarget === 'both') && (masterRate > 0 || ratePerCard > 0)) {
            setValue('rate', (masterRate > 0 ? masterRate : ratePerCard).toFixed(2));
        }

        const customizedPricing = (getValue('order_type') || 'readymade') === 'customized';

        if (customizedPricing) {
            setValue('custom_item_printing_charge', printing.toFixed(2));
            setValue('custom_item_plate_charge', (plate + additional).toFixed(2));
            setValue('custom_item_package_charge', packing.toFixed(2));
        } else {
            if (autoTarget === 'printing_charge' || autoTarget === 'both' || autoTarget === '') {
                setValue('printing_charge', printing.toFixed(2));
            }
            setValue('extra_card_charge', (plate + additional).toFixed(2));
            setValue('packing_charge', packing.toFixed(2));
        }

        syncPricingHiddenFromEditableFields();

        if (row.gst_percent !== undefined && row.gst_percent !== null) {
            setValue('gst_percent', parseFloat(row.gst_percent || 18).toFixed(2));
        }

        const card = document.getElementById('pricingSummaryCard');
        const badge = document.getElementById('priceStatusBadge');
        const matched = document.getElementById('priceMatchedBox');
        const noMatch = document.getElementById('priceNoMatchBox');
        const info = document.getElementById('pricingMessage');
        card?.classList.remove('no-price');
        badge?.classList.remove('warn');
        if (badge) badge.textContent = 'Pricing Matched';
        matched?.classList.remove('d-none');
        noMatch?.classList.add('d-none');
        info?.classList.remove('d-none');
        const slabEl = document.getElementById('priceSlabText');
        if (slabEl) slabEl.textContent = slab;
        const modeEl = document.getElementById('priceModeText');
        if (modeEl) modeEl.textContent = pricingMode === 'per_card' ? 'Per Card Rule' : 'Fixed Slab Charge';
        calculate();
    }

    let pricingLookupTimer = null;
    let pricingLookupController = null;

    function currentPricingPayload() {
        const isCustomized = (getValue('order_type') || 'readymade') === 'customized';
        const laminationRequired = isCustomized && document.getElementById('lamination_required')?.checked === true;

        let productId = getValue('product_id');
        let productName = getValue('product_name');
        let qty = getValue('qty') || '0';

        if (!isCustomized && Array.isArray(proformaItems) && proformaItems.length > 0) {
            qty = String(proformaItems.reduce((sum, item) => sum + (parseFloat(item.qty || 0) || 0), 0));

            if (proformaItems.length === 1) {
                productId = proformaItems[0].product_id || '';
                productName = proformaItems[0].product_name || '';
            } else {
                productId = '';
                productName = '';
            }
        }

        return {
            product_id: productId,
            product_name: productName,
            printing_type_id: getValue('printing_type_id'),
            printing_sub_type_id: getValue('printing_sub_type_id'),
            size_text: getValue('size_text'),
            gsm_thickness: getValue('gsm_thickness'),
            printing_side: getValue('printing_side'),
            lamination_type: laminationRequired ? (getValue('lamination_type') || 'none') : 'none',
            print_type: 'first_print',
            qty: qty
        };
    }

    function canLookupPricing(payload) {
        const qty = parseFloat(payload.qty || 0) || 0;

        if ((getValue('order_type') || 'readymade') === 'printing_only') {
            return false;
        }

        if ((getValue('order_type') || 'readymade') === 'readymade' &&
            Array.isArray(proformaItems) && proformaItems.length > 1) {
            return false;
        }

        return qty > 0 &&
            (String(payload.product_id || '').trim() !== '' || String(payload.product_name || '').trim() !== '') &&
            String(payload.printing_type_id || '').trim() !== '';
    }

    function fetchPricing() {
        const payload = currentPricingPayload();
        if (!canLookupPricing(payload)) {
            if ((getValue('order_type') || 'readymade') === 'printing_only') {
                clearPricingSelection('Printing Only uses manual Printing Charge. Product slab pricing is not used.');
            } else if ((getValue('order_type') || 'readymade') === 'readymade' &&
                Array.isArray(proformaItems) && proformaItems.length > 1 &&
                String(getValue('printing_type_id') || '').trim() !== '') {
                clearPricingSelection(
                    'Multiple Readymade products use one common Printing Type. Product-specific slab pricing is not auto-applied; enter the common Printing Charge manually.'
                );
            } else {
                clearPricingSelection('Select product, printing type and quantity to fetch automatic pricing.');
            }
            return;
        }

        if (pricingLookupController) pricingLookupController.abort();
        pricingLookupController = new AbortController();
        const params = new URLSearchParams(Object.assign({
            action: 'find_price'
        }, payload));

        fetch('api/printing_price_master.php?' + params.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                signal: pricingLookupController.signal
            })
            .then(response => response.json())
            .then(data => {
                if (data && data.status && data.price) {
                    applyPricingResult(data.price);
                } else {
                    clearPricingSelection((data && data.message) ||
                        'No pricing found. Please add pricing in Printing Price Master.');
                }
            })
            .catch(error => {
                if (error && error.name === 'AbortError') return;
                clearPricingSelection('Unable to fetch pricing. Please check the pricing API.');
            });
    }

    function schedulePriceLookup() {
        clearTimeout(pricingLookupTimer);
        pricingLookupTimer = setTimeout(fetchPricing, 350);
    }


    let creatingProductInMaster = false;

    function createProductInMaster(productName) {
        const form = document.getElementById('proformaForm');
        const token = form?.querySelector('[name="csrf_token"]')?.value || '';
        const fd = new FormData();
        fd.append('csrf_token', token);
        fd.append('action', 'create_product');
        fd.append('product_name', productName);
        fd.append('order_type', getValue('order_type') || 'readymade');
        fd.append('rate', getValue('rate') || '0');

        return fetch('api/create_proforma.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(response => response.json());
    }

    function selectCreatedProduct(product) {
        if (!product || !product.id) return;
        const select = document.getElementById('product_id');
        if (!select) return;
        const id = String(product.id);
        const name = String(product.product_name || product.name || '').trim();
        let opt = Array.from(select.options).find(o => String(o.value) === id);
        if (!opt) {
            opt = new Option(name || id, id, true, true);
            select.appendChild(opt);
        }
        opt.value = id;
        opt.textContent = name || id;
        opt.dataset.name = name || id;
        opt.dataset.orderType = product.default_order_type || getValue('order_type') || 'readymade';
        opt.dataset.price = product.default_price || '0';
        opt.dataset.onHand = product.on_hand_stock || '0';
        opt.dataset.reserved = product.reserved_stock || '0';
        opt.dataset.available = product.available_stock || '0';
        select.value = id;
        refreshSelect('product_id');
        setValue('product_name', name || id);
    }

    function ensureSelectedProductSaved() {
        const select = document.getElementById('product_id');
        const value = String(select?.value || '').trim();
        const name = selectedProductName();
        if (!value || /^\d+$/.test(value) || creatingProductInMaster) {
            productChangedCore();
            return;
        }

        creatingProductInMaster = true;
        showActionToast('Saving new product to Product Master...', 'success', 'Product Master');
        createProductInMaster(name || value)
            .then(data => {
                if (data && data.status && data.product) {
                    selectCreatedProduct(data.product);
                    showActionToast('New product saved and selected.', 'success', 'Product Master');
                } else {
                    setValue('product_name', name || value);
                    showActionToast((data && data.message) ||
                        'Product could not be saved now. It will be saved while creating proforma.', 'danger',
                        'Product Master');
                }
                productChangedCore();
            })
            .catch(() => {
                setValue('product_name', name || value);
                showActionToast('Product auto-save failed. It will be saved while creating proforma.', 'danger',
                    'Product Master');
                productChangedCore();
            })
            .finally(() => {
                creatingProductInMaster = false;
            });
    }

    function selectedProductName() {
        const select = document.getElementById('product_id');
        const opt = select?.selectedOptions[0];
        return (opt?.dataset?.name || opt?.textContent || opt?.value || '').trim();
    }

    function syncProductNameFromMaster() {
        const productName = selectedProductName();
        const lowerName = productName.toLowerCase();
        if (!getValue('product_id')) {
            setValue('product_name', '');
            return;
        }
        if (productName && !['search or type product/card name', 'select or type product/card name',
                'select product/card name'
            ].includes(lowerName)) {
            setValue('product_name', productName);
        }
    }

    function updateSelectedProductStockInfo() {
        const info = document.getElementById('selectedProductStockInfo');
        const select = document.getElementById('product_id');
        const opt = select?.selectedOptions[0];

        if (!info) return;

        if (!select?.value || !opt) {
            info.textContent = 'Select a product to view On Hand, Reserved and Available stock.';
            return;
        }

        const stock = currentSelectedStock();
        const onHand = stock.on_hand_stock;
        const reserved = stock.reserved_stock;
        const available = stock.available_stock;
        const fmt = value => Number(value || 0).toLocaleString('en-IN', {
            maximumFractionDigits: 2
        });

        info.textContent = 'On Hand: ' + fmt(onHand) + ' | Reserved: ' + fmt(reserved) + ' | Available: ' + fmt(
            available);
        info.classList.toggle('text-danger', available < 0);
    }


    let proformaItems = Array.isArray(editItems) ?
        editItems.map(item => ({
            proforma_item_id: parseInt(item.proforma_item_id || 0, 10) || 0,
            product_id: String(item.product_id || ''),
            product_name: String(item.product_name || '').trim(),
            description: String(item.description || ''),
            qty: parseFloat(item.qty || 0) || 0,
            rate: parseFloat(item.rate || 0) || 0,
            amount: parseFloat(item.amount || 0) || 0,
            printing_type_id: String(item.printing_type_id || ''),
            printing_sub_type_id: String(item.printing_sub_type_id || ''),
            finishing_required: parseInt(item.finishing_required || 0, 10) || 0,
            size_text: String(item.size_text || ''),
            gsm_thickness: String(item.gsm_thickness || ''),
            lamination_required: parseInt(item.lamination_required || 0, 10) || 0,
            lamination_type: String(item.lamination_type || ''),
            printing_side: String(item.printing_side || ''),
            screening_type: String(item.screening_type || ''),
            printing_price_master_id: String(item.printing_price_master_id || ''),
            price_slab_text: String(item.price_slab_text || ''),
            plate_charge: parseFloat(item.plate_charge || 0) || 0,
            item_printing_charge: parseFloat(item.item_printing_charge || 0) || 0,
            item_package_charge: parseFloat(item.item_package_charge || 0) || 0,
            item_additional_charge: parseFloat(item.item_additional_charge || 0) || 0,
            is_gst_inclusive: parseInt(item.is_gst_inclusive ?? 1, 10) === 1 ? 1 : 0,
            on_hand_stock: parseFloat(item.on_hand_stock || 0) || 0,
            reserved_stock: parseFloat(item.reserved_stock || 0) || 0,
            available_stock: parseFloat(item.available_stock || 0) || 0,
            projected_available_stock: parseFloat(item.projected_available_stock || 0) || 0,
            planned_dates: (item.planned_dates && typeof item.planned_dates === 'object') ?
                item.planned_dates : {}
        })) : [];
    let editingProductIndex = -1;

    function syncItemsJson() {
        const input = document.getElementById('items_json');
        if (input) input.value = JSON.stringify(proformaItems);
    }

    function syncCurrentProductRequiredState() {
        const hasSavedItems = Array.isArray(proformaItems) && proformaItems.length > 0;
        const hasCurrentProduct = String(getValue('product_id') || '').trim() !== '';
        const orderType = getValue('order_type') || 'readymade';
        const isCustomized = orderType === 'customized';
        const isPrintingOnly = orderType === 'printing_only';

        /*
         * Once at least one product has been added, the blank Product Entry area
         * is only for adding the NEXT product. It must not remain browser-required.
         *
         * This prevents Create Proforma from jumping back to Product Master /
         * Printing Side / Scoring Type after a valid product is already in
         * "Added Products".
         */
        const currentLineRequired = !isPrintingOnly && (!hasSavedItems || hasCurrentProduct || editingProductIndex >= 0);

        const product = document.getElementById('product_id');
        const qty = document.getElementById('qty');

        if (product) product.required = currentLineRequired;
        if (qty) qty.required = currentLineRequired;

        ['size_text', 'gsm_thickness', 'printing_side', 'screening_type'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.required = isCustomized && currentLineRequired;
            }
        });

        const laminationCheckbox = document.getElementById('lamination_required');
        const laminationType = document.getElementById('lamination_type');
        if (laminationType) {
            laminationType.required =
                isCustomized &&
                currentLineRequired &&
                laminationCheckbox?.checked === true;
        }
    }

    function escapeItemText(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function currentSelectedStock() {
        const select = document.getElementById('product_id');
        const opt = select?.selectedOptions[0];

        const onHand = parseFloat(opt?.dataset?.onHand || 0) || 0;
        const rawReserved = parseFloat(opt?.dataset?.reserved || 0) || 0;
        const rawAvailable = parseFloat(opt?.dataset?.available || 0) || 0;
        const productId = String(select?.value || '');

        /*
         * Edit mode: release this Proforma's OLD reservation only for UI
         * calculation. The API later resynchronizes the actual reservation when
         * Update Proforma is saved.
         */
        const ownReservation = isEditPage ?
            (parseFloat(editReservationByProduct[productId] || 0) || 0) :
            0;

        return {
            on_hand_stock: onHand,
            reserved_stock: Math.max(0, rawReserved - ownReservation),
            available_stock: rawAvailable + ownReservation
        };
    }

    function currentProductItem() {
        const productId = String(getValue('product_id') || '').trim();
        const productName = String(getValue('product_name') || selectedProductName() || '').trim();
        const qty = parseFloat(getValue('qty')) || 0;
        const rate = parseFloat(getValue('rate')) || 0;
        const stock = currentSelectedStock();
        const orderType = getValue('order_type') || 'readymade';

        const item = {
            proforma_item_id: editingProductIndex >= 0 ?
                (parseInt(proformaItems[editingProductIndex]?.proforma_item_id || 0, 10) || 0) : 0,
            product_id: productId,
            product_name: productName,
            qty: qty,
            rate: rate,
            amount: Math.round(qty * rate * 100) / 100,
            on_hand_stock: stock.on_hand_stock,
            reserved_stock: stock.reserved_stock,
            available_stock: stock.available_stock,
            projected_available_stock: stock.available_stock - qty
        };

        if (orderType === 'customized') {
            const multiId = findMulticolorOffsetPrintingTypeId();

            item.description = String(getValue('custom_item_description') || '').trim();
            item.printing_type_id = String(multiId || getValue('printing_type_id') || '');
            item.printing_sub_type_id = '';
            item.finishing_required = 0;
            item.size_text = String(getValue('size_text') || '').trim();
            item.gsm_thickness = String(getValue('gsm_thickness') || '').trim();
            item.lamination_required = document.getElementById('lamination_required')?.checked ? 1 : 0;
            item.lamination_type = item.lamination_required ? String(getValue('lamination_type') || '').trim() : '';
            item.printing_side = String(getValue('printing_side') || '').trim();
            item.screening_type = String(getValue('screening_type') || '').trim();
            item.printing_price_master_id = String(getValue('printing_price_master_id') || '');
            item.price_slab_text = String(getValue('price_slab_text_input') || '');
            item.plate_charge = parseFloat(getValue('custom_item_plate_charge')) || 0;
            item.item_printing_charge = parseFloat(getValue('custom_item_printing_charge')) || 0;
            item.item_package_charge = parseFloat(getValue('custom_item_package_charge')) || 0;
            item.item_additional_charge = 0;
            item.is_gst_inclusive = parseInt(getValue('pricing_is_gst_inclusive') || '1', 10) === 1 ? 1 : 0;
            item.planned_dates = collectPlannedDatesFromBox(
                'customWorkflowSteps',
                'customPlannedStepId'
            );
        }

        return item;
    }

    function validateCurrentProductForAdd() {
        const item = currentProductItem();
        if (!item.product_id || !item.product_name) {
            return 'Please select a product from Product Master.';
        }
        if (item.qty <= 0) {
            return 'Quantity must be greater than zero.';
        }
        if (item.rate < 0) {
            return 'Rate cannot be negative.';
        }

        if ((getValue('order_type') || 'readymade') === 'customized') {
            if (!item.printing_type_id) {
                return 'Multicolour Offset Print is missing in Printing Type master.';
            }
            if (!item.size_text) {
                return 'Please enter Card Size for this Customized product.';
            }
            if (!item.gsm_thickness) {
                return 'Please enter GSM / Thickness for this Customized product.';
            }
            if (!item.printing_side) {
                return 'Please select Printing Side for this Customized product.';
            }
            if (!item.screening_type) {
                return 'Please select Scoring Type for this Customized product.';
            }
            if (item.lamination_required && !item.lamination_type) {
                return 'Please select Lamination Type for this Customized product.';
            }
        }

        return '';
    }

    function resetProductEntryAfterAdd() {
        editingProductIndex = -1;
        setValue('product_id', '');
        setValue('product_name', '');
        setValue('qty', '1');
        setValue('rate', '0');

        if ((getValue('order_type') || 'readymade') === 'customized') {
            setValue('custom_item_description', '');
            setValue('size_text', '');
            setValue('gsm_thickness', '');
            setValue('printing_side', '');
            setValue('screening_type', '');

            if (document.getElementById('lamination_required')) {
                document.getElementById('lamination_required').checked = false;
            }
            setValue('lamination_type', 'none');

            setValue('custom_item_plate_charge', '0');
            setValue('custom_item_printing_charge', '0');
            setValue('custom_item_package_charge', '0');

            setValue('printing_price_master_id', '');
            setValue('price_slab_text_input', '');
            setValue('pricing_plate_charge', '0');
            setValue('pricing_printing_charge', '0');
            setValue('pricing_package_charge', '0');
            setValue('pricing_additional_charge', '0');

            applyCustomizedDefaults(false);
            toggleLamination();
            renderCustomizedProductWorkflow({}, false);
        }

        refreshSelect('product_id');
        updateSelectedProductStockInfo();
        syncCurrentProductRequiredState();

        const btn = document.getElementById('addProductBtn');
        if (btn) {
            btn.innerHTML = '<i data-lucide="plus" class="me-1"></i> Add Product';
            refreshProformaIcons();
        }

        calculate();
    }

    function renderProformaItems() {
        syncItemsJson();
        syncCurrentProductRequiredState();

        const body = document.getElementById('proformaItemsBody');
        const empty = document.getElementById('proformaItemsEmpty');
        const wrap = document.getElementById('proformaItemsTableWrap');
        const count = document.getElementById('proformaItemsCount');

        if (count) count.textContent = proformaItems.length + (proformaItems.length === 1 ? ' Product' : ' Products');
        if (!body || !empty || !wrap) return;

        if (!proformaItems.length) {
            body.innerHTML = '';
            empty.classList.remove('d-none');
            wrap.classList.add('d-none');
            calculate();
            return;
        }

        empty.classList.add('d-none');
        wrap.classList.remove('d-none');

        body.innerHTML = proformaItems.map((item, index) => {
            const baseAvailable = parseFloat(item.available_stock || 0) || 0;
            const qty = parseFloat(item.qty || 0) || 0;
            const projectedAvailable = Number.isFinite(parseFloat(item.projected_available_stock)) ?
                parseFloat(item.projected_available_stock) :
                (baseAvailable - qty);
            const availableText = Number(projectedAvailable).toLocaleString('en-IN', {
                maximumFractionDigits: 2
            });
            const stockClass = projectedAvailable < 0 ? ' negative' : '';

            const customized = (getValue('order_type') || 'readymade') === 'customized';
            const itemCharges = customized ?
                (parseFloat(item.plate_charge || 0) || 0) +
                (parseFloat(item.item_printing_charge || 0) || 0) +
                (parseFloat(item.item_package_charge || 0) || 0) +
                (parseFloat(item.item_additional_charge || 0) || 0) :
                0;
            const lineTotal = (parseFloat(item.amount || 0) || 0) + itemCharges;
            const productionDetails = customized ? [
                    item.size_text ? 'Size: ' + item.size_text : '',
                    item.gsm_thickness ? 'GSM: ' + item.gsm_thickness : '',
                    item.printing_side ? (item.printing_side === 'double' ? 'Double Side' : 'Single Side') : '',
                    item.screening_type ? (item.screening_type === 'special' ? 'Special Scoring' :
                        'Regular Scoring') : '',
                    item.lamination_required ?
                    'Lamination: ' + (item.lamination_type || '-') :
                    'No Lamination'
                ].filter(Boolean).join(' • ') :
                '';

            const plannedDates = customized && item.planned_dates && typeof item.planned_dates === 'object' ?
                item.planned_dates : {};
            const plannedRows = Object.values(plannedDates).filter(row => row && typeof row === 'object');
            const plannedStarts = plannedRows
                .map(row => normalizeDateValue(row.start || ''))
                .filter(Boolean)
                .sort();
            const plannedCompletions = plannedRows
                .map(row => normalizeDateValue(row.completion || ''))
                .filter(Boolean)
                .sort();
            const trackingSummary = customized ?
                (
                    plannedRows.length ?
                    'Tracking: ' + plannedRows.length + ' stages' +
                    (plannedStarts.length ? ' • Start ' + plannedStarts[0] : '') +
                    (plannedCompletions.length ? ' • End ' + plannedCompletions[plannedCompletions.length - 1] :
                        '') :
                    'Tracking dates: Not planned'
                ) :
                '';

            return `<tr>
                <td>${index + 1}</td>
                <td>
                    <strong>${escapeItemText(item.product_name)}</strong>
                    <div class="product-stock-badge${stockClass}">Available After: ${escapeItemText(availableText)}</div>
                </td>
                <td class="text-end">${Number(item.qty || 0).toLocaleString('en-IN')}</td>
                <td class="text-end">${rupee(item.rate || 0)}</td>
                <td class="text-end fw-bold">${rupee(item.amount || 0)}</td>
                <td class="text-end customized-column${customized ? '' : ' hide-field'}">${rupee(itemCharges)}</td>
                <td class="text-end fw-bold customized-column${customized ? '' : ' hide-field'}">${rupee(lineTotal)}</td>
                <td class="customized-column${customized ? '' : ' hide-field'}">
                    <strong>Separate Job Card / Independent Workflow</strong>
                    <div class="small text-muted-custom">${escapeItemText(productionDetails)}</div>
                    <div class="small text-muted-custom mt-1">${escapeItemText(trackingSummary)}</div>
                </td>
                <td>
                    <div class="proforma-item-actions">
                       <button type="button" class="btn btn-outline-primary" data-edit-product="${index}" title="Edit product">
    <i data-lucide="pencil"></i>
</button>

<button type="button" class="btn btn-outline-danger" data-remove-product="${index}" title="Remove product">
    <i data-lucide="trash-2"></i>
</button>
                    </div>
                </td>
            </tr>`;
        }).join('');

        // Product rows are generated dynamically, so Lucide must be refreshed
        // after every render or the Edit/Delete buttons appear as empty boxes.
        refreshProformaIcons();

        calculate();
        schedulePriceLookup();
    }

    function addOrUpdateCurrentProduct() {
        const message = validateCurrentProductForAdd();
        if (message) {
            showActionToast(message, 'danger', 'Product Check');
            return;
        }

        const item = currentProductItem();

        if ((getValue('order_type') || 'readymade') === 'readymade') {
            const duplicateIndex = proformaItems.findIndex((row, index) =>
                index !== editingProductIndex &&
                String(row.product_id || '') === String(item.product_id || '')
            );

            if (duplicateIndex >= 0) {
                showActionToast(
                    'This Readymade product is already added. Edit the existing product row instead.',
                    'warning',
                    'Duplicate Product'
                );
                return;
            }
        }

        if (editingProductIndex >= 0) {
            proformaItems[editingProductIndex] = item;
            showActionToast(item.product_name + ' updated in Proforma.', 'success', 'Product Updated');
        } else {
            proformaItems.push(item);
            showActionToast(item.product_name + ' added to Proforma.', 'success', 'Product Added');
        }

        renderProformaItems();
        resetProductEntryAfterAdd();
    }

    function editProductLine(index) {
        const item = proformaItems[index];
        if (!item) return;

        editingProductIndex = index;
        setValue('product_id', item.product_id || '');
        setValue('product_name', item.product_name || '');
        setValue('qty', item.qty || 1);
        setValue('rate', item.rate || 0);

        if ((getValue('order_type') || 'readymade') === 'customized') {
            setValue('custom_item_description', item.description || '');
            setValue('size_text', item.size_text || '');
            setValue('gsm_thickness', item.gsm_thickness || '');
            setValue('printing_side', item.printing_side || '');
            setValue('screening_type', item.screening_type || '');

            const laminationRequired = parseInt(item.lamination_required || 0, 10) === 1;
            if (document.getElementById('lamination_required')) {
                document.getElementById('lamination_required').checked = laminationRequired;
            }
            setValue('lamination_type', laminationRequired ? (item.lamination_type || 'matte') : 'none');

            setValue('custom_item_plate_charge', item.plate_charge || 0);
            setValue('custom_item_printing_charge', item.item_printing_charge || 0);
            setValue('custom_item_package_charge', item.item_package_charge || 0);
            setValue('printing_price_master_id', item.printing_price_master_id || '');
            setValue('price_slab_text_input', item.price_slab_text || '');

            toggleLamination();
            renderCustomizedProductWorkflow(item.planned_dates || {}, false);
        }

        refreshSelect('product_id');
        updateSelectedProductStockInfo();
        syncCurrentProductRequiredState();

        const btn = document.getElementById('addProductBtn');
        if (btn) {
            btn.innerHTML = '<i data-lucide="check" class="me-1"></i> Update Product';
            refreshProformaIcons();
        }

        calculate();
        document.getElementById('product_id')?.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }

    function removeProductLine(index) {
        const item = proformaItems[index];
        if (!item) return;
        proformaItems.splice(index, 1);
        if (editingProductIndex === index) {
            resetProductEntryAfterAdd();
        } else if (editingProductIndex > index) {
            editingProductIndex--;
        }
        renderProformaItems();
        showActionToast(item.product_name + ' removed from Proforma.', 'success', 'Product Removed');
    }

    function productChangedCore() {
        syncProductNameFromMaster();
        const select = document.getElementById('product_id');
        const opt = select?.selectedOptions[0];
        const ot = opt?.dataset?.orderType || '';

        if (ot === 'readymade' || ot === 'customized') {
            setValue('order_type', ot);
            toggleOrderType(true);
        } else {
            toggleOrderType(false);
        }

        /*
         * Product Master price is only the starting rate.
         * User can edit Rate / Unit for this Proforma without changing Product Master.
         */
        if (select?.value && getValue('order_type') === 'readymade') {
            const defaultRate = parseFloat(opt?.dataset?.price || 0);
            setValue('rate', (Number.isFinite(defaultRate) && defaultRate >= 0 ? defaultRate : 0).toFixed(2));
        } else if (!select?.value) {
            setValue('rate', '0');
        }

        updateSelectedProductStockInfo();
        syncCurrentProductRequiredState();
        schedulePriceLookup();
        calculate();
    }

    function productChanged() {
        ensureSelectedProductSaved();
    }
    ['discount_amount', 'extra_card_charge', 'packing_charge', 'printing_charge', 'gst_percent', 'cash_amount',
        'upi_amount', 'rate', 'qty', 'custom_item_plate_charge', 'custom_item_printing_charge',
        'custom_item_package_charge'
    ].forEach(id => document.getElementById(id)?.addEventListener(
        'input', calculate));
    ['qty', 'size_text', 'gsm_thickness'].forEach(id => document.getElementById(id)?.addEventListener('input',
        schedulePriceLookup));
    ['printing_type_id', 'printing_sub_type_id', 'printing_side', 'lamination_type'].forEach(id => document
        .getElementById(id)?.addEventListener('change', schedulePriceLookup));
    document.getElementById('quotation_id')?.addEventListener('change', loadQuotation);
    document.getElementById('clearQuotationBtn')?.addEventListener('click', function() {
        clearQuotationSelection(true);
    });
    document.getElementById('function_type_id')?.addEventListener('change', () => {
        toggleFunctionFields();
        syncBillingDetails();
    });
    ['customer_name', 'mobile', 'groom_name', 'bride_name'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', syncBillingDetails);
        document.getElementById(id)?.addEventListener('change', syncBillingDetails);
    });
    document.getElementById('order_type')?.addEventListener('change', () => {
        toggleOrderType(true);
        schedulePriceLookup();
    });
    document.getElementById('printing_type_id')?.addEventListener('change', () => {
        updateSubTypes();
        schedulePriceLookup();
    });
    document.getElementById('delivery_date')?.addEventListener('change', () => syncFinalTrackingDate(true));
    document.getElementById('lamination_required')?.addEventListener('change', () => {
        toggleLamination();
        schedulePriceLookup();
    });
    document.getElementById('product_id')?.addEventListener('change', productChanged);
    document.getElementById('addProductBtn')?.addEventListener('click', addOrUpdateCurrentProduct);
    document.getElementById('proformaItemsBody')?.addEventListener('click', function(event) {
        const editBtn = event.target.closest('[data-edit-product]');
        if (editBtn) {
            editProductLine(parseInt(editBtn.dataset.editProduct || '-1', 10));
            return;
        }
        const removeBtn = event.target.closest('[data-remove-product]');
        if (removeBtn) {
            removeProductLine(parseInt(removeBtn.dataset.removeProduct || '-1', 10));
        }
    });
    document.getElementById('clearProductBtn')?.addEventListener('click', function() {
        setValue('product_id', '');
        setValue('product_name', '');
        if (window.jQuery && $.fn.select2) {
            $('#product_id').val('').trigger('change.select2');
            $('#product_id').select2('close');
        }
        clearPricingSelection('Select product, printing type and quantity to fetch automatic pricing.');
        calculate();
    });
    document.getElementById('proformaForm')?.addEventListener('reset', () => {
        clearFormDraft();
        setTimeout(() => {
            toggleFunctionFields();
            toggleOrderType(false);
            toggleLamination();
            calculate();
        }, 50);
    });
    if (window.jQuery) {
        $('#quotation_id').on('select2:select', loadQuotation);
        $('#quotation_id').on('select2:clear', function() {
            clearQuotationSelection(false);
        });
        $('#function_type_id').on('select2:select select2:clear', function() {
            toggleFunctionFields();
            syncBillingDetails();
        });
        $('#product_id').on('select2:select select2:clear', productChanged);
    }
    initSelect2(document);
    initProductMasterSelect();
    applyEditData();
    if (!editData) {
        restoreFormDraft();
    }
    toggleFunctionFields();
    toggleOrderType(false);
    if (editData) {
        setValue('printing_sub_type_id', editData.item_printing_sub_type_id || '');
        refreshSelect('printing_sub_type_id');
    }
    toggleLamination();
    updateSelectedProductStockInfo();
    renderProformaItems();
    calculate();
    schedulePriceLookup();
    showToastOnLoad();
    if (!editData) {
        document.getElementById('proformaForm')?.addEventListener('input', saveFormDraft);
        document.getElementById('proformaForm')?.addEventListener('change', saveFormDraft);
    }
    let advancePaymentConfirmed = false;
    let submittingToApi = false;

    function showPageLoading(title = 'Processing...', message = 'Please wait. Do not refresh or close this page.') {
        const overlay = document.getElementById('pageLoadingOverlay');
        if (!overlay) return;
        const titleEl = document.getElementById('pageLoadingTitle');
        const msgEl = document.getElementById('pageLoadingMessage');
        if (titleEl) titleEl.textContent = title;
        if (msgEl) msgEl.textContent = message;
        overlay.classList.add('show');
    }

    function hidePageLoading() {
        document.getElementById('pageLoadingOverlay')?.classList.remove('show');
    }

    function currentCashAmount() {
        return (document.getElementById('pay_cash')?.checked === true) ? (parseFloat(getValue('cash_amount')) || 0) :
            0;
    }

    function updateAdvancePaymentModalView() {
        const cash = currentCashAmount();
        const amountEl = document.getElementById('cashModalAmount') || document.getElementById('advanceModalAmount');
        if (amountEl) amountEl.textContent = rupee(cash);
        document.getElementById('cashDenominationSection')?.classList.remove('hide-field');
        updateCashDenominationTotal();
    }

    function updateCashDenominationTotal() {
        let total = 0;
        document.querySelectorAll('.cash-denom-count').forEach(input => {
            const count = Math.max(0, parseInt(input.value || '0', 10) || 0);
            const value = parseFloat(input.dataset.value || '0') || 0;
            const amount = count * value;
            total += amount;
            const row = input.closest('.denom-format-row') || input.closest('tr');
            const amountCell = row?.querySelector('.cash-denom-amount-input, .cash-denom-amount');
            if (amountCell) {
                if ('value' in amountCell) {
                    amountCell.value = rupee(amount);
                } else {
                    amountCell.textContent = rupee(amount);
                }
            }
        });
        const totalEl = document.getElementById('cashDenomTotal');
        if (totalEl) totalEl.textContent = rupee(total);
        return total;
    }

    function closeAdvancePaymentModal() {
        const modalEl = document.getElementById('advancePaymentModal');
        if (!modalEl) return;
        if (window.bootstrap && window.bootstrap.Modal) {
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            return;
        }
        modalEl.classList.remove('show', 'modal-fallback');
        modalEl.style.display = 'none';
        document.body.classList.remove('modal-open-fallback');
        document.querySelectorAll('.modal-backdrop-fallback').forEach(el => el.remove());
    }

    function openAdvancePaymentModal() {
        updateAdvancePaymentModalView();
        const modalEl = document.getElementById('advancePaymentModal');
        if (!modalEl) return;
        if (window.bootstrap && window.bootstrap.Modal) {
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl, {
                backdrop: 'static',
                keyboard: false
            });
            modal.show();
            return;
        }
        modalEl.style.display = 'block';
        modalEl.classList.add('show', 'modal-fallback');
        document.body.classList.add('modal-open-fallback');
        if (!document.querySelector('.modal-backdrop-fallback')) {
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop-fallback';
            document.body.appendChild(backdrop);
        }
    }

    document.querySelectorAll('.cash-denom-count').forEach(input => {
        input.addEventListener('input', () => {
            advancePaymentConfirmed = false;
            updateCashDenominationTotal();
        });
    });

    function syncPaymentModeUI(openCashModal = false) {
        const cashCheck = document.getElementById('pay_cash');
        const upiCheck = document.getElementById('pay_upi');
        const cashChecked = cashCheck?.checked === true;
        const upiChecked = upiCheck?.checked === true;

        document.querySelectorAll('[data-payment-card]').forEach(card => {
            const mode = card.dataset.paymentCard || '';
            const active = mode === 'cash' ? cashChecked : upiChecked;
            card.classList.toggle('active', active);
        });

        document.querySelectorAll('.payment-cash-wrap').forEach(el => el.classList.toggle('hide-field', !cashChecked));
        document.querySelectorAll('.payment-upi-wrap').forEach(el => el.classList.toggle('hide-field', !upiChecked));

        if (!cashChecked) {
            setValue('cash_amount', '0');
            setValue('cash_reference', '');
        }
        if (!upiChecked) {
            setValue('upi_amount', '0');
            setValue('upi_reference', '');
        }

        calculate();
        if (openCashModal && cashChecked) {
            maybeOpenAdvanceModalFromPaymentInput(true);
        }
    }

    function setPaymentMode(mode, openCashModal = true, showAmountReminder = true) {
        const check = document.querySelector('.payment-mode-check[data-mode="' + mode + '"]');
        if (check) {
            check.checked = !check.checked;
        }
        advancePaymentConfirmed = false;
        syncPaymentModeUI(false);
        saveFormDraft();

        if (openCashModal && mode === 'cash' && document.getElementById('pay_cash')?.checked) {
            window.setTimeout(() => maybeOpenAdvanceModalFromPaymentInput(showAmountReminder), 80);
        }
    }

    /* Cash and UPI are real checkboxes now, so both can be selected together. */
    document.querySelectorAll('.payment-check-card').forEach(card => {
        card.addEventListener('click', function(event) {
            if (event.target && event.target.classList.contains('payment-mode-check')) return;
            event.preventDefault();
            const mode = this.dataset.paymentCard || this.querySelector('.payment-mode-check')?.dataset
                .mode || 'cash';
            setPaymentMode(mode, false, true);
        });
    });

    document.querySelectorAll('.payment-mode-check').forEach(check => {
        check.addEventListener('change', function() {
            advancePaymentConfirmed = false;
            syncPaymentModeUI(false);
        });
    });

    ['cash_amount', 'upi_amount', 'cash_reference', 'upi_reference'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', () => {
            advancePaymentConfirmed = false;
            calculate();
        });
    });

    /* Do not open Cash Denomination modal automatically when cash amount changes.
       Modal must open only from the Cash Denomination button. */
    document.getElementById('cash_amount')?.addEventListener('change', () => {
        advancePaymentConfirmed = false;
        calculate();
    });

    document.getElementById('openCashDenominationBtn')?.addEventListener('click', function() {
        maybeOpenAdvanceModalFromPaymentInput(true);
    });

    syncPaymentModeUI(false);

    document.querySelectorAll('#advancePaymentModal [data-bs-dismiss="modal"]').forEach(btn => {
        btn.addEventListener('click', closeAdvancePaymentModal);
    });

    function maybeOpenAdvanceModalFromPaymentInput(showAmountReminder = false) {
        const cash = currentCashAmount();
        const cashChecked = document.getElementById('pay_cash')?.checked === true;

        if (!cashChecked || advancePaymentConfirmed) {
            return;
        }

        if (cash > 0) {
            openAdvancePaymentModal();
            return;
        }

        if (showAmountReminder) {
            showActionToast('Enter the cash amount first, then add the cash denomination count.', 'danger',
                'Cash Denomination');
        }
    }

    document.getElementById('advancePaymentContinueBtn')?.addEventListener('click', function(event) {
        event.preventDefault();
        event.stopPropagation();
        const cash = currentCashAmount();

        if (cash > 0) {
            const denomTotal = updateCashDenominationTotal();
            if (Math.abs(denomTotal - cash) > 0.009) {
                showActionToast('Cash denomination total must match the cash amount. Cash: ' + rupee(cash) +
                    ', Denomination: ' + rupee(denomTotal), 'danger', 'Payment Check');
                return;
            }
        }

        closeAdvancePaymentModal();

        advancePaymentConfirmed = true;
        showActionToast('Cash denomination saved. Now click Create Proforma Bill to save the proforma.',
            'success', 'Cash Denomination');
    });

    window.addEventListener('beforeunload', function() {
        if (submittingToApi) {
            showPageLoading('Loading...', 'Opening the updated page.');
        }
    });

    document.getElementById('proformaForm')?.addEventListener('submit', function(event) {
        event.preventDefault();

        const form = this;

        /*
         * Multiple-product safety:
         * If the user filled the current product fields but forgot to press Add Product,
         * add/update that product automatically before submitting.
         */
        syncCurrentProductRequiredState();

        const submitOrderType = getValue('order_type') || 'readymade';
        const isPrintingOnlySubmit = submitOrderType === 'printing_only';
        const currentProductIdForSubmit = String(getValue('product_id') || '').trim();
        if (!isPrintingOnlySubmit && currentProductIdForSubmit) {
            const productError = validateCurrentProductForAdd();
            if (productError) {
                showActionToast(productError, 'danger', 'Product Check');
                return;
            }
            const currentItem = currentProductItem();
            if (editingProductIndex >= 0) {
                proformaItems[editingProductIndex] = currentItem;
                editingProductIndex = -1;
            } else {
                if ((getValue('order_type') || 'readymade') === 'customized') {
                    proformaItems.push(currentItem);
                } else {
                    const existingIndex = proformaItems.findIndex(row =>
                        String(row.product_id || '') === String(currentItem.product_id || '')
                    );
                    if (existingIndex >= 0) {
                        proformaItems[existingIndex] = currentItem;
                    } else {
                        proformaItems.push(currentItem);
                    }
                }
            }
            renderProformaItems();
        }

        if (!isPrintingOnlySubmit && !proformaItems.length) {
            showActionToast('Please add at least one product before creating the Proforma.', 'danger',
                'Product Check');
            return;
        }

        /*
         * Product rows are already validated when Add Product is clicked.
         * After a row exists, do not force the empty next-product form.
         */
        syncCurrentProductRequiredState();

        if (!String(getValue('delivery_date') || '').trim()) {
            showActionToast('Please select the Delivery Date.', 'danger', 'Delivery Date');
            const target = document.getElementById('delivery_date');
            target?.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            setTimeout(() => target?.focus(), 250);
            return;
        }

        if (['readymade', 'printing_only'].includes(getValue('order_type') || 'readymade')) {
            if (!String(getValue('printing_type_id') || '').trim()) {
                showActionToast(
                    'Please select the common Printing Type. It applies to all added Readymade products.',
                    'danger',
                    'Printing Check'
                );
                const target = document.getElementById('printing_type_id');
                target?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
                setTimeout(() => target?.focus(), 250);
                return;
            }

            if (isScreenPrintingSelected() && !String(getValue('printing_sub_type_id') || '').trim()) {
                showActionToast(
                    'Please select Screen Print Sub-Type. It applies to all added products.',
                    'danger',
                    'Printing Check'
                );
                const target = document.getElementById('printing_sub_type_id');
                target?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
                setTimeout(() => target?.focus(), 250);
                return;
            }
        }

        if (isPrintingOnlySubmit && (parseFloat(getValue('printing_charge')) || 0) <= 0) {
            showActionToast('Please enter the Printing Charge for this Printing Only order.', 'danger', 'Printing Charge');
            const target = document.getElementById('printing_charge');
            target?.scrollIntoView({behavior:'smooth', block:'center'});
            setTimeout(() => target?.focus(), 250);
            return;
        }

        syncItemsJson();
        const btn = document.getElementById('createProformaBtn');
        const oldText = btn ? btn.textContent : '';
        const paymentAmounts = calculate();
        const advance = paymentAmounts.advance;
        const tenderedAmount = paymentAmounts.tenderedAmount;
        const returnAmount = paymentAmounts.returnAmount;
        const cashAmount = currentCashAmount();
        const upiAmount = (document.getElementById('pay_upi')?.checked === true) ? (parseFloat(getValue(
            'upi_amount')) || 0) : 0;
        const payMode = getValue('payment_mode');

        const isEditSubmit = !!editData;

        // Payment denomination is mandatory only while creating a new proforma.
        // In edit mode, existing advance should not block updating customer/order details.
        if (!isEditSubmit && !['cash', 'upi', 'split'].includes(payMode)) {
            showActionToast('Advance payment is compulsory. Please select Cash, UPI, or both.', 'danger',
                'Advance Payment');
            document.getElementById('advancePaymentMethodSection')?.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            return;
        }

        if (!isEditSubmit && advance <= 0) {
            showActionToast(
                'Advance payment is compulsory. Enter a Cash or UPI advance amount greater than zero.',
                'danger', 'Advance Payment');
            const target = document.getElementById('pay_cash')?.checked ?
                document.getElementById('cash_amount') :
                document.getElementById('upi_amount');
            target?.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            setTimeout(() => target?.focus(), 250);
            return;
        }

        if (!isEditSubmit && document.getElementById('pay_cash')?.checked && cashAmount > 0 && !
            advancePaymentConfirmed) {
            const denomTotal = updateCashDenominationTotal();
            if (Math.abs(denomTotal - cashAmount) > 0.009) {
                showActionToast(
                    'Cash denomination is not saved. Click Cash Denomination, enter counts and save it before creating the proforma.',
                    'danger', 'Cash Denomination');
                return;
            }
            advancePaymentConfirmed = true;
        }

        advancePaymentConfirmed = false;
        submittingToApi = true;
        showPageLoading(editData ? 'Updating Proforma...' : 'Creating Proforma...', editData ?
            'Please wait. Saving updated proforma details.' :
            'Please wait. Job card and tracking stages are being prepared.');

        if (btn) {
            btn.disabled = true;
            btn.textContent = editData ? 'Updating...' : 'Creating...';
        }

        fetch('api/create_proforma.php', {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin'
            })
            .then(async response => {
                const raw = await response.text();
                let data = null;

                try {
                    data = JSON.parse(raw);
                } catch (parseError) {
                    /*
                     * Do not hide PHP/server errors behind a generic
                     * "Request failed". Convert unexpected HTML/text into a
                     * readable error so the actual issue can be fixed quickly.
                     */
                    const readable = String(raw || '')
                        .replace(/<script[\s\S]*?<\/script>/gi, ' ')
                        .replace(/<style[\s\S]*?<\/style>/gi, ' ')
                        .replace(/<[^>]+>/g, ' ')
                        .replace(/&nbsp;/gi, ' ')
                        .replace(/&quot;/gi, '"')
                        .replace(/&#039;/gi, "'")
                        .replace(/&lt;/gi, '<')
                        .replace(/&gt;/gi, '>')
                        .replace(/&amp;/gi, '&')
                        .replace(/\s+/g, ' ')
                        .trim();

                    throw new Error(
                        readable ?
                        readable.slice(0, 700) :
                        ('Server returned an invalid response (HTTP ' + response.status + ').')
                    );
                }

                if (!response.ok && !data?.status) {
                    throw new Error(data?.message || ('Server error HTTP ' + response.status + '.'));
                }

                return data;
            })
            .then(async data => {
                if (data.status) {
                    const savedProformaId = parseInt(data.proforma_id || data.id || 0, 10);

                    /*
                     * WhatsApp sending is handled by api/create_proforma.php.
                     * For a NEW Proforma the API now sends:
                     * 1. proforma_created
                     * 2. advance payment template for the payment row(s)
                     * Edit mode never repeats these creation notifications.
                     */

                    let toastMessage = data.message || (editData ?
                        'Proforma bill updated successfully.' :
                        'Proforma bill created successfully.');
                    if (data.proforma_no) {
                        toastMessage += '<br>Proforma: ' + data.proforma_no;
                    }
                    if (data.job_card_no) {
                        toastMessage += '<br>Job Card: ' + data.job_card_no;
                    }
                    if (!isEditSubmit && returnAmount > 0.009) {
                        toastMessage += '<br>Customer Paid: ' + rupee(tenderedAmount) +
                            '<br>Return Amount: ' + rupee(returnAmount);
                    }

                    if (!isEditSubmit) {
                        if (data.proforma_whatsapp_sent === true || data.whatsapp_sent === true) {
                            toastMessage += '<br>Proforma WhatsApp: Sent.';
                        } else if (data.proforma_whatsapp_sent === false || data.whatsapp_sent === false) {
                            toastMessage += '<br>Proforma WhatsApp failed: ' +
                                (data.proforma_whatsapp?.message ||
                                    data.whatsapp_error ||
                                    data.whatsapp?.message ||
                                    'Please retry from Proforma Bills.');
                        }

                        if (data.advance_payment_whatsapp_attempted === true) {
                            if (data.advance_payment_whatsapp_sent === true) {
                                toastMessage += '<br>Advance Payment WhatsApp: Sent.';
                            } else if (data.advance_payment_whatsapp_sent === false) {
                                toastMessage += '<br>Advance Payment WhatsApp failed: ' +
                                    (data.advance_payment_whatsapp_error ||
                                        'Use Retry WhatsApp in the Proforma Payment page.');
                            }
                        }
                    } else if (data.whatsapp_mode === 'manual') {
                        toastMessage += '<br>WhatsApp: Manual window opened.';
                    }

                    clearFormDraft();

                    const proformaWaFailed = !isEditSubmit &&
                        (data.proforma_whatsapp_sent === false || data.whatsapp_sent === false);
                    const advanceWaFailed = !isEditSubmit &&
                        data.advance_payment_whatsapp_attempted === true &&
                        data.advance_payment_whatsapp_sent === false;
                    const toastType = (proformaWaFailed || advanceWaFailed) ? 'warning' : 'success';
                    const toastTitle = toastType === 'warning'
                        ? 'Proforma Saved — WhatsApp Attention'
                        : 'Success';

                    showActionToast(toastMessage, toastType, toastTitle);

                    if (data.open_whatsapp_url) {
                        window.open(data.open_whatsapp_url, '_blank');
                    }

                    setTimeout(() => {
                        window.location.href = data.redirect_url || 'proforma_bills.php';
                    }, 1200);
                } else {
                    showActionToast(data.message || 'Proforma bill creation failed.', 'danger',
                        'Failed');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = oldText || (editData ? 'Update Proforma Bill' :
                            'Create Proforma Bill');
                    }
                    submittingToApi = false;
                    hidePageLoading();
                }
            })
            .catch(error => {
                const message = String(error?.message || 'Request failed. Please try again.');
                showActionToast(message, 'danger', 'Failed');
                console.error('Create/Edit Proforma request failed:', error);
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = oldText || (editData ? 'Update Proforma Bill' :
                        'Create Proforma Bill');
                }
                submittingToApi = false;
                hidePageLoading();
            });
    });
    </script>
</body>

</html>