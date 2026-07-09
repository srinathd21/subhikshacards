<?php
/**
 * proforma_bill_pdf.php
 * Preferred black-and-white Proforma Bill / Sales Order PDF.
 * Same endpoint is used for ERP print, public WhatsApp link, and customer download.
 */
require_once __DIR__ . '/includes/db.php';

$publicAccess = isset($_GET['public']) && (string)$_GET['public'] === '1';
if (!$publicAccess) {
    require_once __DIR__ . '/includes/auth.php';

    $allowed = false;
    $roleKey = strtolower(trim((string)($_SESSION['role_key'] ?? $_SESSION['role'] ?? $_SESSION['user_role'] ?? '')));
    if (in_array($roleKey, ['admin', 'super_admin', 'superadmin'], true)) {
        $allowed = true;
    }

    if (!$allowed) {
        foreach (['can_view', 'can_create', 'can_update', 'can_edit'] as $fn) {
            if (!function_exists($fn)) continue;
            try {
                if ((bool)$fn($conn, 'proforma_bills.php')) {
                    $allowed = true;
                    break;
                }
            } catch (ArgumentCountError $e) {
                try {
                    if ((bool)$fn('proforma_bills.php')) {
                        $allowed = true;
                        break;
                    }
                } catch (Throwable $inner) {}
            } catch (Throwable $e) {}
        }
    }

    if (!$allowed && function_exists('require_permission')) {
        require_permission($conn, 'can_view', 'proforma_bills.php');
        $allowed = true;
    }

    if (!$allowed) {
        http_response_code(403);
        die('Access denied.');
    }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Invalid proforma bill.');
}

function pbf_table_exists(mysqli $conn, string $table): bool
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

function pbf_pdf_text($value): string
{
    $text = (string)$value;
    $text = str_replace(['₹', '–', '—', '“', '”', '‘', '’'], ['Rs.', '-', '-', '"', '"', "'", "'"], $text);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted !== false) return $converted;
    }
    return $text;
}

