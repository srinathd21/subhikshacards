<?php


require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'quotations.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['quotations_csrf'])) {
    $_SESSION['quotations_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['quotations_csrf'];
$message = '';
$messageType = 'success';

function qtTableExists(mysqli $conn, string $table): bool
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

function qtPost(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function qtInt($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function qtFloat($value): float
{
    return (float)str_replace(',', '', (string)$value);
}

function qtRedirect(string $query = ''): void
{
    header('Location: quotations.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function qtCsrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['quotations_csrf']) ||
        !hash_equals($_SESSION['quotations_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function qtNextNo(mysqli $conn): string
{
    $prefix = 'SC-QTN';
    $datePart = date('ymd');
    $like = $prefix . '-' . $datePart . '-%';

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM quotations WHERE quotation_no LIKE ?");
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

function qtDefaultStatusId(mysqli $conn): ?int
{
    try {
        if (!qtTableExists($conn, 'quotation_statuses')) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM quotation_statuses
            WHERE status_key IN ('draft', 'sent')
            ORDER BY FIELD(status_key, 'draft', 'sent'), id ASC
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function qtCancelledStatusId(mysqli $conn): ?int
{
    try {
        if (!qtTableExists($conn, 'quotation_statuses')) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM quotation_statuses
            WHERE status_key IN ('cancelled', 'rejected')
            ORDER BY FIELD(status_key, 'cancelled', 'rejected'), id ASC
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}


function qtFunctionTypeId(mysqli $conn, string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    if (ctype_digit($value)) {
        return (int)$value;
    }

    if (!qtTableExists($conn, 'function_types')) {
        throw new RuntimeException('function_types table is missing.');
    }

    $functionName = mb_substr($value, 0, 150);
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


function qtCustomerId(mysqli $conn, string $customerName, string $mobile, string $address = ''): ?int
{
    $customerName = trim($customerName);
    $mobile = trim($mobile);
    $address = trim($address);

    if ($customerName === '' || $mobile === '' || !qtTableExists($conn, 'customers')) {
        return null;
    }

    try {
        $stmt = $conn->prepare("SELECT id FROM customers WHERE mobile = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('s', $mobile);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $customerId = (int)$row['id'];
            $userId = (int)($_SESSION['user_id'] ?? 0);

            $stmt = $conn->prepare("
                UPDATE customers
                SET customer_name = ?,
                    address = IF(? = '', address, ?),
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('sssii', $customerName, $address, $address, $userId, $customerId);
            $stmt->execute();
            $stmt->close();

            return $customerId;
        }

        $createdBy = (int)($_SESSION['user_id'] ?? 0);

        $stmt = $conn->prepare("
            INSERT INTO customers
                (customer_name, mobile, address, is_active, created_by, created_at)
            VALUES
                (?, ?, ?, 1, ?, NOW())
        ");
        $stmt->bind_param('sssi', $customerName, $mobile, $address, $createdBy);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        return $id;
    } catch (Throwable $e) {
        return null;
    }
}

function qtLog(mysqli $conn, string $actionKey, int $recordId, string $description): void
{
    try {
        if (!qtTableExists($conn, 'activity_logs')) {
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $moduleName = 'Quotations';
        $tableName = 'quotations';
        $actionTypeId = null;

        if (qtTableExists($conn, 'activity_action_types')) {
            $stmt = $conn->prepare("SELECT id FROM activity_action_types WHERE action_key = ? LIMIT 1");
            $stmt->bind_param('s', $actionKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $actionTypeId = (int)$row['id'];
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO activity_logs
                (user_id, role_id, action_type_id, action_key, module_name, table_name, record_id, description, ip_address, user_agent, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            'iiisssisss',
            $userId,
            $roleId,
            $actionTypeId,
            $actionKey,
            $moduleName,
            $tableName,
            $recordId,
            $description,
            $ip,
            $ua
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qtCsrf();

    try {
        $action = qtPost('action');

        if ($action === 'save_record') {
            if (!qtTableExists($conn, 'quotations')) {
                throw new RuntimeException('quotations table is missing.');
            }

            $id = qtInt($_POST['id'] ?? 0);
            $enquiryId = qtInt($_POST['enquiry_id'] ?? 0);
            $functionTypeRaw = qtPost('function_type_id');
            $functionTypeId = qtFunctionTypeId($conn, $functionTypeRaw);
            $customerName = qtPost('customer_name');
            $mobile = qtPost('mobile');
            $address = qtPost('address');
            $brideName = qtPost('bride_name');
            $groomName = qtPost('groom_name');
            $venue = qtPost('venue');
            $functionDate = qtPost('function_date');
            $functionTime = qtPost('function_time');
            $quotationStatusId = qtInt($_POST['quotation_status_id'] ?? 0);
            $totalQty = qtFloat($_POST['total_qty'] ?? 0);
            $subTotal = qtFloat($_POST['sub_total'] ?? 0);
            $discountAmount = qtFloat($_POST['discount_amount'] ?? 0);
            $finalAmount = qtFloat($_POST['final_amount'] ?? 0);
            $remarks = qtPost('remarks');

            if ($customerName === '' || $mobile === '') {
                throw new RuntimeException('Customer name and mobile number are required.');
            }

            if ($functionTypeId <= 0) {
                throw new RuntimeException('Function / Product type is required.');
            }

            if ($totalQty <= 0) {
                throw new RuntimeException('Total quantity must be greater than zero.');
            }

            if ($finalAmount <= 0) {
                throw new RuntimeException('Final amount must be greater than zero.');
            }

            if ($quotationStatusId <= 0) {
                $defaultStatusId = qtDefaultStatusId($conn);
                if (!$defaultStatusId) {
                    throw new RuntimeException('Quotation status master is missing.');
                }
                $quotationStatusId = $defaultStatusId;
            }

            $enquiryIdValue = $enquiryId > 0 ? $enquiryId : null;
            $functionTypeIdValue = $functionTypeId > 0 ? $functionTypeId : null;
            $quotationStatusIdValue = $quotationStatusId > 0 ? $quotationStatusId : null;
            $functionDateValue = $functionDate !== '' ? $functionDate : null;
            $functionTimeValue = $functionTime !== '' ? $functionTime : null;
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $customerId = qtCustomerId($conn, $customerName, $mobile, $address);

            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE quotations
                    SET enquiry_id = ?,
                        customer_id = ?,
                        function_type_id = ?,
                        customer_name = ?,
                        mobile = ?,
                        address = ?,
                        bride_name = ?,
                        groom_name = ?,
                        venue = ?,
                        function_date = ?,
                        function_time = ?,
                        quotation_status_id = ?,
                        total_qty = ?,
                        sub_total = ?,
                        discount_amount = ?,
                        final_amount = ?,
                        remarks = ?,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param(
                    'iiissssssssiddddssi',
                    $enquiryIdValue,
                    $customerId,
                    $functionTypeIdValue,
                    $customerName,
                    $mobile,
                    $address,
                    $brideName,
                    $groomName,
                    $venue,
                    $functionDateValue,
                    $functionTimeValue,
                    $quotationStatusIdValue,
                    $totalQty,
                    $subTotal,
                    $discountAmount,
                    $finalAmount,
                    $remarks,
                    $userId,
                    $id
                );
                $stmt->execute();
                $stmt->close();

                if ($enquiryIdValue) {
                    $stmt = $conn->prepare("UPDATE enquiries SET converted_to_quotation = 1, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param('ii', $userId, $enquiryIdValue);
                    $stmt->execute();
                    $stmt->close();
                }

                qtLog($conn, 'update', $id, 'Quotation updated.');
                qtRedirect('msg=updated');
            }

            $quotationNo = qtNextNo($conn);

            $stmt = $conn->prepare("
                INSERT INTO quotations
                    (
                        quotation_no,
                        enquiry_id,
                        customer_id,
                        function_type_id,
                        customer_name,
                        mobile,
                        address,
                        bride_name,
                        groom_name,
                        venue,
                        function_date,
                        function_time,
                        quotation_status_id,
                        total_qty,
                        sub_total,
                        discount_amount,
                        final_amount,
                        remarks,
                        created_by,
                        created_at,
                        updated_at
                    )
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->bind_param(
                'siiissssssssiddddsi',
                $quotationNo,
                $enquiryIdValue,
                $customerId,
                $functionTypeIdValue,
                $customerName,
                $mobile,
                $address,
                $brideName,
                $groomName,
                $venue,
                $functionDateValue,
                $functionTimeValue,
                $quotationStatusIdValue,
                $totalQty,
                $subTotal,
                $discountAmount,
                $finalAmount,
                $remarks,
                $userId
            );
            $stmt->execute();
            $newId = (int)$stmt->insert_id;
            $stmt->close();

            if ($enquiryIdValue) {
                $stmt = $conn->prepare("UPDATE enquiries SET converted_to_quotation = 1, updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('ii', $userId, $enquiryIdValue);
                $stmt->execute();
                $stmt->close();
            }

            qtLog($conn, 'create_quotation', $newId, 'Quotation created: ' . $quotationNo);
            qtRedirect('msg=created');
        }

        if ($action === 'cancel_record') {
            $id = qtInt($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid quotation.');
            }

            $cancelStatusId = qtCancelledStatusId($conn);
            $userId = (int)($_SESSION['user_id'] ?? 0);

            if ($cancelStatusId) {
                $stmt = $conn->prepare("
                    UPDATE quotations
                    SET quotation_status_id = ?,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('iii', $cancelStatusId, $userId, $id);
                $stmt->execute();
                $stmt->close();
            }

            qtLog($conn, 'delete', $id, 'Quotation cancelled.');
            qtRedirect('msg=cancelled');
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') {
    $message = 'Quotation created successfully.';
} elseif ($msg === 'updated') {
    $message = 'Quotation updated successfully.';
} elseif ($msg === 'cancelled') {
    $message = 'Quotation cancelled successfully.';
}

$functionTypes = [];
try {
    if (qtTableExists($conn, 'function_types')) {
        $res = $conn->query("
            SELECT id, function_name, field_group
            FROM function_types
            WHERE is_active = 1
            ORDER BY sort_order ASC, function_name ASC
        ");
        while ($row = $res->fetch_assoc()) {
            $functionTypes[] = $row;
        }
        $res->free();
    }
} catch (Throwable $e) {
}

$statuses = [];
try {
    if (qtTableExists($conn, 'quotation_statuses')) {
        $res = $conn->query("
            SELECT id, status_name, status_key, color_code
            FROM quotation_statuses
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        while ($row = $res->fetch_assoc()) {
            $statuses[] = $row;
        }
        $res->free();
    }
} catch (Throwable $e) {
}

$enquiries = [];
try {
    if (qtTableExists($conn, 'enquiries')) {
        $res = $conn->query("
            SELECT
                e.id,
                e.enquiry_no,
                e.customer_id,
                e.customer_name,
                e.mobile,
                e.address,
                e.function_type_id,
                e.function_date,
                e.venue,
                ft.function_name,
                ft.field_group
            FROM enquiries e
            LEFT JOIN function_types ft ON ft.id = e.function_type_id
            WHERE COALESCE(e.converted_to_order, 0) = 0
            ORDER BY e.id DESC
            LIMIT 500
        ");
        while ($row = $res->fetch_assoc()) {
            $enquiries[] = $row;
        }
        $res->free();
    }
} catch (Throwable $e) {
}

$rows = [];
if (qtTableExists($conn, 'quotations')) {
    try {
        $res = $conn->query("
            SELECT
                q.*,
                ft.function_name,
                ft.field_group,
                qs.status_name,
                qs.status_key,
                qs.color_code,
                e.enquiry_no
            FROM quotations q
            LEFT JOIN function_types ft ON ft.id = q.function_type_id
            LEFT JOIN quotation_statuses qs ON qs.id = q.quotation_status_id
            LEFT JOIN enquiries e ON e.id = q.enquiry_id
            ORDER BY q.id DESC
            LIMIT 300
        ");
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    } catch (Throwable $e) {
        $message = 'List query error: ' . $e->getMessage();
        $messageType = 'danger';
        $rows = [];
    }
}

$totalRows = count($rows);
$draftRows = 0;
$acceptedRows = 0;
$totalValue = 0;

foreach ($rows as $row) {
    $statusKey = strtolower((string)($row['status_key'] ?? ''));
    if (in_array($statusKey, ['draft', 'sent', 'revised'], true)) {
        $draftRows++;
    }
    if (in_array($statusKey, ['accepted', 'converted_to_proforma_bill'], true)) {
        $acceptedRows++;
    }
    $totalValue += (float)($row['final_amount'] ?? 0);
}

function qtDate($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime($value)) : '-';
}

function qtMoney($value): string
{
    return '₹' . number_format((float)$value, 2);
}

$defaultStatusId = $statuses[0]['id'] ?? '';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Quotations - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
    .module-page .page-head {
        padding: 24px 28px;
        margin-bottom: 18px;
    }

    .module-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main);
    }

    .module-card {
        padding: 24px;
    }

    .module-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
        margin: 0;
    }

    .stat-card {
        padding: 18px;
        min-height: 112px;
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto;
    }

    .stat-card span {
        display: block;
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 900;
        text-transform: uppercase;
    }

    .stat-card strong {
        font-size: 24px;
        font-weight: 900;
        color: var(--text-main);
    }

    .status-pill {
        font-size: 11px;
        font-weight: 900;
        border-radius: 999px;
        padding: 5px 9px;
        background: color-mix(in srgb, var(--info-color) 14%, transparent);
        color: var(--info-color);
        display: inline-flex;
    }

    .status-pill.cancel {
        color: var(--danger-color);
        background: color-mix(in srgb, var(--danger-color) 14%, transparent);
    }

    .form-control,
    .form-select {
        border-radius: 14px;
        min-height: 46px;
    }

    .modal-content {
        border: 0;
        border-radius: 22px;
        background: var(--card-bg);
        color: var(--text-main);
    }

    .modal-header,
    .modal-footer {
        border-color: var(--border-soft);
    }

    .amount-box {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 14px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
    }

    .amount-box small {
        display: block;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
    }

    .amount-box strong {
        display: block;
        margin-top: 4px;
        color: var(--text-main);
        font-size: 18px;
        font-weight: 900;
    }

    .mobile-cards {
        display: none;
    }

    .mobile-card {
        border: 1px solid var(--border-soft);
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        border-radius: 18px;
        padding: 16px;
        margin-bottom: 12px;
    }

    .mobile-card-title {
        font-size: 16px;
        font-weight: 900;
        color: var(--text-main);
    }

    .mobile-card-subtitle {
        display: block;
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
        margin-top: 4px;
        word-break: break-word;
    }

    .mobile-card-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }


    .select2-container {
        width: 100% !important;
    }

    .select2-container--bootstrap-5 .select2-selection {
        min-height: 46px !important;
        border-radius: 14px !important;
        border-color: var(--border-soft, #dbe3ef) !important;
        background: var(--card-bg, #ffffff) !important;
        color: var(--text-main, #0f172a) !important;
        display: flex !important;
        align-items: center !important;
        box-shadow: none !important;
    }

    .select2-container--bootstrap-5.select2-container--focus .select2-selection,
    .select2-container--bootstrap-5.select2-container--open .select2-selection {
        border-color: var(--brand-1, #f59e0b) !important;
        box-shadow: 0 0 0 .20rem color-mix(in srgb, var(--brand-1, #f59e0b) 18%, transparent) !important;
    }

    .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
        font-weight: 700 !important;
        color: var(--text-main, #0f172a) !important;
        padding-left: 10px !important;
    }

    .select2-container--bootstrap-5 .select2-dropdown {
        border-radius: 14px !important;
        border-color: var(--border-soft, #dbe3ef) !important;
        background: var(--card-bg, #ffffff) !important;
        color: var(--text-main, #0f172a) !important;
        overflow: hidden !important;
        z-index: 9999 !important;
    }

    .select2-container--bootstrap-5 .select2-search__field {
        border-radius: 10px !important;
        min-height: 38px !important;
        color: var(--text-main, #0f172a) !important;
        background: var(--card-bg, #ffffff) !important;
    }

    .select2-container--bootstrap-5 .select2-results__option {
        font-weight: 700 !important;
        padding: 9px 12px !important;
    }

    .select2-container--bootstrap-5 .select2-results__option--highlighted {
        background: var(--brand-1, #f59e0b) !important;
        color: var(--brand-text, #ffffff) !important;
    }

    @media(max-width:767.98px) {
        .module-page .page-head {
            padding: 18px;
            border-radius: 18px;
        }

        .module-page .page-head h1 {
            font-size: 24px;
        }

        .module-page .page-head .btn {
            width: 100%;
        }

        .module-card {
            padding: 16px;
            border-radius: 18px;
        }

        .desktop-table {
            display: none !important;
        }

        .mobile-cards {
            display: block;
        }

        .mobile-card-actions .btn,
        .mobile-card-actions form {
            flex: 1 1 auto;
        }

        .mobile-card-actions .btn {
            width: 100%;
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

            <section class="page-section module-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Quotations</h1>
                            <p class="text-muted-custom mb-0">Create quotation from enquiry and convert to proforma
                                bill.</p>
                        </div>

                        <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" id="newRecordBtn"
                            data-bs-toggle="modal" data-bs-target="#recordModal">
                            Create New
                        </button>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                <div class="alert alert-<?= e($messageType) ?> rounded-4 fw-bold">
                    <?= e($message) ?>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)">
                                <i data-lucide="file-text"></i>
                            </div>
                            <div>
                                <span>Total Quotations</span>
                                <strong><?= number_format($totalRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                <i data-lucide="clock"></i>
                            </div>
                            <div>
                                <span>Draft / Sent</span>
                                <strong><?= number_format($draftRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                <i data-lucide="check-circle-2"></i>
                            </div>
                            <div>
                                <span>Accepted</span>
                                <strong><?= number_format($acceptedRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#6d28d9,#a855f7)">
                                <i data-lucide="indian-rupee"></i>
                            </div>
                            <div>
                                <span>Total Value</span>
                                <strong><?= e(qtMoney($totalValue)) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Quotations List</h2>
                            <p class="text-muted-custom mb-0">Correct DB flow: enquiry → quotation → proforma bill.</p>
                        </div>

                        <div style="max-width:340px;width:100%">
                            <input type="search" id="tableSearch" class="form-control" placeholder="Search...">
                        </div>
                    </div>

                    <div class="table-responsive desktop-table">
                        <table class="table-ui" id="dataTable">
                            <thead>
                                <tr>
                                    <th>Quotation No</th>
                                    <th>Customer</th>
                                    <th>Function Type</th>
                                    <th>Qty</th>
                                    <th>Final Amount</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted-custom py-4">
                                        No quotations found.
                                    </td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($rows as $row): ?>
                                <?php $cancelled = in_array(strtolower((string)($row['status_key'] ?? '')), ['cancelled', 'rejected'], true); ?>
                                <tr>
                                    <td>
                                        <strong><?= e($row['quotation_no']) ?></strong>
                                        <small
                                            class="d-block text-muted-custom"><?= e($row['enquiry_no'] ?? 'Direct') ?></small>
                                    </td>
                                    <td>
                                        <?= e($row['customer_name']) ?>
                                        <small class="d-block text-muted-custom"><?= e($row['mobile']) ?></small>
                                    </td>
                                    <td><?= e($row['function_name'] ?? '-') ?></td>
                                    <td><?= e(number_format((float)$row['total_qty'], 2)) ?></td>
                                    <td><?= e(qtMoney($row['final_amount'])) ?></td>
                                    <td>
                                        <span class="status-pill <?= $cancelled ? 'cancel' : '' ?>">
                                            <?= e($row['status_name'] ?? '-') ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <button type="button"
                                            class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-record"
                                            data-bs-toggle="modal" data-bs-target="#recordModal"
                                            data-id="<?= e($row['id']) ?>"
                                            data-enquiry-id="<?= e($row['enquiry_id']) ?>"
                                            data-function-type-id="<?= e($row['function_type_id']) ?>"
                                            data-customer-name="<?= e($row['customer_name']) ?>"
                                            data-mobile="<?= e($row['mobile']) ?>"
                                            data-address="<?= e($row['address']) ?>"
                                            data-bride-name="<?= e($row['bride_name']) ?>"
                                            data-groom-name="<?= e($row['groom_name']) ?>"
                                            data-venue="<?= e($row['venue']) ?>"
                                            data-function-date="<?= e($row['function_date']) ?>"
                                            data-function-time="<?= e($row['function_time']) ?>"
                                            data-quotation-status-id="<?= e($row['quotation_status_id']) ?>"
                                            data-total-qty="<?= e($row['total_qty']) ?>"
                                            data-sub-total="<?= e($row['sub_total']) ?>"
                                            data-discount-amount="<?= e($row['discount_amount']) ?>"
                                            data-final-amount="<?= e($row['final_amount']) ?>"
                                            data-remarks="<?= e($row['remarks']) ?>">
                                            Edit
                                        </button>

                                        <?php if (!$cancelled): ?>
                                        <form method="post" class="d-inline"
                                            onsubmit="return confirm('Cancel this quotation?')">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="cancel_record">
                                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                            <button type="submit"
                                                class="btn btn-sm btn-outline-danger rounded-pill fw-bold">
                                                Cancel
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-cards" id="mobileCards">
                        <?php if (!$rows): ?>
                        <div class="mobile-card text-center text-muted-custom">No quotations found.</div>
                        <?php endif; ?>

                        <?php foreach ($rows as $row): ?>
                        <?php $cancelled = in_array(strtolower((string)($row['status_key'] ?? '')), ['cancelled', 'rejected'], true); ?>
                        <div class="mobile-card">
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <div class="mobile-card-title"><?= e($row['quotation_no']) ?></div>
                                    <span class="mobile-card-subtitle"><?= e($row['customer_name']) ?> ·
                                        <?= e($row['mobile']) ?></span>
                                    <span class="mobile-card-subtitle">Type:
                                        <?= e($row['function_name'] ?? '-') ?></span>
                                    <span class="mobile-card-subtitle">Amount:
                                        <?= e(qtMoney($row['final_amount'])) ?></span>
                                </div>

                                <span class="status-pill <?= $cancelled ? 'cancel' : '' ?>">
                                    <?= e($row['status_name'] ?? '-') ?>
                                </span>
                            </div>

                            <div class="mobile-card-actions">
                                <button type="button"
                                    class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-record"
                                    data-bs-toggle="modal" data-bs-target="#recordModal" data-id="<?= e($row['id']) ?>"
                                    data-enquiry-id="<?= e($row['enquiry_id']) ?>"
                                    data-function-type-id="<?= e($row['function_type_id']) ?>"
                                    data-customer-name="<?= e($row['customer_name']) ?>"
                                    data-mobile="<?= e($row['mobile']) ?>" data-address="<?= e($row['address']) ?>"
                                    data-bride-name="<?= e($row['bride_name']) ?>"
                                    data-groom-name="<?= e($row['groom_name']) ?>" data-venue="<?= e($row['venue']) ?>"
                                    data-function-date="<?= e($row['function_date']) ?>"
                                    data-function-time="<?= e($row['function_time']) ?>"
                                    data-quotation-status-id="<?= e($row['quotation_status_id']) ?>"
                                    data-total-qty="<?= e($row['total_qty']) ?>"
                                    data-sub-total="<?= e($row['sub_total']) ?>"
                                    data-discount-amount="<?= e($row['discount_amount']) ?>"
                                    data-final-amount="<?= e($row['final_amount']) ?>"
                                    data-remarks="<?= e($row['remarks']) ?>">
                                    Edit
                                </button>

                                <?php if (!$cancelled): ?>
                                <form method="post" class="d-inline"
                                    onsubmit="return confirm('Cancel this quotation?')">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="cancel_record">
                                    <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill fw-bold">
                                        Cancel
                                    </button>
                                </form>
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

    <div class="modal fade" id="recordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save_record">
                <input type="hidden" name="id" id="id" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="recordModalTitle">Create Quotation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold">Enquiry Reference</label>
                            <select name="enquiry_id" id="enquiry_id" class="form-select select2-autotype"
                                data-placeholder="Search enquiry by no / customer / mobile">
                                <option value="">Direct Quotation</option>
                                <?php foreach ($enquiries as $enquiry): ?>
                                <option value="<?= e($enquiry['id']) ?>"
                                    data-customer-name="<?= e($enquiry['customer_name']) ?>"
                                    data-mobile="<?= e($enquiry['mobile']) ?>"
                                    data-address="<?= e($enquiry['address']) ?>"
                                    data-function-type-id="<?= e($enquiry['function_type_id']) ?>"
                                    data-function-date="<?= e($enquiry['function_date']) ?>"
                                    data-venue="<?= e($enquiry['venue']) ?>">
                                    <?= e($enquiry['enquiry_no']) ?> - <?= e($enquiry['customer_name']) ?> -
                                    <?= e($enquiry['mobile']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Function / Product Type *</label>
                            <select name="function_type_id" id="function_type_id" class="form-select select2-autotype"
                                required data-placeholder="Type or select function / product type" data-tags="true">
                                <option value="">Select Type</option>
                                <?php foreach ($functionTypes as $type): ?>
                                <option value="<?= e($type['id']) ?>"
                                    data-field-group="<?= e($type['field_group'] ?? 'other') ?>">
                                    <?= e($type['function_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Customer Name *</label>
                            <input type="text" name="customer_name" id="customer_name" class="form-control" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Mobile *</label>
                            <input type="text" name="mobile" id="mobile" class="form-control" required>
                        </div>

                        <div class="col-md-6 wedding-field">
                            <label class="form-label fw-bold">Bride Name</label>
                            <input type="text" name="bride_name" id="bride_name" class="form-control">
                        </div>

                        <div class="col-md-6 wedding-field">
                            <label class="form-label fw-bold">Groom Name</label>
                            <input type="text" name="groom_name" id="groom_name" class="form-control">
                        </div>

                        <div class="col-md-4 event-field wedding-field">
                            <label class="form-label fw-bold">Function Date</label>
                            <input type="date" name="function_date" id="function_date" class="form-control">
                        </div>

                        <div class="col-md-4 event-field wedding-field">
                            <label class="form-label fw-bold">Function Time</label>
                            <input type="time" name="function_time" id="function_time" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Quotation Status</label>
                            <select name="quotation_status_id" id="quotation_status_id"
                                class="form-select select2-autotype" data-placeholder="Search quotation status">
                                <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status['id']) ?>"><?= e($status['status_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 event-field wedding-field">
                            <label class="form-label fw-bold">Venue</label>
                            <textarea name="venue" id="venue" rows="2" class="form-control"></textarea>
                        </div>

                        <div class="col-12 business-field">
                            <label class="form-label fw-bold">Address</label>
                            <textarea name="address" id="address" rows="2" class="form-control"></textarea>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">Total Qty *</label>
                            <input type="number" step="0.01" name="total_qty" id="total_qty" class="form-control"
                                required value="1">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">Sub Total *</label>
                            <input type="number" step="0.01" name="sub_total" id="sub_total" class="form-control"
                                required value="0">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">Discount</label>
                            <input type="number" step="0.01" name="discount_amount" id="discount_amount"
                                class="form-control" value="0">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">Final Amount *</label>
                            <input type="number" step="0.01" name="final_amount" id="final_amount" class="form-control"
                                required value="0">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Remarks</label>
                            <textarea name="remarks" id="remarks" rows="3" class="form-control"></textarea>
                        </div>
                    </div>

                    <div class="row g-3 mt-2">
                        <div class="col-md-4">
                            <div class="amount-box">
                                <small>Sub Total</small>
                                <strong id="subTotalText">₹0.00</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="amount-box">
                                <small>Discount</small>
                                <strong id="discountText">₹0.00</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="amount-box">
                                <small>Final Amount</small>
                                <strong id="finalAmountText">₹0.00</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" id="recordSubmitBtn">
                        Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
    (function() {
        const title = document.getElementById('recordModalTitle');
        const submit = document.getElementById('recordSubmitBtn');
        const defaultStatusId = '<?= e($defaultStatusId) ?>';


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

                const $modal = $select.closest('.modal');
                const enableTags = String($select.data('tags')) === 'true';

                $select.select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    dropdownParent: $modal.length ? $modal : $(document.body),
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

        function refreshSelect2(id) {
            if (window.jQuery && $.fn.select2) {
                $('#' + id).trigger('change.select2');
            }
        }

        function set(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = value == null ? '' : value;
        }

        function money(value) {
            return '₹' + Number(value || 0).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function calculate() {
            const subTotal = parseFloat(document.getElementById('sub_total')?.value || 0);
            const discount = parseFloat(document.getElementById('discount_amount')?.value || 0);
            const finalAmount = Math.max(0, subTotal - discount);

            set('final_amount', finalAmount.toFixed(2));

            document.getElementById('subTotalText').textContent = money(subTotal);
            document.getElementById('discountText').textContent = money(discount);
            document.getElementById('finalAmountText').textContent = money(finalAmount);
        }

        function selectedFieldGroup() {
            const select = document.getElementById('function_type_id');
            const opt = select?.options[select.selectedIndex];
            return opt?.dataset.fieldGroup || 'other';
        }

        function toggleFunctionFields() {
            const group = selectedFieldGroup();

            document.querySelectorAll('.wedding-field').forEach(el => {
                el.style.display = group === 'wedding_reception' ? '' : 'none';
            });

            document.querySelectorAll('.event-field').forEach(el => {
                el.style.display = (group === 'event' || group === 'wedding_reception') ? '' : 'none';
            });

            document.querySelectorAll('.business-field').forEach(el => {
                el.style.display = (group === 'business_print' || group === 'other') ? '' : 'none';
            });
        }

        document.getElementById('newRecordBtn')?.addEventListener('click', function() {
            title.textContent = 'Create Quotation';
            submit.textContent = 'Save';

            set('id', '');
            set('enquiry_id', '');
            set('function_type_id', '');
            set('customer_name', '');
            set('mobile', '');
            set('address', '');
            set('bride_name', '');
            set('groom_name', '');
            set('venue', '');
            set('function_date', '');
            set('function_time', '');
            set('quotation_status_id', defaultStatusId);
            set('total_qty', '1');
            set('sub_total', '0');
            set('discount_amount', '0');
            set('final_amount', '0');
            set('remarks', '');

            refreshSelect2('enquiry_id');
            refreshSelect2('function_type_id');
            refreshSelect2('quotation_status_id');

            toggleFunctionFields();
            calculate();
        });

        document.getElementById('enquiry_id')?.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (!opt || !opt.value) return;

            set('customer_name', opt.dataset.customerName || '');
            set('mobile', opt.dataset.mobile || '');
            set('address', opt.dataset.address || '');
            set('function_type_id', opt.dataset.functionTypeId || '');
            set('function_date', opt.dataset.functionDate || '');
            set('venue', opt.dataset.venue || '');

            refreshSelect2('function_type_id');
            toggleFunctionFields();
        });

        document.getElementById('function_type_id')?.addEventListener('change', toggleFunctionFields);
        document.getElementById('sub_total')?.addEventListener('input', calculate);
        document.getElementById('discount_amount')?.addEventListener('input', calculate);

        document.querySelectorAll('.js-edit-record').forEach(function(btn) {
            btn.addEventListener('click', function() {
                title.textContent = 'Edit Quotation';
                submit.textContent = 'Update';

                set('id', btn.dataset.id || '');
                set('enquiry_id', btn.dataset.enquiryId || '');
                set('function_type_id', btn.dataset.functionTypeId || '');
                set('customer_name', btn.dataset.customerName || '');
                set('mobile', btn.dataset.mobile || '');
                set('address', btn.dataset.address || '');
                set('bride_name', btn.dataset.brideName || '');
                set('groom_name', btn.dataset.groomName || '');
                set('venue', btn.dataset.venue || '');
                set('function_date', btn.dataset.functionDate || '');
                set('function_time', btn.dataset.functionTime || '');
                set('quotation_status_id', btn.dataset.quotationStatusId || defaultStatusId);
                set('total_qty', btn.dataset.totalQty || '1');
                set('sub_total', btn.dataset.subTotal || '0');
                set('discount_amount', btn.dataset.discountAmount || '0');
                set('final_amount', btn.dataset.finalAmount || '0');
                set('remarks', btn.dataset.remarks || '');

                refreshSelect2('enquiry_id');
                refreshSelect2('function_type_id');
                refreshSelect2('quotation_status_id');

                toggleFunctionFields();
                calculate();
            });
        });

        document.getElementById('tableSearch')?.addEventListener('input', function() {
            const value = this.value.toLowerCase().trim();

            document.querySelectorAll('#dataTable tbody tr').forEach(function(row) {
                row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
            });

            document.querySelectorAll('#mobileCards .mobile-card').forEach(function(card) {
                card.style.display = card.textContent.toLowerCase().includes(value) ? '' : 'none';
            });
        });

        initPageSelect2(document);

        document.getElementById('recordModal')?.addEventListener('shown.bs.modal', function() {
            initPageSelect2(this);
        });

        toggleFunctionFields();
        calculate();

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    })();
    </script>
</body>

</html>