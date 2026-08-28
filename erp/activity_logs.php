<?php
/**
 * activity_logs.php
 * Subhiksha Cards ERP - Role based activity log viewer
 */
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'activity_logs.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$currentPage = 'activity_logs.php';

function actTableExists(mysqli $conn, string $table): bool
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

function actColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) {
            $res->free();
        }
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function actCan(mysqli $conn, string $functionName, string $page): bool
{
    if (function_exists($functionName)) {
        try {
            return (bool)$functionName($conn, $page);
        } catch (Throwable $e) {
            return false;
        }
    }
    return false;
}

if (!defined('ACT_DATABASE_TIMEZONE')) {
    define('ACT_DATABASE_TIMEZONE', 'UTC');
}

if (!defined('ACT_SYSTEM_TIMEZONE')) {
    define('ACT_SYSTEM_TIMEZONE', 'Asia/Kolkata');
}

date_default_timezone_set(ACT_SYSTEM_TIMEZONE);

function actDateTime($value): string
{
    if (empty($value)) {
        return '-';
    }

    try {
        $dbTz = new DateTimeZone(ACT_DATABASE_TIMEZONE);
        $systemTz = new DateTimeZone(ACT_SYSTEM_TIMEZONE);

        $dt = DateTime::createFromFormat(
            'Y-m-d H:i:s',
            trim((string)$value),
            $dbTz
        );

        if (!$dt) {
            $dt = new DateTime((string)$value, $dbTz);
        }

        $dt->setTimezone($systemTz);

        return $dt->format('d-m-Y h:i A');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function actLocalDateStartUtc(string $date): ?string
{
    $date = trim($date);
    if ($date === '') return null;

    try {
        $systemTz = new DateTimeZone(ACT_SYSTEM_TIMEZONE);
        $dbTz = new DateTimeZone(ACT_DATABASE_TIMEZONE);

        $dt = DateTime::createFromFormat('!Y-m-d', $date, $systemTz);
        if (!$dt) return null;

        $dt->setTimezone($dbTz);
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function actLocalDateEndExclusiveUtc(string $date): ?string
{
    $date = trim($date);
    if ($date === '') return null;

    try {
        $systemTz = new DateTimeZone(ACT_SYSTEM_TIMEZONE);
        $dbTz = new DateTimeZone(ACT_DATABASE_TIMEZONE);

        $dt = DateTime::createFromFormat('!Y-m-d', $date, $systemTz);
        if (!$dt) return null;

        $dt->modify('+1 day');
        $dt->setTimezone($dbTz);

        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function actActionClass(string $actionKey): string
{
    $key = strtolower($actionKey);
    if (str_contains($key, 'create') || str_contains($key, 'add') || str_contains($key, 'insert')) {
        return 'success';
    }
    if (str_contains($key, 'update') || str_contains($key, 'edit') || str_contains($key, 'save')) {
        return 'warning';
    }
    if (str_contains($key, 'delete') || str_contains($key, 'remove') || str_contains($key, 'cancel')) {
        return 'danger';
    }
    if (str_contains($key, 'login') || str_contains($key, 'logout')) {
        return 'info';
    }
    if (str_contains($key, 'whatsapp') || str_contains($key, 'approve')) {
        return 'primary';
    }
    return 'neutral';
}

$canExport = actCan($conn, 'can_export', $currentPage);
$canDelete = actCan($conn, 'can_delete', $currentPage);

$message = '';
$messageType = 'success';
$toastTitle = 'Info';

if (!actTableExists($conn, 'activity_logs')) {
    $message = 'activity_logs table is missing.';
    $messageType = 'danger';
    $toastTitle = 'Failed';
}

$module = trim((string)($_GET['module'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$userFilter = (int)($_GET['user_id'] ?? 0);
$roleFilter = (int)($_GET['role_id'] ?? 0);
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$keyword = trim((string)($_GET['keyword'] ?? ''));
$isExport = isset($_GET['export']) && $_GET['export'] === 'csv';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$where = [];
$types = '';
$params = [];

if ($module !== '') {
    $where[] = 'al.module_name LIKE ?';
    $types .= 's';
    $params[] = '%' . $module . '%';
}
if ($action !== '') {
    $where[] = 'al.action_key LIKE ?';
    $types .= 's';
    $params[] = '%' . $action . '%';
}
if ($userFilter > 0) {
    $where[] = 'al.user_id = ?';
    $types .= 'i';
    $params[] = $userFilter;
}
if ($roleFilter > 0) {
    $where[] = 'al.role_id = ?';
    $types .= 'i';
    $params[] = $roleFilter;
}
if ($from !== '') {
    $fromUtc = actLocalDateStartUtc($from);
    if ($fromUtc !== null) {
        $where[] = 'al.created_at >= ?';
        $types .= 's';
        $params[] = $fromUtc;
    }
}

if ($to !== '') {
    $toUtcExclusive = actLocalDateEndExclusiveUtc($to);
    if ($toUtcExclusive !== null) {
        $where[] = 'al.created_at < ?';
        $types .= 's';
        $params[] = $toUtcExclusive;
    }
}
if ($keyword !== '') {
    $where[] = '(al.description LIKE ? OR al.table_name LIKE ? OR al.record_id LIKE ? OR al.ip_address LIKE ?)';
    $types .= 'ssss';
    $likeKeyword = '%' . $keyword . '%';
    $params[] = $likeKeyword;
    $params[] = $likeKeyword;
    $params[] = $likeKeyword;
    $params[] = $likeKeyword;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$userNameExpr = "CONCAT('User #', COALESCE(u.id, al.user_id, 0))";
if (actTableExists($conn, 'users')) {
    $hasName = actColumnExists($conn, 'users', 'name');
    $hasUsername = actColumnExists($conn, 'users', 'username');
    if ($hasName && $hasUsername) {
        $userNameExpr = "COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), CONCAT('User #', u.id), 'System')";
    } elseif ($hasName) {
        $userNameExpr = "COALESCE(NULLIF(u.name,''), CONCAT('User #', u.id), 'System')";
    } elseif ($hasUsername) {
        $userNameExpr = "COALESCE(NULLIF(u.username,''), CONCAT('User #', u.id), 'System')";
    }
}

$roleNameExpr = "CONCAT('Role #', COALESCE(r.id, al.role_id, 0))";
if (actTableExists($conn, 'roles')) {
    $hasRoleName = actColumnExists($conn, 'roles', 'role_name');
    $hasRoleTitle = actColumnExists($conn, 'roles', 'name');
    $hasRoleKey = actColumnExists($conn, 'roles', 'role_key');
    if ($hasRoleName && $hasRoleKey) {
        $roleNameExpr = "COALESCE(NULLIF(r.role_name,''), NULLIF(r.role_key,''), CONCAT('Role #', r.id), '')";
    } elseif ($hasRoleName) {
        $roleNameExpr = "COALESCE(NULLIF(r.role_name,''), CONCAT('Role #', r.id), '')";
    } elseif ($hasRoleTitle) {
        $roleNameExpr = "COALESCE(NULLIF(r.name,''), CONCAT('Role #', r.id), '')";
    } elseif ($hasRoleKey) {
        $roleNameExpr = "COALESCE(NULLIF(r.role_key,''), CONCAT('Role #', r.id), '')";
    }
}

$logs = [];
$totalLogs = 0;
$createLogs = 0;
$updateLogs = 0;
$deleteLogs = 0;
$todayLogs = 0;
$totalPages = 1;
$offset = 0;
$showFrom = 0;
$showTo = 0;

if (actTableExists($conn, 'activity_logs')) {
    try {
        /*
         * Summary values always use the complete filtered result,
         * not only the current page.
         */
        $summarySql = "
            SELECT
                COUNT(*) AS total_logs,
                COALESCE(SUM(
                    CASE
                        WHEN LOWER(COALESCE(al.action_key,'')) LIKE '%create%'
                          OR LOWER(COALESCE(al.action_key,'')) LIKE '%add%'
                          OR LOWER(COALESCE(al.action_key,'')) LIKE '%insert%'
                        THEN 1 ELSE 0
                    END
                ),0) AS create_logs,
                COALESCE(SUM(
                    CASE
                        WHEN LOWER(COALESCE(al.action_key,'')) LIKE '%update%'
                          OR LOWER(COALESCE(al.action_key,'')) LIKE '%edit%'
                          OR LOWER(COALESCE(al.action_key,'')) LIKE '%save%'
                        THEN 1 ELSE 0
                    END
                ),0) AS update_logs,
                COALESCE(SUM(
                    CASE
                        WHEN LOWER(COALESCE(al.action_key,'')) LIKE '%delete%'
                          OR LOWER(COALESCE(al.action_key,'')) LIKE '%remove%'
                          OR LOWER(COALESCE(al.action_key,'')) LIKE '%cancel%'
                        THEN 1 ELSE 0
                    END
                ),0) AS delete_logs
            FROM activity_logs al
            LEFT JOIN users u ON u.id = al.user_id
            LEFT JOIN roles r ON r.id = al.role_id
            {$whereSql}
        ";

        $summaryStmt = $conn->prepare($summarySql);
        if ($params) {
            $summaryStmt->bind_param($types, ...$params);
        }
        $summaryStmt->execute();
        $summary = $summaryStmt->get_result()->fetch_assoc();
        $summaryStmt->close();

        $totalLogs = (int)($summary['total_logs'] ?? 0);
        $createLogs = (int)($summary['create_logs'] ?? 0);
        $updateLogs = (int)($summary['update_logs'] ?? 0);
        $deleteLogs = (int)($summary['delete_logs'] ?? 0);

        /*
         * "Today Logs" is based on the current India date while the database
         * stores UTC timestamps.
         */
        $todayLocal = (new DateTime('now', new DateTimeZone(ACT_SYSTEM_TIMEZONE)))->format('Y-m-d');
        $todayStartUtc = actLocalDateStartUtc($todayLocal);
        $todayEndUtc = actLocalDateEndExclusiveUtc($todayLocal);

        if ($todayStartUtc !== null && $todayEndUtc !== null) {
            $todayWhere = $where;
            $todayTypes = $types . 'ss';
            $todayParams = $params;
            $todayWhere[] = 'al.created_at >= ?';
            $todayWhere[] = 'al.created_at < ?';
            $todayParams[] = $todayStartUtc;
            $todayParams[] = $todayEndUtc;

            $todaySql = "
                SELECT COUNT(*) AS total
                FROM activity_logs al
                LEFT JOIN users u ON u.id = al.user_id
                LEFT JOIN roles r ON r.id = al.role_id
                WHERE " . implode(' AND ', $todayWhere);

            $todayStmt = $conn->prepare($todaySql);
            $todayStmt->bind_param($todayTypes, ...$todayParams);
            $todayStmt->execute();
            $todayRow = $todayStmt->get_result()->fetch_assoc();
            $todayStmt->close();

            $todayLogs = (int)($todayRow['total'] ?? 0);
        }

        if ($isExport && $canExport) {
            /*
             * CSV export returns the complete filtered result.
             * No artificial 3000-row cap.
             */
            $sql = "
                SELECT
                    al.*,
                    {$userNameExpr} AS user_name,
                    {$roleNameExpr} AS role_name
                FROM activity_logs al
                LEFT JOIN users u ON u.id = al.user_id
                LEFT JOIN roles r ON r.id = al.role_id
                {$whereSql}
                ORDER BY al.id DESC
            ";

            $stmt = $conn->prepare($sql);
            if ($params) {
                $stmt->bind_param($types, ...$params);
            }
        } else {
            /*
             * Normal page uses database pagination so every matching log
             * remains accessible without loading hundreds of rows at once.
             */
            $totalPages = max(1, (int)ceil($totalLogs / $perPage));

            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $offset = ($page - 1) * $perPage;

            $sql = "
                SELECT
                    al.*,
                    {$userNameExpr} AS user_name,
                    {$roleNameExpr} AS role_name
                FROM activity_logs al
                LEFT JOIN users u ON u.id = al.user_id
                LEFT JOIN roles r ON r.id = al.role_id
                {$whereSql}
                ORDER BY al.id DESC
                LIMIT ? OFFSET ?
            ";

            $stmt = $conn->prepare($sql);
            $pagedTypes = $types . 'ii';
            $pagedParams = $params;
            $pagedParams[] = $perPage;
            $pagedParams[] = $offset;
            $stmt->bind_param($pagedTypes, ...$pagedParams);
        }

        $stmt->execute();
        $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $showFrom = $totalLogs > 0 ? $offset + 1 : 0;
        $showTo = $totalLogs > 0 ? min($offset + count($logs), $totalLogs) : 0;
    } catch (Throwable $e) {
        $message = 'Unable to load activity logs: ' . $e->getMessage();
        $messageType = 'danger';
        $toastTitle = 'Failed';
        $logs = [];
    }
}

if ($isExport) {
    if (!$canExport) {
        http_response_code(403);
        die('Export permission denied.');
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_logs_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'User', 'Role', 'Action', 'Module', 'Table', 'Record ID', 'Description', 'IP Address', 'User Agent']);
    foreach ($logs as $log) {
        fputcsv($out, [
            actDateTime($log['created_at'] ?? null),
            $log['user_name'] ?? 'System',
            $log['role_name'] ?? '',
            $log['action_key'] ?? '',
            $log['module_name'] ?? '',
            $log['table_name'] ?? '',
            $log['record_id'] ?? '',
            $log['description'] ?? '',
            $log['ip_address'] ?? '',
            $log['user_agent'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$filterUsers = [];
$filterRoles = [];
$filterModules = [];
$filterActions = [];

try {
    if (actTableExists($conn, 'activity_logs')) {
        $res = $conn->query("SELECT DISTINCT module_name FROM activity_logs WHERE TRIM(module_name) <> '' ORDER BY module_name ASC LIMIT 200");
        while ($res && $row = $res->fetch_assoc()) {
            $filterModules[] = $row['module_name'];
        }
        if ($res) $res->free();

        $res = $conn->query("SELECT DISTINCT action_key FROM activity_logs WHERE TRIM(action_key) <> '' ORDER BY action_key ASC LIMIT 200");
        while ($res && $row = $res->fetch_assoc()) {
            $filterActions[] = $row['action_key'];
        }
        if ($res) $res->free();
    }

    if (actTableExists($conn, 'users')) {
        $res = $conn->query("SELECT id, {$userNameExpr} AS display_name FROM users u ORDER BY display_name ASC LIMIT 500");
        while ($res && $row = $res->fetch_assoc()) {
            $filterUsers[] = $row;
        }
        if ($res) $res->free();
    }

    if (actTableExists($conn, 'roles')) {
        $res = $conn->query("SELECT id, {$roleNameExpr} AS display_name FROM roles r ORDER BY display_name ASC LIMIT 100");
        while ($res && $row = $res->fetch_assoc()) {
            $filterRoles[] = $row;
        }
        if ($res) $res->free();
    }
} catch (Throwable $e) {
    // Filters are optional; page should still load.
}

$queryString = $_GET;
unset($queryString['export']);

$exportQuery = $queryString;
unset($exportQuery['page']);
$exportUrl = 'activity_logs.php?' . http_build_query(array_merge($exportQuery, ['export' => 'csv']));

function actPageUrl(int $targetPage): string
{
    $query = $_GET;
    unset($query['export']);
    $query['page'] = max(1, $targetPage);

    return 'activity_logs.php?' . http_build_query($query);
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Activity Logs - Subhiksha Cards</title>
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

    .form-control,
    .form-select {
        border-radius: 14px;
        min-height: 46px;
    }

    .action-pill {
        font-size: 11px;
        font-weight: 900;
        border-radius: 999px;
        padding: 6px 10px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .action-pill.success {
        color: #166534;
        background: #dcfce7;
    }

    .action-pill.warning {
        color: #92400e;
        background: #fef3c7;
    }

    .action-pill.danger {
        color: #991b1b;
        background: #fee2e2;
    }

    .action-pill.info {
        color: #075985;
        background: #e0f2fe;
    }

    .action-pill.primary {
        color: #1d4ed8;
        background: #dbeafe;
    }

    .action-pill.neutral {
        color: var(--text-muted);
        background: color-mix(in srgb, var(--text-muted) 12%, transparent);
    }

    .small-muted {
        display: block;
        margin-top: 3px;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 700;
        word-break: break-word;
    }

    .log-description {
        max-width: 440px;
        white-space: normal;
        word-break: break-word;
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

    @media(max-width:767.98px) {
        .module-page .page-head {
            padding: 18px;
            border-radius: 18px;
        }

        .module-page .page-head h1 {
            font-size: 24px;
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
    }

    /* Compact Activity Logs UI */
    .module-page .page-head {
        padding: 14px 16px !important;
        margin-bottom: 10px !important;
        border-radius: 14px !important;
    }

    .module-page .page-head h1 {
        font-size: 22px !important;
        margin-bottom: 2px !important;
    }

    .module-page .page-head p {
        font-size: 11px !important;
        margin-bottom: 0 !important;
    }

    .module-card {
        padding: 12px !important;
        border-radius: 14px !important;
        margin-bottom: 10px !important;
    }

    .module-title {
        font-size: 14px !important;
    }

    .stat-card {
        padding: 10px 12px !important;
        min-height: 74px !important;
        border-radius: 14px !important;
        gap: 9px !important;
    }

    .stat-icon {
        width: 38px !important;
        height: 38px !important;
        border-radius: 11px !important;
    }

    .stat-card span {
        font-size: 9px !important;
    }

    .stat-card strong {
        font-size: 18px !important;
    }

    .module-page .form-control,
    .module-page .form-select {
        min-height: 36px !important;
        height: 36px !important;
        border-radius: 9px !important;
        font-size: 11.5px !important;
        padding: 5px 8px !important;
    }

    .module-page .form-label {
        font-size: 10px !important;
        margin-bottom: 3px !important;
    }

    .module-page .btn {
        font-size: 11px !important;
        padding: 6px 10px !important;
    }

    .table-ui th {
        font-size: 9px !important;
        padding: 7px 8px !important;
    }

    .table-ui td {
        font-size: 10.5px !important;
        padding: 7px 8px !important;
        line-height: 1.25 !important;
    }

    .action-pill {
        font-size: 9px !important;
        padding: 3px 7px !important;
    }

    .small-muted,
    .mobile-card-subtitle {
        font-size: 9.5px !important;
    }

    .log-description {
        max-width: 360px !important;
        font-weight: 600 !important;
    }

    .activity-pagination-wrap {
        border-top: 1px solid var(--border-soft);
        padding-top: 10px;
        margin-top: 10px;
    }

    .activity-pagination-wrap .page-link {
        min-width: 30px;
        height: 30px;
        padding: 4px 7px;
        border-radius: 8px !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 800;
    }

    @media(max-width:767.98px) {
        .mobile-card {
            padding: 11px !important;
            border-radius: 13px !important;
            margin-bottom: 8px !important;
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
                            <h1 class="mb-1">Activity Logs</h1>
                            <p class="text-muted-custom mb-0">Role based audit history for create, update, delete,
                                login, WhatsApp, approval and other user actions.</p>
                        </div>

                        <div class="d-flex flex-column flex-sm-row gap-2">
                            <?php if ($canExport): ?>
                            <a href="<?= e($exportUrl) ?>" class="btn btn-outline-primary rounded-pill px-4 fw-bold">
                                <i data-lucide="download"></i> Export CSV
                            </a>
                            <?php endif; ?>
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

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)"><i
                                    data-lucide="history"></i></div>
                            <div><span>Loaded Logs</span><strong><?= number_format($totalLogs) ?></strong></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)"><i
                                    data-lucide="plus-circle"></i></div>
                            <div><span>Create Actions</span><strong><?= number_format($createLogs) ?></strong></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)"><i
                                    data-lucide="pencil"></i></div>
                            <div><span>Update Actions</span><strong><?= number_format($updateLogs) ?></strong></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#8b5cf6,#a855f7)"><i
                                    data-lucide="calendar-clock"></i></div>
                            <div><span>Today Logs</span><strong><?= number_format($todayLogs) ?></strong></div>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card mb-3">
                    <form method="get" class="row g-3 align-items-end">
                        <div class="col-12 col-md-6 col-xl-2">
                            <label class="form-label fw-bold">Module</label>
                            <select name="module" class="form-select">
                                <option value="">All Modules</option>
                                <?php foreach ($filterModules as $m): ?>
                                <option value="<?= e($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= e($m) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <label class="form-label fw-bold">Action</label>
                            <select name="action" class="form-select">
                                <option value="">All Actions</option>
                                <?php foreach ($filterActions as $a): ?>
                                <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <label class="form-label fw-bold">User</label>
                            <select name="user_id" class="form-select">
                                <option value="0">All Users</option>
                                <?php foreach ($filterUsers as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"
                                    <?= $userFilter === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['display_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <label class="form-label fw-bold">Role</label>
                            <select name="role_id" class="form-select">
                                <option value="0">All Roles</option>
                                <?php foreach ($filterRoles as $r): ?>
                                <option value="<?= (int)$r['id'] ?>"
                                    <?= $roleFilter === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['display_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <label class="form-label fw-bold">From</label>
                            <input name="from" type="date" class="form-control" value="<?= e($from) ?>">
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <label class="form-label fw-bold">To</label>
                            <input name="to" type="date" class="form-control" value="<?= e($to) ?>">
                        </div>
                        <div class="col-12 col-lg-9">
                            <label class="form-label fw-bold">Keyword</label>
                            <input name="keyword" class="form-control"
                                placeholder="Search description, table, record id, IP..." value="<?= e($keyword) ?>">
                        </div>
                        <div class="col-12 col-lg-3 d-flex gap-2">
                            <button class="btn btn-primary rounded-pill fw-bold flex-fill" type="submit">
                                <i data-lucide="filter"></i> Filter
                            </button>
                            <a href="activity_logs.php"
                                class="btn btn-outline-secondary rounded-pill fw-bold flex-fill">Reset</a>
                        </div>
                    </form>
                </div>

                <div class="card-ui module-card">
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Latest Logs</h2>
                            <p class="text-muted-custom mb-0">All matching audit records are available with compact
                                pagination.</p>
                        </div>
                        <div style="max-width:340px;width:100%">
                            <input type="search" id="tableSearch" class="form-control"
                                placeholder="Search loaded logs...">
                        </div>
                    </div>

                    <div class="table-responsive desktop-table">
                        <table class="table-ui" id="dataTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Module</th>
                                    <th>Description</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$logs): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted-custom py-4">No activity logs found.
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach ($logs as $log): ?>
                                <?php $actionClass = actActionClass((string)($log['action_key'] ?? '')); ?>
                                <tr>
                                    <td>
                                        <strong><?= e(actDateTime($log['created_at'] ?? null)) ?></strong>
                                        <span class="small-muted">#<?= e($log['id'] ?? '-') ?></span>
                                    </td>
                                    <td>
                                        <strong><?= e($log['user_name'] ?? 'System') ?></strong>
                                        <span class="small-muted"><?= e($log['role_name'] ?? '') ?></span>
                                    </td>
                                    <td><span
                                            class="action-pill <?= e($actionClass) ?>"><?= e($log['action_key'] ?? '-') ?></span>
                                    </td>
                                    <td>
                                        <strong><?= e($log['module_name'] ?? '-') ?></strong>
                                        <span
                                            class="small-muted"><?= e($log['table_name'] ?? '') ?><?= !empty($log['record_id']) ? ' #' . e($log['record_id']) : '' ?></span>
                                    </td>
                                    <td class="log-description"><?= e($log['description'] ?? '-') ?><span
                                            class="small-muted"><?= e($log['user_agent'] ?? '') ?></span></td>
                                    <td><?= e($log['ip_address'] ?? '-') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-cards" id="mobileCards">
                        <?php if (!$logs): ?>
                        <div class="mobile-card text-center text-muted-custom">No activity logs found.</div>
                        <?php endif; ?>
                        <?php foreach ($logs as $log): ?>
                        <?php $actionClass = actActionClass((string)($log['action_key'] ?? '')); ?>
                        <div class="mobile-card">
                            <div class="d-flex justify-content-between gap-2 align-items-start">
                                <div>
                                    <div class="mobile-card-title"><?= e($log['module_name'] ?? '-') ?></div>
                                    <span
                                        class="mobile-card-subtitle"><?= e(actDateTime($log['created_at'] ?? null)) ?></span>
                                    <span class="mobile-card-subtitle">User:
                                        <?= e($log['user_name'] ?? 'System') ?><?= !empty($log['role_name']) ? ' / ' . e($log['role_name']) : '' ?></span>
                                </div>
                                <span
                                    class="action-pill <?= e($actionClass) ?>"><?= e($log['action_key'] ?? '-') ?></span>
                            </div>
                            <span class="mobile-card-subtitle mt-2">Description:
                                <?= e($log['description'] ?? '-') ?></span>
                            <span class="mobile-card-subtitle">Record:
                                <?= e($log['table_name'] ?? '-') ?><?= !empty($log['record_id']) ? ' #' . e($log['record_id']) : '' ?></span>
                            <span class="mobile-card-subtitle">IP: <?= e($log['ip_address'] ?? '-') ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <?php
                    $windowStart = max(1, $page - 2);
                    $windowEnd = min($totalPages, $windowStart + 4);
                    $windowStart = max(1, $windowEnd - 4);
                    ?>
                    <div
                        class="activity-pagination-wrap d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2">
                        <div class="text-muted-custom fw-bold small">
                            Showing <?= number_format($showFrom) ?>-<?= number_format($showTo) ?>
                            of <?= number_format($totalLogs) ?> records
                        </div>

                        <nav aria-label="Activity Logs Pagination">
                            <ul class="pagination pagination-sm mb-0 flex-wrap">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page > 1 ? e(actPageUrl($page - 1)) : '#' ?>"
                                        aria-label="Previous">‹</a>
                                </li>

                                <?php if ($windowStart > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= e(actPageUrl(1)) ?>">1</a>
                                </li>
                                <?php if ($windowStart > 2): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= e(actPageUrl($p)) ?>"
                                        <?= $p === $page ? 'aria-current="page"' : '' ?>>
                                        <?= $p ?>
                                    </a>
                                </li>
                                <?php endfor; ?>

                                <?php if ($windowEnd < $totalPages): ?>
                                <?php if ($windowEnd < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= e(actPageUrl($totalPages)) ?>">
                                        <?= $totalPages ?>
                                    </a>
                                </li>
                                <?php endif; ?>

                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link"
                                        href="<?= $page < $totalPages ? e(actPageUrl($page + 1)) : '#' ?>"
                                        aria-label="Next">›</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>
    <script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }

    const pageToastEl = document.getElementById('pageToast');
    if (pageToastEl && window.bootstrap && bootstrap.Toast) {
        bootstrap.Toast.getOrCreateInstance(pageToastEl).show();
    }

    /*
     * Quick search applies to the current page only.
     * Use the Keyword filter above when searching the full activity history.
     */
    document.getElementById('tableSearch')?.addEventListener('input', function() {
        const value = this.value.toLowerCase().trim();

        document.querySelectorAll('#dataTable tbody tr').forEach(function(row) {
            row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
        });

        document.querySelectorAll('#mobileCards .mobile-card').forEach(function(card) {
            card.style.display = card.textContent.toLowerCase().includes(value) ? '' : 'none';
        });
    });
    </script>
</body>

</html>