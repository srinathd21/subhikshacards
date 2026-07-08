<?php
/**
 * proforma_bill_pdf.php
 * GST inclusive Proforma Bill / Sales Order PDF.
 * HTML fallback has been removed intentionally: this endpoint outputs PDF only.
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

function pbf_money($value): string
{
    return 'Rs. ' . number_format((float)$value, 2);
}

function pbf_num($value): string
{
    return number_format((float)$value, 2);
}

function pbf_date($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function pbf_clean($value): string
{
    $value = trim((string)$value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    return $value === '' ? '-' : $value;
}

function pbf_load_fpdf(): void
{
    if (class_exists('FPDF', false) || class_exists('FPDF')) {
        return;
    }

    $paths = [
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/assets/libs/fpdf.php',
        __DIR__ . '/assets/libs/fpdf/fpdf.php',
        __DIR__ . '/assets/lib/fpdf.php',
        __DIR__ . '/assets/lib/fpdf/fpdf.php',
        __DIR__ . '/fpdf.php',
        __DIR__ . '/fpdf/fpdf.php',
        __DIR__ . '/libs/fpdf.php',
        __DIR__ . '/libs/fpdf/fpdf.php',
        __DIR__ . '/includes/fpdf.php',
        __DIR__ . '/includes/fpdf/fpdf.php',
        __DIR__ . '/admin/libs/fpdf.php',
        __DIR__ . '/admin/libs/fpdf/fpdf.php',
        dirname(__DIR__) . '/libs/fpdf.php',
        dirname(__DIR__) . '/libs/fpdf/fpdf.php',
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            if (class_exists('FPDF', false) || class_exists('FPDF')) {
                return;
            }
        }
    }

    throw new RuntimeException(
        'FPDF library not found. Place fpdf.php in erp/assets/libs/fpdf.php, erp/libs/fpdf.php, or erp/includes/fpdf.php. ' .
        'Checked: ' . implode(', ', $paths)
    );
}

/*
 * IMPORTANT: load FPDF before declaring the class below.
 * Otherwise PHP throws: Class "FPDF" not found at class SubhikshaProformaPDF extends FPDF.
 */
try {
    pbf_load_fpdf();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to generate PDF. ' . $e->getMessage();
    exit;
}

