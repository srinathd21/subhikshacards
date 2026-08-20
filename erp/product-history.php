<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product-stock-helper.php';

require_permission($conn, 'can_view', 'product-history.php');
ps_require_module($conn);

$productId=(int)($_GET['product_id']??0);
$action=trim((string)($_GET['action']??''));

$products=[];
$res=$conn->query("SELECT id,product_name FROM products ORDER BY product_name ASC");
while($r=$res->fetch_assoc())$products[]=$r;$res->free();

$where=['1=1'];$params=[];$types='';
if($productId>0){$where[]='ph.product_id=?';$params[]=$productId;$types.='i';}
if($action!==''){$where[]='ph.action_type=?';$params[]=$action;$types.='s';}

$stmt=$conn->prepare("
SELECT ph.*,p.product_name,u.name user_name
FROM product_history ph
INNER JOIN products p ON p.id=ph.product_id
LEFT JOIN users u ON u.id=ph.created_by
WHERE ".implode(' AND ',$where)."
ORDER BY ph.id DESC
LIMIT 1000
");
if($types!=='')$stmt->bind_param($types,...$params);
$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();

$actions=[];
$res=$conn->query("SELECT DISTINCT action_type FROM product_history ORDER BY action_type ASC");
while($r=$res->fetch_assoc())$actions[]=$r['action_type'];$res->free();

$message = trim((string)($_GET['message'] ?? ''));
$messageType = trim((string)($_GET['message_type'] ?? 'success'));
if (!in_array($messageType, ['success','danger','warning'], true)) $messageType = 'success';
$toastTitle = $messageType === 'danger' ? 'Unable to Continue' : ($messageType === 'warning' ? 'Warning' : 'Success');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Product History - Subhiksha Cards</title>
<?php include __DIR__.'/includes/links.php'; ?><?php include __DIR__.'/includes/theme-loader.php'; ?>
<style>

    .toast-ui {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
        overflow: hidden;
        min-width: 320px;
        max-width: 420px;
    }

    .toast-ui.success {
        background: #dcfce7;
        color: #14532d;
    }

    .toast-ui.danger {
        background: #fee2e2;
        color: #7f1d1d;
    }

    .toast-ui.warning {
        background: #fef3c7;
        color: #78350f;
    }

    .toast-ui .toast-title {
        font-size: 14px;
        font-weight: 900;
        margin-bottom: 2px;
    }

    .toast-ui .toast-message {
        font-size: 13px;
        font-weight: 800;
        line-height: 1.45;
    }
.module-page .page-head{padding:24px 28px;margin-bottom:18px}.module-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}.action-pill{display:inline-flex;padding:5px 10px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:11px;font-weight:900}.action-pill.removed{background:#fff1f2;color:#dc2626}.action-pill.restored{background:#ecfdf3;color:#047857}.history-json{max-width:380px;white-space:pre-wrap;word-break:break-word;font-size:11px;color:var(--text-muted)}@media(max-width:767.98px){.module-page .page-head h1{font-size:24px}}</style>
</head><body class="<?= ps_e(($theme['layout_density']??'')==='compact'?'layout-compact':'') ?>"><div id="mobileOverlay"></div><div class="app-shell"><?php include __DIR__.'/includes/sidebar.php'; ?><main id="main"><?php include __DIR__.'/includes/nav.php'; ?><section class="page-section module-page">
<div class="card-ui page-head"><div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3"><div><h1 class="mb-1">Product History</h1><p class="text-muted-custom mb-0">Product creation, edits, removal and restore records are never deleted.</p></div><a href="manage-products.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Back to Products</a></div></div>

<?php if ($message !== ''): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 12000">
                    <div id="pageToast" class="toast toast-ui <?= ps_e($messageType) ?>" role="alert" aria-live="assertive"
                        aria-atomic="true" data-bs-delay="4200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title"><?= ps_e($toastTitle) ?></div>
                                <div class="toast-message"><?= ps_e($message) ?></div>
                            </div>
                            <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

<form class="card-ui p-3 mb-3" method="get"><div class="row g-2 align-items-end"><div class="col-12 col-md-5"><label class="form-label fw-bold small">Product</label><select class="form-select" name="product_id"><option value="0">All Products</option><?php foreach($products as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $productId===(int)$p['id']?'selected':'' ?>><?= ps_e($p['product_name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4"><label class="form-label fw-bold small">Action</label><select class="form-select" name="action"><option value="">All Actions</option><?php foreach($actions as $a): ?><option value="<?= ps_e($a) ?>" <?= $action===$a?'selected':'' ?>><?= ps_e(ucwords(str_replace('_',' ',$a))) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-3 d-flex gap-2"><button class="btn btn-primary fw-bold w-100">Filter</button><a href="product-history.php" class="btn btn-outline-secondary fw-bold">Reset</a></div></div></form>

<div class="card-ui overflow-hidden"><div class="table-responsive"><table class="table-ui mb-0"><thead><tr><th>Date</th><th>Product</th><th>Action</th><th>Description</th><th>User</th><th>Details</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="6" class="text-center text-muted-custom py-5">No product history found.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?>
<tr><td><?= ps_e(date('d-m-Y h:i A',strtotime($r['created_at']))) ?></td><td class="fw-bold"><?= ps_e($r['product_name']) ?></td><td><span class="action-pill <?= ps_e($r['action_type']) ?>"><?= ps_e(ucwords(str_replace('_',' ',$r['action_type']))) ?></span></td><td><?= ps_e($r['description']?:'-') ?></td><td><?= ps_e($r['user_name']?:'-') ?></td><td><?php if($r['old_data']||$r['new_data']): ?><details><summary class="fw-bold small" style="cursor:pointer">View change</summary><div class="history-json mt-2"><?php if($r['old_data']): ?><strong>Before</strong><br><?= ps_e(json_encode(json_decode($r['old_data'],true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?><br><?php endif; ?><?php if($r['new_data']): ?><strong>After</strong><br><?= ps_e(json_encode(json_decode($r['new_data'],true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?><?php endif; ?></div></details><?php else: ?>-<?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
</section></main><div id="settingsOverlay"></div><?php include __DIR__.'/includes/rightsidebar.php'; ?></div><?php include __DIR__.'/includes/script.php'; ?><script>

function showToast(message, type = 'success', title = '') {
    if (!message) return;

    const oldToastWrap = document.getElementById('dynamicActionToastWrap');
    if (oldToastWrap) oldToastWrap.remove();

    const toastTitle = title || (type === 'danger' ? 'Failed' : (type === 'warning' ? 'Warning' : 'Success'));
    const wrap = document.createElement('div');
    wrap.id = 'dynamicActionToastWrap';
    wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
    wrap.style.zIndex = '12000';

    const toast = document.createElement('div');
    toast.id = 'dynamicActionToast';
    toast.className = 'toast toast-ui ' + type;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    toast.setAttribute('data-bs-delay', '4200');

    const flex = document.createElement('div');
    flex.className = 'd-flex';

    const body = document.createElement('div');
    body.className = 'toast-body';

    const titleEl = document.createElement('div');
    titleEl.className = 'toast-title';
    titleEl.textContent = toastTitle;

    const messageEl = document.createElement('div');
    messageEl.className = 'toast-message';
    messageEl.textContent = message;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close me-3 m-auto';
    close.setAttribute('data-bs-dismiss', 'toast');
    close.setAttribute('aria-label', 'Close');

    body.appendChild(titleEl);
    body.appendChild(messageEl);
    flex.appendChild(body);
    flex.appendChild(close);
    toast.appendChild(flex);
    wrap.appendChild(toast);
    document.body.appendChild(wrap);

    if (window.bootstrap && bootstrap.Toast) {
        bootstrap.Toast.getOrCreateInstance(toast).show();
    }
}

const pageToastEl = document.getElementById('pageToast');
if (pageToastEl && window.bootstrap && bootstrap.Toast) {
    bootstrap.Toast.getOrCreateInstance(pageToastEl).show();
}

</script></body></html>
