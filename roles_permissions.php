<?php
/**
 * roles_permissions.php
 * Subhiksha Cards ERP - Separate Roles & Permissions
 *
 * Same tabular reference style as sidebar_settings.php.
 *
 * Tables used:
 * - roles
 * - sidebar_items
 * - role_sidebar_permissions
 * - role_page_permissions
 */

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'roles_permissions.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['roles_permissions_csrf'])) {
    $_SESSION['roles_permissions_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['roles_permissions_csrf'];
$message = '';
$messageType = 'success';

$permissionColumns = [
    'can_view'          => 'View',
    'can_create'        => 'Create',
    'can_edit'          => 'Edit',
    'can_delete'        => 'Delete',
    'can_update'        => 'Update',
    'can_approve'       => 'Approve',
    'can_print'         => 'Print',
    'can_export'        => 'Export',
    'can_send_whatsapp' => 'WhatsApp',
    'can_assign'        => 'Assign',
    'can_override'      => 'Override',
];

function rp_table_exists(mysqli $conn, string $table): bool
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

function rp_col_exists(mysqli $conn, string $table, string $column): bool
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

function rp_int($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function rp_text(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function rp_slug(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '_', $text);
    $text = trim((string)$text, '_');
    return $text !== '' ? $text : 'role_' . time();
}

function rp_redirect(string $query = ''): void
{
    $url = 'roles_permissions.php';
    if ($query !== '') {
        $url .= '?' . ltrim($query, '?');
    }
    header('Location: ' . $url);
    exit;
}

function rp_require_csrf(): void
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['roles_permissions_csrf']) ||
        !hash_equals($_SESSION['roles_permissions_csrf'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function rp_get_roles(mysqli $conn): array
{
    if (!rp_table_exists($conn, 'roles')) {
        return [];
    }

    $rows = [];

    try {
        $res = $conn->query("
            SELECT id, role_name, role_key, is_active
            FROM roles
            ORDER BY is_active DESC, id ASC
        ");

        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }

        $res->free();
    } catch (Throwable $e) {
        $rows = [];
    }

    return $rows;
}

function rp_get_active_roles(mysqli $conn): array
{
    return array_values(array_filter(rp_get_roles($conn), function ($role) {
        return (int)($role['is_active'] ?? 1) === 1;
    }));
}

function rp_get_sidebar_items(mysqli $conn): array
{
    if (!rp_table_exists($conn, 'sidebar_items')) {
        return [];
    }

    $rows = [];

    try {
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
                is_active
            FROM sidebar_items
            WHERE is_active = 1
            ORDER BY
                COALESCE(parent_id, id),
                CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END,
                sort_order,
                id
        ");

        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }

        $res->free();
    } catch (Throwable $e) {
        $rows = [];
    }

    return $rows;
}

function rp_build_tree(array $items): array
{
    $parents = [];
    $children = [];

    foreach ($items as $item) {
        if (empty($item['parent_id'])) {
            $item['children'] = [];
            $parents[(int)$item['id']] = $item;
        } else {
            $children[(int)$item['parent_id']][] = $item;
        }
    }

    foreach ($children as $parentId => $childRows) {
        if (isset($parents[$parentId])) {
            $parents[$parentId]['children'] = $childRows;
        }
    }

    return $parents;
}

function rp_get_role_by_id(mysqli $conn, int $roleId): ?array
{
    if ($roleId <= 0 || !rp_table_exists($conn, 'roles')) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT id, role_name, role_key, is_active
            FROM roles
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function rp_get_role_permissions(mysqli $conn, int $roleId): array
{
    $permissions = [
        'sidebar' => [],
        'page' => [],
    ];

    if ($roleId <= 0) {
        return $permissions;
    }

    try {
        if (rp_table_exists($conn, 'role_sidebar_permissions')) {
            $stmt = $conn->prepare("
                SELECT sidebar_item_id, can_show
                FROM role_sidebar_permissions
                WHERE role_id = ?
            ");
            $stmt->bind_param('i', $roleId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $permissions['sidebar'][(int)$row['sidebar_item_id']] = (int)$row['can_show'];
            }

            $stmt->close();
        }

        if (rp_table_exists($conn, 'role_page_permissions')) {
            $stmt = $conn->prepare("
                SELECT *
                FROM role_page_permissions
                WHERE role_id = ?
            ");
            $stmt->bind_param('i', $roleId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $permissions['page'][(int)$row['sidebar_item_id']] = $row;
            }

            $stmt->close();
        }
    } catch (Throwable $e) {
        return $permissions;
    }

    return $permissions;
}

function rp_upsert_sidebar_permission(mysqli $conn, int $roleId, int $sidebarItemId, int $canShow): void
{
    if (!rp_table_exists($conn, 'role_sidebar_permissions')) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM role_sidebar_permissions
        WHERE role_id = ? AND sidebar_item_id = ?
    ");
    $stmt->bind_param('ii', $roleId, $sidebarItemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int)($row['total'] ?? 0) > 0) {
        $stmt = $conn->prepare("
            UPDATE role_sidebar_permissions
            SET can_show = ?, updated_at = NOW()
            WHERE role_id = ? AND sidebar_item_id = ?
        ");
        $stmt->bind_param('iii', $canShow, $roleId, $sidebarItemId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO role_sidebar_permissions
            (role_id, sidebar_item_id, can_show, created_at, updated_at)
        VALUES
            (?, ?, ?, NOW(), NOW())
    ");
    $stmt->bind_param('iii', $roleId, $sidebarItemId, $canShow);
    $stmt->execute();
    $stmt->close();
}

function rp_upsert_page_permission(mysqli $conn, int $roleId, int $sidebarItemId, array $values, array $permissionColumns): void
{
    if (!rp_table_exists($conn, 'role_page_permissions')) {
        return;
    }

    $safeValues = [];
    foreach ($permissionColumns as $column => $label) {
        $safeValues[$column] = !empty($values[$column]) ? 1 : 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM role_page_permissions
        WHERE role_id = ? AND sidebar_item_id = ?
    ");
    $stmt->bind_param('ii', $roleId, $sidebarItemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int)($row['total'] ?? 0) > 0) {
        $setParts = [];
        foreach ($permissionColumns as $column => $label) {
            if (rp_col_exists($conn, 'role_page_permissions', $column)) {
                $setParts[] = "{$column} = " . (int)$safeValues[$column];
            }
        }

        if (!$setParts) {
            return;
        }

        $sql = "
            UPDATE role_page_permissions
            SET " . implode(', ', $setParts) . ", updated_at = NOW()
            WHERE role_id = ? AND sidebar_item_id = ?
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $roleId, $sidebarItemId);
        $stmt->execute();
        $stmt->close();

        return;
    }

    $columns = ['role_id', 'sidebar_item_id'];
    $insertValues = [$roleId, $sidebarItemId];

    foreach ($permissionColumns as $column => $label) {
        if (rp_col_exists($conn, 'role_page_permissions', $column)) {
            $columns[] = $column;
            $insertValues[] = (int)$safeValues[$column];
        }
    }

    $columns[] = 'created_at';
    $columns[] = 'updated_at';

    $placeholders = array_fill(0, count($insertValues), '?');
    $sql = "
        INSERT INTO role_page_permissions
            (" . implode(', ', $columns) . ")
        VALUES
            (" . implode(', ', $placeholders) . ", NOW(), NOW())
    ";

    $types = str_repeat('i', count($insertValues));
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$insertValues);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rp_require_csrf();

    $action = rp_text('action');

    try {
        if ($action === 'save_role') {
            if (!rp_table_exists($conn, 'roles')) {
                throw new RuntimeException('roles table is missing.');
            }

            $roleId = rp_int($_POST['role_id'] ?? 0);
            $roleName = rp_text('role_name');
            $roleKey = rp_text('role_key');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($roleName === '') {
                throw new RuntimeException('Role name is required.');
            }

            $roleKey = $roleKey !== '' ? rp_slug($roleKey) : rp_slug($roleName);

            if ($roleId > 0) {
                $stmt = $conn->prepare("
                    UPDATE roles
                    SET role_name = ?, role_key = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->bind_param('ssii', $roleName, $roleKey, $isActive, $roleId);
                $stmt->execute();
                $stmt->close();

                rp_redirect('msg=role_updated&role_id=' . $roleId);
            }

            $stmt = $conn->prepare("
                INSERT INTO roles
                    (role_name, role_key, is_active)
                VALUES
                    (?, ?, ?)
            ");
            $stmt->bind_param('ssi', $roleName, $roleKey, $isActive);
            $stmt->execute();
            $newRoleId = (int)$conn->insert_id;
            $stmt->close();

            rp_redirect('msg=role_created&role_id=' . $newRoleId);
        }

        if ($action === 'disable_role') {
            $roleId = rp_int($_POST['role_id'] ?? 0);
            $role = rp_get_role_by_id($conn, $roleId);

            if (!$role) {
                throw new RuntimeException('Role not found.');
            }

            if (strtolower((string)$role['role_key']) === 'admin') {
                throw new RuntimeException('Admin role cannot be disabled.');
            }

            $stmt = $conn->prepare("
                UPDATE roles
                SET is_active = 0
                WHERE id = ?
            ");
            $stmt->bind_param('i', $roleId);
            $stmt->execute();
            $stmt->close();

            rp_redirect('msg=role_disabled');
        }

        if ($action === 'save_permissions') {
            $roleId = rp_int($_POST['role_id'] ?? 0);

            if ($roleId <= 0) {
                throw new RuntimeException('Please select a role.');
            }

            $role = rp_get_role_by_id($conn, $roleId);
            if (!$role) {
                throw new RuntimeException('Selected role not found.');
            }

            $items = rp_get_sidebar_items($conn);
            $sidebarPost = $_POST['sidebar'] ?? [];
            $permissionPost = $_POST['perm'] ?? [];

            foreach ($items as $item) {
                $sidebarItemId = (int)$item['id'];
                $canShow = isset($sidebarPost[$sidebarItemId]) ? 1 : 0;

                rp_upsert_sidebar_permission($conn, $roleId, $sidebarItemId, $canShow);

                $values = [];
                foreach ($permissionColumns as $column => $label) {
                    $values[$column] = isset($permissionPost[$sidebarItemId][$column]) ? 1 : 0;
                }

                if ($canShow === 1) {
                    $values['can_view'] = 1;
                }

                rp_upsert_page_permission($conn, $roleId, $sidebarItemId, $values, $permissionColumns);
            }

            rp_redirect('msg=permissions_saved&role_id=' . $roleId);
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'role_created') {
    $message = 'Role created successfully.';
} elseif ($msg === 'role_updated') {
    $message = 'Role updated successfully.';
} elseif ($msg === 'role_disabled') {
    $message = 'Role disabled successfully.';
} elseif ($msg === 'permissions_saved') {
    $message = 'Permissions saved successfully.';
}

$roles = rp_get_roles($conn);
$activeRoles = rp_get_active_roles($conn);
$items = rp_get_sidebar_items($conn);
$tree = rp_build_tree($items);

$selectedRoleId = rp_int($_GET['role_id'] ?? 0);
if ($selectedRoleId <= 0 && $activeRoles) {
    $selectedRoleId = (int)$activeRoles[0]['id'];
}

$selectedRole = rp_get_role_by_id($conn, $selectedRoleId);
$rolePermissions = rp_get_role_permissions($conn, $selectedRoleId);

$totalRoles = count($roles);
$totalActiveRoles = count($activeRoles);
$totalPages = count($items);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Roles & Permissions - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
    .roles-page .roles-head {
        padding: 24px 28px;
        margin-bottom: 18px;
    }

    .roles-page .roles-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main);
    }

    .rp-stat-card {
        padding: 18px;
        min-height: 112px;
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .rp-stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto;
    }

    .rp-stat-card span {
        display: block;
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 900;
        text-transform: uppercase;
    }

    .rp-stat-card strong {
        font-size: 24px;
        font-weight: 900;
        color: var(--text-main);
    }

    .rp-card {
        padding: 24px;
    }

    .rp-section-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
        margin-bottom: 18px;
    }

    .rp-role-pill {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-weight: 900;
        padding: 7px 11px;
        font-size: 12px;
    }

    .rp-role-pill.active {
        color: var(--success-color);
        background: color-mix(in srgb, var(--success-color) 14%, transparent);
    }

    .rp-role-pill.inactive {
        color: var(--danger-color);
        background: color-mix(in srgb, var(--danger-color) 14%, transparent);
    }

    .permission-table th,
    .permission-table td {
        vertical-align: middle;
        white-space: nowrap;
    }

    .permission-table th {
        text-align: center;
    }

    .permission-table th:first-child,
    .permission-table td:first-child {
        text-align: left;
        position: sticky;
        left: 0;
        z-index: 2;
        background: var(--card-bg);
    }

    .permission-table thead th:first-child {
        z-index: 3;
        background: var(--table-header-bg);
    }

    .rp-menu-name {
        min-width: 280px;
        font-weight: 900;
    }

    .rp-child {
        padding-left: 34px !important;
        color: var(--text-muted);
    }

    .rp-route {
        display: block;
        margin-top: 3px;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 700;
    }

    .rp-check {
        width: 18px;
        height: 18px;
        cursor: pointer;
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

    .form-control,
    .form-select {
        border-radius: 14px;
        min-height: 46px;
    }

    @media (max-width: 991px) {
        .rp-card {
            padding: 18px;
        }

        .roles-page .roles-head {
            padding: 20px;
        }
    }

    /* =========================================================
           MOBILE CARD VIEW FIX
           Tables remain on desktop, but mobile shows clean cards.
           ========================================================= */

    .mobile-role-cards,
    .mobile-permission-cards {
        display: none;
    }

    .mobile-card-item {
        border: 1px solid var(--border-soft);
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        border-radius: 18px;
        padding: 16px;
        margin-bottom: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .05);
    }

    .mobile-card-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 12px;
    }

    .mobile-card-title {
        font-size: 16px;
        font-weight: 900;
        color: var(--text-main);
        line-height: 1.25;
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

    .mobile-card-actions .btn {
        flex: 1 1 auto;
        min-width: 96px;
    }

    .mobile-permission-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-top: 14px;
    }

    .mobile-permission-check {
        border: 1px solid var(--border-soft);
        background: var(--card-bg);
        border-radius: 14px;
        padding: 10px 11px;
        display: flex;
        align-items: center;
        gap: 8px;
        min-height: 42px;
    }

    .mobile-permission-check span {
        font-size: 12px;
        font-weight: 800;
        color: var(--text-main);
    }

    .mobile-permission-check .form-check-input {
        margin: 0;
        flex: 0 0 auto;
    }

    .mobile-role-select-form {
        width: 100%;
    }

    @media (max-width: 767.98px) {
        .roles-page .roles-head {
            padding: 18px;
            border-radius: 18px;
        }

        .roles-page .roles-head h1 {
            font-size: 24px;
        }

        .roles-page .roles-head .btn {
            width: 100%;
        }

        .rp-stat-card {
            min-height: auto;
            padding: 15px;
        }

        .rp-stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 14px;
        }

        .rp-stat-card strong {
            font-size: 21px;
        }

        .rp-card {
            padding: 16px;
            border-radius: 18px;
        }

        .rp-section-title {
            font-size: 17px;
        }

        .desktop-role-table,
        .desktop-permission-table {
            display: none !important;
        }

        .mobile-role-cards,
        .mobile-permission-cards {
            display: block;
        }

        .permission-role-select-wrap {
            width: 100%;
        }

        .permission-role-select-wrap .form-select {
            width: 100%;
        }

        .mobile-permission-save {
            position: sticky;
            bottom: 12px;
            z-index: 20;
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 10px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, .12);
        }

        .mobile-permission-save .btn {
            width: 100%;
        }
    }

    @media (max-width: 420px) {
        .mobile-permission-grid {
            grid-template-columns: 1fr;
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

            <section class="page-section roles-page">
                <div class="card-ui roles-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Roles & Permissions</h1>
                            <p class="text-muted-custom mb-0">
                                Manage roles and page permissions in a separate tabular view.
                            </p>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <a href="sidebar_settings.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                                Sidebar Settings
                            </a>

                            <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" id="newRoleBtn"
                                data-bs-toggle="modal" data-bs-target="#roleModal">
                                Create New Role
                            </button>
                        </div>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                <div class="alert alert-<?= e($messageType) ?> rounded-4 fw-bold">
                    <?= e($message) ?>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4">
                        <div class="card-ui rp-stat-card h-100">
                            <div class="rp-stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)">
                                <i data-lucide="shield-check"></i>
                            </div>
                            <div>
                                <span>Total Roles</span>
                                <strong><?= number_format($totalRoles) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui rp-stat-card h-100">
                            <div class="rp-stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                <i data-lucide="user-check"></i>
                            </div>
                            <div>
                                <span>Active Roles</span>
                                <strong><?= number_format($totalActiveRoles) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui rp-stat-card h-100">
                            <div class="rp-stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                <i data-lucide="file-check-2"></i>
                            </div>
                            <div>
                                <span>Permission Pages</span>
                                <strong><?= number_format($totalPages) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui rp-card mb-3">
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="rp-section-title mb-1">Role List</h2>
                            <p class="text-muted-custom mb-0">Create and edit roles from modal view.</p>
                        </div>

                        <div style="max-width:340px;width:100%">
                            <input type="search" id="roleSearch" class="form-control" placeholder="Search role...">
                        </div>
                    </div>

                    <div class="table-responsive desktop-role-table">
                        <table class="table-ui" id="roleTable">
                            <thead>
                                <tr>
                                    <th>Role Name</th>
                                    <th>Role Key</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$roles): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted-custom py-4">
                                        No roles found.
                                    </td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($roles as $role): ?>
                                <tr>
                                    <td><strong><?= e($role['role_name']) ?></strong></td>
                                    <td><?= e($role['role_key']) ?></td>
                                    <td>
                                        <span
                                            class="rp-role-pill <?= (int)$role['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                            <?= (int)$role['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="roles_permissions.php?role_id=<?= e($role['id']) ?>"
                                            class="btn btn-sm btn-outline-secondary rounded-pill fw-bold">
                                            Permissions
                                        </a>

                                        <button type="button"
                                            class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-role"
                                            data-bs-toggle="modal" data-bs-target="#roleModal"
                                            data-role-id="<?= e($role['id']) ?>"
                                            data-role-name="<?= e($role['role_name']) ?>"
                                            data-role-key="<?= e($role['role_key']) ?>"
                                            data-is-active="<?= e($role['is_active']) ?>">
                                            Edit
                                        </button>

                                        <?php if (strtolower((string)$role['role_key']) !== 'admin' && (int)$role['is_active'] === 1): ?>
                                        <form method="post" class="d-inline"
                                            onsubmit="return confirm('Disable this role?')">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="disable_role">
                                            <input type="hidden" name="role_id" value="<?= e($role['id']) ?>">
                                            <button type="submit"
                                                class="btn btn-sm btn-outline-danger rounded-pill fw-bold">
                                                Disable
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-role-cards" id="roleMobileCards">
                        <?php if (!$roles): ?>
                        <div class="mobile-card-item text-center text-muted-custom">
                            No roles found.
                        </div>
                        <?php endif; ?>

                        <?php foreach ($roles as $role): ?>
                        <div class="mobile-card-item">
                            <div class="mobile-card-top">
                                <div>
                                    <div class="mobile-card-title"><?= e($role['role_name']) ?></div>
                                    <span class="mobile-card-subtitle"><?= e($role['role_key']) ?></span>
                                </div>

                                <span class="rp-role-pill <?= (int)$role['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                    <?= (int)$role['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>

                            <div class="mobile-card-actions">
                                <a href="roles_permissions.php?role_id=<?= e($role['id']) ?>"
                                    class="btn btn-sm btn-outline-secondary rounded-pill fw-bold">
                                    Permissions
                                </a>

                                <button type="button"
                                    class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-role"
                                    data-bs-toggle="modal" data-bs-target="#roleModal"
                                    data-role-id="<?= e($role['id']) ?>" data-role-name="<?= e($role['role_name']) ?>"
                                    data-role-key="<?= e($role['role_key']) ?>"
                                    data-is-active="<?= e($role['is_active']) ?>">
                                    Edit
                                </button>

                                <?php if (strtolower((string)$role['role_key']) !== 'admin' && (int)$role['is_active'] === 1): ?>
                                <form method="post" class="d-inline flex-fill"
                                    onsubmit="return confirm('Disable this role?')">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="disable_role">
                                    <input type="hidden" name="role_id" value="<?= e($role['id']) ?>">
                                    <button type="submit"
                                        class="btn btn-sm btn-outline-danger rounded-pill fw-bold w-100">
                                        Disable
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card-ui rp-card">
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="rp-section-title mb-1">Permission Matrix</h2>
                            <p class="text-muted-custom mb-0">
                                Select a role and control sidebar visibility plus page actions.
                            </p>
                        </div>

                        <form method="get" class="d-flex gap-2 align-items-center permission-role-select-wrap">
                            <select name="role_id" class="form-select" onchange="this.form.submit()">
                                <?php foreach ($activeRoles as $role): ?>
                                <option value="<?= e($role['id']) ?>"
                                    <?= (int)$selectedRoleId === (int)$role['id'] ? 'selected' : '' ?>>
                                    <?= e($role['role_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>

                    <?php if (!$selectedRole): ?>
                    <div class="text-muted-custom">Please create/select a role.</div>
                    <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_permissions">
                        <input type="hidden" name="role_id" value="<?= e($selectedRoleId) ?>">

                        <div class="alert alert-info rounded-4">
                            Editing permissions for:
                            <strong><?= e($selectedRole['role_name']) ?></strong>
                        </div>

                        <div class="table-responsive desktop-permission-table">
                            <table class="table-ui permission-table">
                                <thead>
                                    <tr>
                                        <th class="rp-menu-name">Menu / Page</th>
                                        <th>Sidebar</th>
                                        <?php foreach ($permissionColumns as $column => $label): ?>
                                        <th><?= e($label) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tree as $parent): ?>
                                    <?php
                                            $parentId = (int)$parent['id'];
                                            $parentPage = $rolePermissions['page'][$parentId] ?? [];
                                            $parentSidebar = isset($rolePermissions['sidebar'][$parentId])
                                                ? (int)$rolePermissions['sidebar'][$parentId] === 1
                                                : strtolower((string)$selectedRole['role_key']) === 'admin';
                                            ?>
                                    <tr>
                                        <td class="rp-menu-name">
                                            <i data-lucide="<?= e($parent['icon'] ?: 'circle') ?>"
                                                style="width:16px"></i>
                                            <?= e($parent['menu_title']) ?>
                                            <span class="rp-route"><?= e($parent['route'] ?: '#') ?></span>
                                        </td>
                                        <td class="text-center">
                                            <input class="form-check-input rp-check js-row-sidebar" type="checkbox"
                                                name="sidebar[<?= e($parentId) ?>]" value="1"
                                                <?= $parentSidebar ? 'checked' : '' ?>>
                                        </td>

                                        <?php foreach ($permissionColumns as $column => $label): ?>
                                        <?php
                                                    $checked = isset($parentPage[$column])
                                                        ? (int)$parentPage[$column] === 1
                                                        : (strtolower((string)$selectedRole['role_key']) === 'admin' || $column === 'can_view');
                                                    ?>
                                        <td class="text-center">
                                            <input class="form-check-input rp-check js-row-permission" type="checkbox"
                                                name="perm[<?= e($parentId) ?>][<?= e($column) ?>]" value="1"
                                                <?= $checked ? 'checked' : '' ?>>
                                        </td>
                                        <?php endforeach; ?>
                                    </tr>

                                    <?php foreach (($parent['children'] ?? []) as $child): ?>
                                    <?php
                                                $childId = (int)$child['id'];
                                                $childPage = $rolePermissions['page'][$childId] ?? [];
                                                $childSidebar = isset($rolePermissions['sidebar'][$childId])
                                                    ? (int)$rolePermissions['sidebar'][$childId] === 1
                                                    : strtolower((string)$selectedRole['role_key']) === 'admin';
                                                ?>
                                    <tr>
                                        <td class="rp-menu-name rp-child">
                                            <i data-lucide="<?= e($child['icon'] ?: 'circle') ?>"
                                                style="width:16px"></i>
                                            <?= e($child['menu_title']) ?>
                                            <span class="rp-route"><?= e($child['route'] ?: '#') ?></span>
                                        </td>
                                        <td class="text-center">
                                            <input class="form-check-input rp-check js-row-sidebar" type="checkbox"
                                                name="sidebar[<?= e($childId) ?>]" value="1"
                                                <?= $childSidebar ? 'checked' : '' ?>>
                                        </td>

                                        <?php foreach ($permissionColumns as $column => $label): ?>
                                        <?php
                                                        $checked = isset($childPage[$column])
                                                            ? (int)$childPage[$column] === 1
                                                            : (strtolower((string)$selectedRole['role_key']) === 'admin' || $column === 'can_view');
                                                        ?>
                                        <td class="text-center">
                                            <input class="form-check-input rp-check js-row-permission" type="checkbox"
                                                name="perm[<?= e($childId) ?>][<?= e($column) ?>]" value="1"
                                                <?= $checked ? 'checked' : '' ?>>
                                        </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mobile-permission-cards">
                            <?php foreach ($tree as $parent): ?>
                            <?php
                                    $parentId = (int)$parent['id'];
                                    $parentPage = $rolePermissions['page'][$parentId] ?? [];
                                    $parentSidebar = isset($rolePermissions['sidebar'][$parentId])
                                        ? (int)$rolePermissions['sidebar'][$parentId] === 1
                                        : strtolower((string)$selectedRole['role_key']) === 'admin';
                                    ?>
                            <div class="mobile-card-item">
                                <div class="mobile-card-top">
                                    <div>
                                        <div class="mobile-card-title">
                                            <i data-lucide="<?= e($parent['icon'] ?: 'circle') ?>"
                                                style="width:16px"></i>
                                            <?= e($parent['menu_title']) ?>
                                        </div>
                                        <span class="mobile-card-subtitle"><?= e($parent['route'] ?: '#') ?></span>
                                    </div>
                                </div>

                                <div class="mobile-permission-grid">
                                    <label class="mobile-permission-check">
                                        <input class="form-check-input rp-check js-row-sidebar" type="checkbox"
                                            name="sidebar[<?= e($parentId) ?>]" value="1"
                                            <?= $parentSidebar ? 'checked' : '' ?>>
                                        <span>Sidebar</span>
                                    </label>

                                    <?php foreach ($permissionColumns as $column => $label): ?>
                                    <?php
                                                $checked = isset($parentPage[$column])
                                                    ? (int)$parentPage[$column] === 1
                                                    : (strtolower((string)$selectedRole['role_key']) === 'admin' || $column === 'can_view');
                                                ?>
                                    <label class="mobile-permission-check">
                                        <input class="form-check-input rp-check js-row-permission" type="checkbox"
                                            name="perm[<?= e($parentId) ?>][<?= e($column) ?>]" value="1"
                                            <?= $checked ? 'checked' : '' ?>>
                                        <span><?= e($label) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php foreach (($parent['children'] ?? []) as $child): ?>
                            <?php
                                        $childId = (int)$child['id'];
                                        $childPage = $rolePermissions['page'][$childId] ?? [];
                                        $childSidebar = isset($rolePermissions['sidebar'][$childId])
                                            ? (int)$rolePermissions['sidebar'][$childId] === 1
                                            : strtolower((string)$selectedRole['role_key']) === 'admin';
                                        ?>
                            <div class="mobile-card-item">
                                <div class="mobile-card-top">
                                    <div>
                                        <div class="mobile-card-title">
                                            <i data-lucide="<?= e($child['icon'] ?: 'circle') ?>"
                                                style="width:16px"></i>
                                            <?= e($child['menu_title']) ?>
                                        </div>
                                        <span class="mobile-card-subtitle"><?= e($child['route'] ?: '#') ?></span>
                                    </div>
                                </div>

                                <div class="mobile-permission-grid">
                                    <label class="mobile-permission-check">
                                        <input class="form-check-input rp-check js-row-sidebar" type="checkbox"
                                            name="sidebar[<?= e($childId) ?>]" value="1"
                                            <?= $childSidebar ? 'checked' : '' ?>>
                                        <span>Sidebar</span>
                                    </label>

                                    <?php foreach ($permissionColumns as $column => $label): ?>
                                    <?php
                                                    $checked = isset($childPage[$column])
                                                        ? (int)$childPage[$column] === 1
                                                        : (strtolower((string)$selectedRole['role_key']) === 'admin' || $column === 'can_view');
                                                    ?>
                                    <label class="mobile-permission-check">
                                        <input class="form-check-input rp-check js-row-permission" type="checkbox"
                                            name="perm[<?= e($childId) ?>][<?= e($column) ?>]" value="1"
                                            <?= $checked ? 'checked' : '' ?>>
                                        <span><?= e($label) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>

                        <div class="d-flex justify-content-end mt-3 mobile-permission-save">
                            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
                                Save Permissions
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <div class="modal fade" id="roleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save_role">
                <input type="hidden" name="role_id" id="role_id" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="roleModalTitle">Create New Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Role Name *</label>
                        <input type="text" name="role_name" id="role_name" class="form-control" required
                            placeholder="Example: Designer">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Role Key</label>
                        <input type="text" name="role_key" id="role_key" class="form-control"
                            placeholder="Auto generated if empty">
                    </div>

                    <label class="form-check">
                        <input type="checkbox" name="is_active" id="role_is_active" value="1" class="form-check-input"
                            checked>
                        <span class="form-check-label fw-bold">Active</span>
                    </label>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" id="roleSubmitBtn">
                        Save Role
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
    (function() {
        const roleModalTitle = document.getElementById('roleModalTitle');
        const roleSubmitBtn = document.getElementById('roleSubmitBtn');
        const roleId = document.getElementById('role_id');
        const roleName = document.getElementById('role_name');
        const roleKey = document.getElementById('role_key');
        const roleActive = document.getElementById('role_is_active');

        document.getElementById('newRoleBtn')?.addEventListener('click', function() {
            roleModalTitle.textContent = 'Create New Role';
            roleSubmitBtn.textContent = 'Save Role';
            roleId.value = '';
            roleName.value = '';
            roleKey.value = '';
            roleActive.checked = true;
        });

        document.querySelectorAll('.js-edit-role').forEach(function(button) {
            button.addEventListener('click', function() {
                roleModalTitle.textContent = 'Edit Role';
                roleSubmitBtn.textContent = 'Update Role';
                roleId.value = button.dataset.roleId || '';
                roleName.value = button.dataset.roleName || '';
                roleKey.value = button.dataset.roleKey || '';
                roleActive.checked = String(button.dataset.isActive || '0') === '1';
            });
        });

        document.getElementById('roleSearch')?.addEventListener('input', function() {
            const value = this.value.toLowerCase().trim();

            document.querySelectorAll('#roleTable tbody tr').forEach(function(row) {
                row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
            });

            document.querySelectorAll('#roleMobileCards .mobile-card-item').forEach(function(card) {
                card.style.display = card.textContent.toLowerCase().includes(value) ? '' : 'none';
            });
        });

        document.querySelectorAll('.js-row-sidebar').forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                const row = checkbox.closest('tr');
                if (!row) return;

                const viewPermission = row.querySelector('input[name*="[can_view]"]');

                if (checkbox.checked && viewPermission) {
                    viewPermission.checked = true;
                }
            });
        });

        document.querySelectorAll('.js-row-permission').forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                const row = checkbox.closest('tr');
                if (!row) return;

                const sidebar = row.querySelector('.js-row-sidebar');
                const viewPermission = row.querySelector('input[name*="[can_view]"]');

                if (checkbox.checked && sidebar) {
                    sidebar.checked = true;
                }

                if (checkbox.checked && viewPermission) {
                    viewPermission.checked = true;
                }
            });
        });

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    })();
    </script>
</body>

</html>