function pbf_load_data(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("\n        SELECT\n            pb.*,\n            ft.function_name,\n            ps.status_name,\n            c.address AS customer_master_address,\n            c.gst_number AS customer_master_gst\n        FROM proforma_bills pb\n        LEFT JOIN function_types ft ON ft.id = pb.function_type_id\n        LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id\n        LEFT JOIN customers c ON c.id = pb.customer_id\n        WHERE pb.id = ?\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bill) {
        return null;
    }

    $items = [];
    $stmt = $conn->prepare("\n        SELECT\n            pbi.*,\n            p.product_name AS master_product_name,\n            pt.printing_name,\n            pst.sub_type_name\n        FROM proforma_bill_items pbi\n        LEFT JOIN products p ON p.id = pbi.product_id\n        LEFT JOIN printing_types pt ON pt.id = pbi.printing_type_id\n        LEFT JOIN printing_sub_types pst ON pst.id = pbi.printing_sub_type_id\n        WHERE pbi.proforma_bill_id = ?\n        ORDER BY pbi.sort_order ASC, pbi.id ASC\n    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    return ['bill' => $bill, 'items' => $items];
}

function pbf_amount_summary(array $bill): array
{
    $subTotal = round((float)($bill['sub_total'] ?? 0), 2);
    $discount = round((float)($bill['discount_amount'] ?? 0), 2);
    $extra = round((float)($bill['card_extra_charge'] ?? 0), 2);
    $packing = round((float)($bill['packing_charge'] ?? 0), 2);
    $printing = round((float)($bill['printing_charge'] ?? 0), 2);
    $gstPercent = round((float)($bill['gst_percent'] ?? 0), 2);
    $storedFinal = round((float)($bill['final_amount'] ?? 0), 2);
    $grossBeforeDiscount = round($subTotal + $extra + $packing + $printing, 2);
    $calculatedFinal = round(max(0, $grossBeforeDiscount - $discount), 2);
    $final = $storedFinal > 0 ? $storedFinal : $calculatedFinal;

    $taxable = round((float)($bill['taxable_value'] ?? 0), 2);
    $gstAmount = round((float)($bill['gst_amount'] ?? 0), 2);

    if ($gstPercent > 0 && ($taxable <= 0 || abs(($taxable + $gstAmount) - $final) > 0.05)) {
        $taxable = round($final / (1 + ($gstPercent / 100)), 2);
        $gstAmount = round(max(0, $final - $taxable), 2);
    } elseif ($taxable <= 0) {
        $taxable = $final;
        $gstAmount = 0.00;
    } elseif ($gstAmount <= 0 && $final >= $taxable) {
        $gstAmount = round($final - $taxable, 2);
    }

    return [
        'sub_total' => $subTotal,
        'discount' => $discount,
        'extra' => $extra,
        'packing' => $packing,
        'printing' => $printing,
        'gross_before_discount' => $grossBeforeDiscount,
        'final' => $final,
        'gst_percent' => $gstPercent,
        'taxable' => $taxable,
        'gst_amount' => $gstAmount,
        'advance' => round((float)($bill['advance_amount'] ?? 0), 2),
        'balance' => round((float)($bill['balance_amount'] ?? 0), 2),
    ];
}

class SubhikshaProformaPDF extends FPDF
{
    public array $company = [];

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 6, 'Page ' . $this->PageNo() . ' | Computer generated proforma invoice', 0, 0, 'C');
    }

    function NbLines($w, $txt): int
    {
        $cw = $this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
            }
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    function CheckPageBreak($h): void
    {
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
    }

    function Row(array $data, array $widths, array $aligns, float $lineHeight = 5): void
    {
        $nb = 0;
        foreach ($data as $i => $txt) {
            $nb = max($nb, $this->NbLines($widths[$i], (string)$txt));
        }
        $h = $lineHeight * $nb + 2;
        $this->CheckPageBreak($h);
        for ($i = 0; $i < count($data); $i++) {
            $w = $widths[$i];
            $a = $aligns[$i] ?? 'L';
            $x = $this->GetX();
            $y = $this->GetY();
            $this->Rect($x, $y, $w, $h);
            $this->MultiCell($w, $lineHeight, (string)$data[$i], 0, $a);
            $this->SetXY($x + $w, $y);
        }
        $this->Ln($h);
    }

    function LabelValue($label, $value, $w = 95): void
    {
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(27, 5, (string)$label, 0, 0, 'L');
        $this->SetFont('Arial', '', 8);
        $this->MultiCell($w - 27, 5, (string)$value, 0, 'L');
    }
}

