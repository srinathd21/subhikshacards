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
    function sbp_amount_summary(array $bill, array $items = []): array
    {
        $orderType = strtolower(trim((string)($bill['order_type'] ?? 'readymade')));
        $discount = round(max(0, (float)($bill['discount_amount'] ?? 0)), 2);
        $gstPercent = round(max(0, (float)($bill['gst_percent'] ?? 0)), 2);

        $itemSubTotal = 0.0;
        $itemPlate = 0.0;
        $itemAdditional = 0.0;
        $itemPrinting = 0.0;
        $itemPackage = 0.0;

        foreach ($items as $item) {
            $qty = (float)($item['qty'] ?? 0);
            $rate = (float)($item['rate'] ?? 0);
            $amount = isset($item['amount'])
                ? (float)$item['amount']
                : ($qty * $rate);

            $itemSubTotal += $amount;
            $itemPlate += (float)($item['plate_charge'] ?? 0);
            $itemAdditional += (float)($item['item_additional_charge'] ?? 0);
            $itemPrinting += (float)($item['item_printing_charge'] ?? 0);
            $itemPackage += (float)($item['item_package_charge'] ?? 0);
        }

        $storedSubTotal = round(max(0, (float)($bill['sub_total'] ?? 0)), 2);
        $subTotal = $items
            ? round(max(0, $itemSubTotal), 2)
            : $storedSubTotal;

        if ($orderType === 'customized' && $items) {
            $plate = round(max(0, $itemPlate), 2);
            $additional = round(max(0, $itemAdditional), 2);
            $printing = round(max(0, $itemPrinting), 2);
            $packing = round(max(0, $itemPackage), 2);

            $itemChargeTotal = round($plate + $additional + $printing + $packing, 2);
            $storedChargeTotal = round(
                max(0, (float)($bill['card_extra_charge'] ?? 0))
                + max(0, (float)($bill['printing_charge'] ?? 0))
                + max(0, (float)($bill['packing_charge'] ?? 0)),
                2
            );

            if ($itemChargeTotal <= 0.009 && $storedChargeTotal > 0.009) {
                $plate = round(max(0, (float)($bill['card_extra_charge'] ?? 0)), 2);
                $additional = 0.0;
                $printing = round(max(0, (float)($bill['printing_charge'] ?? 0)), 2);
                $packing = round(max(0, (float)($bill['packing_charge'] ?? 0)), 2);
            }
        } else {
            $plate = round(max(0, (float)($bill['card_extra_charge'] ?? 0)), 2);
            $additional = 0.0;
            $printing = round(max(0, (float)($bill['printing_charge'] ?? 0)), 2);
            $packing = round(max(0, (float)($bill['packing_charge'] ?? 0)), 2);
        }

        $extra = round($plate + $additional, 2);
        $chargeTotal = round($extra + $printing + $packing, 2);
        $grossBeforeDiscount = round($subTotal + $chargeTotal, 2);
        $final = round(max(0, $grossBeforeDiscount - $discount), 2);

        if ($gstPercent > 0) {
            $taxable = round($final / (1 + ($gstPercent / 100)), 2);
            $gstAmount = round(max(0, $final - $taxable), 2);
        } else {
            $taxable = $final;
            $gstAmount = 0.0;
        }

        $advance = round(max(0, (float)($bill['advance_amount'] ?? 0)), 2);
        $advance = min($advance, $final);
        $balance = round(max(0, $final - $advance), 2);

        return [
            'order_type' => $orderType,
            'sub_total' => $subTotal,
            'plate' => $plate,
            'additional' => $additional,
            'extra' => $extra,
            'printing' => $printing,
            'packing' => $packing,
            'charge_total' => $chargeTotal,
            'gross_before_discount' => $grossBeforeDiscount,
            'discount' => $discount,
            'gst_percent' => $gstPercent,
            'taxable' => $taxable,
            'gst_amount' => $gstAmount,
            'final' => $final,
            'advance' => $advance,
            'balance' => $balance,
            'stored_final' => round((float)($bill['final_amount'] ?? 0), 2),

            /*
             * Quick Sale invoice presentation metadata.
             * Normal Proforma bills ignore these fields and keep the existing
             * TOTAL / ADVANCE / BALANCE behavior.
             */
            'is_quick_sale_invoice' => !empty($bill['is_quick_sale_invoice']),
            'paid_amount' => round(max(
                0,
                (float)($bill['paid_amount'] ?? ($bill['advance_amount'] ?? 0))
            ), 2),
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
        // Remarks intentionally omitted from the Proforma Bill PDF.

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
        float $maxSize = 10.0,
        string $style = '',
        string $align = 'L'
    ): void {
        $text = sbp_pdf_text($text);
        $size = $maxSize;
        do {
            $this->SetFont('Arial', $style, $size);
            if ($this->GetStringWidth($text) <= $w - 2 || $size <= 6.2) break;
            $size -= 0.3;
        } while ($size > 6.2);

        $this->SetXY($x, $y);
        $this->Cell($w, $h, $text, 0, 0, $align);
    }

    private function invoiceIdentity(array $bill): void
    {
        // "No." is part of the original artwork. Only its value is dynamic.
        $this->SetTextColor(40, 40, 40);
        $this->fitText(29, 91.1, 72, 6, (string)($bill['proforma_no'] ?? '-'), 10.0, 'B');

        // Cover the Tamil date caption while preserving the exact original UI.
        $this->SetFillColor(255, 199, 26);
        $this->Rect(151, 90.4, 45, 7.0, 'F');
        $this->SetTextColor(204, 26, 24);
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetXY(153, 91.0);
        $this->Cell(13, 5.8, 'Date:', 0, 0, 'L');
        $this->SetTextColor(40, 40, 40);
        $this->fitText(165.5, 91.0, 29.5, 5.8, sbp_date($bill['created_at'] ?? date('Y-m-d')), 9.2, 'B', 'L');
    }

    private function detailPair(
        float $y,
        string $leftLabel,
        string $leftValue,
        string $rightLabel,
        string $rightValue
    ): void {
        $this->SetTextColor(111, 31, 25);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetXY(14, $y + 0.7);
        $this->Cell(40, 3.2, sbp_pdf_text($leftLabel), 0, 0, 'L');
        $this->SetXY(107, $y + 0.7);
        $this->Cell(40, 3.2, sbp_pdf_text($rightLabel), 0, 0, 'L');

        $this->SetTextColor(35, 35, 35);
        $this->fitText(14, $y + 3.6, 88, 5.2, sbp_clean($leftValue), 9.5, 'B');
        $this->fitText(107, $y + 3.6, 88, 5.2, sbp_clean($rightValue), 9.5, 'B');
    }

    private function detailFull(float $y, string $label, string $value): void
    {
        $this->SetTextColor(111, 31, 25);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetXY(14, $y + 0.7);
        $this->Cell(55, 3.2, sbp_pdf_text($label), 0, 0, 'L');

        $this->SetTextColor(35, 35, 35);
        $this->fitText(14, $y + 3.6, 181, 5.2, sbp_clean($value), 9.5, 'B');
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
        $labelSize = $compact ? 7.6 : 8.5;
        $valueSize = $compact ? 8.2 : 8.8;

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
        $this->SetFont('Arial', 'B', 6.8);
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
        /*
         * CLEAN SINGLE GRID v21
         * ---------------------
         * The background artwork already contains old table lines. Drawing
         * new lines on top made the border look doubled/thick/unfamiliar.
         *
         * First cover the complete table area, then draw ONE fresh grid.
         */
        $tableX = 22.9;
        $tableY = 127.3;
        $tableRight = 185.9;
        $tableBottom = 263.0;
        $headerBottom = 136.6;

        // Clean body: removes all baked borders/artwork beneath the table.
        $this->SetFillColor(255, 198, 26);
        $this->Rect(
            $tableX,
            $tableY,
            $tableRight - $tableX,
            $tableBottom - $tableY,
            'F'
        );

        // Clean white header.
        $this->SetFillColor(255, 255, 255);
        $this->Rect(
            $tableX,
            $tableY,
            $tableRight - $tableX,
            $headerBottom - $tableY,
            'F'
        );

        $headers = [
            [22.9, 20.3, 'S.NO'],
            [43.2, 69.9, 'DESCRIPTION'],
            [113.1, 25.1, 'QTY'],
            [138.2, 22.7, 'RATE'],
            [160.9, 25.0, 'AMOUNT'],
        ];

        $this->SetTextColor(199, 27, 24);
        $this->SetFont('Arial', 'B', 10.5);

        foreach ($headers as $header) {
            $this->SetXY($header[0], 128.2);
            $this->Cell($header[1], 7.6, $header[2], 0, 0, 'C');
        }

        /*
         * Draw the vertical grid ONCE only.
         * Product/detail methods do not redraw these same lines.
         */
        $this->SetDrawColor(211, 31, 28);
        $this->SetLineWidth(0.22);

        foreach ([22.9, 43.2, 113.1, 138.2, 160.9, 185.9] as $x) {
            $this->Line($x, $tableY, $x, $tableBottom);
        }

        $this->Line($tableX, $tableY, $tableRight, $tableY);
        $this->Line($tableX, $headerBottom, $tableRight, $headerBottom);
        $this->Line($tableX, $tableBottom, $tableRight, $tableBottom);
    }

    private function compactText(string $text, int $maxChars = 240): string
    {
        $text = preg_replace('/\s+/', ' ', trim(sbp_pdf_text($text)));
        if (strlen($text) <= $maxChars) return $text;
        return rtrim(substr($text, 0, $maxChars - 3)) . '...';
    }

    private function titleCaseValue($value): string
    {
        $value = trim((string)$value);
        if ($value === '') return '';
        return ucwords(str_replace(['_', '-'], ' ', strtolower($value)));
    }

    private function wrappedLines(
        string $text,
        float $width,
        float $fontSize = 9.5,
        string $style = '',
        int $maxLines = 3
    ): array {
        $text = trim(preg_replace('/\s+/', ' ', sbp_pdf_text($text)));
        if ($text === '') return [];

        $this->SetFont('Arial', $style, $fontSize);
        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;

            if ($this->GetStringWidth($candidate) <= ($width - 1.5)) {
                $line = $candidate;
                continue;
            }

            if ($line !== '') {
                $lines[] = $line;
            }

            $line = $word;

            if (count($lines) >= $maxLines - 1) {
                break;
            }
        }

        if ($line !== '' && count($lines) < $maxLines) {
            $lines[] = $line;
        }

        if (count($words) > 0 && count($lines) === $maxLines) {
            $joined = implode(' ', $lines);
            if (strlen($joined) < strlen($text)) {
                $lastIndex = count($lines) - 1;
                $last = rtrim($lines[$lastIndex], ' .');

                while (
                    $last !== '' &&
                    $this->GetStringWidth($last . '...') > ($width - 1.5)
                ) {
                    $last = rtrim(substr($last, 0, -1));
                }

                $lines[$lastIndex] = $last . '...';
            }
        }

        return $lines;
    }

    private function itemDetailRows(array $item, string $orderType): array
    {
        $rows = [];

        $printingType = trim((string)($item['printing_name'] ?? ''));
        $printingSubType = trim((string)($item['sub_type_name'] ?? ''));

        /*
         * Printing Type and Sub Type are shown on separate lines.
         *
         * Example:
         * Printing: Screen Print
         * Sub Type: Single Colour
         */
        if ($printingType !== '') {
            $rows[] = [
                'text' => 'Printing: ' . $printingType,
                'amount' => null,
                'style' => 'printing',
                'max_lines' => 2,
            ];
        }

        if ($printingSubType !== '') {
            $rows[] = [
                'text' => 'Sub Type: ' . $printingSubType,
                'amount' => null,
                'style' => 'printing',
                'max_lines' => 2,
            ];
        }

        $specs1 = [];
        if (trim((string)($item['size_text'] ?? '')) !== '') {
            $specs1[] = 'Size: ' . trim((string)$item['size_text']);
        }
        if (trim((string)($item['gsm_thickness'] ?? '')) !== '') {
            $specs1[] = 'GSM / Thickness: ' . trim((string)$item['gsm_thickness']);
        }
        if (trim((string)($item['printing_side'] ?? '')) !== '') {
            $specs1[] = 'Side: ' . $this->titleCaseValue($item['printing_side']);
        }

        if ($specs1) {
            $rows[] = [
                'text' => implode(' | ', $specs1),
                'amount' => null,
                'style' => 'detail',
                'max_lines' => 2,
            ];
        }

        $specs2 = [];
        if (trim((string)($item['screening_type'] ?? '')) !== '') {
            $specs2[] = 'Scoring: ' . $this->titleCaseValue($item['screening_type']);
        }

        if ((int)($item['lamination_required'] ?? 0) === 1) {
            $lamination = trim((string)($item['lamination_type'] ?? ''));
            $specs2[] = 'Lamination: ' . ($lamination !== ''
                ? $this->titleCaseValue($lamination)
                : 'Required');
        }

        /*
         * Optional fields are printed only when they are actually included
         * in this Proforma. Do not print "Finishing: No".
         */
        if ((int)($item['finishing_required'] ?? 0) === 1) {
            $specs2[] = 'Finishing: Yes';
        }

        if ($specs2) {
            $rows[] = [
                'text' => implode(' | ', $specs2),
                'amount' => null,
                'style' => 'detail',
                'max_lines' => 2,
            ];
        }

        if (trim((string)($item['price_slab_text'] ?? '')) !== '') {
            $rows[] = [
                'text' => 'Pricing Slab: ' . trim((string)$item['price_slab_text']),
                'amount' => null,
                'style' => 'detail',
                'max_lines' => 2,
            ];
        }

        $plate = round(max(0, (float)($item['plate_charge'] ?? 0)), 2);
        $printing = round(max(0, (float)($item['item_printing_charge'] ?? 0)), 2);
        $package = round(max(0, (float)($item['item_package_charge'] ?? 0)), 2);
        $additional = round(max(0, (float)($item['item_additional_charge'] ?? 0)), 2);

        if ($orderType === 'customized') {
            if ($plate > 0.009) {
                $rows[] = [
                    'text' => 'Plate Charge',
                    'amount' => $plate,
                    'style' => 'charge',
                    'max_lines' => 1,
                ];
            }

            if ($printing > 0.009) {
                $rows[] = [
                    'text' => 'Printing Charge',
                    'amount' => $printing,
                    'style' => 'charge',
                    'max_lines' => 1,
                ];
            }

            if ($package > 0.009) {
                $rows[] = [
                    'text' => 'Package Charge',
                    'amount' => $package,
                    'style' => 'charge',
                    'max_lines' => 1,
                ];
            }

            if ($additional > 0.009) {
                $rows[] = [
                    'text' => 'Additional Charge',
                    'amount' => $additional,
                    'style' => 'charge',
                    'max_lines' => 1,
                ];
            }

            $baseAmount = round((float)(
                $item['amount']
                ?? ((float)($item['qty'] ?? 0) * (float)($item['rate'] ?? 0))
            ), 2);

            $lineTotal = round(
                $baseAmount + $plate + $printing + $package + $additional,
                2
            );

            if ($plate + $printing + $package + $additional > 0.009) {
                $rows[] = [
                    'text' => 'Product Total (including charges)',
                    'amount' => $lineTotal,
                    'style' => 'line_total',
                    'max_lines' => 1,
                ];
            }
        }

        return $rows;
    }

    private function productRecordDetailRows(array $item, string $orderType): array
    {
        $details = [];

        foreach ($this->itemDetailRows($item, $orderType) as $row) {
            if ($row['amount'] === null) {
                $details[] = $row;
            }
        }

        return $details;
    }

    private function productChargeRows(array $item, string $orderType): array
    {
        $charges = [];

        foreach ($this->itemDetailRows($item, $orderType) as $row) {
            if ($row['amount'] !== null) {
                $charges[] = $row;
            }
        }

        return $charges;
    }

    private function productRecordLayout(array $item, string $orderType): array
    {
        $descriptionWidth = 66.2;
        $product = sbp_clean(
            ($item['item_name'] ?? '')
            ?: ($item['master_product_name'] ?? ''),
            'Item'
        );

        $entries = [];

        $productLines = $this->wrappedLines(
            $product,
            $descriptionWidth,
            9.5,
            '',
            3
        );

        $entries[] = [
            'lines' => $productLines ?: [$product],
            'font_size' => 9.5,
            'style' => '',
            'line_height' => 4.2,
            'gap_after' => 1.4,
            'kind' => 'product',
        ];

        foreach ($this->productRecordDetailRows($item, $orderType) as $row) {
            $text = trim((string)($row['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $maxLines = max(1, (int)($row['max_lines'] ?? 2));

            /*
             * All printing/scoring/size/lamination/finishing information stays
             * inside this same Product record. Long values such as
             * "Multicolor Offset Print" wrap naturally inside DESCRIPTION.
             */
            $lines = $this->wrappedLines(
                $text,
                $descriptionWidth,
                9.5,
                '',
                max(2, $maxLines)
            );

            $entries[] = [
                'lines' => $lines ?: [$text],
                'font_size' => 9.5,
                'style' => '',
                'line_height' => 4.15,
                'gap_after' => 1.05,
                'kind' => 'detail',
            ];
        }

        $contentHeight = 0.0;

        foreach ($entries as $entry) {
            $contentHeight += max(1, count($entry['lines']))
                * (float)$entry['line_height'];
            $contentHeight += (float)$entry['gap_after'];
        }

        /*
         * Minimum record height for simple products; otherwise grow based on
         * the wrapped description/detail content.
         */
        $height = max(10.4, $contentHeight + 1.6);

        return [
            'height' => $height,
            'entries' => $entries,
        ];
    }

    private function detailRowHeight(array $row): float
    {
        $fontSize = 9.5;
        $maxLines = max(1, (int)($row['max_lines'] ?? 1));
        $style = (string)($row['style'] ?? 'detail');

        /*
         * Every detail is wrapped strictly inside DESCRIPTION.
         * Charge values are rendered separately inside AMOUNT.
         */
        $descriptionWidth = 66.2;

        $lines = $this->wrappedLines(
            (string)($row['text'] ?? ''),
            $descriptionWidth,
            $fontSize,
            $style === 'line_total' ? 'B' : '',
            $maxLines
        );

        $lineCount = max(1, count($lines));
        return max(6.4, ($lineCount * 4.4) + 1.4);
    }

    private function itemBlockHeight(array $item, string $orderType): float
    {
        $record = $this->productRecordLayout($item, $orderType);
        $height = (float)$record['height'];

        /*
         * Descriptive details are already part of the Product record.
         * Only amount-bearing charge rows remain below it.
         */
        foreach ($this->productChargeRows($item, $orderType) as $row) {
            $height += $this->detailRowHeight($row);
        }

        return $height + 1.0;
    }

    private function pageItemGroups(
        array $items,
        string $orderType,
        array $summary
    ): array {
        if (!$items) return [[]];

        $startY = 136.6;
        $summaryBottom = 263.0;
        $summaryGap = 3.0;
        $summaryRowH = 5.85;
        $summaryRows = $this->summaryRows($summary);
        $summaryHeight = count($summaryRows) * $summaryRowH;

        /*
         * Last page gets a protected area for Summary.
         * Earlier pages can use much more of the item table.
         */
        $lastPageMaxHeight = max(
            24.0,
            ($summaryBottom - $summaryHeight - $summaryGap) - $startY
        );

        $regularPageMaxHeight = 106.0;

        /*
         * Build the last page backwards so there is always enough room for
         * the dynamic Summary, regardless of how many products are entered.
         */
        $lastPage = [];
        $lastUsed = 0.0;
        $lastStartIndex = count($items);

        for ($i = count($items) - 1; $i >= 0; $i--) {
            $height = $this->itemBlockHeight($items[$i], $orderType);

            if ($lastPage && ($lastUsed + $height) > $lastPageMaxHeight) {
                break;
            }

            array_unshift($lastPage, $items[$i]);
            $lastUsed += $height;
            $lastStartIndex = $i;
        }

        $remaining = array_slice($items, 0, $lastStartIndex);
        $pages = [];
        $page = [];
        $used = 0.0;

        foreach ($remaining as $item) {
            $height = $this->itemBlockHeight($item, $orderType);

            if ($page && ($used + $height) > $regularPageMaxHeight) {
                $pages[] = $page;
                $page = [];
                $used = 0.0;
            }

            $page[] = $item;
            $used += $height;
        }

        if ($page) {
            $pages[] = $page;
        }

        if ($lastPage) {
            $pages[] = $lastPage;
        }

        return $pages ?: [[]];
    }

    private function horizontalSeparator(float $y, bool $strong = false): void
    {
        $this->SetDrawColor(211, 31, 28);
        $this->SetLineWidth(0.22);
        $this->Line(22.9, $y, 185.9, $y);
    }

    private function itemMainRow(
        float $y,
        array $item,
        int $serial,
        string $orderType
    ): float {
        $qty = (float)($item['qty'] ?? 0);
        $rate = (float)($item['rate'] ?? 0);
        $amount = round((float)($item['amount'] ?? ($qty * $rate)), 2);

        $record = $this->productRecordLayout($item, $orderType);
        $height = (float)$record['height'];
        $entries = (array)$record['entries'];

        /*
         * One Product = one table record.
         *
         * DESCRIPTION contains:
         * Product Name
         * Printing
         * Sub Type
         * Size / GSM / Side
         * Scoring / Lamination / Finishing
         *
         * Customer-requested separator lines are drawn only inside the
         * DESCRIPTION cell between each logical detail line. QTY/RATE/AMOUNT
         * remain one continuous cell for the complete Product record.
         */
        $cursorY = $y + 1.0;
        $entryCount = count($entries);

        foreach ($entries as $entryIndex => $entry) {
            $lines = (array)($entry['lines'] ?? []);
            $fontSize = (float)($entry['font_size'] ?? 9.5);
            $fontStyle = (string)($entry['style'] ?? '');
            $lineHeight = (float)($entry['line_height'] ?? 4.15);
            $gapAfter = (float)($entry['gap_after'] ?? 1.0);

            $this->SetTextColor(45, 37, 32);
            $this->SetFont('Arial', $fontStyle, $fontSize);
            $this->SetXY(45.0, $cursorY);
            $this->MultiCell(
                66.2,
                $lineHeight,
                implode("\n", $lines),
                0,
                'L'
            );

            $contentHeight = max(1, count($lines)) * $lineHeight;
            $cursorY += $contentHeight + $gapAfter;

            /*
             * Thin separator after every logical DESCRIPTION entry except the
             * final one. It stays strictly inside DESCRIPTION so this does not
             * turn Printing/Sub Type/Size/etc. into separate table records.
             */
            if ($entryIndex < $entryCount - 1) {
                $separatorY = $cursorY - ($gapAfter / 2);
                $this->SetDrawColor(211, 31, 28);
                $this->SetLineWidth(0.12);
                $this->Line(43.2, $separatorY, 113.1, $separatorY);
            }
        }

        /*
         * S.No stays near the Product Name.
         * QTY / RATE / AMOUNT are vertically centered in the entire Product
         * record, including all Printing/Sub Type/Size/Scoring details.
         */
        $this->SetTextColor(45, 37, 32);
        $this->SetFont('Arial', '', 10.5);

        $this->SetXY(22.9, $y + 1.0);
        $this->Cell(20.3, 8.0, (string)$serial, 0, 0, 'C');

        $valueCellH = 8.0;
        $valueY = $y + max(0.8, (($height - $valueCellH) / 2));

        $this->SetXY(113.1, $valueY);
        $this->Cell(25.1, $valueCellH, sbp_qty($qty), 0, 0, 'C');

        $this->SetXY(138.2, $valueY);
        $this->Cell(22.2, $valueCellH, sbp_number($rate), 0, 0, 'R');

        $this->SetXY(160.9, $valueY);
        $this->Cell(24.2, $valueCellH, sbp_number($amount), 0, 0, 'R');

        /*
         * One normal full-width line closes the complete Product record.
         */
        $this->SetDrawColor(211, 31, 28);
        $this->SetLineWidth(0.22);
        $this->Line(22.9, $y + $height, 185.9, $y + $height);

        return $height;
    }

    private function itemChildRow(float $y, array $row): float
    {
        $style = (string)($row['style'] ?? 'charge');
        $height = $this->detailRowHeight($row);
        $fontStyle = $style === 'line_total' ? 'B' : '';

        /*
         * Only amount-bearing rows reach this renderer now:
         * Plate Charge / Printing Charge / Package Charge /
         * Additional Charge / Product Total.
         */
        $descriptionWidth = 66.2;

        $this->SetTextColor(55, 45, 40);
        $this->SetFont('Arial', $fontStyle, 9.5);

        $lines = $this->wrappedLines(
            (string)($row['text'] ?? ''),
            $descriptionWidth,
            9.5,
            $fontStyle,
            max(1, (int)($row['max_lines'] ?? 1))
        );

        $this->SetXY(45.0, $y + 1.0);
        $this->MultiCell(
            $descriptionWidth,
            4.4,
            implode("\n", $lines ?: [(string)($row['text'] ?? '')]),
            0,
            'L'
        );

        if ($row['amount'] !== null) {
            $this->SetTextColor(45, 37, 32);
            $this->SetFont(
                'Arial',
                $style === 'line_total' ? 'B' : '',
                9.5
            );
            $this->SetXY(160.9, $y + 0.8);
            $this->Cell(
                24.2,
                min(6.2, $height - 0.8),
                sbp_number($row['amount']),
                0,
                0,
                'R'
            );
        }

        $this->SetDrawColor(211, 31, 28);
        $this->SetLineWidth(0.22);
        $this->Line(22.9, $y + $height, 185.9, $y + $height);

        return $height;
    }

    private function itemBlock(float $y, array $item, int $serial, string $orderType): float
    {
        $start = $y;

        // Product + all descriptive printing/scoring details in ONE record.
        $y += $this->itemMainRow($y, $item, $serial, $orderType);

        // Only monetary charge rows remain as separate rows.
        foreach ($this->productChargeRows($item, $orderType) as $row) {
            $y += $this->itemChildRow($y, $row);
        }

        return ($y - $start) + 1.0;
    }

    private function summaryRows(array $summary): array
    {
        $extra = (float)$summary['extra'];
        $printing = (float)$summary['printing'];
        $packing = (float)$summary['packing'];
        $discount = (float)$summary['discount'];
        $subTotal = (float)$summary['sub_total'];
        $final = (float)$summary['final'];

        $hasAmountAdjustment =
            $extra > 0.009
            || $printing > 0.009
            || $packing > 0.009
            || $discount > 0.009
            || abs($subTotal - $final) > 0.009;

        $rows = [];

        if ($hasAmountAdjustment) {
            $rows[] = ['PRODUCT SUBTOTAL', $subTotal, false, 'normal'];
        }

        if ($extra > 0.009) {
            $rows[] = ['PLATE / ADDITIONAL', $extra, false, 'charge'];
        }

        if ($printing > 0.009) {
            $rows[] = ['PRINTING CHARGE', $printing, false, 'charge'];
        }

        if ($packing > 0.009) {
            $rows[] = ['PACKAGE CHARGE', $packing, false, 'charge'];
        }

        if ($discount > 0.009) {
            $rows[] = ['DISCOUNT (-)', $discount, false, 'discount'];
        }

        if (
            (float)$summary['gst_percent'] > 0.009
            && (float)$summary['gst_amount'] > 0.009
        ) {
            $rows[] = ['TAXABLE VALUE', (float)$summary['taxable'], false, 'tax'];
            $rows[] = [
                'GST ' . sbp_number($summary['gst_percent']) . '% (INCL.)',
                (float)$summary['gst_amount'],
                false,
                'tax'
            ];
        }

        /*
         * QUICK SALE INVOICE
         * ------------------
         * Always show:
         * TOTAL AMOUNT
         * PAID
         * BALANCE
         *
         * BALANCE stays visible even when the value is 0.00.
         * Normal Proforma PDFs keep their existing behavior below.
         */
        if (!empty($summary['is_quick_sale_invoice'])) {
            $paid = round(max(0, (float)($summary['paid_amount'] ?? 0)), 2);
            $balance = round(max(0, $final - $paid), 2);

            $rows[] = ['TOTAL AMOUNT', $final, true, 'total'];
            $rows[] = ['PAID', $paid, false, 'advance'];
            $rows[] = ['BALANCE', $balance, true, 'balance'];

            return $rows;
        }

        $rows[] = ['TOTAL', $final, true, 'total'];

        if ((float)$summary['advance'] > 0.009) {
            $rows[] = ['ADVANCE', (float)$summary['advance'], false, 'advance'];
        }

        if ((float)$summary['balance'] > 0.009) {
            $rows[] = ['BALANCE', (float)$summary['balance'], true, 'balance'];
        }

        return $rows;
    }

    private function detailedTotals(array $summary): void
    {
        $rows = $this->summaryRows($summary);

        $boxX = 113.1;
        $labelW = 47.8;
        $valueW = 25.0;
        $boxW = $labelW + $valueW;
        $rowH = 5.85;

        /*
         * Slightly lower than v16.
         * Pagination above already reserves this exact space.
         */
        $bottom = 263.0;
        $top = $bottom - (count($rows) * $rowH);

        $this->SetDrawColor(211, 31, 28);
        $this->SetLineWidth(0.28);

        foreach ($rows as $index => $row) {
            [$label, $amount, $strong, $type] = $row;
            $y = $top + ($index * $rowH);

            $this->SetFillColor(255, 198, 26);
            $this->Rect($boxX, $y, $boxW, $rowH, 'F');

            $this->SetLineWidth(0.22);
            $this->Line($boxX, $y, $boxX + $boxW, $y);
            $this->Line(
                $boxX + $labelW,
                $y,
                $boxX + $labelW,
                $y + $rowH
            );

            $this->SetTextColor(204, 26, 24);
            $this->SetFont('Arial', 'B', $strong ? 8.5 : 7.8);
            $this->SetXY($boxX + 0.8, $y + 0.20);
            $this->Cell(
                $labelW - 1.6,
                $rowH - 0.2,
                sbp_pdf_text($label),
                0,
                0,
                'R'
            );

            $this->SetTextColor(45, 37, 32);
            $this->SetFont('Arial', $strong ? 'B' : '', $strong ? 8.8 : 8.2);
            $this->SetXY($boxX + $labelW + 0.4, $y + 0.20);
            $this->Cell(
                $valueW - 0.9,
                $rowH - 0.2,
                sbp_number($amount),
                0,
                0,
                'R'
            );
        }

        $this->Line($boxX, $bottom, $boxX + $boxW, $bottom);
        $this->Rect($boxX, $top, $boxW, count($rows) * $rowH);
    }

    public function draw(array $bill, array $items): void
    {
        $summary = sbp_amount_summary($bill, $items);
        $orderType = strtolower(trim((string)($bill['order_type'] ?? 'readymade')));
        $pages = $this->pageItemGroups($items, $orderType, $summary);
        $serial = 1;

        foreach ($pages as $pageIndex => $pageItems) {
            $this->AddPage('P', 'A4');
            $this->invoiceIdentity($bill);
            $this->customerEventDetails($bill, $items);
            $this->tableFrame();

            $rowY = 136.6;

            foreach ($pageItems as $item) {
                if (!empty($item)) {
                    $rowY += $this->itemBlock(
                        $rowY,
                        $item,
                        $serial++,
                        $orderType
                    );
                }
            }

            if ($pageIndex === count($pages) - 1) {
                $this->detailedTotals($summary);
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
        $isCurrentLayout = strpos(basename($existingPath), '_detail_separator_lines_v25_') !== false;
        if (!$force && $isCurrentLayout && $existingPath !== '' && is_file($root . '/' . ltrim($existingPath, '/'))) {
            return ['path' => $existingPath, 'url' => sbp_base_url($conn) . '/' . ltrim($existingPath, '/'), 'filename' => basename($existingPath)];
        }
        $dir = $root . '/uploads/proforma_bills';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Unable to create uploads/proforma_bills folder.');
        $proformaNo = (string)($bill['proforma_no'] ?? ('PROFORMA_' . $id));
        $fileName = sbp_safe_filename($proformaNo) . '_detail_separator_lines_v25_' . date('YmdHis') . '.pdf';
        $abs = $dir . '/' . $fileName;
        $rel = 'uploads/proforma_bills/' . $fileName;
        $pdf = new SubhikshaProformaInvoicePDF($background);
        $pdf->SetTitle('Proforma Bill - ' . $proformaNo);
        $pdf->SetAuthor('Subhiksha Cards');
        $pdf->SetCreator('Subhiksha Cards Detail Separator Lines v25');
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
        $pdf->SetCreator('Subhiksha Cards Detail Separator Lines v25');
        $pdf->draw($data['bill'], $data['items']);

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Subhiksha-Proforma-Layout: detail-separator-lines-v25');
        $pdf->Output($download ? 'D' : 'I', sbp_safe_filename($proformaNo) . '.pdf');
    }
}
