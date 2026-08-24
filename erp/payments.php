<?php
require_once __DIR__ . '/includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function cmp_admin(mysqli $conn): bool {
    $rk=strtolower(trim((string)($_SESSION['role_key']??$_SESSION['role']??'')));$rn=strtolower(trim((string)($_SESSION['role_name']??'')));
    if(in_array($rk,['admin','super_admin','superadmin','business_admin'],true)||$rn==='admin')return true;
    $id=(int)($_SESSION['role_id']??0);if($id<=0)return false;
    try{$s=$conn->prepare("SELECT role_key,role_name FROM roles WHERE id=? LIMIT 1");$s->bind_param('i',$id);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();if(!$r)return false;$rk=strtolower((string)$r['role_key']);$rn=strtolower((string)$r['role_name']);return in_array($rk,['admin','super_admin','superadmin','business_admin'],true)||$rn==='admin';}catch(Throwable $e){return false;}
}
function cmp_allowed(mysqli $conn,string $action): bool {
    if(cmp_admin($conn))return true;$map=['view'=>'can_view','create'=>'can_create','edit'=>'can_edit','update'=>'can_update'];$fn=$map[$action]??'can_view';
    if(function_exists($fn)){try{return (bool)$fn($conn,'customers.php');}catch(Throwable $e){}}
    if(function_exists('permission_allowed')){try{return (bool)permission_allowed($conn,$fn,'customers.php');}catch(Throwable $e){}}
    return false;
}
if(!cmp_admin($conn)) require_permission($conn,'can_view','customers.php');
if(empty($_SESSION['customers_csrf']))$_SESSION['customers_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['customers_csrf'];$canCreate=cmp_allowed($conn,'create');$canEdit=cmp_allowed($conn,'edit')||cmp_allowed($conn,'update');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer Management - Subhiksha Cards</title>
<?php include __DIR__.'/includes/links.php'; ?><?php include __DIR__.'/includes/theme-loader.php'; ?>
<style>
.customer-page .page-head{padding:24px 28px;margin-bottom:18px}.customer-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}.stat-box{border:1px solid var(--border-soft);border-radius:18px;padding:16px}.stat-box small{display:block;font-size:11px;font-weight:900;text-transform:uppercase;color:var(--text-muted)}.stat-box strong{display:block;font-size:23px;font-weight:900;margin-top:4px}.workspace{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(350px,.85fr);gap:18px;align-items:start}.pane{border:1px solid var(--border-soft);border-radius:20px;background:var(--card-bg);overflow:hidden}.pane-head{padding:18px 20px;border-bottom:1px solid var(--border-soft)}.pane-body{padding:18px 20px}.form-pane{position:sticky;top:88px}.filter-grid{display:grid;grid-template-columns:minmax(220px,1fr) 160px 145px auto;gap:10px;align-items:end}.customer-name{font-weight:900;color:var(--text-main)}.meta{font-size:11px;font-weight:700;color:var(--text-muted);line-height:1.45}.badge-pill{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:900}.type-business{background:#eef2ff;color:#4338ca}.type-individual{background:#ecfeff;color:#0f766e}.status-active{background:#dcfce7;color:#166534}.status-inactive{background:#fee2e2;color:#991b1b}.actions{display:flex;justify-content:flex-end;gap:6px;flex-wrap:wrap}.actions .btn{width:34px;height:34px;padding:0;border-radius:50%;display:inline-flex;align-items:center;justify-content:center}.customer-table td,.customer-table th{vertical-align:middle}.mobile-card{border:1px solid var(--border-soft);border-radius:16px;padding:15px;margin-bottom:10px}.business-fields{display:none}.business-fields.show{display:block}.section-label{font-size:11px;font-weight:900;text-transform:uppercase;color:var(--text-muted);letter-spacing:.04em;margin:5px 0 11px}.required{color:#dc2626}.profile-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:16px}.profile-box{border:1px solid var(--border-soft);border-radius:18px;padding:16px}.profile-name{font-size:20px;font-weight:900}.summary-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.summary-item{border:1px solid var(--border-soft);border-radius:14px;padding:12px}.summary-item small{display:block;font-size:10px;font-weight:900;text-transform:uppercase;color:var(--text-muted)}.summary-item strong{display:block;font-size:16px;font-weight:900;margin-top:4px}.tabs{display:flex;gap:7px;flex-wrap:wrap;margin:18px 0 12px}.tabs button{border:1px solid var(--border-soft);background:var(--card-bg);border-radius:999px;padding:7px 12px;font-size:11px;font-weight:900}.tabs button.active{background:var(--primary-color,#2563eb);color:#fff}.hist{display:none}.hist.active{display:block}.hist-row{border:1px solid var(--border-soft);border-radius:14px;padding:12px;margin-bottom:8px}.hist-row small{display:block;color:var(--text-muted);font-size:10px;margin-top:3px}.toast-ui{border:0;border-radius:18px;min-width:320px;box-shadow:0 18px 45px rgba(15,23,42,.18)}.toast-ui.success{background:#dcfce7;color:#14532d}.toast-ui.danger{background:#fee2e2;color:#7f1d1d}.toast-ui.warning{background:#fef3c7;color:#78350f}.loading{padding:24px;text-align:center;font-weight:800;color:var(--text-muted)}
@media(max-width:1199.98px){.workspace{grid-template-columns:1fr}.form-pane{position:static}.stats-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:767.98px){.customer-page .page-head{padding:18px}.customer-page .page-head h1{font-size:24px}.filter-grid{grid-template-columns:1fr 1fr}.filter-grid .wide{grid-column:1/-1}.desktop-list{display:none!important}.mobile-list{display:block!important}.profile-grid{grid-template-columns:1fr}}@media(min-width:768px){.mobile-list{display:none!important}}@media(max-width:480px){.stats-grid,.summary-grid{grid-template-columns:1fr}}
</style>
<style>
/* ========================================================================
   Compact module UI - tuned for comfortable use at 100% browser zoom.
   UI sizing only: no PHP, SQL, workflow, filters, pagination or API logic.
   ======================================================================== */
#main .page-section {
    font-size: 12.5px;
}

