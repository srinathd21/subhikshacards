<?php
/**
 * includes/proforma-bill-pdf.php
 * Event-aware A4 FPDF proforma invoice for Subhiksha Cards.
 * FPDF expected path: /assets/libs/fpdf/fpdf.php
 * Background: /assets/img/subhiksha_proforma_invoice_bg.png
 */

if (!function_exists('sbp_table_exists')) {
    function sbp_table_exists(mysqli $conn, string $table): bool
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
}

if (!function_exists('sbp_col_exists')) {
    function sbp_col_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $tableEsc = $conn->real_escape_string($table);
            $columnEsc = $conn->real_escape_string($column);
            $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
            $ok = $res && $res->num_rows > 0;
            if ($res) $res->free();
            return $cache[$key] = $ok;
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('sbp_setting')) {
    function sbp_setting(mysqli $conn, string $key, string $default = ''): string
    {
        try {
            if (!sbp_table_exists($conn, 'system_settings')) return $default;
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ? trim((string)$row['setting_value']) : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('sbp_base_url')) {
    function sbp_base_url(mysqli $conn): string
    {
        foreach (['site_url', 'base_url', 'app_url'] as $key) {
            $value = sbp_setting($conn, $key, '');
            if ($value !== '') return rtrim($value, '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $dir = preg_replace('#/api$#', '', $dir);
        return rtrim($scheme . '://' . $host . ($dir === '' || $dir === '/' ? '' : $dir), '/');
    }
}

if (!function_exists('sbp_load_fpdf')) {
    function sbp_load_fpdf(): void
    {
        if (class_exists('FPDF')) return;
        $root = dirname(__DIR__);
        $candidates = [
            $root . '/assets/libs/fpdf/fpdf.php',
            $root . '/assets/libs/fpdf/FPDF.php',
            $root . '/assets/libs/fpdf/fpdf186/fpdf.php',
            $root . '/assets/libs/fpdf/fpdf184/fpdf.php',
            $root . '/vendor/autoload.php',
            $root . '/libs/fpdf.php',
            $root . '/libs/fpdf/fpdf.php',
            $root . '/fpdf.php',
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
                if (class_exists('FPDF')) return;
            }
        }
        throw new RuntimeException('FPDF library not found. Expected: assets/libs/fpdf/fpdf.php');
    }
}

if (!function_exists('sbp_pdf_text')) {
    function sbp_pdf_text($text): string
    {
        $text = trim((string)$text);
        if ($text === '') return '';
        $text = str_replace(['₹', '–', '—'], ['Rs.', '-', '-'], $text);
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
        return $converted !== false ? $converted : $text;
    }
}

if (!function_exists('sbp_money')) {
    function sbp_money($value): string
    {
        return 'Rs. ' . number_format((float)$value, 2);
    }
}

if (!function_exists('sbp_date')) {
    function sbp_date($value): string
    {
        return !empty($value) ? date('d-m-Y', strtotime((string)$value)) : '-';
    }
}

if (!function_exists('sbp_time')) {
    function sbp_time($value): string
    {
        if (empty($value)) return '-';
        $timestamp = strtotime((string)$value);
        return $timestamp !== false ? date('h:i A', $timestamp) : '-';
    }
}

if (!function_exists('sbp_number')) {
    function sbp_number($value): string
    {
        return number_format((float)$value, 2, '.', ',');
    }
}

if (!function_exists('sbp_qty')) {
    function sbp_qty($value): string
    {
        $number = (float)$value;
        return abs($number - round($number)) < 0.00001
            ? number_format($number, 0, '.', ',')
            : number_format($number, 2, '.', ',');
    }
}

if (!function_exists('sbp_clean')) {
    function sbp_clean($value, string $default = '-'): string
    {
        $value = trim((string)$value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return $value === '' ? $default : $value;
    }
}

if (!function_exists('sbp_amount_summary')) {
    function sbp_amount_summary(array $bill): array
    {
        $subTotal = round((float)($bill['sub_total'] ?? 0), 2);
        $discount = round((float)($bill['discount_amount'] ?? 0), 2);
        $extra = round((float)($bill['card_extra_charge'] ?? 0), 2);
        $packing = round((float)($bill['packing_charge'] ?? 0), 2);
        $printing = round((float)($bill['printing_charge'] ?? 0), 2);
        $gstPercent = round((float)($bill['gst_percent'] ?? 18), 2);
        $storedFinal = round((float)($bill['final_amount'] ?? 0), 2);

        $calculatedFinal = round(max(0, $subTotal + $extra + $packing + $printing - $discount), 2);
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
            'gst_percent' => $gstPercent,
            'gst_amount' => $gstAmount,
            'final' => $final,
            'advance' => round((float)($bill['advance_amount'] ?? 0), 2),
            'balance' => round((float)($bill['balance_amount'] ?? 0), 2),
        ];
    }
}

if (!function_exists('sbp_safe_filename')) {
    function sbp_safe_filename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $name);
        $name = trim((string)$name, '_');
        return $name !== '' ? $name : 'proforma_bill';
    }
}

if (!function_exists('sbp_ensure_pdf_column')) {
    function sbp_ensure_pdf_column(mysqli $conn): void
    {
        if (!sbp_table_exists($conn, 'proforma_bills')) return;
        if (!sbp_col_exists($conn, 'proforma_bills', 'proforma_pdf_path')) {
            try { $conn->query("ALTER TABLE proforma_bills ADD COLUMN proforma_pdf_path VARCHAR(255) DEFAULT NULL AFTER remarks"); } catch (Throwable $e) {}
        }
        if (!sbp_col_exists($conn, 'proforma_bills', 'proforma_pdf_generated_at')) {
            try { $conn->query("ALTER TABLE proforma_bills ADD COLUMN proforma_pdf_generated_at DATETIME DEFAULT NULL AFTER proforma_pdf_path"); } catch (Throwable $e) {}
        }
    }
}

if (!function_exists('sbp_get_proforma_data')) {
    function sbp_get_proforma_data(mysqli $conn, int $id): ?array
    {
        if ($id <= 0 || !sbp_table_exists($conn, 'proforma_bills')) return null;
        try {
            $stmt = $conn->prepare("\n                SELECT\n                    pb.*,\n                    ps.status_name,\n                    ft.function_name,\n                    q.quotation_no,\n                    e.enquiry_no,\n                    c.address AS customer_master_address\n                FROM proforma_bills pb\n                LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id\n                LEFT JOIN function_types ft ON ft.id = pb.function_type_id\n                LEFT JOIN quotations q ON q.id = pb.quotation_id\n                LEFT JOIN enquiries e ON e.id = pb.enquiry_id\n                LEFT JOIN customers c ON c.id = pb.customer_id\n                WHERE pb.id = ?\n                LIMIT 1\n            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $bill = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$bill) return null;

            // Older installations did not select field_group in the main query.
            // Load it separately so the invoice fields follow the selected event type.
            $bill['field_group'] = 'other';
            if (!empty($bill['function_type_id']) && sbp_col_exists($conn, 'function_types', 'field_group')) {
                $functionTypeId = (int)$bill['function_type_id'];
                $groupStmt = $conn->prepare("SELECT field_group FROM function_types WHERE id = ? LIMIT 1");
                $groupStmt->bind_param('i', $functionTypeId);
                $groupStmt->execute();
                $groupRow = $groupStmt->get_result()->fetch_assoc();
                $groupStmt->close();
                if ($groupRow && trim((string)($groupRow['field_group'] ?? '')) !== '') {
                    $bill['field_group'] = trim((string)$groupRow['field_group']);
                }
            }

            $items = [];
            $stmt = $conn->prepare("\n                SELECT\n                    pbi.*,\n                    p.product_name AS master_product_name,\n                    pt.printing_name,\n                    pst.sub_type_name\n                FROM proforma_bill_items pbi\n                LEFT JOIN products p ON p.id = pbi.product_id\n                LEFT JOIN printing_types pt ON pt.id = pbi.printing_type_id\n                LEFT JOIN printing_sub_types pst ON pst.id = pbi.printing_sub_type_id\n                WHERE pbi.proforma_bill_id = ?\n                ORDER BY pbi.sort_order ASC, pbi.id ASC\n            ");
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
}


/*
 | IMPORTANT:
 | The PDF class extends FPDF, so the library must be loaded before PHP parses
 | this class declaration. Without this call you will get:
 | Fatal error: Class "FPDF" not found.
 */
sbp_load_fpdf();

class SubhikshaLegacyProformaInvoicePDF extends FPDF
{
    /* Do not use $w / $h property names here because FPDF already declares them. */
    private $contentMargin = 12.0;
    private $contentWidth = 186.0;

    public function Header() {}
    public function Footer() {}

    private function t($text): string { return sbp_pdf_text($text); }

    private function fitCell(float $x, float $y, float $w, float $h, $txt, int $size = 9, string $style = '', string $align = 'L', int $border = 0): void
    {
        $txt = $this->t($txt);
        $fontSize = $size;
        do {
            $this->SetFont('Arial', $style, $fontSize);
            $tooWide = $this->GetStringWidth($txt) > ($w - 2) && $fontSize > 6;
            if ($tooWide) $fontSize--;
        } while ($tooWide);
        $this->SetXY($x, $y);
        $this->Cell($w, $h, $txt, $border, 0, $align);
    }

    private function multi(float $x, float $y, float $w, float $h, $txt, int $size = 8, string $style = '', string $align = 'L'): void
    {
        $this->SetFont('Arial', $style, $size);
        $this->SetXY($x, $y);
        $this->MultiCell($w, $h, $this->t($txt), 0, $align);
    }

    private function labelValue(float $x, float $y, float $labelW, float $valueW, string $label, $value, int $size = 8): void
    {
        $this->fitCell($x, $y, $labelW, 5, $label, $size, 'B');
        $this->fitCell($x + $labelW, $y, $valueW, 5, $value !== '' ? $value : '-', $size, '');
    }

    public function draw(array $bill, array $items): void
    {
        $this->AddPage('P', 'A4');
        $this->SetAutoPageBreak(false);
        $this->SetMargins($this->contentMargin, $this->contentMargin, $this->contentMargin);
        $this->SetDrawColor(0, 0, 0);
        $this->SetTextColor(0, 0, 0);
        $this->SetLineWidth(0.25);

        $x = $this->contentMargin;
        $y = 10;
        $w = $this->contentWidth;
        $this->Rect($x, $y, $w, 275);

        // Header
        $this->Rect($x, $y, $w, 36);
        $this->fitCell($x + 4, $y + 3, 70, 5, 'Contact: 72006 02020, 72007 02020', 8);
        $this->fitCell($x + 112, $y + 3, 70, 5, 'GSTIN: 33AMRPA4225G1ZD', 8, 'B', 'R');
        $this->fitCell($x, $y + 10, $w, 8, 'SUBHIKSHA CARDS', 20, 'B', 'C');
        $this->fitCell($x, $y + 21, $w, 5, 'A unit of Mani Paper Card Company', 10, 'B', 'C');
        $this->fitCell($x, $y + 28, $w, 5, 'Dharmapuri', 9, '', 'C');

        $titleY = 50;
        $this->fitCell($x, $titleY, $w, 7, 'PROFORMA BILL / SALES ORDER', 12, 'B', 'C');
        $this->Line($x, $titleY + 8, $x + $w, $titleY + 8);
        $this->labelValue($x + 6, $titleY + 12, 20, 74, 'No:', $bill['proforma_no'] ?? '-', 9);
        $this->labelValue($x + 124, $titleY + 12, 22, 34, 'Date:', sbp_date($bill['created_at'] ?? date('Y-m-d')), 9);

        // Customer and function blocks
        $boxY = 72;
        $boxH = 49;
        $this->Rect($x + 6, $boxY, 84, $boxH);
        $this->Rect($x + 96, $boxY, 84, $boxH);
        $this->fitCell($x + 8, $boxY + 3, 78, 5, 'CUSTOMER DETAILS', 8, 'B', 'C');
        $this->Line($x + 6, $boxY + 10, $x + 90, $boxY + 10);
        $this->labelValue($x + 9, $boxY + 14, 25, 54, 'Name', $bill['customer_name'] ?? '-', 8);
        $this->labelValue($x + 9, $boxY + 23, 25, 54, 'Mobile', $bill['mobile'] ?? '-', 8);
        $address = ($bill['billing_address'] ?? '') ?: ($bill['customer_master_address'] ?? '-');
        $this->fitCell($x + 9, $boxY + 32, 25, 5, 'Address', 8, 'B');
        $this->multi($x + 34, $boxY + 32, 52, 3.8, $address ?: '-', 7);

        $this->fitCell($x + 98, $boxY + 3, 80, 5, 'ORDER DETAILS', 8, 'B', 'C');
        $this->Line($x + 96, $boxY + 10, $x + 180, $boxY + 10);
        $this->labelValue($x + 99, $boxY + 14, 32, 45, 'Function', $bill['function_name'] ?? '-', 8);
        $brideGroom = trim((string)($bill['bride_name'] ?? '') . ' / ' . (string)($bill['groom_name'] ?? ''), ' /');
        $this->labelValue($x + 99, $boxY + 23, 32, 45, 'Bride/Groom', $brideGroom ?: '-', 8);
        $this->labelValue($x + 99, $boxY + 32, 32, 45, 'Function Date', sbp_date($bill['function_date'] ?? ''), 8);
        $this->labelValue($x + 99, $boxY + 41, 32, 45, 'Delivery Date', sbp_date($bill['delivery_date'] ?? ''), 8);

        // Items table
        $tableX = $x + 6;
        $tableY = 126;
        $tableW = 174;
        $headerH = 8;
        $rowH = 9;
        $rowsAreaH = 82;
        $cols = [10, 66, 18, 23, 25, 32]; // 174
        $headers = ['#', 'Description', 'Qty', 'Rate', 'Amount', 'Printing'];
        $this->Rect($tableX, $tableY, $tableW, $headerH + $rowsAreaH);
        $this->Line($tableX, $tableY + $headerH, $tableX + $tableW, $tableY + $headerH);
        $cx = $tableX;
        foreach ($cols as $i => $cw) {
            if ($i > 0) $this->Line($cx, $tableY, $cx, $tableY + $headerH + $rowsAreaH);
            $this->fitCell($cx, $tableY + 2, $cw, 4, $headers[$i], 8, 'B', 'C');
            $cx += $cw;
        }
        $maxRows = min(9, count($items));
        for ($i = 0; $i < $maxRows; $i++) {
            $item = $items[$i];
            $yy = $tableY + $headerH + ($i * $rowH);
            if ($i > 0) $this->Line($tableX, $yy, $tableX + $tableW, $yy);
            $name = trim((string)($item['item_name'] ?? ''));
            $desc = trim((string)($item['description'] ?? ''));
            $line = $name !== '' ? $name : $desc;
            if ($desc !== '' && strcasecmp($desc, $name) !== 0) $line .= ' - ' . $desc;
            $printing = trim((string)($item['printing_name'] ?? ''));
            if (!empty($item['sub_type_name'])) $printing .= ' / ' . $item['sub_type_name'];
            $this->fitCell($tableX, $yy + 2.2, $cols[0], 4, (string)($i + 1), 8, '', 'C');
            $this->multi($tableX + $cols[0] + 1, $yy + 1.2, $cols[1] - 2, 3.3, $line ?: '-', 7);
            $this->fitCell($tableX + $cols[0] + $cols[1], $yy + 2.2, $cols[2], 4, number_format((float)($item['qty'] ?? 0), 0), 8, '', 'C');
            $this->fitCell($tableX + array_sum(array_slice($cols, 0, 3)), $yy + 2.2, $cols[3] - 1, 4, number_format((float)($item['rate'] ?? 0), 2), 8, '', 'R');
            $this->fitCell($tableX + array_sum(array_slice($cols, 0, 4)), $yy + 2.2, $cols[4] - 1, 4, number_format((float)($item['amount'] ?? 0), 2), 8, '', 'R');
            $this->multi($tableX + array_sum(array_slice($cols, 0, 5)) + 1, $yy + 1.2, $cols[5] - 2, 3.3, $printing ?: '-', 7);
        }

        // Summary and remarks - kept above footer to avoid overlap/clipping.
        $sumX = $x + 104;
        $sumY = 221;
        $labelW = 48;
        $amtW = 28;
        $lineH = 6.8;
        $summary = [
            'Sub Total' => $bill['sub_total'] ?? 0,
            'Discount' => $bill['discount_amount'] ?? 0,
            'Extra Charge' => $bill['card_extra_charge'] ?? 0,
            'Final Amount' => $bill['final_amount'] ?? 0,
            'Advance Paid' => $bill['advance_amount'] ?? 0,
            'Balance Amount' => $bill['balance_amount'] ?? 0,
        ];
        $this->Rect($sumX, $sumY, $labelW + $amtW, $lineH * count($summary));
        $this->Line($sumX + $labelW, $sumY, $sumX + $labelW, $sumY + ($lineH * count($summary)));
        $i = 0;
        foreach ($summary as $label => $value) {
            if ($i > 0) $this->Line($sumX, $sumY + ($lineH * $i), $sumX + $labelW + $amtW, $sumY + ($lineH * $i));
            $bold = in_array($label, ['Final Amount', 'Balance Amount'], true) ? 'B' : '';
            $this->fitCell($sumX + 1, $sumY + ($lineH * $i) + 1.8, $labelW - 2, 4, $label, 8, $bold, 'R');
            $this->fitCell($sumX + $labelW + 1, $sumY + ($lineH * $i) + 1.8, $amtW - 2, 4, sbp_money($value), 8, $bold, 'R');
            $i++;
        }

        $remarks = trim((string)($bill['remarks'] ?? ''));
        $this->fitCell($x + 8, 221, 55, 5, 'Remarks:', 8, 'B');
        $this->multi($x + 8, 228, 88, 4, $remarks !== '' ? $remarks : '-', 8);

        // Footer area is separated from the summary box.
        $this->fitCell($x + 8, 266, 65, 5, 'Terms & Conditions:', 8, 'B');
        $this->multi($x + 8, 272, 108, 3.7, 'Order once booked cannot be cancelled by the buyer at any circumstance.', 7);
        $this->Line($x + 125, 275, $x + 178, 275);
        $this->fitCell($x + 124, 277, 54, 5, 'For Subhiksha Cards', 9, 'B', 'C');
    }
}

/**
 * Current decorated invoice renderer.
 * This is the only class used by both inline output and saved WhatsApp PDFs.
 */
class SubhikshaProformaInvoicePDF extends FPDF
{
    private $background;

    public function __construct(string $background)
    {
        parent::__construct('P', 'mm', 'A4');
        if (!is_file($background)) {
            throw new RuntimeException('Invoice background image missing: assets/img/subhiksha_proforma_invoice_bg.png');
        }
        $this->background = $background;
        $this->SetMargins(0, 0, 0);
        $this->SetAutoPageBreak(false);
    }

    public function Header()
    {
        $this->Image($this->background, 0, 0, 210, 297);
    }

    public function Footer() {}

    private function fitText(
        float $x,
        float $y,
        float $w,
        float $h,
        string $text,
        float $maxSize = 8.5,
        string $style = '',
        string $align = 'L'
    ): void {
        $text = sbp_pdf_text($text);
        $size = $maxSize;
        do {
            $this->SetFont('Arial', $style, $size);
            if ($this->GetStringWidth($text) <= $w - 2 || $size <= 5.5) break;
            $size -= 0.3;
        } while ($size > 5.5);

        $this->SetXY($x, $y);
        $this->Cell($w, $h, $text, 0, 0, $align);
    }

    private function invoiceIdentity(array $bill): void
    {
        // "No." is part of the original artwork. Only its value is dynamic.
        $this->SetTextColor(40, 40, 40);
        $this->fitText(29, 91.1, 72, 6, (string)($bill['proforma_no'] ?? '-'), 8.2, 'B');

        // Cover the Tamil date caption while preserving the exact original UI.
        $this->SetFillColor(255, 199, 26);
        $this->Rect(151, 90.4, 45, 7.0, 'F');
        $this->SetTextColor(204, 26, 24);
        $this->SetFont('Arial', 'B', 8.2);
        $this->SetXY(153, 91.0);
        $this->Cell(13, 5.8, 'Date:', 0, 0, 'L');
        $this->SetTextColor(40, 40, 40);
        $this->fitText(165.5, 91.0, 29.5, 5.8, sbp_date($bill['created_at'] ?? date('Y-m-d')), 8.0, 'B', 'L');
    }

    private function detailPair(
        float $y,
        string $leftLabel,
        string $leftValue,
        string $rightLabel,
        string $rightValue
    ): void {
        $this->SetTextColor(111, 31, 25);
        $this->SetFont('Arial', 'B', 6.2);
        $this->SetXY(14, $y + 0.7);
        $this->Cell(40, 3.2, sbp_pdf_text($leftLabel), 0, 0, 'L');
        $this->SetXY(107, $y + 0.7);
        $this->Cell(40, 3.2, sbp_pdf_text($rightLabel), 0, 0, 'L');

        $this->SetTextColor(35, 35, 35);
        $this->fitText(14, $y + 3.6, 88, 5.2, sbp_clean($leftValue), 8.2, 'B');
        $this->fitText(107, $y + 3.6, 88, 5.2, sbp_clean($rightValue), 8.2, 'B');
    }

    private function detailFull(float $y, string $label, string $value): void
    {
        $this->SetTextColor(111, 31, 25);
        $this->SetFont('Arial', 'B', 6.2);
        $this->SetXY(14, $y + 0.7);
        $this->Cell(55, 3.2, sbp_pdf_text($label), 0, 0, 'L');

        $this->SetTextColor(35, 35, 35);
        $this->fitText(14, $y + 3.6, 181, 5.2, sbp_clean($value), 8.2, 'B');
    }

    private function dottedLine(float $x1, float $y, float $x2): void
    {
        $this->SetFillColor(211, 31, 28);
        for ($x = $x1; $x <= $x2; $x += 1.35) {
            $this->Rect($x, $y, 0.42, 0.42, 'F');
        }
    }

    private function exactUiDetailLine(float $y, string $label, string $value, bool $compact = false): void
    {
        // Remove only the baked Tamil row, then rebuild the same dotted-line UI.
        $rowHeight = $compact ? 4.1 : 5.6;
        $cellHeight = $compact ? 3.8 : 5.2;
        $lineOffset = $compact ? 3.6 : 4.7;
        $labelSize = $compact ? 6.5 : 7.2;
        $valueSize = $compact ? 7.0 : 7.4;

        $this->SetFillColor(255, 199, 26);
        $this->Rect(23.5, $y, 175.0, $rowHeight, 'F');

        $this->SetTextColor(211, 31, 28);
        $this->fitText(24.5, $y - 0.1, 36.0, $cellHeight, $label, $labelSize, 'B');
        $this->dottedLine(61.0, $y + $lineOffset, 195.0);

        $this->SetTextColor(35, 35, 35);
        $this->fitText(62.0, $y - 0.2, 132.0, $cellHeight, sbp_clean($value), $valueSize, 'B');
    }

    private function drawOrderCheckboxes(string $orderType): void
    {
        $orderType = strtolower(trim($orderType));
        $this->SetTextColor(85, 35, 24);
        $this->SetFont('Arial', 'B', 5.5);
        $this->SetXY(181.5, 104.2);
        $this->Cell(17.5, 5, 'READYMADE', 0, 0, 'L');
        $this->SetXY(181.5, 115.6);
        $this->Cell(17.5, 5, 'CUSTOMIZED', 0, 0, 'L');

        $this->SetTextColor(211, 31, 28);
        $this->SetFont('Arial', 'B', 15);
        if ($orderType === 'customized') {
            $this->SetXY(173.5, 113.9);
        } else {
            $this->SetXY(173.5, 102.5);
        }
        $this->Cell(7, 7, 'X', 0, 0, 'C');
    }

    private function customerEventDetails(array $bill, array $items): void
    {
        $fieldGroup = strtolower(trim((string)($bill['field_group'] ?? 'other')));
        $customerName = sbp_clean(($bill['billing_name'] ?? '') ?: ($bill['customer_name'] ?? '-'));
        $mobile = trim((string)(($bill['billing_mobile'] ?? '') ?: ($bill['mobile'] ?? '')));
        $address = trim((string)(($bill['billing_address'] ?? '') ?: ($bill['customer_master_address'] ?? '')));
        $address = preg_replace('/\s+/', ' ', $address);
        $functionName = trim((string)($bill['function_name'] ?? ''));
        if ($functionName === '' && !empty($items[0])) {
            $functionName = trim((string)(($items[0]['item_name'] ?? '') ?: ($items[0]['master_product_name'] ?? '')));
        }
        $functionName = sbp_clean($functionName);
        $functionDate = sbp_date($bill['function_date'] ?? '');
        $functionTime = sbp_time($bill['function_time'] ?? '');
        $dateTime = $functionDate . ($functionTime !== '-' ? '  ' . $functionTime : '');
        $venue = sbp_clean($bill['venue'] ?? '-');

        // Clear all baked customer rows and checkbox artwork first so every
        // field-group layout begins from the same aligned area.
        $this->SetFillColor(255, 199, 26);
        $this->Rect(23.5, 97.2, 175.0, 29.0, 'F');

        if ($fieldGroup === 'wedding_reception') {
            $bride = trim((string)($bill['bride_name'] ?? ''));
            $groom = trim((string)($bill['groom_name'] ?? ''));
            $this->exactUiDetailLine(97.4, 'BRIDE NAME:', $bride !== '' ? $bride : '-', true);
            $this->exactUiDetailLine(101.5, 'GROOM NAME:', $groom !== '' ? $groom : '-', true);
            $this->exactUiDetailLine(105.6, 'FUNCTION TYPE:', $functionName, true);
            $this->exactUiDetailLine(109.7, 'FUNCTION DATE / TIME:', $dateTime, true);
            $this->exactUiDetailLine(113.8, 'VENUE:', $venue, true);
            $this->exactUiDetailLine(117.9, 'MOBILE:', $mobile !== '' ? $mobile : '-', true);
            $this->exactUiDetailLine(122.0, 'ADDRESS:', $address !== '' ? $address : '-', true);
            return;
        }

        if ($fieldGroup === 'event') {
            $this->exactUiDetailLine(97.4, 'CUSTOMER NAME:', $customerName, true);
            $this->exactUiDetailLine(102.1, 'FUNCTION TYPE:', $functionName, true);
            $this->exactUiDetailLine(106.8, 'FUNCTION DATE / TIME:', $dateTime, true);
            $this->exactUiDetailLine(111.5, 'VENUE:', $venue, true);
            $this->exactUiDetailLine(116.2, 'MOBILE:', $mobile !== '' ? $mobile : '-', true);
            $this->exactUiDetailLine(120.9, 'ADDRESS:', $address !== '' ? $address : '-', true);
            return;
        }

        $this->exactUiDetailLine(98.0, 'CUSTOMER NAME:', $customerName);
        $this->exactUiDetailLine(105.2, 'FUNCTION TYPE:', $functionName);
        $this->exactUiDetailLine(112.4, 'MOBILE:', $mobile !== '' ? $mobile : '-');
        $this->exactUiDetailLine(119.6, 'ADDRESS:', $address !== '' ? $address : '-');
    }

    private function tableFrame(): void
    {
        // Keep the exact white header and red table borders from the artwork.
        // Only cover the baked Tamil captions and print English captions.
        $this->SetFillColor(255, 255, 255);
        $headers = [
            [22.9, 19.5, 'S.NO'],
            [43.2, 69.1, 'DESCRIPTION'],
            [113.1, 24.1, 'QTY'],
            [138.2, 21.9, 'RATE'],
            [160.9, 25.0, 'AMOUNT'],
        ];
        foreach ($headers as $header) {
            $this->Rect($header[0], 127.7, $header[1], 7.9, 'F');
        }

        $this->SetTextColor(199, 27, 24);
        $this->SetFont('Arial', 'B', 7.6);
        foreach ($headers as $header) {
            $this->SetXY($header[0], 128.4);
            $this->Cell($header[1], 6.5, $header[2], 0, 0, 'C');
        }

        // Remove the baked Tamil totals captions on every page. The last page
        // receives the live English totals in totals().
        $this->SetFillColor(255, 198, 26);
        $totalRows = [
            [235.5, 4.7],
            [240.9, 5.0],
            [246.6, 4.8],
            [252.0, 5.2],
        ];
        foreach ($totalRows as $row) {
            $this->Rect(138.2, $row[0], 21.9, $row[1], 'F');
        }
    }

    private function compactText(string $text, int $maxChars = 95): string
    {
        $text = preg_replace('/\s+/', ' ', trim(sbp_pdf_text($text)));
        if (strlen($text) <= $maxChars) return $text;
        return rtrim(substr($text, 0, $maxChars - 3)) . '...';
    }

    private function itemRow(float $y, array $item, int $serial): void
    {
        $qty = (float)($item['qty'] ?? 0);
        $rate = (float)($item['rate'] ?? 0);
        $amount = round((float)($item['amount'] ?? ($qty * $rate)), 2);

        $description = sbp_clean(($item['item_name'] ?? '') ?: ($item['master_product_name'] ?? ''), 'Item');
        $details = [];
        if (!empty($item['description']) && trim((string)$item['description']) !== trim($description)) $details[] = trim((string)$item['description']);
        if (!empty($item['printing_name'])) $details[] = trim((string)$item['printing_name']);
        if (!empty($item['sub_type_name'])) $details[] = trim((string)$item['sub_type_name']);
        if (!empty($item['size_text'])) $details[] = 'Size ' . trim((string)$item['size_text']);
        if (!empty($item['gsm_thickness'])) $details[] = 'GSM ' . trim((string)$item['gsm_thickness']);
        if ((int)($item['lamination_required'] ?? 0) === 1 && !empty($item['lamination_type'])) $details[] = ucfirst((string)$item['lamination_type']) . ' lamination';
        if ($details) $description .= ' - ' . implode(', ', array_unique($details));
        $description = $this->compactText($description);

        $this->SetTextColor(45, 37, 32);
        $this->SetFont('Arial', '', 7.4);
        $this->SetXY(22.7, $y + 0.6);
        $this->Cell(20.1, 7.2, (string)$serial, 0, 0, 'C');

        $this->SetFont('Arial', '', 6.9);
        $this->SetXY(44.0, $y + 0.5);
        $this->MultiCell(67.8, 3.2, sbp_pdf_text($description), 0, 'L');

        $this->SetFont('Arial', '', 7.4);
        $this->SetXY(113.0, $y + 0.6);
        $this->Cell(24.2, 7.2, sbp_qty($qty), 0, 0, 'C');
        $this->SetXY(138.0, $y + 0.6);
        $this->Cell(22.0, 7.2, sbp_number($rate), 0, 0, 'R');
        $this->SetXY(161.0, $y + 0.6);
        $this->Cell(24.6, 7.2, sbp_number($amount), 0, 0, 'R');
    }

    private function totals(array $summary, string $remarks): void
    {
        $rows = [
            ['GST ' . sbp_number($summary['gst_percent']) . '%', $summary['gst_amount']],
            ['TOTAL', $summary['final']],
            ['ADVANCE', $summary['advance']],
            ['BALANCE', $summary['balance']],
        ];

        $rowTops = [235.5, 240.9, 246.6, 252.0];
        $rowHeights = [4.7, 5.0, 4.8, 5.2];
        $this->SetFillColor(255, 198, 26);
        foreach ($rowTops as $index => $top) {
            $this->Rect(138.2, $top, 21.9, $rowHeights[$index], 'F');
        }

        $this->SetTextColor(204, 26, 24);
        $this->SetFont('Arial', 'B', 6.6);
        foreach ($rows as $index => $row) {
            $this->SetXY(138.4, $rowTops[$index] + 0.1);
            $this->Cell(21.5, $rowHeights[$index], sbp_pdf_text($row[0]), 0, 0, 'R');

            $this->SetTextColor(45, 37, 32);
            $this->SetXY(160.9, $rowTops[$index] + 0.1);
            $this->Cell(24.8, $rowHeights[$index], sbp_number($row[1]), 0, 0, 'R');
            $this->SetTextColor(204, 26, 24);
        }

        if (trim($remarks) !== '') {
            $this->SetTextColor(45, 37, 32);
            $this->SetFont('Arial', '', 6.0);
            $this->SetXY(24, 229.0);
            $this->MultiCell(105, 3.2, sbp_pdf_text('Remarks: ' . trim($remarks)), 0, 'L');
        }
    }

    public function draw(array $bill, array $items): void
    {
        $summary = sbp_amount_summary($bill);
        $chunks = array_chunk($items ?: [[]], 9);
        $serial = 1;

        foreach ($chunks as $pageIndex => $pageItems) {
            $this->AddPage('P', 'A4');
            $this->invoiceIdentity($bill);
            $this->customerEventDetails($bill, $items);
            $this->tableFrame();

            $rowY = 138.2;
            foreach ($pageItems as $item) {
                if (!empty($item)) $this->itemRow($rowY, $item, $serial++);
                $rowY += 10.0;
            }

            if ($pageIndex === count($chunks) - 1) {
                $this->totals($summary, (string)($bill['remarks'] ?? ''));
            }
        }
    }
}

if (!function_exists('sbp_generate_proforma_pdf_file')) {
    function sbp_generate_proforma_pdf_file(mysqli $conn, int $id, bool $force = false): array
    {
        sbp_load_fpdf();
        sbp_ensure_pdf_column($conn);
        $data = sbp_get_proforma_data($conn, $id);
        if (!$data) throw new RuntimeException('Unable to load proforma bill details for PDF.');
        $bill = $data['bill'];
        $existingPath = (string)($bill['proforma_pdf_path'] ?? '');
        $root = dirname(__DIR__);
        $background = $root . '/assets/img/subhiksha_proforma_invoice_bg.png';
        $isCurrentLayout = strpos(basename($existingPath), '_closer_title_value_v10_') !== false;
        if (!$force && $isCurrentLayout && $existingPath !== '' && is_file($root . '/' . ltrim($existingPath, '/'))) {
            return ['path' => $existingPath, 'url' => sbp_base_url($conn) . '/' . ltrim($existingPath, '/'), 'filename' => basename($existingPath)];
        }
        $dir = $root . '/uploads/proforma_bills';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Unable to create uploads/proforma_bills folder.');
        $proformaNo = (string)($bill['proforma_no'] ?? ('PROFORMA_' . $id));
        $fileName = sbp_safe_filename($proformaNo) . '_closer_title_value_v10_' . date('YmdHis') . '.pdf';
        $abs = $dir . '/' . $fileName;
        $rel = 'uploads/proforma_bills/' . $fileName;
        $pdf = new SubhikshaProformaInvoicePDF($background);
        $pdf->SetTitle('Proforma Bill - ' . $proformaNo);
        $pdf->SetAuthor('Subhiksha Cards');
        $pdf->SetCreator('Subhiksha Cards Closer Title Value v10');
        $pdf->draw($bill, $data['items']);
        $pdf->Output('F', $abs);
        if (!is_file($abs)) throw new RuntimeException('PDF file was not generated.');
        if (sbp_col_exists($conn, 'proforma_bills', 'proforma_pdf_path')) {
            try {
                if (sbp_col_exists($conn, 'proforma_bills', 'proforma_pdf_generated_at')) {
                    $stmt = $conn->prepare("UPDATE proforma_bills SET proforma_pdf_path = ?, proforma_pdf_generated_at = NOW(), updated_at = NOW() WHERE id = ?");
                } else {
                    $stmt = $conn->prepare("UPDATE proforma_bills SET proforma_pdf_path = ?, updated_at = NOW() WHERE id = ?");
                }
                $stmt->bind_param('si', $rel, $id);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable $e) {}
        }
        return ['path' => $rel, 'url' => sbp_base_url($conn) . '/' . $rel, 'filename' => $fileName];
    }
}

if (!function_exists('sbp_output_proforma_pdf_inline')) {
    function sbp_output_proforma_pdf_inline(mysqli $conn, int $id, bool $download = false): void
    {
        sbp_load_fpdf();
        $data = sbp_get_proforma_data($conn, $id);
        if (!$data) throw new RuntimeException('Proforma bill not found.');
        $proformaNo = (string)($data['bill']['proforma_no'] ?? ('PROFORMA_' . $id));
        $background = dirname(__DIR__) . '/assets/img/subhiksha_proforma_invoice_bg.png';
        $pdf = new SubhikshaProformaInvoicePDF($background);
        $pdf->SetTitle('Proforma Bill - ' . $proformaNo);
        $pdf->SetAuthor('Subhiksha Cards');
        $pdf->SetCreator('Subhiksha Cards Closer Title Value v10');
        $pdf->draw($data['bill'], $data['items']);

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Subhiksha-Proforma-Layout: closer-title-value-v10');
        $pdf->Output($download ? 'D' : 'I', sbp_safe_filename($proformaNo) . '.pdf');
    }
}
