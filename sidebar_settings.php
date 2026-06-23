<?php
/**
 * sidebar_settings.php
 * Subhiksha Cards ERP - Sidebar Menu Settings Only
 *
 * This file is only for:
 * - Create parent menu
 * - Create submenu
 * - Edit menu/submenu
 * - Disable menu/submenu
 *
 * Role and permission management removed.
 * Use roles_permissions.php for roles and permissions.
 *
 * Tables used:
 * - sidebar_items
 */

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'sidebar_settings.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['sidebar_settings_csrf'])) {
    $_SESSION['sidebar_settings_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['sidebar_settings_csrf'];
$message = '';
$messageType = 'success';

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

function ss_redirect(string $extra = ''): void
{
    $url = 'sidebar_settings.php';
    if ($extra !== '') {
        $url .= '?' . ltrim($extra, '?');
    }
    header('Location: ' . $url);
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

function ss_get_sidebar_items(mysqli $conn): array
{
    $rows = [];

    try {
        if (!ss_table_exists($conn, 'sidebar_items')) {
            return [];
        }

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
            FROM sidebar_items
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

function ss_get_parent_menus(array $items): array
{
    $parents = [];

    foreach ($items as $item) {
        if (empty($item['parent_id'])) {
            $parents[] = $item;
        }
    }

    return $parents;
}

function ss_build_tree(array $items): array
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ss_require_csrf();

    $action = ss_post_string('action');

    try {
        if ($action === 'save_menu') {
            if (!ss_table_exists($conn, 'sidebar_items')) {
                throw new RuntimeException('sidebar_items table is missing.');
            }

            $id = ss_int($_POST['id'] ?? 0);
            $parentIdRaw = ss_int($_POST['parent_id'] ?? 0);
            $parentId = $parentIdRaw > 0 ? $parentIdRaw : null;

            $menuTitle = ss_post_string('menu_title');
            $pageTitle = ss_post_string('page_title');
            $menuKey = ss_post_string('menu_key');
            $route = ss_post_string('route');
            $icon = ss_post_string('icon', 'circle');
            $sortOrder = ss_int($_POST['sort_order'] ?? 0);
            $isHeader = isset($_POST['is_header']) ? 1 : 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($menuTitle === '') {
                throw new RuntimeException('Menu title is required.');
            }

            $menuKey = $menuKey !== '' ? ss_slug($menuKey) : ss_slug($menuTitle);

            if ($pageTitle === '') {
                $pageTitle = $menuTitle;
            }

            if ($id > 0) {
                if ($parentId === $id) {
                    $parentId = null;
                }

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
                $stmt->execute();
                $stmt->close();

                ss_redirect('msg=updated');
            }

            $stmt = $conn->prepare("
                INSERT INTO sidebar_items
                    (
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
                    )
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
            $stmt->execute();
            $stmt->close();

            ss_redirect('msg=created');
        }

        if ($action === 'delete_menu') {
            if (!ss_table_exists($conn, 'sidebar_items')) {
                throw new RuntimeException('sidebar_items table is missing.');
            }

            $id = ss_int($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid menu.');
            }

            $stmt = $conn->prepare("
                UPDATE sidebar_items
                SET is_active = 0, updated_at = NOW()
                WHERE id = ? OR parent_id = ?
            ");
            $stmt->bind_param('ii', $id, $id);
            $stmt->execute();
            $stmt->close();

            ss_redirect('msg=deleted');
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') {
    $message = 'Sidebar menu created successfully.';
} elseif ($msg === 'updated') {
    $message = 'Sidebar menu updated successfully.';
} elseif ($msg === 'deleted') {
    $message = 'Sidebar menu disabled successfully.';
}

$items = ss_get_sidebar_items($conn);
$parents = ss_get_parent_menus($items);
$tree = ss_build_tree($items);

$totalMenus = count($items);
$totalParents = count($parents);
$totalSubmenus = max(0, $totalMenus - $totalParents);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sidebar Settings - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
        .sidebar-settings-page .settings-head {
            padding: 24px 28px;
            margin-bottom: 18px;
        }

        .sidebar-settings-page .settings-head h1 {
            font-size: 30px;
            font-weight: 900;
            color: var(--text-main);
        }

        .ss-stat-card {
            padding: 18px;
            min-height: 112px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .ss-stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            color: #fff;
            flex: 0 0 auto;
        }

        .ss-stat-card span {
            display: block;
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 900;
            text-transform: uppercase;
        }

        .ss-stat-card strong {
            font-size: 24px;
            font-weight: 900;
            color: var(--text-main);
        }

        .ss-card {
            padding: 24px;
        }

        .ss-section-title {
            font-size: 18px;
            font-weight: 900;
            color: var(--text-main);
            margin-bottom: 18px;
        }

        .menu-title-main {
            font-weight: 900;
            color: var(--text-main);
        }

        .menu-child-title {
            padding-left: 28px;
            color: var(--text-muted);
            font-weight: 900;
        }

        .menu-route {
            display: block;
            margin-top: 3px;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
        }

        .menu-status {
            font-size: 11px;
            font-weight: 900;
            border-radius: 999px;
            padding: 5px 9px;
        }

        .menu-status.active {
            color: var(--success-color);
            background: color-mix(in srgb, var(--success-color) 14%, transparent);
        }

        .menu-status.inactive {
            color: var(--danger-color);
            background: color-mix(in srgb, var(--danger-color) 14%, transparent);
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
            .ss-card {
                padding: 18px;
            }

            .sidebar-settings-page .settings-head {
                padding: 20px;
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

            <section class="page-section sidebar-settings-page">
                <div class="card-ui settings-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Sidebar Settings</h1>
                            <p class="text-muted-custom mb-0">
                                Manage only sidebar menus and submenus. Use Roles & Permissions page for role access.
                            </p>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <a href="roles_permissions.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                                Roles & Permissions
                            </a>

                            <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" id="newMenuBtn"
                                data-bs-toggle="modal" data-bs-target="#menuModal">
                                Create New Menu
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
                        <div class="card-ui ss-stat-card h-100">
                            <div class="ss-stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)">
                                <i data-lucide="panel-left"></i>
                            </div>
                            <div>
                                <span>Total Menus</span>
                                <strong><?= number_format($totalMenus) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui ss-stat-card h-100">
                            <div class="ss-stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                <i data-lucide="folder"></i>
                            </div>
                            <div>
                                <span>Parent Menus</span>
                                <strong><?= number_format($totalParents) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="card-ui ss-stat-card h-100">
                            <div class="ss-stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                <i data-lucide="list-tree"></i>
                            </div>
                            <div>
                                <span>Submenus</span>
                                <strong><?= number_format($totalSubmenus) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui ss-card">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="ss-section-title mb-1">Sidebar Menu List</h2>
                            <p class="text-muted-custom mb-0">Tabular menu/submenu management.</p>
                        </div>

                        <div style="max-width:340px;width:100%">
                            <input type="search" id="menuSearch" class="form-control" placeholder="Search menu...">
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table-ui" id="menuTable">
                            <thead>
                                <tr>
                                    <th>Menu</th>
                                    <th>Route</th>
                                    <th>Icon</th>
                                    <th>Sort</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$tree): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted-custom py-4">
                                            No sidebar menu found.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($tree as $parent): ?>
                                    <tr>
                                        <td>
                                            <div class="menu-title-main">
                                                <i data-lucide="<?= e($parent['icon'] ?: 'circle') ?>" style="width:16px"></i>
                                                <?= e($parent['menu_title']) ?>
                                            </div>
                                            <span class="menu-route"><?= e($parent['menu_key']) ?></span>
                                        </td>
                                        <td><?= e($parent['route'] ?: '#') ?></td>
                                        <td><?= e($parent['icon'] ?: 'circle') ?></td>
                                        <td><?= e($parent['sort_order']) ?></td>
                                        <td>
                                            <span class="menu-status <?= (int)$parent['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                                <?= (int)$parent['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <button type="button"
                                                class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-menu"
                                                data-bs-toggle="modal"
                                                data-bs-target="#menuModal"
                                                data-id="<?= e($parent['id']) ?>"
                                                data-parent-id="<?= e($parent['parent_id'] ?? '') ?>"
                                                data-menu-key="<?= e($parent['menu_key']) ?>"
                                                data-menu-title="<?= e($parent['menu_title']) ?>"
                                                data-page-title="<?= e($parent['page_title']) ?>"
                                                data-route="<?= e($parent['route']) ?>"
                                                data-icon="<?= e($parent['icon']) ?>"
                                                data-sort-order="<?= e($parent['sort_order']) ?>"
                                                data-is-header="<?= e($parent['is_header']) ?>"
                                                data-is-active="<?= e($parent['is_active']) ?>">
                                                Edit
                                            </button>

                                            <form method="post" class="d-inline" onsubmit="return confirm('Disable this menu and its submenus?')">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete_menu">
                                                <input type="hidden" name="id" value="<?= e($parent['id']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill fw-bold">
                                                    Disable
                                                </button>
                                            </form>
                                        </td>
                                    </tr>

                                    <?php foreach (($parent['children'] ?? []) as $child): ?>
                                        <tr>
                                            <td>
                                                <div class="menu-child-title">
                                                    <i data-lucide="<?= e($child['icon'] ?: 'circle') ?>" style="width:16px"></i>
                                                    <?= e($child['menu_title']) ?>
                                                </div>
                                                <span class="menu-route"><?= e($child['menu_key']) ?></span>
                                            </td>
                                            <td><?= e($child['route'] ?: '#') ?></td>
                                            <td><?= e($child['icon'] ?: 'circle') ?></td>
                                            <td><?= e($child['sort_order']) ?></td>
                                            <td>
                                                <span class="menu-status <?= (int)$child['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                                    <?= (int)$child['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-menu"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#menuModal"
                                                    data-id="<?= e($child['id']) ?>"
                                                    data-parent-id="<?= e($child['parent_id'] ?? '') ?>"
                                                    data-menu-key="<?= e($child['menu_key']) ?>"
                                                    data-menu-title="<?= e($child['menu_title']) ?>"
                                                    data-page-title="<?= e($child['page_title']) ?>"
                                                    data-route="<?= e($child['route']) ?>"
                                                    data-icon="<?= e($child['icon']) ?>"
                                                    data-sort-order="<?= e($child['sort_order']) ?>"
                                                    data-is-header="<?= e($child['is_header']) ?>"
                                                    data-is-active="<?= e($child['is_active']) ?>">
                                                    Edit
                                                </button>

                                                <form method="post" class="d-inline" onsubmit="return confirm('Disable this submenu?')">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="delete_menu">
                                                    <input type="hidden" name="id" value="<?= e($child['id']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill fw-bold">
                                                        Disable
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <div class="modal fade" id="menuModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="post" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save_menu">
                <input type="hidden" name="id" id="menu_id" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="menuModalTitle">Create New Menu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold">Parent Menu</label>
                            <select name="parent_id" id="menu_parent_id" class="form-select">
                                <option value="">Main Menu</option>
                                <?php foreach ($parents as $parent): ?>
                                    <option value="<?= e($parent['id']) ?>">
                                        <?= e($parent['menu_title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold">Menu Title *</label>
                            <input type="text" name="menu_title" id="menu_title" class="form-control" required
                                placeholder="Example: Master Controls">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold">Menu Key</label>
                            <input type="text" name="menu_key" id="menu_key" class="form-control"
                                placeholder="Auto generated if empty">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold">Page Title</label>
                            <input type="text" name="page_title" id="page_title" class="form-control"
                                placeholder="Example: Sidebar Settings">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold">Route / URL</label>
                            <input type="text" name="route" id="route" class="form-control"
                                placeholder="Example: sidebar_settings.php or #">
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold">Lucide Icon</label>
                            <input type="text" name="icon" id="icon" class="form-control"
                                value="circle" placeholder="settings">
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold">Sort Order</label>
                            <input type="number" name="sort_order" id="sort_order" class="form-control" value="0">
                        </div>

                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-3">
                                <label class="form-check">
                                    <input type="checkbox" name="is_active" value="1" id="is_active" class="form-check-input" checked>
                                    <span class="form-check-label fw-bold">Active</span>
                                </label>

                                <label class="form-check">
                                    <input type="checkbox" name="is_header" value="1" id="is_header" class="form-check-input">
                                    <span class="form-check-label fw-bold">Header</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" id="menuSubmitBtn">
                        Save Menu
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
        (function () {
            const modalTitle = document.getElementById('menuModalTitle');
            const submitBtn = document.getElementById('menuSubmitBtn');

            const fields = {
                id: document.getElementById('menu_id'),
                parent_id: document.getElementById('menu_parent_id'),
                menu_key: document.getElementById('menu_key'),
                menu_title: document.getElementById('menu_title'),
                page_title: document.getElementById('page_title'),
                route: document.getElementById('route'),
                icon: document.getElementById('icon'),
                sort_order: document.getElementById('sort_order'),
                is_header: document.getElementById('is_header'),
                is_active: document.getElementById('is_active')
            };

            function setValue(field, value) {
                if (!field) return;
                field.value = value == null ? '' : value;
            }

            document.getElementById('newMenuBtn')?.addEventListener('click', function () {
                modalTitle.textContent = 'Create New Menu';
                submitBtn.textContent = 'Save Menu';

                setValue(fields.id, '');
                setValue(fields.parent_id, '');
                setValue(fields.menu_key, '');
                setValue(fields.menu_title, '');
                setValue(fields.page_title, '');
                setValue(fields.route, '');
                setValue(fields.icon, 'circle');
                setValue(fields.sort_order, 0);

                fields.is_header.checked = false;
                fields.is_active.checked = true;
            });

            document.querySelectorAll('.js-edit-menu').forEach(function (button) {
                button.addEventListener('click', function () {
                    const data = button.dataset;

                    modalTitle.textContent = 'Edit Menu';
                    submitBtn.textContent = 'Update Menu';

                    setValue(fields.id, data.id || '');
                    setValue(fields.parent_id, data.parentId || '');
                    setValue(fields.menu_key, data.menuKey || '');
                    setValue(fields.menu_title, data.menuTitle || '');
                    setValue(fields.page_title, data.pageTitle || '');
                    setValue(fields.route, data.route || '');
                    setValue(fields.icon, data.icon || 'circle');
                    setValue(fields.sort_order, data.sortOrder || 0);

                    fields.is_header.checked = String(data.isHeader || '0') === '1';
                    fields.is_active.checked = String(data.isActive || '0') === '1';
                });
            });

            document.getElementById('menuSearch')?.addEventListener('input', function () {
                const value = this.value.toLowerCase().trim();

                document.querySelectorAll('#menuTable tbody tr').forEach(function (row) {
                    row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
                });
            });

            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        })();
    </script>
</body>

</html>
