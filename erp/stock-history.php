<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product-stock-helper.php';

require_permission($conn, 'can_view', 'stock-history.php');
ps_require_module($conn);

/*
|--------------------------------------------------------------------------
| Stock History Timezone Policy
|--------------------------------------------------------------------------
| Database DATETIME timestamps are stored in UTC.
| All timestamps displayed to users are converted to Asia/Kolkata (IST).
| Date filters are also interpreted as India-local calendar dates.
*/
if (!defined('SH_DATABASE_TIMEZONE')) {
    define('SH_DATABASE_TIMEZONE', 'UTC');
}

if (!defined('SH_SYSTEM_TIMEZONE')) {
    define('SH_SYSTEM_TIMEZONE', 'Asia/Kolkata');
}

date_default_timezone_set(SH_SYSTEM_TIMEZONE);

function sh_datetime_ist($value): string
{
    if (empty($value)) {
        return '-';
    }

    try {
        $dbTz = new DateTimeZone(SH_DATABASE_TIMEZONE);
        $systemTz = new DateTimeZone(SH_SYSTEM_TIMEZONE);

        $dt = DateTime::createFromFormat(
            'Y-m-d H:i:s',
            trim((string)$value),
            $dbTz
        );

        if (!$dt) {
            $dt = new DateTime((string)$value, $dbTz);
        }

        $dt->setTimezone($systemTz);

        return $dt->format('d-m-Y h:i A');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function sh_local_date_start_utc(string $date): ?string
{
    $date = trim($date);

    if ($date === '') {
        return null;
    }

    try {
        $systemTz = new DateTimeZone(SH_SYSTEM_TIMEZONE);
        $dbTz = new DateTimeZone(SH_DATABASE_TIMEZONE);

        $dt = DateTime::createFromFormat('!Y-m-d', $date, $systemTz);

        if (!$dt) {
            return null;
        }

        $dt->setTimezone($dbTz);

        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function sh_local_date_end_exclusive_utc(string $date): ?string
{
    $date = trim($date);

    if ($date === '') {
        return null;
    }

    try {
        $systemTz = new DateTimeZone(SH_SYSTEM_TIMEZONE);
        $dbTz = new DateTimeZone(SH_DATABASE_TIMEZONE);

        $dt = DateTime::createFromFormat('!Y-m-d', $date, $systemTz);

        if (!$dt) {
            return null;
        }

        $dt->modify('+1 day');
        $dt->setTimezone($dbTz);

        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

$productId = (int)($_GET['product_id'] ?? 0);
$type = trim((string)($_GET['type'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$products = [];
$res = $conn->query("SELECT id,product_name FROM products ORDER BY product_name ASC");
while ($r = $res->fetch_assoc()) $products[] = $r;
$res->free();

$where = ['1=1'];
$params = [];
$types = '';

if ($productId > 0) {
    $where[] = 'st.product_id = ?';
    $params[] = $productId;
    $types .= 'i';
}

if ($type !== '') {
    $where[] = 'st.transaction_type = ?';
    $params[] = $type;
    $types .= 's';
}

/*
 * The filter inputs are India-local dates.
 * Convert their boundaries to UTC before comparing with stored timestamps.
 */
$dateFromUtc = sh_local_date_start_utc($dateFrom);
if ($dateFromUtc !== null) {
    $where[] = 'st.created_at >= ?';
    $params[] = $dateFromUtc;
    $types .= 's';
}

$dateToUtcExclusive = sh_local_date_end_exclusive_utc($dateTo);
if ($dateToUtcExclusive !== null) {
    $where[] = 'st.created_at < ?';
    $params[] = $dateToUtcExclusive;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

/* Count first so pagination is based on the complete filtered result. */
$countSql = "
    SELECT COUNT(*) AS total
    FROM stock_transactions st
    WHERE {$whereSql}
";

$countStmt = $conn->prepare($countSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();
$countStmt->close();

$totalRecords = (int)($countRow['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
        st.*,
        p.product_name,
        u.name AS user_name
    FROM stock_transactions st
    INNER JOIN products p ON p.id = st.product_id
    LEFT JOIN users u ON u.id = st.created_by
    WHERE {$whereSql}
    ORDER BY st.id DESC
    LIMIT ? OFFSET ?
";

$dataParams = $params;
$dataTypes = $types . 'ii';
$dataParams[] = $perPage;
$dataParams[] = $offset;

$stmt = $conn->prepare($sql);
$stmt->bind_param($dataTypes, ...$dataParams);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}
$stmt->close();

$showPagination = $totalRecords > $perPage;
$showingFrom = $totalRecords > 0 ? $offset + 1 : 0;
$showingTo = min($offset + count($rows), $totalRecords);

$transactionTypes=[];
$res=$conn->query("SELECT DISTINCT transaction_type FROM stock_transactions ORDER BY transaction_type ASC");
while($r=$res->fetch_assoc())$transactionTypes[]=$r['transaction_type'];$res->free();

function sh_label(string $type): string {
    $map=['inward'=>'Stock Inward','manual_reduce'=>'Stock Reduce','reserve'=>'Proforma Reserve','reserve_release'=>'Reservation Release','dispatch'=>'Dispatch','return'=>'Return','opening'=>'Opening Stock','adjustment'=>'Adjustment'];
    return $map[$type]??ucwords(str_replace('_',' ',$type));
}

$message = trim((string)($_GET['message'] ?? ''));
$messageType = trim((string)($_GET['message_type'] ?? 'success'));
if (!in_array($messageType, ['success','danger','warning'], true)) $messageType = 'success';
$toastTitle = $messageType === 'danger' ? 'Unable to Continue' : ($messageType === 'warning' ? 'Warning' : 'Success');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Stock History - Subhiksha Cards</title>
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

    .module-page .page-head {
        padding: 24px 28px;
        margin-bottom: 18px
    }

    .module-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main)
    }

    .history-type {
        font-size: 11px;
        font-weight: 900;
        padding: 4px 9px;
        border-radius: 999px;
        background: #f1f5f9;
        color: #334155
    }

    .qty-plus {
        color: #047857;
        font-weight: 900
    }

    .qty-minus {
        color: #dc2626;
        font-weight: 900
    }

    .desktop-table th {
        white-space: nowrap
    }

    .stock-pagination-wrap {
        border-top: 1px solid var(--border-soft);
        padding: 12px 14px;
        background: color-mix(in srgb, var(--card-bg) 97%, var(--body-bg));
    }

    .stock-pagination-wrap .pagination {
        margin-bottom: 0;
        gap: 3px;
    }

    .stock-pagination-wrap .page-link {
        min-width: 34px;
        height: 34px;
        padding: 5px 8px;
        border-radius: 9px !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 800;
        color: var(--text-main);
        background: var(--card-bg);
        border-color: var(--border-soft);
        box-shadow: none;
    }

    .stock-pagination-wrap .page-item.active .page-link {
        background: var(--brand-1);
        border-color: var(--brand-1);
        color: #fff;
    }

    .stock-pagination-wrap .page-item.disabled .page-link {
        opacity: .45;
        pointer-events: none;
    }

    @media(max-width:767.98px) {
        .stock-pagination-wrap {
            text-align: center;
        }

        .stock-pagination-wrap .pagination {
            justify-content: center;
        }
    }

    @media(max-width:767.98px) {
        .module-page .page-head h1 {
            font-size: 24px
        }
    }
    </style>

    <style id="compact-stock-history-ui">
    /* Compact Stock History UI - visual changes only */
    .module-page .page-head {
        padding: 16px 18px !important;
        margin-bottom: 12px !important;
        border-radius: 16px !important;
    }

    .module-page .page-head h1 {
        font-size: 23px !important;
        font-weight: 800 !important;
        line-height: 1.2 !important;
    }

    .module-page .page-head p {
        font-size: 12px !important;
        font-weight: 500 !important;
        line-height: 1.4 !important;
    }

    .module-page .page-head .btn {
        padding: 7px 12px !important;
        font-size: 11.5px !important;
        font-weight: 700 !important;
    }

    .module-page form.card-ui {
        padding: 13px 14px !important;
        margin-bottom: 12px !important;
        border-radius: 16px !important;
    }

    .module-page .form-label {
        font-size: 11px !important;
        font-weight: 700 !important;
        margin-bottom: 4px !important;
    }

    .module-page .form-control,
    .module-page .form-select {
        min-height: 37px !important;
        height: 37px !important;
        padding: 6px 9px !important;
        border-radius: 10px !important;
        font-size: 12px !important;
        font-weight: 500 !important;
    }

    .module-page form.card-ui .btn {
        min-height: 37px !important;
        padding: 6px 11px !important;
        font-size: 11.5px !important;
        font-weight: 700 !important;
    }

    .module-page>.card-ui.overflow-hidden {
        border-radius: 16px !important;
    }

    .module-page .table-responsive {
        padding: 0 !important;
    }

    .module-page .table-ui {
        font-size: 11.5px !important;
    }

    .module-page .table-ui th {
        padding: 8px 9px !important;
        font-size: 9.5px !important;
        font-weight: 750 !important;
        letter-spacing: .02em !important;
        line-height: 1.2 !important;
        white-space: nowrap !important;
    }

    .module-page .table-ui td {
        padding: 7px 9px !important;
        font-size: 11.25px !important;
        font-weight: 500 !important;
        line-height: 1.3 !important;
    }

    .module-page .table-ui td strong,
    .module-page .table-ui td.fw-bold {
        font-weight: 700 !important;
    }

    .history-type {
        font-size: 9.5px !important;
        font-weight: 700 !important;
        padding: 3px 7px !important;
        border-radius: 999px !important;
    }

    .qty-plus,
    .qty-minus {
        font-weight: 700 !important;
    }

    .toast-ui {
        min-width: 290px !important;
        max-width: 360px !important;
        border-radius: 14px !important;
    }

    .toast-ui .toast-title {
        font-size: 12.5px !important;
        font-weight: 750 !important;
    }

    .toast-ui .toast-message {
        font-size: 11.5px !important;
        font-weight: 600 !important;
    }

    @media(max-width:767.98px) {
        .module-page .page-head {
            padding: 14px !important;
            border-radius: 14px !important;
        }

        .module-page .page-head h1 {
            font-size: 20px !important;
        }

        .module-page form.card-ui {
            padding: 11px !important;
        }

        .module-page .table-ui th {
            font-size: 9px !important;
            padding: 7px !important;
        }

        .module-page .table-ui td {
            font-size: 10.75px !important;
            padding: 7px !important;
        }
    }
    </style>

</head>

<body class="<?= ps_e(($theme['layout_density']??'')==='compact'?'layout-compact':'') ?>">
    <div id="mobileOverlay"></div>
    <div class="app-shell"><?php include __DIR__.'/includes/sidebar.php'; ?><main id="main">
            <?php include __DIR__.'/includes/nav.php'; ?><section class="page-section module-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Stock History</h1>
                            <p class="text-muted-custom mb-0">Permanent history for inward, reductions, Proforma
                                reservations, releases and dispatch.</p>
                        </div><a href="stock-management.php"
                            class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Back to Stock</a>
                    </div>
                </div>

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

                <form class="card-ui p-3 mb-3" method="get">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-3"><label class="form-label fw-bold small">Product</label><select
                                class="form-select" name="product_id">
                                <option value="0">All Products</option><?php foreach($products as $p): ?><option
                                    value="<?= (int)$p['id'] ?>" <?= $productId===(int)$p['id']?'selected':'' ?>>
                                    <?= ps_e($p['product_name']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-12 col-md-2"><label class="form-label fw-bold small">Transaction</label><select
                                class="form-select" name="type">
                                <option value="">All Types</option><?php foreach($transactionTypes as $t): ?><option
                                    value="<?= ps_e($t) ?>" <?= $type===$t?'selected':'' ?>><?= ps_e(sh_label($t)) ?>
                                </option><?php endforeach; ?>
                            </select></div>
                        <div class="col-6 col-md-2"><label class="form-label fw-bold small">From</label><input
                                type="date" class="form-control" name="date_from" value="<?= ps_e($dateFrom) ?>"></div>
                        <div class="col-6 col-md-2"><label class="form-label fw-bold small">To</label><input type="date"
                                class="form-control" name="date_to" value="<?= ps_e($dateTo) ?>"></div>
                        <div class="col-12 col-md-3 d-flex gap-2"><button
                                class="btn btn-primary fw-bold w-100">Filter</button><a
                                class="btn btn-outline-secondary fw-bold" href="stock-history.php">Reset</a></div>
                    </div>
                </form>

                <div class="card-ui overflow-hidden">
                    <div class="table-responsive">
                        <table class="table-ui mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>Transaction</th>
                                    <th>Qty</th>
                                    <th>On Hand</th>
                                    <th>Reserved</th>
                                    <th>Available After</th>
                                    <th>Reference</th>
                                    <th>Description</th>
                                    <th>User</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!$rows): ?><tr>
                                    <td colspan="10" class="text-center text-muted-custom py-5">No stock history found.
                                    </td>
                                </tr><?php endif; ?>
                                <?php foreach($rows as $r): $qty=(float)$r['quantity'];$availableAfter=(float)$r['on_hand_after']-(float)$r['reserved_after']; ?>
                                <tr>
                                    <td><?= ps_e(sh_datetime_ist($r['created_at'] ?? null)) ?></td>
                                    <td class="fw-bold"><?= ps_e($r['product_name']) ?></td>
                                    <td><span class="history-type"><?= ps_e(sh_label($r['transaction_type'])) ?></span>
                                    </td>
                                    <td class="<?= $qty<0?'qty-minus':'qty-plus' ?>">
                                        <?= $qty>0?'+':'' ?><?= ps_e(ps_qty($qty)) ?></td>
                                    <td><?= ps_e(ps_qty($r['on_hand_before'])) ?> →
                                        <strong><?= ps_e(ps_qty($r['on_hand_after'])) ?></strong>
                                    </td>
                                    <td><?= ps_e(ps_qty($r['reserved_before'])) ?> →
                                        <strong><?= ps_e(ps_qty($r['reserved_after'])) ?></strong>
                                    </td>
                                    <td class="<?= $availableAfter<0?'text-danger fw-bold':'fw-bold' ?>">
                                        <?= ps_e(ps_qty($availableAfter)) ?></td>
                                    <td><?= ps_e($r['reference_no']?:($r['reference_type']?:'-')) ?></td>
                                    <td><?= ps_e($r['description']?:'-') ?></td>
                                    <td><?= ps_e($r['user_name']?:'-') ?></td>
                                </tr><?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($showPagination): ?>
                    <?php
                    $paginationParams = [
                        'product_id' => $productId,
                        'type' => $type,
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                    ];

                    $pageUrl = static function (int $targetPage) use ($paginationParams): string {
                        $params = $paginationParams;
                        $params['page'] = max(1, $targetPage);

                        return 'stock-history.php?' . http_build_query($params);
                    };

                    $windowStart = max(1, $page - 2);
                    $windowEnd = min($totalPages, $windowStart + 4);
                    $windowStart = max(1, $windowEnd - 4);
                    ?>
                    <div
                        class="stock-pagination-wrap d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2">
                        <div class="text-muted-custom fw-bold small">
                            Showing <?= number_format($showingFrom) ?>-<?= number_format($showingTo) ?>
                            of <?= number_format($totalRecords) ?> records
                        </div>

                        <nav aria-label="Stock History Pagination">
                            <ul class="pagination pagination-sm flex-wrap">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page > 1 ? ps_e($pageUrl($page - 1)) : '#' ?>"
                                        aria-label="Previous">‹</a>
                                </li>

                                <?php if ($windowStart > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= ps_e($pageUrl(1)) ?>">1</a>
                                </li>
                                <?php if ($windowStart > 2): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= ps_e($pageUrl($p)) ?>"
                                        <?= $p === $page ? 'aria-current="page"' : '' ?>>
                                        <?= $p ?>
                                    </a>
                                </li>
                                <?php endfor; ?>

                                <?php if ($windowEnd < $totalPages): ?>
                                <?php if ($windowEnd < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= ps_e($pageUrl($totalPages)) ?>">
                                        <?= $totalPages ?>
                                    </a>
                                </li>
                                <?php endif; ?>

                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link"
                                        href="<?= $page < $totalPages ? ps_e($pageUrl($page + 1)) : '#' ?>"
                                        aria-label="Next">›</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
        <div id="settingsOverlay"></div><?php include __DIR__.'/includes/rightsidebar.php'; ?>
    </div><?php include __DIR__.'/includes/script.php'; ?><script>
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
</body>

</html>