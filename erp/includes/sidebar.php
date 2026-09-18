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
$sidebarLoadedFromDb = false;

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

if (!function_exists('sidebar_table_has_column')) {
    function sidebar_table_has_column(mysqli $conn, string $table, string $column): bool
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
        $hasShowInSidebar = sidebar_table_has_column($conn, 'sidebar_items', 'show_in_sidebar');
        $sidebarVisibilitySql = $hasShowInSidebar ? " AND si.show_in_sidebar = 1" : "";

        /*
         | Strict role-based sidebar.
         | No Admin bypass: Admin also follows role_sidebar_permissions.can_show.
         | If show_in_sidebar exists, hidden items are excluded here.
         |
         | Sorting:
         | - Main menus follow sidebar_items.sort_order.
         | - Submenus remain grouped under their parent.
         | - Submenus follow their own sort_order.
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
            LEFT JOIN sidebar_items parent_si
                ON parent_si.id = si.parent_id
            WHERE si.is_active = 1{$sidebarVisibilitySql}
            ORDER BY
                COALESCE(parent_si.sort_order, si.sort_order) ASC,
                CASE WHEN si.parent_id IS NULL THEN 0 ELSE 1 END ASC,
                CASE WHEN si.parent_id IS NULL THEN si.sort_order ELSE si.sort_order END ASC,
                si.id ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $sidebarMenus[] = $row;
        }

        $sidebarLoadedFromDb = true;
        $stmt->close();
    }
} catch (Throwable $e) {
    $sidebarMenus = [];
    $sidebarLoadedFromDb = false;
}

if (!$sidebarLoadedFromDb && !$sidebarMenus) {
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
<style id="compact-sidebar-size-overrides">
/*
 * Compact desktop sidebar.
 * Expanded width  : 232px
 * Collapsed width : 72px
 *
 * IMPORTANT:
 * The old code forced #sidebar and #main to 232px even when
 * body.sidebar-collapsed was active. That is why only the icons
 * disappeared/appeared while the dark sidebar area stayed wide.
 */
:root {
    --erp-sidebar-width: 232px;
    --sidebar-width: 232px;
    --erp-sidebar-collapsed-width: 72px;
}

@media (min-width:1200px) {
    #sidebar {
        width: 232px !important;
        min-width: 232px !important;
        max-width: 232px !important;
        transition: width .22s ease, min-width .22s ease, max-width .22s ease !important;
    }

    #main {
        margin-left: 232px !important;
        width: calc(100% - 232px) !important;
        max-width: calc(100% - 232px) !important;
        transition: margin-left .22s ease, width .22s ease, max-width .22s ease !important;
    }

    /* Real desktop collapsed state */
    body.sidebar-collapsed {
        --erp-sidebar-width: 72px;
        --sidebar-width: 72px;
    }

    body.sidebar-collapsed #sidebar {
        width: 72px !important;
        min-width: 72px !important;
        max-width: 72px !important;
    }

    body.sidebar-collapsed #main {
        margin-left: 72px !important;
        width: calc(100% - 72px) !important;
        max-width: calc(100% - 72px) !important;
    }

    /* Header / logo */
    body.sidebar-collapsed #sidebar .sidebar-header {
        padding-left: 8px !important;
        padding-right: 8px !important;
    }

    body.sidebar-collapsed #sidebar .sidebar-header>.d-flex {
        justify-content: center !important;
        gap: 0 !important;
    }

    body.sidebar-collapsed #sidebar .sidebar-brand-text {
        display: none !important;
    }

    /* Main menu icons */
    body.sidebar-collapsed #sidebar .sidebar-nav {
        padding-left: 8px !important;
        padding-right: 8px !important;
    }

    body.sidebar-collapsed #sidebar .nav-link-custom {
        width: 48px !important;
        min-width: 48px !important;
        max-width: 48px !important;
        min-height: 46px !important;
        margin-left: auto !important;
        margin-right: auto !important;
        padding: 0 !important;
        justify-content: center !important;
        gap: 0 !important;
    }

    body.sidebar-collapsed #sidebar .nav-link-custom>svg,
    body.sidebar-collapsed #sidebar .nav-link-custom>i>svg {
        width: 19px !important;
        height: 19px !important;
        min-width: 19px !important;
        margin: 0 !important;
    }

    body.sidebar-collapsed #sidebar .sidebar-text,
    body.sidebar-collapsed #sidebar .sidebar-sub-arrow {
        display: none !important;
    }

    /*
     * Bootstrap can leave a submenu with .show from expanded mode.
     * Hide it inside the narrow sidebar; the JS flyout adds
     * .sidebar-flyout-open when the user clicks the parent icon.
     */
    body.sidebar-collapsed #sidebar .sidebar-submenu:not(.sidebar-flyout-open) {
        display: none !important;
    }

    body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open {
        display: block !important;
    }

    /* Footer */
    body.sidebar-collapsed #sidebar .sidebar-footer {
        padding-left: 8px !important;
        padding-right: 8px !important;
    }

    body.sidebar-collapsed #sidebar .sidebar-footer>.d-flex {
        justify-content: center !important;
        gap: 0 !important;
    }

    body.sidebar-collapsed #sidebar .sidebar-help-text {
        display: none !important;
    }
}

