<?php
/**
 * proforma_bill_pdf.php
 * Subhiksha Cards – dynamic wedding-style Proforma Bill PDF.
 *
 * Required background image:
 *   assets/images/subhiksha_wedding_invoice_bg.png
 *
 * URL examples:
 *   proforma_bill_pdf.php?id=1
 *   proforma_bill_pdf.php?id=1&public=1
 *   proforma_bill_pdf.php?id=1&public=1&download=1
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
                } catch (Throwable $inner) {
                }
            } catch (Throwable $e) {
            }
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

function pbf_money_value($value): string
{
    return number_format((float)$value, 2, '.', ',');
}

function pbf_qty($value): string
{
    $n = (float)$value;
    return abs($n - round($n)) < 0.00001
        ? number_format($n, 0, '.', ',')
        : number_format($n, 2, '.', ',');
}

function pbf_date($value): string
{
    return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
}

function pbf_clean($value, string $default = '-'): string
{
    $value = trim((string)$value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    return $value === '' ? $default : $value;
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
        dirname(__DIR__) . '/libs/fpdf.php',
        dirname(__DIR__) . '/libs/fpdf/fpdf.php',
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            if (class_exists('FPDF', false) || class_exists('FPDF')) return;
        }
    }

    throw new RuntimeException('FPDF library not found. Place fpdf.php in assets/libs/fpdf/fpdf.php.');
}

function pbf_load_data(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("
        SELECT
            pb.*,
            ft.function_name,
            ps.status_name,
            c.address AS customer_master_address,
            c.gst_number AS customer_master_gst
        FROM proforma_bills pb
        LEFT JOIN function_types ft ON ft.id = pb.function_type_id
        LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id
        LEFT JOIN customers c ON c.id = pb.customer_id
        WHERE pb.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bill) return null;

    $items = [];
    $stmt = $conn->prepare("
        SELECT
            pbi.*,
            p.product_name AS master_product_name,
            pt.printing_name,
            pst.sub_type_name
        FROM proforma_bill_items pbi
        LEFT JOIN products p ON p.id = pbi.product_id
        LEFT JOIN printing_types pt ON pt.id = pbi.printing_type_id
        LEFT JOIN printing_sub_types pst ON pst.id = pbi.printing_sub_type_id
        WHERE pbi.proforma_bill_id = ?
        ORDER BY pbi.sort_order ASC, pbi.id ASC
    ");
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
        'final' => $final,
        'gst_percent' => $gstPercent,
        'taxable' => $taxable,
        'gst_amount' => $gstAmount,
        'advance' => round((float)($bill['advance_amount'] ?? 0), 2),
        'balance' => round((float)($bill['balance_amount'] ?? 0), 2),
    ];
}

try {
    pbf_load_fpdf();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to generate PDF. ' . $e->getMessage();
    exit;
}

class SubhikshaWeddingProformaPDF extends FPDF
{
    private string $background;

    public function __construct(string $background)
    {
        parent::__construct('P', 'mm', [210, 280]); // exact 3:4 page ratio
        $this->background = $background;
        $this->SetMargins(0, 0, 0);
        $this->SetAutoPageBreak(false);
    }

    public function Header()
    {
        if (is_file($this->background)) {
            $this->Image($this->background, 0, 0, 210, 280);
        }
    }

    public function fitText(float $x, float $y, float $w, float $h, string $text, float $maxSize = 8.5, string $style = ''): void
    {
        $text = pbf_pdf_text($text);
        $size = $maxSize;
        do {
            $this->SetFont('Arial', $style, $size);
            if ($this->GetStringWidth($text) <= $w - 2 || $size <= 5.5) break;
            $size -= 0.3;
        } while ($size > 5.5);

        $this->SetXY($x, $y);
        $this->Cell($w, $h, $text, 0, 0, 'L');
    }

    private function compactText(string $text, int $maxChars = 145): string
    {
        $text = preg_replace('/\s+/', ' ', trim(pbf_pdf_text($text)));
        if (strlen($text) <= $maxChars) return $text;
        return rtrim(substr($text, 0, $maxChars - 3)) . '...';
    }

    public function itemRow(float $y, array $item, int $serial): void
    {
        $qty = (float)($item['qty'] ?? 0);
        $rate = (float)($item['rate'] ?? 0);
        $amount = round((float)($item['amount'] ?? ($qty * $rate)), 2);

        $description = pbf_clean($item['item_name'] ?? $item['master_product_name'] ?? '', 'Item');
        $details = [];
        if (!empty($item['description']) && trim((string)$item['description']) !== trim($description)) $details[] = trim((string)$item['description']);
        if (!empty($item['printing_name'])) $details[] = trim((string)$item['printing_name']);
        if (!empty($item['sub_type_name'])) $details[] = trim((string)$item['sub_type_name']);
        if (!empty($item['size_text'])) $details[] = 'Size ' . trim((string)$item['size_text']);
        if (!empty($item['gsm_thickness'])) $details[] = 'GSM ' . trim((string)$item['gsm_thickness']);
        if ((int)($item['lamination_required'] ?? 0) === 1 && !empty($item['lamination_type'])) $details[] = ucfirst((string)$item['lamination_type']) . ' lamination';
        if (!empty($details)) $description .= ' - ' . implode(', ', array_unique($details));
        $description = $this->compactText($description);

        // Stronger, more readable item styling.
        $this->SetTextColor(92, 18, 14);
        $this->SetFont('Helvetica', 'B', 8.8);
        $this->SetXY(11, $y + 1.2);
        $this->Cell(20, 7, (string)$serial, 0, 0, 'C');

        // Description is bold and wrapped inside its own column.
        $this->SetFont('Helvetica', 'B', 8.2);
        $this->SetXY(35.0, $y + 0.8);
        $this->MultiCell(77.5, 4.3, $description, 0, 'L');

        $this->SetFont('Helvetica', 'B', 8.8);
        $this->SetXY(114, $y + 1.2);
        $this->Cell(28, 7, pbf_qty($qty), 0, 0, 'C');
        $this->SetXY(143, $y + 1.2);
        $this->Cell(25, 7, pbf_money_value($rate), 0, 0, 'R');
        $this->SetXY(169, $y + 1.2);
        $this->Cell(29, 7, pbf_money_value($amount), 0, 0, 'R');
    }
}

try {
    pbf_load_fpdf();

    $data = pbf_load_data($conn, $id);
    if (!$data) {
        http_response_code(404);
        die('Proforma Bill not found.');
    }

    $bill = $data['bill'];
    $items = $data['items'];
    $summary = pbf_amount_summary($bill);

    $background = __DIR__ . '/assets/img/subhiksha_wedding_invoice_bg.png';
    if (!is_file($background)) {
        throw new RuntimeException('Invoice background image missing: assets/img/subhiksha_wedding_invoice_bg.png');
    }

    $pdf = new SubhikshaWeddingProformaPDF($background);
    $pdf->SetTitle('Proforma Bill - ' . (string)($bill['proforma_no'] ?? ''));
    $pdf->SetAuthor('Subhiksha Cards');
    $pdf->AddPage();

    // Dynamic values over the reference template.
    $pdf->SetTextColor(43, 46, 113);
    $pdf->SetFont('Helvetica', 'B', 8.8);
    $pdf->SetXY(20, 79.8);
    $pdf->Cell(38, 6, pbf_pdf_text((string)($bill['proforma_no'] ?? '-')), 0, 0, 'L');
    $pdf->SetXY(158, 79.8);
    $pdf->Cell(36, 6, pbf_pdf_text(pbf_date($bill['created_at'] ?? date('Y-m-d'))), 0, 0, 'R');

    $bride = trim((string)($bill['bride_name'] ?? ''));
    $groom = trim((string)($bill['groom_name'] ?? ''));
    $couple = trim($bride . ($bride !== '' && $groom !== '' ? ' & ' : '') . $groom);
    if ($couple === '') $couple = pbf_clean($bill['customer_name'] ?? '-', '-');

    $address = trim((string)(($bill['billing_address'] ?? '') ?: ($bill['customer_master_address'] ?? '')));
    $mobile = trim((string)(($bill['billing_mobile'] ?? '') ?: ($bill['mobile'] ?? '')));
    $addressMobile = trim($address . ($address !== '' && $mobile !== '' ? ' / ' : '') . $mobile);

    $pdf->SetTextColor(116, 23, 17);
    $pdf->fitText(60, 90.0, 112, 6, $couple, 9.2, 'B');
    $pdf->fitText(48, 99.7, 124, 6, pbf_date($bill['function_date'] ?? ''), 9.0, 'B');
    $pdf->fitText(46, 109.5, 126, 6, pbf_clean($bill['venue'] ?? '-', '-'), 9.0, 'B');
    $pdf->fitText(48, 119.3, 124, 6, pbf_clean($addressMobile, '-'), 8.4, 'B');

    // Item rows. The designed table comfortably supports 10 lines.
    $rowsPerPage = 8;
    $chunks = array_chunk($items ?: [[]], $rowsPerPage);
    $serial = 1;

    foreach ($chunks as $pageIndex => $pageItems) {
        if ($pageIndex > 0) {
            $pdf->AddPage();
            // Reprint the identifying values on continuation pages.
            $pdf->SetTextColor(43, 46, 113);
            $pdf->SetFont('Helvetica', 'B', 8.8);
            $pdf->SetXY(20, 79.8);
            $pdf->Cell(38, 6, pbf_pdf_text((string)($bill['proforma_no'] ?? '-')), 0, 0, 'L');
            $pdf->SetXY(158, 79.8);
            $pdf->Cell(36, 6, pbf_pdf_text(pbf_date($bill['created_at'] ?? date('Y-m-d'))), 0, 0, 'R');
            $pdf->SetTextColor(116, 23, 17);
            $pdf->fitText(60, 90.0, 112, 6, $couple, 9.2, 'B');
            $pdf->fitText(48, 99.7, 124, 6, pbf_date($bill['function_date'] ?? ''), 9.0, 'B');
            $pdf->fitText(46, 109.5, 126, 6, pbf_clean($bill['venue'] ?? '-', '-'), 9.0, 'B');
            $pdf->fitText(48, 119.3, 124, 6, pbf_clean($addressMobile, '-'), 8.4, 'B');
        }

        $rowY = 145.5;
        foreach ($pageItems as $item) {
            if (!empty($item)) $pdf->itemRow($rowY, $item, $serial++);
            $rowY += 10.0;
        }

        // Totals are shown only on the last page.
        if ($pageIndex === count($chunks) - 1) {
            $pdf->SetTextColor(116, 23, 17);
            $pdf->SetFont('Helvetica', 'B', 8.4);

            // The label cell already displays "GST 18%", so show only the GST amount here.
            $gstText = pbf_money_value($summary['gst_amount']);

            // Use equal horizontal padding and lift each value slightly above the next row line.
            // A shorter cell height prevents the baseline from sitting too close to the bottom border.
            $summaryValueX = 169.5;
            $summaryValueW = 28.5;
            $summaryRowH   = 6.6;

            $pdf->SetXY($summaryValueX, 216.15);
            $pdf->Cell($summaryValueW, $summaryRowH, pbf_pdf_text($gstText), 0, 0, 'C');

            $pdf->SetXY($summaryValueX, 224.25);
            $pdf->Cell($summaryValueW, $summaryRowH, pbf_pdf_text(pbf_money_value($summary['final'])), 0, 0, 'C');

            $pdf->SetXY($summaryValueX, 232.35);
            $pdf->Cell($summaryValueW, $summaryRowH, pbf_pdf_text(pbf_money_value($summary['advance'])), 0, 0, 'C');

            $pdf->SetXY($summaryValueX, 240.45);
            $pdf->Cell($summaryValueW, $summaryRowH, pbf_pdf_text(pbf_money_value($summary['balance'])), 0, 0, 'C');

            if (!empty($bill['remarks'])) {
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', '', 5.8);
                $pdf->SetXY(7, 251.8);
                $pdf->MultiCell(72, 3.4, pbf_pdf_text('Remarks: ' . trim((string)$bill['remarks'])), 0, 'L');
            }
        }
    }

    $filename = 'Proforma_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($bill['proforma_no'] ?? $id)) . '.pdf';
    $outputMode = (isset($_GET['download']) && (string)$_GET['download'] === '1') ? 'D' : 'I';
    $pdf->Output($outputMode, $filename);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('PDF generation failed: ' . $e->getMessage());
}