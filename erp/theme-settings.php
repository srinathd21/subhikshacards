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

<style id="compact-ui-overrides">
/* Compact 100% zoom UI override - visual sizing only */
.master-page .page-head{padding:16px 18px !important;margin-bottom:12px !important;border-radius:16px !important;}
.master-page .page-head h1{font-size:24px !important;line-height:1.2 !important;font-weight:800 !important;}
.master-page .page-head p{font-size:12px !important;font-weight:500 !important;}
.master-page .master-card{padding:16px !important;border-radius:16px !important;}
.master-page .master-title{font-size:15px !important;font-weight:750 !important;margin-bottom:12px !important;}
.master-page .master-stat-card{min-height:auto !important;padding:12px 14px !important;gap:10px !important;}
.master-page .master-stat-icon{width:38px !important;height:38px !important;border-radius:12px !important;}
.master-page .master-stat-icon svg{width:19px !important;height:19px !important;}
.master-page .master-stat-card span{font-size:10px !important;font-weight:700 !important;}
.master-page .master-stat-card strong{font-size:18px !important;font-weight:800 !important;}
.master-page .form-label{font-size:12px !important;font-weight:700 !important;margin-bottom:5px !important;}
.master-page .form-control,.master-page .form-select{min-height:38px !important;font-size:13px !important;border-radius:10px !important;padding:7px 10px !important;}
.master-page textarea.form-control{min-height:72px !important;}
.master-page .btn{font-size:12px !important;font-weight:700 !important;padding:7px 12px !important;}
.master-page .btn-sm{font-size:11px !important;padding:5px 9px !important;}
.master-page .table-ui th,.master-page .table-ui td{font-size:12px !important;padding:8px 9px !important;}
.master-page .table-ui th{font-size:10.5px !important;font-weight:750 !important;}
.master-page .status-pill{font-size:10px !important;font-weight:700 !important;padding:4px 8px !important;}
.master-page .small-muted{font-size:10px !important;font-weight:500 !important;}
.master-page .modal-title{font-size:16px !important;font-weight:800 !important;}
.master-page .modal-header{padding:14px 16px !important;}
.master-page .modal-body{padding:16px !important;}
.master-page .modal-footer{padding:12px 16px !important;}
.master-page .modal-content{border-radius:16px !important;}
@media(max-width:767.98px){.master-page .page-head{padding:14px !important}.master-page .page-head h1{font-size:21px !important}.master-page .master-card{padding:13px !important;}}
</style><!-- compact-ui-overrides -->
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

