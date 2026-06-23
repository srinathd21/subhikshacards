<?php
/**
 * dispatch.php
 * Subhiksha Cards ERP - Dispatch
 */

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'dispatch.php');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['dispatch_csrf'])) $_SESSION['dispatch_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['dispatch_csrf'];
$message = '';
$messageType = 'success';

function dp_table_exists(mysqli $conn, string $table): bool {
    try { $t=$conn->real_escape_string($table); $r=$conn->query("SHOW TABLES LIKE '{${t}}'"); return $r && $r->num_rows>0; } catch(Throwable $e) { return false; }
}
function dp_post(string $k,string $d=''): string { return trim((string)($_POST[$k]??$d)); }
function dp_int($v): int { return (int)filter_var($v,FILTER_SANITIZE_NUMBER_INT); }
function dp_redirect(string $q=''): void { header('Location: dispatch.php'.($q!==''?'?'.$q:'')); exit; }
function dp_csrf(): void { if(empty($_POST['csrf_token'])||!hash_equals($_SESSION['dispatch_csrf']??'',(string)$_POST['csrf_token']))die('Invalid CSRF token.'); }

if($_SERVER['REQUEST_METHOD']==='POST') {
    dp_csrf();
    try {
        $action=dp_post('action');
        if($action==='save_dispatch') {
            if(!dp_table_exists($conn,'dispatches')) throw new RuntimeException('dispatches table is missing. Run SQL first.');
            $id=dp_int($_POST['id']??0); $dispatchNo=dp_post('dispatch_no'); $jobNo=dp_post('job_no'); $customerName=dp_post('customer_name'); $mobile=dp_post('mobile'); $dispatchDate=dp_post('dispatch_date'); $deliveryMode=dp_post('delivery_mode','Direct Pickup'); $vehicleNo=dp_post('vehicle_no'); $remarks=dp_post('remarks'); $status=dp_post('status','Pending');
            if($dispatchNo==='') $dispatchNo='DIS-'.date('ymd').'-'.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);
            if($jobNo===''||$customerName==='') throw new RuntimeException('Job no and customer name are required.');
            if($id>0) { $stmt=$conn->prepare("UPDATE dispatches SET dispatch_no=?,job_no=?,customer_name=?,mobile=?,dispatch_date=?,delivery_mode=?,vehicle_no=?,remarks=?,status=?,updated_at=NOW() WHERE id=?"); $stmt->bind_param('sssssssssi',$dispatchNo,$jobNo,$customerName,$mobile,$dispatchDate,$deliveryMode,$vehicleNo,$remarks,$status,$id); $stmt->execute();$stmt->close();dp_redirect('msg=updated'); }
            $createdBy=(int)($_SESSION['user_id']??0); $stmt=$conn->prepare("INSERT INTO dispatches(dispatch_no,job_no,customer_name,mobile,dispatch_date,delivery_mode,vehicle_no,remarks,status,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW(),NOW())"); $stmt->bind_param('sssssssssi',$dispatchNo,$jobNo,$customerName,$mobile,$dispatchDate,$deliveryMode,$vehicleNo,$remarks,$status,$createdBy); $stmt->execute();$stmt->close();dp_redirect('msg=created');
        }
    } catch(Throwable $e) { $message=$e->getMessage(); $messageType='danger'; }
}
$msg=(string)($_GET['msg']??''); if($msg==='created')$message='Dispatch created successfully.'; elseif($msg==='updated')$message='Dispatch updated successfully.';
$rows=[]; if(dp_table_exists($conn,'dispatches')){$res=$conn->query("SELECT * FROM dispatches ORDER BY id DESC LIMIT 300"); while($row=$res->fetch_assoc())$rows[]=$row;}
$totalRows=count($rows); $pending=0; $done=0; foreach($rows as $row){ if(strtolower((string)$row['status'])==='pending')$pending++; if(strtolower((string)$row['status'])==='delivered')$done++; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dispatch - Subhiksha Cards</title><?php include __DIR__ . '/includes/links.php'; ?><?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
        .module-page .page-head{padding:24px 28px;margin-bottom:18px}
        .module-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}
        .module-card{padding:24px}
        .module-title{font-size:18px;font-weight:900;color:var(--text-main);margin:0}
        .stat-card{padding:18px;min-height:112px;display:flex;align-items:center;gap:14px}
        .stat-icon{width:52px;height:52px;border-radius:16px;display:grid;place-items:center;color:#fff;flex:0 0 auto}
        .stat-card span{display:block;font-size:12px;color:var(--text-muted);font-weight:900;text-transform:uppercase}
        .stat-card strong{font-size:24px;font-weight:900;color:var(--text-main)}
        .status-pill{font-size:11px;font-weight:900;border-radius:999px;padding:5px 9px}
        .status-pill.pending{color:var(--warning-color);background:color-mix(in srgb,var(--warning-color) 14%,transparent)}
        .status-pill.active{color:var(--info-color);background:color-mix(in srgb,var(--info-color) 14%,transparent)}
        .status-pill.completed{color:var(--success-color);background:color-mix(in srgb,var(--success-color) 14%,transparent)}
        .status-pill.inactive{color:var(--danger-color);background:color-mix(in srgb,var(--danger-color) 14%,transparent)}
        .stage-tabs{display:flex;gap:9px;flex-wrap:wrap}
        .stage-tabs a{border:1px solid var(--border-soft);background:var(--card-bg);color:var(--text-main);border-radius:999px;padding:9px 14px;text-decoration:none;font-size:12px;font-weight:900}
        .stage-tabs a.active{background:linear-gradient(135deg,var(--brand-1),var(--brand-2));color:var(--brand-text);border-color:transparent}
        .form-control,.form-select{border-radius:14px;min-height:46px}
        .modal-content{border:0;border-radius:22px;background:var(--card-bg);color:var(--text-main)}
        .modal-header,.modal-footer{border-color:var(--border-soft)}
        .small-muted{display:block;margin-top:3px;color:var(--text-muted);font-size:11px;font-weight:700}
        .mobile-cards{display:none}
        .mobile-card{border:1px solid var(--border-soft);background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg));border-radius:18px;padding:16px;margin-bottom:12px}
        .mobile-card-title{font-size:16px;font-weight:900;color:var(--text-main)}
        .mobile-card-subtitle{display:block;color:var(--text-muted);font-size:12px;font-weight:700;margin-top:4px;word-break:break-word}
        .mobile-card-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
        @media(max-width:767.98px){
            .module-page .page-head{padding:18px;border-radius:18px}
            .module-page .page-head h1{font-size:24px}
            .module-page .page-head .btn{width:100%}
            .module-card{padding:16px;border-radius:18px}
            .desktop-table{display:none!important}
            .mobile-cards{display:block}
            .mobile-card-actions .btn,.mobile-card-actions form{flex:1 1 auto}
            .mobile-card-actions .btn{width:100%}
            .stage-tabs a{flex:1 1 auto;text-align:center}
        }
    </style>
