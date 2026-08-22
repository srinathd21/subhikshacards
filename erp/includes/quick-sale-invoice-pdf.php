<?php
/**
 * includes/quick-sale-invoice-pdf.php
 *
 * Dedicated Quick Sale / Cash Invoice renderer.
 * This file intentionally DOES NOT use the Proforma layout.
 *
 * Required:
 * - FPDF under assets/libs/fpdf/
 * - optional logo: assets/img/subhiksha-quick-sale-logo.png
 */

if (!function_exists('qsi_load_fpdf')) {
    function qsi_load_fpdf(): void
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

        throw new RuntimeException(
            'FPDF library not found. Expected assets/libs/fpdf/fpdf.php'
        );
    }
}

if (!function_exists('qsi_text')) {
    function qsi_text($value): string
    {
        $text = trim((string)$value);
        if ($text === '') return '';

        $text = str_replace(
            ['₹', '–', '—', '’', '“', '”'],
            ['Rs.', '-', '-', "'", '"', '"'],
            $text
        );

        $converted = @iconv(
            'UTF-8',
            'windows-1252//TRANSLIT//IGNORE',
            $text
        );

        return $converted !== false ? $converted : $text;
    }
}

if (!function_exists('qsi_money')) {
    function qsi_money($value): string
    {
        return number_format((float)$value, 2);
    }
}

if (!function_exists('qsi_date')) {
    function qsi_date($value): string
    {
        return !empty($value)
            ? date('d-m-Y', strtotime((string)$value))
            : '-';
    }
}

qsi_load_fpdf();

class SubhikshaQuickSaleCashInvoicePDF extends FPDF
{
    private string $logoPath;
    private float $left = 15.0;
    private float $right = 195.0;
    private float $usable = 180.0;

    public function __construct(string $logoPath = '')
    {
        parent::__construct('P', 'mm', 'A4');
        $this->logoPath = $logoPath;
        $this->SetMargins($this->left, 10, 15);
        $this->SetAutoPageBreak(false);
        $this->SetTitle('Subhiksha Cards - Cash Invoice');
        $this->SetAuthor('Subhiksha Cards');
        $this->SetCreator('Subhiksha Cards ERP');
    }

    private function navy(): void
    {
        $this->SetTextColor(11, 59, 120);
    }

    private function orange(): void
    {
        $this->SetTextColor(230, 111, 0);
    }

    private function dark(): void
    {
        $this->SetTextColor(31, 41, 55);
    }

    private function muted(): void
    {
        $this->SetTextColor(100, 116, 139);
    }

    private function lineColor(): void
    {
        $this->SetDrawColor(226, 232, 240);
    }

    private function fillSoft(): void
    {
        $this->SetFillColor(248, 250, 252);
    }

    private function fillNavy(): void
    {
        $this->SetFillColor(11, 59, 120);
    }

    private function fillOrangeSoft(): void
    {
        $this->SetFillColor(255, 247, 237);
    }

    private function splitText(string $text, float $width): array
    {
        $text = trim(preg_replace('/\s+/', ' ', qsi_text($text)));
        if ($text === '') return [''];

        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;

            if ($this->GetStringWidth($candidate) <= $width) {
                $line = $candidate;
                continue;
            }

            if ($line !== '') {
                $lines[] = $line;
                $line = '';
            }

            // Very long single token.
            if ($this->GetStringWidth($word) > $width) {
                $buffer = '';
                $chars = str_split($word);

                foreach ($chars as $ch) {
                    $test = $buffer . $ch;
                    if ($buffer !== '' && $this->GetStringWidth($test) > $width) {
                        $lines[] = $buffer;
                        $buffer = $ch;
                    } else {
                        $buffer = $test;
                    }
                }

                $line = $buffer;
            } else {
                $line = $word;
            }
        }

        if ($line !== '') $lines[] = $line;

