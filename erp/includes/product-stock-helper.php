<?php
/**
 * includes/product-stock-helper.php
 * Subhiksha Cards ERP - Product and stock helper.
 *
 * This helper is intentionally independent from Proforma UI.
 * It provides:
 * - CSRF protection
 * - Product image upload helpers
 * - Product / stock history helpers
 * - On Hand / Reserved / Available stock functions
 * - Reservation functions that allow Available Stock to go negative
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('ps_e')) {
    function ps_e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ps_table_exists')) {
    function ps_table_exists(mysqli $conn, string $table): bool
    {
        $safe = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $ok;
    }
}

if (!function_exists('ps_column_exists')) {
    function ps_column_exists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $ok;
    }
}

if (!function_exists('ps_require_module')) {
    function ps_require_module(mysqli $conn): void
    {
        $required = [
            'products',
            'product_images',
            'product_stock',
            'product_history',
            'stock_transactions',
            'product_stock_reservations',
        ];

        foreach ($required as $table) {
            if (!ps_table_exists($conn, $table)) {
                throw new RuntimeException(
                    'Product & Stock module is not installed. Import database/product_stock_module_subhiksha16.sql first.'
                );
            }
        }
    }
}

if (!function_exists('ps_csrf_token')) {
    function ps_csrf_token(): string
    {
        if (empty($_SESSION['product_stock_csrf'])) {
            $_SESSION['product_stock_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['product_stock_csrf'];
    }
}

if (!function_exists('ps_require_csrf')) {
    function ps_require_csrf(): void
    {
        $posted = (string)($_POST['csrf_token'] ?? '');
        $stored = (string)($_SESSION['product_stock_csrf'] ?? '');
        if ($posted === '' || $stored === '' || !hash_equals($stored, $posted)) {
            http_response_code(400);
            throw new RuntimeException('Invalid request token. Refresh the page and try again.');
        }
    }
}

if (!function_exists('ps_user_id')) {
    function ps_user_id(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('ps_redirect')) {
    function ps_redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('ps_money')) {
    function ps_money($value): string
    {
        return '₹' . number_format((float)$value, 2);
    }
}

if (!function_exists('ps_qty')) {
    function ps_qty($value): string
    {
        $formatted = number_format((float)$value, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }
}

if (!function_exists('ps_product_key')) {
    function ps_product_key(mysqli $conn, string $productName): string
    {
        $slug = strtolower(trim($productName));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim((string)$slug, '_');
        if ($slug === '') $slug = 'product';
        $slug = substr($slug, 0, 80);

        for ($i = 0; $i < 20; $i++) {
            $suffix = $i === 0 ? '' : '_' . bin2hex(random_bytes(2));
            $key = $slug . $suffix;
            $stmt = $conn->prepare('SELECT id FROM products WHERE product_key = ? LIMIT 1');
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$exists) return $key;
        }

        return 'product_' . bin2hex(random_bytes(6));
    }
}

if (!function_exists('ps_upload_dir')) {
    function ps_upload_dir(): string
    {
        return dirname(__DIR__) . '/uploads/products';
    }
}

if (!function_exists('ps_upload_image')) {
    function ps_upload_image(array $file, string $prefix = 'product'): string
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            return '';
        }

        if ($error !== UPLOAD_ERR_OK) {
            $message = 'Image upload failed.';
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                $message = 'Image is larger than the server upload limit.';
            }
            throw new RuntimeException($message);
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid uploaded image.');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Uploaded image is empty.');
        }
        if ($size > 10 * 1024 * 1024) {
            throw new RuntimeException('Product image must be 10 MB or smaller.');
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }

        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        if (!isset($map[$mime])) {
            throw new RuntimeException('Only JPG, PNG, WEBP or GIF images are allowed.');
        }

        $dir = ps_upload_dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Unable to create product image folder.');
        }

        $safePrefix = preg_replace('/[^a-z0-9_-]+/i', '_', $prefix);
        $filename = $safePrefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $map[$mime];
        $target = $dir . '/' . $filename;

        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('Unable to save uploaded image.');
        }

        return 'uploads/products/' . $filename;
    }
}

if (!function_exists('ps_delete_uploaded_path')) {
    function ps_delete_uploaded_path(string $relativePath): void
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '' || strpos($relativePath, 'uploads/products/') !== 0) return;

        $full = dirname(__DIR__) . '/' . $relativePath;
        if (is_file($full)) @unlink($full);
    }
}

if (!function_exists('ps_multi_files')) {
    function ps_multi_files(array $files): array
    {
        $out = [];
        $names = $files['name'] ?? [];
        if (!is_array($names)) return $out;

        foreach ($names as $i => $name) {
            $out[] = [
                'name' => $name,
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
        }

        return $out;
    }
}

if (!function_exists('ps_log_product_history')) {
    function ps_log_product_history(
        mysqli $conn,
        int $productId,
        string $actionType,
        string $description,
        ?array $oldData = null,
        ?array $newData = null,
        int $userId = 0
    ): void {
        $stmt = $conn->prepare("
            INSERT INTO product_history
                (product_id, action_type, description, old_data, new_data, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $oldJson = $oldData === null ? null : json_encode($oldData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $newJson = $newData === null ? null : json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $uid = $userId > 0 ? $userId : null;

        $stmt->bind_param('issssi', $productId, $actionType, $description, $oldJson, $newJson, $uid);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('ps_ensure_stock_row')) {
    function ps_ensure_stock_row(mysqli $conn, int $productId): void
    {
        $stmt = $conn->prepare("
            INSERT INTO product_stock
                (product_id, on_hand_stock, reserved_stock, minimum_stock, low_stock_alert, created_at, updated_at)
            VALUES (?, 0, 0, 0, 0, NOW(), NOW())
            ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)
        ");
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('ps_get_stock_for_update')) {
    function ps_get_stock_for_update(mysqli $conn, int $productId): array
    {
        ps_ensure_stock_row($conn, $productId);

        $stmt = $conn->prepare("
            SELECT *
            FROM product_stock
            WHERE product_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('Stock record could not be loaded.');
        }

        return $row;
    }
}

if (!function_exists('ps_log_stock_transaction')) {
    function ps_log_stock_transaction(
        mysqli $conn,
        int $productId,
        string $transactionType,
        float $quantity,
        float $onHandBefore,
        float $onHandAfter,
        float $reservedBefore,
        float $reservedAfter,
        string $referenceType = '',
        int $referenceId = 0,
        string $referenceNo = '',
        string $description = '',
        int $userId = 0
    ): int {
        $stmt = $conn->prepare("
            INSERT INTO stock_transactions
            (
                product_id,
                transaction_type,
                quantity,
                on_hand_before,
                on_hand_after,
                reserved_before,
                reserved_after,
                reference_type,
                reference_id,
                reference_no,
                description,
                created_by,
                created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $refId = $referenceId > 0 ? $referenceId : null;
        $uid = $userId > 0 ? $userId : null;

        $stmt->bind_param(
            'isdddddsissi',
            $productId,
            $transactionType,
            $quantity,
            $onHandBefore,
            $onHandAfter,
            $reservedBefore,
            $reservedAfter,
            $referenceType,
            $refId,
            $referenceNo,
            $description,
            $uid
        );
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        return $id;
    }
}

if (!function_exists('ps_adjust_on_hand')) {
    function ps_adjust_on_hand(
        mysqli $conn,
        int $productId,
        float $quantityDelta,
        string $transactionType,
        string $description,
        int $userId = 0,
        string $referenceType = '',
        int $referenceId = 0,
        string $referenceNo = '',
        bool $allowNegativeOnHand = false
    ): array {
        if ($productId <= 0 || abs($quantityDelta) < 0.000001) {
            throw new RuntimeException('Valid product and quantity are required.');
        }

        $stock = ps_get_stock_for_update($conn, $productId);
        $onHandBefore = (float)$stock['on_hand_stock'];
        $reservedBefore = (float)$stock['reserved_stock'];
        $onHandAfter = $onHandBefore + $quantityDelta;

        if (!$allowNegativeOnHand && $onHandAfter < -0.000001) {
            throw new RuntimeException(
                'Reduce quantity cannot be greater than On Hand Stock. Available On Hand: ' . ps_qty($onHandBefore)
            );
        }

        $stmt = $conn->prepare("
            UPDATE product_stock
            SET on_hand_stock = ?, updated_at = NOW()
            WHERE product_id = ?
        ");
        $stmt->bind_param('di', $onHandAfter, $productId);
        $stmt->execute();
        $stmt->close();

        ps_log_stock_transaction(
            $conn,
            $productId,
            $transactionType,
            $quantityDelta,
            $onHandBefore,
            $onHandAfter,
            $reservedBefore,
            $reservedBefore,
            $referenceType,
            $referenceId,
            $referenceNo,
            $description,
            $userId
        );

        return [
            'on_hand_before' => $onHandBefore,
            'on_hand_after' => $onHandAfter,
            'reserved_before' => $reservedBefore,
            'reserved_after' => $reservedBefore,
            'available_before' => $onHandBefore - $reservedBefore,
            'available_after' => $onHandAfter - $reservedBefore,
        ];
    }
}

if (!function_exists('ps_set_reservation')) {
    function ps_set_reservation(
        mysqli $conn,
        int $productId,
        string $referenceType,
        int $referenceId,
        string $referenceNo,
        float $targetQuantity,
        string $description,
        int $userId = 0
    ): array {
        if ($productId <= 0 || $referenceId <= 0 || trim($referenceType) === '') {
            throw new RuntimeException('Reservation reference is invalid.');
        }

        $targetQuantity = max(0, $targetQuantity);
        $stock = ps_get_stock_for_update($conn, $productId);

        $stmt = $conn->prepare("
            SELECT *
            FROM product_stock_reservations
            WHERE product_id = ?
              AND reference_type = ?
              AND reference_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('isi', $productId, $referenceType, $referenceId);
        $stmt->execute();
        $reservation = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $oldQty = $reservation && ($reservation['status'] ?? '') === 'active'
            ? (float)$reservation['quantity']
            : 0.0;

        $delta = $targetQuantity - $oldQty;

        $onHandBefore = (float)$stock['on_hand_stock'];
        $reservedBefore = (float)$stock['reserved_stock'];
        $reservedAfter = max(0, $reservedBefore + $delta);

        $stmt = $conn->prepare("
            UPDATE product_stock
            SET reserved_stock = ?, updated_at = NOW()
            WHERE product_id = ?
        ");
        $stmt->bind_param('di', $reservedAfter, $productId);
        $stmt->execute();
        $stmt->close();

        $status = $targetQuantity > 0 ? 'active' : 'released';

        if ($reservation) {
            $reservationId = (int)$reservation['id'];
            $stmt = $conn->prepare("
                UPDATE product_stock_reservations
                SET quantity = ?,
                    reference_no = ?,
                    status = ?,
                    description = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $uid = $userId > 0 ? $userId : null;
            $stmt->bind_param('dsssii', $targetQuantity, $referenceNo, $status, $description, $uid, $reservationId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("
                INSERT INTO product_stock_reservations
                (
                    product_id, reference_type, reference_id, reference_no,
                    quantity, status, description, created_by, created_at, updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $uid = $userId > 0 ? $userId : null;
            $stmt->bind_param(
                'isisdssi',
                $productId,
                $referenceType,
                $referenceId,
                $referenceNo,
                $targetQuantity,
                $status,
                $description,
                $uid
            );
            $stmt->execute();
            $stmt->close();
        }

        if (abs($delta) > 0.000001) {
            ps_log_stock_transaction(
                $conn,
                $productId,
                $delta > 0 ? 'reserve' : 'reserve_release',
                $delta,
                $onHandBefore,
                $onHandBefore,
                $reservedBefore,
                $reservedAfter,
                $referenceType,
                $referenceId,
                $referenceNo,
                $description,
                $userId
            );
        }

        return [
            'reservation_before' => $oldQty,
            'reservation_after' => $targetQuantity,
            'on_hand' => $onHandBefore,
            'reserved_before' => $reservedBefore,
            'reserved_after' => $reservedAfter,
            'available_before' => $onHandBefore - $reservedBefore,
            'available_after' => $onHandBefore - $reservedAfter,
        ];
    }
}

if (!function_exists('ps_release_reference_reservations')) {
    function ps_release_reference_reservations(
        mysqli $conn,
        string $referenceType,
        int $referenceId,
        string $description,
        int $userId = 0
    ): void {
        $stmt = $conn->prepare("
            SELECT product_id, reference_no
            FROM product_stock_reservations
            WHERE reference_type = ?
              AND reference_id = ?
              AND status = 'active'
            ORDER BY id ASC
        ");
        $stmt->bind_param('si', $referenceType, $referenceId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();

        foreach ($rows as $row) {
            ps_set_reservation(
                $conn,
                (int)$row['product_id'],
                $referenceType,
                $referenceId,
                (string)($row['reference_no'] ?? ''),
                0,
                $description,
                $userId
            );
        }
    }
}

if (!function_exists('ps_dispatch_reservation')) {
    function ps_dispatch_reservation(
        mysqli $conn,
        int $productId,
        string $referenceType,
        int $referenceId,
        string $description,
        int $userId = 0,
        bool $allowNegativeOnHand = false
    ): array {
        $stock = ps_get_stock_for_update($conn, $productId);

        $stmt = $conn->prepare("
            SELECT *
            FROM product_stock_reservations
            WHERE product_id = ?
              AND reference_type = ?
              AND reference_id = ?
              AND status = 'active'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('isi', $productId, $referenceType, $referenceId);
        $stmt->execute();
        $reservation = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$reservation) {
            throw new RuntimeException('No active reservation found for dispatch.');
        }

        $qty = (float)$reservation['quantity'];
        $onHandBefore = (float)$stock['on_hand_stock'];
        $reservedBefore = (float)$stock['reserved_stock'];
        $onHandAfter = $onHandBefore - $qty;
        $reservedAfter = max(0, $reservedBefore - $qty);

        if (!$allowNegativeOnHand && $onHandAfter < -0.000001) {
            throw new RuntimeException(
                'Dispatch cannot be completed. On Hand Stock is only ' . ps_qty($onHandBefore) .
                ' but reserved quantity is ' . ps_qty($qty) . '.'
            );
        }

        $stmt = $conn->prepare("
            UPDATE product_stock
            SET on_hand_stock = ?, reserved_stock = ?, updated_at = NOW()
            WHERE product_id = ?
        ");
        $stmt->bind_param('ddi', $onHandAfter, $reservedAfter, $productId);
        $stmt->execute();
        $stmt->close();

        $reservationId = (int)$reservation['id'];
        $stmt = $conn->prepare("
            UPDATE product_stock_reservations
            SET status = 'dispatched',
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $uid = $userId > 0 ? $userId : null;
        $stmt->bind_param('ii', $uid, $reservationId);
        $stmt->execute();
        $stmt->close();

        ps_log_stock_transaction(
            $conn,
            $productId,
            'dispatch',
            -$qty,
            $onHandBefore,
            $onHandAfter,
            $reservedBefore,
            $reservedAfter,
            $referenceType,
            $referenceId,
            (string)($reservation['reference_no'] ?? ''),
            $description,
            $userId
        );

        return [
            'on_hand_before' => $onHandBefore,
            'on_hand_after' => $onHandAfter,
            'reserved_before' => $reservedBefore,
            'reserved_after' => $reservedAfter,
            'available_after' => $onHandAfter - $reservedAfter,
        ];
    }
}

if (!function_exists('ps_stock_status')) {
    function ps_stock_status(float $onHand, float $reserved, float $minimum, int $alertEnabled): array
    {
        $available = $onHand - $reserved;

        if ($available < 0) {
            return ['key' => 'negative', 'label' => 'Negative Stock', 'class' => 'danger'];
        }

        if ($available <= 0) {
            return ['key' => 'out', 'label' => 'Out of Stock', 'class' => 'secondary'];
        }

        if ($alertEnabled === 1 && $minimum > 0 && $available <= $minimum) {
            return ['key' => 'low', 'label' => 'Low Stock', 'class' => 'warning'];
        }

        return ['key' => 'in', 'label' => 'In Stock', 'class' => 'success'];
    }
}

if (!function_exists('ps_fetch_products_with_stock')) {
    function ps_fetch_products_with_stock(mysqli $conn, bool $includeRemoved = false): array
    {
        $where = $includeRemoved ? '1=1' : 'COALESCE(p.is_removed, 0) = 0 AND p.is_active = 1';

        $sql = "
            SELECT
                p.*,
                COALESCE(ps.on_hand_stock, 0) AS on_hand_stock,
                COALESCE(ps.reserved_stock, 0) AS reserved_stock,
                COALESCE(ps.minimum_stock, 0) AS minimum_stock,
                COALESCE(ps.low_stock_alert, 0) AS low_stock_alert,
                COALESCE(ps.on_hand_stock, 0) - COALESCE(ps.reserved_stock, 0) AS available_stock
            FROM products p
            LEFT JOIN product_stock ps ON ps.product_id = p.id
            WHERE {$where}
            ORDER BY p.product_name ASC
        ";

        $rows = [];
        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $res->free();
        return $rows;
    }
}
