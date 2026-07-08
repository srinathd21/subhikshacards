<?php
/**
 * api/printing_price_master.php
 * Lookup API for Create Proforma automatic pricing.
 * Uses existing includes/db.php and includes/auth.php.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Permission Fix
|--------------------------------------------------------------------------
| This file is used as an AJAX lookup inside create_proforma.php.
| Do NOT check permission against printing_price_master.php here.
| A user who can create/view/update Proforma Bills should be allowed to
| fetch pricing for the Proforma form.
*/
function ppm_has_proforma_access(mysqli $conn): bool
{
    try {
        if (function_exists('is_super_admin') && is_super_admin()) {
            return true;
        }

        $page = 'proforma_bills.php';
        $checks = ['can_create', 'can_view', 'can_update', 'can_edit'];

        foreach ($checks as $fn) {
            if (function_exists($fn) && $fn($conn, $page)) {
                return true;
            }
        }

        /*
         * Some older auth.php files may not expose can_create/can_view helpers.
         * In that case, auth.php has already validated the logged-in session.
         * Allow the lookup for logged-in users instead of returning HTML Access Denied
         * inside the AJAX response.
         */
        if (!function_exists('can_create') && !function_exists('can_view') && !function_exists('can_update') && !function_exists('can_edit')) {
            return !empty($_SESSION['user_id']);
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

if (!ppm_has_proforma_access($conn)) {
    http_response_code(403);
    echo json_encode([
        'status' => false,
        'success' => false,
        'message' => 'Access denied. Proforma bill create/view permission required for pricing lookup.'
    ]);
    exit;
}

function ppm_response(bool $status, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge(['status' => $status, 'success' => $status, 'message' => $message], $extra));
    exit;
}

function ppm_table_exists(mysqli $conn, string $table): bool
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

function ppm_col_exists(mysqli $conn, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $table = $conn->real_escape_string($table);
        $col = $conn->real_escape_string($col);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $cache[$key] = $ok;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function ppm_req(string $key, string $default = ''): string
{
    return trim((string)($_REQUEST[$key] ?? $default));
}

function ppm_int($value): int
{
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

function ppm_float($value): float
{
    return (float)str_replace(',', '', (string)$value);
}

function ppm_norm(string $value): string
{
    $value = strtolower(trim($value));
    $value = str_replace(['×', '*', ' '], ['x', 'x', ''], $value);
    $value = preg_replace('/[^a-z0-9\.]+/', '', $value);
    return $value ?: '';
}

function ppm_slug_value(string $value, string $fallback = 'item'): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string)$value, '_');
    return $value !== '' ? substr($value, 0, 140) : $fallback;
}

function ppm_find_or_create_size(mysqli $conn, string $sizeText, int $userId = 0): ?int
{
    $sizeText = trim($sizeText);
    if ($sizeText === '' || !ppm_table_exists($conn, 'card_size_master')) return null;
    $norm = ppm_norm($sizeText);
    try {
        $stmt = $conn->prepare("SELECT id FROM card_size_master WHERE REPLACE(REPLACE(REPLACE(LOWER(size_name), ' ', ''), '×', 'x'), '*', 'x') = ? OR size_key = ? LIMIT 1");
        $key = ppm_slug_value($sizeText, 'size');
        $stmt->bind_param('ss', $norm, $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];

        $stmt = $conn->prepare("INSERT INTO card_size_master (size_name, size_key, is_active, sort_order, created_by, created_at) VALUES (?, ?, 1, 999, ?, NOW())");
        $stmt->bind_param('ssi', $sizeText, $key, $userId);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function ppm_find_or_create_gsm(mysqli $conn, string $gsmText, int $userId = 0): ?int
{
    $gsmText = trim($gsmText);
    if ($gsmText === '' || !ppm_table_exists($conn, 'gsm_master')) return null;
    $norm = ppm_norm($gsmText);
    try {
        $stmt = $conn->prepare("SELECT id FROM gsm_master WHERE REPLACE(LOWER(gsm_name), ' ', '') = ? OR gsm_key = ? LIMIT 1");
        $key = ppm_slug_value($gsmText, 'gsm');
        $stmt->bind_param('ss', $norm, $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];

        preg_match('/\d+/', $gsmText, $m);
        $gsmValue = !empty($m[0]) ? (int)$m[0] : null;
        $stmt = $conn->prepare("INSERT INTO gsm_master (gsm_name, gsm_key, gsm_value, is_active, sort_order, created_by, created_at) VALUES (?, ?, ?, 1, 999, ?, NOW())");
        $stmt->bind_param('ssii', $gsmText, $key, $gsmValue, $userId);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function ppm_find_lamination_id(mysqli $conn, string $laminationType): ?int
{
    $key = ppm_slug_value($laminationType ?: 'none', 'none');
    if (!ppm_table_exists($conn, 'lamination_master')) return null;
    try {
        $stmt = $conn->prepare("SELECT id FROM lamination_master WHERE lamination_key = ? OR LOWER(lamination_name) = LOWER(?) LIMIT 1");
        $stmt->bind_param('ss', $key, $laminationType);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ppm_try_add_column(mysqli $conn, string $column, string $definition): void
{
    try {
        if (!ppm_col_exists($conn, 'printing_price_master', $column)) {
            $conn->query("ALTER TABLE printing_price_master ADD COLUMN {$definition}");
        }
    } catch (Throwable $e) {
        // If DB user has no ALTER permission, lookup still works with existing columns.
    }
}

function ppm_ensure_sheet_pricing_columns(mysqli $conn): void
{
    if (!ppm_table_exists($conn, 'printing_price_master')) return;

    ppm_try_add_column($conn, 'pricing_mode', "pricing_mode ENUM('total_charge','per_card') NOT NULL DEFAULT 'total_charge' AFTER print_type");
    ppm_try_add_column($conn, 'card_type', "card_type VARCHAR(150) DEFAULT NULL AFTER product_name");
    ppm_try_add_column($conn, 'rate_per_card', "rate_per_card DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER rate");
    ppm_try_add_column($conn, 'auto_fill_target', "auto_fill_target ENUM('printing_charge','rate','both') NOT NULL DEFAULT 'printing_charge' AFTER rate_per_card");
}


$action = strtolower(ppm_req('action', 'find_price'));

if ($action !== 'find_price') {
    ppm_response(false, 'Invalid action.');
}

if (!ppm_table_exists($conn, 'printing_price_master')) {
    ppm_response(false, 'printing_price_master table is missing. Please run the DB setup SQL.');
}

ppm_ensure_sheet_pricing_columns($conn);

$hasCardType = ppm_col_exists($conn, 'printing_price_master', 'card_type');
$hasPricingMode = ppm_col_exists($conn, 'printing_price_master', 'pricing_mode');
$hasRatePerCard = ppm_col_exists($conn, 'printing_price_master', 'rate_per_card');
$hasAutoFillTarget = ppm_col_exists($conn, 'printing_price_master', 'auto_fill_target');

$productId = ppm_int(ppm_req('product_id')) ?: null;
$productName = ppm_req('product_name');
$printingTypeId = ppm_int(ppm_req('printing_type_id')) ?: null;
$printingSubTypeId = ppm_int(ppm_req('printing_sub_type_id')) ?: null;
$sizeText = ppm_req('size_text');
$gsmThickness = ppm_req('gsm_thickness');
$printingSide = strtolower(ppm_req('printing_side', 'not_applicable')) ?: 'not_applicable';
$laminationType = strtolower(ppm_req('lamination_type', 'none')) ?: 'none';
$printType = strtolower(ppm_req('print_type', 'first_print')) ?: 'first_print';
$qty = ppm_float(ppm_req('qty'));
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($qty <= 0) ppm_response(false, 'Quantity is required for price lookup.');
if (!$printingTypeId) ppm_response(false, 'Printing type is required for price lookup.');
if (!$productId && $productName === '') ppm_response(false, 'Product is required for price lookup.');

$cardSizeId = ppm_find_or_create_size($conn, $sizeText, $userId);
$gsmId = ppm_find_or_create_gsm($conn, $gsmThickness, $userId);
$laminationId = ppm_find_lamination_id($conn, $laminationType);
$normSize = ppm_norm($sizeText);
$normGsm = ppm_norm($gsmThickness);

try {
    $where = [];
    $types = '';
    $params = [];

    $where[] = 'ppm.is_active = 1';
    $where[] = 'ppm.min_qty <= ? AND ppm.max_qty >= ?';
    $types .= 'dd';
    $params[] = $qty;
    $params[] = $qty;

    if ($productId) {
        if ($hasCardType) {
            $where[] = '(ppm.product_id = ? OR LOWER(TRIM(ppm.product_name)) = LOWER(TRIM(?)) OR LOWER(TRIM(ppm.card_type)) = LOWER(TRIM(?)))';
            $types .= 'iss';
            $params[] = $productId;
            $params[] = $productName;
            $params[] = $productName;
        } else {
            $where[] = '(ppm.product_id = ? OR LOWER(TRIM(ppm.product_name)) = LOWER(TRIM(?)))';
            $types .= 'is';
            $params[] = $productId;
            $params[] = $productName;
        }
    } else {
        if ($hasCardType) {
            $where[] = '(LOWER(TRIM(ppm.product_name)) = LOWER(TRIM(?)) OR LOWER(TRIM(ppm.card_type)) = LOWER(TRIM(?)) OR ppm.product_name IS NULL OR ppm.product_name = \'\')';
            $types .= 'ss';
            $params[] = $productName;
            $params[] = $productName;
        } else {
            $where[] = '(LOWER(TRIM(ppm.product_name)) = LOWER(TRIM(?)) OR ppm.product_name IS NULL OR ppm.product_name = \'\')';
            $types .= 's';
            $params[] = $productName;
        }
    }

    $where[] = 'ppm.printing_type_id = ?';
    $types .= 'i';
    $params[] = $printingTypeId;

    $where[] = '(ppm.printing_sub_type_id = ? OR ppm.printing_sub_type_id IS NULL OR ppm.printing_sub_type_id = 0)';
    $types .= 'i';
    $params[] = $printingSubTypeId ?: 0;

    if ($cardSizeId || $normSize !== '') {
        $where[] = '((ppm.card_size_id = ? AND ? > 0) OR REPLACE(REPLACE(REPLACE(LOWER(COALESCE(ppm.size_text,\'\')), \' \', \'\'), \'×\', \'x\'), \'*\', \'x\') = ? OR ppm.card_size_id IS NULL OR COALESCE(ppm.size_text,\'\') = \'\')';
        $types .= 'iis';
        $params[] = $cardSizeId ?: 0;
        $params[] = $cardSizeId ?: 0;
        $params[] = $normSize;
    }

    if ($gsmId || $normGsm !== '') {
        $where[] = '((ppm.gsm_id = ? AND ? > 0) OR REPLACE(LOWER(COALESCE(ppm.gsm_thickness,\'\')), \' \', \'\') = ? OR ppm.gsm_id IS NULL OR COALESCE(ppm.gsm_thickness,\'\') = \'\')';
        $types .= 'iis';
        $params[] = $gsmId ?: 0;
        $params[] = $gsmId ?: 0;
        $params[] = $normGsm;
    }

    $where[] = "ppm.printing_side IN (?, 'both', 'not_applicable', '')";
    $types .= 's';
    $params[] = $printingSide;

    $where[] = "ppm.lamination_type IN (?, 'not_applicable', 'none', '')";
    $types .= 's';
    $params[] = $laminationType;

    $where[] = "ppm.print_type IN (?, 'both', '')";
    $types .= 's';
    $params[] = $printType;

    $cardTypeSelect = $hasCardType ? 'ppm.card_type' : 'NULL AS card_type';
    $pricingModeSelect = $hasPricingMode ? 'ppm.pricing_mode' : "'total_charge' AS pricing_mode";
    $ratePerCardSelect = $hasRatePerCard ? 'ppm.rate_per_card' : '0.00 AS rate_per_card';
    $autoFillTargetSelect = $hasAutoFillTarget ? 'ppm.auto_fill_target' : "'printing_charge' AS auto_fill_target";

    $sql = "
        SELECT ppm.*,
               {$cardTypeSelect},
               {$pricingModeSelect},
               {$ratePerCardSelect},
               {$autoFillTargetSelect},
               COALESCE(p.product_name, ppm.product_name) AS display_product_name,
               pt.printing_name,
               pst.sub_type_name,
               csm.size_name,
               gm.gsm_name,
               lm.lamination_name
        FROM printing_price_master ppm
        LEFT JOIN products p ON p.id = ppm.product_id
        LEFT JOIN printing_types pt ON pt.id = ppm.printing_type_id
        LEFT JOIN printing_sub_types pst ON pst.id = ppm.printing_sub_type_id
        LEFT JOIN card_size_master csm ON csm.id = ppm.card_size_id
        LEFT JOIN gsm_master gm ON gm.id = ppm.gsm_id
        LEFT JOIN lamination_master lm ON lm.id = ppm.lamination_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            CASE WHEN ppm.product_id = ? THEN 0 ELSE 1 END,
            CASE WHEN ppm.printing_type_id = ? THEN 0 ELSE 1 END,
            CASE WHEN ppm.printing_sub_type_id = ? THEN 0 WHEN ppm.printing_sub_type_id IS NULL OR ppm.printing_sub_type_id = 0 THEN 1 ELSE 2 END,
            CASE WHEN ppm.card_size_id = ? THEN 0 ELSE 1 END,
            CASE WHEN ppm.gsm_id = ? THEN 0 ELSE 1 END,
            ppm.min_qty DESC,
            ppm.id DESC
        LIMIT 1
    ";
    $types .= 'iiiii';
    $params[] = $productId ?: 0;
    $params[] = $printingTypeId ?: 0;
    $params[] = $printingSubTypeId ?: 0;
    $params[] = $cardSizeId ?: 0;
    $params[] = $gsmId ?: 0;

    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        ppm_response(false, 'No matching predefined printing charge found. You can still edit Rate / Printing Charge manually.');
    }

    $qtyFloat = (float)$qty;
    $pricingMode = (string)($row['pricing_mode'] ?? 'total_charge');
    $autoFillTarget = (string)($row['auto_fill_target'] ?? 'printing_charge');
    $masterRate = (float)($row['rate'] ?? 0);
    $ratePerCard = (float)($row['rate_per_card'] ?? 0);
    $rate = 0.0;
    $printing = (float)($row['printing_charge'] ?? 0);

    if ($pricingMode === 'per_card') {
        $perCard = $ratePerCard > 0 ? $ratePerCard : $masterRate;
        if ($autoFillTarget === 'rate') {
            $rate = $perCard;
            $printing = (float)($row['printing_charge'] ?? 0);
        } elseif ($autoFillTarget === 'both') {
            $rate = $perCard;
            if ($printing <= 0 && $perCard > 0) $printing = round($qtyFloat * $perCard, 2);
        } else {
            $printing = $perCard > 0 ? round($qtyFloat * $perCard, 2) : $printing;
        }
    } else {
        // Sheet-style fixed slab charge: 500 cards = ₹3750, 1000 cards = ₹5000, etc.
        if ($printing <= 0 && $masterRate > 0 && $autoFillTarget !== 'rate') {
            $printing = $masterRate;
        }
        if ($autoFillTarget === 'rate' || $autoFillTarget === 'both') {
            $rate = $masterRate;
        }
    }

    $plate = (float)($row['plate_charge'] ?? 0);
    $package = (float)($row['package_charge'] ?? 0);
    $additional = (float)($row['additional_charge'] ?? 0);
    $itemAmount = round($qtyFloat * $rate, 2);
    $final = round($itemAmount + $plate + $printing + $package + $additional, 2);
    $slab = (int)$row['min_qty'] . ' - ' . (int)$row['max_qty'];

    ppm_response(true, 'Predefined printing charge matched successfully.', [
        'price' => [
            'id' => (int)$row['id'],
            'product_id' => !empty($row['product_id']) ? (int)$row['product_id'] : null,
            'product_name' => (string)($row['display_product_name'] ?? ''),
            'card_type' => (string)($row['card_type'] ?? ''),
            'printing_type_id' => !empty($row['printing_type_id']) ? (int)$row['printing_type_id'] : null,
            'printing_name' => (string)($row['printing_name'] ?? ''),
            'printing_sub_type_id' => !empty($row['printing_sub_type_id']) ? (int)$row['printing_sub_type_id'] : null,
            'sub_type_name' => (string)($row['sub_type_name'] ?? ''),
            'card_size_id' => !empty($row['card_size_id']) ? (int)$row['card_size_id'] : null,
            'size_text' => (string)($row['size_text'] ?: ($row['size_name'] ?? '')),
            'gsm_id' => !empty($row['gsm_id']) ? (int)$row['gsm_id'] : null,
            'gsm_thickness' => (string)($row['gsm_thickness'] ?: ($row['gsm_name'] ?? '')),
            'printing_side' => (string)($row['printing_side'] ?? ''),
            'lamination_type' => (string)($row['lamination_type'] ?? ''),
            'print_type' => (string)($row['print_type'] ?? ''),
            'pricing_mode' => $pricingMode,
            'auto_fill_target' => $autoFillTarget,
            'min_qty' => (int)$row['min_qty'],
            'max_qty' => (int)$row['max_qty'],
            'slab_text' => $slab,
            'rate' => $rate,
            'master_rate' => $masterRate,
            'rate_per_card' => $ratePerCard,
            'plate_charge' => $plate,
            'printing_charge' => $printing,
            'package_charge' => $package,
            'additional_charge' => $additional,
            'is_gst_inclusive' => (int)($row['is_gst_inclusive'] ?? 1),
            'gst_percent' => (float)($row['gst_percent'] ?? 18),
            'item_amount' => $itemAmount,
            'final_amount' => $final,
        ]
    ]);
} catch (Throwable $e) {
    ppm_response(false, 'Pricing lookup failed: ' . $e->getMessage());
}