#sidebar .sidebar-header {
    min-height: 62px !important;
    padding: 9px 12px !important;
}

#sidebar .brand-logo {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    border-radius: 10px !important;
    overflow: hidden;
}

#sidebar .brand-logo img {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain !important;
}

#sidebar .sidebar-brand-text {
    font-size: 14px !important;
    line-height: 1.1 !important;
    font-weight: 750 !important;
    letter-spacing: .01em !important;
    white-space: nowrap;
}

#sidebar .sidebar-nav {
    padding: 8px 9px 68px !important;
}

#sidebar .nav-link-custom {
    min-height: 38px !important;
    margin: 2px 0 !important;
    padding: 7px 10px !important;
    border-radius: 10px !important;
    gap: 9px !important;
    font-size: 12.5px !important;
    line-height: 1.15 !important;
    font-weight: 650 !important;
}

#sidebar .nav-link-custom>svg,
#sidebar .nav-link-custom>i>svg {
    width: 17px !important;
    height: 17px !important;
    min-width: 17px !important;
    stroke-width: 1.9 !important;
}

#sidebar .sidebar-sub-arrow {
    width: 14px !important;
    height: 14px !important;
}

#sidebar .sidebar-submenu {
    padding: 2px 0 3px !important;
}

#sidebar .sidebar-sub-link {
    min-height: 34px !important;
    padding-top: 6px !important;
    padding-bottom: 6px !important;
    padding-left: 27px !important;
    font-size: 11.5px !important;
}

#sidebar .sidebar-flyout-title {
    font-size: 10px !important;
    font-weight: 700 !important;
    padding: 6px 10px !important;
}

#sidebar .sidebar-footer {
    min-height: 52px !important;
    padding: 8px 12px !important;
}

#sidebar .support-icon {
    width: 32px !important;
    height: 32px !important;
    min-width: 32px !important;
    border-radius: 10px !important;
}

#sidebar .support-icon svg {
    width: 16px !important;
    height: 16px !important;
}

#sidebar .sidebar-help-text .small,
#sidebar .sidebar-help-text p {
    font-size: 10.5px !important;
    line-height: 1.2 !important;
    font-weight: 600 !important;
}

@media (max-width:1199.98px) {
    #sidebar {
        width: min(84vw, 250px) !important;
        max-width: 250px !important;
    }

    /* Desktop collapsed state must never affect the mobile drawer. */
    body.sidebar-collapsed #sidebar {
        width: min(84vw, 250px) !important;
        max-width: 250px !important;
    }
}


/* ============================================================
 * FIX: desktop collapse toggle + compact submenu flyout
 * ============================================================ */
