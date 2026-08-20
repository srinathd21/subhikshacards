<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product-stock-helper.php';

require_permission($conn, 'can_view', 'edit-product.php');
ps_require_module($conn);

if (!can_edit($conn, 'edit-product.php')) {
    http_response_code(403);
    exit('You do not have permission to edit products.');
}

$productId = (int)($_GET['id'] ?? $_POST['product_id'] ?? 0);
if ($productId <= 0) {
    ps_redirect('manage-products.php?error=' . urlencode('Invalid product.'));
}

function ep_load_product(mysqli $conn, int $productId): ?array
{
    $stmt = $conn->prepare("
        SELECT
            p.*,
            COALESCE(ps.minimum_stock,0) AS minimum_stock,
            COALESCE(ps.low_stock_alert,0) AS low_stock_alert,
            COALESCE(ps.on_hand_stock,0) AS on_hand_stock,
            COALESCE(ps.reserved_stock,0) AS reserved_stock
        FROM products p
        LEFT JOIN product_stock ps ON ps.product_id=p.id
        WHERE p.id=?
        LIMIT 1
    ");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

$product = ep_load_product($conn, $productId);
if (!$product) ps_redirect('manage-products.php?error=' . urlencode('Product not found.'));
if ((int)($product['is_removed'] ?? 0) === 1) {
    ps_redirect('manage-products.php?error=' . urlencode('Restore the removed product before editing it.'));
}

$error = trim((string)($_GET['error'] ?? ''));

$images = [];
$stmt = $conn->prepare("SELECT * FROM product_images WHERE product_id=? AND is_active=1 ORDER BY sort_order ASC,id ASC");
$stmt->bind_param('i', $productId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $images[] = $row;
$stmt->close();

$csrfToken = ps_csrf_token();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Edit Product - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
    .module-page .page-head {
        padding: 22px 24px;
        margin-bottom: 16px
    }

    .module-page .page-head h1 {
        font-size: 28px;
        font-weight: 900
    }

    .form-card {
        padding: 24px
    }

    .current-thumb {
        width: 120px;
        height: 120px;
        object-fit: cover;
        border: 1px solid var(--border-soft);
        background: #fff
    }

    .image-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        gap: 12px
    }

    .image-card {
        border: 1px solid var(--border-soft);
        padding: 10px;
        background: var(--card-bg)
    }

    .image-card img {
        width: 100%;
        height: 110px;
        object-fit: cover;
        background: #fff
    }

    .settings-box {
        border: 1px solid var(--border-soft);
        padding: 16px;
        background: var(--card-bg)
    }

    @media(max-width:767.98px) {
        .form-card {
            padding: 16px
        }

        .module-page .page-head h1 {
            font-size: 24px
        }
    }
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
                            <h1 class="mb-1">Edit Product</h1>
                            <p class="text-muted-custom mb-0">Update the simple product details and website-ready
                                images.</p>
                        </div><a href="manage-products.php"
                            class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Back to Products</a>
                    </div>
                </div>

                <?php if ($error !== ''): ?><div class="alert alert-danger fw-semibold"><?= ps_e($error) ?></div>
                <?php endif; ?>

                <form method="post" action="api/products.php" enctype="multipart/form-data" class="card-ui form-card"
                    id="editProductForm">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="csrf_token" value="<?= ps_e($csrfToken) ?>">
                    <input type="hidden" name="product_id" value="<?= $productId ?>">
                    <div class="row g-3">
                        <div class="col-12 col-lg-7"><label class="form-label fw-bold">Product Name *</label><input
                                class="form-control" name="product_name" maxlength="200" required
                                value="<?= ps_e($product['product_name']) ?>"></div>
                        <div class="col-12 col-lg-5"><label class="form-label fw-bold">Product Price <span
                                    class="text-muted fw-normal">(Optional)</span></label><input class="form-control"
                                inputmode="decimal" name="default_price"
                                value="<?= (float)$product['default_price']>0?ps_e($product['default_price']):'' ?>"
                                placeholder="Optional"></div>

                        <div class="col-12 col-lg-6">
                            <label class="form-label fw-bold">Thumbnail Image</label>
                            <div class="d-flex gap-3 align-items-start flex-wrap">
                                <?php if (!empty($product['thumbnail_image'])): ?><img class="current-thumb"
                                    src="<?= ps_e($product['thumbnail_image']) ?>" alt=""><?php endif; ?>
                                <div class="flex-grow-1"><input type="file" class="form-control" name="thumbnail_image"
                                        accept="image/jpeg,image/png,image/webp,image/gif"><small
                                        class="text-muted-custom">Choose a new image only when you want to replace the
                                        current thumbnail.</small></div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label fw-bold">Add Secondary Images <span
                                    class="text-muted fw-normal">(Optional)</span></label>
                            <input type="file" class="form-control" name="secondary_images[]"
                                accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                            <small class="text-muted-custom">Existing images stay unless you mark them for removal
                                below.</small>
                        </div>

                        <?php if ($images): ?>
                        <div class="col-12">
                            <label class="form-label fw-bold">Current Secondary Images</label>
                            <div class="image-grid">
                                <?php foreach ($images as $img): ?>
                                <label class="image-card">
                                    <img src="<?= ps_e($img['image_path']) ?>" alt="">
                                    <div class="form-check mt-2"><input class="form-check-input" type="checkbox"
                                            name="remove_secondary[]" value="<?= (int)$img['id'] ?>"><span
                                            class="form-check-label small fw-bold">Remove from product</span></div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="col-12">
                            <div class="settings-box">
                                <div class="row g-3 align-items-end">
                                    <div class="col-12 col-md-7">
                                        <div class="form-check form-switch"><input class="form-check-input"
                                                type="checkbox" name="low_stock_alert" id="lowStockAlert"
                                                <?= (int)$product['low_stock_alert']===1?'checked':'' ?>><label
                                                class="form-check-label fw-bold" for="lowStockAlert">Enable Low Stock
                                                Alert</label></div><small class="text-muted-custom">Available Stock = On
                                            Hand Stock - Reserved Stock.</small>
                                    </div>
                                    <div class="col-12 col-md-5"><label class="form-label fw-bold">Minimum
                                            Stock</label><input class="form-control" inputmode="decimal"
                                            name="minimum_stock" id="minimumStock"
                                            value="<?= ps_e(ps_qty($product['minimum_stock'])) ?>"></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="alert alert-light border mb-0"><strong>Current Stock:</strong> On Hand
                                <?= ps_e(ps_qty($product['on_hand_stock'])) ?> · Reserved
                                <?= ps_e(ps_qty($product['reserved_stock'])) ?> · Available
                                <?= ps_e(ps_qty((float)$product['on_hand_stock']-(float)$product['reserved_stock'])) ?>
                            </div>
                        </div>

                        <div class="col-12 d-flex flex-column flex-sm-row justify-content-end gap-2 mt-4"><a
                                href="manage-products.php"
                                class="btn btn-outline-secondary fw-bold px-4">Cancel</a><button
                                class="btn btn-primary fw-bold px-4">Save Changes</button></div>
                    </div>
                </form>
            </section>
        </main>
        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>
    <?php include __DIR__ . '/includes/script.php'; ?>
    <script>
    (function() {
        const form = document.getElementById('editProductForm');
        let dirty = false,
            submitting = false;
        form?.addEventListener('input', () => dirty = true);
        form?.addEventListener('change', () => dirty = true);
        form?.addEventListener('submit', () => submitting = true);
        window.addEventListener('beforeunload', e => {
            if (!dirty || submitting) return;
            e.preventDefault();
            e.returnValue = '';
        });
        const toggle = document.getElementById('lowStockAlert'),
            min = document.getElementById('minimumStock');

        function sync() {
            min.disabled = !toggle.checked;
            if (!toggle.checked) min.value = '0';
        }
        toggle?.addEventListener('change', sync);
        sync();
    })();
    </script>
</body>

</html>