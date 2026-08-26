<?php
/**
 * quick_sale_invoice_pdf.php
 *
 * Dedicated Quick Sale / Cash Invoice.
 *
 * IMPORTANT:
 * - No Proforma Bill layout.
 * - No Proforma DB record.
 * - No stock reservation.
 * - No Job Card / workflow.
 * - Supports normal ERP access by ?id=
 * - Supports secure customer/WhatsApp access by ?token=
 */

require_once __DIR__ . '/includes/db.php';

$invoiceToken = trim((string)($_GET['token'] ?? ''));
$quickSaleId = (int)($_GET['id'] ?? 0);

/*
 * WhatsApp/Wabbs approved button format:
 *   https://subhikshacards.in/erp/quick_sale_invoice_pdf.php?id={{1}}
 *
 * Therefore ?id= must be readable without an ERP login. The endpoint only
 * renders this Quick Sale invoice; it does not expose ERP navigation/actions.
 * Secure token links remain supported for older messages.
 */
$publicAccess = $invoiceToken !== '' || $quickSaleId > 0;


if ($invoiceToken === '' && $quickSaleId <= 0) {
    http_response_code(400);
    die('Invalid Quick Sale.');
}

if (
    $invoiceToken !== ''
    && !preg_match('/^[a-f0-9]{48}$/i', $invoiceToken)
) {
    http_response_code(400);
    die('Invalid invoice token.');
}

require_once __DIR__ . '/includes/quick-sale-invoice-pdf.php';

try {
    // ---------------------------------------------------------------
    // Quick Sale Header + Customer
    // ---------------------------------------------------------------
    if ($invoiceToken !== '') {
        $stmt = $conn->prepare("
            SELECT
                qs.id,
                qs.sale_no,
                qs.customer_name,
                qs.mobile,
                qs.address,
                qs.total_amount,
                qs.created_at,
                qs.created_by
            FROM quick_sales qs
            WHERE qs.invoice_token = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $invoiceToken);
    } else {
        $stmt = $conn->prepare("
            SELECT
                qs.id,
                qs.sale_no,
                qs.customer_name,
                qs.mobile,
                qs.address,
                qs.total_amount,
                qs.created_at,
                qs.created_by
            FROM quick_sales qs
            WHERE qs.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $quickSaleId);
    }

    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sale) {
        throw new RuntimeException('Quick Sale not found.');
    }

    $quickSaleId = (int)$sale['id'];

    // ---------------------------------------------------------------
    // Items
    // ---------------------------------------------------------------
    $items = [];

    $stmt = $conn->prepare("
        SELECT
            qsi.id,
            qsi.product_id,
            qsi.product_name,
            qsi.qty,
            qsi.rate,
            qsi.amount
        FROM quick_sale_items qsi
        WHERE qsi.quick_sale_id = ?
        ORDER BY qsi.id ASC
    ");
    $stmt->bind_param('i', $quickSaleId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }

    $stmt->close();

    if (!$items) {
        throw new RuntimeException('Quick Sale items not found.');
    }

    // ---------------------------------------------------------------
    // Payment Summary
    // Supports fully-paid, partial and unpaid Quick Sales.
    // ---------------------------------------------------------------
    $payment = [
        'paid_amount' => 0.0,
        'cash_amount' => 0.0,
        'upi_amount' => 0.0,
        'return_amount' => 0.0,
        'upi_reference' => '',
        'mode_label' => 'Unpaid',
        'status' => 'UNPAID',
        'balance_amount' => (float)$sale['total_amount'],
    ];

    $hasPaymentTable = false;
    try {
        $res = $conn->query("SHOW TABLES LIKE 'quick_sale_payments'");
        $hasPaymentTable = $res && $res->num_rows > 0;
        if ($res) $res->free();
    } catch (Throwable $e) {
        $hasPaymentTable = false;
    }

    if ($hasPaymentTable) {
        $stmt = $conn->prepare("
            SELECT
                COALESCE(SUM(amount), 0) AS paid_amount,
                COALESCE(SUM(
                    CASE
                        WHEN payment_mode = 'cash'
                        THEN amount
                        ELSE 0
                    END
                ), 0) AS cash_amount,
                COALESCE(SUM(
                    CASE
                        WHEN payment_mode = 'upi'
                        THEN amount
                        ELSE 0
                    END
                ), 0) AS upi_amount,
                COALESCE(SUM(return_amount), 0) AS return_amount,
                COALESCE(
                    GROUP_CONCAT(
                        DISTINCT CASE
                            WHEN payment_mode = 'upi'
                             AND COALESCE(reference_no, '') <> ''
                            THEN reference_no
                        END
                        ORDER BY id
                        SEPARATOR ', '
                    ),
                    ''
                ) AS upi_reference,
                SUM(CASE WHEN payment_mode = 'cash' THEN 1 ELSE 0 END)
                    AS cash_count,
                SUM(CASE WHEN payment_mode = 'upi' THEN 1 ELSE 0 END)
                    AS upi_count
            FROM quick_sale_payments
            WHERE quick_sale_id = ?
        ");
        $stmt->bind_param('i', $quickSaleId);
        $stmt->execute();
        $payRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $total = round((float)$sale['total_amount'], 2);
        $paid = round((float)($payRow['paid_amount'] ?? 0), 2);
        $balance = max(0, round($total - $paid, 2));

        $cashCount = (int)($payRow['cash_count'] ?? 0);
        $upiCount = (int)($payRow['upi_count'] ?? 0);

        if ($cashCount > 0 && $upiCount > 0) {
            $modeLabel = 'Cash + UPI';
        } elseif ($cashCount > 0) {
            $modeLabel = 'Cash';
        } elseif ($upiCount > 0) {
            $modeLabel = 'UPI';
        } else {
            $modeLabel = 'Unpaid';
        }

        if ($balance <= 0.009 && $total > 0) {
            $status = 'PAID';
        } elseif ($paid > 0.009) {
            $status = 'PARTIAL';
        } else {
            $status = 'UNPAID';
        }

        $payment = [
            'paid_amount' => $paid,
            'cash_amount' => (float)($payRow['cash_amount'] ?? 0),
            'upi_amount' => (float)($payRow['upi_amount'] ?? 0),
            'return_amount' => (float)($payRow['return_amount'] ?? 0),
            'upi_reference' => trim((string)($payRow['upi_reference'] ?? '')),
            'mode_label' => $modeLabel,
            'status' => $status,
            'balance_amount' => $balance,
        ];
    }

    // ---------------------------------------------------------------
    // Render Dedicated Cash Invoice
    // ---------------------------------------------------------------
    $logoPath = __DIR__ . '/assets/img/subhiksha-quick-sale-logo.png';

    $pdf = new SubhikshaQuickSaleCashInvoicePDF($logoPath);
    $saleNo = (string)$sale['sale_no'];

    $pdf->SetTitle('Cash Invoice - ' . $saleNo);
    $pdf->drawInvoice($sale, $items, $payment);

    $download =
        isset($_GET['download'])
        && (string)$_GET['download'] === '1';

    header(
        'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
    );
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Subhiksha-Document-Type: quick-sale-cash-invoice');

    $safeSaleNo = preg_replace(
        '/[^A-Za-z0-9_-]+/',
        '_',
        $saleNo
    );

    $filename = $safeSaleNo . '_cash_invoice.pdf';

    $pdf->Output(
        $download ? 'D' : 'I',
        $filename
    );
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die(
        'Quick Sale invoice generation failed: '
        . $e->getMessage()
    );
}
