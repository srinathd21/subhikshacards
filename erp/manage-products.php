<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product-stock-helper.php';

require_permission($conn, 'can_view', 'manage-products.php');
ps_require_module($conn);

$canCreate = can_create($conn, 'manage-products.php');
$canEdit = can_edit($conn, 'manage-products.php');
$canDelete = can_delete($conn, 'manage-products.php');

$message = '';
$messageType = 'success';
$toastTitle = 'Success';

if (isset($_GET['saved'])) {
    $message = 'Product saved successfully.';
} elseif (isset($_GET['bulk_saved'])) {
    $count = max(1, (int)$_GET['bulk_saved']);
    $message = $count . ' product' . ($count === 1 ? '' : 's') . ' added successfully.';
} elseif (isset($_GET['removed'])) {
    $message = 'Product removed successfully. Existing history and references are preserved.';
} elseif (isset($_GET['restored'])) {
    $message = 'Product restored successfully.';
} elseif (isset($_GET['error'])) {
    $messageType = 'danger';
    $toastTitle = 'Unable to Continue';
    $message = trim((string)$_GET['error']);
}

$search = trim((string)($_GET['search'] ?? ''));
$view = trim((string)($_GET['view'] ?? 'active'));
$stockFilter = trim((string)($_GET['stock'] ?? 'all'));

if (!in_array($view, ['active', 'removed', 'all'], true)) $view = 'active';
if (!in_array($stockFilter, ['all', 'in', 'low', 'out', 'negative'], true)) $stockFilter = 'all';

/*
 * Server-side pagination.
 * Keep this page lightweight even when the Product Master grows large.
 */
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = [];
$params = [];
$types = '';

if ($view === 'active') {
    $where[] = 'COALESCE(p.is_removed,0) = 0 AND p.is_active = 1';
} elseif ($view === 'removed') {
    $where[] = 'COALESCE(p.is_removed,0) = 1';
}

if ($search !== '') {
    $where[] = 'p.product_name LIKE ?';
    $params[] = '%' . $search . '%';
    $types .= 's';
}

$availableExpr = '(COALESCE(ps.on_hand_stock,0) - COALESCE(ps.reserved_stock,0))';