</head>
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>"><div id="mobileOverlay"></div><div class="app-shell"><?php include __DIR__ . '/includes/sidebar.php'; ?><main id="main"><?php include __DIR__ . '/includes/nav.php'; ?>
<section class="page-section module-page"><div class="card-ui page-head"><div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3"><div><h1 class="mb-1">Dispatch</h1><p class="text-muted-custom mb-0">Manage dispatch, delivery and pickup details.</p></div><button class="btn btn-primary rounded-pill px-4 fw-bold" id="newDispatchBtn" data-bs-toggle="modal" data-bs-target="#dispatchModal">Create Dispatch</button></div></div>
<?php if($message): ?><div class="alert alert-<?=e($messageType)?> rounded-4 fw-bold"><?=e($message)?></div><?php endif; ?>
<div class="row g-3 mb-3"><div class="col-md-4"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)"><i data-lucide="truck"></i></div><div><span>Total Dispatch</span><strong><?=number_format($totalRows)?></strong></div></div></div><div class="col-md-4"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)"><i data-lucide="clock"></i></div><div><span>Pending</span><strong><?=number_format($pending)?></strong></div></div></div><div class="col-md-4"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)"><i data-lucide="check-circle-2"></i></div><div><span>Delivered</span><strong><?=number_format($done)?></strong></div></div></div></div>
<div class="card-ui module-card"><div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3"><div><h2 class="module-title">Dispatch List</h2><p class="text-muted-custom mb-0">Desktop table and mobile card view.</p></div><div style="max-width:340px;width:100%"><input id="tableSearch" class="form-control" placeholder="Search dispatch..."></div></div><div class="table-responsive desktop-table"><table class="table-ui" id="dataTable"><thead><tr><th>Dispatch</th><th>Job</th><th>Customer</th><th>Date</th><th>Mode</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="7" class="text-center text-muted-custom py-4">No dispatch found.</td></tr><?php endif; ?><?php foreach($rows as $row): ?><tr><td><strong><?=e($row['dispatch_no'])?></strong></td><td><?=e($row['job_no'])?></td><td><?=e($row['customer_name'])?><span class="small-muted"><?=e($row['mobile']??'')?></span></td><td><?=e($row['dispatch_date']??'-')?></td><td><?=e($row['delivery_mode']??'-')?></td><td><span class="status-pill <?=strtolower((string)$row['status'])==='delivered'?'completed':'pending'?>"><?=e($row['status']??'Pending')?></span></td><td class="text-end"><button class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-dispatch" data-bs-toggle="modal" data-bs-target="#dispatchModal" data-id="<?=e($row['id'])?>" data-dispatch-no="<?=e($row['dispatch_no'])?>" data-job-no="<?=e($row['job_no'])?>" data-customer-name="<?=e($row['customer_name'])?>" data-mobile="<?=e($row['mobile'])?>" data-dispatch-date="<?=e($row['dispatch_date'])?>" data-delivery-mode="<?=e($row['delivery_mode'])?>" data-vehicle-no="<?=e($row['vehicle_no'])?>" data-remarks="<?=e($row['remarks'])?>" data-status="<?=e($row['status'])?>">Edit</button></td></tr><?php endforeach; ?></tbody></table></div><div class="mobile-cards" id="mobileCards"><?php foreach($rows as $row): ?><div class="mobile-card"><div class="d-flex justify-content-between gap-2"><div><div class="mobile-card-title"><?=e($row['dispatch_no'])?></div><span class="mobile-card-subtitle">Job: <?=e($row['job_no'])?></span><span class="mobile-card-subtitle"><?=e($row['customer_name'])?> · <?=e($row['mobile'])?></span><span class="mobile-card-subtitle"><?=e($row['dispatch_date'])?> · <?=e($row['delivery_mode'])?></span></div><span class="status-pill <?=strtolower((string)$row['status'])==='delivered'?'completed':'pending'?>"><?=e($row['status']??'Pending')?></span></div></div><?php endforeach; ?></div></div></section></main><div id="settingsOverlay"></div><?php include __DIR__ . '/includes/rightsidebar.php'; ?></div>
<div class="modal fade" id="dispatchModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><form method="post" class="modal-content"><input type="hidden" name="csrf_token" value="<?=e($csrfToken)?>"><input type="hidden" name="action" value="save_dispatch"><input type="hidden" name="id" id="id"><div class="modal-header"><h5 class="modal-title fw-bold" id="dispatchModalTitle">Create Dispatch</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><label class="form-label fw-bold">Dispatch No</label><input name="dispatch_no" id="dispatch_no" class="form-control" placeholder="Auto if empty"></div><div class="col-md-6"><label class="form-label fw-bold">Job No *</label><input name="job_no" id="job_no" class="form-control" required></div><div class="col-md-6"><label class="form-label fw-bold">Customer Name *</label><input name="customer_name" id="customer_name" class="form-control" required></div><div class="col-md-6"><label class="form-label fw-bold">Mobile</label><input name="mobile" id="mobile" class="form-control"></div><div class="col-md-4"><label class="form-label fw-bold">Dispatch Date</label><input type="date" name="dispatch_date" id="dispatch_date" class="form-control"></div><div class="col-md-4"><label class="form-label fw-bold">Delivery Mode</label><select name="delivery_mode" id="delivery_mode" class="form-select"><option>Direct Pickup</option><option>Courier</option><option>Transport</option><option>Delivery Staff</option></select></div><div class="col-md-4"><label class="form-label fw-bold">Status</label><select name="status" id="status" class="form-select"><option>Pending</option><option>Dispatched</option><option>Delivered</option><option>Cancelled</option></select></div><div class="col-md-6"><label class="form-label fw-bold">Vehicle / Tracking No</label><input name="vehicle_no" id="vehicle_no" class="form-control"></div><div class="col-12"><label class="form-label fw-bold">Remarks</label><textarea name="remarks" id="remarks" rows="3" class="form-control"></textarea></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary rounded-pill px-4 fw-bold">Save Dispatch</button></div></form></div></div>
<?php include __DIR__ . '/includes/script.php'; ?><script>(function(){function set(id,v){const el=document.getElementById(id);if(el)el.value=v||'';}document.getElementById('newDispatchBtn')?.addEventListener('click',()=>{document.getElementById('dispatchModalTitle').textContent='Create Dispatch';['id','dispatch_no','job_no','customer_name','mobile','dispatch_date','vehicle_no','remarks'].forEach(i=>set(i,''));set('delivery_mode','Direct Pickup');set('status','Pending');});document.querySelectorAll('.js-edit-dispatch').forEach(btn=>btn.addEventListener('click',()=>{document.getElementById('dispatchModalTitle').textContent='Edit Dispatch';set('id',btn.dataset.id);set('dispatch_no',btn.dataset.dispatchNo);set('job_no',btn.dataset.jobNo);set('customer_name',btn.dataset.customerName);set('mobile',btn.dataset.mobile);set('dispatch_date',btn.dataset.dispatchDate);set('delivery_mode',btn.dataset.deliveryMode);set('vehicle_no',btn.dataset.vehicleNo);set('remarks',btn.dataset.remarks);set('status',btn.dataset.status);}));document.getElementById('tableSearch')?.addEventListener('input',function(){const v=this.value.toLowerCase();document.querySelectorAll('#dataTable tbody tr').forEach(r=>r.style.display=r.textContent.toLowerCase().includes(v)?'':'none');document.querySelectorAll('#mobileCards .mobile-card').forEach(c=>c.style.display=c.textContent.toLowerCase().includes(v)?'':'none');});if(window.lucide)lucide.createIcons();})();</script></body></html>
