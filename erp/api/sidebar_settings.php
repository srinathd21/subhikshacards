<?php
/**
 * api/sidebar_settings.php
 * Subhiksha Cards ERP - Sidebar Settings API
 * Returns JSON for sidebar_settings.php AJAX forms.
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission($conn, 'can_view', 'sidebar_settings.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function ss_api_json(bool $status, string $message, string $type = 'success', array $extra = []): void
{
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message,
        'type' => $type,
    ], $extra));
    exit;
}

function ss_api_table_exists(mysqli $conn, string $table): bool
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

function ss_api_table_has_column(mysqli $conn, string $table, string $column): bool
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

function ss_api_ensure_sidebar_column(mysqli $conn): bool
{
    if (!ss_api_table_exists($conn, 'sidebar_items')) {
        return false;
    }

    if (ss_api_table_has_column($conn, 'sidebar_items', 'show_in_sidebar')) {
        return true;
    }

    try {
        $conn->query("ALTER TABLE sidebar_items ADD COLUMN show_in_sidebar TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");
    } catch (Throwable $e) {
        return false;
    }

    return ss_api_table_has_column($conn, 'sidebar_items', 'show_in_sidebar');
}

function ss_api_slug(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '_', $text);
    $text = trim((string)$text, '_');
    return $text !== '' ? $text : 'menu_' . time();
}

function ss_api_int($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function ss_api_post_string(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function ss_api_require_csrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['sidebar_settings_csrf']) ||
        !hash_equals($_SESSION['sidebar_settings_csrf'], (string)$_POST['csrf_token'])
    ) {
        ss_api_json(false, 'Invalid CSRF token.', 'danger');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ss_api_json(false, 'Invalid request method.', 'danger');
}

ss_api_require_csrf();
$action = ss_api_post_string('action');

try {
    if (!ss_api_table_exists($conn, 'sidebar_items')) {
        throw new RuntimeException('sidebar_items table is missing.');
    }

    $hasShowInSidebar = ss_api_ensure_sidebar_column($conn);

    if ($action === 'save_menu') {
        $id = ss_api_int($_POST['id'] ?? 0);
        $parentIdRaw = ss_api_int($_POST['parent_id'] ?? 0);
        $parentId = $parentIdRaw > 0 ? $parentIdRaw : null;

        $menuTitle = ss_api_post_string('menu_title');
        $pageTitle = ss_api_post_string('page_title');
        $menuKey = ss_api_post_string('menu_key');
        $route = ss_api_post_string('route', '#');
        $icon = ss_api_post_string('icon', 'circle');
        $sortOrder = ss_api_int($_POST['sort_order'] ?? 0);
        $isHeader = isset($_POST['is_header']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? ss_api_int($_POST['is_active']) : 1;
        $isActive = $isActive === 1 ? 1 : 0;
        $showInSidebar = isset($_POST['show_in_sidebar']) ? ss_api_int($_POST['show_in_sidebar']) : 1;
        $showInSidebar = $showInSidebar === 1 ? 1 : 0;

        if ($menuTitle === '') {
            throw new RuntimeException('Menu title is required.');
        }

        $menuKey = $menuKey !== '' ? ss_api_slug($menuKey) : ss_api_slug($menuTitle);
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
                    SET parent_id = ?, menu_key = ?, menu_title = ?, page_title = ?, route = ?, icon = ?,
                        sort_order = ?, is_header = ?, is_active = ?, show_in_sidebar = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('isssssiiiii', $parentId, $menuKey, $menuTitle, $pageTitle, $route, $icon, $sortOrder, $isHeader, $isActive, $showInSidebar, $id);
            } else {
                $stmt = $conn->prepare("
                    UPDATE sidebar_items
                    SET parent_id = ?, menu_key = ?, menu_title = ?, page_title = ?, route = ?, icon = ?,
                        sort_order = ?, is_header = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('isssssiiii', $parentId, $menuKey, $menuTitle, $pageTitle, $route, $icon, $sortOrder, $isHeader, $isActive, $id);
            }
            $stmt->execute();
            $stmt->close();
            ss_api_json(true, 'Sidebar menu updated successfully.', 'success', ['reload' => true]);
        }

        if ($hasShowInSidebar) {
            $stmt = $conn->prepare("
                INSERT INTO sidebar_items
                    (parent_id, menu_key, menu_title, page_title, route, icon, sort_order, is_header, is_active, show_in_sidebar, created_at, updated_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->bind_param('isssssiiii', $parentId, $menuKey, $menuTitle, $pageTitle, $route, $icon, $sortOrder, $isHeader, $isActive, $showInSidebar);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO sidebar_items
                    (parent_id, menu_key, menu_title, page_title, route, icon, sort_order, is_header, is_active, created_at, updated_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->bind_param('isssssiii', $parentId, $menuKey, $menuTitle, $pageTitle, $route, $icon, $sortOrder, $isHeader, $isActive);
        }
        $stmt->execute();
        $stmt->close();
        ss_api_json(true, 'Sidebar menu created successfully.', 'success', ['reload' => true]);
    }

    if ($action === 'toggle_active') {
        $id = ss_api_int($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid menu.');
        }
        $stmt = $conn->prepare("UPDATE sidebar_items SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        ss_api_json(true, 'Sidebar status updated successfully.', 'success', ['reload' => true]);
    }

    if ($action === 'toggle_sidebar') {
        $id = ss_api_int($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid menu.');
        }
        if (!$hasShowInSidebar) {
            throw new RuntimeException('show_in_sidebar column could not be created. Please add it in sidebar_items table.');
        }
        $stmt = $conn->prepare("UPDATE sidebar_items SET show_in_sidebar = IF(show_in_sidebar = 1, 0, 1), updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        ss_api_json(true, 'Sidebar visibility updated successfully.', 'success', ['reload' => true]);
    }

    if ($action === 'sort_one') {
        $id = ss_api_int($_POST['id'] ?? 0);
        $sortOrder = ss_api_int($_POST['sort_order'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid menu.');
        }
        $stmt = $conn->prepare("UPDATE sidebar_items SET sort_order = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('ii', $sortOrder, $id);
        $stmt->execute();
        $stmt->close();
        ss_api_json(true, 'Sidebar sort order updated successfully.', 'success', ['reload' => true]);
    }

    if ($action === 'bulk_sort') {
        $sortRows = $_POST['sort_order'] ?? [];
        if (!is_array($sortRows)) {
            throw new RuntimeException('Invalid sort data.');
        }
        $stmt = $conn->prepare("UPDATE sidebar_items SET sort_order = ?, updated_at = NOW() WHERE id = ?");
        foreach ($sortRows as $menuId => $sortOrder) {
            $menuId = ss_api_int($menuId);
            $sortOrder = ss_api_int($sortOrder);
            if ($menuId <= 0) {
                continue;
            }
            $stmt->bind_param('ii', $sortOrder, $menuId);
            $stmt->execute();
        }
        $stmt->close();
        ss_api_json(true, 'Sidebar sort order updated successfully.', 'success', ['reload' => true]);
    }

    if ($action === 'delete_menu') {
        $id = ss_api_int($_POST['id'] ?? 0);
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
        ss_api_json(true, 'Sidebar menu deleted successfully.', 'success', ['reload' => true]);
    }

    throw new RuntimeException('Invalid action.');
} catch (Throwable $e) {
    ss_api_json(false, $e->getMessage(), 'danger');
}
