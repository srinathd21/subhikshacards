<?php

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'followups.php');
// Backend create/update/delete processing moved to api/followups.php
// Toast rule: show toast only for important save/update/delete/error messages.

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
$focusId = (int)($_GET['focus'] ?? 0);

$currentPage = 'followups.php';
$canView = can_view($conn, $currentPage);
$canCreate = can_create($conn, $currentPage);
$canEdit = can_edit($conn, $currentPage);
$canDelete = can_delete($conn, $currentPage);
$canUpdate = can_update($conn, $currentPage);

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




/* Backend processing moved to api/followups.php */

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

$statusKeys = array_map(static fn(array $row): string => strtolower((string)($row['status_key'] ?? '')), $statusOptions);
if (!in_array('completed', $statusKeys, true)) {
    $statusOptions[] = ['status_key' => 'completed', 'status_name' => 'Completed'];
}

function fuTerminalStatus($value): bool
{
    return in_array(strtolower(trim((string)$value)), [
        'completed', 'closed', 'converted_to_quotation', 'not_interested'
    ], true);
}

function fuCurrentFilterQuery(array $extra = []): string
{
    $keep = [];
    foreach (['q', 'from_date', 'to_date'] as $key) {
        if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $keep[$key] = trim((string)$_GET[$key]);
        }
    }

    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            unset($keep[$key]);
        } else {
            $keep[$key] = $value;
        }
    }

    return http_build_query($keep);
}

