<?php
/**
 * includes/proforma-bill-pdf.php
 * Formal A4 black & white FPDF proforma invoice for Subhiksha Cards.
 * FPDF expected path: /assets/libs/fpdf/fpdf.php
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
            $stmt = $conn->prepare("\n                SELECT\n                    pb.*,\n                    ps.status_name,\n                    ft.function_name,\n                    q.quotation_no,\n                    e.enquiry_no,\n                    c.address AS customer_master_address,\n                    c.gst_number AS customer_master_gst\n                FROM proforma_bills pb\n                LEFT JOIN proforma_statuses ps ON ps.id = pb.proforma_status_id\n                LEFT JOIN function_types ft ON ft.id = pb.function_type_id\n                LEFT JOIN quotations q ON q.id = pb.quotation_id\n                LEFT JOIN enquiries e ON e.id = pb.enquiry_id\n                LEFT JOIN customers c ON c.id = pb.customer_id\n                WHERE pb.id = ?\n                LIMIT 1\n            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $bill = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$bill) return null;

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

class SubhikshaProformaInvoicePDF extends FPDF
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
        $this->labelValue($x + 9, $boxY + 32, 25, 54, 'GST', ($bill['gst_number'] ?? '') ?: ($bill['customer_master_gst'] ?? '-'), 8);
        $address = ($bill['billing_address'] ?? '') ?: ($bill['customer_master_address'] ?? '-');
        $this->fitCell($x + 9, $boxY + 40, 25, 5, 'Address', 8, 'B');
        $this->multi($x + 34, $boxY + 40, 52, 3.8, $address ?: '-', 7);

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
        if (!$force && $existingPath !== '' && is_file($root . '/' . ltrim($existingPath, '/'))) {
            return ['path' => $existingPath, 'url' => sbp_base_url($conn) . '/' . ltrim($existingPath, '/'), 'filename' => basename($existingPath)];
        }
        $dir = $root . '/uploads/proforma_bills';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Unable to create uploads/proforma_bills folder.');
        $proformaNo = (string)($bill['proforma_no'] ?? ('PROFORMA_' . $id));
        $fileName = sbp_safe_filename($proformaNo) . '_' . date('YmdHis') . '.pdf';
        $abs = $dir . '/' . $fileName;
        $rel = 'uploads/proforma_bills/' . $fileName;
        $pdf = new SubhikshaProformaInvoicePDF();
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
    function sbp_output_proforma_pdf_inline(mysqli $conn, int $id): void
    {
        sbp_load_fpdf();
        $data = sbp_get_proforma_data($conn, $id);
        if (!$data) throw new RuntimeException('Proforma bill not found.');
        $pdf = new SubhikshaProformaInvoicePDF();
        $pdf->draw($data['bill'], $data['items']);
        $proformaNo = (string)($data['bill']['proforma_no'] ?? ('PROFORMA_' . $id));
        $pdf->Output('I', sbp_safe_filename($proformaNo) . '.pdf');
    }
}
