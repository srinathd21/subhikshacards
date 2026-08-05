<?php
/**
 * proforma_bills.php
 * Fast list page with separate payment page link.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission($conn, 'can_view', 'proforma_bills.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function pb_fast_table_exists(mysqli $conn, string $table): bool
{
    try {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) {
            $res->free();
        }
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function pb_fast_money($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function pb_fast_status_class($balance): string
{
    return ((float)$balance <= 0) ? 'paid' : 'pending';
}

function pb_fast_mobile($mobile): string
{
    $mobile = preg_replace('/\D+/', '', (string)$mobile);
    if ($mobile === '') {
        return '';
    }
    return strlen($mobile) === 10 ? '91' . $mobile : $mobile;
}

function pb_fast_base_url(mysqli $conn): string
{
    $setting = '';
    try {
        if (pb_fast_table_exists($conn, 'system_settings')) {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key IN ('site_url','base_url','app_url') AND TRIM(setting_value) <> '' ORDER BY FIELD(setting_key,'site_url','base_url','app_url') LIMIT 1");
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $setting = trim((string)($row['setting_value'] ?? ''));
        }
    } catch (Throwable $e) {
        $setting = '';
    }

    if ($setting !== '') {
        return rtrim($setting, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return rtrim($scheme . '://' . $host . ($dir === '' || $dir === '/' ? '' : $dir), '/');
}

function pb_fast_tracking_url(mysqli $conn, array $row): string
{
    $token = trim((string)($row['tracking_token'] ?? ''));
    if ($token === '') {
        return '';
    }
    return pb_fast_base_url($conn) . '/customer_tracking.php?token=' . rawurlencode($token);
}


function pb_fast_col_exists(mysqli $conn, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) return $cache[$key];

    try {
        $tableEsc = $conn->real_escape_string($table);
        $colEsc = $conn->real_escape_string($col);
        $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function pb_fast_proforma_pdf_url(mysqli $conn, array $row): string
{
    $id = (int)($row['id'] ?? 0);
    $path = trim((string)($row['proforma_pdf_path'] ?? ''));

    if ($path === '' && $id > 0 && pb_fast_col_exists($conn, 'proforma_bills', 'proforma_pdf_path')) {
        try {
            $stmt = $conn->prepare("SELECT proforma_pdf_path FROM proforma_bills WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $path = trim((string)(($stmt->get_result()->fetch_assoc()['proforma_pdf_path'] ?? '')));
            $stmt->close();
        } catch (Throwable $e) {
            $path = '';
        }
    }

    /* WhatsApp/customer link must always open the same Proforma Bill PDF endpoint without authentication. */
    return $id > 0 ? pb_fast_base_url($conn) . '/proforma_bill_pdf.php?id=' . $id . '&public=1&download=1' : '';
}

function pb_fast_append_invoice_link(mysqli $conn, array $row, string $message): string
{
    $link = pb_fast_proforma_pdf_url($conn, $row);
    if ($link === '') return $message;
    if (strpos($message, $link) !== false) return $message;

    $message = rtrim($message);
    if ($message !== '') $message .= "\n\n";
    return $message . "Proforma Invoice PDF:\n" . $link;
}