        return $lines ?: [''];
    }

    private function writeLines(
        float $x,
        float $y,
        float $w,
        array $lines,
        float $lineH = 4.4,
        string $align = 'L'
    ): void {
        foreach ($lines as $i => $line) {
            $this->SetXY($x, $y + ($i * $lineH));
            $this->Cell($w, $lineH, $line, 0, 0, $align);
        }
    }

    private function drawPageHeader(array $sale, bool $continued = false): float
    {
        $this->SetDrawColor(230, 111, 0);
        $this->SetLineWidth(1.2);
        $this->Line($this->left, 9.5, $this->right, 9.5);

        if ($this->logoPath !== '' && is_file($this->logoPath)) {
            try {
                $this->Image($this->logoPath, 15, 13, 44);
            } catch (Throwable $e) {
                // Logo is optional. Continue with text branding.
            }
        }

        $this->navy();
        $this->SetFont('Arial', 'B', 16);
        $this->SetXY(15, 17);
        if ($this->logoPath === '' || !is_file($this->logoPath)) {
            $this->Cell(75, 8, 'SUBHIKSHA CARDS', 0, 0, 'L');
        }

        $this->orange();
        $this->SetFont('Arial', 'B', 18);
        $this->SetXY(115, 15);
        $this->Cell(80, 8, $continued ? 'CASH INVOICE' : 'CASH INVOICE', 0, 0, 'R');

        $this->muted();
        $this->SetFont('Arial', '', 7.8);
        $this->SetXY(110, 24);
        $this->Cell(85, 4, 'Quick Sale Invoice', 0, 0, 'R');

        $this->SetXY(85, 29);
        $this->Cell(
            110,
            4,
            'Phone: 72006 02020 / 72007 02020  |  GSTIN: 33AMRPA4225G1ZD',
            0,
            0,
            'R'
        );

        $this->lineColor();
        $this->SetLineWidth(0.3);
        $this->Line($this->left, 36, $this->right, 36);

        if ($continued) {
            $this->dark();
            $this->SetFont('Arial', 'B', 8.5);
            $this->SetXY(15, 40);
            $this->Cell(90, 5, 'Invoice: ' . qsi_text($sale['sale_no'] ?? '-'), 0, 0, 'L');

            $this->SetFont('Arial', '', 8);
            $this->SetXY(105, 40);
            $this->Cell(
                90,
                5,
                'Date: ' . qsi_date($sale['created_at'] ?? ''),
                0,
                0,
                'R'
            );

            return 49.0;
        }

        return 41.0;
    }

    private function drawInfoSection(array $sale, array $payment): float
    {
        $y = 41.0;

        $this->lineColor();
        $this->fillSoft();
        $this->SetLineWidth(0.3);
        $this->Rect(15, $y, 113, 35, 'DF');
        $this->Rect(132, $y, 63, 35, 'DF');

        // BILL TO
        $this->navy();
        $this->SetFont('Arial', 'B', 8);
        $this->SetXY(19, $y + 3);
        $this->Cell(70, 4, 'BILL TO', 0, 0, 'L');

        $this->dark();
        $this->SetFont('Arial', 'B', 10.5);
        $customer = trim((string)($sale['customer_name'] ?? ''));
        $customer = $customer !== '' ? $customer : 'Walk-in Customer';
        $this->SetXY(19, $y + 8);
        $this->Cell(104, 5, qsi_text($customer), 0, 0, 'L');

        $mobile = trim((string)($sale['mobile'] ?? ''));
        $this->SetFont('Arial', '', 8.4);
        $this->SetXY(19, $y + 14);
        $this->Cell(
            104,
            4.5,
            'Mobile: ' . qsi_text($mobile !== '' ? $mobile : '-'),
            0,
            0,
            'L'
        );

        $address = trim((string)($sale['address'] ?? ''));
        $addressLines = $this->splitText(
            'Address: ' . ($address !== '' ? $address : '-'),
            101
        );
        $addressLines = array_slice($addressLines, 0, 3);
        $this->SetFont('Arial', '', 7.8);
        $this->writeLines(19, $y + 20, 104, $addressLines, 4.0);

        // INVOICE INFO
        $this->navy();
        $this->SetFont('Arial', 'B', 8);
        $this->SetXY(136, $y + 3);
        $this->Cell(55, 4, 'INVOICE DETAILS', 0, 0, 'L');

        $this->dark();
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetXY(136, $y + 9);
        $this->Cell(21, 4.5, 'Invoice No', 0, 0, 'L');
        $this->SetFont('Arial', '', 8.5);
        $this->SetXY(157, $y + 9);
        $this->Cell(34, 4.5, qsi_text($sale['sale_no'] ?? '-'), 0, 0, 'R');

        $this->SetFont('Arial', 'B', 8.5);
        $this->SetXY(136, $y + 15);
        $this->Cell(21, 4.5, 'Date', 0, 0, 'L');
        $this->SetFont('Arial', '', 8.5);
        $this->SetXY(157, $y + 15);
        $this->Cell(34, 4.5, qsi_date($sale['created_at'] ?? ''), 0, 0, 'R');

        $status = strtoupper((string)($payment['status'] ?? 'UNPAID'));
        if ($status === 'PAID') {
            $this->SetFillColor(220, 252, 231);
            $this->SetTextColor(22, 101, 52);
        } elseif ($status === 'PARTIAL') {
            $this->SetFillColor(255, 247, 237);
            $this->SetTextColor(154, 52, 18);
        } else {
            $this->SetFillColor(254, 226, 226);
            $this->SetTextColor(153, 27, 27);
        }

        $this->SetFont('Arial', 'B', 8);
        $this->SetXY(136, $y + 23);
        $this->Cell(55, 7, qsi_text($status), 0, 0, 'C', true);

        return 82.0;
    }

    private function drawTableHeader(float $y): float
    {
        $x = 15.0;
        $cols = [
            [12.0, 'S.NO', 'C'],
            [83.0, 'PRODUCT / DESCRIPTION', 'L'],
            [22.0, 'QTY', 'C'],
            [28.0, 'RATE', 'R'],
            [35.0, 'AMOUNT', 'R'],
        ];

        $this->fillNavy();
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(11, 59, 120);
        $this->SetFont('Arial', 'B', 8.4);

        foreach ($cols as [$w, $label, $align]) {
            $this->SetXY($x, $y);
            $this->Cell($w, 8, $label, 1, 0, $align, true);
            $x += $w;
        }

        return $y + 8;
    }

    private function itemRowHeight(string $product): float
    {
        $this->SetFont('Arial', '', 8.3);
        $lines = $this->splitText($product, 79);
        return max(8.0, count($lines) * 4.3 + 3.0);
    }

    private function drawItemRow(float $y, int $index, array $item): float
    {
        $x = 15.0;
        $product = trim((string)($item['product_name'] ?? $item['item_name'] ?? ''));
        $rowH = $this->itemRowHeight($product);

        $this->SetDrawColor(226, 232, 240);
        $this->SetFillColor(255, 255, 255);
        $this->SetTextColor(31, 41, 55);
        $this->SetLineWidth(0.25);

        $widths = [12.0, 83.0, 22.0, 28.0, 35.0];

        foreach ($widths as $w) {
            $this->Rect($x, $y, $w, $rowH, 'D');
            $x += $w;
        }

        $this->SetFont('Arial', '', 8.3);

        $this->SetXY(15, $y + (($rowH - 4.5) / 2));
        $this->Cell(12, 4.5, (string)$index, 0, 0, 'C');

        $lines = $this->splitText($product !== '' ? $product : '-', 79);
        $textH = count($lines) * 4.3;
        $textY = $y + max(1.5, ($rowH - $textH) / 2);
        $this->writeLines(29, $textY, 79, $lines, 4.3, 'L');

        $qty = (float)($item['qty'] ?? 0);
        $rate = (float)($item['rate'] ?? 0);
        $amount = (float)($item['amount'] ?? ($qty * $rate));

        $this->SetXY(110, $y + (($rowH - 4.5) / 2));
        $this->Cell(22, 4.5, rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'), 0, 0, 'C');

        $this->SetXY(132, $y + (($rowH - 4.5) / 2));
        $this->Cell(28, 4.5, qsi_money($rate), 0, 0, 'R');

        $this->SetFont('Arial', 'B', 8.3);
        $this->SetXY(160, $y + (($rowH - 4.5) / 2));
        $this->Cell(35, 4.5, qsi_money($amount), 0, 0, 'R');

        return $y + $rowH;
    }

    private function drawPaymentSummary(float $y, array $sale, array $payment): float
    {
        $minH = 44.0;

        if ($y + $minH > 255) {
            $this->AddPage();
            $this->drawPageHeader($sale, true);
            $y = 52.0;
        }

        $leftW = 105.0;
        $rightX = 124.0;
        $rightW = 71.0;

        $this->lineColor();
        $this->fillSoft();
        $this->Rect(15, $y, $leftW, 40, 'DF');

        $this->navy();
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetXY(19, $y + 3);
        $this->Cell(90, 4.5, 'PAYMENT DETAILS', 0, 0, 'L');

        $this->dark();
        $this->SetFont('Arial', '', 8.2);

        $mode = trim((string)($payment['mode_label'] ?? 'Unpaid'));
        $this->SetXY(19, $y + 10);
        $this->Cell(96, 4.3, 'Payment Mode: ' . qsi_text($mode), 0, 0, 'L');

        $cash = (float)($payment['cash_amount'] ?? 0);
        $upi = (float)($payment['upi_amount'] ?? 0);
        $ret = (float)($payment['return_amount'] ?? 0);

        $this->SetXY(19, $y + 16);
        $this->Cell(47, 4.3, 'Cash: Rs. ' . qsi_money($cash), 0, 0, 'L');

        $this->SetXY(66, $y + 16);
        $this->Cell(49, 4.3, 'UPI: Rs. ' . qsi_money($upi), 0, 0, 'L');

        if ($ret > 0.009) {
            $this->SetXY(19, $y + 22);
            $this->Cell(47, 4.3, 'Cash Return: Rs. ' . qsi_money($ret), 0, 0, 'L');
        }

        $upiRef = trim((string)($payment['upi_reference'] ?? ''));
        if ($upiRef !== '') {
            $refLines = $this->splitText('UPI Ref: ' . $upiRef, 92);
            $refLines = array_slice($refLines, 0, 2);
            $this->SetFont('Arial', '', 7.7);
            $this->writeLines(19, $y + 28, 94, $refLines, 4.0);
        }

        // Amount Summary
        $this->SetDrawColor(226, 232, 240);
        $this->Rect($rightX, $y, $rightW, 40, 'D');

        $total = (float)($sale['total_amount'] ?? 0);
        $paid = (float)($payment['paid_amount'] ?? 0);
        $balance = max(0, (float)($payment['balance_amount'] ?? ($total - $paid)));

        $labelW = 37.0;
        $valueW = $rightW - $labelW;

        $rows = [
            ['TOTAL AMOUNT', $total, false],
            ['PAID', $paid, false],
            ['BALANCE', $balance, true],
        ];

        $rowY = $y;
        foreach ($rows as $i => [$label, $value, $highlight]) {
            $h = $i === 2 ? 14.0 : 13.0;

            if ($highlight) {
                if ($balance > 0.009) {
                    $this->SetFillColor(254, 226, 226);
                    $this->SetTextColor(153, 27, 27);
                } else {
                    $this->SetFillColor(220, 252, 231);
                    $this->SetTextColor(22, 101, 52);
                }
                $this->Rect($rightX, $rowY, $rightW, $h, 'F');
            } else {
                $this->SetFillColor(255, 255, 255);
                $this->Rect($rightX, $rowY, $rightW, $h, 'F');
                $this->dark();
            }

            $this->SetDrawColor(226, 232, 240);
            $this->Rect($rightX, $rowY, $rightW, $h, 'D');

            $this->SetFont('Arial', 'B', $highlight ? 8.8 : 8.2);
            $this->SetXY($rightX + 3, $rowY + (($h - 5) / 2));
            $this->Cell($labelW - 4, 5, $label, 0, 0, 'L');

            $this->SetFont('Arial', 'B', $highlight ? 10.2 : 9.0);
            $this->SetXY($rightX + $labelW, $rowY + (($h - 5) / 2));
            $this->Cell($valueW - 3, 5, qsi_money($value), 0, 0, 'R');

            $rowY += $h;
        }

        return $y + 44.0;
    }

    private function drawFooter(float $y): void
    {
        $footerY = max($y + 4, 267.0);

        $this->SetDrawColor(230, 111, 0);
        $this->SetLineWidth(0.6);
        $this->Line(15, $footerY, 195, $footerY);

        $this->navy();
        $this->SetFont('Arial', 'B', 8.2);
        $this->SetXY(15, $footerY + 4);
        $this->Cell(105, 4.5, 'Thank you for choosing Subhiksha Cards.', 0, 0, 'L');

        $this->muted();
        $this->SetFont('Arial', '', 6.9);
        $this->SetXY(15, $footerY + 10);
        $this->Cell(
            105,
            4,
            'This is a computer-generated Quick Sale invoice.',
            0,
            0,
            'L'
        );

        $this->orange();
        $this->SetFont('Arial', 'B', 8.2);
        $this->SetXY(135, $footerY + 4);
        $this->Cell(60, 4.5, 'For Subhiksha Cards', 0, 0, 'R');

        $this->muted();
        $this->SetFont('Arial', '', 6.8);
        $this->SetXY(15, 287);
        $this->Cell(180, 4, 'Page ' . $this->PageNo(), 0, 0, 'C');
    }

    public function drawInvoice(array $sale, array $items, array $payment): void
    {
        $this->AddPage();

        $this->drawPageHeader($sale, false);
        $y = $this->drawInfoSection($sale, $payment);
        $y = $this->drawTableHeader($y);

        $index = 1;

        foreach ($items as $item) {
            $nextHeight = $this->itemRowHeight(
                (string)($item['product_name'] ?? $item['item_name'] ?? '')
            );

            if ($y + $nextHeight > 238) {
                $this->AddPage();
                $y = $this->drawPageHeader($sale, true);
                $y = $this->drawTableHeader($y);
            }

            $y = $this->drawItemRow($y, $index, $item);
            $index++;
        }

        $y += 5;
        $y = $this->drawPaymentSummary($y, $sale, $payment);
        $this->drawFooter($y);
    }
}
