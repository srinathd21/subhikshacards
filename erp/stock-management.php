<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product-stock-helper.php';

require_permission($conn, 'can_view', 'stock-management.php');
ps_require_module($conn);

$canCreate = can_create($conn, 'stock-management.php');
$csrfToken = ps_csrf_token();

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
if (!in_array($statusFilter, ['all', 'in', 'low', 'out', 'negative'], true)) {
    $statusFilter = 'all';
}

$availableExpr = '(COALESCE(ps.on_hand_stock,0)-COALESCE(ps.reserved_stock,0))';
$where = ["COALESCE(p.is_removed,0)=0", "p.is_active=1"];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = 'p.product_name LIKE ?';
    $params[] = '%' . $search . '%';
    $types .= 's';
}

if ($statusFilter === 'negative') {
    $where[] = "$availableExpr < 0";
} elseif ($statusFilter === 'out') {
    $where[] = "$availableExpr = 0";
} elseif ($statusFilter === 'low') {
    $where[] = "$availableExpr > 0 AND COALESCE(ps.low_stock_alert,0)=1 AND COALESCE(ps.minimum_stock,0)>0 AND $availableExpr <= COALESCE(ps.minimum_stock,0)";
} elseif ($statusFilter === 'in') {
    $where[] = "$availableExpr > 0 AND NOT(COALESCE(ps.low_stock_alert,0)=1 AND COALESCE(ps.minimum_stock,0)>0 AND $availableExpr <= COALESCE(ps.minimum_stock,0))";
}

$sql = "
    SELECT
        p.id,
        p.product_name,
        p.thumbnail_image,
        p.default_price,
        COALESCE(ps.on_hand_stock,0) AS on_hand_stock,
        COALESCE(ps.reserved_stock,0) AS reserved_stock,
        COALESCE(ps.minimum_stock,0) AS minimum_stock,
        COALESCE(ps.low_stock_alert,0) AS low_stock_alert,
        $availableExpr AS available_stock,
        COALESCE(tx.added_qty,0) AS added_qty,
        COALESCE(tx.reduced_qty,0) AS reduced_qty,
        (COALESCE(ps.on_hand_stock,0) * COALESCE(p.default_price,0)) AS current_value
    FROM products p
    LEFT JOIN product_stock ps ON ps.product_id = p.id
    LEFT JOIN (
        SELECT
            product_id,
            SUM(CASE WHEN transaction_type='inward' AND quantity > 0 THEN quantity ELSE 0 END) AS added_qty,
            SUM(CASE WHEN transaction_type='manual_reduce' THEN ABS(quantity) ELSE 0 END) AS reduced_qty
        FROM stock_transactions
        GROUP BY product_id
    ) tx ON tx.product_id = p.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.product_name ASC
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

