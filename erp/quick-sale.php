<?php
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
 * Admin must never be blocked from a newly added module just because the
 * role_page_permissions row has not been created yet.
 *
 * This also supports installations where role_key / role_name is not copied
 * into the session by checking the current role_id against roles.
 */
function qs_is_admin_role(mysqli $conn): bool
{
    $sessionRoleKey = strtolower(trim((string)($_SESSION['role_key'] ?? '')));
    $sessionRoleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));

    if (
        in_array($sessionRoleKey, ['admin', 'super_admin', 'business_admin'], true) ||
        $sessionRoleName === 'admin'
    ) {
        return true;
    }

    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($roleId <= 0) {
        return false;
    }

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

        return
            in_array($roleKey, ['admin', 'super_admin', 'business_admin'], true) ||
            $roleName === 'admin';
    } catch (Throwable $e) {
        return false;
    }
}

if (!qs_is_admin_role($conn)) {
    require_permission($conn, 'can_view', 'quick-sale.php');
}

if (!function_exists('qs_e')) {
    function qs_e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('qs_money')) {
    function qs_money($value): string
    {
        return '₹' . number_format((float)$value, 2);
    }
}

if (!function_exists('qs_table_exists')) {
    function qs_table_exists(mysqli $conn, string $table): bool
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
}

