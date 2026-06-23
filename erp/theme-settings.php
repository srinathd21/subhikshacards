<?php
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'theme-settings.php');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Theme Settings - Subhiksha Cards</title>
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

<section class="page-section master-page"><div class="card-ui page-head"><div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3"><div><h1 class="mb-1">Theme Settings</h1><p class="text-muted-custom mb-0">Theme settings are handled through Website Colors.</p></div><a href="website-colors.php" class="btn btn-primary rounded-pill px-4 fw-bold">Open Website Colors</a></div></div>
<div class="card-ui master-card"><h2 class="master-title">Theme Shortcut</h2><p class="text-muted-custom mb-0">Use Website Colors page to update sidebar gradient, brand color, topbar, cards, table, form and dark mode colors.</p></div></section>
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