@media (min-width:1200px) {

    html,
    body {
        overflow-x: hidden;
    }

    #sidebar,
    #main {
        box-sizing: border-box !important;
    }

    #sidebar {
        padding-left: 0 !important;
        padding-right: 0 !important;
        overflow: visible !important;
    }

    #sidebar .sidebar-nav {
        overflow-x: visible !important;
    }

    /* The title is only needed when a submenu is used as a flyout. */
    #sidebar .sidebar-flyout-title {
        display: none !important;
    }

    /* Never let an expanded Bootstrap submenu become a large panel
       inside the collapsed 72px sidebar. */
    body.sidebar-collapsed #sidebar .sidebar-submenu {
        position: fixed !important;
        left: 82px;
        top: 80px;
        width: 265px !important;
        min-width: 265px !important;
        max-width: min(265px, calc(100vw - 100px)) !important;
        max-height: calc(100vh - 24px) !important;
        margin: 0 !important;
        padding: 8px !important;
        overflow-x: hidden !important;
        overflow-y: auto !important;
        border: 1px solid rgba(255, 255, 255, .10) !important;
        border-radius: 14px !important;
        background: #0d1b2d !important;
        box-shadow: 0 18px 45px rgba(0, 0, 0, .28) !important;
        z-index: 10050 !important;
    }

    body.sidebar-collapsed #sidebar .sidebar-submenu:not(.sidebar-flyout-open) {
        display: none !important;
    }

    body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open {
        display: block !important;
        height: auto !important;
        visibility: visible !important;
    }

    body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .sidebar-flyout-title {
        display: block !important;
        padding: 8px 10px 10px !important;
        margin: 0 0 5px !important;
        border-bottom: 1px solid rgba(255, 255, 255, .10) !important;
        color: #fff !important;
        font-size: 13px !important;
        line-height: 1.2 !important;
        font-weight: 800 !important;
        letter-spacing: .01em !important;
        white-space: normal !important;
    }

    /* Restore normal link dimensions/text INSIDE the flyout. The old
       collapsed rule applied 48px width to every nav-link, including
       submenu links, which caused the large empty Products Master box. */
    body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom.sidebar-sub-link {
        display: flex !important;
        width: 100% !important;
        min-width: 0 !important;
        max-width: none !important;
        min-height: 38px !important;
        margin: 2px 0 !important;
        padding: 8px 10px !important;
        justify-content: flex-start !important;
        align-items: center !important;
        gap: 10px !important;
        border-radius: 9px !important;
        font-size: 12px !important;
        line-height: 1.25 !important;
        text-decoration: none !important;
        white-space: normal !important;
    }

    body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom.sidebar-sub-link>svg,
    body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom.sidebar-sub-link>i>svg {
        width: 17px !important;
        height: 17px !important;
        min-width: 17px !important;
        margin: 0 !important;
        flex: 0 0 17px !important;
    }

    body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .sidebar-text {
        display: inline-block !important;
        width: auto !important;
        opacity: 1 !important;
        visibility: visible !important;
        color: inherit !important;
        overflow: visible !important;
        white-space: normal !important;
    }

    /* Small pointer toward the selected sidebar icon. */
    body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open::before {
        content: '';
        position: absolute;
        left: -7px;
        top: var(--flyout-arrow-top, 18px);
        width: 14px;
        height: 14px;
        background: #0d1b2d;
        border-left: 1px solid rgba(255, 255, 255, .10);
        border-bottom: 1px solid rgba(255, 255, 255, .10);
        transform: rotate(45deg);
    }

    /* Keep the selected parent icon highlighted while its flyout is open. */
    body.sidebar-collapsed #sidebar .sidebar-collapse-link.sidebar-parent-open {
        background: linear-gradient(135deg, #ff4b36, #ff9f38) !important;
        color: #fff !important;
    }
}
</style>

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

