<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product-stock-helper.php';

require_permission($conn, 'can_view', 'stock-adjustment.php');
ps_require_module($conn);

if (!can_create($conn, 'stock-adjustment.php')) {
    http_response_code(403);
    exit('You do not have permission to reduce stock.');
}

$message = trim((string)($_GET['error'] ?? ''));
$messageType = $message !== '' ? 'danger' : 'success';
$toastTitle = $message !== '' ? 'Unable to Continue' : 'Success';
$selectedProductId = (int)($_GET['product_id'] ?? 0);
$products = ps_fetch_products_with_stock($conn, false);

$csrfToken = ps_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stock Reduce - Subhiksha Cards</title>
<?php include __DIR__.'/includes/links.php'; ?>
<?php include __DIR__.'/includes/theme-loader.php'; ?>
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

.module-page .page-head{padding:24px 28px;margin-bottom:18px}.module-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}
.stock-table-wrap{max-height:58vh;overflow:auto;border:1px solid var(--border-soft)}.stock-table th{position:sticky;top:0;background:var(--card-bg);z-index:2;white-space:nowrap}.qty-input{min-width:130px}.selected-row{background:rgba(220,53,69,.06)}
@media(max-width:767.98px){.module-page .page-head h1{font-size:24px}}
</style>
</head>
<body class="<?= ps_e(($theme['layout_density']??'')==='compact'?'layout-compact':'') ?>">
<div id="mobileOverlay"></div><div class="app-shell"><?php include __DIR__.'/includes/sidebar.php'; ?>
<main id="main"><?php include __DIR__.'/includes/nav.php'; ?><section class="page-section module-page">
<div class="card-ui page-head"><div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3"><div><h1 class="mb-1">Stock Reduce</h1><p class="text-muted-custom mb-0">Bulk reduce stock with a mandatory reason and permanent stock history.</p></div><a href="stock-management.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Back to Stock</a></div></div>
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

<form method="post" action="api/stock.php" class="card-ui p-3 p-lg-4" id="reduceForm">
<input type="hidden" name="action" value="reduce">
<input type="hidden" name="csrf_token" value="<?= ps_e($csrfToken) ?>">
<div class="row g-3 mb-3">
<div class="col-12 col-md-4"><label class="form-label fw-bold">Reason *</label><select class="form-select" name="reason" required><option value="">Select Reason</option><option value="damage">Damage</option><option value="wastage">Wastage</option><option value="sample">Sample</option><option value="manual_usage">Manual Usage</option><option value="other">Other</option></select></div>
<div class="col-12 col-md-8"><label class="form-label fw-bold">Description *</label><input class="form-control" name="description" required minlength="3" value="" placeholder="Explain why the stock is being reduced"></div>
<div class="col-12"><label class="form-label fw-bold">Search Product</label><input type="text" class="form-control" id="productSearch" placeholder="Type product name to filter"></div>
</div>

<div class="stock-table-wrap"><table class="table-ui stock-table w-100 mb-0"><thead><tr><th>Product</th><th>On Hand</th><th>Reserved</th><th>Available</th><th>Reduce Qty</th><th>After Reduce</th></tr></thead><tbody id="stockRows">
<?php foreach($products as $p): $selected=$selectedProductId===(int)$p['id']; ?>
<tr class="<?= $selected?'selected-row':'' ?>" data-name="<?= ps_e(mb_strtolower($p['product_name'])) ?>" data-onhand="<?= ps_e($p['on_hand_stock']) ?>">
<td class="fw-bold"><?= ps_e($p['product_name']) ?></td><td><?= ps_e(ps_qty($p['on_hand_stock'])) ?></td><td><?= ps_e(ps_qty($p['reserved_stock'])) ?></td><td class="<?= (float)$p['available_stock']<0?'text-danger fw-bold':'' ?>"><?= ps_e(ps_qty($p['available_stock'])) ?></td>
<td><input class="form-control qty-input reduce-qty" inputmode="decimal" name="qty[<?= (int)$p['id'] ?>]" placeholder="0" <?= $selected?'autofocus':'' ?>></td><td class="fw-bold after-cell"><?= ps_e(ps_qty($p['on_hand_stock'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<div class="alert alert-light border mt-3 mb-0"><strong>Rule:</strong> Manual reduction cannot exceed On Hand Stock. Negative stock is allowed only through Proforma reservation, where Available Stock can become negative.</div>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-3"><div class="fw-bold">Selected Products: <span id="selectedCount">0</span> · Total Reduce Qty: <span id="totalQty">0</span></div><div class="d-flex gap-2"><a href="stock-management.php" class="btn btn-outline-secondary fw-bold px-4">Cancel</a><button class="btn btn-danger fw-bold px-4" type="submit">Save Stock Reduction</button></div></div>
</form>
</section></main><div id="settingsOverlay"></div><?php include __DIR__.'/includes/rightsidebar.php'; ?></div>
<?php include __DIR__.'/includes/script.php'; ?>
<script>

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


(function(){
const form=document.getElementById('reduceForm'),search=document.getElementById('productSearch'),rows=[...document.querySelectorAll('#stockRows tr')],count=document.getElementById('selectedCount'),total=document.getElementById('totalQty');let dirty=false,submitting=false;
function calc(){let c=0,t=0,invalid=false;rows.forEach(r=>{const input=r.querySelector('.reduce-qty');const q=parseFloat(input.value||'0');const on=parseFloat(r.dataset.onhand||'0');if(q>0){c++;t+=q;if(q>on){input.classList.add('is-invalid');invalid=true;}else input.classList.remove('is-invalid');}else input.classList.remove('is-invalid');r.querySelector('.after-cell').textContent=(on-(q>0?q:0)).toFixed(2).replace(/\.?0+$/,'');});count.textContent=c;total.textContent=t.toFixed(2).replace(/\.?0+$/,'');return !invalid;}
rows.forEach(r=>r.querySelector('.reduce-qty').addEventListener('input',()=>{dirty=true;calc();}));
search.addEventListener('input',()=>{const q=search.value.trim().toLowerCase();rows.forEach(r=>r.style.display=!q||r.dataset.name.includes(q)?'':'none');});
form.addEventListener('input',()=>dirty=true);form.addEventListener('change',()=>dirty=true);
form.addEventListener('submit',e=>{if(parseInt(count.textContent||'0',10)<=0){e.preventDefault();showToast('Enter reduce quantity for at least one product.', 'warning', 'Check Stock Quantity');return;}if(!calc()){e.preventDefault();showToast('Reduce quantity cannot exceed On Hand Stock.', 'danger', 'Invalid Stock Reduction');return;}if(!confirm('Reduce the entered stock quantities?')){e.preventDefault();return;}submitting=true;});
window.addEventListener('beforeunload',e=>{if(!dirty||submitting)return;e.preventDefault();e.returnValue='';});calc();
})();
</script>
</body></html>
