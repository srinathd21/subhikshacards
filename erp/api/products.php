<?php
/**
 * api/products.php
 * Subhiksha Cards ERP - Product write API
 *
 * UI files are kept separate. This endpoint handles only mutations:
 * create, bulk_add, update, remove, restore.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/product-stock-helper.php';

ps_require_module($conn);
header('X-Content-Type-Options: nosniff');

function p_api_wants_json(): bool
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return strpos($accept, 'application/json') !== false || $xhr === 'xmlhttprequest' || ($_POST['format'] ?? '') === 'json';
}

function p_api_success(string $message, string $redirect, array $data = []): void
{
    if (p_api_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    ps_redirect($redirect);
}

function p_api_fail(string $message, string $redirect, int $status = 422): void
{
    if (p_api_wants_json()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $join = strpos($redirect, '?') === false ? '?' : '&';
    ps_redirect($redirect . $join . 'error=' . urlencode($message));
}

function p_api_product(mysqli $conn, int $productId): ?array
{
    $stmt = $conn->prepare("\n        SELECT\n            p.*,\n            COALESCE(ps.minimum_stock,0) AS minimum_stock,\n            COALESCE(ps.low_stock_alert,0) AS low_stock_alert,\n            COALESCE(ps.on_hand_stock,0) AS on_hand_stock,\n            COALESCE(ps.reserved_stock,0) AS reserved_stock\n        FROM products p\n        LEFT JOIN product_stock ps ON ps.product_id=p.id\n        WHERE p.id=?\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    p_api_fail('Invalid request method.', '../manage-products.php', 405);
}

$action = trim((string)($_POST['action'] ?? ''));
$uploadedPaths = [];

try {
    ps_require_csrf();

    if ($action === 'create') {
        if (!can_create($conn, 'add-product.php')) {
            throw new RuntimeException('You do not have permission to add products.');
        }

        $productName = trim((string)($_POST['product_name'] ?? ''));
        $priceRaw = trim((string)($_POST['default_price'] ?? ''));
        $defaultPrice = $priceRaw === '' ? 0.0 : (float)$priceRaw;
        $lowStockAlert = isset($_POST['low_stock_alert']) ? 1 : 0;
        $minimumStock = $lowStockAlert ? (float)($_POST['minimum_stock'] ?? 0) : 0.0;

        if ($productName === '') throw new RuntimeException('Product Name is required.');
        if (mb_strlen($productName) > 200) throw new RuntimeException('Product Name cannot exceed 200 characters.');
        if ($defaultPrice < 0) throw new RuntimeException('Product Price cannot be negative.');
        if ($minimumStock < 0) throw new RuntimeException('Minimum Stock cannot be negative.');

        $stmt = $conn->prepare("SELECT id FROM products WHERE LOWER(TRIM(product_name))=LOWER(TRIM(?)) LIMIT 1");
        $stmt->bind_param('s', $productName);
        $stmt->execute();
        $duplicate = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($duplicate) throw new RuntimeException('A product with the same name already exists. Edit or restore the existing product instead.');

        if (empty($_FILES['thumbnail_image']) || (int)($_FILES['thumbnail_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Thumbnail Image is required.');
        }

        $thumbnail = ps_upload_image($_FILES['thumbnail_image'], 'thumb');
        $uploadedPaths[] = $thumbnail;

        $secondaryPaths = [];
        if (!empty($_FILES['secondary_images'])) {
            $secondaryFiles = ps_multi_files($_FILES['secondary_images']);
            $secondaryFiles = array_values(array_filter($secondaryFiles, static function ($file) {
                return (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            }));
            if (count($secondaryFiles) > 10) throw new RuntimeException('You can upload up to 10 secondary images for one product.');
            foreach ($secondaryFiles as $file) {
                $path = ps_upload_image($file, 'secondary');
                if ($path !== '') {
                    $secondaryPaths[] = $path;
                    $uploadedPaths[] = $path;
                }
            }
        }

        $conn->begin_transaction();
        try {
            $userId = ps_user_id();
            $productKey = ps_product_key($conn, $productName);
            $orderType = 'both';

            $stmt = $conn->prepare("\n                INSERT INTO products\n                    (product_name, product_key, default_order_type, default_price, thumbnail_image, is_active, is_removed, created_by, created_at)\n                VALUES (?, ?, ?, ?, ?, 1, 0, ?, NOW())\n            ");
            $stmt->bind_param('sssdsi', $productName, $productKey, $orderType, $defaultPrice, $thumbnail, $userId);
            $stmt->execute();
            $productId = (int)$stmt->insert_id;
            $stmt->close();
            if ($productId <= 0) throw new RuntimeException('Unable to create product.');

            $stmt = $conn->prepare("\n                INSERT INTO product_stock\n                    (product_id, on_hand_stock, reserved_stock, minimum_stock, low_stock_alert, created_at, updated_at)\n                VALUES (?, 0, 0, ?, ?, NOW(), NOW())\n            ");
            $stmt->bind_param('idi', $productId, $minimumStock, $lowStockAlert);
            $stmt->execute();
            $stmt->close();

            $sort = 1;
            foreach ($secondaryPaths as $path) {
                $stmt = $conn->prepare("\n                    INSERT INTO product_images\n                        (product_id, image_path, sort_order, is_active, created_by, created_at)\n                    VALUES (?, ?, ?, 1, ?, NOW())\n                ");
                $stmt->bind_param('isii', $productId, $path, $sort, $userId);
                $stmt->execute();
                $stmt->close();
                $sort++;
            }

            ps_log_product_history($conn, $productId, 'created', 'Product created.', null, [
                'product_name' => $productName,
                'default_price' => $defaultPrice,
                'thumbnail_image' => $thumbnail,
                'secondary_images' => count($secondaryPaths),
                'minimum_stock' => $minimumStock,
                'low_stock_alert' => $lowStockAlert,
            ], $userId);

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        p_api_success('Product saved successfully.', '../manage-products.php?saved=1', ['product_id' => $productId]);
    }

    if ($action === 'bulk_add') {
        if (!can_create($conn, 'manage-products.php')) {
            throw new RuntimeException('You do not have permission to add products.');
        }

        $names = $_POST['bulk_product_name'] ?? [];
        $prices = $_POST['bulk_default_price'] ?? [];
        if (!is_array($names) || !is_array($prices)) throw new RuntimeException('Invalid bulk product data.');

        $rowsToSave = [];
        $seenNames = [];

        foreach (array_values($names) as $i => $rawName) {
            $name = trim((string)$rawName);
            $priceRaw = trim((string)($prices[$i] ?? ''));
            $file = [
                'name' => $_FILES['bulk_thumbnail_image']['name'][$i] ?? '',
                'type' => $_FILES['bulk_thumbnail_image']['type'][$i] ?? '',
                'tmp_name' => $_FILES['bulk_thumbnail_image']['tmp_name'][$i] ?? '',
                'error' => $_FILES['bulk_thumbnail_image']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['bulk_thumbnail_image']['size'][$i] ?? 0,
            ];

            $rowHasAnyValue = $name !== '' || $priceRaw !== '' || (int)$file['error'] !== UPLOAD_ERR_NO_FILE;
            if (!$rowHasAnyValue) continue;
            if ($name === '') throw new RuntimeException('Product Name is required for every entered bulk row.');
            if (mb_strlen($name) > 200) throw new RuntimeException('Product Name cannot exceed 200 characters.');

            $price = $priceRaw === '' ? 0.0 : (float)$priceRaw;
            if ($price < 0) throw new RuntimeException('Product Price cannot be negative for ' . $name . '.');

            $nameKey = mb_strtolower($name);
            if (isset($seenNames[$nameKey])) throw new RuntimeException('Duplicate product name in Bulk Add: ' . $name . '.');
            $seenNames[$nameKey] = true;

            if ((int)$file['error'] === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Thumbnail Image is required for ' . $name . '.');

            $stmt = $conn->prepare("SELECT id FROM products WHERE LOWER(TRIM(product_name))=LOWER(TRIM(?)) LIMIT 1");
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($existing) throw new RuntimeException('Product already exists: ' . $name . '. Edit or restore the existing product instead.');

            $thumbnail = ps_upload_image($file, 'thumb');
            $uploadedPaths[] = $thumbnail;
            $rowsToSave[] = ['name' => $name, 'price' => $price, 'thumbnail' => $thumbnail];
        }

        if (!$rowsToSave) throw new RuntimeException('Enter at least one product in Bulk Add.');
        if (count($rowsToSave) > 50) throw new RuntimeException('A maximum of 50 products can be added at one time.');

        $conn->begin_transaction();
        try {
            $userId = ps_user_id();
            foreach ($rowsToSave as $row) {
                $productKey = ps_product_key($conn, $row['name']);
                $orderType = 'both';
                $stmt = $conn->prepare("\n                    INSERT INTO products\n                        (product_name, product_key, default_order_type, default_price, thumbnail_image, is_active, is_removed, created_by, created_at)\n                    VALUES (?, ?, ?, ?, ?, 1, 0, ?, NOW())\n                ");
                $stmt->bind_param('sssdsi', $row['name'], $productKey, $orderType, $row['price'], $row['thumbnail'], $userId);
                $stmt->execute();
                $newProductId = (int)$stmt->insert_id;
                $stmt->close();
                if ($newProductId <= 0) throw new RuntimeException('Unable to create product: ' . $row['name']);

                ps_ensure_stock_row($conn, $newProductId);
                ps_log_product_history($conn, $newProductId, 'created', 'Product created through Bulk Add.', null, [
                    'product_name' => $row['name'],
                    'default_price' => $row['price'],
                    'thumbnail_image' => $row['thumbnail'],
                ], $userId);
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        p_api_success(count($rowsToSave) . ' products added successfully.', '../manage-products.php?bulk_saved=' . count($rowsToSave), ['count' => count($rowsToSave)]);
    }

    if ($action === 'update') {
        if (!can_edit($conn, 'edit-product.php')) {
            throw new RuntimeException('You do not have permission to edit products.');
        }

        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId <= 0) throw new RuntimeException('Invalid product.');
        $product = p_api_product($conn, $productId);
        if (!$product) throw new RuntimeException('Product not found.');
        if ((int)($product['is_removed'] ?? 0) === 1) throw new RuntimeException('Restore the removed product before editing it.');

        $productName = trim((string)($_POST['product_name'] ?? ''));
        $priceRaw = trim((string)($_POST['default_price'] ?? ''));
        $price = $priceRaw === '' ? 0.0 : (float)$priceRaw;
        $alert = isset($_POST['low_stock_alert']) ? 1 : 0;
        $minimum = $alert ? (float)($_POST['minimum_stock'] ?? 0) : 0.0;

        if ($productName === '') throw new RuntimeException('Product Name is required.');
        if (mb_strlen($productName) > 200) throw new RuntimeException('Product Name cannot exceed 200 characters.');
        if ($price < 0 || $minimum < 0) throw new RuntimeException('Price and Minimum Stock cannot be negative.');

        $stmt = $conn->prepare("SELECT id FROM products WHERE LOWER(TRIM(product_name))=LOWER(TRIM(?)) AND id<>? LIMIT 1");
        $stmt->bind_param('si', $productName, $productId);
        $stmt->execute();
        $duplicate = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($duplicate) throw new RuntimeException('Another product with the same name already exists.');

        $newThumbnail = (string)($product['thumbnail_image'] ?? '');
        if (!empty($_FILES['thumbnail_image']) && (int)($_FILES['thumbnail_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newThumbnail = ps_upload_image($_FILES['thumbnail_image'], 'thumb');
            $uploadedPaths[] = $newThumbnail;
        }

        $newSecondary = [];
        if (!empty($_FILES['secondary_images'])) {
            $secondaryFiles = ps_multi_files($_FILES['secondary_images']);
            $secondaryFiles = array_values(array_filter($secondaryFiles, static function ($file) {
                return (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            }));
            if (count($secondaryFiles) > 10) throw new RuntimeException('You can upload up to 10 secondary images at a time.');
            foreach ($secondaryFiles as $file) {
                $path = ps_upload_image($file, 'secondary');
                if ($path !== '') {
                    $newSecondary[] = $path;
                    $uploadedPaths[] = $path;
                }
            }
        }

        $removeImageIds = array_values(array_unique(array_filter(array_map('intval', is_array($_POST['remove_secondary'] ?? null) ? $_POST['remove_secondary'] : []))));

        $conn->begin_transaction();
        try {
            $userId = ps_user_id();
            $oldData = $product;

            $stmt = $conn->prepare("\n                UPDATE products\n                SET product_name=?, default_price=?, thumbnail_image=?, updated_by=?, updated_at=NOW()\n                WHERE id=?\n            ");
            $stmt->bind_param('sdsii', $productName, $price, $newThumbnail, $userId, $productId);
            $stmt->execute();
            $stmt->close();

            ps_ensure_stock_row($conn, $productId);
            $stmt = $conn->prepare("UPDATE product_stock SET minimum_stock=?, low_stock_alert=?, updated_at=NOW() WHERE product_id=?");
            $stmt->bind_param('dii', $minimum, $alert, $productId);
            $stmt->execute();
            $stmt->close();

            if ($removeImageIds) {
                $placeholders = implode(',', array_fill(0, count($removeImageIds), '?'));
                $types = str_repeat('i', count($removeImageIds)) . 'i';
                $params = $removeImageIds;
                $params[] = $productId;
                $stmt = $conn->prepare("UPDATE product_images SET is_active=0, updated_at=NOW() WHERE id IN ({$placeholders}) AND product_id=?");
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order),0) AS max_sort FROM product_images WHERE product_id=?");
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $sortRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $sort = (int)($sortRow['max_sort'] ?? 0) + 1;

            foreach ($newSecondary as $path) {
                $stmt = $conn->prepare("INSERT INTO product_images (product_id,image_path,sort_order,is_active,created_by,created_at) VALUES (?,?,?,1,?,NOW())");
                $stmt->bind_param('isii', $productId, $path, $sort, $userId);
                $stmt->execute();
                $stmt->close();
                $sort++;
            }

            ps_log_product_history($conn, $productId, 'updated', 'Product details updated.', [
                'product_name' => $oldData['product_name'] ?? '',
                'default_price' => $oldData['default_price'] ?? 0,
                'thumbnail_image' => $oldData['thumbnail_image'] ?? '',
                'minimum_stock' => $oldData['minimum_stock'] ?? 0,
                'low_stock_alert' => $oldData['low_stock_alert'] ?? 0,
            ], [
                'product_name' => $productName,
                'default_price' => $price,
                'thumbnail_image' => $newThumbnail,
                'minimum_stock' => $minimum,
                'low_stock_alert' => $alert,
                'secondary_images_added' => count($newSecondary),
                'secondary_images_removed' => count($removeImageIds),
            ], $userId);

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        p_api_success('Product updated successfully.', '../manage-products.php?saved=1', ['product_id' => $productId]);
    }

    if ($action === 'remove' || $action === 'restore') {
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId <= 0) throw new RuntimeException('Invalid product.');
        $product = p_api_product($conn, $productId);
        if (!$product) throw new RuntimeException('Product not found.');

        if ($action === 'remove') {
            if (!can_delete($conn, 'manage-products.php')) throw new RuntimeException('You do not have permission to remove products.');
            $reason = trim((string)($_POST['removal_reason'] ?? ''));
            if (mb_strlen($reason) < 3) throw new RuntimeException('Removal description is required.');

            $conn->begin_transaction();
            try {
                $userId = ps_user_id();
                $stmt = $conn->prepare("\n                    UPDATE products\n                    SET is_active=0,is_removed=1,removed_at=NOW(),removed_by=?,removal_reason=?,updated_by=?,updated_at=NOW()\n                    WHERE id=?\n                ");
                $stmt->bind_param('isii', $userId, $reason, $userId, $productId);
                $stmt->execute();
                $stmt->close();
                ps_log_product_history($conn, $productId, 'removed', $reason, $product, ['is_active'=>0,'is_removed'=>1,'removal_reason'=>$reason], $userId);
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            p_api_success('Product removed successfully.', '../manage-products.php?removed=1', ['product_id'=>$productId]);
        }

        if (!can_edit($conn, 'manage-products.php')) throw new RuntimeException('You do not have permission to restore products.');
        $conn->begin_transaction();
        try {
            $userId = ps_user_id();
            $stmt = $conn->prepare("\n                UPDATE products\n                SET is_active=1,is_removed=0,removed_at=NULL,removed_by=NULL,removal_reason=NULL,updated_by=?,updated_at=NOW()\n                WHERE id=?\n            ");
            $stmt->bind_param('ii', $userId, $productId);
            $stmt->execute();
            $stmt->close();
            ps_log_product_history($conn, $productId, 'restored', 'Product restored to active list.', $product, ['is_active'=>1,'is_removed'=>0], $userId);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        p_api_success('Product restored successfully.', '../manage-products.php?restored=1', ['product_id'=>$productId]);
    }

    throw new RuntimeException('Invalid action.');
} catch (Throwable $e) {
    foreach ($uploadedPaths as $path) {
        ps_delete_uploaded_path($path);
    }

    $redirect = '../manage-products.php';
    if ($action === 'create') $redirect = '../add-product.php';
    if ($action === 'update') {
        $id = (int)($_POST['product_id'] ?? 0);
        $redirect = '../edit-product.php?id=' . $id;
    }
    p_api_fail($e->getMessage(), $redirect);
}