#main .page-section .page-head {
    padding: 16px 18px !important;
    margin-bottom: 12px !important;
    border-radius: 16px !important;
}

#main .page-section .page-head h1 {
    font-size: 22px !important;
    font-weight: 800 !important;
    line-height: 1.15 !important;
    letter-spacing: -.15px !important;
    margin-bottom: 3px !important;
}

#main .page-section .page-head p,
#main .page-section .page-head .text-muted-custom {
    font-size: 11.5px !important;
    font-weight: 500 !important;
    line-height: 1.35 !important;
}

#main .page-section .module-card {
    padding: 14px 15px !important;
    border-radius: 16px !important;
    margin-bottom: 12px !important;
}

#main .page-section .module-title {
    font-size: 15px !important;
    font-weight: 800 !important;
    line-height: 1.2 !important;
}

#main .page-section .stat-card,
#main .page-section .kpi-card {
    min-height: 86px !important;
    padding: 12px 13px !important;
    border-radius: 14px !important;
    gap: 10px !important;
}

#main .page-section .stat-icon {
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    border-radius: 12px !important;
}

#main .page-section .stat-icon svg,
#main .page-section .stat-icon i {
    width: 19px !important;
    height: 19px !important;
}

#main .page-section .stat-card span,
#main .page-section .stat-card small,
#main .page-section .kpi-card small {
    font-size: 10px !important;
    font-weight: 700 !important;
    letter-spacing: .2px !important;
}

#main .page-section .stat-card strong,
#main .page-section .kpi-card strong {
    font-size: 18px !important;
    font-weight: 800 !important;
    line-height: 1.15 !important;
}

#main .page-section .filter-card {
    padding: 12px !important;
    border-radius: 14px !important;
}

#main .page-section .form-label,
#main .page-section label.fw-bold {
    font-size: 11px !important;
    font-weight: 700 !important;
    margin-bottom: 4px !important;
}

#main .page-section .form-control,
#main .page-section .form-select,
#main .page-section .select2-container--bootstrap-5 .select2-selection {
    min-height: 38px !important;
    font-size: 12px !important;
    border-radius: 10px !important;
}

#main .page-section .form-control,
#main .page-section .form-select {
    padding-top: .38rem !important;
    padding-bottom: .38rem !important;
}

#main .page-section textarea.form-control {
    min-height: 68px !important;
}

#main .page-section .btn:not(.btn-action-icon):not(.btn-delete-icon):not(.btn-whatsapp-icon) {
    font-size: 11.5px !important;
    font-weight: 700 !important;
    line-height: 1.2 !important;
}