function fuBindParams(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || !$params) {
        return;
    }

    $bind = [$types];
    foreach ($params as $key => &$value) {
        $bind[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

$filterSearch = trim((string)($_GET['q'] ?? ''));
$filterFromDate = trim((string)($_GET['from_date'] ?? ''));
$filterToDate = trim((string)($_GET['to_date'] ?? ''));

if ($filterFromDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFromDate)) {
    $filterFromDate = '';
}
if ($filterToDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterToDate)) {
    $filterToDate = '';
}
if ($filterFromDate !== '' && $filterToDate !== '' && $filterFromDate > $filterToDate) {
    [$filterFromDate, $filterToDate] = [$filterToDate, $filterFromDate];
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$rows = [];
$totalFilteredRows = 0;
$totalPages = 1;

$today = date('Y-m-d');
$totalRows = 0;
$todayRows = 0;
$pendingCallbackRows = 0;

if (fuTableExists($conn, 'enquiry_followups')) {
    try {
        $effectiveDateSql = "DATE(COALESCE(ef.next_callback_at, ef.followup_at))";
        $where = [];
        $params = [];
        $types = '';

        if ($filterFromDate !== '') {
            $where[] = "{$effectiveDateSql} >= ?";
            $params[] = $filterFromDate;
            $types .= 's';
        }

        if ($filterToDate !== '') {
            $where[] = "{$effectiveDateSql} <= ?";
            $params[] = $filterToDate;
            $types .= 's';
        }

        if ($filterSearch !== '') {
            $like = '%' . $filterSearch . '%';
            $where[] = "(
                e.enquiry_no LIKE ?
                OR e.customer_name LIKE ?
                OR e.mobile LIKE ?
                OR ef.call_remarks LIKE ?
                OR ef.customer_response LIKE ?
                OR ef.followup_status LIKE ?
            )";
            for ($i = 0; $i < 6; $i++) {
                $params[] = $like;
                $types .= 's';
            }
        }

        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        $countSql = "
            SELECT COUNT(*) AS total
            FROM enquiry_followups ef
            INNER JOIN enquiries e ON e.id = ef.enquiry_id
            {$whereSql}
        ";
        $stmt = $conn->prepare($countSql);
        $countParams = $params;
        fuBindParams($stmt, $types, $countParams);
        $stmt->execute();
        $totalFilteredRows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $totalPages = max(1, (int)ceil($totalFilteredRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $focusOrderSql = $focusId > 0 ? "CASE WHEN ef.id = {$focusId} THEN 0 ELSE 1 END ASC," : '';

        $listSql = "
            SELECT
                ef.*,
                e.enquiry_no,
                e.customer_name,
                e.mobile,
                e.function_date,
                ft.function_name,
                es.status_name AS enquiry_status_name,
                u.username AS created_by_name,
                COALESCE(ef.next_callback_at, ef.followup_at) AS effective_followup_at
            FROM enquiry_followups ef
            INNER JOIN enquiries e ON e.id = ef.enquiry_id
            LEFT JOIN function_types ft ON ft.id = e.function_type_id
            LEFT JOIN enquiry_statuses es ON es.id = e.enquiry_status_id
            LEFT JOIN users u ON u.id = ef.created_by
            {$whereSql}
            ORDER BY {$focusOrderSql} COALESCE(ef.next_callback_at, ef.followup_at) DESC, ef.id DESC
            LIMIT ? OFFSET ?
        ";

        $listParams = $params;
        $listTypes = $types . 'ii';
        $listParams[] = $perPage;
        $listParams[] = $offset;

        $stmt = $conn->prepare($listSql);
        fuBindParams($stmt, $listTypes, $listParams);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        $summarySql = "
            SELECT
                COUNT(*) AS total_rows,
                SUM(
                    CASE
                        WHEN LOWER(COALESCE(ef.followup_status, 'followup_pending')) NOT IN
                            ('completed','closed','converted_to_quotation','not_interested')
                         AND DATE(COALESCE(ef.next_callback_at, ef.followup_at)) = CURDATE()
                        THEN 1 ELSE 0
                    END
                ) AS today_rows,
                SUM(
                    CASE
                        WHEN LOWER(COALESCE(ef.followup_status, 'followup_pending')) NOT IN
                            ('completed','closed','converted_to_quotation','not_interested')
                         AND ef.next_callback_at IS NOT NULL
                         AND ef.next_callback_at >= CURDATE()
                        THEN 1 ELSE 0
                    END
                ) AS pending_callback_rows
            FROM enquiry_followups ef
        ";
        $summaryRes = $conn->query($summarySql);
        $summary = $summaryRes ? ($summaryRes->fetch_assoc() ?: []) : [];
        if ($summaryRes) {
            $summaryRes->free();
        }
        $totalRows = (int)($summary['total_rows'] ?? 0);
        $todayRows = (int)($summary['today_rows'] ?? 0);
        $pendingCallbackRows = (int)($summary['pending_callback_rows'] ?? 0);
    } catch (Throwable $e) {
        $message = 'List query error: ' . $e->getMessage();
        $messageType = 'danger';
        $toastTitle = 'Failed';
        $rows = [];
        $totalFilteredRows = 0;
        $totalPages = 1;
    }
} else {
    $message = 'enquiry_followups table is missing. Run the support SQL file first.';
    $messageType = 'danger';
    $toastTitle = 'Failed';
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

    /* Mobile follow-up card UI fix */
    @media(max-width:767.98px) {
        .mobile-card {
            padding: 16px 16px 14px !important;
            border-radius: 20px !important;
        }

        .mobile-card>.d-flex.justify-content-between {
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
            max-width: 110px !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }

        .mobile-card-title {
            font-size: 16px !important;
            line-height: 1.25 !important;
            margin-bottom: 6px !important;
        }

        .mobile-card-subtitle {
            font-size: 12px !important;
            line-height: 1.45 !important;
            margin-top: 3px !important;
        }

        .mobile-card-actions {
            margin-top: 14px !important;
            gap: 8px !important;
        }

        .mobile-card-actions .btn {
            min-height: 38px !important;
            border-radius: 999px !important;
            font-size: 13px !important;
            font-weight: 900 !important;
        }

        .module-card .form-control#tableSearch {
            min-height: 46px !important;
            border-radius: 16px !important;
        }
    }

    /* Mobile follow-up card UI fix - compact status and neat actions */
    @media(max-width:767.98px) {
        .mobile-card {
            padding: 16px 16px 14px !important;
            border-radius: 20px !important;
        }

        .mobile-card > .d-flex.justify-content-between,
        .mobile-card>.d-flex.justify-content-between {
            align-items: flex-start !important;
            gap: 12px !important;
        }

        .mobile-card .status-pill {
            align-self: flex-start !important;
            flex: 0 0 auto !important;
            width: auto !important;
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
            max-width: 112px !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }

        .mobile-card-title {
            font-size: 16px !important;
            line-height: 1.25 !important;
            margin-bottom: 6px !important;
        }

        .mobile-card-subtitle {
            font-size: 12px !important;
            line-height: 1.45 !important;
            margin-top: 3px !important;
        }

        .mobile-card-actions {
            margin-top: 14px !important;
            gap: 8px !important;
        }

        .mobile-card-actions .btn {
            min-height: 38px !important;
            border-radius: 999px !important;
            font-size: 13px !important;
            font-weight: 900 !important;
        }

        .module-card .form-control#tableSearch {
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


    .status-pill.completed {
        color: #166534;
        background: #dcfce7;
    }

    .followup-focus-row {
        outline: 2px solid color-mix(in srgb, var(--brand-1) 55%, transparent);
        outline-offset: -2px;
        background: color-mix(in srgb, var(--brand-1) 7%, var(--card-bg)) !important;
    }

    .btn-complete-followup {
        color: #15803d !important;
        border-color: #86efac !important;
    }

    .btn-complete-followup:hover {
        background: #dcfce7 !important;
        color: #166534 !important;
    }
    </style>

<style>
/* ========================================================================
   Compact module UI - tuned for comfortable use at 100% browser zoom.
   UI sizing only: no PHP, SQL, workflow, filters, pagination or API logic.
   ======================================================================== */
#main .page-section {
    font-size: 12.5px;
}

#main .page-section .page-head {
    padding: 16px 18px !important;
    margin-bottom: 12px !important;
    border-radius: 16px !important;
}

#main .page-section .page-head h1 {
    font-size: 22px !important;
    font-weight: 800 !important;
    line-height: 1.15 !important;
    letter-spacing: -.15px !important;
    margin-bottom: 3px !important;
}

