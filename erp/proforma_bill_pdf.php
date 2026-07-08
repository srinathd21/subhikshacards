<?php
/**
 * proforma_bill_pdf.php
 * Opens the formal FPDF proforma invoice.
 * Fallback: printable HTML bill if FPDF is missing or PDF generation fails.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (function_exists('require_permission')) {
    require_permission($conn, 'can_view', 'proforma_bills.php');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Invalid proforma bill.');
}

function pbf_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pbf_money($value): string
{
    return 'Rs. ' . number_format((float)$value, 2);
}

function pbf_date($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function pbf_load_data(mysqli $conn, int $id): ?array
{
    try {
        $stmt = $conn->prepare("\n            SELECT pb.*, ft.function_name, ps.status_name, c.address AS customer_master_address, c.gst_number AS customer_master_gst\n            FROM proforma_bills pb\n            LEFT JOIN function_types ft ON ft.id = pb.function_type_id\n            LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id\n            LEFT JOIN customers c ON c.id = pb.customer_id\n            WHERE pb.id = ?\n            LIMIT 1\n        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $bill = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$bill) return null;

        $items = [];
        $stmt = $conn->prepare("\n            SELECT pbi.*, pt.printing_name, pst.sub_type_name\n            FROM proforma_bill_items pbi\n            LEFT JOIN printing_types pt ON pt.id = pbi.printing_type_id\n            LEFT JOIN printing_sub_types pst ON pst.id = pbi.printing_sub_type_id\n            WHERE pbi.proforma_bill_id = ?\n            ORDER BY pbi.sort_order ASC, pbi.id ASC\n        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $items[] = $row;
        $stmt->close();

        return ['bill' => $bill, 'items' => $items];
    } catch (Throwable $e) {
        return null;
    }
}

function pbf_output_html_fallback(mysqli $conn, int $id, string $reason = ''): void
{
    $data = pbf_load_data($conn, $id);
    if (!$data) {
        http_response_code(500);
        die('Unable to load proforma bill for fallback print.');
    }

    $bill = $data['bill'];
    $items = $data['items'];
    $brideGroom = trim((string)($bill['bride_name'] ?? '') . ' / ' . (string)($bill['groom_name'] ?? ''), ' /');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Proforma Bill - <?= pbf_e($bill['proforma_no'] ?? '') ?></title>
<style>
    body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:20px;color:#111827}.wrap{max-width:900px;margin:0 auto;background:#fff;border:1px solid #111;padding:24px}.top-actions{max-width:900px;margin:0 auto 12px;display:flex;gap:10px;justify-content:flex-end}.btn{border:1px solid #111;background:#fff;border-radius:999px;padding:9px 16px;font-weight:700;text-decoration:none;color:#111}.btn-primary{background:#111;color:#fff}.alert{max-width:900px;margin:0 auto 12px;border:1px solid #92400e;background:#fef3c7;color:#78350f;padding:10px 14px;border-radius:10px;font-weight:700}.head{text-align:center;border-bottom:1px solid #111;padding-bottom:12px;margin-bottom:16px}.head h1{margin:6px 0 2px;font-size:30px}.meta,.boxgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.box{border:1px solid #111;padding:12px;min-height:105px}.box h3{margin:0 0 8px;font-size:14px;text-align:center}.row{display:flex;margin:6px 0;font-size:13px}.row b{width:125px}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{border:1px solid #111;padding:8px;font-size:13px;vertical-align:top}th{text-align:center}.right{text-align:right}.center{text-align:center}.bottom{display:grid;grid-template-columns:1.2fr .8fr;gap:16px;margin-top:16px}.summary td:first-child{font-weight:700}.summary td{padding:7px}.terms{margin-top:18px;font-size:12px}.sign{text-align:center;margin-top:35px;font-weight:700}@media print{body{background:#fff;padding:0}.top-actions,.alert{display:none}.wrap{border:1px solid #111;margin:0;max-width:none;box-shadow:none}}
</style>
</head>
<body>
<?php if ($reason !== ''): ?><div class="alert">FPDF fallback mode opened: <?= pbf_e($reason) ?></div><?php endif; ?>
<div class="top-actions"><button class="btn btn-primary" onclick="window.print()">Print</button><a class="btn" href="proforma_bill_view.php?id=<?= (int)$id ?>">Back</a></div>
<div class="wrap">
    <div class="head">
        <div style="display:flex;justify-content:space-between;font-size:12px"><span>Contact: 72006 02020, 72007 02020</span><b>GSTIN: 33AMRPA4225G1ZD</b></div>
        <h1>SUBHIKSHA CARDS</h1>
        <div><b>A unit of Mani Paper Card Company</b></div>
        <div>Dharmapuri</div>
        <h2 style="margin:14px 0 0;font-size:18px">PROFORMA BILL / SALES ORDER</h2>
    </div>
    <div class="meta">
        <div><b>No:</b> <?= pbf_e($bill['proforma_no'] ?? '-') ?></div>
        <div class="right"><b>Date:</b> <?= pbf_e(pbf_date($bill['created_at'] ?? date('Y-m-d'))) ?></div>
    </div>
    <div class="boxgrid" style="margin-top:14px">
        <div class="box"><h3>CUSTOMER DETAILS</h3><div class="row"><b>Name</b><span><?= pbf_e($bill['customer_name'] ?? '-') ?></span></div><div class="row"><b>Mobile</b><span><?= pbf_e($bill['mobile'] ?? '-') ?></span></div><div class="row"><b>GST</b><span><?= pbf_e(($bill['gst_number'] ?? '') ?: ($bill['customer_master_gst'] ?? '-')) ?></span></div><div class="row"><b>Address</b><span><?= pbf_e(($bill['billing_address'] ?? '') ?: ($bill['customer_master_address'] ?? '-')) ?></span></div></div>
        <div class="box"><h3>ORDER DETAILS</h3><div class="row"><b>Function</b><span><?= pbf_e($bill['function_name'] ?? '-') ?></span></div><div class="row"><b>Bride/Groom</b><span><?= pbf_e($brideGroom ?: '-') ?></span></div><div class="row"><b>Function Date</b><span><?= pbf_e(pbf_date($bill['function_date'] ?? '')) ?></span></div><div class="row"><b>Delivery Date</b><span><?= pbf_e(pbf_date($bill['delivery_date'] ?? '')) ?></span></div></div>
    </div>
    <table><thead><tr><th>#</th><th>Description</th><th>Qty</th><th>Rate</th><th>Amount</th><th>Printing</th></tr></thead><tbody>
        <?php if (!$items): ?><tr><td colspan="6" class="center">No items found.</td></tr><?php endif; ?>
        <?php foreach ($items as $i => $item): $printing = trim((string)($item['printing_name'] ?? '')); if (!empty($item['sub_type_name'])) $printing .= ' / ' . $item['sub_type_name']; ?>
        <tr><td class="center"><?= $i + 1 ?></td><td><b><?= pbf_e($item['item_name'] ?? '-') ?></b><br><?= pbf_e($item['description'] ?? '') ?></td><td class="center"><?= pbf_e(number_format((float)($item['qty'] ?? 0), 0)) ?></td><td class="right"><?= pbf_e(number_format((float)($item['rate'] ?? 0), 2)) ?></td><td class="right"><?= pbf_e(number_format((float)($item['amount'] ?? 0), 2)) ?></td><td><?= pbf_e($printing ?: '-') ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <div class="bottom">
        <div><b>Remarks:</b><br><?= nl2br(pbf_e(($bill['remarks'] ?? '') ?: '-')) ?><div class="terms"><b>Terms & Conditions:</b><br>Order once booked cannot be cancelled by the buyer at any circumstance.</div></div>
        <table class="summary"><tbody><tr><td>Sub Total</td><td class="right"><?= pbf_e(pbf_money($bill['sub_total'] ?? 0)) ?></td></tr><tr><td>Discount</td><td class="right"><?= pbf_e(pbf_money($bill['discount_amount'] ?? 0)) ?></td></tr><tr><td>Extra Charge</td><td class="right"><?= pbf_e(pbf_money($bill['card_extra_charge'] ?? 0)) ?></td></tr><tr><td>Final Amount</td><td class="right"><b><?= pbf_e(pbf_money($bill['final_amount'] ?? 0)) ?></b></td></tr><tr><td>Advance Paid</td><td class="right"><?= pbf_e(pbf_money($bill['advance_amount'] ?? 0)) ?></td></tr><tr><td>Balance Amount</td><td class="right"><b><?= pbf_e(pbf_money($bill['balance_amount'] ?? 0)) ?></b></td></tr></tbody></table>
    </div>
    <div class="sign">For Subhiksha Cards</div>
</div>
</body>
</html>
    <?php
}

if (isset($_GET['fallback']) && $_GET['fallback'] === 'html') {
    pbf_output_html_fallback($conn, $id, 'Manual fallback requested.');
    exit;
}

try {
    require_once __DIR__ . '/includes/proforma-bill-pdf.php';
    sbp_output_proforma_pdf_inline($conn, $id);
} catch (Throwable $e) {
    pbf_output_html_fallback($conn, $id, $e->getMessage());
}