#main .page-section .btn.rounded-pill:not(.btn-action-icon):not(.btn-delete-icon):not(.btn-whatsapp-icon) {
    padding-top: 6px !important;
    padding-bottom: 6px !important;
}

#main .page-section .table-ui,
#main .page-section table {
    font-size: 11.5px !important;
}

#main .page-section .table-ui th,
#main .page-section table th {
    font-size: 10px !important;
    font-weight: 700 !important;
    padding: 8px 9px !important;
    line-height: 1.25 !important;
}

#main .page-section .table-ui td,
#main .page-section table td {
    font-size: 11.5px !important;
    font-weight: 500 !important;
    padding: 8px 9px !important;
    line-height: 1.3 !important;
}

#main .page-section table td strong,
#main .page-section .customer-name,
#main .page-section .job-no,
#main .page-section .mobile-card-title,
#main .page-section .product-names,
#main .page-section .amount-text,
#main .page-section .balance-text,
#main .page-section .paid-amount,
#main .page-section .balance-amount {
    font-weight: 700 !important;
}

#main .page-section .status-pill,
#main .page-section .stock-pill,
#main .page-section .badge-pill,
#main .page-section .order-badge,
#main .page-section .filter-tab {
    font-size: 9.5px !important;
    font-weight: 700 !important;
    padding: 4px 7px !important;
}

#main .page-section .mobile-card,
#main .page-section .mobile-products .card-ui {
    padding: 12px !important;
    border-radius: 14px !important;
    margin-bottom: 9px !important;
}

#main .page-section .mobile-card-title {
    font-size: 13px !important;
    font-weight: 700 !important;
}

#main .page-section .mobile-card-subtitle,
#main .page-section .muted-small,
#main .page-section .small-muted,
#main .page-section .meta {
    font-size: 10.5px !important;
    font-weight: 500 !important;
    line-height: 1.35 !important;
}

#main .page-section .view-info-card,
#main .page-section .amount-box,
#main .page-section .profile-box,
#main .page-section .summary-item,
#main .page-section .hist-row {
    border-radius: 13px !important;
    padding: 11px !important;
}

#main .page-section .view-info-card small,
#main .page-section .amount-box small,
#main .page-section .summary-item small,
#main .page-section .section-label {
    font-size: 9.5px !important;
    font-weight: 700 !important;
}

#main .page-section .view-info-card span,
#main .page-section .view-info-card strong,
#main .page-section .amount-box strong,
#main .page-section .summary-item strong {
    font-size: 13px !important;
    font-weight: 700 !important;
}

#main .page-section .pagination-wrap,
#main .page-section nav[aria-label*="Pagination" i] {
    font-size: 11px !important;
}

#main .page-section .pagination .page-link,
#main .page-section .product-pagination .page-link-ui {
    min-width: 32px !important;
    min-height: 32px !important;
    padding: 5px 8px !important;
    font-size: 10.5px !important;
    font-weight: 700 !important;
}

/* Customer Management compact sizing */
#main .customer-page .stats-grid {
    gap: 10px !important;
    margin-bottom: 12px !important;
}

#main .customer-page .stat-box {
    padding: 11px 12px !important;
    border-radius: 14px !important;
}

#main .customer-page .stat-box small {
    font-size: 9.5px !important;
    font-weight: 700 !important;
}

#main .customer-page .stat-box strong {
    font-size: 18px !important;
    font-weight: 800 !important;
    margin-top: 2px !important;
}

#main .customer-page .workspace {
    gap: 12px !important;
}

#main .customer-page .pane {
    border-radius: 16px !important;
}

#main .customer-page .pane-head,
#main .customer-page .pane-body {
    padding: 13px 14px !important;
}

#main .customer-page .customer-name,
#main .customer-page .profile-name {
    font-size: 13px !important;
    font-weight: 700 !important;
}

#main .customer-page .profile-grid,
#main .customer-page .summary-grid {
    gap: 9px !important;
}

#main .customer-page .tabs {
    margin: 12px 0 9px !important;
    gap: 5px !important;
}

#main .customer-page .tabs button {
    padding: 5px 9px !important;
    font-size: 10px !important;
    font-weight: 700 !important;
}

/* Product master images and rows */
#main .module-page .product-thumb,
#main .module-page .placeholder-thumb {
    width: 42px !important;
    height: 42px !important;
}