#main .page-section .page-head p,
#main .page-section .page-head .text-muted-custom {
    font-size: 11.5px !important;
    font-weight: 500 !important;
    line-height: 1.35 !important;
}

#main .page-section .module-card {
    padding: 14px 15px !important;
    border-radius: 16px !important;
    margin-bottom: 12px !important;
}

#main .page-section .module-title {
    font-size: 15px !important;
    font-weight: 800 !important;
    line-height: 1.2 !important;
}

#main .page-section .stat-card,
#main .page-section .kpi-card {
    min-height: 86px !important;
    padding: 12px 13px !important;
    border-radius: 14px !important;
    gap: 10px !important;
}

#main .page-section .stat-icon {
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    border-radius: 12px !important;
}

#main .page-section .stat-icon svg,
#main .page-section .stat-icon i {
    width: 19px !important;
    height: 19px !important;
}

#main .page-section .stat-card span,
#main .page-section .stat-card small,
#main .page-section .kpi-card small {
    font-size: 10px !important;
    font-weight: 700 !important;
    letter-spacing: .2px !important;
}

#main .page-section .stat-card strong,
#main .page-section .kpi-card strong {
    font-size: 18px !important;
    font-weight: 800 !important;
    line-height: 1.15 !important;
}

#main .page-section .filter-card {
    padding: 12px !important;
    border-radius: 14px !important;
}

#main .page-section .form-label,
#main .page-section label.fw-bold {
    font-size: 11px !important;
    font-weight: 700 !important;
    margin-bottom: 4px !important;
}

#main .page-section .form-control,
#main .page-section .form-select,
#main .page-section .select2-container--bootstrap-5 .select2-selection {
    min-height: 38px !important;
    font-size: 12px !important;
    border-radius: 10px !important;
}

