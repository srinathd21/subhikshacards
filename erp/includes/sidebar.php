<?php
/**
 * includes/sidebar.php
 * Subhiksha Cards ERP - Safe dynamic sidebar
 * Works even if some permission tables are missing.
 * Uses current DB: sidebar_items + role_sidebar_permissions
 */

$currentPage = basename($_SERVER['PHP_SELF']);
$userId      = (int)($_SESSION['user_id'] ?? 0);
$roleId      = (int)($_SESSION['role_id'] ?? 0);
$roleKey     = strtolower((string)($_SESSION['role_key'] ?? ''));
$roleName    = strtolower((string)($_SESSION['role_name'] ?? ''));
$isAdmin     = ($roleKey === 'admin' || $roleName === 'admin');

$sidebarMenus = [];

if (!function_exists('sidebar_table_exists')) {
    function sidebar_table_exists(mysqli $conn, string $table): bool
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
}

if (!function_exists('sidebar_active')) {
    function sidebar_active(string $url, string $currentPage): bool
    {
        if ($url === '' || $url === '#') {
            return false;
        }
        return basename(parse_url($url, PHP_URL_PATH) ?: '') === $currentPage;
    }
}

if (!function_exists('sidebar_child_active')) {
    function sidebar_child_active(array $children, string $currentPage): bool
    {
        foreach ($children as $child) {
            if (sidebar_active((string)($child['menu_url'] ?? ''), $currentPage)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('sidebar_icon')) {
    function sidebar_icon(?string $icon): string
    {
        $icon = trim((string)$icon);
        if ($icon === '') {
            return 'circle';
        }

        if (str_contains($icon, 'fa-')) {
            $parts = preg_split('/\s+/', $icon);
            $lastIcon = '';

            foreach ($parts as $part) {
                if (str_starts_with($part, 'fa-') && !in_array($part, ['fa-solid', 'fa-regular', 'fa-brands'], true)) {
                    $lastIcon = substr($part, 3);
                }
            }

            $map = [
                'gauge-high' => 'layout-dashboard',
                'dashboard' => 'layout-dashboard',
                'home' => 'home',
                'phone' => 'phone',
                'phone-volume' => 'phone-call',
                'comments' => 'messages-square',
                'comment' => 'message-circle',
                'file-invoice' => 'file-text',
                'file-lines' => 'file-text',
                'receipt' => 'receipt',
                'money-bill' => 'banknote',
                'briefcase' => 'briefcase',
                'truck-fast' => 'truck',
                'truck' => 'truck',
                'circle-check' => 'circle-check',
                'triangle-exclamation' => 'triangle-alert',
                'users' => 'users',
                'user' => 'user',
                'gear' => 'settings',
                'cog' => 'settings',
                'right-from-bracket' => 'log-out',
                'print' => 'printer',
                'id-card' => 'badge',
                'palette' => 'palette',
                'database' => 'database',
                'list' => 'list',
            ];

            return $map[$lastIcon] ?? ($lastIcon ?: 'circle');
        }

        return $icon;
    }
}

try {
    if (
        isset($conn)
        && $conn instanceof mysqli
        && $roleId > 0
        && sidebar_table_exists($conn, 'sidebar_items')
        && sidebar_table_exists($conn, 'role_sidebar_permissions')
    ) {
        /*
         | Strict role-based sidebar.
         | No Admin bypass: Admin also follows role_sidebar_permissions.can_show.
         */
        $sql = "
            SELECT DISTINCT
                si.id,
                si.parent_id,
                si.menu_key,
                si.menu_title,
                si.page_title,
                si.route AS menu_url,
                si.icon,
                si.sort_order
            FROM sidebar_items si
            INNER JOIN role_sidebar_permissions rsp
                ON rsp.sidebar_item_id = si.id
               AND rsp.role_id = ?
               AND rsp.can_show = 1
            WHERE si.is_active = 1
            ORDER BY
                COALESCE(si.parent_id, si.id),
                CASE WHEN si.parent_id IS NULL THEN 0 ELSE 1 END,
                si.sort_order,
                si.id
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $sidebarMenus[] = $row;
        }

        $stmt->close();
    }
} catch (Throwable $e) {
    $sidebarMenus = [];
} catch (Throwable $e) {
    $sidebarMenus = [];
}

if (!$sidebarMenus) {
    $sidebarMenus = [
        ['id'=>1, 'parent_id'=>null, 'menu_key'=>'dashboard', 'menu_title'=>'Dashboard', 'page_title'=>'Dashboard', 'menu_url'=>'dashboard.php', 'icon'=>'layout-dashboard', 'sort_order'=>1],
        ['id'=>2, 'parent_id'=>null, 'menu_key'=>'enquiries', 'menu_title'=>'Enquiries', 'page_title'=>'Enquiries', 'menu_url'=>'enquiries.php', 'icon'=>'phone', 'sort_order'=>2],
        ['id'=>3, 'parent_id'=>null, 'menu_key'=>'quotations', 'menu_title'=>'Quotations', 'page_title'=>'Quotations', 'menu_url'=>'quotations.php', 'icon'=>'file-text', 'sort_order'=>3],
        ['id'=>4, 'parent_id'=>null, 'menu_key'=>'job_cards', 'menu_title'=>'Job Cards', 'page_title'=>'Job Cards', 'menu_url'=>'job-cards.php', 'icon'=>'briefcase', 'sort_order'=>4],
        ['id'=>5, 'parent_id'=>null, 'menu_key'=>'master_controls', 'menu_title'=>'Master Controls', 'page_title'=>'Master Controls', 'menu_url'=>'#', 'icon'=>'settings', 'sort_order'=>50],
        ['id'=>6, 'parent_id'=>5, 'menu_key'=>'users', 'menu_title'=>'Users', 'page_title'=>'Users', 'menu_url'=>'users.php', 'icon'=>'users', 'sort_order'=>1],
        ['id'=>7, 'parent_id'=>5, 'menu_key'=>'roles_permissions', 'menu_title'=>'Roles & Permissions', 'page_title'=>'Roles & Permissions', 'menu_url'=>'roles-permissions.php', 'icon'=>'shield-check', 'sort_order'=>2],
        ['id'=>8, 'parent_id'=>5, 'menu_key'=>'website_colors', 'menu_title'=>'Website Colors', 'page_title'=>'Website Colors', 'menu_url'=>'website-colors.php', 'icon'=>'palette', 'sort_order'=>3],
        ['id'=>9, 'parent_id'=>5, 'menu_key'=>'system_config', 'menu_title'=>'System Configuration', 'page_title'=>'System Configuration', 'menu_url'=>'system-config.php', 'icon'=>'sliders-horizontal', 'sort_order'=>4],
    ];
}

$mainMenus = [];
$subMenus = [];

foreach ($sidebarMenus as $menu) {
    if (empty($menu['parent_id'])) {
        $mainMenus[(int)$menu['id']] = $menu;
        $mainMenus[(int)$menu['id']]['children'] = [];
    } else {
        $subMenus[(int)$menu['parent_id']][] = $menu;
    }
}

foreach ($subMenus as $parentId => $children) {
    if (isset($mainMenus[$parentId])) {
        $mainMenus[$parentId]['children'] = $children;
    }
}

$logoPath = 'assets/img/subhiksha-logo.png';
$logoFullPath = __DIR__ . '/../' . $logoPath;
?>

<aside id="sidebar">
    <div class="sidebar-header">
        <div class="d-flex align-items-center gap-2 w-100">
            <div class="brand-logo">
                <?php if (is_file($logoFullPath)): ?>
                <img src="<?= e($logoPath) ?>" alt="Subhiksha Cards Logo">
                <?php else: ?>
                SC
                <?php endif; ?>
            </div>

            <div class="sidebar-brand-text">
                <span>SUBHIKSHA</span><span class="brand-accent-text"> CARDS</span>
            </div>

            <button id="closeMobileSidebar" class="icon-btn ms-auto d-xl-none border-0" type="button"
                aria-label="Close sidebar">
                <i data-lucide="x"></i>
            </button>
        </div>
    </div>

    <nav class="sidebar-nav thin-scrollbar">
        <?php foreach ($mainMenus as $main): ?>
        <?php
            $children = $main['children'] ?? [];
            $hasChildren = count($children) > 0;
            $isMainActive = sidebar_active((string)($main['menu_url'] ?? ''), $currentPage);
            $hasActiveChild = sidebar_child_active($children, $currentPage);
            $collapseId = 'menu_' . (int)$main['id'];
            $icon = sidebar_icon($main['icon'] ?? 'circle');
            $mainUrl = trim((string)($main['menu_url'] ?? ''));
            $mainTitle = trim((string)($main['menu_title'] ?? 'Menu'));
            ?>

        <?php if ($hasChildren): ?>
        <a href="#<?= e($collapseId) ?>"
            class="nav-link-custom sidebar-collapse-link <?= $hasActiveChild ? 'active' : '' ?>"
            data-bs-toggle="collapse" role="button" aria-expanded="<?= $hasActiveChild ? 'true' : 'false' ?>"
            aria-controls="<?= e($collapseId) ?>" title="<?= e($mainTitle) ?>" data-flyout-title="<?= e($mainTitle) ?>">
            <i data-lucide="<?= e($icon) ?>"></i>
            <span class="sidebar-text"><?= e($mainTitle) ?></span>
            <i data-lucide="chevron-down" class="ms-auto sidebar-sub-arrow"></i>
        </a>

        <div class="collapse sidebar-submenu <?= $hasActiveChild ? 'show' : '' ?>" id="<?= e($collapseId) ?>"
            data-flyout-title="<?= e($mainTitle) ?>">
            <div class="sidebar-flyout-title"><?= e($mainTitle) ?></div>

            <?php foreach ($children as $child): ?>
            <?php
                        $childUrl = trim((string)($child['menu_url'] ?? '#'));
                        $childUrl = $childUrl !== '' ? $childUrl : '#';
                        $childActive = sidebar_active($childUrl, $currentPage);
                        $childIcon = sidebar_icon($child['icon'] ?? 'circle');
                        ?>
            <a href="<?= e($childUrl) ?>" class="nav-link-custom sidebar-sub-link <?= $childActive ? 'active' : '' ?>"
                title="<?= e($child['menu_title'] ?? 'Sub Menu') ?>">
                <i data-lucide="<?= e($childIcon) ?>"></i>
                <span class="sidebar-text"><?= e($child['menu_title'] ?? 'Sub Menu') ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <?php $href = $mainUrl !== '' ? $mainUrl : '#'; ?>
        <a href="<?= e($href) ?>" class="nav-link-custom <?= $isMainActive ? 'active' : '' ?>"
            title="<?= e($mainTitle) ?>" data-flyout-title="<?= e($mainTitle) ?>">
            <i data-lucide="<?= e($icon) ?>"></i>
            <span class="sidebar-text"><?= e($mainTitle) ?></span>
        </a>
        <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="d-flex align-items-center gap-2">
            <div class="support-icon"><i data-lucide="headphones"></i></div>
            <div class="sidebar-help-text">
                <p class="fw-bold small mb-0">Logged in as</p>
                <p class="mb-0" style="font-size:11px;opacity:.7"><?= e($_SESSION['role_name'] ?? 'User') ?></p>
            </div>
        </div>
    </div>
</aside>