if (empty($_SESSION['quick_sale_csrf'])) {
    $_SESSION['quick_sale_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['quick_sale_csrf'];

/*
 * QUICK SALE PRODUCT MASTER - CREATE ON ENTER
 *
 * When Sales types a product which does not exist and presses Enter in the
 * Select2 search, create the Product Master immediately. This is separate from
 * Save Quick Sale, so the new product is already available in Product Master
 * even before the sale is saved.
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    (string)($_POST['quick_sale_action'] ?? '') === 'create_product'
) {
    header('Content-Type: application/json; charset=utf-8');

    $ajaxResponse = static function (bool $status, string $message, array $extra = []): void {
        echo json_encode(array_merge([
            'status' => $status,
            'message' => $message,
        ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    try {
        $postedToken = (string)($_POST['csrf_token'] ?? '');
        if ($postedToken === '' || !hash_equals((string)$_SESSION['quick_sale_csrf'], $postedToken)) {
            throw new RuntimeException('Invalid CSRF token. Refresh the page and try again.');
        }

        if (!qs_is_admin_role($conn)) {
            if (function_exists('permission_allowed')) {
                if (!permission_allowed($conn, 'can_create', 'quick-sale.php')) {
                    throw new RuntimeException('You do not have permission to create Quick Sale products.');
                }
            } elseif (function_exists('can_create') && !can_create($conn, 'quick-sale.php')) {
                throw new RuntimeException('You do not have permission to create Quick Sale products.');
            }
        }

        $productName = trim((string)($_POST['product_name'] ?? ''));
        if ($productName === '') {
            throw new RuntimeException('Product Name is required.');
        }
        $productNameLength = function_exists('mb_strlen') ? mb_strlen($productName) : strlen($productName);
        if ($productNameLength > 200) {
            throw new RuntimeException('Product Name cannot exceed 200 characters.');
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $createdBy = $userId > 0 ? $userId : null;
        $wasCreated = false;
        $wasRestored = false;

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                SELECT
                    id,
                    product_name,
                    default_price,
                    is_active,
                    COALESCE(is_removed, 0) AS is_removed
                FROM products
                WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->bind_param('s', $productName);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($product) {
                $productId = (int)$product['id'];

                if ((int)$product['is_active'] !== 1 || (int)$product['is_removed'] === 1) {
                    $stmt = $conn->prepare("
                        UPDATE products
                        SET
                            is_active = 1,
                            is_removed = 0,
                            removed_at = NULL,
                            removed_by = NULL,
                            removal_reason = NULL,
                            updated_by = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->bind_param('ii', $createdBy, $productId);
                    $stmt->execute();
                    $stmt->close();
                    $wasRestored = true;
                }
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO products
                        (
                            product_name,
                            default_order_type,
                            default_price,
                            is_active,
                            is_removed,
                            created_by,
                            created_at
                        )
                    VALUES
                        (?, 'readymade', 0, 1, 0, ?, NOW())
                ");
                $stmt->bind_param('si', $productName, $createdBy);
                $stmt->execute();
                $productId = (int)$stmt->insert_id;
                $stmt->close();

                if ($productId <= 0) {
                    throw new RuntimeException('Unable to create Product Master.');
                }
                $wasCreated = true;
            }

            $stmt = $conn->prepare("
                INSERT INTO product_stock
                    (
                        product_id,
                        on_hand_stock,
                        reserved_stock,
                        minimum_stock,
                        low_stock_alert,
                        created_at
                    )
                VALUES
                    (?, 0, 0, 0, 0, NOW())
                ON DUPLICATE KEY UPDATE
                    updated_at = NOW()
            ");
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("
                SELECT
                    p.id,
                    p.product_name,
                    p.default_price,
                    COALESCE(ps.on_hand_stock, 0) AS on_hand_stock,
                    COALESCE(ps.reserved_stock, 0) AS reserved_stock,
                    (COALESCE(ps.on_hand_stock, 0) - COALESCE(ps.reserved_stock, 0)) AS available_stock
                FROM products p
                LEFT JOIN product_stock ps ON ps.product_id = p.id
                WHERE p.id = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $savedProduct = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$savedProduct) {
                throw new RuntimeException('Product Master verification failed.');
            }

            $conn->commit();

            $ajaxResponse(true, $wasCreated
                ? 'New Product Master created.'
                : ($wasRestored ? 'Existing Product Master restored.' : 'Product already exists.'), [
                'product' => $savedProduct,
                'was_created' => $wasCreated,
                'was_restored' => $wasRestored,
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    } catch (Throwable $e) {
        $ajaxResponse(false, $e->getMessage());
    }
}

$products = [];
try {
    $sql = "
        SELECT
            p.id,
            p.product_name,
            p.default_price,
            COALESCE(ps.on_hand_stock, 0) AS on_hand_stock,
            COALESCE(ps.reserved_stock, 0) AS reserved_stock,
            (COALESCE(ps.on_hand_stock, 0) - COALESCE(ps.reserved_stock, 0)) AS available_stock
        FROM products p
        LEFT JOIN product_stock ps ON ps.product_id = p.id
        WHERE p.is_active = 1
          AND COALESCE(p.is_removed, 0) = 0
        ORDER BY
            CASE
                WHEN (COALESCE(ps.on_hand_stock, 0) - COALESCE(ps.reserved_stock, 0)) > 0 THEN 0
                ELSE 1
            END,
            p.product_name ASC
    ";
    $res = $conn->query($sql);
    while ($row = $res->fetch_assoc()) {
        $products[] = $row;
    }
    $res->free();
} catch (Throwable $e) {
    $products = [];
}

$recentSales = [];
$todaySaleCount = 0;
$todaySaleAmount = 0.0;
$hasQuickSalePayments = qs_table_exists($conn, 'quick_sale_payments');

try {
    $paymentSelect = $hasQuickSalePayments
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
                SELECT SUM(qsp.return_amount)
                FROM quick_sale_payments qsp
                WHERE qsp.quick_sale_id = qs.id
            ), 0) AS return_amount
        "
        : "0 AS cash_received, 0 AS upi_received, 0 AS return_amount";

    $res = $conn->query("
        SELECT
            qs.id,
            qs.sale_no,
            qs.customer_name,
            qs.mobile,
            qs.address,
            qs.total_amount,
            qs.created_at,
            COALESCE(
                MAX(NULLIF(u.name, '')),
                MAX(NULLIF(u.username, '')),
                'System'
            ) AS sale_by,
            COUNT(qsi.id) AS item_count,
            COALESCE(SUM(qsi.qty), 0) AS total_qty,
            {$paymentSelect}
        FROM quick_sales qs
        LEFT JOIN quick_sale_items qsi ON qsi.quick_sale_id = qs.id
        LEFT JOIN users u ON u.id = qs.created_by
        GROUP BY qs.id
        ORDER BY qs.id DESC
        LIMIT 5
    ");
    while ($row = $res->fetch_assoc()) {
        $recentSales[] = $row;
    }
    $res->free();

    $res = $conn->query("
        SELECT
            COUNT(*) AS sale_count,
            COALESCE(SUM(total_amount), 0) AS sale_amount
        FROM quick_sales
        WHERE DATE(created_at) = CURDATE()
    ");
    $today = $res ? $res->fetch_assoc() : null;
    if ($res) $res->free();

    $todaySaleCount = (int)($today['sale_count'] ?? 0);
    $todaySaleAmount = (float)($today['sale_amount'] ?? 0);
} catch (Throwable $e) {
    // SQL migration may not have been run yet. The UI still loads and explains it.
}

$successMessage = trim((string)($_GET['message'] ?? ''));

$quickSaleSidebarReady = false;
try {
    $stmt = $conn->prepare("
        SELECT id
        FROM sidebar_items
        WHERE menu_key = 'quick_sale'
          AND route = 'quick-sale.php'
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute();
    $quickSaleSidebarReady = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $e) {
    $quickSaleSidebarReady = false;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Quick Sale - Subhiksha Cards</title>
    <?php include __DIR__ . '/includes/links.php'; ?>
    <?php include __DIR__ . '/includes/theme-loader.php'; ?>

    <style>
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
        border-radius: 20px;
        margin-bottom: 18px;
    }

    .section-title {
        font-size: 17px;
        font-weight: 900;
        color: var(--text-main);
        margin-bottom: 12px;
    }

    .quick-help {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 12px 14px;
        font-size: 12px;
        font-weight: 800;
        color: var(--text-muted);
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
    }

    .stock-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        border: 1px solid var(--border-soft);
        border-radius: 999px;
        padding: 5px 9px;
        font-size: 11px;
        font-weight: 900;
    }

    .stock-pill.ok {
        color: #166534;
        background: #dcfce7;
        border-color: #bbf7d0;
    }

    .stock-pill.bad {
        color: #991b1b;
        background: #fee2e2;
        border-color: #fecaca;
    }

    .quick-total {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 18px;
        background: color-mix(in srgb, var(--card-bg) 96%, var(--body-bg));
    }

    .quick-total small {
        display: block;
        font-size: 11px;
        font-weight: 900;
        color: var(--text-muted);
        text-transform: uppercase;
    }

    .quick-total strong {
        display: block;
        font-size: 28px;
        font-weight: 900;
        color: var(--text-main);
        margin-top: 3px;
    }

    .stat-card {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 16px;
        height: 100%;
    }

    .stat-card small {
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
    }

    .stat-card strong {
        display: block;
        margin-top: 4px;
        font-size: 22px;
        font-weight: 900;
        color: var(--text-main);
    }

    .table-ui th {
        white-space: nowrap;
    }

    .toast-ui {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
        min-width: 320px;
        max-width: 420px;
        overflow: hidden;
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

    .product-select-wrap {
        display: flex;
        gap: 8px;
        align-items: stretch;
    }

    .product-select-wrap .select2-container {
        flex: 1 1 auto;
        min-width: 0;
    }

    .product-search-clear {
        flex: 0 0 44px;
        width: 44px;
        min-width: 44px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 900;
        border: 1.5px solid #ef4444;
        background: transparent;
        color: #ef4444;
        transition: .18s ease;
    }

    .product-search-clear:hover,
    .product-search-clear:focus {
        background: #fee2e2;
        color: #b91c1c;
        border-color: #dc2626;
        outline: none;
        box-shadow: 0 0 0 3px rgba(239, 68, 68, .12);
    }

    .item-actions {
        display: inline-flex;
        gap: 6px;
    }

    .item-actions .btn {
        width: 34px;
        height: 34px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }


    .customer-panel {
        border: 1px solid var(--border-soft);
        border-radius: 20px;
        padding: 18px;
        background: color-mix(in srgb, #2563eb 4%, var(--card-bg));
        margin-bottom: 18px;
    }

    .customer-panel .customer-note {
        font-size: 11px;
        font-weight: 800;
        color: var(--text-muted);
        margin-top: 5px;
    }

    .customer-panel .form-control {
        min-height: 44px;
    }

    .payment-panel {
        border: 1px solid var(--border-soft);
        border-radius: 20px;
        padding: 18px;
        background: color-mix(in srgb, var(--success-color, #16a34a) 5%, var(--card-bg));
    }

    .payment-mode-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(220px, 1fr));
        gap: 14px;
    }

    .payment-mode-card {
        border: 1.5px solid var(--border-soft);
        border-radius: 18px;
        padding: 16px;
        background: var(--card-bg);
        cursor: pointer;
        transition: .18s ease;
        user-select: none;
    }

    .payment-mode-card.active {
        border-color: #2563eb;
        background: rgba(37, 99, 235, .08);
        box-shadow: 0 12px 30px rgba(37, 99, 235, .10);
    }

    .payment-mode-card .mode-head {
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }

    .payment-mode-card .mode-head input {
        width: 22px;
        height: 22px;
        margin-top: 1px;
        accent-color: #2563eb;
    }

    .payment-mode-card strong {
        font-size: 16px;
        font-weight: 900;
        color: var(--text-main);
    }

    .payment-mode-card .mode-note {
        display: block;
        font-size: 11px;
        font-weight: 800;
        color: var(--text-muted);
        margin-top: 2px;
    }

    .payment-detail-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(220px, 1fr));
        gap: 14px;
        margin-top: 14px;
    }

    .payment-detail-box {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 14px;
        background: var(--card-bg);
    }

    .payment-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(140px, 1fr));
        gap: 10px;
        margin-top: 14px;
    }

    .payment-summary-box {
        border: 1px solid var(--border-soft);
        border-radius: 15px;
        padding: 12px 13px;
        background: var(--card-bg);
    }

    .payment-summary-box small {
        display: block;
        color: var(--text-muted);
        font-size: 10.5px;
        font-weight: 900;
        text-transform: uppercase;
        margin-bottom: 4px;
    }

    .payment-summary-box strong {
        display: block;
        color: var(--text-main);
        font-size: 18px;
        font-weight: 900;
    }

    .payment-summary-box.return-box {
        border-color: rgba(245, 158, 11, .42);
        background: rgba(245, 158, 11, .10);
    }

    .payment-summary-box.return-box strong {
        color: #9a3412;
    }

    .payment-validation-message {
        border-radius: 13px;
        padding: 9px 11px;
        font-size: 12px;
        font-weight: 900;
        margin-top: 10px;
    }

    .payment-validation-message.ok {
        background: #dcfce7;
        color: #166534;
    }

    .payment-validation-message.bad {
        background: #fee2e2;
        color: #991b1b;
    }

    .denom-modal-compact .modal-dialog {
        max-width: 610px;
    }

    .denom-modal-compact .modal-content {
        border: 0;
        border-radius: 22px;
        overflow: hidden;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .26);
    }

    .denom-modal-compact .modal-header,
    .denom-modal-compact .modal-footer {
        padding: 12px 16px;
    }

    .denom-modal-compact .modal-body {
        padding: 14px 16px;
        max-height: 70vh;
        overflow: auto;
    }

    .denom-section-title {
        font-size: 13px;
        font-weight: 900;
        color: var(--text-main);
        margin: 8px 0 6px;
    }

    .denom-line {
        display: grid;
        grid-template-columns: 86px 72px 1fr;
        align-items: center;
        gap: 8px;
        margin-bottom: 7px;
        font-weight: 800;
        font-size: 13px;
    }

    .denom-line input {
        min-height: 36px;
        border-radius: 10px;
        text-align: center;
        font-weight: 900;
    }

    .denom-line .denom-amount {
        min-height: 36px;
        border: 1px solid var(--border-soft);
        border-radius: 10px;
        padding: 7px 9px;
        background: color-mix(in srgb, var(--card-bg) 94%, var(--body-bg));
        font-weight: 900;
        text-align: right;
    }

    .denom-total-box {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
        border-radius: 14px;
        background: rgba(22, 163, 74, .10);
        border: 1px solid rgba(22, 163, 74, .24);
        padding: 9px 11px;
        font-weight: 900;
        font-size: 13px;
    }

    .modal-backdrop-fallback {
        position: fixed;
        inset: 0;
        z-index: 1040;
        background: rgba(15, 23, 42, .50);
    }

    .modal.show.modal-fallback {
        display: block;
        z-index: 1055;
        background: transparent;
    }

    body.modal-open-fallback {
        overflow: hidden;
    }

    @media (max-width: 767.98px) {
        .module-page .page-head {
            padding: 18px;
        }

        .module-page .page-head h1 {
            font-size: 24px;
        }

        .module-card {
            padding: 16px;
        }

        .payment-mode-grid,
        .payment-detail-grid,
        .payment-summary-grid,
        .denom-total-box {
            grid-template-columns: 1fr;
        }

        .denom-line {
            grid-template-columns: 76px 66px 1fr;
            font-size: 12px;
        }
    }
    </style>
</head>

<body class="<?= qs_e(($theme['layout_density'] ?? '') === 'compact' ? 'layout-compact' : '') ?>">
    <div id="mobileOverlay"></div>

    <div class="app-shell">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main id="main">
            <?php include __DIR__ . '/includes/nav.php'; ?>

            <section class="page-section module-page">
                <div class="card-ui page-head">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h1 class="mb-1">Quick Sale</h1>
                            <p class="text-muted-custom mb-0">
                                Direct card sale with instant stock reduction and invoice PDF. No Job Card or production workflow.
                            </p>
                        </div>
                    </div>
                </div>

                <?php if (!$quickSaleSidebarReady && qs_is_admin_role($conn)): ?>
                <div class="alert alert-warning rounded-4 fw-bold">
                    Quick Sale page is available, but its Sidebar/Permission database entry is missing.
                    Run <code>quick_sale_sidebar_permission_fix.sql</code> once.
                </div>
                <?php endif; ?>

                <?php if ($successMessage !== ''): ?>
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:12000">
                    <div id="pageToast" class="toast toast-ui success" role="alert" data-bs-delay="4200">
                        <div class="d-flex">
                            <div class="toast-body">
                                <div class="toast-title">Success</div>
                                <div class="toast-message"><?= qs_e($successMessage) ?></div>
                            </div>
                            <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="card-ui stat-card">
                            <small>Today's Quick Sales</small>
                            <strong><?= number_format($todaySaleCount) ?></strong>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card-ui stat-card">
                            <small>Today's Quick Sale Amount</small>
                            <strong><?= qs_e(qs_money($todaySaleAmount)) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="card-ui module-card">
                    <div class="section-title">Direct Sale</div>

                    <div class="quick-help mb-3">
                        Enter the customer details first, then add Product Name, Quantity and Price. The customer
                        mobile number is used to send the Quick Sale invoice automatically through WhatsApp after the
                        sale is saved. Quick Sale can continue even when stock is insufficient.
                    </div>

                    <form id="quickSaleForm" autocomplete="off" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= qs_e($csrfToken) ?>">
                        <input type="hidden" name="items_json" id="items_json" value="[]">

                        <div class="customer-panel">
                            <div class="section-title mb-2">Customer Details</div>
                            <div class="row g-3">
                                <div class="col-lg-4 col-md-6">
                                    <label class="form-label fw-bold">Customer Name *</label>
                                    <input type="text" name="customer_name" id="customer_name"
                                        class="form-control" maxlength="200"
                                        placeholder="Enter customer name" autocomplete="name">
                                </div>

                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label fw-bold">Mobile Number *</label>
                                    <input type="text" name="customer_mobile" id="customer_mobile"
                                        class="form-control" inputmode="numeric" maxlength="10"
                                        placeholder="10 digit mobile number" autocomplete="tel">
                                    <div class="customer-note">
                                        Invoice will be sent to this WhatsApp number.
                                    </div>
                                </div>

                                <div class="col-lg-5">
                                    <label class="form-label fw-bold">Address</label>
                                    <input type="text" name="customer_address" id="customer_address"
                                        class="form-control" maxlength="1000"
                                        placeholder="Optional customer address" autocomplete="street-address">
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 align-items-end">
                            <div class="col-lg-6">
                                <label class="form-label fw-bold">Product Name *</label>
                                <div class="product-select-wrap">
                                    <select id="product_id" class="form-select select2-autotype"
                                        data-placeholder="Search or Add Product">
                                        <option value="">Search or Add Product</option>
                                        <?php foreach ($products as $product): ?>
                                        <?php
                                        $available = (float)($product['available_stock'] ?? 0);
                                    ?>
                                        <option value="<?= (int)$product['id'] ?>"
                                            data-name="<?= qs_e($product['product_name']) ?>"
                                            data-price="<?= qs_e($product['default_price']) ?>"
                                            data-on-hand="<?= qs_e($product['on_hand_stock']) ?>"
                                            data-reserved="<?= qs_e($product['reserved_stock']) ?>"
                                            data-available="<?= qs_e($available) ?>">
                                            <?= qs_e($product['product_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="button" id="clearProductSearchBtn" class="product-search-clear"
                                        title="Clear Product Search" aria-label="Clear Product Search">
                                        ×
                                    </button>
                                </div>

                                <div class="mt-2" id="stockInfo">
                                    <span class="text-muted-custom small fw-bold">
                                        Select a product to see stock.
                                    </span>
                                </div>
                            </div>

                            <div class="col-lg-2 col-md-4">
                                <label class="form-label fw-bold">Qty *</label>
                                <input type="number" min="0.01" step="0.01" id="qty" class="form-control" value=""
                                    disabled>
                            </div>

                            <div class="col-lg-2 col-md-4">
                                <label class="form-label fw-bold">Price *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" min="0.01" step="0.01" id="rate" class="form-control" value=""
                                        disabled>
                                </div>
                            </div>

                            <div class="col-lg-2 col-md-4">
                                <button type="button" id="addProductBtn" class="btn btn-primary w-100 fw-bold">
                                    Add Product
                                </button>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="section-title mb-2">Sale Items</div>

                            <div id="emptyItems" class="quick-help text-center">
                                No product added yet.
                            </div>

                            <div id="itemsTableWrap" class="table-responsive d-none">
                                <table class="table-ui">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Product Name</th>
                                            <th class="text-end">Qty</th>
                                            <th class="text-end">Price</th>
                                            <th class="text-end">Amount</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsBody"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="row g-3 align-items-end mt-2">
                            <div class="col-lg-8">
                                <div class="quick-help">
                                    Quick Sale does not create a Job Card, tracking stages, printing workflow or stock
                                    reservation. After saving, an invoice PDF is generated using the existing Proforma
                                    Bill PDF design. If stock is insufficient, the shortage remains visible as negative stock.
                                </div>
                            </div>

                            <div class="col-lg-2 col-md-6">
                                <div class="quick-total">
                                    <small>Total Qty</small>
                                    <strong id="totalQty">0</strong>
                                </div>
                            </div>

                            <div class="col-lg-2 col-md-6">
                                <div class="quick-total">
                                    <small>Total Amount</small>
                                    <strong id="grandTotal">₹0.00</strong>
                                </div>
                            </div>
                        </div>


                        <div class="mt-4">
                            <div class="section-title mb-2">Payment</div>

                            <div class="payment-panel">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Payment Date *</label>
                                        <input type="date" name="payment_date" id="payment_date" class="form-control"
                                            value="<?= qs_e(date('Y-m-d')) ?>">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label fw-bold">Payment Mode *</label>
                                        <div class="payment-mode-grid">
                                            <label class="payment-mode-card active" id="cashModeCard" for="use_cash">
                                                <span class="mode-head">
                                                    <input type="checkbox" id="use_cash" name="use_cash" value="1"
                                                        checked>
                                                    <span>
                                                        <strong>Cash</strong>
                                                        <span class="mode-note">Cash denomination is mandatory.</span>
                                                    </span>
                                                </span>
                                            </label>

                                            <label class="payment-mode-card" id="upiModeCard" for="use_upi">
                                                <span class="mode-head">
                                                    <input type="checkbox" id="use_upi" name="use_upi" value="1">
                                                    <span>
                                                        <strong>UPI</strong>
                                                        <span class="mode-note">Can be used alone or split with
                                                            Cash.</span>
                                                    </span>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="payment-detail-grid">
                                    <div class="payment-detail-box" id="cashPaymentBox">
                                        <label class="form-label fw-bold">Cash Received *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" min="0.01" step="0.01" name="cash_amount"
                                                id="cash_amount" class="form-control" placeholder="Cash received">
                                        </div>
                                        <div
                                            class="d-flex justify-content-between align-items-center gap-2 mt-2 flex-wrap">
                                            <small class="text-muted-custom fw-bold">
                                                Excess Cash is allowed and will be shown as Return Amount.
                                            </small>
                                            <button type="button" id="openCashDenomBtn"
                                                class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold">
                                                Enter Cash Denomination
                                            </button>
                                        </div>
                                    </div>

                                    <div class="payment-detail-box d-none" id="upiPaymentBox">
                                        <label class="form-label fw-bold">UPI Amount *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" min="0.01" step="0.01" name="upi_amount"
                                                id="upi_amount" class="form-control" placeholder="UPI amount" disabled>
                                        </div>

                                        <label class="form-label fw-bold mt-2">UPI Reference</label>
                                        <input type="text" name="upi_reference" id="upi_reference" class="form-control"
                                            placeholder="Optional UPI transaction ID" disabled>
                                    </div>
                                </div>

                                <div class="payment-summary-grid" aria-live="polite">
                                    <div class="payment-summary-box">
                                        <small>Sale Total</small>
                                        <strong id="paymentSaleTotal">₹0.00</strong>
                                    </div>
                                    <div class="payment-summary-box">
                                        <small>Total Received</small>
                                        <strong id="paymentReceived">₹0.00</strong>
                                    </div>
                                    <div class="payment-summary-box">
                                        <small>Applied to Sale</small>
                                        <strong id="paymentApplied">₹0.00</strong>
                                    </div>
                                    <div class="payment-summary-box return-box">
                                        <small>Return Amount</small>
                                        <strong id="paymentReturn">₹0.00</strong>
                                    </div>
                                </div>

                                <div id="paymentValidationMessage" class="payment-validation-message bad">
                                    Add a product to calculate payment.
                                </div>

                                <div class="mt-3">
                                    <label class="form-label fw-bold">Payment Remarks</label>
                                    <textarea name="payment_remarks" id="payment_remarks" class="form-control" rows="2"
                                        placeholder="Optional payment remarks"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade denom-modal-compact" id="cashDenominationModal" tabindex="-1"
                            aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div>
                                            <h5 class="modal-title fw-black mb-0">Cash Denomination</h5>
                                            <small class="text-muted-custom fw-bold">
                                                Denomination total must equal Cash Received.
                                            </small>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                            aria-label="Close"></button>
                                    </div>

                                    <div class="modal-body">
                                        <div class="denom-total-box mb-2">
                                            <span>Cash Received: <span id="denomTarget">₹0.00</span></span>
                                            <span>Total: <span id="denomTotal">₹0.00</span></span>
                                            <span>Return: <span id="denomReturn">₹0.00</span></span>
                                        </div>

                                        <div class="denom-section-title">Notes:</div>
                                        <?php foreach ([500, 200, 100, 50, 20, 10] as $noteValue): ?>
                                        <div class="denom-line">
                                            <input type="number" min="0" step="1" value="0"
                                                class="form-control cash-denom-count"
                                                name="cash_note_<?= (int)$noteValue ?>"
                                                data-value="<?= (int)$noteValue ?>" form="quickSaleForm">
                                            <span>x ₹<?= (int)$noteValue ?></span>
                                            <span class="denom-amount">₹<span class="denom-row-total">0.00</span></span>
                                        </div>
                                        <?php endforeach; ?>

                                        <div class="denom-section-title">Coins:</div>
                                        <?php foreach ([20, 10, 5, 2, 1] as $coinValue): ?>
                                        <div class="denom-line">
                                            <input type="number" min="0" step="1" value="0"
                                                class="form-control cash-denom-count"
                                                name="cash_coin_<?= (int)$coinValue ?>"
                                                data-value="<?= (int)$coinValue ?>" form="quickSaleForm">
                                            <span>x ₹<?= (int)$coinValue ?></span>
                                            <span class="denom-amount">₹<span class="denom-row-total">0.00</span></span>
                                        </div>
                                        <?php endforeach; ?>

                                        <div id="denomError"
                                            class="alert alert-danger rounded-4 fw-bold py-2 px-3 mt-2 mb-0 d-none">
                                        </div>
                                    </div>

                                    <div class="modal-footer">
                                        <button type="button"
                                            class="btn btn-outline-secondary rounded-pill px-3 fw-bold"
                                            data-bs-dismiss="modal">Close</button>
                                        <button type="button" class="btn btn-primary rounded-pill px-3 fw-bold"
                                            id="saveDenomBtn">Save Denomination</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <button type="button" id="clearBtn"
                                class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                                Clear
                            </button>
                            <button type="submit" id="saveBtn" class="btn btn-success rounded-pill px-5 fw-bold">
                                Save Quick Sale & Generate Invoice
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card-ui module-card">
                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                        <div class="section-title mb-0">Recent Quick Sales</div>
                        <a href="quick-sales.php" class="btn btn-outline-primary rounded-pill px-4 fw-bold">
                            View All
                        </a>
                    </div>

                    <div class="table-responsive">
                        <table class="table-ui">
                            <thead>
                                <tr>
                                    <th>Sale No</th>
                                    <th>Customer</th>
                                    <th>Sale By</th>
                                    <th>Date</th>
                                    <th class="text-end">Products</th>
                                    <th class="text-end">Qty</th>
                                    <th>Payment</th>
                                    <th class="text-end">Return</th>
                                    <th class="text-end">Total Amount</th>
                                    <th class="text-end">Invoice</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$recentSales): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted-custom py-4">
                                        No Quick Sale found.
                                    </td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($recentSales as $sale): ?>
                                <tr>
                                    <td><strong><?= qs_e($sale['sale_no'] ?? '-') ?></strong></td>
                                    <td>
                                        <?php
                                            $recentCustomerName = trim((string)($sale['customer_name'] ?? ''));
                                            $recentCustomerMobile = trim((string)($sale['mobile'] ?? ''));
                                            $recentCustomerAddress = trim((string)($sale['address'] ?? ''));
                                        ?>
                                        <strong>
                                            <?= qs_e($recentCustomerName !== '' ? $recentCustomerName : 'Walk-in Customer') ?>
                                        </strong>
                                        <?php if ($recentCustomerMobile !== ''): ?>
                                        <small class="d-block text-muted-custom fw-bold">
                                            <?= qs_e($recentCustomerMobile) ?>
                                        </small>
                                        <?php endif; ?>
                                        <?php if ($recentCustomerAddress !== ''): ?>
                                        <small class="d-block text-muted-custom"
                                            title="<?= qs_e($recentCustomerAddress) ?>">
                                            <?= qs_e($recentCustomerAddress) ?>
                                        </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= qs_e($sale['sale_by'] ?? 'System') ?></strong>
                                    </td>
                                    <td><?= !empty($sale['created_at']) ? qs_e(date('d-m-Y h:i A', strtotime($sale['created_at']))) : '-' ?>
                                    </td>
                                    <td class="text-end"><?= number_format((int)($sale['item_count'] ?? 0)) ?></td>
                                    <td class="text-end"><?= number_format((float)($sale['total_qty'] ?? 0), 2) ?></td>
                                    <td>
                                        <?php
                                            $cashReceived = (float)($sale['cash_received'] ?? 0);
                                            $upiReceived = (float)($sale['upi_received'] ?? 0);
                                            $paymentParts = [];
                                            if ($cashReceived > 0.009) $paymentParts[] = 'Cash ' . qs_money($cashReceived);
                                            if ($upiReceived > 0.009) $paymentParts[] = 'UPI ' . qs_money($upiReceived);
                                        ?>
                                        <?= qs_e($paymentParts ? implode(' + ', $paymentParts) : '-') ?>
                                    </td>
                                    <td class="text-end"><?= qs_e(qs_money($sale['return_amount'] ?? 0)) ?></td>
                                    <td class="text-end fw-bold"><?= qs_e(qs_money($sale['total_amount'] ?? 0)) ?></td>
                                    <td class="text-end">
                                        <a href="quick_sale_invoice_pdf.php?id=<?= (int)$sale['id'] ?>"
                                           target="_blank"
                                           class="btn btn-sm btn-outline-primary rounded-pill fw-bold px-3">
                                            Invoice
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
        const items = [];
        let lastSaleTotal = 0;
        let fallbackBackdrop = null;

        const quickSaleForm = document.getElementById('quickSaleForm');
        const cashCheck = document.getElementById('use_cash');
        const upiCheck = document.getElementById('use_upi');
        const cashModeCard = document.getElementById('cashModeCard');
        const upiModeCard = document.getElementById('upiModeCard');
        const cashPaymentBox = document.getElementById('cashPaymentBox');
        const upiPaymentBox = document.getElementById('upiPaymentBox');
        const cashAmountInput = document.getElementById('cash_amount');
        const upiAmountInput = document.getElementById('upi_amount');
        const upiReferenceInput = document.getElementById('upi_reference');
        const paymentDateInput = document.getElementById('payment_date');
        const paymentSaleTotalEl = document.getElementById('paymentSaleTotal');
        const paymentReceivedEl = document.getElementById('paymentReceived');
        const paymentAppliedEl = document.getElementById('paymentApplied');
        const paymentReturnEl = document.getElementById('paymentReturn');
        const paymentValidationMessage = document.getElementById('paymentValidationMessage');
        const openCashDenomBtn = document.getElementById('openCashDenomBtn');
        const cashDenomModalEl = document.getElementById('cashDenominationModal');
        const denomInputs = [...document.querySelectorAll('.cash-denom-count')];
        const denomTarget = document.getElementById('denomTarget');
        const denomTotalEl = document.getElementById('denomTotal');
        const denomReturnEl = document.getElementById('denomReturn');
        const denomError = document.getElementById('denomError');
        const saveDenomBtn = document.getElementById('saveDenomBtn');
        const clearProductSearchBtn = document.getElementById('clearProductSearchBtn');
        const customerNameInput = document.getElementById('customer_name');
        const customerMobileInput = document.getElementById('customer_mobile');
        const customerAddressInput = document.getElementById('customer_address');

        function money(value) {
            return '₹' + (parseFloat(value || 0) || 0).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function showToast(message, type = 'success', title = '') {
            const old = document.getElementById('dynamicQuickSaleToast');
            if (old) old.remove();

            const wrap = document.createElement('div');
            wrap.id = 'dynamicQuickSaleToast';
            wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
            wrap.style.zIndex = '12000';

            const finalTitle = title || (type === 'danger' ? 'Failed' : (type === 'warning' ? 'Warning' :
                'Success'));

            wrap.innerHTML = `
                <div class="toast toast-ui ${type}" role="alert" data-bs-delay="4200">
                    <div class="d-flex">
                        <div class="toast-body">
                            <div class="toast-title">${escapeHtml(finalTitle)}</div>
                            <div class="toast-message">${message}</div>
                        </div>
                        <button type="button" class="btn-close me-3 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;

            document.body.appendChild(wrap);
            const toastEl = wrap.querySelector('.toast');
            if (window.bootstrap && toastEl) {
                bootstrap.Toast.getOrCreateInstance(toastEl).show();
            }
        }


        function validateCustomer(showErrors = true) {
            const name = String(customerNameInput?.value || '').trim();
            const mobile = String(customerMobileInput?.value || '').replace(/\D+/g, '');

            if (!name) {
                if (showErrors) {
                    showToast('Please enter Customer Name.', 'danger', 'Customer Details');
                    customerNameInput?.focus();
                }
                return false;
            }

            if (mobile.length !== 10) {
                if (showErrors) {
                    showToast('Please enter a valid 10 digit Mobile Number.', 'danger', 'Customer Details');
                    customerMobileInput?.focus();
                }
                return false;
            }

            if (customerMobileInput) customerMobileInput.value = mobile;
            return true;
        }

        function saleTotal() {
            return Math.round(
                items.reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0) * 100
            ) / 100;
        }

        function numericValue(input) {
            return Math.round((parseFloat(input?.value || '0') || 0) * 100) / 100;
        }

        function denominationTotal() {
            let total = 0;

            denomInputs.forEach(input => {
                const count = Math.max(0, parseInt(input.value || '0', 10) || 0);
                input.value = count;
                const value = parseFloat(input.dataset.value || '0') || 0;
                const rowTotal = count * value;
                total += rowTotal;

                const rowEl = input.closest('.denom-line')?.querySelector('.denom-row-total');
                if (rowEl) rowEl.textContent = rowTotal.toFixed(2);
            });

            total = Math.round(total * 100) / 100;
            if (denomTotalEl) denomTotalEl.textContent = money(total);
            return total;
        }

        function calculatePayment() {
            const total = saleTotal();
            const useCash = cashCheck?.checked === true;
            const useUpi = upiCheck?.checked === true;
            const cashTendered = useCash ? Math.max(0, numericValue(cashAmountInput)) : 0;
            const upiAmount = useUpi ? Math.max(0, numericValue(upiAmountInput)) : 0;

            let cashApplied = 0;
            let applied = 0;
            let returned = 0;
            let valid = false;
            let message = '';

            if (total <= 0) {
                message = 'Add a product to calculate payment.';
            } else if (!useCash && !useUpi) {
                message = 'Select Cash, UPI or both.';
            } else if (useCash && cashTendered <= 0) {
                message = 'Enter Cash Received.';
            } else if (useUpi && upiAmount <= 0) {
                message = 'Enter UPI Amount.';
            } else if (upiAmount > total + 0.009) {
                message = 'UPI Amount cannot exceed Sale Total. Excess/Return is allowed only through Cash.';
            } else {
                const cashRequired = Math.max(0, Math.round((total - upiAmount) * 100) / 100);

                if (!useCash) {
                    if (Math.abs(upiAmount - total) > 0.009) {
                        message = 'UPI-only payment must exactly match Sale Total.';
                    } else {
                        applied = total;
                        valid = true;
                    }
                } else if (cashRequired <= 0.009 && useUpi) {
                    message = 'UPI already covers the Sale Total. Unselect Cash or reduce UPI Amount.';
                } else if (cashTendered + 0.009 < cashRequired) {
                    message = 'Payment is short by ' + money(cashRequired - cashTendered) + '.';
                } else {
                    cashApplied = cashRequired;
                    applied = Math.round((cashApplied + upiAmount) * 100) / 100;
                    returned = Math.round(Math.max(0, cashTendered - cashApplied) * 100) / 100;
                    valid = Math.abs(applied - total) <= 0.009;
                    message = valid ?
                        (returned > 0.009 ?
                            'Fully paid. Return ' + money(returned) + ' to the customer.' :
                            'Payment fully covers this Quick Sale.') :
                        'Payment does not fully cover this Quick Sale.';
                }
            }

            const received = Math.round((cashTendered + upiAmount) * 100) / 100;

            if (paymentSaleTotalEl) paymentSaleTotalEl.textContent = money(total);
            if (paymentReceivedEl) paymentReceivedEl.textContent = money(received);
            if (paymentAppliedEl) paymentAppliedEl.textContent = money(applied);
            if (paymentReturnEl) paymentReturnEl.textContent = money(returned);
            if (denomTarget) denomTarget.textContent = money(cashTendered);
            if (denomReturnEl) denomReturnEl.textContent = money(returned);

            if (paymentValidationMessage) {
                paymentValidationMessage.textContent = message;
                paymentValidationMessage.classList.toggle('ok', valid);
                paymentValidationMessage.classList.toggle('bad', !valid);
            }

            return {
                total,
                useCash,
                useUpi,
                cashTendered,
                cashApplied,
                upiAmount,
                received,
                applied,
                returned,
                valid,
                message
            };
        }

        function syncPaymentModeUi() {
            const useCash = cashCheck?.checked === true;
            const useUpi = upiCheck?.checked === true;

            cashModeCard?.classList.toggle('active', useCash);
            upiModeCard?.classList.toggle('active', useUpi);

            if (cashPaymentBox) {
                cashPaymentBox.classList.toggle('d-none', !useCash);
                cashPaymentBox.style.display = useCash ? '' : 'none';
            }

            if (upiPaymentBox) {
                upiPaymentBox.classList.toggle('d-none', !useUpi);
                upiPaymentBox.style.display = useUpi ? '' : 'none';
            }

            if (cashAmountInput) {
                cashAmountInput.disabled = !useCash;
                if (!useCash) cashAmountInput.value = '';
            }

            if (upiAmountInput) {
                upiAmountInput.disabled = !useUpi;
                if (!useUpi) upiAmountInput.value = '';
            }

            if (upiReferenceInput) {
                upiReferenceInput.disabled = !useUpi;
                if (!useUpi) upiReferenceInput.value = '';
            }

            if (!useCash) {
                denomInputs.forEach(input => input.value = '0');
                denominationTotal();
            }

            /*
             * Convenient defaults:
             * - Cash only -> fill current Sale Total.
             * - UPI only -> fill current Sale Total.
             * - Split -> do not overwrite values; user controls the split.
             */
            const total = saleTotal();

            if (total > 0 && useCash && !useUpi && numericValue(cashAmountInput) <= 0) {
                cashAmountInput.value = total.toFixed(2);
            }

            if (total > 0 && useUpi && !useCash && numericValue(upiAmountInput) <= 0) {
                upiAmountInput.value = total.toFixed(2);
            }

            calculatePayment();
        }

        function syncPaymentForSaleTotal(newTotal) {
            const useCash = cashCheck?.checked === true;
            const useUpi = upiCheck?.checked === true;

            if (newTotal <= 0) {
                if (cashAmountInput) cashAmountInput.value = '';
                if (upiAmountInput) upiAmountInput.value = '';
                lastSaleTotal = 0;
                calculatePayment();
                return;
            }

            if (useCash && !useUpi) {
                const current = numericValue(cashAmountInput);
                if (current <= 0 || Math.abs(current - lastSaleTotal) <= 0.009) {
                    cashAmountInput.value = newTotal.toFixed(2);
                }
            } else if (useUpi && !useCash) {
                const current = numericValue(upiAmountInput);
                if (current <= 0 || Math.abs(current - lastSaleTotal) <= 0.009) {
                    upiAmountInput.value = newTotal.toFixed(2);
                }
            }

            lastSaleTotal = newTotal;
            calculatePayment();
        }

        function showDenomError(message) {
            if (!denomError) return;
            denomError.textContent = message || '';
            denomError.classList.toggle('d-none', !message);
        }

        function fallbackShowModal() {
            if (!cashDenomModalEl) return;

            cashDenomModalEl.classList.add('show', 'modal-fallback');
            cashDenomModalEl.style.display = 'block';
            cashDenomModalEl.removeAttribute('aria-hidden');
            document.body.classList.add('modal-open-fallback');

            if (!fallbackBackdrop) {
                fallbackBackdrop = document.createElement('div');
                fallbackBackdrop.className = 'modal-backdrop-fallback';
                fallbackBackdrop.addEventListener('click', fallbackHideModal);
                document.body.appendChild(fallbackBackdrop);
            }
        }

        function fallbackHideModal() {
            if (!cashDenomModalEl) return;

            cashDenomModalEl.classList.remove('show', 'modal-fallback');
            cashDenomModalEl.style.display = 'none';
            cashDenomModalEl.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open-fallback');

            if (fallbackBackdrop) {
                fallbackBackdrop.remove();
                fallbackBackdrop = null;
            }
        }

        function openDenominationModal() {
            if (!cashCheck?.checked) {
                showToast('Select Cash payment first.', 'warning', 'Cash Payment');
                return;
            }

            const cash = numericValue(cashAmountInput);
            if (cash <= 0) {
                showToast('Enter Cash Received before denomination.', 'danger', 'Cash Payment');
                cashAmountInput?.focus();
                return;
            }

            showDenomError('');
            denominationTotal();
            calculatePayment();

            if (window.bootstrap && bootstrap.Modal && cashDenomModalEl) {
                bootstrap.Modal.getOrCreateInstance(cashDenomModalEl).show();
            } else {
                fallbackShowModal();
            }
        }

        function closeDenominationModal() {
            if (window.bootstrap && bootstrap.Modal && cashDenomModalEl) {
                bootstrap.Modal.getOrCreateInstance(cashDenomModalEl).hide();
            }
            fallbackHideModal();
        }

        function validatePayment(showErrors = true) {
            const payment = calculatePayment();

            if (!payment.valid) {
                if (showErrors) {
                    showToast(payment.message, 'danger', 'Payment Check');

                    if (!payment.useCash && !payment.useUpi) {
                        cashCheck?.focus();
                    } else if (payment.useCash && payment.cashTendered <= 0) {
                        cashAmountInput?.focus();
                    } else if (payment.useUpi && payment.upiAmount <= 0) {
                        upiAmountInput?.focus();
                    }
                }
                return null;
            }

            if (!String(paymentDateInput?.value || '').trim()) {
                if (showErrors) {
                    showToast('Please select Payment Date.', 'danger', 'Payment Date');
                    paymentDateInput?.focus();
                }
                return null;
            }

            if (payment.useCash) {
                const denomTotal = denominationTotal();
                if (Math.abs(denomTotal - payment.cashTendered) > 0.009) {
                    const msg = 'Cash denomination total ' + money(denomTotal) +
                        ' must match Cash Received ' + money(payment.cashTendered) + '.';

                    if (showErrors) {
                        showToast(msg, 'danger', 'Cash Denomination');
                        showDenomError(msg);
                        openDenominationModal();
                    }
                    return null;
                }
            }

            return payment;
        }

        async function createProductMasterOnEnter(productName) {
            const name = String(productName || '').trim();
            if (!name) return null;

            const form = document.getElementById('quickSaleForm');
            const csrf = form?.querySelector('input[name="csrf_token"]')?.value || '';
            const body = new FormData();
            body.set('quick_sale_action', 'create_product');
            body.set('csrf_token', csrf);
            body.set('product_name', name);

            const response = await fetch('quick-sale.php', {
                method: 'POST',
                body,
                credentials: 'same-origin'
            });

            const data = await response.json().catch(() => null);
            if (!data || !data.status || !data.product) {
                throw new Error(data?.message || 'Unable to create Product Master.');
            }

            const product = data.product;
            const productId = String(product.id || '');
            if (!productId) {
                throw new Error('Product Master ID is missing.');
            }

            const select = document.getElementById('product_id');
            if (!select) return product;

            // Remove temporary new-tag options with the same name.
            [...select.options].forEach(option => {
                if (
                    String(option.value || '').startsWith('new:') &&
                    String(option.textContent || '').trim().toLowerCase() === name.toLowerCase()
                ) {
                    option.remove();
                }
            });

            let option = [...select.options].find(row => String(row.value) === productId);
            if (!option) {
                option = new Option(String(product.product_name || name), productId, true, true);
                select.appendChild(option);
            }

            option.dataset.name = String(product.product_name || name);
            option.dataset.price = String(product.default_price || 0);
            option.dataset.onHand = String(product.on_hand_stock || 0);
            option.dataset.reserved = String(product.reserved_stock || 0);
            option.dataset.available = String(product.available_stock || 0);
            option.selected = true;

            if (window.jQuery && $.fn.select2) {
                $('#product_id').val(productId).trigger('change');
                $('#product_id').select2('close');
            }

            useMasterPrice();

            showToast(
                escapeHtml(data.message || 'Product Master ready.'),
                'success',
                data.was_created ? 'Product Created' : (data.was_restored ? 'Product Restored' :
                    'Product Found')
            );

            setTimeout(() => document.getElementById('qty')?.focus(), 80);
            return product;
        }

        function selectedOption() {
            const select = document.getElementById('product_id');
            return select?.options?. [select.selectedIndex] || null;
        }

        function currentProductSelection() {
            let data = null;

            if (window.jQuery && $.fn.select2) {
                const selected = $('#product_id').select2('data');
                if (Array.isArray(selected) && selected.length) {
                    data = selected[0];
                }
            }

            if (data && String(data.id || '').trim() !== '') {
                const rawId = String(data.id || '').trim();
                const numericId = /^\d+$/.test(rawId) ? parseInt(rawId, 10) : 0;
                const productName = String(data.text || '').trim();
                const isNew =
                    data.newTag === true ||
                    rawId.startsWith('new:') ||
                    !(numericId > 0);

                return {
                    has_product: productName !== '',
                    is_new: isNew,
                    product_id: numericId > 0 ? numericId : 0,
                    product_name: productName
                };
            }

            const opt = selectedOption();

            if (!opt || !opt.value) {
                return {
                    has_product: false,
                    is_new: false,
                    product_id: 0,
                    product_name: ''
                };
            }

            const rawValue = String(opt.value || '').trim();
            const numericId = /^\d+$/.test(rawValue) ? parseInt(rawValue, 10) : 0;
            const explicitName = String(opt.dataset?.name || '').trim();
            const textName = String(opt.textContent || '').trim();
            const productName = explicitName || textName;

            return {
                has_product: productName !== '',
                is_new: rawValue.startsWith('new:') || !(numericId > 0),
                product_id: numericId > 0 ? numericId : 0,
                product_name: productName
            };
        }

        function syncEntryState() {
            const current = currentProductSelection();
            const qty = document.getElementById('qty');
            const rate = document.getElementById('rate');

            if (!qty || !rate) return;

            const enabled = current.has_product;

            qty.disabled = !enabled;
            rate.disabled = !enabled;

            qty.required = enabled;
            rate.required = enabled;

            if (!enabled) {
                qty.value = '';
                rate.value = '';
            }
        }

        function refreshStockInfo() {
            const opt = selectedOption();
            const box = document.getElementById('stockInfo');
            const current = currentProductSelection();

            if (!box) return;

            if (!current.has_product) {
                box.innerHTML =
                    '<span class="text-muted-custom small fw-bold">Search an existing product or type a new Product Name.</span>';
                return;
            }

            if (current.is_new) {
                box.innerHTML = `
                    <span class="stock-pill bad">
                        New Product • On Hand: 0 • Reserved: 0 • Available: 0
                    </span>
                    <span class="small text-muted-custom fw-bold ms-2">
                        Saving the sale will create this Product Master and stock may become negative.
                    </span>
                `;
                return;
            }

            const onHand = parseFloat(opt.dataset.onHand || 0) || 0;
            const reserved = parseFloat(opt.dataset.reserved || 0) || 0;
            const available = parseFloat(opt.dataset.available || 0) || 0;
            const cls = available > 0 ? 'ok' : 'bad';

            box.innerHTML = `
                <span class="stock-pill ${cls}">
                    On Hand: ${onHand.toLocaleString('en-IN')} |
                    Reserved: ${reserved.toLocaleString('en-IN')} |
                    Available: ${available.toLocaleString('en-IN')}
                </span>
            `;
        }

        function useMasterPrice() {
            const current = currentProductSelection();
            const opt = selectedOption();
            const rate = document.getElementById('rate');
            const qty = document.getElementById('qty');

            syncEntryState();

            if (!current.has_product) {
                refreshStockInfo();
                return;
            }

            if (qty && String(qty.value || '').trim() === '') {
                qty.value = '';
            }

            if (!current.is_new) {
                const price = parseFloat(opt?.dataset?.price || 0) || 0;
                rate.value = price > 0 ? price.toFixed(2) : '';
            } else {
                rate.value = '';
            }

            refreshStockInfo();
        }

        function resetEntry() {
            const product = document.getElementById('product_id');

            if (window.jQuery && $.fn.select2) {
                $('#product_id').val(null).trigger('change');
            } else if (product) {
                product.value = '';
            }

            const qty = document.getElementById('qty');
            const rate = document.getElementById('rate');

            if (qty) {
                qty.value = '';
                qty.disabled = true;
                qty.required = false;
            }

            if (rate) {
                rate.value = '';
                rate.disabled = true;
                rate.required = false;
            }

            refreshStockInfo();
        }

        function clearProductSearch() {
            const product = document.getElementById('product_id');

            /*
             * Remove only temporary Select2 "new:" options. Existing Product
             * Master options stay untouched.
             */
            if (product) {
                [...product.options].forEach(option => {
                    if (String(option.value || '').startsWith('new:')) {
                        option.remove();
                    }
                });
            }

            if (window.jQuery && $.fn.select2) {
                const $product = $('#product_id');
                $product.val(null).trigger('change');

                if ($product.data('select2')) {
                    $product.select2('close');
                }
            } else if (product) {
                product.value = '';
            }

            const openSearch = document.querySelector(
                '.select2-container--open .select2-search__field'
            );

            if (openSearch) {
                openSearch.value = '';
                openSearch.dispatchEvent(new Event('input', {
                    bubbles: true
                }));
            }

            const qty = document.getElementById('qty');
            const rate = document.getElementById('rate');

            if (qty) {
                qty.value = '';
                qty.disabled = true;
                qty.required = false;
            }

            if (rate) {
                rate.value = '';
                rate.disabled = true;
                rate.required = false;
            }

            refreshStockInfo();
        }

        function renderItems() {
            const body = document.getElementById('itemsBody');
            const empty = document.getElementById('emptyItems');
            const wrap = document.getElementById('itemsTableWrap');
            const jsonInput = document.getElementById('items_json');

            const totalQty = items.reduce((sum, item) => sum + (parseFloat(item.qty) || 0), 0);
            const totalAmount = items.reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0);

            document.getElementById('totalQty').textContent = totalQty.toLocaleString('en-IN');
            document.getElementById('grandTotal').textContent = money(totalAmount);

            if (jsonInput) jsonInput.value = JSON.stringify(items);
            syncPaymentForSaleTotal(totalAmount);

            if (!items.length) {
                body.innerHTML = '';
                empty.classList.remove('d-none');
                wrap.classList.add('d-none');
                return;
            }

            empty.classList.add('d-none');
            wrap.classList.remove('d-none');

            body.innerHTML = items.map((item, index) => `
                <tr>
                    <td>${index + 1}</td>
                    <td>
                        <strong>${escapeHtml(item.product_name)}</strong>
                        <div class="small text-muted-custom fw-bold">
                            On Hand After: ${Number(item.projected_on_hand_stock || 0).toLocaleString('en-IN')} •
                            Available After: ${Number(item.projected_available_stock || 0).toLocaleString('en-IN')}
                        </div>
                    </td>
                    <td class="text-end">${Number(item.qty || 0).toLocaleString('en-IN')}</td>
                    <td class="text-end">${money(item.rate)}</td>
                    <td class="text-end fw-bold">${money(item.amount)}</td>
                    <td class="text-end">
                        <div class="item-actions">
                            <button type="button" class="btn btn-outline-danger" data-remove="${index}" title="Remove">
                                <i class="fa fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function addProduct() {
            const current = currentProductSelection();
            const opt = selectedOption();
            const qty = parseFloat(document.getElementById('qty').value || 0) || 0;
            const rate = parseFloat(document.getElementById('rate').value || 0) || 0;

            if (!current.has_product || !current.product_name) {
                showToast('Please select or enter Product Name.', 'danger', 'Product Check');
                return false;
            }

            if (qty <= 0) {
                showToast('Quantity must be greater than zero.', 'danger', 'Quantity Check');
                document.getElementById('qty')?.focus();
                return false;
            }

            if (rate <= 0) {
                showToast('Price must be greater than zero.', 'danger', 'Price Check');
                document.getElementById('rate')?.focus();
                return false;
            }

            const onHand = current.is_new ? 0 : (parseFloat(opt?.dataset?.onHand || 0) || 0);
            const reserved = current.is_new ? 0 : (parseFloat(opt?.dataset?.reserved || 0) || 0);
            const available = current.is_new ? 0 : (parseFloat(opt?.dataset?.available || 0) || 0);
            const projectedOnHand = onHand - qty;
            const projectedAvailable = available - qty;

            /*
             * Existing Product Master rows are unique by id.
             * New typed products are compared by product name.
             */
            const duplicate = items.some(row => {
                if (current.product_id > 0 && parseInt(row.product_id || 0, 10) > 0) {
                    return parseInt(row.product_id || 0, 10) === current.product_id;
                }

                return String(row.product_name || '').trim().toLowerCase() ===
                    current.product_name.trim().toLowerCase();
            });

            if (duplicate) {
                showToast(
                    'This product is already added. Remove it and add again if you want to change Qty or Price.',
                    'warning',
                    'Duplicate Product'
                );
                return false;
            }

            items.push({
                product_id: current.product_id,
                product_name: current.product_name,
                is_new_product: current.is_new ? 1 : 0,
                qty: qty,
                rate: rate,
                amount: Math.round(qty * rate * 100) / 100,
                on_hand_stock: onHand,
                reserved_stock: reserved,
                available_stock: available,
                projected_on_hand_stock: projectedOnHand,
                projected_available_stock: projectedAvailable
            });

            renderItems();
            resetEntry();
            return true;
        }

        document.getElementById('product_id')?.addEventListener('change', useMasterPrice);
        document.getElementById('addProductBtn')?.addEventListener('click', addProduct);

        clearProductSearchBtn?.addEventListener('click', function() {
            clearProductSearch();
        });

        document.getElementById('itemsBody')?.addEventListener('click', function(event) {
            const btn = event.target.closest('[data-remove]');
            if (!btn) return;

            const index = parseInt(btn.dataset.remove || '-1', 10);
            if (index < 0 || !items[index]) return;

            items.splice(index, 1);
            renderItems();
        });

        document.getElementById('clearBtn')?.addEventListener('click', function() {
            items.splice(0, items.length);
            denomInputs.forEach(input => input.value = '0');
            renderItems();
            resetEntry();

            if (cashCheck) cashCheck.checked = true;
            if (upiCheck) upiCheck.checked = false;
            if (cashAmountInput) cashAmountInput.value = '';
            if (upiAmountInput) upiAmountInput.value = '';
            if (upiReferenceInput) upiReferenceInput.value = '';
            if (customerNameInput) customerNameInput.value = '';
            if (customerMobileInput) customerMobileInput.value = '';
            if (customerAddressInput) customerAddressInput.value = '';
            syncPaymentModeUi();
            denominationTotal();
        });

        document.getElementById('quickSaleForm')?.addEventListener('submit', function(event) {
            event.preventDefault();

            if (!validateCustomer(true)) {
                return;
            }

            const current = currentProductSelection();

            /*
             * If the user entered Product + Qty + Price and clicks Save directly,
             * automatically add that current product first.
             *
             * If the current Product area is blank and Added Products already
             * contains rows, ignore the blank next-product controls completely.
             */
            if (current.has_product) {
                const added = addProduct();
                if (!added) {
                    return;
                }
            }

            if (!items.length) {
                showToast('Please add at least one product.', 'danger', 'Quick Sale');
                return;
            }

            const payment = validatePayment(true);
            if (!payment) {
                return;
            }

            const btn = document.getElementById('saveBtn');
            const oldText = btn?.textContent || 'Save Quick Sale & Generate Invoice';

            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Saving...';
            }

            const formData = new FormData(this);
            formData.set('items_json', JSON.stringify(items));

            fetch('api/quick-sale.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.status) {
                        throw new Error(data.message || 'Quick Sale failed.');
                    }

                    showToast(
                        (data.message || 'Quick Sale saved successfully.') +
                        (data.sale_no ? '<br>Sale No: ' + escapeHtml(data.sale_no) : '') +
                        (data.total_amount !== undefined ? '<br>Total: ' + money(data
                            .total_amount) : '') +
                        (data.return_amount > 0 ? '<br>Return: ' + money(data.return_amount) : '') +
                        (Array.isArray(data.created_products) && data.created_products.length ?
                            '<br>New Product Master: ' + escapeHtml(data.created_products.join(
                                ', ')) :
                            '') +
                        (Array.isArray(data.restored_products) && data.restored_products.length ?
                            '<br>Restored Product: ' + escapeHtml(data.restored_products.join(
                            ', ')) :
                            '') +
                        (data.whatsapp_sent === true
                            ? '<br>WhatsApp: Invoice sent to customer'
                            : (data.whatsapp_message
                                ? '<br>WhatsApp: ' + escapeHtml(data.whatsapp_message)
                                : '')),
                        'success',
                        'Quick Sale Saved'
                    );

                    setTimeout(() => {
                        const quickSaleId = parseInt(data.quick_sale_id || '0', 10);

                        if (quickSaleId > 0) {
                            window.location.href =
                                'quick_sale_invoice_pdf.php?id=' + encodeURIComponent(quickSaleId);
                            return;
                        }

                        window.location.href = 'quick-sale.php?message=' +
                            encodeURIComponent((data.sale_no || 'Quick Sale') +
                                ' saved successfully.');
                    }, 700);
                })
                .catch(error => {
                    showToast(escapeHtml(error.message || 'Unable to save Quick Sale.'), 'danger',
                        'Failed');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = oldText;
                    }
                });
        });

        customerMobileInput?.addEventListener('input', function() {
            this.value = String(this.value || '').replace(/\D+/g, '').slice(0, 10);
        });

        /*
         * Payment mode listeners must live at top level.
         * In the previous version these listeners were accidentally nested
         * inside resetEntry(), so UPI could be checked without revealing the
         * UPI Amount / UPI Reference fields.
         */
        cashCheck?.addEventListener('change', syncPaymentModeUi);
        upiCheck?.addEventListener('change', syncPaymentModeUi);

        cashModeCard?.addEventListener('click', function() {
            setTimeout(syncPaymentModeUi, 0);
        });

        upiModeCard?.addEventListener('click', function() {
            setTimeout(syncPaymentModeUi, 0);
        });

        cashAmountInput?.addEventListener('input', calculatePayment);
        upiAmountInput?.addEventListener('input', calculatePayment);

        openCashDenomBtn?.addEventListener('click', openDenominationModal);

        denomInputs.forEach(input => {
            input.addEventListener('input', function() {
                denominationTotal();
                calculatePayment();
            });
        });

        saveDenomBtn?.addEventListener('click', function() {
            const payment = calculatePayment();
            const total = denominationTotal();

            if (Math.abs(total - payment.cashTendered) > 0.009) {
                showDenomError(
                    'Denomination total ' + money(total) +
                    ' must match Cash Received ' + money(payment.cashTendered) + '.'
                );
                return;
            }

            showDenomError('');
            closeDenominationModal();
        });

        document.querySelectorAll('[data-bs-dismiss="modal"]').forEach(btn => {
            btn.addEventListener('click', function() {
                setTimeout(fallbackHideModal, 10);
            });
        });

        if (window.jQuery && $.fn.select2) {
            const $product = $('#product_id');

            $product.select2({
                width: '100%',
                placeholder: 'Search or Add Product',
                allowClear: true,
                tags: true,
                createTag: function(params) {
                    const term = String(params.term || '').trim();
                    if (!term) return null;

                    return {
                        id: 'new:' + term,
                        text: term,
                        newTag: true
                    };
                },
                templateResult: function(data) {
                    if (data.newTag || String(data.id || '').startsWith('new:')) {
                        return $('<span><strong>Add New:</strong> ' + escapeHtml(data.text) +
                        '</span>');
                    }

                    return data.text;
                }
            });

            /*
             * Select2 tags normally require Enter/click to commit a new tag.
             * For counter sales, if Sales types a new Product Name and simply
             * clicks Qty/Price, commit that typed name automatically.
             */
            $product.on('select2:closing', function() {
                const searchInput = document.querySelector(
                    '.select2-container--open .select2-search__field'
                );

                const typedName = String(searchInput?.value || '').trim();
                const current = $product.val();

                if (!typedName || current) {
                    return;
                }

                let existingValue = '';

                this.querySelectorAll('option').forEach(option => {
                    if (
                        !existingValue &&
                        String(option.textContent || '').trim().toLowerCase() === typedName
                        .toLowerCase()
                    ) {
                        existingValue = String(option.value || '');
                    }
                });

                if (existingValue) {
                    $product.val(existingValue).trigger('change');
                    return;
                }

                const newValue = 'new:' + typedName;
                const option = new Option(typedName, newValue, true, true);
                option.dataset.newProduct = '1';
                this.appendChild(option);
                $product.val(newValue).trigger('change');
            });

            $product.on('select2:open', function() {
                const searchInput = document.querySelector(
                    '.select2-container--open .select2-search__field'
                );

                if (!searchInput || searchInput.dataset.quickSaleEnterBound === '1') {
                    return;
                }

                searchInput.dataset.quickSaleEnterBound = '1';

                /*
                 * Capture Enter before Select2 handles it. If the typed text is
                 * not an exact existing Product Master, create it immediately.
                 */
                searchInput.addEventListener('keydown', async function(event) {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        event.stopPropagation();
                        clearProductSearch();
                        return;
                    }

                    if (event.key !== 'Enter') {
                        return;
                    }

                    const typedName = String(searchInput.value || '').trim();
                    if (!typedName) {
                        return;
                    }

                    let exactOption = null;
                    this.closest('.select2-container');

                    document.getElementById('product_id')?.querySelectorAll('option').forEach(
                        option => {
                            if (
                                !exactOption &&
                                !String(option.value || '').startsWith('new:') &&
                                String(option.textContent || '').trim().toLowerCase() ===
                                typedName.toLowerCase()
                            ) {
                                exactOption = option;
                            }
                        });

                    if (exactOption) {
                        event.preventDefault();
                        event.stopPropagation();
                        $product.val(String(exactOption.value)).trigger('change');
                        $product.select2('close');
                        setTimeout(() => document.getElementById('qty')?.focus(), 60);
                        return;
                    }

                    event.preventDefault();
                    event.stopImmediatePropagation();

                    searchInput.disabled = true;
                    try {
                        await createProductMasterOnEnter(typedName);
                    } catch (error) {
                        showToast(
                            escapeHtml(error?.message ||
                            'Unable to create Product Master.'),
                            'danger',
                            'Product Creation Failed'
                        );
                    } finally {
                        searchInput.disabled = false;
                    }
                }, true);
            });

            $product.on('select2:select', function(event) {
                const data = event.params?.data || null;
                const option = this.options[this.selectedIndex];

                if (data && option) {
                    option.dataset.name = String(data.text || '').trim();

                    if (data.newTag || String(data.id || '').startsWith('new:')) {
                        option.dataset.newProduct = '1';
                    }
                }

                useMasterPrice();
            });

            $product.on('select2:clear change', useMasterPrice);
        }

        const pageToast = document.getElementById('pageToast');
        if (pageToast && window.bootstrap) {
            bootstrap.Toast.getOrCreateInstance(pageToast).show();
        }

        renderItems();
        syncEntryState();
        syncPaymentModeUi();
        denominationTotal();
        refreshStockInfo();
    })();
    </script>
</body>

</html>