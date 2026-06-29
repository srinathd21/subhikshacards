<?php
/**
 * create_proforma.php
 * Subiksha Card ERP - Create Proforma Bill / Sales Order
 *
 * Uses the current u966043993_subhiksha schema:
 * quotations -> proforma_bills -> payments -> job_cards -> job_tracking -> customer_tracking_links
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (function_exists('require_permission')) {
    require_permission($conn, 'can_create', 'proforma_bills.php');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function cpTableExists(mysqli $conn, string $table): bool
{
    try {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}


function cpColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    try {
        $tableSafe = $conn->real_escape_string($table);
        $columnSafe = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function cpPost(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function cpInt($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function cpFloat($value): float
{
    return (float)str_replace(',', '', (string)$value);
}

function cpDateOrNull(string $value): ?string
{
    return $value !== '' ? $value : null;
}

function cpTimeOrNull(string $value): ?string
{
    return $value !== '' ? $value : null;
}

function cpNextNo(mysqli $conn, string $table, string $column, string $prefix): string
{
    $datePart = date('ymd');
    $like = $prefix . '-' . $datePart . '-%';

    try {
        if (!cpTableExists($conn, $table) || !cpColumnExists($conn, $table, $column)) {
            return $prefix . '-' . $datePart . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        }

        $tableSafe = $conn->real_escape_string($table);
        $columnSafe = $conn->real_escape_string($column);
        $stmt = $conn->prepare("SELECT `{$columnSafe}` AS last_no FROM `{$tableSafe}` WHERE `{$columnSafe}` LIKE ? ORDER BY `{$columnSafe}` DESC LIMIT 1");
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $lastNo = (string)($row['last_no'] ?? '');
        $next = 1;
        if ($lastNo !== '' && preg_match('/-(\d+)$/', $lastNo, $match)) {
            $next = ((int)$match[1]) + 1;
        }

        return $prefix . '-' . $datePart . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return $prefix . '-' . $datePart . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}

function cpStatusId(mysqli $conn, string $table, array $keys): ?int
{
    if (!cpTableExists($conn, $table) || !$keys) return null;
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $types = str_repeat('s', count($keys));
    try {
        $stmt = $conn->prepare("SELECT id FROM `{$table}` WHERE status_key IN ({$placeholders}) ORDER BY sort_order ASC, id ASC LIMIT 1");
        $stmt->bind_param($types, ...$keys);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function cpRoleId(mysqli $conn, ?string $roleKey): ?int
{
    if (!$roleKey || !cpTableExists($conn, 'roles')) return null;
    try {
        $stmt = $conn->prepare("SELECT id FROM roles WHERE role_key = ? LIMIT 1");
        $stmt->bind_param('s', $roleKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function cpPrintingRoleKey(mysqli $conn, ?int $printingTypeId): ?string
{
    if (!$printingTypeId || !cpTableExists($conn, 'printing_types')) return null;
    try {
        $stmt = $conn->prepare("SELECT role_key FROM printing_types WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $printingTypeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (string)$row['role_key'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function cpCustomerId(mysqli $conn, string $customerName, string $mobile, string $address, string $gstNumber): ?int
{
    if ($customerName === '' || $mobile === '' || !cpTableExists($conn, 'customers')) return null;
    $userId = (int)($_SESSION['user_id'] ?? 0);

    try {
        $stmt = $conn->prepare("SELECT id FROM customers WHERE mobile = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('s', $mobile);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $id = (int)$row['id'];
            $stmt = $conn->prepare("UPDATE customers SET customer_name = ?, address = IF(? = '', address, ?), gst_number = IF(? = '', gst_number, ?), updated_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('sssssii', $customerName, $address, $address, $gstNumber, $gstNumber, $userId, $id);
            $stmt->execute();
            $stmt->close();
            return $id;
        }

        $stmt = $conn->prepare("INSERT INTO customers (customer_name, mobile, address, gst_number, is_active, created_by, created_at) VALUES (?, ?, ?, ?, 1, ?, NOW())");
        $stmt->bind_param('ssssi', $customerName, $mobile, $address, $gstNumber, $userId);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    } catch (Throwable $e) {
        return null;
    }
}

function cpLog(mysqli $conn, string $actionKey, string $module, string $table, int $recordId, string $description, array $newValues = []): void
{
    if (!cpTableExists($conn, 'activity_logs')) return;
    try {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        $actionTypeId = null;
        if (cpTableExists($conn, 'activity_action_types')) {
            $stmt = $conn->prepare("SELECT id FROM activity_action_types WHERE action_key = ? LIMIT 1");
            $stmt->bind_param('s', $actionKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) $actionTypeId = (int)$row['id'];
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $oldJson = null;
        $newJson = $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, role_id, action_type_id, action_key, module_name, table_name, record_id, old_values, new_values, description, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param('iiisssisssss', $userId, $roleId, $actionTypeId, $actionKey, $module, $table, $recordId, $oldJson, $newJson, $description, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Do not block business flow for logging failures.
    }
}

function cpFetchAll(mysqli $conn, string $sql): array
{
    $rows = [];
    try {
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) $rows[] = $row;
            $res->free();
        }
    } catch (Throwable $e) {
    }
    return $rows;
}

if (empty($_SESSION['create_proforma_csrf'])) {
    $_SESSION['create_proforma_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['create_proforma_csrf'];

$message = '';
$messageType = 'success';
$createdProformaNo = '';
$createdJobCardNo = '';

if (!empty($_SESSION['create_proforma_flash']) && is_array($_SESSION['create_proforma_flash'])) {
    $flash = $_SESSION['create_proforma_flash'];
    $message = (string)($flash['message'] ?? '');
    $messageType = (string)($flash['message_type'] ?? 'success');
    $createdProformaNo = (string)($flash['proforma_no'] ?? '');
    $createdJobCardNo = (string)($flash['job_card_no'] ?? '');
    unset($_SESSION['create_proforma_flash']);
}

/* Create Proforma backend moved to api/create_proforma.php */

