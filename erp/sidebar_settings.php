<?php
/**
 * sidebar_settings.php
 * Subhiksha Cards ERP - Sidebar Control
 * Reference format based on manage-sidebar.php.
 * Works with current Subhiksha table: sidebar_items.
 */

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'sidebar_settings.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['sidebar_settings_csrf'])) {
    $_SESSION['sidebar_settings_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['sidebar_settings_csrf'];
$message = '';
$messageType = 'success';
$toastTitle = 'Info';
$pageTitle = 'Sidebar Control';

function ss_table_exists(mysqli $conn, string $table): bool
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

function ss_table_has_column(mysqli $conn, string $table, string $column): bool
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

function ss_ensure_sidebar_column(mysqli $conn): bool
{
    if (!ss_table_exists($conn, 'sidebar_items')) {
        return false;
    }

    if (ss_table_has_column($conn, 'sidebar_items', 'show_in_sidebar')) {
        return true;
    }

    try {
        $conn->query("ALTER TABLE sidebar_items ADD COLUMN show_in_sidebar TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");
    } catch (Throwable $e) {
        // If ALTER is not allowed, the page still works by falling back to is_active.
    }

    return ss_table_has_column($conn, 'sidebar_items', 'show_in_sidebar');
}

function ss_slug(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '_', $text);
    $text = trim((string)$text, '_');
    return $text !== '' ? $text : 'menu_' . time();
}

function ss_int($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function ss_post_string(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function ss_current_page(): string
{
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? 'sidebar_settings.php'), PHP_URL_PATH);
    $base = basename($path);
    return $base !== '' ? $base : 'sidebar_settings.php';
}

function ss_page_url(string $query = ''): string
{
    $url = ss_current_page();
    if ($query !== '') {
        $url .= '?' . ltrim($query, '?');
    }
    return $url;
}

function ss_redirect(string $extra = ''): void
{
    header('Location: ' . ss_page_url($extra));
    exit;
}