$allProducts = [];
$res = $conn->query("
    SELECT
        p.id,
        p.product_name,
        p.default_price,
        COALESCE(ps.on_hand_stock,0) AS on_hand_stock,
        COALESCE(ps.reserved_stock,0) AS reserved_stock,
        COALESCE(ps.minimum_stock,0) AS minimum_stock,
        COALESCE(ps.low_stock_alert,0) AS low_stock_alert,
        (COALESCE(ps.on_hand_stock,0)-COALESCE(ps.reserved_stock,0)) AS available_stock
    FROM products p
    LEFT JOIN product_stock ps ON ps.product_id = p.id
    WHERE p.is_active=1 AND COALESCE(p.is_removed,0)=0
    ORDER BY p.product_name ASC
");
while ($row = $res->fetch_assoc()) {
    $allProducts[] = $row;
}
$res->free();

$summary = [
    'items' => 0,
    'out' => 0,
    'low' => 0,
    'negative' => 0,
    'added' => 0.0,
    'reserved' => 0.0,
    'on_hand' => 0.0,
    'current_value' => 0.0,
];

$res = $conn->query("
    SELECT
        COUNT(*) AS stock_items,
        SUM(CASE WHEN (COALESCE(ps.on_hand_stock,0)-COALESCE(ps.reserved_stock,0)) = 0 THEN 1 ELSE 0 END) AS out_count,
        SUM(CASE WHEN (COALESCE(ps.on_hand_stock,0)-COALESCE(ps.reserved_stock,0)) < 0 THEN 1 ELSE 0 END) AS negative_count,
        SUM(CASE WHEN (COALESCE(ps.on_hand_stock,0)-COALESCE(ps.reserved_stock,0)) > 0
                  AND COALESCE(ps.low_stock_alert,0)=1
                  AND COALESCE(ps.minimum_stock,0)>0
                  AND (COALESCE(ps.on_hand_stock,0)-COALESCE(ps.reserved_stock,0)) <= COALESCE(ps.minimum_stock,0)
                 THEN 1 ELSE 0 END) AS low_count,
        COALESCE(SUM(COALESCE(ps.on_hand_stock,0)),0) AS on_hand_qty,
        COALESCE(SUM(COALESCE(ps.reserved_stock,0)),0) AS reserved_qty,
        COALESCE(SUM(COALESCE(ps.on_hand_stock,0) * COALESCE(p.default_price,0)),0) AS current_value
    FROM products p
    LEFT JOIN product_stock ps ON ps.product_id = p.id
    WHERE p.is_active=1 AND COALESCE(p.is_removed,0)=0
");
if ($s = $res->fetch_assoc()) {
    $summary['items'] = (int)$s['stock_items'];
    $summary['out'] = (int)$s['out_count'];
    $summary['low'] = (int)$s['low_count'];
    $summary['negative'] = (int)$s['negative_count'];
    $summary['on_hand'] = (float)$s['on_hand_qty'];
    $summary['reserved'] = (float)$s['reserved_qty'];
    $summary['current_value'] = (float)$s['current_value'];
}
$res->free();

$res = $conn->query("SELECT COALESCE(SUM(quantity),0) AS total_added FROM stock_transactions WHERE transaction_type='inward' AND quantity>0");
if ($s = $res->fetch_assoc()) {
    $summary['added'] = (float)$s['total_added'];
}
$res->free();

$message = '';
$messageType = 'success';
$toastTitle = 'Success';
if (!empty($_GET['error'])) {
    $message = trim((string)$_GET['error']);
    $messageType = 'danger';
    $toastTitle = 'Unable to Continue';
} elseif (($_GET['saved'] ?? '') === 'inward' || isset($_GET['inward_saved'])) {
    $message = 'Stock added successfully.';
} elseif (($_GET['saved'] ?? '') === 'reduce' || isset($_GET['reduce_saved'])) {
    $message = 'Stock reduced successfully.';
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Stock Management - Subhiksha Cards</title>
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

    .module-page .page-head-card {
        padding: 24px 28px;
        margin-bottom: 18px;
    }

    .module-page .filter-card {
        padding: 18px 20px;
    }

    .module-page .page-head-card h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main);
    }

    .product-thumb {
        width: 42px;
        height: 42px;
        object-fit: cover;
        border: 1px solid var(--border-soft);
        background: #fff
    }

    .stock-status-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 5px 9px;
        font-size: 11px;
        font-weight: 900;
        border-radius: 8px;
        white-space: nowrap
    }

    .stock-status-pill.success {
        background: #dcfce7;
        color: #047857
    }

    .stock-status-pill.warning {
        background: #fff7ed;
        color: #c2410c
    }

    .stock-status-pill.danger {
        background: #fee2e2;
        color: #dc2626
    }

    .stock-status-pill.secondary {
        background: #f1f5f9;
        color: #475569
    }

    .shortage-note {
        font-size: 11px;
        font-weight: 800;
        color: #dc2626
    }

    .bulk-table-wrap {
        overflow: auto;
        border: 1px solid var(--border-soft)
    }

    .bulk-stock-table {
        border-collapse: collapse;
        min-width: 850px
    }

    .bulk-stock-table th,
    .bulk-stock-table td {
        padding: 11px 12px;
        border-bottom: 1px solid var(--border-soft);
        vertical-align: middle
    }

    .bulk-stock-table th {
        background: var(--soft-bg);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .03em;
        white-space: nowrap
    }

    .bulk-stock-table tr:last-child td {
        border-bottom: 0
    }

    .modal-stock-info {
        background: var(--soft-bg);
        border: 1px solid var(--border-soft);
        padding: 12px
    }

    .modal-stock-info .value {
        font-weight: 900;
        font-size: 18px
    }

    .modal-stock-info .label {
        font-size: 11px;
        color: var(--text-muted);
        font-weight: 800;
        text-transform: uppercase
    }

    .summary-line {
        display: flex;
        gap: 18px;
        flex-wrap: wrap;
        padding: 12px 14px;
        border: 1px solid var(--border-soft);
        background: var(--soft-bg);
        font-size: 13px
    }

    .summary-line strong {
        font-size: 16px
    }

    .qty-error {
        font-size: 11px;
        color: #dc2626;
        font-weight: 800;
        margin-top: 4px;
        display: none
    }

    .mobile-stock {
        display: none
    }

    .stock-action-buttons .btn {
        white-space: nowrap
    }

    @media(max-width:767.98px) {
        .desktop-table {
            display: none !important
        }

        .mobile-stock {
            display: grid;
            gap: 12px;
            padding: 12px
        }

        .module-page .page-head-card h1 {
            font-size: 23px
        }

        .module-page .page-head-card,
        .module-page .filter-card {
            padding: 16px
        }

        .stock-action-buttons {
            width: 100%
        }

        .stock-action-buttons .btn {
            flex: 1 1 160px
        }

        .kpi-card {
            min-height: 100%
        }
    }
    </style>

    <style id="compact-stock-ui-overrides">
    /* Compact 100% zoom UI - visual sizing only */
    .module-page {
        font-size: 12.5px;
    }

    .module-page .page-head-card {
        padding: 15px 18px !important;
        margin-bottom: 12px !important;
        border-radius: 16px !important;
    }

    .module-page .page-head-card h1 {
        font-size: 23px !important;
        font-weight: 800 !important;
        line-height: 1.15 !important;
        margin-bottom: 3px !important;
    }

    .module-page .page-head-card p {
        font-size: 11.5px !important;
        font-weight: 500 !important;
        line-height: 1.35 !important;
    }

    .module-page .stock-action-buttons {
        gap: 6px !important;
    }

    .module-page .stock-action-buttons .btn {
        font-size: 11.5px !important;
        font-weight: 700 !important;
        padding: 7px 10px !important;
        min-height: 34px !important;
        border-radius: 9px !important;
    }

    .module-page .stock-action-buttons .btn i {
        width: 13px !important;
        height: 13px !important;
    }

    .module-page .row.g-3.mb-3 {
        --bs-gutter-x: .75rem;
        --bs-gutter-y: .75rem;
        margin-bottom: 12px !important;
    }

    .module-page .kpi-card {
        min-height: 88px !important;
        padding: 12px 14px !important;
        gap: 10px !important;
        border-radius: 15px !important;
    }

    .module-page .kpi-icon {
        width: 40px !important;
        height: 40px !important;
        border-radius: 12px !important;
        flex: 0 0 40px !important;
    }

    .module-page .kpi-icon svg {
        width: 18px !important;
        height: 18px !important;
    }

    .module-page .kpi-label {
        font-size: 10px !important;
        font-weight: 700 !important;
        letter-spacing: .02em !important;
    }

    .module-page .kpi-value {
        font-size: 18px !important;
        font-weight: 800 !important;
        line-height: 1.1 !important;
        margin: 2px 0 !important;
    }

    .module-page .kpi-sub {
        font-size: 10.5px !important;
        font-weight: 500 !important;
        line-height: 1.25 !important;
        margin: 2px 0 0 !important;
    }

    .module-page .filter-card {
        padding: 12px 14px !important;
        margin-bottom: 12px !important;
        border-radius: 15px !important;
    }

    .module-page .filter-card .form-label {
        font-size: 10.5px !important;
        font-weight: 700 !important;
        margin-bottom: 4px !important;
    }

    .module-page .filter-card .form-control,
    .module-page .filter-card .form-select {
        min-height: 36px !important;
        height: 36px !important;
        font-size: 12px !important;
        padding: 6px 10px !important;
        border-radius: 9px !important;
    }

    .module-page .filter-card .btn {
        min-height: 36px !important;
        font-size: 11.5px !important;
        font-weight: 700 !important;
        padding: 6px 10px !important;
        border-radius: 9px !important;
    }

    .module-page section.card-ui.overflow-hidden {
        border-radius: 16px !important;
    }

    .module-page section.card-ui.overflow-hidden>.border-bottom {
        padding: 12px 14px !important;
    }

    .module-page section.card-ui.overflow-hidden>.border-bottom h2 {
        font-size: 14px !important;
        font-weight: 800 !important;
        margin-bottom: 2px !important;
    }

    .module-page section.card-ui.overflow-hidden>.border-bottom p {
        font-size: 10.5px !important;
        font-weight: 500 !important;
        line-height: 1.3 !important;
    }

    .module-page .desktop-table {
        padding-left: 12px !important;
        padding-right: 12px !important;
        padding-bottom: 10px !important;
    }

    .module-page .table-ui {
        font-size: 11.5px !important;
    }

    .module-page .table-ui th {
        font-size: 9.5px !important;
        font-weight: 750 !important;
        padding: 7px 8px !important;
        letter-spacing: .02em !important;
        white-space: nowrap !important;
    }

    .module-page .table-ui td {
        font-size: 11.5px !important;
        padding: 7px 8px !important;
        line-height: 1.25 !important;
        vertical-align: middle !important;
    }

    .module-page .table-ui td .fw-bold {
        font-weight: 700 !important;
    }

    .module-page .table-ui tbody tr {
        height: auto !important;
    }

    .module-page .product-thumb {
        width: 30px !important;
        height: 30px !important;
        border-radius: 6px !important;
    }

    .module-page .shortage-note {
        font-size: 9.5px !important;
        font-weight: 650 !important;
        margin-top: 2px !important;
    }

    .module-page .stock-status-pill {
        padding: 4px 7px !important;
        font-size: 9.5px !important;
        font-weight: 700 !important;
        border-radius: 7px !important;
    }

    .module-page .table-ui .btn.btn-sm {
        font-size: 10px !important;
        font-weight: 700 !important;
        line-height: 1.1 !important;
        padding: 5px 7px !important;
        min-height: 27px !important;
        border-radius: 7px !important;
    }

    .module-page .table-ui td:last-child .d-flex {
        gap: 4px !important;
        flex-wrap: nowrap !important;
    }

    .module-page .modal-content {
        border-radius: 16px !important;
    }

    .module-page .modal-header {
        padding: 13px 16px !important;
    }

    .module-page .modal-body {
        padding: 14px 16px !important;
    }

    .module-page .modal-footer {
        padding: 11px 16px !important;
    }

    .module-page .modal-title {
        font-size: 15px !important;
        font-weight: 800 !important;
    }

    .module-page .modal-header p {
        font-size: 10.5px !important;
    }

    .module-page .modal-body .form-label {
        font-size: 10.5px !important;
        font-weight: 700 !important;
        margin-bottom: 4px !important;
    }

    .module-page .modal-body .form-control,
    .module-page .modal-body .form-select {
        min-height: 36px !important;
        font-size: 12px !important;
        padding: 6px 9px !important;
        border-radius: 9px !important;
    }

    .module-page .modal-stock-info {
        padding: 9px !important;
        border-radius: 10px !important;
    }

    .module-page .modal-stock-info .label {
        font-size: 9.5px !important;
        font-weight: 700 !important;
    }

    .module-page .modal-stock-info .value {
        font-size: 15px !important;
        font-weight: 800 !important;
    }

    .module-page .bulk-stock-table th,
    .module-page .bulk-stock-table td {
        padding: 7px 8px !important;
        font-size: 11px !important;
    }

    .module-page .bulk-stock-table th {
        font-size: 9.5px !important;
        font-weight: 750 !important;
    }

    .module-page .summary-line {
        padding: 9px 11px !important;
        gap: 12px !important;
        font-size: 11px !important;
    }

    .module-page .summary-line strong {
        font-size: 13px !important;
        font-weight: 800 !important;
    }

    @media(max-width:767.98px) {
        .module-page .page-head-card {
            padding: 13px 14px !important;
        }

        .module-page .page-head-card h1 {
            font-size: 20px !important;
        }

        .module-page .kpi-card {
            min-height: 78px !important;
            padding: 10px 12px !important;
        }

        .module-page .kpi-value {
            font-size: 17px !important;
        }

        .module-page .mobile-stock {
            gap: 8px !important;
            padding: 8px !important;
        }

        .module-page .mobile-project-card {
            padding: 11px !important;
            border-radius: 13px !important;
            font-size: 11.5px !important;
        }

        .module-page .mobile-field {
            padding: 4px 0 !important;
            font-size: 11px !important;
        }

        .module-page .mobile-project-card .btn {
            font-size: 10px !important;
            padding: 5px 7px !important;
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

                <?php if ($message !== ''): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 12000">
                    <div id="pageToast" class="toast toast-ui <?= ps_e($messageType) ?>" role="alert"
                        aria-live="assertive" aria-atomic="true" data-bs-delay="4200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title"><?= ps_e($toastTitle) ?></div>
                                <div class="toast-message"><?= ps_e($message) ?></div>
                            </div>
                            <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"
                                aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card-ui page-head-card mb-3">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Stock Management</h1>
                            <p class="text-muted-custom mb-0 small">Add or reduce stock from this page. On Hand,
                                Reserved and Available quantities are maintained together.</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2 stock-action-buttons">
                            <?php if ($canCreate): ?>
                            <button type="button" class="btn btn-primary fw-bold px-3" data-bs-toggle="modal"
                                data-bs-target="#singleStockModal">
                                <i data-lucide="plus" style="width:15px;height:15px"></i> Add / Reduce Stock
                            </button>
                            <button type="button" class="btn btn-outline-primary fw-bold px-3" data-bs-toggle="modal"
                                data-bs-target="#bulkStockModal">
                                <i data-lucide="list-plus" style="width:15px;height:15px"></i> Bulk Add / Reduce
                            </button>
                            <?php endif; ?>
                            <a href="stock-history.php" class="btn btn-outline-secondary fw-bold px-3">
                                <i data-lucide="history" style="width:15px;height:15px"></i> Stock History
                            </a>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-primary-subtle text-primary"><i data-lucide="boxes"></i></div>
                            <div>
                                <div class="kpi-label">Stock Items</div>
                                <p class="kpi-value"><?= $summary['items'] ?></p>
                                <p class="kpi-sub"><?= $summary['out'] ?> out, <?= $summary['low'] ?> low,
                                    <?= $summary['negative'] ?> negative</p>
                            </div>
                        </article>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-success-subtle text-success"><i data-lucide="plus-circle"></i></div>
                            <div>
                                <div class="kpi-label">Added Qty</div>
                                <p class="kpi-value"><?= ps_e(ps_qty($summary['added'])) ?></p>
                                <p class="kpi-sub">total stock inward</p>
                            </div>
                        </article>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-info-subtle text-info"><i data-lucide="bookmark-check"></i></div>
                            <div>
                                <div class="kpi-label">Reserved Qty</div>
                                <p class="kpi-value"><?= ps_e(ps_qty($summary['reserved'])) ?></p>
                                <p class="kpi-sub"><?= ps_e(ps_qty($summary['on_hand'])) ?> on hand</p>
                            </div>
                        </article>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-warning-subtle text-warning"><i data-lucide="package-check"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Current Stock Value</div>
                                <p class="kpi-value"><?= ps_e(ps_money($summary['current_value'])) ?></p>
                                <p class="kpi-sub">On Hand × product price</p>
                            </div>
                        </article>
                    </div>
                </div>

                <form class="card-ui filter-card mb-3" method="get">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold small">Search</label>
                            <input type="text" name="search" class="form-control" value="<?= ps_e($search) ?>"
                                placeholder="Search product...">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-bold small">Stock Filter</label>
                            <select name="status" class="form-select">
                                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Stock</option>
                                <option value="in" <?= $statusFilter === 'in' ? 'selected' : '' ?>>In Stock</option>
                                <option value="low" <?= $statusFilter === 'low' ? 'selected' : '' ?>>Low Stock</option>
                                <option value="out" <?= $statusFilter === 'out' ? 'selected' : '' ?>>Out of Stock
                                </option>
                                <option value="negative" <?= $statusFilter === 'negative' ? 'selected' : '' ?>>Negative
                                    Stock</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 d-flex gap-2">
                            <button class="btn btn-primary fw-bold w-100" type="submit">Filter</button>
                            <a href="stock-management.php" class="btn btn-outline-secondary fw-bold">Reset</a>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4 border-bottom" style="border-color:var(--border-soft)!important">
                        <h2 class="fw-bold fs-6 mb-1">Current Stock</h2>
                        <p class="text-muted-custom small mb-0">Added, reduced, On Hand, Reserved, Available and stock
                            value are shown product-wise.</p>
                    </div>

                    <div class="desktop-table table-responsive px-3 px-lg-4 pb-3">
                        <table class="table-ui mb-0">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Price</th>
                                    <th>Added</th>
                                    <th>Reduced</th>
                                    <th>On Hand</th>
                                    <th>Reserved</th>
                                    <th>Available</th>
                                    <th>Current Value</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted-custom py-5">No stock records found.
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach ($rows as $r):
                            $status = ps_stock_status((float)$r['on_hand_stock'], (float)$r['reserved_stock'], (float)$r['minimum_stock'], (int)$r['low_stock_alert']);
                            $short = max(0, -(float)$r['available_stock']);
                        ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if (!empty($r['thumbnail_image'])): ?><img
                                                src="<?= ps_e($r['thumbnail_image']) ?>" class="product-thumb"
                                                alt=""><?php endif; ?>
                                            <div>
                                                <div class="fw-bold"><?= ps_e($r['product_name']) ?></div>
                                                <?php if ($short > 0): ?><div class="shortage-note">
                                                    <?= ps_e(ps_qty($short)) ?> qty required</div><?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= (float)$r['default_price'] > 0 ? ps_e(ps_money($r['default_price'])) : '-' ?>
                                    </td>
                                    <td class="text-success fw-bold"><?= ps_e(ps_qty($r['added_qty'])) ?></td>
                                    <td class="text-danger fw-bold"><?= ps_e(ps_qty($r['reduced_qty'])) ?></td>
                                    <td class="fw-bold"><?= ps_e(ps_qty($r['on_hand_stock'])) ?></td>
                                    <td><?= ps_e(ps_qty($r['reserved_stock'])) ?></td>
                                    <td
                                        class="<?= (float)$r['available_stock'] < 0 ? 'text-danger fw-bold' : 'fw-bold' ?>">
                                        <?= ps_e(ps_qty($r['available_stock'])) ?></td>
                                    <td class="fw-bold"><?= ps_e(ps_money($r['current_value'])) ?></td>
                                    <td><span
                                            class="stock-status-pill <?= ps_e($status['class']) ?>"><?= ps_e($status['label']) ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 flex-wrap">
                                            <?php if ($canCreate): ?>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-success fw-bold js-stock-open"
                                                data-action="inward" data-product-id="<?= (int)$r['id'] ?>">Add</button>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-danger fw-bold js-stock-open"
                                                data-action="reduce"
                                                data-product-id="<?= (int)$r['id'] ?>">Reduce</button>
                                            <?php endif; ?>
                                            <a href="stock-history.php?product_id=<?= (int)$r['id'] ?>"
                                                class="btn btn-sm btn-outline-secondary fw-bold">History</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-stock">
                        <?php foreach ($rows as $r):
                        $status = ps_stock_status((float)$r['on_hand_stock'], (float)$r['reserved_stock'], (float)$r['minimum_stock'], (int)$r['low_stock_alert']);
                    ?>
                        <article class="mobile-project-card">
                            <div class="d-flex justify-content-between gap-2 mb-2">
                                <div class="fw-bold"><?= ps_e($r['product_name']) ?></div><span
                                    class="stock-status-pill <?= ps_e($status['class']) ?>"><?= ps_e($status['label']) ?></span>
                            </div>
                            <div class="mobile-field"><span>On Hand</span><span
                                    class="fw-bold"><?= ps_e(ps_qty($r['on_hand_stock'])) ?></span></div>
                            <div class="mobile-field">
                                <span>Reserved</span><span><?= ps_e(ps_qty($r['reserved_stock'])) ?></span>
                            </div>
                            <div class="mobile-field">
                                <span>Available</span><span><?= ps_e(ps_qty($r['available_stock'])) ?></span>
                            </div>
                            <div class="mobile-field"><span>Added /
                                    Reduced</span><span><?= ps_e(ps_qty($r['added_qty'])) ?> /
                                    <?= ps_e(ps_qty($r['reduced_qty'])) ?></span></div>
                            <div class="mt-3 d-flex gap-2 flex-wrap">
                                <?php if ($canCreate): ?>
                                <button type="button" class="btn btn-sm btn-outline-success fw-bold js-stock-open"
                                    data-action="inward" data-product-id="<?= (int)$r['id'] ?>">Add</button>
                                <button type="button" class="btn btn-sm btn-outline-danger fw-bold js-stock-open"
                                    data-action="reduce" data-product-id="<?= (int)$r['id'] ?>">Reduce</button>
                                <?php endif; ?>
                                <a href="stock-history.php?product_id=<?= (int)$r['id'] ?>"
                                    class="btn btn-sm btn-outline-secondary fw-bold">History</a>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </section>
        </main>
        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <?php if ($canCreate): ?>
    <div class="modal fade" id="singleStockModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form action="api/stock.php" method="post" class="modal-content" id="singleStockForm">
                <input type="hidden" name="csrf_token" value="<?= ps_e($csrfToken) ?>">
                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Add / Reduce Stock</h5>
                        <p class="text-muted-custom small mb-0">Select action, product and quantity, then save.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Stock Action *</label>
                            <select name="action" id="single_action" class="form-select" required>
                                <option value="inward">Add Stock</option>
                                <option value="reduce">Reduce Stock</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Product *</label>
                            <select name="product_id" id="single_product_id" class="form-select" required>
                                <option value="">Select Product</option>
                                <?php foreach ($allProducts as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" data-on-hand="<?= ps_e($p['on_hand_stock']) ?>"
                                    data-reserved="<?= ps_e($p['reserved_stock']) ?>"
                                    data-available="<?= ps_e($p['available_stock']) ?>"
                                    data-price="<?= ps_e($p['default_price']) ?>">
                                    <?= ps_e($p['product_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-4">
                            <div class="modal-stock-info">
                                <div class="label">On Hand</div>
                                <div class="value" id="single_on_hand">0</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="modal-stock-info">
                                <div class="label">Reserved</div>
                                <div class="value" id="single_reserved">0</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="modal-stock-info">
                                <div class="label">Available</div>
                                <div class="value" id="single_available">0</div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Quantity *</label>
                            <input type="number" name="quantity" id="single_quantity" class="form-control" min="0.01"
                                step="0.01" required placeholder="0">
                            <div class="qty-error" id="single_qty_error">Reduce quantity cannot exceed On Hand Stock.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Line Value</label>
                            <input type="text" id="single_line_value" class="form-control" value="₹0.00" readonly>
                        </div>

                        <div class="col-md-6" id="single_reference_wrap">
                            <label class="form-label fw-bold small">Reference</label>
                            <input type="text" name="reference_no" id="single_reference_no" class="form-control"
                                maxlength="150" placeholder="Purchase / supplier / inward reference">
                        </div>
                        <div class="col-md-6 d-none" id="single_reason_wrap">
                            <label class="form-label fw-bold small">Reduction Reason *</label>
                            <select name="reason" id="single_reason" class="form-select">
                                <option value="">Select Reason</option>
                                <option value="damage">Damage</option>
                                <option value="wastage">Wastage</option>
                                <option value="sample">Sample</option>
                                <option value="manual_usage">Manual Usage</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Description *</label>
                            <textarea name="description" id="single_description" class="form-control" rows="3"
                                minlength="3" required
                                placeholder="Enter why this stock is being added or reduced"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary fw-bold"
                        data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4" id="single_save_btn">Save Stock</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="bulkStockModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-fullscreen-lg-down">
            <form action="api/stock.php" method="post" class="modal-content" id="bulkStockForm">
                <input type="hidden" name="csrf_token" value="<?= ps_e($csrfToken) ?>">
                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Bulk Add / Reduce Stock</h5>
                        <p class="text-muted-custom small mb-0">Choose Add or Reduce and enter quantity only for
                            required products.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Stock Action *</label>
                            <select name="action" id="bulk_action" class="form-select" required>
                                <option value="inward">Add Stock</option>
                                <option value="reduce">Reduce Stock</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Search Product</label>
                            <input type="text" id="bulk_product_search" class="form-control"
                                placeholder="Search product...">
                        </div>
                        <div class="col-md-3" id="bulk_reference_wrap">
                            <label class="form-label fw-bold small">Reference</label>
                            <input type="text" name="reference_no" id="bulk_reference_no" class="form-control"
                                maxlength="150" placeholder="Optional reference">
                        </div>
                        <div class="col-md-3 d-none" id="bulk_reason_wrap">
                            <label class="form-label fw-bold small">Reduction Reason *</label>
                            <select name="reason" id="bulk_reason" class="form-select">
                                <option value="">Select Reason</option>
                                <option value="damage">Damage</option>
                                <option value="wastage">Wastage</option>
                                <option value="sample">Sample</option>
                                <option value="manual_usage">Manual Usage</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Description *</label>
                            <textarea name="description" id="bulk_description" class="form-control" rows="2"
                                minlength="3" required
                                placeholder="Enter stock inward / reduction description"></textarea>
                        </div>
                    </div>

                    <div class="bulk-table-wrap">
                        <table class="w-100 bulk-stock-table">
                            <thead>
                                <tr>
                                    <th style="min-width:260px">Product</th>
                                    <th>Price</th>
                                    <th>On Hand</th>
                                    <th>Reserved</th>
                                    <th>Available</th>
                                    <th style="width:150px" id="bulk_qty_heading">Add Qty</th>
                                    <th>Line Value</th>
                                </tr>
                            </thead>
                            <tbody id="bulk_stock_body">
                                <?php foreach ($allProducts as $p): ?>
                                <tr class="bulk-product-row" data-search="<?= ps_e(strtolower($p['product_name'])) ?>"
                                    data-on-hand="<?= ps_e($p['on_hand_stock']) ?>"
                                    data-price="<?= ps_e($p['default_price']) ?>">
                                    <td>
                                        <div class="fw-bold"><?= ps_e($p['product_name']) ?></div>
                                    </td>
                                    <td><?= (float)$p['default_price'] > 0 ? ps_e(ps_money($p['default_price'])) : '-' ?>
                                    </td>
                                    <td class="fw-bold"><?= ps_e(ps_qty($p['on_hand_stock'])) ?></td>
                                    <td><?= ps_e(ps_qty($p['reserved_stock'])) ?></td>
                                    <td class="<?= (float)$p['available_stock'] < 0 ? 'text-danger fw-bold' : '' ?>">
                                        <?= ps_e(ps_qty($p['available_stock'])) ?></td>
                                    <td>
                                        <input type="number" name="qty[<?= (int)$p['id'] ?>]"
                                            class="form-control bulk-qty" min="0" step="0.01" placeholder="0"
                                            data-product="<?= ps_e($p['product_name']) ?>">
                                        <div class="qty-error">Cannot exceed On Hand Stock.</div>
                                    </td>
                                    <td class="fw-bold bulk-line-value">₹0.00</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="summary-line mt-3">
                        <div>Selected Products: <strong id="bulk_selected_count">0</strong></div>
                        <div>Total Qty: <strong id="bulk_total_qty">0</strong></div>
                        <div>Total Value: <strong id="bulk_total_value">₹0.00</strong></div>
                    </div>
                </div>
                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary fw-bold"
                        data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4" id="bulk_save_btn">Save Bulk
                        Stock</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/includes/script.php'; ?>
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
    </script>
    <?php if ($canCreate): ?>
    <script>
    (function() {
        const money = n => '₹' + (Number(n || 0)).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        const qtyText = n => {
            const x = Number(n || 0);
            return Number.isInteger(x) ? String(x) : x.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
        };

        const singleModalEl = document.getElementById('singleStockModal');
        const singleModal = singleModalEl ? bootstrap.Modal.getOrCreateInstance(singleModalEl) : null;
        const singleForm = document.getElementById('singleStockForm');
        const singleAction = document.getElementById('single_action');
        const singleProduct = document.getElementById('single_product_id');
        const singleQty = document.getElementById('single_quantity');
        const singleSave = document.getElementById('single_save_btn');
        const singleReasonWrap = document.getElementById('single_reason_wrap');
        const singleReason = document.getElementById('single_reason');
        const singleRefWrap = document.getElementById('single_reference_wrap');
        const singleRef = document.getElementById('single_reference_no');
        const singleQtyError = document.getElementById('single_qty_error');

        function selectedSingleData() {
            const opt = singleProduct?.selectedOptions?. [0];
            return {
                onHand: Number(opt?.dataset?.onHand || 0),
                reserved: Number(opt?.dataset?.reserved || 0),
                available: Number(opt?.dataset?.available || 0),
                price: Number(opt?.dataset?.price || 0)
            };
        }

        function refreshSingle() {
            const d = selectedSingleData();
            document.getElementById('single_on_hand').textContent = qtyText(d.onHand);
            document.getElementById('single_reserved').textContent = qtyText(d.reserved);
            document.getElementById('single_available').textContent = qtyText(d.available);
            document.getElementById('single_line_value').value = money(Number(singleQty?.value || 0) * d.price);

            const reducing = singleAction?.value === 'reduce';
            singleReasonWrap?.classList.toggle('d-none', !reducing);
            singleRefWrap?.classList.toggle('d-none', reducing);
            if (singleReason) {
                singleReason.required = reducing;
                singleReason.disabled = !reducing;
            }
            if (singleRef) singleRef.disabled = reducing;

            const qty = Number(singleQty?.value || 0);
            const invalid = reducing && qty > d.onHand + 0.000001;
            singleQtyError.style.display = invalid ? 'block' : 'none';
            singleQty?.classList.toggle('is-invalid', invalid);
            if (singleSave) singleSave.disabled = invalid;
        }

        singleAction?.addEventListener('change', refreshSingle);
        singleProduct?.addEventListener('change', refreshSingle);
        singleQty?.addEventListener('input', refreshSingle);

        document.querySelectorAll('.js-stock-open').forEach(btn => {
            btn.addEventListener('click', () => {
                singleForm.reset();
                singleAction.value = btn.dataset.action === 'reduce' ? 'reduce' : 'inward';
                singleProduct.value = String(btn.dataset.productId || '');
                refreshSingle();
                singleModal.show();
            });
        });

        const bulkAction = document.getElementById('bulk_action');
        const bulkSearch = document.getElementById('bulk_product_search');
        const bulkReasonWrap = document.getElementById('bulk_reason_wrap');
        const bulkReason = document.getElementById('bulk_reason');
        const bulkRefWrap = document.getElementById('bulk_reference_wrap');
        const bulkRef = document.getElementById('bulk_reference_no');
        const bulkSave = document.getElementById('bulk_save_btn');
        const bulkQtyHeading = document.getElementById('bulk_qty_heading');
        const bulkRows = [...document.querySelectorAll('.bulk-product-row')];

        function refreshBulk() {
            const reducing = bulkAction?.value === 'reduce';
            bulkQtyHeading.textContent = reducing ? 'Reduce Qty' : 'Add Qty';
            bulkReasonWrap?.classList.toggle('d-none', !reducing);
            bulkRefWrap?.classList.toggle('d-none', reducing);
            if (bulkReason) {
                bulkReason.required = reducing;
                bulkReason.disabled = !reducing;
            }
            if (bulkRef) bulkRef.disabled = reducing;

            let selected = 0,
                totalQty = 0,
                totalValue = 0,
                hasInvalid = false;
            bulkRows.forEach(row => {
                const input = row.querySelector('.bulk-qty');
                const error = row.querySelector('.qty-error');
                const line = row.querySelector('.bulk-line-value');
                const q = Math.max(0, Number(input.value || 0));
                const onHand = Number(row.dataset.onHand || 0);
                const price = Number(row.dataset.price || 0);
                const invalid = reducing && q > onHand + 0.000001;
                input.classList.toggle('is-invalid', invalid);
                error.style.display = invalid ? 'block' : 'none';
                if (invalid) hasInvalid = true;
                if (q > 0) {
                    selected++;
                    totalQty += q;
                    totalValue += q * price;
                }
                line.textContent = money(q * price);
            });
            document.getElementById('bulk_selected_count').textContent = selected;
            document.getElementById('bulk_total_qty').textContent = qtyText(totalQty);
            document.getElementById('bulk_total_value').textContent = money(totalValue);
            if (bulkSave) bulkSave.disabled = hasInvalid;
        }

        bulkAction?.addEventListener('change', refreshBulk);
        document.querySelectorAll('.bulk-qty').forEach(el => el.addEventListener('input', refreshBulk));
        bulkSearch?.addEventListener('input', () => {
            const q = bulkSearch.value.trim().toLowerCase();
            bulkRows.forEach(row => row.classList.toggle('d-none', q !== '' && !String(row.dataset.search ||
                '').includes(q)));
        });

        document.getElementById('bulkStockModal')?.addEventListener('shown.bs.modal', refreshBulk);

        function protectModal(modalId, formId) {
            const modalEl = document.getElementById(modalId);
            const form = document.getElementById(formId);
            if (!modalEl || !form) return;
            let dirty = false;
            let allowClose = false;
            let submitting = false;

            form.addEventListener('input', () => dirty = true);
            form.addEventListener('change', () => dirty = true);
            form.addEventListener('submit', () => {
                // This is an intentional save/navigation. Clear both dirty trackers
                // before the browser leaves the page so beforeunload does not show
                // the "Changes you made may not be saved" warning.
                submitting = true;
                dirty = false;
                allowClose = true;
                form.dataset.dirty = '0';
            });

            modalEl.addEventListener('hide.bs.modal', function(e) {
                if (!dirty || allowClose || submitting) return;
                e.preventDefault();
                if (window.confirm(
                        'Unsaved changes will be lost. Are you sure you want to close this form?')) {
                    allowClose = true;
                    bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                }
            });
            modalEl.addEventListener('hidden.bs.modal', function() {
                dirty = false;
                allowClose = false;
                submitting = false;
                form.dataset.dirty = '0';
            });
            form.addEventListener('input', () => form.dataset.dirty = '1');
            form.addEventListener('change', () => form.dataset.dirty = '1');
        }

        protectModal('singleStockModal', 'singleStockForm');
        protectModal('bulkStockModal', 'bulkStockForm');

        window.addEventListener('beforeunload', function(e) {
            const dirty = document.querySelector(
                '#singleStockForm[data-dirty="1"],#bulkStockForm[data-dirty="1"]');
            if (!dirty) return;
            e.preventDefault();
            e.returnValue = '';
        });
    })();
    </script>
    <?php endif; ?>
</body>

</html>