function pb_fast_whatsapp_template_row(mysqli $conn, string $templateKey): ?array
{
    try {
        if (!pb_fast_table_exists($conn, 'whatsapp_templates')) return null;

        $stmt = $conn->prepare("
            SELECT id, template_key, template_name, message_body
            FROM whatsapp_templates
            WHERE template_key = ?
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->bind_param('s', $templateKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function pb_fast_render_template(string $message, array $variables): string
{
    foreach ($variables as $key => $value) {
        $key = trim((string)$key);
        $value = (string)$value;
        $message = str_replace('{{' . $key . '}}', $value, $message);
        $message = str_replace('{' . $key . '}', $value, $message);
    }

    return $message;
}

function pb_fast_whatsapp_variables(mysqli $conn, array $row): array
{
    return [
        'customer_name' => trim((string)($row['customer_name'] ?? 'Customer')) ?: 'Customer',
        'proforma_no' => trim((string)($row['proforma_no'] ?? '-')) ?: '-',
        'job_card_no' => trim((string)($row['job_card_no'] ?? '-')) ?: '-',
        'function_type' => trim((string)($row['function_name'] ?? '-')) ?: '-',
        'product_name' => trim((string)($row['item_name'] ?? $row['function_name'] ?? '-')) ?: '-',
        'order_type' => ucfirst((string)($row['order_type'] ?? '-')),
        'final_amount' => pb_fast_money($row['final_amount'] ?? 0),
        'advance_amount' => pb_fast_money($row['advance_amount'] ?? 0),
        'balance_amount' => pb_fast_money($row['balance_amount'] ?? 0),
        'delivery_date' => !empty($row['delivery_date']) ? date('d-m-Y', strtotime((string)$row['delivery_date'])) : '-',
        'tracking_link' => pb_fast_tracking_url($conn, $row),
        'proforma_pdf_link' => pb_fast_proforma_pdf_url($conn, $row),
        'invoice_link' => pb_fast_proforma_pdf_url($conn, $row),
        'proforma_view_link' => !empty($row['id']) ? pb_fast_base_url($conn) . '/proforma_bill_view.php?id=' . (int)$row['id'] : '',
        'mobile' => (string)($row['mobile'] ?? '')
    ];
}

function pb_fast_whatsapp_message(mysqli $conn, array $row): string
{
    $template = pb_fast_whatsapp_template_row($conn, 'proforma_created');
    if (!$template) {
        $vars = pb_fast_whatsapp_variables($conn, $row);
        return pb_fast_append_invoice_link($conn, $row, 'Hi ' . ($vars['customer_name'] ?: 'Customer') . ', your proforma bill ' . ($vars['proforma_no'] ?: '-') . ' has been created. Final Amount: ' . ($vars['final_amount'] ?: '-') . '.');
    }

    $message = pb_fast_render_template((string)$template['message_body'], pb_fast_whatsapp_variables($conn, $row));
    return pb_fast_append_invoice_link($conn, $row, $message);
}

function pb_fast_whatsapp_url(mysqli $conn, array $row): string
{
    $mobile = pb_fast_mobile($row['mobile'] ?? '');
    if ($mobile === '') return '#';

    $message = pb_fast_whatsapp_message($conn, $row);
    if (trim($message) === '') return '#';

    return 'https://wa.me/' . $mobile . '?text=' . rawurlencode($message);
}

function pb_fast_whatsapp_svg(): string
{
    return '<svg viewBox="0 0 32 32" width="17" height="17" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16.04 3C8.85 3 3 8.73 3 15.78c0 2.26.61 4.47 1.77 6.41L3 29l7.02-1.8a13.3 13.3 0 0 0 6.02 1.43C23.23 28.63 29 22.9 29 15.85S23.23 3 16.04 3Zm0 23.45c-1.9 0-3.76-.5-5.39-1.45l-.39-.23-4.16 1.07 1.11-4.01-.26-.41a11.05 11.05 0 0 1-1.73-5.64c0-5.84 4.85-10.6 10.82-10.6 5.96 0 10.81 4.76 10.81 10.67 0 5.84-4.85 10.6-10.81 10.6Zm5.93-7.95c-.32-.16-1.9-.92-2.2-1.03-.3-.11-.52-.16-.74.16-.22.32-.85 1.03-1.04 1.24-.19.22-.38.24-.7.08-.32-.16-1.36-.49-2.59-1.55-.96-.84-1.61-1.88-1.8-2.2-.19-.32-.02-.49.14-.65.14-.14.32-.38.49-.57.16-.19.22-.32.32-.54.11-.22.05-.41-.03-.57-.08-.16-.74-1.76-1.01-2.41-.27-.65-.54-.54-.74-.55h-.63c-.22 0-.57.08-.87.41-.3.32-1.14 1.09-1.14 2.68s1.17 3.12 1.33 3.34c.16.22 2.3 3.46 5.58 4.85.78.33 1.39.53 1.86.68.78.24 1.49.21 2.05.13.63-.09 1.9-.76 2.17-1.49.27-.73.27-1.36.19-1.49-.08-.13-.3-.21-.62-.37Z"/></svg>';
}

if (empty($_SESSION['proforma_csrf'])) {
    $_SESSION['proforma_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['proforma_csrf'];
$currentPage = 'proforma_bills.php';
$canCreate = function_exists('can_create') ? (bool)can_create($conn, $currentPage) : false;
$canUpdate = function_exists('can_update') ? (bool)can_update($conn, $currentPage) : false;
$canEdit = function_exists('can_edit') ? (bool)can_edit($conn, $currentPage) : false;
$canEdit = $canEdit || $canUpdate;
$canDelete = function_exists('can_delete') ? (bool)can_delete($conn, $currentPage) : false;
$canSendWhatsapp = function_exists('can_send_whatsapp') ? (bool)can_send_whatsapp($conn, $currentPage) : false;
$canPayment = $canUpdate;
$canCreateJobCard = $canCreate || $canUpdate;
$message = '';
$messageType = 'success';
$toastTitle = 'Info';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'payment_collected') {
    $message = 'Payment collected successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'payment_cancelled') {
    $message = 'Payment cancelled and balance reverted successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'job_created') {
    $message = 'Job card created successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif ($msg === 'deleted') {
    $message = 'Proforma bill deleted successfully.';
    $messageType = 'success';
    $toastTitle = 'Success';
} elseif (!empty($_GET['err'])) {
    $message = 'Error: ' . trim((string)$_GET['err']);
    $messageType = 'danger';
    $toastTitle = 'Failed';
}

$rows = [];
try {
    $sql = "
        SELECT
            pb.id,
            pb.proforma_no,
            pb.customer_name,
            pb.mobile,
            pb.order_type,
            pb.balance_amount,
            pb.final_amount,
            pb.advance_amount,
            pb.delivery_date,
            COALESCE(ft.function_name, '-') AS function_name,
            pbi.item_name,
            jc.job_card_no,
            jc.tracking_token
        FROM proforma_bills pb
        LEFT JOIN function_types ft ON ft.id = pb.function_type_id
        LEFT JOIN (
            SELECT proforma_bill_id, MIN(id) AS first_item_id
            FROM proforma_bill_items
            GROUP BY proforma_bill_id
        ) first_pbi ON first_pbi.proforma_bill_id = pb.id
        LEFT JOIN proforma_bill_items pbi ON pbi.id = first_pbi.first_item_id
        LEFT JOIN (
            SELECT proforma_bill_id, MAX(job_card_no) AS job_card_no, MAX(tracking_token) AS tracking_token
            FROM job_cards
            GROUP BY proforma_bill_id
        ) jc ON jc.proforma_bill_id = pb.id
        ORDER BY pb.id DESC
        LIMIT 150
    ";
    $res = $conn->query($sql);
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();
} catch (Throwable $e) {
    $message = 'Unable to load proforma bills: ' . $e->getMessage();
    $messageType = 'danger';
    $toastTitle = 'Failed';
}


$totalRows = count($rows);
$pendingRows = 0;
$paidRows = 0;
$jobCardRows = 0;
$totalBalanceAmount = 0.0;

foreach ($rows as $statRow) {
    $balanceAmount = (float)($statRow['balance_amount'] ?? 0);
    $totalBalanceAmount += max(0, $balanceAmount);

    if ($balanceAmount <= 0) {
        $paidRows++;
    } else {
        $pendingRows++;
    }

    if (!empty($statRow['job_card_no'])) {
        $jobCardRows++;
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Proforma Bills - Subhiksha Cards</title>
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
        margin-bottom: 18px;
    }

    .module-page .page-head h1 {
        font-size: 30px;
        font-weight: 900;
        color: var(--text-main);
    }

    .module-card {
        padding: 24px;
    }

    .module-title {
        font-size: 18px;
        font-weight: 900;
        color: var(--text-main);
        margin: 0;
    }

    .stat-card {
        padding: 18px;
        min-height: 112px;
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        color: #fff;
        flex: 0 0 auto;
    }

    .stat-card span {
        display: block;
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 900;
        text-transform: uppercase;
    }

    .stat-card strong {
        font-size: 24px;
        font-weight: 900;
        color: var(--text-main);
    }

    .status-pill {
        font-size: 11px;
        font-weight: 900;
        border-radius: 999px;
        padding: 5px 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        line-height: 1.2;
    }

    .status-pill.paid {
        background: #dcfce7;
        color: #166534;
    }

    .status-pill.pending {
        background: #fef3c7;
        color: #92400e;
    }

    .balance-text {
        font-weight: 900;
        color: #991b1b;
    }

    .balance-text.paid {
        color: #166534;
    }

    .action-buttons {
        display: inline-flex;
        gap: 6px;
        justify-content: flex-end;
        align-items: center;
        flex-wrap: nowrap;
        white-space: nowrap;
    }

    .action-buttons form {
        display: inline-flex;
        margin: 0;
    }

    .btn-action-icon,
    .btn-delete-icon {
        width: 36px !important;
        height: 36px !important;
        min-width: 36px !important;
        max-width: 36px !important;
        padding: 0 !important;
        border-radius: 50% !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        line-height: 1 !important;
    }

    .btn-action-icon svg,
    .btn-delete-icon svg {
        width: 16px !important;
        height: 16px !important;
        stroke-width: 2.5 !important;
        flex: 0 0 auto !important;
    }

    .btn-whatsapp-icon {
        background: #22c55e !important;
        border-color: #22c55e !important;
        color: #fff !important;
    }

    .btn-whatsapp-icon:hover {
        background: #16a34a !important;
        border-color: #16a34a !important;
        color: #fff !important;
    }

    .form-control,
    .form-select {
        border-radius: 14px;
        min-height: 46px;
    }

    .mobile-cards {
        display: none;
    }

    .mobile-card {
        border: 1px solid var(--border-soft);
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
        border-radius: 18px;
        padding: 16px;
        margin-bottom: 12px;
    }

    .mobile-card-title {
        font-size: 16px;
        font-weight: 900;
        color: var(--text-main);
    }

    .mobile-card-subtitle {
        display: block;
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
        margin-top: 4px;
        word-break: break-word;
    }

    .mobile-card-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }

    @media(max-width:767.98px) {
        .module-page .page-head {
            padding: 18px;
            border-radius: 18px;
        }

        .module-page .page-head h1 {
            font-size: 24px;
        }

        .module-page .page-head .btn {
            width: 100%;
        }

        .module-card {
            padding: 16px;
            border-radius: 18px;
        }

        .desktop-table {
            display: none !important;
        }

        .mobile-cards {
            display: block;
        }

        .mobile-card {
            padding: 16px 16px 14px !important;
            border-radius: 20px !important;
        }

        .mobile-card>.d-flex.justify-content-between {
            align-items: flex-start !important;
            gap: 12px !important;
        }

        .mobile-card .status-pill {
            align-self: flex-start !important;
            flex: 0 0 auto !important;
            white-space: nowrap !important;
            font-size: 10px !important;
            max-width: 120px !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }

        .mobile-card-actions .btn-action-icon,
        .mobile-card-actions .btn-delete-icon {
            width: 42px !important;
            height: 42px !important;
            min-width: 42px !important;
            max-width: 42px !important;
            border-radius: 50% !important;
            justify-self: center !important;
            margin: 0 auto !important;
        }

        .mobile-card-actions .btn-action-icon svg,
        .mobile-card-actions .btn-delete-icon svg {
            width: 18px !important;
            height: 18px !important;
        }

        .module-card .form-control#tableSearch {
            min-height: 46px !important;
            border-radius: 16px !important;
        }
    }
    </style>
</head>

<body class="<?= e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
    <div id="mobileOverlay"></div>

    <div class="app-shell">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main id="main">
            <?php include __DIR__ . '/includes/nav.php'; ?>

            <section class="page-section module-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Proforma Bills / Sales Orders</h1>
                            <p class="text-muted-custom mb-0">Create, edit, collect payment and move confirmed orders to
                                job card.</p>
                        </div>

                        <?php if ($canCreate): ?>
                        <a href="create_proforma.php" class="btn btn-primary rounded-pill px-4 fw-bold">
                            Create Proforma Bill
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 12000">
                    <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" aria-live="assertive"
                        aria-atomic="true" data-bs-delay="4200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title"><?= e($toastTitle) ?></div>
                                <div class="toast-message"><?= e($message) ?></div>
                            </div>
                            <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"
                                aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#2563eb,#0ea5e9)">
                                <i data-lucide="receipt"></i>
                            </div>
                            <div>
                                <span>Total Bills</span>
                                <strong><?= number_format($totalRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                                <i data-lucide="clock"></i>
                            </div>
                            <div>
                                <span>Pending</span>
                                <strong><?= number_format($pendingRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#16a34a,#22c55e)">
                                <i data-lucide="check-circle-2"></i>
                            </div>
                            <div>
                                <span>Paid</span>
                                <strong><?= number_format($paidRows) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-3">
                        <div class="card-ui stat-card h-100">
                            <div class="stat-icon" style="background:linear-gradient(135deg,#8b5cf6,#6366f1)">
                                <i data-lucide="briefcase-business"></i>
                            </div>
                            <div>
                                <span>Job Cards</span>
                                <strong><?= number_format($jobCardRows) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div
                        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="module-title">Proforma Bill List</h2>
                            <p class="text-muted-custom mb-0">Correct flow: proforma bill → payment → job card.</p>
                        </div>

                        <div style="max-width:340px;width:100%">
                            <input type="search" id="tableSearch" class="form-control" placeholder="Search...">
                        </div>
                    </div>

                    <div class="table-responsive desktop-table">
                        <table class="table-ui" id="dataTable">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Customer</th>
                                    <th>Function</th>
                                    <th>Order Type</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted-custom py-4">No proforma bills found.
                                    </td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($rows as $row): ?>
                                <?php
                                    $balance = (float)($row['balance_amount'] ?? 0);
                                    $paidClass = $balance <= 0 ? 'paid' : 'pending';
                                    $editUrl = 'create_proforma.php?id=' . (int)$row['id'] . '&mode=edit';
                                ?>
                                <tr>
                                    <td><strong><?= e($row['proforma_no'] ?? '-') ?></strong></td>
                                    <td>
                                        <?= e($row['customer_name'] ?? '-') ?>
                                        <small class="d-block text-muted-custom"><?= e($row['mobile'] ?? '-') ?></small>
                                    </td>
                                    <td><?= e($row['function_name'] ?? '-') ?></td>
                                    <td><?= e(ucfirst((string)($row['order_type'] ?? '-'))) ?></td>
                                    <td><span
                                            class="balance-text <?= e($paidClass) ?>"><?= e(pb_fast_money($balance)) ?></span>
                                    </td>
                                    <td>
                                        <span class="status-pill <?= e($paidClass) ?>">
                                            <?= $balance <= 0 ? 'Paid' : 'Pending' ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="action-buttons">
                                            <a title="View" aria-label="View"
                                                href="proforma_bill_view.php?id=<?= (int)$row['id'] ?>"
                                                class="btn btn-sm btn-outline-secondary rounded-circle fw-bold btn-action-icon">
                                                <i data-lucide="eye"></i>
                                            </a>

                                            <a title="PDF Proforma" aria-label="PDF Proforma"
                                                href="proforma_bill_pdf.php?id=<?= (int)$row['id'] ?>" target="_blank"
                                                class="btn btn-sm btn-outline-dark rounded-circle fw-bold btn-action-icon">
                                                <i data-lucide="file-text"></i>
                                            </a>


                                            <?php if ($canEdit): ?>
                                            <a title="Edit" aria-label="Edit" href="<?= e($editUrl) ?>"
                                                class="btn btn-sm btn-outline-primary rounded-circle fw-bold btn-action-icon">
                                                <i data-lucide="pencil"></i>
                                            </a>
                                            <?php endif; ?>

                                            <?php if ($canPayment): ?>
                                            <a title="Payment" aria-label="Payment"
                                                href="proforma_payment.php?id=<?= (int)$row['id'] ?>"
                                                class="btn btn-sm btn-success rounded-circle fw-bold btn-action-icon">
                                                <i data-lucide="indian-rupee"></i>
                                            </a>
                                            <?php endif; ?>

                                            <?php if ($canSendWhatsapp): ?>
                                            <a title="Send WhatsApp" aria-label="Send WhatsApp" href="#"
                                                data-api-url="api/proforma_bills.php" data-id="<?= (int)$row['id'] ?>"
                                                class="btn btn-sm btn-whatsapp-icon rounded-circle fw-bold btn-action-icon js-proforma-whatsapp-link">
                                                <?= pb_fast_whatsapp_svg() ?>
                                            </a>
                                            <?php endif; ?>

                                            <?php if (empty($row['job_card_no']) && $canCreateJobCard): ?>
                                            <form method="post" action="api/proforma_bills.php"
                                                class="js-api-job-card-form" onsubmit="return false;">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="create_job_card">
                                                <input type="hidden" name="proforma_id" value="<?= (int)$row['id'] ?>">
                                                <button title="Create Job Card" aria-label="Create Job Card"
                                                    type="submit"
                                                    class="btn btn-sm btn-primary rounded-circle fw-bold btn-action-icon">
                                                    <i data-lucide="briefcase-business"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>

                                            <?php if ($canDelete): ?>
                                            <form method="post" action="api/proforma_bills.php"
                                                class="js-api-delete-form" onsubmit="return false;">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete_record">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button title="Delete" aria-label="Delete" type="submit"
                                                    class="btn btn-sm btn-outline-danger rounded-circle fw-bold btn-delete-icon">
                                                    <i data-lucide="trash-2"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-cards" id="mobileCards">
                        <?php if (!$rows): ?>
                        <div class="mobile-card text-center text-muted-custom">No proforma bills found.</div>
                        <?php endif; ?>

                        <?php foreach ($rows as $row): ?>
                        <?php
                            $balance = (float)($row['balance_amount'] ?? 0);
                            $paidClass = $balance <= 0 ? 'paid' : 'pending';
                            $editUrl = 'create_proforma.php?id=' . (int)$row['id'] . '&mode=edit';
                        ?>
                        <div class="mobile-card">
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <div class="mobile-card-title"><?= e($row['customer_name'] ?? '-') ?></div>
                                    <span class="mobile-card-subtitle">No: <?= e($row['proforma_no'] ?? '-') ?></span>
                                    <span class="mobile-card-subtitle">Mobile: <?= e($row['mobile'] ?? '-') ?></span>
                                    <span class="mobile-card-subtitle">Function:
                                        <?= e($row['function_name'] ?? '-') ?></span>
                                    <span class="mobile-card-subtitle">Order Type:
                                        <?= e(ucfirst((string)($row['order_type'] ?? '-'))) ?></span>
                                    <span class="mobile-card-subtitle balance-text <?= e($paidClass) ?>">Balance:
                                        <?= e(pb_fast_money($balance)) ?></span>
                                </div>

                                <span class="status-pill <?= e($paidClass) ?>">
                                    <?= $balance <= 0 ? 'Paid' : 'Pending' ?>
                                </span>
                            </div>

                            <div class="mobile-card-actions">
                                <a title="View" aria-label="View"
                                    href="proforma_bill_view.php?id=<?= (int)$row['id'] ?>"
                                    class="btn btn-sm btn-outline-secondary rounded-circle fw-bold btn-action-icon"><i
                                        data-lucide="eye"></i></a>

                                <a title="PDF Proforma" aria-label="PDF Proforma"
                                    href="proforma_bill_pdf.php?id=<?= (int)$row['id'] ?>" target="_blank"
                                    class="btn btn-sm btn-outline-dark rounded-circle fw-bold btn-action-icon"><i
                                        data-lucide="file-text"></i></a>


                                <?php if ($canEdit): ?>
                                <a title="Edit" aria-label="Edit" href="<?= e($editUrl) ?>"
                                    class="btn btn-sm btn-outline-primary rounded-circle fw-bold btn-action-icon"><i
                                        data-lucide="pencil"></i></a>
                                <?php endif; ?>

                                <?php if ($canPayment): ?>
                                <a title="Payment" aria-label="Payment"
                                    href="proforma_payment.php?id=<?= (int)$row['id'] ?>"
                                    class="btn btn-sm btn-success rounded-circle fw-bold btn-action-icon"><i
                                        data-lucide="indian-rupee"></i></a>
                                <?php endif; ?>

                                <?php if ($canSendWhatsapp): ?>
                                <a title="Send WhatsApp" aria-label="Send WhatsApp" href="#"
                                    data-api-url="api/proforma_bills.php" data-id="<?= (int)$row['id'] ?>"
                                    class="btn btn-sm btn-whatsapp-icon rounded-circle fw-bold btn-action-icon js-proforma-whatsapp-link"><?= pb_fast_whatsapp_svg() ?></a>
                                <?php endif; ?>

                                <?php if (empty($row['job_card_no']) && $canCreateJobCard): ?>
                                <form method="post" action="api/proforma_bills.php" class="js-api-job-card-form"
                                    onsubmit="return false;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="create_job_card">
                                    <input type="hidden" name="proforma_id" value="<?= (int)$row['id'] ?>">
                                    <button title="Create Job Card" aria-label="Create Job Card" type="submit"
                                        class="btn btn-sm btn-primary rounded-circle fw-bold btn-action-icon"><i
                                            data-lucide="briefcase-business"></i></button>
                                </form>
                                <?php endif; ?>

                                <?php if ($canDelete): ?>
                                <form method="post" action="api/proforma_bills.php" class="js-api-delete-form"
                                    onsubmit="return false;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_record">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button title="Delete" aria-label="Delete" type="submit"
                                        class="btn btn-sm btn-outline-danger rounded-circle fw-bold btn-delete-icon"><i
                                            data-lucide="trash-2"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include __DIR__ . '/includes/rightsidebar.php'; ?>
    </div>

    <?php include __DIR__ . '/includes/script.php'; ?>

    <script>
    (function() {
        function showToast(message, type = 'success', title = '') {
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
                            <div class="toast-title">${toastTitle}</div>
                            <div class="toast-message">${message}</div>
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

        const pageToastEl = document.getElementById('pageToast');
        if (pageToastEl && window.bootstrap && bootstrap.Toast) {
            bootstrap.Toast.getOrCreateInstance(pageToastEl).show();
        }

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }

        document.getElementById('tableSearch')?.addEventListener('input', function() {
            const value = this.value.toLowerCase().trim();

            document.querySelectorAll('#dataTable tbody tr').forEach(function(row) {
                row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
            });

            document.querySelectorAll('#mobileCards .mobile-card').forEach(function(card) {
                card.style.display = card.textContent.toLowerCase().includes(value) ? '' : 'none';
            });
        });

        document.querySelectorAll('.js-proforma-whatsapp-link').forEach(function(link) {
            link.addEventListener('click', function(event) {
                event.preventDefault();

                const id = link.getAttribute('data-id') || '';
                const apiUrl = link.getAttribute('data-api-url') ||
                    'api/proforma_bills.php';

                if (!id) {
                    showToast('Invalid proforma bill id.', 'danger', 'Failed');
                    return;
                }

                if (link.dataset.sending === '1') {
                    return;
                }

                link.dataset.sending = '1';
                link.classList.add('disabled');
                link.setAttribute('aria-disabled', 'true');

                const formData = new FormData();
                formData.append('csrf_token', <?= json_encode($csrfToken) ?>);
                formData.append('action', 'send_whatsapp_api');
                formData.append('id', id);

                fetch(apiUrl, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        showToast(
                            data.message || (data.status ?
                                'Proforma bill sent through WhatsApp API.' :
                                'WhatsApp API sending failed.'),
                            data.status ? 'success' : 'danger',
                            data.status ? 'Success' : 'Failed'
                        );

                        if (data.open_whatsapp_url) {
                            window.location.href = data.open_whatsapp_url;
                        }
                    })
                    .catch(() => {
                        showToast(
                            'WhatsApp API request failed. Please check API settings and internet connection.',
                            'danger',
                            'Failed'
                        );
                    })
                    .finally(() => {
                        link.dataset.sending = '0';
                        link.classList.remove('disabled');
                        link.removeAttribute('aria-disabled');
                    });
            });
        });

        document.querySelectorAll('.js-api-job-card-form').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
            });

            form.querySelector('button[type="submit"]')?.addEventListener('click', function() {
                if (!confirm('Create job card?')) return;

                const formData = new FormData(form);
                fetch('api/proforma_bills.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        showToast(data.message || (data.status ? 'Job card created.' :
                                'Job card failed.'),
                            data.status ? 'success' : 'danger', data.status ? 'Success' :
                            'Failed');

                        if (data.status) {
                            setTimeout(() => window.location.reload(), 900);
                        }
                    })
                    .catch(() => showToast('API request failed.', 'danger', 'Failed'));
            });
        });

        document.querySelectorAll('.js-api-delete-form').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
            });

            form.querySelector('button[type="submit"]')?.addEventListener('click', function() {
                if (!confirm('Delete this proforma bill?')) return;

                const formData = new FormData(form);
                fetch('api/proforma_bills.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        showToast(data.message || (data.status ? 'Deleted successfully.' :
                                'Delete failed.'),
                            data.status ? 'success' : 'danger', data.status ? 'Success' :
                            'Failed');

                        if (data.status) {
                            setTimeout(() => window.location.reload(), 900);
                        }
                    })
                    .catch(() => showToast('API request failed.', 'danger', 'Failed'));
            });
        });
    })();
    </script>
</body>

</html>