function pbf_load_fpdf(): void
{
    if (class_exists('FPDF', false) || class_exists('FPDF')) return;

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
            if (class_exists('FPDF', false) || class_exists('FPDF')) return;
        }
    }

    throw new RuntimeException('FPDF library not found. Place fpdf.php in erp/assets/libs/fpdf.php. Checked: ' . implode(', ', $paths));
}

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

    if (!$bill) return null;

    $items = [];
    $stmt = $conn->prepare("\n        SELECT\n            pbi.*,\n            p.product_name AS master_product_name,\n            pt.printing_name,\n            pst.sub_type_name\n        FROM proforma_bill_items pbi\n        LEFT JOIN products p ON p.id = pbi.product_id\n        LEFT JOIN printing_types pt ON pt.id = pbi.printing_type_id\n        LEFT JOIN printing_sub_types pst ON pst.id = pbi.printing_sub_type_id\n        WHERE pbi.proforma_bill_id = ?\n        ORDER BY pbi.sort_order ASC, pbi.id ASC\n    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $items[] = $row;
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
    $gstPercent = round((float)($bill['gst_percent'] ?? 18), 2);
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
    function Footer()
    {
        $this->SetY(-10);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 5, pbf_pdf_text('Computer generated proforma invoice'), 0, 0, 'C');
    }

    function NbLines($w, $txt): int
    {
        $cw = $this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', pbf_pdf_text($txt));
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
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
            if ($c == ' ') $sep = $i;
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) $i++;
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
        if ($this->GetY() + $h > $this->PageBreakTrigger) $this->AddPage($this->CurOrientation);
    }

    function Row(array $data, array $widths, array $aligns, float $lineHeight = 4.5, float $minHeight = 0): void
    {
        $nb = 0;
        foreach ($data as $i => $txt) $nb = max($nb, $this->NbLines($widths[$i], (string)$txt));
        $h = max($minHeight, $lineHeight * $nb + 3);
        $this->CheckPageBreak($h);
        for ($i = 0; $i < count($data); $i++) {
            $w = $widths[$i];
            $a = $aligns[$i] ?? 'L';
            $x = $this->GetX();
            $y = $this->GetY();
            $this->Rect($x, $y, $w, $h);
            $this->SetXY($x, $y + 1.5);
            $this->MultiCell($w, $lineHeight, pbf_pdf_text((string)$data[$i]), 0, $a);
            $this->SetXY($x + $w, $y);
        }
        $this->Ln($h);
    }

    function LabelValue($label, $value, $labelW, $valueW, $lineH = 5): void
    {
        $x = $this->GetX();
        $y = $this->GetY();
        $this->SetFont('Arial', 'B', 8);
        $this->Cell($labelW, $lineH, pbf_pdf_text((string)$label), 0, 0, 'L');
        $this->SetFont('Arial', '', 8);
        $this->MultiCell($valueW, $lineH, pbf_pdf_text((string)$value), 0, 'L');
        $this->SetXY($x, max($this->GetY(), $y + $lineH));
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
    $gstLabel = $summary['gst_percent'] > 0 ? number_format($summary['gst_percent'], 2) . '%' : '-';
    $brideGroom = trim((string)($bill['bride_name'] ?? '') . ' / ' . (string)($bill['groom_name'] ?? ''), ' /');

    $pdf = new SubhikshaProformaPDF('P', 'mm', 'A4');
    $pdf->SetTitle('Proforma Bill - ' . (string)($bill['proforma_no'] ?? ''));
    $pdf->SetAuthor('Subhiksha Cards');
    $pdf->SetMargins(12, 10, 12);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);

    // Outer border and top header exactly in the preferred classic style.
    $pdf->Rect(12, 8, 186, 272);
    $pdf->SetXY(16, 12);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(80, 4, pbf_pdf_text('Contact: 72006 02020, 72007 02020'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(98, 4, pbf_pdf_text('GSTIN: 33AMRPA4225G1ZD'), 0, 1, 'R');

    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 9, pbf_pdf_text('SUBHIKSHA CARDS'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(0, 5, pbf_pdf_text('A unit of Mani Paper Card Company'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(0, 5, pbf_pdf_text('Dharmapuri'), 0, 1, 'C');

    $pdf->SetY(38);
    $pdf->Cell(186, 0, '', 'T', 1);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(186, 10, pbf_pdf_text('PROFORMA BILL / SALES ORDER'), 'B', 1, 'C');

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(22, 8, pbf_pdf_text('No:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(78, 8, pbf_pdf_text(pbf_clean($bill['proforma_no'] ?? '-')), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(20, 8, pbf_pdf_text('Date:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(66, 8, pbf_pdf_text(pbf_date($bill['created_at'] ?? date('Y-m-d'))), 0, 1, 'L');

    // Customer and order boxes.
    $leftX = 16;
    $rightX = 108;
    $boxY = $pdf->GetY() + 2;
    $boxW = 82;
    $boxH = 39;
    $pdf->SetFillColor(248, 248, 248);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Rect($leftX, $boxY, $boxW, $boxH);
    $pdf->Rect($rightX, $boxY, $boxW, $boxH);
    $pdf->SetXY($leftX, $boxY);
    $pdf->Cell($boxW, 8, pbf_pdf_text('CUSTOMER DETAILS'), 1, 0, 'C', true);
    $pdf->SetXY($rightX, $boxY);
    $pdf->Cell($boxW, 8, pbf_pdf_text('ORDER DETAILS'), 1, 1, 'C', true);

    $customerAddress = pbf_clean(($bill['billing_address'] ?? '') ?: ($bill['customer_master_address'] ?? '-'));
    $customerGst = pbf_clean(($bill['gst_number'] ?? '') ?: ($bill['customer_master_gst'] ?? '-'));

    $pdf->SetXY($leftX + 4, $boxY + 12);
    $pdf->LabelValue('Name', pbf_clean($bill['billing_name'] ?? $bill['customer_name'] ?? '-'), 22, 52);
    $pdf->LabelValue('Mobile', pbf_clean($bill['billing_mobile'] ?? $bill['mobile'] ?? '-'), 22, 52);
    $pdf->LabelValue('GST', $customerGst, 22, 52);
    $pdf->LabelValue('Address', $customerAddress, 22, 52, 4.3);

    $pdf->SetXY($rightX + 4, $boxY + 12);
    $pdf->LabelValue('Function', pbf_clean($bill['function_name'] ?? '-'), 27, 47);
    $pdf->LabelValue('Bride/Groom', pbf_clean($brideGroom ?: '-'), 27, 47);
    $pdf->LabelValue('Function Date', pbf_date($bill['function_date'] ?? ''), 27, 47);
    $pdf->LabelValue('Delivery Date', pbf_date($bill['delivery_date'] ?? ''), 27, 47);

    $pdf->SetY($boxY + $boxH + 6);

    // Items table.
    $widths = [8, 68, 15, 18, 23, 20, 34]; // total 186
    $headers = ['#', 'Description', 'Qty', 'Rate', 'Amount', 'GST', 'Printing'];
    $pdf->SetX(12);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetFillColor(245, 245, 245);
    foreach ($headers as $i => $head) {
        $pdf->Cell($widths[$i], 7, pbf_pdf_text($head), 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 7);
    if (!$items) {
        $pdf->Cell(186, 10, pbf_pdf_text('No items found.'), 1, 1, 'C');
    }

    $itemMinHeight = count($items) <= 1 ? 58 : 22;
    foreach ($items as $i => $item) {
        $printing = trim((string)($item['printing_name'] ?? ''));
        if (!empty($item['sub_type_name'])) $printing .= ' / ' . (string)$item['sub_type_name'];

        $extraDetails = [];
        if (!empty($item['size_text'])) $extraDetails[] = 'Size: ' . $item['size_text'];
        if (!empty($item['gsm_thickness'])) $extraDetails[] = 'GSM: ' . $item['gsm_thickness'];
        if (!empty($item['printing_side'])) $extraDetails[] = 'Side: ' . $item['printing_side'];
        if (!empty($item['screening_type'])) $extraDetails[] = 'Scoring: ' . $item['screening_type'];
        if ((int)($item['lamination_required'] ?? 0) === 1 && !empty($item['lamination_type']) && strtolower((string)$item['lamination_type']) !== 'none') {
            $extraDetails[] = 'Lamination: ' . $item['lamination_type'];
        }
        if (!empty($item['price_slab_text'])) $extraDetails[] = 'Slab: ' . $item['price_slab_text'];

        $description = pbf_clean($item['item_name'] ?? $item['master_product_name'] ?? '-');
        if (!empty($item['description'])) $description .= "\n" . pbf_clean($item['description']);
        if ($extraDetails) $description .= "\n" . implode(' | ', $extraDetails);

        $qty = (float)($item['qty'] ?? 0);
        $rate = (float)($item['rate'] ?? 0);
        $amount = round((float)($item['amount'] ?? ($qty * $rate)), 2);

        $pdf->SetX(12);
        $pdf->Row([
            (string)($i + 1),
            $description,
            number_format($qty, 0),
            pbf_num($rate),
            pbf_num($amount),
            $gstLabel,
            pbf_clean($printing ?: '-'),
        ], $widths, ['C', 'L', 'C', 'R', 'R', 'C', 'L'], 4.2, $itemMinHeight);
    }

    // Keep the amount block in the same visual location for short invoices.
    if ($pdf->GetY() < 166) $pdf->SetY(166);
    $sectionY = $pdf->GetY() + 4;

    // Remarks / terms on left.
    $pdf->SetXY(16, $sectionY);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(88, 5, pbf_pdf_text('Remarks:'), 0, 1, 'L');
    $pdf->SetX(16);
    $pdf->SetFont('Arial', '', 7);
    $pdf->MultiCell(88, 5, pbf_pdf_text(pbf_clean($bill['remarks'] ?? '-')), 0, 'L');

    // Amount summary on right.
    $summaryX = 112;
    $summaryLabelW = 48;
    $summaryAmountW = 30;
    $pdf->SetXY($summaryX, $sectionY);
    $summaryRows = [
        ['Sub Total', $summary['sub_total'], false],
        ['Plate / Additional', $summary['extra'], false],
        ['Package Charge', $summary['packing'], false],
        ['Printing Charge', $summary['printing'], false],
        ['Gross Total', $summary['gross_before_discount'], false],
        ['Discount', -1 * $summary['discount'], false],
        ['Taxable Value', $summary['taxable'], true],
        ['GST Amount @ ' . number_format($summary['gst_percent'], 2) . '%', $summary['gst_amount'], false],
        ['GST Inclusive Amount', $summary['final'], true],
        ['Advance Paid', $summary['advance'], false],
        ['Balance Amount', $summary['balance'], true],
    ];

    foreach ($summaryRows as $row) {
        [$label, $value, $bold] = $row;
        $pdf->SetX($summaryX);
        $pdf->SetFont('Arial', $bold ? 'B' : '', 7.5);
        $pdf->Cell($summaryLabelW, 6, pbf_pdf_text($label), 1, 0, 'R');
        $amountText = ((float)$value < 0 ? '-Rs. ' : 'Rs. ') . number_format(abs((float)$value), 2);
        $pdf->Cell($summaryAmountW, 6, pbf_pdf_text($amountText), 1, 1, 'R');
    }

    // Terms and signature.
    $termsY = max($pdf->GetY() + 8, 242);
    if ($termsY > 250) {
        $pdf->AddPage();
        $pdf->Rect(12, 8, 186, 272);
        $termsY = 20;
    }
    $pdf->SetXY(16, $termsY);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(90, 5, pbf_pdf_text('Terms & Conditions:'), 0, 1, 'L');
    $pdf->SetX(16);
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->MultiCell(90, 4, pbf_pdf_text('Order once booked cannot be cancelled by the buyer at any circumstance. GST inclusive: taxable value and GST amount are shown separately inside the final amount.'), 0, 'L');

    $signY = max($pdf->GetY() + 8, 260);
    if ($signY < 268) {
        $pdf->SetXY(136, $signY);
        $pdf->Cell(48, 0, '', 'T', 1, 'C');
        $pdf->SetXY(136, $signY + 4);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(48, 5, pbf_pdf_text('For Subhiksha Cards'), 0, 1, 'C');
    }

    $filename = 'Proforma_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($bill['proforma_no'] ?? $id)) . '.pdf';
    $outputMode = (isset($_GET['download']) && (string)$_GET['download'] === '1') ? 'D' : 'I';
    $pdf->Output($outputMode, $filename);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('PDF generation failed: ' . $e->getMessage());
}
