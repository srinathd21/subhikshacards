<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product-stock-helper.php';

require_permission($conn, 'can_view', 'add-product.php');
ps_require_module($conn);

if (!can_create($conn, 'add-product.php')) {
    http_response_code(403);
    exit('You do not have permission to add products.');
}

$error = trim((string)($_GET['error'] ?? ''));

$csrfToken = ps_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Add Product - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
        .module-page .page-head{padding:22px 24px;margin-bottom:16px}
        .module-page .page-head h1{font-size:28px;font-weight:900}
        .form-card{padding:24px}
        .image-preview{width:110px;height:110px;object-fit:cover;border:1px solid var(--border-soft);background:#fff;display:none}
        .secondary-preview{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
        .secondary-preview img{width:72px;height:72px;object-fit:cover;border:1px solid var(--border-soft);background:#fff}
        .settings-box{border:1px solid var(--border-soft);padding:16px;background:var(--card-bg)}
        @media(max-width:767.98px){.form-card{padding:16px}.module-page .page-head h1{font-size:24px}}
    </style>
</head>
<body class="<?= ps_e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
<div id="mobileOverlay"></div>
<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main id="main">
        <?php include __DIR__ . '/includes/nav.php'; ?>
        <section class="page-section module-page">
            <div class="card-ui page-head">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div>
                        <h1 class="mb-1">Add Product</h1>
                        <p class="text-muted-custom mb-0">Only the essential product details. Stock can be added separately from Stock Inward.</p>
                    </div>
                    <a href="manage-products.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Back to Products</a>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger fw-semibold"><?= ps_e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="api/products.php" enctype="multipart/form-data" class="card-ui form-card" id="productForm">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="csrf_token" value="<?= ps_e($csrfToken) ?>">

                <div class="row g-3">
                    <div class="col-12 col-lg-7">
                        <label class="form-label fw-bold">Product Name *</label>
                        <input type="text" name="product_name" class="form-control" maxlength="200" required value="" placeholder="Enter product name">
                    </div>
                    <div class="col-12 col-lg-5">
                        <label class="form-label fw-bold">Product Price <span class="text-muted fw-normal">(Optional)</span></label>
                        <input type="text" inputmode="decimal" name="default_price" class="form-control" value="" placeholder="Example: 25.00">
                    </div>

                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-bold">Thumbnail Image <span class="text-muted fw-normal">(Optional)</span></label>
                        <input type="file" name="thumbnail_image" id="thumbnailInput" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                        <small class="text-muted-custom">Optional main product image used inside ERP and later on the website.</small>
                        <div class="mt-2"><img id="thumbnailPreview" class="image-preview" alt=""></div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-bold">Secondary Images <span class="text-muted fw-normal">(Optional)</span></label>
                        <input type="file" name="secondary_images[]" id="secondaryInput" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                        <small class="text-muted-custom">Optional gallery images kept ready for future dynamic website use.</small>
                        <div id="secondaryPreview" class="secondary-preview"></div>
                    </div>

                    <div class="col-12">
                        <div class="settings-box">
                            <div class="d-flex flex-column flex-md-row align-items-md-end gap-3">
                                <div class="form-check form-switch flex-grow-1">
                                    <input class="form-check-input" type="checkbox" role="switch" name="low_stock_alert" id="lowStockAlert">
                                    <label class="form-check-label fw-bold" for="lowStockAlert">Enable Low Stock Alert</label>
                                    <div class="small text-muted-custom">Optional. Available Stock is checked against the minimum quantity.</div>
                                </div>
                                <div style="min-width:220px">
                                    <label class="form-label fw-bold">Minimum Stock</label>
                                    <input type="text" inputmode="decimal" name="minimum_stock" id="minimumStock" class="form-control" value="0" placeholder="0">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 d-flex flex-column flex-sm-row justify-content-end gap-2 mt-4">
                        <a href="manage-products.php" class="btn btn-outline-secondary fw-bold px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary fw-bold px-4">Save Product</button>
                    </div>
                </div>
            </form>
        </section>
    </main>
    <div id="settingsOverlay"></div>
    <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
</div>
<?php include __DIR__ . '/includes/script.php'; ?>
<script>
(function () {
    const form = document.getElementById('productForm');
    let dirty = false;
    let submitting = false;

    form?.addEventListener('input', () => dirty = true);
    form?.addEventListener('change', () => dirty = true);
    form?.addEventListener('submit', () => submitting = true);

    window.addEventListener('beforeunload', function (event) {
        if (!dirty || submitting) return;
        event.preventDefault();
        event.returnValue = '';
    });

    const thumb = document.getElementById('thumbnailInput');
    const thumbPreview = document.getElementById('thumbnailPreview');
    thumb?.addEventListener('change', function () {
        const file = this.files?.[0];
        if (!file) {
            thumbPreview.style.display = 'none';
            return;
        }
        thumbPreview.src = URL.createObjectURL(file);
        thumbPreview.style.display = 'block';
    });

    const secondary = document.getElementById('secondaryInput');
    const secondaryPreview = document.getElementById('secondaryPreview');
    secondary?.addEventListener('change', function () {
        secondaryPreview.innerHTML = '';
        [...(this.files || [])].forEach(file => {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            secondaryPreview.appendChild(img);
        });
    });

    const alertToggle = document.getElementById('lowStockAlert');
    const minimumStock = document.getElementById('minimumStock');
    function syncMinimum() {
        minimumStock.disabled = !alertToggle.checked;
        if (!alertToggle.checked) minimumStock.value = '0';
    }
    alertToggle?.addEventListener('change', syncMinimum);
    syncMinimum();
})();
</script>
</body>
</html>