#main .page-section .form-control,
#main .page-section .form-select {
    padding-top: .38rem !important;
    padding-bottom: .38rem !important;
}

#main .page-section textarea.form-control {
    min-height: 68px !important;
}

#main .page-section .btn:not(.btn-action-icon):not(.btn-delete-icon):not(.btn-whatsapp-icon) {
    font-size: 11.5px !important;
    font-weight: 700 !important;
    line-height: 1.2 !important;
}

#main .page-section .btn.rounded-pill:not(.btn-action-icon):not(.btn-delete-icon):not(.btn-whatsapp-icon) {
    padding-top: 6px !important;
    padding-bottom: 6px !important;
}

#main .page-section .table-ui,
#main .page-section table {
    font-size: 11.5px !important;
}

#main .page-section .table-ui th,
#main .page-section table th {
    font-size: 10px !important;
    font-weight: 700 !important;
    padding: 8px 9px !important;
    line-height: 1.25 !important;
}

#main .page-section .table-ui td,
#main .page-section table td {
    font-size: 11.5px !important;
    font-weight: 500 !important;
    padding: 8px 9px !important;
    line-height: 1.3 !important;
}

#main .page-section table td strong,
#main .page-section .customer-name,
#main .page-section .job-no,
#main .page-section .mobile-card-title,
#main .page-section .product-names,
#main .page-section .amount-text,
#main .page-section .balance-text,
#main .page-section .paid-amount,
#main .page-section .balance-amount {
    font-weight: 700 !important;
}

#main .page-section .status-pill,
#main .page-section .stock-pill,
#main .page-section .badge-pill,
#main .page-section .order-badge,
#main .page-section .filter-tab {
    font-size: 9.5px !important;
    font-weight: 700 !important;
    padding: 4px 7px !important;
}

#main .page-section .mobile-card,
#main .page-section .mobile-products .card-ui {
    padding: 12px !important;
    border-radius: 14px !important;
    margin-bottom: 9px !important;
}

#main .page-section .mobile-card-title {
    font-size: 13px !important;
    font-weight: 700 !important;
}

#main .page-section .mobile-card-subtitle,
#main .page-section .muted-small,
#main .page-section .small-muted,
#main .page-section .meta {
    font-size: 10.5px !important;
    font-weight: 500 !important;
    line-height: 1.35 !important;
}

#main .page-section .view-info-card,
#main .page-section .amount-box,
#main .page-section .profile-box,
#main .page-section .summary-item,
#main .page-section .hist-row {
    border-radius: 13px !important;
    padding: 11px !important;
}

#main .page-section .view-info-card small,
#main .page-section .amount-box small,
#main .page-section .summary-item small,
#main .page-section .section-label {
    font-size: 9.5px !important;
    font-weight: 700 !important;
}

#main .page-section .view-info-card span,
#main .page-section .view-info-card strong,
#main .page-section .amount-box strong,
#main .page-section .summary-item strong {
    font-size: 13px !important;
    font-weight: 700 !important;
}

#main .page-section .pagination-wrap,
#main .page-section nav[aria-label*="Pagination" i] {
    font-size: 11px !important;
}

#main .page-section .pagination .page-link,
#main .page-section .product-pagination .page-link-ui {
    min-width: 32px !important;
    min-height: 32px !important;
    padding: 5px 8px !important;
    font-size: 10.5px !important;
    font-weight: 700 !important;
}

/* Customer Management compact sizing */
#main .customer-page .stats-grid {
    gap: 10px !important;
    margin-bottom: 12px !important;
}

#main .customer-page .stat-box {
    padding: 11px 12px !important;
    border-radius: 14px !important;
}

#main .customer-page .stat-box small {
    font-size: 9.5px !important;
    font-weight: 700 !important;
}

#main .customer-page .stat-box strong {
    font-size: 18px !important;
    font-weight: 800 !important;
    margin-top: 2px !important;
}

#main .customer-page .workspace {
    gap: 12px !important;
}

