<?php
/**
 * customer_approvals.php
 * Subhiksha Cards ERP - Customer Approvals
 */

require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'customer_approvals.php');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['customer_approvals_csrf'])) $_SESSION['customer_approvals_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['customer_approvals_csrf'];
$message = '';
$messageType = 'success';

function ca_table_exists(mysqli $conn, string $table): bool {
    try { $t=$conn->real_escape_string($table); $r=$conn->query("SHOW TABLES LIKE '{${t}}'"); return $r && $r->num_rows>0; } catch(Throwable $e) { return false; }
}
function ca_post(string $key,string $default=''): string { return trim((string)($_POST[$key]??$default)); }
function ca_int($v): int { return (int)filter_var($v,FILTER_SANITIZE_NUMBER_INT); }
function ca_redirect(string $q=''): void { header('Location: customer_approvals.php'.($q!==''?'?'.$q:'')); exit; }
function ca_csrf(): void {
    if(empty($_POST['csrf_token']) || !hash_equals($_SESSION['customer_approvals_csrf']??'', (string)$_POST['csrf_token'])) die('Invalid CSRF token.');
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    ca_csrf();
    try {
        $action=ca_post('action');
        if($action==='save_approval') {
            if(!ca_table_exists($conn,'customer_approvals')) throw new RuntimeException('customer_approvals table is missing. Run SQL first.');
            $id=ca_int($_POST['id']??0);
            $customerName=ca_post('customer_name');
            $mobile=ca_post('mobile');
            $jobNo=ca_post('job_no');
            $proof_link=ca_post('proof_link');
            $approval_date=ca_post('approval_date');
            $remarks=ca_post('remarks');
            $status=ca_post('status','Pending');
            if($customerName==='' || $jobNo==='') throw new RuntimeException('Customer name and job no are required.');
            if($id>0) {
                $stmt=$conn->prepare("UPDATE customer_approvals SET customer_name=?, mobile=?, job_no=?, proof_link=?, approval_date=?, remarks=?, status=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param('sssssssi',$customerName,$mobile,$jobNo,$proof_link,$approval_date,$remarks,$status,$id);
                $stmt->execute(); $stmt->close(); ca_redirect('msg=updated');
            }
            $createdBy=(int)($_SESSION['user_id']??0);
            $stmt=$conn->prepare("INSERT INTO customer_approvals(customer_name,mobile,job_no,proof_link,approval_date,remarks,status,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,NOW(),NOW())");
            $stmt->bind_param('sssssssi',$customerName,$mobile,$jobNo,$proof_link,$approval_date,$remarks,$status,$createdBy);
            $stmt->execute(); $stmt->close(); ca_redirect('msg=created');
        }
        if($action==='delete_approval') {
            $id=ca_int($_POST['id']??0); if($id<=0) throw new RuntimeException('Invalid approval.');
            $stmt=$conn->prepare("UPDATE customer_approvals SET status='Inactive', updated_at=NOW() WHERE id=?");
            $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close(); ca_redirect('msg=deleted');
        }
    } catch(Throwable $e) { $message=$e->getMessage(); $messageType='danger'; }
}
$msg=(string)($_GET['msg']??'');
if($msg==='created') $message='Approval created successfully.';
elseif($msg==='updated') $message='Approval updated successfully.';
elseif($msg==='deleted') $message='Approval disabled successfully.';

$rows=[];
if(ca_table_exists($conn,'customer_approvals')) {
    $res=$conn->query("SELECT * FROM customer_approvals ORDER BY id DESC LIMIT 300");
    while($row=$res->fetch_assoc()) $rows[]=$row;
}
$totalRows=count($rows);
$pendingRows=0; $approvedRows=0;
foreach($rows as $row) {
    if(strtolower((string)$row['status'])==='pending') $pendingRows++;
    if(strtolower((string)$row['status'])==='approved') $approvedRows++;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer Approvals - Subhiksha Cards</title>
<?php include __DIR__ . '/includes/links.php'; ?>
<?php include __DIR__ . '/includes/theme-loader.php'; ?>

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
<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div><div class="app-shell"><?php include __DIR__ . '/includes/sidebar.php'; ?><main id="main"><?php include __DIR__ . '/includes/nav.php'; ?>
<section class="page-section module-page">
<div class="card-ui page-head"><div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3"><div><h1 class="mb-1">Customer Approvals</h1><p class="text-muted-custom mb-0">Manage design proof approvals from customers.</p></div><button class="btn btn-primary rounded-pill px-4 fw-bold" id="newApprovalBtn" data-bs-toggle="modal" data-bs-target="#approvalModal">Create Approval</button></div></div>
<?php if($message): ?><div class="alert alert-<?=e($messageType)?> rounded-4 fw-bold"><?=e($message)?></div><?php endif; ?>
<div class="row g-3 mb-3"><div class="col-md-4"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)"><i data-lucide="badge-check"></i></div><div><span>Total</span><strong><?=number_format($totalRows)?></strong></div></div></div><div class="col-md-4"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)"><i data-lucide="clock"></i></div><div><span>Pending</span><strong><?=number_format($pendingRows)?></strong></div></div></div><div class="col-md-4"><div class="card-ui stat-card h-100"><div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)"><i data-lucide="check-circle-2"></i></div><div><span>Approved</span><strong><?=number_format($approvedRows)?></strong></div></div></div></div>
<div class="card-ui module-card"><div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3"><div><h2 class="module-title">Approval List</h2><p class="text-muted-custom mb-0">Desktop table and mobile card view.</p></div><div style="max-width:340px;width:100%"><input type="search" id="tableSearch" class="form-control" placeholder="Search approval..."></div></div>
<div class="table-responsive desktop-table"><table class="table-ui" id="dataTable"><thead><tr><th>Customer</th><th>Job No</th><th>Proof</th><th>Date</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="6" class="text-center text-muted-custom py-4">No approvals found.</td></tr><?php endif; ?>
<?php foreach($rows as $row): ?><tr><td><?=e($row['customer_name'])?><span class="small-muted"><?=e($row['mobile']??'')?></span></td><td><?=e($row['job_no'])?></td><td><?php if(!empty($row['proof_link'])): ?><a href="<?=e($row['proof_link'])?>" target="_blank">Open Proof</a><?php else: ?>-<?php endif; ?></td><td><?=e($row['approval_date']??'-')?></td><td><span class="status-pill <?=strtolower((string)$row['status'])==='approved'?'completed':(strtolower((string)$row['status'])==='inactive'?'inactive':'pending')?>"><?=e($row['status']??'Pending')?></span></td><td class="text-end"><button class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit-approval" data-bs-toggle="modal" data-bs-target="#approvalModal" data-id="<?=e($row['id'])?>" data-customer-name="<?=e($row['customer_name'])?>" data-mobile="<?=e($row['mobile'])?>" data-job-no="<?=e($row['job_no'])?>" data-proof-link="<?=e($row['proof_link'])?>" data-approval-date="<?=e($row['approval_date'])?>" data-remarks="<?=e($row['remarks'])?>" data-status="<?=e($row['status'])?>">Edit</button></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="mobile-cards" id="mobileCards"><?php foreach($rows as $row): ?><div class="mobile-card"><div class="d-flex justify-content-between gap-2"><div><div class="mobile-card-title"><?=e($row['customer_name'])?></div><span class="mobile-card-subtitle"><?=e($row['mobile']??'')?> · Job: <?=e($row['job_no'])?></span><span class="mobile-card-subtitle">Date: <?=e($row['approval_date']??'-')?></span></div><span class="status-pill <?=strtolower((string)$row['status'])==='approved'?'completed':'pending'?>"><?=e($row['status']??'Pending')?></span></div></div><?php endforeach; ?></div>
</div></section></main><div id="settingsOverlay"></div><?php include __DIR__ . '/includes/rightsidebar.php'; ?></div>
<div class="modal fade" id="approvalModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><form method="post" class="modal-content"><input type="hidden" name="csrf_token" value="<?=e($csrfToken)?>"><input type="hidden" name="action" value="save_approval"><input type="hidden" name="id" id="id"><div class="modal-header"><h5 class="modal-title fw-bold" id="approvalModalTitle">Create Approval</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><label class="form-label fw-bold">Customer Name *</label><input name="customer_name" id="customer_name" class="form-control" required></div><div class="col-md-6"><label class="form-label fw-bold">Mobile</label><input name="mobile" id="mobile" class="form-control"></div><div class="col-md-6"><label class="form-label fw-bold">Job No *</label><input name="job_no" id="job_no" class="form-control" required></div><div class="col-md-6"><label class="form-label fw-bold">Proof Link</label><input name="proof_link" id="proof_link" class="form-control"></div><div class="col-md-6"><label class="form-label fw-bold">Approval Date</label><input type="date" name="approval_date" id="approval_date" class="form-control"></div><div class="col-md-6"><label class="form-label fw-bold">Status</label><select name="status" id="status" class="form-select"><option>Pending</option><option>Approved</option><option>Rejected</option><option>Inactive</option></select></div><div class="col-12"><label class="form-label fw-bold">Remarks</label><textarea name="remarks" id="remarks" rows="3" class="form-control"></textarea></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary rounded-pill px-4 fw-bold" id="approvalSubmitBtn">Save Approval</button></div></form></div></div>
<?php include __DIR__ . '/includes/script.php'; ?><script>(function(){function set(id,v){const el=document.getElementById(id);if(el)el.value=v||'';}document.getElementById('newApprovalBtn')?.addEventListener('click',()=>{document.getElementById('approvalModalTitle').textContent='Create Approval';['id','customer_name','mobile','job_no','proof_link','approval_date','remarks'].forEach(i=>set(i,''));set('status','Pending');});document.querySelectorAll('.js-edit-approval').forEach(btn=>btn.addEventListener('click',()=>{document.getElementById('approvalModalTitle').textContent='Edit Approval';set('id',btn.dataset.id);set('customer_name',btn.dataset.customerName);set('mobile',btn.dataset.mobile);set('job_no',btn.dataset.jobNo);set('proof_link',btn.dataset.proofLink);set('approval_date',btn.dataset.approvalDate);set('remarks',btn.dataset.remarks);set('status',btn.dataset.status);}));document.getElementById('tableSearch')?.addEventListener('input',function(){const v=this.value.toLowerCase();document.querySelectorAll('#dataTable tbody tr').forEach(r=>r.style.display=r.textContent.toLowerCase().includes(v)?'':'none');document.querySelectorAll('#mobileCards .mobile-card').forEach(c=>c.style.display=c.textContent.toLowerCase().includes(v)?'':'none');});if(window.lucide)lucide.createIcons();})();</script></body></html>