function ss_require_csrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['sidebar_settings_csrf']) ||
        !hash_equals($_SESSION['sidebar_settings_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function ss_filter_value(array $menu): string
{
    $key = trim((string)($menu['menu_key'] ?? ''));
    return $key !== '' ? $key : 'id_' . (int)$menu['id'];
}

function ss_parent_name(array $parents, ?int $parentId): string
{
    if (!$parentId) {
        return '';
    }

    foreach ($parents as $parent) {
        if ((int)$parent['id'] === (int)$parentId) {
            return (string)$parent['menu_title'];
        }
    }

    return '';
}

function ss_get_sidebar_items(mysqli $conn, bool $hasShowInSidebar): array
{
    $rows = [];

    try {
        if (!ss_table_exists($conn, 'sidebar_items')) {
            return [];
        }

        $showSelect = $hasShowInSidebar ? ', show_in_sidebar' : '';
        $res = $conn->query("
            SELECT
                id,
                parent_id,
                menu_key,
                menu_title,
                page_title,
                route,
                icon,
                sort_order,
                is_header,
                is_active,
                created_at,
                updated_at
                {$showSelect}
            FROM sidebar_items
            ORDER BY
                CASE WHEN parent_id IS NULL THEN sort_order ELSE 999999 END,
                COALESCE(parent_id, id),
                parent_id IS NOT NULL,
                sort_order,
                id
        ");

        while ($row = $res->fetch_assoc()) {
            if (!$hasShowInSidebar) {
                $row['show_in_sidebar'] = (int)($row['is_active'] ?? 0);
            }
            $rows[] = $row;
        }

        $res->free();
    } catch (Throwable $e) {
        $rows = [];
    }

    return $rows;
}

$hasShowInSidebar = ss_ensure_sidebar_column($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ss_require_csrf();
    $action = ss_post_string('action');

    try {
        if (!ss_table_exists($conn, 'sidebar_items')) {
            throw new RuntimeException('sidebar_items table is missing.');
        }

        if ($action === 'save_menu') {
            $id = ss_int($_POST['id'] ?? 0);
            $parentIdRaw = ss_int($_POST['parent_id'] ?? 0);
            $parentId = $parentIdRaw > 0 ? $parentIdRaw : null;

            $menuTitle = ss_post_string('menu_title');
            $pageTitle = ss_post_string('page_title');
            $menuKey = ss_post_string('menu_key');
            $route = ss_post_string('route', '#');
            $icon = ss_post_string('icon', 'circle');
            $sortOrder = ss_int($_POST['sort_order'] ?? 0);
            $isHeader = isset($_POST['is_header']) ? 1 : 0;
            $isActive = isset($_POST['is_active']) ? ss_int($_POST['is_active']) : 1;
            $isActive = $isActive === 1 ? 1 : 0;
            $showInSidebar = isset($_POST['show_in_sidebar']) ? ss_int($_POST['show_in_sidebar']) : 1;
            $showInSidebar = $showInSidebar === 1 ? 1 : 0;

            if ($menuTitle === '') {
                throw new RuntimeException('Menu title is required.');
            }

            $menuKey = $menuKey !== '' ? ss_slug($menuKey) : ss_slug($menuTitle);
            $pageTitle = $pageTitle !== '' ? $pageTitle : $menuTitle;
            $route = $route !== '' ? $route : '#';
            $icon = $icon !== '' ? $icon : 'circle';

            if ($id > 0 && $parentId === $id) {
                $parentId = null;
            }

            if ($id > 0) {
                if ($hasShowInSidebar) {
                    $stmt = $conn->prepare("
                        UPDATE sidebar_items
                        SET
                            parent_id = ?,
                            menu_key = ?,
                            menu_title = ?,
                            page_title = ?,
                            route = ?,
                            icon = ?,
                            sort_order = ?,
                            is_header = ?,
                            is_active = ?,
                            show_in_sidebar = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->bind_param(
                        'isssssiiiii',
                        $parentId,
                        $menuKey,
                        $menuTitle,
                        $pageTitle,
                        $route,
                        $icon,
                        $sortOrder,
                        $isHeader,
                        $isActive,
                        $showInSidebar,
                        $id
                    );
                } else {
                    $stmt = $conn->prepare("
                        UPDATE sidebar_items
                        SET
                            parent_id = ?,
                            menu_key = ?,
                            menu_title = ?,
                            page_title = ?,
                            route = ?,
                            icon = ?,
                            sort_order = ?,
                            is_header = ?,
                            is_active = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->bind_param(
                        'isssssiiii',
                        $parentId,
                        $menuKey,
                        $menuTitle,
                        $pageTitle,
                        $route,
                        $icon,
                        $sortOrder,
                        $isHeader,
                        $isActive,
                        $id
                    );
                }

                $stmt->execute();
                $stmt->close();
                ss_redirect('msg=updated');
            }

            if ($hasShowInSidebar) {
                $stmt = $conn->prepare("
                    INSERT INTO sidebar_items
                        (parent_id, menu_key, menu_title, page_title, route, icon, sort_order, is_header, is_active, show_in_sidebar, created_at, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->bind_param(
                    'isssssiiii',
                    $parentId,
                    $menuKey,
                    $menuTitle,
                    $pageTitle,
                    $route,
                    $icon,
                    $sortOrder,
                    $isHeader,
                    $isActive,
                    $showInSidebar
                );
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO sidebar_items
                        (parent_id, menu_key, menu_title, page_title, route, icon, sort_order, is_header, is_active, created_at, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->bind_param(
                    'isssssiii',
                    $parentId,
                    $menuKey,
                    $menuTitle,
                    $pageTitle,
                    $route,
                    $icon,
                    $sortOrder,
                    $isHeader,
                    $isActive
                );
            }

            $stmt->execute();
            $stmt->close();
            ss_redirect('msg=created');
        }

        if ($action === 'toggle_active') {
            $id = ss_int($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid menu.');
            }

            $stmt = $conn->prepare("UPDATE sidebar_items SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            ss_redirect('msg=status_updated');
        }

        if ($action === 'toggle_sidebar') {
            $id = ss_int($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid menu.');
            }

            if ($hasShowInSidebar) {
                $stmt = $conn->prepare("UPDATE sidebar_items SET show_in_sidebar = IF(show_in_sidebar = 1, 0, 1), updated_at = NOW() WHERE id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE sidebar_items SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW() WHERE id = ?");
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            ss_redirect('msg=sidebar_updated');
        }

        if ($action === 'sort_one') {
            $id = ss_int($_POST['id'] ?? 0);
            $sortOrder = ss_int($_POST['sort_order'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid menu.');
            }

            $stmt = $conn->prepare("UPDATE sidebar_items SET sort_order = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $sortOrder, $id);
            $stmt->execute();
            $stmt->close();
            ss_redirect('msg=sort_updated');
        }

        if ($action === 'bulk_sort') {
            $sortRows = $_POST['sort_order'] ?? [];
            if (!is_array($sortRows)) {
                throw new RuntimeException('Invalid sort data.');
            }

            $stmt = $conn->prepare("UPDATE sidebar_items SET sort_order = ?, updated_at = NOW() WHERE id = ?");
            foreach ($sortRows as $menuId => $sortOrder) {
                $menuId = ss_int($menuId);
                $sortOrder = ss_int($sortOrder);
                if ($menuId <= 0) {
                    continue;
                }
                $stmt->bind_param('ii', $sortOrder, $menuId);
                $stmt->execute();
            }
            $stmt->close();
            ss_redirect('msg=sort_updated');
        }

        if ($action === 'delete_menu') {
            $id = ss_int($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid menu.');
            }

            if ($hasShowInSidebar) {
                $stmt = $conn->prepare("
                    UPDATE sidebar_items
                    SET is_active = 0, show_in_sidebar = 0, updated_at = NOW()
                    WHERE id = ? OR parent_id = ?
                ");
            } else {
                $stmt = $conn->prepare("
                    UPDATE sidebar_items
                    SET is_active = 0, updated_at = NOW()
                    WHERE id = ? OR parent_id = ?
                ");
            }
            $stmt->bind_param('ii', $id, $id);
            $stmt->execute();
            $stmt->close();
            ss_redirect('msg=deleted');
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
        $toastTitle = 'Failed';
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') {
    $message = 'Sidebar menu created successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'updated') {
    $message = 'Sidebar menu updated successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'deleted') {
    $message = 'Sidebar menu deleted successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'status_updated') {
    $message = 'Sidebar status updated successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'sidebar_updated') {
    $message = 'Sidebar visibility updated successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'sort_updated') {
    $message = 'Sidebar sort order updated successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
}

$filter = trim((string)($_GET['filter'] ?? 'all'));
$allMenus = ss_get_sidebar_items($conn, $hasShowInSidebar);
$parents = [];
foreach ($allMenus as $row) {
    if (empty($row['parent_id'])) {
        $parents[] = $row;
    }
}

$menus = [];
foreach ($allMenus as $row) {
    if ($filter === 'all') {
        $menus[] = $row;
        continue;
    }

    if (ss_filter_value($row) === $filter) {
        $menus[] = $row;
        continue;
    }

    if (!empty($row['parent_id'])) {
        foreach ($parents as $parent) {
            if ((int)$parent['id'] === (int)$row['parent_id'] && ss_filter_value($parent) === $filter) {
                $menus[] = $row;
                break;
            }
        }
    }
}

$totalMenus = count($allMenus);
$mainCount = 0;
$subCount = 0;
$activeCount = 0;
$shownCount = 0;

foreach ($allMenus as $menu) {
    empty($menu['parent_id']) ? $mainCount++ : $subCount++;
    if ((int)$menu['is_active'] === 1) {
        $activeCount++;
    }
    if ((int)($menu['show_in_sidebar'] ?? $menu['is_active']) === 1) {
        $shownCount++;
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php if (file_exists(__DIR__ . '/includes/theme-loader.php')) { include __DIR__ . '/includes/theme-loader.php'; } ?>

    <style>
    .sidebar-control-page {
        font-family: "Inter", "Segoe UI", Arial, sans-serif;
        font-size: 12px;
        font-weight: 500;
    }

    .sidebar-control-page .sidebar-control-hero {
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        box-shadow: 0 8px 20px rgba(15, 23, 42, .06);
        padding: 12px 14px;
        margin-bottom: 10px;
    }

    .sidebar-control-page .sidebar-control-hero h1 {
        font-size: 20px;
        font-weight: 750;
        line-height: 1.1;
        margin: 0 0 3px;
        letter-spacing: -.02em;
        color: var(--text-main);
    }

    .sidebar-control-page .sidebar-control-hero p,
    .sidebar-control-page .card-ui p,
    .sidebar-control-page .text-muted-custom {
        font-size: 11px !important;
        line-height: 1.3 !important;
        font-weight: 500 !important;
    }

    .sidebar-control-page .sidebar-control-hero .btn,
    .sidebar-control-page .card-ui .btn {
        font-size: 11px !important;
        padding: 6px 10px !important;
        min-height: 30px !important;
        border-radius: 999px !important;
    }

    .sidebar-control-page .kpi-card {
        min-height: 70px;
        padding: 10px 12px;
        gap: 10px;
        border-radius: 14px;
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        box-shadow: 0 8px 20px rgba(15, 23, 42, .06);
        display: flex;
        align-items: center;
    }

    .sidebar-control-page .sidebar-stat-icon {
        width: 38px;
        height: 38px;
        border-radius: 13px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto;
    }

    .sidebar-control-page .sidebar-stat-icon svg {
        width: 17px !important;
        height: 17px !important;
    }

    .sidebar-control-page .kpi-label {
        font-size: 10px;
        font-weight: 650;
        line-height: 1.15;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .sidebar-control-page .kpi-value {
        font-size: 17px;
        font-weight: 750;
        margin: 1px 0;
        line-height: 1.05;
        color: var(--text-main);
    }

    .sidebar-control-page .kpi-sub {
        font-size: 10px;
        font-weight: 550;
        margin: 0;
        line-height: 1.15;
    }

    .sidebar-control-page .card-ui {
        border-radius: 16px !important;
        box-shadow: 0 8px 20px rgba(15, 23, 42, .06) !important;
    }

    .sidebar-control-page .card-ui>.p-3,
    .sidebar-control-page .card-ui>.p-lg-4 {
        padding: 12px 14px !important;
    }

    .sidebar-control-page .card-ui h2 {
        font-size: 15px !important;
        font-weight: 750 !important;
        margin-bottom: 3px !important;
    }

    .sidebar-control-page .sidebar-filter-pill {
        border: 1px solid var(--border-soft);
        background: var(--card-bg);
        color: var(--text-main);
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 11px;
        font-weight: 650;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: .18s ease;
    }

    .sidebar-control-page .sidebar-filter-pill:hover {
        transform: translateY(-1px);
        color: var(--text-main);
        box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
    }

    .sidebar-control-page .sidebar-filter-pill.active {
        border-color: transparent;
        background: linear-gradient(135deg, #244ffb, #0b88f5);
        color: #fff;
    }

    .sidebar-control-page .sidebar-filter-pill svg {
        width: 13px !important;
        height: 13px !important;
    }

    .sidebar-control-page .sidebar-menu-icon {
        width: 30px;
        height: 30px;
        border-radius: 10px;
        background: rgba(148, 163, 184, .13);
        display: grid;
        place-items: center;
        color: var(--text-main);
        flex: 0 0 auto;
    }

    .sidebar-control-page .sidebar-menu-icon svg {
        width: 15px !important;
        height: 15px !important;
    }

    .sidebar-control-page .sidebar-url-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border: 1px solid var(--border-soft);
        background: rgba(148, 163, 184, .08);
        border-radius: 999px;
        padding: 5px 8px;
        font-size: 10px;
        color: var(--text-muted);
        font-weight: 600;
    }

    .sidebar-control-page .sidebar-sort-input {
        width: 76px;
        max-width: 100%;
        text-align: center;
        border-radius: 11px;
        font-size: 11px;
        font-weight: 650;
        min-height: 30px;
        padding: 4px 6px;
    }

    .sidebar-control-page .badge-soft {
        border-radius: 999px;
        padding: 5px 8px;
        font-size: 10px;
        font-weight: 650;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        white-space: nowrap;
    }

    .sidebar-control-page .badge-main {
        background: #dcfce7;
        color: #008a5b;
    }

    .sidebar-control-page .badge-sub {
        background: #ede9fe;
        color: #6d28d9;
    }

    .sidebar-control-page .badge-shown {
        background: #d1fae5;
        color: #008a5b;
    }

    .sidebar-control-page .badge-hidden {
        background: #fee2e2;
        color: #b91c1c;
    }

    .sidebar-control-page .badge-active {
        background: #dcfce7;
        color: #008a5b;
    }

    .sidebar-control-page .badge-inactive {
        background: #f1f5f9;
        color: #64748b;
    }

    .sidebar-control-page .badge-header {
        background: #e0f2fe;
        color: #0369a1;
    }

    .sidebar-control-page .sidebar-action-btn {
        font-size: 10.5px !important;
        font-weight: 650 !important;
        padding: 5px 8px !important;
        gap: 4px !important;
        margin-top: 3px !important;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        line-height: 1;
    }

    .sidebar-control-page .sidebar-action-btn svg {
        width: 12px !important;
        height: 12px !important;
    }

    .sidebar-control-page .sidebar-table th {
        white-space: nowrap;
        font-size: 10px !important;
        font-weight: 700 !important;
        padding: 8px 8px !important;
        color: var(--text-muted);
    }

    .sidebar-control-page .sidebar-table td {
        vertical-align: middle;
        font-size: 11px !important;
        padding: 8px 8px !important;
    }

    .sidebar-control-page .sidebar-table .fw-bold,
    .sidebar-control-page .sidebar-mobile-card .fw-bold {
        font-size: 12px !important;
        font-weight: 700 !important;
    }

    .sidebar-control-page .sidebar-table small,
    .sidebar-control-page .sidebar-mobile-card small {
        font-size: 10px !important;
    }

    .sidebar-control-page .sidebar-mobile-card {
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 14px;
        padding: 10px;
        box-shadow: 0 8px 20px rgba(15, 23, 42, .06);
    }

    .sidebar-control-page .modal-content {
        border: 0;
        border-radius: 20px;
        background: var(--card-bg);
        color: var(--text-main);
    }

    .sidebar-control-page .modal-title {
        font-size: 15px !important;
        font-weight: 700 !important;
    }

    .sidebar-control-page .modal-body {
        font-size: 12px !important;
    }

    .sidebar-control-page .modal .form-label {
        font-size: 11px !important;
        font-weight: 650 !important;
        margin-bottom: 4px !important;
    }

    .sidebar-control-page .modal .form-control,
    .sidebar-control-page .modal .form-select {
        min-height: 34px !important;
        font-size: 12px !important;
        border-radius: 12px !important;
        padding: 6px 10px !important;
    }

    .sidebar-control-page .modal-header,
    .sidebar-control-page .modal-footer {
        border-color: var(--border-soft);
    }

    .sidebar-control-page .modal-footer .btn {
        font-size: 12px !important;
        padding: 7px 12px !important;
    }

    .sidebar-control-page .btn-outline-primary {
        color: #0F766E !important;
        border-color: #0F766E !important;
        background: #ffffff !important;
    }

    .sidebar-control-page .btn-outline-primary:hover {
        color: #ffffff !important;
        background: #0F766E !important;
        border-color: #0F766E !important;
    }

    .sidebar-control-page .btn-warning {
        color: #ffffff !important;
        background: #2563EB !important;
        border-color: #2563EB !important;
    }

    .sidebar-control-page .btn-warning:hover {
        background: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
    }


    /* Toast UI - same pattern used in enquiries.php */
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

    /* Module page UI - matched with enquiries.php */
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

    @media (max-width: 767px) {
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

        .stat-card {
            min-height: 96px !important;
            padding: 16px !important;
        }

        .stat-icon {
            width: 46px !important;
            height: 46px !important;
            border-radius: 14px !important;
        }
    }
    </style>

<style id="compact-ui-overrides">
/* Compact 100% zoom UI override - visual sizing only */
.module-page .page-head{
    padding:16px 18px !important;
    margin-bottom:12px !important;
    border-radius:16px !important;
}
.module-page .page-head h1{
    font-size:24px !important;
    line-height:1.2 !important;
    font-weight:800 !important;
    letter-spacing:-.2px !important;
}
.module-page .page-head p,
.module-page .text-muted-custom{
    font-size:12px !important;
    font-weight:500 !important;
}
.module-page .module-card{
    padding:16px !important;
    border-radius:16px !important;
    margin-bottom:12px !important;
}
.module-page .module-title,
.module-page .section-title{
    font-size:15px !important;
    line-height:1.25 !important;
    font-weight:750 !important;
    margin-bottom:10px !important;
}
.module-page .stat-card{
    min-height:auto !important;
    padding:12px 14px !important;
    border-radius:14px !important;
}
.module-page .stat-card small,
.module-page .stat-card span{
    font-size:10px !important;
    font-weight:700 !important;
}
.module-page .stat-card strong{
    font-size:18px !important;
    line-height:1.15 !important;
    font-weight:800 !important;
}
.module-page .stat-icon{
    width:38px !important;
    height:38px !important;
    border-radius:12px !important;
}
.module-page .stat-icon svg{width:19px !important;height:19px !important;}
.module-page .form-label{
    font-size:12px !important;
    font-weight:700 !important;
    margin-bottom:5px !important;
}
.module-page .form-control,
.module-page .form-select,
.module-page .input-group-text{
    min-height:38px !important;
    font-size:13px !important;
    border-radius:10px !important;
    padding:7px 10px !important;
}
.module-page textarea.form-control{min-height:72px !important;}
.module-page .btn{
    font-size:12px !important;
    font-weight:700 !important;
    line-height:1.2 !important;
    padding:7px 12px !important;
}
.module-page .btn-sm{
    font-size:11px !important;
    padding:5px 9px !important;
}
.module-page .table-ui th,
.module-page .table-ui td,
.module-page .table th,
.module-page .table td{
    font-size:12px !important;
    padding:9px 10px !important;
}
.module-page .table-ui th,
.module-page .table th{
    font-size:10.5px !important;
    font-weight:750 !important;
}
.module-page .status-pill,
.module-page .stock-pill,
.module-page .filter-chip,
.module-page .sidebar-filter-pill,
.module-page .badge-soft,
.module-page .sidebar-url-badge{
    font-size:10px !important;
    font-weight:700 !important;
    padding:4px 8px !important;
}
.module-page .modal-title{font-size:16px !important;font-weight:800 !important;}
.module-page .modal-header{padding:14px 16px !important;}
.module-page .modal-body{padding:16px !important;}
.module-page .modal-footer{padding:12px 16px !important;}
.module-page .modal-content{border-radius:16px !important;}
.module-page .toast-title{font-size:13px !important;font-weight:800 !important;}
.module-page .toast-message{font-size:12px !important;font-weight:600 !important;}
@media(max-width:767.98px){
    .module-page .page-head{padding:14px !important;}
    .module-page .page-head h1{font-size:21px !important;}
    .module-page .module-card{padding:13px !important;}
}

.sidebar-control-page .sidebar-menu-icon{width:34px !important;height:34px !important;border-radius:10px !important;}
.sidebar-control-page .sidebar-action-btn{width:30px !important;height:30px !important;padding:0 !important;}
.sidebar-control-page .sidebar-mobile-card{padding:12px !important;border-radius:14px !important;}
.sidebar-control-page .sidebar-table th,.sidebar-control-page .sidebar-table td{font-size:11.5px !important;padding:8px 9px !important;}
</style><!-- compact-ui-overrides -->
</head>

<body
    class="<?= e((isset($theme['layout_density']) && $theme['layout_density'] === 'compact') ? 'layout-compact' : '') ?>">
    <div id="mobileOverlay"></div>

    <div class="app-shell">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main id="main">
            <?php include __DIR__ . '/includes/nav.php'; ?>

            <section class="page-section module-page sidebar-control-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                        <div>
                            <h1>Sidebar Control</h1>
                            <p class="text-muted-custom mb-0">
                                Create main menus and assign submenus under the correct parent. Page files are created
                                automatically from the given URL.
                            </p>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <a href="roles_permissions.php" class="btn btn-outline-secondary rounded-pill fw-bold px-3">
                                <i data-lucide="shield-check" style="width:16px;height:16px;"></i>
                                Roles & Permissions
                            </a>

                            <button type="button" class="btn btn-outline-primary rounded-pill fw-bold px-3"
                                data-bs-toggle="modal" data-bs-target="#bulkSortModal">
                                <i data-lucide="arrow-up-down" style="width:16px;height:16px;"></i>
                                Arrange Sort Order
                            </button>

                            <button type="button" class="btn btn-warning text-white rounded-pill fw-bold px-3"
                                data-bs-toggle="modal" data-bs-target="#menuModal" onclick="openMenuModal()">
                                <i data-lucide="plus" style="width:16px;height:16px;"></i>
                                Add Menu
                            </button>
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
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                                <i data-lucide="panel-left"></i>
                            </div>
                            <div>
                                <span>Total Menus</span>
                                <strong><?= number_format($totalMenus) ?></strong>
                                <small class="d-block text-success fw-bold">↑ <?= (int)$totalMenus ?> sidebar
                                    items</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:#cce8dc;color:#008a5b;">
                                <i data-lucide="list-tree"></i>
                            </div>
                            <div>
                                <span>Main Menus</span>
                                <strong><?= number_format($mainCount) ?></strong>
                                <small class="d-block text-success fw-bold">↑ <?= (int)$mainCount ?> parent
                                    menus</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                                <i data-lucide="list-plus"></i>
                            </div>
                            <div>
                                <span>Sub Menus</span>
                                <strong><?= number_format($subCount) ?></strong>
                                <small class="d-block text-muted-custom fw-bold"><?= (int)$subCount ?> child
                                    menus</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:#fef3c7;color:#f59e0b;">
                                <i data-lucide="badge-check"></i>
                            </div>
                            <div>
                                <span>Active</span>
                                <strong><?= number_format($activeCount) ?></strong>
                                <small class="d-block text-success fw-bold">↑ <?= (int)$shownCount ?> shown
                                    items</small>
                            </div>
                        </div>
                    </div>
                </div>

                <section class="card-ui module-card">
                    <div class="p-3 p-lg-4">
                        <div
                            class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-start gap-3 mb-3">
                            <div>
                                <h2 class="module-title">Manage Sidebar Menus</h2>
                                <p class="text-muted-custom mb-0">
                                    Click a main menu below to show only that menu and its assigned submenus. Use
                                    Sidebar Hide/Show separately from Active/Inactive status.
                                </p>
                            </div>

                            <button type="button" class="btn btn-outline-primary rounded-pill fw-bold btn-sm px-3"
                                data-bs-toggle="modal" data-bs-target="#bulkSortModal">
                                <i data-lucide="list-ordered" style="width:15px;height:15px;"></i>
                                Bulk Sort
                            </button>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <a href="<?= e(ss_page_url('filter=all')) ?>"
                                class="sidebar-filter-pill <?= $filter === 'all' ? 'active' : '' ?>">
                                <i data-lucide="list"></i> All Menus
                            </a>

                            <?php foreach ($parents as $parent): ?>
                            <?php $filterValue = ss_filter_value($parent); ?>
                            <a href="<?= e(ss_page_url('filter=' . urlencode($filterValue))) ?>"
                                class="sidebar-filter-pill <?= $filter === $filterValue ? 'active' : '' ?>">
                                <i data-lucide="<?= e($parent['icon'] ?: 'circle') ?>"></i>
                                <?= e($parent['menu_title']) ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="d-none d-md-block table-responsive px-3 px-lg-4 pb-3">
                        <table class="table sidebar-table" id="menuTable">
                            <thead>
                                <tr>
                                    <th>Menu</th>
                                    <th>Type</th>
                                    <th>URL / Page</th>
                                    <th>Icon</th>
                                    <th>Sort</th>
                                    <th>Sidebar</th>
                                    <th>Status</th>
                                    <th style="width: 250px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$menus): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">No menus found.</td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($menus as $menu): ?>
                                <?php
                                        $isSub = !empty($menu['parent_id']);
                                        $parentName = ss_parent_name($parents, (int)($menu['parent_id'] ?? 0));
                                        $isShown = (int)($menu['show_in_sidebar'] ?? $menu['is_active']) === 1;
                                        $isActive = (int)$menu['is_active'] === 1;
                                    ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3 <?= $isSub ? 'ps-4' : '' ?>">
                                            <div class="sidebar-menu-icon">
                                                <i data-lucide="<?= e($menu['icon'] ?: 'circle') ?>"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold"><?= e($menu['menu_title']) ?></div>
                                                <small class="text-muted-custom">
                                                    <?= e($menu['menu_key']) ?><?= $parentName ? ' · ' . e($parentName) : '' ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-soft <?= $isSub ? 'badge-sub' : 'badge-main' ?>">
                                            <?= $isSub ? 'Sub' : 'Main' ?>
                                        </span>
                                        <?php if ((int)$menu['is_header'] === 1): ?>
                                        <span class="badge-soft badge-header ms-1">Header</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="sidebar-url-badge">
                                            <i data-lucide="file-code" style="width:13px;height:13px;"></i>
                                            <?= e($menu['route'] ?: '#') ?>
                                        </span>
                                    </td>
                                    <td><?= e($menu['icon'] ?: 'circle') ?></td>
                                    <td>
                                        <form method="post" action="api/sidebar_settings.php"
                                            class="js-sidebar-api-form">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="sort_one">
                                            <input type="hidden" name="id" value="<?= (int)$menu['id'] ?>">
                                            <input type="number" name="sort_order"
                                                value="<?= (int)$menu['sort_order'] ?>"
                                                class="form-control sidebar-sort-input"
                                                onchange="this.form.dispatchEvent(new Event('submit', {cancelable:true, bubbles:true}))">
                                        </form>
                                    </td>
                                    <td>
                                        <span class="badge-soft <?= $isShown ? 'badge-shown' : 'badge-hidden' ?>">
                                            <?= $isShown ? 'Shown' : 'Hidden' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-soft <?= $isActive ? 'badge-active' : 'badge-inactive' ?>">
                                            <?= $isActive ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary sidebar-action-btn"
                                            data-bs-toggle="modal" data-bs-target="#menuModal"
                                            onclick='editMenu(<?= json_encode($menu, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            Edit
                                        </button>

                                        <form method="post" action="api/sidebar_settings.php"
                                            class="d-inline js-sidebar-api-form">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="id" value="<?= (int)$menu['id'] ?>">
                                            <button type="submit"
                                                class="btn btn-sm btn-outline-warning sidebar-action-btn">
                                                <i data-lucide="power" style="width:14px;height:14px;"></i>
                                                <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>

                                        <form method="post" action="api/sidebar_settings.php"
                                            class="d-inline js-sidebar-api-form">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="toggle_sidebar">
                                            <input type="hidden" name="id" value="<?= (int)$menu['id'] ?>">
                                            <button type="submit"
                                                class="btn btn-sm btn-outline-secondary sidebar-action-btn mt-1">
                                                <i data-lucide="<?= $isShown ? 'eye-off' : 'eye' ?>"
                                                    style="width:14px;height:14px;"></i>
                                                Sidebar <?= $isShown ? 'Hide' : 'Show' ?>
                                            </button>
                                        </form>

                                        <form method="post" action="api/sidebar_settings.php"
                                            class="d-inline js-sidebar-api-form js-confirm-delete">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete_menu">
                                            <input type="hidden" name="id" value="<?= (int)$menu['id'] ?>">
                                            <button type="submit"
                                                class="btn btn-sm btn-outline-danger sidebar-action-btn mt-1">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-md-none px-3 pb-3 d-grid gap-3" id="menuMobileList">
                        <?php if (!$menus): ?>
                        <div class="sidebar-mobile-card text-center text-muted">No menus found.</div>
                        <?php endif; ?>

                        <?php foreach ($menus as $menu): ?>
                        <?php
                                $isSub = !empty($menu['parent_id']);
                                $isShown = (int)($menu['show_in_sidebar'] ?? $menu['is_active']) === 1;
                                $isActive = (int)$menu['is_active'] === 1;
                            ?>
                        <div class="sidebar-mobile-card">
                            <div class="d-flex gap-3">
                                <div class="sidebar-menu-icon">
                                    <i data-lucide="<?= e($menu['icon'] ?: 'circle') ?>"></i>
                                </div>

                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div>
                                            <div class="fw-bold"><?= e($menu['menu_title']) ?></div>
                                            <small class="text-muted-custom"><?= e($menu['menu_key']) ?></small>
                                        </div>
                                        <span class="badge-soft <?= $isSub ? 'badge-sub' : 'badge-main' ?>">
                                            <?= $isSub ? 'Sub' : 'Main' ?>
                                        </span>
                                    </div>

                                    <div class="small text-muted-custom mt-2">
                                        <?= e($menu['route'] ?: '#') ?> · <?= e($menu['icon'] ?: 'circle') ?> · Sort
                                        <?= (int)$menu['sort_order'] ?>
                                    </div>

                                    <div class="d-flex flex-wrap gap-2 mt-3">
                                        <span class="badge-soft <?= $isShown ? 'badge-shown' : 'badge-hidden' ?>">
                                            <?= $isShown ? 'Shown' : 'Hidden' ?>
                                        </span>
                                        <span class="badge-soft <?= $isActive ? 'badge-active' : 'badge-inactive' ?>">
                                            <?= $isActive ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </div>

                                    <div class="d-flex flex-wrap gap-2 mt-3">
                                        <button type="button"
                                            class="btn btn-sm btn-outline-primary rounded-pill fw-bold"
                                            data-bs-toggle="modal" data-bs-target="#menuModal"
                                            onclick='editMenu(<?= json_encode($menu, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            Edit
                                        </button>

                                        <form method="post" action="api/sidebar_settings.php"
                                            class="js-sidebar-api-form">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="id" value="<?= (int)$menu['id'] ?>">
                                            <button type="submit"
                                                class="btn btn-sm btn-outline-warning rounded-pill fw-bold">
                                                <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>

                                        <form method="post" action="api/sidebar_settings.php"
                                            class="js-sidebar-api-form">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="toggle_sidebar">
                                            <input type="hidden" name="id" value="<?= (int)$menu['id'] ?>">
                                            <button type="submit"
                                                class="btn btn-sm btn-outline-secondary rounded-pill fw-bold">
                                                Sidebar <?= $isShown ? 'Hide' : 'Show' ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php if (file_exists(__DIR__ . '/includes/rightsidebar.php')) { include __DIR__ . '/includes/rightsidebar.php'; } ?>
    </div>

    <div class="modal fade" id="menuModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form method="post" action="api/sidebar_settings.php" class="modal-content js-sidebar-api-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save_menu">
                <input type="hidden" name="id" id="menuId">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="menuModalTitle">Sidebar Menu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Menu Title</label>
                            <input type="text" name="menu_title" id="menuTitle" class="form-control rounded-4" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Menu Key</label>
                            <input type="text" name="menu_key" id="menuKey" class="form-control rounded-4"
                                placeholder="Auto generated if empty">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Parent Menu</label>
                            <select name="parent_id" id="parentId" class="form-select rounded-4">
                                <option value="">Main Menu</option>
                                <?php foreach ($parents as $parent): ?>
                                <option value="<?= (int)$parent['id'] ?>"><?= e($parent['menu_title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Page Title</label>
                            <input type="text" name="page_title" id="pageTitle" class="form-control rounded-4"
                                placeholder="Same as menu title if empty">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Menu URL / Page</label>
                            <input type="text" name="route" id="menuRoute" class="form-control rounded-4" value="#">
                            <small class="text-muted-custom">Example: sidebar_settings.php or #</small>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">Lucide Icon</label>
                            <input type="text" name="icon" id="menuIcon" class="form-control rounded-4" value="circle">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">Sort</label>
                            <input type="number" name="sort_order" id="sortOrder" class="form-control rounded-4"
                                value="0">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Status</label>
                            <select name="is_active" id="isActive" class="form-select rounded-4">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Sidebar</label>
                            <select name="show_in_sidebar" id="showInSidebar" class="form-select rounded-4">
                                <option value="1">Shown</option>
                                <option value="0">Hidden</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Header</label>
                            <div class="form-check mt-2">
                                <input type="checkbox" name="is_header" value="1" id="isHeader"
                                    class="form-check-input">
                                <label for="isHeader" class="form-check-label fw-bold">Header Menu</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light rounded-4 fw-bold"
                        data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-4 fw-bold px-4" id="menuSubmitBtn">Save
                        Menu</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="bulkSortModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form method="post" action="api/sidebar_settings.php" class="modal-content js-sidebar-api-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="bulk_sort">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Arrange Sort Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <?php foreach ($allMenus as $menu): ?>
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><?= e($menu['menu_title']) ?></label>
                            <input type="number" name="sort_order[<?= (int)$menu['id'] ?>]"
                                value="<?= (int)$menu['sort_order'] ?>" class="form-control rounded-4">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light rounded-4 fw-bold"
                        data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-4 fw-bold px-4">Save Sort Order</button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
    function setFieldValue(id, value) {
        const field = document.getElementById(id);
        if (!field) return;
        field.value = value == null ? '' : value;
    }

    function openMenuModal() {
        document.getElementById('menuModalTitle').textContent = 'Create New Menu';
        document.getElementById('menuSubmitBtn').textContent = 'Save Menu';
        setFieldValue('menuId', '');
        setFieldValue('menuTitle', '');
        setFieldValue('menuKey', '');
        setFieldValue('parentId', '');
        setFieldValue('pageTitle', '');
        setFieldValue('menuRoute', '#');
        setFieldValue('menuIcon', 'circle');
        setFieldValue('sortOrder', '0');
        setFieldValue('isActive', '1');
        setFieldValue('showInSidebar', '1');
        const isHeader = document.getElementById('isHeader');
        if (isHeader) isHeader.checked = false;
    }

    function editMenu(menu) {
        document.getElementById('menuModalTitle').textContent = 'Edit Menu';
        document.getElementById('menuSubmitBtn').textContent = 'Update Menu';
        setFieldValue('menuId', menu.id || '');
        setFieldValue('menuTitle', menu.menu_title || '');
        setFieldValue('menuKey', menu.menu_key || '');
        setFieldValue('parentId', menu.parent_id || '');
        setFieldValue('pageTitle', menu.page_title || '');
        setFieldValue('menuRoute', menu.route || '#');
        setFieldValue('menuIcon', menu.icon || 'circle');
        setFieldValue('sortOrder', menu.sort_order || '0');
        setFieldValue('isActive', String(menu.is_active ?? '1'));
        setFieldValue('showInSidebar', String(menu.show_in_sidebar ?? menu.is_active ?? '1'));
        const isHeader = document.getElementById('isHeader');
        if (isHeader) isHeader.checked = String(menu.is_header || '0') === '1';
    }

    (function() {
        function showToast(message, type = 'success', title = '') {
            if (!message) return;

            const oldToastWrap = document.getElementById('dynamicActionToastWrap');
            if (oldToastWrap) {
                oldToastWrap.remove();
            }

            const toastTitle = title || (type === 'danger' ? 'Failed' : (type === 'warning' ? 'Warning' :
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
                return;
            }

            if (toastEl) {
                toastEl.classList.add('show');
                toastEl.style.display = 'block';
                toastEl.style.opacity = '1';
                toastEl.querySelector('[data-bs-dismiss="toast"]')?.addEventListener('click', function() {
                    wrap.remove();
                });
                setTimeout(function() {
                    wrap.remove();
                }, 4200);
            }
        }

        const pageToastEl = document.getElementById('pageToast');
        if (pageToastEl && window.bootstrap && bootstrap.Toast) {
            bootstrap.Toast.getOrCreateInstance(pageToastEl).show();
        } else if (pageToastEl) {
            pageToastEl.classList.add('show');
            pageToastEl.style.display = 'block';
            pageToastEl.style.opacity = '1';
            pageToastEl.querySelector('[data-bs-dismiss="toast"]')?.addEventListener('click', function() {
                pageToastEl.style.display = 'none';
            });
            setTimeout(function() {
                pageToastEl.style.display = 'none';
            }, 4200);
        }

        document.querySelectorAll('.js-sidebar-api-form').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();

                if (form.classList.contains('js-confirm-delete') && !confirm('Delete this menu?')) {
                    return;
                }

                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                }

                fetch(form.getAttribute('action') || 'api/sidebar_settings.php', {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        const ok = !!data.status;
                        showToast(data.message || (ok ? 'Updated successfully.' :
                                'Action failed.'), ok ? 'success' : 'danger', ok ?
                            'Success' : 'Failed');

                        if (ok && data.reload) {
                            setTimeout(function() {
                                window.location.href = window.location.pathname;
                            }, 850);
                        } else if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                    })
                    .catch(() => {
                        showToast('API request failed.', 'danger', 'Failed');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                    });
            });
        });

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    })();
    </script>
</body>

</html>