#main .customer-page .pane {
    border-radius: 16px !important;
}

#main .customer-page .pane-head,
#main .customer-page .pane-body {
    padding: 13px 14px !important;
}

#main .customer-page .customer-name,
#main .customer-page .profile-name {
    font-size: 13px !important;
    font-weight: 700 !important;
}

#main .customer-page .profile-grid,
#main .customer-page .summary-grid {
    gap: 9px !important;
}

#main .customer-page .tabs {
    margin: 12px 0 9px !important;
    gap: 5px !important;
}

#main .customer-page .tabs button {
    padding: 5px 9px !important;
    font-size: 10px !important;
    font-weight: 700 !important;
}

/* Product master images and rows */
#main .module-page .product-thumb,
#main .module-page .placeholder-thumb {
    width: 42px !important;
    height: 42px !important;
}

/* Job Card shortcut controls */
#main .module-page .shortcut-action-box {
    padding: 10px !important;
    border-radius: 13px !important;
}

#main .module-page .shortcut-btn {
    min-height: 34px !important;
    font-size: 10.5px !important;
    font-weight: 700 !important;
}

#main .module-page .shortcut-note,
#main .module-page .shortcut-help-bar {
    font-size: 10.5px !important;
    font-weight: 500 !important;
}

/* Keep icon-only actions compact */
#main .page-section .btn-action-icon,
#main .page-section .btn-delete-icon,
#main .page-section .btn-whatsapp-icon,
#main .customer-page .actions .btn {
    width: 32px !important;
    height: 32px !important;
    min-width: 32px !important;
    max-width: 32px !important;
    padding: 0 !important;
}

#main .page-section .btn-action-icon svg,
#main .page-section .btn-delete-icon svg,
#main .page-section .btn-whatsapp-icon svg,
#main .customer-page .actions .btn svg {
    width: 14px !important;
    height: 14px !important;
}

/* Reduce heavy utility weight only inside module content */
#main .page-section .fw-bold,
#main .page-section strong {
    font-weight: 700 !important;
}

/* Compact modal typography without changing modal workflow */
#main ~ .modal .modal-title,
.modal .modal-title {
    font-size: 15px !important;
    font-weight: 800 !important;
}

.modal .modal-header,
.modal .modal-footer {
    padding-top: 11px !important;
    padding-bottom: 11px !important;
}

.modal .modal-body {
    font-size: 12px !important;
}

