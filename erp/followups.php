<?php

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'followups.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['followups_csrf'])) {
    $_SESSION['followups_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['followups_csrf'];
$message = '';
$messageType = 'success';
$toastTitle = 'Info';

function fuTableExists(mysqli $conn, string $table): bool
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

function fuPost(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function fuInt($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function fuRedirect(string $query = ''): void
{
    header('Location: followups.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function fuCsrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['followups_csrf']) ||
        !hash_equals($_SESSION['followups_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function fuDateTimeValue(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);
    if (strlen($value) === 16) {
        $value .= ':00';
    }

    return $value;
}

function fuStatusIdByKey(mysqli $conn, string $statusKey): ?int
{
    $statusKey = trim($statusKey);

    if ($statusKey === '' || !fuTableExists($conn, 'enquiry_statuses')) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT id
            FROM enquiry_statuses
            WHERE status_key = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $statusKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}




if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fuCsrf();

    try {
        $action = fuPost('action');

        if ($action === 'save_record') {
            if (!fuTableExists($conn, 'enquiry_followups')) {
                throw new RuntimeException('enquiry_followups table is missing. Run the support SQL first.');
            }

            if (!fuTableExists($conn, 'enquiries')) {
                throw new RuntimeException('enquiries table is missing.');
            }

            $id = fuInt($_POST['id'] ?? 0);
            $enquiryId = fuInt($_POST['enquiry_id'] ?? 0);
            $followupAt = fuDateTimeValue(fuPost('followup_at'));
            $callRemarks = fuPost('call_remarks');
            $customerResponse = fuPost('customer_response');
            $nextCallbackAt = fuDateTimeValue(fuPost('next_callback_at'));
            $followupStatus = fuPost('followup_status', 'followup_pending');
            $createdBy = (int)($_SESSION['user_id'] ?? 0);

            if ($enquiryId <= 0) {
                throw new RuntimeException('Please select enquiry.');
            }

            if (!$followupAt) {
                throw new RuntimeException('Follow-up date and time is required.');
            }

            if ($callRemarks === '') {
                throw new RuntimeException('Call remarks is required.');
            }

            $stmt = $conn->prepare("SELECT id FROM enquiries WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $enquiryId);
            $stmt->execute();
            $enquiryExists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$enquiryExists) {
                throw new RuntimeException('Selected enquiry not found.');
            }

            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE enquiry_followups
                    SET enquiry_id = ?,
                        followup_at = ?,
                        call_remarks = ?,
                        customer_response = ?,
                        next_callback_at = ?,
                        followup_status = ?
                    WHERE id = ?
                ");
                $stmt->bind_param(
                    'isssssi',
                    $enquiryId,
                    $followupAt,
                    $callRemarks,
                    $customerResponse,
                    $nextCallbackAt,
                    $followupStatus,
                    $id
                );
                $stmt->execute();
                $stmt->close();

                $statusId = fuStatusIdByKey($conn, $followupStatus);
                if ($statusId || $nextCallbackAt) {
                    if ($statusId) {
                        $stmt = $conn->prepare("
                            UPDATE enquiries
                            SET enquiry_status_id = ?,
                                next_callback_at = ?,
                                updated_by = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->bind_param('isii', $statusId, $nextCallbackAt, $createdBy, $enquiryId);
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE enquiries
                            SET next_callback_at = ?,
                                updated_by = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->bind_param('sii', $nextCallbackAt, $createdBy, $enquiryId);
                    }
                    $stmt->execute();
                    $stmt->close();
                }

                fuRedirect('msg=updated');
            }

            $stmt = $conn->prepare("
                INSERT INTO enquiry_followups
                    (
                        enquiry_id,
                        followup_at,
                        call_remarks,
                        customer_response,
                        next_callback_at,
                        followup_status,
                        created_by,
                        created_at
                    )
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param(
                'isssssi',
                $enquiryId,
                $followupAt,
                $callRemarks,
                $customerResponse,
                $nextCallbackAt,
                $followupStatus,
                $createdBy
            );
            $stmt->execute();
            $stmt->close();

            $statusId = fuStatusIdByKey($conn, $followupStatus);
            if ($statusId) {
                $stmt = $conn->prepare("
                    UPDATE enquiries
                    SET enquiry_status_id = ?,
                        next_callback_at = ?,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('isii', $statusId, $nextCallbackAt, $createdBy, $enquiryId);
            } else {
                $stmt = $conn->prepare("
                    UPDATE enquiries
                    SET next_callback_at = ?,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('sii', $nextCallbackAt, $createdBy, $enquiryId);
            }
            $stmt->execute();
            $stmt->close();

            fuRedirect('msg=created');
        }


        if ($action === 'delete_record') {
            $id = fuInt($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid follow-up.');
            }

            $stmt = $conn->prepare("DELETE FROM enquiry_followups WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            fuRedirect('msg=deleted');
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
        $toastTitle = 'Failed';
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') {
    $message = 'Follow-up added successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'updated') {
    $message = 'Follow-up updated successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'deleted') {
    $message = 'Follow-up deleted successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'failed') {
    $message = 'Action failed. Please try again.';
    $messageType = 'danger';
    $toastTitle = 'Failed';
}

if (isset($_GET['err']) && trim((string)$_GET['err']) !== '') {
    $errText = trim((string)$_GET['err']);
    $message .= ($message !== '' ? ' ' : '') . 'Error: ' . $errText;
}

$enquiries = [];
try {
    if (fuTableExists($conn, 'enquiries')) {
        $res = $conn->query("
            SELECT
                e.id,
                e.enquiry_no,
                e.customer_name,
                e.mobile,
                e.next_callback_at,
                ft.function_name,
                es.status_name
            FROM enquiries e
            LEFT JOIN function_types ft ON ft.id = e.function_type_id
            LEFT JOIN enquiry_statuses es ON es.id = e.enquiry_status_id
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
    $enquiries = [];
}

$statusOptions = [];
try {
    if (fuTableExists($conn, 'enquiry_statuses')) {
        $res = $conn->query("
            SELECT status_key, status_name
            FROM enquiry_statuses
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        while ($row = $res->fetch_assoc()) {
            $statusOptions[] = $row;
        }
        $res->free();
    }
} catch (Throwable $e) {
    $statusOptions = [];
}

if (!$statusOptions) {
    $statusOptions = [
        ['status_key' => 'followup_pending', 'status_name' => 'Follow-up Pending'],
        ['status_key' => 'interested', 'status_name' => 'Interested'],
        ['status_key' => 'not_interested', 'status_name' => 'Not Interested'],
        ['status_key' => 'callback_scheduled', 'status_name' => 'Callback Scheduled'],
        ['status_key' => 'converted_to_quotation', 'status_name' => 'Converted to Quotation'],
        ['status_key' => 'closed', 'status_name' => 'Closed'],
    ];
}

$rows = [];
if (fuTableExists($conn, 'enquiry_followups')) {
    try {
        $res = $conn->query("
            SELECT
                ef.*,
                e.enquiry_no,
                e.customer_name,
                e.mobile,
                e.function_date,
                ft.function_name,
                es.status_name AS enquiry_status_name,
                u.username AS created_by_name
            FROM enquiry_followups ef
            INNER JOIN enquiries e ON e.id = ef.enquiry_id
            LEFT JOIN function_types ft ON ft.id = e.function_type_id
            LEFT JOIN enquiry_statuses es ON es.id = e.enquiry_status_id
            LEFT JOIN users u ON u.id = ef.created_by
            ORDER BY ef.followup_at DESC, ef.id DESC
            LIMIT 500
        ");

        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    } catch (Throwable $e) {
        $message = 'List query error: ' . $e->getMessage();
        $messageType = 'danger';
        $toastTitle = 'Failed';
        $rows = [];
    }
} else {
    $message = 'enquiry_followups table is missing. Run the support SQL file first.';
    $messageType = 'danger';
    $toastTitle = 'Failed';
}

$totalRows = count($rows);
$todayRows = 0;
$pendingCallbackRows = 0;
$today = date('Y-m-d');

foreach ($rows as $row) {
    if (!empty($row['followup_at']) && date('Y-m-d', strtotime($row['followup_at'])) === $today) {
        $todayRows++;
    }

    if (!empty($row['next_callback_at']) && strtotime($row['next_callback_at']) >= strtotime(date('Y-m-d 00:00:00'))) {
        $pendingCallbackRows++;
    }
}

function fuDateTime($value): string
{
    return !empty($value) ? date('d-m-Y h:i A', strtotime($value)) : '-';
}

$nowLocal = date('Y-m-d\TH:i');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Follow-ups - Subhiksha Cards</title>
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

    .view-info-card {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 14px 16px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        height: 100%;
    }

    .view-info-card small {
        display: block;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        margin-bottom: 4px;
    }

    .view-info-card strong,
    .view-info-card span {
        display: block;
        color: var(--text-main);
        font-weight: 900;
        word-break: break-word;
        white-space: pre-wrap;
    }


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

    .status-pill.pending {
        color: var(--warning-color);
        background: color-mix(in srgb, var(--warning-color) 14%, transparent);
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

    .small-muted {
        display: block;
        margin-top: 3px;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 700;
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
    }

    .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
        font-weight: 700 !important;
        color: var(--text-main, #0f172a) !important;
        padding-left: 10px !important;
    }

    .select2-container--bootstrap-5 .select2-dropdown {
        border-radius: 14px !important;
        border-color: var(--border-soft, #dbe3ef) !important;
        overflow: hidden !important;
        z-index: 9999 !important;
    }

    .select2-container--bootstrap-5 .select2-search__field {
        border-radius: 10px !important;
        min-height: 38px !important;
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
                            <h1 class="mb-1">Follow-ups</h1>
                            <p class="text-muted-custom mb-0">Add follow-up history for enquiries and schedule
                                callbacks.</p>
                        </div>

                        <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" id="newRecordBtn"
                            data-bs-toggle="modal" data-bs-target="#recordModal">
                            Add Follow-up
                        </button>
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

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)">
                                <i data-lucide="messages-square"></i>
                            </div>
                            <div>
                                <span>Total Follow-ups</span>
                                <strong><?= number_format($totalRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                <i data-lucide="phone-call"></i>
                            </div>
                            <div>
                                <span>Today Follow-ups</span>
                                <strong><?= number_format($todayRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                <i data-lucide="clock"></i>
                            </div>
                            <div>
                                <span>Pending Callbacks</span>
                                <strong><?= number_format($pendingCallbackRows) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Follow-ups List</h2>
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
                                    <th>Enquiry</th>
                                    <th>Customer</th>
                                    <th>Follow-up Time</th>
                                    <th>Remarks</th>
                                    <th>Response</th>
                                    <th>Next Callback</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted-custom py-4">
                                        No follow-ups found.
                                    </td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($row['enquiry_no']) ?></strong>
                                        <small
                                            class="d-block text-muted-custom"><?= e($row['function_name'] ?? '-') ?></small>
                                    </td>
                                    <td>
                                        <?= e($row['customer_name']) ?>
                                        <small class="d-block text-muted-custom"><?= e($row['mobile']) ?></small>
                                    </td>
                                    <td><?= e(fuDateTime($row['followup_at'])) ?></td>
                                    <td><?= e($row['call_remarks']) ?></td>
                                    <td><?= e($row['customer_response'] ?? '-') ?></td>
                                    <td><?= e(fuDateTime($row['next_callback_at'] ?? null)) ?></td>
                                    <td><span
                                            class="status-pill pending"><?= e($row['followup_status'] ?: 'Follow-up') ?></span>
                                    </td>
                                    <td class="text-end">
                                        <button type="button"
                                            class="btn btn-sm btn-outline-secondary rounded-pill fw-bold js-view-record"
                                            data-bs-toggle="modal" data-bs-target="#viewModal"
                                            data-enquiry-no="<?= e($row['enquiry_no']) ?>"
                                            data-customer-name="<?= e($row['customer_name']) ?>"
                                            data-mobile="<?= e($row['mobile']) ?>"
                                            data-function-name="<?= e($row['function_name'] ?? '-') ?>"
                                            data-followup-time="<?= e(fuDateTime($row['followup_at'])) ?>"
                                            data-call-remarks="<?= e($row['call_remarks']) ?>"
                                            data-customer-response="<?= e($row['customer_response'] ?? '-') ?>"
                                            data-next-callback="<?= e(fuDateTime($row['next_callback_at'] ?? null)) ?>"
                                            data-followup-status="<?= e($row['followup_status'] ?: 'Follow-up') ?>"
                                            data-created-by="<?= e($row['created_by_name'] ?? '-') ?>">
                                            View
                                        </button>

                                        <button type="button"
                                            class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-record"
                                            data-bs-toggle="modal" data-bs-target="#recordModal"
                                            data-id="<?= e($row['id']) ?>"
                                            data-enquiry-id="<?= e($row['enquiry_id']) ?>"
                                            data-followup-at="<?= !empty($row['followup_at']) ? e(date('Y-m-d\TH:i', strtotime($row['followup_at']))) : '' ?>"
                                            data-call-remarks="<?= e($row['call_remarks']) ?>"
                                            data-customer-response="<?= e($row['customer_response'] ?? '') ?>"
                                            data-next-callback-at="<?= !empty($row['next_callback_at']) ? e(date('Y-m-d\TH:i', strtotime($row['next_callback_at']))) : '' ?>"
                                            data-followup-status="<?= e($row['followup_status'] ?? '') ?>">
                                            Edit
                                        </button>

                                        <form method="post" class="d-inline"
                                            onsubmit="const ok = confirm('Delete this follow-up?'); if (ok) { showToast('Deleting follow-up, please wait...', 'warning', 'Processing'); } return ok;">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete_record">
                                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                            <button type="submit"
                                                class="btn btn-sm btn-outline-danger rounded-pill fw-bold">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-cards" id="mobileCards">
                        <?php if (!$rows): ?>
                        <div class="mobile-card text-center text-muted-custom">No follow-ups found.</div>
                        <?php endif; ?>

                        <?php foreach ($rows as $row): ?>
                        <div class="mobile-card">
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <div class="mobile-card-title"><?= e($row['customer_name']) ?></div>
                                    <span class="mobile-card-subtitle">Enquiry: <?= e($row['enquiry_no']) ?></span>
                                    <span class="mobile-card-subtitle">Mobile: <?= e($row['mobile']) ?></span>
                                    <span class="mobile-card-subtitle">Follow-up:
                                        <?= e(fuDateTime($row['followup_at'])) ?></span>
                                    <span class="mobile-card-subtitle">Next:
                                        <?= e(fuDateTime($row['next_callback_at'] ?? null)) ?></span>
                                </div>

                                <span
                                    class="status-pill pending"><?= e($row['followup_status'] ?: 'Follow-up') ?></span>
                            </div>

                            <div class="mobile-card-actions">
                                <button type="button"
                                    class="btn btn-sm btn-outline-secondary rounded-pill fw-bold js-view-record"
                                    data-bs-toggle="modal" data-bs-target="#viewModal"
                                    data-enquiry-no="<?= e($row['enquiry_no']) ?>"
                                    data-customer-name="<?= e($row['customer_name']) ?>"
                                    data-mobile="<?= e($row['mobile']) ?>"
                                    data-function-name="<?= e($row['function_name'] ?? '-') ?>"
                                    data-followup-time="<?= e(fuDateTime($row['followup_at'])) ?>"
                                    data-call-remarks="<?= e($row['call_remarks']) ?>"
                                    data-customer-response="<?= e($row['customer_response'] ?? '-') ?>"
                                    data-next-callback="<?= e(fuDateTime($row['next_callback_at'] ?? null)) ?>"
                                    data-followup-status="<?= e($row['followup_status'] ?: 'Follow-up') ?>"
                                    data-created-by="<?= e($row['created_by_name'] ?? '-') ?>">
                                    View
                                </button>

                                <button type="button"
                                    class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-record"
                                    data-bs-toggle="modal" data-bs-target="#recordModal" data-id="<?= e($row['id']) ?>"
                                    data-enquiry-id="<?= e($row['enquiry_id']) ?>"
                                    data-followup-at="<?= !empty($row['followup_at']) ? e(date('Y-m-d\TH:i', strtotime($row['followup_at']))) : '' ?>"
                                    data-call-remarks="<?= e($row['call_remarks']) ?>"
                                    data-customer-response="<?= e($row['customer_response'] ?? '') ?>"
                                    data-next-callback-at="<?= !empty($row['next_callback_at']) ? e(date('Y-m-d\TH:i', strtotime($row['next_callback_at']))) : '' ?>"
                                    data-followup-status="<?= e($row['followup_status'] ?? '') ?>">
                                    Edit
                                </button>

                                <form method="post" class="d-inline"
                                    onsubmit="const ok = confirm('Delete this follow-up?'); if (ok) { showToast('Deleting follow-up, please wait...', 'warning', 'Processing'); } return ok;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_record">
                                    <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill fw-bold">
                                        Delete
                                    </button>
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

    <div class="modal fade" id="recordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save_record">
                <input type="hidden" name="id" id="id" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="recordModalTitle">Add Follow-up</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold">Enquiry *</label>
                            <select name="enquiry_id" id="enquiry_id" class="form-select select2-autotype" required
                                data-placeholder="Search enquiry by no / customer / mobile">
                                <option value="">Select Enquiry</option>
                                <?php foreach ($enquiries as $enquiry): ?>
                                <option value="<?= e($enquiry['id']) ?>">
                                    <?= e($enquiry['enquiry_no']) ?> - <?= e($enquiry['customer_name']) ?> -
                                    <?= e($enquiry['mobile']) ?>
                                    <?= !empty($enquiry['function_name']) ? ' - ' . e($enquiry['function_name']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Follow-up Date & Time *</label>
                            <input type="datetime-local" name="followup_at" id="followup_at" class="form-control"
                                required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Follow-up Status</label>
                            <select name="followup_status" id="followup_status" class="form-select select2-autotype"
                                data-placeholder="Search follow-up status">
                                <?php foreach ($statusOptions as $status): ?>
                                <option value="<?= e($status['status_key']) ?>"><?= e($status['status_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Call Remarks *</label>
                            <textarea name="call_remarks" id="call_remarks" rows="3" class="form-control"
                                required></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Customer Response</label>
                            <textarea name="customer_response" id="customer_response" rows="2"
                                class="form-control"></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Next Callback</label>
                            <input type="datetime-local" name="next_callback_at" id="next_callback_at"
                                class="form-control">
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



    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold">View Follow-up</h5>
                        <small class="text-muted-custom" id="viewEnquiryNo"></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="view-info-card">
                                <small>Customer</small>
                                <strong id="viewCustomerName">-</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="view-info-card">
                                <small>Mobile</small>
                                <strong id="viewMobile">-</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="view-info-card">
                                <small>Function Type</small>
                                <strong id="viewFunctionName">-</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="view-info-card">
                                <small>Status</small>
                                <strong id="viewFollowupStatus">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Follow-up Time</small>
                                <strong id="viewFollowupTime">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Next Callback</small>
                                <strong id="viewNextCallback">-</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="view-info-card">
                                <small>Created By</small>
                                <strong id="viewCreatedBy">-</strong>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="view-info-card">
                                <small>Call Remarks</small>
                                <span id="viewCallRemarks">-</span>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="view-info-card">
                                <small>Customer Response</small>
                                <span id="viewCustomerResponse">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold"
                        data-bs-dismiss="modal">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
    (function() {
        const title = document.getElementById('recordModalTitle');
        const submit = document.getElementById('recordSubmitBtn');
        const nowLocal = '<?= e($nowLocal) ?>';

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

        function setText(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            const clean = (value == null || String(value).trim() === '') ? '-' : String(value);
            el.textContent = clean;
        }

        document.querySelectorAll('.js-view-record').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setText('viewEnquiryNo', btn.dataset.enquiryNo || '-');
                setText('viewCustomerName', btn.dataset.customerName || '-');
                setText('viewMobile', btn.dataset.mobile || '-');
                setText('viewFunctionName', btn.dataset.functionName || '-');
                setText('viewFollowupStatus', btn.dataset.followupStatus || '-');
                setText('viewFollowupTime', btn.dataset.followupTime || '-');
                setText('viewNextCallback', btn.dataset.nextCallback || '-');
                setText('viewCreatedBy', btn.dataset.createdBy || '-');
                setText('viewCallRemarks', btn.dataset.callRemarks || '-');
                setText('viewCustomerResponse', btn.dataset.customerResponse || '-');
                showToast('Follow-up details opened.', 'success', 'Success');
            });
        });

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

                $select.select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    dropdownParent: $modal.length ? $modal : $(document.body),
                    placeholder: $select.data('placeholder') || $select.find('option:first')
                        .text() || 'Search and select',
                    allowClear: false
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

        document.getElementById('newRecordBtn')?.addEventListener('click', function() {
            title.textContent = 'Add Follow-up';
            submit.textContent = 'Save';

            set('id', '');
            set('enquiry_id', '');
            set('followup_at', nowLocal);
            set('call_remarks', '');
            set('customer_response', '');
            set('next_callback_at', '');
            set('followup_status', 'followup_pending');

            refreshSelect2('enquiry_id');
            refreshSelect2('followup_status');
        });

        document.querySelectorAll('.js-edit-record').forEach(function(btn) {
            btn.addEventListener('click', function() {
                title.textContent = 'Edit Follow-up';
                submit.textContent = 'Update';

                set('id', btn.dataset.id || '');
                set('enquiry_id', btn.dataset.enquiryId || '');
                set('followup_at', btn.dataset.followupAt || nowLocal);
                set('call_remarks', btn.dataset.callRemarks || '');
                set('customer_response', btn.dataset.customerResponse || '');
                set('next_callback_at', btn.dataset.nextCallbackAt || '');
                set('followup_status', btn.dataset.followupStatus || 'followup_pending');

                refreshSelect2('enquiry_id');
                refreshSelect2('followup_status');
            });
        });


        document.querySelector('#recordModal form')?.addEventListener('submit', function() {
            showToast('Saving follow-up, please wait...', 'success', 'Processing');
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

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    })();
    </script>
</body>

</html>