$quotations = cpFetchAll($conn, "SELECT q.*, e.enquiry_no, ft.function_name, ft.field_group, c.gst_number, c.address AS customer_address FROM quotations q LEFT JOIN enquiries e ON e.id = q.enquiry_id LEFT JOIN function_types ft ON ft.id = q.function_type_id LEFT JOIN customers c ON c.id = q.customer_id LEFT JOIN proforma_bills pb ON pb.quotation_id = q.id WHERE pb.id IS NULL ORDER BY q.id DESC LIMIT 500");
$functionTypes = cpFetchAll($conn, "SELECT id, function_name, field_group FROM function_types WHERE is_active = 1 ORDER BY sort_order ASC, function_name ASC");
$products = cpFetchAll($conn, "SELECT id, product_name, default_order_type, default_price FROM products WHERE is_active = 1 ORDER BY product_name ASC");
$printingTypes = cpFetchAll($conn, "SELECT id, printing_name, printing_key, role_key, is_for_readymade, is_for_customized FROM printing_types WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
$printingSubTypes = cpFetchAll($conn, "SELECT id, printing_type_id, sub_type_name FROM printing_sub_types WHERE is_active = 1 ORDER BY printing_type_id ASC, sort_order ASC, id ASC");
$readymadeSteps = cpFetchAll($conn, "SELECT id, step_name, sort_order FROM workflow_steps WHERE order_type = 'readymade' AND is_active = 1 ORDER BY sort_order ASC");
$customizedSteps = cpFetchAll($conn, "SELECT id, step_name, sort_order FROM workflow_steps WHERE order_type = 'customized' AND is_active = 1 ORDER BY sort_order ASC");

$quotationJson = json_encode($quotations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$subTypeJson = json_encode($printingSubTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$printingTypeJson = json_encode($printingTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$stepsJson = json_encode(['readymade' => $readymadeSteps, 'customized' => $customizedSteps], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Create Proforma Bill - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>
    <style>
        .module-page .page-head{padding:24px 28px;margin-bottom:18px}.module-page .page-head h1{font-size:30px;font-weight:900;color:var(--text-main)}.module-card{padding:24px}.section-title{font-size:16px;font-weight:900;color:var(--text-main);margin-bottom:12px}.form-control,.form-select{border-radius:14px;min-height:46px}.form-label{font-size:12px;text-transform:uppercase;letter-spacing:.02em}.soft-panel{border:1px solid var(--border-soft);border-radius:20px;padding:18px;background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg))}.amount-box{border:1px solid var(--border-soft);border-radius:18px;padding:14px;background:color-mix(in srgb,var(--card-bg) 96%,var(--body-bg))}.amount-box small{display:block;color:var(--text-muted);font-size:11px;font-weight:900;text-transform:uppercase}.amount-box strong{display:block;margin-top:4px;color:var(--text-main);font-size:20px;font-weight:900}.toast-ui{border:0;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.18);overflow:hidden;min-width:320px;max-width:460px}.toast-ui.success{background:#dcfce7;color:#14532d}.toast-ui.danger{background:#fee2e2;color:#7f1d1d}.toast-title{font-size:14px;font-weight:900}.toast-message{font-size:13px;font-weight:800;line-height:1.45}.hide-field{display:none!important}.workflow-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.workflow-step{border:1px solid var(--border-soft);border-radius:16px;padding:12px;background:var(--card-bg)}.workflow-step strong{font-size:13px}.select2-container{width:100%!important}@media(max-width:767.98px){.module-page .page-head{padding:18px;border-radius:18px}.module-page .page-head h1{font-size:24px}.module-card{padding:16px;border-radius:18px}}
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
                        <h1 class="mb-1">Create Proforma Bill</h1>
                        <p class="text-muted-custom mb-0">Convert quotation to sales order, collect advance, create job card and initialize tracking.</p>
                    </div>
                    <a href="proforma_bills.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Back to Proforma Bills</a>
                </div>
            </div>

            <?php if ($message !== ''): ?>
            <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000">
                <div id="pageToast" class="toast toast-ui <?= e($messageType) ?>" role="alert" data-bs-delay="5200">
                    <div class="d-flex"><div class="toast-body"><div class="toast-title"><?= e($messageType === 'success' ? 'Success' : 'Failed') ?></div><div class="toast-message"><?= e($message) ?><?php if ($createdProformaNo): ?><br>Proforma: <?= e($createdProformaNo) ?><?php endif; ?><?php if ($createdJobCardNo): ?><br>Job Card: <?= e($createdJobCardNo) ?><?php endif; ?></div></div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"></button></div>
                </div>
            </div>
            <?php endif; ?>

            <form method="post" action="api/create_proforma.php" class="card-ui module-card" id="proformaForm">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <div class="row g-3">
                    <div class="col-12"><div class="section-title">1. Quotation / Customer Details</div></div>
                    <div class="col-12">
                        <label class="form-label fw-bold">Quotation Reference</label>
                        <select name="quotation_id" id="quotation_id" class="form-select select2-autotype" data-placeholder="Search quotation / customer / mobile">
                            <option value="">Direct Proforma Bill</option>
                            <?php foreach ($quotations as $q): ?>
                            <option value="<?= e($q['id']) ?>"><?= e($q['quotation_no']) ?> - <?= e($q['customer_name']) ?> - <?= e($q['mobile']) ?> - <?= e($q['function_name'] ?? '-') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label fw-bold">Customer Name *</label><input type="text" name="customer_name" id="customer_name" class="form-control" required></div>
                    <div class="col-md-4"><label class="form-label fw-bold">Mobile *</label><input type="text" name="mobile" id="mobile" class="form-control" required></div>
                    <div class="col-md-4"><label class="form-label fw-bold">Function / Product Type</label><select name="function_type_id" id="function_type_id" class="form-select select2-autotype"><option value="">Select Type</option><?php foreach ($functionTypes as $type): ?><option value="<?= e($type['id']) ?>" data-field-group="<?= e($type['field_group']) ?>"><?= e($type['function_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4 wedding-field"><label class="form-label fw-bold">Bride Name</label><input type="text" name="bride_name" id="bride_name" class="form-control"></div>
                    <div class="col-md-4 wedding-field"><label class="form-label fw-bold">Groom Name</label><input type="text" name="groom_name" id="groom_name" class="form-control"></div>
                    <div class="col-md-4 event-field"><label class="form-label fw-bold">Function Date</label><input type="date" name="function_date" id="function_date" class="form-control"></div>
                    <div class="col-md-4 event-field"><label class="form-label fw-bold">Function Time</label><input type="time" name="function_time" id="function_time" class="form-control"></div>
                    <div class="col-md-8 event-field"><label class="form-label fw-bold">Venue</label><input type="text" name="venue" id="venue" class="form-control"></div>

                    <div class="col-12 mt-3"><div class="section-title">2. Billing Details</div></div>
                    <div class="col-md-4"><label class="form-label fw-bold">Billing Name</label><input type="text" name="billing_name" id="billing_name" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label fw-bold">Billing Mobile</label><input type="text" name="billing_mobile" id="billing_mobile" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label fw-bold">GST Number</label><input type="text" name="gst_number" id="gst_number" class="form-control"></div>
                    <div class="col-12"><label class="form-label fw-bold">Billing Address</label><textarea name="billing_address" id="billing_address" class="form-control" rows="2"></textarea></div>

                    <div class="col-12 mt-3"><div class="section-title">3. Order / Production Details</div></div>
                    <div class="col-md-3"><label class="form-label fw-bold">Order Type *</label><select name="order_type" id="order_type" class="form-select" required><option value="readymade">Readymade</option><option value="customized">Customized</option></select></div>
                    <div class="col-md-3"><label class="form-label fw-bold">Product Master</label><select name="product_id" id="product_id" class="form-select select2-autotype"><option value="">Manual Product</option><?php foreach ($products as $p): ?><option value="<?= e($p['id']) ?>" data-name="<?= e($p['product_name']) ?>" data-price="<?= e($p['default_price']) ?>" data-order-type="<?= e($p['default_order_type']) ?>"><?= e($p['product_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label fw-bold">Product / Item Name *</label><input type="text" name="product_name" id="product_name" class="form-control" required placeholder="Type new product name if not in master"></div>
                    <div class="col-12"><label class="form-label fw-bold">Item Description</label><textarea name="description" id="description" class="form-control" rows="2"></textarea></div>
                    <div class="col-md-3"><label class="form-label fw-bold">Quantity *</label><input type="number" step="0.01" min="0" name="qty" id="qty" class="form-control" value="1" required></div>
                    <div class="col-md-3"><label class="form-label fw-bold">Rate *</label><input type="number" step="0.01" min="0" name="rate" id="rate" class="form-control" value="0" required></div>
                    <div class="col-md-3"><label class="form-label fw-bold">Discount</label><input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount" class="form-control" value="0"></div>
                    <div class="col-md-3"><label class="form-label fw-bold">Delivery Date *</label><input type="date" name="delivery_date" id="delivery_date" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label fw-bold">Printing Type *</label><select name="printing_type_id" id="printing_type_id" class="form-select" required><option value="">Select Printing Type</option><?php foreach ($printingTypes as $pt): ?><option value="<?= e($pt['id']) ?>" data-readymade="<?= e($pt['is_for_readymade']) ?>" data-customized="<?= e($pt['is_for_customized']) ?>" data-role-key="<?= e($pt['role_key']) ?>"><?= e($pt['printing_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4 readymade-field"><label class="form-label fw-bold">Screen Print Sub-Type</label><select name="printing_sub_type_id" id="printing_sub_type_id" class="form-select"><option value="">Not Applicable</option></select></div>
                    <div class="col-md-4 readymade-field d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="finishing_required" id="finishing_required" value="1"><label class="form-check-label fw-bold" for="finishing_required">With Finishing</label></div></div>
                    <div class="col-md-3 customized-field"><label class="form-label fw-bold">Size *</label><input type="text" name="size_text" id="size_text" class="form-control" placeholder="Eg: 19x25"></div>
                    <div class="col-md-3 customized-field"><label class="form-label fw-bold">GSM Thickness *</label><input type="text" name="gsm_thickness" id="gsm_thickness" class="form-control"></div>
                    <div class="col-md-3 customized-field"><label class="form-label fw-bold">Printing Side *</label><select name="printing_side" id="printing_side" class="form-select"><option value="">Select</option><option value="single">Single Side</option><option value="double">Double Side</option></select></div>
                    <div class="col-md-3 customized-field"><label class="form-label fw-bold">Screening *</label><select name="screening_type" id="screening_type" class="form-select"><option value="">Select</option><option value="regular">Regular Screening</option><option value="special">Special Screening</option></select></div>
                    <div class="col-md-3 customized-field d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="lamination_required" id="lamination_required" value="1"><label class="form-check-label fw-bold" for="lamination_required">Lamination Required</label></div></div>
                    <div class="col-md-3 customized-field lamination-type-wrap"><label class="form-label fw-bold">Lamination Type</label><select name="lamination_type" id="lamination_type" class="form-select"><option value="">Select</option><option value="glossy">Glossy</option><option value="matte">Matte</option><option value="special">Special</option></select></div>

                    <div class="col-12 mt-3"><div class="section-title">4. Amount / Payment</div></div>
                    <div class="col-md-3"><div class="amount-box"><small>Sub Total</small><strong id="subTotalText">₹0.00</strong></div></div>
                    <div class="col-md-3"><div class="amount-box"><small>Final Amount</small><strong id="finalAmountText">₹0.00</strong></div></div>
                    <div class="col-md-3"><label class="form-label fw-bold">Advance Amount</label><input type="number" step="0.01" min="0" name="advance_amount" id="advance_amount" class="form-control" value="0"></div>
                    <div class="col-md-3"><div class="amount-box"><small>Balance</small><strong id="balanceAmountText">₹0.00</strong></div></div>
                    <div class="col-md-3"><label class="form-label fw-bold">Payment Mode</label><select name="payment_mode" id="payment_mode" class="form-select"><option value="cash">Cash</option><option value="upi">UPI</option><option value="bank">Bank</option><option value="cheque">Cheque</option><option value="card">Card</option><option value="other">Other</option></select></div>
                    <div class="col-md-9"><label class="form-label fw-bold">Payment Reference / Remarks</label><input type="text" name="payment_reference" id="payment_reference" class="form-control"></div>
                    <div class="col-12"><label class="form-label fw-bold">Internal Remarks</label><textarea name="remarks" id="remarks" class="form-control" rows="2"></textarea></div>

                    <div class="col-12 mt-3"><div class="section-title">5. Job Card and Tracking</div></div>
                    <div class="col-md-4"><div class="form-check form-switch"><input type="hidden" name="auto_create_job_card" value="1"><input class="form-check-input" type="checkbox" id="auto_create_job_card" value="1" checked disabled><label class="form-check-label fw-bold" for="auto_create_job_card">Create Job Card Automatically</label></div></div>
                    <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="create_tracking_link" id="create_tracking_link" value="1" checked><label class="form-check-label fw-bold" for="create_tracking_link">Create Customer Tracking Link</label></div></div>
                    <div class="col-12"><div class="soft-panel"><div class="d-flex justify-content-between align-items-center mb-2"><strong>Planned Dates for Tracking Steps</strong><small class="text-muted-custom">Optional, Sales can fill now or later</small></div><div id="workflowSteps" class="workflow-grid"></div></div></div>

                    <div class="col-12 d-flex flex-column flex-md-row justify-content-end gap-2 mt-4">
                        <button type="reset" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Reset</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold" id="createProformaBtn">Create Proforma Bill</button>
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
const quotations = <?= $quotationJson ?: '[]' ?>;
const printingSubTypes = <?= $subTypeJson ?: '[]' ?>;
const printingTypes = <?= $printingTypeJson ?: '[]' ?>;
const workflowData = <?= $stepsJson ?: '{"readymade":[],"customized":[]}' ?>;

function rupee(value){return '₹' + Number(value || 0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}
function setValue(id,value){const el=document.getElementById(id); if(el) el.value=value ?? '';}
function getValue(id){return document.getElementById(id)?.value || '';}
function showToastOnLoad(){const toastEl=document.getElementById('pageToast'); if(toastEl && window.bootstrap){new bootstrap.Toast(toastEl).show();}}
function showActionToast(message,type='success',titleText=''){if(!message)return; const old=document.getElementById('dynamicActionToastWrap'); if(old)old.remove(); const title=titleText || (type==='danger'?'Failed':'Success'); const wrap=document.createElement('div'); wrap.id='dynamicActionToastWrap'; wrap.className='toast-container position-fixed top-0 end-0 p-3'; wrap.style.zIndex='12000'; wrap.innerHTML=`<div id="dynamicActionToast" class="toast toast-ui ${type}" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4200"><div class="d-flex"><div class="toast-body"><div class="toast-title">${title}</div><div class="toast-message">${message}</div></div><button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>`; document.body.appendChild(wrap); const toastEl=document.getElementById('dynamicActionToast'); if(window.bootstrap&&toastEl)new bootstrap.Toast(toastEl).show();}
function initSelect2(context){if(window.initSelect2AutoType){window.initSelect2AutoType(context||document);return;} if(window.jQuery && $.fn.select2){$(context||document).find('select.select2-autotype').each(function(){const $s=$(this); if($s.hasClass('select2-hidden-accessible')) $s.select2('destroy'); $s.select2({theme:'bootstrap-5',width:'100%',placeholder:$s.data('placeholder')||'Search and select'});});}}
function refreshSelect(id){if(window.jQuery && $.fn.select2){$('#'+id).trigger('change.select2');}}
function calculate(){const qty=parseFloat(getValue('qty'))||0; const rate=parseFloat(getValue('rate'))||0; const discount=parseFloat(getValue('discount_amount'))||0; const advance=parseFloat(getValue('advance_amount'))||0; const sub=Math.max(0,qty*rate); const final=Math.max(0,sub-discount); const balance=Math.max(0,final-advance); document.getElementById('subTotalText').textContent=rupee(sub); document.getElementById('finalAmountText').textContent=rupee(final); document.getElementById('balanceAmountText').textContent=rupee(balance);}
function functionGroup(){const opt=document.getElementById('function_type_id')?.selectedOptions[0]; return opt?.dataset.fieldGroup || 'other';}
function toggleFunctionFields(){const g=functionGroup(); document.querySelectorAll('.wedding-field').forEach(el=>el.classList.toggle('hide-field',g!=='wedding_reception')); document.querySelectorAll('.event-field').forEach(el=>el.classList.toggle('hide-field',!(g==='wedding_reception'||g==='event')));}
function toggleOrderType(){const type=getValue('order_type')||'readymade'; document.querySelectorAll('.readymade-field').forEach(el=>el.classList.toggle('hide-field',type!=='readymade')); document.querySelectorAll('.customized-field').forEach(el=>el.classList.toggle('hide-field',type!=='customized')); document.getElementById('delivery_date').required = true; ['size_text','gsm_thickness','printing_side','screening_type'].forEach(id=>{const el=document.getElementById(id); if(el) el.required=(type==='customized');}); updatePrintingTypeOptions(); renderWorkflowSteps();}
function updatePrintingTypeOptions(){const type=getValue('order_type')||'readymade'; const select=document.getElementById('printing_type_id'); Array.from(select.options).forEach(opt=>{if(!opt.value) return; const ok=type==='readymade' ? opt.dataset.readymade==='1' : opt.dataset.customized==='1'; opt.hidden=!ok; opt.disabled=!ok;}); if(select.selectedOptions[0]?.disabled){select.value='';} updateSubTypes();}
function updateSubTypes(){const pt=parseInt(getValue('printing_type_id')||0,10); const sub=document.getElementById('printing_sub_type_id'); sub.innerHTML='<option value="">Not Applicable</option>'; printingSubTypes.filter(s=>parseInt(s.printing_type_id,10)===pt).forEach(s=>{const opt=document.createElement('option'); opt.value=s.id; opt.textContent=s.sub_type_name; sub.appendChild(opt);});}
function toggleLamination(){document.querySelectorAll('.lamination-type-wrap').forEach(el=>el.classList.toggle('hide-field',!document.getElementById('lamination_required').checked));}
function renderWorkflowSteps(){const type=getValue('order_type')||'readymade'; const box=document.getElementById('workflowSteps'); box.innerHTML=''; (workflowData[type]||[]).forEach(step=>{const div=document.createElement('div'); div.className='workflow-step'; div.innerHTML='<strong>'+step.sort_order+'. '+step.step_name+'</strong><div class="row g-2 mt-1"><div class="col-6"><small>Start</small><input type="date" class="form-control" name="planned_step['+step.id+'][start]"></div><div class="col-6"><small>Complete</small><input type="date" class="form-control" name="planned_step['+step.id+'][completion]"></div></div>'; box.appendChild(div);});}
function loadQuotation(){const id=parseInt(getValue('quotation_id')||0,10); const q=quotations.find(row=>parseInt(row.id,10)===id); if(!q) return; setValue('customer_name',q.customer_name||''); setValue('mobile',q.mobile||''); setValue('billing_name',q.billing_name||q.customer_name||''); setValue('billing_mobile',q.billing_mobile||q.mobile||''); setValue('billing_address',q.billing_address||q.address||q.customer_address||''); setValue('gst_number',q.gst_number||''); setValue('function_type_id',q.function_type_id||''); setValue('bride_name',q.bride_name||''); setValue('groom_name',q.groom_name||''); setValue('venue',q.venue||''); setValue('function_date',q.function_date||''); setValue('function_time',q.function_time||''); setValue('qty',q.total_qty||1); setValue('description',q.item_details||q.description||''); setValue('product_name',q.product_name||q.item_name||q.item_details||''); setValue('rate',((parseFloat(q.total_qty||0)>0)?(parseFloat(q.sub_total||0)/parseFloat(q.total_qty||1)):parseFloat(q.sub_total||0)).toFixed(2)); setValue('discount_amount',q.discount_amount||0); refreshSelect('function_type_id'); toggleFunctionFields(); calculate();}
function productChanged(){const opt=document.getElementById('product_id')?.selectedOptions[0]; if(!opt||!opt.value) return; if(!getValue('product_name')) setValue('product_name',opt.dataset.name||''); if(parseFloat(opt.dataset.price||0)>0) setValue('rate',opt.dataset.price); const ot=opt.dataset.orderType; if(ot==='readymade'||ot==='customized') setValue('order_type',ot); toggleOrderType(); calculate();}
['qty','rate','discount_amount','advance_amount'].forEach(id=>document.getElementById(id)?.addEventListener('input',calculate));
document.getElementById('quotation_id')?.addEventListener('change',loadQuotation);
document.getElementById('function_type_id')?.addEventListener('change',toggleFunctionFields);
document.getElementById('order_type')?.addEventListener('change',toggleOrderType);
document.getElementById('printing_type_id')?.addEventListener('change',updateSubTypes);
document.getElementById('lamination_required')?.addEventListener('change',toggleLamination);
document.getElementById('product_id')?.addEventListener('change',productChanged);
document.getElementById('proformaForm')?.addEventListener('reset',()=>setTimeout(()=>{toggleFunctionFields();toggleOrderType();toggleLamination();calculate();},50));
if(window.jQuery){$('#quotation_id').on('select2:select select2:clear',loadQuotation); $('#function_type_id').on('select2:select select2:clear',toggleFunctionFields); $('#product_id').on('select2:select select2:clear',productChanged);}
initSelect2(document); toggleFunctionFields(); toggleOrderType(); toggleLamination(); calculate(); showToastOnLoad();
document.getElementById('proformaForm')?.addEventListener('submit',function(event){
    event.preventDefault();

    const form = this;
    const btn = document.getElementById('createProformaBtn');
    const oldText = btn ? btn.textContent : '';

    if(btn){btn.disabled=true; btn.textContent='Creating...';}

    fetch('api/create_proforma.php',{
        method:'POST',
        body:new FormData(form),
        credentials:'same-origin'
    })
    .then(response=>response.json())
    .then(data=>{
        if(data.status){
            let toastMessage = data.message || 'Proforma bill created successfully.';
            if(data.proforma_no){toastMessage += '<br>Proforma: ' + data.proforma_no;}
            if(data.job_card_no){toastMessage += '<br>Job Card: ' + data.job_card_no;}
            if(data.whatsapp_mode === 'api'){toastMessage += '<br>WhatsApp: Sent using API.';}
            if(data.whatsapp_mode === 'manual'){toastMessage += '<br>WhatsApp: Manual window opened.';}

            showActionToast(toastMessage,'success','Success');

            if(data.open_whatsapp_url){
                window.open(data.open_whatsapp_url,'_blank');
            }

            setTimeout(()=>{window.location.href = data.redirect_url || 'proforma_bills.php';},1200);
        }else{
            showActionToast(data.message || 'Proforma bill creation failed.','danger','Failed');
            if(btn){btn.disabled=false; btn.textContent=oldText || 'Create Proforma Bill';}
        }
    })
    .catch(()=>{
        showActionToast('Request failed. Please try again.','danger','Failed');
        if(btn){btn.disabled=false; btn.textContent=oldText || 'Create Proforma Bill';}
    });
});

</script>
</body>
</html>