try {
    $data = pbf_load_data($conn, $id);
    if (!$data) {
        http_response_code(404);
        die('Proforma bill not found.');
    }

    $bill = $data['bill'];
    $items = $data['items'];
    $summary = pbf_amount_summary($bill);
    $gstLabel = $summary['gst_percent'] > 0 ? number_format($summary['gst_percent'], 2) . '% GST Incl.' : 'GST Incl.';
    $brideGroom = trim((string)($bill['bride_name'] ?? '') . ' / ' . (string)($bill['groom_name'] ?? ''), ' /');

    $pdf = new SubhikshaProformaPDF('P', 'mm', 'A4');
    $pdf->SetTitle('Proforma Bill - ' . (string)($bill['proforma_no'] ?? ''));
    $pdf->SetAuthor('Subhiksha Cards');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $pdf->SetDrawColor(40, 40, 40);
    $pdf->SetLineWidth(0.2);

    // Header
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 8, 'SUBHIKSHA CARDS', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 5, 'A unit of Mani Paper Card Company', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(0, 5, 'Dharmapuri | Contact: 72006 02020, 72007 02020 | GSTIN: 33AMRPA4225G1ZD', 0, 1, 'C');
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(238, 242, 247);
    $pdf->Cell(0, 8, 'PROFORMA BILL / SALES ORDER - GST INCLUSIVE', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(95, 7, 'Proforma No: ' . pbf_clean($bill['proforma_no'] ?? '-'), 1, 0, 'L');
    $pdf->Cell(95, 7, 'Date: ' . pbf_date($bill['created_at'] ?? date('Y-m-d')), 1, 1, 'R');

    // Customer and order boxes
    $y = $pdf->GetY();
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(95, 7, 'CUSTOMER / BILLING DETAILS', 1, 0, 'C', true);
    $pdf->Cell(95, 7, 'ORDER DETAILS', 1, 1, 'C', true);
    $boxY = $pdf->GetY();
    $leftText =
        'Name: ' . pbf_clean($bill['billing_name'] ?? $bill['customer_name'] ?? '-') . "\n" .
        'Mobile: ' . pbf_clean($bill['billing_mobile'] ?? $bill['mobile'] ?? '-') . "\n" .
        'GST No: ' . pbf_clean(($bill['gst_number'] ?? '') ?: ($bill['customer_master_gst'] ?? '-')) . "\n" .
        'Address: ' . pbf_clean(($bill['billing_address'] ?? '') ?: ($bill['customer_master_address'] ?? '-'));
    $rightText =
        'Function: ' . pbf_clean($bill['function_name'] ?? '-') . "\n" .
        'Bride/Groom: ' . pbf_clean($brideGroom ?: '-') . "\n" .
        'Function Date: ' . pbf_date($bill['function_date'] ?? '') . "\n" .
        'Delivery Date: ' . pbf_date($bill['delivery_date'] ?? '');
    $hLeft = ($pdf->NbLines(93, $leftText) * 5) + 4;
    $hRight = ($pdf->NbLines(93, $rightText) * 5) + 4;
    $boxH = max(28, $hLeft, $hRight);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Rect(10, $boxY, 95, $boxH);
    $pdf->Rect(105, $boxY, 95, $boxH);
    $pdf->SetXY(12, $boxY + 2);
    $pdf->MultiCell(91, 5, $leftText, 0, 'L');
    $pdf->SetXY(107, $boxY + 2);
    $pdf->MultiCell(91, 5, $rightText, 0, 'L');
    $pdf->SetY($boxY + $boxH + 5);

    // Items table
    $pdf->SetFont('Arial', 'B', 8);
    $widths = [8, 64, 16, 20, 25, 25, 32];
    $headers = ['#', 'Description', 'Qty', 'Rate', 'Amount', 'GST', 'Line Total'];
    foreach ($headers as $i => $head) {
        $pdf->Cell($widths[$i], 7, $head, 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 8);
    if (!$items) {
        $pdf->Cell(190, 8, 'No items found.', 1, 1, 'C');
    }

    foreach ($items as $i => $item) {
        $printing = trim((string)($item['printing_name'] ?? ''));
        if (!empty($item['sub_type_name'])) {
            $printing .= ' / ' . (string)$item['sub_type_name'];
        }
        $extraDetails = [];
        if (!empty($item['size_text'])) $extraDetails[] = 'Size: ' . $item['size_text'];
        if (!empty($item['gsm_thickness'])) $extraDetails[] = 'GSM: ' . $item['gsm_thickness'];
        if (!empty($item['printing_side'])) $extraDetails[] = 'Side: ' . $item['printing_side'];
        if (!empty($item['price_slab_text'])) $extraDetails[] = 'Slab: ' . $item['price_slab_text'];
        if ($printing !== '') $extraDetails[] = 'Printing: ' . $printing;

        $description = pbf_clean($item['item_name'] ?? '-');
        if (!empty($item['description'])) $description .= "\n" . pbf_clean($item['description']);
        if ($extraDetails) $description .= "\n" . implode(' | ', $extraDetails);

        $qty = (float)($item['qty'] ?? 0);
        $rate = (float)($item['rate'] ?? 0);
        $amount = round((float)($item['amount'] ?? ($qty * $rate)), 2);

        $pdf->Row([
            (string)($i + 1),
            $description,
            number_format($qty, 0),
            pbf_num($rate),
            pbf_num($amount),
            $gstLabel,
            pbf_num($amount),
        ], $widths, ['C', 'L', 'C', 'R', 'R', 'C', 'R'], 4.5);
    }

    // Summary
    $pdf->Ln(3);
    $y = $pdf->GetY();
    $leftW = 108;
    $summaryX = 118;
    $summaryW1 = 48;
    $summaryW2 = 34;

    $pdf->SetXY(10, $y);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($leftW, 7, 'Remarks / Terms', 1, 1, 'L', true);
    $pdf->SetFont('Arial', '', 8);
    $terms = 'Remarks: ' . pbf_clean($bill['remarks'] ?? '-') . "\n\n" .
        'Terms: Order once booked cannot be cancelled by the buyer at any circumstance.' . "\n" .
        'GST Note: Amount is GST inclusive. Taxable value and GST amount are split from the final amount after discount.';
    $startY = $pdf->GetY();
    $pdf->MultiCell($leftW, 5, $terms, 1, 'L');

    $pdf->SetXY($summaryX, $y);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($summaryW1 + $summaryW2, 7, 'AMOUNT SUMMARY', 1, 1, 'C', true);

    $summaryRows = [
        ['Sub Total', $summary['sub_total']],
    ];
    if ($summary['extra'] > 0) $summaryRows[] = ['Plate / Additional', $summary['extra']];
    if ($summary['printing'] > 0) $summaryRows[] = ['Printing Charge', $summary['printing']];
    if ($summary['packing'] > 0) $summaryRows[] = ['Package Charge', $summary['packing']];
    $summaryRows[] = ['Gross Total', $summary['gross_before_discount']];
    if ($summary['discount'] > 0) $summaryRows[] = ['Discount (-)', -1 * $summary['discount']];
    $summaryRows[] = ['Taxable Value', $summary['taxable']];
    $summaryRows[] = ['GST Amount @ ' . number_format($summary['gst_percent'], 2) . '%', $summary['gst_amount']];
    $summaryRows[] = ['Final Amount', $summary['final']];
    $summaryRows[] = ['Advance Paid', $summary['advance']];
    $summaryRows[] = ['Balance Amount', $summary['balance']];

    $pdf->SetFont('Arial', '', 8);
    foreach ($summaryRows as $row) {
        $label = $row[0];
        $value = (float)$row[1];
        $isFinal = in_array($label, ['Final Amount', 'Balance Amount'], true);
        $pdf->SetX($summaryX);
        $pdf->SetFont('Arial', $isFinal ? 'B' : '', 8);
        $pdf->Cell($summaryW1, 6, $label, 1, 0, 'L');
        $amountText = ($value < 0 ? '-Rs. ' : 'Rs. ') . number_format(abs($value), 2);
        $pdf->Cell($summaryW2, 6, $amountText, 1, 1, 'R');
    }

    $afterSummaryY = $pdf->GetY();
    $afterTermsY = $startY + (max(1, $pdf->NbLines($leftW, $terms)) * 5) + 1;
    $pdf->SetY(max($afterSummaryY, $afterTermsY) + 12);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(95, 8, 'Customer Signature', 0, 0, 'C');
    $pdf->Cell(95, 8, 'For Subhiksha Cards', 0, 1, 'C');

    $filename = 'Proforma_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($bill['proforma_no'] ?? $id)) . '.pdf';
    $pdf->Output('I', $filename);
} catch (Throwable $e) {
    http_response_code(500);
    die('PDF generation failed: ' . $e->getMessage());
}
