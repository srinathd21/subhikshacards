<?php
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'activity_logs.php');
$module = trim((string)($_GET['module'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$where=[]; $types=''; $params=[];
if($module!==''){ $where[]='al.module_name LIKE ?'; $types.='s'; $params[]='%'.$module.'%'; }
if($from!==''){ $where[]='DATE(al.created_at) >= ?'; $types.='s'; $params[]=$from; }
if($to!==''){ $where[]='DATE(al.created_at) <= ?'; $types.='s'; $params[]=$to; }
$sql="SELECT al.*,u.name AS user_name,r.role_name FROM activity_logs al LEFT JOIN users u ON u.id=al.user_id LEFT JOIN roles r ON r.id=al.role_id".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY al.id DESC LIMIT 300";
$stmt=$conn->prepare($sql); if($params) $stmt->bind_param($types,...$params); $stmt->execute(); $logs=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Activity Logs - Subhiksha Cards</title>
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
<div class="card-ui page-head"><div class="d-flex flex-column flex-lg-row justify-content-between gap-3"><div><h1 class="mb-1">Activity Logs</h1><p class="text-muted-custom mb-0">System audit history and user activities.</p></div><a href="masters.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Master Control</a></div></div>
<div class="card-ui master-card mb-3"><form method="get" class="row g-3"><div class="col-md-4"><input name="module" class="form-control" placeholder="Module" value="<?=e($module)?>"></div><div class="col-md-3"><input name="from" type="date" class="form-control" value="<?=e($from)?>"></div><div class="col-md-3"><input name="to" type="date" class="form-control" value="<?=e($to)?>"></div><div class="col-md-2"><button class="btn btn-primary w-100 rounded-pill fw-bold">Filter</button></div></form></div>
<div class="card-ui master-card"><h2 class="master-title">Latest Logs</h2><div class="table-responsive"><table class="table-ui"><thead><tr><th>Date</th><th>User</th><th>Action</th><th>Module</th><th>Description</th><th>IP</th></tr></thead><tbody><?php foreach($logs as $l): ?><tr><td><?=e(date('d-m-Y h:i A',strtotime($l['created_at'])))?></td><td><strong><?=e($l['user_name'] ?? 'System')?></strong><span class="small-muted"><?=e($l['role_name'] ?? '')?></span></td><td><?=e($l['action_key'])?></td><td><?=e($l['module_name'])?></td><td><?=e($l['description'] ?? '-')?></td><td><?=e($l['ip_address'] ?? '-')?></td></tr><?php endforeach; ?></tbody></table></div></div>
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

