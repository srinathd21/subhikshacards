<?php

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'enquiries.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['enquiries_csrf'])) {
    $_SESSION['enquiries_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['enquiries_csrf'];
$message = '';
$messageType = 'success';

function enqTableExists(mysqli $conn, string $table): bool
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

function enqPost(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function enqInt($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function enqRedirect(string $query = ''): void
{
    header('Location: enquiries.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function enqCsrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['enquiries_csrf']) ||
        !hash_equals($_SESSION['enquiries_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function enqNextNo(mysqli $conn): string
{
    $prefix = 'SC-ENQ';
    $datePart = date('ymd');
    $like = $prefix . '-' . $datePart . '-%';

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM enquiries WHERE enquiry_no LIKE ?");
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

function enqGetDefaultStatusId(mysqli $conn): ?int
{
    try {
        $stmt = $conn->prepare("
            SELECT id
            FROM enquiry_statuses
            WHERE status_key = 'new'
            ORDER BY id ASC
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

function enqGetClosedStatusId(mysqli $conn): ?int
{
    try {
        $stmt = $conn->prepare("
            SELECT id
            FROM enquiry_statuses
            WHERE status_key IN ('cancelled', 'closed')
            ORDER BY FIELD(status_key, 'cancelled', 'closed'), id ASC
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


function enqFunctionTypeId(mysqli $conn, string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    if (ctype_digit($value)) {
        return (int)$value;
    }

    if (!enqTableExists($conn, 'function_types')) {
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


function enqCustomerId(mysqli $conn, string $customerName, string $mobile, string $address = ''): ?int
{
    $customerName = trim($customerName);
    $mobile = trim($mobile);
    $address = trim($address);

    if ($customerName === '' || $mobile === '' || !enqTableExists($conn, 'customers')) {
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

function enqLog(mysqli $conn, string $actionKey, int $recordId, string $description): void
{
    try {
        if (!enqTableExists($conn, 'activity_logs')) {
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $actionTypeId = null;
        if (enqTableExists($conn, 'activity_action_types')) {
            $stmt = $conn->prepare("SELECT id FROM activity_action_types WHERE action_key = ? LIMIT 1");
            $stmt->bind_param('s', $actionKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $actionTypeId = (int)$row['id'];
            }
        }

        $moduleName = 'Enquiries';
        $tableName = 'enquiries';

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
        // Ignore log failure; page action should not fail because of log.
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enqCsrf();

    try {
        $action = enqPost('action');

        if ($action === 'save_record') {
            if (!enqTableExists($conn, 'enquiries')) {
                throw new RuntimeException('enquiries table is missing.');
            }

            $id = enqInt($_POST['id'] ?? 0);
            $customerName = enqPost('customer_name');
            $mobile = enqPost('mobile');
            $functionTypeRaw = enqPost('function_type_id');
            $functionTypeId = enqFunctionTypeId($conn, $functionTypeRaw);
            $functionDate = enqPost('function_date');
            $venue = enqPost('venue');
            $address = enqPost('address');
            $enquirySource = enqPost('enquiry_source');
            $enquiryStatusId = enqInt($_POST['enquiry_status_id'] ?? 0);
            $assignedSalesUserId = enqInt($_POST['assigned_sales_user_id'] ?? 0);
            $nextCallbackAt = enqPost('next_callback_at');
            $remarks = enqPost('remarks');

            if ($customerName === '' || $mobile === '') {
                throw new RuntimeException('Customer name and mobile number are required.');
            }

            if ($functionTypeId <= 0) {
                throw new RuntimeException('Function / Product type is required.');
            }

            if ($enquiryStatusId <= 0) {
                $defaultStatus = enqGetDefaultStatusId($conn);
                if (!$defaultStatus) {
                    throw new RuntimeException('Enquiry status master is missing. Add enquiry_statuses first.');
                }
                $enquiryStatusId = $defaultStatus;
            }

            $functionDateValue = $functionDate !== '' ? $functionDate : null;
            $nextCallbackValue = null;

            if ($nextCallbackAt !== '') {
                $nextCallbackValue = str_replace('T', ' ', $nextCallbackAt);
                if (strlen($nextCallbackValue) === 16) {
                    $nextCallbackValue .= ':00';
                }
            }

            $assignedSalesUserIdValue = $assignedSalesUserId > 0 ? $assignedSalesUserId : null;
            $functionTypeIdValue = $functionTypeId > 0 ? $functionTypeId : null;
            $enquiryStatusIdValue = $enquiryStatusId > 0 ? $enquiryStatusId : null;
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $customerId = enqCustomerId($conn, $customerName, $mobile, $address);

            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE enquiries
                    SET customer_id = ?,
                        customer_name = ?,
                        mobile = ?,
                        function_type_id = ?,
                        function_date = ?,
                        venue = ?,
                        address = ?,
                        enquiry_source = ?,
                        enquiry_status_id = ?,
                        assigned_sales_user_id = ?,
                        next_callback_at = ?,
                        remarks = ?,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    'ississssiissii',
                    $customerId,
                    $customerName,
                    $mobile,
                    $functionTypeIdValue,
                    $functionDateValue,
                    $venue,
                    $address,
                    $enquirySource,
                    $enquiryStatusIdValue,
                    $assignedSalesUserIdValue,
                    $nextCallbackValue,
                    $remarks,
                    $userId,
                    $id
                );
                $stmt->execute();
                $stmt->close();

                enqLog($conn, 'update', $id, 'Enquiry updated.');
                enqRedirect('msg=updated');
            }

            $enquiryNo = enqNextNo($conn);

            $stmt = $conn->prepare("
                INSERT INTO enquiries
                    (
                        enquiry_no,
                        customer_id,
                        customer_name,
                        mobile,
                        function_type_id,
                        function_date,
                        venue,
                        address,
                        enquiry_source,
                        enquiry_status_id,
                        assigned_sales_user_id,
                        next_callback_at,
                        remarks,
                        created_by,
                        created_at,
                        updated_at
                    )
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");

            $stmt->bind_param(
                'sississssiissi',
                $enquiryNo,
                $customerId,
                $customerName,
                $mobile,
                $functionTypeIdValue,
                $functionDateValue,
                $venue,
                $address,
                $enquirySource,
                $enquiryStatusIdValue,
                $assignedSalesUserIdValue,
                $nextCallbackValue,
                $remarks,
                $userId
            );
            $stmt->execute();
            $newId = (int)$stmt->insert_id;
            $stmt->close();

            enqLog($conn, 'create_enquiry', $newId, 'Enquiry created: ' . $enquiryNo);
            enqRedirect('msg=created');
        }

        if ($action === 'close_record') {
            $id = enqInt($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Invalid enquiry.');
            }

            $closedStatusId = enqGetClosedStatusId($conn);
            $userId = (int)($_SESSION['user_id'] ?? 0);

            if ($closedStatusId) {
                $stmt = $conn->prepare("
                    UPDATE enquiries
                    SET enquiry_status_id = ?,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('iii', $closedStatusId, $userId, $id);
            } else {
                $stmt = $conn->prepare("
                    UPDATE enquiries
                    SET updated_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('ii', $userId, $id);
            }

            $stmt->execute();
            $stmt->close();

            enqLog($conn, 'delete', $id, 'Enquiry closed.');
            enqRedirect('msg=closed');
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') {
    $message = 'Enquiry created successfully.';
} elseif ($msg === 'updated') {
    $message = 'Enquiry updated successfully.';
} elseif ($msg === 'closed') {
    $message = 'Enquiry closed successfully.';
}

$functionTypes = [];
try {
    if (enqTableExists($conn, 'function_types')) {
        $res = $conn->query("
            SELECT id, function_name
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
    if (enqTableExists($conn, 'enquiry_statuses')) {
        $res = $conn->query("
            SELECT id, status_name, status_key, color_code
            FROM enquiry_statuses
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

$salesUsers = [];
try {
    if (enqTableExists($conn, 'users')) {
        $res = $conn->query("
            SELECT id, username AS display_name
            FROM users
            WHERE is_active = 1
            ORDER BY display_name ASC
        ");
        while ($row = $res->fetch_assoc()) {
            $salesUsers[] = $row;
        }
        $res->free();
    }
} catch (Throwable $e) {
}

$rows = [];
if (enqTableExists($conn, 'enquiries')) {
    try {
        $res = $conn->query("
            SELECT
                e.*,
                ft.function_name,
                es.status_name,
                es.status_key,
                es.color_code,
                u.username AS sales_person
            FROM enquiries e
            LEFT JOIN function_types ft ON ft.id = e.function_type_id
            LEFT JOIN enquiry_statuses es ON es.id = e.enquiry_status_id
            LEFT JOIN users u ON u.id = e.assigned_sales_user_id
            ORDER BY e.id DESC
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
$pendingRows = 0;
$convertedRows = 0;

foreach ($rows as $row) {
    $statusKey = strtolower((string)($row['status_key'] ?? ''));

    if (in_array($statusKey, ['new', 'follow_up_pending', 'callback_scheduled', 'interested'], true)) {
        $pendingRows++;
    }

    if ((int)($row['converted_to_quotation'] ?? 0) === 1 || (int)($row['converted_to_order'] ?? 0) === 1) {
        $convertedRows++;
    }
}

function enqDate($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime($value)) : '-';
}

function enqDateTime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime($value)) : '-';
}

$defaultStatusId = $statuses[0]['id'] ?? '';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Enquiries - Subhiksha Cards</title>
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

    .status-pill.closed {
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


    .select2-container--bootstrap-5 .select2-selection {
        min-height: 46px;
        border-radius: 14px;
        border-color: var(--border-soft);
        display: flex;
        align-items: center;
    }

    .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
        font-weight: 700;
        color: var(--text-main);
        padding-left: 10px;
    }

    .select2-container--bootstrap-5 .select2-dropdown {
        border-radius: 14px;
        border-color: var(--border-soft);
        overflow: hidden;
        z-index: 9999;
    }

    .select2-container--bootstrap-5 .select2-search__field {
        border-radius: 10px !important;
        min-height: 38px;
    }

    .modal .select2-container {
        width: 100% !important;
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
                            <h1 class="mb-1">Enquiries</h1>
                            <p class="text-muted-custom mb-0">Register customer enquiry and callback details.</p>
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
                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)">
                                <i data-lucide="phone"></i>
                            </div>
                            <div>
                                <span>Total Enquiries</span>
                                <strong><?= number_format($totalRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                <i data-lucide="clock"></i>
                            </div>
                            <div>
                                <span>Pending Follow-up</span>
                                <strong><?= number_format($pendingRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                <i data-lucide="check-circle-2"></i>
                            </div>
                            <div>
                                <span>Converted</span>
                                <strong><?= number_format($convertedRows) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Enquiries List</h2>
                            <p class="text-muted-custom mb-0">Correct flow: enquiry → follow-up → quotation.</p>
                        </div>

                        <div style="max-width:340px;width:100%">
                            <input type="search" id="tableSearch" class="form-control" placeholder="Search...">
                        </div>
                    </div>

                    <div class="table-responsive desktop-table">
                        <table class="table-ui" id="dataTable">
                            <thead>
                                <tr>
                                    <th>Enquiry No</th>
                                    <th>Customer</th>
                                    <th>Function Type</th>
                                    <th>Function Date</th>
                                    <th>Next Callback</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted-custom py-4">
                                        No enquiries found.
                                    </td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($rows as $row): ?>
                                <?php $closed = in_array(strtolower((string)($row['status_key'] ?? '')), ['cancelled', 'closed'], true); ?>
                                <tr>
                                    <td><strong><?= e($row['enquiry_no']) ?></strong></td>
                                    <td>
                                        <?= e($row['customer_name']) ?>
                                        <small class="d-block text-muted-custom"><?= e($row['mobile']) ?></small>
                                    </td>
                                    <td><?= e($row['function_name'] ?? '-') ?></td>
                                    <td><?= e(enqDate($row['function_date'] ?? null)) ?></td>
                                    <td><?= e(enqDateTime($row['next_callback_at'] ?? null)) ?></td>
                                    <td>
                                        <span class="status-pill <?= $closed ? 'closed' : '' ?>">
                                            <?= e($row['status_name'] ?? 'New') ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <button type="button"
                                            class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-record"
                                            data-bs-toggle="modal" data-bs-target="#recordModal"
                                            data-id="<?= e($row['id']) ?>"
                                            data-customer-name="<?= e($row['customer_name']) ?>"
                                            data-mobile="<?= e($row['mobile']) ?>"
                                            data-function-type-id="<?= e($row['function_type_id']) ?>"
                                            data-function-date="<?= e($row['function_date']) ?>"
                                            data-venue="<?= e($row['venue']) ?>"
                                            data-address="<?= e($row['address']) ?>"
                                            data-enquiry-source="<?= e($row['enquiry_source']) ?>"
                                            data-enquiry-status-id="<?= e($row['enquiry_status_id']) ?>"
                                            data-assigned-sales-user-id="<?= e($row['assigned_sales_user_id']) ?>"
                                            data-next-callback-at="<?= !empty($row['next_callback_at']) ? e(date('Y-m-d\TH:i', strtotime($row['next_callback_at']))) : '' ?>"
                                            data-remarks="<?= e($row['remarks']) ?>">
                                            Edit
                                        </button>

                                        <?php if (!$closed): ?>
                                        <form method="post" class="d-inline"
                                            onsubmit="return confirm('Close this enquiry?')">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="close_record">
                                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                            <button type="submit"
                                                class="btn btn-sm btn-outline-danger rounded-pill fw-bold">
                                                Close
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
                        <div class="mobile-card text-center text-muted-custom">No enquiries found.</div>
                        <?php endif; ?>

                        <?php foreach ($rows as $row): ?>
                        <?php $closed = in_array(strtolower((string)($row['status_key'] ?? '')), ['cancelled', 'closed'], true); ?>
                        <div class="mobile-card">
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <div class="mobile-card-title"><?= e($row['customer_name']) ?></div>
                                    <span class="mobile-card-subtitle">No: <?= e($row['enquiry_no']) ?></span>
                                    <span class="mobile-card-subtitle">Mobile: <?= e($row['mobile']) ?></span>
                                    <span class="mobile-card-subtitle">Type:
                                        <?= e($row['function_name'] ?? '-') ?></span>
                                    <span class="mobile-card-subtitle">Callback:
                                        <?= e(enqDateTime($row['next_callback_at'] ?? null)) ?></span>
                                </div>

                                <span class="status-pill <?= $closed ? 'closed' : '' ?>">
                                    <?= e($row['status_name'] ?? 'New') ?>
                                </span>
                            </div>

                            <div class="mobile-card-actions">
                                <button type="button"
                                    class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-record"
                                    data-bs-toggle="modal" data-bs-target="#recordModal" data-id="<?= e($row['id']) ?>"
                                    data-customer-name="<?= e($row['customer_name']) ?>"
                                    data-mobile="<?= e($row['mobile']) ?>"
                                    data-function-type-id="<?= e($row['function_type_id']) ?>"
                                    data-function-date="<?= e($row['function_date']) ?>"
                                    data-venue="<?= e($row['venue']) ?>" data-address="<?= e($row['address']) ?>"
                                    data-enquiry-source="<?= e($row['enquiry_source']) ?>"
                                    data-enquiry-status-id="<?= e($row['enquiry_status_id']) ?>"
                                    data-assigned-sales-user-id="<?= e($row['assigned_sales_user_id']) ?>"
                                    data-next-callback-at="<?= !empty($row['next_callback_at']) ? e(date('Y-m-d\TH:i', strtotime($row['next_callback_at']))) : '' ?>"
                                    data-remarks="<?= e($row['remarks']) ?>">
                                    Edit
                                </button>

                                <?php if (!$closed): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Close this enquiry?')">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="close_record">
                                    <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill fw-bold">
                                        Close
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
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save_record">
                <input type="hidden" name="id" id="id" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="recordModalTitle">Create Enquiry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Customer Name *</label>
                            <input type="text" name="customer_name" id="customer_name" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Mobile *</label>
                            <input type="text" name="mobile" id="mobile" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Function / Product Type *</label>
                            <select name="function_type_id" id="function_type_id" class="form-select select2-autotype"
                                required data-placeholder="Search function / product type" data-tags="true"
                                data-placeholder="Type or select function / product type">
                                <option value="">Select Type</option>
                                <?php foreach ($functionTypes as $type): ?>
                                <option value="<?= e($type['id']) ?>"><?= e($type['function_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Function Date</label>
                            <input type="date" name="function_date" id="function_date" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Enquiry Source</label>
                            <input type="text" name="enquiry_source" id="enquiry_source" class="form-control"
                                placeholder="Walk-in / WhatsApp / Instagram / Referral">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Next Callback</label>
                            <input type="datetime-local" name="next_callback_at" id="next_callback_at"
                                class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status *</label>
                            <select name="enquiry_status_id" id="enquiry_status_id" class="form-select select2-autotype"
                                required data-placeholder="Search status">
                                <option value="">Select Status</option>
                                <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status['id']) ?>"><?= e($status['status_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Assigned Sales Person</label>
                            <select name="assigned_sales_user_id" id="assigned_sales_user_id"
                                class="form-select select2-autotype" data-placeholder="Search sales person">
                                <option value="">Not Assigned</option>
                                <?php foreach ($salesUsers as $user): ?>
                                <option value="<?= e($user['id']) ?>"><?= e($user['display_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Venue</label>
                            <textarea name="venue" id="venue" rows="2" class="form-control"></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Address</label>
                            <textarea name="address" id="address" rows="2" class="form-control"></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Remarks</label>
                            <textarea name="remarks" id="remarks" rows="3" class="form-control"></textarea>
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


        function initSelect2AutoType(context) {
            if (!window.jQuery || !$.fn.select2) return;

            const $context = context ? $(context) : $(document);

            $context.find('select.select2-autotype').each(function() {
                const $select = $(this);

                if ($select.hasClass('select2-hidden-accessible')) {
                    $select.select2('destroy');
                }

                const $modal = $select.closest('.modal');

                $select.select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    dropdownParent: $modal.length ? $modal : $(document.body),
                    placeholder: $select.data('placeholder') || $select.find('option:first')
                        .text() || 'Search and select',
                    allowClear: false,
                    tags: String($select.data('tags')) === 'true',
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

        function refreshSelect2Value(id) {
            if (window.jQuery && $.fn.select2) {
                $('#' + id).trigger('change.select2');
            }
        }

        function set(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = value == null ? '' : value;
        }

        document.getElementById('newRecordBtn')?.addEventListener('click', function() {
            title.textContent = 'Create Enquiry';
            submit.textContent = 'Save';

            set('id', '');
            set('customer_name', '');
            set('mobile', '');
            set('function_type_id', '');
            set('function_date', '');
            set('venue', '');
            set('address', '');
            set('enquiry_source', '');
            set('enquiry_status_id', defaultStatusId);
            set('assigned_sales_user_id', '');
            set('next_callback_at', '');
            set('remarks', '');

            refreshSelect2Value('function_type_id');
            refreshSelect2Value('enquiry_status_id');
            refreshSelect2Value('assigned_sales_user_id');
        });

        document.querySelectorAll('.js-edit-record').forEach(function(btn) {
            btn.addEventListener('click', function() {
                title.textContent = 'Edit Enquiry';
                submit.textContent = 'Update';

                set('id', btn.dataset.id || '');
                set('customer_name', btn.dataset.customerName || '');
                set('mobile', btn.dataset.mobile || '');
                set('function_type_id', btn.dataset.functionTypeId || '');
                set('function_date', btn.dataset.functionDate || '');
                set('venue', btn.dataset.venue || '');
                set('address', btn.dataset.address || '');
                set('enquiry_source', btn.dataset.enquirySource || '');
                set('enquiry_status_id', btn.dataset.enquiryStatusId || defaultStatusId);
                set('assigned_sales_user_id', btn.dataset.assignedSalesUserId || '');
                set('next_callback_at', btn.dataset.nextCallbackAt || '');
                set('remarks', btn.dataset.remarks || '');

                refreshSelect2Value('function_type_id');
                refreshSelect2Value('enquiry_status_id');
                refreshSelect2Value('assigned_sales_user_id');
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

        initSelect2AutoType(document);

        document.getElementById('recordModal')?.addEventListener('shown.bs.modal', function() {
            initSelect2AutoType(this);
        });

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    })();
    </script>
</body>

</html>