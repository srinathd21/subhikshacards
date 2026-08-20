<?php
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function qsl_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function qsl_money($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function qsl_table_exists(mysqli $conn, string $table): bool
{
    try {
        $safe = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function qsl_is_admin(mysqli $conn): bool
{
    if (function_exists('is_admin_user') && is_admin_user()) {
        return true;
    }

    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($roleId <= 0) return false;

    try {
        $stmt = $conn->prepare("
            SELECT role_key, role_name
            FROM roles
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $roleKey = strtolower(trim((string)($row['role_key'] ?? '')));
        $roleName = strtolower(trim((string)($row['role_name'] ?? '')));

        return in_array($roleKey, ['admin', 'super_admin', 'business_admin'], true)
            || $roleName === 'admin';
    } catch (Throwable $e) {
        return false;
    }
}

if (!qsl_is_admin($conn)) {
    require_permission($conn, 'can_view', 'quick-sale.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$paymentMode = strtolower(trim((string)($_GET['payment_mode'] ?? '')));

if (!in_array($paymentMode, ['', 'cash', 'upi'], true)) {
    $paymentMode = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$rows = [];
$totalRows = 0;
$filteredAmount = 0.0;
$error = '';

$hasPayment = qsl_table_exists($conn, 'quick_sale_payments');

$where = "
    (? = ''
        OR qs.sale_no LIKE CONCAT('%', ?, '%')
        OR EXISTS (
            SELECT 1
            FROM quick_sale_items qsi_search
            WHERE qsi_search.quick_sale_id = qs.id
              AND qsi_search.product_name LIKE CONCAT('%', ?, '%')
        )
    )
    AND (? = '' OR DATE(qs.created_at) >= ?)
    AND (? = '' OR DATE(qs.created_at) <= ?)
";

if ($hasPayment) {
    $where .= "
        AND (
            ? = ''
            OR EXISTS (
                SELECT 1
                FROM quick_sale_payments qsp_filter
                WHERE qsp_filter.quick_sale_id = qs.id
                  AND qsp_filter.payment_mode = ?
            )
        )
    ";
} else {
    $where .= " AND (? = '' OR ? = '') ";
}

$types = 'sssssssss';
$params = [
    $q, $q, $q,
    $fromDate, $fromDate,
    $toDate, $toDate,
    $paymentMode, $paymentMode
];

try {
    $countSql = "
        SELECT
            COUNT(*) AS total_rows,
            COALESCE(SUM(qs.total_amount), 0) AS total_amount
        FROM quick_sales qs
        WHERE {$where}
    ";

    $stmt = $conn->prepare($countSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $totalRows = (int)($summary['total_rows'] ?? 0);
    $filteredAmount = (float)($summary['total_amount'] ?? 0);

    $paymentSelect = $hasPayment
        ? "
            COALESCE((
                SELECT SUM(CASE WHEN qsp.payment_mode = 'cash' THEN qsp.tendered_amount ELSE 0 END)
                FROM quick_sale_payments qsp
                WHERE qsp.quick_sale_id = qs.id
            ), 0) AS cash_received,
            COALESCE((
                SELECT SUM(CASE WHEN qsp.payment_mode = 'upi' THEN qsp.amount ELSE 0 END)
                FROM quick_sale_payments qsp
                WHERE qsp.quick_sale_id = qs.id
            ), 0) AS upi_received,
            COALESCE((
                SELECT GROUP_CONCAT(
                    DISTINCT CASE
                        WHEN qsp.payment_mode = 'upi'
                         AND COALESCE(qsp.reference_no, '') <> ''
                        THEN qsp.reference_no
                    END
                    ORDER BY qsp.id SEPARATOR ', '
                )
                FROM quick_sale_payments qsp
                WHERE qsp.quick_sale_id = qs.id
            ), '') AS upi_reference,
            COALESCE((
                SELECT SUM(qsp.return_amount)
                FROM quick_sale_payments qsp
                WHERE qsp.quick_sale_id = qs.id
            ), 0) AS return_amount
        "
        : "0 AS cash_received, 0 AS upi_received, '' AS upi_reference, 0 AS return_amount";

    $sql = "
        SELECT
            qs.id,
            qs.sale_no,
            qs.total_amount,
            qs.created_at,
            COALESCE((
                SELECT COUNT(*)
                FROM quick_sale_items qsi_count
                WHERE qsi_count.quick_sale_id = qs.id
            ), 0) AS item_count,
            COALESCE((
                SELECT SUM(qsi_qty.qty)
                FROM quick_sale_items qsi_qty
                WHERE qsi_qty.quick_sale_id = qs.id
            ), 0) AS total_qty,
            COALESCE((
                SELECT GROUP_CONCAT(qsi_name.product_name ORDER BY qsi_name.id SEPARATOR ', ')
                FROM quick_sale_items qsi_name
                WHERE qsi_name.quick_sale_id = qs.id
            ), '') AS product_names,
            {$paymentSelect}
        FROM quick_sales qs
        WHERE {$where}
        ORDER BY qs.id DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);

    $mainTypes = $types . 'ii';
    $mainParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($mainTypes, ...$mainParams);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));

function qsl_page_url(int $page): string
{
    $query = $_GET;
    $query['page'] = max(1, $page);
    return 'quick-sales.php?' . http_build_query($query);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Quick Sales - Subhiksha Cards</title>

    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
    .quick-sales-page .page-head {
        padding: 24px 28px;
        margin-bottom: 18px;
    }

    .quick-sales-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main);
    }

    .module-card {
        padding: 22px;
        border-radius: 20px;
        margin-bottom: 18px;
    }

    .stat-card {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 16px;
        height: 100%;
    }

    .stat-card small {
        display: block;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
    }

    .stat-card strong {
        display: block;
        color: var(--text-main);
        font-size: 22px;
        font-weight: 900;
        margin-top: 4px;
    }

    .filter-card {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 16px;
        background: color-mix(in srgb, var(--card-bg) 97%, var(--body-bg));
    }

    .product-names {
        max-width: 380px;
        white-space: normal;
        line-height: 1.35;
        font-weight: 700;
    }

    .payment-parts {
        font-size: 12px;
        font-weight: 800;
        line-height: 1.45;
    }

    .payment-parts .upi-ref {
        color: var(--text-muted);
        font-size: 11px;
    }

    @media(max-width:767.98px) {
        .quick-sales-page .page-head {
            padding: 18px;
        }

        .quick-sales-page .page-head h1 {
            font-size: 24px;
        }

        .module-card {
            padding: 16px;
        }
    }
    </style>
</head>

<body class="<?= qsl_e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
    <div id="mobileOverlay"></div>

    <div class="app-shell">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main id="main">
            <?php include __DIR__ . '/includes/nav.php'; ?>

            <section class="page-section quick-sales-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Quick Sales</h1>
                            <p class="text-muted-custom mb-0">
                                Direct sale history with Cash / UPI payment split.
                            </p>
                        </div>

                        <a href="quick-sale.php"
                            class="btn btn-primary rounded-pill px-4 fw-bold">
                            New Quick Sale
                        </a>
                    </div>
                </div>

                <?php if ($error !== ''): ?>
                <div class="card-ui module-card">
                    <div class="alert alert-danger rounded-4 fw-bold mb-0">
                        <?= qsl_e($error) ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="card-ui stat-card">
                            <small>Filtered Quick Sales</small>
                            <strong><?= number_format($totalRows) ?></strong>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card-ui stat-card">
                            <small>Filtered Sale Amount</small>
                            <strong><?= qsl_e(qsl_money($filteredAmount)) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <form method="get" class="filter-card">
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-4">
                                <label class="form-label fw-bold">Search</label>
                                <input type="text" name="q" class="form-control"
                                    value="<?= qsl_e($q) ?>"
                                    placeholder="Sale No / Product Name">
                            </div>

                            <div class="col-lg-2 col-md-4">
                                <label class="form-label fw-bold">From Date</label>
                                <input type="date" name="from_date" class="form-control"
                                    value="<?= qsl_e($fromDate) ?>">
                            </div>

                            <div class="col-lg-2 col-md-4">
                                <label class="form-label fw-bold">To Date</label>
                                <input type="date" name="to_date" class="form-control"
                                    value="<?= qsl_e($toDate) ?>">
                            </div>

                            <div class="col-lg-2 col-md-4">
                                <label class="form-label fw-bold">Payment</label>
                                <select name="payment_mode" class="form-select">
                                    <option value="">All</option>
                                    <option value="cash" <?= $paymentMode === 'cash' ? 'selected' : '' ?>>Cash</option>
                                    <option value="upi" <?= $paymentMode === 'upi' ? 'selected' : '' ?>>UPI</option>
                                </select>
                            </div>

                            <div class="col-lg-2">
                                <div class="d-flex gap-2">
                                    <button class="btn btn-primary fw-bold flex-grow-1">Filter</button>
                                    <a href="quick-sales.php"
                                        class="btn btn-outline-secondary fw-bold">Reset</a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="card-ui module-card">
                    <div class="table-responsive">
                        <table class="table-ui">
                            <thead>
                                <tr>
                                    <th>Sale No</th>
                                    <th>Date</th>
                                    <th>Products</th>
                                    <th class="text-end">Qty</th>
                                    <th>Payment</th>
                                    <th class="text-end">Return</th>
                                    <th class="text-end">Total Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted-custom py-4">
                                        No Quick Sales found.
                                    </td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($rows as $row): ?>
                                <?php
                                    $cash = (float)($row['cash_received'] ?? 0);
                                    $upi = (float)($row['upi_received'] ?? 0);
                                    $upiRef = trim((string)($row['upi_reference'] ?? ''));
                                ?>
                                <tr>
                                    <td><strong><?= qsl_e($row['sale_no'] ?? '-') ?></strong></td>
                                    <td>
                                        <?= !empty($row['created_at'])
                                            ? qsl_e(date('d-m-Y h:i A', strtotime((string)$row['created_at'])))
                                            : '-' ?>
                                    </td>
                                    <td>
                                        <div class="product-names">
                                            <?= qsl_e($row['product_names'] ?? '-') ?>
                                        </div>
                                        <small class="text-muted-custom fw-bold">
                                            <?= number_format((int)($row['item_count'] ?? 0)) ?> Product(s)
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <?= number_format((float)($row['total_qty'] ?? 0), 2) ?>
                                    </td>
                                    <td>
                                        <div class="payment-parts">
                                            <?php if ($cash > 0.009): ?>
                                            <div>Cash: <?= qsl_e(qsl_money($cash)) ?></div>
                                            <?php endif; ?>

                                            <?php if ($upi > 0.009): ?>
                                            <div>UPI: <?= qsl_e(qsl_money($upi)) ?></div>
                                            <?php if ($upiRef !== ''): ?>
                                            <div class="upi-ref">Ref: <?= qsl_e($upiRef) ?></div>
                                            <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if ($cash <= 0.009 && $upi <= 0.009): ?>
                                            <span class="text-muted-custom">-</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <?= qsl_e(qsl_money($row['return_amount'] ?? 0)) ?>
                                    </td>
                                    <td class="text-end fw-bold">
                                        <?= qsl_e(qsl_money($row['total_amount'] ?? 0)) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mt-3">
                        <small class="text-muted-custom fw-bold">
                            Page <?= number_format($page) ?> of <?= number_format($totalPages) ?>
                        </small>

                        <div class="d-flex gap-2">
                            <a href="<?= qsl_e(qsl_page_url(max(1, $page - 1))) ?>"
                                class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold
                                    <?= $page <= 1 ? 'disabled' : '' ?>">
                                Previous
                            </a>

                            <a href="<?= qsl_e(qsl_page_url(min($totalPages, $page + 1))) ?>"
                                class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold
                                    <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                Next
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>
</body>
</html>
