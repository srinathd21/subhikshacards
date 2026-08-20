<?php
/**
 * api/stock.php
 * Subhiksha Cards ERP - Stock API for the single Stock Management page.
 * Supports both single and bulk Add / Reduce forms.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/product-stock-helper.php';

ps_require_module($conn);
header('X-Content-Type-Options: nosniff');

function s_api_wants_json(): bool
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return strpos($accept, 'application/json') !== false || $xhr === 'xmlhttprequest' || ($_POST['format'] ?? '') === 'json';
}

function s_api_success(string $message, string $redirect, array $data = []): void
{
    if (s_api_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    ps_redirect($redirect);
}

function s_api_fail(string $message, string $redirect = '../stock-management.php', int $status = 422): void
{
    if (s_api_wants_json()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $join = strpos($redirect, '?') === false ? '?' : '&';
    ps_redirect($redirect . $join . 'error=' . urlencode($message));
}

function s_collect_qty_lines(): array
{
    $lines = [];

    $qtyMap = $_POST['qty'] ?? null;
    if (is_array($qtyMap)) {
        foreach ($qtyMap as $productIdRaw => $qtyRaw) {
            $productId = (int)$productIdRaw;
            $qty = (float)$qtyRaw;
            if ($productId > 0 && $qty > 0) {
                $lines[$productId] = $qty;
            }
        }
    }

    if (!$lines) {
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = (float)($_POST['quantity'] ?? 0);
        if ($productId > 0 && $quantity > 0) {
            $lines[$productId] = $quantity;
        }
    }

    return $lines;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    s_api_fail('Invalid request method.', '../stock-management.php', 405);
}

$action = trim((string)($_POST['action'] ?? ''));

try {
    ps_require_csrf();

    if (!can_create($conn, 'stock-management.php')) {
        throw new RuntimeException('You do not have permission to update stock.');
    }

    if (!in_array($action, ['inward', 'reduce'], true)) {
        throw new RuntimeException('Invalid stock action.');
    }

    $lines = s_collect_qty_lines();
    if (!$lines) {
        throw new RuntimeException($action === 'inward'
            ? 'Enter add quantity for at least one product.'
            : 'Enter reduce quantity for at least one product.');
    }

    $description = trim((string)($_POST['description'] ?? ''));
    if (mb_strlen($description) < 3) {
        throw new RuntimeException('Description is required.');
    }
    if (mb_strlen($description) > 2000) {
        throw new RuntimeException('Description is too long.');
    }

    $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
    if (mb_strlen($referenceNo) > 150) {
        throw new RuntimeException('Reference is too long.');
    }

    $reason = trim((string)($_POST['reason'] ?? ''));
    $allowedReasons = ['damage', 'wastage', 'sample', 'manual_usage', 'other'];
    if ($action === 'reduce' && !in_array($reason, $allowedReasons, true)) {
        throw new RuntimeException('Please select a valid reduction reason.');
    }

    $placeholders = implode(',', array_fill(0, count($lines), '?'));
    $types = str_repeat('i', count($lines));
    $ids = array_keys($lines);
    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.product_name,
            COALESCE(ps.on_hand_stock,0) AS on_hand_stock
        FROM products p
        LEFT JOIN product_stock ps ON ps.product_id = p.id
        WHERE p.id IN ({$placeholders})
          AND p.is_active = 1
          AND COALESCE(p.is_removed,0) = 0
    ");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $valid = [];
    while ($row = $res->fetch_assoc()) {
        $valid[(int)$row['id']] = $row;
    }
    $stmt->close();

    if (count($valid) !== count($lines)) {
        throw new RuntimeException('One or more selected products are not available.');
    }

    if ($action === 'reduce') {
        foreach ($lines as $productId => $qty) {
            $onHand = (float)$valid[$productId]['on_hand_stock'];
            if ($qty > $onHand + 0.000001) {
                throw new RuntimeException(
                    'Reduce quantity for ' . $valid[$productId]['product_name'] .
                    ' cannot exceed On Hand Stock (' . ps_qty($onHand) . ').'
                );
            }
        }
    }

    $conn->begin_transaction();
    try {
        $userId = ps_user_id();

        if ($action === 'inward') {
            foreach ($lines as $productId => $qty) {
                ps_adjust_on_hand(
                    $conn,
                    (int)$productId,
                    (float)$qty,
                    'inward',
                    $description,
                    $userId,
                    'stock_inward',
                    0,
                    $referenceNo,
                    false
                );
            }
        } else {
            $reasonLabel = ucwords(str_replace('_', ' ', $reason));
            $fullDescription = $reasonLabel . ': ' . $description;
            foreach ($lines as $productId => $qty) {
                ps_adjust_on_hand(
                    $conn,
                    (int)$productId,
                    -(float)$qty,
                    'manual_reduce',
                    $fullDescription,
                    $userId,
                    'stock_reduce',
                    0,
                    '',
                    false
                );
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    if ($action === 'inward') {
        s_api_success(
            'Stock added successfully.',
            '../stock-management.php?saved=inward',
            ['product_count' => count($lines)]
        );
    }

    s_api_success(
        'Stock reduced successfully.',
        '../stock-management.php?saved=reduce',
        ['product_count' => count($lines)]
    );
} catch (Throwable $e) {
    s_api_fail($e->getMessage(), '../stock-management.php');
}
