<?php
/**
 * includes/proforma-stock-integration.php
 *
 * Ready-to-call stock integration for Subhiksha Proforma.
 *
 * IMPORTANT:
 * Call ps_sync_proforma_stock() from api/create_proforma.php AFTER the
 * proforma_bill_items are saved and BEFORE the database transaction commits.
 *
 * It sets the TARGET reservation for each product. Therefore Edit Proforma:
 * 100 -> 150 reserves only +50, and 150 -> 80 releases 70.
 *
 * Reserved Stock is allowed to exceed On Hand Stock, so Available Stock
 * can become negative without blocking the Proforma.
 */

require_once __DIR__ . '/product-stock-helper.php';

if (!function_exists('ps_sync_proforma_stock')) {
    function ps_sync_proforma_stock(
        mysqli $conn,
        int $proformaId,
        string $proformaNo,
        int $userId = 0
    ): array {
        if ($proformaId <= 0) {
            throw new RuntimeException('Invalid Proforma ID for stock reservation.');
        }

        ps_require_module($conn);

        // Aggregate multiple lines of the same product.
        $stmt = $conn->prepare("
            SELECT product_id, COALESCE(SUM(qty),0) AS total_qty
            FROM proforma_bill_items
            WHERE proforma_bill_id = ?
              AND product_id IS NOT NULL
              AND product_id > 0
            GROUP BY product_id
        ");
        $stmt->bind_param('i', $proformaId);
        $stmt->execute();
        $res = $stmt->get_result();

        $targets = [];
        while ($row = $res->fetch_assoc()) {
            $targets[(int)$row['product_id']] = (float)$row['total_qty'];
        }
        $stmt->close();

        // Existing active reservations may include products removed during edit.
        $stmt = $conn->prepare("
            SELECT product_id
            FROM product_stock_reservations
            WHERE reference_type = 'proforma'
              AND reference_id = ?
              AND status = 'active'
        ");
        $stmt->bind_param('i', $proformaId);
        $stmt->execute();
        $res = $stmt->get_result();

        $existing = [];
        while ($row = $res->fetch_assoc()) {
            $existing[] = (int)$row['product_id'];
        }
        $stmt->close();

        $allProductIds = array_values(array_unique(array_merge(array_keys($targets), $existing)));
        $result = [];

        foreach ($allProductIds as $productId) {
            $targetQty = (float)($targets[$productId] ?? 0);

            $result[$productId] = ps_set_reservation(
                $conn,
                $productId,
                'proforma',
                $proformaId,
                $proformaNo,
                $targetQty,
                $targetQty > 0
                    ? 'Stock reserved/updated from Proforma ' . $proformaNo
                    : 'Stock reservation released because product was removed from Proforma ' . $proformaNo,
                $userId
            );
        }

        return $result;
    }
}

if (!function_exists('ps_cancel_proforma_stock')) {
    function ps_cancel_proforma_stock(
        mysqli $conn,
        int $proformaId,
        string $reason,
        int $userId = 0
    ): void {
        ps_release_reference_reservations(
            $conn,
            'proforma',
            $proformaId,
            trim($reason) !== '' ? $reason : 'Proforma cancelled - reserved stock released.',
            $userId
        );
    }
}

if (!function_exists('ps_dispatch_proforma_stock')) {
    function ps_dispatch_proforma_stock(
        mysqli $conn,
        int $proformaId,
        string $description,
        int $userId = 0,
        bool $allowNegativeOnHand = false
    ): array {
        if ($proformaId <= 0) {
            throw new RuntimeException('Invalid Proforma ID for dispatch stock update.');
        }

        $stmt = $conn->prepare("\n            SELECT product_id\n            FROM product_stock_reservations\n            WHERE reference_type = 'proforma'\n              AND reference_id = ?\n              AND status = 'active'\n            ORDER BY id ASC\n        ");
        $stmt->bind_param('i', $proformaId);
        $stmt->execute();
        $res = $stmt->get_result();
        $productIds = [];
        while ($row = $res->fetch_assoc()) {
            $productIds[] = (int)$row['product_id'];
        }
        $stmt->close();

        $result = [];
        foreach ($productIds as $productId) {
            $result[$productId] = ps_dispatch_reservation(
                $conn,
                $productId,
                'proforma',
                $proformaId,
                trim($description) !== '' ? $description : 'Stock dispatched against Proforma.',
                $userId,
                $allowNegativeOnHand
            );
        }

        return $result;
    }
}