<script>
(function() {
    'use strict';

    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    const desktopMq = window.matchMedia('(min-width: 1200px)');

    function isDesktopCollapsed() {
        return desktopMq.matches && document.body.classList.contains('sidebar-collapsed');
    }

    function closeSidebarFlyouts(exceptMenu) {
        sidebar.querySelectorAll('.sidebar-submenu.sidebar-flyout-open').forEach(function(menu) {
            if (exceptMenu && menu === exceptMenu) return;
            menu.classList.remove('sidebar-flyout-open');
            menu.style.removeProperty('top');
            menu.style.removeProperty('left');
            menu.style.removeProperty('--flyout-arrow-top');
        });

        sidebar.querySelectorAll('.sidebar-collapse-link.sidebar-parent-open').forEach(function(link) {
            if (exceptMenu && link.getAttribute('aria-controls') === exceptMenu.id) return;
            link.classList.remove('sidebar-parent-open');
            if (isDesktopCollapsed()) {
                link.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function positionFlyout(parentLink, menu) {
        const parentRect = parentLink.getBoundingClientRect();

        // First make it measurable.
        menu.classList.add('sidebar-flyout-open');

        const menuRect = menu.getBoundingClientRect();
        const gap = 10;
        const safe = 12;

        let left = parentRect.right + gap;
        let top = parentRect.top - 7;

        if (left + menuRect.width > window.innerWidth - safe) {
            left = Math.max(safe, window.innerWidth - menuRect.width - safe);
        }

        if (top + menuRect.height > window.innerHeight - safe) {
            top = Math.max(safe, window.innerHeight - menuRect.height - safe);
        }

        if (top < safe) top = safe;

        const arrowTop = Math.max(
            12,
            Math.min(menuRect.height - 26, (parentRect.top + parentRect.height / 2) - top - 7)
        );

        menu.style.left = Math.round(left) + 'px';
        menu.style.top = Math.round(top) + 'px';
        menu.style.setProperty('--flyout-arrow-top', Math.round(arrowTop) + 'px');
    }

    sidebar.querySelectorAll('.sidebar-collapse-link').forEach(function(parentLink) {
        parentLink.addEventListener('click', function(event) {
            if (!isDesktopCollapsed()) {
                closeSidebarFlyouts();
                return; // Bootstrap collapse works normally while expanded.
            }

            // In compact mode use our flyout instead of Bootstrap's inline collapse.
            event.preventDefault();
            event.stopPropagation();

            const menuId = parentLink.getAttribute('aria-controls');
            if (!menuId) return;

            const menu = document.getElementById(menuId);
            if (!menu) return;

            const wasOpen = menu.classList.contains('sidebar-flyout-open');
            closeSidebarFlyouts(menu);

            if (wasOpen) {
                menu.classList.remove('sidebar-flyout-open');
                parentLink.classList.remove('sidebar-parent-open');
                parentLink.setAttribute('aria-expanded', 'false');
                return;
            }

            parentLink.classList.add('sidebar-parent-open');
            parentLink.setAttribute('aria-expanded', 'true');
            positionFlyout(parentLink, menu);
        }, true);
    });

    // Close when clicking anywhere outside the currently opened parent/flyout.
    document.addEventListener('click', function(event) {
        if (!isDesktopCollapsed()) return;
        if (event.target.closest('#sidebar .sidebar-submenu.sidebar-flyout-open')) return;
        if (event.target.closest('#sidebar .sidebar-collapse-link')) return;
        closeSidebarFlyouts();
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') closeSidebarFlyouts();
    });

    window.addEventListener('resize', function() {
        closeSidebarFlyouts();
    });

    // If the existing topbar hamburger adds/removes body.sidebar-collapsed,
    // clean up any popup left from the previous state immediately.
    const bodyObserver = new MutationObserver(function(mutations) {
        for (const mutation of mutations) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                closeSidebarFlyouts();
                break;
            }
        }
    });

    bodyObserver.observe(document.body, {
        attributes: true
    });
})();
</script>