if ($stockFilter === 'negative') {
    $where[] = "{$availableExpr} < 0";
} elseif ($stockFilter === 'out') {
    $where[] = "{$availableExpr} = 0";
} elseif ($stockFilter === 'low') {
    $where[] = "{$availableExpr} > 0 AND COALESCE(ps.low_stock_alert,0)=1 AND COALESCE(ps.minimum_stock,0)>0 AND {$availableExpr} <= COALESCE(ps.minimum_stock,0)";
} elseif ($stockFilter === 'in') {
    $where[] = "{$availableExpr} > 0 AND NOT (COALESCE(ps.low_stock_alert,0)=1 AND COALESCE(ps.minimum_stock,0)>0 AND {$availableExpr} <= COALESCE(ps.minimum_stock,0))";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/*
 * Count the filtered rows first so pagination always respects
 * Search Product, Products and Stock Status filters.
 */
$countSql = "
    SELECT COUNT(*) AS total_rows
    FROM products p
    LEFT JOIN product_stock ps ON ps.product_id = p.id
    {$whereSql}
";
$countStmt = $conn->prepare($countSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();
$totalRows = (int)($countRow['total_rows'] ?? 0);
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
        p.*,
        COALESCE(ps.on_hand_stock,0) AS on_hand_stock,
        COALESCE(ps.reserved_stock,0) AS reserved_stock,
        COALESCE(ps.minimum_stock,0) AS minimum_stock,
        COALESCE(ps.low_stock_alert,0) AS low_stock_alert,
        {$availableExpr} AS available_stock,
        (SELECT COUNT(*) FROM product_images pi WHERE pi.product_id=p.id AND pi.is_active=1) AS secondary_image_count
    FROM products p
    LEFT JOIN product_stock ps ON ps.product_id = p.id
    {$whereSql}
    ORDER BY COALESCE(p.is_removed,0) ASC, p.product_name ASC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

$showingFrom = $totalRows > 0 ? $offset + 1 : 0;
$showingTo = $totalRows > 0 ? min($offset + $perPage, $totalRows) : 0;

/*
 * Pagination URLs intentionally preserve only the current filters.
 * Success/error query flags are not repeated on every page.
 */
function mp_page_url(int $targetPage, string $search, string $view, string $stockFilter): string
{
    $query = [
        'search' => $search,
        'view' => $view,
        'stock' => $stockFilter,
        'page' => max(1, $targetPage),
    ];

    if ($query['search'] === '') {
        unset($query['search']);
    }
    if ($query['view'] === 'active') {
        unset($query['view']);
    }
    if ($query['stock'] === 'all') {
        unset($query['stock']);
    }
    if ($query['page'] === 1) {
        unset($query['page']);
    }

    $qs = http_build_query($query);
    return 'manage-products.php' . ($qs !== '' ? '?' . $qs : '');
}

$summary = [
    'total' => 0,
    'active' => 0,
    'removed' => 0,
    'low' => 0,
    'negative' => 0,
];

$res = $conn->query("
    SELECT
        COUNT(*) total_count,
        SUM(CASE WHEN COALESCE(p.is_removed,0)=0 AND p.is_active=1 THEN 1 ELSE 0 END) active_count,
        SUM(CASE WHEN COALESCE(p.is_removed,0)=1 THEN 1 ELSE 0 END) removed_count,
        SUM(CASE
            WHEN COALESCE(p.is_removed,0)=0
             AND p.is_active=1
             AND COALESCE(ps.on_hand_stock,0)-COALESCE(ps.reserved_stock,0) > 0
             AND COALESCE(ps.low_stock_alert,0)=1
             AND COALESCE(ps.minimum_stock,0)>0
             AND COALESCE(ps.on_hand_stock,0)-COALESCE(ps.reserved_stock,0) <= COALESCE(ps.minimum_stock,0)
            THEN 1 ELSE 0 END) low_count,
        SUM(CASE
            WHEN COALESCE(p.is_removed,0)=0
             AND p.is_active=1
             AND COALESCE(ps.on_hand_stock,0)-COALESCE(ps.reserved_stock,0) < 0
            THEN 1 ELSE 0 END) negative_count
    FROM products p
    LEFT JOIN product_stock ps ON ps.product_id=p.id
");
if ($s = $res->fetch_assoc()) {
    $summary = [
        'total' => (int)$s['total_count'],
        'active' => (int)$s['active_count'],
        'removed' => (int)$s['removed_count'],
        'low' => (int)$s['low_count'],
        'negative' => (int)$s['negative_count'],
    ];
}
$res->free();

$csrfToken = ps_csrf_token();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Manage Products - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
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

    .module-page .page-head {
        padding: 24px 28px;
        margin-bottom: 18px
    }

    .module-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main)
    }

    .kpi-card {
        min-height: 112px
    }

    .product-thumb {
        width: 52px;
        height: 52px;
        object-fit: cover;
        border: 1px solid var(--border-soft);
        background: #fff
    }

    .placeholder-thumb {
        width: 52px;
        height: 52px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--border-soft);
        background: var(--card-bg);
        color: var(--text-muted)
    }

    .stock-pill {
        display: inline-flex;
        padding: 5px 10px;
        font-size: 11px;
        font-weight: 900;
        border-radius: 999px
    }

    .stock-pill.success {
        background: #ecfdf3;
        color: #047857
    }

    .stock-pill.warning {
        background: #fff7ed;
        color: #c2410c
    }

    .stock-pill.danger {
        background: #fff1f2;
        color: #dc2626
    }

    .stock-pill.secondary {
        background: #f1f5f9;
        color: #475569
    }

    .removed-row {
        opacity: .75
    }

    .desktop-table th {
        white-space: nowrap
    }

    .mobile-products {
        display: none
    }

    .modal-content {
        background: var(--card-bg);
        color: var(--text-main);
        border: 1px solid var(--border-soft)
    }

    .bulk-add-row {
        display: grid;
        grid-template-columns: minmax(240px, 1.6fr) minmax(150px, .7fr) minmax(260px, 1fr) 44px;
        gap: 10px;
        align-items: end;
        padding: 12px;
        border: 1px solid var(--border-soft);
        background: var(--card-bg);
        margin-bottom: 10px
    }

    .bulk-add-row label {
        font-size: 12px;
        font-weight: 800;
        margin-bottom: 5px
    }

    .bulk-row-no {
        font-size: 11px;
        font-weight: 900;
        color: var(--text-muted);
        margin-bottom: 5px
    }

    .bulk-remove-btn {
        width: 42px;
        height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center
    }

    .bulk-note {
        border: 1px solid var(--border-soft);
        padding: 12px;
        background: var(--card-bg)
    }

    .pagination-wrap {
        border-top: 1px solid var(--border-soft);
        padding: 14px 16px;
        background: var(--card-bg)
    }

    .pagination-summary {
        font-size: 12px;
        font-weight: 800;
        color: var(--text-muted)
    }

    .product-pagination {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        margin: 0;
        padding: 0;
        list-style: none
    }

    .product-pagination .page-link-ui {
        min-width: 36px;
        height: 36px;
        padding: 0 11px;
        border: 1px solid var(--border-soft);
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        color: var(--text-main);
        background: var(--card-bg);
        font-size: 12px;
        font-weight: 900;
        transition: .18s ease
    }

    .product-pagination .page-link-ui:hover {
        border-color: var(--primary);
        color: var(--primary)
    }

    .product-pagination .page-link-ui.active {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff
    }

    .product-pagination .page-link-ui.disabled {
        opacity: .45;
        pointer-events: none
    }

    @media(max-width:991.98px) {
        .bulk-add-row {
            grid-template-columns: 1fr 1fr
        }

        .bulk-remove-wrap {
            grid-column: 1/-1
        }

        .bulk-remove-btn {
            width: 100%
        }
    }

    @media(max-width:767.98px) {
        .desktop-table {
            display: none
        }

        .mobile-products {
            display: grid;
            gap: 12px
        }

        .module-page .page-head h1 {
            font-size: 24px
        }

        .bulk-add-row {
            grid-template-columns: 1fr
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
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Manage Products</h1>
                            <p class="text-muted-custom mb-0">Simple product master with images, stock visibility and
                                permanent removal history.</p>
                        </div>
                        <?php if ($canCreate): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="add-product.php" class="btn btn-primary rounded-pill px-4 fw-bold"><i
                                    data-lucide="plus" style="width:16px"></i> Add Product</a>
                            <button type="button" class="btn btn-outline-primary rounded-pill px-4 fw-bold"
                                data-bs-toggle="modal" data-bs-target="#bulkAddModal"><i data-lucide="list-plus"
                                    style="width:16px"></i> Bulk Add</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000">
                    <div id="pageToast" class="toast toast-ui <?= ps_e($messageType) ?>" role="alert"
                        aria-live="assertive" aria-atomic="true" data-bs-delay="4200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title"><?= ps_e($toastTitle) ?></div>
                                <div class="toast-message"><?= ps_e($message) ?></div>
                            </div>
                            <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-primary-subtle text-primary"><i data-lucide="boxes"></i></div>
                            <div>
                                <div class="kpi-label">Total</div>
                                <p class="kpi-value"><?= $summary['total'] ?></p>
                                <p class="kpi-sub">all products</p>
                            </div>
                        </article>
                    </div>
                    <div class="col-6 col-lg">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-success-subtle text-success"><i data-lucide="package-check"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Active</div>
                                <p class="kpi-value"><?= $summary['active'] ?></p>
                                <p class="kpi-sub">available products</p>
                            </div>
                        </article>
                    </div>
                    <div class="col-6 col-lg">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-warning-subtle text-warning"><i data-lucide="triangle-alert"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Low Stock</div>
                                <p class="kpi-value"><?= $summary['low'] ?></p>
                                <p class="kpi-sub">needs attention</p>
                            </div>
                        </article>
                    </div>
                    <div class="col-6 col-lg">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-danger-subtle text-danger"><i data-lucide="package-x"></i></div>
                            <div>
                                <div class="kpi-label">Negative</div>
                                <p class="kpi-value"><?= $summary['negative'] ?></p>
                                <p class="kpi-sub">short quantity</p>
                            </div>
                        </article>
                    </div>
                    <div class="col-6 col-lg">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-secondary-subtle text-secondary"><i data-lucide="archive"></i></div>
                            <div>
                                <div class="kpi-label">Removed</div>
                                <p class="kpi-value"><?= $summary['removed'] ?></p>
                                <p class="kpi-sub">history preserved</p>
                            </div>
                        </article>
                    </div>
                </div>

                <form method="get" class="card-ui p-3 mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-5"><label class="form-label fw-bold small">Search
                                Product</label><input class="form-control" name="search" value="<?= ps_e($search) ?>"
                                placeholder="Search by product name"></div>
                        <div class="col-6 col-md-2"><label class="form-label fw-bold small">Products</label><select
                                class="form-select" name="view">
                                <option value="active" <?= $view==='active'?'selected':'' ?>>Active</option>
                                <option value="removed" <?= $view==='removed'?'selected':'' ?>>Removed</option>
                                <option value="all" <?= $view==='all'?'selected':'' ?>>All</option>
                            </select></div>
                        <div class="col-6 col-md-3"><label class="form-label fw-bold small">Stock Status</label><select
                                class="form-select" name="stock">
                                <option value="all">All</option>
                                <option value="in" <?= $stockFilter==='in'?'selected':'' ?>>In Stock</option>
                                <option value="low" <?= $stockFilter==='low'?'selected':'' ?>>Low Stock</option>
                                <option value="out" <?= $stockFilter==='out'?'selected':'' ?>>Out of Stock</option>
                                <option value="negative" <?= $stockFilter==='negative'?'selected':'' ?>>Negative Stock
                                </option>
                            </select></div>
                        <div class="col-12 col-md-2 d-flex gap-2"><button
                                class="btn btn-primary fw-bold w-100">Filter</button><a href="manage-products.php"
                                class="btn btn-outline-secondary fw-bold">Reset</a></div>
                    </div>
                </form>

                <div class="card-ui overflow-hidden">
                    <div class="desktop-table table-responsive">
                        <table class="table-ui mb-0">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Price</th>
                                    <th>On Hand</th>
                                    <th>Reserved</th>
                                    <th>Available</th>
                                    <th>Status</th>
                                    <th>Images</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted-custom py-5">No products found.</td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach ($rows as $row):
                            $status = ps_stock_status((float)$row['on_hand_stock'], (float)$row['reserved_stock'], (float)$row['minimum_stock'], (int)$row['low_stock_alert']);
                            $isRemoved = (int)($row['is_removed'] ?? 0) === 1;
                        ?>
                                <tr class="<?= $isRemoved ? 'removed-row' : '' ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if (!empty($row['thumbnail_image'])): ?><img
                                                src="<?= ps_e($row['thumbnail_image']) ?>" class="product-thumb"
                                                alt=""><?php else: ?><div class="placeholder-thumb"><i
                                                    data-lucide="image"></i></div><?php endif; ?>
                                            <div>
                                                <div class="fw-bold"><?= ps_e($row['product_name']) ?></div>
                                                <?php if ($isRemoved): ?><small
                                                    class="text-danger fw-bold">Removed</small><?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= (float)$row['default_price'] > 0 ? ps_money($row['default_price']) : '<span class="text-muted">Optional</span>' ?>
                                    </td>
                                    <td class="fw-bold"><?= ps_e(ps_qty($row['on_hand_stock'])) ?></td>
                                    <td><?= ps_e(ps_qty($row['reserved_stock'])) ?></td>
                                    <td
                                        class="<?= (float)$row['available_stock'] < 0 ? 'text-danger fw-bold' : 'fw-bold' ?>">
                                        <?= ps_e(ps_qty($row['available_stock'])) ?></td>
                                    <td><?= $isRemoved ? '<span class="stock-pill secondary">Removed</span>' : '<span class="stock-pill '.ps_e($status['class']).'">'.ps_e($status['label']).'</span>' ?>
                                    </td>
                                    <td><?= (int)$row['secondary_image_count'] ?> secondary</td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php if (!$isRemoved && $canEdit): ?><a
                                                class="btn btn-sm btn-outline-primary fw-bold"
                                                href="edit-product.php?id=<?= (int)$row['id'] ?>">Edit</a><?php endif; ?>
                                            <a class="btn btn-sm btn-outline-secondary fw-bold"
                                                href="stock-history.php?product_id=<?= (int)$row['id'] ?>">Stock
                                                History</a>
                                            <?php if (!$isRemoved && $canDelete): ?>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-danger fw-bold js-remove-product"
                                                data-bs-toggle="modal" data-bs-target="#removeModal"
                                                data-id="<?= (int)$row['id'] ?>"
                                                data-name="<?= ps_e($row['product_name']) ?>">Remove</button>
                                            <?php elseif ($isRemoved && $canEdit): ?>
                                            <form method="post" action="api/products.php" class="d-inline"
                                                onsubmit="return confirm('Restore this product?')"><input type="hidden"
                                                    name="csrf_token" value="<?= ps_e($csrfToken) ?>"><input
                                                    type="hidden" name="action" value="restore"><input type="hidden"
                                                    name="product_id" value="<?= (int)$row['id'] ?>"><button
                                                    class="btn btn-sm btn-outline-success fw-bold">Restore</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-products p-3">
                        <?php foreach ($rows as $row):
                        $status = ps_stock_status((float)$row['on_hand_stock'], (float)$row['reserved_stock'], (float)$row['minimum_stock'], (int)$row['low_stock_alert']);
                        $isRemoved = (int)($row['is_removed'] ?? 0) === 1;
                    ?>
                        <article class="mobile-project-card <?= $isRemoved ? 'removed-row' : '' ?>">
                            <div class="d-flex gap-3 align-items-center mb-3">
                                <?php if (!empty($row['thumbnail_image'])): ?><img
                                    src="<?= ps_e($row['thumbnail_image']) ?>" class="product-thumb"
                                    alt=""><?php else: ?><div class="placeholder-thumb"><i data-lucide="image"></i>
                                </div><?php endif; ?>
                                <div class="flex-grow-1">
                                    <div class="fw-bold"><?= ps_e($row['product_name']) ?></div>
                                    <small><?= (float)$row['default_price']>0?ps_money($row['default_price']):'Price optional' ?></small>
                                </div>
                                <?= $isRemoved ? '<span class="stock-pill secondary">Removed</span>' : '<span class="stock-pill '.ps_e($status['class']).'">'.ps_e($status['label']).'</span>' ?>
                            </div>
                            <div class="mobile-field"><span>On
                                    Hand</span><span><?= ps_e(ps_qty($row['on_hand_stock'])) ?></span></div>
                            <div class="mobile-field">
                                <span>Reserved</span><span><?= ps_e(ps_qty($row['reserved_stock'])) ?></span>
                            </div>
                            <div class="mobile-field">
                                <span>Available</span><span><?= ps_e(ps_qty($row['available_stock'])) ?></span>
                            </div>
                            <div class="mt-3 d-flex flex-wrap gap-2">
                                <?php if (!$isRemoved && $canEdit): ?><a class="btn btn-sm btn-outline-primary fw-bold"
                                    href="edit-product.php?id=<?= (int)$row['id'] ?>">Edit</a><?php endif; ?>
                                <a class="btn btn-sm btn-outline-secondary fw-bold"
                                    href="stock-history.php?product_id=<?= (int)$row['id'] ?>">Stock</a>
                                <?php if (!$isRemoved && $canDelete): ?><button type="button"
                                    class="btn btn-sm btn-outline-danger fw-bold js-remove-product"
                                    data-bs-toggle="modal" data-bs-target="#removeModal"
                                    data-id="<?= (int)$row['id'] ?>"
                                    data-name="<?= ps_e($row['product_name']) ?>">Remove</button><?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalRows > 0): ?>
                    <div
                        class="pagination-wrap d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                        <div class="pagination-summary">
                            Showing <?= (int)$showingFrom ?> to <?= (int)$showingTo ?> of <?= (int)$totalRows ?>
                            products
                        </div>

                        <?php if ($totalPages > 1): ?>
                        <nav aria-label="Product pagination">
                            <ul class="product-pagination">
                                <li>
                                    <a class="page-link-ui <?= $page <= 1 ? 'disabled' : '' ?>"
                                        href="<?= ps_e(mp_page_url($page - 1, $search, $view, $stockFilter)) ?>"
                                        aria-label="Previous">&lsaquo;</a>
                                </li>

                                <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);

                            if ($startPage > 1): ?>
                                <li><a class="page-link-ui"
                                        href="<?= ps_e(mp_page_url(1, $search, $view, $stockFilter)) ?>">1</a></li>
                                <?php if ($startPage > 2): ?><li><span class="page-link-ui disabled">...</span></li>
                                <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <li>
                                    <a class="page-link-ui <?= $i === $page ? 'active' : '' ?>"
                                        href="<?= ps_e(mp_page_url($i, $search, $view, $stockFilter)) ?>"><?= $i ?></a>
                                </li>
                                <?php endfor; ?>

                                <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?><li><span
                                        class="page-link-ui disabled">...</span></li><?php endif; ?>
                                <li><a class="page-link-ui"
                                        href="<?= ps_e(mp_page_url($totalPages, $search, $view, $stockFilter)) ?>"><?= $totalPages ?></a>
                                </li>
                                <?php endif; ?>

                                <li>
                                    <a class="page-link-ui <?= $page >= $totalPages ? 'disabled' : '' ?>"
                                        href="<?= ps_e(mp_page_url($page + 1, $search, $view, $stockFilter)) ?>"
                                        aria-label="Next">&rsaquo;</a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <div class="modal fade" id="bulkAddModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-fullscreen-lg-down">
            <form method="post" action="api/products.php" enctype="multipart/form-data" class="modal-content"
                id="bulkAddForm">
                <input type="hidden" name="csrf_token" value="<?= ps_e($csrfToken) ?>">
                <input type="hidden" name="action" value="bulk_add">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold">Bulk Add Products</h5>
                        <small class="text-muted-custom">Add multiple products quickly. Product price is optional;
                            thumbnail is required.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="bulk-note mb-3 small text-muted-custom">
                        Secondary images are intentionally not included here. They can be added later from Edit Product
                        so Bulk Add stays simple.
                    </div>
                    <div id="bulkRows"></div>
                    <button type="button" class="btn btn-outline-primary fw-bold" id="addBulkRowBtn">
                        <i data-lucide="plus" style="width:16px"></i> Add Another Row
                    </button>
                </div>
                <div class="modal-footer d-flex justify-content-between align-items-center gap-2">
                    <div class="small fw-bold">Products Entered: <span id="bulkEnteredCount">0</span></div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary fw-bold"
                            data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary fw-bold px-4">Save All Products</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="removeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="post" action="api/products.php" class="modal-content" id="removeProductForm">
                <input type="hidden" name="csrf_token" value="<?= ps_e($csrfToken) ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="product_id" id="removeProductId">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold">Remove Product</h5><small class="text-muted-custom">The product
                            record and all histories will be preserved.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Product: <strong id="removeProductName"></strong></p>
                    <label class="form-label fw-bold">Removal Description *</label>
                    <textarea class="form-control" name="removal_reason" id="removalReason" rows="4" required
                        minlength="3"
                        placeholder="Example: Product discontinued / duplicate product / no longer sold"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary fw-bold"
                        data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-bold">Remove Product</button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {

        function showToast(message, type = 'success', title = '') {
            if (!message) return;

            const oldToastWrap = document.getElementById('dynamicActionToastWrap');
            if (oldToastWrap) oldToastWrap.remove();

            const toastTitle = title || (type === 'danger' ? 'Failed' : (type === 'warning' ? 'Warning' :
                'Success'));
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


        // ------------------------------------------------------------
        // Bulk Add modal
        // ------------------------------------------------------------
        const bulkModalEl = document.getElementById('bulkAddModal');
        const bulkForm = document.getElementById('bulkAddForm');
        const bulkRows = document.getElementById('bulkRows');
        const addBulkRowBtn = document.getElementById('addBulkRowBtn');
        const bulkEnteredCount = document.getElementById('bulkEnteredCount');
        let bulkDirty = false;
        let bulkAllowClose = false;
        let bulkRowIndex = 0;

        function bulkRowHtml(index) {
            return `
            <div class="bulk-add-row" data-bulk-row>
                <div>
                    <div class="bulk-row-no">PRODUCT ${index + 1}</div>
                    <label>Product Name *</label>
                    <input type="text" class="form-control bulk-name" name="bulk_product_name[]" maxlength="200" placeholder="Enter product name" required>
                </div>
                <div>
                    <label>Product Price <span class="text-muted fw-normal">(Optional)</span></label>
                    <input type="text" inputmode="decimal" class="form-control bulk-price" name="bulk_default_price[]" placeholder="Example: 25.00">
                </div>
                <div>
                    <label>Thumbnail Image *</label>
                    <input type="file" class="form-control bulk-thumb" name="bulk_thumbnail_image[]" accept="image/jpeg,image/png,image/webp,image/gif" required>
                </div>
                <div class="bulk-remove-wrap">
                    <button type="button" class="btn btn-outline-danger bulk-remove-btn" title="Remove row"><i data-lucide="trash-2" style="width:16px"></i></button>
                </div>
            </div>`;
        }

        function refreshBulkRows() {
            const rows = [...bulkRows.querySelectorAll('[data-bulk-row]')];
            rows.forEach((row, index) => {
                const no = row.querySelector('.bulk-row-no');
                if (no) no.textContent = 'PRODUCT ' + (index + 1);
                const removeBtn = row.querySelector('.bulk-remove-btn');
                if (removeBtn) removeBtn.disabled = rows.length <= 1;
            });
            const entered = rows.filter(row => (row.querySelector('.bulk-name')?.value || '').trim() !== '')
                .length;
            bulkEnteredCount.textContent = entered;
            if (window.lucide) window.lucide.createIcons();
        }

        function addBulkRow(markDirty = true) {
            if (bulkRows.querySelectorAll('[data-bulk-row]').length >= 50) {
                showToast('Maximum 50 products can be added at one time.', 'warning', 'Bulk Add Limit');
                return;
            }
            bulkRows.insertAdjacentHTML('beforeend', bulkRowHtml(bulkRowIndex++));
            if (markDirty) bulkDirty = true;
            refreshBulkRows();
            const lastName = bulkRows.querySelector('[data-bulk-row]:last-child .bulk-name');
            lastName?.focus();
        }

        addBulkRowBtn?.addEventListener('click', () => addBulkRow(true));
        bulkRows?.addEventListener('input', () => {
            bulkDirty = true;
            refreshBulkRows();
        });
        bulkRows?.addEventListener('change', () => {
            bulkDirty = true;
            refreshBulkRows();
        });
        bulkRows?.addEventListener('click', function(event) {
            const btn = event.target.closest('.bulk-remove-btn');
            if (!btn) return;
            const rows = bulkRows.querySelectorAll('[data-bulk-row]');
            if (rows.length <= 1) return;
            btn.closest('[data-bulk-row]')?.remove();
            bulkDirty = true;
            refreshBulkRows();
        });

        bulkForm?.addEventListener('submit', function(event) {
            const rows = [...bulkRows.querySelectorAll('[data-bulk-row]')];
            if (!rows.length) {
                event.preventDefault();
                showToast('Add at least one product.', 'warning', 'Bulk Add');
                return;
            }

            const seen = new Set();
            for (const row of rows) {
                const name = (row.querySelector('.bulk-name')?.value || '').trim();
                const price = (row.querySelector('.bulk-price')?.value || '').trim();
                const fileCount = row.querySelector('.bulk-thumb')?.files?.length || 0;
                if (!name) {
                    event.preventDefault();
                    showToast('Product Name is required for every row.', 'warning', 'Required Field');
                    return;
                }
                const key = name.toLowerCase();
                if (seen.has(key)) {
                    event.preventDefault();
                    showToast('Duplicate product name in Bulk Add: ' + name, 'warning',
                        'Duplicate Product');
                    return;
                }
                seen.add(key);
                if (price !== '' && (Number.isNaN(Number(price)) || Number(price) < 0)) {
                    event.preventDefault();
                    showToast('Enter a valid optional Product Price for ' + name + '.', 'warning',
                        'Invalid Price');
                    return;
                }
                if (fileCount <= 0) {
                    event.preventDefault();
                    showToast('Thumbnail Image is required for ' + name + '.', 'warning',
                        'Thumbnail Required');
                    return;
                }
            }
            bulkAllowClose = true;
        });

        bulkModalEl?.addEventListener('show.bs.modal', function() {
            if (bulkRows.querySelectorAll('[data-bulk-row]').length === 0) addBulkRow(false);
            bulkDirty = false;
            bulkAllowClose = false;
            refreshBulkRows();
        });

        bulkModalEl?.addEventListener('hide.bs.modal', function(event) {
            if (!bulkDirty || bulkAllowClose) return;
            event.preventDefault();
            if (confirm('Bulk product details are not saved. Discard the entered details and close?')) {
                bulkAllowClose = true;
                bootstrap.Modal.getOrCreateInstance(bulkModalEl).hide();
            }
        });

        bulkModalEl?.addEventListener('hidden.bs.modal', function() {
            bulkRows.innerHTML = '';
            bulkRowIndex = 0;
            bulkDirty = false;
            bulkAllowClose = false;
            addBulkRow(false);
        });

        if (bulkRows && bulkRows.children.length === 0) addBulkRow(false);

        // ------------------------------------------------------------
        // Product removal modal
        // ------------------------------------------------------------
        const modalEl = document.getElementById('removeModal');
        const form = document.getElementById('removeProductForm');
        const reason = document.getElementById('removalReason');
        let modalDirty = false;
        let allowModalClose = false;

        document.querySelectorAll('.js-remove-product').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('removeProductId').value = this.dataset.id || '';
                document.getElementById('removeProductName').textContent = this.dataset.name ||
                    '';
                reason.value = '';
                modalDirty = false;
                allowModalClose = false;
            });
        });

        reason?.addEventListener('input', () => {
            modalDirty = true;
        });
        form?.addEventListener('submit', function() {
            allowModalClose = true;
        });

        modalEl?.addEventListener('hide.bs.modal', function(event) {
            if (!modalDirty || allowModalClose) return;
            event.preventDefault();
            if (confirm('Removal description is not saved. Discard it and close?')) {
                allowModalClose = true;
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }
        });

        modalEl?.addEventListener('hidden.bs.modal', function() {
            modalDirty = false;
            allowModalClose = false;
        });
    });
    </script>
</body>

</html>