@media (max-width: 767.98px) {
    #main .page-section .page-head {
        padding: 14px !important;
    }

    #main .page-section .page-head h1 {
        font-size: 20px !important;
    }

    #main .page-section .module-card {
        padding: 12px !important;
    }

    #main .page-section .stat-card,
    #main .page-section .kpi-card {
        min-height: 76px !important;
        padding: 10px 11px !important;
    }

    #main .page-section .stat-icon {
        width: 36px !important;
        height: 36px !important;
        min-width: 36px !important;
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

                        <?php if ($canCreate): ?>
                        <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" id="newRecordBtn"
                            data-bs-toggle="modal" data-bs-target="#recordModal">
                            Add Follow-up
                        </button>
                        <?php endif; ?>
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
                    </div>

                    <form method="get" class="filter-card mb-3">
                        <div class="row g-3 align-items-end">
                            <div class="col-12 col-lg-4">
                                <label class="form-label fw-bold">Search</label>
                                <input type="search" name="q" id="tableSearch" class="form-control"
                                    value="<?= e($filterSearch) ?>"
                                    placeholder="Enquiry / customer / mobile / remarks">
                            </div>
                            <div class="col-12 col-md-4 col-lg-2">
                                <label class="form-label fw-bold">From Date</label>
                                <input type="date" name="from_date" class="form-control"
                                    value="<?= e($filterFromDate) ?>">
                            </div>
                            <div class="col-12 col-md-4 col-lg-2">
                                <label class="form-label fw-bold">To Date</label>
                                <input type="date" name="to_date" class="form-control"
                                    value="<?= e($filterToDate) ?>">
                            </div>
                            <div class="col-12 col-md-4 col-lg-4 d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
                                    <i data-lucide="filter" style="width:16px;height:16px"></i> Filter
                                </button>
                                <a href="followups.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                                    Clear
                                </a>
                            </div>
                        </div>
                        <div class="small text-muted-custom mt-2">
                            Date filter uses Next Callback when scheduled; otherwise it uses Follow-up Time.
                        </div>
                    </form>

                    <?php
                        $showFrom = $totalFilteredRows > 0 ? (($page - 1) * $perPage) + 1 : 0;
                        $showTo = $totalFilteredRows > 0 ? min($page * $perPage, $totalFilteredRows) : 0;
                    ?>
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                        <div class="small text-muted-custom fw-bold">
                            Showing <?= number_format($showFrom) ?>-<?= number_format($showTo) ?>
                            of <?= number_format($totalFilteredRows) ?> filtered follow-up(s)
                        </div>
                        <div class="small text-muted-custom fw-bold">
                            Page <?= number_format($page) ?> of <?= number_format($totalPages) ?>
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
                                <?php $rowCompleted = fuTerminalStatus($row['followup_status'] ?? ''); ?>
                                <tr id="followup-row-<?= (int)$row['id'] ?>" class="<?= $focusId === (int)$row['id'] ? 'followup-focus-row' : '' ?>">
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
                                            class="status-pill <?= $rowCompleted ? 'completed' : 'pending' ?>"><?= e($row['followup_status'] ?: 'Follow-up') ?></span>
                                    </td>
                                    <td class="text-end">
                                        <button title="View" aria-label="View" type="button"
                                            class="btn btn-sm btn-outline-secondary rounded-circle fw-bold js-view-record btn-action-icon"
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
                                            data-created-by="<?= e($row['created_by_name'] ?? '-') ?>"><i data-lucide="eye"></i></button>

                                        <?php if ($canEdit): ?>
                                        <button title="Edit" aria-label="Edit" type="button"
                                            class="btn btn-sm btn-outline-primary rounded-circle fw-bold js-edit-record btn-action-icon"
                                            data-bs-toggle="modal" data-bs-target="#recordModal"
                                            data-id="<?= e($row['id']) ?>"
                                            data-enquiry-id="<?= e($row['enquiry_id']) ?>"
                                            data-followup-at="<?= !empty($row['followup_at']) ? e(date('Y-m-d\TH:i', strtotime($row['followup_at']))) : '' ?>"
                                            data-call-remarks="<?= e($row['call_remarks']) ?>"
                                            data-customer-response="<?= e($row['customer_response'] ?? '') ?>"
                                            data-next-callback-at="<?= !empty($row['next_callback_at']) ? e(date('Y-m-d\TH:i', strtotime($row['next_callback_at']))) : '' ?>"
                                            data-followup-status="<?= e($row['followup_status'] ?? '') ?>"><i data-lucide="pencil"></i></button>
                                        <?php endif; ?>

                                        <?php if (($canEdit || $canUpdate) && !$rowCompleted): ?>
                                        <button title="Complete" aria-label="Complete" type="button"
                                            class="btn btn-sm btn-outline-success rounded-circle fw-bold btn-action-icon btn-complete-followup js-complete-followup"
                                            data-id="<?= (int)$row['id'] ?>"><i data-lucide="check"></i></button>
                                        <?php endif; ?>

                                        <?php if ($canDelete): ?>
                                        <form method="post" action="api/followups.php"
                                            class="d-inline js-api-delete-form" onsubmit="return false;">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete_record">
                                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                            <button title="Delete" aria-label="Delete" type="submit"
                                                class="btn btn-sm btn-outline-danger rounded-circle fw-bold btn-delete-icon btn-action-icon"><i data-lucide="trash-2"></i></button>
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
                        <div class="mobile-card text-center text-muted-custom">No follow-ups found.</div>
                        <?php endif; ?>

                        <?php foreach ($rows as $row): ?>
                        <?php $rowCompleted = fuTerminalStatus($row['followup_status'] ?? ''); ?>
                        <div id="followup-mobile-<?= (int)$row['id'] ?>" class="mobile-card <?= $focusId === (int)$row['id'] ? 'followup-focus-row' : '' ?>">
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
                                    class="status-pill <?= $rowCompleted ? 'completed' : 'pending' ?>"><?= e($row['followup_status'] ?: 'Follow-up') ?></span>
                            </div>

                            <div class="mobile-card-actions">
                                <button title="View" aria-label="View" type="button"
                                    class="btn btn-sm btn-outline-secondary rounded-circle fw-bold js-view-record btn-action-icon"
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
                                    data-created-by="<?= e($row['created_by_name'] ?? '-') ?>"><i data-lucide="eye"></i></button>

                                <?php if ($canEdit): ?>
                                <button title="Edit" aria-label="Edit" type="button"
                                    class="btn btn-sm btn-outline-primary rounded-circle fw-bold js-edit-record btn-action-icon"
                                    data-bs-toggle="modal" data-bs-target="#recordModal" data-id="<?= e($row['id']) ?>"
                                    data-enquiry-id="<?= e($row['enquiry_id']) ?>"
                                    data-followup-at="<?= !empty($row['followup_at']) ? e(date('Y-m-d\TH:i', strtotime($row['followup_at']))) : '' ?>"
                                    data-call-remarks="<?= e($row['call_remarks']) ?>"
                                    data-customer-response="<?= e($row['customer_response'] ?? '') ?>"
                                    data-next-callback-at="<?= !empty($row['next_callback_at']) ? e(date('Y-m-d\TH:i', strtotime($row['next_callback_at']))) : '' ?>"
                                    data-followup-status="<?= e($row['followup_status'] ?? '') ?>"><i data-lucide="pencil"></i></button>
                                <?php endif; ?>

                                <?php if (($canEdit || $canUpdate) && !$rowCompleted): ?>
                                <button title="Complete" aria-label="Complete" type="button"
                                    class="btn btn-sm btn-outline-success rounded-circle fw-bold btn-action-icon btn-complete-followup js-complete-followup"
                                    data-id="<?= (int)$row['id'] ?>"><i data-lucide="check"></i></button>
                                <?php endif; ?>

                                <?php if ($canDelete): ?>
                                <form method="post" action="api/followups.php" class="d-inline js-api-delete-form"
                                    onsubmit="return false;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_record">
                                    <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                    <button title="Delete" aria-label="Delete" type="submit" class="btn btn-sm btn-outline-danger rounded-circle fw-bold btn-delete-icon btn-action-icon"><i data-lucide="trash-2"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <nav class="mt-4" aria-label="Follow-up pagination">
                        <ul class="pagination pagination-sm flex-wrap justify-content-center mb-2">
                            <?php
                                $previousPage = max(1, $page - 1);
                                $nextPage = min($totalPages, $page + 1);
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                            ?>
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link"
                                    href="<?= $page <= 1 ? '#' : 'followups.php?' . e(fuCurrentFilterQuery(['page' => $previousPage])) ?>">
                                    Previous
                                </a>
                            </li>

                            <?php if ($startPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="followups.php?<?= e(fuCurrentFilterQuery(['page' => 1])) ?>">1</a>
                            </li>
                            <?php if ($startPage > 2): ?>
                            <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($pageNo = $startPage; $pageNo <= $endPage; $pageNo++): ?>
                            <li class="page-item <?= $pageNo === $page ? 'active' : '' ?>">
                                <a class="page-link"
                                    href="followups.php?<?= e(fuCurrentFilterQuery(['page' => $pageNo])) ?>"><?= $pageNo ?></a>
                            </li>
                            <?php endfor; ?>

                            <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link"
                                    href="followups.php?<?= e(fuCurrentFilterQuery(['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                            </li>
                            <?php endif; ?>

                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link"
                                    href="<?= $page >= $totalPages ? '#' : 'followups.php?' . e(fuCurrentFilterQuery(['page' => $nextPage])) ?>">
                                    Next
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <?php if ($canCreate || $canEdit): ?>
    <div class="modal fade" id="recordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="post" action="api/followups.php" class="modal-content" id="followupForm">
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
    <?php endif; ?>



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
    window.followupsPermissions = {
        canCreate: <?= $canCreate ? 'true' : 'false' ?>,
        canEdit: <?= $canEdit ? 'true' : 'false' ?>,
        canUpdate: <?= $canUpdate ? 'true' : 'false' ?>,
        canDelete: <?= $canDelete ? 'true' : 'false' ?>
    };
    </script>

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


        document.querySelector('#recordModal form')?.addEventListener('submit', function(event) {
            event.preventDefault();

            const form = this;
            const formData = new FormData(form);
            const recordId = String(formData.get('id') || '').trim();

            if (recordId === '' && !window.followupsPermissions.canCreate) {
                showToast('You do not have permission to create follow-ups.', 'danger', 'Access Denied');
                return;
            }

            if (recordId !== '' && !window.followupsPermissions.canEdit) {
                showToast('You do not have permission to edit follow-ups.', 'danger', 'Access Denied');
                return;
            }

            fetch('api/followups.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    showToast(data.message || (data.status ? 'Saved successfully.' : 'Save failed.'),
                        data.status ? 'success' : 'danger', data.status ? 'Success' : 'Failed');

                    if (data.status) {
                        setTimeout(() => window.location.reload(), 800);
                    }
                })
                .catch(() => showToast('API request failed.', 'danger', 'Failed'));
        });

        document.querySelectorAll('.js-api-delete-form').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
            });

            form.querySelector('button[type="submit"]')?.addEventListener('click', function() {
                if (!window.followupsPermissions.canDelete) {
                    showToast('You do not have permission to delete follow-ups.', 'danger', 'Access Denied');
                    return;
                }

                const ok = confirm('Delete this follow-up?');
                if (!ok) return;

                const formData = new FormData(form);
                fetch('api/followups.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        showToast(data.message || (data.status ? 'Follow-up deleted.' :
                                'Delete failed.'), data.status ? 'success' : 'danger', data
                            .status ? 'Success' : 'Failed');

                        if (data.status) {
                            setTimeout(() => window.location.reload(), 800);
                        }
                    })
                    .catch(() => showToast('API request failed.', 'danger', 'Failed'));
            });
        });


        document.querySelectorAll('.js-complete-followup').forEach(function(button) {
            button.addEventListener('click', function() {
                if (!window.followupsPermissions.canEdit && !window.followupsPermissions.canUpdate) {
                    showToast('You do not have permission to update follow-ups.', 'danger', 'Access Denied');
                    return;
                }

                if (!confirm('Mark this follow-up as completed?')) return;

                const formData = new FormData();
                formData.append('action', 'complete_reminder');
                formData.append('id', button.dataset.id || '0');
                formData.append('csrf_token', '<?= e($csrfToken) ?>');

                button.disabled = true;
                fetch('api/followups.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        showToast(data.message || (data.status ? 'Follow-up completed.' : 'Update failed.'),
                            data.status ? 'success' : 'danger', data.status ? 'Completed' : 'Failed');
                        if (data.status) {
                            setTimeout(() => window.location.reload(), 650);
                        } else {
                            button.disabled = false;
                        }
                    })
                    .catch(() => {
                        button.disabled = false;
                        showToast('API request failed.', 'danger', 'Failed');
                    });
            });
        });

        const focusId = <?= (int)$focusId ?>;
        if (focusId > 0) {
            const focusEl = document.getElementById('followup-row-' + focusId) || document.getElementById('followup-mobile-' + focusId);
            if (focusEl) {
                setTimeout(() => focusEl.scrollIntoView({behavior: 'smooth', block: 'center'}), 250);
            }
        }

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