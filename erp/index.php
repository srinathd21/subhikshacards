<?php
require_once __DIR__ . '/includes/db.php';

requireLogin();

$userId   = (int)($_SESSION['user_id'] ?? 0);
$roleId   = (int)($_SESSION['role_id'] ?? 0);
$name     = $_SESSION['name'] ?? 'User';
$roleName = $_SESSION['role_name'] ?? 'User';
$roleKey  = $_SESSION['role_key'] ?? '';

function countTable(PDO $pdo, string $table): int
{
    try {
        $stmt = $pdo->query("SELECT COUNT(*) AS total FROM `$table`");
        $row = $stmt->fetch();
        return (int)($row['total'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function countQuery(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int)($row['total'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

$totalEnquiries = countTable($pdo, 'enquiries');
$todayEnquiries = countQuery($pdo, "
    SELECT COUNT(*) AS total 
    FROM enquiries 
    WHERE DATE(created_at) = CURDATE()
");

$totalQuotations = countTable($pdo, 'quotations');
$totalProforma   = countTable($pdo, 'proforma_bills');
$totalJobCards   = countTable($pdo, 'job_cards');

$delayedJobs = countQuery($pdo, "
    SELECT COUNT(*) AS total 
    FROM job_cards 
    WHERE is_delayed = 1
");

$activeJobCards = countQuery($pdo, "
    SELECT COUNT(*) AS total 
    FROM job_cards jc
    LEFT JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
    WHERE jcs.status_key IS NULL 
       OR jcs.status_key NOT IN ('completed', 'cancelled')
");

$completedJobs = countQuery($pdo, "
    SELECT COUNT(*) AS total 
    FROM job_cards jc
    INNER JOIN job_card_statuses jcs ON jcs.id = jc.job_card_status_id
    WHERE jcs.status_key = 'completed'
");

$recentActivities = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            al.module_name,
            al.action_key,
            al.description,
            al.created_at,
            u.name AS user_name
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.user_id
        ORDER BY al.id DESC
        LIMIT 8
    ");
    $stmt->execute();
    $recentActivities = $stmt->fetchAll();
} catch (Throwable $e) {
    $recentActivities = [];
}

$menus = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            si.id,
            si.parent_id,
            si.menu_title,
            si.route,
            si.icon,
            si.sort_order
        FROM sidebar_items si
        INNER JOIN role_sidebar_permissions rsp 
            ON rsp.sidebar_item_id = si.id
        WHERE rsp.role_id = :role_id
          AND rsp.can_show = 1
          AND si.is_active = 1
        ORDER BY si.sort_order ASC, si.id ASC
    ");
    $stmt->execute([
        ':role_id' => $roleId
    ]);
    $menus = $stmt->fetchAll();
} catch (Throwable $e) {
    $menus = [];
}

$parentMenus = [];
$childMenus = [];

foreach ($menus as $menu) {
    if (empty($menu['parent_id'])) {
        $parentMenus[] = $menu;
    } else {
        $childMenus[(int)$menu['parent_id']][] = $menu;
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Dashboard | Subhiksha Card ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

    <style>
    :root {
        --sidebar: #111827;
        --sidebar-light: #1f2937;
        --primary: #6d28d9;
        --secondary: #ec4899;
        --bg: #f8fafc;
        --card-border: #e5e7eb;
        --text: #111827;
        --muted: #6b7280;
    }

    body {
        background: var(--bg);
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        color: var(--text);
    }

    .app {
        display: flex;
        min-height: 100vh;
    }

    .sidebar {
        width: 270px;
        background: linear-gradient(180deg, #111827, #1f2937);
        color: #fff;
        position: fixed;
        inset: 0 auto 0 0;
        overflow-y: auto;
        z-index: 1000;
    }

    .brand {
        padding: 22px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, .10);
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .brand-icon {
        width: 46px;
        height: 46px;
        border-radius: 15px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        display: grid;
        place-items: center;
        font-size: 20px;
    }

    .brand-title {
        font-weight: 800;
        line-height: 1.1;
    }

    .brand-sub {
        font-size: 12px;
        opacity: .7;
    }

    .menu {
        padding: 14px 12px;
    }

    .menu a {
        color: rgba(255, 255, 255, .82);
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 11px 12px;
        border-radius: 13px;
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .menu a:hover,
    .menu a.active {
        background: rgba(255, 255, 255, .10);
        color: #fff;
    }

    .menu .submenu {
        margin-left: 16px;
        padding-left: 10px;
        border-left: 1px solid rgba(255, 255, 255, .12);
        margin-bottom: 8px;
    }

    .menu .submenu a {
        font-size: 13px;
        padding: 9px 10px;
        opacity: .9;
    }

    .main {
        margin-left: 270px;
        width: calc(100% - 270px);
        min-height: 100vh;
    }

    .topbar {
        height: 72px;
        background: #fff;
        border-bottom: 1px solid var(--card-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 26px;
        position: sticky;
        top: 0;
        z-index: 900;
    }

    .page-content {
        padding: 26px;
    }

    .page-title {
        font-size: 25px;
        font-weight: 800;
        margin-bottom: 4px;
    }

    .page-subtitle {
        color: var(--muted);
        font-size: 14px;
    }

    .user-pill {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #f9fafb;
        border: 1px solid var(--card-border);
        padding: 8px 12px;
        border-radius: 16px;
    }

    .user-avatar {
        width: 36px;
        height: 36px;
        border-radius: 13px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: #fff;
        display: grid;
        place-items: center;
        font-weight: 800;
    }

    .stat-card {
        background: #fff;
        border: 1px solid var(--card-border);
        border-radius: 20px;
        padding: 20px;
        height: 100%;
        box-shadow: 0 10px 30px rgba(15, 23, 42, .04);
    }

    .stat-icon {
        width: 46px;
        height: 46px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        background: #f5f3ff;
        color: var(--primary);
        font-size: 20px;
        margin-bottom: 14px;
    }

    .stat-value {
        font-size: 28px;
        font-weight: 800;
        margin-bottom: 2px;
    }

    .stat-label {
        color: var(--muted);
        font-size: 14px;
        font-weight: 600;
    }

    .section-card {
        background: #fff;
        border: 1px solid var(--card-border);
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, .04);
    }

    .section-card .card-header {
        background: transparent;
        border-bottom: 1px solid var(--card-border);
        padding: 17px 20px;
        font-weight: 800;
    }

    .activity-item {
        padding: 14px 20px;
        border-bottom: 1px solid #f1f5f9;
    }

    .activity-item:last-child {
        border-bottom: 0;
    }

    .activity-title {
        font-weight: 700;
        font-size: 14px;
    }

    .activity-meta {
        color: var(--muted);
        font-size: 12px;
        margin-top: 3px;
    }

    .quick-btn {
        display: block;
        text-decoration: none;
        color: var(--text);
        border: 1px solid var(--card-border);
        border-radius: 16px;
        padding: 16px;
        background: #fff;
        font-weight: 700;
        transition: .15s;
    }

    .quick-btn:hover {
        transform: translateY(-2px);
        color: var(--primary);
        box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
    }

    .mobile-toggle {
        display: none;
    }

    @media (max-width: 991px) {
        .sidebar {
            transform: translateX(-100%);
            transition: .2s;
        }

        .sidebar.show {
            transform: translateX(0);
        }

        .main {
            margin-left: 0;
            width: 100%;
        }

        .mobile-toggle {
            display: inline-flex;
        }

        .page-content {
            padding: 18px;
        }
    }
    </style>
</head>

<body>

    <div class="app">

        <aside class="sidebar" id="sidebar">
            <div class="brand">
                <div class="brand-icon">
                    <i class="fa-solid fa-id-card"></i>
                </div>
                <div>
                    <div class="brand-title">Subhiksha Cards</div>
                    <div class="brand-sub">ERP Dashboard</div>
                </div>
            </div>

            <nav class="menu">
                <a href="index.php" class="active">
                    <i class="fa-solid fa-gauge-high"></i>
                    Dashboard
                </a>

                <?php if (!empty($parentMenus)): ?>
                <?php foreach ($parentMenus as $parent): ?>
                <?php
                    $parentId = (int)$parent['id'];
                    $hasChildren = !empty($childMenus[$parentId]);
                    $route = $parent['route'] ?: '#';
                    ?>
                <a href="<?= e($hasChildren ? '#' : $route) ?>">
                    <i class="<?= e($parent['icon'] ?: 'fa-solid fa-circle') ?>"></i>
                    <?= e($parent['menu_title']) ?>
                </a>

                <?php if ($hasChildren): ?>
                <div class="submenu">
                    <?php foreach ($childMenus[$parentId] as $child): ?>
                    <a href="<?= e($child['route'] ?: '#') ?>">
                        <i class="<?= e($child['icon'] ?: 'fa-regular fa-circle') ?>"></i>
                        <?= e($child['menu_title']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php endif; ?>

                <a href="logout.php">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    Logout
                </a>
            </nav>
        </aside>

        <main class="main">
            <div class="topbar">
                <div class="d-flex align-items-center gap-3">
                    <button class="btn btn-light mobile-toggle" onclick="toggleSidebar()">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div>
                        <div class="page-title">Dashboard</div>
                        <div class="page-subtitle">Welcome back, <?= e($name) ?></div>
                    </div>
                </div>

                <div class="user-pill">
                    <div class="user-avatar">
                        <?= e(strtoupper(substr($name, 0, 1))) ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="fw-bold small"><?= e($name) ?></div>
                        <div class="text-muted small"><?= e($roleName) ?></div>
                    </div>
                </div>
            </div>

            <div class="page-content">

                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fa-solid fa-phone-volume"></i>
                            </div>
                            <div class="stat-value"><?= number_format($totalEnquiries) ?></div>
                            <div class="stat-label">Total Enquiries</div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fa-solid fa-calendar-day"></i>
                            </div>
                            <div class="stat-value"><?= number_format($todayEnquiries) ?></div>
                            <div class="stat-label">Today Enquiries</div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fa-solid fa-file-invoice"></i>
                            </div>
                            <div class="stat-value"><?= number_format($totalQuotations) ?></div>
                            <div class="stat-label">Quotations</div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <div class="stat-value"><?= number_format($totalProforma) ?></div>
                            <div class="stat-label">Proforma Bills</div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fa-solid fa-clipboard-list"></i>
                            </div>
                            <div class="stat-value"><?= number_format($totalJobCards) ?></div>
                            <div class="stat-label">Total Job Cards</div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fa-solid fa-spinner"></i>
                            </div>
                            <div class="stat-value"><?= number_format($activeJobCards) ?></div>
                            <div class="stat-label">Active Jobs</div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </div>
                            <div class="stat-value"><?= number_format($delayedJobs) ?></div>
                            <div class="stat-label">Delayed Jobs</div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                            <div class="stat-value"><?= number_format($completedJobs) ?></div>
                            <div class="stat-label">Completed Jobs</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-5">
                        <div class="section-card">
                            <div class="card-header">
                                Quick Actions
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <a class="quick-btn" href="enquiry-add.php">
                                            <i class="fa-solid fa-plus me-2"></i>
                                            New Enquiry
                                        </a>
                                    </div>
                                    <div class="col-12">
                                        <a class="quick-btn" href="quotations.php">
                                            <i class="fa-solid fa-file-invoice me-2"></i>
                                            Quotations
                                        </a>
                                    </div>
                                    <div class="col-12">
                                        <a class="quick-btn" href="proforma-bills.php">
                                            <i class="fa-solid fa-receipt me-2"></i>
                                            Proforma Bills
                                        </a>
                                    </div>
                                    <div class="col-12">
                                        <a class="quick-btn" href="job-cards.php">
                                            <i class="fa-solid fa-clipboard-list me-2"></i>
                                            Job Cards
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="section-card">
                            <div class="card-header">
                                Recent Activity
                            </div>

                            <?php if (!empty($recentActivities)): ?>
                            <?php foreach ($recentActivities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-title">
                                    <?= e($activity['description'] ?: ucfirst(str_replace('_', ' ', $activity['action_key']))) ?>
                                </div>
                                <div class="activity-meta">
                                    <?= e($activity['module_name']) ?>
                                    by <?= e($activity['user_name'] ?: 'System') ?>
                                    · <?= e(date('d-m-Y h:i A', strtotime($activity['created_at']))) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <div class="p-4 text-muted">
                                No recent activity found.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </main>

    </div>

    <script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('show');
    }
    </script>

</body>

</html>