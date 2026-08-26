<?php
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['quick_sale_csrf'])) {
    $_SESSION['quick_sale_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['quick_sale_csrf'];

function qsl_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function qsl_money($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function qsl_action_svg(string $icon): string
{
    $common = 'class="quick-action-svg" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"';

    if ($icon === 'whatsapp') {
        return '<svg ' . $common . ' viewBox="0 0 32 32"><path fill="currentColor" d="M16.04 3C8.85 3 3 8.73 3 15.78c0 2.26.61 4.47 1.77 6.41L3 29l7.02-1.8a13.3 13.3 0 0 0 6.02 1.43C23.23 28.63 29 22.9 29 15.85S23.23 3 16.04 3Zm0 23.45c-1.9 0-3.76-.5-5.39-1.45l-.39-.23-4.16 1.07 1.11-4.01-.26-.41a11.05 11.05 0 0 1-1.73-5.64c0-5.84 4.85-10.6 10.82-10.6 5.96 0 10.81 4.76 10.81 10.67 0 5.84-4.85 10.6-10.81 10.6Zm5.93-7.95c-.32-.16-1.9-.92-2.2-1.03-.3-.11-.52-.16-.74.16-.22.32-.85 1.03-1.04 1.24-.19.22-.38.24-.7.08-.32-.16-1.36-.49-2.59-1.55-.96-.84-1.61-1.88-1.8-2.2-.19-.32-.02-.49.14-.65.14-.14.32-.38.49-.57.16-.19.22-.32.32-.54.11-.22.05-.41-.03-.57-.08-.16-.74-1.76-1.01-2.41-.27-.65-.54-.54-.74-.55h-.63c-.22 0-.57.08-.87.41-.3.32-1.14 1.09-1.14 2.68s1.17 3.12 1.33 3.34c.16.22 2.3 3.46 5.58 4.85.78.33 1.39.53 1.86.68.78.24 1.49.21 2.05.13.63-.09 1.9-.76 2.17-1.49.27-.73.27-1.36.19-1.49-.08-.13-.3-.21-.62-.37Z"/></svg>';
    }

    $paths = [
        'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/>',
        'invoice' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'delete' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>',
    ];

    $path = $paths[$icon] ?? $paths['invoice'];
    return '<svg ' . $common . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
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
        OR COALESCE(qs.customer_name, '') LIKE CONCAT('%', ?, '%')
        OR COALESCE(qs.mobile, '') LIKE CONCAT('%', ?, '%')
        OR COALESCE(qs.address, '') LIKE CONCAT('%', ?, '%')
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

$types = 'ssssssssssss';
$params = [
    $q, $q, $q, $q, $q, $q,
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
            qs.customer_name,
            qs.mobile,
            qs.address,
            qs.whatsapp_status,
            qs.whatsapp_sent_at,
            qs.total_amount,
            qs.created_at,
            COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), 'System') AS sale_by,
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
        LEFT JOIN users u ON u.id = qs.created_by
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

    .customer-info {
        min-width: 185px;
        max-width: 260px;
        line-height: 1.35;
    }

    .customer-info .customer-name {
        display: block;
        font-weight: 900;
        color: var(--text-main);
    }

    .customer-info .customer-mobile,
    .customer-info .customer-address {
        display: block;
        margin-top: 2px;
        font-size: 11px;
        font-weight: 700;
        color: var(--text-muted);
        white-space: normal;
        overflow-wrap: anywhere;
    }

    .customer-info .customer-address {
        max-width: 250px;
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
    
    .quick-sale-action-group {
        display:flex;
        justify-content:flex-end;
        gap:5px;
        white-space:nowrap;
    }
    .quick-sale-action-group .btn {
        width:31px !important;
        height:31px !important;
        min-width:31px !important;
        padding:0 !important;
        border-radius:9px !important;
        display:inline-flex !important;
        align-items:center;
        justify-content:center;
    }
    .quick-sale-action-group .btn svg { width:14px; height:14px; }
    .quick-sale-action-group .wa-sent { box-shadow:0 0 0 2px rgba(25,135,84,.12); }
    .quick-sale-edit-modal .modal-content { border:0; border-radius:18px; overflow:hidden; }
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

<style>

/* Quick Sales list - compact content and reliable action icons */
.quick-sales-page .module-card {
    padding: 12px 13px !important;
}

.quick-sales-page .table-ui {
    font-size: 10.5px !important;
}

.quick-sales-page .table-ui th {
    padding: 6px 7px !important;
    font-size: 9px !important;
    font-weight: 700 !important;
    white-space: nowrap;
}

.quick-sales-page .table-ui td {
    padding: 6px 7px !important;
    font-size: 10.5px !important;
    font-weight: 500 !important;
    line-height: 1.25 !important;
    vertical-align: middle !important;
}

.quick-sales-page .table-ui td strong,
.quick-sales-page .customer-info .customer-name,
.quick-sales-page .product-names {
    font-size: 10.7px !important;
    font-weight: 700 !important;
}

.quick-sales-page .customer-info .customer-mobile,
.quick-sales-page .customer-info .customer-address,
.quick-sales-page .table-ui td small,
.quick-sales-page .payment-parts .upi-ref {
    font-size: 9.3px !important;
    line-height: 1.2 !important;
    font-weight: 500 !important;
}

.quick-sales-page .product-names {
    line-height: 1.25 !important;
}

.quick-sales-page .payment-parts {
    font-size: 10.2px !important;
    line-height: 1.3 !important;
    font-weight: 600 !important;
}

.quick-sales-page .quick-sale-action-group {
    gap: 4px !important;
}

.quick-sales-page .quick-sale-action-group .btn {
    width: 28px !important;
    height: 28px !important;
    min-width: 28px !important;
    max-width: 28px !important;
    border-radius: 8px !important;
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.quick-sales-page .quick-sale-action-group .btn i {
    font-size: 12px !important;
    line-height: 1 !important;
}

.quick-sales-page .quick-sale-action-group .btn-whatsapp-icon i {
    font-size: 14px !important;
}

.quick-sales-page .quick-sale-action-group .btn-whatsapp-icon {
    background: #198754 !important;
    border-color: #198754 !important;
    color: #fff !important;
}

.quick-sales-page .quick-sale-action-group .btn-whatsapp-icon:hover,
.quick-sales-page .quick-sale-action-group .btn-whatsapp-icon:focus {
    background: #157347 !important;
    border-color: #146c43 !important;
    color: #fff !important;
}

.quick-sales-page .stat-card {
    min-height: 68px !important;
    padding: 9px 11px !important;
}

.quick-sales-page .stat-card small {
    font-size: 9px !important;
    font-weight: 700 !important;
}

.quick-sales-page .stat-card strong {
    font-size: 16px !important;
    font-weight: 750 !important;
    margin-top: 2px !important;
}

/* Toast UI - same styling used by Enquiries */
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

.quick-action-svg {
    display: block !important;
    width: 14px !important;
    height: 14px !important;
    flex: 0 0 14px !important;
    pointer-events: none !important;
}

.btn-whatsapp-icon .quick-action-svg {
    width: 16px !important;
    height: 16px !important;
}

@media (max-width: 991.98px) {
    .quick-sales-page .table-ui {
        min-width: 1040px;
    }
}


/* Quick Sales action icons - match the supplied reference page */
.quick-sales-page .quick-sale-action-group {
    gap: 7px !important;
}

.quick-sales-page .quick-sale-action-group .btn {
    width: 36px !important;
    height: 36px !important;
    min-width: 36px !important;
    max-width: 36px !important;
    padding: 0 !important;
    border-radius: 50% !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-shadow: none !important;
    background: #fff !important;
}

.quick-sales-page .quick-sale-action-group .quick-action-svg {
    width: 16px !important;
    height: 16px !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-invoice {
    border: 1px solid #667085 !important;
    color: #667085 !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-invoice:hover {
    background: #f8fafc !important;
    border-color: #344054 !important;
    color: #344054 !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-edit {
    border: 1.5px solid #0d6efd !important;
    color: #0d6efd !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-edit:hover {
    background: #eff6ff !important;
    border-color: #0b5ed7 !important;
    color: #0b5ed7 !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-whatsapp {
    background: #198754 !important;
    border: 1px solid #198754 !important;
    color: #fff !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-whatsapp:hover {
    background: #157347 !important;
    border-color: #157347 !important;
    color: #fff !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-delete {
    border: 1px solid #dc3545 !important;
    color: #dc3545 !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-delete:hover {
    background: #fff5f5 !important;
    border-color: #bb2d3b !important;
    color: #bb2d3b !important;
}

</style>
<style>

/* FINAL Quick Sales action icon override - exact reference style */
.quick-sales-page .quick-sale-action-group {
    gap: 7px !important;
    white-space: nowrap !important;
}

.quick-sales-page .quick-sale-action-group .btn {
    width: 36px !important;
    height: 36px !important;
    min-width: 36px !important;
    max-width: 36px !important;
    border-radius: 50% !important;
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-shadow: none !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-invoice {
    background: #fff !important;
    border: 1px solid #344054 !important;
    color: #1f2937 !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-invoice:hover {
    background: #f8fafc !important;
    border-color: #111827 !important;
    color: #111827 !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-whatsapp {
    background: #198754 !important;
    border: 1px solid #198754 !important;
    color: #fff !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-whatsapp:hover {
    background: #157347 !important;
    border-color: #157347 !important;
    color: #fff !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-edit {
    background: #fff !important;
    border: 1.5px solid #0d6efd !important;
    color: #0d6efd !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-delete {
    background: #fff !important;
    border: 1px solid #dc3545 !important;
    color: #dc3545 !important;
}

.quick-sales-page .quick-sale-action-group .quick-action-svg {
    width: 16px !important;
    height: 16px !important;
    display: block !important;
    flex: 0 0 16px !important;
}

.quick-sales-page .quick-sale-action-group .qs-ref-whatsapp .quick-action-svg {
    width: 18px !important;
    height: 18px !important;
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
                                    placeholder="Sale No / Customer / Mobile / Venue / Product">
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
                                    <th>Customer</th>
                                    <th>Sale By</th>
                                    <th>Date</th>
                                    <th>Products</th>
                                    <th class="text-end">Qty</th>
                                    <th>Payment</th>
                                    <th class="text-end">Return</th>
                                    <th class="text-end">Total Amount</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted-custom py-4">
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
                                        <?php
                                            $customerName = trim((string)($row['customer_name'] ?? ''));
                                            $customerMobile = trim((string)($row['mobile'] ?? ''));
                                            $customerVenue = trim((string)($row['address'] ?? ''));
                                        ?>
                                        <div class="customer-info">
                                            <span class="customer-name">
                                                <?= qsl_e($customerName !== '' ? $customerName : 'Walk-in Customer') ?>
                                            </span>

                                            <?php if ($customerMobile !== ''): ?>
                                            <span class="customer-mobile">
                                                <?= qsl_e($customerMobile) ?>
                                            </span>
                                            <?php endif; ?>

                                            <?php if ($customerVenue !== ''): ?>
                                            <span class="customer-address"
                                                title="<?= qsl_e($customerVenue) ?>">
                                                Venue: <?= qsl_e($customerVenue) ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?= qsl_e($row['sale_by'] ?? 'System') ?></strong>
                                    </td>
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
                                    <td class="text-end">
                                        <?php $waSent = strtolower((string)($row['whatsapp_status'] ?? '')) === 'sent'; ?>
                                        <div class="quick-sale-action-group">
                                            <a href="quick_sale_invoice_pdf.php?id=<?= (int)$row['id'] ?>"
                                                target="_blank" class="btn btn-action-icon qs-ref-invoice"
                                                title="View Invoice" aria-label="View Invoice">
                                                <?= qsl_action_svg('invoice') ?>
                                            </a>
                                            <button type="button"
                                                class="btn btn-whatsapp-icon qs-ref-whatsapp js-qsl-wa <?= $waSent ? 'wa-sent' : '' ?>"
                                                data-id="<?= (int)$row['id'] ?>"
                                                title="<?= $waSent ? 'Send WhatsApp Again' : 'Send WhatsApp' ?>"
                                                aria-label="Send WhatsApp">
                                                <?= qsl_action_svg('whatsapp') ?>
                                            </button>
                                            <a href="quick-sale.php?edit=<?= (int)$row['id'] ?>"
                                                class="btn btn-action-icon qs-ref-edit"
                                                title="Edit in Quick Sale" aria-label="Edit">
                                                <?= qsl_action_svg('edit') ?>
                                            </a>
                                            <button type="button" class="btn btn-delete-icon qs-ref-delete js-qsl-delete"
                                                data-id="<?= (int)$row['id'] ?>"
                                                data-sale-no="<?= qsl_e($row['sale_no'] ?? '') ?>"
                                                title="Delete" aria-label="Delete">
                                                <?= qsl_action_svg('delete') ?>
                                            </button>
                                        </div>
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

    <script>
    (function () {
        const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
        function escapeToastHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function actionToast(message, type = 'success', title = '') {
            if (!message) return;

            const oldToastWrap = document.getElementById('dynamicActionToastWrap');
            if (oldToastWrap) {
                oldToastWrap.remove();
            }

            const toastTitle = title || (type === 'danger' ? 'Failed' : (type === 'warning' ? 'Warning' :
                'Success'));
            const wrap = document.createElement('div');
            wrap.id = 'dynamicActionToastWrap';
            wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
            wrap.style.zIndex = '12000';

            wrap.innerHTML = `
                <div id="dynamicActionToast" class="toast toast-ui ${type}" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4200">
                    <div class="d-flex">
                        <div class="toast-body">
                            <div class="toast-title">${escapeToastHtml(toastTitle)}</div>
                            <div class="toast-message">${escapeToastHtml(message)}</div>
                        </div>
                        <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;

            document.body.appendChild(wrap);

            const toastEl = document.getElementById('dynamicActionToast');
            if (window.bootstrap && bootstrap.Toast && toastEl) {
                bootstrap.Toast.getOrCreateInstance(toastEl).show();
            }
        }

        async function runAction(action, values = {}) {
            const fd = new FormData();
            fd.set('action', action);
            fd.set('csrf_token', csrfToken);
            Object.entries(values).forEach(([key, value]) => fd.set(key, String(value ?? '')));

            const response = await fetch('api/quick-sale.php', {
                method: 'POST',
                body: fd,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const data = await response.json().catch(() => ({status:false, message:'Invalid server response.'}));
            if (!response.ok || !data.status) throw new Error(data.message || 'Quick Sale action failed.');
            return data;
        }


        document.querySelectorAll('.js-qsl-wa').forEach(button => {
            button.addEventListener('click', async function () {
                const original = this.innerHTML;
                this.disabled = true;
                this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
                try {
                    const data = await runAction('send_whatsapp', {quick_sale_id: this.dataset.id});
                    actionToast(data.message || 'WhatsApp sent.', 'success', 'WhatsApp Sent');
                    this.classList.add('wa-sent');
                } catch (error) {
                    actionToast(error?.message || 'WhatsApp sending failed.', 'danger', 'WhatsApp Failed');
                } finally {
                    this.disabled = false;
                    this.innerHTML = original;
                }
            });
        });

        document.querySelectorAll('.js-qsl-delete').forEach(button => {
            button.addEventListener('click', async function () {
                const saleNo = this.dataset.saleNo || 'this Quick Sale';
                if (!window.confirm('Delete ' + saleNo + '? Stock sold by this Quick Sale will be restored automatically.')) return;
                this.disabled = true;
                try {
                    const data = await runAction('delete', {quick_sale_id: this.dataset.id});
                    actionToast(data.message || 'Quick Sale deleted.', 'success', 'Quick Sale Deleted');
                    setTimeout(() => window.location.reload(), 500);
                } catch (error) {
                    actionToast(error?.message || 'Unable to delete Quick Sale.', 'danger', 'Delete Failed');
                    this.disabled = false;
                }
            });
        });

    })();
    </script>

</body>
</html>
