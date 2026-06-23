<?php
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'masters.php');

function mc_count_table(mysqli $conn, string $table): int {
    try {
        $table = $conn->real_escape_string($table);
        $check = $conn->query("SHOW TABLES LIKE '{${table}}'");
        if (!$check || $check->num_rows === 0) return 0;
        $res = $conn->query("SELECT COUNT(*) AS total FROM `{${table}}`");
        $row = $res->fetch_assoc();
        return (int)($row['total'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

$cards = [
    ['Users','users.php','users','users','Manage login users'],
    ['Website Colors','website-colors.php','palette','website_color_settings','Theme colors'],
    ['System Settings','settings.php','settings','system_settings','Application configuration'],
    ['Workflow Settings','workflow_settings.php','workflow','workflow_steps','Production workflow'],
    ['Activity Logs','activity_logs.php','history','activity_logs','Audit history'],
    ['System Config','system-config.php','sliders-horizontal','system_settings','Quick configuration'],
    ['Theme Settings','theme-settings.php','paintbrush','website_color_settings','Theme shortcut'],
];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Master Control - Subhiksha Cards</title>
<?php include __DIR__ . '/includes/links.php'; ?>
<?php include __DIR__ . '/includes/theme-loader.php'; ?>

<style>
.master-page .page-head{padding:24px 28px;margin-bottom:18px}
.master-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}
.master-stat-card{padding:18px;min-height:112px;display:flex;align-items:center;gap:14px}
.master-stat-icon{width:52px;height:52px;border-radius:16px;display:grid;place-items:center;color:#fff;flex:0 0 auto}
.master-stat-card span{display:block;font-size:12px;color:var(--text-muted);font-weight:900;text-transform:uppercase}
.master-stat-card strong{font-size:24px;font-weight:900;color:var(--text-main)}
.master-card{padding:24px}
.master-title{font-size:18px;font-weight:900;color:var(--text-main);margin-bottom:18px}
.status-pill{font-size:11px;font-weight:900;border-radius:999px;padding:5px 9px}
.status-pill.active{color:var(--success-color);background:color-mix(in srgb,var(--success-color) 14%,transparent)}
.status-pill.inactive{color:var(--danger-color);background:color-mix(in srgb,var(--danger-color) 14%,transparent)}
.form-control,.form-select{border-radius:14px;min-height:46px}
.modal-content{border:0;border-radius:22px;background:var(--card-bg);color:var(--text-main)}
.modal-header,.modal-footer{border-color:var(--border-soft)}
.small-muted{display:block;margin-top:3px;color:var(--text-muted);font-size:11px;font-weight:700}
@media(max-width:991px){.master-card{padding:18px}.master-page .page-head{padding:20px}}
</style>

</head>
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div>
<div class="app-shell">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<main id="main">
<?php include __DIR__ . '/includes/nav.php'; ?>

<section class="page-section master-page">
    <div class="card-ui page-head">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
            <div>
                <h1 class="mb-1">Master Control</h1>
                <p class="text-muted-custom mb-0">All admin control files except Sidebar Settings and Roles & Permissions.</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Dashboard</a>
        </div>
    </div>
    <div class="row g-3">
        <?php foreach ($cards as $card): ?>
            <div class="col-12 col-md-6 col-xl-4">
                <a href="<?= e($card[1]) ?>" class="text-decoration-none">
                    <div class="card-ui master-stat-card h-100">
                        <div class="master-stat-icon" style="background:linear-gradient(135deg,var(--brand-1),var(--brand-2))"><i data-lucide="<?= e($card[2]) ?>"></i></div>
                        <div>
                            <span><?= e($card[4]) ?></span>
                            <strong><?= e($card[0]) ?></strong>
                            <small class="small-muted"><?= number_format(mc_count_table($conn, $card[3])) ?> records</small>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
</main>
<div id="settingsOverlay"></div>
<?php include __DIR__ . '/includes/rightsidebar.php'; ?>
</div>
<?php include __DIR__ . '/includes/script.php'; ?>
<script>
if (window.lucide && typeof window.lucide.createIcons === 'function') { window.lucide.createIcons(); }
</script>
</body>
</html>