/* Job Card shortcut controls */
#main .module-page .shortcut-action-box {
    padding: 10px !important;
    border-radius: 13px !important;
}

#main .module-page .shortcut-btn {
    min-height: 34px !important;
    font-size: 10.5px !important;
    font-weight: 700 !important;
}

#main .module-page .shortcut-note,
#main .module-page .shortcut-help-bar {
    font-size: 10.5px !important;
    font-weight: 500 !important;
}

/* Keep icon-only actions compact */
#main .page-section .btn-action-icon,
#main .page-section .btn-delete-icon,
#main .page-section .btn-whatsapp-icon,
#main .customer-page .actions .btn {
    width: 32px !important;
    height: 32px !important;
    min-width: 32px !important;
    max-width: 32px !important;
    padding: 0 !important;
}

#main .page-section .btn-action-icon svg,
#main .page-section .btn-delete-icon svg,
#main .page-section .btn-whatsapp-icon svg,
#main .customer-page .actions .btn svg {
    width: 14px !important;
    height: 14px !important;
}

/* Reduce heavy utility weight only inside module content */
#main .page-section .fw-bold,
#main .page-section strong {
    font-weight: 700 !important;
}

/* Compact modal typography without changing modal workflow */
#main ~ .modal .modal-title,
.modal .modal-title {
    font-size: 15px !important;
    font-weight: 800 !important;
}

.modal .modal-header,
.modal .modal-footer {
    padding-top: 11px !important;
    padding-bottom: 11px !important;
}

.modal .modal-body {
    font-size: 12px !important;
}

@media (max-width: 767.98px) {
    #main .page-section .page-head {
        padding: 14px !important;
    }

    #main .page-section .page-head h1 {
        font-size: 20px !important;
    }

    #main .page-section .module-card {
        padding: 12px !important;
    }

    #main .page-section .stat-card,
    #main .page-section .kpi-card {
        min-height: 76px !important;
        padding: 10px 11px !important;
    }

    #main .page-section .stat-icon {
        width: 36px !important;
        height: 36px !important;
        min-width: 36px !important;
    }
}
</style>

