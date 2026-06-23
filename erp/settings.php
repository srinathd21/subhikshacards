<?php
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'settings.php');
if(session_status()===PHP_SESSION_NONE)session_start();
if(empty($_SESSION['settings_csrf']))$_SESSION['settings_csrf']=bin2hex(random_bytes(32));
$csrfToken=$_SESSION['settings_csrf']; $message=''; $messageType='success';
function st_post($k,$d=''){return trim((string)($_POST[$k]??$d));}
function st_redirect($q=''){header('Location: settings.php'.($q?'?'.$q:''));exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){ if(empty($_POST['csrf_token'])||!hash_equals($_SESSION['settings_csrf'],(string)$_POST['csrf_token']))die('Invalid CSRF token.'); try{ $id=(int)($_POST['id']??0); $key=st_post('setting_key'); $val=st_post('setting_value'); $type=st_post('setting_type','text'); $desc=st_post('description'); $pub=isset($_POST['is_public'])?1:0; if($key==='')throw new RuntimeException('Setting key is required.'); if($id>0){ $stmt=$conn->prepare("UPDATE system_settings SET setting_key=?,setting_value=?,setting_type=?,description=?,is_public=?,updated_by=?,updated_at=NOW() WHERE id=?"); $uid=(int)($_SESSION['user_id']??0); $stmt->bind_param('ssssiii',$key,$val,$type,$desc,$pub,$uid,$id); $stmt->execute();$stmt->close();st_redirect('msg=updated'); } $stmt=$conn->prepare("INSERT INTO system_settings(setting_key,setting_value,setting_type,description,is_public,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,NOW(),NOW())"); $uid=(int)($_SESSION['user_id']??0); $stmt->bind_param('ssssii',$key,$val,$type,$desc,$pub,$uid); $stmt->execute();$stmt->close();st_redirect('msg=created'); }catch(Throwable $e){$message=$e->getMessage();$messageType='danger';} }
if(($_GET['msg']??'')==='created')$message='Setting created successfully.'; elseif(($_GET['msg']??'')==='updated')$message='Setting updated successfully.';
$settings=[]; $res=$conn->query("SELECT * FROM system_settings ORDER BY setting_key ASC"); while($row=$res->fetch_assoc())$settings[]=$row;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>System Settings - Subhiksha Cards</title>
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

<section class="page-section master-page"><div class="card-ui page-head"><div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3"><div><h1 class="mb-1">System Settings</h1><p class="text-muted-custom mb-0">Application settings like review link, WhatsApp and tracking configuration.</p></div><button id="newSettingBtn" class="btn btn-primary rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#settingModal">New Setting</button></div></div>
<?php if($message): ?><div class="alert alert-<?=e($messageType)?> rounded-4 fw-bold"><?=e($message)?></div><?php endif; ?>
<div class="card-ui master-card"><div class="table-responsive"><table class="table-ui"><thead><tr><th>Key</th><th>Value</th><th>Type</th><th>Public</th><th class="text-end">Action</th></tr></thead><tbody><?php foreach($settings as $s): ?><tr><td><strong><?=e($s['setting_key'])?></strong><span class="small-muted"><?=e($s['description'] ?? '')?></span></td><td><?=e(mb_strimwidth((string)$s['setting_value'],0,80,'...'))?></td><td><?=e($s['setting_type'])?></td><td><span class="status-pill <?=(int)$s['is_public']===1?'active':'inactive'?>"><?=(int)$s['is_public']===1?'Yes':'No'?></span></td><td class="text-end"><button class="btn btn-sm btn-outline-primary rounded-pill fw-bold js-edit" data-bs-toggle="modal" data-bs-target="#settingModal" data-id="<?=e($s['id'])?>" data-key="<?=e($s['setting_key'])?>" data-value="<?=e($s['setting_value'])?>" data-type="<?=e($s['setting_type'])?>" data-description="<?=e($s['description'])?>" data-public="<?=e($s['is_public'])?>">Edit</button></td></tr><?php endforeach; ?></tbody></table></div></div></section>
</main><div id="settingsOverlay"></div><?php include __DIR__ . '/includes/rightsidebar.php'; ?></div>
<div class="modal fade" id="settingModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><form method="post" class="modal-content"><input type="hidden" name="csrf_token" value="<?=e($csrfToken)?>"><input type="hidden" name="id" id="id"><div class="modal-header"><h5 class="modal-title fw-bold" id="modalTitle">New Setting</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><label class="form-label fw-bold">Setting Key *</label><input name="setting_key" id="setting_key" required class="form-control"></div><div class="col-md-6"><label class="form-label fw-bold">Type</label><select name="setting_type" id="setting_type" class="form-select"><option>text</option><option>number</option><option>boolean</option><option>json</option><option>file</option><option>url</option></select></div><div class="col-12"><label class="form-label fw-bold">Value</label><textarea name="setting_value" id="setting_value" rows="3" class="form-control"></textarea></div><div class="col-12"><label class="form-label fw-bold">Description</label><textarea name="description" id="description" rows="2" class="form-control"></textarea></div><div class="col-12"><label class="form-check"><input type="checkbox" name="is_public" id="is_public" value="1" class="form-check-input"><span class="form-check-label fw-bold">Public</span></label></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary rounded-pill px-4 fw-bold" id="submitBtn">Save Setting</button></div></form></div></div>
<?php include __DIR__ . '/includes/script.php'; ?>
<script>(function(){function set(id,v){const e=document.getElementById(id);if(!e)return;if(e.type==='checkbox')e.checked=String(v)==='1';else e.value=v||'';}document.getElementById('newSettingBtn')?.addEventListener('click',()=>{['id','setting_key','setting_value','description'].forEach(i=>set(i,''));set('setting_type','text');set('is_public','0');document.getElementById('modalTitle').textContent='New Setting';});document.querySelectorAll('.js-edit').forEach(b=>b.addEventListener('click',()=>{set('id',b.dataset.id);set('setting_key',b.dataset.key);set('setting_value',b.dataset.value);set('setting_type',b.dataset.type);set('description',b.dataset.description);set('is_public',b.dataset.public);document.getElementById('modalTitle').textContent='Edit Setting';}));if(window.lucide)lucide.createIcons();})();</script></body></html>