</head>
<body class="<?= htmlspecialchars((($theme['layout_density']??'')==='compact'?'layout-compact':''),ENT_QUOTES,'UTF-8') ?>"><div id="mobileOverlay"></div><div class="app-shell"><?php include __DIR__.'/includes/sidebar.php'; ?><main id="main"><?php include __DIR__.'/includes/nav.php'; ?>
<section class="page-section customer-page">
<div class="card-ui page-head"><div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3"><div><h1 class="mb-1">Customer Management</h1><p class="text-muted-custom mb-0">Manage customer master, contact details and complete business history in one page.</p></div><?php if($canCreate): ?><button class="btn btn-primary rounded-pill px-4 fw-bold" id="newBtn"><i data-lucide="user-plus"></i> New Customer</button><?php endif; ?></div></div>
<div class="stats-grid"><div class="card-ui stat-box"><small>Total Customers</small><strong id="sTotal">0</strong></div><div class="card-ui stat-box"><small>Active</small><strong id="sActive">0</strong></div><div class="card-ui stat-box"><small>Business</small><strong id="sBusiness">0</strong></div><div class="card-ui stat-box"><small>Individual</small><strong id="sIndividual">0</strong></div></div>
<div class="workspace">
<section class="pane"><div class="pane-head"><h5 class="mb-1 fw-bold">Customer List</h5><small id="resultText" class="text-muted-custom fw-bold">Loading...</small></div><div class="pane-body">
<div class="filter-grid mb-3"><div class="wide"><label class="form-label fw-bold">Search</label><input class="form-control" id="q" placeholder="Name / Mobile / Email / Business / GST"></div><div><label class="form-label fw-bold">Type</label><select class="form-select" id="type"><option value="">All</option><option value="individual">Individual</option><option value="business">Business</option></select></div><div><label class="form-label fw-bold">Status</label><select class="form-select" id="status"><option value="">All</option><option value="1">Active</option><option value="0">Inactive</option></select></div><div class="wide d-flex gap-2"><button class="btn btn-primary fw-bold" id="filterBtn">Filter</button><button class="btn btn-outline-secondary fw-bold" id="resetBtn">Reset</button></div></div>
<div class="table-responsive desktop-list"><table class="table-ui customer-table"><thead><tr><th>Customer</th><th>Type / Business</th><th>Location</th><th>Business</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody id="tbody"><tr><td colspan="6" class="loading">Loading customers...</td></tr></tbody></table></div><div class="mobile-list" id="mobileList"><div class="loading">Loading customers...</div></div>
<div class="d-flex justify-content-between align-items-center mt-3 gap-2" id="pager" style="display:none!important"><small class="text-muted-custom fw-bold" id="pageText"></small><div class="d-flex gap-2"><button class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold" id="prev">Previous</button><button class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold" id="next">Next</button></div></div>
</div></section>
<aside class="pane form-pane" id="formPane"><div class="pane-head"><h5 class="mb-1 fw-bold" id="formTitle">Add Customer</h5><small class="text-muted-custom fw-bold">Use the same form for add and edit.</small></div><div class="pane-body"><form id="customerForm" autocomplete="off"><input type="hidden" name="action" value="save"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="id" id="cid" value="0">
<div class="section-label">Basic Details</div><div class="mb-3"><label class="form-label fw-bold">Customer Name <span class="required">*</span></label><input class="form-control" name="customer_name" id="name" maxlength="150" required></div><div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label fw-bold">Mobile <span class="required">*</span></label><input class="form-control" name="mobile" id="mobile" inputmode="numeric" maxlength="10" required></div><div class="col-md-6"><label class="form-label fw-bold">Alternate Mobile</label><input class="form-control" name="alternate_mobile" id="alt" inputmode="numeric" maxlength="10"></div></div><div class="mb-3"><label class="form-label fw-bold">Email</label><input type="email" class="form-control" name="email" id="email" maxlength="150"></div>
<div class="section-label">Customer Type</div><div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label fw-bold">Type</label><select class="form-select" name="customer_type" id="ctype"><option value="individual">Individual</option><option value="business">Business</option></select></div><div class="col-md-6"><label class="form-label fw-bold">Status</label><select class="form-select" name="is_active" id="active"><option value="1">Active</option><option value="0">Inactive</option></select></div></div><div class="business-fields" id="bizWrap"><div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label fw-bold">Business Name <span class="required">*</span></label><input class="form-control" name="business_name" id="biz" maxlength="200"></div><div class="col-md-6"><label class="form-label fw-bold">GST Number</label><input class="form-control text-uppercase" name="gst_number" id="gst" maxlength="50"></div></div></div>
<div class="section-label">Address</div><div class="mb-3"><label class="form-label fw-bold">Address</label><textarea class="form-control" name="address" id="address" rows="3" maxlength="1500"></textarea></div><div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label fw-bold">City</label><input class="form-control" name="city" id="city" maxlength="100"></div><div class="col-md-6"><label class="form-label fw-bold">State</label><input class="form-control" name="state" id="state" maxlength="100" value="Tamil Nadu"></div></div><div class="mb-3"><label class="form-label fw-bold">Pincode</label><input class="form-control" name="pincode" id="pin" inputmode="numeric" maxlength="6"></div>
<div class="d-flex gap-2"><button type="button" class="btn btn-outline-secondary fw-bold flex-grow-1" id="clearBtn">Clear</button><?php if($canCreate||$canEdit): ?><button class="btn btn-primary fw-bold flex-grow-1" id="saveBtn">Save Customer</button><?php endif; ?></div></form></div></aside>
</div></section></main><div id="settingsOverlay"></div><?php include __DIR__.'/includes/rightsidebar.php'; ?></div>
<div class="modal fade" id="viewModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content rounded-4"><div class="modal-header"><div><h5 class="modal-title fw-bold">Customer Profile</h5><small class="text-muted-custom fw-bold">Details, summary and history.</small></div><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="viewBody"><div class="loading">Loading...</div></div></div></div></div>
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1095"><div id="toast" class="toast toast-ui"><div class="toast-body"><div class="fw-bold" id="toastTitle"></div><div class="small fw-bold" id="toastMsg"></div></div></div></div>
<script>window.CM={csrf:<?= json_encode($csrf) ?>,canCreate:<?= $canCreate?'true':'false' ?>,canEdit:<?= $canEdit?'true':'false' ?>};</script><?php include __DIR__.'/includes/script.php'; ?>
<script>
(function(){'use strict';var pg=1,tp=1,pp=15,modalEl=document.getElementById('viewModal'),viewModal=window.bootstrap?new bootstrap.Modal(modalEl):null;
function e(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}function money(v){return '₹'+Number(v||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})}function toast(type,title,msg){var x=document.getElementById('toast');x.className='toast toast-ui '+type;document.getElementById('toastTitle').textContent=title;document.getElementById('toastMsg').textContent=msg;if(window.bootstrap)bootstrap.Toast.getOrCreateInstance(x,{delay:3500}).show()}function digits(id,max){document.getElementById(id).addEventListener('input',function(){this.value=this.value.replace(/\D+/g,'').slice(0,max)})}digits('mobile',10);digits('alt',10);digits('pin',6);
function biz(){var on=document.getElementById('ctype').value==='business';document.getElementById('bizWrap').classList.toggle('show',on);document.getElementById('biz').required=on;if(!on){document.getElementById('biz').value='';document.getElementById('gst').value=''}}document.getElementById('ctype').addEventListener('change',biz);
function reset(){document.getElementById('customerForm').reset();document.getElementById('cid').value='0';document.getElementById('state').value='Tamil Nadu';document.getElementById('active').value='1';document.getElementById('ctype').value='individual';document.getElementById('formTitle').textContent='Add Customer';var b=document.getElementById('saveBtn');if(b)b.textContent='Save Customer';biz()}document.getElementById('clearBtn').onclick=reset;var nb=document.getElementById('newBtn');if(nb)nb.onclick=function(){reset();document.getElementById('name').focus()};
function summary(){fetch('api/customers.php?action=summary',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{if(!j.status)return;document.getElementById('sTotal').textContent=Number(j.summary.total||0).toLocaleString('en-IN');document.getElementById('sActive').textContent=Number(j.summary.active||0).toLocaleString('en-IN');document.getElementById('sBusiness').textContent=Number(j.summary.business||0).toLocaleString('en-IN');document.getElementById('sIndividual').textContent=Number(j.summary.individual||0).toLocaleString('en-IN')})}
function params(){var p=new URLSearchParams({action:'list',page:pg,per_page:pp,q:document.getElementById('q').value.trim(),customer_type:document.getElementById('type').value,status:document.getElementById('status').value});return p}
function row(r){var typ=r.customer_type==='business'?'business':'individual',loc=[r.city,r.state,r.pincode].filter(Boolean).join(', ')||'-',st=Number(r.is_active)===1;return '<tr><td><div class="customer-name">'+e(r.customer_name)+'</div><div class="meta">'+e(r.mobile)+(r.email?' · '+e(r.email):'')+'</div></td><td><span class="badge-pill type-'+typ+'">'+(typ==='business'?'Business':'Individual')+'</span><div class="meta">'+e(r.business_name||'-')+'</div></td><td>'+e(loc)+'</td><td><strong>'+money(r.total_business)+'</strong><div class="meta">Enq '+Number(r.enquiry_count||0)+' · Qtn '+Number(r.quotation_count||0)+' · Pro '+Number(r.proforma_count||0)+'</div></td><td><span class="badge-pill '+(st?'status-active':'status-inactive')+'">'+(st?'Active':'Inactive')+'</span></td><td><div class="actions"><button class="btn btn-outline-primary" onclick="cmView('+r.id+')" title="View"><i data-lucide="eye"></i></button>'+(window.CM.canEdit?'<button class="btn btn-outline-secondary" onclick="cmEdit('+r.id+')" title="Edit"><i data-lucide="pencil"></i></button><button class="btn btn-outline-'+(st?'danger':'success')+'" onclick="cmToggle('+r.id+','+(st?0:1)+')" title="'+(st?'Deactivate':'Activate')+'"><i data-lucide="'+(st?'user-x':'user-check')+'"></i></button>':'')+'</div></td></tr>'}
function card(r){var st=Number(r.is_active)===1;return '<div class="mobile-card"><div class="d-flex justify-content-between gap-2"><div><div class="customer-name">'+e(r.customer_name)+'</div><div class="meta">'+e(r.mobile)+'</div></div><span class="badge-pill '+(st?'status-active':'status-inactive')+'">'+(st?'Active':'Inactive')+'</span></div><div class="meta mt-2">'+e(r.business_name||r.customer_type)+' · '+money(r.total_business)+'</div><div class="d-flex gap-2 mt-2"><button class="btn btn-outline-primary btn-sm fw-bold" onclick="cmView('+r.id+')">View</button>'+(window.CM.canEdit?'<button class="btn btn-outline-secondary btn-sm fw-bold" onclick="cmEdit('+r.id+')">Edit</button><button class="btn btn-outline-'+(st?'danger':'success')+' btn-sm fw-bold" onclick="cmToggle('+r.id+','+(st?0:1)+')">'+(st?'Deactivate':'Activate')+'</button>':'')+'</div></div>'}
function load(){document.getElementById('tbody').innerHTML='<tr><td colspan="6" class="loading">Loading...</td></tr>';fetch('api/customers.php?'+params(),{credentials:'same-origin'}).then(r=>r.json()).then(j=>{if(!j.status)throw Error(j.message);var rows=j.rows||[];document.getElementById('tbody').innerHTML=rows.length?rows.map(row).join(''):'<tr><td colspan="6" class="text-center py-4 text-muted-custom">No customers found.</td></tr>';document.getElementById('mobileList').innerHTML=rows.length?rows.map(card).join(''):'<div class="mobile-card text-center">No customers found.</div>';pg=Number(j.pagination.page||1);tp=Number(j.pagination.total_pages||1);document.getElementById('resultText').textContent=Number(j.pagination.total_rows||0).toLocaleString('en-IN')+' customer(s) found';document.getElementById('pageText').textContent='Page '+pg+' of '+tp;document.getElementById('prev').disabled=pg<=1;document.getElementById('next').disabled=pg>=tp;var p=document.getElementById('pager');p.style.setProperty('display',tp>1?'flex':'none','important');if(window.lucide)lucide.createIcons()}).catch(x=>{document.getElementById('tbody').innerHTML='<tr><td colspan="6" class="text-danger text-center py-4">'+e(x.message)+'</td></tr>'})}
document.getElementById('filterBtn').onclick=function(){pg=1;load()};document.getElementById('resetBtn').onclick=function(){document.getElementById('q').value='';document.getElementById('type').value='';document.getElementById('status').value='';pg=1;load()};document.getElementById('q').addEventListener('keydown',function(x){if(x.key==='Enter'){x.preventDefault();pg=1;load()}});document.getElementById('prev').onclick=function(){if(pg>1){pg--;load()}};document.getElementById('next').onclick=function(){if(pg<tp){pg++;load()}};
document.getElementById('customerForm').onsubmit=function(ev){ev.preventDefault();var id=Number(document.getElementById('cid').value||0),m=document.getElementById('mobile').value,a=document.getElementById('alt').value,p=document.getElementById('pin').value;if(id&&!window.CM.canEdit)return toast('danger','Permission','No edit permission.');if(!id&&!window.CM.canCreate)return toast('danger','Permission','No create permission.');if(!/^\d{10}$/.test(m))return toast('warning','Mobile','Mobile must contain exactly 10 digits.');if(a&&!/^\d{10}$/.test(a))return toast('warning','Alternate Mobile','Alternate mobile must contain 10 digits.');if(p&&!/^\d{6}$/.test(p))return toast('warning','Pincode','Pincode must contain 6 digits.');var fd=new FormData(this),b=document.getElementById('saveBtn');if(b){b.disabled=true;b.textContent='Saving...'}fetch('api/customers.php',{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(j=>{if(!j.status)throw Error(j.message);toast('success',id?'Customer Updated':'Customer Added',j.message);reset();summary();load()}).catch(x=>toast('danger','Save Failed',x.message)).finally(()=>{if(b){b.disabled=false;b.textContent=document.getElementById('cid').value==='0'?'Save Customer':'Update Customer'}})};
window.cmEdit=function(id){fetch('api/customers.php?action=get&id='+id,{credentials:'same-origin'}).then(r=>r.json()).then(j=>{if(!j.status)throw Error(j.message);var c=j.customer;document.getElementById('cid').value=c.id;document.getElementById('name').value=c.customer_name||'';document.getElementById('mobile').value=c.mobile||'';document.getElementById('alt').value=c.alternate_mobile||'';document.getElementById('email').value=c.email||'';document.getElementById('ctype').value=c.customer_type||'individual';document.getElementById('biz').value=c.business_name||'';document.getElementById('gst').value=c.gst_number||'';document.getElementById('address').value=c.address||'';document.getElementById('city').value=c.city||'';document.getElementById('state').value=c.state||'';document.getElementById('pin').value=c.pincode||'';document.getElementById('active').value=String(Number(c.is_active));document.getElementById('formTitle').textContent='Edit Customer';document.getElementById('saveBtn').textContent='Update Customer';biz();document.getElementById('formPane').scrollIntoView({behavior:'smooth',block:'start'})}).catch(x=>toast('danger','Edit Failed',x.message))};
window.cmToggle=function(id,st){if(!confirm('Are you sure?'))return;var fd=new FormData();fd.append('action','toggle_status');fd.append('id',id);fd.append('is_active',st);fd.append('csrf_token',window.CM.csrf);fetch('api/customers.php',{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(j=>{if(!j.status)throw Error(j.message);toast('success','Status Updated',j.message);summary();load()}).catch(x=>toast('danger','Status Failed',x.message))};
function hrows(rows,msg){if(!rows||!rows.length)return '<div class="text-center text-muted-custom fw-bold py-4">'+e(msg)+'</div>';return rows.map(r=>'<div class="hist-row"><div class="d-flex justify-content-between gap-2"><strong>'+e(r.title)+'</strong><strong>'+e(r.amount_text||'')+'</strong></div><small>'+e(r.meta||'')+'</small></div>').join('')}
window.cmView=function(id){if(viewModal)viewModal.show();document.getElementById('viewBody').innerHTML='<div class="loading">Loading...</div>';fetch('api/customers.php?action=profile&id='+id,{credentials:'same-origin'}).then(r=>r.json()).then(j=>{if(!j.status)throw Error(j.message);var c=j.customer,s=j.summary,h=j.history,addr=[c.address,c.city,c.state,c.pincode].filter(Boolean).join(', ');document.getElementById('viewBody').innerHTML='<div class="profile-grid"><div class="profile-box"><div class="profile-name">'+e(c.customer_name)+'</div><div class="meta mt-2">Mobile: '+e(c.mobile||'-')+'</div>'+(c.alternate_mobile?'<div class="meta">Alternate: '+e(c.alternate_mobile)+'</div>':'')+(c.email?'<div class="meta">Email: '+e(c.email)+'</div>':'')+'<div class="meta">Type: '+e(c.customer_type)+'</div>'+(c.business_name?'<div class="meta">Business: '+e(c.business_name)+'</div>':'')+(c.gst_number?'<div class="meta">GST: '+e(c.gst_number)+'</div>':'')+'<div class="meta">Address: '+e(addr||'-')+'</div></div><div class="profile-box"><div class="summary-grid"><div class="summary-item"><small>Enquiries</small><strong>'+s.enquiries+'</strong></div><div class="summary-item"><small>Quotations</small><strong>'+s.quotations+'</strong></div><div class="summary-item"><small>Proformas</small><strong>'+s.proformas+'</strong></div><div class="summary-item"><small>Quick Sales</small><strong>'+s.quick_sales+'</strong></div><div class="summary-item"><small>Total Business</small><strong>'+money(s.total_business)+'</strong></div><div class="summary-item"><small>Pending Balance</small><strong>'+money(s.pending_balance)+'</strong></div></div></div></div><div class="tabs"><button class="active" data-t="enquiries">Enquiries</button><button data-t="quotations">Quotations</button><button data-t="proformas">Proformas</button><button data-t="quick_sales">Quick Sales</button><button data-t="payments">Payments</button></div><div class="hist active" data-p="enquiries">'+hrows(h.enquiries,'No enquiries found.')+'</div><div class="hist" data-p="quotations">'+hrows(h.quotations,'No quotations found.')+'</div><div class="hist" data-p="proformas">'+hrows(h.proformas,'No proformas found.')+'</div><div class="hist" data-p="quick_sales">'+hrows(h.quick_sales,'No Quick Sales found.')+'</div><div class="hist" data-p="payments">'+hrows(h.payments,'No payments found.')+'</div>';document.querySelectorAll('.tabs button').forEach(b=>b.onclick=function(){document.querySelectorAll('.tabs button').forEach(x=>x.classList.remove('active'));document.querySelectorAll('.hist').forEach(x=>x.classList.remove('active'));b.classList.add('active');document.querySelector('[data-p="'+b.dataset.t+'"]').classList.add('active')})}).catch(x=>document.getElementById('viewBody').innerHTML='<div class="alert alert-danger fw-bold">'+e(x.message)+'</div>')};
reset();summary();load();if(window.lucide)lucide.createIcons();})();
</script></body></html>
